<?php

class GoogleAnalyticsMetricsHooks {

	/** @var Google_Client|null */
	private static $client = null;

	/**
	 * Sets up the parser function
	 *
	 * @param Parser &$parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setFunctionHook( 'googleanalyticsmetrics',
			'GoogleAnalyticsMetricsHooks::googleAnalyticsMetrics' );
		$parser->setFunctionHook( 'googleanalyticstrackurl',
			'GoogleAnalyticsMetricsHooks::googleAnalyticsTrackUrl' );
	}

	/**
	 * @param Parser &$parser
	 * @param string $link
	 * @param string $link_text
	 * @return array|string
	 */
	public static function googleAnalyticsTrackUrl( Parser &$parser, $link, $link_text ) {
		$link = Sanitizer::cleanUrl( $link );
		// We're assuming no relative links here.
		$prots = $parser->getUrlProtocols();

		// Make sure only to use valid protocols. Important for security.
		if ( !preg_match( "/^((?i)$prots)/", $link ) ) {
			return '<strong class="error">' .
				wfMessage( 'googleanalyticsmetrics-invalid-url' )->inContentLanguage()->text() .
				'</strong>';
		}
		// Register the link to ensure that spam filters see it
		$parser->getOutput()->addExternalLink( $link );

		// Linker does html encoding, but we need to ensure the value is JS econded for onclick
		$linkJs = Xml::encodeJsVar( $link );
		$link_html = Linker::makeExternalLink(
			$link,
			$link_text,
			true,
			'',
			[ "onClick" => "ga('send', 'event', 'link', 'click', $linkJs );" ]
		);
		return [ $link_html, 'noparse' => true, 'isHTML' => true ];
	}

	/**
	 * Handles the googleanalyticsmetrics parser function
	 *
	 * @param Parser &$parser Unused
	 * @return mixed
	 */
	public static function googleAnalyticsMetrics( Parser &$parser ) {
		global $wgArticlePath, $wgGoogleAnalyticsMetricsAllowed, $wgScript, $wgUsePathInfo;
		$options = self::extractOptions( array_slice( func_get_args(), 1 ) );

		if ( $wgGoogleAnalyticsMetricsAllowed !== '*' && !in_array( $options['metric'],
				$wgGoogleAnalyticsMetricsAllowed ) ) {
			return self::getWrappedError( 'The requested metric is forbidden.' );
		}

		if ( isset( $options['page'] ) ) {
			$pageName = $options['page'];

			$options['page'] = str_replace( '$1', Title::newFromText( $pageName )->getPrefixedDBKey(), $wgArticlePath );
			$metric_short_url = self::getMetric( $options );

			$long_url = '';
			$metric_long_url = 0;

			if ( $wgUsePathInfo ) {
				$long_url = "$wgScript/$pageName";
			} else {
				$long_url = "$wgScript?title=$pageName";
			}

			if ( $long_url !== $options['page'] ) {
				$options['page'] = $long_url;
				$metric_long_url = self::getMetric( $options );
			}

			// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfAdd
			return $metric_short_url + $metric_long_url;
		}
		return self::getMetric( $options );
	}

