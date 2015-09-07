<?php
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, not a valid entry point.' );
}

$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'GoogleAnalyticsMetrics',
	'author' => 'Ike Hecht',
	'url' => 'https://www.mediawiki.org/wiki/Extension:GoogleAnalyticsMetrics',
	'descriptionmsg' => 'google-analytics-metrics-desc',
	'version' => '0.2.0 beta',
	'license-name' => 'GPL-2.0+'
);

$wgMessageDirs['GoogleAnalyticsMetrics'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['GoogleAnalyticsMetricsHooksMagic'] = __DIR__ .
	'/GoogleAnalyticsMetrics.i18n.magic.php';

$wgAutoloadClasses['GoogleAnalyticsMetricsHooks'] = __DIR__ . '/GoogleAnalyticsMetrics.hooks.php';
$wgAutoloadClasses['GoogleAnalyticsMetricsCache'] = __DIR__ . '/GoogleAnalyticsMetricsCache.php';

$wgHooks['ParserFirstCallInit'][] = 'GoogleAnalyticsMetricsHooks::onParserFirstCallInit';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'GoogleAnalyticsMetricsHooks::onLoadExtensionSchemaUpdates';

$wgGoogleAnalyticsMetricsAllowed = '*';
$wgGoogleAnalyticsMetricsEmail = null;
$wgGoogleAnalyticsMetricsPath = null;
$wgGoogleAnalyticsMetricsExpiry = 600; //10 minutes