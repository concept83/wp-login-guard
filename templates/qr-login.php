<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo get_bloginfo('name'); ?> &rsaquo; <?php esc_html_e('Login Verification', 'wplgngrd'); ?></title>
    <?php wp_head(); ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
            width: 100%;
            overflow: auto;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .wplg-container {
            background: white;
            max-width: 420px;
            width: 100%;
            border-radius: 20px;
            padding: 48px 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: fadeIn 0.4s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .wplg-logo h1 {
            font-size: 28px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .wplg-subtitle {
            font-size: 15px;
            color: #86868b;
            margin-bottom: 40px;
            font-weight: 400;
        }
        
        .wplg-qr {
            margin: 40px 0;
            position: relative;
        }
        
        .wplg-qr img {
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            width: 100%;
            max-width: 300px;
            height: auto;
        }
        
        .wplg-instructions {
            margin-top: 32px;
        }
        
        .wplg-instructions strong {
            display: block;
            font-size: 17px;
            color: #1a1a1a;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .wplg-instructions p {
            font-size: 14px;
            color: #86868b;
            line-height: 1.5;
        }
        
        .wplg-spinner {
            display: none;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #f5f5f7;
        }
        
        .wplg-spinner.active {
            display: block;
        }
        
        .wplg-spinner-icon {
            width: 24px;
            height: 24px;
            border: 3px solid #f5f5f7;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 12px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .wplg-spinner p {
            font-size: 14px;
            color: #86868b;
        }
        
        .wplg-timeout-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            line-height: 1.5;
        }
    </style>
</head>
<body class="login">
    <div class="wplg-container">
        <?php if (isset($_GET['wplg_timeout'])): ?>
            <div class="wplg-timeout-notice">
                <?php esc_html_e('You have been logged out due to inactivity.', 'wplgngrd'); ?>
            </div>
        <?php endif; ?>
        
        <div class="wplg-logo">
            <h1><?php echo get_bloginfo('name'); ?></h1>
            <div class="wplg-subtitle"><?php esc_html_e('Secure Login', 'wplgngrd'); ?></div>
        </div>
        
        <div class="wplg-qr">
            <img src="<?php echo esc_attr($qr_code); ?>" alt="QR Code" />
        </div>
        
        <div class="wplg-instructions">
            <strong><?php esc_html_e('Scan with your mobile phone', 'wplgngrd'); ?></strong>
            <p><?php esc_html_e('This code will expire in 15 minutes', 'wplgngrd'); ?></p>
        </div>
        
        <div class="wplg-spinner">
            <div class="wplg-spinner-icon"></div>
            <p><?php esc_html_e('Waiting for verification', 'wplgngrd'); ?>...</p>
        </div>
        
        <input type="hidden" id="wplg-token" value="<?php echo esc_attr($token); ?>" />
    </div>
    <?php wp_footer(); ?>
</body>
</html>
