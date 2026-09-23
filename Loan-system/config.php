<?php

$environmentOverride = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? null);
$hostName = strtolower($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));

$isLocalEnvironment = ($environmentOverride === 'local') ||
    strpos($hostName, 'localhost') !== false ||
    strpos($hostName, '127.0.0.1') !== false ||
    strpos($hostName, '::1') !== false ||
    strpos($hostName, 'loanapp.local') !== false ||
    strpos($hostName, 'loanapp.test') !== false;

if ($isLocalEnvironment) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'loan_system');
} else {
    define('DB_HOST', 'sql100.infinityfree.com');
    define('DB_USER', 'if0_41024119');
    define('DB_PASS', 'HIRAM2026');
    define('DB_NAME', 'if0_41024119_loan_system');
}

// Create database connection
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
                DB_USER,
                DB_PASS,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
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