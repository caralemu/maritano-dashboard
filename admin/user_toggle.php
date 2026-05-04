<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

use MaritanoDashboard\Lib\UserRepository;

requireAdmin();

$repo = new UserRepository(appDb(), sigaDb());

try {
    $repo->toggleUser((int)(requestString('id', '0') ?? '0'));
    auth()->refreshCurrentUser();
    redirect('./users.php?message=' . urlencode('Estado de usuario actualizado.'));
} catch (Throwable $e) {
    redirect('./users.php?error=' . urlencode($e->getMessage()));
}
