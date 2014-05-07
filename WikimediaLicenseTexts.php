<?php
if ( !defined( 'MEDIAWIKI' ) ) die();
/**
 * An extension that adds Wikimedia specific functionality
 *
 * @file
 * @ingroup Extensions
 *
 * @copyright Copyright © 2008-2009, Tim Starling
 * @copyright Copyright © 2009-2012, Siebrand Mazeland, Multichill
 * @license https://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'WikimediaLicenseTexts',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:WikimediaMessages',
	'author'         => array( 'Multichill', 'Siebrand Mazeland' ),
	'descriptionmsg' => 'wikimedialicensetexts-desc',
);

$wgMessagesDirs['WikimediaLicenseTexts'] = __DIR__ . '/i18n/licensetexts';
$wgMessagesDirs['WikimediaCCLicenseTexts'] = __DIR__ . '/i18n/cclicensetexts';
