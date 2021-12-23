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
				// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
				$newtitle = Title::newFromText( $data['query']['redirects'][$lastRedirect]['to'] );
				$img = new self( $newtitle, $repo, $info, true );
				$img->redirectedFrom( $title->getDBkey() );
			} else {
				$img = new self( $title, $repo, $info, true );
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
	 * @return bool
	 */
	public function hasGoodHandler() {
		return $this->handler && !$this->handler instanceof \TiffHandler;
	}

	/**
	 * Override to allow thumbnailing images without a handler
	 * @return bool
	 */
	public function canRender() {
		if ( $this->hasGoodHandler() ) {
			return parent::canRender();
		} else {
			// Even if we don't have handler, try to render anyways.
			// FIXME: Is there some way we could know what width we need, so we
			// don't end up making multiple requests?
			return $this->transform( [ 'width' => '100' ] ) instanceof ThumbnailImage;
		}
	}

	/**
	 * @note We add support for thumbnailing images with no handler based on foreign repo
	 * @param array $params
	 * @param int $flags
	 * @suppress PhanTypeMismatchDimFetch
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
			$res = $this->repo->getThumbUrlFromCache(
				$this->getName(),
				$width,
				$height,
				$otherParams
			);
			$thumbUrl = $res['url'];
			$thumbWidth = $res['width'];
			$thumbHeight = $res['height'];
			// Hacky, try not to use fileicons in the no handler case, as that's not a real thumb.
			// We're trying to defer rendering to foreign repo, but at the same time we don't
			// want to use the fallback thumbs.
			if (
				$thumbUrl === false ||
				( !$this->handler && preg_match( '!assets/file-type-icons/fileicon[^/]*\.png$!', $thumbUrl ) )
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
		return $this->mInfo['descriptionurl'] ?? false;
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
		$url = $this->repo->getDescriptionRenderUrl(
			$this->getName(),
			$services->getContentLanguage()->getCode()
		);

		$key = $this->repo->getLocalCacheKey( 'file-remote-description', md5( $url ) );
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
			$baseUrl = preg_replace( '/\/.\/..\/.*$/', '', $this->getUrl(), 1, $count );
			if ( $count !== 1 ) {
				throw new \Exception( "Error replacing ##URLBASEPATH##. Try disabling transformVia404" );
			}
			$res = str_replace( '##URLBASEPATH##', $baseUrl, $res );
		}
		return $res;
	}
}
