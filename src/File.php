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
 * @note Based heavily on ForeignAPIFile from core MediaWiki.
 * @ingroup FileAbstraction
 */
namespace MediaWiki\Extension\QuickInstantCommons;

use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use ThumbnailImage;
use Title;

/**
 * Foreign file accessible through api.php requests.
 *
 * @ingroup FileAbstraction
 */
class File extends \File {
	/** @var bool */
	private $mExists;
	/** @var array */
	private $mInfo;

	/** @var string */
	protected $repoClass = Repo::class;

	/**
	 * @param Title|string|bool $title
	 * @param Repo $repo
	 * @param array $info
	 * @param bool $exists
	 */
	public function __construct( $title, $repo, $info, $exists = false ) {
		parent::__construct( $title, $repo );

		$this->mInfo = $info;
		$this->mExists = $exists;

		$this->assertRepoDefined();
	}

	/**
	 * @param Title $title
	 * @param Repo $repo
	 * @return File|null
	 */
	public static function newFromTitle( Title $title, $repo ) {
		$data = $repo->fetchImageQuery(
			$repo->getMetadataQuery( $title->getDBkey() ),
			[ $repo, 'getMetadataCacheTime' ]
		);

		$info = $repo->getImageInfo( $data );

		if ( $info ) {
			$lastRedirect = isset( $data['query']['redirects'] )
				? count( $data['query']['redirects'] ) - 1
				: -1;
			if ( $lastRedirect >= 0 ) {
				// FIXME what if foreign repo is not english, namespace might not match.
				// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
				$newtitle = Title::newFromText( $data['query']['redirects'][$lastRedirect]['to'] );
				$img = new self( $newtitle, $repo, $info, true );
				$img->redirectedFrom( $title->getDBkey() );
			} else {
				// Recursive foreign repos don't support redirects. Hacky work-around. (T298358)
				$repoName = $data['query']['pages']['-1']['imagerepository'] ?? 'local';
				$pageName = '';
				if ( $repoName !== 'local' ) {
					$descUrl = $data['query']['pages']['-1']['imageinfo']['0']['descriptionurl'] ?? '';
					$m = [];

					// Very hacky.
					if ( preg_match(
						'/\/[^:]*:([^\/]+)$|index.php\?(?:.*&)?title=[^:]*:([^&]*)(?:&|$)/',
						$descUrl,
						$m
					) ) {
						$pageName = rawurldecode( $m[1] );
					}
				}
				if ( $pageName !== '' && $pageName !== $title->getDBKey() ) {
					$newtitle = Title::makeTitleSafe( NS_FILE, $pageName );
					if ( !$newtitle ) {
						throw new \Exception( "redirected title invalid" );
					}
					$img = new self( $newtitle, $repo, $info, true );
					$img->redirectedFrom( $title->getDBKey() );
				} else {
					$img = new self( $title, $repo, $info, true );
				}
			}

			return $img;
		} else {
			return null;
		}
	}

	/**
	 * Get the property string for iiprop and aiprop
	 * @return string
	 */
	public static function getProps() {
		return 'timestamp|user|comment|url|size|sha1|metadata|mime|mediatype|extmetadata';
	}

	/**
	 * @return Repo|bool
	 */
	public function getRepo() {
		return $this->repo;
	}

	// Dummy functions...

	/**
	 * @return bool
	 */
	public function exists() {
		return $this->mExists;
	}

	/**
	 * @return bool
	 */
	public function getPath() {
		return false;
	}

	/**
	 * Check if we have a good handler
	 *
	 * Built-in TiffHandler hardcodes ForeignAPIRepo. Its also not
	 * really compatible with PagedTiffHandler. So don't use it
	 *
	 * @suppress PhanUndeclaredMethod
	 * @return bool
	 */
	public function hasGoodHandler() {
		$disabled = $this->repo->getDisabledMediaHandlers();
		if ( $this->handler ) {
			foreach ( $disabled as $handler ) {
				if ( $this->handler instanceof $handler ) {
					return false;
				}
			}
		}
		return (bool)$this->handler;
	}

	/**
	 * Override to allow thumbnailing images without a handler
	 * @return bool
	 */
	public function canRender() {
		if ( $this->hasGoodHandler() ) {
			return parent::canRender();
		} else {
			// FIXME, if we're always fetching this anyways, should
			// we check it in the case we do have a handler? It might
			// be more robust in the case local has a handler that foreign
			// doesn't.
			return isset( $this->mInfo['thumburl'] ) && !$this->isIconUrl( $this->mInfo['thumburl'] );
		}
	}

