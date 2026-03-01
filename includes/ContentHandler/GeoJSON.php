<?php

namespace MediaWiki\Extension\GloopTweaks\ContentHandler;

class GeoJSON extends \JsonContentHandler {
	public const CONTENT_MODEL_ID = 'GeoJSON';

	/**
	 * @inheritDoc
	 */
	public function __construct( $modelId = self::CONTENT_MODEL_ID ) {
		parent::__construct( $modelId );
	}
}
