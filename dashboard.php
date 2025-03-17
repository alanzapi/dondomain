<?php
// public/dashboard.php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}
require_once "../includes/config.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Domain Manager</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">Domain Manager</div>
        <ul>
            <li><a href="dashboard.php">Dashboard</a></li>
            <li><a href="export_csv.php">Export CSV</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1>Dashboard</h1>
            <div class="clock" id="clock"></div>
        </div>
        <!-- Form to Add Domains -->
        <div class="editor-container">
            <h2>Add Domains</h2>
            <form action="add_domain.php" method="post">
                <div class="form-group">
                    <textarea name="domains" placeholder="Enter one domain per line"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Add Domains</button>
            </form>
        </div>
        <!-- Domain List -->
        <div class="table-container">
            <h2>Domain List</h2>
            <table id="dataTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Domain</th>
                        <th>TLD</th>
                        <th>Language</th>
                        <th>Phones</th>
                        <th>Emails</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Fetch domains with details
                $stmt = $pdo->query("SELECT d.id, d.domain_name, d.tld, dd.language, dd.phones, dd.emails, dd.is_online
                                     FROM domains d 
                                     LEFT JOIN domain_details dd ON d.id = dd.domain_id
                                     ORDER BY d.id DESC");
                while ($row = $stmt->fetch()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['domain_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['tld']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['language']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['phones']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['emails']) . "</td>";
                    echo "<td>" . ($row['is_online'] ? "Online" : "Offline") . "</td>";
                    echo "<td><a href='delete_domain.php?id=" . $row['id'] . "' class='btn btn-danger'>Delete</a></td>";
                    echo "</tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
        // Simple clock updater
        function updateClock() {
            document.getElementById("clock").innerHTML = new Date().toLocaleTimeString();
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
