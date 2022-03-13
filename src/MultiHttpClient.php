<?php
/**
 * HTTP service client
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * Based on MediaWiki Core https://github.com/wikimedia/mediawiki/blob/master/includes/libs/http/MultiHttpClient.php
 * @file
 */
namespace MediaWiki\Extension\QuickInstantCommons;

use Exception;
use LogicException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class to handle multiple HTTP requests
 *
 * If curl is available, requests will be made concurrently.
 * Otherwise, they will be made serially.
 *
 * HTTP request maps are arrays that use the following format:
 *   - method   : GET/HEAD/PUT/POST/DELETE
 *   - url      : HTTP/HTTPS URL
 *   - query    : <query parameter field/value associative array> (uses RFC 3986)
 *   - headers  : <header name/value associative array>
 *   - stream   : resource to stream the HTTP response body to
 *   - proxy    : HTTP proxy to use
 *   - flags    : map of boolean flags which supports:
 *                  - relayResponseHeaders : write out header via header()
 * Request maps can use integer index 0 instead of 'method' and 1 instead of 'url'.
 *
 */
class MultiHttpClient implements LoggerAwareInterface {
	/** @var resource curl_multi_init() handle */
	protected $cmh;
	/** @var string|null SSL certificates path */
	protected $caBundlePath;
	/** @var float */
	protected $connTimeout = 10;
	/** @var float */
	protected $maxConnTimeout = INF;
	/** @var float */
	protected $reqTimeout = 30;
	/** @var float */
	protected $maxReqTimeout = INF;
	/**
	 * @var bool
	 * @note This is changed from core!!!
	 */
	protected $usePipelining = true;
	/**
	 * @var int
	 * @note Now that we use PIPEWAIT, this probably does very little.
	 */
	protected $maxConnsPerHost = 50;
	/** @var string|null proxy */
	protected $proxy;
	/** @var string */
	protected $userAgent = 'QuickInstantCommons';
	/** @var LoggerInterface */
	protected $logger;
	/** @var resource[] Hacky state sharing to make async requests work. */
	private $handles = [];
	/** @var resource|null allegedly reusing curl handlers make things faster. When measuring
	 * it seemed to very roughly be a 2-4% speed improvement.
	 */
	private $curlHandleCache = null;
	/** @var array|null state for doin an async request. */
	private $inFlightState = null;

	/**
	 * Since 1.35, callers should use HttpRequestFactory::createMultiClient() to get
	 * a client object with appropriately configured timeouts instead of constructing
	 * a MultiHttpClient directly.
	 *
	 * @param array $options
	 *   - connTimeout     : default connection timeout (seconds)
	 *   - reqTimeout      : default request timeout (seconds)
	 *   - maxConnTimeout  : maximum connection timeout (seconds)
	 *   - maxReqTimeout   : maximum request timeout (seconds)
	 *   - proxy           : HTTP proxy to use
	 *   - usePipelining   : whether to use HTTP pipelining if possible (for all hosts)
	 *   - maxConnsPerHost : maximum number of concurrent connections (per host)
	 *   - userAgent       : The User-Agent header value to send
	 *   - logger          : a \Psr\Log\LoggerInterface instance for debug logging
	 *   - caBundlePath    : path to specific Certificate Authority bundle (if any)
	 * @throws Exception
	 */
	public function __construct( array $options ) {
		global $wgSitename;
		$qicVersion = \ExtensionRegistry::getInstance()->getAllThings()['QuickInstantCommons']['version'];
		$this->userAgent = 'QuickInstantCommons/' . $qicVersion . ' MediaWiki/' . MW_VERSION . '; ' . $wgSitename;
		if ( isset( $options['caBundlePath'] ) ) {
			$this->caBundlePath = $options['caBundlePath'];
			if ( !file_exists( $this->caBundlePath ) ) {
				throw new Exception( "Cannot find CA bundle: " . $this->caBundlePath );
			}
		}
		static $opts = [
			'connTimeout', 'maxConnTimeout', 'reqTimeout', 'maxReqTimeout',
			'usePipelining', 'maxConnsPerHost', 'proxy', 'userAgent', 'logger'
		];

		foreach ( $opts as $key ) {
			if ( isset( $options[$key] ) ) {
				$this->$key = $options[$key];
			}
		}

		if ( $this->connTimeout > $this->maxConnTimeout ) {
			$this->connTimeout = $this->maxConnTimeout;
		}
		if ( $this->reqTimeout > $this->maxReqTimeout ) {
			$this->reqTimeout = $this->maxReqTimeout;
		}

		if ( $this->logger === null ) {
			$this->logger = new NullLogger;
		}
	}

