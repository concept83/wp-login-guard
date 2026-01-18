<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo get_bloginfo('name'); ?> &rsaquo; <?php esc_html_e('Select Number', 'wplgngrd'); ?></title>
    <?php wp_head(); ?>
    <style>
        body {
            background: #f0f0f1;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .wplg-container {
            max-width: 500px;
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
        .wplg-instructions {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
        }
        .wplg-numbers {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin: 30px 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .wplg-number-btn {
            padding: 25px 20px;
            font-size: 28px;
            font-weight: bold;
            background: #f0f0f1;
            border: 2px solid #dcdcde;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .wplg-number-btn:hover:not(:disabled) {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
            transform: scale(1.05);
        }
        .wplg-number-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .wplg-error {
            display: none;
            color: #d63638;
            margin-top: 20px;
            font-weight: 600;
        }
    </style>
</head>
<body class="login">
    <div class="wplg-container">
        <div class="wplg-logo">
            <h1><?php echo get_bloginfo('name'); ?></h1>
        </div>
        
        <div class="wplg-instructions">
            <p><strong><?php esc_html_e('Select the number shown on your mobile phone', 'wplgngrd'); ?></strong></p>
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
        const maxAttempts = 2; // Per-session limit
        let globalAttempts = 0; // Track for rate limiting
        
        document.querySelectorAll('.wplg-number-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const selected = this.dataset.number;
                const correct = document.getElementById('wplg-correct').value;
                const token = document.getElementById('wplg-token').value;
                const rateMax = parseInt(document.getElementById('wplg-rate-max').value);
                const rateWindow = parseInt(document.getElementById('wplg-rate-window').value);
                
                if (selected === correct) {
                    // Correct! Redirect to actual login
                    window.location.href = '?wplg_verified=1&token=' + token;
                } else {
                    attempts++;
                    globalAttempts++;
                    
                    // Check global rate limit
                    if (globalAttempts >= rateMax) {
                        alert('<?php echo esc_js(sprintf(__('Too many failed attempts. Please wait %d minutes before trying again.', 'wplgngrd'), '')); ?>'.replace('%d', rateWindow));
                        window.location.href = '/wp-login.php';
                        return;
                    }
                    
                    // Check per-session limit
                    if (attempts >= maxAttempts) {
                        alert('<?php esc_html_e('Too many incorrect attempts. Please start over.', 'wplgngrd'); ?>');
                        window.location.href = '/wp-login.php';
                    } else {
                        // Show error
                        const errorMsg = document.getElementById('wplg-error');
                        const remaining = maxAttempts - attempts;
                        errorMsg.textContent = '<?php printf(esc_html__('Incorrect number. You have %s attempt(s) remaining.', 'wplgngrd'), "' + remaining + '"); ?>';
                        errorMsg.style.display = 'block';
                        
                        // Disable the wrong button
                        this.disabled = true;
                        this.style.opacity = '0.5';
                        this.style.cursor = 'not-allowed';
                        
                        // Send wrong attempt to server for rate limiting
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