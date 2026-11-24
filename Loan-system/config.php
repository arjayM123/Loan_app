<?php

$environmentOverride = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? null);
$hostName = strtolower($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
$isLocalEnvironment = $environmentOverride === 'local'
    || in_array($hostName, ['localhost', '127.0.0.1', '::1', 'loanapp.local', 'loanapp.test'], true)
    || strpos($hostName, 'localhost:') === 0
    || strpos($hostName, '127.0.0.1:') === 0
    || strpos($hostName, 'loanapp.local:') === 0
    || strpos($hostName, 'loanapp.test:') === 0;

if (!$isLocalEnvironment) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

$privateConfigPath = getenv('LOAN_PRIVATE_CONFIG_FILE') ?: __DIR__ . '/private_config.php';
$privateDatabaseConfig = [];
if (is_file($privateConfigPath)) {
    $loadedPrivateConfig = require $privateConfigPath;
    if (is_array($loadedPrivateConfig)) {
        $privateDatabaseConfig = $loadedPrivateConfig;
    }
}

$readDatabaseSetting = static function ($environmentName, $privateKey) use ($privateDatabaseConfig) {
    $value = getenv($environmentName);
    if ($value === false || $value === '') {
        $value = $_SERVER[$environmentName] ?? $_ENV[$environmentName] ?? null;
    }
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    $value = $privateDatabaseConfig[$privateKey] ?? null;
    return is_string($value) ? trim($value) : '';
};

define('LOAN_PRIVATE_UPLOAD_DIR', $readDatabaseSetting('LOAN_PRIVATE_UPLOAD_DIR', 'upload_dir'));

if ($isLocalEnvironment) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'loan_system');
} else {
    define('DB_HOST', $readDatabaseSetting('sql100.infinityfree.com', 'host'));
    define('DB_USER', $readDatabaseSetting('if0_41024119', 'user'));
    define('DB_PASS', $readDatabaseSetting('HIRAM2026', 'password'));
    define('DB_NAME', $readDatabaseSetting('if0_41024119_loan_system', 'database'));

    if (DB_HOST === '' || DB_USER === '' || DB_PASS === '' || DB_NAME === '') {
        error_log('Loan app database configuration is missing.');
        http_response_code(500);
        exit('The application database is not configured. Contact the site administrator.');
    }
}

// Create database connection
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            error_log('Loan app database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('The application database is temporarily unavailable.');
        }
    }
    
    public static function getInstance() {
        if(!self::$instance) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

// Session configuration
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $requestIsSecure = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $requestIsSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function csrfToken($scope = 'default') {
    if (!isset($_SESSION['_csrf_tokens']) || !is_array($_SESSION['_csrf_tokens'])) {
        $_SESSION['_csrf_tokens'] = [];
    }
    if (empty($_SESSION['_csrf_tokens'][$scope])) {
        $_SESSION['_csrf_tokens'][$scope] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_tokens'][$scope];
}

function verifyCsrfToken($token, $scope = 'default') {
    return is_string($token)
        && isset($_SESSION['_csrf_tokens'][$scope])
        && hash_equals($_SESSION['_csrf_tokens'][$scope], $token);
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function normalizePhoneNumber($phone, $country = 'PH') {
    if (!is_string($phone) && !is_numeric($phone)) {
        return null;
    }

    $rawPhone = trim((string) $phone);
    if ($rawPhone === '') {
        return null;
    }

    $country = strtoupper(trim((string) $country));
    $country = $country !== '' ? $country : 'PH';

    $cleanPhone = preg_replace('/\s+/', '', $rawPhone);
    $cleanPhone = preg_replace('/[()\-.]/', '', $cleanPhone);
    $digits = preg_replace('/\D/', '', $cleanPhone);

    if ($digits === '') {
        return null;
    }

    $countryPatterns = [
        'PH' => ['code' => '63', 'valid' => '/^(?:0|63)?9\d{9}$/'],
        'US' => ['code' => '1', 'valid' => '/^(?:1)?[2-9]\d{9}$/'],
        'GB' => ['code' => '44', 'valid' => '/^(?:44)?7\d{9}$/'],
        'SG' => ['code' => '65', 'valid' => '/^(?:65)?[689]\d{7}$/'],
        'AU' => ['code' => '61', 'valid' => '/^(?:61)?[4578]\d{8,9}$/'],
    ];

    if (!isset($countryPatterns[$country])) {
        $country = 'PH';
    }

    $code = $countryPatterns[$country]['code'];
    $validPattern = $countryPatterns[$country]['valid'];

    $withoutPlus = ltrim($cleanPhone, '+');

    if (preg_match('/^' . $code . '/', $withoutPlus)) {
        $digits = preg_replace('/^' . $code . '/', '', $withoutPlus);
    } elseif (preg_match('/^0/', $cleanPhone) && $country === 'PH') {
        $digits = substr($digits, 1);
    } elseif (preg_match('/^' . $code . '/', $digits)) {
        $digits = preg_replace('/^' . $code . '/', '', $digits);
    }

    if (!preg_match($validPattern, $digits) && !preg_match($validPattern, $withoutPlus)) {
        if ($country === 'PH' && preg_match('/^0?9\d{9}$/', $digits)) {
            // valid local mobile number
        } else {
            return null;
        }
    }

    if ($country === 'PH' && preg_match('/^9\d{9}$/', $digits)) {
        return '+63' . $digits;
    }

    if ($country === 'PH' && preg_match('/^9\d{8}$/', $digits)) {
        return '+63' . $digits;
    }

    if ($country !== 'PH' && preg_match('/^\d+$/', $digits)) {
        return '+' . $code . $digits;
    }

    return '+' . $code . $digits;
}

function phoneVariants($phone) {
    $variants = [];
    $normalized = normalizePhoneNumber($phone);

    if ($normalized) {
        $variants[] = $normalized;
        if (strpos($normalized, '+63') === 0) {
            $variants[] = '0' . substr($normalized, 3);
        }
    }

    if (is_string($phone) && trim($phone) !== '') {
        $raw = preg_replace('/\s+/', '', trim($phone));
        $raw = preg_replace('/[()\-.]/', '', $raw);
        if ($raw !== '') {
            $variants[] = $raw;
        }
    }

    $variants = array_values(array_unique(array_filter($variants, fn($value) => $value !== null && $value !== '')));
    return $variants;
}
?>
