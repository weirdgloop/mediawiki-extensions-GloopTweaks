<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements
	LoadExtensionSchemaUpdatesHook
{
	/**
	 * @param DatabaseUpdater $updater DatabaseUpdater subclass
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$base = dirname( __DIR__, 1 ) . '/sql';

		$updater->addExtensionTable( 'objectstash', "$base/table-objectstash.sql" );
	}
}
