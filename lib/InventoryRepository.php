<?php
declare(strict_types=1);

namespace MaritanoDashboard\Lib;

use RuntimeException;

final class InventoryRepository
{
    /** @var string[] */
    private array $allowedBrands;
    /** @var string[] */
    private array $allowedBranches;
    /** @var string[] */
    private array $allowedVehicleTypes;

    public function __construct(
        private Database $db,
        array $allowedBrands = [],
        array $allowedBranches = [],
        array $allowedVehicleTypes = ['VN']
    ) {
        $this->allowedBrands = array_values(array_filter(array_map(static fn($v): string => appUpper((string)$v), $allowedBrands)));
        $this->allowedBranches = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $allowedBranches)));
        $vehicleTypes = array_values(array_filter(array_map(static fn($v): string => strtoupper(trim((string)$v)), $allowedVehicleTypes)));
        $this->allowedVehicleTypes = $vehicleTypes !== [] ? $vehicleTypes : ['VN'];
    }

    public function getOptions(array $filters): array
    {
        $vehicleType = $this->resolveVehicleType($filters['vehicle_type'] ?? null);
        $baseFilters = ['vehicle_type' => $vehicleType];

        $brandRows = $this->db->fetchAll(
            $this->baseCte() . $this->baseWhereClause($baseFilters, false) . '
                SELECT DISTINCT marca
                FROM inventario_filtrado
                ORDER BY marca;',
            array_merge($this->brandParams(), $this->filterParams($baseFilters, false))
        );

        $branchRows = $this->db->fetchAll(
            $this->baseCte() . $this->baseWhereClause($baseFilters, false) . '
                SELECT DISTINCT local_codigo, local_nombre, sucursal_codigo, sucursal_nombre, ciudad, comuna
                FROM inventario_filtrado
                ORDER BY local_nombre, ciudad;',
            array_merge($this->brandParams(), $this->filterParams($baseFilters, false))
        );

        return [
            'brands' => array_map(static fn(array $row): string => (string)$row['marca'], $brandRows),
            'branches' => $branchRows,
        ];
    }

    public function getInventoryData(array $filters): array
    {
        $filters['vehicle_type'] = $this->resolveVehicleType($filters['vehicle_type'] ?? null);

        return [
            'meta' => [
                'selected_vehicle_type' => $filters['vehicle_type'],
                'generated_at' => date('Y-m-d H:i:s'),
                'assumptions' => [
                    'Fuente única: VH_VEHNEW.',
                    'Disponible para venta: ESTADO = 1.',
                    'Se excluyen vehículos con nota de venta: NRONVT = 0.',
                    'Se excluyen activos fijos con TIPPRO = 4.',
                    'VN usa sucursal corporativa.',
                    'VU usa centro_nombre basado en CENRES como local físico.',
                    'Días inventario calculados desde FECCRE, y si no existe, desde FECREC_R o FECDOC_R.',
                    'Fallback de precio desde SG_MODMAR_MODELO_PRECIO.PREUNI cuando PRELIS y PREOFE vienen en 0.',
                ],
            ],
            'kpis' => $this->getKpis($filters),
            'inventoryByBrand' => $this->getInventoryByBrand($filters),
            'inventoryByBranch' => $this->getInventoryByBranch($filters),
            'detailRows' => $this->getDetailRows($filters),
        ];
    }

    private function getKpis(array $filters): array
    {
        $params = array_merge($this->brandParams(), $this->filterParams($filters));

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT
                COUNT(*) AS unidades,
                SUM(COALESCE(valor_inventario, 0)) AS valor_referencia_total,
                SUM(COALESCE(valor_costo, 0)) AS valor_costo_total,
                COUNT(DISTINCT local_codigo) AS locales_con_stock,
                COUNT(DISTINCT marca) AS marcas_con_stock
            FROM inventario_filtrado;';

        $row = $this->db->fetchOne($sql, $params) ?? [];

        return [
            'unidades' => (int)($row['unidades'] ?? 0),
            'valor_referencia_total' => (float)($row['valor_referencia_total'] ?? 0),
            'valor_costo_total' => (float)($row['valor_costo_total'] ?? 0),
            'sucursales_con_stock' => (int)($row['locales_con_stock'] ?? 0),
            'marcas_con_stock' => (int)($row['marcas_con_stock'] ?? 0),
        ];
    }

    private function getInventoryByBrand(array $filters): array
    {
        $params = array_merge($this->brandParams(), $this->filterParams($filters));

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT
                marca,
                COUNT(*) AS unidades,
                SUM(COALESCE(valor_inventario, 0)) AS valor_referencia_total,
                SUM(COALESCE(valor_costo, 0)) AS valor_costo_total
            FROM inventario_filtrado
            GROUP BY marca
            ORDER BY unidades DESC, valor_referencia_total DESC, marca;';

        return $this->db->fetchAll($sql, $params);
    }

    private function getInventoryByBranch(array $filters): array
    {
        $params = array_merge($this->brandParams(), $this->filterParams($filters));

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT
                local_codigo,
                local_nombre,
                ciudad,
                comuna,
                COUNT(*) AS unidades,
                SUM(COALESCE(valor_inventario, 0)) AS valor_referencia_total,
                SUM(COALESCE(valor_costo, 0)) AS valor_costo_total
            FROM inventario_filtrado
            GROUP BY local_codigo, local_nombre, ciudad, comuna
            ORDER BY unidades DESC, local_nombre;';

        return $this->db->fetchAll($sql, $params);
    }

    private function getDetailRows(array $filters): array
    {
        $params = array_merge($this->brandParams(), $this->filterParams($filters));

        $sql = $this->baseCte() . $this->baseWhereClause($filters) . '
            SELECT TOP 600
                TIPVEH AS vehicle_type,
                codigo_interno,
                marca,
                modelo,
                patente,
                local_nombre,
                ciudad,
                fecha_ingreso_referencia,
                dias_inventario,
                valor_lista,
                valor_oferta,
                valor_inventario
            FROM inventario_filtrado
            ORDER BY TIPVEH, codigo_interno DESC;';

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
                SELECT
                    MARCAS,
                    MAX(LTRIM(RTRIM(DESCRI))) AS marca
                FROM SG_MODMAR_MARCAS
                {$brandWhere}
                GROUP BY MARCAS
            ),
            modelos_catalogo_tipo AS (
                SELECT
                    MARCAS,
                    TIPVEH,
                    MODELO AS modelo_codigo,
                    MAX(LTRIM(RTRIM(DESCRI))) AS modelo
                FROM SG_MODMAR_MODELO
                WHERE DESCRI IS NOT NULL
                GROUP BY MARCAS, TIPVEH, MODELO
            ),
            modelos_catalogo_general AS (
                SELECT
                    MARCAS,
                    MODELO AS modelo_codigo,
                    MAX(LTRIM(RTRIM(DESCRI))) AS modelo
                FROM SG_MODMAR_MODELO
                WHERE DESCRI IS NOT NULL
                GROUP BY MARCAS, MODELO
            ),
            inventario_base AS (
                SELECT
                    CAST(v.TIPVEH AS varchar(2)) AS TIPVEH,
                    CAST(v.CODVEH AS varchar(50)) AS codigo_interno,
                    CAST(NULLIF(v.PATENT, '') AS varchar(50)) AS patente,
                    CAST(NULLIF(v.NROVIN, '') AS varchar(80)) AS nro_vin,
                    CAST(NULLIF(v.NCHASI, '') AS varchar(80)) AS nro_chasis,
                    v.CENRES AS centro_resuelto,
                    cr.CSUCUR AS sucursal_codigo,
                    COALESCE(NULLIF(LTRIM(RTRIM(s.NOMBRE)), ''), '(Sin sucursal)') AS sucursal_nombre,
                    COALESCE(NULLIF(LTRIM(RTRIM(cr.NOMBRE)), ''), '(Sin local)') AS centro_nombre,
                    CASE
                        WHEN v.TIPVEH = 'VU' THEN CAST(v.CENRES AS varchar(50))
                        ELSE CAST(cr.CSUCUR AS varchar(50))
                    END AS local_codigo,
                    CASE
                        WHEN v.TIPVEH = 'VU' THEN COALESCE(NULLIF(LTRIM(RTRIM(cr.NOMBRE)), ''), '(Sin local)')
                        ELSE COALESCE(NULLIF(LTRIM(RTRIM(s.NOMBRE)), ''), '(Sin sucursal)')
                    END AS local_nombre,
                    COALESCE(NULLIF(LTRIM(RTRIM(s.CIUDAD)), ''), '(Sin ciudad)') AS ciudad,
                    COALESCE(NULLIF(LTRIM(RTRIM(s.COMUNA)), ''), '(Sin comuna)') AS comuna,
                    m.marca,
                    COALESCE(mtt.modelo, mtg.modelo, CAST(v.MODELO AS varchar(50))) AS modelo,
                    TRY_CONVERT(int, v.ANOFAB) AS anio_fabricacion,
                    CAST(NULLIF(v.CCOLOR, '') AS varchar(80)) AS color,
                    TRY_CONVERT(decimal(18,2), v.VALCOM) AS valor_costo,
                    TRY_CONVERT(decimal(18,2), CASE
                        WHEN ISNULL(v.PRELIS, 0) > 0 THEN v.PRELIS
                        ELSE precio_catalogo.precio_catalogo
                    END) AS valor_lista,
                    TRY_CONVERT(decimal(18,2), v.PREOFE) AS valor_oferta,
                    TRY_CONVERT(decimal(18,2), CASE
                        WHEN ISNULL(v.PREOFE, 0) > 0 THEN v.PREOFE
                        WHEN ISNULL(v.PRELIS, 0) > 0 THEN v.PRELIS
                        ELSE ISNULL(precio_catalogo.precio_catalogo, 0)
                    END) AS valor_inventario,
                    fechas.fecha_ingreso_referencia,
                    CASE
                        WHEN fechas.fecha_ingreso_referencia IS NULL THEN NULL
                        ELSE DATEDIFF(DAY, fechas.fecha_ingreso_referencia, CAST(GETDATE() AS date))
                    END AS dias_inventario
                FROM VH_VEHNEW v
                INNER JOIN marcas_catalogo m
                    ON v.MARCAS = m.MARCAS
                LEFT JOIN modelos_catalogo_tipo mtt
                    ON v.MARCAS = mtt.MARCAS
                   AND v.TIPVEH = mtt.TIPVEH
                   AND v.MODELO = mtt.modelo_codigo
                LEFT JOIN modelos_catalogo_general mtg
                    ON v.MARCAS = mtg.MARCAS
                   AND v.MODELO = mtg.modelo_codigo
                LEFT JOIN SG_IDEMPR_CENRES cr
                    ON v.CLACLI = cr.CLACLI
                   AND v.CLAEMP = cr.CLAEMP
                   AND v.CENRES = cr.CENRES
                LEFT JOIN SG_IDEMPR_SUCURS s
                    ON cr.CLACLI = s.CLACLI
                   AND cr.CLAEMP = s.CLAEMP
                   AND cr.CSUCUR = s.CSUCUR
                OUTER APPLY (
                    SELECT TOP 1
                        TRY_CONVERT(decimal(18,2), p.PREUNI) AS precio_catalogo
                    FROM SG_MODMAR_MODELO_PRECIO p
                    WHERE p.CLACLI = v.CLACLI
                      AND p.MARCAS = v.MARCAS
                      AND p.MODELO = v.MODELO
                      AND ISNULL(TRY_CONVERT(int, p.REGIME), 0) = 0
                      AND ISNULL(TRY_CONVERT(int, p.PREUNI), 0) > 0
                      AND ISNULL(TRY_CONVERT(int, p.ANOFAB), 0) IN (ISNULL(TRY_CONVERT(int, v.ANOFAB), 0), 0)
                    ORDER BY CASE
                        WHEN ISNULL(TRY_CONVERT(int, p.ANOFAB), 0) = ISNULL(TRY_CONVERT(int, v.ANOFAB), 0) THEN 0
                        WHEN ISNULL(TRY_CONVERT(int, p.ANOFAB), 0) = 0 THEN 1
                        ELSE 2
                    END,
                    TRY_CONVERT(int, p.ANOFAB) DESC
                ) precio_catalogo
                OUTER APPLY (
                    SELECT
                        CAST(COALESCE(
                            NULLIF(CAST(v.FECCRE AS date), CAST('1900-01-01' AS date)),
                            NULLIF(CAST(v.FECREC_R AS date), CAST('1900-01-01' AS date)),
                            NULLIF(CAST(v.FECDOC_R AS date), CAST('1900-01-01' AS date))
                        ) AS date) AS fecha_ingreso_referencia
                ) fechas
                WHERE v.ESTADO = 1
                  AND v.TIPVEH IN ('VN', 'VU')
                  AND ISNULL(TRY_CONVERT(int, v.NRONVT), 0) = 0
                  AND ISNULL(TRY_CONVERT(int, v.TIPPRO), 0) <> 4
            )
        ";
    }

    private function baseCte(): string
    {
        return $this->baseCteOptions() . '
            , inventario_filtrado AS (
                SELECT *
                FROM inventario_base
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
            if (!empty($filters['branch'])) {
                $sql .= ' AND CAST(local_codigo AS varchar(50)) = ? ';
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
}
