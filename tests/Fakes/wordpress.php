<?php

/**
 * Fake WordPress for the tests: the functions the plugin calls, with their
 * documented behaviour, over an in-memory state (WP_Fake). No network, no
 * database. Signatures follow developer.wordpress.org.
 */

declare(strict_types=1);

// phpcs:disable

final class WP_Fake
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, bool|null> */
    public static array $autoload = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, mixed> */
    public static array $site_transients = [];
    /** @var array<string, list<callable>> */
    public static array $hooks = [];
    /** @var list<string> */
    public static array $caps = ['manage_options'];
    public static int $user_id = 7;
    public static string $home = 'https://www.boulangerie-martin.fr';
    /** @var string|false */
    public static $referer = 'https://www.boulangerie-martin.fr/wp-admin/index.php';
    public static string $wp_version = '6.8.3';
    public static bool $nonce_ok = true;
    /** @var string|false */
    public static $options_page = 'settings_page_attackscout';
    /** @var null|callable(string, array<string, mixed>): mixed */
    public static $http = null;
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];
    /** @var list<string> */
    public static array $checked_nonces = [];
    /** @var list<array<mixed>> */
    public static array $calls = [];
    /** @var array<string, string> fake translations */
    public static array $translations = [];

    public static function reset(): void
    {
        self::$options = ['date_format' => 'd/m/Y'];
        self::$autoload = [];
        self::$transients = [];
        self::$site_transients = [];
        self::$hooks = [];
        self::$caps = ['manage_options'];
        self::$user_id = 7;
        self::$home = 'https://www.boulangerie-martin.fr';
        self::$referer = 'https://www.boulangerie-martin.fr/wp-admin/index.php';
        self::$wp_version = '6.8.3';
        self::$nonce_ok = true;
        self::$options_page = 'settings_page_attackscout';
        self::$http = null;
        self::$requests = [];
        self::$checked_nonces = [];
        self::$calls = [];
        self::$translations = [];
    }

    /** @param mixed ...$args */
    public static function call(string $name, ...$args): void
    {
        self::$calls[] = array_merge([$name], $args);
    }

    /** @return list<array<mixed>> */
    public static function calls(string $name): array
    {
        return array_values(array_filter(self::$calls, static fn (array $c): bool => $c[0] === $name));
    }

    /** Runs the callbacks of a hook, like do_action(). @param mixed ...$args */
    public static function run(string $hook, ...$args): void
    {
        foreach (self::$hooks[$hook] ?? [] as $cb) {
            $cb(...$args);
        }
    }
}

final class WP_Error
{
    public string $code;

    public function __construct(string $code = '', string $message = '')
    {
        $this->code = $code;
    }
}

/** Thrown by wp_die() and a failed nonce check. */
final class WPDieException extends RuntimeException {}

/** Thrown by wp_safe_redirect(), in place of the exit that follows it. */
final class WPRedirectException extends RuntimeException {}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WP_Fake::$hooks[$hook][] = $cb;
    return true;
}

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_action($hook, $cb, $priority, $args);
}

function __(string $text, string $domain = 'default'): string
{
    return WP_Fake::$translations[$text] ?? $text;
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return __(1 === $number ? $single : $plural, $domain);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return esc_html($text);
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html(__($text, $domain));
}

function esc_url(string $url): string
{
    // Like WordPress: & becomes &#038;.
    return preg_match('#^https?://#', $url) === 1 ? str_replace('&amp;', '&#038;', esc_html($url)) : '';
}

function sanitize_text_field(string $str): string
{
    // Like wp_strip_all_tags(): script and style contents go too.
    $str = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $str);
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($str)));
}

/** @return mixed */
function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

/** @param mixed $data */
function wp_json_encode($data): string
{
    return (string) json_encode($data);
}

/** @param mixed $thing */
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * @param array<string, mixed> $args
 * @return mixed
 */
function wp_remote_post(string $url, array $args = [])
{
    WP_Fake::$requests[] = ['url' => $url, 'args' => $args];
    if (WP_Fake::$http === null) {
        throw new LogicException('unexpected HTTP request');
    }
    return (WP_Fake::$http)($url, $args);
}

/** @param array<string, mixed> $response @return int|string */
function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'] ?? '';
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_body($response): string
{
    return $response['body'] ?? '';
}

