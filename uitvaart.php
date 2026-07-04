<?php
// uitvaart.php
require_once __DIR__ . '/private/config.php';

// Beveiliging: Alleen admin kan deze pagina direct openen of via sessie
if (!isset($_SESSION["admin_logged_in"])) {
    die(t('uitvaart_access_denied'));
}

// Haal instellingen op
$speed_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'slideshow_speed'")->fetch();
$photo_speed = ($speed_res) ? (int)$speed_res["value"] : 5000;

$msg_dur_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'message_duration'")->fetch();
$msg_speed = ($msg_dur_res) ? (int)$msg_dur_res["value"] : 10000;

// Globale styling uit settings ophalen
$global_font_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'global_font'")->fetch();
$global_font = ($global_font_res) ? $global_font_res["value"] : "'Dancing Script', cursive";

$global_color_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'global_color'")->fetch();
$global_color = ($global_color_res) ? $global_color_res["value"] : "#ffffff";

$global_bg_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'global_bg_type'")->fetch();
$global_bg_type = ($global_bg_res) ? $global_bg_res["value"] : "semi-transparent";

$memorial_name_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'memorial_name'")->fetch();
$memorial_name = ($memorial_name_res) ? $memorial_name_res["value"] : "John Doe";

$memorial_date_res = $conn->query("SELECT value FROM settings WHERE setting_key = 'memorial_date'")->fetch();
$memorial_date_formatted = '';
if ($memorial_date_res && !empty($memorial_date_res["value"])) {
    $memorial_date_ts = strtotime($memorial_date_res["value"]);
    if ($memorial_date_ts !== false) {
        $memorial_date_formatted = (int)date('j', $memorial_date_ts) . ' ' . t('month_' . (int)date('n', $memorial_date_ts)) . ' ' . date('Y', $memorial_date_ts);
    }
}

// Bepaal de achtergrondstijl voor message-only slides
$message_bg_style = "background: rgba(255,255,255,0.85);"; // default: semi-transparent white
if ($global_bg_type === 'none') {
    $message_bg_style = "background: transparent; box-shadow: none;";
} elseif ($global_bg_type === 'dark-transparent') {
    $message_bg_style = "background: rgba(0,0,0,0.6);";
}

// Logica om foto's en berichten te combineren
// We halen eerst alle actieve items op
$all_media_stmt = $conn->query("SELECT * FROM media WHERE is_active = 1 ORDER BY created_at DESC");
$raw_media = $all_media_stmt->fetchAll(PDO::FETCH_ASSOC);

$slides_content = [];
$temp_messages = [];

// Stap 1: Verzamel alle berichten
foreach ($raw_media as $m) {
    if ($m['file_type'] == 'message') {
        $temp_messages[] = $m;
    }
}

// Stap 2: Maak slides. We proberen berichten over foto's te plakken
// We gebruiken een globale pool van berichten om ze gelijkmatig over de foto's te verdelen
$msg_pool = $temp_messages;
$photo_slides = [];

foreach ($raw_media as $m) {
    if ($m['file_type'] == 'photo') {
        $photo_slides[] = $m;
    }
}

// We maken slides op basis van het aantal foto's
foreach ($photo_slides as $idx => $p) {
    // Pak een bericht uit de pool (indien beschikbaar)
    $msg = array_shift($msg_pool);
    
    // Bepaal de naam van de uploader voor foto en bericht, rekening houdend met anonimiteit
    $photo_uploader = ($p['is_anonymous'] == 1) ? "" : $p['uploader_name'];
    
    if ($msg) {
        $msg_uploader = ($msg['is_anonymous'] == 1) ? "" : $msg['uploader_name'];
        
        // Combineer namen als ze er zijn
        $combined_uploader = "";
        if ($photo_uploader && $msg_uploader) {
            $combined_uploader = $photo_uploader . ($photo_uploader !== $msg_uploader ? " & " . $msg_uploader : "");
        } elseif ($photo_uploader) {
            $combined_uploader = $photo_uploader;
        } elseif ($msg_uploader) {
            $combined_uploader = $msg_uploader;
        }

        $slides_content[] = [
            'type' => 'combined',
            'photo' => $p['file_path'],
            'message' => $msg['message_text'],
            'uploader' => $combined_uploader,
            'font' => $global_font,
            'color' => $global_color,
            'duration' => $msg_speed
        ];
    } else {
        $slides_content[] = [
            'type' => 'photo',
            'photo' => $p['file_path'],
            'uploader' => $photo_uploader,
            'duration' => $photo_speed
        ];
    }
}

