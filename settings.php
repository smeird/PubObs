<?php
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (name VARCHAR(191) PRIMARY KEY, value TEXT NOT NULL)");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to connect to the database']);
    exit;
}

function sanitize_weight($weight)
{
    $weight = trim((string)$weight);
    return preg_match('/^(100|200|300|400|500|600|700|800|900)$/', $weight) ? $weight : null;
}

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT value FROM site_settings WHERE name = :name');
    $stmt->execute(['name' => 'accent_font_weight']);
    $weight = $stmt->fetchColumn();
    $sanitized = is_string($weight) ? sanitize_weight($weight) : null;
    if ($sanitized === null) {
        $sanitized = '600';
    }
    echo json_encode(['accent_font_weight' => $sanitized]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !array_key_exists('accent_font_weight', $input)) {
        $input = $_POST;
    }
    $weight = $input['accent_font_weight'] ?? '';
    $weight = sanitize_weight($weight);
    if ($weight === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid accent font weight value']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO site_settings (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute(['name' => 'accent_font_weight', 'value' => $weight]);
    echo json_encode(['success' => true, 'accent_font_weight' => $weight]);
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['error' => 'Method not allowed']);
