<?php
// cron/check_domains.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

// Resolve the path to the includes folder (which contains config.php and functions.php)
$includesPath = realpath(__DIR__ . '/../includes');
if ($includesPath === false) {
    die("Error: Unable to resolve includes path.");
}
require_once $includesPath . '/config.php';      // This file should set up your $pdo connection.
require_once $includesPath . '/functions.php';   // Contains scrapeDomainData(), updateDomainStatus(), etc.

// Use a bookmark file to remember the last processed domain ID
$bookmarkFile = __DIR__ . '/last_checked.txt';
if (!file_exists($bookmarkFile)) {
    file_put_contents($bookmarkFile, '0');
}
$lastChecked = (int) file_get_contents($bookmarkFile);
error_log("CRON: Starting from domain ID: $lastChecked");

// Define how many domains to check per run
$batchSize = 10;
$stmt = $pdo->prepare("SELECT id, normalized_domain FROM domains WHERE id > ? ORDER BY id ASC LIMIT ?");
$stmt->execute([$lastChecked, $batchSize]);
$domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$domains) {
    error_log("CRON: No domains found with id > $lastChecked. Resetting bookmark.");
    file_put_contents($bookmarkFile, '0');
    $lastChecked = 0;
    $stmt->execute([$lastChecked, $batchSize]);
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
error_log("CRON: Fetched " . count($domains) . " domains to process.");
$lastProcessedId = $lastChecked;

foreach ($domains as $domainRow) {
    $domainId   = $domainRow['id'];
    $domainName = $domainRow['normalized_domain'];
    error_log("CRON: Processing domain ID $domainId: $domainName");
    try {
        // scrapeDomainData() fetches site data (including emails, phones, title, keywords, etc.)
        $data = scrapeDomainData($domainName);
    } catch (Exception $e) {
        error_log("CRON: Error processing $domainName: " . $e->getMessage());
        // Write the current domainId to resume later and skip this one.
        file_put_contents($bookmarkFile, $domainId);
        continue;
    }
    // Update domain details (phones, emails, language, etc.)
    updateDomainStatus($pdo, $domainId, $data);
    // Update domain site info (title and keywords)
    updateDomainSiteInfo($pdo, $domainId, $data['title'], $data['keywords']);
    error_log("CRON: Finished processing $domainName. Online: " . ($data['is_online'] ? 'Yes' : 'No') . ", Title: " . $data['title']);
    $lastProcessedId = $domainId;
    // Save the current domain ID to the bookmark file
    file_put_contents($bookmarkFile, $domainId);
    // Pause briefly to avoid overloading the server.
    usleep(500000); // 0.5 second pause
}

error_log("CRON: Completed. Last processed ID: $lastProcessedId");
echo "Processed up to domain ID: " . $lastProcessedId;
?>