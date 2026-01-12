<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo get_bloginfo('name'); ?> &rsaquo; <?php esc_html_e('Mobile Verification', 'wplgngrd'); ?></title>
    <?php wp_head(); ?>
    <style>
        body {
            background: #f0f0f1;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 0;
            padding: 20px;
        }
        .wplg-mobile-container {
            max-width: 400px;
            margin: 50px auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .wplg-number {
            font-size: 72px;
            font-weight: bold;
            color: #2271b1;
            margin: 30px 0;
            letter-spacing: 10px;
        }
        .wplg-instructions {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
        }
        .wplg-buttons {
            display: flex;
            gap: 15px;
            flex-direction: column;
        }
        .wplg-button {
            padding: 15px 30px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        .wplg-button-continue {
            background: #2271b1;
            color: white;
        }
        .wplg-button-continue:hover {
            background: #135e96;
        }
        .wplg-button-cancel {
            background: #dcdcde;
            color: #50575e;
        }
        .wplg-button-cancel:hover {
            background: #c3c4c7;
        }
        .wplg-success {
            display: none;
            color: #00a32a;
            font-size: 18px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="wplg-mobile-container">
        <h2><?php esc_html_e('Verification Code', 'wplgngrd'); ?></h2>
        
        <div class="wplg-number"><?php echo esc_html($verification_number); ?></div>
        
        <div class="wplg-instructions">
            <?php esc_html_e('Remember this number and click Continue to proceed with login on your desktop.', 'wplgngrd'); ?>
        </div>
        
        <div class="wplg-buttons">
            <button class="wplg-button wplg-button-continue" id="wplg-continue">
                <?php esc_html_e('Continue to Login', 'wplgngrd'); ?>
            </button>
            <button class="wplg-button wplg-button-cancel" id="wplg-cancel">
                <?php esc_html_e('Cancel', 'wplgngrd'); ?>
            </button>
        </div>
        
        <div class="wplg-success" id="wplg-success">
            <?php esc_html_e('Success! You can now select this number on your desktop.', 'wplgngrd'); ?>
        </div>
    </div>
    
    <script>
        const token = '<?php echo esc_js($token); ?>';
        const continueBtn = document.getElementById('wplg-continue');
        const cancelBtn = document.getElementById('wplg-cancel');
        const successMsg = document.getElementById('wplg-success');
        
        continueBtn.addEventListener('click', async function() {
            try {
                const response = await fetch('<?php echo rest_url('wplgngrd/v1/update-token/'); ?>' + token, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ status: 'confirmed' })
                });
                
                if (response.ok) {
                    continueBtn.disabled = true;
                    cancelBtn.disabled = true;
                    successMsg.style.display = 'block';
                }
            } catch (error) {
                alert('<?php esc_html_e('Error confirming verification. Please try again.', 'wplgngrd'); ?>');
            }
        });
        
        cancelBtn.addEventListener('click', async function() {
            if (confirm('<?php esc_html_e('Are you sure you want to cancel?', 'wplgngrd'); ?>')) {
                try {
                    await fetch('<?php echo rest_url('wplgngrd/v1/update-token/'); ?>' + token, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ status: 'cancelled' })
                    });
                    
                    window.close();
                } catch (error) {
                    alert('<?php esc_html_e('Error cancelling. Please close this window.', 'wplgngrd'); ?>');
                }
            }
        });
    </script>
</body>
</html>