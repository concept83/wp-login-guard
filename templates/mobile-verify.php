<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo get_bloginfo('name'); ?> &rsaquo; <?php esc_html_e('Mobile Verification', 'wplgngrd'); ?></title>
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
            overflow: hidden;
            position: fixed;
            top: 0;
            left: 0;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .wplg-mobile-container {
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
        
        h2 {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .wplg-subtitle {
            font-size: 15px;
            color: #86868b;
            margin-bottom: 40px;
        }
        
        .wplg-number {
            font-size: 72px;
            font-weight: 700;
            color: #667eea;
            margin: 48px 0;
            letter-spacing: 12px;
            text-shadow: 0 2px 10px rgba(102, 126, 234, 0.2);
            animation: numberPulse 0.5s ease-out;
        }
        
        @keyframes numberPulse {
            0% {
                transform: scale(0.8);
                opacity: 0;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .wplg-instructions {
            color: #86868b;
            font-size: 15px;
            margin-bottom: 40px;
            line-height: 1.6;
        }
        
        .wplg-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .wplg-button {
            padding: 16px 32px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }
        
        .wplg-button-continue {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .wplg-button-continue:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        
        .wplg-button-continue:active {
            transform: translateY(0);
        }
        
        .wplg-button-cancel {
            background: #f5f5f7;
            color: #86868b;
        }
        
        .wplg-button-cancel:hover {
            background: #e8e8ed;
        }
        
        .wplg-success {
            display: none;
            color: #28a745;
            font-size: 16px;
            font-weight: 600;
            margin-top: 24px;
            padding: 16px;
            background: #d4edda;
            border-radius: 12px;
            animation: fadeIn 0.3s ease-out;
        }
        
        .wplg-success::before {
            content: "✓ ";
            font-size: 20px;
        }
    </style>
</head>
<body>
    <div class="wplg-mobile-container">
        <h2><?php esc_html_e('Verification Code', 'wplgngrd'); ?></h2>
        <div class="wplg-subtitle"><?php esc_html_e('Keep this number visible', 'wplgngrd'); ?></div>
        
        <div class="wplg-number"><?php echo esc_html($verification_number); ?></div>
        
        <div class="wplg-instructions">
            <?php esc_html_e('Remember this number and tap Continue to proceed with login on your desktop.', 'wplgngrd'); ?>
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
                    continueBtn.style.opacity = '0.5';
                    cancelBtn.style.opacity = '0.5';
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
