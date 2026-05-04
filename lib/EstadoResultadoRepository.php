<?php
declare(strict_types=1);

namespace MaritanoDashboard\Lib;

use DateTimeImmutable;
use RuntimeException;

final class EstadoResultadoRepository
{
    private const COLUMNAS = [
        'TOYOTA',
        'CITROEN',
        'OPEL',
        'HONDA',
        'JETOUR',
        'MAXUS',
        'KARRY',
        'KAIYI',
        'SOUEAST',
        'OMODA/JAE',
        'BAIC',
        'USADOS',
        'SERVICIOS',
        'DYP',
        'REPUESTOS',
        'ADM',
        'TI',
        'TOTAL',
    ];

    public function __construct(private Database $db)
    {
    }

    public function getEstadoResultadoData(array $filters): array
    {
        [$periodo, $desde, $hasta] = $this->resolvePeriod($filters['month'] ?? null);

        $rows = $this->db->fetchAll($this->buildQuery(), [$desde, $hasta]);
        $matrixRows = $this->normalizeRows($rows);
        $matrixRows = $this->insertCalculatedPercentageRows($matrixRows);

        return [
            'meta' => [
                'periodo' => $periodo,
                'desde' => $desde,
                'hasta' => $hasta,
                'unidad' => 'miles',
                'fuente' => 'CO_MOVTOS_DET / CO_PLACTA / SG_PARAME',
            ],
            'columns' => self::COLUMNAS,
            'rows' => $matrixRows,
            'kpis' => $this->buildKpis($matrixRows),
        ];
    }

    private function resolvePeriod(?string $month): array
    {
        $month = trim((string)$month);

        if ($month === '') {
            $month = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new RuntimeException('Formato de mes inválido. Usa YYYY-MM.');
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$start instanceof DateTimeImmutable) {
            throw new RuntimeException('Mes inválido.');
        }

        $end = $start->modify('+1 month');

        return [
            $start->format('Y-m'),
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        ];
    }

