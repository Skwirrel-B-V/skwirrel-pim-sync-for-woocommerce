<?php
/**
 * Pest bootstrap — standalone (no WP test suite required).
 *
 * Defines minimal WP/WC stubs so plugin classes can be instantiated
 * without a running WordPress installation.
 */

// Prevent ABSPATH guard from exiting.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wp/');
}

// WordPress time constants.
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Stub WordPress i18n/escaping functions.
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}

// Stub WP_Error class.
if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $code;
        private string $message;
        private $data;

        public function __construct(string $code = '', string $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

// Stub WordPress functions used by plugin classes.
if (!function_exists('get_locale')) {
    function get_locale(): string {
        return 'nl_NL';
    }
}

// Global overrides for get_option() — tests can set $GLOBALS['_test_options'] to
// override specific options for a single test run.
if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        // Allow per-test overrides.
        if (isset($GLOBALS['_test_options'][$option])) {
            return $GLOBALS['_test_options'][$option];
        }
        // Return sensible defaults for test context.
        $options = [
            'skwirrel_wc_sync_settings' => [
                'image_language' => 'nl',
                'include_languages' => ['nl-NL', 'nl'],
                'use_sku_field' => 'internal_product_code',
            ],
        ];
        return $options[$option] ?? $default;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string {
        return strip_tags($text);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}

if (!function_exists('wc_get_logger')) {
    function wc_get_logger() {
        return null;
    }
}

// Stub $wpdb for slug_exists() and other direct queries.
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $term_relationships = 'wp_term_relationships';
        public string $termmeta = 'wp_termmeta';

        public function prepare(string $query, ...$args): string {
            return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), $args);
        }

        public function get_var(string $query) {
            return '0'; // Default: slug does not exist.
        }

        public function get_results(string $query, $output = 'OBJECT') {
            return [];
        }
    };
}

// Stub WordPress hook functions.
if (!function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void {}
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void {}
}

// Stub get_post_meta() — tests can set $GLOBALS['_test_post_meta'][$post_id][$key].
if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false) {
        if (isset($GLOBALS['_test_post_meta'][$post_id][$key])) {
            return $single ? $GLOBALS['_test_post_meta'][$post_id][$key] : [$GLOBALS['_test_post_meta'][$post_id][$key]];
        }
        return $single ? '' : [];
    }
}

// Stub get_post_field() — tests can set $GLOBALS['_test_post_fields'][$post_id][$field].
if (!function_exists('get_post_field')) {
    function get_post_field(string $field, int $post_id = 0) {
        return $GLOBALS['_test_post_fields'][$post_id][$field] ?? '';
    }
}

// Stub get_permalink() — tests can set $GLOBALS['_test_permalinks'][$post_id].
if (!function_exists('get_permalink')) {
    function get_permalink(int $post_id = 0) {
        return $GLOBALS['_test_permalinks'][$post_id] ?? '';
    }
}

// Stub add_query_arg().
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, string $url = ''): string {
        if (is_array($args)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($args);
        }
        return $url;
    }
}

// Stub trailingslashit().
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/') . '/';
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool {
        $GLOBALS['_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        unset($GLOBALS['_test_options'][$option]);
        return true;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array {
        $base = $GLOBALS['_test_upload_basedir'] ?? sys_get_temp_dir();
        return ['basedir' => $base, 'baseurl' => 'https://example.test'];
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        if (is_dir($target)) {
            return true;
        }
        return mkdir($target, 0777, true);
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null): string {
        return date($format, $timestamp ?? time());
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string {
        return json_encode($data, $options, $depth);
    }
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Stub wc_get_product() — tests can set $GLOBALS['_test_wc_products'][$product_id].
if (!function_exists('wc_get_product')) {
    function wc_get_product(int $product_id) {
        return $GLOBALS['_test_wc_products'][$product_id] ?? null;
    }
}

// Stub wc_placeholder_img().
if (!function_exists('wc_placeholder_img')) {
    function wc_placeholder_img(string $size = 'woocommerce_thumbnail'): string {
        return '<img src="placeholder.png" />';
    }
}

// Stub wp_get_attachment_image().
if (!function_exists('wp_get_attachment_image')) {
    function wp_get_attachment_image(int $attachment_id, $size = 'thumbnail'): string {
        return '<img src="attachment-' . $attachment_id . '.png" />';
    }
}

// Stub wc_get_permalink_structure().
if (!function_exists('wc_get_permalink_structure')) {
    function wc_get_permalink_structure(): array {
        return $GLOBALS['_test_wc_permalink_structure'] ?? ['product_base' => 'product'];
    }
}

// Stub WC_Logger to prevent fatal errors in Logger constructor.
if (!class_exists('WC_Logger')) {
    class WC_Logger {
        public function log($level, $message, $context = []) {}
    }
}

// Stub WC_Product for tests.
if (!class_exists('WC_Product')) {
    class WC_Product {
        protected int $id = 0;
        protected string $type = 'simple';
        protected array $attributes = [];
        protected int $parent_id = 0;
        protected int $image_id = 0;
        protected string $sku = '';
        protected array $children = [];

        public function __construct(int $id = 0) {
            $this->id = $id;
        }

        public function get_id(): int {
            return $this->id;
        }

        public function is_type(string $type): bool {
            return $this->type === $type;
        }

        public function get_parent_id(): int {
            return $this->parent_id;
        }

        public function get_attributes(): array {
            return $this->attributes;
        }

        public function get_image_id(): int {
            return $this->image_id;
        }

        public function get_sku(): string {
            return $this->sku;
        }

        public function get_children(): array {
            return $this->children;
        }
    }
}

// Stub WC_Product_Variation for tests.
if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        protected string $type = 'variation';
    }
}

// Stub WC_Product_Variable for tests.
if (!class_exists('WC_Product_Variable')) {
    class WC_Product_Variable extends WC_Product {
        protected string $type = 'variable';
    }
}

// Load plugin classes (order matters — dependencies first).
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-logger.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-media-importer.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-etim-extractor.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-custom-class-extractor.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-attachment-handler.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-product-mapper.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-permalink-settings.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-slug-resolver.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-theme-api.php';
require_once __DIR__ . '/../plugin/skwirrel-pim-sync/includes/class-variation-permalinks.php';
