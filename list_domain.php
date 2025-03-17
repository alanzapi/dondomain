<?php
// list_domain.php
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == 1;
require_once "handler.php";

/**
 * Safely read a GET param as string, avoiding trim(null) warnings.
 */
function safeGet($key) {
    return isset($_GET[$key]) ? trim($_GET[$key]) : '';
}

// Tag-based filters
$domainFilter        = safeGet('domain');      // e.g. "abc, def"
$tldFilter           = safeGet('tld');         // e.g. "es, com"
$langFilter          = safeGet('language');    // e.g. "en, es"
$countryFilter       = safeGet('country');     // e.g. "Spain, Germany"
$noteFilter          = safeGet('note');        // e.g. "hello, test"
$statusFilter        = safeGet('status');      // e.g. "online, offline"
$titleKeywordsFilter = safeGet('title_keywords'); // e.g. "keyword1, keyword2"

$itemsPerPage = isset($_GET['items']) ? (int)$_GET['items'] : 100;
$page         = isset($_GET['page'])  ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }

/**
 * Build an OR clause for multiple tags in a single field.
 * e.g. if user typed "es, com" for TLD => TLD=es OR TLD=com (case-insensitive).
 * We'll combine these OR clauses with other fields using AND.
 */
function buildOrClauseForTags($field, $tagString) {
    $tags = array_filter(array_map('trim', explode(',', $tagString)));
    if (empty($tags)) return null;

    $pieces = [];
    switch ($field) {
        case 'domain':
            // Domain => partial ignoring case
            // e.g. LOWER(d.normalized_domain) LIKE '%tag%'
            foreach ($tags as $t) {
                $pieces[] = "LOWER(d.normalized_domain) LIKE CONCAT('%', LOWER(?), '%')";
            }
            break;
        case 'tld':
            // TLD => exact ignoring case
            foreach ($tags as $t) {
                $pieces[] = "LOWER(d.tld) = LOWER(?)";
            }
            break;
        case 'language':
            // Language => exact ignoring case
            foreach ($tags as $t) {
                $pieces[] = "LOWER(dd.language) = LOWER(?)";
            }
            break;
        case 'note':
            // Note => partial ignoring case
            foreach ($tags as $t) {
                $pieces[] = "LOWER(d.note) LIKE CONCAT('%', LOWER(?), '%')";
            }
            break;
        case 'title_keywords':
            // Title & Keywords => partial ignoring case
            // Each tag => (LOWER(d.site_title) LIKE '%tag%' OR LOWER(d.site_keywords) LIKE '%tag%')
            // We'll do an OR for each tag. Then we combine them with OR among tags.
            $temp = [];
            foreach ($tags as $t) {
                $temp[] = "(LOWER(d.site_title) LIKE CONCAT('%', LOWER(?), '%') 
                            OR LOWER(d.site_keywords) LIKE CONCAT('%', LOWER(?), '%'))";
            }
            // If multiple tags => we do big OR among them
            return '(' . implode(' OR ', $temp) . ')';
        default:
            return null;
    }
    if (empty($pieces)) return null;
    // Combine them with OR
    return '(' . implode(' OR ', $pieces) . ')';
}

/**
 * Add parameters for each tag (Domain, TLD, Language, etc.).
 * For Title/Keywords, each tag => 2 params. For others, each tag => 1 param.
 */
function addParamsForTags(&$params, $field, $tagString) {
    $tags = array_filter(array_map('trim', explode(',', $tagString)));
    switch ($field) {
        case 'domain':
        case 'tld':
        case 'language':
        case 'note':
            foreach ($tags as $t) {
                $params[] = $t;
            }
            break;
        case 'title_keywords':
            foreach ($tags as $t) {
                // each tag => 2 params
                $params[] = $t;
                $params[] = $t;
            }
            break;
        default:
            break;
    }
}

// We'll store partial OR clauses for domain, tld, etc. Then combine them with AND across fields.
$whereClauses = [];
$params = [];

// 1) Domain
$clDomain = buildOrClauseForTags('domain', $domainFilter);
if ($clDomain) {
    $whereClauses[] = $clDomain;
    addParamsForTags($params, 'domain', $domainFilter);
}