	/**
	 * Execute an HTTP(S) request
	 *
	 * This method returns a response map of:
	 *   - code    : HTTP response code or 0 if there was a serious error
	 *   - reason  : HTTP response reason (empty if there was a serious error)
	 *   - headers : <header name/value associative array>
	 *   - body    : HTTP response body or resource (if "stream" was set)
	 *   - error     : Any error string
	 * The map also stores integer-indexed copies of these values. This lets callers do:
	 * @code
	 * 		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
	 * @endcode
	 * @param array $req HTTP request array
	 * @return array Response array for request
	 */
	public function run( array $req ) {
		return $this->runMulti( [ $req ] )[0]['response'];
	}

	/**
	 * Execute a set of HTTP(S) requests.
	 *
	 * If curl is available, requests will be made concurrently.
	 * Otherwise, they will be made serially.
	 *
	 * The maps are returned by this method with the 'response' field set to a map of:
	 *   - code    : HTTP response code or 0 if there was a serious error
	 *   - reason  : HTTP response reason (empty if there was a serious error)
	 *   - headers : <header name/value associative array>
	 *   - body    : HTTP response body or resource (if "stream" was set)
	 *   - error   : Any error string
	 * The map also stores integer-indexed copies of these values. This lets callers do:
	 * @code
	 *        list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $req['response'];
	 * @endcode
	 * All headers in the 'headers' field are normalized to use lower case names.
	 * This is true for the request headers and the response headers. Integer-indexed
	 * method/URL entries will also be changed to use the corresponding string keys.
	 *
	 * @param array[] $reqs Map of HTTP request arrays
	 * @return array[] $reqs With response array populated for each
	 * @throws Exception
	 */
	public function runMulti( array $reqs ) {
		$this->assertNotInAsyncRequest();
		$this->normalizeRequests( $reqs );

		if ( $this->isCurlEnabled() ) {
			$this->runMultiCurl( $reqs );
			return $this->runMultiCurlFinish( $reqs );
		} else {
			throw new Exception( "Curl php extension needs to be installed" );
		}
	}

	/**
	 * Fetch some stuff async.
	 *
	 * After you call this, you must call finishMultiAsync to get the result.
	 *
	 * Once you started a runMultiAsync, you must not call runMultiAsync or
	 * runMulti until after you have called finishMultiAsync.
	 *
	 * @param array $reqs List of requests, containing method and url
	 */
	public function runMultiAsync( array $reqs ) {
		$this->assertNotInAsyncRequest();
		$this->normalizeRequests( $reqs );

		if ( $this->isCurlEnabled() ) {
			$this->runMultiCurl( $reqs );
			$this->inFlightState = $reqs;
		} else {
			throw new Exception( "Curl php extension needs to be installed" );
		}
	}

	public function finishMultiAsync() {
		// This is the simplest version. An improved version could allow overlapping requests,
		// and manage if someone requests a url in sync fashion that was previously asynced, etc.
		// Maybe with callbacks instead. But that sounds really complicated.

		if ( !$this->inAsyncRequest() ) {
			throw new LogicException( "Not in an async request!" );
		}

		$res = $this->runMultiCurlFinish( $this->inFlightState );
		$this->inFlightState = null;
		return $res;
	}

	public function inAsyncRequest() {
		return $this->inFlightState !== null;
	}

	private function assertNotInAsyncRequest() {
		if ( $this->inFlightState !== null ) {
			throw new LogicException( "Async request already in flight" );
		}
	}

	/**
	 * Determines if the curl extension is available
	 *
	 * @return bool true if curl is available, false otherwise.
	 */
	protected function isCurlEnabled() {
		// Explicitly test if curl_multi* is blocked, as some users' hosts provide
		// them with a modified curl with the multi-threaded parts removed(!)
		return extension_loaded( 'curl' ) && function_exists( 'curl_multi_init' );
	}

