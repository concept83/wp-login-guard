<?php
/**
 * Plugin Name: WP Login Guard
 * Description: Two-factor QR code verification for WordPress login with auto-logout
 * Version: 1.0.0
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
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'login_guard_tokens';
        
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
        
        // Auto-logout functionality - use different hooks
        add_action('admin_init', [$this, 'check_auto_logout']);
        add_action('wp_login', [$this, 'set_login_timestamp']);
        
        // IMPORTANT: Don't update timestamp on heartbeat/ajax
        add_action('admin_init', [$this, 'maybe_update_activity'], 1);
    }

    /**
     * Update activity timestamp only on real page loads, not Ajax/Heartbeat
     */
    public function maybe_update_activity() {
        // Skip if Ajax request
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        // Skip if Heartbeat
        if (!empty($_POST['action']) && $_POST['action'] === 'heartbeat') {
            return;
        }
        
        // Real page load - update activity
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            update_user_meta($current_user->ID, 'wplgngrd_last_activity', time());
        }
    }
    
    /**
     * Create database table on activation
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Cleanup on deactivation
     */
    public function deactivate() {
        $this->cleanup_expired_tokens();
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
            return; // Plugin disabled, allow normal login
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
            // Check if they completed QR verification
            if (!isset($_SESSION['wplgngrd_verified']) || $_SESSION['wplgngrd_verified'] !== true) {
                // Not verified - block the login attempt and redirect to QR page
                wp_redirect(wp_login_url());
                exit;
            }
            // Verified - clear the session flag and allow login to proceed
            unset($_SESSION['wplgngrd_verified']);
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
                // Set session flag that QR verification is complete
                $_SESSION['wplgngrd_verified'] = true;
                return;
            }
        }
        
        // Show QR code login page
        $this->show_qr_login_page();
        exit;
    }

    /**
     * Display QR code login page
     */
    private function show_qr_login_page() {
        // Create new session
        $token = $this->create_session();
        $qr_code = $this->generate_qr_code($token);
        
        // Make token available globally for script localization
        global $wplg_current_token;
        $wplg_current_token = $token;
        
        // Manually trigger login scripts hook
        do_action('login_enqueue_scripts');
        
        // Display custom login page
        include plugin_dir_path(__FILE__) . 'templates/qr-login.php';
    }

    /**
     * Display mobile verification page
     */
    public function show_mobile_verification() {
        $token = sanitize_text_field($_GET['wplg_verify']);
        $session = $this->get_session($token);
        
        // Check if token is valid
        if (!$session) {
            wp_die(__('Invalid or expired verification code.', 'wplgngrd'));
        }
        
        // Check if already used
        if ($session->status !== 'pending') {
            wp_die(__('This verification code has already been used.', 'wplgngrd'));
        }
        
        // Generate random 4-digit number and store it
        $verification_number = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $this->update_session($token, [
            'verification_number' => $verification_number
        ]);
        
        // Display mobile verification template
        include plugin_dir_path(__FILE__) . 'templates/mobile-verify.php';
        
        // Manually trigger scripts
        do_action('login_enqueue_scripts');
        wp_footer();
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Check token status
        register_rest_route('wplgngrd/v1', '/check-token/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'check_token_status'],
            'permission_callback' => '__return_true'
        ]);
        
        // Update token status
        register_rest_route('wplgngrd/v1', '/update-token/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_token_status'],
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
     * Enqueue JavaScript for polling
     */
    public function enqueue_login_scripts() {
        global $wplg_current_token;
        
        if (!isset($_GET['wplg_select']) && !empty($wplg_current_token)) {
            wp_enqueue_script(
                'wplgngrd-login',
                plugins_url('assets/js/login-polling.js', __FILE__),
                ['jquery'],
                '1.0.0',
                true
            );
            
            wp_localize_script('wplgngrd-login', 'wplgngrd', [
                'ajax_url' => rest_url('wplgngrd/v1/check-token/'),
                'token' => $wplg_current_token
            ]);
        }
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
     * Display number selection page
     */
    private function show_number_selection() {
        $token = sanitize_text_field($_GET['token']);
        $session = $this->get_session($token);
        
        if (!$session || $session->status !== 'confirmed') {
            wp_die(__('Invalid session. Please start over.', 'wplgngrd'));
        }
        
        $correct_number = $session->verification_number;
        
        // Generate 4 random decoy numbers (different from correct one)
        $decoys = [];
        while (count($decoys) < 4) {
            $decoy = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            if ($decoy !== $correct_number && !in_array($decoy, $decoys)) {
                $decoys[] = $decoy;
            }
        }
        
        // Combine and shuffle
        $numbers = array_merge([$correct_number], $decoys);
        shuffle($numbers);
        
        // Display number selection template
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
        
        // DON'T update timestamp here - let maybe_update_activity() handle it
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
        
        // Settings section
        add_settings_section(
            'wplgngrd_main_section',
            __('Login Guard Configuration', 'wplgngrd'),
            [$this, 'render_section_description'],
            'wp-login-guard'
        );
        
        // Enable/Disable field
        add_settings_field(
            'wplgngrd_enabled',
            __('Enable Login Guard', 'wplgngrd'),
            [$this, 'render_enabled_field'],
            'wp-login-guard',
            'wplgngrd_main_section'
        );
        
        // Auto-logout field
        add_settings_field(
            'wplgngrd_auto_logout',
            __('Auto-logout after inactivity', 'wplgngrd'),
            [$this, 'render_auto_logout_field'],
            'wp-login-guard',
            'wplgngrd_main_section'
        );
    }

    /**
     * Render settings section description
     */
    public function render_section_description() {
        echo '<p>' . esc_html__('Configure QR code verification and security settings for login.', 'wplgngrd') . '</p>';
    }

    /**
     * Render enabled field
     */
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

    /**
     * Render auto-logout field
     */
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
            <?php esc_html_e('Automatically log out users after this period of inactivity. Disabled = never auto-logout.', 'wplgngrd'); ?>
        </p>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if settings were saved
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
                <li><?php esc_html_e('Blocks direct POST attacks - bots cannot bypass QR verification', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('15-minute token expiry prevents replay attacks', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('One-time use tokens - each verification is unique', 'wplgngrd'); ?></li>
                <li><?php esc_html_e('Session-based verification tracking', 'wplgngrd'); ?></li>
            </ul>
            
            <hr>
            
            <h2><?php esc_html_e('Auto-logout Feature', 'wplgngrd'); ?></h2>
            <p><?php esc_html_e('When auto-logout is enabled, users will be automatically logged out after the specified period of inactivity. Activity is tracked on every page load in the admin area.', 'wplgngrd'); ?></p>
        </div>
        <?php
    }
}

// Initialize plugin
WP_Login_Guard::get_instance();
