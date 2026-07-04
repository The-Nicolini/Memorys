<?php
// admin.php
require_once __DIR__ . '/private/config.php';

// Check login
if (!isset($_SESSION["admin_logged_in"])) {
    header("Location: index");
    exit;
}

// Beheerders (moderators) hebben dezelfde rechten als admin, behalve toegang tot
// de instellingen- en welkomstpagina-tabs (alleen de echte admin mag die aanpassen)
$is_full_admin = isset($_SESSION["is_admin"]);

check_for_updates();
$update_info = get_update_info();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_verify();
}

// Beschikbare kleurenschema's (aardse tinten) voor de hele site
$color_schemes = [
    'terracotta' => ['label' => t('scheme_terracotta'), 'primary' => '#d4a373', 'hover' => '#bc8a5f', 'dark' => '#8b4513'],
    'olijf' => ['label' => t('scheme_olijf'), 'primary' => '#a3a86c', 'hover' => '#8a8f52', 'dark' => '#5f6b3a'],
    'kastanje' => ['label' => t('scheme_kastanje'), 'primary' => '#b3705a', 'hover' => '#96543f', 'dark' => '#6b3a2a'],
    'zand' => ['label' => t('scheme_zand'), 'primary' => '#c9a878', 'hover' => '#ab8a5a', 'dark' => '#7a5c36'],
    'mos' => ['label' => t('scheme_mos'), 'primary' => '#8fa885', 'hover' => '#748c6a', 'dark' => '#4f5e45'],
];

$message = "";

// 1. Verwerk Toevoegen Media (Admin Upload)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["admin_add_media"])) {
    $uploader_name = !empty(trim($_POST["admin_name"] ?? "")) ? $_POST["admin_name"] : t('admin_panel_label');
    
    // Foto's
    if (!empty($_FILES["photos"]["name"][0])) {
        $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!is_dir("uploads/photos")) {
            mkdir("uploads/photos", 0777, true);
        }
        foreach ($_FILES["photos"]["tmp_name"] as $key => $tmp_name) {
            $safe_name = basename($_FILES["photos"]["name"][$key]);
            $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_images)) {
                continue;
            }
            $file_name = time() . "_" . $safe_name;
            $target = "uploads/photos/" . $file_name;
            if (move_uploaded_file($tmp_name, $target)) {
                $stmt = $conn->prepare("INSERT INTO media (file_type, file_path, uploader_name, is_active) VALUES ('photo', :path, :name, 1)");
                $stmt->execute(['path' => $target, 'name' => $uploader_name]);
            }
        }
        $message = t('admin_msg_photos_added');
    }

    // Muziek
    if (!empty($_FILES["music"]["name"][0])) {
        if (!is_dir("uploads/music")) {
            mkdir("uploads/music", 0777, true);
        }
        foreach ($_FILES["music"]["tmp_name"] as $key => $tmp_name) {
            $safe_name = basename($_FILES["music"]["name"][$key]);
            $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
            if ($ext === "mp3") {
                $file_name = time() . "_" . $safe_name;
                $target = "uploads/music/" . $file_name;
                if (move_uploaded_file($tmp_name, $target)) {
                    $stmt = $conn->prepare("INSERT INTO media (file_type, file_path, uploader_name, is_active) VALUES ('music', :path, :name, 1)");
                    $stmt->execute(['path' => $target, 'name' => $uploader_name]);
                }
            }
        }
        $message = t('admin_msg_music_added');
    }

    // Bericht
    if (!empty(trim($_POST["admin_message"] ?? ""))) {
        $stmt = $conn->prepare("INSERT INTO media (file_type, message_text, uploader_name, is_active) VALUES ('message', :text, :name, 1)");
        $stmt->execute(['text' => $_POST["admin_message"], 'name' => $uploader_name]);
        $message = t('admin_msg_message_added');
    }
}

