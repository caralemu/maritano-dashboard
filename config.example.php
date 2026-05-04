<?php
declare(strict_types=1);

return [
    // Nombre visible de la aplicación
    'app_name' => 'Maritano - Gestión Comercial',

    // Zona horaria usada por PHP y por las fechas internas del sistema
    'timezone' => 'America/Santiago',

    // Nombre de la cookie de sesión PHP
    'session_name' => 'MARITANO_SESSID',

    // Catálogo base de marcas consideradas por el dashboard
    'brand_catalog' => [
        'BAIC', 'CITROEN', 'HONDA', 'JAECOO', 'JETOUR', 'KAIYI', 'KARRY',
        'MAXUS', 'OMODA', 'OPEL', 'SOUEAST', 'TOYOTA',
    ],

    'connections' => [
        // Conexión a SQL Server / SIGA
        'siga' => [
            // Valores permitidos: sqlsrv | pdo_sqlsrv
            'driver' => 'sqlsrv',
            'host' => 'IP_O_HOST_SQLSERVER',
            'port' => 1433,
            'database' => 'NOMBRE_BASE_SIGA',
            'username' => 'USUARIO_SIGA',
            'password' => 'CLAVE_SIGA',

            // En servidores internos con certificados no públicos normalmente se usa true en trust_server_certificate
            'encrypt' => true,
            'trust_server_certificate' => true,
        ],

        // Conexión a base interna de la aplicación, normalmente MariaDB/MySQL
        'app' => [
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'NOMBRE_BASE_APP',
            'username' => 'USUARIO_APP',
            'password' => 'CLAVE_APP',
            'charset' => 'utf8mb4',
        ],
    ],
];
