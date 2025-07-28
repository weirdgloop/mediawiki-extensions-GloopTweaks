<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\MediaWikiServices;
use Wikimedia\EventRelayer\EventRelayer;
use Wikimedia\ObjectCache\RedisConnectionPool;

/**
 * EventRelayer to perform Cloudflare purging.
 * Note: This performs purges directly, so if purging fails for any reason, the purges are lost.
 *
 */
class GloopEventRelayer extends EventRelayer {
	// Cloudflare limits purge_cache API to 100 URLs per request.
	private const MAX_URLS_PER_REQUEST = 100;
	/** @var Config */
	private $config;
	/** @var RedisConnectionPool|null */
	private $redisPool = null;
	/** @var string */
	private $redisServer;

	public function __construct( array $params ) {
		parent::__construct( $params );
		$this->config = MediaWikiServices::getInstance()->getMainConfig();
		$this->redisServer = $this->config->get( 'GloopTweaksCFPurgerRedisServer' );
		if ( $this->redisServer ) {
			$this->redisPool = RedisConnectionPool::singleton(
				[ 'serializer' => 'none' ] + $this->config->get( 'GloopTweaksCFPurgerRedisConfig' )
			);
		}
	}

	public function doNotify( $channel, array $events ) {
		$services = MediaWikiServices::getInstance();
		// This EventRelayer is for CDN URL purges only.
		if ( $channel !== 'cdn-url-purges' ) {
			return false;
		}

		// Extract the URLs to purge from the 'cdn-url-purges' events.
		$urls = [];
		foreach ( $events as $event ) {
			// File purges include only hostname, so the URL must be expanded.
			$urls[] = (string)$services->getUrlUtils()->expand( $event['url'], PROTO_INTERNAL );
		}

		// Purge the URLs from Cloudflare.
		if ( count( $urls ) > 0 ) {
			// Deduplicate URLs.
			$urls = array_unique( $urls );

			wfDebugLog( 'purges_cf', __METHOD__ . ': ' . implode( ' ', $urls ) );

			// Fallback to curl if cfpurger fails.
			$useCurl = true;
			if ( $this->redisServer ) {
				$useCurl = !$this->CloudflarePurge( $urls );
			}
			if ( $useCurl ) {
				$this->CloudflareCurlPurge( $urls );
			}
		}

		return true;
	}

	/**
	* Send Cloudflare purge requests via curl.
	*
	* @param string[] $urls Array of URLs to purge.
	*/
	private function CloudflareCurlPurge( array $urls ) {
		// Break the purge requests into chunks sized to Cloudflare's per-request URL limit.
		$chunks = array_chunk( $urls, self::MAX_URLS_PER_REQUEST );

		// Prepare cURL
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $this->config->get( 'GloopTweaksCFToken' ),
			'Content-Type: application/json',
		] );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $this->config->get( 'GloopTweaksCFZone' ) . '/purge_cache' );

		// Perform the purge requests a chunk at a time.
		foreach ( $chunks as $chunk ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, '{"files":' . json_encode( $chunk, JSON_UNESCAPED_SLASHES ) . '}' );
			curl_exec( $ch );
		}
		curl_close( $ch );
	}

	/**
	* Send Cloudflare purge requests via cfpurger.
	*
	* @param string[] $urls Array of URLs to purge.
	* @return bool Success
	*/
	private function CloudflarePurge( array $urls ) {
		$conn = $this->redisPool->getConnection( $this->redisServer );
		if ( !$conn ) {
			wfDebugLog( 'purges_cf', __METHOD__ . ': Redis connection failed.' );
			return false;
		}

		static $script =
		/** @lang Lua */
<<<LUA
		-- Get highest score in the pending and ready queues.
		local start = math.max(
			tonumber(redis.call('ZRANGE', KEYS[1], '0', '0', 'REV', 'WITHSCORES')[2]) or 0,
			tonumber(redis.call('ZRANGE', KEYS[2], '0', '0', 'REV', 'WITHSCORES')[2]) or 0
		)
		-- Use the highest score from above to generate unique scores for each entry that is being added to the ready queue.
		local numAdded = 0
		local statAdded = 0
		repeat
			local batchSize = math.min(3999, #ARGV-numAdded)
			local entries = {}
			local j = 1
			for i = 1, batchSize do
				entries[j] = start + i + numAdded
				entries[j+1] = ARGV[i+numAdded]
				j = j + 2
			end
			-- Add non-existing entries to the ready queue.
			statAdded = statAdded + redis.call('ZADD', KEYS[2], 'NX', unpack(entries))
			numAdded = numAdded + batchSize
		until numAdded == #ARGV
LUA;
		$zone = $this->config->get( 'GloopTweaksCFZone' );
		try {
			$conn->luaEval(
				$script,
				[
					// KEYS[1]
					"cfpurger:queue:$zone:file:pending",
					// KEYS[2]
					"cfpurger:queue:$zone:file:ready",
					// ARGV
					...$urls # ARGV
				],
				// Number of KEYS before ARGV.
				2
			);
		} catch ( RedisException $e ) {
			wfDebugLog( 'purges_cf', __METHOD__ . ': Redis exception: ' . $e->getMessage() );
			return false;
		}

		return true;
	}
}
