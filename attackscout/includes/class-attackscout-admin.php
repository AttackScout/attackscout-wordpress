<?php
/**
 * Administration screens: settings page, dashboard widget, audit action.
 *
 * @package AttackScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // @codeCoverageIgnore
}

/**
 * Settings page (Settings > AttackScout), dashboard widget and the two form
 * actions (run the audit, remove the key). Everything requires the
 * manage_options capability. Pages only read the stored summary: the API is
 * called by the "Run the audit" action alone.
 *
 * @phpstan-import-type Summary from AttackScout_Report
 * @phpstan-type Notice array{type: string, code: string, retry: int}
 */
final class AttackScout_Admin {

	const CAPABILITY    = 'manage_options';
	const PAGE          = 'attackscout';
	const OPTION_KEY    = 'attackscout_api_key';
	const OPTION_SCAN   = 'attackscout_last_scan';
	const NOTICE        = 'attackscout_notice_';
	const ACTION_RUN    = 'attackscout_run_audit';
	const ACTION_FORGET = 'attackscout_forget_key';
	const KEY_PATTERN   = '/^as_(live|test)_[0-9a-f]{48}$/';

	/**
	 * Main points shown, the worst first.
	 */
	const MAX_POINTS = 3;

	/**
	 * Opening of the AttackScout service: 2026-11-30 00:00 UTC. Before that
	 * date, an unreachable or unexpected answer of the API means "not open
	 * yet" and is explained as such.
	 */
	const SERVICE_OPENS = 1795996800;

	/**
	 * Error codes that mean the API is not (yet) there: no answer, an answer
	 * that is not the one of the API, or an unknown address.
	 */
	const UNAVAILABLE_CODES = array( 'network', 'unexpected', 'not_found' );

	/**
	 * Source of the links to attackscout.com, so that AttackScout can count
	 * the visits that come from the plugin. Nothing else is added: no
	 * identifier of the site or of the user.
	 */
	const LINK_SOURCE = 'utm_source=wordpress&utm_medium=plugin&utm_campaign=dashboard';

	/**
	 * API client.
	 *
	 * @var AttackScout_Api
	 */
	private $api;

