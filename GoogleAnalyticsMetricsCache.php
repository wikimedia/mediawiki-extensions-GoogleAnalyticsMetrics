<?php

/**
 * Custom db cache for GoogleAnalyticsMetrics calls
 *
 * @author Ike Hecht
 */
class GoogleAnalyticsMetricsCache {
	const TABLE = 'GoogleAnalyticsMetrics';

	/**
	 * Get this call from db cache
	 *
	 * @param string $request
	 * @param boolean $ignoreExpiration
	 * @return string|boolean
	 */
	public static function getCache( $request, $ignoreExpiration = false ) {
		$dbr = wfGetDB( DB_SLAVE );
		/** @todo Is this platform independent? */
		$conds = array( 'request' => md5( serialize( $request ) ) );
		if ( !$ignoreExpiration ) {
			$conds[] = $dbr->encodeExpiry( wfTimestampNow() ) . ' < expiration';
		}
		$result = $dbr->select( self::TABLE, 'response', $conds, __METHOD__ );

		$row = $result->fetchObject();
		if ( $row ) {
			return ( unserialize( $row->response ) );
		} else {
			return false;
		}
	}

	/**
	 * Store this call in cache
	 *
	 * @param string $request
	 * @param string $response
	 * @param integer $cache_expire
	 * @return boolean
	 * @throws MWException
	 */
	public static function setCache( $request, $response, $cache_expire ) {
		/** @todo: cleanup expired cache rows */
		$dbw = wfGetDB( DB_MASTER );
		$data = array(
			'request' => md5( serialize( $request ) ),
			'response' => serialize( $response ),
			'expiration' => $dbw->encodeExpiry( wfTimestamp( TS_MW, time() + $cache_expire ) )
		);
		$result = $dbw->upsert( self::TABLE, $data, array( 'request' ), $data, __METHOD__ );
		if ( !$result ) {
			throw new MWException( __METHOD__ . ': Set Cache failed' );
		}

		return $result;
	}
}
