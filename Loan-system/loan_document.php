<?php
require_once 'config.php';
require_once 'loan_document_storage.php';

if (!isLoggedIn()) {
    http_response_code(404);
    exit('Not found.');
}

$loanId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$side = $_GET['side'] ?? '';
if ($loanId === false || $loanId < 1 || !in_array($side, ['front', 'back'], true)) {
    http_response_code(404);
    exit('Not found.');
}

$stmt = Database::getInstance()->getConnection()->prepare(
    'SELECT user_id, id_front_path, id_back_path FROM loan_applications WHERE id = ?'
);
$stmt->execute([$loanId]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$loan || (!isAdmin() && (int) $loan['user_id'] !== (int) $_SESSION['user_id'])) {
    http_response_code(404);
    exit('Not found.');
}

$storedPath = $side === 'front' ? $loan['id_front_path'] : $loan['id_back_path'];
$absolutePath = loanDocumentAbsolutePath($storedPath);
if ($absolutePath === null) {
    http_response_code(404);
    exit('Document not found.');
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($absolutePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($absolutePath));
header('Content-Disposition: inline; filename="loan-document"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($absolutePath);
exit;
