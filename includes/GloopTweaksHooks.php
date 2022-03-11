<?php

namespace MediaWiki\Extension\GloopTweaks;

use CdnCacheUpdate;
use DeferredUpdates;
use ErrorPageError;
use Html;
use MediaWiki\Extension\GloopTweaks\StopForumSpam\StopForumSpam;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use OutputPage;
use RepoGroup;
use RequestContext;
use Skin;
use Title;
use WikiMap;
use WikiPage;

/**
 * Hooks for GloopTweaks extension
 *
 * @file
 * @ingroup Extensions
 */
class GloopTweaksHooks {
	/**
	 * When core requests certain messages, change the key to a Weird Gloop version.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCache::get
	 * @param string &$lcKey message key to check and possibly convert
	 */
	public static function onMessageCacheGet( &$lcKey ) {
		global $wgGloopTweaksEnableMessageOverrides, $wgLanguageCode;

		if ($wgGloopTweaksEnableMessageOverrides) {
			static $keys = [
				'privacypage',
				'changecontentmodel-text',
				'emailmessage',
				'mobile-frontend-copyright',
				'contactpage-pagetext',
				'newusermessage-editor',
				'revisionslider-help-dialog-slide1'
			];

			if ( in_array( $lcKey, $keys, true ) ) {
				$transformedKey = "weirdgloop-$lcKey";
			} else {
				return;
			}

			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );

			$cache = MediaWikiServices::getInstance()->getMessageCache();
			if (
				/*
					* Override order:
					* 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
					* (in all languages) with normal fallback order.  Specific
					* language pages (MediaWiki:$ucKey/xy) are not checked when
					* deciding which key to use, but are still used if applicable
					* after the key is decided.
					*
					* 2. Otherwise, use the prefixed key with normal fallback order
					* (including MediaWiki pages if they exist).
					*/
				$cache->getMsgFromNamespace( $ucKey, $wgLanguageCode ) === false
			) {
				$lcKey = $transformedKey;
			}
		}
	}

	// When [[MediaWiki:weirdgloop-contact-filter]] is edited, clear the contact-filter-regexes global cache key.
	public static function onPageSaveComplete( WikiPage $wikiPage, UserIdentity $user, string $summary, int $flags, RevisionRecord $revisionRecord, EditResult $editResult ) {
		if ( $wikiPage->getTitle()->getPrefixedDBkey() === 'MediaWiki:Weirdgloop-contact-filter' ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

			$cache->delete(
				$cache->makeGlobalKey(
					'GloopTweaks',
					'contact-filter-regexes'
				)
			);
		}
	}

	/**
	 * Override with Weird Gloop's site-specific copyright message.
	 *
	 * @param Title $title
	 * @param string $type
	 * @param string &$msg
	 * @param string &$link
	 */
	public static function onSkinCopyrightFooter( $title, $type, &$msg, &$link ) {
		global $wgGloopTweaksEnableMessageOverrides;

		if ($wgGloopTweaksEnableMessageOverrides) {
			if ( $type !== 'history' ) {
				$msg = 'weirdgloop-copyright';
			}
		}
	}

	/**
	 * Add some links at the bottom of pages
	 *
	 * @param Skin $skin
	 * @param string $key
	 * @param array &$footerLinks
	 */
	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerLinks ) {
		global $wgGloopTweaksAddFooterLinks;

		if ( $wgGloopTweaksAddFooterLinks && $key === 'places' ) {
			$footerLinks['tou'] = $skin->footerLink( 'weirdgloop-tou', 'weirdgloop-tou-url' );
			$footerLinks['contact'] = $skin->footerLink( 'weirdgloop-contact', 'weirdgloop-contact-url' );
		}
	}

	/**
	 * Set the message on GlobalBlocking IP block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 */
	public static function onGlobalBlockingBlockedIpMsg( &$msg ) {
		global $wgGloopTweaksEnableMessageOverrides;

		if ($wgGloopTweaksEnableMessageOverrides) {
			$msg = 'weirdgloop-globalblocking-ipblocked';
		}
	}

	/**
	 * Set the message on GlobalBlocking XFF block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 */
	public static function onGlobalBlockingBlockedIpXffMsg( &$msg ) {
		global $wgGloopTweaksEnableMessageOverrides;

		if ($wgGloopTweaksEnableMessageOverrides) {
			$msg = 'weirdgloop-globalblocking-ipblocked-xff';
		}
	}

	/**
	 * Require the creation of MediaWiki:Licenses to enable uploading.
	 *
	 * Do not require it when licenses is in $wgForceUIMsgAsContentMsg,
	 * to prevent checking each subpage of MediaWiki:Licenses.
	 *
	 * @param BaseTemplate $tpl
	 * @throws ErrorPageError
	 */
	public static function onUploadFormInitial( $tpl ) {
		global $wgGloopTweaksRequireLicensesToUpload, $wgForceUIMsgAsContentMsg;

		if ($wgGloopTweaksRequireLicensesToUpload) {
			if ( !in_array( 'licenses', $wgForceUIMsgAsContentMsg )
				&& wfMessage( 'licenses' )->inContentLanguage()->isDisabled()
			) {
				throw new ErrorPageError( 'uploaddisabled', 'weirdgloop-upload-nolicenses' );
			}
		}
	}

	/**
	 * Restrict sensitive user rights to only 2FAed sessions.
	 *
	 * @param User $user Current user
	 * @param array &$rights Current user rights.
	 */
	public static function onUserGetRightsRemove( $user, &$rights ) {
		global $wgGloopTweaksSensitiveRights;

		// This hook is called even on endpoints without a session, as we can't check for 2FA in this case, remove sensitive rights.
		if ( !defined( 'MW_NO_SESSION' ) ) {
			$session = $user->getRequest()->getSession();
			// Hardcoded to avoid dependency on OATHAuth::AUTHENTICATED_OVER_2FA.
			$has2FASession = (bool)$session->get( 'OATHAuthAuthenticatedOver2FA', false );
			// Sensitive rights are removed only if this isn't a 2FAed session.
			if ( $has2FASession ) {
				return;
			}
		}

		// Otherwise, filter out the sensitive user rights.
		$rights = array_diff( $rights, $wgGloopTweaksSensitiveRights );
	}

	/**
	 * Protect Weird Gloop system messages from being edited by those that do not have
	 * the "editinterfacesite" right. This is because system messages that are prefixed
	 * with "weirdgloop" are probably there for a legal reason or to ensure consistency
	 * across the site.
	 *
	 * @return bool
	 */
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		global $wgGloopTweaksProtectSiteInterface;

		if ( $wgGloopTweaksProtectSiteInterface
			&& $title->inNamespace( NS_MEDIAWIKI )
			&& strpos( lcfirst( $title->getDBKey() ), 'weirdgloop-' ) === 0
			&& !$user->isAllowed( 'editinterfacesite' )
			&& $action !== 'read'
		) {
				$result = 'weirdgloop-siteinterface';
				return false;
		}

		return true;
	}

	/**
	 * Implement a dark mode and add structured data for the Google Sitelinks search box.
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgGloopTweaksAnalyticsID, $wgCloudflareDomain, $wgGloopTweaksCSP, $wgGloopTweaksCSPAnons;
		global $wgGloopTweaksEnableLoadingDarkmode, $wgGloopTweaksEnableLoadingReadermode, $wgGloopTweaksEnableSearchboxMetadata, $wgArticlePath, $wgCanonicalServer;

		// For letting user JS import from additional sources, like the Wikimedia projects, they have a longer CSP than anons.
		if ( $wgGloopTweaksCSP !== '' ) {
			$user = RequestContext::getMain()->getUser();
			$response = $out->getRequest()->response();

			if ( $wgGloopTweaksCSPAnons === '' || ( $user && !$user->isAnon() ) ) {
				$response->header( 'Content-Security-Policy: ' . $wgGloopTweaksCSP );
			} else {
				$response->header( 'Content-Security-Policy: ' . $wgGloopTweaksCSPAnons );
			}
		}

		if ( $wgGloopTweaksAnalyticsID ) {
			$out->addScript(
				Html::element( 'script',
					[
						'async' => true,
						'nonce' => $out->getCSP()->getNonce(),
						'src' => "https://www.googletagmanager.com/gtag/js?id=$wgGloopTweaksAnalyticsID",
					]
				)
			);
			$out->addInlineScript("window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','$wgGloopTweaksAnalyticsID')");
		}

		/*
		 * Server-side logic to implement the dark mode, reader mode, and sticky header styling customisations.
		 * However, for most requests, this is instead done by our Cloudflare worker to avoid cache fragmentation.
		 * The actual styling is located on the wikis and toggling implemented through Gadgets.
		 */
		$cfWorker = $out->getRequest()->getHeader( 'CF-Worker' );
		$workerProcessed = $cfWorker !== false && $cfWorker === $wgCloudflareDomain;

		// Avoid duplicate processing if this will be performed instead by our Cloudflare worker.
		if ( !$workerProcessed ) {
			/* Dark mode */
			if ( $wgGloopTweaksEnableLoadingDarkmode && isset( $_COOKIE['darkmode'] ) && $_COOKIE['darkmode'] === 'true' ) {
				$out->addBodyClasses( [ 'wgl-darkmode' ] );
				$out->addModuleStyles( [ 'wg.darkmode' ] );
			} else {
				$out->addBodyClasses( [ 'wgl-lightmode' ] );
			}

			/* Reader mode */
			if ( $wgGloopTweaksEnableLoadingReadermode && isset( $_COOKIE['readermode'] ) && $_COOKIE['readermode'] === 'true' ) {
				$out->addBodyClasses( [ 'wgl-readermode' ] );
				$out->addModuleStyles( [ 'wg.readermode' ] );
			}

			/* Sticky header */
			if ( isset( $_COOKIE['stickyheader'] ) && $_COOKIE['stickyheader'] === 'true' ) {
				$out->addBodyClasses( [ 'wgl-stickyheader' ] );
			}
		}

		/* Structured data for the Google Sitelinks search box. */
		if ( $wgGloopTweaksEnableSearchboxMetadata && $out->getTitle()->isMainPage() ) {
			$targetUrl = $wgCanonicalServer . str_replace( '$1', 'Special:Search', $wgArticlePath );
			$targetUrl = wfAppendQuery( $targetUrl, 'search={search_term_string}' );
			$structuredData = [
				'@context'        => 'http://schema.org',
				'@type'           => 'WebSite',
				'url'             => $wgCanonicalServer,
				'potentialAction' => [
					'@type'       => 'SearchAction',
					'target'      => $targetUrl,
					'query-input' => 'required name=search_term_string',
				],
			];
			$out->addHeadItem( 'StructuredData', '<script type="application/ld+json">' . json_encode( $structuredData ) . '</script>' );
		}
	}

	// Cache OpenSearch for 600 seconds. (10 minutes)
	public static function onOpenSearchUrls( &$urls ) {
		foreach ( $urls as &$url ) {
			if ( in_array( $url['type'], [ 'application/x-suggestions+json', 'application/x-suggestions+xml' ] ) ) {
				$url['template'] = wfAppendQuery( $url['template'], [ 'maxage' => 600, 'smaxage' => 600, 'uselang' => 'content' ] );
			}
		}
	}

	/**
	 * Implement diagnostic information into Special:Contact.
	 * Hook provided by ContactPage extension.
	 */
	public static function onContactPage( &$to, &$replyTo, &$subject, &$text ) {
		global $wgGloopTweaksSendDetailsWithContactPage, $wgDBname, $wgRequest, $wgOut, $wgServer;

		$user = $wgOut->getUser();
		$userIP = $wgRequest->getIP();

		// Spam filter for Special:Contact, checks against [[MediaWiki:weirdgloop-contact-filter]] on metawiki. Regex per line and use '#' for comments.
		if ( !GloopTweaksUtils::checkContactFilter( $subject . "\n" . $text ) ) {
			wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as their message matches regex in our contact filter" );
			return false;
		}

		// StopForumSpam check: only check users who are not registered already
		if ( $user->isAnon() && StopForumSpam::isBlacklisted( $userIP ) ) {
			wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as they are in StopForumSpam's database" );
			return false;
		}

		// Block contact page submissions that have an invalid "Reply to"
		// Bots appear to rewrite <input> tags with type='email' to type='text'
		// And then the form lets them submit without any additional verification.
		// if ( !filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) ) {
		// 	wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as the Reply-To address is not an email address" );
		// 	return false;
		// }

		if ($wgGloopTweaksSendDetailsWithContactPage) {
			$text .= "\n\n---\n\n"; // original message
			$text .= $wgServer . ' (' . $wgDBname . ") [" . gethostname() . "]\n"; // server & database name
			$text .= $userIP . ' - ' . ( $_SERVER['HTTP_USER_AGENT'] ?? null ) . "\n"; // IP & user agent
			$text .= 'Referrer: ' . ( $_SERVER['HTTP_REFERER'] ?? null ) . "\n"; // referrer if any
			$text .= 'Skin: ' . $wgOut->getSkin()->getSkinName() . "\n"; // skin
			$text .= 'User: ' . $user->getName() . ' (' . $user->getId() . ')'; // user
		}

		return true;
	}

	/**
	 * Prevent infinite looping of main page requests with cache parameters.
	 */
	public static function onTestCanonicalRedirect( $request, $title, $output ) {
		if ( $title->isMainPage() && strpos( $request->getRequestURL(), '/?') === 0 ) {
			return false;
		}
	}

	/**
	 * Use Short URL always, even for queries.
	 */
	public static function onGetLocalURLInternal( $title, &$url, $query ) {
		global $wgArticlePath, $wgScript;
		$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
		if ( $url == "{$wgScript}?title={$dbkey}&{$query}" ) {
			$url = wfAppendQuery(str_replace( '$1', $dbkey, $wgArticlePath ), $query );
		}
	}

	/**
	 * Add purging for hashless thumbnails.
	 */
	public static function onLocalFilePurgeThumbnails( $file, $archiveName, $hashedUrls ) {
		$hashlessUrls = [];
		foreach ( $hashedUrls as $url ) {
			$hashlessUrls[] = strtok( $url, '?' );
		}

		// Purge the CDN
		DeferredUpdates::addUpdate( new CdnCacheUpdate( $hashlessUrls ), DeferredUpdates::PRESEND );
	}

	/**
	* Add purging for global robots.txt, well-known URLs, and hashless images.
	*/
	public static function onTitleSquidURLs( Title $title, array &$urls ) {
		global $wgCanonicalServer, $wgGloopTweaksCentralDB, $wgDBname;
		$dbkey = $title->getPrefixedDBKey();
		// MediaWiki:Robots.txt on metawiki is global.
		if ( $wgDBname === $wgGloopTweaksCentralDB && $dbkey === 'MediaWiki:Robots.txt' ) {
			// Purge each wiki's /robots.txt route.
			foreach( WikiMap::getCanonicalServerInfoForAllWikis() as $serverInfo ) {
				$urls[] = $serverInfo['url'] . '/robots.txt';
			}
		} elseif ( $dbkey === 'File:Apple-touch-icon.png' ) {
			$urls[] = $wgCanonicalServer . '/apple-touch-icon.png';
		} elseif ( $dbkey === 'File:Favicon.ico' ) {
			$urls[] = $wgCanonicalServer . '/favicon.ico';
		} elseif ( $title->getNamespace() == NS_FILE ) {
			$file = RepoGroup::singleton()->getLocalRepo()->newFile( $title );
			if ( $file ) {
				$urls[] = strtok( $file->getUrl(), '?' );
			}
		}
	}

	/**
	 * External Lua library for Scribunto
	 *
	 * @param string $engine
	 * @param array &$extraLibraries
	 */
	public static function onScribuntoExternalLibraries( $engine, array &$extraLibraries ) {
		if ( $engine == 'lua' ) {
			$extraLibraries['mw.ext.GloopTweaks'] = Scribunto_LuaGloopTweaksLibrary::class;
		}
	}
}
