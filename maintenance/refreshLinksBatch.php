<?php

/**
 * Modified from MW 1.39's maintenance/refreshLinks.php to produce jobs for the work.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 */

namespace MediaWiki\Extension\GloopTweaks\Maintenance;

use Maintenance;
use MediaWiki\Extension\GloopTweaks\RefreshLinksBatchJob;
use MediaWiki\MediaWikiServices;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class RefreshLinksBatch extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Refresh link tables using job queue' );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$batchSize = $this->getBatchSize();
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA, 'vslow' );

		$maxPage = $dbr->selectField( 'page', 'max(page_id)', '', __METHOD__ );
		$maxRD = $dbr->selectField( 'redirect', 'max(rd_from)', '', __METHOD__ );
		$end = max( $maxPage, $maxRD );

		for ( $batchStart = 1; $batchStart <= $end; $batchStart += $batchSize ) {
			$batchEnd = $batchStart + $batchSize - 1;
			$job = new RefreshLinksBatchJob( [
				'start' => $batchStart,
				'end' => $batchEnd,
			] );
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
			$this->output( "$batchStart -> $batchEnd\n" );
		}

	}
}

$maintClass = RefreshLinksBatch::class;
require_once RUN_MAINTENANCE_IF_MAIN;