<?php

namespace MediaWiki\Extension\GloopTweaks\ResourceLoader;

use MediaWiki\Extension\GloopTweaks\GloopTweaksUtils;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\SiteStylesModule;

class FixedWidthStyleModule extends SiteStylesModule {
	/**
	 * @param string $titleText
	 * @param Context $context
	 * @return null|string
	 * @since 1.32 added the $context parameter
	 */
	protected function getContent( $titleText, Context $context ) {
		global $wgGloopTweaksFamilyCentralDB;

		$content = GloopTweaksUtils::getContentFromWiki(
			MediaWikiServices::getInstance(),
			$titleText,
			$wgGloopTweaksFamilyCentralDB
		);

		if ( !$content ) {
			return null; // No content found
		}

		$handler = $content->getContentHandler();
		if ( $handler->isSupportedFormat( CONTENT_FORMAT_CSS ) ) {
			$format = CONTENT_FORMAT_CSS;
		} elseif ( $handler->isSupportedFormat( CONTENT_FORMAT_JAVASCRIPT ) ) {
			$format = CONTENT_FORMAT_JAVASCRIPT;
		} else {
			return null; // Bad content model
		}

		return $content->serialize( $format );
	}

	// Override getDB() to use family main wiki rather than having a per-wiki MediaWiki:Vector-fixedwidth.css.
	protected function getDB() {
		global $wgGloopTweaksFamilyCentralDB;
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wgGloopTweaksFamilyCentralDB );
		return $lb->getConnection( DB_REPLICA, [], $wgGloopTweaksFamilyCentralDB );
	}

	/**
	 * Get list of pages used by this module
	 *
	 * @param Context $context
	 * @return array[]
	 */
	protected function getPages( Context $context ) {
		$pages = [];
		if ( $this->getConfig()->get( 'UseSiteCss' ) ) {
			$skin = $context->getSkin();
			$pages['MediaWiki:' . ucfirst( $skin ) . '-fixedWidth.css'] = [ 'type' => 'style' ];
		}
		return $pages;
	}

	// 'site' should be used, but can't as this module needs to load after 'site.styles'.
	public function getGroup() {
		return 'user';
	}
}