	/**
	 * Execute a set of HTTP(S) requests concurrently
	 *
	 * @see MultiHttpClient::runMulti()
	 *
	 * @param array[] &$reqs Map of HTTP request arrays
	 * @throws Exception
	 */
	private function runMultiCurl( array &$reqs ) {
		$chm = $this->getCurlMulti();

		// Add all of the required cURL handles...
		if ( count( $this->handles ) !== 0 ) {
			throw new Exception( "Async req in progress" );
		}
		foreach ( $reqs as $index => &$req ) {
			// Note: getCurlHandle modifies $req!!
			$this->handles[$index] = $this->getCurlHandle( $req );
			curl_multi_add_handle( $chm, $this->handles[$index] );
		}
		// unset( $req ); // don't assign over this by accident
		$active = null;
		// Send all data that is waiting to be sent.
		do {
			$mrc = curl_multi_exec( $chm, $active );
		} while ( $mrc == CURLM_CALL_MULTI_PERFORM );
	}

	/**
	 * Complete all the queued up requests
	 *
	 * @param array &$reqs
	 * @return array List of requests and their results
	 * @suppress PhanTypeInvalidDimOffset
	 */
	private function runMultiCurlFinish( array &$reqs ) {
		$selectTimeout = $this->getSelectTimeout();
		$infos = [];
		// Execute the cURL handles concurrently...
		$active = null; // handles still being processed
		do {
			// Send/recieve all pending data. e.g. read responses.
			do {
				$mrc = curl_multi_exec( $this->cmh, $active );
				// A request probably completed, so read its info.
				$info = curl_multi_info_read( $this->cmh );
				if ( $info !== false ) {
					$infos[(int)$info['handle']] = $info;
				}
			// In old versions of curl, we had to loop this. Should not matter in new versions.
			} while ( $mrc == CURLM_CALL_MULTI_PERFORM );

			// Wait (if possible) for available work...
			if ( $active > 0 && $mrc == CURLM_OK && curl_multi_select( $this->cmh, $selectTimeout ) == -1 ) {
				// This bug should be fixed now in theory!
				// So we should not reach this code unless we hit the select timeout.
				// PHP bug 63411; https://curl.haxx.se/libcurl/c/curl_multi_fdset.html
				usleep( 5000 ); // 5ms
			}
		} while ( $active > 0 && $mrc == CURLM_OK );

		// Make sure we got them all.
		$info = false;
		do {
			$info = curl_multi_info_read( $this->cmh );
			if ( $info !== false ) {
				$infos[(int)$info['handle']] = $info;
			}
		} while ( $info );

		// Remove all of the added cURL handles and check for errors...
		foreach ( $reqs as $index => &$req ) {
			$ch = $this->handles[$index];
			curl_multi_remove_handle( $this->cmh, $ch );
			if ( isset( $infos[(int)$ch] ) ) {
				$info = $infos[(int)$ch];
				$errno = $info['result'];
				if ( $errno !== 0 ) {
					$req['response']['error'] = "(curl error: $errno)";
					if ( function_exists( 'curl_strerror' ) ) {
						$req['response']['error'] .= " " . curl_strerror( $errno );
					}
					// @phan-suppress-next-line PhanTypeConversionFromArray
					$this->logger->warning( "Error fetching URL \"" . $req['url'] . "\": " .
						$req['response']['error'] );
				} else {
					$this->logger->debug(
						"HTTP complete: {method} {url} code={response_code} size={size} " .
						"total={total_time} connect={connect_time}",
						[
							'method' => $req['method'],
							'url' => $req['url'],
							'response_code' => $req['response']['code'],
							'size' => curl_getinfo( $ch, CURLINFO_SIZE_DOWNLOAD ),
							'total_time' => $this->getCurlTime(
								$ch, CURLINFO_TOTAL_TIME, 'CURLINFO_TOTAL_TIME_T'
							),
							'connect_time' => $this->getCurlTime(
								$ch, CURLINFO_CONNECT_TIME, 'CURLINFO_CONNECT_TIME_T'
							),
						]
					);
				}
			} else {
				$req['response']['error'] = "(curl error: no status set)";
			}

			// For convenience with the list() operator
			$req['response'][0] = $req['response']['code'];
			$req['response'][1] = $req['response']['reason'];
			$req['response'][2] = $req['response']['headers'];
			$req['response'][3] = $req['response']['body'];
			$req['response'][4] = $req['response']['error'];
			if ( !$this->curlHandleCache ) {
				// reuse the handle
				$this->curlHandleCache = $ch;
			} else {
				curl_close( $ch );
			}
		}
		$this->handles = [];
		unset( $req ); // don't assign over this by accident

		return $reqs;
	}

