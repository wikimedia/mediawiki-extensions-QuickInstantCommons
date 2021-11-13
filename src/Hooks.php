<?php
namespace MediaWiki\Extension\QuickInstantCommons;

class Hooks {
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
}
