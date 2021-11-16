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

	public function __construct( \Config $config, \RepoGroup $repoGroup ) {
		$this->config = $config;
		$this->repoGroup = $repoGroup;
		$this->logger = LoggerFactory::getInstance( 'quickinstantcommons' );
	}

	public static function setup() {
		global $wgForeignFileRepos, $wgUploadDirectory, $wgUseQuickInstantCommons;

		// For reference, this code is executed after LocalSettings.php but before most of Setup.php
		// Setup.php will add a filebackend entry to this.
		if ( $wgUseQuickInstantCommons ) {
			$wgForeignFileRepos[] = [
				'class' => 'MediaWiki\Extension\QuickInstantCommons\Repo', // ::class not registered yet.
				'name' => 'commonswiki', // Must be a distinct name
				'directory' => $wgUploadDirectory, // FileBackend needs some value here.
				'apibase' => 'https://commons.wikimedia.org/w/api.php',
				'hashLevels' => 2,
				'thumbUrl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb',
				'fetchDescription' => true, // Optional
				'descriptionCacheExpiry' => 43200, // 12 hours, optional (values are seconds)
				'transformVia404' => true,
				'abbrvThreshold' => 160,
			];
		}
	}

	public function onContentGetParserOutput( $content, $title, $revId, $options, $generateHtml, &$po ) {
		if ( !$this->config->get( 'QuickInstantCommonsPrefetch' ) ) {
			return;
		}

		if ( !$options->getEnableLimitReport() || !$generateHtml ) {
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

	public function onImageOpenShowImageInlineBefore( $imagePage, $output ) {
		$file = $imagePage->getDisplayedFile();
		if ( $file && $file instanceof File ) {
			if ( !$file->getHandler() && $file->canRender() ) {
				$output->addWikiMsg( 'quickinstantcommons-missinghandler' );
			}
		}
	}
}
