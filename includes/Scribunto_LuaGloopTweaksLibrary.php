<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;

class Scribunto_LuaGloopTweaksLibrary extends Scribunto_LuaLibraryBase {
	public function register() {
		$lib = [
			'filepath' => [ $this, 'filepath' ],
		];

		return $this->getEngine()->registerInterface(
			__DIR__ . '/mw.ext.GloopTweaks.lua', $lib, []
		);
	}

	// Based on CoreParserFunctions::filepath().
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
