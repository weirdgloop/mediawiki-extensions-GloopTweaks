<?php

namespace MediaWiki\Extension\GloopTweaks;

use Exception;
use MediaWiki\Actions\RawAction;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;
use MediaWiki\Config\Config;
use MediaWiki\Content\Content;
use MediaWiki\Content\Hook\ContentAlterParserOutputHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\CdnCacheUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Exception\MWException;
use MediaWiki\Extension\ContactPage\Hooks\ContactFormHook;
use MediaWiki\Extension\GloopTweaks\ResourceLoader\ThemeStylesModule;
use MediaWiki\Extension\GloopTweaks\StopForumSpam\StopForumSpam;
use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Hook\AfterImportPageHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\GetLocalURL__InternalHook;
use MediaWiki\Hook\LocalFilePurgeThumbnailsHook;
use MediaWiki\Hook\OpenSearchUrlsHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\ParserBeforeInternalParseHook;
use MediaWiki\Hook\RawPageViewBeforeOutputHook;
use MediaWiki\Hook\SkinAddFooterLinksHook;
use MediaWiki\Hook\SkinCopyrightFooterMessageHook;
use MediaWiki\Hook\TestCanonicalRedirectHook;
use MediaWiki\Hook\TitleSquidURLsHook;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Mail\MailAddress;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Article;
use MediaWiki\Page\Hook\ArticleViewHeaderHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Page\LinkCache;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\Permissions\Hook\UserGetRightsRemoveHook;
use MediaWiki\Request\WebRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderBeforeResponseHook;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\ResourceLoader\WikiModule;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Skin\Skin;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\ForeignTitle;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MessageSpecifier;
use Wikimedia\HtmlArmor\HtmlArmor;
use Wikimedia\Rdbms\IConnectionProvider;

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

/**
 * Hooks for various customisations used on Weird Gloop wikis.
 */
