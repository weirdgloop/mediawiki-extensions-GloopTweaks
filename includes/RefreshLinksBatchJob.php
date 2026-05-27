<?php

/**
 * Modified from MW 1.39's maintenance/refreshLinks.php to work as a job.
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

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\IDatabase;

class RefreshLinksBatchJob extends Job implements GenericParameterJob {

	/** @var IDatabase|null */
	private $dbr;

	/** @var IDatabase|null */
	private $dbw;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'refreshLinksBatch', $params );
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;

		$this->dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA, 'vslow' );
		$this->dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
	}

	public function run() {
		$start = $this->params[ 'start' ];
		$end = $this->params[ 'end' ];

		for ( $id = $start; $id <= $end; $id++ ) {
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromID( $id );

			$this->fixRedirect( $id, $page );
			$this->fixLinks( $page );
		}

		$this->deleteLinksFromNonexistent( $start, $end );

		return true;
	}

	/**
	 * Update the redirect entry for a given page.
	 *
	 * This methods bypasses the "redirect" table to get the redirect target,
	 * and parses the page's content to fetch it. This allows to be sure that
	 * the redirect target is up to date and valid.
	 *
	 * @param int $id The page ID to check
	 * @param WikiPage $page The page to check
	 */
	private function fixRedirect( $id, $page ) {
		if ( $page === null ) {
			// This page doesn't exist (any more)
			// Delete any redirect table entry for it
			$this->dbw->delete( 'redirect', [ 'rd_from' => $id ],
				__METHOD__ );

			return;
		}

		$rt = null;
		$content = $page->getContent( RevisionRecord::RAW );
		if ( $content !== null ) {
			$rt = $content->getRedirectTarget();
		}

		if ( $rt === null ) {
			// The page is not a redirect
			// Delete any redirect table entry for it
			$this->dbw->delete( 'redirect', [ 'rd_from' => $id ], __METHOD__ );
			$fieldValue = 0;
		} else {
			$page->insertRedirectEntry( $rt );
			$fieldValue = 1;
		}

		// Update the page table to be sure it is an a consistent state
		$this->dbw->update( 'page', [ 'page_is_redirect' => $fieldValue ],
			[ 'page_id' => $id ], __METHOD__ );
	}

	/**
	 * Run LinksUpdate for all links on a given page
	 * @param WikiPage $page The page to update all links on
	 */
	private function fixLinks( $page ) {
		if ( $page === null ) {
			return;
		}

		MediaWikiServices::getInstance()->getLinkCache()->clear();

		// Defer updates to post-send but then immediately execute deferred updates;
		// this is the simplest way to run all updates immediately (including updates
		// scheduled by other updates).
		$page->doSecondaryDataUpdates( [
			'defer' => DeferredUpdates::POSTSEND,
			'recursive' => false,
		] );
		DeferredUpdates::doUpdates();
	}

	/**
	 * Removes non-existing links from pages from pagelinks, imagelinks,
	 * categorylinks, templatelinks, externallinks, interwikilinks, langlinks and redirect tables.
	 *
	 * @param int|null $start Page_id to start from
	 * @param int|null $end Page_id to stop at
	 * @param int $batchSize The size of deletion batches
	 * @param int $chunkSize Maximum number of existent IDs to check per query
	 *
	 * @author Merlijn van Deen <valhallasw@arctus.nl>
	 */
	private function deleteLinksFromNonexistent( $start = null, $end = null, $batchSize = 100,
		$chunkSize = 100000
	) {
		MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
		do {
			// Find the start of the next chunk. This is based only
			// on existent page_ids.
			$nextStart = $this->dbr->selectField(
				'page',
				'page_id',
				[ self::intervalCond( $this->dbr, 'page_id', $start, $end ) ],
				__METHOD__,
				[ 'ORDER BY' => 'page_id', 'OFFSET' => $chunkSize ]
			);

			if ( $nextStart !== false ) {
				// To find the end of the current chunk, subtract one.
				// This will serve to limit the number of rows scanned in
				// dfnCheckInterval(), per query, to at most the sum of
				// the chunk size and deletion batch size.
				$chunkEnd = $nextStart - 1;
			} else {
				// This is the last chunk. Check all page_ids up to $end.
				$chunkEnd = $end;
			}

			$fmtStart = $start !== null ? "[$start" : '(-INF';
			$fmtChunkEnd = $chunkEnd !== null ? "$chunkEnd]" : 'INF)';
			$this->dfnCheckInterval( $start, $chunkEnd, $batchSize );

			$start = $nextStart;

		} while ( $nextStart !== false );
	}

	/**
	 * @see RefreshLinks::deleteLinksFromNonexistent()
	 * @param int|null $start Page_id to start from
	 * @param int|null $end Page_id to stop at
	 * @param int $batchSize The size of deletion batches
	 */
	private function dfnCheckInterval( $start = null, $end = null, $batchSize = 100 ) {
		$linksTables = [
			// table name => page_id field
			'pagelinks' => 'pl_from',
			'imagelinks' => 'il_from',
			'categorylinks' => 'cl_from',
			'templatelinks' => 'tl_from',
			'externallinks' => 'el_from',
			'iwlinks' => 'iwl_from',
			'langlinks' => 'll_from',
			'redirect' => 'rd_from',
			'page_props' => 'pp_page',
		];

		foreach ( $linksTables as $table => $field ) {
			$tableStart = $start;
			do {
				$ids = $this->dbr->selectFieldValues(
					$table,
					$field,
					[
						self::intervalCond( $this->dbr, $field, $tableStart, $end ),
						"$field NOT IN ({$this->dbr->selectSQLText( 'page', 'page_id', [], __METHOD__ )})",
					],
					__METHOD__,
					[ 'DISTINCT', 'ORDER BY' => $field, 'LIMIT' => $batchSize ]
				);

				$numIds = count( $ids );
				if ( $numIds ) {
					$this->dbw->delete( $table, [ $field => $ids ], __METHOD__ );
					$tableStart = $ids[$numIds - 1] + 1;
					MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
				}

			} while ( $numIds >= $batchSize && ( $end === null || $tableStart <= $end ) );
		}
	}

	/**
	 * Build a SQL expression for a closed interval (i.e. BETWEEN).
	 *
	 * By specifying a null $start or $end, it is also possible to create
	 * half-bounded or unbounded intervals using this function.
	 *
	 * @param IDatabase $db
	 * @param string $var Field name
	 * @param mixed $start First value to include or null
	 * @param mixed $end Last value to include or null
	 * @return string
	 */
	private static function intervalCond( IDatabase $db, $var, $start, $end ) {
		if ( $start === null && $end === null ) {
			return "$var IS NOT NULL";
		} elseif ( $end === null ) {
			return "$var >= " . $db->addQuotes( $start );
		} elseif ( $start === null ) {
			return "$var <= " . $db->addQuotes( $end );
		} else {
			return "$var BETWEEN " . $db->addQuotes( $start ) . ' AND ' . $db->addQuotes( $end );
		}
	}
}
