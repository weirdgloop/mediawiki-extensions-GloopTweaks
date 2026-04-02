<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\Content\Content;
use MediaWiki\Content\TextContent;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\AtEase\AtEase;

class GloopTweaksUtils {
	public static function currentWikiIsNetworkCentralWiki() {
		global $wgDBname, $wgGloopTweaksNetworkCentralDB;

		return $wgGloopTweaksNetworkCentralDB && $wgDBname === $networkCentralDB;
	}
	/**
	 * @return BagOStuff
	 */
	public static function getNetworkCentralCache() {
		global $wgGloopTweaksNetworkCentralCacheType;

		$objectCacheFactory = MediaWikiServices::getInstance()->getObjectCacheFactory();
		if ( $wgGloopTweaksNetworkCentralCacheType !== null ) {
			return $objectCacheFactory->getInstance( $wgGloopTweaksNetworkCentralCacheType );
		} else {
			return $objectCacheFactory->getLocalClusterInstance();
		}
	}

	/**
	 * Prepare the Special:Contact filter regexes.
	 * @return array
	 */
	private static function getContactFilter() {
		global $wgGloopTweaksNetworkCentralContactFilterUrl;

		$text = '';
		if ( $wgGloopTweaksNetworkCentralContactFilterUrl ) {
			$text = MediaWikiServices::getInstance()->getHttpRequestFactory()
				->get( $wgGloopTweaksNetworkCentralContactFilterUrl, [], __METHOD__ ) ?? '';
		}

		$regexes = [];

		$lines = preg_split( "/\r?\n/", $text );
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
	 * @param WebRequest &$request
	 * @param array $cacheTags
	 */
	public static function addCacheTag( WebRequest &$request, array $cacheTags ) {
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
	 * Implements spam filter for Special:Contact, checks against [[MediaWiki:Weirdgloop-contact-filter]] on metawiki.
	 * Regex per line and use '#' for comments.
	 *
	 * @param string $text - The message text to check for spam.
	 * @return bool
	 */
	public static function checkContactFilter( $text ) {
		$cache = self::getNetworkCentralCache();

		$regexes = $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				'GloopTweaks',
				'contact-filter-regexes'
			),
			// 1 hour cache time.
			3600,
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
