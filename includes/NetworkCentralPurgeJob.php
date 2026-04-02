<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

class NetworkCentralPurgeJob extends Job implements GenericParameterJob {
	public function __construct( $params ) {
		parent::__construct( 'networkCentralPurgeJob', $params );
        $this->removeDuplicates = false; // delay semantics are critical
	}

	public function run() {
        global $wgGloopTweaksCFZones;

        $entries = $this->params[ 'entries' ];
		$method = $this->params[ 'method' ];
        $purger = new GloopEventRelayer([]);
        $zones = array_values( $wgGloopTweaksCFZones );

        foreach ( $zones as $zone ) {
            $status = $purger->purgeByMethod( $entries, $method, $zone, true );
            // Job is retryable to handle purge failures.
            if ( !$status ) {
                return false;
            }
        }

		return true;
	}
}
