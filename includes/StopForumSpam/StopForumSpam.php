<?php

namespace MediaWiki\Extension\GloopTweaks\StopForumSpam;

class StopForumSpam {
	/**
	 * Checks if a given IP address is blacklisted via the remote API
	 * @param string $ip
	 * @return bool
	 */
	public static function isBlacklisted( $ip, $email = null, $username = null ) {
		$query = array(
			"ip" => $ip,
			"json" => true
		);

		if ($email !== null) {
			$query['email'] = $email;
		}
		if ($username !== null) {
			$query['username'] = $username;
		}

		$result = self::doRemoteCall($query);
		$resultJson = json_decode($result, true);

		if (!$resultJson || $resultJson['success'] < 1) {
			// Unsuccessful API call, so we'll just log and return false.
			wfDebugLog( 'GloopTweaks', "Unsuccessful StopForumSpam API call for IP {$ip}" );
			return false;
		}

		if ($resultJson['ip']['appears'] > 0 && $resultJson['ip']['confidence'] > 10.0) {
			// IP appears in the SFS database.
			wfDebugLog( 'GloopTweaks', "{$ip} appears in StopForumSpam database" );
			return true;
		}
		if ($email && $resultJson['email']['appears'] > 0 && $resultJson['email']['confidence'] > 10.0) {
			// Email appears in the SFS database.
			wfDebugLog( 'GloopTweaks', "{$email} appears in StopForumSpam database" );
			return true;
		}
		if ($username && $resultJson['username']['appears'] > 0 && $resultJson['username']['confidence'] > 10.0) {
			// Username appears in the SFS database.
			wfDebugLog( 'GloopTweaks', "{$username} appears in StopForumSpam database" );
			return true;
		}

		// Otherwise, return false.
		return false;
	}

	private static function doRemoteCall( $query ) {
		$curl = curl_init();
		$url = sprintf("%s?%s", 'https://api.stopforumspam.org/api', http_build_query($query));

		wfDebugLog('GloopTweaks', "Making request to SFS API with URL: {$url}");

		curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);

		$result = curl_exec($curl);
		curl_close($curl);

		wfDebugLog( 'GloopTweaks', "SFS lookup for {$url}. Result: {$result}" );

		return $result;
	}
}
