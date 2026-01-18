<?php
/**
 * Settings Page View
 * 
 * @package WP_Login_Guard
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<style>
    .wplg-admin-wrap {
        max-width: 1200px;
        margin: 20px 0;
    }
    
    .wplg-admin-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 32px;
        border-radius: 8px;
        margin-bottom: 24px;
    }
    
    .wplg-admin-header h1 {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        margin: 0 0 8px 0;
        font-size: 28px;
        font-weight: 600;
        color: #fff;
    }
    
    .wplg-admin-header p {
        margin: 0;
        opacity: 0.9;
        font-size: 14px;
    }
    
    .wplg-admin-header .notice {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        min-height: 36px;
        color: #646970;
    }
    
    .wplg-settings-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        margin-bottom: 8px;
    }
    
    @media (max-width: 960px) {
        .wplg-settings-grid {
            grid-template-columns: 1fr;
        }
    }
    
    .wplg-settings-main {
        background: white;
        border: 1px solid #dcdcde;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    
    .wplg-settings-sidebar {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }
    
    .wplg-info-box {
        background: white;
        border: 1px solid #dcdcde;
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    
    .wplg-info-box h3 {
        margin: 0 0 16px 0;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .wplg-info-box h3 .dashicons {
        color: #667eea;
    }
    
    .wplg-info-box ol,
    .wplg-info-box ul {
        margin: 0;
        padding-left: 20px;
    }
    
    .wplg-info-box li {
        margin-bottom: 12px;
        line-height: 1.6;
        font-size: 14px;
    }
    
    .wplg-feature-list {
        list-style: none;
        padding: 0 0 0 5px !important;
    }
    
    .wplg-feature-list li {
        padding-left: 24px;
        position: relative;
    }
    
    .wplg-feature-list li::before {
        content: "✓";
        position: absolute;
        left: 0;
        color: #28a745;
        font-weight: bold;
    }
    
    .wplg-section-header {
        background: #f9f9f9;
        padding: 16px 24px;
        border-bottom: 1px solid #dcdcde;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .wplg-section-header h2 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }
    
    .wplg-section-header .dashicons {
        color: #667eea;
        font-size: 24px;
        width: 24px;
        height: 24px;
    }
    
    .wplg-section-content {
        padding: 24px;
    }
    
    .wplg-section-content table {
        margin-top: 0;
    }
    
    .wplg-section-content th {
        padding-left: 0;
        font-weight: 600;
    }
    
    .wplg-version {
        text-align: left;
        padding: 8px 16px 8px 0;
        color: #86868b;
        font-size: 13px;
    }
    
    .wplg-security-badge {
        display: inline-block;
        background: #28a745;
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
    }

    .submit {
        margin: 0;
        padding: 24px;
    }
</style>

<div class="wrap wplg-admin-wrap">
    <div class="wplg-admin-header">
        <h1>
            <?php echo esc_html(get_admin_page_title()); ?>
            <span class="wplg-security-badge"><?php esc_html_e('Active', 'wplgngrd'); ?></span>
        </h1>
        <p><?php esc_html_e('Two-factor authentication with QR code verification, rate limiting, and auto-logout protection.', 'wplgngrd'); ?></p>
    </div>
    
    <div class="wplg-settings-grid">
        <div class="wplg-settings-main">
            <form action="options.php" method="post">
                <?php settings_fields('wplgngrd_settings'); ?>
                
                <!-- Main Configuration -->
                <div class="wplg-section-header">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <h2><?php esc_html_e('Main Configuration', 'wplgngrd'); ?></h2>
                </div>
                <div class="wplg-section-content">
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('wp-login-guard', 'wplgngrd_main_section'); ?>
                    </table>
                </div>
                
                <!-- Rate Limiting -->
                <div class="wplg-section-header">
                    <span class="dashicons dashicons-shield"></span>
                    <h2><?php esc_html_e('Rate Limiting', 'wplgngrd'); ?></h2>
                </div>
                <div class="wplg-section-content">
                    <p style="margin-top: 0; color: #666;">
                        <?php esc_html_e('Prevent brute-force attacks by limiting attempts from a single IP address.', 'wplgngrd'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('wp-login-guard', 'wplgngrd_rate_section'); ?>
                    </table>
                </div>
                
                <!-- Advanced Security -->
                <div class="wplg-section-header">
                    <span class="dashicons dashicons-lock"></span>
                    <h2><?php esc_html_e('Advanced Security', 'wplgngrd'); ?></h2>
                </div>
                <div class="wplg-section-content">
                    <p style="margin-top: 0; color: #666;">
                        <?php esc_html_e('Advanced options for high-security environments.', 'wplgngrd'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('wp-login-guard', 'wplgngrd_advanced_section'); ?>
                    </table>
                </div>
                
                <?php submit_button(__('Save All Settings', 'wplgngrd'), 'primary large'); ?>
            </form>
        </div>
        
        <div class="wplg-settings-sidebar">
            <!-- How It Works -->
            <div class="wplg-info-box">
                <h3>
                    <span class="dashicons dashicons-info"></span>
                    <?php esc_html_e('How It Works', 'wplgngrd'); ?>
                </h3>
                <ol>
                    <li><?php esc_html_e('User visits login page and sees a QR code', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('User scans QR code with mobile phone', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('Mobile shows a random 4-digit number', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('User clicks "Continue to Login"', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('Desktop shows 5 numbers to choose from', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('User enters credentials and logs in', 'wplgngrd'); ?></li>
                </ol>
            </div>
            
            <!-- Security Features -->
            <div class="wplg-info-box">
                <h3>
                    <span class="dashicons dashicons-shield-alt"></span>
                    <?php esc_html_e('Security Features', 'wplgngrd'); ?>
                </h3>
                <ul class="wplg-feature-list">
                    <li><?php esc_html_e('Blocks direct POST attacks', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('15-minute token expiry', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('One-time use tokens', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('Session-based verification', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('Rate limiting protection', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('Auto-logout after inactivity', 'wplgngrd'); ?></li>
                    <li><?php esc_html_e('Optional IP binding', 'wplgngrd'); ?></li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="wplg-version">
        <?php esc_html_e('WP Login Guard v1.1.0', 'wplgngrd'); ?> • 
        <?php esc_html_e('Developed by Slawek Jurczyk', 'wplgngrd'); ?>
    </div>
</div>
