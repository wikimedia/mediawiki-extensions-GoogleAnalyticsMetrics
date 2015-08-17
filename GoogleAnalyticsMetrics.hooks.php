<?php

class GoogleAnalyticsMetricsHooks {

	/**
	 * Sets up the parser function
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setFunctionHook( 'googleanalyticsmetrics',
			'GoogleAnalyticsMetricsHooks::googleAnalyticsMetrics' );
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
	public static function googleAnalyticsMetrics( Parser &$parser, $metric, $startDate = null,
		$endDate = null ) {
		global $wgGoogleAnalyticsMetricsAllowed;

		// Setting the defaults above would not allow an empty start parameter
		if ( !$startDate ) {
			$startDate = '2005-01-01';
		}
		if ( !$endDate ) {
			$endDate = 'today';
		}
		if ( $wgGoogleAnalyticsMetricsAllowed !== '*' && !in_array( $metric,
				$wgGoogleAnalyticsMetricsAllowed ) ) {
			return self::getWrappedError( 'The requested metric is forbidden.' );
		}

		return self::getMetric( $metric, $startDate, $endDate );
	}

	/**
	 * Gets the Analytics metric with the dates provided
	 *
	 * @global string $wgGoogleAnalyticsMetricsViewID
	 * @param string $metric The name of the Analyitcs metric, without the "ga:" prefix
	 * @param string $startDate Must be a valid date recognized by the Google API
	 * @param string $endDate Must be a valid date recognized by the Google API
	 * @return string
	 */
	public static function getMetric( $metric, $startDate, $endDate ) {
		global $wgGoogleAnalyticsMetricsViewID;
		$service = self::getService();
		try {
			$response = $service->data_ga->get(
				'ga:' . $wgGoogleAnalyticsMetricsViewID, $startDate, $endDate, 'ga:' . $metric
			);
		} catch ( Exception $e ) {
			MWExceptionHandler::logException( $e );
			return self::getWrappedError( 'Error!' );
		}

		$rows = $response->getRows();
		return $rows[0][0];
	}

	/**
	 * Returns the Analytics service, ready for use
	 *
	 * @global string $wgGoogleAnalyticsMetricsEmail
	 * @global string $wgGoogleAnalyticsMetricsPath
	 * @global WebRequest $wgRequest
	 * @return \Google_Service_Analytics
	 */
	private static function getService() {
		//This entire function is copied from GoogleAnalyticsTopPages::getData()
		global $wgGoogleAnalyticsMetricsEmail, $wgGoogleAnalyticsMetricsPath, $wgRequest;

		// create a new Google_Client object
		$client = new Google_Client();
		// set app name
		$client->setApplicationName( 'GoogleAnalyticsMetrics' );

		$request = $wgRequest;
		// check, if the client is already authenticated
		if ( $request->getSessionData( 'service_token' ) !== null ) {
			$client->setAccessToken( $request->getSessionData( 'service_token' ) );
		}

		// load the certificate key file
		$key = file_get_contents( $wgGoogleAnalyticsMetricsPath );
		// create the service account credentials
		$cred = new Google_Auth_AssertionCredentials(
			$wgGoogleAnalyticsMetricsEmail, array( 'https://www.googleapis.com/auth/analytics.readonly' ),
			$key
		);
		// set the credentials
		$client->setAssertionCredentials( $cred );
		if ( $client->getAuth()->isAccessTokenExpired() ) {
			// authenticate the service account
			$client->getAuth()->refreshTokenWithAssertion( $cred );
		}
		// set the service_token to the session for future requests
		$request->setSessionData( 'service_token', $client->getAccessToken() );

		// Create the needed Google Analytics service object
		return new Google_Service_Analytics( $client );
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
}
