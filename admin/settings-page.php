<?php
/**
 * Admin Settings Page
 * 
 * @package WP_Login_Guard
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Login_Guard_Settings {
    
    private $plugin;
    
    public function __construct($plugin_instance) {
        $this->plugin = $plugin_instance;
    }
    
    /**
     * Render the settings page
     */
    public function render() {
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
        
        include plugin_dir_path(__FILE__) . 'views/settings-page-view.php';
    }
}