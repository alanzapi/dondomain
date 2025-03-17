<?php
// login_process.php
session_start();
$username = $_POST['username'];
$password = $_POST['password'];

// For demonstration, credentials are hardcoded. In production use a secure method.
if ($username === 'admin' && $password === 'admin123') {
    $_SESSION['user'] = $username;
    header("Location: list_domain.php");
    exit();
} else {
    header("Location: index.php?error=Invalid credentials");
    exit();
}
?>
