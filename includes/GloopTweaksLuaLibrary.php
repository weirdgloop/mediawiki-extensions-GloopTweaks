<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\MediaWikiServices;

class GloopTweaksLuaLibrary extends LibraryBase {
	/** @inheritDoc */
	public function register() {
		$lib = [
			'filepath' => [ $this, 'filepath' ],
		];

		return $this->getEngine()->registerInterface(
			__DIR__ . '/mw.ext.GloopTweaks.lua', $lib, []
		);
	}

	/**
	 * Based on CoreParserFunctions::filepath().
	 * @param string $name
	 * @param string $width
	 * @return array|null[]
	 * @throws LuaError
	 */
	public function filepath( $name, $width ) {
		$this->checkType( 'mw.ext.GloopTweaks.filepath', 1, $name, 'string' );
		$this->incrementExpensiveFunctionCount();

		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $name );

		if ( $file ) {
			$parsedWidth = [];
			if ( $width !== null ) {
				$parsedWidth = MediaWikiServices::getInstance()->getParser()->parseWidthParam( $width );
			}
			$url = $file->getFullUrl();

			// If a size is requested...
			if ( count( $parsedWidth ) ) {
				$mto = $file->transform( $parsedWidth );
				// ... and we can
				if ( $mto && !$mto->isError() ) {
					// ... change the URL to point to a thumbnail.
					$url = wfExpandUrl( $mto->getUrl(), PROTO_RELATIVE );
				}
			}
			return [ $url ];
		} else {
			return [ null ];
		}
	}
}
