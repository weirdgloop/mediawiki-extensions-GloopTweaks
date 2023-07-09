<?php
// This file is intended to be symlinked into $IP.
// Based on https://github.com/wikimedia/operations-mediawiki-config/blob/4cd21ef34b81dd10b04c230f7e9eedc66bce1a87/w/static.php

define( 'MW_NO_SESSION', 1 );
define( 'MW_ENTRY_POINT', 'static' );

require dirname($_SERVER['SCRIPT_FILENAME']) . '/includes/WebStart.php';

function wfStaticShowError ( $status ) {
	header( 'Cache-Control: public, max-age=0, must-revalidate, s-maxage=60, stale-while-revalidate=60' );
	HttpStatus::header( $status );
	return;
}

function wfStaticMain() {
	// REQUEST_URI is used to determine the resource to retrieve, we must fail without it.
	if ( !isset( $_SERVER['REQUEST_URI'] ) ) {
		wfStaticShowError( 500 );
	}

	$urlPath = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

	// Reject bad URLs, non-allowed paths, non-allowed extensions, and dot paths.
	if ( !$urlPath || !preg_match( '/^\/(extensions|resources|skins)\/.+\.(css|cur|gif|jpg|jpeg|js|png|svg|wasm)$/', $urlPath ) || strpos( $urlPath, '/.' ) !== false ) {
		wfStaticShowError( 404 );
		return;
	}

	// Strip leading slash.
	$filePath = substr( $urlPath, 1 );

	$ctype = StreamFile::contentTypeFromPath( $filePath, /* safe: not for upload */ false );
	if ( !$ctype || $ctype === 'unknown/unknown' ) {
		// Directory, extension-less file or unknown extension
		wfStaticShowError( 404 );
		return;
	}

	// Keep track of how well the requests are being cached.
	$stats = MediaWiki\MediaWikiServices::getInstance()->getStatsdDataFactory();

	$stat = stat( $filePath );
	if ( !$stat ) {
		$stats->increment( 'wglstatic.notfound' );
		wfStaticShowError( 404 );
		return;
	}

	header( 'Access-Control-Allow-Origin: *' );
	header( 'Last-Modified: ' . wfTimestamp( TS_RFC2822, $stat['mtime'] ) );
	header( "Content-Type: $ctype" );

	$urlHash = $_SERVER['QUERY_STRING'] ?? '';

	// No hash was provided, since that is unusual for static resources, cache it for a day.
	if ( !$urlHash ) {
		$stats->increment( 'wglstatic.nohash' );
		header( 'Cache-Control: public, max-age=86400, must-revalidate, s-maxage=86400, stale-while-revalidate=300' );
	}
	// Otherwise either short cache if it is a mismatch, or immutable if it matches or wouldn't be produced by MediaWiki.
	else {
		$validHash = preg_match( '/^[a-fA-F0-9]{5}$/', $urlHash );
		$fileHash = $validHash ? substr( md5_file( $filePath ), 0, 5 ) : null;

		// If the hash is invalid, or it matches, it'll never change, so treat it as immutable.
		if ( !$validHash || $urlHash === $fileHash ) {
			$stats->increment( 'wglstatic.immutable' );
			header( 'Cache-Control: public, max-age=31536000, immutable' );
		}
		// Otherwise, it mismatched, so make sure the resource stays reasonably fresh.
		else {
			$stats->increment( 'wglstatic.mismatch' );
			header( 'Cache-Control: public, max-age=0, must-revalidate, s-maxage=60, stale-while-revalidate=60' );
		}
	}

	if ( !empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$ims = preg_replace( '/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( wfTimestamp( TS_UNIX, $stat['mtime'] ) <= strtotime( $ims ) ) {
			ini_set( 'zlib.output_compression', 0 );
			header( 'HTTP/1.1 304 Not Modified' );
			return;
		}
	}

	header( 'Content-Length: ' . $stat['size'] );
	readfile( $filePath );
}

wfResetOutputBuffers();
wfStaticMain();

// Presumably needed for stats collection.
$mediawiki = new MediaWiki();
$mediawiki->doPostOutputShutdown();