	/**
	 * @note We add support for thumbnailing images with no handler based on foreign repo
	 * @param array $params
	 * @param int $flags
	 * @suppress PhanTypeMismatchDimFetch
	 * @suppress PhanParamTooMany
	 * @return bool|\MediaTransformOutput
	 */
	public function transform( $params, $flags = 0 ) {
		if ( $this->hasGoodHandler() && !parent::canRender() ) {
			// show icon
			return parent::transform( $params, $flags );
		}

		if ( $flags & self::RENDER_NOW ) {
			// what would this even mean?
			throw new \Exception( "RENDER_NOW not supported by QuickInstantCommons" );
		}

		$otherParams = $this->hasGoodHandler() ? $this->handler->makeParamString( $params ) : null;
		$width = $params['width'] ?? -1;
		$height = $params['height'] ?? -1;
		$combinedParams = (array)$otherParams + [ 'width' => $width, 'height' => $height ];

		$normalisedParams = $params;
		if ( $this->hasGoodHandler() ) {
			$this->handler->normaliseParams( $this, $normalisedParams );
		}

		$thumbUrl = false;
		$thumbWidth = $width;
		$thumbHeight = $height;
		if ( $this->repo->canTransformVia404() && $this->hasGoodHandler() ) {
			// XXX: Pass in the storage path even though we are not rendering anything
			// and the path is supposed to be an FS path. This is due to getScalerType()
			// getting called on the path and clobbering $thumb->getUrl() if it's false.
			$thumbName = $this->thumbName( $normalisedParams );
			$thumbUrl = $this->getThumbUrl( $thumbName );
			$thumb = $this->handler->getTransform( $this, "/dev/null", $thumbUrl, $params );
		} else {
			// Our repo extends the base class with an extra argument.
			$res = $this->repo->getThumbUrlFromCache(
				$this->getName(),
				$width,
				$height,
				$otherParams,
				$this->getResponsiveParams( $combinedParams )
			);
			$thumbUrl = $res['url'];
			$thumbWidth = $res['width'];
			$thumbHeight = $res['height'];
			// Hacky, try not to use fileicons in the no handler case, as that's not a real thumb.
			// We're trying to defer rendering to foreign repo, but at the same time we don't
			// want to use the fallback thumbs.
			if (
				$thumbUrl === false ||
				( !$this->handler && $this->isIconUrl( $thumbUrl ) )
			) {
				global $wgLang;

				return $this->repo->getThumbError(
					$this->getName(),
					$width,
					$height,
					$otherParams,
					$wgLang->getCode()
				);
			}
		}

		if ( $thumbWidth !== null ) {
			$params['width'] = $thumbWidth;
		}
		if ( $thumbHeight !== null ) {
			$params['height'] = $thumbHeight;
		}
		if ( $this->hasGoodHandler() ) {
			return $this->handler->getTransform( $this, 'bogus', $thumbUrl, $params );
		} else {
			return new ThumbnailImage( $this, $thumbUrl, false, $params );
		}
	}

	/**
	 * Is the url an icon image instead of a real thumbnail
	 *
	 * @param string $url
	 * @return bool
	 */
	private function isIconUrl( $url ) {
		return (bool)preg_match( '!assets/file-type-icons/fileicon[^/]*\.png$!', $url );
	}

	/**
	 * Figure out what urls to pre-fetch for responsive images
	 *
	 * @note Keep in sync in Linker::processResponsiveImages
	 * @param array $hp Image params.
	 * @return array
	 */
	private function getResponsiveParams( $hp ) {
		global $wgResponsiveImages;
		if ( !$wgResponsiveImages ) {
			return [];
		}
		$hp15 = $hp;
		$hp15['width'] = round( $hp['width'] * 1.5 );
		$hp20 = $hp;
		$hp20['width'] = $hp['width'] * 2;
		if ( isset( $hp['height'] ) && $hp['height'] !== -1 ) {
			$hp15['height'] = round( $hp['height'] * 1.5 );
			$hp20['height'] = $hp['height'] * 2;
		}

		$otherParams15 = $this->hasGoodHandler() ? $this->handler->makeParamString( $hp15 ) : null;
		$width15 = $hp15['width'] ?? -1;
		$height15 = $hp15['height'] ?? -1;

		$otherParams20 = $this->hasGoodHandler() ? $this->handler->makeParamString( $hp20 ) : null;
		$width20 = $hp20['width'] ?? -1;
		$height20 = $hp20['height'] ?? -1;
		return [
			[ $width15, $height15, $otherParams15 ],
			[ $width20, $height20, $otherParams20 ],
		];
	}

	// Info we can get from API...

	/**
	 * @param int $page
	 * @return int
	 */
	public function getWidth( $page = 1 ) {
		return isset( $this->mInfo['width'] ) ? intval( $this->mInfo['width'] ) : 0;
	}

	/**
	 * @param int $page
	 * @return int
	 */
	public function getHeight( $page = 1 ) {
		return isset( $this->mInfo['height'] ) ? intval( $this->mInfo['height'] ) : 0;
	}

