<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo get_bloginfo('name'); ?> &rsaquo; <?php esc_html_e('Select Number', 'wplgngrd'); ?></title>
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
            max-width: 600px;
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
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .wplg-instructions {
            font-size: 15px;
            color: #86868b;
            margin-bottom: 40px;
        }
        
        .wplg-instructions strong {
            display: block;
            color: #1a1a1a;
            font-size: 17px;
            margin-bottom: 8px;
        }
        
        .wplg-numbers {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 16px;
            margin: 40px 0;
        }
        
        .wplg-number-btn {
            padding: 32px 20px;
            font-size: 32px;
            font-weight: 700;
            background: #f5f5f7;
            border: 2px solid transparent;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            color: #1a1a1a;
        }
        
        .wplg-number-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .wplg-number-btn:active:not(:disabled) {
            transform: translateY(-2px);
        }
        
        .wplg-number-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
            transform: none;
        }
        
        .wplg-error {
            display: none;
            color: #dc3545;
            background: #f8d7da;
            padding: 16px;
            border-radius: 12px;
            margin-top: 24px;
            font-weight: 600;
            font-size: 14px;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
    </style>
</head>
<body class="login">
    <div class="wplg-container">
        <div class="wplg-logo">
            <h1><?php echo get_bloginfo('name'); ?></h1>
        </div>
        
        <div class="wplg-instructions">
            <strong><?php esc_html_e('Select your verification number', 'wplgngrd'); ?></strong>
            <p><?php esc_html_e('Choose the number shown on your mobile phone', 'wplgngrd'); ?></p>
        </div>
        
        <div class="wplg-numbers">
            <?php foreach ($numbers as $number): ?>
                <button class="wplg-number-btn" data-number="<?php echo esc_attr($number); ?>">
                    <?php echo esc_html($number); ?>
                </button>
            <?php endforeach; ?>
        </div>
        
        <div class="wplg-error" id="wplg-error"></div>
        
        <input type="hidden" id="wplg-token" value="<?php echo esc_attr($token); ?>" />
        <input type="hidden" id="wplg-correct" value="<?php echo esc_attr($correct_number); ?>" />
        <input type="hidden" id="wplg-rate-max" value="<?php echo esc_attr($rate_limit_info['max']); ?>" />
        <input type="hidden" id="wplg-rate-window" value="<?php echo esc_attr($rate_limit_info['window']); ?>" />
    </div>
    
    <script>
        let attempts = 0;
        const maxAttempts = 2;
        let globalAttempts = 0;
        
        document.querySelectorAll('.wplg-number-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const selected = this.dataset.number;
                const correct = document.getElementById('wplg-correct').value;
                const token = document.getElementById('wplg-token').value;
                const rateMax = parseInt(document.getElementById('wplg-rate-max').value);
                const rateWindow = parseInt(document.getElementById('wplg-rate-window').value);
                
                if (selected === correct) {
                    window.location.href = '?wplg_verified=1&token=' + token;
                } else {
                    attempts++;
                    globalAttempts++;
                    
                    if (globalAttempts >= rateMax) {
                        alert('<?php echo esc_js(sprintf(__('Too many failed attempts. Please wait %d minutes before trying again.', 'wplgngrd'), '')); ?>'.replace('%d', rateWindow));
                        window.location.href = '/wp-login.php';
                        return;
                    }
                    
                    if (attempts >= maxAttempts) {
                        alert('<?php esc_html_e('Too many incorrect attempts. Please start over.', 'wplgngrd'); ?>');
                        window.location.href = '/wp-login.php';
                    } else {
                        const errorMsg = document.getElementById('wplg-error');
                        const remaining = maxAttempts - attempts;
                        errorMsg.textContent = '<?php printf(esc_html__('Incorrect number. You have %s attempt(s) remaining.', 'wplgngrd'), "' + remaining + '"); ?>';
                        errorMsg.style.display = 'block';
                        
                        this.disabled = true;
                        
                        await fetch('<?php echo rest_url('wplgngrd/v1/record-failed-attempt'); ?>', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({token: token})
                        });
                        
                        setTimeout(() => {
                            errorMsg.style.display = 'none';
                        }, 3000);
                    }
                }
            });
        });
    </script>
    <?php wp_footer(); ?>
</body>
</html>
