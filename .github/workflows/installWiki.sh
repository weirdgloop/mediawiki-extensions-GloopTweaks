#! /bin/bash

MW_BRANCH=$1

git clone https://github.com/wikimedia/mediawiki/ --branch "$MW_BRANCH" --depth 1

cd mediawiki

composer install

git clone https://github.com/wikimedia/mediawiki-extensions-ContactPage.git -b $MW_BRANCH extensions/ContactPage
git clone https://github.com/weirdgloop/mediawiki-extensions-Scribunto.git -b weirdgloop/$MW_BRANCH extensions/Scribunto

# Temporarily commented out since we don't run any unit tests right now
: <<'COMMENT'
php maintenance/install.php --dbtype sqlite --dbuser root --dbname mw --dbpath $(pwd) --pass AdminPassword WikiName AdminUser

# echo 'error_reporting(E_ALL| E_STRICT);' >> LocalSettings.php
# echo 'ini_set("display_errors", 1);' >> LocalSettings.php
echo '$wgShowExceptionDetails = true;' >> LocalSettings.php
echo '$wgShowDBErrorBacktrace = true;' >> LocalSettings.php
echo '$wgDevelopmentWarnings = true;' >> LocalSettings.php

echo 'wfLoadExtension( "GloopTweaks" );' >> LocalSettings.php

cat <<EOT >> composer.local.json
{
  "require": {

  },
	"extra": {
		"merge-plugin": {
			"merge-dev": true,
			"include": []
		}
	}
}
EOT
COMMENT
