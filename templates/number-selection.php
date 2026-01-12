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
        .wplg-number-btn:hover {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
            transform: scale(1.05);
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
        
        <div class="wplg-error" id="wplg-error">
            <?php esc_html_e('Incorrect number. Please try again.', 'wplgngrd'); ?>
        </div>
        
        <input type="hidden" id="wplg-token" value="<?php echo esc_attr($token); ?>" />
        <input type="hidden" id="wplg-correct" value="<?php echo esc_attr($correct_number); ?>" />
    </div>
    
    <script>
        let attempts = 0;
        const maxAttempts = 2;
        
        document.querySelectorAll('.wplg-number-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const selected = this.dataset.number;
                const correct = document.getElementById('wplg-correct').value;
                const token = document.getElementById('wplg-token').value;
                
                if (selected === correct) {
                    // Correct! Redirect to actual login
                    window.location.href = '?wplg_verified=1&token=' + token;
                } else {
                    attempts++;
                    
                    if (attempts >= maxAttempts) {
                        // Too many attempts - restart
                        alert('<?php esc_html_e('Too many incorrect attempts. Please start over.', 'wplgngrd'); ?>');
                        window.location.href = '/wp-login.php';
                    } else {
                        // Show error, allow retry
                        const errorMsg = document.getElementById('wplg-error');
                        const remaining = maxAttempts - attempts;
                        errorMsg.textContent = '<?php printf(esc_html__('Incorrect number. You have %s attempt(s) remaining.', 'wplgngrd'), "' + remaining + '"); ?>';
                        errorMsg.style.display = 'block';
                        
                        // Disable the wrong button
                        this.disabled = true;
                        this.style.opacity = '0.5';
                        this.style.cursor = 'not-allowed';
                        
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