<?php

namespace MediaWiki\Extension\GloopTweaks;

use MediaWiki\MediaWikiServices;
use Wikimedia\EventRelayer\EventRelayer;
use Wikimedia\ObjectCache\RedisConnectionPool;

/**
 * EventRelayer to perform Cloudflare purging.
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

	/**
	 * @param string $channel
	 * @param array $events List of event data maps
	 * @return bool Success
	 */
	public function doNotify( $channel, array $events ) {
		if ( $channel === 'cdn-prefix-purges' ) {
			return $this->purgeByMethod( $events, 'prefix' );
		} elseif ( $channel === 'cdn-tag-purges' ) {
			return $this->purgeByMethod( $events, 'tag' );
		} elseif ( $channel === 'cdn-url-purges' ) {
			// Channel is 'cdn-url-purges' instead of 'cdn-file-purges' for compatibility with upstream mediawiki.
			return $this->purgeByMethod( $events, 'file' );
		} else {
			return false;
		}
	}

	/**
	* Send Cloudflare purge requests via curl.
	*
	* @param string[] $urls List of URLs to purge
	*/
	private function purgeViaCurl( array $urls ) {
		// Break the purge requests into chunks sized to Cloudflare's per-request URL limit.
		$chunks = array_chunk( $urls, self::MAX_URLS_PER_REQUEST );

		// Prepare curl.
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
	 * @param array $events List of event data maps
	 * @param string $method Cloudflare purge method
	 * @return bool Success
	 */
	private function purgeByMethod( array $events, string $method ) {
		// Extract the entries to purge from the 'cdn-{$method}-purges' events, but
		// 'file' events are keyed 'url' for compatibility with upstream mediawiki.
		$key = ( $method === 'file' ) ? 'url' : $method;
		$entries = [];
		foreach ( $events as $event ) {
			$entries[] = $event[$key];
		}

		wfDebugLog( 'purges_cf', __METHOD__ . ': ' . implode( ' ', $entries ) );

		// Legacy support for falling back to purging via curl for 'file' events if cfpurger isn't configured.
		if ( !$this->redisServer && $method === 'file' ) {
			// Purge via curl is fire-and-forget, so if purging fails for any reason, the purges are lost.
			$this->purgeViaCurl( $entries );
			return true;
		}

		// Obtain redis connection.
		$conn = $this->redisPool->getConnection( $this->redisServer );
		if ( !$conn ) {
			wfDebugLog( 'GloopEventRelayer', __METHOD__ . ': Redis connection failed.' );
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
					"cfpurger:queue:$zone:$method:pending",
					// KEYS[2]
					"cfpurger:queue:$zone:$method:ready",
					// ARGV
					...$entries # ARGV
				],
				// Number of KEYS before ARGV.
				2
			);
		} catch ( RedisException $e ) {
			wfDebugLog( 'GloopEventRelayer', __METHOD__ . ': Redis exception: ' . $e->getMessage() );
			return false;
		}

		return true;
	}
}
