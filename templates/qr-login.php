<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo get_bloginfo('name'); ?> &rsaquo; <?php esc_html_e( 'Login Verification', 'wplgngrd' ); ?></title>
    <?php wp_head(); ?>
    <style>
        body {
            background: #f0f0f1;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .wplg-container {
            max-width: 400px;
            margin: 100px auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .wplg-logo {
            margin-bottom: 20px;
        }
        .wplg-qr {
            margin: 30px 0;
        }
        .wplg-instructions {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        .wplg-spinner {
            display: none;
            margin: 20px auto;
        }
        .wplg-spinner.active {
            display: block;
        }
    </style>
</head>
<body class="login">
    <div class="wplg-container">
        <div class="wplg-logo">
            <h1><?php echo get_bloginfo('name'); ?></h1>
        </div>
        
        <div class="wplg-qr">
            <img src="<?php echo esc_attr($qr_code); ?>" alt="QR Code" />
        </div>
        
        <div class="wplg-instructions">
            <p><strong><?php esc_html_e( 'Scan this QR code with your mobile phone', 'wplgngrd' ); ?></strong></p>
            <p><?php esc_html_e( 'This code will expire in 5 minutes', 'wplgngrd' ); ?></p>
        </div>
        
        <div class="wplg-spinner">
            <p><?php echo sprintf( '%s...', esc_html__( 'Waiting for verification', 'wplgngrd' ) ); ?></p>
        </div>
        
        <input type="hidden" id="wplg-token" value="<?php echo esc_attr($token); ?>" />
    </div>
    <?php wp_footer(); ?>
</body>
</html>