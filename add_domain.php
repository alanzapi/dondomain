<?php
// add_domain.php
require_once "handler.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Domain - Domain Manager</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
      .form-group { margin-bottom: 15px; }
      textarea { width: 100%; height: 150px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">Domain Manager</div>
        <ul>
            <li><a href="list_domain.php">Domain List</a></li>
            <li><a href="reverify.php">Reverify</a></li>
            <li><a href="admin_config.php">Config</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <div class="main-content">
        <div class="header"><h1>Add Domains</h1></div>
        <div class="editor-container">
            <form action="process_add_domain.php" method="post">
                <div class="form-group">
                    <textarea name="domains" placeholder="Enter one domain per line (URLs, emails, etc.)" required></textarea>
                </div>
                <div class="form-group">
                    <input type="text" name="note" placeholder="Optional note for these domains">
                </div>
                <button type="submit" class="btn btn-primary">Add Domains</button>
            </form>
        </div>
    </div>
</body>
</html>
