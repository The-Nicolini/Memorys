<?php
// family_overview.php
require_once __DIR__ . '/private/config.php';

// Helper function to recursively delete directory
function deleteDirectory($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($dir);
}

// Define family base directory early (needed by handlers below)
$family_base_dir = __DIR__ . '/uploads/family_photos/';
if (!is_dir($family_base_dir)) {
    mkdir($family_base_dir, 0777, true);
}

// Alleen toegankelijk voor familie (of admin)
if (!isset($_SESSION["family_access"]) && !isset($_SESSION["admin_logged_in"])) {
    header("Location: index");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_verify();
}

// Familieleden mogen alleen foto's aan de slideshow toevoegen als de admin dat toestaat
$can_add_slideshow_photos = true;
if (isset($_SESSION["family_access"]) && !isset($_SESSION["admin_logged_in"])) {
    $setting_val = $conn->query("SELECT value FROM settings WHERE setting_key = 'family_can_add_slideshow_photos'")->fetchColumn();
    $can_add_slideshow_photos = ($setting_val ?? '1') == '1';
}

// Initialize session array for persistent selections
if (!isset($_SESSION['family_photo_selections'])) {
    $_SESSION['family_photo_selections'] = [];
}

// Handle clearing selections
if ($_GET['clear'] ?? false) {
    $_SESSION['family_photo_selections'] = [];
}

// Handle getting total count (for page load)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['get_count'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        exit(json_encode(['success' => true, 'count' => count($_SESSION['family_photo_selections'])]));
    }
}

// Handle adding family photos to slideshow
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_to_slideshow']) && $can_add_slideshow_photos) {
    $family_photos_to_add = $_POST['add_to_slideshow_photos'] ?? [];

    if (!empty($family_photos_to_add)) {
        $uploader = $_SESSION['uploader_name'] ?? $_SESSION['family_access'] ?? t('family_member_fallback');
        
        foreach ($family_photos_to_add as $photoPath) {
            // Sanitize path
            $photoPath = str_replace(['\\\\', '..'], ['/', ''], $photoPath);
            
            // Verify path is within family_photos folder
            $abs_path = __DIR__ . '/' . $photoPath;
            if (file_exists($abs_path) && strpos(realpath($abs_path), realpath($family_base_dir)) === 0) {
                // Add to media table
                $stmt = $conn->prepare("INSERT INTO media (file_type, file_path, uploader_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute(['photo', $photoPath, $uploader]);
            }
        }
        
        $upload_message = sprintf(t('family_added_to_slideshow'), count($family_photos_to_add));
    }
}

// Handle getting total count (for page load)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['get_count'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        exit(json_encode(['success' => true, 'count' => count($_SESSION['family_photo_selections'])]));
    }
}

// Handle updating selections from AJAX form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_selections'])) {
    // MERGE new selections with existing ones (don't replace)
    $newSelections = $_POST['download_family_photos'] ?? [];
    $existingSelections = $_SESSION['family_photo_selections'] ?? [];
    
    // Combine and remove duplicates
    $_SESSION['family_photo_selections'] = array_unique(array_merge($existingSelections, $newSelections));
    
    // Force session save
    session_write_close();
    // Exit early for AJAX requests to avoid processing the rest of the page
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        exit(json_encode(['success' => true, 'count' => count($_SESSION['family_photo_selections']), 'selections' => $_SESSION['family_photo_selections']]));
    }
}

// Handle folder rename
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rename_folder'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $old_path = $_POST['old_folder_path'] ?? '';
        $new_name = $_POST['new_folder_name'] ?? '';
        
        // Security checks
        $old_path = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $old_path);
        $new_name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $new_name);
        
        if (!$old_path || !$new_name) {
            exit(json_encode(['success' => false, 'error' => t('family_invalid_folder_name')]));
        }
        
        $old_full_path = $family_base_dir . $old_path;
        $parent_dir = dirname($old_full_path);
        $new_full_path = $parent_dir . '/' . $new_name;
        
        // Verify path is safe
        if (realpath($old_full_path) && strpos(realpath($old_full_path), realpath($family_base_dir)) === 0 && is_dir($old_full_path)) {
            if (rename($old_full_path, $new_full_path)) {
                exit(json_encode(['success' => true, 'message' => t('family_folder_renamed')]));
            }
        }
        exit(json_encode(['success' => false, 'error' => t('family_rename_error')]));
    }
}

// Handle folder delete
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_folder'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        $folder_path = $_POST['folder_path'] ?? '';
        $folder_path = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder_path);
        
        if (!$folder_path) {
            exit(json_encode(['success' => false, 'error' => t('family_invalid_folder_name')]));
        }
        
        // Prevent deletion of slideshow folder
        if ($folder_path === 'slideshow') {
            exit(json_encode(['success' => false, 'error' => t('family_cannot_delete_slideshow')]));
        }
        
        $full_path = $family_base_dir . $folder_path;
        $base_real = realpath($family_base_dir);
        $path_real = realpath($full_path);
        
        // Verify path is safe and is a directory - improved checks
        if (!$path_real || !$base_real || strpos($path_real, $base_real) !== 0 || !is_dir($full_path)) {
            exit(json_encode(['success' => false, 'error' => t('family_folder_not_found')]));
        }
        
        // Check if user is owner of this folder
        $owner_file = $full_path . DIRECTORY_SEPARATOR . '.owner.txt';
        $current_user = $_SESSION["username"] ?? t('family_member_fallback');
        
        $folder_owner = '';
        if (file_exists($owner_file)) {
            $folder_owner = trim(file_get_contents($owner_file));
        }
        
        // Only allow deletion if user is the owner or is admin
        if ($folder_owner !== $current_user && !isset($_SESSION["admin_logged_in"])) {
            exit(json_encode(['success' => false, 'error' => t('family_only_own_folders_delete')]));
        }
        
        // First, remove any photos from this folder that are in the slideshow
        $folder_prefix = 'uploads/family_photos/' . $folder_path . '/';
        $stmt = $conn->prepare("DELETE FROM media WHERE file_type = 'photo' AND file_path LIKE ?");
        $stmt->execute([$folder_prefix . '%']);
        
        // Recursively delete directory
        $success = deleteDirectory($full_path);
        if ($success) {
            exit(json_encode(['success' => true, 'message' => t('family_folder_deleted')]));
        } else {
            exit(json_encode(['success' => false, 'error' => t('family_folder_delete_failed')]));
        }
    }
}

