<?php
/**
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
 * @file
 * @note This is based heavily on ForeignAPIRepo from MediaWiki core
 * @ingroup FileRepo
 */

namespace MediaWiki\Extension\QuickInstantCommons;

use FormatJson;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\PageIdentity;
use MWException;
use RequestContext;
use Title;
use WANObjectCache;

/**
 * A foreign repository for a remote MediaWiki accessible through api.php requests supporting 404 handling.
 *
 * Example config:
 *
 * $wgForeignFileRepos[] = [
 *   'class'                  => ForeignAPIRepo::class,
 *   'name'                   => 'shared',
 *   'apibase'                => 'https://en.wikipedia.org/w/api.php',
 *   'fetchDescription'       => true, // Optional
 *   'descriptionCacheExpiry' => 3600,
 * ];
 *
 * @ingroup FileRepo
 */
class Repo extends \FileRepo {

	/**
	 * List of iiprop values for the thumbnail fetch queries.
	 */
	private const IMAGE_INFO_PROPS = [
		'url',
		'timestamp',
	];

	protected $fileFactory = [ File::class, 'newFromTitle' ];

	/** @var array FIXME: is this even used? */
	protected $mFileExists = [];

	/** @var string */
	private $mApiBase;

	/** @var MultiHttpClient */
	private $httpClient;

	/** @var \Psr\Log\LoggerInterface */
	private $logger;

	/**
	 * @var array
	 * This is the cache of prefetched images. The cache
	 * is rather stateful in an ugly way. We keep it around
	 * until the next time we parse a page, and hope parsing is
	 * just never interleaved.
	 */
	private $prefetchCache = [];

	private const MAX_PREFETCH_SIZE = 1024 * 100;
	// Always refresh if TTL this low.
	private const MIN_TTL_REFRESH = 10;
	// Start probabilistic refresh
	private const START_PREEMPT_REFRESH = 900;

	/**
	 * @param array|null $info
	 */
	public function __construct( $info ) {
		$config = RequestContext::getMain()->getConfig();

		// Some additional defaults.

		// https://commons.wikimedia.org/w/api.php
		$this->mApiBase = $info['apibase'] ?? null;

		if ( !$this->scriptDirUrl ) {
			// hack for description fetches
			$this->scriptDirUrl = dirname( $this->mApiBase );
		}
		$this->logger = LoggerFactory::getInstance( 'quickinstantcommons' );

		$options = [
			'maxReqTimeout' => $config->get( 'HTTPMaxTimeout' ),
			'maxConnTimeout' => $config->get( 'HTTPMaxConnectTimeout' ),
			'connTimeout' => $config->get( 'HTTPConnectTimeout' ) ?: $config->get( 'HTTPMaxConnectTimeout' ),
			'reqTimeout' => $config->get( 'HTTPTimeout' ) ?: $config->get( 'HTTPMaxTimeout' ),
			'logger' => $this->logger
		];
		$this->httpClient = new MultiHttpClient( $options );
		parent::__construct( $info );
	}

	/**
	 * @return string
	 */
	private function getApiUrl() {
		return $this->mApiBase;
	}

	/**
	 * Per docs in FileRepo, this needs to return false if we don't support versioned
	 * files. Well, we don't.
	 *
	 * @param PageIdentity|LinkTarget|string $title
	 * @param string|bool $time
	 * @suppress PhanTypeMismatchReturnSuperType
	 * @return File|false
	 */
	public function newFile( $title, $time = false ) {
		if ( $time ) {
			return false;
		}

		// This actually does return our type of File and not the base class.
		return parent::newFile( $title, $time );
	}

