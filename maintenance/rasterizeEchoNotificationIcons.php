<?php
// Rasterize Echo notification icons for use in HTML emails as that is the only remaining use of rsvg-convert.

namespace MediaWiki\Extension\GloopTweaks\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use SvgHandler;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class RasterizeEchoNotificationIcons extends Maintenance {
	public function execute() {
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
		global $IP, $wgSVGConverter;
		// Force direct use of rsvg as converter.
		$wgSVGConverter = 'rsvg';

		$this->output( "Rasterizing Echo notification icons...\n" );

		$patterns = [
			"$IP/extensions/Echo/modules/icons/*.svg",
			"$IP/extensions/LoginNotify/*.svg",
			"$IP/extensions/Thanks/modules/*.svg",
		];

		foreach ( $patterns as $pattern ) {
			$paths = glob( $pattern );

			foreach ( $paths as $path ) {
				$this->output( "Rasterizing '$path'.\n" );
				self::rasterize( $path );
			}
		}
	}

	/**
	 * @param string $path
	 * @return void
	 */
	private static function rasterize( $path ) {
		$handler = new SvgHandler;
		// This is an improvement over the default rasterization, which generates a 20x20 image,
		// but gets stretched to 30x30 in HTML emails.
		$handler->rasterize(
			$path,
			"$path.png",
			30,
			30
		);
	}
}

$maintClass = RasterizeEchoNotificationIcons::class;
require_once RUN_MAINTENANCE_IF_MAIN;
