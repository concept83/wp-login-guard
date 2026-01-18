<?php
/**
 * Plugin Name: WP Login Guard
 * Description: Two-factor QR code verification for WordPress login with auto-logout and rate limiting
 * Version: 1.1.0
 * Author: Slawek Jurczyk
 * Text Domain: wplgngrd
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class WP_Login_Guard {
    
    private static $instance = null;
    private $table_name;
    private $rate_limit_table;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'login_guard_tokens';
        $this->rate_limit_table = $wpdb->prefix . 'login_guard_rate_limits';
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize plugin
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        // Start session early
        if (!session_id()) {
            session_start();
        }
        
        // Check for mobile verification FIRST (before login_init)
        if (isset($_GET['wplg_verify'])) {
            add_action('template_redirect', [$this, 'show_mobile_verification'], 1);
            return;
        }
        
        // Intercept login page
        add_action('login_init', [$this, 'maybe_show_qr_login']);
        
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Enqueue scripts on login page
        add_action('login_enqueue_scripts', [$this, 'enqueue_login_scripts']);
        
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Auto-logout functionality
        add_action('admin_init', [$this, 'check_auto_logout']);
        add_action('wp_login', [$this, 'set_login_timestamp']);
        add_action('admin_init', [$this, 'maybe_update_activity'], 1);
        
        // Cleanup old rate limit entries daily
        add_action('wp_scheduled_delete', [$this, 'cleanup_rate_limits']);
    }
    
    /**
     * Create database tables on activation
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tokens table
        $sql1 = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            verification_number varchar(10) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Rate limits table
        $sql2 = "CREATE TABLE {$this->rate_limit_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            action_type varchar(50) NOT NULL,
            attempt_count int NOT NULL DEFAULT 1,
            first_attempt_at datetime NOT NULL,
            last_attempt_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ip_action (ip_address, action_type),
            KEY last_attempt (last_attempt_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
    }
    
    /**
     * Cleanup on deactivation
     */
    public function deactivate() {
        $this->cleanup_expired_tokens();
        $this->cleanup_rate_limits();
    }
    
    /**
     * Remove expired tokens from database
     */
    public function cleanup_expired_tokens() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE expires_at < NOW()"
        );
    }
    
    /**
     * Remove old rate limit entries (older than 1 hour)
     */
    public function cleanup_rate_limits() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$this->rate_limit_table} WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }
    
    /**
     * Check rate limit for specific action
     * 
     * @param string $action_type 'qr_generation', 'mobile_verify', 'number_selection'
     * @param int $max_attempts Maximum attempts allowed
     * @param int $time_window_minutes Time window in minutes
     * @return array ['allowed' => bool, 'wait_minutes' => int]
     */
    public function check_rate_limit($action_type, $max_attempts, $time_window_minutes) {
        global $wpdb;
        
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $current_time = current_time('mysql');
        $window_start = date('Y-m-d H:i:s', strtotime("-{$time_window_minutes} minutes"));
        
        // Get existing record within time window
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->rate_limit_table} 
                WHERE ip_address = %s 
                AND action_type = %s 
                AND last_attempt_at > %s",
                $ip_address,
                $action_type,
                $window_start
            )
        );
        
        if (!$record) {
            // No record or expired - create new one
            $wpdb->insert(
                $this->rate_limit_table,
                [
                    'ip_address' => $ip_address,
                    'action_type' => $action_type,
                    'attempt_count' => 1,
                    'first_attempt_at' => $current_time,
                    'last_attempt_at' => $current_time
                ]
            );
            
            return ['allowed' => true, 'wait_minutes' => 0];
        }
        
        // Check if limit exceeded
        if ($record->attempt_count >= $max_attempts) {
            // Calculate wait time
            $first_attempt_time = strtotime($record->first_attempt_at);
            $window_end = $first_attempt_time + ($time_window_minutes * 60);
            $wait_seconds = $window_end - time();
            $wait_minutes = max(1, ceil($wait_seconds / 60));
            
            return ['allowed' => false, 'wait_minutes' => $wait_minutes];
        }
        
        // Increment counter
        $wpdb->update(
            $this->rate_limit_table,
            [
                'attempt_count' => $record->attempt_count + 1,
                'last_attempt_at' => $current_time
            ],
            ['id' => $record->id]
        );
        
        return ['allowed' => true, 'wait_minutes' => 0];
    }
    
    /**
     * Reset rate limit for specific action (e.g., after successful login)
     */
    public function reset_rate_limit($action_type) {
        global $wpdb;
        
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        $wpdb->delete(
            $this->rate_limit_table,
            [
                'ip_address' => $ip_address,
                'action_type' => $action_type
            ]
        );
    }
    
    /**
     * Generate unique token
     */
    public function generate_token() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Create new verification session
     */
    public function create_session() {
        global $wpdb;
        
        $token = $this->generate_token();
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        $wpdb->insert(
            $this->table_name,
            [
                'token' => $token,
                'status' => 'pending',
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
            ]
        );
        
        return $token;
    }
    
    /**
     * Get session by token
     */
    public function get_session($token) {
        global $wpdb;
        
        $current_time = current_time('mysql');
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE token = %s AND expires_at > %s",
                $token,
                $current_time
            )
        );
    }
    
    /**
     * Update session status
     */
    public function update_session($token, $data) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            $data,
            ['token' => $token]
        );
    }

    /**
     * Generate QR code image as base64
     */
    public function generate_qr_code($token) {
        $verify_url = add_query_arg([
            'wplg_verify' => $token
        ], home_url());
        
        $qrCode = QrCode::create($verify_url)
            ->setSize(300)
            ->setMargin(10);
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        
        return $result->getDataUri();
    }

    /**
     * Intercept login page and show QR code
     */
    public function maybe_show_qr_login() {
        // Check if plugin is enabled
        $enabled = get_option('wplgngrd_enabled', false);
        if (!$enabled) {
            return;
        }
        
        // Don't interfere if user is already logged in
        if (is_user_logged_in()) {
            return;
        }
        
        // Don't interfere with logout, password reset, etc.
        if (isset($_GET['action'])) {
            return;
        }
        
        // SECURITY: For POST requests (login form submissions) - verify QR was completed
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_SESSION['wplgngrd_verified']) || $_SESSION['wplgngrd_verified'] !== true) {
                wp_redirect(wp_login_url());
                exit;
            }
            // Verified - clear the session flag and reset rate limits
            unset($_SESSION['wplgngrd_verified']);
            $this->reset_rate_limit('qr_generation');
            $this->reset_rate_limit('mobile_verify');
            $this->reset_rate_limit('number_selection');
            return;
        }
        
        // Show number selection if verified
        if (isset($_GET['wplg_select'])) {
            $this->show_number_selection();
            exit;
        }
        
        // Complete verification and set session flag
        if (isset($_GET['wplg_verified'])) {
            $token = sanitize_text_field($_GET['token']);
            $session = $this->get_session($token);
            
            if ($session && $session->status === 'confirmed') {
                $this->update_session($token, ['status' => 'used']);
                $_SESSION['wplgngrd_verified'] = true;
                return;
            }
        }
        
        // RATE LIMIT: Check QR code generation limit
        $qr_max = (int) get_option('wplgngrd_rate_qr_max', 5);
        $qr_window = (int) get_option('wplgngrd_rate_qr_window', 15);
        
        $rate_check = $this->check_rate_limit('qr_generation', $qr_max, $qr_window);
        
        if (!$rate_check['allowed']) {
            $this->show_rate_limit_error('qr_generation', $rate_check['wait_minutes']);
            exit;
        }
        
        // Show QR code login page
        $this->show_qr_login_page();
        exit;
    }

    /**
     * Display rate limit error page
     */
    private function show_rate_limit_error($action_type, $wait_minutes) {
        $messages = [
            'qr_generation' => sprintf(
                __('Too many login attempts. Please wait %d minutes before trying again.', 'wplgngrd'),
                $wait_minutes
            ),
            'mobile_verify' => sprintf(
                __('Too many verification attempts. Please wait %d minutes before trying again.', 'wplgngrd'),
                $wait_minutes
            ),
            'number_selection' => sprintf(
                __('Too many failed attempts. Please wait %d minutes before trying again.', 'wplgngrd'),
                $wait_minutes
            )
        ];
        
        $message = $messages[$action_type] ?? __('Rate limit exceeded. Please try again later.', 'wplgngrd');
        
        wp_die($message, __('Rate Limit Exceeded', 'wplgngrd'), ['response' => 429]);
    }

    /**
     * Display QR code login page
     */
    private function show_qr_login_page() {
        $token = $this->create_session();
        $qr_code = $this->generate_qr_code($token);
        
        global $wplg_current_token;
        $wplg_current_token = $token;
        
        do_action('login_enqueue_scripts');
        
        include plugin_dir_path(__FILE__) . 'templates/qr-login.php';
    }

    /**
     * Display mobile verification page
     */
    public function show_mobile_verification() {
        $token = sanitize_text_field($_GET['wplg_verify']);
        $session = $this->get_session($token);
        
        if (!$session) {
            wp_die(__('Invalid or expired verification code.', 'wplgngrd'));
        }
        
        if ($session->status !== 'pending') {
            wp_die(__('This verification code has already been used.', 'wplgngrd'));
        }
        
        // RATE LIMIT: Check mobile verification limit
        $mobile_max = (int) get_option('wplgngrd_rate_mobile_max', 10);
        $mobile_window = (int) get_option('wplgngrd_rate_mobile_window', 15);
        
        $rate_check = $this->check_rate_limit('mobile_verify', $mobile_max, $mobile_window);
        
        if (!$rate_check['allowed']) {
            $this->show_rate_limit_error('mobile_verify', $rate_check['wait_minutes']);
            exit;
        }
        
        // CHECK IP BINDING
        $desktop_ip = $session->ip_address;
        $mobile_ip = $_SERVER['REMOTE_ADDR'];

        $ip_check = $this->check_ip_binding($desktop_ip, $mobile_ip);

        if (!$ip_check['allowed']) {
            wp_die($ip_check['message'], __('Security Check Failed', 'wplgngrd'), ['response' => 403]);
        }

        // Generate random 4-digit number and store it
        $verification_number = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $this->update_session($token, [
            'verification_number' => $verification_number
        ]);
        
        include plugin_dir_path(__FILE__) . 'templates/mobile-verify.php';
        
        do_action('login_enqueue_scripts');
        wp_footer();
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('wplgngrd/v1', '/check-token/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'check_token_status'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('wplgngrd/v1', '/update-token/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_token_status'],
            'permission_callback' => '__return_true'
        ]);

        // Record failed number selection attempt
        register_rest_route('wplgngrd/v1', '/record-failed-attempt', [
            'methods' => 'POST',
            'callback' => [$this, 'record_failed_attempt'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * Check token status via REST API
     */
    public function check_token_status($request) {
        $token = $request->get_param('token');
        $session = $this->get_session($token);
        
        if (!$session) {
            return new WP_REST_Response(['status' => 'expired'], 404);
        }
        
        return new WP_REST_Response([
            'status' => $session->status,
            'verification_number' => $session->verification_number
        ], 200);
    }

    /**
     * Update token status via REST API
     */
    public function update_token_status($request) {
        $token = $request->get_param('token');
        $body = json_decode($request->get_body(), true);
        $status = sanitize_text_field($body['status']);
        
        $session = $this->get_session($token);
        
        if (!$session) {
            return new WP_REST_Response(['error' => 'Invalid token'], 404);
        }
        
        $this->update_session($token, ['status' => $status]);
        
        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Record failed number selection attempt (for rate limiting)
     */
    public function record_failed_attempt($request) {
        $number_max = (int) get_option('wplgngrd_rate_number_max', 5);
        $number_window = (int) get_option('wplgngrd_rate_number_window', 15);
        
        $rate_check = $this->check_rate_limit('number_selection', $number_max, $number_window);
        
        return new WP_REST_Response([
            'allowed' => $rate_check['allowed'],
            'wait_minutes' => $rate_check['wait_minutes']
        ], 200);
    }

    /**
     * Enqueue JavaScript for polling
     */
    public function enqueue_login_scripts() {
        global $wplg_current_token;
        
        if (!isset($_GET['wplg_select']) && !empty($wplg_current_token)) {
            wp_enqueue_script(
                'wplgngrd-login',
                plugins_url('assets/js/login-polling.js', __FILE__),
                ['jquery'],
                '1.1.0',
                true
            );
            
            wp_localize_script('wplgngrd-login', 'wplgngrd', [
                'ajax_url' => rest_url('wplgngrd/v1/check-token/'),
                'token' => $wplg_current_token
            ]);
        }
    }

    /**
     * Display number selection page
     */
    private function show_number_selection() {
        $token = sanitize_text_field($_GET['token']);
        $session = $this->get_session($token);
        
        if (!$session || $session->status !== 'confirmed') {
            wp_die(__('Invalid session. Please start over.', 'wplgngrd'));
        }
        
        $correct_number = $session->verification_number;
        
        // Generate 4 random decoy numbers
        $decoys = [];
        while (count($decoys) < 4) {
            $decoy = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            if ($decoy !== $correct_number && !in_array($decoy, $decoys)) {
                $decoys[] = $decoy;
            }
        }
        
        $numbers = array_merge([$correct_number], $decoys);
        shuffle($numbers);
        
        // RATE LIMIT: Check number selection limit (for wrong attempts)
        $number_max = (int) get_option('wplgngrd_rate_number_max', 5);
        $number_window = (int) get_option('wplgngrd_rate_number_window', 15);
        
        // Note: We'll check this in JavaScript when wrong number is clicked
        // Pass rate limit info to template
        $rate_limit_info = [
            'max' => $number_max,
            'window' => $number_window
        ];
        
        include plugin_dir_path(__FILE__) . 'templates/number-selection.php';
        
        do_action('login_enqueue_scripts');
        wp_footer();
    }

    /**
     * Set login timestamp when user logs in
     */
    public function set_login_timestamp($user_login) {
        $user = get_user_by('login', $user_login);
        if ($user) {
            update_user_meta($user->ID, 'wplgngrd_last_activity', time());
        }
    }

    /**
     * Update activity timestamp only on real page loads
     */
    public function maybe_update_activity() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        if (!empty($_POST['action']) && $_POST['action'] === 'heartbeat') {
            return;
        }
        
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            update_user_meta($current_user->ID, 'wplgngrd_last_activity', time());
        }
    }

    /**
     * Check and enforce auto-logout
     */
    public function check_auto_logout() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $auto_logout = (int) get_option('wplgngrd_auto_logout', 0);
        
        if ($auto_logout <= 0) {
            return;
        }
        
        $current_user = wp_get_current_user();
        $last_activity = (int) get_user_meta($current_user->ID, 'wplgngrd_last_activity', true);
        
        if (!$last_activity) {
            update_user_meta($current_user->ID, 'wplgngrd_last_activity', time());
            return;
        }
        
        $timeout_seconds = $auto_logout * 60;
        $inactive_time = time() - $last_activity;
        
        if ($inactive_time > $timeout_seconds) {
            wp_logout();
            wp_redirect(add_query_arg('wplg_timeout', '1', wp_login_url()));
            exit;
        }
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_options_page(
            __('WP Login Guard Settings', 'wplgngrd'),
            __('Login Guard', 'wplgngrd'),
            'manage_options',
            'wp-login-guard',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Main settings
        register_setting('wplgngrd_settings', 'wplgngrd_enabled', [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);
        
        register_setting('wplgngrd_settings', 'wplgngrd_auto_logout', [
            'type' => 'integer',
            'default' => 0,
            'sanitize_callback' => 'absint'
        ]);
        
        // Rate limit settings - QR Generation
        register_setting('wplgngrd_settings', 'wplgngrd_rate_qr_max', [
            'type' => 'integer',
            'default' => 5,
            'sanitize_callback' => 'absint'
        ]);
        
        register_setting('wplgngrd_settings', 'wplgngrd_rate_qr_window', [
            'type' => 'integer',
            'default' => 15,
            'sanitize_callback' => 'absint'
        ]);
        
        // Rate limit settings - Mobile Verification
        register_setting('wplgngrd_settings', 'wplgngrd_rate_mobile_max', [
            'type' => 'integer',
            'default' => 10,
            'sanitize_callback' => 'absint'
        ]);
        
        register_setting('wplgngrd_settings', 'wplgngrd_rate_mobile_window', [
            'type' => 'integer',
            'default' => 15,
            'sanitize_callback' => 'absint'
        ]);
        
        // Rate limit settings - Number Selection
        register_setting('wplgngrd_settings', 'wplgngrd_rate_number_max', [
            'type' => 'integer',
            'default' => 5,
            'sanitize_callback' => 'absint'
        ]);
        
        register_setting('wplgngrd_settings', 'wplgngrd_rate_number_window', [
            'type' => 'integer',
            'default' => 15,
            'sanitize_callback' => 'absint'
        ]);

        // IP Binding settings
        register_setting('wplgngrd_settings', 'wplgngrd_strict_ip', [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);

        register_setting('wplgngrd_settings', 'wplgngrd_ip_whitelist', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => [$this, 'sanitize_ip_whitelist']
        ]);
        
        // Main section
        add_settings_section(
            'wplgngrd_main_section',
            __('Login Guard Configuration', 'wplgngrd'),
            [$this, 'render_section_description'],
            'wp-login-guard'
        );
        
        add_settings_field(
            'wplgngrd_enabled',
            __('Enable Login Guard', 'wplgngrd'),
            [$this, 'render_enabled_field'],
            'wp-login-guard',
            'wplgngrd_main_section'
        );
        
        add_settings_field(
            'wplgngrd_auto_logout',
            __('Auto-logout after inactivity', 'wplgngrd'),
            [$this, 'render_auto_logout_field'],
            'wp-login-guard',
            'wplgngrd_main_section'
        );
        
        // Rate limiting section
        add_settings_section(
            'wplgngrd_rate_section',
            __('Rate Limiting Configuration', 'wplgngrd'),
            [$this, 'render_rate_section_description'],
            'wp-login-guard'
        );
        
        add_settings_field(
            'wplgngrd_rate_qr',
            __('QR Code Generation Limit', 'wplgngrd'),
            [$this, 'render_rate_qr_field'],
            'wp-login-guard',
            'wplgngrd_rate_section'
        );
        
        add_settings_field(
            'wplgngrd_rate_mobile',
            __('Mobile Verification Limit', 'wplgngrd'),
            [$this, 'render_rate_mobile_field'],
            'wp-login-guard',
            'wplgngrd_rate_section'
        );
        
        add_settings_field(
            'wplgngrd_rate_number',
            __('Number Selection Limit', 'wplgngrd'),
            [$this, 'render_rate_number_field'],
            'wp-login-guard',
            'wplgngrd_rate_section'
        );

        // Advanced security section
        add_settings_section(
            'wplgngrd_advanced_section',
            __('Advanced Security', 'wplgngrd'),
            [$this, 'render_advanced_section_description'],
            'wp-login-guard'
        );

        add_settings_field(
            'wplgngrd_strict_ip',
            __('Strict IP Binding', 'wplgngrd'),
            [$this, 'render_strict_ip_field'],
            'wp-login-guard',
            'wplgngrd_advanced_section'
        );

        add_settings_field(
            'wplgngrd_ip_whitelist',
            __('Whitelisted IPs', 'wplgngrd'),
            [$this, 'render_ip_whitelist_field'],
            'wp-login-guard',
            'wplgngrd_advanced_section'
        );
    }

    public function render_section_description() {
        echo '<p>' . esc_html__('Configure QR code verification and security settings for login.', 'wplgngrd') . '</p>';
    }

    public function render_rate_section_description() {
        echo '<p>' . esc_html__('Rate limiting prevents brute-force attacks by restricting the number of attempts from a single IP address.', 'wplgngrd') . '</p>';
    }

    public function render_enabled_field() {
        $enabled = get_option('wplgngrd_enabled', false);
        ?>
        <label>
            <input type="checkbox" name="wplgngrd_enabled" value="1" <?php checked($enabled, true); ?> />
            <?php esc_html_e('Enable QR code verification for all users', 'wplgngrd'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, all users must verify with QR code before logging in.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    public function render_auto_logout_field() {
        $auto_logout = get_option('wplgngrd_auto_logout', 0);
        ?>
        <select name="wplgngrd_auto_logout">
            <option value="0" <?php selected($auto_logout, 0); ?>><?php esc_html_e('Disabled', 'wplgngrd'); ?></option>
            <option value="2" <?php selected($auto_logout, 2); ?>>2 <?php esc_html_e('minutes', 'wplgngrd'); ?></option>
            <option value="15" <?php selected($auto_logout, 15); ?>>15 <?php esc_html_e('minutes', 'wplgngrd'); ?></option>
            <option value="30" <?php selected($auto_logout, 30); ?>>30 <?php esc_html_e('minutes', 'wplgngrd'); ?></option>
            <option value="60" <?php selected($auto_logout, 60); ?>>1 <?php esc_html_e('hour', 'wplgngrd'); ?></option>
            <option value="120" <?php selected($auto_logout, 120); ?>>2 <?php esc_html_e('hours', 'wplgngrd'); ?></option>
            <option value="240" <?php selected($auto_logout, 240); ?>>4 <?php esc_html_e('hours', 'wplgngrd'); ?></option>
        </select>
        <p class="description">
            <?php esc_html_e('Automatically log out users after this period of inactivity.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    public function render_rate_qr_field() {
        $max = get_option('wplgngrd_rate_qr_max', 5);
        $window = get_option('wplgngrd_rate_qr_window', 15);
        ?>
        <input type="number" name="wplgngrd_rate_qr_max" value="<?php echo esc_attr($max); ?>" min="1" max="100" style="width: 80px;" />
        <?php esc_html_e('attempts per', 'wplgngrd'); ?>
        <input type="number" name="wplgngrd_rate_qr_window" value="<?php echo esc_attr($window); ?>" min="1" max="60" style="width: 80px;" />
        <?php esc_html_e('minutes', 'wplgngrd'); ?>
        <p class="description">
            <?php esc_html_e('Limits how many times a user can load the login page and generate QR codes.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    public function render_rate_mobile_field() {
        $max = get_option('wplgngrd_rate_mobile_max', 10);
        $window = get_option('wplgngrd_rate_mobile_window', 15);
        ?>
        <input type="number" name="wplgngrd_rate_mobile_max" value="<?php echo esc_attr($max); ?>" min="1" max="100" style="width: 80px;" />
        <?php esc_html_e('attempts per', 'wplgngrd'); ?>
        <input type="number" name="wplgngrd_rate_mobile_window" value="<?php echo esc_attr($window); ?>" min="1" max="60" style="width: 80px;" />
        <?php esc_html_e('minutes', 'wplgngrd'); ?>
        <p class="description">
            <?php esc_html_e('Limits how many times verification numbers can be requested from a single IP.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    public function render_rate_number_field() {
        $max = get_option('wplgngrd_rate_number_max', 5);
        $window = get_option('wplgngrd_rate_number_window', 15);
        ?>
        <input type="number" name="wplgngrd_rate_number_max" value="<?php echo esc_attr($max); ?>" min="1" max="100" style="width: 80px;" />
        <?php esc_html_e('failed attempts per', 'wplgngrd'); ?>
        <input type="number" name="wplgngrd_rate_number_window" value="<?php echo esc_attr($window); ?>" min="1" max="60" style="width: 80px;" />
        <?php esc_html_e('minutes', 'wplgngrd'); ?>
        <p class="description">
            <?php esc_html_e('Limits how many wrong number selections can be made across all sessions.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'wplgngrd_messages',
                'wplgngrd_message',
                __('Settings saved successfully.', 'wplgngrd'),
                'success'
            );
        }
        
        settings_errors('wplgngrd_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('wplgngrd_settings');
                do_settings_sections('wp-login-guard');
                submit_button(__('Save Settings', 'wplgngrd'));
                ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('How it works', 'wplgngrd'); ?></h2>
            <ol>
                <li><?php esc_html_e('User visits the login page and sees a QR code', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('User scans QR code with their mobile phone', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('Mobile shows a random 4-digit number', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('User clicks "Continue to Login" on mobile', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('Desktop shows 5 numbers - user selects the correct one', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('User can now log in with their credentials', 'wplgngrd'); ?></li>
            </ol>
            
            <hr>
            
            <h2><?php esc_html_e('Security Features', 'wplgngrd'); ?></h2>
            <ul>
                <li><?php esc_html_e('✓ Blocks direct POST attacks - bots cannot bypass QR verification', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('✓ 15-minute token expiry prevents replay attacks', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('✓ One-time use tokens - each verification is unique', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('✓ Session-based verification tracking', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('✓ Rate limiting prevents brute-force attacks', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('✓ Auto-logout after inactivity', 'wplgngrd'); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Sanitize IP whitelist
     */
    public function sanitize_ip_whitelist($input) {
        if (empty($input)) {
            return '';
        }
        
        $ips = explode("\n", $input);
        $valid_ips = [];
        
        foreach ($ips as $ip) {
            $ip = trim($ip);
            
            if (empty($ip)) {
                continue;
            }
            
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $valid_ips[] = $ip;
            }
        }
        
        return implode("\n", $valid_ips);
    }

    /**
     * Check if IP binding is satisfied
     */
    public function check_ip_binding($desktop_ip, $mobile_ip) {
        $strict_ip = get_option('wplgngrd_strict_ip', false);
        
        if (!$strict_ip) {
            return ['allowed' => true, 'reason' => ''];
        }
        
        $whitelist = get_option('wplgngrd_ip_whitelist', '');
        $whitelisted_ips = array_filter(array_map('trim', explode("\n", $whitelist)));
        
        if (in_array($mobile_ip, $whitelisted_ips)) {
            return ['allowed' => true, 'reason' => 'whitelisted'];
        }
        
        if ($desktop_ip !== $mobile_ip) {
            return ['allowed' => true, 'reason' => 'different_ips'];
        }
        
        return [
            'allowed' => false,
            'reason' => 'same_ip',
            'message' => __('Security check failed: Mobile verification must come from a different network. Contact your administrator if you need assistance.', 'wplgngrd')
        ];
    }

    public function render_advanced_section_description() {
        echo '<p>' . esc_html__('Advanced security options for high-security environments.', 'wplgngrd') . '</p>';
    }

    public function render_strict_ip_field() {
        $enabled = get_option('wplgngrd_strict_ip', false);
        ?>
        <label>
            <input type="checkbox" name="wplgngrd_strict_ip" value="1" <?php checked($enabled, true); ?> />
            <?php esc_html_e('Require mobile verification from different IP than desktop', 'wplgngrd'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, the mobile device must be on a different network than the desktop. Recommended for high-security environments. Note: This may cause issues if users scan QR codes from the same WiFi network.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    public function render_ip_whitelist_field() {
        $whitelist = get_option('wplgngrd_ip_whitelist', '');
        ?>
        <textarea 
            name="wplgngrd_ip_whitelist" 
            rows="5" 
            cols="50" 
            class="large-text code"
            placeholder="203.0.113.5&#10;198.51.100.0"
        ><?php echo esc_textarea($whitelist); ?></textarea>
        <p class="description">
            <?php esc_html_e('Enter one IP address per line. These IPs will bypass the strict IP binding check. Useful for office networks or trusted locations.', 'wplgngrd'); ?>
        </p>
        <?php
    }
}

// Initialize plugin
WP_Login_Guard::get_instance();