// Als er nog berichten over zijn (meer berichten dan foto's), voeg ze als losse slides toe
foreach ($msg_pool as $msg) {
    $slides_content[] = [
        'type' => 'message',
        'message' => $msg['message_text'],
        'uploader' => ($msg['is_anonymous'] == 1) ? "" : $msg['uploader_name'],
        'font' => $global_font,
        'color' => $global_color,
        'duration' => $msg_speed
    ];
}

// Haal alleen de ACTIEVE muziek op
$music_stmt = $conn->query("SELECT file_path FROM media WHERE file_type = 'music' AND is_active = 1 ORDER BY created_at DESC");
$music_files = $music_stmt->fetchAll(PDO::FETCH_ASSOC);

// In deze versie negeren we funeral_mode zodat de admin altijd de slides ziet.
$is_funeral_mode = false; 
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(t('html_lang')); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(sprintf(t('slideshow_title'), $memorial_name)); ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&family=Great+Vibes&family=Parisienne&family=Lora:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Cinzel:wght@400;600&family=Josefin+Sans:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <style>
        body, html { height: 100%; margin: 0; padding: 0; overflow: hidden; background-color: #000; }
        .funeral-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: radial-gradient(circle, #2c3e50 0%, #000000 100%);
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            color: white; text-align: center; z-index: 9999; font-family: 'Lora', serif;
            padding: 20px;
        }
        .funeral-overlay h1 { font-family: 'Dancing Script', cursive; font-size: 4rem; margin-bottom: 30px; color: #d4a373; }
        .funeral-overlay p { font-size: 1.8rem; max-width: 800px; line-height: 1.6; font-style: italic; color: #ecf0f1; }
        .funeral-overlay .respect { margin-top: 50px; font-size: 1.2rem; color: #95a5a6; border-top: 1px solid #34495e; padding-top: 20px; }

        .slideshow-container { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: #000; }
        .slide { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; transition: opacity 1.5s ease-in-out; display: flex; justify-content: center; align-items: center; }
        .slide.active { opacity: 1; z-index: 5; }
        
        .slide img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            border: none !important;
            box-shadow: none !important;
            background: none !important;
        }
        
        /* Tekst bericht styling met transparante overlay OVER de foto */
        .message-overlay { 
            position: absolute; 
            bottom: 0; 
            left: 0; 
            width: 100%; 
            padding: 40px 30px; 
            text-align: center; 
            background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.5) 60%, transparent 100%);
            box-sizing: border-box;
            z-index: 10;
            color: white;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        .message-overlay p { font-size: 2.5rem; margin: 0 0 10px 0; line-height: 1.3; font-style: italic; white-space: pre-wrap; font-weight: 300; }
        .message-overlay .from { font-family: 'Dancing Script', cursive; font-size: 2rem; opacity: 0.9; }

        /* Full page message slide (wanneer er GEEN foto is) */
        .message-slide-full { 
            padding: 60px; 
            text-align: center; 
            max-width: 80%; 
            background: rgba(255,255,255,0.7); 
            border-radius: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.05);
        }
        .message-slide-full p { font-size: 3.2rem; margin-bottom: 25px; line-height: 1.4; font-style: italic; }

        .uploader-tag { position: absolute; top: 20px; right: 20px; font-family: 'Lora', serif; font-size: 0.9rem; color: rgba(255,255,255,0.6); font-style: italic; z-index: 20; text-shadow: 1px 1px 2px rgba(0,0,0,0.3); }
        .memorial-header { position: absolute; top: 30px; width: 100%; text-align: center; z-index: 1000; pointer-events: none; }
        .memorial-header h1 { font-family: 'Dancing Script', cursive; font-size: 2.5rem; color: rgba(255, 255, 255, 0.6); margin: 0; text-shadow: 2px 2px 4px rgba(0,0,0,0.5); }
        #bgPlaylist { display: none; }
    </style>
</head>
<body onload="startEverything()">

    <?php if ($is_funeral_mode): ?>
        <div class="funeral-overlay">
            <h1><?php echo htmlspecialchars(t('index_memorial_prefix')); ?> <?php echo htmlspecialchars($memorial_name); ?><?php echo $memorial_date_formatted ? '<br><span style="font-size:0.5em;">' . htmlspecialchars($memorial_date_formatted) . '</span>' : ''; ?></h1>
            <p><?php echo t('slideshow_funeral_mode_message'); ?></p>
            <div class="respect"><?php echo htmlspecialchars(t('slideshow_funeral_mode_respect')); ?></div>
        </div>
    <?php endif; ?>

    <div class="memorial-header"><h1><?php echo htmlspecialchars($memorial_name); ?></h1></div>

    <div class="slideshow-container" id="slideshow">
        <?php if (!$is_funeral_mode): ?>
            <?php if (empty($slides_content)): ?>
                <div class="slide active"><p style="color:white;"><?php echo htmlspecialchars(t('slideshow_no_content')); ?></p></div>
            <?php else: ?>
                <?php foreach ($slides_content as $index => $s): ?>
                    <div class="slide <?php echo ($index === 0) ? 'active' : ''; ?>" 
                        data-duration="<?php echo $s['duration']; ?>">
                        
                        <?php if ($s['type'] == 'combined'): ?>
                            <img src="<?php echo htmlspecialchars($s['photo']); ?>?t=<?php echo time(); ?>" alt="<?php echo htmlspecialchars(t('slideshow_alt_memory')); ?>">
                            <div class="message-overlay" style="font-family: <?php echo $s['font']; ?>; color: <?php echo $s['color']; ?>;">
                                <p>"<?php echo htmlspecialchars($s['message']); ?>"</p>
                                <div class="from">- <?php echo htmlspecialchars($s['uploader']); ?></div>
                            </div>
                        <?php elseif ($s['type'] == 'photo'): ?>
                            <img src="<?php echo htmlspecialchars($s['photo']); ?>?t=<?php echo time(); ?>" alt="<?php echo htmlspecialchars(t('slideshow_alt_memory')); ?>">
                            <div class="uploader-tag"><?php echo htmlspecialchars(t('slideshow_shared_by')); ?> <?php echo htmlspecialchars($s['uploader']); ?></div>
                        <?php else: ?>
                            <div class="message-slide-full" style="font-family: <?php echo $s['font']; ?>; <?php echo $message_bg_style; ?>">
                                <p style="color: <?php echo $s['color']; ?>;">"<?php echo htmlspecialchars($s['message']); ?>"</p>
                                <div class="from" style="color: <?php echo $s['color']; ?>; opacity: 0.8;">- <?php echo htmlspecialchars($s['uploader']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <audio id="bgPlaylist"></audio>

    <script>
        const audioPlayer = document.getElementById('bgPlaylist');
        const slides = document.querySelectorAll('.slide');
        let currentSlide = 0;
        const musicFiles = <?php echo json_encode(!$is_funeral_mode ? array_column($music_files, 'file_path') : []); ?>;
        let currentTrack = 0;
        let slideTimer;

        function startEverything() {
            if (slides.length === 0) return;
            const docElm = document.documentElement;
            if (docElm.requestFullscreen) docElm.requestFullscreen().catch(e => {});
            if (musicFiles.length > 0) playNextTrack();
            if (slides.length > 1) {
                const firstDuration = parseInt(slides[0].getAttribute('data-duration'));
                slideTimer = setTimeout(nextSlide, firstDuration);
            }
            document.addEventListener('click', () => {
                if (audioPlayer.paused && musicFiles.length > 0) audioPlayer.play();
                if (!document.fullscreenElement) { if (docElm.requestFullscreen) docElm.requestFullscreen(); }
            }, { once: true });
        }

        function nextSlide() {
            clearTimeout(slideTimer);
            slides[currentSlide].classList.remove('active');
            currentSlide = (currentSlide + 1) % slides.length;
            slides[currentSlide].classList.add('active');
            const duration = parseInt(slides[currentSlide].getAttribute('data-duration'));
            slideTimer = setTimeout(nextSlide, duration);
        }

        function playNextTrack() {
            if (musicFiles.length === 0) return;
            audioPlayer.src = musicFiles[currentTrack];
            audioPlayer.load();
            audioPlayer.play().catch(e => {});
            currentTrack = (currentTrack + 1) % musicFiles.length;
        }

        audioPlayer.onended = playNextTrack;
    </script>
</body>
</html>
