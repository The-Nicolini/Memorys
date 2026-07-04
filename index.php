<?php
// index.php
require_once __DIR__ . '/private/config.php';

$error = "";

// Haal alle instellingen in één keer op
$settings = $conn->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$guest_password = $settings['display_guest_password'] ?? '';
$family_password = $settings['family_password'] ?? '';
$moderator_password = $settings['moderator_password'] ?? '';
$memorial_name = ($settings['memorial_name'] ?? '') ?: 'John Doe';

// Geboorte- en sterfdatum elegant formatteren in de gekozen sitetaal (bv. "12 maart 1950 – ✝ 12 maart 2026")
$memorial_birth_date_formatted = format_elegant_date($settings['memorial_birth_date'] ?? '');
$memorial_death_date_formatted = format_elegant_date($settings['memorial_date'] ?? '');

if ($memorial_birth_date_formatted && $memorial_death_date_formatted) {
    $memorial_date_formatted = $memorial_birth_date_formatted . ' – ✝ ' . $memorial_death_date_formatted;
} elseif ($memorial_death_date_formatted) {
    $memorial_date_formatted = '✝ ' . $memorial_death_date_formatted;
} else {
    $memorial_date_formatted = $memorial_birth_date_formatted;
}
$site_font = ($settings['site_font'] ?? '') ?: "'Dancing Script', cursive";
$welcome_image = ($settings['welcome_image'] ?? '') ?: 'fonback2.jpeg';
$welcome_subtitle = ($settings['welcome_subtitle'] ?? '') ?: t('welcome_subtitle_default');
$welcome_button_color = ($settings['welcome_button_color'] ?? '') ?: '#d4a373';
$welcome_bg_size = ($settings['welcome_bg_size'] ?? '') ?: 'cover';
$welcome_bg_position = ($settings['welcome_bg_position'] ?? '') ?: 'center';
$welcome_overlay_color = ($settings['welcome_overlay_color'] ?? '') ?: '#000000';
$welcome_overlay_opacity = isset($settings['welcome_overlay_opacity']) ? (int)$settings['welcome_overlay_opacity'] : 0;
$welcome_card_opacity = isset($settings['welcome_card_opacity']) ? (int)$settings['welcome_card_opacity'] : 95;

