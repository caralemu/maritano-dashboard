<?php
declare(strict_types=1);

namespace MaritanoDashboard\Lib;

use RuntimeException;

final class UserRepository
{
    public function __construct(private Database $appDb, private Database $sigaDb)
    {
    }

    public function listUsers(): array
    {
        $rows = $this->appDb->fetchAll(
            'SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.is_active, u.last_login_at,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ", ") AS roles
             FROM app_users u
             LEFT JOIN app_user_roles ur ON ur.user_id = u.id
             LEFT JOIN app_roles r ON r.id = ur.role_id
             GROUP BY u.id, u.username, u.first_name, u.last_name, u.email, u.is_active, u.last_login_at
             ORDER BY u.first_name, u.last_name, u.username'
        );

        foreach ($rows as &$row) {
            $row['brands'] = $this->getUserBrandNames((int)$row['id']);
            $row['branches'] = $this->getUserBranchNames((int)$row['id']);
            $row['vehicle_types'] = $this->getUserVehicleTypeNames((int)$row['id']);
        }

        return $rows;
    }

    public function getUserById(int $userId): ?array
    {
        $user = $this->appDb->fetchOne(
            'SELECT id, username, first_name, last_name, email, is_active, force_password_change
             FROM app_users WHERE id = ? LIMIT 1',
            [$userId]
        );

        if ($user === null) {
            return null;
        }

        $roleRows = $this->appDb->fetchAll('SELECT role_id FROM app_user_roles WHERE user_id = ?', [$userId]);
        $brandRows = $this->appDb->fetchAll('SELECT marca_id FROM app_user_marcas WHERE user_id = ?', [$userId]);
        $branchRows = $this->appDb->fetchAll('SELECT sucursal_id FROM app_user_sucursales WHERE user_id = ?', [$userId]);
        $vehicleTypeRows = $this->appDb->fetchAll('SELECT vehicle_type_id FROM app_user_vehicle_types WHERE user_id = ?', [$userId]);

        $user['role_ids'] = array_map(static fn(array $row): int => (int)$row['role_id'], $roleRows);
        $user['marca_ids'] = array_map(static fn(array $row): int => (int)$row['marca_id'], $brandRows);
        $user['sucursal_ids'] = array_map(static fn(array $row): int => (int)$row['sucursal_id'], $branchRows);
        $user['vehicle_type_ids'] = array_map(static fn(array $row): int => (int)$row['vehicle_type_id'], $vehicleTypeRows);

        return $user;
    }

    public function getRoles(): array
    {
        return $this->appDb->fetchAll('SELECT id, code, name FROM app_roles WHERE is_active = 1 ORDER BY name');
    }

    public function getBrands(): array
    {
        return $this->appDb->fetchAll('SELECT id, siga_codigo, nombre FROM app_marcas WHERE is_active = 1 ORDER BY sort_order, nombre');
    }

    public function getBranches(): array
    {
        return $this->appDb->fetchAll('SELECT id, siga_csucur, nombre, ciudad, comuna FROM app_sucursales WHERE is_active = 1 ORDER BY nombre');
    }

    public function getVehicleTypes(): array
    {
        return $this->appDb->fetchAll('SELECT id, code, name FROM app_vehicle_types WHERE is_active = 1 ORDER BY sort_order, name');
    }

