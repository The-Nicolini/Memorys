<?php
// logout.php
require_once __DIR__ . '/private/config.php';
session_destroy();
header("Location: index");
exit;
?>
