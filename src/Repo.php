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
use RequestContext;
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
	/* This version string is used in the user agent for requests and will help
	 * server maintainers in identify ForeignAPI usage.
	 * Update the version every time you make breaking or significant changes. */
	private const VERSION = "1.0";

	/**
	 * List of iiprop values for the thumbnail fetch queries.
	 */
	private const IMAGE_INFO_PROPS = [
		'url',
		'timestamp',
	];

	protected $fileFactory = [ File::class, 'newFromTitle' ];

	/** @var int Redownload thumbnail files after this expiry */
	protected $fileCacheExpiry = 2592000; // 1 month (30*24*3600)

	/** @var array */
	protected $mFileExists = [];

	/** @var string */
	private $mApiBase;

	/** @var MultiHttpClient */
	private $httpClient;

	private $logger;

	/**
	 * @param array|null $info
	 */
	public function __construct( $info ) {
		$config = RequestContext::getMain()->getConfig();

		// Some additional defaults.

		// https://commons.wikimedia.org/w/api.php
		$this->mApiBase = $info['apibase'] ?? null;

		if ( isset( $info['apiThumbCacheExpiry'] ) ) {
			$this->apiThumbCacheExpiry = $info['apiThumbCacheExpiry'];
		}
		if ( isset( $info['fileCacheExpiry'] ) ) {
			$this->fileCacheExpiry = $info['fileCacheExpiry'];
		}
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
	 * @return File|false
	 */
	public function newFile( $title, $time = false ) {
		if ( $time ) {
			return false;
		}

		return parent::newFile( $title, $time );
	}

	/**
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
			} elseif ( FileBackend::isStoragePath( $f ) ) {
				$results[$k] = false;
				unset( $files[$k] );
				wfWarn( "Got mwstore:// path '$f'." );
			}
		}

		$data = $this->fetchImageQuery( [
			'titles' => implode( '|', $files ),
			'prop' => 'imageinfo' ]
		);

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

		$data = $this->httpGetCached( 'Metadata', $query );

		if ( $data ) {
			return FormatJson::decode( $data, true );
		} else {
			return null;
		}
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
	 * @return ForeignAPIFile[]
	 */
	public function findBySha1( $hash ) {
		$results = $this->fetchImageQuery( [
			'aisha1base36' => $hash,
			'aiprop' => ForeignAPIFile::getProps(),
			'list' => 'allimages',
		] );
		$ret = [];
		if ( isset( $results['query']['allimages'] ) ) {
			foreach ( $results['query']['allimages'] as $img ) {
				$ret[] = new ForeignAPIFile( Title::makeTitle( NS_FILE, $img['name'] ), $this, $img );
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
	 * @return bool|MediaTransformError
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

			return new MediaTransformError(
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
	public function httpGet(
		$url
	) {
		/* Http::get */
		$url = wfExpandUrl( $url, PROTO_HTTP );
		wfDebug( "ForeignAPIRepo: HTTP GET: $url" );
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
	 * HTTP GET request to a mediawiki API (with caching)
	 * @param string $attribute Used in cache key creation, mostly
	 * @param array $query The query parameters for the API request
	 * @param int $cacheTTL Time to live for the memcached caching
	 * @return string|null
	 */
	public function httpGetCached( $attribute, $query, $cacheTTL = 3600 ) {
		if ( $this->mApiBase ) {
			$url = wfAppendQuery( $this->mApiBase, $query );
		} else {
			$url = $this->makeUrl( $query, 'api' );
		}

		return $this->wanCache->getWithSetCallback(
			$this->getLocalCacheKey( $attribute, sha1( $url ) ),
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
}
