-- Validaciones rápidas para el prototipo

-- 1) Ver marcas nuevas presentes en la tabla base
SELECT DISTINCT
    n.MARCAS,
    m.DESCRI
FROM VH_NOTVEN n
LEFT JOIN SG_MODMAR_MARCAS m
    ON n.MARCAS = m.MARCAS
WHERE n.TIPVEH = 'VN'
  AND n.NROFAC IS NOT NULL
  AND TRY_CONVERT(bigint, n.NROFAC) <> 0
ORDER BY m.DESCRI;

-- 2) Revisar sucursal y ciudad desde una venta
SELECT TOP 50
    n.NUMNVT,
    n.NROFAC,
    n.FECFAC,
    n.CENRES,
    cr.CSUCUR,
    s.nombre AS sucursal,
    s.ciudad,
    s.comuna
FROM VH_NOTVEN n
LEFT JOIN SG_IDEMPR_CENRES cr
    ON n.CLACLI = cr.CLACLI
   AND n.CLAEMP = cr.CLAEMP
   AND n.CENRES = cr.CENRES
LEFT JOIN SG_IDEMPR_SUCURS s
    ON cr.CLACLI = s.clacli
   AND cr.CLAEMP = s.claemp
   AND cr.CSUCUR = s.csucur
WHERE n.TIPVEH = 'VN'
  AND n.NROFAC IS NOT NULL
  AND TRY_CONVERT(bigint, n.NROFAC) <> 0
ORDER BY n.FECFAC DESC;
