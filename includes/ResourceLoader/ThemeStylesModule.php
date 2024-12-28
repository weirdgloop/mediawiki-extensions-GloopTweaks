<?php

namespace MediaWiki\Extension\GloopTweaks\ResourceLoader;

use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\WikiModule;

class ThemeStylesModule extends WikiModule {
	/**
	 * @var string
	 */
	private $theme;

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$this->theme = $options['theme'];
	}

	// 'site' should be used, but can't as this module needs to load after 'site.styles'.
	public function getGroup() {
		return 'user';
	}

	/**
	 * Get list of pages used by this module
	 *
	 * @param Context $context
	 * @return array[]
	 */
	protected function getPages( Context $context ) {
		$pages = [];
		$skin = ucfirst( $context->getSkin() );

		// i.e. MediaWiki:Vector-theme-dark.css
		$pages["MediaWiki:$skin-theme-{$this->theme}.css"] = [ 'type' => 'style' ];

		// Legacy dark mode stylesheet
		if ( $this->theme === 'dark' ) {
			$pages['MediaWiki:' . ucfirst( $skin ) . '-darkmode.css'] = [ 'type' => 'style' ];
		}
		return $pages;
	}
}
