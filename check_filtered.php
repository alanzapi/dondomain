<?php
// check_filtered.php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

require_once "includes/config.php";
require_once "includes/functions.php";

// Get filter parameters from GET
$tldFilter      = isset($_GET['tld']) ? trim($_GET['tld']) : '';
$langFilter     = isset($_GET['language']) ? trim($_GET['language']) : '';
$statusFilter   = isset($_GET['status']) ? trim($_GET['status']) : '';
$noteFilter     = isset($_GET['note']) ? trim($_GET['note']) : '';
$domainSearch   = isset($_GET['domain']) ? trim($_GET['domain']) : '';
$countryFilter  = isset($_GET['country']) ? trim($_GET['country']) : '';

$whereClauses = [];
$params = [];
if ($tldFilter !== '') {
    $whereClauses[] = "d.tld = ?";
    $params[] = $tldFilter;
}
if ($langFilter !== '') {
    $whereClauses[] = "dd.language = ?";
    $params[] = $langFilter;
}
if ($statusFilter !== '') {
    if ($statusFilter == 'online') {
        $whereClauses[] = "dd.is_online = 1";
    } elseif ($statusFilter == 'offline') {
        $whereClauses[] = "dd.is_online = 0";
    }
}
if ($noteFilter !== '') {
    $whereClauses[] = "d.note LIKE ?";
    $params[] = "%$noteFilter%";
}
if ($domainSearch !== '') {
    $whereClauses[] = "d.normalized_domain LIKE ?";
    $params[] = "%$domainSearch%";
}
$whereSQL = ($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Get matching domains
$sql = "SELECT d.id, d.normalized_domain FROM domains d
        LEFT JOIN domain_details dd ON d.id = dd.domain_id
        $whereSQL
        ORDER BY d.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$domains = $stmt->fetchAll();

// Apply country filter if needed
if ($countryFilter !== '') {
    $filteredIds = [];
    foreach ($domains as $row) {
        $stmt2 = $pdo->prepare("SELECT phones FROM domain_details WHERE domain_id = ?");
        $stmt2->execute([$row['id']]);
        $detail = $stmt2->fetch();
        $match = false;
        if ($detail && $detail['phones']) {
            $phones = json_decode($detail['phones'], true);
            if (is_array($phones)) {
                foreach ($phones as $phone) {
                    if (strcasecmp(getCountryByPhonePrefix($phone), $countryFilter) === 0) {
                        $match = true;
                        break;
                    }
                }
            }
        }
        if ($match) {
            $filteredIds[] = $row['id'];
        }
    }
    if ($filteredIds) {
        $inClause = rtrim(str_repeat('?,', count($filteredIds)), ',');
        $sql = "SELECT id, normalized_domain FROM domains WHERE id IN ($inClause) ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filteredIds);
        $domains = $stmt->fetchAll();
    } else {
        $domains = [];
    }
}

// For each matching domain, run a re-check
foreach ($domains as $domain) {
    $domainId = $domain['id'];
    $normalized = $domain['normalized_domain'];
    $data = scrapeDomainData($normalized);
    $phonesJson = json_encode($data['phones']);
    $emailsJson = json_encode($data['emails']);
    
    $stmt3 = $pdo->prepare("SELECT id FROM domain_details WHERE domain_id = ?");
    $stmt3->execute([$domainId]);
    if ($stmt3->fetch()) {
        $stmt4 = $pdo->prepare("UPDATE domain_details SET language = ?, is_online = ?, phones = ?, emails = ? WHERE domain_id = ?");
        $stmt4->execute([$data['language'], $data['is_online'] ? 1 : 0, $phonesJson, $emailsJson, $domainId]);
    } else {
        $stmt5 = $pdo->prepare("INSERT INTO domain_details (domain_id, language, is_online, phones, emails) VALUES (?, ?, ?, ?, ?)");
        $stmt5->execute([$domainId, $data['language'], $data['is_online'] ? 1 : 0, $phonesJson, $emailsJson]);
    }
    usleep(500000);
}

header("Location: list_domain.php?" . http_build_query($_GET));
exit();
?>
