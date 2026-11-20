<?php
declare(strict_types=1);

function driveConfigPath(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'google-drive.json';
}

function getDriveConfig(): array {
    $path = driveConfigPath();
    if (!is_file($path)) {
        return ['configured' => false, 'folder_id' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => ''];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveDriveConfig(array $config): void {
    $path = driveConfigPath();
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $safe = [
        'configured' => true,
        'folder_id' => trim((string)($config['folder_id'] ?? '')),
        'client_id' => trim((string)($config['client_id'] ?? '')),
        'client_secret' => trim((string)($config['client_secret'] ?? '')),
        'refresh_token' => trim((string)($config['refresh_token'] ?? '')),
    ];
    file_put_contents($path, json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function uploadBackupToDrive(string $encryptedPayload): array {
    $config = getDriveConfig();
    foreach (['client_id','client_secret','refresh_token'] as $key) {
        if (empty($config[$key])) {
            throw new RuntimeException('أكمل إعدادات Google OAuth قبل الرفع');
        }
    }

    $tokenContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
            'ignore_errors' => true,
        ],
    ]);
    $tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false, $tokenContext);
    $tokenData = json_decode((string)$tokenResponse, true);
    if (empty($tokenData['access_token'])) {
        throw new RuntimeException('تعذر تسجيل الدخول إلى Google Drive');
    }

    $boundary = 'Kinan' . bin2hex(random_bytes(12));
    $metadata = [
        'name' => 'kinan-backup-' . date('Y-m-d-His') . '.kinan',
        'mimeType' => 'application/json',
    ];
    if (!empty($config['folder_id'])) {
        $metadata['parents'] = [(string)$config['folder_id']];
    }
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= json_encode($metadata, JSON_UNESCAPED_UNICODE) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/json\r\n\r\n";
    $body .= $encryptedPayload . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $uploadContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$tokenData['access_token']}\r\n"
                . "Content-Type: multipart/related; boundary={$boundary}\r\n",
            'content' => $body,
            'ignore_errors' => true,
        ],
    ]);
    $uploadResponse = file_get_contents(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink',
        false,
        $uploadContext
    );
    $uploadData = json_decode((string)$uploadResponse, true);
    if (!is_array($uploadData) || empty($uploadData['id'])) {
        throw new RuntimeException('فشل رفع النسخة إلى Google Drive');
    }
    return $uploadData;
}
