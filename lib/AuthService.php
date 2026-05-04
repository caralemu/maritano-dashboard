<?php
declare(strict_types=1);

namespace MaritanoDashboard\Lib;

use RuntimeException;

final class AuthService
{
    public function __construct(private Database $db)
    {
    }

    public function anyUsersExist(): bool
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS total FROM app_users');
        return (int)($row['total'] ?? 0) > 0;
    }

    public function bootstrapAdmin(string $username, string $password, string $firstName, string $lastName, string $email = ''): void
    {
        if ($this->anyUsersExist()) {
            throw new RuntimeException('Ya existen usuarios. El bootstrap inicial ya no está disponible.');
        }

        $role = $this->db->fetchOne('SELECT id FROM app_roles WHERE code = ?', ['ADMIN']);
        if ($role === null) {
            throw new RuntimeException('No existe el rol ADMIN en la base interna.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'INSERT INTO app_users (username, password_hash, first_name, last_name, email, is_active, force_password_change) VALUES (?, ?, ?, ?, ?, 1, 0)',
                [$username, $passwordHash, $firstName, $lastName, $email !== '' ? $email : null]
            );

            $userId = (int)$this->db->lastInsertId();
            $this->db->execute('INSERT INTO app_user_roles (user_id, role_id) VALUES (?, ?)', [$userId, (int)$role['id']]);
            $this->db->execute(
                'INSERT IGNORE INTO app_user_vehicle_types (user_id, vehicle_type_id)
                 SELECT ?, id FROM app_vehicle_types WHERE code IN (\'VN\', \'VU\')',
                [$userId]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function login(string $username, string $password, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        $user = $this->db->fetchOne(
            'SELECT id, username, password_hash, first_name, last_name, email, is_active, force_password_change
             FROM app_users WHERE username = ? LIMIT 1',
            [$username]
        );

        if ($user === null || (int)$user['is_active'] !== 1) {
            $this->auditLogin(null, $username, $ipAddress, $userAgent, false);
            return false;
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            $this->auditLogin((int)$user['id'], $username, $ipAddress, $userAgent, false);
            return false;
        }

        $context = $this->loadUserContext((int)$user['id']);
        $_SESSION['auth_user'] = $context;

        $this->db->execute('UPDATE app_users SET last_login_at = NOW() WHERE id = ?', [(int)$user['id']]);
        $this->auditLogin((int)$user['id'], $username, $ipAddress, $userAgent, true);
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['auth_user']);
    }

    public function refreshCurrentUser(): void
    {
        if (!isset($_SESSION['auth_user']['id'])) {
            return;
        }

        $_SESSION['auth_user'] = $this->loadUserContext((int)$_SESSION['auth_user']['id']);
    }

    public function loadUserContext(int $userId): array
    {
        $user = $this->db->fetchOne(
            'SELECT id, username, first_name, last_name, email, is_active, force_password_change, last_login_at
             FROM app_users WHERE id = ? LIMIT 1',
            [$userId]
        );

        if ($user === null || (int)$user['is_active'] !== 1) {
            throw new RuntimeException('Usuario no válido o inactivo.');
        }

        $roles = $this->db->fetchAll(
            'SELECT r.code, r.name
             FROM app_roles r
             INNER JOIN app_user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND r.is_active = 1
             ORDER BY r.name',
            [$userId]
        );

        $roleCodes = array_map(static fn(array $row): string => (string)$row['code'], $roles);

        $permissionsRows = $this->db->fetchAll(
            'SELECT m.code AS module_code, p.can_view, p.can_export, p.can_admin
             FROM app_role_module_permissions p
             INNER JOIN app_modules m ON m.id = p.module_id AND m.is_active = 1
             INNER JOIN app_user_roles ur ON ur.role_id = p.role_id
             WHERE ur.user_id = ?',
            [$userId]
        );

        $modulePermissions = [];
        foreach ($permissionsRows as $row) {
            $moduleCode = (string)$row['module_code'];
            if (!isset($modulePermissions[$moduleCode])) {
                $modulePermissions[$moduleCode] = ['can_view' => false, 'can_export' => false, 'can_admin' => false];
            }
            $modulePermissions[$moduleCode]['can_view'] = $modulePermissions[$moduleCode]['can_view'] || (bool)$row['can_view'];
            $modulePermissions[$moduleCode]['can_export'] = $modulePermissions[$moduleCode]['can_export'] || (bool)$row['can_export'];
            $modulePermissions[$moduleCode]['can_admin'] = $modulePermissions[$moduleCode]['can_admin'] || (bool)$row['can_admin'];
        }

        $brandRows = $this->db->fetchAll(
            'SELECT UPPER(TRIM(m.nombre)) AS nombre
             FROM app_user_marcas um
             INNER JOIN app_marcas m ON m.id = um.marca_id
             WHERE um.user_id = ? AND m.is_active = 1
             ORDER BY m.sort_order, m.nombre',
            [$userId]
        );

        $branchRows = $this->db->fetchAll(
            'SELECT COALESCE(NULLIF(TRIM(s.siga_csucur), \'\'), \'\') AS siga_csucur, s.nombre, s.ciudad, s.comuna
             FROM app_user_sucursales us
             INNER JOIN app_sucursales s ON s.id = us.sucursal_id
             WHERE us.user_id = ? AND s.is_active = 1
             ORDER BY s.nombre',
            [$userId]
        );

        $vehicleTypeRows = $this->db->fetchAll(
            'SELECT vt.code, vt.name
             FROM app_user_vehicle_types uvt
             INNER JOIN app_vehicle_types vt ON vt.id = uvt.vehicle_type_id
             WHERE uvt.user_id = ? AND vt.is_active = 1
             ORDER BY vt.sort_order, vt.name',
            [$userId]
        );

        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'first_name' => (string)$user['first_name'],
            'last_name' => (string)$user['last_name'],
            'full_name' => trim(((string)$user['first_name']) . ' ' . ((string)$user['last_name'])),
            'email' => (string)($user['email'] ?? ''),
            'roles' => $roleCodes,
            'role_names' => array_map(static fn(array $row): string => (string)$row['name'], $roles),
            'allowed_brands' => array_values(array_filter(array_map(static fn(array $row): string => (string)$row['nombre'], $brandRows))),
            'allowed_branches' => array_values(array_filter(array_map(static fn(array $row): string => (string)$row['siga_csucur'], $branchRows))),
            'allowed_branch_rows' => $branchRows,
            'allowed_vehicle_types' => array_values(array_filter(array_map(static fn(array $row): string => (string)$row['code'], $vehicleTypeRows))),
            'allowed_vehicle_type_rows' => $vehicleTypeRows,
            'module_permissions' => $modulePermissions,
            'force_password_change' => (bool)$user['force_password_change'],
            'last_login_at' => $user['last_login_at'],
        ];
    }

    private function auditLogin(?int $userId, string $usernameAttempt, ?string $ipAddress, ?string $userAgent, bool $wasSuccessful): void
    {
        $this->db->execute(
            'INSERT INTO app_login_audit (user_id, username_attempt, ip_address, user_agent, was_successful) VALUES (?, ?, ?, ?, ?)',
            [$userId, $usernameAttempt, $ipAddress, $userAgent, $wasSuccessful ? 1 : 0]
        );
    }
}
