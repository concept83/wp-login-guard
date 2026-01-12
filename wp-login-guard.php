<?php
/**
 * Plugin Name: WP Login Guard
 * Description: Two-factor QR code verification for WordPress login
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
        // Intercept login page
        add_action('login_init', [$this, 'maybe_show_qr_login']);
        
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Enqueue scripts on login page
        add_action('login_enqueue_scripts', [$this, 'enqueue_login_scripts']);
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
        // Optional: clean up old tokens
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
                'expires_at' => date('Y-m-d H:i:s', strtotime('+5 minutes'))
            ]
        );
        
        return $token;
    }
    
    /**
     * Get session by token
     */
    public function get_session($token) {
        global $wpdb;
        
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE token = %s AND expires_at > NOW()",
                $token
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
        // Skip if already verified or showing number selection
        if (isset($_GET['wplg_verified']) || isset($_GET['wplg_select'])) {
            return;
        }
        
        // Skip if this is mobile verification request
        if (isset($_GET['wplg_verify'])) {
            $this->show_mobile_verification();
            exit;
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
     * Display mobile verification (placeholder for now)
     */
    private function show_mobile_verification() {
        echo '<h1>Mobile verification page - coming in Step 3!</h1>';
    }

    /**
     * Register REST API routes for polling
     */
    public function register_rest_routes() {
        register_rest_route('wplgngrd/v1', '/check-token/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'check_token_status'],
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
}

// Initialize plugin
WP_Login_Guard::get_instance();