	/**
	 * @param array &$req HTTP request map
	 * @phpcs:ignore Generic.Files.LineLength
	 * @phan-param array{url:string,proxy?:?string,query:mixed,method:string,body:string|resource,headers:string[],stream?:resource,flags:array} $req
	 * @suppress PhanTypePossiblyInvalidDimOffset
	 * @return resource
	 * @throws Exception
	 */
	protected function getCurlHandle( array &$req ) {
		// TODO: I did a test of reusing curl handles. On a long page, that caused time
		// to go from 139 seconds -> 135. On a medium page it went 19.0 -> 18.1 seconds.
		// For simplicity, only cache a single handler
		if ( $this->curlHandleCache ) {
			$ch = $this->curlHandleCache;
			$this->curlHandleCache = null;
		} else {
			$ch = curl_init();
			// Prefer to use HTTP/2 multiplexing over multiple connections.
			curl_setopt( $ch, CURLOPT_PIPEWAIT, 1 );
			curl_setopt( $ch, CURLOPT_PROXY, $req['proxy'] ?? $this->proxy );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT_MS, intval( $this->connTimeout * 1e3 ) );
			curl_setopt( $ch, CURLOPT_TIMEOUT_MS, intval( $this->reqTimeout * 1e3 ) );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
			curl_setopt( $ch, CURLOPT_MAXREDIRS, 4 );
			curl_setopt( $ch, CURLOPT_HEADER, 0 );
			if ( $this->caBundlePath !== null ) {
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
				curl_setopt( $ch, CURLOPT_CAINFO, $this->caBundlePath );
			}
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		}

		$url = $req['url'];
		$query = http_build_query( $req['query'], '', '&', PHP_QUERY_RFC3986 );
		if ( $query != '' ) {
			$url .= strpos( $req['url'], '?' ) === false ? "?$query" : "&$query";
		}
		curl_setopt( $ch, CURLOPT_URL, $url );

		if ( $req['method'] !== 'GET' ) {
			throw new Exception( "Only GET supported" );
		}
		if ( is_resource( $req['body'] ) || $req['body'] !== '' ) {
			throw new Exception( "HTTP body specified for a non PUT/POST request." );
		}

		if ( !isset( $req['headers']['user-agent'] ) ) {
			$req['headers']['user-agent'] = $this->userAgent;
		}

		$headers = [];
		foreach ( $req['headers'] as $name => $value ) {
			if ( strpos( $name, ': ' ) ) {
				throw new Exception( "Headers cannot have ':' in the name." );
			}
			$headers[] = $name . ': ' . trim( $value );
		}
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		curl_setopt( $ch, CURLOPT_HEADERFUNCTION,
			static function ( $ch, $header ) use ( &$req ) {
				if ( !empty( $req['flags']['relayResponseHeaders'] ) && trim( $header ) !== '' ) {
					header( $header );
				}
				$length = strlen( $header );
				$matches = [];
				if ( preg_match( "/^(HTTP\/(?:1\.[01]|2)) (\d{3}) (.*)/", $header, $matches ) ) {
					$req['response']['code'] = (int)$matches[2];
					$req['response']['reason'] = trim( $matches[3] );
					// After a redirect we will receive this again, but we already stored headers
					// that belonged to a redirect response. Start over.
					$req['response']['headers'] = [];
					return $length;
				}
				if ( strpos( $header, ":" ) === false ) {
					return $length;
				}
				list( $name, $value ) = explode( ":", $header, 2 );
				$name = strtolower( $name );
				$value = trim( $value );
				if ( isset( $req['response']['headers'][$name] ) ) {
					$req['response']['headers'][$name] .= ', ' . $value;
				} else {
					$req['response']['headers'][$name] = $value;
				}
				return $length;
			}
		);

		curl_setopt( $ch, CURLOPT_WRITEFUNCTION,
			static function ( $ch, $data ) use ( &$req ) {
				// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
				$req['response']['body'] .= $data;

				return strlen( $data );
			}
		);