// 2. Verwerk Opslaan Settings (alleen de echte admin, niet de beheerder)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["save_settings"]) && !$is_full_admin) {
    $message = t('admin_msg_no_permission');
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["save_settings"]) && $is_full_admin) {
    // Slideshow snelheid
    if (isset($_POST["slideshow_speed"])) {
        $stmt = $conn->prepare("UPDATE settings SET value = :val WHERE setting_key = 'slideshow_speed'");
        $stmt->execute(['val' => $_POST["slideshow_speed"]]);
    }
    // Bericht duur
    if (isset($_POST["message_duration"])) {
        $stmt = $conn->prepare("UPDATE settings SET value = :val WHERE setting_key = 'message_duration'");
        $stmt->execute(['val' => $_POST["message_duration"]]);
    }
    // Gast wachtwoord
    if (isset($_POST["guest_password"])) {
        $stmt = $conn->prepare("UPDATE settings SET value = :val WHERE setting_key = 'display_guest_password'");
        $stmt->execute(['val' => $_POST["guest_password"]]);
    }

    // Familie wachtwoord
    if (isset($_POST["family_password"])) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('family_password', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["family_password"]]);
    }

    // Beheerder (moderator) wachtwoord
    if (isset($_POST["moderator_password"])) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('moderator_password', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["moderator_password"]]);
    }

    // Familie mag foto's toevoegen aan de slideshow
    $family_can_add_slideshow_photos = isset($_POST["family_can_add_slideshow_photos"]) ? "1" : "0";
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('family_can_add_slideshow_photos', :val) ON DUPLICATE KEY UPDATE value = :val");
    $stmt->execute(['val' => $family_can_add_slideshow_photos]);

    // Naam persoon in gedachtenis
    if (isset($_POST["memorial_name"])) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('memorial_name', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["memorial_name"]]);
    }

    // Geboortedatum persoon in gedachtenis
    if (isset($_POST["memorial_birth_date"])) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('memorial_birth_date', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["memorial_birth_date"]]);
    }

    // Sterfdatum persoon in gedachtenis
    if (isset($_POST["memorial_date"])) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('memorial_date', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["memorial_date"]]);
    }

    // Taal van de site
    if (isset($_POST["site_language"]) && array_key_exists($_POST["site_language"], SUPPORTED_LANGUAGES)) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('site_language', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["site_language"]]);
    }

    // Lettertype voor welkomstpagina en site (koppen)
    if (isset($_POST["site_font"])) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('site_font', :val) ON DUPLICATE KEY UPDATE value = :val");
        $stmt->execute(['val' => $_POST["site_font"]]);
    }

    // Achtergrondafbeelding welkomstpagina
    if (!empty($_FILES["welcome_image"]["name"])) {
        $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES["welcome_image"]["name"], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_images)) {
            $file_name = "welcome_bg_" . time() . "." . $ext;
            $target = "uploads/" . $file_name;
            if (move_uploaded_file($_FILES["welcome_image"]["tmp_name"], $target)) {
                $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES ('welcome_image', :val) ON DUPLICATE KEY UPDATE value = :val");
                $stmt->execute(['val' => $target]);
            }
        }
    }

    // Overige personalisatie-instellingen van de welkomstpagina
    $welcome_page_settings = [
        'welcome_subtitle' => $_POST["welcome_subtitle"] ?? null,
        'welcome_button_color' => $_POST["welcome_button_color"] ?? null,
        'welcome_bg_size' => $_POST["welcome_bg_size"] ?? null,
        'welcome_bg_position' => $_POST["welcome_bg_position"] ?? null,
        'welcome_overlay_color' => $_POST["welcome_overlay_color"] ?? null,
        'welcome_overlay_opacity' => $_POST["welcome_overlay_opacity"] ?? null,
        'welcome_card_opacity' => $_POST["welcome_card_opacity"] ?? null,
    ];
    foreach ($welcome_page_settings as $key => $val) {
        if ($val !== null) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE value = :val");
            $stmt->execute(['key' => $key, 'val' => $val]);
        }
    }

    // Kleurenschema van de site: schema-keuze + de bijbehorende hex-kleuren opslaan
    if (isset($_POST["site_color_scheme"]) && array_key_exists($_POST["site_color_scheme"], $color_schemes)) {
        $chosen_scheme = $color_schemes[$_POST["site_color_scheme"]];
        $scheme_settings = [
            'site_color_scheme' => $_POST["site_color_scheme"],
            'site_primary_color' => $chosen_scheme['primary'],
            'site_primary_hover_color' => $chosen_scheme['hover'],
            'site_primary_dark_color' => $chosen_scheme['dark'],
        ];
        foreach ($scheme_settings as $key => $val) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE value = :val");
            $stmt->execute(['key' => $key, 'val' => $val]);
        }
    }

    // Uitvaart modus (Funeral Mode)
    $funeral_mode = isset($_POST["funeral_mode"]) ? "1" : "0";
    $stmt = $conn->prepare("UPDATE settings SET value = :val WHERE setting_key = 'funeral_mode'");
    $stmt->execute(['val' => $funeral_mode]);

    // Globale styling opslaan in settings
    if (isset($_POST["global_font"])) {
        // We slaan de globale font en kleur op in de settings tabel
        // Eerst checken of ze bestaan of aangemaakt moeten worden
        $styling_keys = [
            'global_font' => $_POST["global_font"],
            'global_color' => $_POST["global_color"],
            'global_bg_type' => $_POST["global_bg_type"] ?? 'semi-transparent'
        ];
        foreach ($styling_keys as $key => $val) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE value = :val");
            $stmt->execute(['key' => $key, 'val' => $val]);
        }
        
        // Optioneel: Update alle bestaande media naar deze styling (als je dat wilt forceren)
        $stmt = $conn->prepare("UPDATE media SET font_family = :font, text_color = :color WHERE file_type = 'message'");
        $stmt->execute(['font' => $_POST["global_font"], 'color' => $_POST["global_color"]]);
    }
    
    // Admin credentials opslaan
    if (isset($_POST["admin_name"]) || isset($_POST["admin_email"]) || isset($_POST["admin_phone"])) {
        $credentials = [
            'admin_name' => $_POST["admin_name"] ?? '',
            'admin_email' => $_POST["admin_email"] ?? '',
            'admin_phone' => $_POST["admin_phone"] ?? ''
        ];
        foreach ($credentials as $key => $val) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE value = :val");
            $stmt->execute(['key' => $key, 'val' => $val]);
        }
    }

    // Admin wachtwoord wijzigen (indien ingevuld)
    if (!empty($_POST["new_admin_password"]) && !empty($_POST["current_admin_password"])) {
        // Haal huidige admin hash uit users-tabel
        $stmt = $conn->prepare("SELECT password FROM users WHERE username = 'admin' AND is_admin = 1");
        $stmt->execute();
        $current_hash = $stmt->fetchColumn();

        if ($current_hash && password_verify($_POST["current_admin_password"], $current_hash)) {
            $new_hash = password_hash($_POST["new_admin_password"], PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = :val WHERE username = 'admin' AND is_admin = 1");
            $stmt->execute(['val' => $new_hash]);
            $message = t('admin_msg_password_and_settings_saved');
        } else {
            $message = t('admin_msg_wrong_current_password');
        }
    } else {
        $message = t('admin_msg_settings_saved');
    }

    // Per-bericht styling opslaan
    if (isset($_POST["msg_font"])) {
        foreach ($_POST["msg_font"] as $id => $font) {
            $color = $_POST["msg_color"][$id];
            $stmt = $conn->prepare("UPDATE media SET font_family = :font, text_color = :color WHERE id = :id");
            $stmt->execute(['font' => $font, 'color' => $color, 'id' => $id]);
        }
    }
}

// 2. Verwerk Media Selectie (Aan/Uit vinkjes)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["save_selection"])) {
    $conn->exec("UPDATE media SET is_active = 0");
    if (isset($_POST["active_items"])) {
        foreach ($_POST["active_items"] as $id) {
            $stmt = $conn->prepare("UPDATE media SET is_active = 1 WHERE id = :id");
            $stmt->execute(['id' => $id]);
        }
    }
    $message = t('admin_msg_selection_updated');
}

// 3. Verwerk Verwijderen
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_id"])) {
    $stmt = $conn->prepare("DELETE FROM media WHERE id = :id");
    $stmt->execute(['id' => $_POST["delete_id"]]);
    $message = t('admin_msg_item_deleted');
}

// Haal alle data op
$photos = $conn->query("SELECT * FROM media WHERE file_type = 'photo' ORDER BY created_at DESC")->fetchAll();
$music = $conn->query("SELECT * FROM media WHERE file_type = 'music' ORDER BY created_at DESC")->fetchAll();
$all_messages = $conn->query("SELECT * FROM media WHERE file_type = 'message' ORDER BY created_at DESC")->fetchAll();

