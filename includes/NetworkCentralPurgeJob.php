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
        $entries = $this->params[ 'entries' ];
		$method = $this->params[ 'method' ];
        $key = ( $method === 'file' ) ? 'url' : $method;
        $purger = new GloopEventRelayer([]);
        $zones = array_values( $this->config->get( 'GloopTweaksCFZones' ) );

        foreach ( $zones as $zone ) {
			$status = $purger->purgeByMethod( [ $key => $entries ], $method, $zone );
            // Job is retryable to handle purge failures.
            if ( !$status ) {
                return false;
            }
        }

		return true;
	}
}
