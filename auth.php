<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
        http_response_code(403);
        exit('طلب غير صالح');
    }
}

function currentUser(): ?array {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) {
        header('Location: ?page=login');
        exit;
    }
    return $user;
}

function canManage(array $user): bool {
    return in_array($user['role'], ['مدير', 'معاون'], true);
}

function canAccounting(array $user): bool {
    return in_array($user['role'], ['مدير', 'معاون', 'محاسب'], true);
}

function canCounsel(array $user): bool {
    return in_array($user['role'], ['مدير', 'معاون', 'مدرس'], true);
}
