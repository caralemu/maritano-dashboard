<?php
declare(strict_types=1);

use MaritanoDashboard\Lib\AuthService;
use MaritanoDashboard\Lib\Database;

spl_autoload_register(function (string $class): void {
    $prefix = 'MaritanoDashboard\\';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));

    if (strncmp('Lib\\', $relativeClass, 4) === 0) {
        $relativeClass = substr($relativeClass, 4);
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relativeClass) . '.php';
    } else {
        $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    }

    if (file_exists($file)) {
        require_once $file;
    }
});

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionName = $config['session_name'] ?? 'MARITANO_SESSID';
    session_name($sessionName);
    session_start();
}

$GLOBALS['__app_dbs'] = [];

function config(string $key = null, $default = null)
{
    global $config;
    if ($key === null) {
        return $config;
    }

    $segments = explode('.', $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function db(string $connectionName): Database
{
    global $config;

    if (isset($GLOBALS['__app_dbs'][$connectionName])) {
        return $GLOBALS['__app_dbs'][$connectionName];
    }

    $connections = $config['connections'] ?? [];
    if (!isset($connections[$connectionName])) {
        throw new RuntimeException("Conexión no configurada: {$connectionName}");
    }

    $db = new Database($connections[$connectionName]);
    $GLOBALS['__app_dbs'][$connectionName] = $db;

    return $db;
}

function appDb(): Database
{
    return db('app');
}

function sigaDb(): Database
{
    return db('siga');
}

function auth(): AuthService
{
    static $auth = null;
    if ($auth === null) {
        $auth = new AuthService(appDb());
    }
    return $auth;
}

function currentUser(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function requireLogin(): void
{
    if (currentUser() === null) {
        header('Location: ./login.php');
        exit;
    }
}

function hasRole(string $roleCode): bool
{
    $user = currentUser();
    if ($user === null) {
        return false;
    }

    return in_array($roleCode, $user['roles'] ?? [], true);
}

function canAccessModule(string $moduleCode, string $permission = 'can_view'): bool
{
    $user = currentUser();
    if ($user === null) {
        return false;
    }

    if (hasRole('ADMIN')) {
        return true;
    }

    $permissions = $user['module_permissions'][$moduleCode] ?? [];
    return !empty($permissions[$permission]);
}

function requireAdmin(): void
{
    requireLogin();
    if (!hasRole('ADMIN')) {
        http_response_code(403);
        echo 'Acceso denegado.';
        exit;
    }
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestString(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? $_POST[$key] ?? $default;

    if ($value === null) {
        return null;
    }

    return trim((string)$value);
}

function requestArray(string $key): array
{
    $value = $_POST[$key] ?? [];
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter(array_map(static fn($item) => trim((string)$item), $value), static fn($item) => $item !== ''));
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function appUpper(string $value): string
{
    $value = trim($value);
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }
    return strtoupper($value);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function requireApiLogin(): void
{
    if (currentUser() === null) {
        jsonResponse([
            'ok' => false,
            'error' => 'SESSION_EXPIRED',
            'message' => 'La sesión expiró.'
        ], 401);
    }
}
