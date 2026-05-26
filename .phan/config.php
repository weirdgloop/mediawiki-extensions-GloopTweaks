<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';


$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/ContactPage',
		'../../extensions/Scribunto',
	]
);
$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/ContactPage',
		'../../extensions/Scribunto',
	]
);

$cfg['suppress_issue_types'] = array_merge(
	$cfg['suppress_issue_types'],
	[
		'PhanTypePossiblyInvalidDimOffset',
	]
);

return $cfg;