// Zet een hex-kleur (#rrggbb) om naar "r, g, b" voor gebruik in rgba()
function hex_to_rgb_triplet($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return '0, 0, 0';
    }
    return implode(', ', [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ]);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_verify();
    login_rate_limit_check();

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($username) || empty($password)) {
        $error = t('index_error_fill_all_fields');
    } else {
        // 1. Check EERST of het de admin is (via de gewone velden)
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? AND is_admin = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin["password"])) {
             login_rate_limit_reset();
             session_regenerate_id(true);
             $_SESSION["admin_logged_in"] = true;
             $_SESSION["username"] = $username;
             $_SESSION["is_admin"] = true;
             header("Location: admin");
             exit;
        }
        // 2. Check of het een gast is
        else if ($password === $guest_password) {
            login_rate_limit_reset();
            session_regenerate_id(true);
            $_SESSION["guest_access"] = true;
            $_SESSION["username"] = $username;
            $_SESSION["role"] = "guest";
            header("Location: upload");
            exit;
        }
        // 3. Check of het een familielid is
        else if ($password === $family_password) {
            login_rate_limit_reset();
            session_regenerate_id(true);
            $_SESSION["family_access"] = true;
            $_SESSION["username"] = $username;
            $_SESSION["role"] = "family";
            header("Location: family_overview");
            exit;
        }
        // 4. Check of het een beheerder is (zelfde rechten als admin, behalve instellingen)
        else if (!empty($moderator_password) && $password === $moderator_password) {
            login_rate_limit_reset();
            session_regenerate_id(true);
            $_SESSION["admin_logged_in"] = true;
            $_SESSION["username"] = $username;
            $_SESSION["role"] = "moderator";
            header("Location: admin");
            exit;
        } else {
            login_rate_limit_register_failure();
            $error = t('index_error_wrong_credentials');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(t('html_lang')); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(t('index_memorial_prefix')); ?> <?php echo htmlspecialchars($memorial_name); ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&family=Great+Vibes&family=Parisienne&family=Lora:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Cinzel:wght@400;600&family=Josefin+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <?php
        $bg_repeat = ($welcome_bg_size === 'repeat') ? 'repeat' : 'no-repeat';
        $bg_size_css = ($welcome_bg_size === 'repeat') ? 'auto' : $welcome_bg_size;
        $overlay_rgb = hex_to_rgb_triplet($welcome_overlay_color);
        $card_rgb = hex_to_rgb_triplet('#ffffff');
    ?>
    <style>
        .slideshow-body {
            background-image: url('<?php echo htmlspecialchars($welcome_image); ?>');
            background-size: <?php echo htmlspecialchars($bg_size_css); ?>;
            background-position: <?php echo htmlspecialchars($welcome_bg_position); ?>;
            background-repeat: <?php echo $bg_repeat; ?>;
        }
        h1, h2, h3, .uploader-caption { font-family: <?php echo htmlspecialchars($site_font); ?>; }
        .welcome-overlay { background: rgba(<?php echo $overlay_rgb; ?>, <?php echo $welcome_overlay_opacity / 100; ?>); }
        .login-container { background-color: rgba(<?php echo $card_rgb; ?>, <?php echo $welcome_card_opacity / 100; ?>); }
        .btn-primary { background: <?php echo htmlspecialchars($welcome_button_color); ?> !important; }
    </style>
</head>
<body class="slideshow-body">
    <div class="welcome-overlay">
        <div class="login-container">
            <h2><?php echo htmlspecialchars(t('index_memorial_prefix')); ?></h2>
            <h1 style="font-family: <?php echo htmlspecialchars($site_font); ?>; font-size: 3.5em; margin-bottom: 20px; color: #333;"><?php echo htmlspecialchars($memorial_name); ?></h1>
            <?php if ($memorial_date_formatted): ?>
                <p style="font-family: <?php echo htmlspecialchars($site_font); ?>; font-style: italic; color: #888; margin-top: -10px; margin-bottom: 25px; font-size: 1.2em; letter-spacing: 0.5px;"><?php echo htmlspecialchars($memorial_date_formatted); ?></p>
            <?php endif; ?>
            <p style="margin-bottom: 30px; font-size: 1.1em; color: #555;"><?php echo nl2br(htmlspecialchars($welcome_subtitle)); ?></p>
            
            <?php if ($error): ?>
                <p class="error" style="color: #e63946; font-weight: bold; margin-bottom: 20px;"><?php echo $error; ?></p>
            <?php endif; ?>

            <form action="index" method="post">
                <?php echo csrf_field(); ?>
                <div class="form-group" style="margin-bottom: 20px; text-align: left;">
                    <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #666;"><?php echo htmlspecialchars(t('index_label_your_name')); ?></label>
                    <input type="text" name="username" placeholder="<?php echo htmlspecialchars(t('index_placeholder_name_example')); ?>" required
                           style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                </div>
                <div class="form-group" style="margin-bottom: 30px; text-align: left;">
                    <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #666;"><?php echo htmlspecialchars(t('index_label_shared_password')); ?></label>
                    <input type="password" name="password" required
                           style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                </div>
                <button type="submit" class="btn-primary"
                        style="width: 100%; padding: 15px; background: #d4a373; border: none; color: white; border-radius: 8px; font-size: 1.1em; cursor: pointer;">
                    <?php echo htmlspecialchars(t('common_login')); ?>
                </button>
            </form>
            <br>
            <p><a href="slideshow" style="color: #6c757d; text-decoration: none; font-weight: bold; border-bottom: 1px solid #6c757d;"><?php echo htmlspecialchars(t('index_link_view_slideshow')); ?></a></p>

            <!-- Privacy Mededeling -->
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px dashed #ddd; font-size: 0.85em; color: #888; line-height: 1.5;">
                <p><?php echo t('index_privacy_notice'); ?></p>
            </div>
        </div>
    </div>

    <!-- HET VERSTOPTE ADMIN PANEEL (Modal) -->
    <div id="adminModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:white; padding:40px; border-radius:15px; text-align:center; max-width:350px; width:90%;">
            <h2 style="font-family: <?php echo htmlspecialchars($site_font); ?>; font-size: 2em; margin-bottom: 20px;"><?php echo htmlspecialchars(t('index_admin_login_heading')); ?></h2>
            <!-- AANGEPAST: Action wijst nu naar index.php zodat je op de pagina blijft -->
            <form action="index" method="POST">
                <?php echo csrf_field(); ?>
                <!-- Verborgen veld om username 'admin' mee te sturen naar jezelf -->
                <input type="hidden" name="username" value="admin">
                <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('index_placeholder_admin_password')); ?>" required
                       style="width:100%; padding:12px; margin-bottom:20px; border:1px solid #ddd; border-radius:8px;">
                <button type="submit" style="width:100%; padding:12px; background:#333; color:white; border:none; border-radius:8px; cursor:pointer;">
                    <?php echo htmlspecialchars(t('index_btn_open_admin')); ?>
                </button>
            </form>
            <button onclick="document.getElementById('adminModal').style.display='none'"
                    style="margin-top:15px; background:none; border:none; color:#999; cursor:pointer; font-size:0.8em;">
                <?php echo htmlspecialchars(t('common_cancel')); ?>
            </button>
        </div>
    </div>

    <script>
        // Verborgen Admin Login (Alt + Shift + A)
        document.addEventListener('keydown', function(e) {
            if (e.altKey && e.shiftKey && e.code === 'KeyA') {
                document.getElementById('adminModal').style.display = 'flex';
            }
        });
    </script>
</body>
</html>