	/**
	 * @return string|false
	 * @fixme This can be really big for PDFs. We should consider delaying
	 *  fetching until actually needed.
	 */
	public function getMetadata() {
		if ( isset( $this->mInfo['metadata'] ) ) {
			return serialize( self::parseMetadata( $this->mInfo['metadata'] ) );
		}

		return false;
	}

	/**
	 * @return array
	 */
	public function getMetadataArray(): array {
		if ( isset( $this->mInfo['metadata'] ) ) {
			return self::parseMetadata( $this->mInfo['metadata'] );
		}

		return [];
	}

	/**
	 * @return array|null Extended metadata (see imageinfo API for format) or
	 *   null on error
	 */
	public function getExtendedMetadata() {
		return $this->mInfo['extmetadata'] ?? null;
	}

	/**
	 * @param mixed $metadata
	 * @return array
	 */
	public static function parseMetadata( $metadata ) {
		if ( !is_array( $metadata ) ) {
			return [ '_error' => $metadata ];
		}
		'@phan-var array[] $metadata';
		$ret = [];
		foreach ( $metadata as $meta ) {
			$ret[$meta['name']] = self::parseMetadataValue( $meta['value'] );
		}

		return $ret;
	}

	/**
	 * @param mixed $metadata
	 * @return mixed
	 */
	private static function parseMetadataValue( $metadata ) {
		if ( !is_array( $metadata ) ) {
			return $metadata;
		}
		'@phan-var array[] $metadata';
		$ret = [];
		foreach ( $metadata as $meta ) {
			$ret[$meta['name']] = self::parseMetadataValue( $meta['value'] );
		}

		return $ret;
	}

	/**
	 * @return bool|int|null
	 */
	public function getSize() {
		return isset( $this->mInfo['size'] ) ? intval( $this->mInfo['size'] ) : null;
	}

	/**
	 * @return null|string
	 */
	public function getUrl() {
		return isset( $this->mInfo['url'] ) ? strval( $this->mInfo['url'] ) : null;
	}

	/**
	 * Get short description URL for a file based on the foreign API response,
	 * or if unavailable, the short URL is constructed from the foreign page ID.
	 *
	 * @return null|string
	 * @since 1.27
	 */
	public function getDescriptionShortUrl() {
		if ( isset( $this->mInfo['descriptionshorturl'] ) ) {
			return $this->mInfo['descriptionshorturl'];
		} elseif ( isset( $this->mInfo['pageid'] ) ) {
			$url = $this->repo->makeUrl( [ 'curid' => $this->mInfo['pageid'] ] );
			if ( $url !== false ) {
				return $url;
			}
		}
		return null;
	}

	/** @inheritDoc */
	public function getUploader( int $audience = self::FOR_PUBLIC, Authority $performer = null ): ?UserIdentity {
		if ( isset( $this->mInfo['user'] ) ) {
			// We don't know if the foreign repo will have a real interwiki prefix,
			// treat this user as a foreign imported user. Maybe we can do better?
			return UserIdentityValue::newExternal( $this->getRepoName(), $this->mInfo['user'] );
		}
		return null;
	}

	/**
	 * @param int $audience
	 * @param Authority|null $performer
	 * @return null|string
	 */
	public function getDescription( $audience = self::FOR_PUBLIC, Authority $performer = null ) {
		return isset( $this->mInfo['comment'] ) ? strval( $this->mInfo['comment'] ) : null;
	}

	/**
	 * @return null|string
	 */
	public function getSha1() {
		return isset( $this->mInfo['sha1'] )
			? \Wikimedia\base_convert( strval( $this->mInfo['sha1'] ), 16, 36, 31 )
			: null;
	}

	/**
	 * @return bool|string
	 */
	public function getTimestamp() {
		return wfTimestamp( TS_MW,
			isset( $this->mInfo['timestamp'] )
				? strval( $this->mInfo['timestamp'] )
				: null
		);
	}

	/**
	 * @return string
	 */
	public function getMimeType() {
		if ( !isset( $this->mInfo['mime'] ) ) {
			$magic = \MediaWiki\MediaWikiServices::getInstance()->getMimeAnalyzer();
			$this->mInfo['mime'] = $magic->getMimeTypeFromExtensionOrNull( $this->getExtension() );
		}

		return $this->mInfo['mime'];
	}

	/**
	 * @return int|string
	 */
	public function getMediaType() {
		if ( isset( $this->mInfo['mediatype'] ) ) {
			return $this->mInfo['mediatype'];
		}
		$magic = \MediaWiki\MediaWikiServices::getInstance()->getMimeAnalyzer();

		return $magic->getMediaType( null, $this->getMimeType() );
	}

	/**
	 * @return bool|string
	 */
	public function getDescriptionUrl() {
		return $this->mInfo['descriptionurl'] ?? $this->getRepo()->getDescriptionUrl( $this->getName() );
	}

