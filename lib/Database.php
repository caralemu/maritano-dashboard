<?php
declare(strict_types=1);

namespace MaritanoDashboard\Lib;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private array $config;
    private $connection = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getConnection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $driver = $this->config['driver'] ?? 'sqlsrv';

        if ($driver === 'sqlsrv') {
            if (!function_exists('sqlsrv_connect')) {
                throw new RuntimeException('La extensión sqlsrv no está habilitada en PHP.');
            }

            $serverName = sprintf('%s,%s', $this->config['host'], $this->config['port']);
            $connectionInfo = [
                'Database' => $this->config['database'],
                'UID' => $this->config['username'],
                'PWD' => $this->config['password'],
                'CharacterSet' => 'UTF-8',
                'TrustServerCertificate' => (bool)($this->config['trust_server_certificate'] ?? true),
                'Encrypt' => (bool)($this->config['encrypt'] ?? false),
                'ReturnDatesAsStrings' => true,
            ];

            $conn = sqlsrv_connect($serverName, $connectionInfo);
            if ($conn === false) {
                throw new RuntimeException($this->formatSqlsrvErrors('No se pudo conectar a SQL Server.'));
            }

            $this->connection = $conn;
            return $conn;
        }

        if ($driver === 'pdo_sqlsrv') {
            if (!extension_loaded('pdo_sqlsrv')) {
                throw new RuntimeException('La extensión pdo_sqlsrv no está habilitada en PHP.');
            }

            $dsn = sprintf(
                'sqlsrv:Server=%s,%s;Database=%s;TrustServerCertificate=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                ($this->config['trust_server_certificate'] ?? true) ? '1' : '0'
            );

            $this->connection = $this->connectPdo($dsn, $this->config['username'], $this->config['password']);
            return $this->connection;
        }

        if ($driver === 'pdo_mysql') {
            if (!extension_loaded('pdo_mysql')) {
                throw new RuntimeException('La extensión pdo_mysql no está habilitada en PHP.');
            }

            $charset = $this->config['charset'] ?? 'utf8mb4';
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $charset
            );

            $this->connection = $this->connectPdo($dsn, $this->config['username'], $this->config['password']);
            return $this->connection;
        }

        throw new RuntimeException('Driver no soportado: ' . $driver);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $driver = $this->config['driver'] ?? 'sqlsrv';

        if ($driver === 'sqlsrv') {
            $stmt = sqlsrv_query($this->getConnection(), $sql, $params);
            if ($stmt === false) {
                throw new RuntimeException($this->formatSqlsrvErrors('Error ejecutando consulta.'));
            }

            $rows = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = $row;
            }
            sqlsrv_free_stmt($stmt);
            return $rows;
        }

        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $driver = $this->config['driver'] ?? 'sqlsrv';

        if ($driver === 'sqlsrv') {
            $stmt = sqlsrv_query($this->getConnection(), $sql, $params);
            if ($stmt === false) {
                throw new RuntimeException($this->formatSqlsrvErrors('Error ejecutando comando.'));
            }
            sqlsrv_free_stmt($stmt);
            return true;
        }

        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function lastInsertId(): string
    {
        $driver = $this->config['driver'] ?? 'sqlsrv';
        if ($driver === 'sqlsrv') {
            $row = $this->fetchOne('SELECT SCOPE_IDENTITY() AS id');
            return (string)($row['id'] ?? '0');
        }

        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        return $pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $driver = $this->config['driver'] ?? 'sqlsrv';
        if ($driver === 'sqlsrv') {
            if (!sqlsrv_begin_transaction($this->getConnection())) {
                throw new RuntimeException($this->formatSqlsrvErrors('No se pudo iniciar transacción.'));
            }
            return;
        }

        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        $pdo->beginTransaction();
    }

    public function commit(): void
    {
        $driver = $this->config['driver'] ?? 'sqlsrv';
        if ($driver === 'sqlsrv') {
            if (!sqlsrv_commit($this->getConnection())) {
                throw new RuntimeException($this->formatSqlsrvErrors('No se pudo confirmar transacción.'));
            }
            return;
        }

        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        $pdo->commit();
    }

    public function rollBack(): void
    {
        $driver = $this->config['driver'] ?? 'sqlsrv';
        if ($driver === 'sqlsrv') {
            sqlsrv_rollback($this->getConnection());
            return;
        }

        /** @var PDO $pdo */
        $pdo = $this->getConnection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function connectPdo(string $dsn, string $username, string $password): PDO
    {
        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('No se pudo conectar por PDO: ' . $e->getMessage(), 0, $e);
        }
    }

    private function formatSqlsrvErrors(string $prefix): string
    {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS) ?: [];
        $chunks = [$prefix];

        foreach ($errors as $error) {
            $chunks[] = sprintf(
                '[SQLSTATE %s] (%s) %s',
                $error['SQLSTATE'] ?? 'N/A',
                $error['code'] ?? 'N/A',
                $error['message'] ?? 'Sin detalle'
            );
        }

        return implode(' ', $chunks);
    }
}
