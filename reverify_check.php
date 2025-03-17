<?php
// cron/reverify_check.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

$queueFile = __DIR__ . '/reverify_queue.txt';
$includesPath = realpath(__DIR__ . '/../includes');
if ($includesPath === false) { die("Error: Unable to resolve includes path."); }
require_once $includesPath . '/config.php';
require_once $includesPath . '/functions.php';

$queue = [];
if (file_exists($queueFile)) {
    $queue = file($queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}
if (empty($queue)) {
    echo "No domains queued for reverify.";
    exit();
}
$domainId = array_shift($queue);
$stmt = $pdo->prepare("SELECT normalized_domain FROM domains WHERE id = ?");
$stmt->execute([$domainId]);
$row = $stmt->fetch();
if ($row) {
    $norm = $row['normalized_domain'];
    try {
        $data = scrapeDomainData($norm);
    } catch (Exception $e) {
        error_log("REVERIFY: Exception for $norm: " . $e->getMessage());
        file_put_contents($queueFile, implode("\n", $queue));
        exit();
    }
    $phonesJson = json_encode($data['phones']);
    $emailsJson = json_encode($data['emails']);
    $isOnline = $data['is_online'] ? 1 : 0;
    $stmt2 = $pdo->prepare("SELECT id FROM domain_details WHERE domain_id = ?");
    $stmt2->execute([$domainId]);
    if ($stmt2->fetch()) {
        $stmt3 = $pdo->prepare("UPDATE domain_details SET language = ?, is_online = ?, phones = ?, emails = ? WHERE domain_id = ?");
        $stmt3->execute([$data['language'], $isOnline, $phonesJson, $emailsJson, $domainId]);
    } else {
        $stmt4 = $pdo->prepare("INSERT INTO domain_details (domain_id, language, is_online, phones, emails) VALUES (?, ?, ?, ?, ?)");
        $stmt4->execute([$domainId, $data['language'], $isOnline, $phonesJson, $emailsJson]);
    }
    updateDomainSiteInfo($pdo, $domainId, $data['title'], $data['keywords']);
    error_log("REVERIFY: $norm reverified. is_online=$isOnline, language={$data['language']}");
}
file_put_contents($queueFile, implode("\n", $queue)); // Save remaining queue.
echo "Reverified domain ID: $domainId";
?>
