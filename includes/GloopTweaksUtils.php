<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Content\TextContent;
use Wikimedia\AtEase\AtEase;

class GloopTweaksUtils {
	public static function getContentFromWiki( MediaWikiServices $services, string $pageName, string $wiki ) {
		$targetWikiIsCurrentWiki = $wiki === $services->getMainConfig()->get( MainConfigNames::DBname );
		$page = $services->getPageStoreFactory()
			->getPageStore( $targetWikiIsCurrentWiki ? WikiAwareEntity::LOCAL : $wiki )
			->getPageByText( $pageName );
		$rev = $services->getRevisionStoreFactory()
			->getRevisionStore($targetWikiIsCurrentWiki ? WikiAwareEntity::LOCAL : $wiki )
			->getRevisionByTitle( $page );
		$content = $rev ? $rev->getContent( SlotRecord::MAIN ) : null;

		return $content;
	}

	// Prepare the Special:Contact filter regexes.
	private static function getContactFilter() {
		global $wgGloopTweaksNetworkCentralDB;

		$filterContent = self::getContentFromWiki( MediaWikiServices::getInstance(),
			'MediaWiki:Weirdgloop-contact-filter', $wgGloopTweaksNetworkCentralDB );
		if ( !( $filterContent instanceof TextContent ) ) {
			$filterText = '';
		} else {
			$filterText = $filterContent->getText();
		}

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
	 * Adds the Cache-Tag header to the request.
	 */
	public static function addCacheTag( &$request, $cacheTags ) {
		global $wgGloopTweaksCacheTagDebug;
		if ( count( $cacheTags ) > 0 ) {
			$request->response()->header( 'Cache-Tag:' . implode( ',', $cacheTags ), false );
			// Cloudflare strips Cache-Tag from the response, so it's useful to add it in another header for debugging.
			if ( $wgGloopTweaksCacheTagDebug ) {
				$request->response()->header( 'X-Cache-Tag:' . implode( ',', $cacheTags ), false );
			}
		}
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
