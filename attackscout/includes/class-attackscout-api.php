<?php
/**
 * Client of the AttackScout public audit API.
 *
 * @package AttackScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * One call only: POST /api/v1/public/scans (scope scans:write), through the
 * WordPress HTTP API. Only the domain name of the site is sent.
 */
final class AttackScout_Api {

	/**
	 * Seconds to wait for the audit: it usually takes less than a minute.
	 */
	const TIMEOUT = 75;

	/**
	 * API origin, without trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Constructor.
	 *
	 * @param string $base API origin, for example https://attackscout.com.
	 */
	public function __construct( string $base ) {
		$this->base = rtrim( $base, '/' );
	}

	/**
	 * Runs the public audit of a domain.
	 *
	 * @param string $key    AttackScout API key.
	 * @param string $domain Domain name.
	 * @return array{ok: true, result: array<mixed>}|array{ok: false, code: string, retry_after: int}
	 */
	public function run_public_scan( string $key, string $domain ): array {
		$response = wp_remote_post(
			$this->base . '/api/v1/public/scans',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'AttackScout-WordPress/' . ATTACKSCOUT_VERSION,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => (string) wp_json_encode( array( 'domain' => $domain ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::failure( 'network' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 ) {
			if ( is_array( $body ) && isset( $body['id'], $body['findings'] ) && is_string( $body['id'] ) && is_array( $body['findings'] ) ) {
				return array(
					'ok'     => true,
					'result' => $body,
				);
			}
			return self::failure( 'unexpected' );
		}
		$error = is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
		$code  = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : 'unexpected';
		$retry = wp_remote_retrieve_header( $response, 'retry-after' );
		return self::failure( $code, is_string( $retry ) && ctype_digit( $retry ) ? (int) $retry : 0 );
	}

	/**
	 * Failed call.
	 *
	 * @param string $code        Stable error code.
	 * @param int    $retry_after Seconds to wait, 0 if unknown.
	 * @return array{ok: false, code: string, retry_after: int}
	 */
	private static function failure( string $code, int $retry_after = 0 ): array {
		return array(
			'ok'          => false,
			'code'        => $code,
			'retry_after' => $retry_after,
		);
	}
}