// Handle folder download
if ($_POST['download_folder'] ?? false) {
    $folder_path = $_POST['download_folder'];
    $folder_path = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder_path);
    
    if (!$folder_path) {
        die(t('family_invalid_folder_name'));
    }
    
    $full_path = __DIR__ . '/uploads/family_photos/' . $folder_path;
    
    // Verify path is safe
    if (!realpath($full_path) || strpos(realpath($full_path), realpath(__DIR__ . '/uploads/family_photos')) !== 0 || !is_dir($full_path)) {
        die(t('family_folder_not_found'));
    }
    
    // Forward to download_export with folder list as hidden inputs
    $folderName = basename($full_path);
    ?>
    <form id="folderDownloadForm" action="download_export" method="post" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="download_folder" value="<?php echo htmlspecialchars($folder_path); ?>">
    </form>
    <script>
        document.getElementById('folderDownloadForm').submit();
    </script>
    <?php
    exit;
}

$photos = $conn->query("SELECT * FROM media WHERE file_type = 'photo' ORDER BY created_at DESC")->fetchAll();
$music = $conn->query("SELECT * FROM media WHERE file_type = 'music' ORDER BY created_at DESC")->fetchAll();
$messages = $conn->query("SELECT * FROM media WHERE file_type = 'message' ORDER BY created_at DESC")->fetchAll();

// Map structure based on URL parameter or root
$current_folder = $_POST["current_folder"] ?? $_GET['folder'] ?? '';
$safe_folder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $current_folder);

// Build target path - realpath won't work for non-existent dirs, so we build it manually
$requested_path = $family_base_dir . $safe_folder;
$target_path = $requested_path;
$base_real = realpath($family_base_dir);

// Normalize the paths for comparison
$target_real = realpath($target_path);
if (!$target_real && is_dir($target_path)) {
    $target_real = $target_path;
} elseif (!$target_real) {
    // If path doesn't exist, verify it's safe by checking parent
    $target_real = str_replace('\\', '/', $target_path);
    $base_real = str_replace('\\', '/', $base_real);
    if (strpos($target_real, $base_real) !== 0) {
        $target_path = $family_base_dir;
        $safe_folder = '';
    }
} else {
    $target_path = $target_real;
}

// Final security check
if (!$target_path || !is_dir($family_base_dir)) {
    $target_path = realpath($family_base_dir);
    $safe_folder = '';
}

$upload_message = "";

// Folder creation logic
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["new_folder_name"])) {
    $new_folder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($_POST["new_folder_name"]));
    
    if (!empty($new_folder)) {
        $new_path = $target_path . DIRECTORY_SEPARATOR . $new_folder;
        
        if (!is_dir($new_path)) {
            // Ensure parent directory exists
            if (!is_dir($target_path)) {
                mkdir($target_path, 0777, true);
            }
            if (mkdir($new_path, 0777, true)) {
                // Write owner file to track who created this folder
                $owner_name = $_SESSION["username"] ?? t('family_member_fallback');
                $owner_file = $new_path . DIRECTORY_SEPARATOR . '.owner.txt';
                file_put_contents($owner_file, $owner_name);
                
                $upload_message = t('family_folder_created_prefix') . htmlspecialchars($new_folder);
            } else {
                $upload_message = t('family_folder_create_failed');
            }
        } else {
            $upload_message = t('family_folder_already_exists');
        }
    } else {
        $upload_message = t('family_folder_name_required');
    }
}

// Upload logic
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["family_photos"]) && !empty($_FILES["family_photos"]["name"][0])) {
    // Ensure target directory exists
    if (!is_dir($target_path)) {
        mkdir($target_path, 0777, true);
    }
    
    $upload_count = 0;
    $zip_extract_count = 0;
    $zip_errors = [];
    
    foreach ($_FILES["family_photos"]["tmp_name"] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;
        
        $original_name = $_FILES["family_photos"]["name"][$key];
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        
        // Check if it's a ZIP file
        if ($file_ext === 'zip') {
            // Handle ZIP extraction
            $temp_zip_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'family_' . time() . '_' . md5($original_name) . '.zip';
            
            if (move_uploaded_file($tmp_name, $temp_zip_path)) {
                $zip = new ZipArchive();
                if ($zip->open($temp_zip_path) === TRUE) {
                    // Extract all files
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        $zip_file_name = $stat['name'];
                        
                        // Skip directories and hidden files
                        if (substr($zip_file_name, -1) === '/') continue;
                        if (basename($zip_file_name)[0] === '.') continue;
                        
                        // Check if it's an image
                        $file_ext = strtolower(pathinfo($zip_file_name, PATHINFO_EXTENSION));
                        if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                            $extracted_name = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.]/', '_', basename($zip_file_name));
                            $extract_to = $target_path . DIRECTORY_SEPARATOR . $extracted_name;
                            
                            // Read file content from ZIP and write to destination
                            $content = $zip->getFromIndex($i);
                            if ($content !== false && file_put_contents($extract_to, $content)) {
                                $zip_extract_count++;
                            }
                        }
                    }
                    $zip->close();
                } else {
                    $zip_errors[] = t('family_zip_open_failed') . htmlspecialchars($original_name);
                }
                @unlink($temp_zip_path);
            } else {
                $zip_errors[] = t('family_zip_upload_failed') . htmlspecialchars($original_name);
            }
        } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            // Handle regular image upload
            $file_name = time() . "_" . preg_replace('/[^a-zA-Z0-9_\.]/', '_', basename($original_name));
            $dest = $target_path . DIRECTORY_SEPARATOR . $file_name;
            if (move_uploaded_file($tmp_name, $dest)) {
                $upload_count++;
            }
        }
    }
    
    // Build message
    if ($upload_count > 0 || $zip_extract_count > 0) {
        $parts = [];
        if ($upload_count > 0) $parts[] = $upload_count . " " . t('family_photos_suffix');
        if ($zip_extract_count > 0) $parts[] = $zip_extract_count . " " . t('family_from_zip_suffix');
        $upload_message = t('family_upload_success_prefix') . implode(" + ", $parts) . "!";
        if (!empty($zip_errors)) {
            $upload_message .= t('family_errors_prefix') . implode("; ", $zip_errors);
        }
    } else {
        $upload_message = t('family_upload_failed');
        if (!empty($zip_errors)) {
            $upload_message = implode(" ", $zip_errors);
        }
    }
}