    public function saveUser(array $data): int
    {
        $userId = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : 0;
        $username = trim((string)($data['username'] ?? ''));
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $forcePasswordChange = !empty($data['force_password_change']) ? 1 : 0;
        $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));
        $marcaIds = array_values(array_unique(array_map('intval', $data['marca_ids'] ?? [])));
        $sucursalIds = array_values(array_unique(array_map('intval', $data['sucursal_ids'] ?? [])));
        $vehicleTypeIds = array_values(array_unique(array_map('intval', $data['vehicle_type_ids'] ?? [])));

        if ($username === '' || $firstName === '' || $lastName === '') {
            throw new RuntimeException('Usuario, nombre y apellido son obligatorios.');
        }

        if ($roleIds === []) {
            throw new RuntimeException('Debes asignar al menos un rol.');
        }

        if ($vehicleTypeIds === []) {
            throw new RuntimeException('Debes asignar al menos un tipo de vehículo.');
        }

        if ($userId === 0 && $password === '') {
            throw new RuntimeException('La contraseña es obligatoria para un usuario nuevo.');
        }

        $this->appDb->beginTransaction();
        try {
            if ($userId === 0) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $this->appDb->execute(
                    'INSERT INTO app_users (username, password_hash, first_name, last_name, email, is_active, force_password_change) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$username, $passwordHash, $firstName, $lastName, $email !== '' ? $email : null, $isActive, $forcePasswordChange]
                );
                $userId = (int)$this->appDb->lastInsertId();
            } else {
                $params = [$username, $firstName, $lastName, $email !== '' ? $email : null, $isActive, $forcePasswordChange, $userId];
                $sql = 'UPDATE app_users SET username = ?, first_name = ?, last_name = ?, email = ?, is_active = ?, force_password_change = ?';
                if ($password !== '') {
                    $sql .= ', password_hash = ?';
                    array_splice($params, 6, 0, [password_hash($password, PASSWORD_DEFAULT)]);
                }
                $sql .= ' WHERE id = ?';
                $this->appDb->execute($sql, $params);

                $this->appDb->execute('DELETE FROM app_user_roles WHERE user_id = ?', [$userId]);
                $this->appDb->execute('DELETE FROM app_user_marcas WHERE user_id = ?', [$userId]);
                $this->appDb->execute('DELETE FROM app_user_sucursales WHERE user_id = ?', [$userId]);
                $this->appDb->execute('DELETE FROM app_user_vehicle_types WHERE user_id = ?', [$userId]);
            }

            foreach ($roleIds as $roleId) {
                $this->appDb->execute('INSERT INTO app_user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
            }
            foreach ($marcaIds as $marcaId) {
                $this->appDb->execute('INSERT INTO app_user_marcas (user_id, marca_id) VALUES (?, ?)', [$userId, $marcaId]);
            }
            foreach ($sucursalIds as $sucursalId) {
                $this->appDb->execute('INSERT INTO app_user_sucursales (user_id, sucursal_id) VALUES (?, ?)', [$userId, $sucursalId]);
            }
            foreach ($vehicleTypeIds as $vehicleTypeId) {
                $this->appDb->execute('INSERT INTO app_user_vehicle_types (user_id, vehicle_type_id) VALUES (?, ?)', [$userId, $vehicleTypeId]);
            }

            $this->appDb->commit();
            return $userId;
        } catch (\Throwable $e) {
            $this->appDb->rollBack();
            throw $e;
        }
    }

    public function toggleUser(int $userId): void
    {
        $this->appDb->execute('UPDATE app_users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?', [$userId]);
    }

    public function syncCatalogsFromSiga(): array
    {
        $brandRows = $this->sigaDb->fetchAll(
            "SELECT MARCAS AS siga_codigo, MAX(LTRIM(RTRIM(DESCRI))) AS nombre
             FROM SG_MODMAR_MARCAS
             WHERE DESCRI IS NOT NULL
             GROUP BY MARCAS
             ORDER BY nombre"
        );

        $branchRows = $this->sigaDb->fetchAll(
            "SELECT DISTINCT
                CAST(clacli AS varchar(20)) AS siga_clacli,
                CAST(claemp AS varchar(20)) AS siga_claemp,
                CAST(csucur AS varchar(20)) AS siga_csucur,
                LTRIM(RTRIM(nombre)) AS nombre,
                LTRIM(RTRIM(ciudad)) AS ciudad,
                LTRIM(RTRIM(comuna)) AS comuna
             FROM SG_IDEMPR_SUCURS
             WHERE nombre IS NOT NULL
             ORDER BY nombre"
        );

        $this->appDb->beginTransaction();
        try {
            foreach ($brandRows as $index => $row) {
                $nombre = trim((string)$row['nombre']);
                $sigaCodigo = (int)$row['siga_codigo'];

                $existing = $this->appDb->fetchOne('SELECT id FROM app_marcas WHERE siga_codigo = ? LIMIT 1', [$sigaCodigo]);
                if ($existing === null) {
                    $existing = $this->appDb->fetchOne('SELECT id FROM app_marcas WHERE UPPER(nombre) = UPPER(?) LIMIT 1', [$nombre]);
                }

                if ($existing === null) {
                    $this->appDb->execute(
                        'INSERT INTO app_marcas (siga_codigo, nombre, sort_order, is_active) VALUES (?, ?, ?, 1)',
                        [$sigaCodigo, $nombre, ($index + 1) * 10]
                    );
                } else {
                    $this->appDb->execute(
                        'UPDATE app_marcas SET siga_codigo = ?, nombre = ?, is_active = 1 WHERE id = ?',
                        [$sigaCodigo, $nombre, (int)$existing['id']]
                    );
                }
            }

            foreach ($branchRows as $row) {
                $existing = $this->appDb->fetchOne(
                    'SELECT id FROM app_sucursales WHERE siga_clacli = ? AND siga_claemp = ? AND siga_csucur = ? LIMIT 1',
                    [(string)$row['siga_clacli'], (string)$row['siga_claemp'], (string)$row['siga_csucur']]
                );
                if ($existing === null) {
                    $this->appDb->execute(
                        'INSERT INTO app_sucursales (siga_clacli, siga_claemp, siga_csucur, nombre, ciudad, comuna, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
                        [
                            (string)$row['siga_clacli'],
                            (string)$row['siga_claemp'],
                            (string)$row['siga_csucur'],
                            trim((string)$row['nombre']),
                            trim((string)($row['ciudad'] ?? '')),
                            trim((string)($row['comuna'] ?? '')),
                        ]
                    );
                } else {
                    $this->appDb->execute(
                        'UPDATE app_sucursales SET nombre = ?, ciudad = ?, comuna = ?, is_active = 1 WHERE id = ?',
                        [
                            trim((string)$row['nombre']),
                            trim((string)($row['ciudad'] ?? '')),
                            trim((string)($row['comuna'] ?? '')),
                            (int)$existing['id'],
                        ]
                    );
                }
            }

            $this->appDb->commit();
        } catch (\Throwable $e) {
            $this->appDb->rollBack();
            throw $e;
        }

        return [
            'brands_synced' => count($brandRows),
            'branches_synced' => count($branchRows),
        ];
    }

    private function getUserBrandNames(int $userId): array
    {
        $rows = $this->appDb->fetchAll(
            'SELECT m.nombre FROM app_user_marcas um INNER JOIN app_marcas m ON m.id = um.marca_id WHERE um.user_id = ? ORDER BY m.sort_order, m.nombre',
            [$userId]
        );

        return array_map(static fn(array $row): string => (string)$row['nombre'], $rows);
    }

    private function getUserBranchNames(int $userId): array
    {
        $rows = $this->appDb->fetchAll(
            'SELECT s.nombre, s.ciudad FROM app_user_sucursales us INNER JOIN app_sucursales s ON s.id = us.sucursal_id WHERE us.user_id = ? ORDER BY s.nombre',
            [$userId]
        );

        return array_map(
            static fn(array $row): string => trim((string)$row['nombre'] . (!empty($row['ciudad']) ? ' / ' . (string)$row['ciudad'] : '')),
            $rows
        );
    }

    private function getUserVehicleTypeNames(int $userId): array
    {
        $rows = $this->appDb->fetchAll(
            'SELECT vt.name FROM app_user_vehicle_types uvt INNER JOIN app_vehicle_types vt ON vt.id = uvt.vehicle_type_id WHERE uvt.user_id = ? ORDER BY vt.sort_order, vt.name',
            [$userId]
        );

        return array_map(static fn(array $row): string => (string)$row['name'], $rows);
    }
}
