<?php
// admin_config.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}
$configFile = "../includes/config_options.json";
$options = [
    "pagesToTry" => ["", "/contact", "/about", "/contact-us", "/about-us"],
    "emailExclude" => ["+", "top"],
    "phonePrefixes" => [
        "+1" => "USA/Canada",
        "+44" => "United Kingdom",
        "+34" => "Spain",
        "+33" => "France",
        "+49" => "Germany",
        "+39" => "Italy"
    ],
    "searchKeywords" => [],
    "mxMapping" => []  // New: mapping of MX hostnames to friendly names.
];
if (file_exists($configFile)) {
    $json = file_get_contents($configFile);
    $saved = json_decode($json, true);
    if (is_array($saved)) {
        $options = array_merge($options, $saved);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pages = isset($_POST['pages']) ? array_map('trim', explode(',', $_POST['pages'])) : [];
    $emailExclude = isset($_POST['emailExclude']) ? array_map('trim', explode(',', $_POST['emailExclude'])) : [];
    $phonePrefixesRaw = isset($_POST['phonePrefixes']) ? explode("\n", $_POST['phonePrefixes']) : [];
    $phonePrefixes = [];
    foreach ($phonePrefixesRaw as $line) {
        $line = trim($line);
        if ($line && strpos($line, ':') !== false) {
            list($prefix, $country) = explode(':', $line, 2);
            $phonePrefixes[trim($prefix)] = trim($country);
        }
    }
    $searchKeywordsRaw = isset($_POST['searchKeywords']) ? explode("\n", $_POST['searchKeywords']) : [];
    $searchKeywords = array_filter(array_map('trim', $searchKeywordsRaw));
    
    // New: MX Mapping
    $mxMappingRaw = isset($_POST['mxMapping']) ? explode("\n", $_POST['mxMapping']) : [];
    $mxMapping = [];
    foreach ($mxMappingRaw as $line) {
        $line = trim($line);
        if ($line && strpos($line, ':') !== false) {
            list($mx, $friendly) = explode(':', $line, 2);
            $mxMapping[trim(strtolower($mx))] = trim($friendly);
        }
    }
    
    $options['pagesToTry'] = $pages;
    $options['emailExclude'] = $emailExclude;
    $options['phonePrefixes'] = $phonePrefixes;
    $options['searchKeywords'] = $searchKeywords;
    $options['mxMapping'] = $mxMapping;
    
    file_put_contents($configFile, json_encode($options, JSON_PRETTY_PRINT));
    header("Location: admin_config.php?msg=Options saved successfully");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Configuration - Domain Manager</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .form-group { margin-bottom: 15px; }
        textarea { width: 100%; height: 100px; }
        input[type="text"] { width: 100%; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">Domain Manager</div>
        <ul>
            <li><a href="list_domain.php">Domain List</a></li>
            <li><a href="add_domain.php">Add Domains</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <div class="main-content">
        <h1>Admin Configuration</h1>
        <?php if (isset($_GET['msg'])): ?>
            <p style="color: green;"><?= htmlspecialchars($_GET['msg'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post" action="admin_config.php">
            <div class="form-group">
                <label for="pages">Candidate Pages (comma separated):</label>
                <input type="text" name="pages" id="pages" value="<?= htmlspecialchars(implode(',', $options['pagesToTry']) ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label for="emailExclude">Email Exclude Filters (comma separated):</label>
                <input type="text" name="emailExclude" id="emailExclude" value="<?= htmlspecialchars(implode(',', $options['emailExclude']) ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label for="phonePrefixes">Phone Prefix Mapping (one per line, format: prefix: country):</label>
                <textarea name="phonePrefixes" id="phonePrefixes"><?php
                    $lines = [];
                    foreach ($options['phonePrefixes'] as $prefix => $country) {
                        $lines[] = "$prefix: $country";
                    }
                    echo htmlspecialchars(implode("\n", $lines) ?? '', ENT_QUOTES, 'UTF-8');
                ?></textarea>
            </div>
            <div class="form-group">
                <label for="searchKeywords">Search Keywords (one per line):</label>
                <textarea name="searchKeywords" id="searchKeywords"><?php
                    echo htmlspecialchars(implode("\n", $options['searchKeywords']) ?? '', ENT_QUOTES, 'UTF-8');
                ?></textarea>
            </div>
            <div class="form-group">
                <label for="mxMapping">MX Mapping (one per line, format: mx.host.com: Friendly Name):</label>
                <textarea name="mxMapping" id="mxMapping"><?php
                    $lines = [];
                    if (isset($options['mxMapping']) && is_array($options['mxMapping'])) {
                        foreach ($options['mxMapping'] as $host => $friendly) {
                            $lines[] = "$host: $friendly";
                        }
                    }
                    echo htmlspecialchars(implode("\n", $lines) ?? '', ENT_QUOTES, 'UTF-8');
                ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Options</button>
        </form>
    </div>
</body>
</html>