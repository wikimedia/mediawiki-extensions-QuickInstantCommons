The QuickInstantCommons extension is a performance optimized version of MediaWiki core's $wgUseInstantCommons feature. It also allows basic thumbnailing of files that are missing a MediaHandler extension on the local wiki. For example, the first page of a PDF will still thumbnail even without Extension:PDFHandler installed, but advanced features like multipage requires the extension to be installed locally.

Initial testing on an uncached page with 85 images caused the time to render to drop from 17 minutes to 1.1 seconds.

It does a number of things to improve performance, but the most important are reusing HTTP/2 connections and avoiding unnecessary API requests. It will also try to prefetch images based on the imagelinks DB table.

To use, simply put the QuickInstantCommons directory in your MediaWiki extensions directory, and add:

wfLoadExtension( 'QuickInstantCommons' );

to the bottom of your LocalSettings.php

For more information and advanced configuration see https://www.mediawiki.org/wiki/Extension:QuickInstantCommons

Note: This extension includes modified code from MediaWiki core, which is licensed under the GPL 2 or later and copyright the respective contributors to MediaWiki.
