# Maritano Dashboard PHP (prototipo)

Prototipo web en PHP para reportería de ventas nuevas facturadas desde SQL Server.

## Qué muestra
- KPIs del mes seleccionado vs mes anterior
- Venta diaria comparada
- Unidades por marca
- Top vendedores
- Sucursales con ciudad/comuna
- Tabla detalle de facturados

## Regla de negocio usada
- Fuente principal: `VH_NOTVEN`
- Solo vehículos nuevos: `TIPVEH = 'VN'`
- Solo facturados: `NROFAC IS NOT NULL` y `NROFAC <> 0`
- Fecha de análisis: `FECFAC`
- Estado permitido: `0` y `3`
- Sucursal se resuelve por:
  - `VH_NOTVEN.CENRES`
  - `SG_IDEMPR_CENRES.CENRES -> CSUCUR`
  - `SG_IDEMPR_SUCURS.CSUCUR`
- Ciudad/comuna salen de `SG_IDEMPR_SUCURS`

## Estructura
- `index.php`: pantalla principal
- `api/options.php`: combos de filtros
- `api/dashboard_data.php`: datos del dashboard
- `lib/Database.php`: conexión SQL Server
- `lib/DashboardRepository.php`: consultas
- `config.php`: configuración local

## Cómo probar
1. Copia la carpeta en tu Apache/PHP.
2. Edita `config.php`.
3. Configura:
   - host
   - puerto
   - base
   - usuario
   - clave
   - driver (`sqlsrv` o `pdo_sqlsrv`)
4. Asegúrate de tener el driver de SQL Server habilitado en PHP.
5. Abre `index.php` en el navegador.

## Observación importante
El catálogo de marcas se amarra por código de marca (`MARCAS`) porque en tus pruebas el join por `CLACLI`/`CLAEMP` contra `SG_MODMAR_MARCAS` no siempre resolvía bien el nombre.

## Siguiente mejora recomendada
- Exportar a Excel
- KPI por sucursal con meta
- Vista mensual por marca y vendedor
- Ranking por ciudad
- caché simple para no consultar SQL Server en cada clic
