<?php
// process_add_domain.php
require_once "handler.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $domainsText = trim($_POST['domains']);
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    $domainsArray = preg_split("/\r\n|\n|\r/", $domainsText);
    
    foreach ($domainsArray as $input) {
        $input = trim($input);
        if (!empty($input)) {
            $extracted = extractDomainFromInput($input);
            $normalized = normalizeDomain($extracted);
            $mainDomain = extractMainDomain($normalized);
            $tld = getDomainTLD($mainDomain);
            
            $stmt = $pdo->prepare("SELECT id, note FROM domains WHERE normalized_domain = ?");
            $stmt->execute([$normalized]);
            $row = $stmt->fetch();
            if (!$row) {
                $stmtInsert = $pdo->prepare("INSERT INTO domains (domain_name, normalized_domain, tld, note) VALUES (?, ?, ?, ?)");
                $stmtInsert->execute([$input, $normalized, $tld, $note]);
            } else {
                $existingNote = $row['note'] ?? '';
                if (!empty($note) && strpos($existingNote, $note) === false) {
                    $newNote = empty($existingNote) ? $note : $existingNote . "; " . $note;
                    $stmtUpdate = $pdo->prepare("UPDATE domains SET note = ? WHERE id = ?");
                    $stmtUpdate->execute([$newNote, $row['id']]);
                }
            }
            if ($normalized !== $mainDomain) {
                $stmt = $pdo->prepare("SELECT id FROM domains WHERE normalized_domain = ?");
                $stmt->execute([$mainDomain]);
                if (!$stmt->fetch()) {
                    $mainTld = getDomainTLD($mainDomain);
                    $stmtInsert = $pdo->prepare("INSERT INTO domains (domain_name, normalized_domain, tld, note) VALUES (?, ?, ?, ?)");
                    $stmtInsert->execute([$mainDomain, $mainDomain, $mainTld, $note]);
                }
            }
        }
    }
    header("Location: list_domain.php");
    exit();
} else {
    header("Location: add_domain.php");
    exit();
}
?>