    private function buildQuery(): string
    {
        return <<<'SQL'
;WITH base AS (
    SELECT
        CASE
            WHEN d.ARECOS = 'TYT' THEN 'TOYOTA'
            WHEN d.ARECOS = 'CIT' THEN 'CITROEN'
            WHEN d.ARECOS = 'OPE' THEN 'OPEL'
            WHEN d.ARECOS = 'HON' THEN 'HONDA'
            WHEN d.ARECOS = 'JET' THEN 'JETOUR'
            WHEN d.ARECOS = 'MAX' THEN 'MAXUS'
            WHEN d.ARECOS = 'KAR' THEN 'KARRY'
            WHEN d.ARECOS = 'KAI' THEN 'KAIYI'
            WHEN d.ARECOS = 'SOU' THEN 'SOUEAST'
            WHEN d.ARECOS IN ('OMO', 'JAE', 'OMD') THEN 'OMODA/JAE'
            WHEN d.ARECOS = 'BAI' THEN 'BAIC'
            WHEN d.ARECOS IN ('VEU', 'OTM') THEN 'USADOS'
            WHEN d.ARECOS = 'SER' THEN 'SERVICIOS'
            WHEN d.ARECOS = 'DYP' THEN 'DYP'
            WHEN d.ARECOS = 'REP' THEN 'REPUESTOS'
            WHEN d.ARECOS = 'ADM' THEN 'ADM'
            WHEN d.ARECOS = 'AD2' THEN 'ADM 2'
            WHEN d.ARECOS = 'TI' THEN 'TI'
            ELSE ISNULL(NULLIF(d.ARECOS, ''), 'SIN_CLASIFICAR')
        END AS columna_er,
        CASE
            WHEN d.CODCTA IN ('610101','610105') THEN 'Ingreso'
            WHEN d.CODCTA = '510101' AND d.CONGAS NOT IN ('059','060','062','063','066','068','070','071','072','073','075') THEN 'Costos'
            WHEN d.CONGAS IN ('059','060','062','063','066','068','070','071','072','073','075') THEN 'Remuneraciones'
            WHEN d.CONGAS IN ('045','047') THEN 'Arriendos'
            WHEN d.CONGAS IN ('128','134') THEN 'Publicidad'
            WHEN d.CODCTA = '520101' THEN 'Otros Gastos'
            WHEN d.CODCTA IN ('620101','620110','640101','650101') THEN 'Ingresos No Operacionales'
            WHEN d.CODCTA = '520102' THEN 'Depreciación'
            WHEN d.CODCTA = '530101' THEN 'Gastos Financieros'
            WHEN d.CODCTA IN ('540101','540110') THEN 'Egresos No Operacionales'
            WHEN d.CODCTA = '550101' THEN 'Impuesto'
            ELSE NULL
        END AS fila_base,
        SUM(d.VALHAB - d.VALDEB) / 1000.0 AS monto
    FROM dbo.CO_MOVTOS_DET d
    WHERE d.FECMOV >= ?
      AND d.FECMOV <  ?
      AND d.ESTADO = 0
      AND (
            d.CODCTA IN (
                '610101','610105',
                '510101','520101','520102',
                '530101','540101','540110',
                '550101','620101','620110','640101','650101'
            )
         OR d.CONGAS IN ('059','060','062','063','066','068','070','071','072','073','075','045','047','128','134')
      )
    GROUP BY
        d.ARECOS,
        CASE
            WHEN d.CODCTA IN ('610101','610105') THEN 'Ingreso'
            WHEN d.CODCTA = '510101' AND d.CONGAS NOT IN ('059','060','062','063','066','068','070','071','072','073','075') THEN 'Costos'
            WHEN d.CONGAS IN ('059','060','062','063','066','068','070','071','072','073','075') THEN 'Remuneraciones'
            WHEN d.CONGAS IN ('045','047') THEN 'Arriendos'
            WHEN d.CONGAS IN ('128','134') THEN 'Publicidad'
            WHEN d.CODCTA = '520101' THEN 'Otros Gastos'
            WHEN d.CODCTA IN ('620101','620110','640101','650101') THEN 'Ingresos No Operacionales'
            WHEN d.CODCTA = '520102' THEN 'Depreciación'
            WHEN d.CODCTA = '530101' THEN 'Gastos Financieros'
            WHEN d.CODCTA IN ('540101','540110') THEN 'Egresos No Operacionales'
            WHEN d.CODCTA = '550101' THEN 'Impuesto'
            ELSE NULL
        END
),
mapa AS (
    SELECT *
    FROM (VALUES
        (1, 'Ingreso', 'Ingreso'),
        (2, 'Costos', 'Costos'),
        (3, 'MC', 'Ingreso'),
        (3, 'MC', 'Costos'),
        (5, 'Remuneraciones', 'Remuneraciones'),
        (6, 'Arriendos', 'Arriendos'),
        (7, 'Publicidad', 'Publicidad'),
        (8, 'Otros Gastos', 'Otros Gastos'),
        (9, 'Total Gastos Adm. y Ventas', 'Remuneraciones'),
        (9, 'Total Gastos Adm. y Ventas', 'Arriendos'),
        (9, 'Total Gastos Adm. y Ventas', 'Publicidad'),
        (9, 'Total Gastos Adm. y Ventas', 'Otros Gastos'),
        (10, 'Resultado Operacional / EBITDA', 'Ingreso'),
        (10, 'Resultado Operacional / EBITDA', 'Costos'),
        (10, 'Resultado Operacional / EBITDA', 'Remuneraciones'),
        (10, 'Resultado Operacional / EBITDA', 'Arriendos'),
        (10, 'Resultado Operacional / EBITDA', 'Publicidad'),
        (10, 'Resultado Operacional / EBITDA', 'Otros Gastos'),
        (11, 'Ingresos No Operacionales', 'Ingresos No Operacionales'),
        (12, 'Depreciación', 'Depreciación'),
        (13, 'Gastos Financieros', 'Gastos Financieros'),
        (14, 'Egresos No Operacionales', 'Egresos No Operacionales'),
        (15, 'Resultado No Operacional', 'Ingresos No Operacionales'),
        (15, 'Resultado No Operacional', 'Depreciación'),
        (15, 'Resultado No Operacional', 'Gastos Financieros'),
        (15, 'Resultado No Operacional', 'Egresos No Operacionales'),
        (16, 'Resultado Antes de Impuesto', 'Ingreso'),
        (16, 'Resultado Antes de Impuesto', 'Costos'),
        (16, 'Resultado Antes de Impuesto', 'Remuneraciones'),
        (16, 'Resultado Antes de Impuesto', 'Arriendos'),
        (16, 'Resultado Antes de Impuesto', 'Publicidad'),
        (16, 'Resultado Antes de Impuesto', 'Otros Gastos'),
        (16, 'Resultado Antes de Impuesto', 'Ingresos No Operacionales'),
        (16, 'Resultado Antes de Impuesto', 'Depreciación'),
        (16, 'Resultado Antes de Impuesto', 'Gastos Financieros'),
        (16, 'Resultado Antes de Impuesto', 'Egresos No Operacionales'),
        (17, 'Impuesto', 'Impuesto'),
        (18, 'Resultado Después de Impuesto', 'Ingreso'),
        (18, 'Resultado Después de Impuesto', 'Costos'),
        (18, 'Resultado Después de Impuesto', 'Remuneraciones'),
        (18, 'Resultado Después de Impuesto', 'Arriendos'),
        (18, 'Resultado Después de Impuesto', 'Publicidad'),
        (18, 'Resultado Después de Impuesto', 'Otros Gastos'),
        (18, 'Resultado Después de Impuesto', 'Ingresos No Operacionales'),
        (18, 'Resultado Después de Impuesto', 'Depreciación'),
        (18, 'Resultado Después de Impuesto', 'Gastos Financieros'),
        (18, 'Resultado Después de Impuesto', 'Egresos No Operacionales'),
        (18, 'Resultado Después de Impuesto', 'Impuesto')
    ) AS x(orden, fila_er, fila_base)
)
SELECT
    m.orden,
    m.fila_er,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'TOYOTA' THEN b.monto ELSE 0 END), 0), 0) AS TOYOTA,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'CITROEN' THEN b.monto ELSE 0 END), 0), 0) AS CITROEN,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'OPEL' THEN b.monto ELSE 0 END), 0), 0) AS OPEL,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'HONDA' THEN b.monto ELSE 0 END), 0), 0) AS HONDA,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'JETOUR' THEN b.monto ELSE 0 END), 0), 0) AS JETOUR,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'MAXUS' THEN b.monto ELSE 0 END), 0), 0) AS MAXUS,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'KARRY' THEN b.monto ELSE 0 END), 0), 0) AS KARRY,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'KAIYI' THEN b.monto ELSE 0 END), 0), 0) AS KAIYI,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'SOUEAST' THEN b.monto ELSE 0 END), 0), 0) AS SOUEAST,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'OMODA/JAE' THEN b.monto ELSE 0 END), 0), 0) AS [OMODA/JAE],
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'BAIC' THEN b.monto ELSE 0 END), 0), 0) AS BAIC,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'USADOS' THEN b.monto ELSE 0 END), 0), 0) AS USADOS,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'SERVICIOS' THEN b.monto ELSE 0 END), 0), 0) AS SERVICIOS,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'DYP' THEN b.monto ELSE 0 END), 0), 0) AS DYP,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'REPUESTOS' THEN b.monto ELSE 0 END), 0), 0) AS REPUESTOS,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'ADM' THEN b.monto ELSE 0 END), 0), 0) AS ADM,
    COALESCE(ROUND(SUM(CASE WHEN b.columna_er = 'TI' THEN b.monto ELSE 0 END), 0), 0) AS TI,
    COALESCE(ROUND(SUM(b.monto), 0), 0) AS TOTAL
