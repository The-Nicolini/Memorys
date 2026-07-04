<?php
// upload.php
require_once __DIR__ . '/private/config.php';

// Toegang alleen als ingelogd als gast, familie of admin
if (!isset($_SESSION["guest_access"]) && !isset($_SESSION["family_access"]) && !isset($_SESSION["admin_logged_in"])) {
    header("Location: index");
    exit;
}

$displayName = $_SESSION["username"] ?? "Gast";

// Naam en sterfdatum van de persoon in gedachtenis ophalen voor de begeleidende tekst
$settings = $conn->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$site_primary_hover = $settings['site_primary_hover_color'] ?? '#bc8a5f';
$memorial_name = ($settings['memorial_name'] ?? '') ?: 'John Doe';

$memorial_date_formatted = '';
if (!empty($settings['memorial_date'])) {
    $memorial_date_ts = strtotime($settings['memorial_date']);
    if ($memorial_date_ts !== false) {
        $memorial_date_formatted = (int)date('j', $memorial_date_ts) . ' ' . t('month_' . (int)date('n', $memorial_date_ts)) . ' ' . date('Y', $memorial_date_ts);
    }
}

// Familieleden kunnen alleen foto's aan de slideshow toevoegen als de admin dat toestaat
$can_add_slideshow_photos = true;
if (isset($_SESSION["family_access"])) {
    $can_add_slideshow_photos = ($settings['family_can_add_slideshow_photos'] ?? '1') == '1';
}

