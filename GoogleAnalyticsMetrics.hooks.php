<?php

class GoogleAnalyticsMetricsHooks {

	private static $client = null;
	/**
	 * Sets up the parser function
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setFunctionHook( 'googleanalyticsmetrics',
			'GoogleAnalyticsMetricsHooks::googleAnalyticsMetrics' );
		$parser->setFunctionHook( 'googleanalyticstrackurl',
			'GoogleAnalyticsMetricsHooks::googleAnalyticsTrackUrl' );
	}

	public static function googleAnalyticsTrackUrl( Parser &$parser, $link, $link_text ) {
		$link_html = Linker::makeExternalLink(
			$link,
			$link_text,
			true,
			'',
			[ "onClick" => "ga('send', 'event', 'link', 'click', '$link' );" ]
		);
		return array( $link_html, 'noparse' => true, 'isHTML' => true );
	}

	/**
	 * Handles the googleanalyticsmetrics parser function
	 *
	 * @global string|array $wgGoogleAnalyticsMetricsAllowed
	 * @param Parser $parser Unused
	 * @param string $metric
	 * @param string $startDate
	 * @param string $endDate
	 * @return string
	 */
	public static function googleAnalyticsMetrics( Parser &$parser ) {
		global $wgGoogleAnalyticsMetricsAllowed, $wgScript, $wgUsePathInfo;
		$options = self::extractOptions( array_slice( func_get_args(), 1 ) );

		// This is the earliest date Analytics accepts
		if ( !isset( $options['startDate'] ) ) {
			$options['startDate'] = '2005-01-01';
		}

		if ( !isset( $options['endDate'] ) ) {
			$options['endDate'] = 'today';
		}

		if ( $wgGoogleAnalyticsMetricsAllowed !== '*' && !in_array( $options['metric'],
				$wgGoogleAnalyticsMetricsAllowed ) ) {
			return self::getWrappedError( 'The requested metric is forbidden.' );
		}

		if ( isset( $options['page'] ) ) {
			$pageName = $options['page'];

			$options['page'] = Title::newFromText( $pageName )->getLocalURL();
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

			return $metric_short_url + $metric_long_url;
		}
		return self::getMetric( $options );
	}

	// Another way to do this is to provide the Id as a config but its an unnecessary step.
	public static function getFirstProfileId( $service ) {
	  $accounts = $service->management_accounts->listManagementAccounts();

	  if ( count( $accounts->getItems() ) > 0 ) {
		$items = $accounts->getItems();
		$firstAccountId = $items[0]->getId();
		$properties = $service->management_webproperties
			->listManagementWebproperties($firstAccountId);

		if ( count( $properties->getItems() ) > 0 ) {
		  $items = $properties->getItems();
		  $firstPropertyId = $items[0]->getId();

		  $profiles = $service->management_profiles
			  ->listManagementProfiles($firstAccountId, $firstPropertyId);

		  if ( count($profiles->getItems() ) > 0 ) {
			$items = $profiles->getItems();

			return $items[0]->getId();

		  } else {
			throw new Exception( 'No views (profiles) found for this user.' );
		  }
		} else {
		  throw new Exception( 'No properties found for this user.' );
		}
	  } else {
		throw new Exception( 'No accounts found for this user.' );
	  }
	}

	/**
	 * Gets the Analytics metric with the dates provided
	 * Based on https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php
	 *
	 * @global int $wgGoogleAnalyticsMetricsExpiry
	 * @return string
	 */
	public static function getMetric( $options ) {
		global $wgGoogleAnalyticsMetricsExpiry;

		$service = self::getService();
		$viewId = self::getFirstProfileId( $service );
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
		$request->setViewId( $viewId );
		$request->setDateRanges( $dateRange );
		$request->setMetrics( array( $metrics ) );

		if ( isset( $options['page'] ) ) {
			// Create the DimensionFilter.
			$dimensionFilter = new Google_Service_AnalyticsReporting_DimensionFilter();
			$dimensionFilter->setDimensionName( 'ga:pagePath' );
			$dimensionFilter->setOperator( 'EXACT' );
			$dimensionFilter->setExpressions( array( $options['page'] ) );

			// Create the DimensionFilterClauses
			$dimensionFilterClause = new Google_Service_AnalyticsReporting_DimensionFilterClause();
			$dimensionFilterClause->setFilters( array( $dimensionFilter ) );

			$request->setDimensionFilterClauses( array( $dimensionFilterClause ) );
		} else if ( isset( $options['url'] ) ) {
			// Create the DimensionFilter.
			$dimensionFilter = new Google_Service_AnalyticsReporting_DimensionFilter();
			$dimensionFilter->setDimensionName( 'ga:eventLabel' );
			$dimensionFilter->setOperator( 'EXACT' );
			$dimensionFilter->setExpressions( array( $options['url'] ) );

			// Create the DimensionFilterClauses
			$dimensionFilterClause = new Google_Service_AnalyticsReporting_DimensionFilterClause();
			$dimensionFilterClause->setFilters( array( $dimensionFilter ) );
			$request->setDimensionFilterClauses( array( $dimensionFilterClause ) );
		}

		$responseMetric = GoogleAnalyticsMetricsCache::getCache( $request );

		if ( !$responseMetric ) {
			try {
				$body = new Google_Service_AnalyticsReporting_GetReportsRequest();
				$body->setReportRequests( array( $request ) );
				$responseMetric = self::getOutputFromResults( $analytics->reports->batchGet( $body ) );

				GoogleAnalyticsMetricsCache::setCache(
					$request,
					$responseMetric,
					$wgGoogleAnalyticsMetricsExpiry
				);

			} catch ( Exception $e ) {
				MWExceptionHandler::logException( $e );

				// Try to at least return something, however old it is
				$lastValue = GoogleAnalyticsMetricsCache::getCache( $request, true );
				if ( $lastValue ) {
					return $lastValue;
				} else {
					return self::getWrappedError( 'Error!' );
				}
			}
		}


		return $responseMetric;
	}

	private static function getOutputFromResults( $reports ) {
		$rows = $reports[0]->getData()->getRows();
		if ( empty( $rows ) ) {
			return 0;
		}
		$row = $rows[0];
		$dimensions = $row->getDimensions();
		$metrics = $row->getMetrics();
		if ( empty( $metrics ) ) {
			throw new MWException( "No metrics returned" );
			return;
		}
		return $metrics[0]->getValues()[0];
	}

	private static function getClientInstance() {
		if ( self::$client == null ) {
			global $wgGoogleAnalyticsMetricsPath;

			self::$client = new Google_Client();
			self::$client->setAuthConfig( $wgGoogleAnalyticsMetricsPath );
			self::$client->setApplicationName( 'GoogleAnalyticsMetrics' );
			self::$client->setScopes( ['https://www.googleapis.com/auth/analytics.readonly'] );
		}
		return self::$client;
	}

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
		return Html::element( 'span', array( 'class' => 'error' ), $text );
	}

	/**
	 *
	 * @param DatabaseUpdater $updater
	 * @return boolean
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( GoogleAnalyticsMetricsCache::TABLE,
			__DIR__ . '/GoogleAnalyticsMetrics.sql', true );
		return true;
	}

	public static function extractOptions( array $options ) {
		$results = array();

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