	/**
	 * Gets the Analytics metric with the dates provided
	 * Based on https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php
	 *
	 * @param array $options
	 * @return string
	 */
	public static function getMetric( $options ) {
		global $wgGoogleAnalyticsMetricsExpiry, $wgGoogleAnalyticsMetricsViewId;

		// This is the earliest date Analytics accepts
		if ( !isset( $options['startDate'] ) ) {
			$options['startDate'] = '2005-01-01';
		}

		if ( !isset( $options['endDate'] ) ) {
			$options['endDate'] = 'today';
		}

		$service = self::getService();
		$analytics = self::getAnalyticsReporting();

		// Create the DateRange object.
		$dateRange = new Google_Service_AnalyticsReporting_DateRange();
		$dateRange->setStartDate( $options['startDate'] );
		$dateRange->setEndDate( $options['endDate'] );

		// Create the Metrics object.
		$metrics = new Google_Service_AnalyticsReporting_Metric();
		$metrics->setExpression( "ga:" . $options['metric'] );

		// Create the ReportRequest object.
		$request = new Google_Service_AnalyticsReporting_ReportRequest();
		$request->setViewId( $wgGoogleAnalyticsMetricsViewId );
		$request->setDateRanges( $dateRange );
		$request->setMetrics( [ $metrics ] );

		if ( isset( $options['page'] ) ) {
			// Create the DimensionFilter.
			$dimensionFilter = new Google_Service_AnalyticsReporting_DimensionFilter();
			$dimensionFilter->setDimensionName( 'ga:pagePath' );
			$dimensionFilter->setOperator( 'EXACT' );
			$dimensionFilter->setExpressions( [ $options['page'] ] );

			// Create the DimensionFilterClauses
			$dimensionFilterClause = new Google_Service_AnalyticsReporting_DimensionFilterClause();
			$dimensionFilterClause->setFilters( [ $dimensionFilter ] );

			$request->setDimensionFilterClauses( [ $dimensionFilterClause ] );
		} elseif ( isset( $options['url'] ) ) {
			// Create the DimensionFilter.
			$dimensionFilter = new Google_Service_AnalyticsReporting_DimensionFilter();
			$dimensionFilter->setDimensionName( 'ga:eventLabel' );
			$dimensionFilter->setOperator( 'EXACT' );
			$dimensionFilter->setExpressions( [ $options['url'] ] );

			// Create the DimensionFilterClauses
			$dimensionFilterClause = new Google_Service_AnalyticsReporting_DimensionFilterClause();
			$dimensionFilterClause->setFilters( [ $dimensionFilter ] );
			$request->setDimensionFilterClauses( [ $dimensionFilterClause ] );
		}

		// CACHE_DB is slow but we can cache more items - which is likely what we want
		$cache_object = ObjectCache::getInstance( CACHE_DB );
		$cache_key = $cache_object->makeKey( 'google-analytics-metrics', md5( serialize( $request ) ) );

		$responseMetric = unserialize( $cache_object->get( $cache_key ) );

		if ( $responseMetric === false ) {
			try {
				$body = new Google_Service_AnalyticsReporting_GetReportsRequest();
				$body->setReportRequests( [ $request ] );
				$responseMetric = self::getOutputFromResults( $analytics->reports->batchGet( $body ) );

				$cache_object->set( $cache_key, serialize( $responseMetric ), $wgGoogleAnalyticsMetricsExpiry );
			} catch ( Exception $e ) {
				MWExceptionHandler::logException( $e );

				return self::getWrappedError( 'Error!' );
			}
		}

		return $responseMetric;
	}

	/**
	 * @param array $reports
	 * @return array|int
	 * @throws MWException
	 */
	private static function getOutputFromResults( $reports ) {
		$rows = $reports[0]->getData()->getRows();
		if ( !$rows ) {
			return 0;
		}
		$row = $rows[0];
		$dimensions = $row->getDimensions();
		$metrics = $row->getMetrics();
		if ( !$metrics ) {
			throw new MWException( "No metrics returned" );
		}
		return $metrics[0]->getValues()[0];
	}

	/**
	 * @inheritDoc
	 */
	private static function getClientInstance() {
		if ( self::$client == null ) {
			global $wgGoogleAnalyticsMetricsPath;

			self::$client = new Google_Client();
			self::$client->setAuthConfig( $wgGoogleAnalyticsMetricsPath );
			self::$client->setApplicationName( 'GoogleAnalyticsMetrics' );
			self::$client->setScopes( [ 'https://www.googleapis.com/auth/analytics.readonly' ] );
		}
		return self::$client;
	}

	/**
	 * @return \Google_Service_AnalyticsReporting
	 */
	private static function getAnalyticsReporting() {
		return new Google_Service_AnalyticsReporting( self::getClientInstance() );
	}

	/**
	 * Returns the Analytics service, ready for use
	 *
	 * @return \Google_Service_Analytics
	 */
	private static function getService() {
		return new Google_Service_Analytics( self::getClientInstance() );
	}

	/**
	 * Convenience function that returns text wrapped in an error class
	 *
	 * @param string $text
	 * @return string HTML
	 */
	private static function getWrappedError( $text ) {
		return Html::element( 'span', [ 'class' => 'error' ], $text );
	}

	/**
	 * @param array $options
	 * @return array
	 */
	public static function extractOptions( array $options ) {
		$results = [];

		foreach ( $options as $option ) {
			$pair = explode( '=', $option, 2 );
			if ( count( $pair ) === 2 ) {
				$name = trim( $pair[0] );
				$value = trim( $pair[1] );
				$results[$name] = $value;
			}

			if ( count( $pair ) === 1 ) {
				$name = trim( $pair[0] );
				$results[$name] = true;
			}
		}
		return $results;
	}
}
