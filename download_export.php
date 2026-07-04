<?php
// download_export.php
// Receives selected photos and messages, creates a ZIP with originals and a plain text file of messages (no external libraries)

require_once __DIR__ . '/private/config.php';

// Alleen toegankelijk voor familie en beheerders (dezelfde pagina's die hier naartoe linken)
if (!isset($_SESSION["family_access"]) && !isset($_SESSION["admin_logged_in"])) {
    header("Location: index");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(t('download_export_method_not_allowed'));
}

csrf_verify();

// Verzamel geselecteerde id's
$photo_ids = $_POST['download_photos'] ?? [];
$music_ids = $_POST['download_music'] ?? [];
$message_ids = $_POST['download_messages'] ?? [];
$family_photo_paths = $_POST['download_family_photos'] ?? [];
$download_folder = $_POST['download_folder'] ?? null;

// If downloading entire folder, get all files from that folder
if ($download_folder) {
    $download_folder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $download_folder);
    $folder_path = realpath(__DIR__ . '/uploads/family_photos/' . $download_folder);
    $base_path = realpath(__DIR__ . '/uploads/family_photos');
    
    // Verify path is safe
    if ($folder_path && $base_path && strpos($folder_path, $base_path) === 0 && is_dir($folder_path)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder_path));
        foreach ($files as $file) {
            if ($file->isFile() && strpos($file->getFilename(), '.owner.txt') === false) {
                $real_path = $file->getRealPath();
                $family_photo_paths[] = $real_path;
            }
        }
    }
}

$zip = new ZipArchive();
$tmp_zip = tempnam(sys_get_temp_dir(), 'export_') . '.zip';
if ($zip->open($tmp_zip, ZipArchive::CREATE) !== TRUE) {
    exit(t('download_export_zip_error'));
}

// Voeg slideshow foto's toe
if (!empty($photo_ids)) {
    $in = str_repeat('?,', count($photo_ids) - 1) . '?';
    $stmt = $conn->prepare("SELECT file_path, uploader_name FROM media WHERE id IN ($in) AND file_type = 'photo'");
    $stmt->execute($photo_ids);
    foreach ($stmt->fetchAll() as $row) {
        $abs_path = __DIR__ . '/' . $row['file_path'];
        if (file_exists($abs_path)) {
            $zip->addFile($abs_path, 'Slideshow_Fotos/' . basename($row['file_path']));
        }
    }
}

// Voeg familie-foto's toe (van filesystem, niet database)
if (!empty($family_photo_paths)) {
    foreach ($family_photo_paths as $filePath) {
        // Check if it's an absolute path (from folder download) or relative (from individual selection)
        if (!file_exists($filePath)) {
            // Try as relative path
            $filePath = __DIR__ . '/' . str_replace('\\', '/', $filePath);
        }
        
        // Sanitize path to prevent directory traversal
        $filePath = str_replace(['\\\\', '..'], ['/', ''], $filePath);
        $abs_path = realpath($filePath);
        $base_path = realpath(__DIR__ . '/uploads/family_photos');
        
        // Only add if file exists and is within uploads/family_photos
        if ($abs_path && $base_path && file_exists($abs_path) && strpos($abs_path, $base_path) === 0) {
            $zip->addFile($abs_path, 'Familie_Fotos/' . basename($abs_path));
        }
    }
}

// Voeg muziek toe
if (!empty($music_ids)) {
    $in = str_repeat('?,', count($music_ids) - 1) . '?';
    $stmt = $conn->prepare("SELECT file_path, uploader_name FROM media WHERE id IN ($in) AND file_type = 'music'");
    $stmt->execute($music_ids);
    foreach ($stmt->fetchAll() as $row) {
        $abs_path = __DIR__ . '/' . $row['file_path'];
        if (file_exists($abs_path)) {
            $zip->addFile($abs_path, 'Muziek/' . basename($row['file_path']));
        }
    }
}

// Voeg boodschappen toe als tekstbestand (ONLY if there are message selections)
$txt_tmp = null;
if (!empty($message_ids)) {
    $txt = "==============================\r\n";
    $txt .= "        Gedeelde Woorden       \r\n";
    $txt .= "==============================\r\n\r\n";
    $in = str_repeat('?,', count($message_ids) - 1) . '?';
    $stmt = $conn->prepare("SELECT message_text, uploader_name FROM media WHERE id IN ($in) AND file_type = 'message'");
    $stmt->execute($message_ids);
    foreach ($stmt->fetchAll() as $row) {
        $txt .= str_repeat('-', 40) . "\r\n";
        $txt .= '"' . $row['message_text'] . '"' . "\r\n";
        $txt .= "  -- " . $row['uploader_name'] . "\r\n";
    }
    $txt .= str_repeat('=', 30) . "\r\n";
    $txt_tmp = tempnam(sys_get_temp_dir(), 'messages_') . '.txt';
    file_put_contents($txt_tmp, $txt);
    $zip->addFile($txt_tmp, 'Gedeelde_Woorden.txt');
}

$zip->close();
$zip_size = filesize($tmp_zip);

// Clear selections after successful download
if (isset($_SESSION['family_photo_selections'])) {
    $_SESSION['family_photo_selections'] = [];
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="export_memoriaal_' . date('Ymd_His') . '.zip"');
header('Content-Length: ' . $zip_size);
readfile($tmp_zip);

// Cleanup
@unlink($tmp_zip);
if ($txt_tmp) {
    @unlink($txt_tmp);
}
exit;
