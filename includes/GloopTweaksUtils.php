<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\SlotRecord;
use TextContent;
use Wikimedia\AtEase\AtEase;

class GloopTweaksUtils {
	// Retrieve Special:Contact filter text from central DB.
	private static function getContactFilterText() {
		global $wgGloopTweaksCentralDB;
		$services = MediaWikiServices::getInstance();

		$title = $services->getTitleParser()->parseTitle( 'MediaWiki:Weirdgloop-contact-filter' );
		$store = $services->getRevisionStoreFactory()->getRevisionStore( $wgGloopTweaksCentralDB );
		$rev = $store->getRevisionByTitle( $title );

		$content = $rev ? $rev->getContent( SlotRecord::MAIN ) : null;

		if ( !( $content instanceof TextContent ) ) {
			return '';
		}

		return $content->getText();
	}

	// Prepare the Special:Contact filter regexes.
	private static function getContactFilter() {
		$filterText = self::getContactFilterText();
		$regexes = [];

		$lines = preg_split( "/\r?\n/", $filterText );
		foreach ( $lines as $line ) {
			// Strip comments and whitespace.
			$line = preg_replace( '/#.*$/', '', $line );
			$line = trim( $line );

			// If anything is left, assume it's a valid regex.
			if ( $line !== '' ) {
				$regexes[] = $line;
			}
		}

		return $regexes;
	}

	/**
	 * Implements spam filter for Special:Contact, checks against [[MediaWiki:Weirdgloop-contact-filter]] on metawiki. Regex per line and use '#' for comments.
	 *
	 * @param string $text - The message text to check for spam.
	 * @return bool
	 */
	public static function checkContactFilter( $text ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		$regexes = $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				'GloopTweaks',
				'contact-filter-regexes'
			),
			300, // 5 minute cache time as this isn't a high frequency check.
			function () {
				return self::getContactFilter();
			}
		);

		if ( !count( $regexes ) ) {
			// No regexes to check.
			return true;
		}

		// Compare message text against each regex.
		foreach ( $regexes as $regex ) {
			AtEase::suppressWarnings();
			$match = preg_match( $regex, $text );
			AtEase::restoreWarnings();
			if ( $match ) {
				return false;
			}
		}

		return true;
	}
}