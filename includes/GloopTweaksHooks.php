<?php

namespace MediaWiki\Extension\GloopTweaks;

use CdnCacheUpdate;
use DeferredUpdates;
use ErrorPageError;
use Html;
use MediaWiki\Extension\GloopTweaks\ResourceLoader\ThemeStylesModule;
use MediaWiki\Extension\GloopTweaks\StopForumSpam\StopForumSpam;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use OutputPage;
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
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCacheFetchOverrides
	 * @param string[] &$keys
	 */
	public static function onMessageCacheFetchOverrides( array &$keys ): void {
		global $wgGloopTweaksEnableMessageOverrides;
		if ( !$wgGloopTweaksEnableMessageOverrides ) return;

		static $keysToOverride = [
			'privacypage',
			'changecontentmodel-text',
			'emailmessage',
			'mobile-frontend-copyright',
			'contactpage-pagetext',
			'newusermessage-editor',
			'revisionslider-help-dialog-slide1'
		];

		foreach( $keysToOverride as $key ) {
			$keys[$key] = "weirdgloop-$key";
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
			$footerLinks['tou'] = Html::element(
				'a',
				[
					'href' => Skin::makeInternalOrExternalUrl(
						$skin->msg( 'weirdgloop-tou-url' )->inContentLanguage()->text()
					),
				],
				$skin->msg( 'weirdgloop-tou' )->text()
			);

			$footerLinks['contact'] = Html::element(
				'a',
				[
					'href' => Skin::makeInternalOrExternalUrl(
						$skin->msg( 'weirdgloop-contact-url' )->inContentLanguage()->text()
					),
				],
				$skin->msg( 'weirdgloop-contact' )->text()
			);
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

		// Avoid 2FA lookup if the user doesn't have any sensitive user rights.
		if ( array_intersect( $wgGloopTweaksSensitiveRights, $rights ) === [] ) {
			return;
		}

		$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		$oathUser = $userRepo->findByUser( $user );
		if ( $oathUser->getModule() === null ) {
			// No 2FA, remove sensitive user rights.
			$rights = array_diff( $rights, $wgGloopTweaksSensitiveRights );
		}
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
			&& $action !== 'read'
			&& $title->inNamespace( NS_MEDIAWIKI )
			&& strpos( lcfirst( $title->getDBKey() ), 'weirdgloop-' ) === 0
			&& !$user->isAllowed( 'editinterfacesite' )
		) {
				$result = 'weirdgloop-siteinterface';
				return false;
		}

		return true;
	}

	/**
	 * Implement theming and add structured data for the Google Sitelinks search box.
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgGloopTweaksAnalyticsID, $wgCloudflareDomain, $wgGloopTweaksCSP, $wgGloopTweaksCSPAnons, $wgSitename;
		global $wgGloopTweaksEnableTheming, $wgGloopTweaksDefaultTheme, $wgGloopTweaksEnableLoadingFixedWidth, $wgGloopTweaksEnableStructuredData, $wgArticlePath, $wgCanonicalServer;

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

		// Inject Google Tag Manager.
		if ( $wgGloopTweaksAnalyticsID ) {
			$out->addInlineScript( "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','$wgGloopTweaksAnalyticsID')" );
			$out->prependHTML( '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $wgGloopTweaksAnalyticsID . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' );
		}

		/*
		 * Server-side logic to implement theming and fixed width styling customisations.
		 * However, for most requests, this is instead done by our Cloudflare worker to avoid cache fragmentation.
		 * The actual styling is located on the wikis and toggling implemented through Gadgets.
		 */
		$cfWorker = $out->getRequest()->getHeader( 'CF-Worker' );
		$cfWorkerHandled = $out->getRequest()->getHeader( 'WGL-Worker' );
		$workerProcessed = $cfWorker !== false && $cfWorkerHandled === '1';

		// Avoid duplicate processing if this will be performed instead by our Cloudflare worker.
		if ( !$workerProcessed ) {
			/* Theming */
			if ( $wgGloopTweaksEnableTheming ) {
				$legacyDarkmode = isset( $_COOKIE['darkmode'] ) && $_COOKIE['darkmode'] === 'true';
				$theme = $_COOKIE['theme'] ?? ( $legacyDarkmode ? 'dark' : $wgGloopTweaksDefaultTheme );

				if ( $theme !== $wgGloopTweaksDefaultTheme ) {
					// If the selected theme is not the default theme, load the custom theme module.
					$out->addModuleStyles( [ "wgl.theme.$theme" ] );
				}

				if ( $theme === 'light' ) {
					// Legacy light mode selector.
					$out->addBodyClasses( [ 'wgl-lightmode' ] );
				} else if ( $theme === 'dark') {
					// Legacy dark mode selector.
					$out->addBodyClasses( [ 'wgl-darkmode' ] );
				}

				$out->addBodyClasses( [ "wgl-theme-$theme" ] );
			}

			/* Fixed width mode */
			if ( $wgGloopTweaksEnableLoadingFixedWidth && isset( $_COOKIE['readermode'] ) && $_COOKIE['readermode'] === 'true' ) {
				$out->addBodyClasses( [ 'wgl-fixedWidth' ] );
				$out->addModuleStyles( [ 'wg.fixedwidth' ] );
			}
		}

		$title = $out->getTitle();
		if ( $title->isMainPage() ) {
			/* Open Graph protocol */
			$out->addMeta( 'og:title', $wgSitename );
			$out->addMeta( 'og:type', 'website' );

			/* Structured data for Google etc */
			if ( $wgGloopTweaksEnableStructuredData ) {
				$structuredData = [
					'@context'        => 'http://schema.org',
					'@type'           => 'WebSite',
					'name'            => $wgSitename,
					'url'             => $wgCanonicalServer,
				];
				$out->addHeadItem( 'StructuredData', '<script type="application/ld+json">' . json_encode( $structuredData ) . '</script>' );
			}
		} else {
			/* Open Graph protocol */
			$out->addMeta( 'og:site_name', $wgSitename );
			$out->addMeta( 'og:title', $out->getHTMLTitle() );
			$out->addMeta( 'og:type', 'article' );
		}
		/* Open Graph protocol */
		$out->addMeta( 'og:url', $title->getFullURL() );
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
		global $wgGloopTweaksEnableContactFilter, $wgGloopTweaksSendDetailsWithContactPage, $wgGloopTweaksUseSFS, $wgDBname, $wgRequest, $wgOut, $wgServer;

		$user = $wgOut->getUser();
		$userIP = $wgRequest->getIP();

		// Spam filter for Special:Contact, checks against [[MediaWiki:weirdgloop-contact-filter]] on metawiki. Regex per line and use '#' for comments.
		if ( $wgGloopTweaksEnableContactFilter && !GloopTweaksUtils::checkContactFilter( $subject . "\n" . $text ) ) {
			wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as their message matches regex in our contact filter" );
			return false;
		}

		// StopForumSpam check: only check users who are not registered already
		if ( $wgGloopTweaksUseSFS && $user->isAnon() && StopForumSpam::isBlacklisted( $userIP ) ) {
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
		global $wgScriptPath;
		if ( $title->isMainPage() && str_starts_with( $request->getRequestURL(), $wgScriptPath . '/?' ) ) {
			return false;
		}
	}

	/**
	 * Use Short URL always, even for queries.
	 * Additionally apply it to the main page
	 * because $wgMainPageIsDomainRoot doesn't apply to the internal URL, which is used for purging.
	 */
	public static function onGetLocalURLInternal( $title, &$url, $query ) {
		global $wgArticlePath, $wgScript, $wgMainPageIsDomainRoot, $wgScriptPath;
		$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
		if ( $wgMainPageIsDomainRoot && $title->isMainPage() ) {
			$url = wfAppendQuery( $wgScriptPath . '/', $query );
		} elseif ( $url == "{$wgScript}?title={$dbkey}&{$query}" ) {
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
		global $wgCanonicalServer, $wgGloopTweaksNetworkCentralDB, $wgDBname;
		$dbkey = $title->getPrefixedDBKey();
		// MediaWiki:Robots.txt on metawiki is global.
		if ( $wgDBname === $wgGloopTweaksNetworkCentralDB && $dbkey === 'MediaWiki:Robots.txt' ) {
			// Purge each wiki's /robots.txt route.
			foreach( WikiMap::getCanonicalServerInfoForAllWikis() as $serverInfo ) {
				$urls[] = $serverInfo['url'] . '/robots.txt';
			}
		} elseif ( $dbkey === 'File:Apple-touch-icon.png' ) {
			$urls[] = $wgCanonicalServer . '/apple-touch-icon.png';
		} elseif ( $dbkey === 'File:Favicon.ico' ) {
			$urls[] = $wgCanonicalServer . '/favicon.ico';
		} elseif ( $title->getNamespace() == NS_FILE ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile( $title );
			if ( $file ) {
				$urls[] = strtok( $file->getUrl(), '?' );
			}
		}
	}

	/**
	* Register resource modules for themes.
	*/
	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		global $wgGloopTweaksThemes;
		foreach ( $wgGloopTweaksThemes as $theme ) {
			$resourceLoader->register( "wgl.theme.$theme", [
				'class' => ThemeStylesModule::class,
				'theme' => $theme,
			] );

			// Legacy dark mode
			if ( $theme === 'dark' ) {
				$resourceLoader->register( 'wg.darkmode', [
					'class' => ThemeStylesModule::class,
					'theme' => $theme,
				] );
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
