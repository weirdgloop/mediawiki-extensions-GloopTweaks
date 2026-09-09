<?php
/**
 * @file
 */

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\MediaWikiServices;
use MediaWiki\RCFeed\FormattedRCFeed;

/**
 * Send recent change notifications to a destination address over HTTP.
 *
 * Parameters:
 * - `formatter`: (Required) Which RCFeedFormatter class to use. Should be JSON.
 * - `uri`: (Required) Where to send the messages.
 *
 * @par Example:
 * @code
 * $wgRCFeeds['rc-to-http'] = [
 *      'class' => 'MediaWiki\Extension\GloopTweaks\HttpFeedEngine',
 *      'formatter' => 'JSONRCFeedFormatter',
 *      'uri' => 'https://example.com/webhook',
 * ];
 * @endcode
 *
 * @see $wgRCFeeds
 * @since 1.22
 * @ingroup RecentChanges
 */
class HttpFeedEngine extends FormattedRCFeed {

	/**
	 * @see FormattedRCFeed::send
	 * @param array $feed
	 * @param string $line
	 * @return bool
	 */
	public function send( array $feed, $line ) {
		$requestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$options = [
			'method' => 'POST',
			'postData' => $line
		];

		if ( !empty( $feed['username'] ) ) {
			$options['username'] = $feed['username'];
		}
		if ( !empty( $feed['password'] ) ) {
			$options['password'] = $feed['password'];
		}

		$request = $requestFactory->create( $feed['uri'], $options, __METHOD__ );
		$request->setHeader( 'Content-Type', 'application/json' );
		$status = $request->execute();
		if ( !$status->isOK() ) {
			return false;
		}

		return true;
	}
}
