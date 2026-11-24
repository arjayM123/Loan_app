<?php

function loanPrivateUploadDirectory() {
    if (defined('LOAN_PRIVATE_UPLOAD_DIR') && LOAN_PRIVATE_UPLOAD_DIR !== '') {
        return LOAN_PRIVATE_UPLOAD_DIR;
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($documentRoot !== false) {
        return dirname($documentRoot) . DIRECTORY_SEPARATOR . 'loan_app_private_uploads';
    }

    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'loan_app_private_uploads';
}

function ensureLoanPrivateUploadDirectory() {
    $directory = loanPrivateUploadDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private document storage could not be created.');
    }

    $realDirectory = realpath($directory);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($realDirectory === false) {
        throw new RuntimeException('Private document storage is unavailable.');
    }

    if ($documentRoot !== false) {
        $normalizedRoot = rtrim(strtolower($documentRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedDirectory = strtolower($realDirectory);
        if ($normalizedDirectory === rtrim(strtolower($documentRoot), DIRECTORY_SEPARATOR)
            || strpos($normalizedDirectory, $normalizedRoot) === 0) {
            throw new RuntimeException('Private document storage must be outside the public web directory.');
        }
    }

    return $realDirectory;
}

function storeLoanDocument($uploadedFile) {
    if ($uploadedFile === null) {
        return null;
    }
    if (!is_array($uploadedFile)) {
        throw new RuntimeException('The uploaded ID image is invalid.');
    }

    $uploadError = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $temporaryPath = $uploadedFile['tmp_name'] ?? null;
    if ($uploadError !== UPLOAD_ERR_OK
        || !is_string($temporaryPath)
        || $temporaryPath === ''
        || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The ID image could not be uploaded. Please try again.');
    }

    $fileSize = (int) ($uploadedFile['size'] ?? 0);
    if ($fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
        throw new RuntimeException('Each ID image must be 5 MB or smaller.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($allowedTypes[$mimeType]) || @getimagesize($temporaryPath) === false) {
        throw new RuntimeException('ID images must be valid JPG, PNG, or WebP files.');
    }

    $directory = ensureLoanPrivateUploadDirectory();
    $filename = 'loan_id_' . bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!@move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
        throw new RuntimeException('The ID image could not be saved. Please try again.');
    }
    @chmod($destination, 0600);

    return 'private:' . $filename;
}

function loanDocumentAbsolutePath($storedPath) {
    if (!is_string($storedPath) || $storedPath === '') {
        return null;
    }

    if (strpos($storedPath, 'private:') === 0) {
        $filename = substr($storedPath, 8);
        if (!preg_match('/^loan_id_[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
            return null;
        }
        $directory = loanPrivateUploadDirectory();
    } else {
        $normalizedPath = str_replace('\\', '/', $storedPath);
        if (!preg_match('~^(?:\.\./User_pages/)?uploads/([^/]+)$~', $normalizedPath, $matches)) {
            return null;
        }
        $filename = $matches[1];
        if ($filename === '.' || $filename === '..') {
            return null;
        }
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'User_pages' . DIRECTORY_SEPARATOR . 'uploads';
    }

    $realDirectory = realpath($directory);
    $realFile = realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($realDirectory === false || $realFile === false || !is_file($realFile)) {
        return null;
    }

    $directoryPrefix = rtrim(strtolower($realDirectory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos(strtolower($realFile), $directoryPrefix) !== 0) {
        return null;
    }

    return $realFile;
}

function deleteLoanDocument($storedPath) {
    $absolutePath = loanDocumentAbsolutePath($storedPath);
    if ($absolutePath !== null && is_file($absolutePath)) {
        return unlink($absolutePath);
    }
    return true;
}
