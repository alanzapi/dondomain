<?php
// export_csv.php
require_once "handler.php";

$domains = [];
if (isset($_GET['ids'])) {
    $ids = explode(',', $_GET['ids']);
    $ids = array_map('intval', $ids);
    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
    $sql = "SELECT d.id, d.normalized_domain, d.tld, d.note, d.site_title, d.site_keywords,
                   dd.language, dd.phones, dd.emails, dd.is_online
            FROM domains d
            LEFT JOIN domain_details dd ON d.id = dd.domain_id
            WHERE d.id IN ($placeholders)
            ORDER BY d.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $domains = $stmt->fetchAll();
} else {
    $params = [];
    $whereClauses = [];
    if(isset($_GET['domain']) && $_GET['domain'] !== ""){
        $whereClauses[] = "d.normalized_domain LIKE ?";
        $params[] = "%" . trim($_GET['domain']) . "%";
    }
    if(isset($_GET['tld']) && $_GET['tld'] !== ""){
        $whereClauses[] = "d.tld = ?";
        $params[] = trim($_GET['tld']);
    }
    if(isset($_GET['language']) && $_GET['language'] !== ""){
        $whereClauses[] = "dd.language = ?";
        $params[] = trim($_GET['language']);
    }
    if(isset($_GET['note']) && $_GET['note'] !== ""){
        $whereClauses[] = "d.note LIKE ?";
        $params[] = "%" . trim($_GET['note']) . "%";
    }
    if(isset($_GET['title_keywords']) && $_GET['title_keywords'] !== ""){
        $keywords = array_map('trim', explode(',', $_GET['title_keywords']));
        foreach ($keywords as $kw) {
            if (!empty($kw)) {
                $whereClauses[] = "(d.site_title LIKE ? OR d.site_keywords LIKE ?)";
                $params[] = "%" . $kw . "%";
                $params[] = "%" . $kw . "%";
            }
        }
    }
    $whereSQL = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";
    $sql = "SELECT d.id, d.normalized_domain, d.tld, d.note, d.site_title, d.site_keywords,
                   dd.language, dd.phones, dd.emails, dd.is_online
            FROM domains d
            LEFT JOIN domain_details dd ON d.id = dd.domain_id
            $whereSQL
            ORDER BY d.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $domains = $stmt->fetchAll();
}

if (isset($_GET['txtEmail']) && $_GET['txtEmail'] == 1) {
    $allEmails = [];
    foreach ($domains as $row) {
        if (!empty($row['emails'])) {
            $emails = json_decode($row['emails'], true);
            if (is_array($emails)) {
                $allEmails = array_merge($allEmails, $emails);
            }
        }
    }
    $allEmails = array_unique($allEmails);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="emails.txt"');
    foreach ($allEmails as $email) {
        echo $email . "\n";
    }
    exit();
} elseif (isset($_GET['clean']) && $_GET['clean'] == 1) {
    $domainIds = array_map(function($row) { return $row['id']; }, $domains);
    if (!empty($domainIds)) {
        $placeholders = rtrim(str_repeat('?,', count($domainIds)), ',');
        $stmt = $pdo->prepare("DELETE FROM domain_details WHERE domain_id IN ($placeholders)");
        $stmt->execute($domainIds);
    }
    header('Location: list_domain.php?msg=Data cleaned for filtered domains');
    exit();
} else {
    header('Content-Type: text/csv; charset=utf-8');
    $filename = "domains_export.csv";
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    if (isset($_GET['emailOnly']) && $_GET['emailOnly'] == 1) {
        fputcsv($output, ['ID', 'Domain', 'Emails']);
        foreach ($domains as $row) {
            $emails = $row['emails'] ? implode(", ", json_decode($row['emails'], true)) : "";
            fputcsv($output, [$row['id'], $row['normalized_domain'], $emails]);
        }
    } else {
        fputcsv($output, ['ID', 'Domain', 'TLD', 'Language', 'Phones', 'Emails', 'Status', 'Note', 'Site Title', 'Site Keywords']);
        foreach ($domains as $row) {
            $phones = $row['phones'] ? implode(", ", json_decode($row['phones'], true)) : "";
            $emails = $row['emails'] ? implode(", ", json_decode($row['emails'], true)) : "";
            $status = $row['is_online'] ? "Online" : "Offline";
            fputcsv($output, [$row['id'], $row['normalized_domain'], $row['tld'], $row['language'], $phones, $emails, $status, $row['note'], $row['site_title'], $row['site_keywords']]);
        }
    }
    fclose($output);
    exit();
}
?>
