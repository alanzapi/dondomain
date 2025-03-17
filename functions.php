
<?php
// includes/functions.php

/**
 * Load configuration options from config_options.json.
 */
function loadConfigOptions() {
    $configFile = __DIR__ . '/config_options.json';
    if (file_exists($configFile)) {
        $json = file_get_contents($configFile);
        $options = json_decode($json, true);
        if (is_array($options)) {
            return $options;
        }
    }
    // Fallback defaults
    return [
        'pagesToTry' => ["", "/contact", "/about", "/contact-us", "/about-us"],
        'emailExclude' => ["+", "top"],
        'phonePrefixes' => [
            '+1'  => 'USA/Canada',
            '+44' => 'United Kingdom',
            '+34' => 'Spain',
            '+33' => 'France',
            '+49' => 'Germany',
            '+39' => 'Italy'
        ],
        'searchKeywords' => []
    ];
}

/**
 * Normalize a domain by removing protocol (http/https), "www.", and trailing slash.
 */
function normalizeDomain($domain) {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/^www\./i', '', $domain);
    $domain = rtrim($domain, '/');
    return strtolower($domain);
}

/**
 * Extract the main domain (e.g., sub.domain.com -> domain.com).
 */
function extractMainDomain($domain) {
    $parts = explode('.', $domain);
    $num = count($parts);
    if ($num >= 2) {
        return $parts[$num - 2] . '.' . $parts[$num - 1];
    }
    return $domain;
}

/**
 * Get the TLD (last part) from a domain.
 */
function getDomainTLD($domain) {
    $parts = explode('.', $domain);
    return strtolower(end($parts));
}

/**
 * Extract a domain from an input string.
 * - If input has '@', assume email, return part after '@'.
 * - If input has '/', parse as URL and extract host.
 */
function extractDomainFromInput($input) {
    $input = trim($input);
    if (strpos($input, '@') !== false) {
        $parts = explode('@', $input);
        return trim($parts[1]);
    }
    if (strpos($input, '://') !== false) {
        $parts = parse_url($input);
        if (isset($parts['host'])) {
            return $parts['host'];
        }
    }
    if (strpos($input, '/') !== false) {
        $parts = parse_url("http://$input");
        if (isset($parts['host'])) {
            return $parts['host'];
        }
    }
    return $input;
}

/**
 * Extract top N keywords from text by frequency, ignoring certain stopwords/junk.
 */
function extractTopKeywords($content, $n = 5) {
    $text = strip_tags($content);
    $text = preg_replace('/[^\w\s]/u', '', $text);
    $text = mb_strtolower((string)$text, 'UTF-8');
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stopwords = ['the','and','for','with','that','this','from','have','are','was','but','not','you','your','has','had','can','its','our','all','their'];
    $junk = ['backgroundcolor','color','ffffff','woocommerce','ba8d43','et_pb_column','xrowinner','xanchortextprimary','cookies','moovegdprinfobarcontainer','moovegdprinfobarcontent'];
    $filtered = array_filter($words, function($w) use ($stopwords, $junk) {
        $w = trim($w);
        return mb_strlen($w, 'UTF-8') > 2 && !in_array($w, $stopwords) && !in_array($w, $junk);
    });
    $freq = array_count_values($filtered);
    arsort($freq);
    $top = array_slice(array_keys($freq), 0, $n);
    return implode(", ", $top);
}

/**
 * Scrape a domain using candidate pages. Returns array with:
 *  - emails (filtered),
 *  - phones,
 *  - language,
 *  - is_online,
 *  - title,
 *  - keywords.
 * If meta keywords not found, auto-extract top 5 from content.
 */
