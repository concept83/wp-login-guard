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
        // We'll add hooks here in next steps
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
}

// Initialize plugin
WP_Login_Guard::get_instance();