<?php
/**
 * Skwirrel Sync - Admin Settings UI.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Skwirrel_WC_Sync_Admin_Settings {

    private const PAGE_SLUG = 'skwirrel-pim-sync';
    private const OPTION_KEY = 'skwirrel_wc_sync_settings';
    private const TOKEN_OPTION_KEY = 'skwirrel_wc_sync_auth_token';
    private const MASK = '••••••••';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private const BG_SYNC_ACTION = 'skwirrel_wc_sync_background';
    private const BG_SYNC_TRANSIENT = 'skwirrel_wc_sync_bg_token';
    private const BG_PURGE_ACTION = 'skwirrel_wc_sync_purge_all';
    private const BG_PURGE_TRANSIENT = 'skwirrel_wc_sync_purge_token';

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_skwirrel_wc_sync_test', [$this, 'handle_test_connection']);
        add_action('admin_post_skwirrel_wc_sync_run', [$this, 'handle_sync_now']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // Background sync/purge handlers use nopriv because the loopback request is unauthenticated.
        // Security: each handler validates a single-use transient token (skwirrel_wc_sync_bg_token / skwirrel_wc_sync_purge_token).
        add_action('wp_ajax_' . self::BG_SYNC_ACTION, [$this, 'handle_background_sync']);
        add_action('wp_ajax_nopriv_' . self::BG_SYNC_ACTION, [$this, 'handle_background_sync']);
        add_action('admin_post_skwirrel_wc_sync_purge', [$this, 'handle_purge_now']);
        add_action('admin_post_skwirrel_wc_sync_clear_history', [$this, 'handle_clear_history']);
        add_action('wp_ajax_' . self::BG_PURGE_ACTION, [$this, 'handle_background_purge']);
        add_action('wp_ajax_nopriv_' . self::BG_PURGE_ACTION, [$this, 'handle_background_purge']);
        add_action('wp_ajax_skwirrel_wc_sync_save_slug_resync', [$this, 'handle_save_slug_resync']);
        add_action('wp_ajax_skwirrel_wc_sync_view_log', [$this, 'handle_view_log']);
        add_action('wp_ajax_skwirrel_wc_sync_download_log', [$this, 'handle_download_log']);
        add_action('wp_ajax_skwirrel_wc_sync_abort', [$this, 'handle_abort_sync']);
    }

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Skwirrel Sync', 'skwirrel-pim-sync'),
            __('Skwirrel Sync', 'skwirrel-pim-sync'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void {
        register_setting('skwirrel_wc_sync', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
        add_action('update_option_' . self::OPTION_KEY, [$this, 'on_settings_updated'], 10, 3);
    }

    public function on_settings_updated($old_value, $value, $option): void {
        if (is_array($value)) {
            delete_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS);
            Skwirrel_WC_Sync_Action_Scheduler::instance()->schedule();
        }
    }

    public function sanitize_settings(array $input): array {
        $out = [];
        $out['endpoint_url'] = isset($input['endpoint_url']) ? esc_url_raw(trim($input['endpoint_url'])) : '';
        $out['auth_type'] = in_array($input['auth_type'] ?? '', ['bearer', 'token'], true) ? $input['auth_type'] : 'bearer';
        $token = $this->sanitize_token($input['auth_token'] ?? '');
        if (!empty($token)) {
            update_option(self::TOKEN_OPTION_KEY, $token, false);
        }
        $out['auth_token'] = !empty($token) ? self::MASK : '';
        $out['timeout'] = isset($input['timeout']) ? max(5, min(120, (int) $input['timeout'])) : 30;
        $out['retries'] = isset($input['retries']) ? max(0, min(5, (int) $input['retries'])) : 2;
        $out['sync_interval'] = $input['sync_interval'] ?? '';
        $out['batch_size'] = isset($input['batch_size']) ? max(1, min(50, (int) $input['batch_size'])) : 10;
        $out['sync_categories'] = !empty($input['sync_categories']);
        $out['super_category_id'] = isset($input['super_category_id']) ? sanitize_text_field(trim($input['super_category_id'])) : '';
        $out['sync_grouped_products'] = !empty($input['sync_grouped_products']);
        $out['use_virtual_product_content'] = !empty($input['use_virtual_product_content']);
        $out['sync_related_products'] = !empty($input['sync_related_products']);
        $out['related_products_type'] = in_array($input['related_products_type'] ?? '', ['auto', 'cross_sells', 'upsells', 'both'], true)
            ? $input['related_products_type']
            : 'auto';
        $out['variant_label_field'] = in_array($input['variant_label_field'] ?? '', ['internal_product_code', 'product_erp_description', 'product_name'], true)
            ? $input['variant_label_field']
            : 'internal_product_code';
        $out['sync_images'] = ($input['sync_images'] ?? 'yes') === 'yes';
        // Image language: dropdown or custom
        $lang_select = $input['image_language_select'] ?? '';
        $lang_custom = sanitize_text_field($input['image_language_custom'] ?? '');
        if ($lang_select === '_custom' && $lang_custom !== '') {
            $out['image_language'] = $lang_custom;
        } elseif ($lang_select !== '' && $lang_select !== '_custom') {
            $out['image_language'] = sanitize_text_field($lang_select);
        } else {
            // Backward compatibility: accept old direct field
            $out['image_language'] = sanitize_text_field($input['image_language'] ?? 'nl');
        }
        // Include languages: merge checkboxes + custom input
        $checked = $input['include_languages_checkboxes'] ?? [];
        if (!is_array($checked)) {
            $checked = [];
        }
        $checked = array_map('sanitize_text_field', $checked);
        $custom_raw = $input['include_languages_custom'] ?? '';
        $custom_parts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', is_string($custom_raw) ? $custom_raw : '', -1, PREG_SPLIT_NO_EMPTY))));
        $custom_parts = array_map('sanitize_text_field', $custom_parts);
        $merged = array_values(array_unique(array_merge($checked, $custom_parts)));
        if (empty($merged)) {
            // Backward compatibility: accept old direct field
            $inc = $input['include_languages'] ?? '';
            $parsed = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', is_string($inc) ? $inc : '', -1, PREG_SPLIT_NO_EMPTY))));
            $merged = !empty($parsed) ? $parsed : ['nl-NL', 'nl'];
        }
        $out['include_languages'] = $merged;
        $out['use_sku_field'] = sanitize_text_field($input['use_sku_field'] ?? 'internal_product_code');

        // Collection IDs: comma-separated, keep only numeric values
        $raw_collections = $input['collection_ids'] ?? '';
        $collection_parts = preg_split('/[\s,]+/', is_string($raw_collections) ? $raw_collections : '', -1, PREG_SPLIT_NO_EMPTY);
        $out['collection_ids'] = implode(', ', array_filter(array_map('trim', $collection_parts), 'is_numeric'));
        // Custom classes
        $out['sync_custom_classes'] = !empty($input['sync_custom_classes']);
        $out['sync_trade_item_custom_classes'] = !empty($input['sync_trade_item_custom_classes']);
        $out['custom_class_filter_mode'] = in_array($input['custom_class_filter_mode'] ?? '', ['whitelist', 'blacklist'], true)
            ? $input['custom_class_filter_mode']
            : '';
        $raw_cc_filter = $input['custom_class_filter_ids'] ?? '';
        $cc_parts = preg_split('/[\s,]+/', is_string($raw_cc_filter) ? $raw_cc_filter : '', -1, PREG_SPLIT_NO_EMPTY);
        $out['custom_class_filter_ids'] = implode(', ', array_map('sanitize_text_field', array_map('trim', $cc_parts)));
        $out['custom_class_visibility_mode'] = in_array($input['custom_class_visibility_mode'] ?? '', ['whitelist', 'blacklist'], true)
            ? $input['custom_class_visibility_mode']
            : '';
        $raw_vis = $input['custom_class_visibility_ids'] ?? '';
        $vis_parts = preg_split('/[\s,]+/', is_string($raw_vis) ? $raw_vis : '', -1, PREG_SPLIT_NO_EMPTY);
        $out['custom_class_visibility_ids'] = implode(', ', array_map('sanitize_text_field', array_map('trim', $vis_parts)));

        $out['sync_manufacturers'] = !empty($input['sync_manufacturers']);
        $out['verbose_logging'] = !empty($input['verbose_logging']);
        $out['purge_stale_products'] = !empty($input['purge_stale_products']);
        $out['show_delete_warning'] = !empty($input['show_delete_warning']);
        $out['log_mode_manual'] = in_array($input['log_mode_manual'] ?? '', ['per_sync', 'per_day'], true)
            ? $input['log_mode_manual']
            : 'per_sync';
        $out['log_mode_scheduled'] = in_array($input['log_mode_scheduled'] ?? '', ['per_sync', 'per_day'], true)
            ? $input['log_mode_scheduled']
            : 'per_day';
        $out['log_retention'] = in_array($input['log_retention'] ?? '', ['12hours', '1day', '2days', '7days', '30days', 'manual'], true)
            ? $input['log_retention']
            : '7days';
        return $out;
    }

    private function sanitize_token(string $token): string {
        $token = trim($token);
        if ($token === self::MASK || $token === '') {
            return (string) get_option(self::TOKEN_OPTION_KEY, '');
        }
        return $token;
    }

    public static function get_auth_token(): string {
        return (string) get_option(self::TOKEN_OPTION_KEY, '');
    }

    public function handle_test_connection(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_test', '_wpnonce');

        $opts = get_option(self::OPTION_KEY, []);
        $token = self::get_auth_token();
        $client = new Skwirrel_WC_Sync_JsonRpc_Client(
            $opts['endpoint_url'] ?? '',
            $opts['auth_type'] ?? 'bearer',
            $token,
            (int) ($opts['timeout'] ?? 30),
            (int) ($opts['retries'] ?? 2)
        );

        $result = $client->test_connection();
        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'test' => $result['success'] ? 'ok' : 'fail',
            'message' => $result['success'] ? '' : urlencode($result['error']['message'] ?? 'Unknown error'),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_sync_now(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_run', '_wpnonce');

        $token = bin2hex(random_bytes(16));
        set_transient(self::BG_SYNC_TRANSIENT . '_' . $token, '1', 120);
        set_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS, (string) time(), 60);

        $url = add_query_arg([
            'action' => self::BG_SYNC_ACTION,
            'token' => $token,
        ], admin_url('admin-ajax.php'));

        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'sync',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        wp_remote_post($url, [
            'blocking' => false,
            'timeout' => 0.01,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        exit;
    }

    public function handle_background_sync(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- uses transient-based token instead of nonce
        $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
        if (empty($token) || strlen($token) !== 32 || !ctype_xdigit($token)) {
            wp_die('Invalid request', 403);
        }
        if (get_transient(self::BG_SYNC_TRANSIENT . '_' . $token) !== '1') {
            wp_die('Invalid or expired token', 403);
        }
        delete_transient(self::BG_SYNC_TRANSIENT . '_' . $token);

        $service = new Skwirrel_WC_Sync_Service();
        $service->run_sync(false, Skwirrel_WC_Sync_History::TRIGGER_MANUAL);

        delete_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS);

        wp_die('', 200);
    }

    public function handle_purge_now(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_purge', '_wpnonce');

        $permanent = !empty($_POST['skwirrel_purge_empty_trash']);
        $mode = $permanent ? 'delete' : 'trash';

        $token = bin2hex(random_bytes(16));
        set_transient(self::BG_PURGE_TRANSIENT . '_' . $token, $mode, 120);

        $url = add_query_arg([
            'action' => self::BG_PURGE_ACTION,
            'token' => $token,
        ], admin_url('admin-ajax.php'));

        $redirect = add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'purge' => 'queued',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        wp_remote_post($url, [
            'blocking' => false,
            'timeout' => 0.01,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        exit;
    }

    public function handle_background_purge(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- uses transient-based token instead of nonce
        $token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';
        if (empty($token) || strlen($token) !== 32 || !ctype_xdigit($token)) {
            wp_die('Invalid request', 403);
        }
        $mode = get_transient(self::BG_PURGE_TRANSIENT . '_' . $token);
        if ($mode === false) {
            wp_die('Invalid or expired token', 403);
        }
        delete_transient(self::BG_PURGE_TRANSIENT . '_' . $token);

        $permanent = ($mode === 'delete');
        $purge_handler = new Skwirrel_WC_Sync_Purge_Handler(new Skwirrel_WC_Sync_Logger());
        $purge_handler->purge_all($permanent);

        wp_die('', 200);
    }

    public function handle_clear_history(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-sync'));
        }
        check_admin_referer('skwirrel_wc_sync_clear_history', '_wpnonce');

        $period = isset($_POST['history_period']) ? sanitize_text_field(wp_unslash($_POST['history_period'])) : 'all';
        $history = Skwirrel_WC_Sync_History::get_sync_history();

        if ($period === 'all') {
            Skwirrel_WC_Sync_History::delete_log_files_for_entries($history);
            $history = [];
        } else {
            $days = (int) $period;
            $cutoff = time() - ($days * DAY_IN_SECONDS);
            $kept = [];
            $removed = [];
            foreach ($history as $entry) {
                if (!empty($entry['timestamp']) && $entry['timestamp'] >= $cutoff) {
                    $kept[] = $entry;
                } else {
                    $removed[] = $entry;
                }
            }
            // Only delete log files not referenced by kept entries.
            $active_files = [];
            foreach ($kept as $entry) {
                $f = $entry['log_file'] ?? '';
                if ('' !== $f) {
                    $active_files[$f] = true;
                }
            }
            foreach ($removed as $entry) {
                $f = $entry['log_file'] ?? '';
                if ('' !== $f && !isset($active_files[$f])) {
                    Skwirrel_WC_Sync_History::delete_log_file($f);
                }
            }
            $history = $kept;
        }

        update_option('skwirrel_wc_sync_history', $history, false);

        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'tab' => 'sync',
            'history' => 'cleared',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * AJAX handler: save the "update slug on re-sync" toggle.
     */
    public function handle_save_slug_resync(): void {
        check_ajax_referer( 'skwirrel_slug_resync_nonce', '_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Access denied', 403 );
        }
        $enabled = ! empty( $_POST['enabled'] );
        $opts    = get_option( Skwirrel_WC_Sync_Permalink_Settings::OPTION_KEY, [] );
        $opts['update_slug_on_resync'] = $enabled;
        update_option( Skwirrel_WC_Sync_Permalink_Settings::OPTION_KEY, $opts );
        wp_send_json_success();
    }

    /**
     * AJAX handler: view a sync log file.
     */
    public function handle_view_log(): void {
        check_ajax_referer('skwirrel_view_log_nonce', '_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
        $filename = isset($_POST['filename']) ? sanitize_text_field(wp_unslash($_POST['filename'])) : '';
        if (!preg_match('/^sync-(manual|scheduled)-[\d-]+\.log$/', $filename)) {
            wp_send_json_error('Invalid filename');
        }

        $path = Skwirrel_WC_Sync_Logger::get_log_directory() . $filename;
        if (!file_exists($path)) {
            wp_send_json_error('Log file not found');
        }

        $chunk_bytes = 100 * 1024; // 100 KB per chunk
        $total_size  = filesize($path);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        // Clamp offset to file size.
        if ($offset >= $total_size) {
            wp_send_json_success([
                'content'    => '',
                'offset'     => $total_size,
                'total_size' => $total_size,
                'has_more'   => false,
            ]);
        }

        $remaining = $total_size - $offset;
        $read_size = min($chunk_bytes, $remaining);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct read of log file
        $fh = fopen($path, 'r');
        if (!$fh) {
            wp_send_json_error('Could not open log file');
        }
        if ($offset > 0) {
            fseek($fh, $offset, SEEK_SET);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Direct read of log file
        $content = fread($fh, $read_size);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct read of log file
        fclose($fh);

        $new_offset = $offset + strlen($content);

        wp_send_json_success([
            'content'    => $content,
            'offset'     => $new_offset,
            'total_size' => $total_size,
            'has_more'   => $new_offset < $total_size,
        ]);
    }

    /**
     * AJAX handler: download a log file.
     */
    public function handle_download_log(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below
        $nonce = isset($_GET['_nonce']) ? sanitize_text_field(wp_unslash($_GET['_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'skwirrel_download_log_nonce')) {
            wp_die(esc_html__('Security check failed.', 'skwirrel-pim-sync'), 403);
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-sync'), 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
        $filename = isset($_GET['filename']) ? sanitize_text_field(wp_unslash($_GET['filename'])) : '';
        if (!preg_match('/^sync-(manual|scheduled)-[\d-]+\.log$/', $filename)) {
            wp_die(esc_html__('Invalid filename.', 'skwirrel-pim-sync'), 400);
        }

        $path = Skwirrel_WC_Sync_Logger::get_log_directory() . $filename;
        if (!file_exists($path)) {
            wp_die(esc_html__('Log file not found.', 'skwirrel-pim-sync'), 404);
        }

        $size = filesize($path);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming log file download
        readfile($path);
        exit;
    }

    /**
     * AJAX handler: abort the running sync.
     */
    public function handle_abort_sync(): void {
        check_ajax_referer('skwirrel_abort_sync_nonce', '_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Access denied', 403);
        }

        Skwirrel_WC_Sync_History::request_abort();
        wp_send_json_success();
    }

    public function enqueue_assets(string $hook): void {
        // Only load plugin page assets on our settings page.
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_style('skwirrel-pim-sync-admin', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/admin.css', [], SKWIRREL_WC_SYNC_VERSION); // @phpstan-ignore constant.notFound
        wp_enqueue_style('skwirrel-pim-sync-dashboard', SKWIRREL_WC_SYNC_PLUGIN_URL . 'assets/dashboard.css', [], SKWIRREL_WC_SYNC_VERSION); // @phpstan-ignore constant.notFound
        wp_enqueue_style('skwirrel-pim-sync-inter-font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', [], null);

        // Admin page JS (purge confirmation + auto-reload).
        wp_register_script('skwirrel-pim-sync-admin', false, [], SKWIRREL_WC_SYNC_VERSION, true);
        wp_enqueue_script('skwirrel-pim-sync-admin');

        wp_localize_script('skwirrel-pim-sync-admin', 'skwirrelPimSync', [
            'purgeConfirmPermanent' => __('WARNING: All Skwirrel products will be PERMANENTLY deleted. This cannot be undone!\n\nAre you sure?', 'skwirrel-pim-sync'),
            'purgeConfirmTrash'     => __('All Skwirrel products will be moved to the trash.\n\nAre you sure?', 'skwirrel-pim-sync'),
            'clearHistoryConfirm'   => __('Delete all sync history?', 'skwirrel-pim-sync'),
            'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
            'slugResyncNonce'       => wp_create_nonce( 'skwirrel_slug_resync_nonce' ),
            'viewLogNonce'          => wp_create_nonce( 'skwirrel_view_log_nonce' ),
            'downloadLogNonce'      => wp_create_nonce( 'skwirrel_download_log_nonce' ),
            'abortSyncNonce'        => wp_create_nonce( 'skwirrel_abort_sync_nonce' ),
            'abortSyncConfirm'      => __( 'Stop the running sync?', 'skwirrel-pim-sync' ),
        ]);

        wp_add_inline_script(
            'skwirrel-pim-sync-admin',
            '(function() {'
            . ' var form = document.getElementById("skwirrel-purge-form");'
            . ' if (form) {'
            . '  form.addEventListener("submit", function(e) {'
            . '   var permanent = document.getElementById("skwirrel-purge-permanent").checked;'
            . '   var msg = permanent ? skwirrelPimSync.purgeConfirmPermanent : skwirrelPimSync.purgeConfirmTrash;'
            . '   if (!confirm(msg)) { e.preventDefault(); }'
            . '  });'
            . ' }'
            . ' var langSelect = document.getElementById("image_language_select");'
            . ' if (langSelect) {'
            . '  langSelect.addEventListener("change", function() {'
            . '   var c = document.getElementById("image_language_custom_wrap");'
            . '   c.style.display = this.value === "_custom" ? "inline-block" : "none";'
            . '   if (this.value !== "_custom") { document.getElementById("image_language_custom").value = ""; }'
            . '  });'
            . ' }'
            . ' var subInput = document.getElementById("skwirrel_subdomain");'
            . ' var urlField = document.getElementById("endpoint_url");'
            . ' if (subInput && urlField) {'
            . '  subInput.addEventListener("input", function() {'
            . '   var v = this.value;'
            . '   urlField.value = v ? "https://" + v + ".skwirrel.eu/jsonrpc" : "";'
            . '   var tokenDomain = document.getElementById("skwirrel-token-domain");'
            . '   var tokenLink = document.getElementById("skwirrel-token-link");'
            . '   if (tokenDomain) tokenDomain.textContent = v || "<your-subdomain>";'
            . '   if (tokenLink && v) tokenLink.href = "https://" + v + ".skwirrel.eu/data/webservice";'
            . '   var catLink = document.getElementById("skwirrel-categories-link");'
            . '   var catDomains = document.querySelectorAll(".skwirrel-link-domain");'
            . '   catDomains.forEach(function(el) { el.textContent = v || "<your-subdomain>"; });'
            . '   if (catLink && v) catLink.href = "https://" + v + ".skwirrel.eu/base/categories";'
            . '   var selLink = document.getElementById("skwirrel-selections-link");'
            . '   if (selLink && v) selLink.href = "https://" + v + ".skwirrel.eu/data/selections";'
            . '  });'
            . ' }'
            . ' var historyBtn = document.getElementById("skwirrel-clear-history-btn");'
            . ' if (historyBtn) {'
            . '  historyBtn.addEventListener("click", function(e) {'
            . '   var period = this.form.history_period.value;'
            . '   if (period === "all" && !confirm(skwirrelPimSync.clearHistoryConfirm)) { e.preventDefault(); }'
            . '  });'
            . ' }'
            . ' document.querySelectorAll(".skw-btn-abort-sync").forEach(function(btn) {'
            . '  btn.addEventListener("click", function(e) {'
            . '   e.preventDefault();'
            . '   if (!confirm(skwirrelPimSync.abortSyncConfirm)) return;'
            . '   btn.disabled = true;'
            . '   btn.textContent = "' . esc_js( __( 'Stopping…', 'skwirrel-pim-sync' ) ) . '";'
            . '   fetch(skwirrelPimSync.ajaxUrl + "?action=skwirrel_wc_sync_abort&_nonce=" + encodeURIComponent(skwirrelPimSync.abortSyncNonce), {method:"POST"})'
            . '    .then(function(r){ return r.json(); })'
            . '    .then(function(d){ if(!d.success) btn.textContent = "' . esc_js( __( 'Error', 'skwirrel-pim-sync' ) ) . '"; });'
            . '  });'
            . ' });'
            . '})();'
        );

        // Move WP admin notices into the dashboard notices slot.
        wp_add_inline_script(
            'skwirrel-pim-sync-admin',
            '(function() {'
            . ' var slot = document.getElementById("skwirrel-notices");'
            . ' if (!slot) return;'
            . ' var container = document.getElementById("wpbody-content");'
            . ' if (!container) return;'
            . ' var notices = container.querySelectorAll(":scope > .notice, :scope > .updated, :scope > .error, :scope > .update-nag, .wrap > .notice, .wrap > .updated, .wrap > .error, .wrap > .update-nag");'
            . ' notices.forEach(function(n) { slot.appendChild(n); });'
            . '})();'
        );

        // Inline toggle: save "update slug on re-sync" via AJAX.
        wp_add_inline_script(
            'skwirrel-pim-sync-admin',
            '(function() {'
            . ' var sel = document.getElementById("skwirrel-update-slug-resync");'
            . ' if (!sel) return;'
            . ' sel.addEventListener("change", function() {'
            . '  var enabled = this.value === "1";'
            . '  var hint = document.getElementById("skwirrel-slug-resync-hint");'
            . '  var warn = document.getElementById("skwirrel-slug-warning");'
            . '  if (hint) hint.style.display = enabled ? "" : "none";'
            . '  if (warn) warn.style.display = enabled ? "" : "none";'
            . '  var fd = new FormData();'
            . '  fd.append("action", "skwirrel_wc_sync_save_slug_resync");'
            . '  fd.append("_nonce", skwirrelPimSync.slugResyncNonce);'
            . '  fd.append("enabled", enabled ? "1" : "0");'
            . '  fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
            . '   .then(function() {'
            . '    var ok = document.getElementById("skwirrel-slug-saved");'
            . '    if (ok) { ok.style.display = "inline"; setTimeout(function() { ok.style.display = "none"; }, 1500); }'
            . '   });'
            . ' });'
            . '})();'
        );

        // Log viewer modal with syntax highlighting + chunked rendering.
        wp_add_inline_script(
            'skwirrel-pim-sync-admin',
            '(function() {'
            . ' var CHUNK = 200;'
            . ' var rafId = 0;'
            . ' var currentFile = "";'
            . ' var currentOffset = 0;'
            . ' var currentTotal = 0;'
            // Format a single log line with syntax highlighting.
            . ' function fmtLine(line) {'
            . '  var e = line.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");'
            . '  if (/^={3,}/.test(line)) return "<span class=\"skw-log-separator\">" + e + "</span>";'
            . '  var m = e.match(/^(\\[\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\])\\[(INFO|WARNING|ERROR|DEBUG)\\](.*)/);'
            . '  if (m) {'
            . '   var msg = m[3].replace(/(\\{[^}]+\\})/g,"<span class=\"skw-log-json\">$1</span>");'
            . '   return "<span class=\"skw-log-ts\">" + m[1] + "</span>"'
            . '    + "<span class=\"skw-log-" + m[2].toLowerCase() + "\">[" + m[2] + "]</span>" + msg;'
            . '  }'
            . '  return e;'
            . ' }'
            // Render lines in batches via requestAnimationFrame.
            . ' function renderChunked(lines, el, total) {'
            . '  cancelAnimationFrame(rafId); rafId = 0;'
            . '  var progress = document.getElementById("skwirrel-log-progress");'
            . '  var idx = 0;'
            . '  function batch() {'
            . '   var end = Math.min(idx + CHUNK, lines.length);'
            . '   var html = "";'
            . '   for (var i = idx; i < end; i++) html += fmtLine(lines[i]) + "\\n";'
            . '   el.insertAdjacentHTML("beforeend", html);'
            . '   idx = end;'
            . '   if (progress && total > 0) progress.textContent = idx + " / " + total + " ' . esc_js( __( 'lines', 'skwirrel-pim-sync' ) ) . '";'
            . '   if (idx < lines.length) { rafId = requestAnimationFrame(batch); }'
            . '   else {'
            . '    el.scrollTop = el.scrollHeight;'
            . '    if (progress) progress.style.display = "none";'
            . '   }'
            . '  }'
            . '  if (progress) { progress.textContent = ""; progress.style.display = "inline"; }'
            . '  batch();'
            . ' }'
            // Fetch a chunk from the server and render it.
            . ' function loadChunk(filename, offset, el, append) {'
            . '  var fd = new FormData();'
            . '  fd.append("action", "skwirrel_wc_sync_view_log");'
            . '  fd.append("_nonce", skwirrelPimSync.viewLogNonce);'
            . '  fd.append("filename", filename);'
            . '  fd.append("offset", offset);'
            . '  return fetch(skwirrelPimSync.ajaxUrl, { method: "POST", body: fd })'
            . '   .then(function(r) { return r.json(); })'
            . '   .then(function(r) {'
            . '    if (!r.success) {'
            . '     if (!append) el.innerHTML = "<span class=\"skw-log-error\">' . esc_js( __( 'Error', 'skwirrel-pim-sync' ) ) . ': " + (r.data || "' . esc_js( __( 'Could not load log', 'skwirrel-pim-sync' ) ) . '") + "</span>";'
            . '     return;'
            . '    }'
            . '    currentOffset = r.data.offset;'
            . '    currentTotal = r.data.total_size;'
            . '    var lines = r.data.content.split("\\n");'
            . '    var total = Math.round(r.data.total_size / 50);'
            . '    if (!append) el.innerHTML = "";'
            . '    renderChunked(lines, el, total);'
            . '    var footer = document.getElementById("skwirrel-log-footer");'
            . '    if (footer) footer.style.display = r.data.has_more ? "flex" : "none";'
            . '   })'
            . '   .catch(function() {'
            . '    if (!append) el.innerHTML = "<span class=\"skw-log-error\">' . esc_js( __( 'Network error', 'skwirrel-pim-sync' ) ) . '</span>";'
            . '   });'
            . ' }'
            // View button: open modal and load first chunk.
            . ' document.addEventListener("click", function(e) {'
            . '  var btn = e.target.closest(".skw-btn-log-view");'
            . '  if (!btn) return;'
            . '  e.preventDefault();'
            . '  currentFile = btn.dataset.logFile;'
            . '  currentOffset = 0;'
            . '  var modal = document.getElementById("skwirrel-log-modal");'
            . '  var el = document.getElementById("skwirrel-log-content");'
            . '  var title = document.getElementById("skwirrel-log-title");'
            . '  if (!modal || !el) return;'
            . '  el.innerHTML = "<span class=\"skw-log-debug\">' . esc_js( __( 'Loading…', 'skwirrel-pim-sync' ) ) . '</span>";'
            . '  if (title) title.textContent = currentFile;'
            . '  modal.style.display = "flex";'
            . '  loadChunk(currentFile, 0, el, false);'
            . ' });'
            // Load-more button: fetch next chunk.
            . ' var loadMoreBtn = document.getElementById("skwirrel-log-load-more");'
            . ' if (loadMoreBtn) {'
            . '  loadMoreBtn.addEventListener("click", function() {'
            . '   if (!currentFile || currentOffset >= currentTotal) return;'
            . '   loadMoreBtn.disabled = true;'
            . '   loadMoreBtn.textContent = "' . esc_js( __( 'Loading…', 'skwirrel-pim-sync' ) ) . '";'
            . '   var el = document.getElementById("skwirrel-log-content");'
            . '   loadChunk(currentFile, currentOffset, el, true).then(function() {'
            . '    loadMoreBtn.disabled = false;'
            . '    loadMoreBtn.textContent = "' . esc_js( __( 'Load more', 'skwirrel-pim-sync' ) ) . '";'
            . '   });'
            . '  });'
            . ' }'
            // Download button: direct file download.
            . ' var dlBtn = document.getElementById("skwirrel-log-download");'
            . ' if (dlBtn) {'
            . '  dlBtn.addEventListener("click", function() {'
            . '   if (!currentFile) return;'
            . '   window.location.href = skwirrelPimSync.ajaxUrl'
            . '    + "?action=skwirrel_wc_sync_download_log"'
            . '    + "&_nonce=" + encodeURIComponent(skwirrelPimSync.downloadLogNonce)'
            . '    + "&filename=" + encodeURIComponent(currentFile);'
            . '  });'
            . ' }'
            // Close modal: cancel rendering, clear content.
            . ' function closeModal() {'
            . '  cancelAnimationFrame(rafId); rafId = 0;'
            . '  var modal = document.getElementById("skwirrel-log-modal");'
            . '  if (modal) modal.style.display = "none";'
            . '  var el = document.getElementById("skwirrel-log-content");'
            . '  if (el) el.innerHTML = "";'
            . '  var footer = document.getElementById("skwirrel-log-footer");'
            . '  if (footer) footer.style.display = "none";'
            . '  var progress = document.getElementById("skwirrel-log-progress");'
            . '  if (progress) progress.style.display = "none";'
            . ' }'
            . ' document.addEventListener("click", function(e) {'
            . '  if (e.target.id === "skwirrel-log-modal" || e.target.closest(".skw-modal-close")) closeModal();'
            . ' });'
            . ' document.addEventListener("keydown", function(e) {'
            . '  if (e.key === "Escape") closeModal();'
            . ' });'
            . '})();'
        );

        // Auto-reload when sync is in progress — only on the dashboard.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab parameter is display-only
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        if (in_array($current_tab, ['dashboard', 'sync'], true) && get_transient(Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS)) {
            wp_add_inline_script('skwirrel-pim-sync-admin', 'setTimeout(function(){ window.location.reload(); }, 5000);');
        }
    }

    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'skwirrel-pim-sync'));
        }

        $this->maybe_show_notices();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab parameter is display-only
        $active_view = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        $allowed_views = ['dashboard', 'sync', 'history', 'settings', 'debug'];
        if (!in_array($active_view, $allowed_views, true)) {
            $active_view = 'dashboard';
        }
        // Legacy: map 'sync' tab to dashboard.
        if ($active_view === 'sync') {
            $active_view = 'dashboard';
        }

        $dashboard = new Skwirrel_WC_Sync_Admin_Dashboard();
        $dashboard->render($active_view);
    }

    private function maybe_show_notices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameters
        if (isset($_GET['test'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($_GET['test'] === 'ok') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Connection test successful.', 'skwirrel-pim-sync') . '</p></div>';
            } else {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
                $msg = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : __('Connection failed.', 'skwirrel-pim-sync');
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['sync']) && $_GET['sync'] === 'queued') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync started in the background. Results will appear here once the sync is completed. Refresh the page to check the status.', 'skwirrel-pim-sync') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['history']) && $_GET['history'] === 'cleared') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync history deleted.', 'skwirrel-pim-sync') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['purge']) && $_GET['purge'] === 'queued') {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Purge started in the background. All Skwirrel products, imported media, categories and attributes will be deleted. Refresh the page to check the status.', 'skwirrel-pim-sync') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect parameter
        if (isset($_GET['sync']) && $_GET['sync'] === 'done') {
            $last = Skwirrel_WC_Sync_History::get_last_result();
            if ($last && $last['success']) {
                $with_a = (int) ($last['with_attributes'] ?? 0);
                $without_a = (int) ($last['without_attributes'] ?? 0);
                $msg = sprintf(
                    /* translators: %1$d = created count, %2$d = updated count, %3$d = failed count */
                    esc_html__('Sync completed. Created: %1$d, Updated: %2$d, Failed: %3$d', 'skwirrel-pim-sync'),
                    (int) $last['created'],
                    (int) $last['updated'],
                    (int) $last['failed']
                );
                if ($with_a + $without_a > 0) {
                    $msg .= ' ' . sprintf(
                        /* translators: %1$d = count with attributes, %2$d = count without attributes */
                        esc_html__('(with attributes: %1$d, without: %2$d)', 'skwirrel-pim-sync'),
                        $with_a,
                        $without_a
                    );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync completed. Check the logs for details.', 'skwirrel-pim-sync') . '</p></div>';
            }
        }
    }

}
