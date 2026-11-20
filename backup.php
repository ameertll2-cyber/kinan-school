<?php
declare(strict_types=1);

function createEncryptedBackup(PDO $pdo, string $password): string {
    $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    $plain = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'kinan_school.db');
    if ($plain === false) {
        throw new RuntimeException('تعذر قراءة قاعدة البيانات');
    }

    $salt = random_bytes(16);
    $nonce = random_bytes(12);
    $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, 'kinan-school-v1', $nonce, $key);

    $payload = [
        'format' => 'kinan-backup-v1',
        'salt' => base64_encode($salt),
        'nonce' => base64_encode($nonce),
        'data' => base64_encode($cipher),
    ];
    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function restoreEncryptedBackup(string $payload, string $password): void {
    $decoded = json_decode($payload, true);
    if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'kinan-backup-v1') {
        throw new RuntimeException('ملف النسخة الاحتياطية غير صالح');
    }
    $salt = base64_decode((string)$decoded['salt'], true);
    $nonce = base64_decode((string)$decoded['nonce'], true);
    $cipher = base64_decode((string)$decoded['data'], true);
    if ($salt === false || $nonce === false || $cipher === false) {
        throw new RuntimeException('تعذر فك ترميز النسخة الاحتياطية');
    }

    $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, 'kinan-school-v1', $nonce, $key);
    if ($plain === false) {
        throw new RuntimeException('كلمة مرور النسخة غير صحيحة');
    }

    $temp = __DIR__ . DIRECTORY_SEPARATOR . 'kinan_school.restore.db';
    if (file_put_contents($temp, $plain) === false) {
        throw new RuntimeException('تعذر حفظ النسخة المؤقتة');
    }
    $test = new PDO('sqlite:' . $temp);
    $test->query('PRAGMA integrity_check')->fetchColumn();
    if (!rename($temp, __DIR__ . DIRECTORY_SEPARATOR . 'kinan_school.db')) {
        unlink($temp);
        throw new RuntimeException('تعذر استبدال قاعدة البيانات');
    }
}
