<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>NexusDB</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&family=Playfair+Display:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        :root {
            --primary-color: <?php echo htmlspecialchars(getSetting('theme_primary_color', '#1a4d7a')); ?>;
            --primary-dark: <?php 
                $color = hex2rgb(getSetting('theme_primary_color', '#1a4d7a'));
                echo 'rgb(' . max(0, $color['r'] - 30) . ', ' . max(0, $color['g'] - 30) . ', ' . max(0, $color['b'] - 30) . ')'; 
            ?>;
            --accent-color: <?php echo htmlspecialchars(getSetting('theme_accent_color', '#d4af37')); ?>;
            --accent-light: <?php 
                $accent = hex2rgb(getSetting('theme_accent_color', '#d4af37'));
                echo 'rgba(' . $accent['r'] . ', ' . $accent['g'] . ', ' . $accent['b'] . ', 0.2)'; 
            ?>;
            --light-bg: <?php echo htmlspecialchars(getSetting('theme_bg_color', '#f5f3f0')); ?>;
            --bg-pattern: <?php 
                $bg = hex2rgb(getSetting('theme_bg_color', '#f5f3f0'));
                $darker = [
                    'r' => max(0, $bg['r'] - 20),
                    'g' => max(0, $bg['g'] - 20),
                    'b' => max(0, $bg['b'] - 20)
                ];
                echo 'rgb(' . $darker['r'] . ', ' . $darker['g'] . ', ' . $darker['b'] . ')'; 
            ?>;
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
            --text-color: <?php echo htmlspecialchars(getSetting('theme_text_color', '#2c2416')); ?>;
            --text-color-light: <?php 
                $text = hex2rgb(getSetting('theme_text_color', '#2c2416'));
                $lighter = [
                    'r' => min(255, $text['r'] + 60),
                    'g' => min(255, $text['g'] + 60),
                    'b' => min(255, $text['b'] + 60)
                ];
                echo 'rgb(' . $lighter['r'] . ', ' . $lighter['g'] . ', ' . $lighter['b'] . ')'; 
            ?>;
            --text-color-muted: <?php 
                $text = hex2rgb(getSetting('theme_text_color', '#2c2416'));
                $muted = [
                    'r' => ($text['r'] + 128) / 2,
                    'g' => ($text['g'] + 128) / 2,
                    'b' => ($text['b'] + 128) / 2
                ];
                echo 'rgb(' . round($muted['r']) . ', ' . round($muted['g']) . ', ' . round($muted['b']) . ')'; 
            ?>;
            --border-color: <?php echo htmlspecialchars(getSetting('theme_border_color', '#c4b5a0')); ?>;
            --pattern-opacity: <?php echo floatval(getSetting('theme_pattern_opacity', '0.05')); ?>;
            --pattern-size: <?php echo intval(getSetting('theme_pattern_size', '40')); ?>px;
            --border-width: <?php echo intval(getSetting('theme_border_width', '3')); ?>px;
            <?php 
            $font_primary = getSetting('theme_font_primary', 'Montserrat');
            $font_decorative = getSetting('theme_font_decorative', 'Playfair Display');
            ?>
            --font-primary: '<?php echo htmlspecialchars($font_primary); ?>', 'Arial Black', 'Arial Bold', Arial, sans-serif;
            --font-decorative: '<?php echo htmlspecialchars($font_decorative); ?>', 'Times New Roman', serif;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="nav-brand">
                <a href="index.php"><?php echo htmlspecialchars(getSetting('organization_name', 'NexusDB')); ?></a>
            </div>
            <ul class="nav-menu">
                <?php if (isLoggedIn()): ?>
                    <li><a href="index.php">Dashboard</a></li>
                    <?php if (hasPermission('customer_create') || isAdmin()): ?>
                        <li><a href="signup.php">New Signup</a></li>
                    <?php endif; ?>
                    <li><a href="customers.php"><?php echo htmlspecialchars(getCustomerTermPlural('Customers')); ?></a></li>
                    <li class="nav-dropdown">
                        <a href="#" class="nav-dropdown-toggle">Visits <ion-icon name="chevron-down"></ion-icon></a>
                        <ul class="nav-dropdown-menu">
                            <?php if (hasPermission('food_visit') || isAdmin()): ?>
                                <li><a href="visits_food.php">Food Visit</a></li>
                            <?php endif; ?>
                            <?php if (hasPermission('money_visit') || isAdmin()): ?>
                                <li><a href="visits_money.php">Money Visit</a></li>
                            <?php endif; ?>
                            <?php if (hasPermission('voucher_create') || isAdmin()): ?>
                                <li><a href="visits_voucher.php">Voucher Visit</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <li><a href="voucher_redemption.php">Redeem</a></li>
                    <?php if (hasPermission('report_access') || isAdmin()): ?>
                        <li><a href="reports.php">Reports</a></li>
                    <?php endif; ?>
                    <?php if (hasPermission('settings_access') || isAdmin()): ?>
                        <li><a href="settings.php">Settings</a></li>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                        <li><a href="employees.php">Employees</a></li>
                    <?php endif; ?>
                    <li class="nav-dropdown">
                        <a href="#" class="nav-dropdown-toggle">
                            <ion-icon name="person"></ion-icon> <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?> <ion-icon name="chevron-down"></ion-icon>
                        </a>
                        <ul class="nav-dropdown-menu">
                            <li><a href="change_password.php">Change Password</a></li>
                            <li><a href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li><a href="login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>
    <main class="main-content">

