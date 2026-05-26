<?php
// This file is intended to be symlinked into $IP.

use MediaWiki\MediaWikiServices;

define( 'MW_NO_SESSION', 1 );
define( 'MW_ENTRY_POINT', 'stream_file' );

require dirname( $_SERVER['SCRIPT_FILENAME'] ) . '/includes/WebStart.php';

// phpcs:ignore MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
wfStreamFileMain( $_GET );

/**
 * @param array $params
 * @return void
 */
function wfStreamFileMain( array $params ) {
	global $wgDBname;

	// Only allow a limited set of files as this is intended to deal with non-page requests
	// fetching "well-known" file URLs.
	$allowedFiles = [
		'Apple-touch-icon.png',
		'Favicon.ico',
	];

	$fileName = $params['f'] ?? '';
	if ( !in_array( $fileName, $allowedFiles ) ) {
		header( 'Cache-Control: public, max-age=0, must-revalidate, s-maxage=300' );
		HttpStatus::header( 400 );
		return;
	}

	$repo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
	$file = $repo->newFile( $fileName );

	if ( $file && $file->exists() ) {
		$headers = [
			'Cache-Control: max-age=300, must-revalidate, s-maxage=3600, revalidate-while-stale=300',
		];
		$pageIdForCache = $file->getTitle() ? $file->getTitle()->getId() : null;
		if ( $pageIdForCache ) {
			$headers[] = "Cache-Tag: $wgDBname:page:$pageIdForCache";
		}
		$repo->streamFileWithStatus( $file->getPath(), $headers );
	} else {
		// Shorter 404.
		header( 'Cache-Control: max-age=300, must-revalidate, s-maxage=3600, revalidate-while-stale=300' );
		HttpStatus::header( 404 );
	}
}