	/**
	 * We hotlink.
	 * @param string $suffix
	 * @return null|string
	 */
	public function getThumbPath( $suffix = '' ) {
		return null;
	}

	/** @inheritDoc */
	public function purgeCache( $options = [] ) {
		// @phan-suppress-next-line PhanUndeclaredMethod
		$this->repo->purgeMetadata( $this->getName() );
		$this->purgeDescriptionPage();
	}

	private function purgeDescriptionPage() {
		$services = MediaWikiServices::getInstance();
		$langCode = $services->getContentLanguage()->getCode();

		$key = $this->repo->getLocalCacheKey( 'file-remote-description', $langCode, md5( $this->getName() ) );
		$services->getMainWANObjectCache()->delete( $key );
	}

	/**
	 * The thumbnail is created on the foreign server and fetched over internet
	 * @since 1.25
	 * @return bool
	 */
	public function isTransformedLocally() {
		return false;
	}

	/**
	 * Same as parent except supporting ##URLBASEPATH## for autodetection
	 *
	 * In a recursive repo setup, there isn't a single ThumbUrl. For example,
	 * if using en.wikipedia.org as a foreign repo, the thumb url depends on
	 * if the image is local or commons. So we support ##URLBASEPATH## as
	 * the base of the main url to "guess". This is a bit hacky but seems to
	 * solve the problem without having to make more requests.
	 *
	 * @param string|bool $suffix Name of thumbnail file
	 * @return string
	 */
	public function getThumbUrl( $suffix = false ) {
		$res = parent::getThumbUrl( $suffix );
		// The following is a bit hacky, but try to autodetect thumb url.
		// This allows us to support 404 handling on repos like en.wikipedia
		// that are recursive where some images are local and some are from commons.
		if ( strpos( $res, '##URLBASEPATH##' ) !== false ) {
			$count = 0;
			$baseUrl = preg_replace( '/\/[a-z0-9]\/[a-z0-9][a-z0-9]\/[^\/]*$/', '', $this->getUrl(), 1, $count );
			if ( $count !== 1 || $this->repo->getHashLevels() !== 2 ) {
				throw new \Exception( "Error replacing ##URLBASEPATH##. Try disabling transformVia404" );
			}
			$res = str_replace( '##URLBASEPATH##', $baseUrl, $res );
		}
		return $res;
	}

	/**
	 * Get the HTML text of the description page, if available
	 * @stable to override
	 *
	 * Basically copied from core, except we use getDescriptionRenderUrl
	 * from File instead of Repo to better support recursive repos like
	 * en.wikipedia.org
	 *
	 * @param \Language|null $lang Optional language to fetch description in
	 * @return string|false HTML
	 * @return-taint escaped
	 */
	public function getDescriptionText( \Language $lang = null ) {
		global $wgLang;

		if ( !$this->repo || !$this->repo->fetchDescription ) {
			return false;
		}

		$lang = $lang ?? $wgLang;

		$renderUrl = $this->getDescriptionRenderUrl( $lang->getCode() );
		if ( $renderUrl ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$key = $this->repo->getLocalCacheKey(
				'file-remote-description',
				$lang->getCode(),
				md5( $this->getName() )
			);
			$fname = __METHOD__;

			// FIXME, in future we want to reuse the HTTP connection, and maybe
			// do adaptive caching based on last mod time.
			// Also we should return false for 404 instead of showing 404 page.
			return $cache->getWithSetCallback(
				$key,
				$this->repo->descriptionCacheExpiry ?: $cache::TTL_UNCACHEABLE,
				function ( $oldValue, &$ttl, array &$setOpts ) use ( $renderUrl, $fname ) {
					wfDebug( "Fetching shared description from $renderUrl" );
					$res = $this->repo->httpGet( $renderUrl );
					// @phan-suppress-next-line PhanTypeMismatchDimFetch
					$code = $res[0]['response']['code'] ?? 0;
					// @phan-suppress-next-line PhanTypeMismatchDimFetch
					$body = $res[0]['response']['body'] ?? false;
					if ( !$res || $code != 200 ) {
						$ttl = \WANObjectCache::TTL_UNCACHEABLE;
					}

					return $body;
				}
			);
		}

		return false;
	}

	/**
	 * Override just for getDescriptionUrl()
	 *
	 * @param string $lang langcode
	 * @return string|bool url
	 */
	private function getDescriptionRenderUrl( $lang ) {
		$query = 'action=render';
		if ( $lang !== null ) {
			$query .= '&uselang=' . urlencode( $lang );
		}
		$descUrl = $this->getDescriptionUrl();
		if ( $descUrl ) {
			return wfAppendQuery( $descUrl, $query );
		} else {
			return false;
		}
	}
}
