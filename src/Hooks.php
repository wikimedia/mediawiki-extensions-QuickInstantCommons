<?php
namespace MediaWiki\Extension\QuickInstantCommons;

use MediaWiki\Content\Hook\ContentGetParserOutputHook;
use MediaWiki\logger\LoggerFactory;
use MediaWiki\Page\Hook\ImageOpenShowImageInlineBeforeHook;

class Hooks implements ContentGetParserOutputHook, ImageOpenShowImageInlineBeforeHook {

	/** @var \Config */
	private $config;
	/** @var \Psr\Log\LoggerInterface */
	private $logger;
	/** @var \RepoGroup */
	private $repoGroup;

	/**
	 * @param \Config $config
	 * @param \RepoGroup $repoGroup
	 */
	public function __construct( \Config $config, \RepoGroup $repoGroup ) {
		$this->config = $config;
		$this->repoGroup = $repoGroup;
		$this->logger = LoggerFactory::getInstance( 'quickinstantcommons' );
	}

	public static function setup() {
		global $wgForeignFileRepos, $wgUploadDirectory, $wgUseQuickInstantCommons;

		if ( !interface_exists( '\IForeignRepoWithMWApi' ) ) {
			// Compatibility with MW < 1.38.
			require __DIR__ . '/../stubs/IForeignRepoWithMWApi.php';
		}
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
		$dbr = wfGetDB( DB_REPLICA );

		// Get all images previously used in this article that aren't local.
		$res = $dbr->selectFieldValues(
			[ 'imagelinks', 'image' ],
			'il_to',
			[ 'il_from' => $title->getArticleId(), 'img_name' => null ],
			__METHOD__,
			[ 'LIMIT' => $limit ],
			[
				'image' => [
					'LEFT JOIN',
					'il_to = img_name'
				]
			]
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
