<?php

namespace MediaWiki\Extension\GloopTweaks\ContentHandler;

class InteractiveMap extends \TextContentHandler {
	public const CONTENT_MODEL_ID = 'interactivemap';

	/**
	 * @inheritDoc
	 */
	public function __construct( $modelId = self::CONTENT_MODEL_ID ) {
		parent::__construct( $modelId );
	}
}