// HERSTEL: Haal alleen setting_key en value op voor FETCH_KEY_PAIR
$settings_stmt = $conn->query("SELECT setting_key, value FROM settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Beschikbare lettertypes voor de dropdowns (koppen/welkomstpagina en berichten)
$font_options = [
    "'Dancing Script', cursive" => 'Sierlijk (Dancing Script)',
    "'Great Vibes', cursive" => 'Zwierig (Great Vibes)',
    "'Parisienne', cursive" => 'Romantisch (Parisienne)',
    "'Lora', serif" => 'Elegant (Lora Serif)',
    "'Playfair Display', serif" => 'Klassiek (Playfair Display)',
    "'Cormorant Garamond', serif" => 'Verfijnd (Cormorant Garamond)',
    "'Merriweather', serif" => 'Warm (Merriweather)',
    "'Crimson Text', serif" => 'Literair (Crimson Text)',
    "'Cinzel', serif" => 'Monumentaal (Cinzel)',
    "'Georgia', serif" => 'Traditioneel (Georgia)',
    "sans-serif" => 'Modern (Sans-Serif)',
    "'Josefin Sans', sans-serif" => 'Strak Modern (Josefin Sans)',
];

function render_font_options($font_options, $selected) {
    $html = '';
    foreach ($font_options as $value => $label) {
        $is_selected = ($selected === $value) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '" style="font-family: ' . htmlspecialchars($value) . ';" ' . $is_selected . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html;
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(t('html_lang')); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(t('admin_panel_label')); ?> <?php echo htmlspecialchars(t('common_memorial_word')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&family=Great+Vibes&family=Parisienne&family=Lora:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Cinzel:wght@400;600&family=Josefin+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($settings['site_primary_color'] ?? '#d4a373'); ?>;
            --primary-hover: <?php echo htmlspecialchars($settings['site_primary_hover_color'] ?? '#bc8a5f'); ?>;
            --primary-dark: <?php echo htmlspecialchars($settings['site_primary_dark_color'] ?? '#8b4513'); ?>;
            --danger: #e63946;
            --bg: #f4f7f6;
            --white: #ffffff;
            --text: #333;
            --shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        body.admin-body {
            background-color: var(--bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            margin: 0;
        }

        .admin-nav {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border-top: 5px solid var(--primary);
            position: static;
            top: auto;
            z-index: 100;
            display: block;
        }

        .admin-nav .logo { font-size: 1.2rem; font-weight: bold; }
        
        .admin-nav .links {
            display: flex;
            gap: 15px;
            width: 100%;
            justify-content: center;
            margin-top: 10px;
        }

        .admin-nav .links a {
            text-decoration: none;
            color: #666;
            font-weight: 600;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 25px;
            border-radius: 8px;
            background: #f8f9fa;
            border: 1px solid #eee;
            flex: 1;
            max-width: 300px;
        }

        .admin-nav .links a:hover { 
            color: var(--primary); 
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .admin-nav .links a.btn-slideshow {
            background: var(--primary);
            color: white;
            border: none;
        }

        .admin-nav .links a.btn-slideshow:hover { background: var(--primary-hover); }
        .admin-nav .links a.btn-logout { 
            color: var(--danger); 
            border-color: #ffcccc;
            background: #fff0f0;
        }
        .admin-nav .links a.btn-logout:hover {
            background: var(--danger);
            color: white;
        }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: #e9ecef;
            padding: 8px;
            border-radius: 12px;
        }

        .tab-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            border-radius: 8px;
            font-weight: bold;
            color: #666;
            transition: 0.3s;
        }

        .tab-btn.active {
            background: var(--white);
            color: var(--primary);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .tab-content {
            display: none;
            background: var(--white);
            padding: 30px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .tab-content.active { display: block; }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 25px;
        }

        .media-card {
            background: var(--white);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #eee;
            transition: 0.3s;
            text-align: center;
        }

        .media-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
        .media-card img { width: 100%; height: 160px; object-fit: cover; border-radius: 8px; margin-bottom: 12px; }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .admin-table th, .admin-table td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .admin-table th {
            background-color: #fafafa;
            color: #666;
            font-weight: 600;
        }

        .delete-btn {
            background: #fff0f0;
            color: var(--danger);
            border: 1px solid #ffcccc;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-top: 10px;
            transition: 0.2s;
        }

        .delete-btn:hover { background: var(--danger); color: white; }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            width: 100%;
            margin-top: 20px;
        }

        .btn-primary:hover { background: var(--primary-hover); }

        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        .settings-section h3 { margin-top: 0; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 20px; }
        .settings-section label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
        .settings-section input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            box-sizing: border-box;
        }

        .message-list-item {
            background: #fdfdfd;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 6px solid var(--primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }

        .msg-controls {
            display: flex;
            gap: 20px;
            align-items: center;
            margin-top: 15px;
            background: #f1f3f5;
            padding: 12px 20px;
            border-radius: 8px;
        }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; text-align: center; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    </style>
</head>
<body class="admin-body">

    <div class="container" style="max-width: 1400px; margin: 30px auto; padding: 0 20px;">
        <?php if ($is_full_admin && $update_info): ?>
            <div class="alert" style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; text-align:left;">
                <?php echo sprintf(htmlspecialchars(t('admin_update_available')), htmlspecialchars($update_info['version'])); ?>
                <?php if ($update_info['changelog']): ?>
                    <p style="font-weight:normal; white-space:pre-line; margin:10px 0 0;"><?php echo htmlspecialchars($update_info['changelog']); ?></p>
                <?php endif; ?>
                <?php if ($update_info['url']): ?>
                    <p style="margin:10px 0 0;"><a href="<?php echo htmlspecialchars($update_info['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(t('admin_update_view_download')); ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert success"><?php echo $message; ?></div>
        <?php endif; ?>

        <nav class="admin-nav" style="background: white; padding: 1.5rem 2rem; border-radius: 15px; box-shadow: var(--shadow); margin-bottom: 30px; border-top: 5px solid var(--primary);">
            <div style="text-align: center; margin-bottom: 20px;">
                <div class="logo"><strong><?php echo htmlspecialchars($settings['memorial_name'] ?? 'John Doe'); ?> <?php echo htmlspecialchars(t('common_memorial_word')); ?></strong> - <?php echo htmlspecialchars(t('admin_panel_label')); ?></div>
            </div>
            <div class="links" style="display: flex; gap: 15px; width: 100%; justify-content: center; flex-wrap: wrap;">
                <a href="uitvaart" target="_blank" class="btn-slideshow" style="background:var(--primary-dark); flex: 1; min-width: 250px; max-width: 300px; padding: 15px; text-decoration: none; color: white; border-radius: 8px; font-weight: bold; text-align: center;"><?php echo htmlspecialchars(t('admin_nav_funeral_slideshow')); ?></a>
                <a href="slideshow" target="_blank" class="btn-slideshow" style="background: var(--primary); flex: 1; min-width: 250px; max-width: 300px; padding: 15px; text-decoration: none; color: white; border-radius: 8px; font-weight: bold; text-align: center;"><?php echo htmlspecialchars(t('admin_nav_public_slideshow')); ?></a>
                <a href="export" class="btn-slideshow" style="background:#2a9d8f; flex: 1; min-width: 250px; max-width: 300px; padding: 15px; text-decoration: none; color: white; border-radius: 8px; font-weight: bold; text-align: center;"><?php echo htmlspecialchars(t('admin_nav_export')); ?></a>
                <a href="logout" class="btn-logout" style="background: #fff0f0; border: 1px solid #ffcccc; color: var(--danger); flex: 1; min-width: 250px; max-width: 300px; padding: 15px; text-decoration: none; border-radius: 8px; font-weight: bold; text-align: center;"><?php echo htmlspecialchars(t('common_logout')); ?></a>
            </div>
        </nav>

        <!-- Direct Media Upload -->
        <div style="background: white; padding: 25px; border-radius: 15px; box-shadow: var(--shadow); margin-bottom: 30px; border-top: 5px solid var(--primary);">
            <h3 style="margin-top:0; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 20px;"><?php echo htmlspecialchars(t('admin_quick_add_heading')); ?></h3>
            <form action="admin" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <div style="margin-bottom: 20px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px;">
                    <label style="display:block; margin-bottom:8px; font-weight:600; color:var(--primary);"><?php echo htmlspecialchars(t('admin_publish_as_label')); ?></label>
                    <input type="text" name="admin_name" placeholder="<?php echo htmlspecialchars(t('admin_publish_as_placeholder')); ?>" style="width:100%; max-width: 300px; border: 1px solid #ddd; padding: 10px; border-radius: 8px;">
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                    <div>
                        <label style="display:block; margin-bottom:8px; font-weight:600;"><?php echo htmlspecialchars(t('admin_upload_photos_label')); ?></label>
                        <input type="file" name="photos[]" multiple accept="image/*" style="width:100%; border: 1px solid #ddd; padding: 8px; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; font-weight:600;"><?php echo htmlspecialchars(t('admin_upload_music_label')); ?></label>
                        <input type="file" name="music[]" multiple accept="audio/mpeg" style="width:100%; border: 1px solid #ddd; padding: 8px; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:8px; font-weight:600;"><?php echo htmlspecialchars(t('admin_write_message_label')); ?></label>
                        <textarea name="admin_message" rows="2" placeholder="<?php echo htmlspecialchars(t('admin_write_message_placeholder')); ?>" style="width:100%; border: 1px solid #ddd; padding: 10px; border-radius: 8px; font-family: inherit;"></textarea>
                    </div>
                </div>
                <button type="submit" name="admin_add_media" class="btn-primary" style="margin-top: 15px; width: auto; min-width: 200px;"><?php echo htmlspecialchars(t('admin_btn_add_to_memorial')); ?></button>
            </form>
        </div>



        <div class="tabs">
            <button class="tab-btn active" onclick="openTab(event, 'photos')"><?php echo htmlspecialchars(t('admin_tab_photos')); ?></button>
            <button class="tab-btn" onclick="openTab(event, 'music')"><?php echo htmlspecialchars(t('admin_tab_music')); ?></button>
            <button class="tab-btn" onclick="openTab(event, 'messages')"><?php echo htmlspecialchars(t('admin_tab_messages')); ?></button>
            <?php if ($is_full_admin): ?>
            <button class="tab-btn" onclick="openTab(event, 'welcome_page')"><?php echo htmlspecialchars(t('admin_tab_welcome')); ?></button>
            <button class="tab-btn" onclick="openTab(event, 'settings')"><?php echo htmlspecialchars(t('admin_tab_settings')); ?></button>
            <button class="tab-btn" onclick="openTab(event, 'language')"><?php echo htmlspecialchars(t('admin_tab_language')); ?></button>
            <?php endif; ?>
        </div>

        <form action="admin" method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div id="photos" class="tab-content active">
                <div class="media-grid">
                    <?php foreach ($photos as $p): ?>
                        <div class="media-card">
                            <img src="<?php echo htmlspecialchars($p['file_path']); ?>" onclick="window.open(this.src)">
                            <div style="font-size: 0.8rem; margin: 5px 0; color: #666;">
                                <?php echo htmlspecialchars(t('admin_uploaded_by_label')); ?> <?php echo htmlspecialchars($p['uploader_name']); ?>
                                <?php if ($p['is_anonymous']): ?>
                                    <span style="display:block; color: #856404; background: #fff3cd; padding: 2px; border-radius: 4px; margin-top:2px;"><?php echo htmlspecialchars(t('admin_anonymous_in_slideshow')); ?></span>
                                <?php endif; ?>
                            </div>
                            <label><input type="checkbox" name="active_items[]" value="<?php echo $p['id']; ?>" <?php echo $p['is_active'] ? 'checked' : ''; ?>> <?php echo htmlspecialchars(t('admin_show_label')); ?></label>
                            <br>
                            <button type="button" class="delete-btn" onclick="deleteItem(<?php echo $p['id']; ?>)"><?php echo htmlspecialchars(t('admin_btn_delete')); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="save_selection" class="btn-primary" style="margin-top: 20px;"><?php echo htmlspecialchars(t('admin_btn_save_selection')); ?></button>
            </div>

            <div id="music" class="tab-content">
                <table class="admin-table">
                    <tr><th><?php echo htmlspecialchars(t('admin_th_file')); ?></th><th><?php echo htmlspecialchars(t('admin_th_from')); ?></th><th><?php echo htmlspecialchars(t('admin_th_active')); ?></th><th><?php echo htmlspecialchars(t('admin_th_listen')); ?></th><th><?php echo htmlspecialchars(t('admin_th_action')); ?></th></tr>
                    <?php foreach ($music as $m): ?>
                    <tr>
                        <td style="font-size: 0.9rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo htmlspecialchars(basename($m['file_path'])); ?>
                        </td>
                        <td><?php echo htmlspecialchars($m['uploader_name']); ?></td>
                        <td><input type="checkbox" name="active_items[]" value="<?php echo $m['id']; ?>" <?php echo $m['is_active'] ? 'checked' : ''; ?>></td>
                        <td>
                            <audio controls style="height: 30px; width: 200px;">
                                <source src="<?php echo htmlspecialchars($m['file_path']); ?>" type="audio/mpeg">
                                <?php echo htmlspecialchars(t('admin_no_audio_support')); ?>
                            </audio>
                        </td>
                        <td><button type="button" class="delete-btn" style="margin-top:0" onclick="deleteItem(<?php echo $m['id']; ?>)">❌</button></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <button type="submit" name="save_selection" class="btn-primary"><?php echo htmlspecialchars(t('admin_btn_save_selection')); ?></button>
            </div>

            <div id="messages" class="tab-content">
                <p style="background: #f8f9fa; padding: 10px; border-radius: 8px; font-size: 0.9rem; color: #666; margin-bottom: 20px;">
                    <?php echo t('admin_messages_style_notice'); ?>
                </p>
                <?php foreach ($all_messages as $msg): ?>
                    <div class="message-list-item">
                        <p>
                            <strong><?php echo htmlspecialchars($msg['uploader_name']); ?>:</strong>
                            <?php if ($msg['is_anonymous']): ?>
                                <span style="background: #fff3cd; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; margin-right: 5px; border: 1px solid #ffeeba; color: #856404;"><?php echo htmlspecialchars(t('admin_anonymous_in_slideshow')); ?></span>
                            <?php endif; ?>
                            "<?php echo htmlspecialchars($msg['message_text']); ?>"
                        </p>
                        <div class="msg-controls">
                            <label><input type="checkbox" name="active_items[]" value="<?php echo $msg['id']; ?>" <?php echo $msg['is_active'] ? 'checked' : ''; ?>> <?php echo htmlspecialchars(t('admin_show_in_slideshow_label')); ?></label>
                            <button type="button" class="delete-btn" style="margin-top:0" onclick="deleteItem(<?php echo $msg['id']; ?>)"><?php echo htmlspecialchars(t('admin_btn_delete')); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button type="submit" name="save_selection" class="btn-primary"><?php echo htmlspecialchars(t('admin_btn_save_selection')); ?></button>
            </div>

            <?php if ($is_full_admin): ?>
            <div id="welcome_page" class="tab-content">
                <div class="settings-grid">
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_welcome_name_text_heading')); ?></h3>
                        <label><?php echo htmlspecialchars(t('admin_memorial_name_label')); ?></label>
                        <input type="text" name="memorial_name" value="<?php echo htmlspecialchars($settings['memorial_name'] ?? 'John Doe'); ?>" placeholder="<?php echo htmlspecialchars(t('admin_full_name_placeholder')); ?>">
                        <p style="font-size:0.8rem; color:#666; margin-top:5px;"><?php echo htmlspecialchars(t('admin_memorial_name_hint')); ?></p>

                        <label style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_memorial_birth_date_label')); ?></label>
                        <input type="date" name="memorial_birth_date" value="<?php echo htmlspecialchars($settings['memorial_birth_date'] ?? ''); ?>">
                        <p style="font-size:0.8rem; color:#666; margin-top:5px;"><?php echo htmlspecialchars(t('admin_memorial_birth_date_hint')); ?></p>

                        <label style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_memorial_date_label')); ?></label>
                        <input type="date" name="memorial_date" value="<?php echo htmlspecialchars($settings['memorial_date'] ?? ''); ?>">
                        <p style="font-size:0.8rem; color:#666; margin-top:5px;"><?php echo htmlspecialchars(t('admin_memorial_date_hint')); ?></p>

                        <label style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_subtitle_label')); ?></label>
                        <textarea name="welcome_subtitle" rows="2" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:8px; font-family: 'Lora', serif; box-sizing:border-box;"><?php echo htmlspecialchars($settings['welcome_subtitle'] ?? t('welcome_subtitle_default')); ?></textarea>

                        <hr style="border:0; border-top: 1px solid #eee; margin: 20px 0;">

                        <label><?php echo htmlspecialchars(t('admin_site_font_label')); ?></label>
                        <select name="site_font" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
                            <?php echo render_font_options($font_options, ($settings['site_font'] ?? "'Dancing Script', cursive")); ?>
                        </select>

                        <label><?php echo htmlspecialchars(t('admin_button_color_label')); ?></label>
                        <input type="color" name="welcome_button_color" id="welcomeButtonColorInput" value="<?php echo htmlspecialchars($settings['welcome_button_color'] ?? '#d4a373'); ?>" style="width: 60px; height: 45px; padding: 2px; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_bg_image_heading')); ?></h3>
                        <label><?php echo htmlspecialchars(t('admin_image_label')); ?></label>
                        <div style="margin-bottom:10px;">
                            <img id="welcomeImageThumbnail" src="<?php echo htmlspecialchars($settings['welcome_image'] ?? ''); ?>" alt="<?php echo htmlspecialchars(t('admin_current_bg_alt')); ?>" style="max-width:200px; max-height:120px; border-radius:8px; border:1px solid #ddd; <?php echo empty($settings['welcome_image']) ? 'display:none;' : ''; ?>">
                        </div>
                        <input type="file" name="welcome_image" id="welcomeImageInput" accept="image/*">
                        <p style="font-size:0.8rem; color:#666; margin-top:5px;"><?php echo htmlspecialchars(t('admin_bg_keep_hint')); ?></p>

                        <label style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_bg_size_label')); ?></label>
                        <select name="welcome_bg_size" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
                            <option value="cover" <?php echo (($settings['welcome_bg_size'] ?? 'cover') == 'cover') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_size_cover')); ?></option>
                            <option value="contain" <?php echo (($settings['welcome_bg_size'] ?? '') == 'contain') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_size_contain')); ?></option>
                            <option value="auto" <?php echo (($settings['welcome_bg_size'] ?? '') == 'auto') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_size_auto')); ?></option>
                            <option value="repeat" <?php echo (($settings['welcome_bg_size'] ?? '') == 'repeat') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_size_repeat')); ?></option>
                        </select>

                        <label><?php echo htmlspecialchars(t('admin_bg_position_label')); ?></label>
                        <select name="welcome_bg_position" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                            <option value="center" <?php echo (($settings['welcome_bg_position'] ?? 'center') == 'center') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_pos_center')); ?></option>
                            <option value="top" <?php echo (($settings['welcome_bg_position'] ?? '') == 'top') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_pos_top')); ?></option>
                            <option value="bottom" <?php echo (($settings['welcome_bg_position'] ?? '') == 'bottom') ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_pos_bottom')); ?></option>
                        </select>
                    </div>
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_transparency_heading')); ?></h3>
                        <label><?php echo htmlspecialchars(t('admin_overlay_color_label')); ?></label>
                        <input type="color" name="welcome_overlay_color" value="<?php echo htmlspecialchars($settings['welcome_overlay_color'] ?? '#000000'); ?>" style="width: 60px; height: 45px; padding: 2px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">

                        <label><?php echo htmlspecialchars(t('admin_overlay_opacity_label')); ?></label>
                        <input type="range" name="welcome_overlay_opacity" min="0" max="100" value="<?php echo htmlspecialchars($settings['welcome_overlay_opacity'] ?? '0'); ?>" style="width:100%; margin-bottom:20px;" oninput="this.nextElementSibling.textContent = this.value + '%'">
                        <span style="font-size:0.85rem; color:#666;"><?php echo htmlspecialchars($settings['welcome_overlay_opacity'] ?? '0'); ?>%</span>

                        <hr style="border:0; border-top: 1px solid #eee; margin: 20px 0;">

                        <label><?php echo htmlspecialchars(t('admin_card_opacity_label')); ?></label>
                        <input type="range" name="welcome_card_opacity" min="0" max="100" value="<?php echo htmlspecialchars($settings['welcome_card_opacity'] ?? '95'); ?>" style="width:100%; margin-bottom:10px;" oninput="this.nextElementSibling.textContent = this.value + '%'">
                        <span style="font-size:0.85rem; color:#666;"><?php echo htmlspecialchars($settings['welcome_card_opacity'] ?? '95'); ?>%</span>
                    </div>
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_color_scheme_heading')); ?></h3>
                        <p style="font-size:0.8rem; color:#666; margin-top:5px; margin-bottom:15px;"><?php echo htmlspecialchars(t('admin_color_scheme_hint')); ?></p>
                        <label><?php echo htmlspecialchars(t('admin_color_scheme_label')); ?></label>
                        <select name="site_color_scheme" id="colorSchemeSelect" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;" onchange="updateColorSchemeSwatches()">
                            <?php foreach ($color_schemes as $scheme_key => $scheme): ?>
                                <option value="<?php echo htmlspecialchars($scheme_key); ?>"
                                    data-primary="<?php echo htmlspecialchars($scheme['primary']); ?>"
                                    data-hover="<?php echo htmlspecialchars($scheme['hover']); ?>"
                                    data-dark="<?php echo htmlspecialchars($scheme['dark']); ?>"
                                    <?php echo (($settings['site_color_scheme'] ?? 'terracotta') === $scheme_key) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($scheme['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="colorSchemeSwatches" style="display:flex; gap:10px; margin-top:15px;"></div>
                    </div>
                </div>

                <div style="margin-top:30px;">
                    <h3><?php echo htmlspecialchars(t('admin_live_preview_heading')); ?></h3>
                    <p style="font-size:0.85rem; color:#666; margin-bottom:10px;"><?php echo htmlspecialchars(t('admin_live_preview_hint')); ?></p>
                    <div style="border:1px solid #ddd; border-radius:12px; overflow:hidden; box-shadow: var(--shadow); aspect-ratio: 16/9; max-width: 900px;">
                        <iframe id="welcomePreviewFrame" allowfullscreen style="width:100%; height:100%; border:0;"></iframe>
                    </div>
                </div>

                <button type="submit" name="save_settings" class="btn-primary" style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_btn_save_welcome')); ?></button>
            </div>

            <div id="settings" class="tab-content">
                <div class="settings-grid">
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_speed_timing_heading')); ?></h3>
                        <label><?php echo htmlspecialchars(t('admin_photo_speed_label')); ?></label>
                        <input type="number" name="slideshow_speed" value="<?php echo $settings['slideshow_speed'] ?? 5000; ?>">
                        <label><?php echo htmlspecialchars(t('admin_message_duration_label')); ?></label>
                        <input type="number" name="message_duration" value="<?php echo $settings['message_duration'] ?? 10000; ?>">
                        <label><?php echo htmlspecialchars(t('admin_guest_password_label')); ?></label>
                        <input type="text" name="guest_password" value="<?php echo htmlspecialchars($settings['display_guest_password'] ?? ''); ?>">

                        <label style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_family_password_label')); ?></label>
                        <input type="text" name="family_password" value="<?php echo htmlspecialchars($settings['family_password'] ?? ''); ?>">

                        <label style="margin-top:20px;"><?php echo htmlspecialchars(t('admin_moderator_password_label')); ?></label>
                        <input type="text" name="moderator_password" value="<?php echo htmlspecialchars($settings['moderator_password'] ?? ''); ?>">
                        <p style="font-size:0.8rem; color:#666; margin-top:-15px; margin-bottom:20px;"><?php echo htmlspecialchars(t('admin_moderator_password_hint')); ?></p>

                        <div style="margin-top:20px; padding:15px; background:#f8f9fa; border: 1px solid #eee; border-radius:8px;">
                            <label style="display:flex; align-items:center; cursor:pointer;">
                                <input type="checkbox" name="family_can_add_slideshow_photos" value="1" <?php echo (($settings['family_can_add_slideshow_photos'] ?? '1') == '1') ? 'checked' : ''; ?> style="width:20px; height:20px; margin-right:10px;">
                                <strong><?php echo htmlspecialchars(t('admin_family_slideshow_upload_label')); ?></strong>
                            </label>
                            <p style="font-size:0.8rem; color:#666; margin-top:5px; margin-bottom:0;">
                                <?php echo htmlspecialchars(t('admin_family_slideshow_upload_hint')); ?>
                            </p>
                        </div>

                        <div style="margin-top:20px; padding:15px; background:#fff3cd; border: 1px solid #ffeeba; border-radius:8px;">
                            <label style="display:flex; align-items:center; cursor:pointer;">
                                <input type="checkbox" name="funeral_mode" value="1" <?php echo (($settings['funeral_mode'] ?? '0') == '1') ? 'checked' : ''; ?> style="width:20px; height:20px; margin-right:10px;">
                                <strong><?php echo htmlspecialchars(t('admin_funeral_mode_label')); ?></strong>
                            </label>
                            <p style="font-size:0.8rem; color:#856404; margin-top:5px; margin-bottom:0;">
                                <?php echo htmlspecialchars(t('admin_funeral_mode_hint')); ?>
                            </p>
                        </div>
                    </div>
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_global_text_style_heading')); ?></h3>
                        <label><?php echo htmlspecialchars(t('admin_global_font_label')); ?></label>
                        <select name="global_font" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 20px;">
                            <?php echo render_font_options($font_options, ($settings['global_font'] ?? "'Dancing Script', cursive")); ?>
                        </select>
                        <label><?php echo htmlspecialchars(t('admin_global_color_label')); ?></label>
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px;">
                            <input type="color" name="global_color" value="<?php echo $settings['global_color'] ?? '#ffffff'; ?>" style="width: 60px; height: 45px; padding: 2px; border: 1px solid #ddd; border-radius: 8px;">
                            <span style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars(t('admin_choose_text_color_hint')); ?></span>
                        </div>

                        <label><?php echo htmlspecialchars(t('admin_text_bg_label')); ?></label>
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px;">
                            <select name="global_bg_type" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                                <option value="semi-transparent" <?php echo (($settings['global_bg_type'] ?? '') == "semi-transparent") ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_light')); ?></option>
                                <option value="dark-transparent" <?php echo (($settings['global_bg_type'] ?? '') == "dark-transparent") ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_dark')); ?></option>
                                <option value="none" <?php echo (($settings['global_bg_type'] ?? '') == "none") ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('admin_bg_none')); ?></option>
                            </select>
                        </div>

                        <hr style="border:0; border-top: 1px solid #eee; margin: 20px 0;">

                        <h3><?php echo htmlspecialchars(t('admin_change_password_heading')); ?></h3>
                        <label><?php echo htmlspecialchars(t('admin_current_password_label')); ?></label>
                        <input type="password" name="current_admin_password" placeholder="<?php echo htmlspecialchars(t('admin_required_for_change_placeholder')); ?>">
                        <label><?php echo htmlspecialchars(t('admin_new_password_label')); ?></label>
                        <input type="password" name="new_admin_password" placeholder="<?php echo htmlspecialchars(t('admin_leave_empty_placeholder')); ?>">

                        <hr style="border:0; border-top: 1px solid #eee; margin: 20px 0;">

                        <h3><?php echo htmlspecialchars(t('admin_contact_info_heading')); ?></h3>
                        <p style="font-size: 0.9rem; color: #666; margin-bottom: 15px;"><?php echo htmlspecialchars(t('admin_contact_info_hint')); ?></p>
                        <label><?php echo htmlspecialchars(t('admin_admin_name_label')); ?></label>
                        <input type="text" name="admin_name" value="<?php echo htmlspecialchars($settings['admin_name'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(t('admin_admin_name_placeholder')); ?>">
                        <label><?php echo htmlspecialchars(t('admin_admin_email_label')); ?></label>
                        <input type="email" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(t('admin_admin_email_placeholder')); ?>">
                        <label><?php echo htmlspecialchars(t('admin_admin_phone_label')); ?></label>
                        <input type="tel" name="admin_phone" value="<?php echo htmlspecialchars($settings['admin_phone'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(t('admin_admin_phone_placeholder')); ?>">
                    </div>
                </div>
                <button type="submit" name="save_settings" class="btn-primary"><?php echo htmlspecialchars(t('admin_btn_save_all_settings')); ?></button>
            </div>

            <div id="language" class="tab-content">
                <div class="settings-grid">
                    <div class="settings-section">
                        <h3><?php echo htmlspecialchars(t('admin_language_heading')); ?></h3>
                        <p style="font-size: 0.9rem; color: #666; margin-bottom: 15px;">
                            <?php echo htmlspecialchars(t('admin_language_hint')); ?>
                        </p>
                        <label><?php echo htmlspecialchars(t('admin_language_select_label')); ?></label>
                        <select name="site_language" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px;">
                            <?php foreach (SUPPORTED_LANGUAGES as $lang_key => $lang_label): ?>
                                <option value="<?php echo htmlspecialchars($lang_key); ?>" <?php echo (($settings['site_language'] ?? 'nl') === $lang_key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="save_settings" class="btn-primary"><?php echo htmlspecialchars(t('admin_btn_save_language')); ?></button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <form id="delete-form" method="POST" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="delete_id" id="delete_id">
    </form>

    <script>
    function renderColorSchemeSwatches() {
        var select = document.getElementById('colorSchemeSelect');
        var option = select.options[select.selectedIndex];
        var primary = option.getAttribute('data-primary');
        var hover = option.getAttribute('data-hover');
        var dark = option.getAttribute('data-dark');

        var swatchContainer = document.getElementById('colorSchemeSwatches');
        if (swatchContainer) {
            swatchContainer.innerHTML = [primary, hover, dark].map(function (color) {
                return '<div style="width:40px;height:40px;border-radius:8px;border:1px solid #ddd;background:' + color + ';" title="' + color + '"></div>';
            }).join('');
        }
        return primary;
    }

    // Wanneer de gebruiker zelf een ander schema kiest: ook de knopkleur meteen bijwerken
    function updateColorSchemeSwatches() {
        var primary = renderColorSchemeSwatches();
        var buttonColorInput = document.getElementById('welcomeButtonColorInput');
        if (buttonColorInput) {
            buttonColorInput.value = primary;
        }
        if (typeof updateWelcomePreview === 'function') {
            updateWelcomePreview();
        }
    }

    // Bij het laden van de pagina alleen de swatches tonen, de bestaande knopkleur niet overschrijven
    document.addEventListener('DOMContentLoaded', renderColorSchemeSwatches);

    function openTab(evt, tabName) {
        var i, tabContent, tabBtns;
        tabContent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabContent.length; i++) { tabContent[i].style.display = "none"; }
        tabBtns = document.getElementsByClassName("tab-btn");
        for (i = 0; i < tabBtns.length; i++) { tabBtns[i].className = tabBtns[i].className.replace(" active", ""); }
        document.getElementById(tabName).style.display = "block";
        evt.currentTarget.className += " active";
    }
    function deleteItem(id) {
        if (confirm(<?php echo json_encode(t('admin_confirm_delete')); ?>)) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete-form').submit();
        }
    }

    // --- Live voorbeeld welkomstpagina ---
    var welcomePreviewImageDataUrl = null;
    var welcomeExistingImagePath = <?php echo json_encode($settings['welcome_image'] ?? 'fonback2.jpeg'); ?>;
    var WELCOME_PREVIEW_I18N = {
        memorialPrefix: <?php echo json_encode(t('index_memorial_prefix')); ?>,
        viewSlideshow: <?php echo json_encode(t('index_link_view_slideshow')); ?>,
        fullscreenButton: <?php echo json_encode(t('admin_fullscreen_button')); ?>,
        yourNameLabel: <?php echo json_encode(t('index_label_your_name')); ?>,
        namePlaceholder: <?php echo json_encode(t('index_placeholder_name_example')); ?>,
        sharedPasswordLabel: <?php echo json_encode(t('index_label_shared_password')); ?>,
        loginButton: <?php echo json_encode(t('common_login')); ?>,
        nameFallback: <?php echo json_encode(t('admin_name_fallback')); ?>
    };

    function getWelcomeFieldValue(name) {
        var el = document.querySelector('#welcome_page [name="' + name + '"]');
        return el ? el.value : '';
    }

    function welcomeEscapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function welcomeHexToRgb(hex) {
        hex = (hex || '#000000').replace('#', '');
        var r = parseInt(hex.substring(0, 2), 16) || 0;
        var g = parseInt(hex.substring(2, 4), 16) || 0;
        var b = parseInt(hex.substring(4, 6), 16) || 0;
        return r + ', ' + g + ', ' + b;
    }

    function formatWelcomeDate(dateStr) {
        if (!dateStr) { return ''; }
        var dutchMonths = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
        var parts = dateStr.split('-');
        if (parts.length !== 3) { return ''; }
        var day = parseInt(parts[2], 10);
        var month = parseInt(parts[1], 10) - 1;
        var year = parts[0];
        if (!dutchMonths[month]) { return ''; }
        return day + ' ' + dutchMonths[month] + ' ' + year;
    }

    function buildWelcomePreviewHtml() {
        var memorialName = getWelcomeFieldValue('memorial_name') || WELCOME_PREVIEW_I18N.nameFallback;
        var memorialBirthDate = formatWelcomeDate(getWelcomeFieldValue('memorial_birth_date'));
        var memorialDeathDate = formatWelcomeDate(getWelcomeFieldValue('memorial_date'));
        var memorialDate = '';
        if (memorialBirthDate && memorialDeathDate) {
            memorialDate = memorialBirthDate + ' – ✝ ' + memorialDeathDate;
        } else if (memorialDeathDate) {
            memorialDate = '✝ ' + memorialDeathDate;
        } else {
            memorialDate = memorialBirthDate;
        }
        var subtitle = getWelcomeFieldValue('welcome_subtitle');
        var font = getWelcomeFieldValue('site_font') || "'Dancing Script', cursive";
        var buttonColor = getWelcomeFieldValue('welcome_button_color') || '#d4a373';
        var bgSize = getWelcomeFieldValue('welcome_bg_size') || 'cover';
        var bgPosition = getWelcomeFieldValue('welcome_bg_position') || 'center';
        var overlayColor = getWelcomeFieldValue('welcome_overlay_color') || '#000000';
        var overlayOpacity = (parseInt(getWelcomeFieldValue('welcome_overlay_opacity') || '0', 10)) / 100;
        var cardOpacity = (parseInt(getWelcomeFieldValue('welcome_card_opacity') || '95', 10)) / 100;
        var imageUrl = welcomePreviewImageDataUrl || welcomeExistingImagePath;

        var bgRepeat = bgSize === 'repeat' ? 'repeat' : 'no-repeat';
        var bgSizeCss = bgSize === 'repeat' ? 'auto' : bgSize;
        var overlayRgb = welcomeHexToRgb(overlayColor);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
            '<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&family=Great+Vibes&family=Parisienne&family=Lora:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Cinzel:wght@400;600&family=Josefin+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">' +
            '<style>' +
            'body{margin:0;font-family:"Lora",serif;}' +
            '.slideshow-body{width:100%;height:100vh;background-image:url(\'' + imageUrl + '\');background-size:' + bgSizeCss + ';background-position:' + bgPosition + ';background-repeat:' + bgRepeat + ';box-sizing:border-box;}' +
            '.welcome-overlay{width:100%;height:100vh;background:rgba(' + overlayRgb + ',' + overlayOpacity + ');display:flex;justify-content:center;align-items:center;box-sizing:border-box;}' +
            '.login-container{background-color:rgba(255,255,255,' + cardOpacity + ');padding:40px;border-radius:15px;box-shadow:0 10px 30px rgba(0,0,0,0.05);max-width:500px;width:90%;text-align:center;}' +
            'h1,h2{font-family:' + font + ';}' +
            'h1{font-size:2.6em;margin-bottom:15px;color:#333;}' +
            'h2{margin:0;color:#495057;}' +
            'p{color:#555;}' +
            '.btn-fullscreen-toggle{position:fixed;bottom:20px;right:20px;padding:10px 18px;background:#333;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:sans-serif;}' +
            '.btn-primary-preview{width:100%;padding:15px;background:' + buttonColor + ';border:none;color:#fff;border-radius:8px;font-size:1.1em;cursor:pointer;font-family:"Lora",serif;box-sizing:border-box;}' +
            '</style></head><body>' +
            '<div class="slideshow-body"><div class="welcome-overlay"><div class="login-container">' +
            '<h2>' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.memorialPrefix) + '</h2><h1>' + welcomeEscapeHtml(memorialName) + '</h1>' +
            (memorialDate ? '<p style="font-style:italic;color:#888;margin-top:-10px;margin-bottom:20px;font-size:1.2em;letter-spacing:0.5px;">' + welcomeEscapeHtml(memorialDate) + '</p>' : '') +
            '<p>' + welcomeEscapeHtml(subtitle) + '</p>' +
            '<form style="margin-top:20px;"><div style="margin-bottom:20px;text-align:left;"><label style="display:block;margin-bottom:8px;font-weight:bold;color:#666;">' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.yourNameLabel) + '</label><input type="text" placeholder="' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.namePlaceholder) + '" disabled style="width:100%;padding:12px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box;"></div>' +
            '<div style="margin-bottom:30px;text-align:left;"><label style="display:block;margin-bottom:8px;font-weight:bold;color:#666;">' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.sharedPasswordLabel) + '</label><input type="password" disabled style="width:100%;padding:12px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box;"></div>' +
            '<button type="button" class="btn-primary-preview">' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.loginButton) + '</button></form>' +
            '<br><p><a href="#" onclick="return false;" style="color:#6c757d;text-decoration:none;font-weight:bold;border-bottom:1px solid #6c757d;">' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.viewSlideshow) + '</a></p>' +
            '</div></div></div>' +
            '<button class="btn-fullscreen-toggle" onclick="if(document.documentElement.requestFullscreen){document.documentElement.requestFullscreen();}">' + welcomeEscapeHtml(WELCOME_PREVIEW_I18N.fullscreenButton) + '</button>' +
            '</body></html>';
    }

    function updateWelcomePreview() {
        var frame = document.getElementById('welcomePreviewFrame');
        if (frame) { frame.srcdoc = buildWelcomePreviewHtml(); }
    }

    document.addEventListener('DOMContentLoaded', function() {
        var welcomeTab = document.getElementById('welcome_page');
        if (welcomeTab) {
            welcomeTab.addEventListener('input', updateWelcomePreview);
            var imageInput = document.getElementById('welcomeImageInput');
            if (imageInput) {
                imageInput.addEventListener('change', function (e) {
                    var file = e.target.files[0];
                    var thumbnail = document.getElementById('welcomeImageThumbnail');
                    if (!file) {
                        welcomePreviewImageDataUrl = null;
                        updateWelcomePreview();
                        return;
                    }
                    var reader = new FileReader();
                    reader.onload = function (ev) {
                        welcomePreviewImageDataUrl = ev.target.result;
                        if (thumbnail) {
                            thumbnail.src = ev.target.result;
                            thumbnail.style.display = 'inline-block';
                        }
                        updateWelcomePreview();
                    };
                    reader.readAsDataURL(file);
                });
            }
            updateWelcomePreview();
        }
    });
    </script>
</body>
</html>