	/**
	 * URL of the plugin folder, with trailing slash.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Plugin basename (attackscout/attackscout.php).
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param AttackScout_Api $api        API client.
	 * @param string          $plugin_url URL of the plugin folder.
	 * @param string          $basename   Plugin basename.
	 */
	public function __construct( AttackScout_Api $api, string $plugin_url, string $basename ) {
		$this->api        = $api;
		$this->plugin_url = $plugin_url;
		$this->basename   = $basename;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION_RUN, array( $this, 'handle_run_audit' ) );
		add_action( 'admin_post_' . self::ACTION_FORGET, array( $this, 'handle_forget_key' ) );
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );
	}

	/**
	 * Settings > AttackScout.
	 */
	public function add_menu(): void {
		$hook            = add_options_page( __( 'AttackScout', 'attackscout' ), __( 'AttackScout', 'attackscout' ), self::CAPABILITY, self::PAGE, array( $this, 'render_page' ) );
		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * The API key option, never exposed in the REST API.
	 */
	public function register_settings(): void {
		register_setting(
			self::PAGE,
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_key' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Checks a submitted key. An empty field keeps the saved key, which is
	 * never shown again; an invalid key is refused.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_key( $value ): string {
		$current = $this->saved_key();
		$value   = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		if ( '' === $value || $value === $current ) {
			return $current;
		}
		if ( ! preg_match( self::KEY_PATTERN, $value ) ) {
			add_settings_error( self::OPTION_KEY, 'attackscout_invalid_key', __( 'This is not an AttackScout API key (as_live_ followed by 48 hexadecimal characters). The saved key is unchanged.', 'attackscout' ) );
			return $current;
		}
		return $value;
	}

	/**
	 * Dashboard widget, for administrators only.
	 */
	public function add_dashboard_widget(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			wp_add_dashboard_widget( 'attackscout_dashboard', __( 'AttackScout security grade', 'attackscout' ), array( $this, 'render_widget' ) );
		}
	}

	/**
	 * Style sheet, on the dashboard and the settings page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ): void {
		if ( 'index.php' === $hook || ( '' !== $this->page_hook && $this->page_hook === $hook ) ) {
			wp_enqueue_style( 'attackscout-admin', $this->plugin_url . 'assets/admin.css', array(), ATTACKSCOUT_VERSION );
		}
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param array<string> $links Existing links.
	 * @return array<string>
	 */
	public function action_links( $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'attackscout' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$key = $this->saved_key();
		echo '<div class="wrap attackscout">';
		echo '<h1>' . esc_html__( 'AttackScout', 'attackscout' ) . '</h1>';
		$this->render_notice();
		echo '<p>' . esc_html__( 'AttackScout shows what anyone can see of your site from the Internet (email, DNS, certificate, HTTP headers, exposed files) and grades it from A to F.', 'attackscout' ) . '</p>';

		echo '<h2>' . esc_html__( 'Latest audit', 'attackscout' ) . '</h2>';
		$this->render_summary();
		if ( '' !== $key ) {
			$this->render_run_form();
		}

		echo '<h2>' . esc_html__( 'API key', 'attackscout' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::PAGE );
		echo '<p><label for="attackscout-key">' . esc_html__( 'AttackScout API key', 'attackscout' ) . '</label><br />';
		echo '<input type="password" id="attackscout-key" name="' . esc_attr( self::OPTION_KEY ) . '" value="" class="regular-text code" autocomplete="off" spellcheck="false" aria-describedby="attackscout-key-help" /></p>';
		echo '<p id="attackscout-key-help" class="description">';
		if ( '' !== $key ) {
			/* translators: %s: first characters of the saved key, for example as_live_1a2b3c4d. */
			printf( esc_html__( 'Saved key: %s… Leave the field empty to keep it.', 'attackscout' ), '<code>' . esc_html( substr( $key, 0, 16 ) ) . '</code>' );
			echo ' ';
		}
		echo esc_html__( 'Create a key in your AttackScout account, "API keys" page: channel "WordPress", scope scans:write only. The key is stored in the options of this site and only sent to AttackScout.', 'attackscout' ) . ( '' === $key ? ' ' . $this->free_key_link( 'button' ) : '' ) . '</p>';
		submit_button( __( 'Save the key', 'attackscout' ) );
		echo '</form>';
		if ( '' !== $key ) {
			$this->render_action_form( self::ACTION_FORGET, __( 'Remove the key', 'attackscout' ), 'secondary' );
		}

		echo '<h2>' . esc_html__( 'What is sent', 'attackscout' ) . '</h2>';
		echo '<p>' . esc_html__( 'Nothing is sent when you install or open WordPress. When an administrator clicks "Run the audit", the plugin sends the domain name of this site, with your key, to the AttackScout API (attackscout.com). AttackScout then audits only what is public on the Internet. The plugin stores the summary of the latest audit in the options of this site.', 'attackscout' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Dashboard widget.
	 */
	public function render_widget(): void {
		$this->render_notice();
		$this->render_summary();
		if ( '' !== $this->saved_key() ) {
			$this->render_run_form();
			return;
		}
		echo '<p>' . esc_html__( 'Add your AttackScout API key to run the audit of this site.', 'attackscout' ) . ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'attackscout' ) . '</a> ' . $this->free_key_link( 'button button-primary' ) . '</p>';
	}

	/**
	 * Form action: runs the audit, then back to the page of the form.
	 */
	public function handle_run_audit(): void {
		$this->check_request( self::ACTION_RUN );
		$this->save_notice( $this->run_audit() );
		$this->redirect_back();
	}

	/**
	 * Form action: removes the API key.
	 */
	public function handle_forget_key(): void {
		$this->check_request( self::ACTION_FORGET );
		delete_option( self::OPTION_KEY );
		$this->save_notice(
			array(
				'type'  => 'success',
				'code'  => 'key_removed',
				'retry' => 0,
			)
		);
		$this->redirect_back();
	}

	/**
	 * Runs the public audit of this site and stores its summary.
	 *
	 * @return Notice
	 */
	public function run_audit(): array {
		$key = $this->saved_key();
		if ( '' === $key ) {
			return self::error( 'no_key' );
		}
		$domain = AttackScout_Report::public_domain( home_url() );
		if ( '' === $domain ) {
			return self::error( 'local_site' );
		}
		$response = $this->api->run_public_scan( $key, $domain );
		if ( ! $response['ok'] ) {
			return self::error( $response['code'], $response['retry_after'] );
		}
		update_option( self::OPTION_SCAN, AttackScout_Report::from_result( $response['result'] ), false );
		return array(
			'type'  => 'success',
			'code'  => 'scan_done',
			'retry' => 0,
		);
	}

	/**
	 * Message of a notice.
	 *
	 * @param array    $notice Notice: type, code, retry.
	 * @phpstan-param Notice $notice
	 * @param int|null $now    Unix time, null for the current time.
	 * @return string
	 */
	public static function notice_message( array $notice, ?int $now = null ): string {
		if ( in_array( $notice['code'], self::UNAVAILABLE_CODES, true ) && ( $now ?? time() ) < self::SERVICE_OPENS ) {
			return __( 'The service will be available on Nov 30, 2026.', 'attackscout' );
		}
		$minutes  = (string) max( 1, (int) ceil( $notice['retry'] / 60 ) );
		$messages = array(
			'scan_done'           => __( 'Audit done.', 'attackscout' ),
			'key_removed'         => __( 'API key removed.', 'attackscout' ),
			'no_key'              => __( 'Add your AttackScout API key first.', 'attackscout' ),
			'local_site'          => __( 'This site has no public domain name: AttackScout can only audit a site that is reachable from the Internet.', 'attackscout' ),
			'invalid_api_key'     => __( 'Your API key is not recognised. Check it in the settings.', 'attackscout' ),
			'api_key_revoked'     => __( 'Your API key has been revoked. Create a new one in your AttackScout account.', 'attackscout' ),
			'insufficient_scope'  => __( 'Your API key cannot run audits: it needs the scans:write scope.', 'attackscout' ),
			'domain_excluded'     => __( 'The owner of this domain asked not to be audited.', 'attackscout' ),
			'invalid_domain'      => __( 'This domain name cannot be audited.', 'attackscout' ),
			'domain_not_found'    => __( 'This domain does not exist in the DNS.', 'attackscout' ),
			/* translators: %s: number of minutes. */
			'rate_limited'        => $notice['retry'] > 0 ? sprintf( __( 'Too many requests. Try again in %s min.', 'attackscout' ), $minutes ) : __( 'Too many requests. Try again later.', 'attackscout' ),
			/* translators: %s: number of minutes. */
			'scan_quota_exceeded' => $notice['retry'] > 0 ? sprintf( __( 'Daily audit quota reached. Try again in %s min.', 'attackscout' ), $minutes ) : __( 'Daily audit quota reached. Try again tomorrow.', 'attackscout' ),
			'network'             => __( 'AttackScout cannot be reached from this site. Try again later.', 'attackscout' ),
		);
		return $messages[ $notice['code'] ] ?? __( 'Something went wrong on our side. Try again later.', 'attackscout' );
	}

	/**
	 * Summary of the latest audit, environment included.
	 */
	private function render_summary(): void {
		$scan = $this->last_scan();
		if ( null === $scan ) {
			echo '<p>' . esc_html__( 'No audit yet.', 'attackscout' ) . '</p>';
			$this->render_environment();
			return;
		}
		$known = '' !== $scan['grade'];
		echo '<div class="attackscout-headline">';
		echo '<span class="attackscout-grade attackscout-grade-' . esc_attr( $known ? $scan['grade'] : 'partial' ) . '">' . esc_html( $known ? $scan['grade'] : '?' ) . '</span>';
		echo '<span>';
		/* translators: %s: date of the audit. */
		printf( esc_html__( 'Audit of %s', 'attackscout' ), esc_html( $this->date( $scan['created_at'] ) ) );
		echo '<br />';
		if ( $scan['failed'] > 0 ) {
			/* translators: %d: number of points to fix. */
			printf( esc_html( _n( '%d point to fix', '%d points to fix', $scan['failed'], 'attackscout' ) ), (int) $scan['failed'] );
		} else {
			echo esc_html__( 'No point to fix in the public audit.', 'attackscout' );
		}
		echo '</span></div>';
		if ( ! $known ) {
			echo '<p class="description">' . esc_html__( 'Partial grade: fewer than 70% of the checks could be verified.', 'attackscout' ) . '</p>';
		}
		if ( array() !== $scan['families'] ) {
			echo '<h3>' . esc_html__( 'Main points', 'attackscout' ) . '</h3><ul class="attackscout-list">';
			foreach ( array_slice( $scan['families'], 0, self::MAX_POINTS ) as $family ) {
				echo '<li><span class="attackscout-tag attackscout-sev-' . esc_attr( $family['worst'] ) . '">' . esc_html( self::severity_label( $family['worst'] ) ) . '</span> ';
				/* translators: 1: family of checks, for example Email; 2: number of points to fix. */
				printf( esc_html__( '%1$s: %2$d', 'attackscout' ), esc_html( self::family_label( $family['family'] ) ), (int) $family['count'] );
				echo '</li>';
			}
			echo '</ul>';
		}
		if ( array() !== $scan['deadlines'] ) {
			echo '<h3>' . esc_html__( 'Deadlines', 'attackscout' ) . '</h3><ul class="attackscout-list">';
			foreach ( $scan['deadlines'] as $deadline ) {
				echo '<li><span class="attackscout-tag attackscout-urg-' . esc_attr( $deadline['urgency'] ) . '">' . esc_html( self::urgency_label( $deadline['urgency'] ) ) . '</span> ';
				echo esc_html( $this->date( $deadline['date'] ) . ' · ' . self::deadline_label( $deadline ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( '' !== $scan['id'] ) {
			echo '<p class="attackscout-links">';
			if ( $scan['failed'] > 0 ) {
				echo '<a class="button" href="' . esc_url( self::result_url( $scan['id'], 'corriger-titre' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'How to fix these points', 'attackscout' ) . '</a> ';
			}
			echo '<a href="' . esc_url( self::result_url( $scan['id'], 'surveiller' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Monitor this site and get deadline alerts', 'attackscout' ) . '</a>';
			echo '</p>';
		}
		$this->render_environment();
	}

	/**
	 * PHP and WordPress versions, from data already on the site.
	 */
	private function render_environment(): void {
		echo '<h3>' . esc_html__( 'Versions', 'attackscout' ) . '</h3><ul class="attackscout-list">';
		echo '<li>';
		/* translators: %s: PHP version. */
		printf( esc_html__( 'PHP %s', 'attackscout' ), esc_html( PHP_VERSION ) );
		$php = get_site_transient( 'php_check_' . md5( PHP_VERSION ) );
		if ( is_array( $php ) && isset( $php['is_secure'] ) && ! $php['is_secure'] ) {
			echo ' · <strong>' . esc_html__( 'no longer receives security updates', 'attackscout' ) . '</strong>';
		}
		echo '</li><li>';
		/* translators: %s: WordPress version. */
		printf( esc_html__( 'WordPress %s', 'attackscout' ), esc_html( (string) get_bloginfo( 'version' ) ) );
		$core    = get_site_transient( 'update_core' );
		$updates = is_object( $core ) ? ( (array) $core )['updates'] ?? array() : array();
		$next    = is_array( $updates ) && isset( $updates[0] ) && is_object( $updates[0] ) ? (array) $updates[0] : array();
		if ( 'upgrade' === ( $next['response'] ?? '' ) && is_string( $next['current'] ?? null ) ) {
			echo ' · <strong>';
			/* translators: %s: WordPress version available. */
			printf( esc_html__( 'version %s available', 'attackscout' ), esc_html( $next['current'] ) );
			echo '</strong>';
		}
		echo '</li></ul>';
	}

	/**
	 * "Run the audit" button, with what it sends.
	 */
	private function render_run_form(): void {
		$domain = AttackScout_Report::public_domain( home_url() );
		if ( '' === $domain ) {
			echo '<p class="description">' . esc_html__( 'This site has no public domain name: AttackScout can only audit a site that is reachable from the Internet.', 'attackscout' ) . '</p>';
			return;
		}
		echo '<p class="description">';
		/* translators: %s: domain name of the site. */
		printf( esc_html__( 'Clicking sends the domain name %s, and nothing else, to AttackScout.', 'attackscout' ), '<code>' . esc_html( $domain ) . '</code>' );
		echo '</p>';
		$this->render_action_form( self::ACTION_RUN, __( 'Run the audit', 'attackscout' ), 'primary' );
	}

	/**
	 * Form posted to admin-post.php, with its nonce.
	 *
	 * @param string $action Action name.
	 * @param string $label  Button label.
	 * @param string $type   Button type (primary, secondary).
	 */
	private function render_action_form( string $action, string $label, string $type ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		submit_button( $label, $type, 'submit', false );
		echo '</form>';
	}

	/**
	 * Notice left by the last form action of this user, shown once.
	 */
	private function render_notice(): void {
		$name   = self::NOTICE . get_current_user_id();
		$notice = get_transient( $name );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['code'], $notice['retry'] ) || ! is_string( $notice['code'] ) || ! is_int( $notice['retry'] ) ) {
			return;
		}
		delete_transient( $name );
		$notice = array(
			'type'  => 'success' === $notice['type'] ? 'success' : 'error',
			'code'  => $notice['code'],
			'retry' => $notice['retry'],
		);
		$class  = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( self::notice_message( $notice ) ) . '</p></div>';
	}

	/**
	 * Capability and nonce of a form action.
	 *
	 * @param string $action Action name.
	 */
	private function check_request( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'attackscout' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Keeps a notice for the next page of this user.
	 *
	 * @param array $notice Notice: type, code, retry.
	 * @phpstan-param Notice $notice
	 */
	private function save_notice( array $notice ): void {
		set_transient( self::NOTICE . get_current_user_id(), $notice, 300 );
	}

	/**
	 * Back to the page of the form.
	 */
	private function redirect_back(): void {
		$back = wp_get_referer();
		wp_safe_redirect( is_string( $back ) && '' !== $back ? $back : admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit; // @codeCoverageIgnore
	}

	/**
	 * Saved API key, or ''.
	 *
	 * @return string
	 */
	private function saved_key(): string {
		$key = get_option( self::OPTION_KEY, '' );
		return is_string( $key ) && preg_match( self::KEY_PATTERN, $key ) ? $key : '';
	}

	/**
	 * Stored summary of the latest audit, or null.
	 *
	 * @return Summary|null
	 */
	private function last_scan(): ?array {
		$scan = get_option( self::OPTION_SCAN, null );
		if ( ! is_array( $scan ) || ! isset( $scan['id'], $scan['grade'], $scan['created_at'], $scan['failed'], $scan['families'], $scan['deadlines'] ) ) {
			return null;
		}
		/**
		 * Written by run_audit() only.
		 *
		 * @var Summary $scan
		 */
		return $scan;
	}

	/**
	 * Link to the private result page on attackscout.com.
	 *
	 * @param string $id     Result identifier.
	 * @param string $anchor Section of the page: corriger-titre (fixes) or surveiller (monitoring).
	 * @return string
	 */
	public static function result_url( string $id, string $anchor ): string {
		return ATTACKSCOUT_SITE_URL . '/r/' . rawurlencode( $id ) . '?' . self::LINK_SOURCE . '#' . $anchor;
	}

	/**
	 * Date in the format of the site.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function date( int $timestamp ): string {
		$format = get_option( 'date_format' );
		return (string) wp_date( is_string( $format ) && '' !== $format ? $format : 'Y-m-d', $timestamp );
	}

	/**
	 * Failed notice.
	 *
	 * @param string $code  Code.
	 * @param int    $retry Seconds to wait.
	 * @return Notice
	 */
	private static function error( string $code, int $retry = 0 ): array {
		return array(
			'type'  => 'error',
			'code'  => $code,
			'retry' => $retry,
		);
	}

	/**
	 * Name of a family of checks.
	 *
	 * @param string $family Family.
	 * @return string
	 */
	public static function family_label( string $family ): string {
		$labels = array(
			'email'      => __( 'Email', 'attackscout' ),
			'web'        => __( 'Website', 'attackscout' ),
			'domain'     => __( 'Domain name', 'attackscout' ),
			'dns'        => __( 'DNS', 'attackscout' ),
			'tls'        => __( 'TLS certificate', 'attackscout' ),
			'http'       => __( 'HTTP headers', 'attackscout' ),
			'content'    => __( 'Page content', 'attackscout' ),
			'tech'       => __( 'Software versions', 'attackscout' ),
			'reputation' => __( 'Reputation', 'attackscout' ),
			'subdomains' => __( 'Subdomains', 'attackscout' ),
			'exposure'   => __( 'Exposure', 'attackscout' ),
			'trust'      => __( 'Trust', 'attackscout' ),
		);
		return $labels[ $family ] ?? $family;
	}

	/**
	 * Name of a severity.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	public static function severity_label( string $severity ): string {
		$labels = array(
			'blocking' => __( 'Blocking', 'attackscout' ),
			'critical' => __( 'Critical', 'attackscout' ),
			'high'     => __( 'High', 'attackscout' ),
			'medium'   => __( 'Medium', 'attackscout' ),
			'low'      => __( 'Low', 'attackscout' ),
		);
		return $labels[ $severity ] ?? __( 'Info', 'attackscout' );
	}

	/**
	 * Name of an urgency level (issue #31).
	 *
	 * @param string $urgency Urgency.
	 * @return string
	 */
	public static function urgency_label( string $urgency ): string {
		$labels = array(
			'now'        => __( 'Broken now', 'attackscout' ),
			'this_month' => __( 'To fix this month', 'attackscout' ),
			'plan'       => __( 'To plan', 'attackscout' ),
			'ok'         => __( 'In order', 'attackscout' ),
		);
		return $labels[ $urgency ] ?? __( 'Good to know', 'attackscout' );
	}

	/**
	 * Name of a deadline.
	 *
	 * @param array{kind: string, name: string, control: string} $deadline Deadline.
	 * @return string
	 */
	public static function deadline_label( array $deadline ): string {
		switch ( $deadline['kind'] ) {
			case 'eol':
				/* translators: %s: software and version branch, for example PHP 8.1. */
				return sprintf( __( 'End of support: %s', 'attackscout' ), $deadline['name'] );
			case 'domain':
				return __( 'Domain name expiry', 'attackscout' );
			case 'cert':
				return __( 'TLS certificate expiry', 'attackscout' );
			case 'cert100':
				return __( 'Certificates move to 100 days', 'attackscout' );
		}
		/* translators: %s: check identifier, for example A38. */
		return sprintf( __( 'Deadline (check %s)', 'attackscout' ), $deadline['control'] );
	}

	/**
	 * Name of the plugin page hook, for tests and the style sheet.
	 *
	 * @return string
	 */
	public function page_hook(): string {
		return $this->page_hook;
	}

	/**
	 * Source of the link that creates a free API key (same rules as
	 * LINK_SOURCE: no identifier of the site or of the user).
	 */
	const KEY_SOURCE = 'utm_source=wordpress&utm_medium=plugin&utm_campaign=wordpress-cle';

	/**
	 * Page of attackscout.com where a free API key is created.
	 *
	 * @return string
	 */
	public static function free_key_url(): string {
		return ATTACKSCOUT_SITE_URL . '/espace/cles?' . self::KEY_SOURCE;
	}

	/**
	 * "Create a free key" link. A plain link, followed only when the user
	 * clicks it: the plugin makes no request.
	 *
	 * @param string $class CSS classes of the link.
	 * @return string
	 */
	private function free_key_link( string $class ): string {
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::free_key_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create a free key', 'attackscout' ) . '</a>';
	}
}
