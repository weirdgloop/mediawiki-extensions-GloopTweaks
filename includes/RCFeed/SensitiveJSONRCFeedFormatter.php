<?php

namespace MediaWiki\Extension\GloopTweaks\RCFeed;

use MediaWiki\Content\TextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\RCFeed\JSONRCFeedFormatter;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Revision\SlotRecord;

/**
 * Enhances the normal JSONRCFeedFormatter with some extra information about the revision and user, for better
 * remote counter-vandalism tooling.
 *
 * THIS SHOULD ONLY BE USED FOR INTERNAL ENDPOINTS! It exposes sensitive user/request information.
 * @internal
 */
class SensitiveJSONRCFeedFormatter extends JSONRCFeedFormatter {

	/**
	 * Overwrite JSONRCFeedFormatter::formatArray() to return the array as-is, to prevent converting it to JSON early.
	 * @param array $packet
	 * @return array
	 * @suppress PhanParamSignatureMismatch
	 */
	protected function formatArray( array $packet ) {
		return $packet;
	}

	/**
	 * Supplement some additional data to the packet.
	 * @inheritDoc
	 */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		// Existing line from MachineReadableRCFeedFormatter::getLine()
		$packet = (array)parent::getLine( $feed, $rc, $actionComment );

		$services = MediaWikiServices::getInstance();
		$user = $rc->getPerformerIdentity();

		$packet['user'] = [
			'name' => $user->getName(),
			'reg' => $services->getUserRegistrationLookup()->getFirstRegistration( $user ),
			'edits' => $services->getUserEditTracker()->getUserEditCount( $user )
		];

		$req = RequestContext::getMain()->getRequest();
		$packet['request'] = [
			'ip' => $req->getIP(),
			'ua' => $req->getHeader( 'User-Agent' ),
			// These headers are added by Cloudflare
			'cn' => $req->getHeader( 'CF-IPCountry' ),
			'asn' => $req->getHeader( 'CF-ASN' )
		];

		if ( !empty( $packet['revision']['new'] ) ) {
			// This was an edit or page creation, so lookup the revision and return the text.
			$revision = $services->getRevisionLookup()->getRevisionById( $packet['revision']['new'] );
			if ( $revision ) {
				$content = $revision->getContent( SlotRecord::MAIN );
				if ( $content ) {
					$text = $content instanceof TextContent ? $content->getText() : $content->getTextForSearchIndex();
					$packet['revision']['text'] = substr( $text, 0, 1000000 );
				}
			}
		}

		return FormatJson::encode( $packet );
	}
}