// 2) TLD
$clTld = buildOrClauseForTags('tld', $tldFilter);
if ($clTld) {
    $whereClauses[] = $clTld;
    addParamsForTags($params, 'tld', $tldFilter);
}

// 3) Language
$clLang = buildOrClauseForTags('language', $langFilter);
if ($clLang) {
    $whereClauses[] = $clLang;
    addParamsForTags($params, 'language', $langFilter);
}

// 4) Note
$clNote = buildOrClauseForTags('note', $noteFilter);
if ($clNote) {
    $whereClauses[] = $clNote;
    addParamsForTags($params, 'note', $noteFilter);
}

// 5) Title & Keywords
$clTitle = buildOrClauseForTags('title_keywords', $titleKeywordsFilter);
if ($clTitle) {
    $whereClauses[] = $clTitle;
    addParamsForTags($params, 'title_keywords', $titleKeywordsFilter);
}

// Combine them with AND across different fields
$whereSQL = '';
if (!empty($whereClauses)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Get overall stats
$totalDomains   = $pdo->query("SELECT COUNT(*) FROM domains")->fetchColumn();
$onlineDomains  = $pdo->query("SELECT COUNT(*) FROM domain_details WHERE is_online = 1")->fetchColumn();
$offlineDomains = $pdo->query("SELECT COUNT(*) FROM domain_details WHERE is_online = 0")->fetchColumn();
$totalDomains   = $totalDomains   !== false ? $totalDomains   : 0;
$onlineDomains  = $onlineDomains  !== false ? $onlineDomains  : 0;
$offlineDomains = $offlineDomains !== false ? $offlineDomains : 0;

// Main query
$sql = "SELECT d.id, d.normalized_domain, d.tld, d.note, d.site_title, d.site_keywords,
               dd.language, dd.phones, dd.emails, dd.is_online
        FROM domains d
        LEFT JOIN domain_details dd ON d.id = dd.domain_id
        $whereSQL
        ORDER BY d.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll();

// 6) Country => multiple tags => OR logic. (If you type "Spain, Germany", domain must have prefix=Spain OR prefix=Germany)
if (!empty($countryFilter)) {
    $countryTags = array_filter(array_map('trim', explode(',', $countryFilter)));
    if ($countryTags) {
        $filtered = [];
        foreach ($allRows as $row) {
            $phones = !empty($row['phones']) ? json_decode($row['phones'], true) : [];
            if (!is_array($phones)) { $phones = []; }
            // If row's phones match ANY of the country tags => keep it
            $keepRow = false;
            foreach ($phones as $phone) {
                $detected = getCountryByPhonePrefix($phone);
                // If user typed "Spain, Germany", we keep row if $detected is Spain OR Germany
                if (in_array($detected, $countryTags, true)) {
                    $keepRow = true;
                    break;
                }
            }
            if ($keepRow) {
                $filtered[] = $row;
            }
        }
        $allRows = $filtered;
    }
}

// 7) Status => multiple tags => OR logic as well. If user typed "online, offline", it shows domains that are online OR offline => basically all. 
// But that's the typical approach for multi-tag status.
if (!empty($statusFilter)) {
    $statusTags = array_filter(array_map('trim', explode(',', $statusFilter)));
    if ($statusTags) {
        $filtered = [];
        foreach ($allRows as $row) {
            $isOnline = (int)($row['is_online'] ?? 0);
            $matchRow = false;
            foreach ($statusTags as $st) {
                $st = strtolower($st);
                if ($st === 'online' && $isOnline === 1) {
                    $matchRow = true; 
                    break;
                }
                if ($st === 'offline' && $isOnline === 0) {
                    $matchRow = true;
                    break;
                }
            }
            if ($matchRow) {
                $filtered[] = $row;
            }
        }
        $allRows = $filtered;
    }
}

// Pagination
$totalFiltered = count($allRows);
$totalPages    = ($totalFiltered > 0) ? ceil($totalFiltered / $itemsPerPage) : 1;
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $itemsPerPage;
$displayRows = array_slice($allRows, $offset, $itemsPerPage);

// If AJAX => only output table + pagination
if ($isAjax) {
    ?>
    <table border="1" cellpadding="5" cellspacing="0" width="100%">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                <th>ID</th>
                <th>Domain</th>
                <th>TLD</th>
                <th>Language</th>
                <th>Country</th>
                <th>Emails</th>
                <th>Status</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($displayRows as $row): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= htmlspecialchars($row['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></td>
                <td><?= htmlspecialchars($row['id'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['normalized_domain'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['tld'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['language'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php
                    $phones  = !empty($row['phones']) ? json_decode($row['phones'], true) : [];
                    $country = 'Unknown';
                    if (is_array($phones) && count($phones) > 0) {
                        $country = getCountryByPhonePrefix($phones[0]);
                    }
                    echo htmlspecialchars($country, ENT_QUOTES, 'UTF-8');
                    if (!empty($phones)) {
                        echo " <span class='tooltip' title='" . htmlspecialchars(implode(", ", $phones), ENT_QUOTES, 'UTF-8') . "'>[?]</span>";
                    }
                    ?>
                </td>
                <td>
                    <?php
                    $emails = !empty($row['emails']) ? json_decode($row['emails'], true) : [];
                    if (is_array($emails) && count($emails) > 0) {
                        $firstEmail = $emails[0];
                        $moreCount  = count($emails) - 1;
                        echo htmlspecialchars($firstEmail, ENT_QUOTES, 'UTF-8');
                        if ($moreCount > 0) {
                            echo " (+" . $moreCount . ")";
                            echo "<span class='tooltip' title='" . htmlspecialchars(implode(", ", $emails), ENT_QUOTES, 'UTF-8') . "'>[?]</span>";
                        }
                    }
                    ?>
                </td>
                <td><?= ($row['is_online'] ?? 0) ? "Online" : "Offline" ?></td>
                <td>
                    <?php
                    $notes = !empty($row['note']) ? explode(";", $row['note']) : [];
                    $firstNote = trim($notes[0] ?? '');
                    if (count($notes) > 1) {
                        $others = implode("; ", array_slice($notes, 1));
                        echo htmlspecialchars($firstNote, ENT_QUOTES, 'UTF-8') . " (+" . (count($notes) - 1) . ")";
                        echo " <span class='tooltip' title='" . htmlspecialchars($others, ENT_QUOTES, 'UTF-8') . "'>[?]</span>";
                    } else {
                        echo htmlspecialchars($firstNote, ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1, 'ajax' => 1])) ?>">« Prev</a>
        <?php endif; ?>
        Page <?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars($totalPages, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1, 'ajax' => 1])) ?>">Next »</a>
        <?php endif; ?>
    </div>
    <!-- "Delete Selected" at bottom -->
    <div style="margin-top:10px;">
        <button type="button" class="btn btn-danger" onclick="bulkDelete()">Delete Selected</button>
    </div>
    <div style="margin-top:5px;">
        <button type="button" class="btn btn-secondary" onclick="cleanFilter()">Clean Filter</button>
        <button type="button" class="btn btn-secondary" onclick="reverifyFiltered()">Reverify (Filtered)</button>
    </div>
    <?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Domain List - Domain Manager</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .stats { margin-bottom: 15px; font-size: 16px; }
        .filter-container { background: #f7f7f7; padding: 15px; margin-bottom: 15px; border: 1px solid #ddd; }
        .filter-row { display: flex; gap: 20px; margin-bottom: 10px; }
        .filter-row > div { flex: 1; }
        .tag-container {
            display: flex; flex-wrap: wrap; border: 1px solid #ccc;
            min-height: 40px; padding: 5px; background: #fff;
        }
        .tag {
            background: #eee; margin: 3px; padding: 3px 6px;
            border-radius: 5px; display: inline-flex; align-items: center;
        }
        .removeTag { cursor: pointer; margin-left: 5px; color: red; }
        .tag-input { border: none; flex: 1; min-width: 60px; background: transparent; outline: none; }
        .button-group { margin-bottom: 15px; }
        .tooltip { cursor: help; border-bottom: 1px dotted #000; }
        .pagination a { margin: 0 5px; text-decoration: none; }
        #refreshTime { margin-bottom: 10px; font-style: italic; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">Domain Manager</div>
        <ul>
            <li><a href="add_domain.php">Add Domains</a></li>
            <li><a href="reverify.php">Reverify</a></li>
            <li><a href="admin_config.php">Config</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </div>
    <div class="main-content">
        <!-- Stats -->
        <div class="stats">
            <strong>Total Domains:</strong> <?= htmlspecialchars((string)$totalDomains, ENT_QUOTES, 'UTF-8') ?>&nbsp;
            <strong>Online:</strong> <?= htmlspecialchars((string)$onlineDomains, ENT_QUOTES, 'UTF-8') ?>&nbsp;
            <strong>Offline:</strong> <?= htmlspecialchars((string)$offlineDomains, ENT_QUOTES, 'UTF-8') ?>
        </div>
        
        <!-- Filter Form -->
        <div class="filter-container">
            <form method="get" action="list_domain.php" id="filterForm">
                <!-- Row 1: Domain, TLD -->
                <div class="filter-row">
                    <div>
                        <label>Domain (Tags, OR logic within domain field):</label>
                        <div id="domainContainer" class="tag-container"></div>
                        <input type="hidden" name="domain" id="domainHidden">
                    </div>
                    <div>
                        <label>TLD (Tags, OR logic):</label>
                        <div id="tldContainer" class="tag-container"></div>
                        <input type="hidden" name="tld" id="tldHidden">
                    </div>
                </div>
                <!-- Row 2: Language, Country -->
                <div class="filter-row">
                    <div>
                        <label>Language (Tags, OR logic):</label>
                        <div id="langContainer" class="tag-container"></div>
                        <input type="hidden" name="language" id="langHidden">
                    </div>
                    <div>
                        <label>Country (Tags, OR logic):</label>
                        <div id="countryContainer" class="tag-container"></div>
                        <input type="hidden" name="country" id="countryHidden">
                    </div>
                </div>
                <!-- Row 3: Note, Status -->
                <div class="filter-row">
                    <div>
                        <label>Note (Tags, OR logic):</label>
                        <div id="noteContainer" class="tag-container"></div>
                        <input type="hidden" name="note" id="noteHidden">
                    </div>
                    <div>
                        <label>Status (Tags, OR logic):</label>
                        <div id="statusContainer" class="tag-container"></div>
                        <input type="hidden" name="status" id="statusHidden">
                        <small>(online/offline)</small>
                    </div>
                </div>
                <!-- Row 4: Title & Keywords -->
                <div style="margin-top:10px;">
                    <label>Title &amp; Keywords (Tags, OR logic):</label>
                    <div id="kwContainer" class="tag-container"></div>
                    <input type="hidden" name="title_keywords" id="kwHidden">
                </div>
                <br>
                <label for="items">Items per page:</label>
                <select name="items" id="items">
                    <?php foreach ([100,50,200,500] as $count): ?>
                        <option value="<?= $count ?>" <?= ($itemsPerPage == $count ? 'selected' : '') ?>><?= $count ?></option>
                    <?php endforeach; ?>
                </select>
                <br><br>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>
        
        <!-- Refresh Time -->
        <div id="refreshTime">Last refreshed: <?= date("Y-m-d H:i:s") ?></div>
        
        <!-- Action Buttons -->
        <div class="button-group">
            <button type="button" class="btn btn-success" onclick="exportCSV()">Export CSV (Filtered)</button>
            <button type="button" class="btn btn-success" onclick="exportEmails()">Export Emails Only (Filtered)</button>
            <button type="button" class="btn btn-success" onclick="exportEmailsText()">Download Emails as TXT</button>
            <button type="button" class="btn btn-primary" onclick="reverifySelected()">Reverify Selected</button>
            <button type="button" class="btn btn-secondary" onclick="reverifyFiltered()">Reverify (Filtered)</button>
            <button type="button" class="btn btn-warning" onclick="cleanData()">Clean Data (Filtered)</button>
        </div>
        
        <div><strong>Domains matching filter:</strong> <?= htmlspecialchars((string)$totalFiltered, ENT_QUOTES, 'UTF-8') ?></div>
        
        <!-- Table Container -->
        <form id="bulkForm" method="post" action="delete_domains.php">
            <div id="table_container">
                <table border="1" cellpadding="5" cellspacing="0" width="100%">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                            <th>ID</th>
                            <th>Domain</th>
                            <th>TLD</th>
                            <th>Language</th>
                            <th>Country</th>
                            <th>Emails</th>
                            <th>Status</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayRows as $row): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= htmlspecialchars($row['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><?= htmlspecialchars($row['id'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['normalized_domain'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['tld'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['language'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                $phones  = !empty($row['phones']) ? json_decode($row['phones'], true) : [];
                                $country = 'Unknown';
                                if (is_array($phones) && count($phones) > 0) {
                                    $country = getCountryByPhonePrefix($phones[0]);
                                }
                                echo htmlspecialchars($country, ENT_QUOTES, 'UTF-8');
                                if (!empty($phones)) {
                                    echo " <span class='tooltip' title='" . htmlspecialchars(implode(", ", $phones), ENT_QUOTES, 'UTF-8') . "'>[?]</span>";
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $emails = !empty($row['emails']) ? json_decode($row['emails'], true) : [];
                                if (is_array($emails) && count($emails) > 0) {
                                    $firstEmail = $emails[0];
                                    $moreCount  = count($emails) - 1;
                                    echo htmlspecialchars($firstEmail, ENT_QUOTES, 'UTF-8');
                                    if ($moreCount > 0) {
                                        echo " (+" . $moreCount . ")";
                                        echo "<span class='tooltip' title='" . htmlspecialchars(implode(", ", $emails), ENT_QUOTES, 'UTF-8') . "'>[?]</span>";
                                    }
                                }
                                ?>
                            </td>
                            <td><?= ($row['is_online'] ?? 0) ? "Online" : "Offline" ?></td>
                            <td>
                                <?php
                                $notes = !empty($row['note']) ? explode(";", $row['note']) : [];
                                $firstNote = trim($notes[0] ?? '');
                                if (count($notes) > 1) {
                                    $others = implode("; ", array_slice($notes, 1));
                                    echo htmlspecialchars($firstNote, ENT_QUOTES, 'UTF-8') . " (+" . (count($notes) - 1) . ")";
                                    echo " <span class='tooltip' title='" . htmlspecialchars($others, ENT_QUOTES, 'UTF-8') . "'>[?]</span>";
                                } else {
                                    echo htmlspecialchars($firstNote, ENT_QUOTES, 'UTF-8');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">« Prev</a>
                    <?php endif; ?>
                    Page <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string)$totalPages, ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next »</a>
                    <?php endif; ?>
                </div>
            </div>
            <!-- "Delete Selected" at bottom -->
            <div style="margin-top:10px;">
                <button type="button" class="btn btn-danger" onclick="bulkDelete()">Delete Selected</button>
            </div>
            <div style="margin-top:5px;">
                <button type="button" class="btn btn-secondary" onclick="cleanFilter()">Clean Filter</button>
                <button type="button" class="btn btn-secondary" onclick="reverifyFiltered()">Reverify (Filtered)</button>
            </div>
        </form>
    </div>
    
    <script>
    // Tag-based container init with OR logic
    function initTagContainer(containerId, hiddenId, initialValue) {
        const container   = document.getElementById(containerId);
        const hiddenInput = document.getElementById(hiddenId);
        let tags = initialValue ? initialValue.split(",").map(t => t.trim()).filter(t => t !== "") : [];
        
        function renderTags(){
            container.innerHTML = "";
            tags.forEach((tag, idx) => {
                const tagDiv = document.createElement("div");
                tagDiv.className = "tag";
                tagDiv.innerHTML = tag + <span class="removeTag" data-index="${idx}">&times;</span>;
                container.appendChild(tagDiv);
            });
            const input = document.createElement("input");
            input.className = "tag-input";
            input.placeholder = "Add tag, Enter";
            container.appendChild(input);
            hiddenInput.value = tags.join(", ");
            input.focus();
            input.addEventListener("keydown", function(e){
                if(e.key === "Enter"){
                    e.preventDefault();
                    let val = input.value.trim();
                    if(val && !tags.includes(val)){
                        tags.push(val);
                        renderTags();
                    }
                }
            });
        }
        container.addEventListener("click", function(e){
            if(e.target.classList.contains("removeTag")){
                const idx = e.target.getAttribute("data-index");
                tags.splice(idx, 1);
                renderTags();
            }
        });
        renderTags();
    }
    
    document.addEventListener("DOMContentLoaded", function(){
        // Initialize tag containers for each field
        initTagContainer("domainContainer",   "domainHidden",   "<?= htmlspecialchars($domainFilter, ENT_QUOTES, 'UTF-8') ?>");
        initTagContainer("tldContainer",      "tldHidden",      "<?= htmlspecialchars($tldFilter, ENT_QUOTES, 'UTF-8') ?>");
        initTagContainer("langContainer",     "langHidden",     "<?= htmlspecialchars($langFilter, ENT_QUOTES, 'UTF-8') ?>");
        initTagContainer("countryContainer",  "countryHidden",  "<?= htmlspecialchars($countryFilter, ENT_QUOTES, 'UTF-8') ?>");
        initTagContainer("noteContainer",     "noteHidden",     "<?= htmlspecialchars($noteFilter, ENT_QUOTES, 'UTF-8') ?>");
        initTagContainer("statusContainer",   "statusHidden",   "<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>");
        initTagContainer("kwContainer",       "kwHidden",       "<?= htmlspecialchars($titleKeywordsFilter, ENT_QUOTES, 'UTF-8') ?>");
        
        // Auto-refresh table every 30 seconds
        setInterval(function(){
            const params = new URLSearchParams(window.location.search);
            params.set("ajax", "1");
            fetch("list_domain.php?" + params.toString())
                .then(r => r.text())
                .then(html => {
                    document.getElementById("table_container").innerHTML = html;
                    document.getElementById("refreshTime").innerText = "Last refreshed: " + new Date().toLocaleString();
                })
                .catch(console.error);
        }, 30000);
    });
    
    // Clean Filter => reload with no GET
    function cleanFilter(){
        window.location.href = "list_domain.php";
    }
    // Reverify(Filtered) => placeholder
    function reverifyFiltered(){
        alert("TODO: Reverify all filtered domains (OR logic).");
    }
    function toggleSelectAll(source){
        document.getElementsByName('ids[]').forEach(cb => cb.checked = source.checked);
    }
    function bulkDelete(){
        if(confirm("Delete selected domains?")){
            document.getElementById("bulkForm").submit();
        }
    }
    function exportCSV(){
        let selected = [];
        document.getElementsByName('ids[]').forEach(cb => { if(cb.checked) selected.push(cb.value); });
        let url = "export_csv.php";
        if(selected.length > 0){
            url += "?ids=" + selected.join(",");
        } else {
            url += "?" + new URLSearchParams(window.location.search).toString();
        }
        window.location.href = url;
    }
    function exportEmails(){
        let url = "export_csv.php?emailOnly=1&" + new URLSearchParams(window.location.search).toString();
        window.location.href = url;
    }
    function exportEmailsText(){
        let url = "export_csv.php?txtEmail=1&" + new URLSearchParams(window.location.search).toString();
        window.location.href = url;
    }
    function reverifySelected(){
        let selected = [];
        document.getElementsByName('ids[]').forEach(cb => { if(cb.checked) selected.push(cb.value); });
        if(!selected.length){
            alert("Select at least one domain to reverify.");
            return;
        }
        window.location.href = "reverify.php?ids=" + selected.join(",");
    }
    function cleanData(){
        if(confirm("Are you sure you want to clean data for filtered domains?")){
            let url = "export_csv.php?clean=1&" + new URLSearchParams(window.location.search).toString();
            window.location.href = url;
        }
    }
    </script>
</body>
</html>