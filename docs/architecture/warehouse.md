# Almacenes

## Límite del dominio

Almacenes administra materiales consumibles por cantidad: catálogo, ingresos, existencias, solicitudes, entregas y kardex. Activos Fijos no se mezcla con estas tablas porque cada bien durable necesitará identificación patrimonial, serie, custodia, transferencia y baja individual. Una recepción de compra común podrá integrarse con ambos dominios cuando se implemente Activos Fijos.

Toda solicitud formal pertenece a un expediente de tipo `WAREHOUSES_INVENTORY`. El expediente conserva documentos, visibilidad y derivaciones; `material_requests` conserva la decisión logística y `warehouse_stock_movements` conserva el hecho físico. Ninguna de estas fuentes sustituye a las otras.

## Conducto regular

1. El funcionario prepara una solicitud desde una oficina vigente. Puede seleccionar artículos del catálogo o describir un material todavía no registrado.
2. Si la oficina exige responsable y el solicitante no es ese responsable, la solicitud espera su aprobación. El botón registra decisión, actor y contexto sin exigir un segundo documento.
3. La nota aprobada llega a OMAF como primer filtro institucional.
4. OMAF aprueba y deriva a Bienes y Servicios, o puede observar o rechazar con fundamento.
5. Bienes y Servicios aplica las mismas decisiones y, cuando corresponde, deriva a Activos Fijos y Almacenes (`AFALM`).
6. Almacenes determina la cantidad real por entregar. Una cantidad menor es una decisión definitiva y no crea saldo pendiente. Una cantidad superior es excepcional, está permitida y exige justificación.
7. La salida reduce existencias bajo bloqueo transaccional y la solicitud llega a la oficina solicitante para confirmar recepción.
8. La confirmación genera un acta oficial inmutable dentro del expediente y cierra la solicitud.

Una observación devuelve la tenencia a la oficina solicitante. La corrección genera una nueva versión documental y conserva los renglones anteriores por número de revisión. Al reenviarla vuelve a la etapa que formuló la observación.

## Estado y ubicación

`material_requests.status` representa el estado de negocio y `current_stage` representa la etapa organizacional. La oficina que posee el expediente se obtiene siempre del último movimiento documental. La interfaz combina ambos datos, por ejemplo `Pendiente — OMAF`, pero no inventa una segunda tenencia.

Estados persistidos:

- `draft`: todavía editable por el solicitante;
- `pending`: espera una decisión en la etapa actual;
- `observed`: volvió al solicitante para corrección;
- `in_attention`: Almacenes puede decidir la entrega;
- `pending_receipt`: la salida ya se registró y falta aceptación;
- `received`: transición auditable inmediatamente anterior al cierre;
- `not_attended`: cierre sin salida física;
- `rejected`: decisión terminal de una instancia de control;
- `closed`: recepción confirmada y acta generada.

El resultado `full`, `partial` o `none` se almacena aparte. De esa forma una solicitud cerrada no pierde la información de cómo fue atendida.

## Catálogo e ingresos

Los materiales pertenecen a una categoría y utilizan una unidad base. El nombre se normaliza en mayúsculas. La ubicación física es opcional porque existe un solo almacén y la institución acomoda cada lote según el espacio disponible.

Todo ingreso exige proveedor, factura o nota, número, fecha del respaldo, fecha de ingreso, cantidades y costo unitario en BOB. Lote, vencimiento, ubicación y archivos de respaldo son opcionales. La cabecera calcula el total desde sus renglones y cada renglón genera exactamente un movimiento positivo de kardex.

Los respaldos binarios se almacenan fuera de PostgreSQL; la base conserva ruta, tipo, tamaño y hash SHA-256.

## Entregas, recepción y acta

Cada renglón entregado referencia el renglón solicitado y el material real del catálogo. Esto permite homologar una descripción libre antes de entregarla. No se admiten existencias negativas.

El jefe vigente de AFALM figura como responsable institucional aunque un funcionario autorizado registre físicamente el ingreso o la entrega. La delegación operativa reutiliza la configuración persistente de acceso documental de AFALM: jefatura o equipo autorizado.

El solicitante confirma por defecto. Ante su ausencia, el responsable de la oficina solicitante puede autorizar para esa entrega a otro funcionario activo de la misma oficina. Almacenes no puede confirmar su propia salida. El acta distingue responsable de Almacenes, funcionario que entregó y persona que recibió realmente.

La confirmación crea un documento oficial de tipo `MINUTES`, un código aleatorio de verificación y una huella SHA-256 sobre los datos esenciales. La vista de Almacenes permite imprimir el acta; el documento también aparece dentro del expediente.

## Concurrencia y auditoría

Los ingresos, salidas y ajustes bloquean la fila del material antes de calcular el nuevo saldo. La transacción escribe el movimiento inmutable y actualiza el saldo proyectado. Si cualquier validación falla, ambos cambios se revierten.

`material_request_decisions` conserva toda transición con estado y etapa anterior/nueva, actor, oficina, fecha, fundamento y metadatos. `activity_logs` agrega IP y navegador para acciones relevantes. Un ajuste nunca reescribe movimientos anteriores: genera un movimiento nuevo con justificación obligatoria.

## Permisos

- `warehouse.requests.view`: ingresar al módulo y consultar solicitudes bajo el alcance documental.
- `warehouse.requests.create`: preparar, corregir y presentar solicitudes propias.
- `warehouse.requests.approve`: decidir en la etapa autorizada; la Policy exige además jefatura, oficina y tenencia.
- `warehouse.operations.manage`: registrar ingresos, entregas y ajustes; se restringe a AFALM y su equipo autorizado.
- `warehouse.catalog.manage`: mantener categorías y materiales bajo la misma restricción organizacional.

El superadministrador conserva consulta total. La matriz de permisos nunca reemplaza la comprobación de oficina, pertenencia, delegación y estado que ejecutan las Policies.
