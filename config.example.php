<?php
declare(strict_types=1);

return [
    'app_name' => 'Maritano - Gestión Comercial',
    'timezone' => 'America/Santiago',
    'session_name' => 'MARITANO_SESSID',
    'brand_catalog' => [
        'BAIC', 'CITROEN', 'HONDA', 'JAECOO', 'JETOUR', 'KAIYI', 'KARRY',
        'MAXUS', 'OMODA', 'OPEL', 'SOUEAST', 'TOYOTA',
    ],
    'connections' => [
        'siga' => [
            'driver' => 'sqlsrv', // sqlsrv | pdo_sqlsrv
            'host' => '127.0.0.1',
            'port' => 1433,
            'database' => 'MARITANO',
            'username' => 'usuario_sqlserver',
            'password' => 'clave_sqlserver',
            'encrypt' => true,
            'trust_server_certificate' => true,
        ],
        'app' => [
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'maritano_comercial',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ],
];