	/**
	 * @note Its unclear this is ever called in practise for foreign repos.
	 * @param string[] $files
	 * @return array
	 */
	public function fileExistsBatch( array $files ) {
		$results = [];
		foreach ( $files as $k => $f ) {
			if ( isset( $this->mFileExists[$f] ) ) {
				$results[$k] = $this->mFileExists[$f];
				unset( $files[$k] );
			} elseif ( self::isVirtualUrl( $f ) ) {
				# @todo FIXME: We need to be able to handle virtual
				# URLs better, at least when we know they refer to the
				# same repo.
				$results[$k] = false;
				unset( $files[$k] );
				$this->logger->info( "Cannot check existence of virtual url" );
			} elseif ( \FileBackend::isStoragePath( $f ) ) {
				$results[$k] = false;
				unset( $files[$k] );
				wfWarn( "Got mwstore:// path '$f'." );
				$this->logger->warning( "Got mwstore:// path {f}", [ 'f' => $f ] );
			}
		}

		if ( count( $files ) === 1 ) {
			// If there is only 1 file, not much to be gained by combining
			// requests, better to use the same form so that we share cache
			// with general imageinfo requests. This is probably the most
			// common case as fileExistsBatch is rarely called, and probably
			// not from anywhere relevant.
			// Keep in sync with File::newFromTitle.
			$data = $this->fetchImageQuery( $this->getMetadataQuery( reset( $files ) ) );
		} elseif ( count( $files ) === 0 ) {
			$data = [];
		} else {
			$data = $this->fetchImageQuery( [
				'titles' => implode( '|', $files ),
				'prop' => 'imageinfo' ]
			);
		}

		if ( isset( $data['query']['pages'] ) ) {
			# First, get results from the query. Note we only care whether the image exists,
			# not whether it has a description page.
			foreach ( $data['query']['pages'] as $p ) {
				$this->mFileExists[$p['title']] = ( $p['imagerepository'] !== '' );
			}
			# Second, copy the results to any redirects that were queried
			if ( isset( $data['query']['redirects'] ) ) {
				foreach ( $data['query']['redirects'] as $r ) {
					$this->mFileExists[$r['from']] = $this->mFileExists[$r['to']];
				}
			}
			# Third, copy the results to any non-normalized titles that were queried
			if ( isset( $data['query']['normalized'] ) ) {
				foreach ( $data['query']['normalized'] as $n ) {
					$this->mFileExists[$n['from']] = $this->mFileExists[$n['to']];
				}
			}
			# Finally, copy the results to the output
			foreach ( $files as $key => $file ) {
				$results[$key] = $this->mFileExists[$file];
			}
		}

		return $results;
	}

	/**
	 * FIXME: throw exception or newPlaceholderProps() instead??
	 * @param string $virtualUrl
	 * @return array
	 */
	public function getFileProps( $virtualUrl ) {
		return [];
	}

	/**
	 * @param array $query
	 * @return array|null
	 */
	public function fetchImageQuery( $query ) {
		$query = $this->normalizeImageQuery( $query );
		// FIXME change TTL
		$data = $this->httpGetCached( 'Metadata', $query );

		if ( $data ) {
			return FormatJson::decode( $data, true );
		} else {
			return null;
		}
	}

	/**
	 * Normalize query options when fetching from foreign api
	 *
	 * @param array $query
	 * @return array
	 */
	private function normalizeImageQuery( $query ) {
		global $wgLanguageCode;

		$query = array_merge( $query,
			[
				'format' => 'json',
				'action' => 'query',
				'redirects' => 'true'
			] );

		if ( !isset( $query['uselang'] ) ) { // uselang is unset or null
			$query['uselang'] = $wgLanguageCode;
		}
		return $query;
	}

