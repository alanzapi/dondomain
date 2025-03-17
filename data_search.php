<?php
// data_search.php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}
require_once "../includes/functions.php";

$result = null;
$error = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['url'])) {
    $url = trim($_POST['url']);
    $url = rtrim($url, '/');
    $parsed = parse_url($url);
    if (isset($parsed['host'])) {
        $domain = $parsed['host'];
    } else {
        $domain = $url;
    }
    $normalized = normalizeDomain($domain);
    $result = scrapeDomainData($normalized);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Data Search - Domain Manager</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">Domain Manager</div>
        <ul>
            <li><a href="list_domain.php">Domain List</a></li>
            <li><a href="add_domain.php">Add Domains</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1>Data Search</h1>
        </div>
        <div class="editor-container">
            <form method="post" action="data_search.php">
                <div class="form-group">
                    <input type="text" name="url" placeholder="Enter URL (e.g., https://example.com/contact-us/)" required>
                </div>
                <button type="submit" class="btn btn-primary">Search Data</button>
            </form>
        </div>
        <?php if ($result !== null): ?>
            <div style="margin-top:20px;">
                <h2>Results for <?= htmlspecialchars($url) ?></h2>
                <p><strong>Domain:</strong> <?= htmlspecialchars($normalized) ?></p>
                <p><strong>Language:</strong> <?= htmlspecialchars($result['language']) ?></p>
                <p><strong>Status:</strong> <?= $result['is_online'] ? "Online" : "Offline" ?></p>
                <p><strong>Emails:</strong> <?= htmlspecialchars(implode(", ", $result['emails'])) ?></p>
                <p><strong>Phone Numbers:</strong> <?= htmlspecialchars(implode(", ", $result['phones'])) ?></p>
                <?php
                    $countries = [];
                    foreach ($result['phones'] as $phone) {
                        $countries[] = getCountryByPhonePrefix($phone);
                    }
                ?>
                <p><strong>Countries (by Phone Prefix):</strong> <?= htmlspecialchars(implode(", ", array_unique($countries))) ?></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
