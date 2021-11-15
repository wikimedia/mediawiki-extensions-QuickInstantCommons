<?php
namespace MediaWiki\Extension\QuickInstantCommons;

use MediaWiki\Content\Hook\ContentGetParserOutputHook;
use MediaWiki\logger\LoggerFactory;

class Hooks implements ContentGetParserOutputHook {

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
		// FIXME how safe are globals here?
		global $wgForeignFileRepos, $wgUploadDirectory, $wgUseQuickInstantCommons;

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
				'apiThumbCacheExpiry' => 0, // 24 hours, optional, but required for local thumb caching
				'transformVia404' => true,
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
var_dump( "skip" );
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
}
