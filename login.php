<?php
require_once 'config.php';
require_once 'auth.php';

$error = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn() && !requiresPasswordReset()) {
    header('Location: index.php');
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        $result = loginUser($username, $password);
        if ($result['success']) {
            // Check if password reset is required
            if ($result['employee']['force_password_reset'] == 1) {
                header('Location: change_password.php');
                exit;
            }
            
            // Redirect to original page or dashboard
            $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$page_title = "Login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - NexusDB</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&family=Playfair+Display:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars(getSetting('theme_primary_color', '#1a4d7a')); ?>;
            --accent-color: <?php echo htmlspecialchars(getSetting('theme_accent_color', '#d4af37')); ?>;
            --light-bg: <?php echo htmlspecialchars(getSetting('theme_bg_color', '#f5f3f0')); ?>;
            --bg-gradient-start: <?php 
                $bg = hex2rgb(getSetting('theme_bg_color', '#f5f3f0'));
                $lighter = [
                    'r' => min(255, $bg['r'] + 15),
                    'g' => min(255, $bg['g'] + 15),
                    'b' => min(255, $bg['b'] + 15)
                ];
                echo 'rgb(' . $lighter['r'] . ', ' . $lighter['g'] . ', ' . $lighter['b'] . ')'; 
            ?>;
            --bg-gradient-end: <?php 
                $bg = hex2rgb(getSetting('theme_bg_color', '#f5f3f0'));
                $darker = [
                    'r' => max(0, $bg['r'] - 10),
                    'g' => max(0, $bg['g'] - 10),
                    'b' => max(0, $bg['b'] - 10)
                ];
                echo 'rgb(' . $darker['r'] . ', ' . $darker['g'] . ', ' . $darker['b'] . ')'; 
            ?>;
            --white-gradient-start: <?php 
                $bg = hex2rgb(getSetting('theme_bg_color', '#f5f3f0'));
                $white = [
                    'r' => min(255, $bg['r'] + 30),
                    'g' => min(255, $bg['g'] + 30),
                    'b' => min(255, $bg['b'] + 30)
                ];
                echo 'rgb(' . $white['r'] . ', ' . $white['g'] . ', ' . $white['b'] . ')'; 
            ?>;
            --border-color: <?php echo htmlspecialchars(getSetting('theme_border_color', '#c4b5a0')); ?>;
            --text-color: <?php echo htmlspecialchars(getSetting('theme_text_color', '#2c2416')); ?>;
            --text-color-muted: <?php 
                $text = hex2rgb(getSetting('theme_text_color', '#2c2416'));
                $muted = [
                    'r' => ($text['r'] + 128) / 2,
                    'g' => ($text['g'] + 128) / 2,
                    'b' => ($text['b'] + 128) / 2
                ];
                echo 'rgb(' . round($muted['r']) . ', ' . round($muted['g']) . ', ' . round($muted['b']) . ')'; 
            ?>;
            --border-width: <?php echo intval(getSetting('theme_border_width', '3')); ?>px;
            --pattern-opacity: <?php echo floatval(getSetting('theme_pattern_opacity', '0.2')); ?>;
            --pattern-size: <?php echo intval(getSetting('theme_pattern_size', '40')); ?>px;
            --bg-pattern: <?php 
                $bg = hex2rgb(getSetting('theme_bg_color', '#f5f3f0'));
                $darker = [
                    'r' => max(0, $bg['r'] - 20),
                    'g' => max(0, $bg['g'] - 20),
                    'b' => max(0, $bg['b'] - 20)
                ];
                echo 'rgb(' . $darker['r'] . ', ' . $darker['g'] . ', ' . $darker['b'] . ')'; 
            ?>;
            <?php 
            $font_primary = getSetting('theme_font_primary', 'Montserrat');
            $font_decorative = getSetting('theme_font_decorative', 'Playfair Display');
            ?>
            --font-primary: '<?php echo htmlspecialchars($font_primary); ?>', 'Arial Black', 'Arial Bold', Arial, sans-serif;
            --font-decorative: '<?php echo htmlspecialchars($font_decorative); ?>', 'Times New Roman', serif;
        }
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: var(--light-bg);
            position: relative;
            z-index: 1;
        }
        .login-box {
            background: linear-gradient(135deg, var(--white-gradient-start) 0%, var(--bg-gradient-end) 100%);
            padding: 3rem;
            border: var(--border-width) solid var(--accent-color);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 2;
        }
        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: repeating-linear-gradient(90deg, 
                var(--accent-color) 0px, 
                var(--accent-color) 12px, 
                var(--primary-color) 12px, 
                var(--primary-color) 24px
            );
        }
        .login-box h1 {
            margin-top: 0;
            text-align: center;
            color: var(--primary-color);
            font-family: var(--font-decorative);
            font-size: 2.5rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 2rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        .login-box input[type="text"],
        .login-box input[type="password"] {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            background: var(--white);
            font-size: 1rem;
            font-family: var(--font-primary);
            transition: all 0.3s;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .login-box input[type="text"]:focus,
        .login-box input[type="password"]:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.2), inset 0 2px 4px rgba(0,0,0,0.05);
            transform: translateY(-1px);
        }
        .login-box .btn-primary {
            width: 100%;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            border: var(--border-width) solid var(--accent-color);
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: var(--white);
        }
        .login-box .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-gold);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>NexusDB Login</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">Login</button>
                </div>
            </form>
            
            <div style="margin-top: 1rem; text-align: center; font-size: 0.9rem; color: #666;">
                <p>Default admin: <strong>admin</strong> / <strong>admin</strong></p>
                <p style="font-size: 0.8rem; color: #999;">(Change password on first login)</p>
            </div>
        </div>
    </div>
</body>
</html>