function scrapeDomainData($domain) {
    $options = loadConfigOptions();
    $data = [
        'emails' => [],
        'phones' => [],
        'language' => 'unknown',
        'is_online' => false,
        'title' => '',
        'keywords' => ''
    ];
    
    $pagesToTry = $options['pagesToTry'] ?? ["", "/contact", "/about", "/contact-us", "/about-us"];
    $allContent = "";
    
    $userAgent = "Mozilla/5.0 (Linux; Android 7.0; SM-G892A Build/NRD90M; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/60.0.3112.107 Mobile Safari/537.36";
    $headers = [
        "Connection: keep-alive",
        "Cache-Control: max-age=0",
        "Upgrade-Insecure-Requests: 1",
        "User-Agent: $userAgent",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8",
        "Accept-Encoding: gzip, deflate",
        "Accept-Language: en-US,en;q=0.9,fr;q=0.8",
        "Referer: https://www.google.com"
    ];
    
    foreach ($pagesToTry as $page) {
        $url = "http://$domain" . $page;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($content !== false && $httpCode >= 200 && $httpCode < 400) {
            $allContent .= " " . $content;
        }
    }
    if (!empty($allContent)) {
        $data['is_online'] = true;
        // Extract <title>
        if (preg_match('/<title>(.*?)<\/title>/si', $allContent, $mTitle)) {
            $tmpTitle = strip_tags($mTitle[1]);
            // Remove 4-byte chars
            $tmpTitle = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $tmpTitle);
            $data['title'] = substr(trim($tmpTitle), 0, 250);
        }
        // Extract <meta name="keywords" ...>
        if (preg_match('/<meta\s+name=["\']keywords["\']\s+content=["\'](.*?)["\']/si', $allContent, $mKw)) {
            $tmpKw = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $mKw[1]);
            $data['keywords'] = substr(trim($tmpKw), 0, 500);
        } else {
            // fallback
            $data['keywords'] = extractTopKeywords($allContent, 5);
        }
        // Regex for normal emails in text
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $allContent, $emailMatches);
        // Also catch mailto: references
        preg_match_all('/mailto\s*:\s*([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $allContent, $mailtoMatches);
        
        $foundEmails = array_merge($emailMatches[0], $mailtoMatches[1]);
        $exclude = $options['emailExclude'] ?? [];
        $validEmails = [];
        foreach ($foundEmails as $em) {
            // Filter out .png, .jpg, etc.
            if (!preg_match('/\.(png|jpg|gif|jpeg)$/i', $em)) {
                $skip = false;
                foreach ($exclude as $ex) {
                    if (stripos($em, $ex) !== false) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $validEmails[] = $em;
                }
            }
        }
        $data['emails'] = array_unique($validEmails);
        
        // Phone detection
        preg_match_all('/(?:tel:)?\s*(\+\d{1,3}[-\s]?\d{1,4}(?:[-\s]?\d{1,4}){1,3})/i', $allContent, $phoneMatches);
        $data['phones'] = !empty($phoneMatches[1]) ? array_unique($phoneMatches[1]) : [];
        
        // Simple language guess
        if (stripos($allContent, 'el ') !== false && stripos($allContent, 'la ') !== false) {
            $data['language'] = 'es';
        } else {
            $data['language'] = 'en';
        }
    }
    return $data;
}

/**
 * Merge newly discovered emails/phones with existing ones.
 */
function updateDomainStatus($pdo, $domainId, $data) {
    // Load existing phones/emails
    $stmt = $pdo->prepare("SELECT phones, emails FROM domain_details WHERE domain_id = ?");
    $stmt->execute([$domainId]);
    $existing = $stmt->fetch();
    
    $oldPhones = [];
    $oldEmails = [];
    if ($existing) {
        $oldPhones = !empty($existing['phones']) ? json_decode($existing['phones'], true) : [];
        $oldEmails = !empty($existing['emails']) ? json_decode($existing['emails'], true) : [];
    }
    // Merge
    $mergedPhones = array_unique(array_merge($oldPhones, $data['phones']));
    $mergedEmails = array_unique(array_merge($oldEmails, $data['emails']));
    
    $phonesJson = json_encode($mergedPhones);
    $emailsJson = json_encode($mergedEmails);
    
    $language = $data['language'];
    $isOnline = $data['is_online'] ? 1 : 0;
    
    if ($existing) {
        // Update row
        $stmt2 = $pdo->prepare("UPDATE domain_details
            SET language = ?, is_online = ?, phones = ?, emails = ?
            WHERE domain_id = ?");
        $stmt2->execute([$language, $isOnline, $phonesJson, $emailsJson, $domainId]);
    } else {
        // Insert new row
        $stmt3 = $pdo->prepare("INSERT INTO domain_details
            (domain_id, language, is_online, phones, emails)
            VALUES (?, ?, ?, ?, ?)");
        $stmt3->execute([$domainId, $language, $isOnline, $phonesJson, $emailsJson]);
    }
}

/**
 * Remove 4-byte chars from title/keywords to avoid MySQL error, then store.
 */
function updateDomainSiteInfo($pdo, $domainId, $title, $keywords) {
    $title = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', (string)$title);
    $keywords = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', (string)$keywords);
    $title = substr($title, 0, 250);
    $keywords = substr($keywords, 0, 500);
    $stmt = $pdo->prepare("UPDATE domains SET site_title = ?, site_keywords = ? WHERE id = ?");
    $stmt->execute([$title, $keywords, $domainId]);
}

/**
 * Return the country name based on phone prefix.
 */
function getCountryByPhonePrefix($phone) {
    $normalizedPhone = preg_replace('/[^\+\d]/', '', trim($phone));
    $options = loadConfigOptions();
    $prefixes = $options['phonePrefixes'] ?? [
        '+1' => 'USA/Canada',
        '+44'=> 'United Kingdom',
        '+34'=> 'Spain',
        '+33'=> 'France',
        '+49'=> 'Germany',
        '+39'=> 'Italy'
    ];
    foreach ($prefixes as $prefix => $country) {
        if (strpos($normalizedPhone, $prefix) === 0) {
            return $country;
        }
    }
    return 'Unknown';
}
?>