		return $ch;
	}

	/**
	 * @return resource
	 * @throws Exception
	 */
	protected function getCurlMulti() {
		// Note, this is access directly by other methods!
		if ( !$this->cmh ) {
			$cmh = curl_multi_init();
			// Limit the size of the idle connection cache such that consecutive parallel
			// request batches to the same host can avoid having to keep making connections
			curl_multi_setopt( $cmh, CURLMOPT_MAXCONNECTS, (int)$this->maxConnsPerHost );
			$this->cmh = $cmh;

			// CURLMOPT_MAX_HOST_CONNECTIONS is available since PHP 7.0.7 and cURL 7.30.0
			if ( version_compare( curl_version()['version'], '7.30.0', '>=' ) ) {
				// Limit the number of in-flight requests for any given host
				$maxHostConns = $this->maxConnsPerHost;
				curl_multi_setopt( $this->cmh, CURLMOPT_MAX_HOST_CONNECTIONS, $this->maxConnsPerHost );
			}
			curl_multi_setopt( $this->cmh, CURLMOPT_PIPELINING, $this->usePipelining ? CURLPIPE_MULTIPLEX : 0 );
		}

		return $this->cmh;
	}

	/**
	 * Get a time in seconds, formatted with microsecond resolution, or fall back to second
	 * resolution on PHP 7.2
	 *
	 * @param resource $ch
	 * @param int $oldOption
	 * @param string $newConstName
	 * @return string
	 */
	private function getCurlTime( $ch, $oldOption, $newConstName ): string {
		if ( defined( $newConstName ) ) {
			return sprintf( "%.6f", curl_getinfo( $ch, constant( $newConstName ) ) / 1e6 );
		} else {
			return (string)curl_getinfo( $ch, $oldOption );
		}
	}

	/**
	 * Normalize request information
	 *
	 * @param array[] &$reqs the requests to normalize
	 */
	private function normalizeRequests( array &$reqs ) {
		foreach ( $reqs as &$req ) {
			$req['response'] = [
				'code'     => 0,
				'reason'   => '',
				'headers'  => [],
				'body'     => '',
				'error'    => ''
			];
			if ( isset( $req[0] ) ) {
				$req['method'] = $req[0]; // short-form
				unset( $req[0] );
			}
			if ( isset( $req[1] ) ) {
				$req['url'] = $req[1]; // short-form
				unset( $req[1] );
			}
			if ( !isset( $req['method'] ) ) {
				throw new Exception( "Request has no 'method' field set." );
			} elseif ( !isset( $req['url'] ) ) {
				throw new Exception( "Request has no 'url' field set." );
			}
			$this->logger->debug( "HTTP start: {method} {url}",
				[
					'method' => $req['method'],
					'url' => $req['url'],
				]
			);
			$req['query'] = $req['query'] ?? [];
			$headers = []; // normalized headers
			if ( isset( $req['headers'] ) ) {
				foreach ( $req['headers'] as $name => $value ) {
					$headers[strtolower( $name )] = $value;
				}
			}
			$req['headers'] = $headers;
			if ( !isset( $req['body'] ) ) {
				$req['body'] = '';
				$req['headers']['content-length'] = 0;
			}
			$req['flags'] = $req['flags'] ?? [];
		}
	}

	/**
	 * Get a suitable select timeout for the given options.
	 *
	 * @return float
	 */
	private function getSelectTimeout() {
		$connTimeout = $this->connTimeout;
		$reqTimeout = $this->reqTimeout;
		$timeouts = array_filter( [ $connTimeout, $reqTimeout ] );
		if ( count( $timeouts ) === 0 ) {
			return 1;
		}

		$selectTimeout = min( $timeouts );
		// Minimum 10us for sanity
		if ( $selectTimeout < 10e-6 ) {
			$selectTimeout = 10e-6;
		}
		return $selectTimeout;
	}

	/**
	 * Register a logger
	 *
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	public function __destruct() {
		if ( $this->inAsyncRequest() ) {
			// This shouldn't really happen, but too late now
			$this->inFlightState = null;
			$this->logger->warning( "Destroying MultiHttpClient while async request pending" );
		}
		if ( $this->cmh ) {
			curl_multi_close( $this->cmh );
		}
		if ( $this->curlHandleCache ) {
			curl_close( $this->curlHandleCache );
		}
	}
}