class GloopTweaksHooks implements
	MessageCacheFetchOverridesHook,
	AfterImportPageHook,
	PageDeleteCompleteHook,
	PageMoveCompleteHook,
	PageSaveCompleteHook,
	PageUndeleteCompleteHook,
	SkinCopyrightFooterMessageHook,
	SkinAddFooterLinksHook,
	UserGetRightsRemoveHook,
	GetUserPermissionsErrorsHook,
	ArticleViewHeaderHook,
	BeforePageDisplayHook,
	OpenSearchUrlsHook,
	ContactFormHook,
	TestCanonicalRedirectHook,
	GetLocalURL__InternalHook,
	LocalFilePurgeThumbnailsHook,
	TitleSquidURLsHook,
	ResourceLoaderRegisterModulesHook,
	ScribuntoExternalLibrariesHook,
	RawPageViewBeforeOutputHook,
	APIAfterExecuteHook,
	ContentAlterParserOutputHook,
	ResourceLoaderBeforeResponseHook,
	ParserBeforeInternalParseHook
{

	private bool $linkCachePrewarmed = false;

	public function __construct(
		private readonly Config $config,
		private readonly IConnectionProvider $connectionProvider,
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly LinkCache $linkCache,
		private readonly LinkRenderer $linkRenderer,
	) {
	}

	/**
	 * @param string[] &$keys
	 * @return void
	 */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		// When certain messages are requested, change the key to a Weird Gloop version.
		if ( $this->config->get( 'GloopTweaksEnableMessageOverrides' ) ) {
			static $keysToOverride = [
				'privacypage',
				'changecontentmodel-text',
				'emailmessage',
				'mobile-frontend-copyright',
				'contactpage-pagetext',
				'newusermessage-editor',
				'revisionslider-help-dialog-slide1',
				'checkuser-tempaccount-enable-preference-description'
			];

			foreach ( $keysToOverride as $key ) {
				$keys[$key] = "weirdgloop-$key";
			}
		}
	}

	/**
	 * @param Title $title
	 * @param ForeignTitle $foreignTitle
	 * @param int $revCount
	 * @param int $sRevCount
	 * @param array $pageInfo
	 * @return void
	 */
	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ): void {
		// Purge by tag doesn't do anything here since the page might already be cached, so also purge by prefix.
		$parsed = parse_url( $title->getFullURL() );
		// @phan-suppress-next-line PhanUndeclaredStaticMethod Part of Weird Gloop's MediaWiki fork
		CdnCacheUpdate::purgeGloop( [ "{$parsed['host']}{$parsed['path']}" ], 'prefix' );
	}

	/**
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param RevisionRecord $deletedRev
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 * @return void
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	): void {
		// Work around page ID for a title no longer existing by the time MediaWiki purges after page deletion.
		$dbName = $this->config->get( MainConfigNames::DBname );
		// @phan-suppress-next-line PhanUndeclaredStaticMethod Part of Weird Gloop's MediaWiki fork
		CdnCacheUpdate::purgeGloop( [ "$dbName:page:$pageID" ], 'tag' );
	}

	/**
	 * @param LinkTarget $old
	 * @param LinkTarget $new
	 * @param UserIdentity $user
	 * @param int $pageid
	 * @param int $redirid
	 * @param string $reason
	 * @param RevisionRecord $revision
	 * @return void
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ): void {
		// Purge by tag doesn't do anything here since the page might already be cached, so also purge by prefix.
		$parsed = parse_url( Title::castFromLinkTarget( $new )->getFullURL() );
		// @phan-suppress-next-line PhanUndeclaredStaticMethod Part of Weird Gloop's MediaWiki fork
		CdnCacheUpdate::purgeGloop( [ "{$parsed['host']}{$parsed['path']}" ], 'prefix' );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return void
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		// Purge by tag doesn't do anything here since the page might already be cached, so also purge by prefix.
		if ( $editResult->isNew() ) {
			$parsed = parse_url( $wikiPage->getTitle()->getFullURL() );
			// @phan-suppress-next-line PhanUndeclaredStaticMethod Part of Weird Gloop's MediaWiki fork
			CdnCacheUpdate::purgeGloop( [ "{$parsed['host']}{$parsed['path']}" ], 'prefix' );
		}

		if ( GloopTweaksUtils::currentWikiIsNetworkCentralWiki() ) {
			if ( $wikiPage->getTitle()->getPrefixedDBkey() === 'MediaWiki:Robots.txt' ) {
				// When [[MediaWiki:Robots.txt]] is edited, clear the 'robots' global cache key.
				$cache = GloopTweaksUtils::getNetworkCentralCache();

				$cache->delete(
					$cache->makeGlobalKey(
						'GloopTweaks',
						'robots'
					)
				);

				// Purge the cache tag in every CF zone.
				MediaWikiServices::getInstance()->getJobQueueGroup()->push(
					new NetworkCentralPurgeJob( [ 'entries' => [ 'GloopTweaks:robots.txt' ], 'method' => 'tag' ] )
				);
			} elseif ( $wikiPage->getTitle()->getPrefixedDBkey() === 'MediaWiki:Weirdgloop-contact-filter' ) {
				// When [[MediaWiki:Weirdgloop-contact-filter]] is edited, clear the 'contact-filter-regexes'
				// global cache key.
				$cache = GloopTweaksUtils::getNetworkCentralCache();

				$cache->delete(
					$cache->makeGlobalKey(
						'GloopTweaks',
						'contact-filter-regexes'
					)
				);
			}
		}
	}

	/**
	 * @param ProperPageIdentity $page
	 * @param Authority $restorer
	 * @param string $reason
	 * @param RevisionRecord $restoredRev
	 * @param ManualLogEntry $logEntry
	 * @param int $restoredRevisionCount
	 * @param bool $created
	 * @param array $restoredPageIds
	 * @return void
	 */
	public function onPageUndeleteComplete(
		ProperPageIdentity $page,
		Authority $restorer,
		string $reason,
		RevisionRecord $restoredRev,
		ManualLogEntry $logEntry,
		int $restoredRevisionCount,
		bool $created,
		array $restoredPageIds
	): void {
		// Purge by tag doesn't do anything here since the page might already be cached, so also purge by prefix.
		if ( $created ) {
			$parsed = parse_url( Title::newFromPageIdentity( $page )->getFullURL() );
			// @phan-suppress-next-line PhanUndeclaredStaticMethod Part of Weird Gloop's MediaWiki fork
			CdnCacheUpdate::purgeGloop( [ "{$parsed['host']}{$parsed['path']}" ], 'prefix' );
		}
	}

	/**
	 * @param Title $title
	 * @param string $type
	 * @param MessageSpecifier &$msg
	 */
	public function onSkinCopyrightFooterMessage( $title, $type, &$msg ): void {
		if ( !$this->config->get( 'GloopTweaksEnableMessageOverrides' ) || $type === 'history' ) {
			return;
		}

		$link = $this->linkRenderer->makeExternalLink(
			$this->config->get( MainConfigNames::RightsUrl ),
			new HtmlArmor( $this->config->get( MainConfigNames::RightsText ) ),
			$title
		);

		$msg = Message::newFromSpecifier( 'weirdgloop-copyright' )->rawParams( $link );
	}

	/**
	 * @param Skin $skin
	 * @param string $key
	 * @param array &$footerItems
	 */
	public function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ): void {
		if ( $this->config->get( 'GloopTweaksAddFooterLinks' ) && $key === 'places' ) {
			$footerItems['tou'] = Html::element(
				'a',
				[
					'href' => Skin::makeInternalOrExternalUrl(
						$skin->msg( 'weirdgloop-tou-url' )->inContentLanguage()->text()
					),
				],
				$skin->msg( 'weirdgloop-tou' )->text()
			);

			$footerItems['contact'] = Html::element(
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
	 * @param User $user
	 * @param string[] &$rights
	 * @return void
	 */
	public function onUserGetRightsRemove( $user, &$rights ): void {
		$sensitiveRights = $this->config->get( 'GloopTweaksSensitiveRights' );

		// Avoid 2FA lookup if the user doesn't have any sensitive user rights.
		if ( array_intersect( $sensitiveRights, $rights ) === [] ) {
			return;
		}

		$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		$oathUser = $userRepo->findByUser( $user );
		if ( !$oathUser->isTwoFactorAuthEnabled() ) {
			// No 2FA, remove sensitive user rights.
			$rights = array_diff( $rights, $sensitiveRights );
		}
	}

	/**
	 * Protect Weird Gloop system messages from being edited by those that do not have
	 * the "editinterfacesite" right. This is because system messages that are prefixed
	 * with "weirdgloop" are probably there for a legal reason or to ensure consistency
	 * across the site.
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result
	 * @return bool
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ): bool {
		if ( $this->config->get( 'GloopTweaksProtectSiteInterface' )
			&& $action !== 'read'
			&& $title->inNamespace( NS_MEDIAWIKI )
			&& str_starts_with( lcfirst( $title->getDBKey() ), 'weirdgloop-' )
			&& !$user->isAllowed( 'editinterfacesite' )
		) {
				$result = 'weirdgloop-siteinterface';
				return false;
		}

		return true;
	}

	/**
	 * @param Article $article
	 * @param bool|ParserOutput|null &$outputDone
	 * @param bool &$pcache
	 * @return void
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ): void {
		$dbName = $this->config->get( MainConfigNames::DBname );

		/**
		 * Add a Cache-Tag HTTP header for Cloudflare to use, for normal page views, ?action=history (and others),
		 * HTTP 200 redirects to this page (e.g standard MediaWiki redirects).
		 */
		$cacheTags = [];
		$id = $article->getTitle()->getArticleID();
		if ( $id ) {
			$cacheTags[] = "$dbName:page:$id";
		}
		// Purge the source page as well for redirected pages.
		$redirectedFrom = $article->getRedirectedFrom();
		if ( $redirectedFrom ) {
			$id = $redirectedFrom->getArticleID();
			if ( $id ) {
				$cacheTags[] = "$dbName:page:$id";
			}
		}

		$request = $article->getContext()->getOutput()->getRequest();
		GloopTweaksUtils::addCacheTag( $request, $cacheTags );
	}

	/**
	 * @param OutputPage $out
	 * @note use Extension:GloopThemes for newer wikis
	 * @return void
	 */
	private function handleTheming( $out ) {
		/*
		 * Server-side logic to implement theming and fixed width styling customisations.
		 * However, for most requests, this is instead done by our Cloudflare worker to avoid cache fragmentation.
		 * The actual styling is located on the wikis and toggling implemented through Gadgets.
		 */
		$cfWorker = $out->getRequest()->getHeader( 'CF-Worker' );
		$cfWorkerHandled = $out->getRequest()->getHeader( 'X-WGL-Worker' );
		$workerProcessed = $cfWorker !== false && $cfWorkerHandled === '1';

		// Avoid duplicate processing if this will be performed instead by our Cloudflare worker.
		if ( !$workerProcessed ) {
			/* Theming */
			if ( $this->config->get( 'GloopTweaksEnableTheming' ) ) {
				$defaultTheme = $this->config->get( 'GloopTweaksDefaultTheme' );
				$legacyDarkmode = isset( $_COOKIE['darkmode'] ) && $_COOKIE['darkmode'] === 'true';
				$theme = $_COOKIE['theme'] ?? ( $legacyDarkmode ? 'dark' : $defaultTheme );

				if ( $theme !== $defaultTheme ) {
					// If the selected theme is not the default theme, load the custom theme module.
					$out->addModuleStyles( [ "wgl.theme.$theme" ] );
				}

				if ( $theme === 'light' ) {
					// Legacy light mode selector.
					$out->addBodyClasses( [ 'wgl-lightmode' ] );
				} elseif ( $theme === 'dark' ) {
					// Legacy dark mode selector.
					$out->addBodyClasses( [ 'wgl-darkmode' ] );
				}

				$out->addBodyClasses( [ "wgl-theme-$theme" ] );
			}

			/* Fixed width mode */
			if ( $this->config->get( 'GloopTweaksEnableLoadingFixedWidth' ) &&
				isset( $_COOKIE['readermode'] ) && $_COOKIE['readermode'] === 'true' ) {
				$out->addBodyClasses( [ 'wgl-fixedWidth' ] );
				$out->addModuleStyles( [ 'wg.fixedwidth' ] );
			}
		}
	}

	/**
	 * Implement theming and add structured data for the Google Sitelinks search box.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$csp = $this->config->get( 'GloopTweaksCSP' );
		$cspAnon = $this->config->get( 'GloopTweaksCSPAnons' );

		// Add a CSP header. The CSP for normal users can be different to anons.
		if ( $csp !== '' ) {
			$user = RequestContext::getMain()->getUser();
			$response = $out->getRequest()->response();

			if ( $cspAnon === '' || ( $user && !$user->isAnon() ) ) {
				$response->header( 'Content-Security-Policy: ' . $csp );
			} else {
				$response->header( 'Content-Security-Policy: ' . $cspAnon );
			}
		}

		$gtmId = $this->config->get( 'GloopTweaksAnalyticsID' );

		// Inject Google Tag Manager.
		if ( $gtmId ) {
			$out->addInlineScript(
				<<<EOD
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','$gtmId')
EOD
			);
			$out->prependHTML(
				<<<EOD
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $gtmId . '" height="0" width="0"
 style="display:none;visibility:hidden"></iframe></noscript>
EOD
			);
		}

		$this->handleTheming( $out );

		$title = $out->getTitle();
		$siteName = $this->config->get( MainConfigNames::Sitename );

		if ( $title->isMainPage() ) {
			/* Open Graph protocol */
			$out->addMeta( 'og:title', $siteName );
			$out->addMeta( 'og:type', 'website' );

			/* Structured data for Google etc */
			if ( $this->config->get( 'GloopTweaksEnableStructuredData' ) ) {
				$structuredData = [
					'@context'        => 'http://schema.org',
					'@type'           => 'WebSite',
					'name'            => $siteName,
					'url'             => $this->config->get( MainConfigNames::CanonicalServer ),
				];
				$out->addHeadItem( 'StructuredData',
					'<script type="application/ld+json">' . json_encode( $structuredData ) . '</script>' );
			}
		} else {
			/* Open Graph protocol */
			$out->addMeta( 'og:title', $out->getHTMLTitle() );
			$out->addMeta( 'og:type', 'article' );
		}
		/* Open Graph protocol */
		$out->addMeta( 'og:site_name', $siteName );
		$out->addMeta( 'og:url', $title->getFullURL() );
	}

	/**
	 * Cache opensearch URLs for 600 seconds (10 minutes)
	 * @param array[] &$urls
	 * @return void
	 */
	public function onOpenSearchUrls( &$urls ): void {
		foreach ( $urls as &$url ) {
			if ( in_array( $url['type'], [ 'application/x-suggestions+json', 'application/x-suggestions+xml' ] ) ) {
				$url['template'] = wfAppendQuery(
					$url['template'], [ 'maxage' => 600, 'smaxage' => 600, 'uselang' => 'content' ] );
			}
		}
	}

	/**
	 * Implement diagnostic information into Special:Contact.
	 * @param MailAddress &$contactRecipientAddress
	 * @param MailAddress|null &$replyTo
	 * @param string &$subject
	 * @param string &$text
	 * @param string $formType
	 * @param array $formData
	 * @return bool
	 * @throws MWException
	 */
	public function onContactForm(
		&$contactRecipientAddress,
		&$replyTo,
		&$subject,
		&$text,
		$formType,
		$formData
	): bool {
		$ctx = RequestContext::getMain();
		$user = $ctx->getUser();
		$userIP = $ctx->getRequest()->getIP();

		/**
		 * Spam filter for Special:Contact, checks against [[MediaWiki:weirdgloop-contact-filter]] on metawiki.
		 * Regex per line and use '#' for comments.
		 */
		if ( $this->config->get( 'GloopTweaksEnableContactFilter' ) &&
			!GloopTweaksUtils::checkContactFilter( $subject . "\n" . $text ) ) {
			wfDebugLog( 'GloopTweaks',
				"Blocked contact form from $userIP as their message matches regex in our contact filter" );
			return false;
		}

		// StopForumSpam check: only check users who are not registered already
		if ( $this->config->get( 'GloopTweaksUseSFS' ) && $user->isAnon() && StopForumSpam::isBlacklisted( $userIP ) ) {
			wfDebugLog( 'GloopTweaks',
				"Blocked contact form from $userIP as they are in StopForumSpam's database" );
			return false;
		}

		if ( $this->config->get( 'GloopTweaksSendDetailsWithContactPage' ) ) {
			$text .= "\n\n---\n\n";
			$text .= $this->config->get( MainConfigNames::Server ) . ' (' .
				$this->config->get( MainConfigNames::DBname ) . ") [" . gethostname() . "]\n";
			$text .= $userIP . ' - ' . ( $_SERVER['HTTP_USER_AGENT'] ?? null ) . "\n";
			$text .= 'Referrer: ' . ( $_SERVER['HTTP_REFERER'] ?? null ) . "\n";
			$text .= 'Skin: ' . $ctx->getSkinName() . "\n";
			$text .= 'User: ' . $user->getName() . ' (' . $user->getId() . ')';
		}

		return true;
	}

	/**
	 * Prevent infinite looping of main page requests with cache parameters.
	 * @param WebRequest $request
	 * @param Title $title
	 * @param OutputPage $output
	 * @return bool
	 * @throws MWException
	 */
	public function onTestCanonicalRedirect( $request, $title, $output ): bool {
		if ( $title->isMainPage() && str_starts_with(
			$request->getRequestURL(), $this->config->get( MainConfigNames::ScriptPath ) . '/?' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onGetLocalURL__Internal( $title, &$url, $query ): void {
		$script = $this->config->get( MainConfigNames::Script );

		$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
		if ( $this->config->get( MainConfigNames::MainPageIsDomainRoot ) && $title->isMainPage() ) {
			// Use short URL for main page.
			$url = wfAppendQuery( $this->config->get( MainConfigNames::ScriptPath ) . '/', $query );
		} elseif ( $url == "$script?title=$dbkey&$query" ) {
			// Use short URL for queries.
			$url = wfAppendQuery( str_replace(
				'$1', $dbkey, $this->config->get( MainConfigNames::ArticlePath ) ), $query );
		}
	}

	/**
	 * Add purging for hashless thumbnails.
	 * @param File $file
	 * @param string|false $archiveName
	 * @param array $urls
	 * @return void
	 */
	public function onLocalFilePurgeThumbnails( $file, $archiveName, $urls ): void {
		$hashlessUrls = [];
		foreach ( $urls as $url ) {
			$hashlessUrls[] = strtok( $url, '?' );
		}

		DeferredUpdates::addUpdate( new CdnCacheUpdate( $hashlessUrls ), DeferredUpdates::PRESEND );
	}

	/**
	 * Add purging for global robots.txt, well-known URLs, and hashless images.
	 * @param Title $title
	 * @param string[] &$urls
	 * @return void
	 */
	public function onTitleSquidURLs( $title, &$urls ): void {
		$canonicalServer = $this->config->get( MainConfigNames::CanonicalServer );

		$dbkey = $title->getPrefixedDBKey();
		// MediaWiki:Robots.txt on metawiki is global.
		if ( $dbkey === 'MediaWiki:Robots.txt' && GloopTweaksUtils::currentWikiIsNetworkCentralWiki() ) {
			$urls[] = $canonicalServer . '/robots.txt';
		} elseif ( $dbkey === 'File:Apple-touch-icon.png' ) {
			$urls[] = $canonicalServer . '/apple-touch-icon.png';
		} elseif ( $dbkey === 'File:Favicon.ico' ) {
			$urls[] = $canonicalServer . '/favicon.ico';
		} elseif ( $title->getNamespace() == NS_FILE ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile( $title );
			if ( $file ) {
				$urls[] = strtok( $file->getUrl(), '?' );
			}
		}
	}

	/**
	 * @param ResourceLoader $rl
	 * @return void
	 */
	public function onResourceLoaderRegisterModules( ResourceLoader $rl ): void {
		if ( $this->config->get( 'GloopTweaksEnableTheming' ) ) {
			// Register resource modules for themes.
			foreach ( $this->config->get( 'GloopTweaksThemes' ) as $theme ) {
				$rl->register( "wgl.theme.$theme", [
					'class' => ThemeStylesModule::class,
					'theme' => $theme,
				] );

				// Legacy dark mode
				if ( $theme === 'dark' ) {
					$rl->register( 'wg.darkmode', [
						'class' => ThemeStylesModule::class,
						'theme' => $theme,
					] );
				}
			}
		}
	}

	/**
	 * External Lua library for Scribunto
	 *
	 * @param string $engine
	 * @param array &$extraLibraries
	 */
	public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ): void {
		if ( $engine == 'lua' ) {
			$extraLibraries['mw.ext.GloopTweaks'] = GloopTweaksLuaLibrary::class;
		}
	}

	/**
	 * @param RawAction $obj
	 * @param string &$text
	 * @return void
	 */
	public function onRawPageViewBeforeOutput( $obj, &$text ): void {
		$dbName = $this->config->get( MainConfigNames::DBname );

		// Add a Cache-Tag HTTP header for Cloudflare to use, for ?action=raw.
		if ( $obj->getContext()->canUseWikiPage() && $obj->getWikiPage()->getId() ) {
			$cacheTags = [
				"$dbName:page:{$obj->getWikiPage()->getId()}"
			];
			$request = $obj->getRequest();
			GloopTweaksUtils::addCacheTag( $request, $cacheTags );
		}
	}

	/**
	 * @param ApiBase $module
	 * @return void
	 */
	public function onAPIAfterExecute( $module ): void {
		$dbName = $this->config->get( MainConfigNames::DBname );

		if ( $module instanceof ApiQuery ) {
			$pages = (array)$module->getResult()->getResultData( [ 'query', 'pages' ], [ 'Strip' => 'base' ] );

			// Do not try to add cache tags to API responses that return more than one result.
			// These types of requests probably aren't CDN cached anyway.
			if ( count( $pages ) > 1 ) {
				return;
			}

			// Add a Cache-Tag HTTP header for Cloudflare to use.
			$cacheTags = [];

			foreach ( $pages as $p2 ) {
				if ( isset( $p2['pageid'] ) ) {
					$cacheTags[] = "$dbName:page:{$p2['pageid']}";
				}
			}

			$request = $module->getRequest();
			GloopTweaksUtils::addCacheTag( $request, $cacheTags );
		}
	}

	/**
	 * @param Content $content
	 * @param Title $title
	 * @param ParserOutput $parserOutput
	 * @return void
	 */
	public function onContentAlterParserOutput( $content, $title, $parserOutput ): void {
		// For each file referenced using filepath:// in a CSS page, add a file backlink.
		if ( $content->getModel() === CONTENT_MODEL_CSS ) {
			$text = $parserOutput->getContentHolderText();

			/* @see CSSMin::getUrlRegex */
			$urlRegex = 'url\(\s*+(?:' .
				// Unquoted url
				'(?P<file>[^\'"][^?)]+?)' .
				// Single quoted url
				'|\'(?P<file>[^?\']++)\'' .
				// Double quoted url
				'|"(?P<file>[^?"]++)"' .
				')\s*\)';

			$pattern = '/(?:^|[;{])\K[^;{}]*' . $urlRegex . '[^;}]*+(?=[;}]|$)/J';
			$matches = [];
			preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER );

			/* @see CSSMin::remapOne */
			foreach ( $matches as $match ) {
				$parsedUrl = parse_url( $match['file'] );
				if (
					is_array( $parsedUrl ) &&
					isset( $parsedUrl['scheme'] ) &&
					$parsedUrl['scheme'] == 'filepath' &&
					isset( $parsedUrl['host'] )
				) {
					$name = rawurldecode( parse_url( $match['file'], PHP_URL_HOST ) );
					$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile( $name );
					if ( !$file ) {
						continue;
					}

					$parserOutput->addImage( $file->getTitle()->getDBkey(), $file->getTimestamp(), $file->getSha1() );
				}
			}
		}
	}

	/**
	 * @param Context $context
	 * @param array &$extraHeaders
	 * @return void
	 */
	public function onResourceLoaderBeforeResponse( Context $context, array &$extraHeaders ): void {
		$rl = $context->getResourceLoader();
		$pageStore = MediaWikiServices::getInstance()->getPageStore();

		// Add a Cache-Tag HTTP header for Cloudflare to use.
		$cacheTags = [];

		foreach ( $context->getModules() as $moduleName ) {
			/** @var WikiModule $module */
			$module = $rl->getModule( $moduleName );
			if ( !$module instanceof WikiModule ) {
				continue;
			}

			foreach ( $module->getDefinitionSummary( $context )[0]['pages'] as $pageName => $value ) {
				$page = $pageStore->getExistingPageByText( $pageName );

				if ( $page ) {
					$cacheTags[] = "{$this->config->get( MainConfigNames::DBname )}:page:{$page->getId()}";
				}
			}
		}

		if ( count( $cacheTags ) > 0 ) {
			$extraHeaders[] = 'Cache-Tag: ' . implode( ', ', $cacheTags );
		}
	}

	/** @inheritDoc */
	public function onParserBeforeInternalParse( $parser, &$text, $stripState ) {
		if ( $this->linkCachePrewarmed ) {
			return;
		}

		$pagelinksCachePrewarmReasons = $this->config->get( 'GloopTweaksPagelinksCachePrewarmReasons' );

		try {
			$parserOptions = $parser->getOptions();
			if (
				$parserOptions !== null &&
				in_array( $parserOptions->getRenderReason(), $pagelinksCachePrewarmReasons ) &&
				$parser->getTitle()->canExist()
			) {
				$id = $parser->getTitle()->getId();
				if ( $id !== 0 ) {
					$res = $this->connectionProvider->getReplicaDatabase()
						->newSelectQueryBuilder()
						->select( LinkCache::getSelectFields() )
						->from( 'pagelinks' )
						->where( [ 'pl_from' => $parser->getTitle()->getId() ] )
						->join( 'linktarget', null, 'lt_id = pl_target_id' )
						->join( 'page', null, [
							'page_title = lt_title',
							'page_namespace = lt_namespace',
						] )
						->caller( __METHOD__ )
						->fetchResultSet();

					$batch = $this->linkBatchFactory->newLinkBatch();
					$batch->addResultToCache( $this->linkCache, $res );
					$this->linkCachePrewarmed = true;
				}
			}
		} catch ( Exception $exception ) {
			// Catch and log any exceptions. The batch query is optional, and it should not cause an error if something
			// doesn't work.
			LoggerFactory::getInstance( 'GloopTweaks' )->error( (string)$exception );
		}
	}
}
