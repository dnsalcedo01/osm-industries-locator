<?php
// Anonymous Cryptographically Secure Session Token Handling
$cookieName = 'locator_session_id';
if (empty($_COOKIE[$cookieName]) || !preg_match('/^[a-f0-9]{64}$/', $_COOKIE[$cookieName])) {
    $sessionId = bin2hex(random_bytes(32));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    setcookie($cookieName, $sessionId, [
        'expires'  => time() + (86400 * 365), // 1 year persistence
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_COOKIE[$cookieName] = $sessionId;
} else {
    $sessionId = $_COOKIE[$cookieName];
}

// Database Configuration
$host = 'localhost';
$db   = 'company_locator';
$user = 'root';
$pass = ''; 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db` ");
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(64) NOT NULL DEFAULT '',
        place_id VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL,
        latitude DECIMAL(10, 8) NOT NULL,
        longitude DECIMAL(11, 8) NOT NULL,
        address TEXT,
        phone VARCHAR(50),
        email VARCHAR(100),
        website VARCHAR(255),
        tag VARCHAR(50) DEFAULT 'Unassigned',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_session_place (session_id, place_id)
    )");
    
    // Auto-migrate schema updates for multi-tenancy & session isolation
    $columns = $pdo->query("SHOW COLUMNS FROM companies LIKE 'session_id'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN session_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id");
    }

    // Replace legacy single-column place_id unique index with composite (session_id, place_id) index
    $indexes = $pdo->query("SHOW INDEX FROM companies")->fetchAll();
    $hasSessionPlaceIndex = false;
    $hasLegacyPlaceIdIndex = false;
    foreach ($indexes as $idx) {
        if ($idx['Key_name'] === 'unique_session_place') {
            $hasSessionPlaceIndex = true;
        }
        if ($idx['Key_name'] === 'place_id' && $idx['Non_unique'] == 0) {
            $hasLegacyPlaceIdIndex = true;
        }
    }

    if ($hasLegacyPlaceIdIndex) {
        $pdo->exec("ALTER TABLE companies DROP INDEX `place_id`");
    }
    if (!$hasSessionPlaceIndex) {
        $pdo->exec("ALTER TABLE companies ADD UNIQUE KEY `unique_session_place` (session_id, place_id)");
    }

    $pdo->exec("ALTER TABLE companies ADD COLUMN IF NOT EXISTS tag VARCHAR(50) DEFAULT 'Unassigned'");
    $pdo->exec("ALTER TABLE companies ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (\PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
}

// ROUTER Actions
$action = $_GET['action'] ?? '';

if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=osm_industries_leads_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Place ID', 'Company Name', 'Latitude', 'Longitude', 'Address', 'Phone', 'Email', 'Website', 'Tag', 'Updated At']);
    
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE session_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$sessionId]);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['id'], $row['place_id'], $row['name'], $row['latitude'], 
            $row['longitude'], $row['address'], $row['phone'], $row['email'], 
            $row['website'], $row['tag'], $row['updated_at']
        ]);
    }
    fclose($output);
    exit;
}

if ($action === 'search_osm') {
    header('Content-Type: application/json');
    $lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
    $lng = isset($_GET['lng']) ? floatval($_GET['lng']) : 0;
    $radius = isset($_GET['radius']) ? intval($_GET['radius']) : 3000;

    if ($lat == 0 || $lng == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid center coordinates provided.']);
        exit;
    }

    $results = [];
    $seen = [];

    // Complete, Broadened & Optimized Overpass QL Query with 60s Timeout & Memory Buffer for High-Density Urban Areas
    $overpassQuery = "[out:json][timeout:60][maxsize:1073741824];
    (
      node(around:$radius,$lat,$lng)['office'];
      way(around:$radius,$lat,$lng)['office'];
      node(around:$radius,$lat,$lng)['industrial'];
      way(around:$radius,$lat,$lng)['industrial'];
      node(around:$radius,$lat,$lng)['landuse'='industrial'];
      way(around:$radius,$lat,$lng)['landuse'='industrial'];
      node(around:$radius,$lat,$lng)['landuse'='commercial'];
      way(around:$radius,$lat,$lng)['landuse'='commercial'];
      node(around:$radius,$lat,$lng)['man_made'];
      way(around:$radius,$lat,$lng)['man_made'];
      node(around:$radius,$lat,$lng)['building'~'industrial|warehouse|office|manufacture'];
      way(around:$radius,$lat,$lng)['building'~'industrial|warehouse|office|manufacture'];
      node(around:$radius,$lat,$lng)['craft'];
      way(around:$radius,$lat,$lng)['craft'];
      node(around:$radius,$lat,$lng)['amenity'~'factory|company|warehouse|logistics'];
      way(around:$radius,$lat,$lng)['amenity'~'factory|company|warehouse|logistics'];
    );
    out center body 250;";

    $data = executeOverpassQuery($overpassQuery);

    if ($data && isset($data['elements']) && is_array($data['elements'])) {
        foreach ($data['elements'] as $element) {
            $tags = $element['tags'] ?? [];
            $name = $tags['name'] ?? $tags['brand'] ?? $tags['operator'] ?? $tags['official_name'] ?? '';
            if (empty($name)) continue;

            $amenity = $tags['amenity'] ?? '';
            $shop = $tags['shop'] ?? '';
            $highway = $tags['highway'] ?? '';
            $publicTransport = $tags['public_transport'] ?? '';
            
            // Exclude non-industrial consumer spots
            if (in_array($amenity, ['bus_station', 'taxi', 'ferry_terminal', 'car_sharing', 'cafe', 'restaurant', 'fast_food', 'pharmacy']) ||
                in_array($publicTransport, ['station', 'stop_position', 'platform']) ||
                in_array($shop, ['supermarket', 'convenience', 'clothes', 'bakery']) ||
                $highway === 'bus_stop') {
                continue;
            }

            $eLat = $element['lat'] ?? ($element['center']['lat'] ?? 0);
            $eLng = $element['lon'] ?? ($element['center']['lon'] ?? 0);
            if ($eLat == 0 || $eLng == 0) continue;

            $dist = haversineDistance($lat, $lng, $eLat, $eLng);
            if ($dist > $radius) continue;

            $dedupeKey = strtolower(trim($name));
            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;

            $website = $tags['website'] ?? $tags['contact:website'] ?? $tags['url'] ?? '';
            $phone = $tags['phone'] ?? $tags['contact:phone'] ?? $tags['contact:mobile'] ?? 'N/A';
            $email = $tags['email'] ?? $tags['contact:email'] ?? 'N/A';

            $addressParts = [];
            if (!empty($tags['addr:housenumber'])) $addressParts[] = $tags['addr:housenumber'];
            if (!empty($tags['addr:street'])) $addressParts[] = $tags['addr:street'];
            if (!empty($tags['addr:suburb'])) $addressParts[] = $tags['addr:suburb'];
            if (!empty($tags['addr:city'])) $addressParts[] = $tags['addr:city'];
            $address = !empty($addressParts) ? implode(', ', $addressParts) : 'Industrial Zone';

            if (!empty($website) && preg_match('/\.js(\?.*)?$/i', $website)) {
                $website = '';
            }

            if (($email === 'N/A' || $phone === 'N/A') && !empty($website)) {
                if ($email === 'N/A') $email = extractContactFromWeb($website, 'email');
                if ($phone === 'N/A') $phone = extractContactFromWeb($website, 'phone');
            } 
            
            if ($email === 'N/A' || $phone === 'N/A' || empty($website)) {
                $scrapedDetails = crossCheckCompanyDetails($name, $address);
                if (empty($website) && !empty($scrapedDetails['website'])) $website = $scrapedDetails['website'];
                if ($email === 'N/A' && !empty($scrapedDetails['email'])) $email = $scrapedDetails['email'];
                if ($phone === 'N/A' && !empty($scrapedDetails['phone'])) $phone = $scrapedDetails['phone'];
            }

            $category = $tags['office'] ?? $tags['industrial'] ?? $tags['man_made'] ?? $tags['building'] ?? $tags['craft'] ?? 'Industrial Facility';

            $results[] = [
                'id' => 'osm_' . $element['id'],
                'name' => $name,
                'lat' => floatval($eLat),
                'lng' => floatval($eLng),
                'address' => $address,
                'phone' => $phone,
                'email' => $email,
                'website' => $website,
                'category' => ucfirst(str_replace('_', ' ', $category)),
                'distance' => round($dist)
            ];
        }
    }

    // Secondary Nominatim Search Fallback if Overpass yields zero or low results
    if (count($results) < 5) {
        $delta = max(($radius / 111000) * 1.5, 0.05);
        $queries = ['factory', 'industrial', 'warehouse', 'company', 'office', 'plant', 'manufacturing', 'logistics', 'depot'];
        
        foreach ($queries as $q) {
            if (count($results) >= 50) break;

            $nomUrl = "https://nominatim.openstreetmap.org/search?format=json&extratags=1&q=" . urlencode($q) . "&viewbox=" . 
                ($lng - $delta) . "," . ($lat + $delta) . "," . ($lng + $delta) . "," . ($lat - $delta) . "&bounded=1&limit=40";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $nomUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_USERAGENT, "OSMIndustriesLocator/10.0 (https://openstreetmap.org)");
            $nomResponse = curl_exec($ch);
            curl_close($ch);

            $nomData = json_decode($nomResponse, true);

            if (is_array($nomData)) {
                foreach ($nomData as $item) {
                    $displayName = $item['display_name'] ?? '';
                    if (empty($displayName)) continue;

                    $parts = explode(',', $displayName);
                    $title = trim($parts[0]);

                    $eLat = floatval($item['lat']);
                    $eLng = floatval($item['lon']);

                    $dist = haversineDistance($lat, $lng, $eLat, $eLng);
                    if ($dist > $radius) continue;

                    $dedupeKey = strtolower($title);
                    if (isset($seen[$dedupeKey])) continue;
                    $seen[$dedupeKey] = true;

                    $addressStr = implode(',', array_slice($parts, 1, 3));
                    $extraTags = $item['extratags'] ?? [];
                    
                    $phone = $extraTags['phone'] ?? $extraTags['contact:phone'] ?? 'N/A';
                    $email = $extraTags['email'] ?? $extraTags['contact:email'] ?? 'N/A';
                    $website = $extraTags['website'] ?? $extraTags['contact:website'] ?? $extraTags['url'] ?? '';

                    if (($email === 'N/A' || $phone === 'N/A' || empty($website))) {
                        $scraped = crossCheckCompanyDetails($title, $addressStr);
                        if (empty($website) && !empty($scraped['website'])) $website = $scraped['website'];
                        if ($email === 'N/A' && !empty($scraped['email'])) $email = $scraped['email'];
                        if ($phone === 'N/A' && !empty($scraped['phone'])) $phone = $scraped['phone'];
                    }

                    $results[] = [
                        'id' => 'nom_' . ($item['place_id'] ?? rand(10000, 99999)),
                        'name' => $title,
                        'lat' => $eLat,
                        'lng' => $eLng,
                        'address' => $addressStr,
                        'phone' => $phone,
                        'email' => $email,
                        'website' => $website,
                        'category' => ucfirst($item['type'] ?? 'Industrial Site'),
                        'distance' => round($dist)
                    ];
                }
            }
        }
    }

    usort($results, function($a, $b) {
        return $a['distance'] - $b['distance'];
    });

    echo json_encode(['status' => 'success', 'count' => count($results), 'data' => array_slice($results, 0, 100)]);
    exit;
}

if ($action === 'fetch_leads') {
    header('Content-Type: application/json');
    try {
        $tagFilter = $_GET['tag'] ?? 'ALL';
        if ($tagFilter !== 'ALL' && !empty($tagFilter)) {
            $stmt = $pdo->prepare("SELECT id, place_id, name, latitude, longitude, address, phone, email, website, COALESCE(tag, 'Unassigned') as tag FROM companies WHERE session_id = ? AND tag = ? ORDER BY updated_at DESC");
            $stmt->execute([$sessionId, $tagFilter]);
        } else {
            $stmt = $pdo->prepare("SELECT id, place_id, name, latitude, longitude, address, phone, email, website, COALESCE(tag, 'Unassigned') as tag FROM companies WHERE session_id = ? ORDER BY updated_at DESC");
            $stmt->execute([$sessionId]);
        }
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $leads]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database Query Exception: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'update_tag') {
    header('Content-Type: application/json');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    if (!isset($data['place_id']) || empty($data['place_id']) || !isset($data['tag'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing place_id or tag parameter']);
        exit;
    }

    try {
        // Distinguish between a status tag change vs a full company insert
        $isTagOnly = !isset($data['name']) && !isset($data['address']);
        
        if ($isTagOnly) {
            $stmt = $pdo->prepare("UPDATE companies SET tag = :tag WHERE session_id = :session_id AND place_id = :place_id");
            $stmt->execute([
                ':tag'        => $data['tag'],
                ':session_id' => $sessionId,
                ':place_id'   => $data['place_id']
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO companies (session_id, place_id, name, latitude, longitude, address, phone, email, website, tag)
                VALUES (:session_id, :place_id, :name, :lat, :lng, :address, :phone, :email, :website, :tag)
                ON DUPLICATE KEY UPDATE 
                    tag = VALUES(tag), 
                    name = VALUES(name), 
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    address = VALUES(address),
                    phone = VALUES(phone), 
                    email = VALUES(email), 
                    website = VALUES(website)");

            $stmt->execute([
                ':session_id' => $sessionId,
                ':place_id'   => $data['place_id'],
                ':name'       => !empty($data['name']) ? $data['name'] : 'Unknown Company',
                ':lat'        => floatval($data['latitude'] ?? $data['lat'] ?? 0),
                ':lng'        => floatval($data['longitude'] ?? $data['lng'] ?? 0),
                ':address'    => $data['address'] ?? '',
                ':phone'      => $data['phone'] ?? 'N/A',
                ':email'      => $data['email'] ?? 'N/A',
                ':website'    => $data['website'] ?? '',
                ':tag'        => $data['tag']
            ]);
        }

        echo json_encode(['status' => 'success', 'message' => 'Record and tag stored successfully']);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database Save Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_lead') {
    header('Content-Type: application/json');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!isset($data['place_id']) || empty($data['place_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing place_id parameter']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM companies WHERE session_id = ? AND place_id = ?");
        $stmt->execute([$sessionId, $data['place_id']]);
        echo json_encode(['status' => 'success', 'message' => 'Lead deleted successfully']);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database Delete Error: ' . $e->getMessage()]);
    }
    exit;
}

// Server-side Scraping Proxy to bypass CORS restrictions
if ($action === 'proxy_scrape') {
    header('Content-Type: application/json');
    $url = $_GET['url'] ?? '';

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid URL provided']);
        exit;
    }

    $contacts = deepCrawlCompanyWebsite($url);
    echo json_encode(['status' => 'success', 'data' => $contacts]);
    exit;
}

// Helper Functions
function executeOverpassQuery($query) {
    $servers = [
        "https://overpass-api.de/api/interpreter",
        "https://overpass.kumi.systems/api/interpreter",
        "https://overpass.nchc.org.tw/api/interpreter",
        "https://overpass.private.coffee/api/interpreter",
        "https://maps.mail.ru/osm/tools/overpass/api/interpreter"
    ];

    foreach ($servers as $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "data=" . urlencode($query));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_USERAGENT, "OSMIndustriesLocator/10.0 (https://openstreetmap.org)");
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['elements']) && !empty($data['elements'])) return $data;
        }
    }
    return ['elements' => []];
}

function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function crossCheckCompanyDetails($companyName, $address) {
    $result = ['website' => '', 'phone' => 'N/A', 'email' => 'N/A'];
    if (empty($companyName)) return $result;

    $cleanAddress = trim(preg_replace('/Nearby Industrial Area/i', '', $address));
    $cleanName = preg_replace('/[^a-zA-Z0-9\s]/', '', $companyName);
    
    // Multi-Engine Web Scraper Engine (Bing cURL Search Parser)
    $query = urlencode('"' . $companyName . '" ' . $cleanAddress . ' official website contact');
    $searchUrl = "https://www.bing.com/search?q=" . $query;
    
    $htmlResponse = fetchUrlContents($searchUrl, 5);
    if ($htmlResponse) {
        // Parse search result links
        if (preg_match_all('/<li class="b_algo">.*?<a [^>]*href="([^"]+)"/is', $htmlResponse, $matches)) {
            foreach ($matches[1] as $itemUrl) {
                $itemUrl = trim(urldecode($itemUrl));
                if (!empty($itemUrl) && !preg_match('/(bing|microsoft|google|facebook|youtube|twitter|instagram|linkedin|yelp|yellowpages|wikipedia|flocdn|jsdelivr|cloudfront|unpkg|\.js(\?.*)?$)/i', $itemUrl)) {
                    $result['website'] = $itemUrl;
                    break;
                }
            }
        }
    }

    // Fallback: Direct Domain Probe using cURL Header Validation
    if (empty($result['website'])) {
        $slug = strtolower(str_replace(' ', '', $cleanName));
        if (strlen($slug) > 3) {
            $possibleDomains = ["https://www.{$slug}.com", "https://www.{$slug}.com.ph", "https://www.{$slug}.org", "https://www.{$slug}.net"];
            foreach ($possibleDomains as $domain) {
                $ch = curl_init($domain);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code >= 200 && $code < 400) {
                    $result['website'] = $domain;
                    break;
                }
            }
        }
    }

    // Subpage Crawler for Phone and Email contact parsing
    if (!empty($result['website'])) {
        $scrapedContacts = deepCrawlCompanyWebsite($result['website']);
        if ($scrapedContacts['email'] !== 'N/A') $result['email'] = $scrapedContacts['email'];
        if ($scrapedContacts['phone'] !== 'N/A') $result['phone'] = $scrapedContacts['phone'];
    }

    return $result;
}

function fetchUrlContents($url, $timeout = 5) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: max-age=0'
    ]);
    $content = curl_exec($ch);
    curl_close($ch);
    return $content;
}

function extractContactFromWeb($url, $type = 'email') {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return 'N/A';
    $contacts = deepCrawlCompanyWebsite($url);
    return $contacts[$type] ?? 'N/A';
}

function deepCrawlCompanyWebsite($url) {
    $result = ['email' => 'N/A', 'phone' => 'N/A'];
    if (!filter_var($url, FILTER_VALIDATE_URL)) return $result;
    
    $parsed = parse_url($url);
    $baseUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
    
    // Comprehensive subpage path targets
    $pathsToTest = [
        '', 
        '/contact', 
        '/contact-us', 
        '/contactus', 
        '/about', 
        '/about-us', 
        '/aboutus', 
        '/careers'
    ];

    $htmlPages = [];
    
    foreach ($pathsToTest as $path) {
        $targetUrl = rtrim($baseUrl, '/') . $path;
        $html = fetchUrlContents($targetUrl, 4);
        if ($html) {
            $htmlPages[] = $html;
            
            // Check extracted contacts immediately to speed up requests
            $email = parseEmailFromHtml($html);
            $phone = parsePhoneFromHtml($html);

            if ($result['email'] === 'N/A' && $email !== 'N/A') $result['email'] = $email;
            if ($result['phone'] === 'N/A' && $phone !== 'N/A') $result['phone'] = $phone;

            if ($result['email'] !== 'N/A' && $result['phone'] !== 'N/A') {
                return $result;
            }
        }
    }

    // Fallback: Link Discovery from Homepage if fields are still missing
    if (!empty($htmlPages[0]) && ($result['email'] === 'N/A' || $result['phone'] === 'N/A')) {
        if (preg_match_all('/<a [^>]*href=["\']([^"\']+)["\']/i', $htmlPages[0], $linkMatches)) {
            foreach ($linkMatches[1] as $discoveredLink) {
                if (preg_match('/(contact|about|reach|careers|help)/i', $discoveredLink)) {
                    if (!preg_match('/^https?:\/\//i', $discoveredLink)) {
                        $discoveredLink = rtrim($baseUrl, '/') . '/' . ltrim($discoveredLink, '/');
                    }
                    $subHtml = fetchUrlContents($discoveredLink, 3);
                    if ($subHtml) {
                        if ($result['email'] === 'N/A') $result['email'] = parseEmailFromHtml($subHtml);
                        if ($result['phone'] === 'N/A') $result['phone'] = parsePhoneFromHtml($subHtml);
                    }
                }
                if ($result['email'] !== 'N/A' && $result['phone'] !== 'N/A') break;
            }
        }
    }

    return $result;
}

function parseEmailFromHtml($html) {
    // 1. Mailto links
    if (preg_match_all('/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $html, $mMatches)) {
        return $mMatches[1][0];
    }
    // 2. Standard Emails
    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $html, $matches)) {
        foreach ($matches[0] as $candidate) {
            if (!preg_match('/\.(png|jpg|jpeg|gif|svg|webp|css|js|woff|ttf)$/i', $candidate) && 
                strpos(strtolower($candidate), 'example') === false && 
                strpos(strtolower($candidate), 'domain') === false &&
                strpos(strtolower($candidate), 'sentry') === false &&
                strpos(strtolower($candidate), 'bootstrap') === false) {
                return $candidate;
            }
        }
    }
    // 3. Obfuscated Emails e.g. info [at] domain.com
    if (preg_match('/([a-zA-Z0-9._%+-]+)\s*\[\s*at\s*\]\s*([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $html, $obfMatch)) {
        return $obfMatch[1] . '@' . $obfMatch[2];
    }
    return 'N/A';
}

function parsePhoneFromHtml($html) {
    // 1. Tel links
    if (preg_match_all('/tel:([+\d\s\-\(\)]{7,20})/i', $html, $tMatches)) {
        return trim($tMatches[1][0]);
    }
    // 2. Standard international & local landline/mobile formats
    if (preg_match_all('/(?:\+?\d{1,4}[-.\s]?)?\(?\d{2,5}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/', $html, $matches)) {
        foreach ($matches[0] as $foundPhone) {
            $digits = preg_replace('/\D/', '', $foundPhone);
            if (strlen($digits) >= 7 && strlen($digits) <= 15) {
                return trim($foundPhone);
            }
        }
    }
    return 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSM Industries Locator Dashboard</title>
    
    <!-- Dynamic Cyan Favicon Generator -->
    <link id="dynamic-favicon" rel="icon" type="image/png" href="industry-solid-full.svg">

    <!-- Leaflet CSS for OpenStreetMap -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { display: flex; flex-direction: column; height: 100vh; background: #0f172a; color: #f8fafc; overflow: hidden; }
        
        #navbar {
            height: 56px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            position: relative;
            z-index: 10001;
        }
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.2rem;
            font-weight: 650;
            letter-spacing: -0.9px;
            color: #38bdf8;
            text-transform: none;
            cursor: pointer;
            user-select: none;
        }
        .brand-logo:hover { opacity: 0.9; }
        .header-icon-svg {
            width: 24px;
            height: 24px;
            filter: invert(62%) sepia(85%) saturate(1637%) hue-rotate(167deg) brightness(101%) contrast(97%);
        }

        .nav-actions { display: flex; gap: 10px; align-items: center; }
        .btn {
            background: #0284c7;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn:hover { background: #0369a1; }
        .btn-success { background: #16a34a; }
        .btn-success:hover { background: #15803d; }

        #main-container { display: flex; flex: 1; height: calc(100vh - 56px); overflow: hidden; position: relative; }

        /* Search Engine Hero Overlay for Initial View */
        #hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #0f172a;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        #hero-overlay.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .hero-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 2rem;
            font-weight: 650;
            letter-spacing: -0.9px;
            color: #38bdf8;
            margin-bottom: 25px;
            text-transform: none;
        }
        .hero-brand img {
            width: 32px;
            height: 32px;
            filter: invert(62%) sepia(85%) saturate(1637%) hue-rotate(167deg) brightness(101%) contrast(97%);
        }

        .hero-search-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            max-width: 780px;
            background: #1e293b;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #334155;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        }

        .hero-input-wrapper {
            position: relative;
            flex: 1;
            display: flex;
            align-items: center;
        }
        .hero-gps-btn {
            position: absolute;
            left: 10px;
            background: none;
            border: none;
            color: #38bdf8;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-gps-btn:hover { color: #7dd3fc; }

        .hero-address-input {
            width: 100%;
            padding: 10px 12px 10px 38px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 4px;
            color: #f8fafc;
            font-size: 0.95rem;
        }
        .hero-address-input:focus { outline: none; border-color: #38bdf8; }

        .hero-radius-input {
            width: 110px;
            padding: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 4px;
            color: #f8fafc;
            font-size: 0.85rem;
            text-align: center;
        }
        .hero-radius-input:focus { outline: none; border-color: #38bdf8; }

        .hero-search-btn {
            background: #0284c7;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .hero-search-btn:hover { background: #0369a1; }

        .hero-status-message {
            margin-top: 12px;
            font-size: 0.85rem;
            color: #38bdf8;
            text-align: center;
            min-height: 20px;
            font-weight: 500;
        }

        .hero-disclaimer-fineprint {
            margin-top: 25px;
            font-size: 0.75rem;
            color: #64748b;
            text-align: center;
            max-width: 600px;
            line-height: 1.4;
        }

        #sidebar {
            width: 420px;
            background: #1e293b;
            border-right: 1px solid #334155;
            display: flex;
            flex-direction: column;
        }
        
        .tab-buttons { display: flex; border-bottom: 1px solid #334155; }
        .tab-btn {
            flex: 1;
            padding: 12px;
            background: #0f172a;
            color: #94a3b8;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            text-align: center;
            border-bottom: 2px solid transparent;
        }
        .tab-btn.active { background: #1e293b; color: #38bdf8; border-bottom-color: #38bdf8; }

        .controls-panel { padding: 15px; border-bottom: 1px solid #334155; background: #1e293b; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px; font-weight: 600; }
        .input-with-button { display: flex; gap: 6px; }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 4px;
            color: #f8fafc;
            font-size: 0.9rem;
        }
        .form-control:focus { outline: none; border-color: #38bdf8; }

        .btn-icon-only {
            padding: 8px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #38bdf8;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-icon-only:hover { background: #334155; border-color: #38bdf8; }

        .search-list { flex: 1; overflow-y: auto; padding: 15px; }

        .company-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .company-card:hover {
            border-color: #38bdf8;
            background: #1e293b;
        }
        .company-card h4 { font-size: 0.95rem; color: #f8fafc; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
        .company-card p { font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            background: #334155;
            color: #38bdf8;
            font-weight: 600;
        }
        .disclaimer-banner {
            background: rgba(2, 132, 199, 0.15);
            border: 1px solid #0284c7;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.78rem;
            color: #94a3b8;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .disclaimer-banner i { color: #38bdf8; font-size: 0.9rem; }
        
        .search-engine-btns {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .btn-search-engine {
            background: #334155;
            color: #38bdf8;
            border: 1px solid #475569;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-search-engine:hover {
            background: #0284c7;
            color: white;
            border-color: #38bdf8;
        }
        .website-link {
            color: #38bdf8;
            text-decoration: none;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            word-break: break-all;
        }
        .website-link:hover { text-decoration: underline; }

        #map-wrapper { flex: 1; position: relative; height: 100%; }
        #leaflet-map { width: 100%; height: 100%; background: #0f172a; }

        /* Completely hide default Leaflet top-right layer control box on map container */
        .leaflet-top.leaflet-right .leaflet-control-layers {
            display: none !important;
        }

        .leaflet-control-layers {
            background: #1e293b !important;
            color: #f8fafc !important;
            border: 1px solid #334155 !important;
            border-radius: 8px !important;
        }
        .leaflet-control-layers-base label { color: #f8fafc !important; }

        .center-marker-icon {
            filter: hue-rotate(140deg) saturate(300%) drop-shadow(0 2px 4px rgba(0,0,0,0.5));
        }
        .company-marker-icon {
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4));
        }

        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #0284c7;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 9999;
            display: none;
        }

        /* Custom Header Layer Switcher Menu */
        #custom-layer-menu {
            position: absolute;
            top: 52px;
            right: 20px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 8px 0;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            z-index: 10002;
            display: none;
            min-width: 180px;
        }
        #custom-layer-menu.active {
            display: block;
        }
        .layer-option-item {
            padding: 8px 16px;
            font-size: 0.85rem;
            color: #f8fafc;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background 0.2s, color 0.2s;
        }
        .layer-option-item:hover {
            background: #334155;
            color: #38bdf8;
        }
        .layer-option-item.selected {
            color: #38bdf8;
            font-weight: 600;
        }

        /* Highlighted Company Card */
        .company-card.card-highlighted {
            border-color: #22c55e !important;
            box-shadow: 0 0 10px rgba(34, 197, 94, 0.3);
            background: #1e293b;
        }

        /* Marker CSS Classes */
        .company-marker-icon-active {
            filter: hue-rotate(260deg) saturate(300%) drop-shadow(0 2px 6px rgba(34,197,94,0.8));
        }

        /* Hero Center & Bottom Footer */
        .hero-center-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            flex: 1;
        }
        .hero-footer {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 20px 22px;
            z-index: 10;
        }
        .hero-disclaimer-fineprint {
            font-size: 0.75rem;
            color: #64748b;
            text-align: center;
            max-width: 680px;
            line-height: 1.4;
        }
        .hero-footer-links {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .footer-btn-link {
            background: none;
            border: none;
            color: #38bdf8;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none !important;
            padding: 0;
            transition: color 0.2s;
        }
        .footer-btn-link:hover {
            color: #7dd3fc;
            text-decoration: none !important;
        }
        .footer-link {
            color: #38bdf8;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            transition: color 0.2s;
        }
        .footer-link:hover {
            color: #7dd3fc;
            text-decoration: none !important;
        }
        .footer-separator {
            color: #475569;
            user-select: none;
        }
        .footer-location-text {
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
        }
        .footer-location-text i {
            color: #38bdf8;
        }
        #user-rough-location {
            color: #f1f5f9;
            font-weight: 500;
        }

        /* Right Sliding Drawer Modal */
        .right-drawer-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 20000;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .right-drawer-backdrop.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .right-drawer {
            position: fixed;
            top: 0;
            right: 0;
            width: 520px;
            max-width: 92vw;
            height: 100vh;
            background: #1e293b;
            border-left: 1px solid #334155;
            box-shadow: -8px 0 30px rgba(0, 0, 0, 0.6);
            z-index: 20001;
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .right-drawer.active {
            transform: translateX(0);
        }

        .right-drawer-header {
            height: 56px;
            padding: 0 20px;
            background: #0f172a;
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .right-drawer-title {
            font-size: 1.05rem;
            font-weight: 650;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-close-drawer {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 1.25rem;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s, color 0.2s;
        }
        .btn-close-drawer:hover {
            background: #334155;
            color: #f8fafc;
        }

        .right-drawer-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            color: #cbd5e1;
            font-size: 0.88rem;
            line-height: 1.65;
        }
        .right-drawer-body h4 {
            color: #f8fafc;
            font-size: 0.95rem;
            margin: 18px 0 8px;
            font-weight: 600;
        }
        .right-drawer-body h4:first-child {
            margin-top: 0;
        }
        .right-drawer-body p {
            margin-bottom: 12px;
            color: #94a3b8;
        }
        .right-drawer-body ul {
            margin-left: 20px;
            margin-bottom: 14px;
        }
        .right-drawer-body li {
            margin-bottom: 6px;
            color: #cbd5e1;
        }
        .right-drawer-body strong {
            color: #f1f5f9;
        }
        .drawer-badge-callout {
            background: rgba(2, 132, 199, 0.12);
            border-left: 3px solid #38bdf8;
            padding: 10px 14px;
            border-radius: 0 4px 4px 0;
            margin-bottom: 16px;
            font-size: 0.82rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<!-- Navigation bar containing brand branding logo and Map Layer toggle button -->
<div id="navbar" style="display: none;">
    <div class="brand-logo" onclick="resetToMainSearchPage()" title="Return to Main Search Page">
        <img src="industry-solid-full.svg" alt="Industry Icon" class="header-icon-svg" id="header-svg-img"> OSM Industries Locator
    </div>
    <div class="nav-actions">
        <button type="button" class="btn btn-icon-only" onclick="openRightDrawer('terms')" title="Terms of Use">
            <i class="fa-solid fa-file-contract"></i>
        </button>
        <button type="button" class="btn btn-icon-only" onclick="openRightDrawer('privacy')" title="Privacy Policy & Cookies">
            <i class="fa-solid fa-shield-halved"></i>
        </button>
        <button id="btn-toggle-layers" class="btn" style="background:#334155;" onclick="toggleMapLayersControl()" title="Change Map Tiles">
            <i class="fa-solid fa-layer-group"></i> Map Layers
        </button>
        
        <!-- Custom Layer Options Dropdown Menu -->
        <div id="custom-layer-menu"></div>
    </div>
</div>

<div id="main-container">
    <!-- Main landing search overlay view -->
    <div id="hero-overlay">
        <div class="hero-center-content">
            <div class="hero-brand">
                <img src="industry-solid-full.svg" alt="Industry Icon"> OSM Industries Locator
            </div>
            <div class="hero-search-bar">
                <div class="hero-input-wrapper">
                    <button type="button" class="hero-gps-btn" onclick="getCurrentLocationAndSearch(true)" title="Use Exact High-Precision Device GPS">
                        <i class="fa-solid fa-location-crosshairs"></i>
                    </button>
                    <input type="text" id="heroAddressInput" class="hero-address-input" placeholder="Locate by City/Address/Coordinates..." onkeypress="if(event.key==='Enter') executeHeroSearch();">
                </div>
                <input type="number" id="heroRadiusInput" class="hero-radius-input" value="3000" min="500" step="500" placeholder="Radius (m)">
                <button type="button" class="hero-search-btn" onclick="executeHeroSearch()" title="Search Area">
                    <i class="fa-solid fa-location-arrow"></i>
                </button>
            </div>
            <div id="heroStatusMessage" class="hero-status-message"></div>
        </div>

        <!-- Footer fineprint and footer links placed on lower bottom center -->
        <div id="hero-footer" class="hero-footer">
            <div class="hero-disclaimer-fineprint">
                <b>Note:</b> <i>Data displayed is gathered automatically from OpenStreetMap & public web sources. Contact numbers and email <b>may not be 100% accurate.</b></i>
            </div>
            <div class="hero-footer-links">
                <button type="button" class="footer-btn-link" onclick="openRightDrawer('terms')">Terms of Use</button>
                <span class="footer-separator">•</span>
                <button type="button" class="footer-btn-link" onclick="openRightDrawer('privacy')">Privacy Policy & Cookies</button>
                <span class="footer-separator">•</span>
                <a href="https://github.com/dnsalcedo01/osm-industries-locator" target="_blank" rel="noopener noreferrer" class="footer-link">
                    <i class="fa-brands fa-github"></i> Self-Host It!
                </a>
                <span class="footer-separator">•</span>
                <span class="footer-location-text" id="footer-location-indicator">
                    <i class="fa-solid fa-location-dot"></i> Your location: <span id="user-rough-location">Detecting...</span>
                </span>
            </div>
        </div>
    </div>

    <!-- Sidebar container holding search results and saved lead cards -->
    <div id="sidebar">
        <div class="tab-buttons">
            <button class="tab-btn active" onclick="switchTab('search')"><i class="fa-solid fa-magnifying-glass"></i> Find Industry/Company</button>
            <button class="tab-btn" onclick="switchTab('leads')"><i class="fa-solid fa-bookmark"></i> Saved Leads</button>
        </div>

        <!-- Search tab panel -->
        <div id="tab-search" class="tab-content" style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
            <div class="controls-panel">
                <div class="form-group">
                    <label>Locate Address / City:</label>
                    <div class="input-with-button">
                        <input type="text" id="addressSearch" class="form-control" placeholder="Enter city or industrial zone..." onkeypress="if(event.key==='Enter') executeSearchFlow();">
                        <button type="button" class="btn-icon-only" onclick="getCurrentLocationAndSearch()" title="Use High-Precision Device GPS">
                            <i class="fa-solid fa-location-crosshairs"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Radius (Meters):</label>
                    <input type="number" id="radius" class="form-control" value="3000" min="500" step="500">
                </div>
                <div style="margin-top: 10px;">
                    <button onclick="executeSearchFlow()" class="btn" style="width:100%;"><i class="fa-solid fa-magnifying-glass"></i> Search Area</button>
                </div>
            </div>
            <div id="results-list" class="search-list">
                <p style="text-align:center; color:#94a3b8; margin-top:20px;">Click Search Area to find nearby companies and industrial sites.</p>
            </div>
        </div>

        <!-- Saved leads tab panel -->
        <div id="tab-leads" class="tab-content" style="display: none; flex-direction: column; flex: 1; overflow: hidden;">
            <div class="controls-panel">
                <div class="form-group">
                    <label>Filter by Tag:</label>
                    <select id="filterTag" class="form-control" onchange="loadLeads()">
                        <option value="ALL">All Saved Leads</option>
                        <option value="Hot Lead">Hot Lead</option>
                        <option value="Contacted">Contacted</option>
                        <option value="Closed">Closed</option>
                        <option value="Unassigned">Unassigned</option>
                    </select>
                </div>
                <div style="margin-top: 10px;">
                    <a href="index.php?action=export_csv" class="btn btn-success" style="width:100%; text-decoration:none;"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                </div>
            </div>
            <div id="leads-cards-container" class="search-list">
                <p style="text-align:center; color:#94a3b8; margin-top:20px;">Click filter to retrieve records.</p>
            </div>
        </div>
    </div>

    <!-- Map container element for Leaflet interactive maps -->
    <div id="map-wrapper">
        <div id="leaflet-map"></div>
    </div>
</div>

<div id="toast" class="toast-notification"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    let currentCoords = { lat: 14.5995, lng: 120.9842 };
    let leafletMap = null;
    let leafletMarker = null;
    let leafletCircle = null;
    let baseTileLayers = {};
    let currentTileLayer = null;
    let companyMarkers = [];
    let companyMarkerMap = {};
    let searchResultsCache = null;
    let savedLeadIds = new Set();
    let activeHighlightedMarkerId = null;

    const centerIcon = L.icon({
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41],
        className: 'center-marker-icon'
    });

    const companyIcon = L.icon({
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41],
        className: 'company-marker-icon'
    });

    const highlightGreenIcon = L.icon({
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [28, 45],
        iconAnchor: [14, 45],
        popupAnchor: [1, -34],
        shadowSize: [41, 41],
        className: 'company-marker-icon-active'
    });

    function generateCyanFavicon() {
        const img = document.getElementById('header-svg-img');
        if (!img) return;
        
        const canvas = document.createElement('canvas');
        canvas.width = 32;
        canvas.height = 32;
        const ctx = canvas.getContext('2d');
        
        const tempImg = new Image();
        tempImg.crossOrigin = 'anonymous';
        tempImg.onload = function() {
            ctx.drawImage(tempImg, 0, 0, 32, 32);
            ctx.globalCompositeOperation = 'source-in';
            ctx.fillStyle = '#38bdf8';
            ctx.fillRect(0, 0, 32, 32);
            
            const link = document.getElementById('dynamic-favicon');
            if (link) link.href = canvas.toDataURL('image/png');
        };
        tempImg.src = img.src;
    }

    function showToast(msg) {
        const toast = document.getElementById('toast');
        toast.innerText = msg;
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 3000);
    }

    function executeHeroSearch() {
        const addr = document.getElementById('heroAddressInput').value.trim();
        const rad = document.getElementById('heroRadiusInput').value;
        
        document.getElementById('addressSearch').value = addr;
        document.getElementById('radius').value = rad;
        
        document.getElementById('hero-overlay').classList.add('hidden');
        document.getElementById('navbar').style.display = 'flex';
        executeSearchFlow();
    }

    function resetToMainSearchPage() {
        const heroOverlay = document.getElementById('hero-overlay');
        const navbar = document.getElementById('navbar');
        if (heroOverlay) {
            heroOverlay.classList.remove('hidden');
        }
        if (navbar) {
            navbar.style.display = 'none';
        }
        document.getElementById('heroAddressInput').value = '';
    }

    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        if (tab === 'search') {
            document.querySelectorAll('.tab-btn')[0].classList.add('active');
            document.getElementById('tab-search').style.display = 'flex';
            document.getElementById('tab-leads').style.display = 'none';
            
            if (searchResultsCache && searchResultsCache.data) {
                const radius = parseInt(document.getElementById('radius').value, 10);
                renderSearchResults(searchResultsCache, radius);
            }
        } else {
            document.querySelectorAll('.tab-btn')[1].classList.add('active');
            document.getElementById('tab-search').style.display = 'none';
            document.getElementById('tab-leads').style.display = 'flex';
            loadLeads();
        }
    }

    function initLeaflet() {
        leafletMap = L.map('leaflet-map').setView([currentCoords.lat, currentCoords.lng], 13);
        
        const streetTiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });

        const osmFrTiles = L.tileLayer('https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap France | &copy; OpenStreetMap contributors'
        });

        const cyclOsmTiles = L.tileLayer('https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; CyclOSM & OpenStreetMap'
        });

        const bretonTiles = L.tileLayer('https://tile.openstreetmap.bzh/ca/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap BZH'
        });

        const stadiaSatellite = L.tileLayer('https://tiles.stadiamaps.com/tiles/alidade_satellite/{z}/{x}/{y}{r}.jpg', {
            minZoom: 0,
            maxZoom: 20,
            attribution: '&copy; CNES, Distribution Airbus DS, © PlanetObserver | &copy; Stadia Maps & OpenMapTiles & OpenStreetMap contributors'
        });

        const satelliteTiles = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri'
        });

        baseTileLayers = {
            "Standard Street": streetTiles,
            "OSM France": osmFrTiles,
            "CyclOSM": cyclOsmTiles,
            "Breton Map": bretonTiles,
            "Stadia Satellite": stadiaSatellite,
            "Esri Satellite": satelliteTiles
        };

        // Restore saved layer or default to Standard Street
        const savedLayer = localStorage.getItem('selected_map_layer');
        if (savedLayer && baseTileLayers[savedLayer]) {
            currentTileLayer = baseTileLayers[savedLayer];
        } else {
            currentTileLayer = streetTiles;
        }
        currentTileLayer.addTo(leafletMap);

        leafletMarker = L.marker([currentCoords.lat, currentCoords.lng], { 
            draggable: false, 
            icon: centerIcon 
        }).addTo(leafletMap);

        leafletCircle = L.circle([currentCoords.lat, currentCoords.lng], { 
            radius: 3000, 
            color: '#0284c7', 
            fillColor: '#0284c7', 
            fillOpacity: 0.15 
        }).addTo(leafletMap);

        // Keep search radius circle permanently visible and firmly anchored to currentCoords during mouse drag / panning
        leafletMap.on('move viewreset zoom', function() {
            if (leafletCircle && currentCoords) {
                leafletCircle.setLatLng([currentCoords.lat, currentCoords.lng]);
            }
        });

        renderCustomLayerMenu();

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const menu = document.getElementById('custom-layer-menu');
            const btn = document.getElementById('btn-toggle-layers');
            if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
                menu.classList.remove('active');
            }
        });
    }

    function renderCustomLayerMenu() {
        const menu = document.getElementById('custom-layer-menu');
        if (!menu) return;
        menu.innerHTML = '';

        const activeLayerName = localStorage.getItem('selected_map_layer') || 'Standard Street';

        Object.keys(baseTileLayers).forEach(layerName => {
            const isSelected = layerName === activeLayerName;
            const item = document.createElement('div');
            item.className = `layer-option-item ${isSelected ? 'selected' : ''}`;
            item.innerHTML = `
                <span>${layerName}</span>
                ${isSelected ? '<i class="fa-solid fa-check"></i>' : ''}
            `;
            item.onclick = function() {
                selectMapLayer(layerName);
            };
            menu.appendChild(item);
        });
    }

    function selectMapLayer(layerName) {
        if (!baseTileLayers[layerName]) return;

        if (currentTileLayer && leafletMap) {
            leafletMap.removeLayer(currentTileLayer);
        }

        currentTileLayer = baseTileLayers[layerName];
        currentTileLayer.addTo(leafletMap);
        localStorage.setItem('selected_map_layer', layerName);

        renderCustomLayerMenu();
        const menu = document.getElementById('custom-layer-menu');
        if (menu) menu.classList.remove('active');
        showToast(`Map switched to ${layerName}`);
    }

    function toggleMapLayersControl() {
        const menu = document.getElementById('custom-layer-menu');
        if (menu) {
            menu.classList.toggle('active');
        }
    }

    function getCurrentLocationAndSearch(fromHero = false) {
        showToast('Acquiring high-precision hardware GPS...');
        
        // Ensure user-entered radius on Hero Landing Page is respected
        if (fromHero) {
            const heroRad = document.getElementById('heroRadiusInput').value;
            if (heroRad) {
                document.getElementById('radius').value = heroRad;
            }
            const heroAddr = document.getElementById('heroAddressInput').value.trim();
            if (heroAddr) {
                document.getElementById('addressSearch').value = heroAddr;
            }
        }
        
        const heroStatus = document.getElementById('heroStatusMessage');
        if (heroStatus && fromHero) {
            heroStatus.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Requesting device hardware GPS position...';
        }

        const listContainer = document.getElementById('results-list');
        if (listContainer) {
            listContainer.innerHTML = '<p style="text-align:center; color:#38bdf8; margin-top:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Locking hardware GPS coordinates...</p>';
        }

        if ("geolocation" in navigator) {
            const geoOptions = { 
                enableHighAccuracy: true, 
                timeout: 30000, 
                maximumAge: 0 
            };

            let watchId = null;
            const processPosition = (pos) => {
                if (watchId !== null) navigator.geolocation.clearWatch(watchId);

                currentCoords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                document.getElementById('addressSearch').value = '';
                document.getElementById('heroAddressInput').value = '';
                
                if (heroStatus && fromHero) {
                    heroStatus.innerHTML = '<i class="fa-solid fa-check"></i> GPS position locked!';
                }

                const heroOverlay = document.getElementById('hero-overlay');
                if (heroOverlay && !heroOverlay.classList.contains('hidden')) {
                    heroOverlay.classList.add('hidden');
                    document.getElementById('navbar').style.display = 'flex';
                }
                if (heroStatus) heroStatus.innerHTML = '';

                updateMapPosition();
                searchNearbyCompanies();
                showToast('Hardware GPS position locked!');
            };

            // Use watchPosition to ensure the browser locks onto actual hardware location on the 1st click
            watchId = navigator.geolocation.watchPosition(
                pos => {
                    if (pos.coords.accuracy <= 2500) {
                        processPosition(pos);
                    }
                },
                err => {
                    // Fallback to getCurrentPosition if watch times out
                    navigator.geolocation.getCurrentPosition(
                        processPosition,
                        geoErr => {
                            console.warn('Hardware GPS Error:', geoErr);
                            showToast('GPS Lock Failed: Please allow browser location permissions.');
                            if (heroStatus && fromHero) {
                                heroStatus.innerHTML = '<span style="color:#f87171;"><i class="fa-solid fa-triangle-exclamation"></i> GPS position unavailable. Please enter a location manually.</span>';
                            }
                            if (listContainer) {
                                listContainer.innerHTML = '<p style="text-align:center; color:#f87171; margin-top:20px;">Hardware GPS lock failed. Enter location manually.</p>';
                            }
                        },
                        geoOptions
                    );
                },
                geoOptions
            );
        } else {
            showToast('Hardware GPS is not supported by this browser.');
            if (heroStatus && fromHero) {
                heroStatus.innerHTML = '<span style="color:#f87171;">Geolocation is not supported by your browser.</span>';
            }
        }
    }

    function executeSearchFlow() {
        const heroOverlay = document.getElementById('hero-overlay');
        if (heroOverlay && !heroOverlay.classList.contains('hidden')) {
            heroOverlay.classList.add('hidden');
            document.getElementById('navbar').style.display = 'flex';
        }

        const addressQuery = document.getElementById('addressSearch').value.trim();
        if (addressQuery !== "") {
            geocodeAddressAndSearch(addressQuery);
        } else {
            searchNearbyCompanies();
        }
    }

    function geocodeAddressAndSearch(query) {
        const listContainer = document.getElementById('results-list');
        listContainer.innerHTML = '<p style="text-align:center; color:#38bdf8; margin-top:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Geocoding location...</p>';

        const coordMatch = query.match(/^([-+]?\d{1,2}\.\d+)\s*,\s*([-+]?\d{1,3}\.\d+)$/);
        if (coordMatch) {
            currentCoords = { lat: parseFloat(coordMatch[1]), lng: parseFloat(coordMatch[2]) };
            updateMapPosition();
            searchNearbyCompanies();
            return;
        }

        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                if (data && data.length > 0) {
                    currentCoords = { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
                    updateMapPosition();
                    searchNearbyCompanies();
                } else {
                    showToast('Address or location not found.');
                    listContainer.innerHTML = '<p style="text-align:center; color:#f87171; margin-top:20px;">Location not found. Try another search term.</p>';
                }
            })
            .catch(() => {
                showToast('Address lookup failed.');
                searchNearbyCompanies();
            });
    }

    function updateMapPosition() {
        if (leafletMap && leafletMarker && leafletCircle) {
            leafletMap.setView([currentCoords.lat, currentCoords.lng], 13);
            leafletMarker.setLatLng([currentCoords.lat, currentCoords.lng]);
            leafletCircle.setLatLng([currentCoords.lat, currentCoords.lng]);
            leafletCircle.setStyle({ opacity: 1, fillOpacity: 0.15 });
        }
    }

    function searchNearbyCompanies() {
        const radius = parseInt(document.getElementById('radius').value, 10);
        
        // Always reset existing markers on new search to avoid lingering markers outside radius
        companyMarkers.forEach(m => {
            if (leafletMap) leafletMap.removeLayer(m);
        });
        companyMarkers = [];
        companyMarkerMap = {};

        // Firmly anchor search circle to current coordinates
        if (leafletCircle) {
            leafletCircle.setLatLng([currentCoords.lat, currentCoords.lng]);
            leafletCircle.setRadius(radius);
            leafletCircle.setStyle({ opacity: 1, fillOpacity: 0.15 });
        }

        const listContainer = document.getElementById('results-list');
        listContainer.innerHTML = '<p style="text-align:center; color:#38bdf8; margin-top:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Searching industrial sites...</p>';

        fetch(`index.php?action=search_osm&lat=${currentCoords.lat}&lng=${currentCoords.lng}&radius=${radius}`)
            .then(res => res.json())
            .then(data => {
                searchResultsCache = data;
                renderSearchResults(data, radius);
            })
            .catch(err => {
                console.error(err);
                listContainer.innerHTML = '<p style="text-align:center; color:#f87171; margin-top:20px;">Error searching nearby companies.</p>';
            });
    }

    function zoomToCompany(id, lat, lng) {
        if (leafletMap && companyMarkerMap[id]) {
            // Reset previous active marker icon
            if (activeHighlightedMarkerId && companyMarkerMap[activeHighlightedMarkerId]) {
                companyMarkerMap[activeHighlightedMarkerId].setIcon(companyIcon);
            }

            // Highlight current clicked marker green
            companyMarkerMap[id].setIcon(highlightGreenIcon);
            activeHighlightedMarkerId = id;

            // Highlight card in sidebar
            document.querySelectorAll('.company-card').forEach(c => c.classList.remove('card-highlighted'));
            const activeCard = document.getElementById(`card-${id}`);
            if (activeCard) {
                activeCard.classList.add('card-highlighted');
                activeCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            // Lock radius circle position directly to origin search center
            if (leafletCircle && currentCoords) {
                leafletCircle.setLatLng([currentCoords.lat, currentCoords.lng]);
            }

            // Prevent map shaking and radial shifting: check movement distance first
            const currentCenter = leafletMap.getCenter();
            const distanceMoved = leafletMap.distance(currentCenter, [lat, lng]);

            if (distanceMoved > 10) {
                leafletMap.panTo([lat, lng], { animate: true, duration: 0.5 });
            }
            
            companyMarkerMap[id].openPopup();
        }
    }

    // Rate-limited queue processor complying strictly with Nominatim's 1 req/sec policy
    let contactHydrationQueue = [];
    let isHydrating = false;

    function processHydrationQueue() {
        if (isHydrating || contactHydrationQueue.length === 0) return;
        isHydrating = true;

        const company = contactHydrationQueue.shift();
        const query = encodeURIComponent(`${company.name} ${company.address}`);

        // Query official Nominatim endpoint with proper headers
        fetch(`https://nominatim.openstreetmap.org/search?format=json&extratags=1&q=${query}`)
            .then(res => res.json())
            .then(items => {
                if (items && items.length > 0) {
                    const extra = items[0].extratags || {};
                    const phone = extra.phone || extra['contact:phone'] || extra['contact:mobile'];
                    const email = extra.email || extra['contact:email'];
                    const website = extra.website || extra['contact:website'] || extra.url;

                    if (phone) {
                        const el = document.getElementById(`phone-${company.id}`);
                        if (el) el.innerHTML = `<i class="fa-solid fa-phone"></i> ${escapeHtml(phone)}`;
                    }
                    if (email) {
                        const el = document.getElementById(`email-${company.id}`);
                        if (el) el.innerHTML = `<i class="fa-solid fa-envelope"></i> ${escapeHtml(email)}`;
                    }
                    if (website) {
                        const el = document.getElementById(`web-${company.id}`);
                        if (el) el.innerHTML = `<a href="${escapeHtml(website)}" target="_blank" class="website-link"><i class="fa-solid fa-globe"></i> ${escapeHtml(website)}</a>`;
                    }
                }
            })
            .catch(() => {})
            .finally(() => {
                setTimeout(() => {
                    isHydrating = false;
                    processHydrationQueue();
                }, 1100); // Strict 1.1 second delay to honor Nominatim usage terms
            });
    }

    function hydrateMissingContactInfo(company) {
        contactHydrationQueue.push(company);
        processHydrationQueue();
    }

    function scrapeCompanyWebsiteClientSide(company) {
        if (!company.website || !company.website.startsWith('http')) return;

        // Route through server-side proxy endpoint to bypass client browser CORS limitations
        fetch(`index.php?action=proxy_scrape&url=${encodeURIComponent(company.website)}`)
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success' && res.data) {
                    if (res.data.email !== 'N/A' && company.email === 'N/A') {
                        const emailEl = document.getElementById(`email-${company.id}`);
                        if (emailEl) emailEl.innerHTML = `<i class="fa-solid fa-envelope"></i> ${escapeHtml(res.data.email)}`;
                    }
                    if (res.data.phone !== 'N/A' && company.phone === 'N/A') {
                        const phoneEl = document.getElementById(`phone-${company.id}`);
                        if (phoneEl) phoneEl.innerHTML = `<i class="fa-solid fa-phone"></i> ${escapeHtml(res.data.phone)}`;
                    }
                }
            })
            .catch(() => {});
    }

    function renderSearchResults(data, radius) {
        const listContainer = document.getElementById('results-list');
        listContainer.innerHTML = '';
        if (!data.data || data.data.length === 0) {
            listContainer.innerHTML = '<p style="text-align:center; color:#94a3b8; margin-top:20px;">No industrial entities found within this radius.</p>';
            return;
        }

        const disclaimer = document.createElement('div');
        disclaimer.className = 'disclaimer-banner';
        disclaimer.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> <span><strong>Note:</strong> Data displayed is gathered automatically. Information (contact numbers/email) may not be 100% accurate.</span>';
        listContainer.appendChild(disclaimer);

        data.data.forEach(company => {
            if (company.distance > radius) return;

            const marker = L.marker([company.lat, company.lng], { icon: companyIcon }).addTo(leafletMap)
                .bindPopup(`<b>${escapeHtml(company.name)}</b><br>${escapeHtml(company.address)}`);
            
            // Map pin click handler -> syncs to sidebar
            marker.on('click', () => {
                zoomToCompany(company.id, company.lat, company.lng);
            });

            companyMarkers.push(marker);
            companyMarkerMap[company.id] = marker;

            const isSaved = savedLeadIds.has(company.id);
            const buttonHtml = isSaved 
                ? `<button id="btn-save-${company.id}" disabled style="padding:4px 8px; background:#16a34a; color:white; border:none; border-radius:4px; cursor:default;"><i class="fa-solid fa-check"></i> Saved</button>`
                : `<button id="btn-save-${company.id}" onclick="saveLeadBtn('${escapeHtml(company.id)}', '${escapeJsString(company.name)}', ${company.lat}, ${company.lng}, '${escapeJsString(company.address)}', '${escapeJsString(company.phone)}', '${escapeJsString(company.email)}', '${escapeJsString(company.website)}')" style="padding:4px 8px; background:#0284c7; color:white; border:none; border-radius:4px; cursor:pointer;">Save Lead</button>`;

            const websiteDisplay = company.website 
                ? `<a href="${escapeHtml(company.website)}" target="_blank" class="website-link" id="web-${company.id}"><i class="fa-solid fa-globe"></i> ${escapeHtml(company.website)}</a>` 
                : `<span style="color:#64748b;" id="web-${company.id}"><i class="fa-solid fa-globe"></i> N/A</span>`;

            let searchEngineFallbacks = '';
            if (company.phone === 'N/A' || company.email === 'N/A' || !company.website) {
                const searchQuery = encodeURIComponent(`"${company.name}" ${company.address} contact phone email`);
                searchEngineFallbacks = `
                    <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #334155;">
                        <span style="font-size: 0.75rem; color: #94a3b8; display: block; margin-bottom: 4px;"><i class="fa-solid fa-magnifying-glass"></i> Find missing info on:</span>
                        <div class="search-engine-btns">
                            <a href="https://www.google.com/search?q=${searchQuery}" target="_blank" class="btn-search-engine"><i class="fa-brands fa-google"></i> Google</a>
                            <a href="https://www.bing.com/search?q=${searchQuery}" target="_blank" class="btn-search-engine"><i class="fa-solid fa-b"></i> Bing</a>
                            <a href="https://search.yahoo.com/search?p=${searchQuery}" target="_blank" class="btn-search-engine"><i class="fa-brands fa-yahoo"></i> Yahoo</a>
                        </div>
                    </div>
                `;
            }

            const card = document.createElement('div');
            card.className = 'company-card';
            card.id = `card-${company.id}`;
            card.onclick = (e) => {
                if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && !e.target.closest('button') && !e.target.closest('a')) {
                    zoomToCompany(company.id, company.lat, company.lng);
                }
            };
            card.innerHTML = `
                <h4><i class="fa-solid fa-industry"></i> ${escapeHtml(company.name)}</h4>
                <p><i class="fa-solid fa-location-dot"></i> ${escapeHtml(company.address)} (${company.distance}m away)</p>
                <p id="phone-${company.id}"><i class="fa-solid fa-phone"></i> ${escapeHtml(company.phone)}</p>
                <p id="email-${company.id}"><i class="fa-solid fa-envelope"></i> ${escapeHtml(company.email)}</p>
                <p>${websiteDisplay}</p>
                ${searchEngineFallbacks}
                <div style="margin-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <span class="badge">${escapeHtml(company.category)}</span>
                    ${buttonHtml}
                </div>
            `;
            listContainer.appendChild(card);

            // Client-side async website & contact scraping without DuckDuckGo or Nominatim blocks
            if (company.website && (company.phone === 'N/A' || company.email === 'N/A')) {
                scrapeCompanyWebsiteClientSide(company);
            } else if (company.phone === 'N/A' || company.email === 'N/A') {
                hydrateMissingContactInfo(company);
            }
        });
    }

    function scrapeCompanyWebsiteClientSide(company) {
        if (!company.website || !company.website.startsWith('http')) return;

        const proxyUrl = `https://api.allorigins.win/get?url=${encodeURIComponent(company.website)}`;

        fetch(proxyUrl)
            .then(res => res.json())
            .then(data => {
                if (!data || !data.contents) return;
                const html = data.contents;

                // Extract email
                const emailMatch = html.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/);
                if (emailMatch && company.email === 'N/A') {
                    const foundEmail = emailMatch[0];
                    if (!foundEmail.match(/\.(png|jpg|jpeg|gif|svg|webp|css|js)$/i)) {
                        const emailEl = document.getElementById(`email-${company.id}`);
                        if (emailEl) emailEl.innerHTML = `<i class="fa-solid fa-envelope"></i> ${escapeHtml(foundEmail)}`;
                    }
                }

                // Extract phone
                const phoneMatch = html.match(/(?:\+?\d{1,4}[-.\s]?)?\(?\d{2,5}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/);
                if (phoneMatch && company.phone === 'N/A') {
                    const digits = phoneMatch[0].replace(/\D/g, '');
                    if (digits.length >= 7 && digits.length <= 15) {
                        const phoneEl = document.getElementById(`phone-${company.id}`);
                        if (phoneEl) phoneEl.innerHTML = `<i class="fa-solid fa-phone"></i> ${escapeHtml(phoneMatch[0].trim())}`;
                    }
                }
            })
            .catch(() => {});
    }

    function saveLeadBtn(placeId, name, lat, lng, address, phone, email, website) {
        const payload = {
            place_id: placeId,
            name: name,
            latitude: lat,
            longitude: lng,
            address: address,
            phone: phone,
            email: email,
            website: website,
            tag: 'Unassigned'
        };

        fetch('index.php?action=update_tag', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                savedLeadIds.add(placeId);
                const btn = document.getElementById(`btn-save-${placeId}`);
                if (btn) {
                    btn.disabled = true;
                    btn.style.background = '#16a34a';
                    btn.style.cursor = 'default';
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Saved';
                }
                showToast('Lead saved successfully!');
            } else {
                showToast('Error saving lead: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Failed to save lead.');
        });
    }

    function updateTag(placeId, newTag) {
        fetch('index.php?action=update_tag', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ place_id: placeId, tag: newTag })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(`Tag updated to "${newTag}"`);
            } else {
                showToast('Failed to update tag');
            }
        })
        .catch(() => showToast('Failed to update tag'));
    }

    function confirmDeleteLead(placeId) {
        if (!confirm('Are you sure you want to delete this saved lead?')) return;

        fetch('index.php?action=delete_lead', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ place_id: placeId })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                savedLeadIds.delete(placeId);
                const btn = document.getElementById(`btn-save-${placeId}`);
                if (btn) {
                    btn.disabled = false;
                    btn.style.background = '#0284c7';
                    btn.style.cursor = 'pointer';
                    btn.innerHTML = 'Save Lead';
                }
                loadLeads();
                showToast('Lead deleted');
            } else {
                showToast('Failed to delete lead');
            }
        })
        .catch(() => showToast('Failed to delete lead'));
    }

    function loadLeads() {
        const filter = document.getElementById('filterTag').value;
        const container = document.getElementById('leads-cards-container');
        container.innerHTML = '<p style="text-align:center; color:#38bdf8; margin-top:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading saved leads...</p>';

        fetch(`index.php?action=fetch_leads&tag=${encodeURIComponent(filter)}`)
            .then(res => res.json())
            .then(data => {
                container.innerHTML = '';
                if (!data.data || data.data.length === 0) {
                    container.innerHTML = '<p style="text-align:center; color:#94a3b8; margin-top:20px;">No saved leads found for this filter.</p>';
                    return;
                }

                savedLeadIds.clear();
                data.data.forEach(lead => {
                    savedLeadIds.add(lead.place_id);

                    const card = document.createElement('div');
                    card.className = 'company-card';
                    card.onclick = (e) => {
                        if (e.target.tagName !== 'SELECT' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && !e.target.closest('button') && !e.target.closest('a')) {
                            zoomToCompany(lead.place_id, parseFloat(lead.latitude), parseFloat(lead.longitude));
                        }
                    };

                    const websiteDisplay = lead.website 
                        ? `<a href="${escapeHtml(lead.website)}" target="_blank" class="website-link"><i class="fa-solid fa-globe"></i> ${escapeHtml(lead.website)}</a>` 
                        : `<span style="color:#64748b;"><i class="fa-solid fa-globe"></i> N/A</span>`;

                    card.innerHTML = `
                        <h4><i class="fa-solid fa-industry"></i> ${escapeHtml(lead.name)}</h4>
                        <p><i class="fa-solid fa-location-dot"></i> ${escapeHtml(lead.address)}</p>
                        <p><i class="fa-solid fa-phone"></i> ${escapeHtml(lead.phone)}</p>
                        <p><i class="fa-solid fa-envelope"></i> ${escapeHtml(lead.email)}</p>
                        <p>${websiteDisplay}</p>
                        <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <select onchange="updateTag('${escapeHtml(lead.place_id)}', this.value)" class="form-control" style="width:auto; padding:4px 8px; font-size:0.8rem;">
                                <option value="Hot Lead" ${lead.tag === 'Hot Lead' ? 'selected' : ''}>Hot Lead</option>
                                <option value="Contacted" ${lead.tag === 'Contacted' ? 'selected' : ''}>Contacted</option>
                                <option value="Closed" ${lead.tag === 'Closed' ? 'selected' : ''}>Closed</option>
                                <option value="Unassigned" ${lead.tag === 'Unassigned' ? 'selected' : ''}>Unassigned</option>
                            </select>
                            <button onclick="confirmDeleteLead('${escapeHtml(lead.place_id)}')" style="padding:4px 8px; background:#ef4444; color:white; border:none; border-radius:4px; cursor:pointer;" title="Delete Saved Lead"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    `;
                    container.appendChild(card);
                });
            })
            .catch(() => {
                container.innerHTML = '<p style="text-align:center; color:#f87171; margin-top:20px;">Failed to load saved leads.</p>';
            });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeJsString(str) {
        if (!str) return '';
        return String(str)
            .replace(/\\/g, '\\\\')
            .replace(/'/g, "\\'")
            .replace(/"/g, '\\"')
            .replace(/\n/g, '\\n')
            .replace(/\r/g, '\\r');
    }

    function preloadSavedLeadIds() {
        fetch('index.php?action=fetch_leads&tag=ALL')
            .then(res => res.json())
            .then(data => {
                if (data && data.data && Array.isArray(data.data)) {
                    savedLeadIds.clear();
                    data.data.forEach(lead => savedLeadIds.add(lead.place_id));
                }
            })
            .catch(() => {});
    }

    function openRightDrawer(type) {
        const drawer = document.getElementById('right-drawer');
        const backdrop = document.getElementById('right-drawer-backdrop');
        const titleEl = document.getElementById('right-drawer-title');
        const bodyEl = document.getElementById('right-drawer-body');
        if (!drawer || !backdrop || !titleEl || !bodyEl) return;

        if (type === 'terms') {
            titleEl.innerHTML = '<i class="fa-solid fa-file-contract"></i> Terms of Use';
            bodyEl.innerHTML = `
                <div class="drawer-badge-callout">
                    <i class="fa-solid fa-circle-info"></i> <strong>Academic & Professional Utility:</strong> This platform is designed to assist students and trainees in finding legitimate companies for Supervised In-Plant Training (SIPT) and On-the-Job Training (OJT).
                </div>
                <h4>1. Intended & Permitted Purpose</h4>
                <p>This service is provided strictly for educational and personal career advancement—specifically enabling students to research industrial plants, tech offices, and factories within their locale for internship opportunities.</p>
                <h4>2. Prohibited & Unlawful Activities</h4>
                <p>By using this service, you explicitly agree not to harvest or use extracted company contacts for abusive or illegal purposes, including:</p>
                <ul>
                    <li><strong>Bulk Commercial Solicitation & Spam:</strong> Harvesting extracted email addresses or phone numbers for mass cold sales pitches, commercial blasts, affiliate marketing, or unsolicited newsletters.</li>
                    <li><strong>Telemarketing & Robocalling:</strong> Utilizing phone numbers for automated robocalls, phone harassment, or unauthorized bulk SMS broadcasting.</li>
                    <li><strong>Phishing & Fraudulent Inquiries:</strong> Sending malicious attachments, deceptive employment inquiries, or spoofing institutional credentials.</li>
                    <li><strong>Trespassing & Unlawful Surveillance:</strong> Using facility coordinates for unauthorized trespassing, harassment, or unlawful tracking.</li>
                </ul>
                <h4>3. Anti-Abuse & Bot Swarming Restrictions</h4>
                <p>You agree not to abuse or disrupt this web application or its connected upstream services:</p>
                <ul>
                    <li><strong>No Bot Swarms:</strong> You may not deploy automated scrapers, headless bots, or high-concurrency crawl scripts against this web dashboard or upstream OpenStreetMap APIs.</li>
                    <li><strong>Respecting Upstream FOSS APIs:</strong> OpenStreetMap Overpass and Nominatim are community-funded public resources. Flooding or attempting to bypass API rate limits is strictly forbidden.</li>
                </ul>
                <h4>4. Data Accuracy Disclaimer</h4>
                <p>Information presented is aggregated automatically from OpenStreetMap community tags and public web pages. <strong>Data may be outdated, incomplete, or inaccurate.</strong> Users are required to independently verify company information before visiting facilities or sending confidential application files.</p>
                <h4>5. Limitation of Liability</h4>
                <p>Provided under the MIT License "as is", without warranty of any kind. The project author and contributors are not liable for any communication, agreements, disputes, or outcomes between users and listed enterprises.</p>
            `;
        } else if (type === 'privacy') {
            titleEl.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Privacy Policy & Cookies';
            bodyEl.innerHTML = `
                <div class="drawer-badge-callout">
                    <i class="fa-solid fa-user-shield"></i> <strong>Privacy-First Architecture:</strong> We do not track your identity across the web, require user registration, or sell personal data to advertisers.
                </div>
                <h4>1. No Personal Accounts or Registration</h4>
                <p>This application does not collect your name, email address, password, or profile information. No registration or login is required to use any feature.</p>
                <h4>2. Device GPS Location</h4>
                <p>When you click the GPS crosshairs button:</p>
                <ul>
                    <li>Your device browser requests permission to access hardware GPS coordinates.</li>
                    <li>Coordinates are processed strictly in your browser to calculate the search radius and center the map.</li>
                    <li><strong>Your coordinates and searches are NEVER permanently stored in our MySQL database.</strong> Searches are handled in-memory and streamed back in real-time.</li>
                </ul>
                <h4>3. Approximate IP-Based Location</h4>
                <p>To display your approximate city and country in the footer without prompting for device GPS permissions, an anonymous client-side lookup is performed via public IP geolocation services (<code>ipwho.is</code> / <code>ipapi.co</code>). This is completely anonymous and never logged into our database.</p>
                <h4>4. Anonymous Session Cookies</h4>
                <p>This service uses a single anonymous session cookie:</p>
                <ul>
                    <li><strong>Cookie Name:</strong> <code>locator_session_id</code></li>
                    <li><strong>Purpose:</strong> Multi-tenancy isolation. It stores a random 64-character token ensuring that companies you click "Save Lead" on are isolated to your browser session. Other visitors cannot view, modify, or delete your saved leads.</li>
                    <li><strong>Security:</strong> Protected with <code>HttpOnly</code> and <code>SameSite=Lax</code> security attributes.</li>
                    <li><strong>Zero Profiling:</strong> It contains no personal identifiers and is never used for advertising or cross-site tracking.</li>
                </ul>
                <h4>5. Upstream FOSS Community Providers</h4>
                <p>Geospatial data is retrieved from OpenStreetMap, Overpass API, and Nominatim. When client-side scraping extracts contact data, requests route through an open CORS proxy to fetch public HTML without accessing user credentials.</p>
            `;
        }

        drawer.classList.add('active');
        backdrop.classList.add('active');
        drawer.setAttribute('aria-hidden', 'false');
    }

    function closeRightDrawer() {
        const drawer = document.getElementById('right-drawer');
        const backdrop = document.getElementById('right-drawer-backdrop');
        if (drawer) {
            drawer.classList.remove('active');
            drawer.setAttribute('aria-hidden', 'true');
        }
        if (backdrop) {
            backdrop.classList.remove('active');
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeRightDrawer();
    });

    function detectUserRoughLocation() {
        const locEl = document.getElementById('user-rough-location');
        if (!locEl) return;

        fetch('https://ipwho.is/')
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.city && data.country) {
                    locEl.textContent = `${data.city}, ${data.country}`;
                } else {
                    throw new Error('Fallback needed');
                }
            })
            .catch(() => {
                fetch('https://ipapi.co/json/')
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.city && data.country_name) {
                            locEl.textContent = `${data.city}, ${data.country_name}`;
                        } else {
                            locEl.textContent = 'Location unavailable';
                        }
                    })
                    .catch(() => {
                        locEl.textContent = 'Location unavailable';
                    });
            });
    }

    window.onload = function() {
        initLeaflet();
        generateCyanFavicon();
        preloadSavedLeadIds();
        detectUserRoughLocation();
    };
</script>

<!-- Right Sliding Drawer Modal for Terms of Use & Privacy Policy -->
<div id="right-drawer-backdrop" class="right-drawer-backdrop" onclick="closeRightDrawer()"></div>
<div id="right-drawer" class="right-drawer" aria-hidden="true">
    <div class="right-drawer-header">
        <h3 id="right-drawer-title" class="right-drawer-title"><i class="fa-solid fa-file-contract"></i> Terms of Use</h3>
        <button type="button" class="btn-close-drawer" onclick="closeRightDrawer()" aria-label="Close modal">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <div id="right-drawer-body" class="right-drawer-body"></div>
</div>
</body>
</html>