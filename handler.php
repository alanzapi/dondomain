<?php
// handler.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/functions.php";
?>