/** @param array<string, mixed> $response @return array<string>|string */
function wp_remote_retrieve_header($response, string $header)
{
    return $response['headers'][strtolower($header)] ?? '';
}

/** @return mixed */
function get_option(string $option, $default = false)
{
    return array_key_exists($option, WP_Fake::$options) ? WP_Fake::$options[$option] : $default;
}

/** @param mixed $value */
function update_option(string $option, $value, ?bool $autoload = null): bool
{
    WP_Fake::$options[$option] = $value;
    WP_Fake::$autoload[$option] = $autoload;
    return true;
}

function delete_option(string $option): bool
{
    unset(WP_Fake::$options[$option]);
    return true;
}

/** @return mixed */
function get_transient(string $name)
{
    return WP_Fake::$transients[$name] ?? false;
}

/** @param mixed $value */
function set_transient(string $name, $value, int $expiration = 0): bool
{
    WP_Fake::$transients[$name] = $value;
    WP_Fake::call('set_transient', $name, $expiration);
    return true;
}

function delete_transient(string $name): bool
{
    unset(WP_Fake::$transients[$name]);
    return true;
}

/** @return mixed */
function get_site_transient(string $name)
{
    return WP_Fake::$site_transients[$name] ?? false;
}

function current_user_can(string $capability): bool
{
    return in_array($capability, WP_Fake::$caps, true);
}

function get_current_user_id(): int
{
    return WP_Fake::$user_id;
}

/** @return int|false */
function check_admin_referer(string $action = '-1', string $query_arg = '_wpnonce')
{
    WP_Fake::$checked_nonces[] = $action;
    if (!WP_Fake::$nonce_ok) {
        throw new WPDieException('The link you followed has expired.');
    }
    return 1;
}

function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
    $field = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '" />';
    echo $field;
    return $field;
}

/**
 * @param string|array<string, mixed> $title
 * @param array<string, mixed> $args
 * @return never
 */
function wp_die(string $message = '', $title = '', array $args = [])
{
    throw new WPDieException($message, (int) ($args['response'] ?? 500));
}

/** @return never */
function wp_safe_redirect(string $location, int $status = 302, string $by = 'WordPress')
{
    throw new WPRedirectException($location);
}

/** @return string|false */
function wp_get_referer()
{
    return WP_Fake::$referer;
}

function admin_url(string $path = ''): string
{
    return 'https://www.boulangerie-martin.fr/wp-admin/' . $path;
}

function home_url(string $path = ''): string
{
    return WP_Fake::$home . $path;
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function plugin_dir_url(string $file): string
{
    return 'https://www.boulangerie-martin.fr/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function load_plugin_textdomain(string $domain, bool $deprecated = false, string $path = ''): bool
{
    WP_Fake::call('load_plugin_textdomain', $domain, $deprecated, $path);
    return true;
}

/** @param array<string, mixed> $args */
function register_setting(string $group, string $name, array $args = []): void
{
    WP_Fake::call('register_setting', $group, $name, $args);
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
{
    WP_Fake::call('add_settings_error', $setting, $code, $message, $type);
}

/** @return string|false */
function add_options_page(string $page_title, string $menu_title, string $capability, string $slug, ?callable $callback = null, ?int $position = null)
{
    WP_Fake::call('add_options_page', $page_title, $menu_title, $capability, $slug, $callback);
    return WP_Fake::$options_page;
}

function wp_add_dashboard_widget(string $id, string $name, callable $callback): void
{
    WP_Fake::call('wp_add_dashboard_widget', $id, $name, $callback);
}

/** @param list<string> $deps */
function wp_enqueue_style(string $handle, string $src = '', array $deps = [], ?string $ver = null, string $media = 'all'): void
{
    WP_Fake::call('wp_enqueue_style', $handle, $src, $deps, $ver);
}

function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '" />';
}

function submit_button(?string $text = null, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
    echo '<button type="submit" name="' . esc_attr($name) . '" class="button button-' . esc_attr($type) . '">' . esc_html((string) $text) . '</button>';
}

function get_bloginfo(string $show = ''): string
{
    return 'version' === $show ? WP_Fake::$wp_version : '';
}

/** @return string|false */
function wp_date(string $format, ?int $timestamp = null)
{
    return gmdate($format, $timestamp ?? time());
}