$message = "";
$status = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_verify();
    $uploader_name = $displayName;

    // 1. Verwerk Foto's (voor slideshow)
    if ($can_add_slideshow_photos && !empty($_FILES["photos"]["name"][0])) {
        $is_anonymous = isset($_POST["is_anonymous"]) ? 1 : 0;
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
                $stmt = $conn->prepare("INSERT INTO media (file_type, file_path, uploader_name, is_active, is_anonymous) VALUES ('photo', :path, :name, 0, :anonymous)");
                $stmt->execute(['path' => $target, 'name' => $uploader_name, 'anonymous' => $is_anonymous]);
            }
        }
        $message = t('upload_msg_photos_success');
        $status = "success";
    }

    // 1b. Verwerk Familie-foto's (alleen voor familie-overzicht)
    if (!empty($_FILES["family_photos"]["name"][0])) {
        $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $family_folder = "uploads/family_photos/" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uploader_name);
        if (!is_dir($family_folder)) {
            mkdir($family_folder, 0777, true);
        }
        foreach ($_FILES["family_photos"]["tmp_name"] as $key => $tmp_name) {
            $safe_name = basename($_FILES["family_photos"]["name"][$key]);
            $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_images)) {
                continue;
            }
            $file_name = time() . "_" . $safe_name;
            $target = $family_folder . "/" . $file_name;
            if (move_uploaded_file($tmp_name, $target)) {
                // Optioneel: metadata opslaan in een aparte tabel
                // $stmt = $conn->prepare("INSERT INTO family_photos (file_path, uploader_name, uploaded_at) VALUES (:path, :name, NOW())");
                // $stmt->execute(['path' => $target, 'name' => $uploader_name]);
            }
        }
        // Direct redirect naar familie-overzicht na upload
        header("Location: family_overview.php?upload=success");
        exit;
    }

    // 2. Verwerk Muziek (MP3, WAV - universeel ondersteund door alle browsers)
    if (!empty($_FILES["music"]["name"][0])) {
        $allowed_audio = ['mp3', 'wav'];
        if (!is_dir("uploads/music")) {
            mkdir("uploads/music", 0777, true);
        }
        foreach ($_FILES["music"]["tmp_name"] as $key => $tmp_name) {
            $original_name = basename($_FILES["music"]["name"][$key]);
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed_audio)) {
                $file_name = time() . "_" . $original_name;
                $target = "uploads/music/" . $file_name;
                if (move_uploaded_file($tmp_name, $target)) {
                    $stmt = $conn->prepare("INSERT INTO media (file_type, file_path, uploader_name, is_active) VALUES ('music', :path, :name, 0)");
                    $stmt->execute(['path' => $target, 'name' => $uploader_name]);
                    $message = t('upload_msg_music_success');
                    $status = "success";
                }
            } else {
                $message = t('upload_msg_music_error');
                $status = "error";
            }
        }
    }

    // 3. Verwerk Berichten (meerdere teksten)
    if (!empty($_POST["messages"])) {
        $is_anonymous = isset($_POST["is_anonymous"]) ? 1 : 0;
        foreach ($_POST["messages"] as $msg_text) {
            if (!empty(trim($msg_text))) {
                $stmt = $conn->prepare("INSERT INTO media (file_type, message_text, uploader_name, is_active, is_anonymous) VALUES ('message', :text, :name, 0, :anonymous)");
                $stmt->execute(['text' => $msg_text, 'name' => $uploader_name, 'anonymous' => $is_anonymous]);
            }
        }
        if ($status !== "error") {
            $message = t('upload_msg_messages_success');
            $status = "success";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(t('html_lang')); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('upload_title')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&family=Lora:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --soft-gold: <?php echo htmlspecialchars($settings['site_primary_color'] ?? '#c5a059'); ?>;
            --deep-blue: #1b263b;
            --ivory: #f8f9fa;
            --shadow-soft: 0 10px 30px rgba(0,0,0,0.1);
        }

        body.guest-body {
            background: linear-gradient(135deg, #fdfcfb 0%, #e2d1c3 100%);
            font-family: 'Lora', serif;
            color: #444;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .upload-container {
            background: rgba(255, 255, 255, 0.95);
            max-width: 700px;
            width: 100%;
            padding: 60px;
            border-radius: 40px;
            box-shadow: var(--shadow-soft);
            text-align: center;
            position: relative;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .upload-container::before {
            content: "";
            position: absolute;
            top: 15px; left: 15px; right: 15px; bottom: 15px;
            border: 1px solid #e0d5c1;
            border-radius: 30px;
            pointer-events: none;
        }

        h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.8rem;
            color: var(--deep-blue);
            margin-bottom: 10px;
            font-style: italic;
        }

        .subtitle {
            font-family: 'Dancing Script', cursive;
            font-size: 1.8rem;
            color: var(--soft-gold);
            margin-bottom: 40px;
        }

        .form-group {
            text-align: left;
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: #555;
            font-style: italic;
        }

        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-family: 'Lora', serif;
            font-size: 1rem;
            background: #fff;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--soft-gold);
            box-shadow: 0 0 8px rgba(197, 160, 89, 0.2);
        }

        .form-group input[type="file"] {
            width: 100%;
            padding: 10px;
            background: #fdfaf2;
            border: 1px dashed #d4b483;
            border-radius: 12px;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--deep-blue);
            color: white;
            border: none;
            padding: 18px 40px;
            border-radius: 50px;
            font-family: 'Playfair Display', serif;
            font-size: 1.2rem;
            cursor: pointer;
            transition: transform 0.3s, background 0.3s;
            margin-top: 20px;
            width: 100%;
            letter-spacing: 1px;
        }

        .btn-primary:hover {
            background: #0d1b2a;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: transparent;
            color: var(--soft-gold);
            border: 1px solid var(--soft-gold);
            padding: 10px 20px;
            border-radius: 50px;
            cursor: pointer;
            font-family: 'Lora', serif;
            font-style: italic;
            transition: 0.3s;
        }

        .btn-secondary:hover {
            background: #fdfaf2;
        }

        .logout-link {
            position: absolute;
            top: -40px;
            right: 0;
            color: #888;
            text-decoration: none;
            font-size: 0.9rem;
            font-style: italic;
            transition: 0.3s;
        }

        .logout-link:hover { color: var(--soft-gold); }

        .alert {
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            font-family: 'Lora', serif;
            font-style: italic;
        }
        .alert.success { background: #f0f7f0; color: #2d5a27; border: 1px solid #d4ebd0; }
        .alert.error { background: #fdf2f2; color: #9b2c2c; border: 1px solid #f9d7d7; }
    </style>
</head>
<body class="guest-body">
    <div class="upload-container">
        <div style="position: relative; min-height: 40px;">
            <a href="logout" class="logout-link" style="right:0; top:0;"><?php echo htmlspecialchars(t('common_logout')); ?></a>
            <?php if (isset($_SESSION["family_access"])): ?>
                <a href="family_overview" class="logout-link" style="right:110px; top:0; background:<?php echo htmlspecialchars($site_primary_hover); ?>; color:#fff; padding:8px 18px; border-radius:8px; font-weight:bold; text-decoration:none; margin-right:10px;"><?php echo htmlspecialchars(t('upload_link_family_overview')); ?></a>
            <?php endif; ?>
        </div>

        <h1><?php echo htmlspecialchars(t('upload_heading')); ?></h1>
        <p class="subtitle"><?php echo htmlspecialchars(t('upload_subtitle_prefix')); ?> <?php echo htmlspecialchars($memorial_name); ?></p>

        <div style="background: rgba(197, 160, 89, 0.05); padding: 25px; border-radius: 20px; border: 1px solid rgba(197, 160, 89, 0.2); margin-bottom: 40px; text-align: left;">
            <p style="margin-top: 0; font-family: 'Playfair Display', serif; font-size: 1.3rem; color: var(--deep-blue);">
                <?php echo htmlspecialchars(t('upload_welcome_prefix')); ?> <?php echo htmlspecialchars($displayName); ?>
            </p>
            <p style="margin-bottom: 0; font-size: 0.95rem; line-height: 1.6; color: #666; font-style: italic;">
                <?php
                    $date_suffix = $memorial_date_formatted ? ' (' . htmlspecialchars($memorial_date_formatted) . ')' : '';
                    echo sprintf(t('upload_intro_text'), htmlspecialchars($memorial_name), $date_suffix);
                ?>
            </p>
        </div>

        <?php if ($message && $status === "success"): ?>
            <div class="alert success" style="text-align: left; padding: 30px;">
                <h3 style="font-family: 'Playfair Display', serif; margin-top: 0; color: #2d5a27;"><?php echo htmlspecialchars(t('upload_thanks_heading')); ?></h3>
                <p><?php echo $message; ?></p>
                <hr style="border: 0; border-top: 1px solid #d4ebd0; margin: 15px 0;">
                <p style="font-size: 0.9rem; margin-bottom: 5px;"><strong><?php echo htmlspecialchars(t('upload_summary_heading')); ?></strong></p>
                <ul style="font-size: 0.9rem; padding-left: 20px; list-style-type: none;">
                    <li>✨ <?php echo htmlspecialchars(t('upload_summary_name')); ?> <?php echo htmlspecialchars($displayName); ?></li>
                    <?php if (!empty($_FILES["photos"]["name"][0])): ?>
                        <li>📸 <?php echo htmlspecialchars(t('upload_summary_photos')); ?> <?php echo count($_FILES["photos"]["name"]); ?> <?php echo htmlspecialchars(t('upload_summary_files_suffix')); ?></li>
                    <?php endif; ?>
                    <?php if (!empty($_FILES["music"]["name"][0])): ?>
                        <li>🎵 <?php echo htmlspecialchars(t('upload_summary_music')); ?> <?php echo count($_FILES["music"]["name"]); ?> <?php echo htmlspecialchars(t('upload_summary_files_suffix')); ?></li>
                    <?php endif; ?>
                    <?php if (!empty($_POST["messages"]) && !empty(trim($_POST["messages"][0]))): ?>
                        <li>✍️ <?php echo htmlspecialchars(t('upload_summary_messages')); ?> <?php echo count(array_filter($_POST["messages"])); ?> <?php echo htmlspecialchars(t('upload_summary_messages_suffix')); ?></li>
                    <?php endif; ?>
                </ul>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="upload" class="btn-secondary" style="text-decoration: none; display: inline-block;"><?php echo htmlspecialchars(t('upload_link_add_more')); ?></a>
                </div>
            </div>
        <?php elseif ($message): ?>
            <div class="alert <?php echo $status; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (!($message && $status === "success")): ?>
        <form id="mainUploadForm" action="upload" method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <!-- Sectie 1: Foto's (voor slideshow) -->
            <?php if ($can_add_slideshow_photos): ?>
            <div class="form-group" style="background: #fff; padding: 20px; border-radius: 15px; border: 1px solid #eee; margin-bottom: 20px;">
                <label><?php echo htmlspecialchars(t('upload_label_select_photos')); ?></label>
                <input type="file" id="photoInput" name="photos[]" multiple accept="image/*">
                <div id="photoPreview" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;"></div>
            </div>
            <?php endif; ?>

            <!-- Sectie 1b: Familie-foto's (alleen voor familie-overzicht) -->
            <div class="form-group" style="background: #fff; padding: 20px; border-radius: 15px; border: 1px solid #eee; margin-bottom: 20px;">
                <label><?php echo htmlspecialchars(t('upload_label_family_photos')); ?></label>
                <input type="file" id="familyPhotoInput" name="family_photos[]" multiple accept="image/*">
                <div id="familyPhotoPreview" style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;"></div>
            </div>

            <!-- Sectie 2: Muziek -->
            <div class="form-group" style="background: #fff; padding: 20px; border-radius: 15px; border: 1px solid #eee; margin-bottom: 20px;">
                <label><?php echo htmlspecialchars(t('upload_label_music')); ?></label>
                <input type="file" id="musicInput" name="music[]" multiple accept=".mp3,.wav">
                <div id="musicList" style="margin-top: 10px; font-size: 0.85rem; color: #666;"></div>
            </div>

            <!-- Sectie 3: Berichten -->
            <div class="form-group" id="messages-container" style="background: #fff; padding: 20px; border-radius: 15px; border: 1px solid #eee; margin-bottom: 20px;">
                <label><?php echo htmlspecialchars(t('upload_label_messages')); ?></label>
                <div class="message-entry">
                    <textarea name="messages[]" rows="3" placeholder="<?php echo htmlspecialchars(t('upload_placeholder_first_message')); ?>"></textarea>
                </div>
            </div>

            <div style="text-align: center; margin-bottom: 30px;">
                <button type="button" class="btn-secondary" onclick="addEntry()" style="font-size: 0.9rem;"><?php echo htmlspecialchars(t('upload_btn_add_message')); ?></button>
            </div>

            <!-- Optie: Anoniem plaatsen -->
            <div class="form-group" style="background: rgba(197, 160, 89, 0.05); padding: 20px; border-radius: 15px; border: 1px dashed var(--soft-gold); margin-bottom: 20px; text-align: left;">
                <label style="display: flex; align-items: center; cursor: pointer; margin-bottom: 0; font-family: 'Lora', serif; font-style: normal; font-size: 1rem; color: var(--deep-blue);">
                    <input type="checkbox" name="is_anonymous" value="1" style="width: 20px; height: 20px; margin-right: 15px; cursor: pointer;">
                    <?php echo htmlspecialchars(t('upload_label_anonymous')); ?>
                </label>
                <p style="margin: 10px 0 0 35px; font-size: 0.8rem; color: #888; font-style: italic;">
                    <?php echo htmlspecialchars(t('upload_anonymous_explanation')); ?>
                </p>
            </div>

            <p style="font-size: 0.85rem; color: #888; margin-bottom: 15px;"><em><?php echo htmlspecialchars(t('upload_check_files_notice')); ?></em></p>
            <button type="submit" class="btn-primary"><?php echo htmlspecialchars(t('upload_btn_submit')); ?></button>
        </form>
        <?php endif; ?>

        <?php 
        // Haal beheerder contactgegevens op voor informele weergave
        $admin_name = $admin_email = $admin_phone = '';
        $stmt = $conn->prepare("SELECT setting_key, value FROM settings WHERE setting_key IN ('admin_name', 'admin_email', 'admin_phone')");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'admin_name') $admin_name = $row['value'];
            if ($row['setting_key'] === 'admin_email') $admin_email = $row['value'];
            if ($row['setting_key'] === 'admin_phone') $admin_phone = $row['value'];
        }
        
        // Toon contactgegevens op informele manier
        if ($admin_name || $admin_email || $admin_phone): 
        ?>
        <div style="background: transparent; padding: 30px 0; text-align: center; border-top: 1px solid #eee; margin-top: 40px;">
            <p style="margin: 0 0 15px 0; font-size: 0.9rem; color: #999; font-style: italic;">
                <?php echo htmlspecialchars(t('upload_contact_intro')); ?>
            </p>
            <?php if ($admin_name): ?>
                <p style="margin: 5px 0; font-size: 0.95rem; color: #666;"><strong><?php echo htmlspecialchars($admin_name); ?></strong></p>
            <?php endif; ?>
            <?php if ($admin_email): ?>
                <p style="margin: 3px 0; font-size: 0.85rem; color: #888;"><a href="mailto:<?php echo htmlspecialchars($admin_email); ?>" style="color: <?php echo htmlspecialchars($site_primary_hover); ?>; text-decoration: none;"><?php echo htmlspecialchars($admin_email); ?></a></p>
            <?php endif; ?>
            <?php if ($admin_phone): ?>
                <p style="margin: 3px 0; font-size: 0.85rem; color: #888;"><a href="tel:<?php echo htmlspecialchars($admin_phone); ?>" style="color: <?php echo htmlspecialchars($site_primary_hover); ?>; text-decoration: none;"><?php echo htmlspecialchars($admin_phone); ?></a></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const UPLOAD_I18N = {
            extraMessagePlaceholder: <?php echo json_encode(t('upload_placeholder_extra_message')); ?>,
            processing: <?php echo json_encode(t('upload_js_processing')); ?>,
            uploadError: <?php echo json_encode(t('upload_js_error')); ?>,
            submitLabel: <?php echo json_encode(t('upload_btn_submit')); ?>
        };

        let allPhotos = [];
        let allFamilyPhotos = [];
        let allMusic = [];

        function addEntry() {
            const container = document.getElementById('messages-container');
            const div = document.createElement('div');
            div.className = 'message-entry';
            div.style.marginTop = "15px";
            div.style.borderTop = "1px solid #f0f0f0";
            div.style.paddingTop = "15px";
            const textarea = document.createElement('textarea');
            textarea.name = 'messages[]';
            textarea.rows = 3;
            textarea.placeholder = UPLOAD_I18N.extraMessagePlaceholder;
            div.appendChild(textarea);
            container.appendChild(div);
        }

        // Preview voor foto's
        document.getElementById('photoInput')?.addEventListener('change', function(e) {
            const preview = document.getElementById('photoPreview');
            const files = Array.from(this.files);
            files.forEach(file => {
                allPhotos.push(file);
                const reader = new FileReader();
                reader.onload = function(e) {
                    const imgContainer = document.createElement('div');
                    imgContainer.style.position = 'relative';
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.width = '60px';
                    img.style.height = '60px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    img.style.border = '1px solid #ddd';
                    imgContainer.appendChild(img);
                    preview.appendChild(imgContainer);
                }
                reader.readAsDataURL(file);
            });
            this.value = '';
        });

        // Preview voor familie-foto's
        document.getElementById('familyPhotoInput').addEventListener('change', function(e) {
            const preview = document.getElementById('familyPhotoPreview');
            const files = Array.from(this.files);
            files.forEach(file => {
                allFamilyPhotos.push(file);
                const reader = new FileReader();
                reader.onload = function(e) {
                    const imgContainer = document.createElement('div');
                    imgContainer.style.position = 'relative';
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.width = '60px';
                    img.style.height = '60px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    img.style.border = '1px solid #ddd';
                    imgContainer.appendChild(img);
                    preview.appendChild(imgContainer);
                }
                reader.readAsDataURL(file);
            });
            this.value = '';
        });

        // Lijst voor muziek
        document.getElementById('musicInput').addEventListener('change', function(e) {
            const list = document.getElementById('musicList');
            const files = Array.from(this.files);

            files.forEach(file => {
                allMusic.push(file);
                const item = document.createElement('div');
                item.textContent = '🎵 ' + file.name;
                list.appendChild(item);
            });
            this.value = '';
        });

        // Loading state bij verzenden
        document.getElementById('mainUploadForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = this.querySelector('.btn-primary');
            btn.innerHTML = UPLOAD_I18N.processing;
            btn.disabled = true;
            btn.style.opacity = "0.7";

            const formData = new FormData(this);
            
            // Remove the empty files from the original input
            formData.delete('photos[]');
            formData.delete('family_photos[]');
            formData.delete('music[]');

            // Append all collected files
            allPhotos.forEach(file => formData.append('photos[]', file));
            allFamilyPhotos.forEach(file => formData.append('family_photos[]', file));
            allMusic.forEach(file => formData.append('music[]', file));

            // Submit using fetch to current page
            fetch('upload', {
                method: 'POST',
                body: formData
            }).then(response => {
                if (response.ok) {
                    return response.text();
                }
                throw new Error('Upload mislukt');
            }).then(html => {
                document.body.innerHTML = html;
                window.scrollTo(0, 0);
            }).catch(error => {
                alert(UPLOAD_I18N.uploadError);
                btn.disabled = false;
                btn.style.opacity = "1";
                btn.innerHTML = UPLOAD_I18N.submitLabel;
            });
        });
    </script>
</body>
</html>
