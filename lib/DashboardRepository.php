<?php
declare(strict_types=1);

namespace MaritanoDashboard\Lib;

use DateTimeImmutable;
use RuntimeException;

final class DashboardRepository
{
    private array $allowedBrands;
    private array $allowedBranches;
    private array $allowedVehicleTypes;

    public function __construct(
        private Database $db,
        array $allowedBrands = [],
        array $allowedBranches = [],
        array $allowedVehicleTypes = ['VN']
    ) {
        $this->allowedBrands = array_values(array_unique(array_map(
            static fn(string $brand): string => strtoupper(trim($brand)),
            array_filter($allowedBrands)
        )));
        $this->allowedBranches = array_values(array_unique(array_map(
            static fn(string $branch): string => trim((string)$branch),
            array_filter($allowedBranches)
        )));
        $this->allowedVehicleTypes = array_values(array_unique(array_map(
            static fn(string $type): string => strtoupper(trim($type)),
            array_filter($allowedVehicleTypes)
        )));

        if ($this->allowedVehicleTypes === []) {
            throw new RuntimeException('No hay tipos de vehículo habilitados para este usuario.');
        }
    }

    public function getDashboardData(array $filters): array
    {
        $vehicleType = $this->resolveVehicleType($filters['vehicle_type'] ?? null);
        $filters['vehicle_type'] = $vehicleType;
        $period = $this->buildPeriods($filters['month'] ?? null);

        return [
            'meta' => [
                'selected_month' => $period['selected_month'],
                'comparison_mode' => $period['comparison_mode'],
                'current_start' => $period['current_start']->format('Y-m-d'),
                'current_end_exclusive' => $period['current_end_exclusive']->format('Y-m-d'),
                'previous_start' => $period['previous_start']->format('Y-m-d'),
                'previous_end_exclusive' => $period['previous_end_exclusive']->format('Y-m-d'),
                'selected_vehicle_type' => $vehicleType,
                'allowed_vehicle_types' => $this->allowedVehicleTypes,
            ],
            'filters' => $filters,
            'kpis' => $this->getKpis($period, $filters),
            'dailySales' => $this->getDailySales($period, $filters),
            'salesByBrand' => $this->getSalesByBrand($period, $filters),
            'salesBySeller' => $this->getSalesBySeller($period, $filters),
            'salesByBranch' => $this->getSalesByBranch($period, $filters),
            'tableRows' => $this->getTableRows($period, $filters),
        ];
    }

    public function getOptions(array $filters = []): array
    {
        $vehicleType = $this->resolveVehicleType($filters['vehicle_type'] ?? null);
        $filters['vehicle_type'] = $vehicleType;

        $params = array_merge($this->brandParams(), $this->filterParams($filters, false));
        $baseSql = $this->baseCte() . $this->baseWhereClause($filters, false);

        $brands = array_map(
            static fn(array $row): string => (string)$row['marca'],
            $this->db->fetchAll($baseSql . ' SELECT DISTINCT marca FROM ventas_filtradas ORDER BY marca;', $params)
        );

        $sellers = $this->db->fetchAll(
            $baseSql . '
            SELECT DISTINCT vendedor_codigo, vendedor
            FROM ventas_filtradas
            WHERE vendedor_codigo IS NOT NULL AND vendedor_codigo <> \'\'
            ORDER BY vendedor;',
            $params
        );

        $branches = $this->db->fetchAll(
            $baseSql . '
            SELECT DISTINCT sucursal_codigo, sucursal, ciudad, comuna
            FROM ventas_filtradas
            WHERE sucursal_codigo IS NOT NULL
            ORDER BY sucursal;',
            $params
        );

        $months = $this->db->fetchAll(
            $baseSql . '
            SELECT DISTINCT CONVERT(char(7), fecha_factura, 120) AS periodo
            FROM ventas_filtradas
            ORDER BY periodo DESC;',
            $params
        );

        return [
            'brands' => $brands,
            'sellers' => $sellers,
            'branches' => $branches,
            'months' => array_map(static fn(array $row): string => (string)$row['periodo'], $months),
            'vehicle_types' => array_map(
                static fn(string $code): array => [
                    'code' => $code,
                    'name' => $code === 'VU' ? 'Usados' : 'Nuevos',
                ],
                $this->allowedVehicleTypes
            ),
            'selected_vehicle_type' => $vehicleType,
        ];
    }

