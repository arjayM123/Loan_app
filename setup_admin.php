<?php
// This maintenance command is intentionally unavailable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/Loan-system/config.php';

$email = $argv[1] ?? '';
if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php setup_admin.php existing-user@example.com\n");
    exit(2);
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();

    if ($userId === false) {
        fwrite(STDERR, "No registered account was found for that email.\n");
        exit(1);
    }

    $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([(int) $userId]);
    fwrite(STDOUT, "Admin access granted to the existing account.\n");
} catch (Throwable $error) {
    error_log('Admin promotion command failed: ' . $error->getMessage());
    fwrite(STDERR, "The admin account could not be updated. Check the server configuration and logs.\n");
    exit(1);
}
