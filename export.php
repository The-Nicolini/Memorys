<?php
// export.php
require_once __DIR__ . '/private/config.php';

// Check login
if (!isset($_SESSION["admin_logged_in"])) {
    header("Location: index");
    exit;
}

// Fetch all photos and messages
$photos = $conn->query("SELECT * FROM media WHERE file_type = 'photo' ORDER BY created_at ASC")->fetchAll();
$messages = $conn->query("SELECT * FROM media WHERE file_type = 'message' ORDER BY created_at ASC")->fetchAll();

// Get global settings for default styling
$settings_stmt = $conn->query("SELECT setting_key, value FROM settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(t('html_lang')); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(sprintf(t('export_title'), $settings['memorial_name'] ?? 'John Doe')); ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;700&family=Lora:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #d4a373;
            --bg: #f4f7f6;
            --white: #ffffff;
            --text: #333;
            --shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        body {
            background-color: var(--bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        .top-header {
            width: 100vw;
            background: white;
            box-shadow: var(--shadow);
            border-bottom: 3px solid var(--primary);
            position: static;
            top: auto;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 2rem 2rem 2rem;
        }

        .top-header h1 {
            margin:0 0 10px 0;
            color: var(--primary);
            font-family: 'Dancing Script', cursive;
            font-size: 3rem;
            text-align: center;
        }

        .top-header p {
            margin: 0 0 30px 0;
            font-size: 1.3rem;
            color: #666;
            text-align: center;
        }

        .top-header .button-row {
            display: flex;
            gap: 20px;
            width: 100%;
            max-width: 900px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .top-header .button-row a,
        .top-header .button-row button {
            flex: 1;
            min-width: 250px;
            max-width: 450px;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-weight: bold;
            font-size: 1.3rem;
            transition: 0.3s;
            box-sizing: border-box;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-decoration: none;
        }

        .top-header .button-row a {
            background: #f8f9fa;
            border: 1px solid #ddd;
            color: #666;
        }

        .top-header .button-row button {
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: 0 6px 15px rgba(212, 163, 115, 0.4);
            cursor: pointer;
        }

        .export-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 60px;
            background: #fff9f6;
            min-height: 297mm; /* A4 aspect ratio approximation */
            box-shadow: 0 0 40px rgba(0,0,0,0.1);
            border-radius: 18px;
            border: 2px solid #e9dcc9;
            position: relative;
            /* Add a subtle floral background image (SVG) */
            background-image: url('data:image/svg+xml;utf8,<svg width="100%25" height="100%25" xmlns="http://www.w3.org/2000/svg"><g opacity="0.08"><circle cx="100" cy="100" r="80" fill="%23d4a373"/><ellipse cx="1100" cy="100" rx="90" ry="60" fill="%23b5838d"/><ellipse cx="600" cy="900" rx="120" ry="80" fill="%23a67c52"/></g></svg>');
            background-repeat: no-repeat;
            background-size: cover;
        }

        .export-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e9dcc9;
            padding-bottom: 15px;
        }

        .export-header h1 {
            font-family: 'Playfair Display', serif;
            color: #a67c52;
            margin-bottom: 10px;
            letter-spacing: 2px;
        }

        /* Photo Collage Grid */
        .collage-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            margin-bottom: 60px;
        }

        .collage-item {
            background: #fff;
            border-radius: 22px;
            border: 2px solid #e9dcc9;
            box-shadow: 0 2px 10px rgba(212,163,115,0.07);
            padding: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: box-shadow 0.2s;
            position: relative;
            overflow: visible;
            aspect-ratio: 4/3;
            min-height: 320px;
            margin-bottom: 0;
        }

        .collage-item-inner {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .collage-item img {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 18px;
            box-shadow: 0 4px 18px rgba(181,131,141,0.10);
            margin: 0;
        }

        .collage-item:hover {
            box-shadow: 0 8px 24px rgba(166,124,82,0.13);
        }

        /* Message List */
        .message-export-list {
            column-count: 2;
            column-gap: 40px;
        }

        .message-box {
            break-inside: avoid;
            margin-bottom: 30px;
            padding: 24px 20px 18px 20px;
            border-left: 5px solid #b5838d;
            background: #fff7f0;
            border-radius: 10px 30px 30px 10px;
            box-shadow: 0 2px 10px rgba(181,131,141,0.07);
            position: relative;
        }

        .message-box:hover {
            background: #fdfdfd;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }

        .message-text {
            font-size: 1.2rem;
            line-height: 1.7;
            margin-bottom: 12px;
            font-style: italic;
            color: #7c5e3b;
            font-family: 'Lora', serif;
        }

        .message-author {
            font-family: 'Dancing Script', cursive;
            font-weight: bold;
            text-align: right;
            color: #b5838d;
            font-size: 1.1rem;
            margin-top: 8px;
        }

        /* Selection Controls */
        .deselected img {
            opacity: 0.1;
            filter: grayscale(1);
        }

        .deselected.message-box {
            opacity: 0.1;
            filter: grayscale(1);
            border-left-color: #ccc;
        }

        .toggle-hint {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.5);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            display: none;
            pointer-events: none;
        }

        .deselected .toggle-hint {
            display: block;
            background: rgba(212, 163, 115, 0.8);
        }

        @media print {
            .top-header { display: none; }
            body { background: white; }
            .export-container { 
                margin: 0; 
                padding: 0; 
                box-shadow: none; 
                max-width: 100%;
            }
            .collage-item.deselected, .message-box.deselected {
                display: none !important;
            }
            body, html {
                width: 210mm;
                height: 297mm;
                margin: 0;
                padding: 0;
                background: #fff !important;
            }
            .export-container {
                width: 190mm;
                min-height: 277mm;
                max-width: 190mm;
                margin: 10mm auto;
                padding: 0;
                box-shadow: none;
                border: none;
                background: #fff !important;
                page-break-after: always;
                page-break-inside: avoid;
            }
            .collage-grid {
                gap: 18px;
                margin-bottom: 30px;
            }
            .collage-item {
                page-break-inside: avoid;
                break-inside: avoid;
                min-height: 120mm;
                max-height: 120mm;
                aspect-ratio: 4/3;
            }
            .collage-item-inner, .collage-item img {
                min-height: 100mm;
                max-height: 100mm;
                height: 100mm;
            }
            .message-export-list {
                column-count: 2;
                column-gap: 18px;
            }
            .message-box {
                page-break-inside: avoid;
                break-inside: avoid;
                min-height: 40mm;
                max-height: 60mm;
            }
        }

        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }

        .btn-print { background: var(--primary); color: white; }
        .btn-back { background: #666; color: white; }
        
        .instruction-box {
            font-size: 0.9rem;
            color: #666;
            margin-top: 10px;
        }
    </style>
</head>
<body style="margin:0; padding:0; background: var(--bg);">

    <div class="top-header">
        <h1><?php echo htmlspecialchars(t('export_heading')); ?></h1>
        <p><?php echo t('export_instructions'); ?></p>
        <div class="button-row">
            <a href="admin" class="btn btn-back"><?php echo htmlspecialchars(t('common_back_to_admin')); ?></a>
            <button onclick="window.print()" class="btn btn-print"><?php echo htmlspecialchars(t('export_btn_print')); ?></button>
            <form method="post" action="download_export" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="selected_photos" id="selected_photos_input">
                <input type="hidden" name="selected_messages" id="selected_messages_input">
                <button type="submit" class="btn btn-download"><?php echo htmlspecialchars(t('export_btn_download')); ?></button>
            </form>
        </div>
    </div>

    <div class="export-container" id="printableArea">
        <div class="export-header">
            <h1 style="font-family: 'Playfair Display', serif; color: #5d4037; margin-bottom: 10px;"><?php echo htmlspecialchars(t('export_loving_memory_heading')); ?></h1>
            <p><?php echo htmlspecialchars(sprintf(t('export_book_subtitle'), $settings['memorial_name'] ?? 'John Doe')); ?></p>
        </div>

        <h2 style="font-family: 'Playfair Display', serif; border-bottom: 1px solid #eee; padding-bottom: 10px;"><?php echo htmlspecialchars(t('export_photo_collage_heading')); ?></h2>
        <div class="collage-grid">
            <?php foreach ($photos as $p): ?>
                <div class="collage-item" onclick="toggleSelection(this)">
                    <div class="collage-item-inner">
                        <img src="<?php echo htmlspecialchars($p['file_path']); ?>" alt="<?php echo htmlspecialchars(t('slideshow_alt_memory')); ?>">
                    </div>
                    <div class="toggle-hint"><?php echo htmlspecialchars(t('export_hidden_label')); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 style="font-family: 'Playfair Display', serif; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 50px;"><?php echo htmlspecialchars(t('export_shared_words_heading')); ?></h2>
        <div class="message-export-list">
            <?php foreach ($messages as $msg): ?>
                <div class="message-box" onclick="toggleSelection(this)" style="position: relative;">
                    <div class="message-text" style="font-family: <?php echo $settings['global_font'] ?? 'serif'; ?>;">
                        "<?php echo htmlspecialchars($msg['message_text']); ?>"
                    </div>
                    <div class="message-author">
                        — <?php echo htmlspecialchars($msg['uploader_name']); ?>
                    </div>
                    <div class="toggle-hint" style="background: rgba(0,0,0,0.4);"><?php echo htmlspecialchars(t('export_not_printed_label')); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    function toggleSelection(element) {
        element.classList.toggle('deselected');
    }

    function updateDownloadSelection() {
        const selectedPhotos = Array.from(document.querySelectorAll('.collage-item:not(.deselected) img')).map(img => img.src);
        const selectedMessages = Array.from(document.querySelectorAll('.message-box:not(.deselected) .message-text')).map(div => div.innerText);
        document.getElementById('selected_photos_input').value = JSON.stringify(selectedPhotos);
        document.getElementById('selected_messages_input').value = JSON.stringify(selectedMessages);
    }
    document.querySelector('form[action="download_export"]').addEventListener('submit', function(e) {
        updateDownloadSelection();
    });
    </script>
</body>
</html>
