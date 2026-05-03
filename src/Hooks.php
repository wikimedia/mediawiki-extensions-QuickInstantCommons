<?php
namespace MediaWiki\Extension\QuickInstantCommons;

use MediaWiki\Content\Hook\ContentGetParserOutputHook;
use MediaWiki\logger\LoggerFactory;
use MediaWiki\Page\Hook\ImageOpenShowImageInlineBeforeHook;
use Wikimedia\Rdbms\IConnectionProvider;

class Hooks implements ContentGetParserOutputHook, ImageOpenShowImageInlineBeforeHook {

	private IConnectionProvider $dbProvider;
	/** @var \Config */
	private $config;
	/** @var \Psr\Log\LoggerInterface */
	private $logger;
	/** @var \RepoGroup */
	private $repoGroup;

	public function __construct( IConnectionProvider $dbProvider, \Config $config, \RepoGroup $repoGroup ) {
		$this->dbProvider = $dbProvider;
		$this->config = $config;
		$this->repoGroup = $repoGroup;
		$this->logger = LoggerFactory::getInstance( 'quickinstantcommons' );
	}

	public static function setup() {
		global $wgForeignFileRepos, $wgUploadDirectory, $wgUseQuickInstantCommons,
			$wgThumbnailSteps;

		if ( $wgUseQuickInstantCommons ) {
			$wgForeignFileRepos[] = [
				'class' => Repo::class,
				'name' => 'wikimediacommons', // "wikimediacommons" triggers builtin i18n.
				'directory' => $wgUploadDirectory, // FileBackend needs some value here.
				'apibase' => 'https://commons.wikimedia.org/w/api.php',
				'hashLevels' => 2,
				'thumbUrl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb',
				'fetchDescription' => true, // Optional
				'descriptionCacheExpiry' => 43200, // 12 hours, optional (values are seconds)
				'transformVia404' => true,
				'abbrvThreshold' => 160,
				// Normally set by SetupDynamicConfig.php.
				'backend' => 'wikimediacommons-backend'
			];
			// If transformVia404 is true, its important this matches Wikipedia's value.
			$wgThumbnailSteps = [ 20, 40, 60, 120, 250, 330, 500, 960, 1280, 1920, 3840 ];
		}
	}

	/** @inheritDoc */
	public function onContentGetParserOutput( $content, $title, $revId, $options, $generateHtml, &$po ) {
		if ( !$this->config->get( 'QuickInstantCommonsPrefetch' ) ) {
			return;
		}

		if ( !$generateHtml ) {
			// Skipping. Probably a false positive of some kind
			$this->logger->warning(
				"Skipping prefetch on {title} limit report={limitreport}; generate html={generatehtml}",
				[
					'title' => $title->getPrefixedDBKey(),
					'limitreport' => $options->getEnableLimitReport(),
					'generatehtml' => $generateHtml,
					'method' => __METHOD__
				]
			);
			return;
		}
		$limit = $this->config->get( 'QuickInstantCommonsPrefetchMaxLimit' );
		$dbr = $this->dbProvider->getReplicaDatabase();

		// Get all images previously used in this article that aren't local.
		// Handle both MW 1.46+ linktarget and older versions using il_to. (T419819)
		if ( version_compare( MW_VERSION, '1.46', '<' ) ) {
			$tables = [ 'imagelinks', 'image' ];
			$field = 'il_to';
			$joinConds = [
				'image' => [ 'LEFT JOIN', 'il_to = img_name' ],
			];
		} else {
			$tables = [ 'imagelinks', 'linktarget', 'image' ];
			$field = 'lt_title';
			$joinConds = [
				'linktarget' => [ 'JOIN', 'il_target_id = lt_id' ],
				'image' => [ 'LEFT JOIN', 'lt_title = img_name' ],
			];
		}

		$res = $dbr->selectFieldValues(
			$tables,
			$field,
			[ 'il_from' => $title->getArticleId(), 'img_name' => null ],
			__METHOD__,
			[ 'LIMIT' => $limit ],
			$joinConds
		);
		if ( count( $res ) ) {
			$this->repoGroup->forEachForeignRepo( static function ( $repo, $res ) {
				if ( $repo instanceof Repo ) {
					$repo->prefetchImgMetadata( $res );
				}
			}, [ $res ] );
		}
	}

	/** @inheritDoc */
	public function onImageOpenShowImageInlineBefore( $imagePage, $output ) {
		$file = $imagePage->getDisplayedFile();
		if ( $file && $file instanceof File ) {
			if ( !$file->hasGoodHandler() && $file->canRender() ) {
				$fileUrl = $file->getDescriptionUrl();
				// Duplicates $file->getRepo()->getDisplayName(); but with different fallback
				$repoName = wfMessageFallback(
					// When using the automatic setup, shared-repo-name-wikimediacommons is used.
					// Which is built into core.
					'shared-repo-name-' . $file->getRepo()->getName(),
					'quickinstantcommons-shared-repo'
				)->plain();
				$output->wrapWikiMsg(
					"<div class='toccolours quickinstantcommons-missinghandler' style='margin-bottom: 4px'>$1</div>",
					[ 'quickinstantcommons-missinghandler', $fileUrl, $repoName ]
				);
			}
		}
	}
}