    private function getKpis(array $period, array $filters): array
    {
        $params = array_merge(
            $this->brandParams(),
            $this->filterParams($filters),
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['previous_start']->format('Y-m-d'), $period['previous_end_exclusive']->format('Y-m-d')]
        );

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT
                periodo,
                COUNT(*) AS unidades,
                SUM(COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0)) AS total_venta,
                SUM(COALESCE(TRY_CONVERT(decimal(18,2), VALNET), 0)) AS total_neto,
                COUNT(DISTINCT vendedor_codigo) AS vendedores_activos,
                COUNT(DISTINCT sucursal_codigo) AS sucursales_activas,
                SUM(CASE WHEN con_credito = 1 THEN 1 ELSE 0 END) AS creditos_totales
            FROM (
                SELECT \'actual\' AS periodo, *
                FROM ventas_filtradas
                WHERE fecha_factura >= ? AND fecha_factura < ?
                UNION ALL
                SELECT \'anterior\' AS periodo, *
                FROM ventas_filtradas
                WHERE fecha_factura >= ? AND fecha_factura < ?
            ) q
            GROUP BY periodo;';

        $rows = $this->db->fetchAll($sql, $params);
        $out = [
            'actual' => ['unidades' => 0, 'total_venta' => 0, 'total_neto' => 0, 'vendedores_activos' => 0, 'sucursales_activas' => 0, 'creditos_totales' => 0, 'penetracion_credito' => 0.0],
            'anterior' => ['unidades' => 0, 'total_venta' => 0, 'total_neto' => 0, 'vendedores_activos' => 0, 'sucursales_activas' => 0, 'creditos_totales' => 0, 'penetracion_credito' => 0.0],
        ];

        foreach ($rows as $row) {
            $key = (string)$row['periodo'];
            $out[$key] = [
                'unidades' => (int)$row['unidades'],
                'total_venta' => (float)$row['total_venta'],
                'total_neto' => (float)$row['total_neto'],
                'vendedores_activos' => (int)$row['vendedores_activos'],
                'sucursales_activas' => (int)$row['sucursales_activas'],
                'creditos_totales' => (int)$row['creditos_totales'],
                'penetracion_credito' => 0.0,
            ];
        }

        foreach (['actual', 'anterior'] as $periodKey) {
            $unidades = (int)$out[$periodKey]['unidades'];
            $creditos = (int)$out[$periodKey]['creditos_totales'];
            $out[$periodKey]['penetracion_credito'] = $unidades > 0
                ? round(($creditos / $unidades) * 100, 1)
                : 0.0;
        }

        return $out;
    }

    private function getDailySales(array $period, array $filters): array
    {
        $params = array_merge(
            $this->brandParams(),
            $this->filterParams($filters),
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['previous_start']->format('Y-m-d'), $period['previous_end_exclusive']->format('Y-m-d')]
        );

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT periodo, DAY(fecha_factura) AS dia, COUNT(*) AS unidades,
                   SUM(COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0)) AS total_venta
            FROM (
                SELECT \'actual\' AS periodo, *
                FROM ventas_filtradas
                WHERE fecha_factura >= ? AND fecha_factura < ?
                UNION ALL
                SELECT \'anterior\' AS periodo, *
                FROM ventas_filtradas
                WHERE fecha_factura >= ? AND fecha_factura < ?
            ) q
            GROUP BY periodo, DAY(fecha_factura)
            ORDER BY dia, periodo;';

        return $this->db->fetchAll($sql, $params);
    }

    private function getSalesByBrand(array $period, array $filters): array
    {
        $params = array_merge(
            $this->brandParams(),
            $this->filterParams($filters),
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['previous_start']->format('Y-m-d'), $period['previous_end_exclusive']->format('Y-m-d')],
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['previous_start']->format('Y-m-d'), $period['previous_end_exclusive']->format('Y-m-d')]
        );

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT marca,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) AS unidades_actual,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) AS unidades_anterior,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0) ELSE 0 END) AS venta_actual,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0) ELSE 0 END) AS venta_anterior
            FROM ventas_filtradas
            GROUP BY marca
            ORDER BY unidades_actual DESC, marca;';

        return $this->db->fetchAll($sql, $params);
    }

    private function getSalesBySeller(array $period, array $filters): array
    {
        $params = array_merge(
            $this->brandParams(),
            $this->filterParams($filters),
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')]
        );

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT TOP 20 vendedor_codigo, vendedor,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) AS unidades_actual,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0) ELSE 0 END) AS venta_actual
            FROM ventas_filtradas
            GROUP BY vendedor_codigo, vendedor
            HAVING SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) > 0
            ORDER BY unidades_actual DESC, venta_actual DESC, vendedor;';

        return $this->db->fetchAll($sql, $params);
    }

    private function getSalesByBranch(array $period, array $filters): array
    {
        $params = array_merge(
            $this->brandParams(),
            $this->filterParams($filters),
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['previous_start']->format('Y-m-d'), $period['previous_end_exclusive']->format('Y-m-d')],
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')],
            [$period['previous_start']->format('Y-m-d'), $period['previous_end_exclusive']->format('Y-m-d')],
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')]
        );

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT sucursal_codigo, sucursal, ciudad, comuna,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) AS unidades_actual,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) AS unidades_anterior,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0) ELSE 0 END) AS venta_actual,
                   SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN COALESCE(TRY_CONVERT(decimal(18,2), VALTOT), 0) ELSE 0 END) AS venta_anterior
            FROM ventas_filtradas
            GROUP BY sucursal_codigo, sucursal, ciudad, comuna
            HAVING SUM(CASE WHEN fecha_factura >= ? AND fecha_factura < ? THEN 1 ELSE 0 END) > 0
            ORDER BY unidades_actual DESC, sucursal;';

        return $this->db->fetchAll($sql, $params);
    }

    private function getTableRows(array $period, array $filters): array
    {
        $params = array_merge(
            $this->brandParams(),
            $this->filterParams($filters),
            [$period['current_start']->format('Y-m-d'), $period['current_end_exclusive']->format('Y-m-d')]
        );

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT TOP 200 fecha_factura, nro_factura, num_nota_venta, marca, modelo, vendedor, sucursal, ciudad, comuna, TIPVEH, con_credito,
                   TRY_CONVERT(decimal(18,2), VALTOT) AS total_venta,
                   TRY_CONVERT(decimal(18,2), VALNET) AS total_neto
            FROM ventas_filtradas
            WHERE fecha_factura >= ? AND fecha_factura < ?
            ORDER BY fecha_factura DESC, nro_factura DESC;';

        return $this->db->fetchAll($sql, $params);
    }

    private function baseCteOptions(): string
    {
        $brandWhere = '';
        if ($this->allowedBrands !== []) {
            $brandWhere = 'WHERE UPPER(LTRIM(RTRIM(DESCRI))) IN (' . implode(',', array_fill(0, count($this->allowedBrands), '?')) . ')';
        }

        return "
            WITH marcas_catalogo AS (
                SELECT MARCAS, MAX(LTRIM(RTRIM(DESCRI))) AS marca
                FROM SG_MODMAR_MARCAS
                {$brandWhere}
                GROUP BY MARCAS
            ),
            modelos_catalogo AS (
                SELECT MARCAS, MODELO AS modelo_codigo, MAX(LTRIM(RTRIM(DESCRI))) AS modelo
                FROM SG_MODMAR_MODELO
                GROUP BY MARCAS, MODELO
            ),
            ventas_base AS (
                SELECT
                    CAST(n.FECFAC AS date) AS fecha_factura,
                    n.NUMNVT AS num_nota_venta,
                    n.NROFAC AS nro_factura,
                    n.CLACLI,
                    n.CLAEMP,
                    n.CLIENT,
                    n.CODVEN AS vendedor_codigo,
                    COALESCE(NULLIF(LTRIM(RTRIM(v.NOMBRE)), ''), '(Sin vendedor)') AS vendedor,
                    n.CENRES AS departamento_codigo,
                    cr.NOMBRE AS departamento,
                    CASE
                        WHEN n.TIPVEH = 'VU' THEN CAST(n.CENRES AS varchar(50))
                        ELSE CAST(cr.CSUCUR AS varchar(50))
                    END AS sucursal_codigo,
                    CASE
                        WHEN n.TIPVEH = 'VU' THEN COALESCE(NULLIF(LTRIM(RTRIM(cr.NOMBRE)), ''), COALESCE(NULLIF(LTRIM(RTRIM(s.nombre)), ''), '(Sin local)'))
                        ELSE COALESCE(NULLIF(LTRIM(RTRIM(s.nombre)), ''), '(Sin sucursal)')
                    END AS sucursal,
                    COALESCE(NULLIF(LTRIM(RTRIM(s.ciudad)), ''), '(Sin ciudad)') AS ciudad,
                    COALESCE(NULLIF(LTRIM(RTRIM(s.comuna)), ''), '(Sin comuna)') AS comuna,
                    n.MARCAS AS marca_codigo,
                    m.marca,
                    n.MODELO AS modelo_codigo,
                    COALESCE(mo.modelo, CAST(n.MODELO AS varchar(50))) AS modelo,
                    n.TIPVEH,
                    n.CODVEH,
                    n.PATENT,
                    n.VALNET,
                    n.VALIVA,
                    n.VALTOT,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM VH_CREFIN cf
                            WHERE cf.CLACLI = n.CLACLI
                              AND cf.CLAEMP = n.CLAEMP
                              AND cf.TIPVEH = n.TIPVEH
                              AND cf.CODVEH = n.CODVEH
                              AND cf.NUMNVT = n.NUMNVT
                        ) THEN 1
                        ELSE 0
                    END AS con_credito,
                    n.ESTADO
                FROM VH_NOTVEN n
                INNER JOIN marcas_catalogo m ON n.MARCAS = m.MARCAS
                LEFT JOIN modelos_catalogo mo ON n.MARCAS = mo.MARCAS AND n.MODELO = mo.modelo_codigo
                LEFT JOIN SG_VENDED v ON n.CLACLI = v.CLACLI AND n.CODVEN = v.CODVEN
                LEFT JOIN SG_IDEMPR_CENRES cr ON n.CLACLI = cr.CLACLI AND n.CLAEMP = cr.CLAEMP AND n.CENRES = cr.CENRES
                LEFT JOIN SG_IDEMPR_SUCURS s ON cr.CLACLI = s.clacli AND cr.CLAEMP = s.claemp AND cr.CSUCUR = s.csucur
                WHERE n.ESTADO IN (0, 3)
                  AND n.NROFAC IS NOT NULL
                  AND TRY_CONVERT(bigint, n.NROFAC) <> 0
                  AND n.FECFAC IS NOT NULL
            )
        ";
    }

    private function baseCte(): string
    {
        return $this->baseCteOptions() . '
            , ventas_filtradas AS (
                SELECT *
                FROM ventas_base
                WHERE 1 = 1 ';
    }

    private function baseWhereClause(array $filters, bool $includeFilterFields = true): string
    {
        $sql = $this->accessWhereClause();

        if (!empty($filters['vehicle_type'])) {
            $sql .= ' AND TIPVEH = ? ';
        }

        if ($includeFilterFields) {
            if (!empty($filters['brand'])) {
                $sql .= ' AND marca = ? ';
            }
            if (!empty($filters['seller'])) {
                $sql .= ' AND vendedor_codigo = ? ';
            }
            if (!empty($filters['branch'])) {
                $sql .= ' AND CAST(sucursal_codigo AS varchar(50)) = ? ';
            }
            if (!empty($filters['city'])) {
                $sql .= ' AND ciudad = ? ';
            }
        }

        return $sql . ' ) ';
    }

    private function accessWhereClause(): string
    {
        $sql = '';

        if ($this->allowedBranches !== []) {
            $sql .= ' AND CAST(sucursal_codigo AS varchar(50)) IN (' . implode(',', array_fill(0, count($this->allowedBranches), '?')) . ') ';
        }

        if ($this->allowedVehicleTypes !== []) {
            $sql .= ' AND TIPVEH IN (' . implode(',', array_fill(0, count($this->allowedVehicleTypes), '?')) . ') ';
        }

        return $sql;
    }

    private function filterParams(array $filters, bool $includeFilterFields = true): array
    {
        $params = $this->accessParams();

        if (!empty($filters['vehicle_type'])) {
            $params[] = $filters['vehicle_type'];
        }

        if ($includeFilterFields) {
            if (!empty($filters['brand'])) {
                $params[] = $filters['brand'];
            }
            if (!empty($filters['seller'])) {
                $params[] = $filters['seller'];
            }
            if (!empty($filters['branch'])) {
                $params[] = $filters['branch'];
            }
            if (!empty($filters['city'])) {
                $params[] = $filters['city'];
            }
        }

        return $params;
    }

    private function brandParams(): array
    {
        return $this->allowedBrands;
    }

    private function accessParams(): array
    {
        return array_merge($this->allowedBranches, $this->allowedVehicleTypes);
    }

    private function resolveVehicleType(?string $vehicleType): string
    {
        $candidate = strtoupper(trim((string)$vehicleType));
        if ($candidate === '') {
            return $this->allowedVehicleTypes[0];
        }

        if (!in_array($candidate, $this->allowedVehicleTypes, true)) {
            throw new RuntimeException('Tipo de vehículo no permitido para este usuario.');
        }

        return $candidate;
    }

    private function buildPeriods(?string $month): array
    {
        $month = $month ?: (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new RuntimeException('El mes debe venir en formato YYYY-MM.');
        }

        $currentStart = new DateTimeImmutable($month . '-01');
        $currentEndExclusive = $currentStart->modify('first day of next month');
        $now = new DateTimeImmutable('today');
        $isCurrentMonth = $month === $now->format('Y-m');
        $previousStart = $currentStart->modify('first day of previous month');
        $previousEndExclusive = $currentStart;
        $comparisonMode = 'full_month';

        if ($isCurrentMonth) {
            $daysElapsed = (int)$now->format('j');
            $currentEndExclusive = $currentStart->modify('+' . $daysElapsed . ' days');
            $previousEndExclusive = $previousStart->modify('+' . $daysElapsed . ' days');
            $comparisonMode = 'month_to_date';
        }

        return [
            'selected_month' => $month,
            'comparison_mode' => $comparisonMode,
            'current_start' => $currentStart,
            'current_end_exclusive' => $currentEndExclusive,
            'previous_start' => $previousStart,
            'previous_end_exclusive' => $previousEndExclusive,
        ];
    }
}