	/**
	 * @param array $data
	 * @return bool|array
	 */
	public function getImageInfo( $data ) {
		if ( $data && isset( $data['query']['pages'] ) ) {
			foreach ( $data['query']['pages'] as $info ) {
				if ( isset( $info['imageinfo'][0] ) ) {
					$return = $info['imageinfo'][0];
					if ( isset( $info['pageid'] ) ) {
						$return['pageid'] = $info['pageid'];
					}
					return $return;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $hash
	 * @return File[]
	 */
	public function findBySha1( $hash ) {
		$results = $this->fetchImageQuery( [
			'aisha1base36' => $hash,
			'aiprop' => File::getProps(),
			'list' => 'allimages',
		] );
		$ret = [];
		if ( isset( $results['query']['allimages'] ) ) {
			foreach ( $results['query']['allimages'] as $img ) {
				$ret[] = new File( Title::makeTitle( NS_FILE, $img['name'] ), $this, $img );
			}
		}

		return $ret;
	}

	/**
	 * @param string $name
	 * @param int $width
	 * @param int $height
	 * @param array|null &$result Output-only parameter, guaranteed to become an array
	 * @param string $otherParams
	 *
	 * @return string|false
	 */
	private function getThumbUrl(
		$name, $width = -1, $height = -1, &$result = null, $otherParams = ''
	) {
		$data = $this->fetchImageQuery( [
			'titles' => 'File:' . $name,
			'iiprop' => self::getIIProps(),
			'iiurlwidth' => $width,
			'iiurlheight' => $height,
			'iiurlparam' => $otherParams,
			'prop' => 'imageinfo' ] );
		$info = $this->getImageInfo( $data );

		if ( $data && $info && isset( $info['thumburl'] ) ) {
			wfDebug( __METHOD__ . " got remote thumb " . $info['thumburl'] );
			$result = $info;

			return $info['thumburl'];
		} else {
			return false;
		}
	}

	/**
	 * @param string $name
	 * @param int $width
	 * @param int $height
	 * @param string $otherParams
	 * @param string|null $lang Language code for language of error
	 * @return bool|\MediaTransformError
	 * @since 1.22
	 */
	public function getThumbError(
		$name, $width = -1, $height = -1, $otherParams = '', $lang = null
	) {
		$data = $this->fetchImageQuery( [
			'titles' => 'File:' . $name,
			'iiprop' => self::getIIProps(),
			'iiurlwidth' => $width,
			'iiurlheight' => $height,
			'iiurlparam' => $otherParams,
			'prop' => 'imageinfo',
			'uselang' => $lang,
		] );
		$info = $this->getImageInfo( $data );

		if ( $data && $info && isset( $info['thumberror'] ) ) {
			wfDebug( __METHOD__ . " got remote thumb error " . $info['thumberror'] );

			return new \MediaTransformError(
				'thumbnail_error_remote',
				$width,
				$height,
				$this->getDisplayName(),
				$info['thumberror'] // already parsed message from foreign repo
			);
		} else {
			return false;
		}
	}

	/**
	 * Return the imageurl from cache if possible
	 *
	 * If the url has been requested today, get it from cache
	 * Otherwise retrieve remote thumb url, check for local file.
	 *
	 * @param string $name Is a dbkey form of a title
	 * @param int $width
	 * @param int $height
	 * @param string $params Other rendering parameters (page number, etc)
	 *   from handler's makeParamString.
	 * @return bool|string
	 */
	public function getThumbUrlFromCache( $name, $width, $height, $params = "" ) {
		$result = null; // can't pass "null" by reference, but it's ok as default value
		return $this->getThumbUrl( $name, $width, $height, $result, $params );
	}

	/**
	 * @see FileRepo::getZoneUrl()
	 * @param string $zone
	 * @param string|null $ext Optional file extension
	 * @return string
	 */
	public function getZoneUrl( $zone, $ext = null ) {
		// This is used during 404 handling.
		switch ( $zone ) {
			case 'public':
				return $this->url;
			case 'thumb':
				return $this->thumbUrl;
			default:
				return parent::getZoneUrl( $zone, $ext );
		}
	}

	/**
	 * Get the local directory corresponding to one of the basic zones
	 * @param string $zone
	 * @return bool|null|string
	 */
	public function getZonePath( $zone ) {
/* FIXME - This is no longer needed now that we don't download thumbs.
		$supported = [ 'public', 'thumb' ];
		if ( in_array( $zone, $supported ) ) {
			return parent::getZonePath( $zone );
		}
*/
		return false;
	}

	/**
	 * Get information about the repo - overrides/extends the parent
	 * class's information.
	 * @return array
	 * @since 1.22
	 */
	public function getInfo() {
		$info = parent::getInfo();
		$info['apiurl'] = $this->getApiUrl();

		$query = [
			'format' => 'json',
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'general',
		];

		$data = $this->httpGetCached( 'SiteInfo', $query, \IExpiringStore::TTL_MONTH );

		if ( $data ) {
			$siteInfo = FormatJson::decode( $data, true );
			$general = $siteInfo['query']['general'];

			$info['articlepath'] = $general['articlepath'];
			$info['server'] = $general['server'];

			if ( isset( $general['favicon'] ) ) {
				$info['favicon'] = $general['favicon'];
			}
		}

		return $info;
	}

	/**
	 * @param string $url
	 * @return string|false
	 */
	protected function httpGet(
		$url
	) {
		$arg = [
			'method' => "GET",
			'url' => $url
		];

		list( $code, , ,$body, $err ) = $this->httpClient->run( $arg );

		if ( $code == 200 ) {
			return $body;
		} else {
			$this->logger->warning(
				'HTTP request to {url} failed {status} - {err}',
				[
					'caller' => __METHOD__,
					'url' => $url,
					'status' => $code,
					'err' => $err
				]
			);

			return false;
		}
	}

	/**
	 * @return string
	 * @since 1.23
	 */
	protected static function getIIProps() {
		return implode( '|', self::IMAGE_INFO_PROPS );
	}

	/**
	 * @param array $query Associative array of query options
	 * @return string Url to fetch
	 */
	private function turnQueryIntoUrl( $query ) {
		if ( $this->mApiBase ) {
			$url = wfAppendQuery( $this->mApiBase, $query );
		} else {
			$url = $this->makeUrl( $query, 'api' );
		}

		return wfExpandUrl( $url, PROTO_HTTP );
	}

	private function turnQueryUrlIntoCacheKey( $attribute, $url ) {
		// Not clear what the point of attribute here, if the result is
		// functionally determined by url...
		return $this->getLocalCacheKey( $attribute, sha1( $url ) );
	}

	/**
	 * HTTP GET request to a mediawiki API (with caching)
	 * @param string $attribute Used in cache key creation, mostly
	 * @param array $query The query parameters for the API request
	 * @param int $cacheTTL Time to live for the memcached caching
	 * @return string|null
	 */
	public function httpGetCached( $attribute, $query, $cacheTTL = 3600 ) {
		$this->finalizeCacheIfNeeded();
		$url = $this->turnQueryIntoUrl( $query );
		$key = $this->turnQueryUrlIntoCacheKey( $attribute, $url );
		if ( isset( $this->prefetchCache[$key] ) ) {
			$this->logger->debug( "Got {key} [{url}] from prefetch cache",
				[
					'key' => $key,
					'url' => $url
				] );
			return $this->prefetchCache[$key];
		}

		return $this->wanCache->getWithSetCallback(
			$key,
			$cacheTTL,
			function ( $curValue, &$ttl ) use ( $url ) {
				$html = $this->httpGet( $url );
				if ( $html !== false ) {
					// $ttl = $mtime ? $this->wanCache->adaptiveTTL( $mtime, $ttl ) : $ttl;

				} else {
					// $ttl = $this->wanCache->adaptiveTTL( $mtime, $ttl );
					$html = null; // caches negatives
				}

				return $html;
			},
			[ 'pcGroup' => 'http-get:3', 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);
	}

	/**
	 * @param callable $callback
	 * @throws MWException
	 */
	public function enumFiles( $callback ) {
		// @phan-suppress-previous-line PhanPluginNeverReturnMethod
		throw new MWException( 'enumFiles is not supported by ' . static::class );
	}

	/**
	 * @throws MWException
	 */
	protected function assertWritableRepo() {
		throw new MWException( static::class . ': write operations are not supported.' );
	}

	/**
	 * @param string $img No file prefix!
	 * @return array URL query parameters
	 */
	public function getMetadataQuery( $img ) {
		$query = [
			'titles' => 'File:' . $img,
			'iiprop' => File::getProps(),
			'prop' => 'imageinfo',
			'iimetadataversion' => \MediaHandler::getMetadataVersion(),
			// extmetadata is language-dependent, accessing the current language here
			// would be problematic, so we just get them all
			'iiextmetadatamultilang' => 1,
		];
		return $this->normalizeImageQuery( $query );
	}

	/**
	 * Prefetch some images async.
	 *
	 * @param string[] $imgs List of files, no File: prefix
	 */
	public function prefetchImgMetadata( array $imgs ) {
		// Clear cache.
		$this->prefetchCache = [];

		// Open question - is there a benefit to sending multiple titles in a single query
		// over sending many requests when using http/2?

		$filesToCacheKey = [];
		$filesToCacheUrl = [];
		foreach ( $imgs as $img ) {
			$query = $this->getMetadataQuery( $img );
			$url = $this->turnQueryIntoUrl( $query );
			$key = $this->turnQueryUrlIntoCacheKey( 'Metadata', $url );
			$filesToCacheKey[$key] = $img;
			$filesToCacheUrl[$img] = [ $url, $key ];
		}

		$this->logger->debug(
			"Async prefetching {count} images via wancache",
			[ 'count' => count( $filesToCacheKey ) ]
		);
		// todo We could use second curTTL argument to probabilistically refresh.
		$ttls = [];
		$res = $this->wanCache->getMulti( array_keys( $filesToCacheKey ), $ttls );

		foreach ( $res as $key => $resp ) {
			$imgName = $filesToCacheKey[$key];
			$curTTLAdj = max( 0, $ttls[$key] - self::MIN_TTL_REFRESH );
			// FIXME these values were chosen randomly.
			$chance = 1 - $curTTLAdj / self::START_PREEMPT_REFRESH;
			$decision = mt_rand( 1, 1000000000 ) <= 1000000000 * $chance;
			if ( $decision ) {
				// Based on WanObjectCache::worthRefreshExpiring
				if ( $decision ) {
				$this->logger->debug( "preemptively refreshing {file} [{key}] during prefetch. ttl={ttl}", [
					'file' => $imgName,
					'key' => $key,
					'ttl' => $ttls[$key]
				] );
				}

			} else {
				if ( strlen( $resp ) < self::MAX_PREFETCH_SIZE ) {
					// Don't store really large files. PDFs have
					// whole text layer. potential OOM risk.
					$this->prefetchCache[$key] = $resp;
					unset( $filesToCacheUrl[$imgName] );
				} else {
					$this->logger->debug(
						"Skipping storing {img} from wancache. Too big",
						[ 'img' => $imgName ]
					);
				}
			}
		}

		$reqs = [];
		foreach ( $filesToCacheUrl as $imgName => $info ) {
			list( $url, $key ) = $info;
			$reqs[] = [
				'method' => 'GET',
				'url' => $url,
				'_imgName' => $imgName,
				'_key' => $key
			];
		}

		$this->logger->debug( "Async prefetching {count} images via HTTP", [ 'count' => count( $reqs ) ] );
		$this->httpClient->runMultiAsync( $reqs );
	}

	public function finalizeCacheIfNeeded() {
		if ( !$this->httpClient->inAsyncRequest() ) {
			return;
		}

		$results = $this->httpClient->finishMultiAsync();
		$this->logger->debug( "Got http prefetch for {count} files", [ 'count' => count( $results ) ] );
		foreach ( $results as $res ) {
			list( $code, , ,$body, $err ) = $res['response'];
			$imgName = $res['_imgName'];
			$key = $res['_key'];

			if ( !$imgName || !$key ) {
				throw new \LogicException( "Missing imgname/key" );
			}

			if ( $code == 200 ) {
				if ( strlen( $body ) < self::MAX_PREFETCH_SIZE ) {
					$this->prefetchCache[$key] = $body;
				} else {
					$this->logger->debug(
						"Skipping storing {img} from http. Too big",
						[ 'img' => $imgName ]
					);
				}
				// FIXME TTL.
				$this->wanCache->set( $key, $body, 3600 );
			} else {
				$this->logger->warning(
					'HTTP request to {url} failed {status} - {err}',
					[
						'caller' => __METHOD__,
						'url' => $res['url'],
						'status' => $code,
						'err' => $err,
						'imgName' => $imgName
					]
				);
			}
		}
	}

	public function purgeMetadata( $imgName ) {
		$query = $this->getMetadataQuery( $imgName );
		$url = $this->turnQueryIntoUrl( $query );
		$key = $this->turnQueryUrlIntoCacheKey( 'Metadata', $url );
		$this->wanCache->delete( $key );
	}

	public function __destruct() {
		$this->finalizeCacheIfNeeded();
	}
}
