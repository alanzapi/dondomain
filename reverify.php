<?php
// reverify.php
require_once "handler.php";
$queueFile = "../cron/reverify_queue.txt";
if (isset($_GET['ids'])) {
    $ids = explode(',', $_GET['ids']);
    $ids = array_map('intval', $ids);
    $existing = [];
    if (file_exists($queueFile)) {
        $existing = file($queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
    $newQueue = array_unique(array_merge($existing, $ids));
    file_put_contents($queueFile, implode("\n", $newQueue));
    header("Location: list_domain.php?msg=Reverify queued for selected domains");
    exit();
} else {
    header("Location: list_domain.php");
    exit();
}
?>