// Get family photos and subfolders for selected path
$items = ['folders' => [], 'photos' => []];

// Check if viewing the special "slideshow" folder
if ($safe_folder === 'slideshow') {
    // Show slideshow photos from database
    foreach ($photos as $p) {
        $items['photos'][] = [
            'name' => basename($p['file_path']),
            'path' => $p['file_path'],
            'id' => $p['id'],  // Add database ID for downloads
            'uploader' => $p['uploader_name'] ?? t('family_unknown_uploader')
        ];
    }
} else {
    // Normal filesystem browsing
    if (is_dir($target_path)) {
        foreach (scandir($target_path) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') continue;
            $full_entry_path = $target_path . DIRECTORY_SEPARATOR . $entry;
            $relative_entry_path = ($safe_folder ? $safe_folder . '/' : '') . $entry;
            
            if (is_dir($full_entry_path)) {
                $items['folders'][] = $entry;
            } else {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $items['photos'][] = [
                        'name' => $entry,
                        'path' => 'uploads/family_photos/' . $relative_entry_path
                    ];
                }
            }
        }
    }
    
    // Always show "slideshow" folder as an option in root directory
    if (empty($safe_folder)) {
        $items['folders'][] = 'slideshow';
    }
}

// Kleurenschema van de site ophalen voor de stijl van deze pagina
$site_primary = $conn->query("SELECT value FROM settings WHERE setting_key = 'site_primary_color'")->fetchColumn() ?: '#d4a373';
$site_primary_hover = $conn->query("SELECT value FROM settings WHERE setting_key = 'site_primary_hover_color'")->fetchColumn() ?: '#bc8a5f';
$site_primary_dark = $conn->query("SELECT value FROM settings WHERE setting_key = 'site_primary_dark_color'")->fetchColumn() ?: '#8b4513';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(t('html_lang')); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(t('family_title')); ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #f8f9fa; }
        .family-container { max-width: 1200px; margin: 40px auto; background: #fff; border-radius: 20px; box-shadow: 0 8px 32px rgba(0,0,0,0.08); padding: 40px; }
        h1 { font-family: 'Dancing Script', cursive; font-size: 2.8em; color: <?php echo htmlspecialchars($site_primary_dark); ?>; margin-bottom: 10px; }
        h2 { color: <?php echo htmlspecialchars($site_primary_hover); ?>; margin-top: 40px; }
        .media-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .media-grid:has(audio) { grid-template-columns: 1fr; max-width: 500px; }
        .music-grid { display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 30px; max-width: 600px; }
        .media-card { background: #fdfdfd; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 18px; text-align: center; border: 1px solid #eee; position: relative; }
        .music-card { background: #fdfdfd; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 18px; border: 1px solid #eee; text-align: left; }
        .folder-card { background: #fff8f0; border: 2px solid <?php echo htmlspecialchars($site_primary); ?>; cursor: pointer; transition: transform 0.2s; text-decoration: none; display: block; }
        .folder-card:hover { transform: scale(1.02); background: #fcefdc; }
        .folder-icon { font-size: 3em; color: <?php echo htmlspecialchars($site_primary); ?>; display: block; margin-bottom: 10px; }
        .media-card img { width: 100%; height: 160px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; }
        .media-card label { display: block; margin-top: 10px; }
        .folder-nav { margin: 20px 0; background: #eee; padding: 10px 20px; border-radius: 8px; font-size: 1.1em; color: <?php echo htmlspecialchars($site_primary_dark); ?>; }
        .folder-nav a { text-decoration: none; color: <?php echo htmlspecialchars($site_primary_hover); ?>; font-weight: bold; }
        .folder-actions { display: flex; gap: 20px; align-items: center; margin-bottom: 30px; background: #f9f9f9; padding: 20px; border-radius: 12px; border: 1px dashed #ccc; }
        .folder-actions input[type="text"] { flex: 1; padding: 10px; border-radius: 8px; border: 1px solid #ddd; }
        .music-list audio { width: 100%; margin-bottom: 10px; }
        .message-list { margin-bottom: 30px; }
        /* Collapsible styling */
        .collapsible-header { display: flex; align-items: center; gap: 15px; cursor: pointer; user-select: none; }
        .collapsible-header:hover { opacity: 0.8; }
        .toggle-icon { font-size: 1.2em; transition: transform 0.3s; }
        .toggle-icon.collapsed { transform: rotate(-90deg); }
        .collapsible-section { overflow: hidden; transition: max-height 0.3s, opacity 0.3s; }
        .collapsible-section.collapsed { max-height: 0; opacity: 0; margin: 0 !important; }
        .collapsible-section.open { overflow: visible; }
        .message-card { background: #fff8f0; border-left: 6px solid <?php echo htmlspecialchars($site_primary); ?>; border-radius: 10px; margin-bottom: 18px; padding: 18px 22px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); }
        .download-btn { background: <?php echo htmlspecialchars($site_primary_hover); ?>; color: #fff; border: none; border-radius: 8px; padding: 12px 30px; font-size: 1.1em; font-weight: bold; cursor: pointer; margin-top: 25px; transition: background 0.2s; }
        .download-btn:hover { background: <?php echo htmlspecialchars($site_primary_dark); ?>; }
        .main-btn { background: <?php echo htmlspecialchars($site_primary_hover); ?>; color: #fff; border: none; border-radius: 8px; padding: 15px 20px; font-size: 1em; font-weight: bold; cursor: pointer; transition: background 0.2s; display: flex; align-items: center; justify-content: center; height: 54px; text-decoration: none; flex: 1 1 0 !important; min-width: 0; white-space: nowrap; gap: 8px; }
        .main-btn span { display: inline; }
        button.main-btn { border: none; flex: 1 1 0 !important; }
        .main-btn:hover { background: <?php echo htmlspecialchars($site_primary_dark); ?>; }
        .bottom-buttons { margin-top: 30px; display: flex; gap: 10px; flex-wrap: nowrap; }
        @media (max-width: 600px) {
            .bottom-buttons { flex-direction: column; gap: 8px; flex-wrap: nowrap; }
            .bottom-buttons .main-btn { flex: 1 1 100% !important; white-space: normal; }
        }
        /* Toggle Switch Styling */
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 26px; }
        .toggle-switch input { display: none; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: 0.3s; border-radius: 26px; }
        .toggle-slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 2px; bottom: 2px; background-color: white; transition: 0.3s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #2d5a27; }
        input:checked + .toggle-slider:before { transform: translateX(24px); }
        .toggle-switch label { display: block; font-size: 0.9em; margin-top: 8px; color: #666; cursor: pointer; }
        /* Context Menu Styling */
        #folderContextMenu { position: fixed; background: white; border: 1px solid #ccc; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 10000; display: none; min-width: 200px; }
        .context-menu-item { padding: 12px 16px; cursor: pointer; transition: background 0.2s; font-size: 0.95em; border:none; background:white; width:100%; text-align:left; color: #333; }
        .context-menu-item:first-child { border-radius: 6px 6px 0 0; }
        .context-menu-item:last-child { border-radius: 0 0 6px 6px; }
        .context-menu-item:hover { background: #f0f0f0; }
        .context-menu-item.danger { color: #c85a54; }
        .context-menu-item.danger:hover { background: #fce8e6; }
        @media (max-width: 700px) { .family-container { padding: 10px; } }
    </style>
    <script>
        const CSRF_TOKEN = "<?php echo $_SESSION['csrf_token']; ?>";
        const FAMILY_I18N = {
            noItemsSelected: <?php echo json_encode(t('family_no_items_selected')); ?>,
            onlyOwnFoldersRename: <?php echo json_encode(t('family_only_own_folders_rename')); ?>,
            newFolderNamePrompt: <?php echo json_encode(t('family_new_folder_name_prompt')); ?>,
            folderRenamedToPrefix: <?php echo json_encode(t('family_folder_renamed_to_prefix')); ?>,
            errorPrefix: <?php echo json_encode(t('family_error_prefix')); ?>,
            unknownError: <?php echo json_encode(t('family_unknown_error')); ?>,
            renameError: <?php echo json_encode(t('family_rename_error')); ?>,
            cannotDeleteSlideshow: <?php echo json_encode(t('family_cannot_delete_slideshow')); ?>,
            onlyOwnFoldersDelete: <?php echo json_encode(t('family_only_own_folders_delete')); ?>,
            deleteFolderWarning: <?php echo json_encode(t('family_delete_folder_warning')); ?>,
            folderDeleted: <?php echo json_encode(t('family_folder_deleted')); ?>,
            deleteError: <?php echo json_encode(t('family_delete_error')); ?>,
            addToSlideshowBtn: <?php echo json_encode(t('family_add_to_slideshow_btn')); ?>,
            photosSelectedSuffix: <?php echo json_encode(t('family_photos_selected_suffix')); ?>,
            clearBtn: <?php echo json_encode(t('family_clear_btn')); ?>
        };

        // Preserve scroll position when navigating between folders
        window.addEventListener('load', function() {
            const scrollPos = sessionStorage.getItem('folderScrollPos');
            if (scrollPos !== null) {
                window.scrollTo(0, parseInt(scrollPos));
                sessionStorage.removeItem('folderScrollPos');
            }
        });
        
        // Save scroll position before navigating to another folder
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href*="family_overview"]');
            if (link && !link.href.includes('clear=1')) {
                sessionStorage.setItem('folderScrollPos', window.scrollY);
            }
        });
        
        // Auto-save selections to session when checkbox changes (using AJAX to avoid page jump)
        function updateSelections() {
            const checkboxes = document.querySelectorAll('input[name="download_family_photos[]"]');
            const selectedPhotos = Array.from(checkboxes)
                .filter(cb => cb.checked)
                .map(cb => cb.value);
            
            // Find current_folder from any form on the page
            let currentFolder = '';
            const folderInputs = document.querySelectorAll('input[name="current_folder"]');
            if (folderInputs.length > 0) {
                currentFolder = folderInputs[0].value;
            }
            
            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('update_selections', '1');
            formData.append('current_folder', currentFolder);

            selectedPhotos.forEach(photo => {
                formData.append('download_family_photos[]', photo);
            });
            
            // Send AJAX request with proper headers
            fetch('family_overview', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json().then(data => {
                // Use the total count from server, not just current page's checkboxes
                updateSelectionCounter(data.count);
                console.log('Selection saved:', data);
            }))
            .catch(err => console.error('Selection save failed:', err));
        }
        
        // Update the selection counter display
        function updateSelectionCounter(count) {
            const counterElement = document.getElementById('selectionCounterDisplay');
            const currentFolder = new URLSearchParams(window.location.search).get('folder') || '';
            const clearUrl = 'family_overview?folder=' + (currentFolder ? encodeURIComponent(currentFolder) : '') + '&clear=1';
            
            if (counterElement) {
                if (count > 0) {
                    counterElement.innerHTML = '<strong style="color: #2d5a27;">✓ ' + count + ' ' + FAMILY_I18N.photosSelectedSuffix + '</strong> <a href="' + clearUrl + '" style="color: #d9534f; text-decoration: underline; margin-left: 15px;">' + FAMILY_I18N.clearBtn + '</a>';
                    counterElement.style.display = 'block';
                } else {
                    counterElement.style.display = 'none';
                }
            }
        }
        
        // Handle download form submission - clear selections after download
        function handleDownloadSubmit(event) {
            event.preventDefault();
            const form = document.getElementById('secondaryDownloadForm');
            
            // Check if there are any items selected for download
            const hasItems = form.querySelectorAll('input[name="download_family_photos[]"]').length > 0 || 
                           form.querySelectorAll('input[name="download_photos[]"]').length > 0 ||
                           form.querySelectorAll('input[name="download_music[]"]').length > 0 ||
                           form.querySelectorAll('input[name="download_messages[]"]').length > 0;
            
            if (!hasItems) {
                alert(FAMILY_I18N.noItemsSelected);
                return;
            }
            
            // Submit the form for download
            form.submit();
            
            // After a short delay (to allow download to start), reload page and clear selections
            setTimeout(() => {
                const currentFolder = new URLSearchParams(window.location.search).get('folder') || '';
                window.location.href = 'family_overview?folder=' + (currentFolder ? encodeURIComponent(currentFolder) : '') + '&clear=1';
            }, 2000);
        }
        
        // Handle slideshow photo selection
        function updateSlideshowPhotos() {
            const checkboxes = document.querySelectorAll('input[name="add_to_slideshow_photos[]"]:checked');
            const container = document.getElementById('slideshowPhotosContainer');
            const btn = document.getElementById('slideshowBtn');
            if (!container || !btn) return;

            // Clear existing inputs
            container.innerHTML = '';
            
            // Add hidden inputs for each selected photo
            checkboxes.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'add_to_slideshow_photos[]';
                input.value = cb.value;
                container.appendChild(input);
            });
            
            // Show/hide button based on selections
            if (checkboxes.length > 0) {
                btn.textContent = FAMILY_I18N.addToSlideshowBtn.replace('%d', checkboxes.length);
                btn.style.display = 'inline-block';
            } else {
                btn.style.display = 'none';
            }
        }
        
        // Initialize slideshow handler on page load
        window.addEventListener('load', function() {
            document.addEventListener('change', function(e) {
                if (e.target.name === 'add_to_slideshow_photos[]') {
                    updateSlideshowPhotos();
                }
            });
        });
        
        // Initialize counter on page load - fetch total from server
        window.addEventListener('load', function() {
            // Get current folder
            let currentFolder = '';
            const folderInputs = document.querySelectorAll('input[name="current_folder"]');
            if (folderInputs.length > 0) {
                currentFolder = folderInputs[0].value;
            }
            
            // Fetch the total count from server
            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('get_count', '1');
            formData.append('current_folder', currentFolder);

            fetch('family_overview', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json().then(data => {
                updateSelectionCounter(data.count);
            }))
            .catch(err => {
                // Fallback: just count the checkboxes on this page if fetch fails
                const checkboxes = document.querySelectorAll('input[name="download_family_photos[]"]:checked');
                updateSelectionCounter(checkboxes.length);
            });
        });

        // Context Menu for Folders
        let currentContextFolder = null;
        let touchStartTime = 0;
        const currentUser = "<?php echo htmlspecialchars($_SESSION['username'] ?? t('family_member_fallback')); ?>";

        document.addEventListener('DOMContentLoaded', function() {
            const folderCards = document.querySelectorAll('.folder-card');
            
            folderCards.forEach(card => {
                const folderName = card.dataset.folderName;
                
                // Skip context menu for slideshow folder
                if (folderName === 'slideshow') return;
                
                // Rechtsmuisklik (Desktop)
                card.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    currentContextFolder = {
                        name: this.dataset.folderName,
                        path: this.dataset.folderPath,
                        owner: this.dataset.folderOwner
                    };
                    showContextMenu(e.clientX, e.clientY);
                });

                // Long press (Mobile)
                card.addEventListener('touchstart', function(e) {
                    touchStartTime = Date.now();
                });

                card.addEventListener('touchend', function(e) {
                    const touchDuration = Date.now() - touchStartTime;
                    if (touchDuration > 500) { // Long press detection
                        e.preventDefault();
                        currentContextFolder = {
                            name: this.dataset.folderName,
                            path: this.dataset.folderPath,
                            owner: this.dataset.folderOwner
                        };
                        const touch = e.changedTouches[0];
                        showContextMenu(touch.clientX, touch.clientY);
                    }
                });
            });

            // Close context menu when clicking elsewhere
            document.addEventListener('click', hideContextMenu);
        });

        function showContextMenu(x, y) {
            const menu = document.getElementById('folderContextMenu');
            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
            menu.style.display = 'block';
        }

        function hideContextMenu() {
            document.getElementById('folderContextMenu').style.display = 'none';
        }

        function renameFolder() {
            if (!currentContextFolder) return;
            
            // Check if user is owner
            if (currentContextFolder.owner !== currentUser) {
                alert(FAMILY_I18N.onlyOwnFoldersRename);
                hideContextMenu();
                return;
            }

            const newName = prompt(FAMILY_I18N.newFolderNamePrompt, currentContextFolder.name);
            if (newName && newName.trim()) {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('rename_folder', '1');
                formData.append('old_folder_path', currentContextFolder.path);
                formData.append('new_folder_name', newName.trim());

                fetch('family_overview', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(FAMILY_I18N.folderRenamedToPrefix + newName);
                        location.reload();
                    } else {
                        alert(FAMILY_I18N.errorPrefix + (data.error || FAMILY_I18N.unknownError));
                    }
                })
                .catch(err => {
                    alert(FAMILY_I18N.renameError);
                    console.error(err);
                });
            }
            hideContextMenu();
        }

        function deleteFolder() {
            if (!currentContextFolder) return;

            // Prevent deletion of slideshow folder
            if (currentContextFolder.name === 'slideshow' || currentContextFolder.path === 'slideshow') {
                alert(FAMILY_I18N.cannotDeleteSlideshow);
                hideContextMenu();
                return;
            }

            // Check if user is owner
            if (currentContextFolder.owner !== currentUser) {
                alert(FAMILY_I18N.onlyOwnFoldersDelete);
                hideContextMenu();
                return;
            }

            // Custom larger warning dialog
            const warningMessage = FAMILY_I18N.deleteFolderWarning.replace('%s', currentContextFolder.name);

            if (!confirm(warningMessage)) {
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('delete_folder', '1');
            formData.append('folder_path', currentContextFolder.path);

            fetch('family_overview', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(FAMILY_I18N.folderDeleted);
                    location.reload();
                } else {
                    alert(FAMILY_I18N.errorPrefix + (data.error || FAMILY_I18N.unknownError));
                }
            })
            .catch(err => {
                alert(FAMILY_I18N.deleteError);
                console.error(err);
            });
            hideContextMenu();
        }

        function downloadFolder() {
            if (!currentContextFolder) return;
            
            const formData = new FormData();
            formData.append('download_folder', '1');
            formData.append('folder_path', currentContextFolder.path);
            
            // Create a temporary form and submit it
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_export';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'download_folder';
            input.value = currentContextFolder.path;

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = CSRF_TOKEN;

            form.appendChild(input);
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            
            hideContextMenu();
        }

        function toggleSection(sectionId, headerElement) {
            const section = document.getElementById(sectionId);
            const icon = headerElement.querySelector('.toggle-icon');
            
            section.classList.toggle('collapsed');
            section.classList.toggle('open');
            icon.classList.toggle('collapsed');
            
            // Store preference in sessionStorage
            const isCollapsed = section.classList.contains('collapsed');
            sessionStorage.setItem('section_' + sectionId, isCollapsed ? 'collapsed' : 'open');
        }

        // Restore section states on page load
        window.addEventListener('load', function() {
            ['music-section', 'messages-section'].forEach(sectionId => {
                const state = sessionStorage.getItem('section_' + sectionId);
                const section = document.getElementById(sectionId);
                if (section && state === 'collapsed') {
                    section.classList.add('collapsed');
                    section.classList.remove('open');
                    const header = section.previousElementSibling;
                    if (header) {
                        const icon = header.querySelector('.toggle-icon');
                        if (icon) icon.classList.add('collapsed');
                    }
                }
            });
        });
    </script>
</head>
<body>
    <div class="family-container">
        <h1><?php echo htmlspecialchars(t('family_heading')); ?></h1>
        <div style="background:#fff3cd; border:1px solid #ffeeba; border-radius:12px; padding:18px 22px; margin-bottom:30px; color:#856404; font-size:1.08em;">
            <strong><?php echo htmlspecialchars(t('family_welcome_heading')); ?></strong><br>
            <?php echo htmlspecialchars(t('family_intro_text')); ?><br><br>
            <ul style="margin:0 0 0 18px; padding:0;">
                <li><?php echo t('family_instructions_li1'); ?></li>
                <li><?php echo htmlspecialchars(t('family_instructions_li2')); ?></li>
                <li><?php echo htmlspecialchars(t('family_instructions_li3')); ?></li>
                <li><?php echo htmlspecialchars(t('family_instructions_li4')); ?></li>
                <li><?php echo htmlspecialchars(t('family_instructions_li5')); ?></li>
            </ul>
            <br><?php echo htmlspecialchars(t('family_contact_admin_notice')); ?>
        </div>

        <?php if ($upload_message): ?>
            <div class="alert success" style="background:#f0f7f0; color:#2d5a27; border:1px solid #d4ebd0; padding:18px 22px; border-radius:12px; margin-bottom:30px;">
                <?php echo htmlspecialchars($upload_message); ?>
            </div>
        <?php endif; ?>

        <!-- Folder Navigation and Content Section -->
        <h2 style="margin-top:20px;"><?php echo htmlspecialchars(t('family_photos_section_heading')); ?></h2>
        <div class="folder-nav" style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <?php echo htmlspecialchars(t('family_location_label')); ?> <a href="family_overview"><?php echo htmlspecialchars(t('family_root_label')); ?></a>
                <?php 
                $parts = explode('/', $safe_folder);
                $build_path = '';
                foreach ($parts as $p) {
                    if (empty($p)) continue;
                    $build_path .= ($build_path ? '/' : '') . $p;
                    echo ' &raquo; <a href="family_overview?folder='.urlencode($build_path).'">'.htmlspecialchars($p).'</a>';
                }
                ?>
            </div>
            <?php 
            // Back button - show if not in root
            if (!empty($safe_folder)) {
                $parent_path = implode('/', array_slice($parts, 0, -1));
                echo '<a href="family_overview' . ($parent_path ? '?folder=' . urlencode($parent_path) : '') . '" style="color: ' . htmlspecialchars($site_primary_hover) . '; text-decoration: none; font-weight: bold; margin-left: 20px; padding: 4px 12px; background: #fff8f0; border-radius: 6px; border: 1px solid ' . htmlspecialchars($site_primary) . '; transition: all 0.2s;" onmouseover="this.style.background=\'#fcefdc\'" onmouseout="this.style.background=\'#fff8f0\'">' . htmlspecialchars(t('family_back_button')) . '</a>';
            }
            ?>
        </div>

        <!-- Hidden form for saving selections to session -->
        <form id="selectionsForm" action="family_overview" method="post" style="display:none;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="current_folder" value="<?php echo htmlspecialchars($safe_folder); ?>">
            <input type="hidden" name="update_selections" value="1">
        </form>

        <!-- Management tools for currently open folder (hidden for slideshow) -->
        <?php if ($safe_folder !== 'slideshow'): ?>
        <form action="family_overview" method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="current_folder" value="<?php echo htmlspecialchars($safe_folder); ?>">
            <div class="folder-actions">
                <div style="flex: 2;">
                    <strong><?php echo htmlspecialchars(t('family_add_files_label')); ?></strong><br>
                    <div style="margin-top: 5px;">
                        <input type="file" name="family_photos[]" id="file_input" multiple accept="image/*,.zip" style="padding: 5px;">
                        <button type="submit" name="upload_files" class="main-btn" style="padding: 8px 15px; font-size: 0.9em;"><?php echo htmlspecialchars(t('family_upload_btn')); ?></button>
                        <small style="display: block; margin-top: 5px; color: #666;"><?php echo htmlspecialchars(t('family_upload_hint')); ?></small>
                    </div>
                </div>
                <div style="flex: 1; border-left: 1px solid #ddd; padding-left: 20px;">
                    <strong><?php echo htmlspecialchars(t('family_new_subfolder_label')); ?></strong><br>
                    <div style="display: flex; gap: 5px; margin-top: 5px;">
                        <input type="text" name="new_folder_name" placeholder="<?php echo htmlspecialchars(t('family_name_placeholder')); ?>">
                        <button type="submit" name="create_folder" class="main-btn" style="padding: 8px 15px; font-size: 0.9em;"><?php echo htmlspecialchars(t('family_create_btn')); ?></button>
                    </div>
                </div>
            </div>
        </form>
        <?php else: ?>
        <div style="background:#e8f4f8; border:1px solid #b3d9e8; border-radius:8px; padding:15px 20px; margin-bottom:25px; color:#0c5460;">
            <strong><?php echo htmlspecialchars(t('family_slideshow_folder_readonly_label')); ?></strong> <?php echo htmlspecialchars(t('family_slideshow_folder_readonly_text')); ?>
        </div>
        <?php endif; ?>

        <form id="secondaryDownloadForm" action="download_export" method="post" style="margin-bottom:0;">
            <?php echo csrf_field(); ?>
            <!-- Hidden inputs for all selected family photos from session -->
            <?php foreach ($_SESSION['family_photo_selections'] as $photoPath): ?>
                <input type="hidden" name="download_family_photos[]" value="<?php echo htmlspecialchars($photoPath); ?>">
            <?php endforeach; ?>
            
            <!-- Form for adding to slideshow -->
            <input type="hidden" name="folder_context" value="<?php echo htmlspecialchars($safe_folder); ?>">
            
            <div class="media-grid">
                <!-- Folders first -->
                <?php if (!empty($items['folders'])): ?>
                    <?php foreach ($items['folders'] as $folderName): 
                        $new_f_path = ($safe_folder ? $safe_folder . '/' : '') . $folderName;
                        
                        // Get folder owner
                        $folder_full_path = $target_path . DIRECTORY_SEPARATOR . $folderName;
                        $owner_file = $folder_full_path . DIRECTORY_SEPARATOR . '.owner.txt';
                        $folder_owner = t('family_member_fallback');
                        if (file_exists($owner_file)) {
                            $folder_owner = trim(file_get_contents($owner_file));
                        }
                    ?>
                        <div class="media-card folder-card" data-folder-name="<?php echo htmlspecialchars($folderName); ?>" data-folder-path="<?php echo htmlspecialchars($new_f_path); ?>" data-folder-owner="<?php echo htmlspecialchars($folder_owner); ?>" onclick="window.location.href='family_overview?folder=<?php echo urlencode($new_f_path); ?>';" style="cursor:pointer;">
                            <span class="folder-icon">📁</span>
                            <strong style="color: <?php echo htmlspecialchars($site_primary_dark); ?>;"><?php echo htmlspecialchars($folderName); ?></strong>
                            <?php if ($folder_owner !== t('family_member_fallback')): ?>
                                <div style="font-size: 0.75em; color: #999; margin-top: 4px;"><?php echo htmlspecialchars(t('family_uploaded_by_label')); ?> <?php echo htmlspecialchars($folder_owner); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Photos next -->
                <?php if (!empty($items['photos'])): ?>
                    <?php foreach ($items['photos'] as $fp): ?>
                        <div class="media-card">
                            <img src="<?php echo htmlspecialchars($fp['path']); ?>" alt="<?php echo htmlspecialchars(t('family_private_photo_alt')); ?>">
                            <?php if (isset($fp['uploader'])): ?>
                                <div style="font-size:0.85em; color:#666; margin:6px 0;"><?php echo htmlspecialchars(t('family_uploaded_by_label')); ?> <?php echo htmlspecialchars($fp['uploader']); ?></div>
                            <?php endif; ?>
                            <div style="display: flex; gap: 15px; align-items: flex-start; margin-top: 10px;">
                                <?php
                                // For slideshow photos (have 'id'), use download_photos[] with ID
                                // For family photos (no 'id'), use download_family_photos[] with path
                                if (isset($fp['id'])): ?>
                                    <label style="display:flex; align-items:center; gap:6px; flex:1;"><input type="checkbox" name="download_photos[]" value="<?php echo $fp['id']; ?>"> <?php echo htmlspecialchars(t('family_download_label')); ?></label>
                                <?php else: ?>
                                    <label style="display:flex; align-items:center; gap:6px; flex:1;"><input type="checkbox" name="download_family_photos[]" value="<?php echo htmlspecialchars($fp['path']); ?>" <?php echo in_array($fp['path'], $_SESSION['family_photo_selections']) ? 'checked' : ''; ?> onchange="updateSelections();"> <?php echo htmlspecialchars(t('family_download_label')); ?></label>
                                <?php endif; ?>
                                <!-- Only show slideshow toggle if NOT in slideshow folder and family is allowed to add -->
                                <?php if ($safe_folder !== 'slideshow' && $can_add_slideshow_photos): ?>
                                    <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="add_to_slideshow_photos[]" value="<?php echo htmlspecialchars($fp['path']); ?>" onchange="updateSlideshowPhotos();">
                                            <span class="toggle-slider"></span>
                                        </label>
                                        <span style="font-size:0.8em; color:#666;"><?php echo htmlspecialchars(t('family_slideshow_toggle_label')); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($items['folders']) && empty($items['photos'])): ?>
                    <div style="grid-column: 1 / -1; text-align: center; color: #888; font-style: italic; padding: 40px;">
                        <?php echo htmlspecialchars(t('family_folder_empty_notice')); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Selection Counter -->
            <?php $selection_count = count($_SESSION['family_photo_selections']); ?>
            <div id="selectionCounterDisplay" style="background: #f0f7f0; border-left: 4px solid #2d5a27; padding: 15px 20px; margin: 25px 0; border-radius: 4px; <?php echo $selection_count > 0 ? '' : 'display: none;'; ?>">
                <strong style="color: #2d5a27;">✓ <?php echo $selection_count; ?> <?php echo htmlspecialchars(t('family_photos_selected_suffix')); ?></strong>
                <a href="family_overview?folder=<?php echo urlencode($safe_folder); ?>&clear=1" style="color: #d9534f; text-decoration: underline; margin-left: 15px;"><?php echo htmlspecialchars(t('family_clear_btn')); ?></a>
            </div>

            <h2 style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-top: 30px;" onclick="toggleSection('music-section', this)">
                <span class="toggle-icon collapsed" style="font-size: 0.9em;">▼</span>
                <span><?php echo htmlspecialchars(t('family_music_heading')); ?></span>
                <span style="font-size: 0.85em; color: #999;">(<?php echo count($music); ?>)</span>
            </h2>
            <div class="music-grid collapsible-section collapsed" id="music-section">
                <?php foreach ($music as $m): ?>
                <div class="music-card">
                    <div style="font-size:0.95em; color:#666; margin-bottom:8px;"><strong><?php echo htmlspecialchars(t('family_uploaded_by_label')); ?></strong> <?php echo htmlspecialchars($m['uploader_name']); ?></div>
                    <audio controls style="width: 100%; margin-bottom: 10px;">
                        <source src="<?php echo htmlspecialchars($m['file_path']); ?>" type="audio/mpeg">
                        <?php echo htmlspecialchars(t('admin_no_audio_support')); ?>
                    </audio>
                    <label><input type="checkbox" name="download_music[]" value="<?php echo $m['id']; ?>"> <?php echo htmlspecialchars(t('family_select_label')); ?></label>
                </div>
                <?php endforeach; ?>
            </div>
            <h2 style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-top: 30px;" onclick="toggleSection('messages-section', this)">
                <span class="toggle-icon collapsed" style="font-size: 0.9em;">▼</span>
                <span><?php echo htmlspecialchars(t('family_messages_heading')); ?></span>
                <span style="font-size: 0.85em; color: #999;">(<?php echo count($messages); ?>)</span>
            </h2>
            <div class="message-list collapsible-section collapsed" id="messages-section">
                <?php foreach ($messages as $msg): ?>
                <div class="message-card">
                    <div style="font-size:1.1em; color:#333; margin-bottom:6px;"><strong><?php echo htmlspecialchars($msg['uploader_name']); ?></strong></div>
                    <div style="font-size:1.05em; color:#7a5c2e; margin-bottom:8px;">"<?php echo htmlspecialchars($msg['message_text']); ?>"</div>
                    <label><input type="checkbox" name="download_messages[]" value="<?php echo $msg['id']; ?>"> <?php echo htmlspecialchars(t('family_select_label')); ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        <div class="bottom-buttons">
            <button type="submit" class="main-btn" onclick="handleDownloadSubmit(event)"><span>📥</span><span><?php echo htmlspecialchars(t('family_btn_download_selected')); ?></span></button>
            <button class="main-btn" onclick="window.location.href='family_overview?clear=1'" style="background:#c85a54;"><span>🗑️</span><span><?php echo htmlspecialchars(t('family_btn_clear_selection')); ?></span></button>
            <button type="button" class="main-btn" onclick="document.getElementById('logoutForm').submit()"><span><?php echo htmlspecialchars(t('common_logout')); ?></span></button>
        </div>
        </form>

        <!-- Form for adding photos to slideshow -->
        <?php if ($can_add_slideshow_photos): ?>
        <form action="family_overview" method="post" style="display:inline;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="folder_context" value="<?php echo htmlspecialchars($safe_folder); ?>">
            <div id="slideshowPhotosContainer" style="display:none;">
            <!-- Checkboxes will be populated via JavaScript -->
            </div>
            <button type="submit" name="add_to_slideshow" class="main-btn" id="slideshowBtn" style="display:none; background:#2d5a27; margin-top:20px;"><?php echo htmlspecialchars(t('family_btn_add_selected_to_slideshow')); ?></button>
        </form>
        <?php endif; ?>

        <form id="logoutForm" action="logout" method="post" style="display:none;"></form>
    </div>

    <!-- Admin Contact Info at Bottom -->
    <?php 
    // Haal beheerder contactgegevens op
    $stmt = $conn->prepare("SELECT setting_key, value FROM settings WHERE setting_key IN ('admin_name', 'admin_email', 'admin_phone')");
    $stmt->execute();
    $admin_info = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $admin_info[$row['setting_key']] = $row['value'];
    }
    
    // Toon contactgegevens op informele manier
    if (!empty($admin_info['admin_name']) || !empty($admin_info['admin_email']) || !empty($admin_info['admin_phone'])): 
    ?>
    <div style="background: transparent; padding: 30px 0; text-align: center; border-top: 1px solid #eee; margin-top: 40px;">
        <p style="margin: 0 0 15px 0; font-size: 0.9rem; color: #999; font-style: italic;">
            <?php echo htmlspecialchars(t('upload_contact_intro')); ?>
        </p>
        <?php if (!empty($admin_info['admin_name'])): ?>
            <p style="margin: 5px 0; font-size: 0.95rem; color: #666;"><strong><?php echo htmlspecialchars($admin_info['admin_name']); ?></strong></p>
        <?php endif; ?>
        <?php if (!empty($admin_info['admin_email'])): ?>
            <p style="margin: 3px 0; font-size: 0.85rem; color: #888;"><a href="mailto:<?php echo htmlspecialchars($admin_info['admin_email']); ?>" style="color: <?php echo htmlspecialchars($site_primary_hover); ?>; text-decoration: none;"><?php echo htmlspecialchars($admin_info['admin_email']); ?></a></p>
        <?php endif; ?>
        <?php if (!empty($admin_info['admin_phone'])): ?>
            <p style="margin: 3px 0; font-size: 0.85rem; color: #888;"><a href="tel:<?php echo htmlspecialchars($admin_info['admin_phone']); ?>" style="color: <?php echo htmlspecialchars($site_primary_hover); ?>; text-decoration: none;"><?php echo htmlspecialchars($admin_info['admin_phone']); ?></a></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Context Menu for Folders -->
    <div id="folderContextMenu">
        <button class="context-menu-item" onclick="renameFolder()"><?php echo htmlspecialchars(t('family_context_rename')); ?></button>
        <button class="context-menu-item" onclick="downloadFolder()"><?php echo htmlspecialchars(t('family_context_download')); ?></button>
        <button class="context-menu-item danger" onclick="deleteFolder()"><?php echo htmlspecialchars(t('family_context_delete')); ?></button>
    </div>
</body>
</html>
