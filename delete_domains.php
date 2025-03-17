<?php
// delete_domains.php
require_once "handler.php";
if (isset($_POST['ids'])) {
    $ids = $_POST['ids'];
    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
    $stmt = $pdo->prepare("DELETE FROM domains WHERE id IN ($placeholders)");
    $stmt->execute($ids);
}
header("Location: list_domain.php");
exit();
?>
