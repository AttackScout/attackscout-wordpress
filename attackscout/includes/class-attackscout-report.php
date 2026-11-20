<?php
/**
 * Summary of an audit result, as stored in the options.
 *
 * @package AttackScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Turns an audit result of the API (ScanResult) into the short summary that
 * the dashboard shows. The response is checked field by field: only known
 * values are kept.
 *
 * @phpstan-type FamilyPoint array{family: string, count: int, worst: string}
 * @phpstan-type DeadlinePoint array{date: int, control: string, urgency: string, kind: string, name: string}
 * @phpstan-type Summary array{id: string, grade: string, created_at: int, failed: int, verified: int, total: int, families: list<FamilyPoint>, deadlines: list<DeadlinePoint>}
 */
final class AttackScout_Report {

	const GRADES     = array( 'A', 'B', 'C', 'D', 'E', 'F' );
	const SEVERITIES = array( 'blocking', 'critical', 'high', 'medium', 'low', 'info' );
	const FAMILIES   = array( 'email', 'web', 'domain', 'dns', 'tls', 'http', 'content', 'tech', 'reputation', 'subdomains', 'exposure', 'trust' );
	const URGENCIES  = array( 'now', 'this_month', 'plan', 'info', 'ok' );

	/**
	 * Most deadlines shown.
	 */
	const MAX_DEADLINES = 6;

	/**
	 * Domain names that never leave the site: local and reserved names.
	 */
	const LOCAL_SUFFIXES = array( '.localhost', '.local', '.internal', '.test', '.invalid', '.example', '.lan', '.home.arpa' );

	/**
	 * Public domain name of an address, or '' (IP address, local name).
	 *
	 * @param string $url Site address.
	 * @return string
	 */
	public static function public_domain( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		$host = strtolower( rtrim( $host, '.' ) );
		if ( false === strpos( $host, '.' ) || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return '';
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return '';
			}
		}
		return $host;
	}

	/**
	 * Summary of an audit result.
	 *
	 * @param array<mixed> $result ScanResult decoded from JSON.
	 * @return Summary
	 */
	public static function from_result( array $result ): array {
		$families = array();
		$failed   = 0;
		foreach ( self::items( $result, 'findings' ) as $finding ) {
			if ( 'fail' !== ( $finding['status'] ?? '' ) || ! empty( $finding['superseded'] ) ) {
				continue;
			}
			++$failed;
			$family   = self::pick( $finding['family'] ?? '', self::FAMILIES, 'web' );
			$severity = self::pick( $finding['severity'] ?? '', self::SEVERITIES, 'info' );
			if ( ! isset( $families[ $family ] ) ) {
				$families[ $family ] = array(
					'family' => $family,
					'count'  => 0,
					'worst'  => 'info',
				);
			}
			++$families[ $family ]['count'];
			if ( self::rank( $severity ) < self::rank( $families[ $family ]['worst'] ) ) {
				$families[ $family ]['worst'] = $severity;
			}
		}
		$families = array_values( $families );
		usort(
			$families,
			static function ( array $a, array $b ): int {
				return array( self::rank( $a['worst'] ), -$a['count'], $a['family'] ) <=> array( self::rank( $b['worst'] ), -$b['count'], $b['family'] );
			}
		);

		$deadlines = array();
		foreach ( self::items( $result, 'deadlines' ) as $deadline ) {
			$date = is_string( $deadline['date'] ?? null ) ? strtotime( $deadline['date'] ) : false;
			if ( false === $date || count( $deadlines ) >= self::MAX_DEADLINES ) {
				continue;
			}
			$control     = is_string( $deadline['control'] ?? null ) && preg_match( '/^[A-Z]{1,2}[0-9]{1,4}$/', $deadline['control'] ) ? $deadline['control'] : '';
			$details     = isset( $deadline['details'] ) && is_array( $deadline['details'] ) ? $deadline['details'] : array();
			$deadlines[] = array(
				'date'    => $date,
				'control' => $control,
				'urgency' => self::pick( $deadline['urgency'] ?? '', self::URGENCIES, 'info' ),
			) + self::deadline_kind( $control, $details );
		}

		$coverage = isset( $result['coverage'] ) && is_array( $result['coverage'] ) ? $result['coverage'] : array();
		$created  = is_string( $result['createdAt'] ?? null ) ? strtotime( $result['createdAt'] ) : false;
		return array(
			'id'         => is_string( $result['id'] ?? null ) ? $result['id'] : '',
			'grade'      => self::pick( $result['grade'] ?? '', self::GRADES, '' ),
			'created_at' => false === $created ? 0 : $created,
			'failed'     => $failed,
			'verified'   => is_int( $coverage['verified'] ?? null ) ? $coverage['verified'] : 0,
			'total'      => is_int( $coverage['total'] ?? null ) ? $coverage['total'] : 0,
			'families'   => $families,
			'deadlines'  => $deadlines,
		);
	}

	/**
	 * Kind of a deadline, from its check (apps/api/internal/scan): A18 domain
	 * expiry, A68 to A70 certificate expiry, A71 move to 100-day certificates,
	 * end of support of a software when the details name it.
	 *
	 * @param string       $control Check identifier.
	 * @param array<mixed> $details Details of the deadline.
	 * @return array{kind: string, name: string}
	 */
	public static function deadline_kind( string $control, array $details ): array {
		$label = isset( $details['label'] ) && is_string( $details['label'] ) ? sanitize_text_field( $details['label'] ) : '';
		if ( '' !== $label ) {
			$cycle = isset( $details['cycle'] ) && is_string( $details['cycle'] ) ? sanitize_text_field( $details['cycle'] ) : '';
			return array(
				'kind' => 'eol',
				'name' => trim( $label . ' ' . $cycle ),
			);
		}
		$kinds = array(
			'A18' => 'domain',
			'A68' => 'cert',
			'A69' => 'cert',
			'A70' => 'cert',
			'A71' => 'cert100',
		);
		return array(
			'kind' => $kinds[ $control ] ?? 'other',
			'name' => '',
		);
	}

	/**
	 * Lists of objects of a field, other values left out.
	 *
	 * @param array<mixed> $result ScanResult.
	 * @param string       $field  Field name.
	 * @return list<array<mixed>>
	 */
	private static function items( array $result, string $field ): array {
		$list = isset( $result[ $field ] ) && is_array( $result[ $field ] ) ? $result[ $field ] : array();
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * The value if it is one of the allowed ones, else the fallback.
	 *
	 * @param mixed         $value    Value read from the response.
	 * @param array<string> $allowed  Allowed values.
	 * @param string        $fallback Fallback.
	 * @return string
	 */
	private static function pick( $value, array $allowed, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Order of a severity, the worst first.
	 *
	 * @param string $severity Known severity.
	 * @return int
	 */
	private static function rank( string $severity ): int {
		return (int) array_search( $severity, self::SEVERITIES, true );
	}
}
