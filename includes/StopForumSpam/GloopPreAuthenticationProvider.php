<?php

namespace MediaWiki\Extension\GloopTweaks\StopForumSpam;

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\MediaWikiServices;
use StatusValue;

class GloopPreAuthenticationProvider extends AbstractPreAuthenticationProvider {
	/**
	 * @param User $user
	 * @param User $creator
	 * @param AuthenticationRequest[] $reqs
	 * @return StatusValue
	 */
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		return $this->testUser( $user, $creator, false );
	}

	/**
	 * @param User $user
	 * @param bool|string $autocreate
	 * @param array $options
	 * @return StatusValue
	 */
	public function testUserForCreation( $user, $autocreate, array $options = [] ) {
		// if this is not an autocreation, testForAccountCreation already handled it
		if ( $autocreate ) {
			return $this->testUser( $user, $user, true );
		}
		return StatusValue::newGood();
	}

	/**
	 * @param User $user The user being created or autocreated
	 * @param User $creator The user who caused $user to be created (or $user itself on autocreation)
	 * @param bool $autocreate Is this an autocreation?
	 * @return StatusValue
	 */
	protected function testUser( $user, $creator, $autocreate ) {
		global $wgRequest;

		$userEmail = $user->getEmail();
		$userName = $user->getName();
		$userIP = $wgRequest->getIP();

		/**
		 * StopForumSpam implementation
		 * - Check the user's IP and email address remotely on the SFS database
		 */

		if (!$creator->isAllowed('bypassgloopspam')) {
			// creator does not have rights to bypass this spam check
			$sfsBlacklisted = StopForumSpam::isBlacklisted( $userIP, $userEmail, null ); // for now, don't use usernamE

			if ($sfsBlacklisted === true) {
				wfDebugLog( 'GloopTweaks', "Blocked account creation from {$userIP} with email {$userEmail} and name {$userName}, as they are in StopForumSpam's database" );
				return StatusValue::newFatal( 'weirdgloop-spam-block' );	
			}
		}

		return StatusValue::newGood();
	}
}
