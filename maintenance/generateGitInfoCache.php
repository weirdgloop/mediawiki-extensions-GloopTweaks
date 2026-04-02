<?php

namespace MediaWiki\Extension\GloopTweaks\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Utils\GitInfo;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

define( 'MW_NO_SESSION_HANDLER', 1 );
define( 'MW_NO_SESSION', 1 );

class GenerateGitInfoCache extends Maintenance {
	public function execute() {
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
		global $IP;

		$this->output( "Generating GitInfo cache...\n" );

		$patterns = [
			"$IP",
			"$IP/extensions/*",
			"$IP/skins/*",
		];

		foreach ( $patterns as $pattern ) {
			$directories = glob( $pattern );

			foreach ( $directories as $directory ) {
				if ( is_dir( $directory ) ) {
					$this->output( "Generating GitInfo cache for '$directory'.\n" );
					( new GitInfo( $directory, false ) )->precomputeValues();
				}
			}
		}
	}
}

$maintClass = GenerateGitInfoCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