FROM mapa m
LEFT JOIN base b
    ON b.fila_base = m.fila_base
GROUP BY
    m.orden,
    m.fila_er
ORDER BY
    m.orden;
SQL;
    }

    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $item = [
                'label' => (string)$row['fila_er'],
                'type' => 'money',
                'is_calculated' => in_array((string)$row['fila_er'], [
                    'MC',
                    'Total Gastos Adm. y Ventas',
                    'Resultado Operacional / EBITDA',
                    'Resultado No Operacional',
                    'Resultado Antes de Impuesto',
                    'Resultado Después de Impuesto',
                ], true),
                'values' => [],
            ];

            foreach (self::COLUMNAS as $column) {
                $item['values'][$column] = (float)($row[$column] ?? 0);
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function insertCalculatedPercentageRows(array $rows): array
    {
        $byLabel = [];
        foreach ($rows as $row) {
            $byLabel[$row['label']] = $row;
        }

        $mcPercent = $this->percentageRow('% MC', $byLabel['MC'] ?? null, $byLabel['Ingreso'] ?? null);
        $resultPercent = $this->percentageRow('%', $byLabel['Resultado Después de Impuesto'] ?? null, $byLabel['Ingreso'] ?? null);

        $final = [];
        foreach ($rows as $row) {
            $final[] = $row;
            if ($row['label'] === 'MC') {
                $final[] = $mcPercent;
            }
            if ($row['label'] === 'Resultado Después de Impuesto') {
                $final[] = $resultPercent;
            }
        }

        return $final;
    }

    private function percentageRow(string $label, ?array $numeratorRow, ?array $denominatorRow): array
    {
        $values = [];

        foreach (self::COLUMNAS as $column) {
            $denominator = (float)($denominatorRow['values'][$column] ?? 0);
            $numerator = (float)($numeratorRow['values'][$column] ?? 0);
            $values[$column] = abs($denominator) > 0.000001 ? ($numerator / $denominator) * 100 : 0;
        }

        return [
            'label' => $label,
            'type' => 'percent',
            'is_calculated' => true,
            'values' => $values,
        ];
    }

    private function buildKpis(array $rows): array
    {
        $byLabel = [];
        foreach ($rows as $row) {
            $byLabel[$row['label']] = $row;
        }

        return [
            'ingreso_total' => (float)($byLabel['Ingreso']['values']['TOTAL'] ?? 0),
            'mc_total' => (float)($byLabel['MC']['values']['TOTAL'] ?? 0),
            'margen_mc_total' => (float)($byLabel['% MC']['values']['TOTAL'] ?? 0),
            'ebitda_total' => (float)($byLabel['Resultado Operacional / EBITDA']['values']['TOTAL'] ?? 0),
            'resultado_total' => (float)($byLabel['Resultado Después de Impuesto']['values']['TOTAL'] ?? 0),
            'margen_resultado_total' => (float)($byLabel['%']['values']['TOTAL'] ?? 0),
        ];
    }
}
