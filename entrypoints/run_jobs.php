<?php
// This file is intended to be symlinked into $IP.

/** This code is derived from the following sources:
 *  - https://github.com/wikimedia/mediawiki/blob/1.41.1/includes/specials/SpecialRunJobs.php for its validation.
 *  - https://github.com/wikimedia/operations-mediawiki-config/blob/0d9039491711b014c145aa82a1ea5af504e30e8f/rpc/RunSingleJob.php for a current take on this approach.
 *  - https://github.com/wikimedia/operations-mediawiki-config/blob/ca3b94f2d9bc755d92839e5e69072615ea9008df/rpc/RunJobs.php for Wikimedia's last redis-based jobrunner approach.
 * */

// Validate request method
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	http_response_code( 405 );
	header( 'Allow: POST' );
	die( "Request must use POST.\n" );
}

// Validate request parameters
$requiredNames = [ 'db', 'sigexpiry', 'signature' ];
$missingNames = array_diff( $requiredNames, array_keys( $_GET ) );
if ( count( $missingNames ) ) {
	http_response_code( 400 );
	die( 'Missing URL parameters: ' . implode( ', ', array_values( $missingNames ) ) . "\n" );
}

// Set database from 'db' request parameter
define( 'MW_DB', $_GET['db'] );

// Load MediaWiki
define( 'MEDIAWIKI_JOB_RUNNER', 1 );
define( 'MW_ENTRY_POINT', 'run_jobs' );
define( 'MW_NO_SESSION', 1 );
require dirname( $_SERVER['SCRIPT_FILENAME'] ) . '/includes/WebStart.php';

// Verify request signature
$signedNames = [ 'db', 'maxjobs', 'maxmem', 'maxtime', 'sigexpiry', 'type' ];
$signedParams = array_filter( $_GET, function( $name ) use ( $signedNames ) {
    return in_array( $name, $signedNames );
}, ARRAY_FILTER_USE_KEY );
ksort( $signedParams );
$expectedSignature = hash_hmac( 'sha1', http_build_query( $signedParams ), $wgSecretKey );
$providedSignature = $_GET['signature'];
$verified = is_string( $providedSignature ) && hash_equals( $expectedSignature, $providedSignature );
if ( !$verified || $_GET['sigexpiry'] < time() ) {
	http_response_code( 400 );
	die( "Invalid or stale signature provided.\n" );
}

// Fatals but not random I/O warnings
error_reporting( E_ERROR );
ini_set( 'display_errors', 1 );
$wgShowExceptionDetails = true;

// Set job memory limit from 'maxmem' request parameter. This is missing from other non-CLI implementations.
if ( $_GET['maxmem'] !== '' ) {
	ini_set( 'memory_limit', $_GET['maxmem'] );
}

// Session consistency is not helpful here and will slow things down in some cases
$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
$lbFactory->disableChronologyProtection();

try {
	$mediawiki = new MediaWiki();
	$runner = new JobRunner();
	$response = $runner->run( [
		'maxJobs' => $_GET['maxjobs'] ?? 5000,
		'maxTime' => $_GET['maxtime'] ?? 30,
		// Run any job type by default.
		'type' => $_GET['type'] ?? false,
	] );

	print json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	$mediawiki->restInPeace();
} catch ( Exception $e ) {
	http_response_code( 500 );
	MWExceptionHandler::rollbackPrimaryChangesAndLog( $e );
}
