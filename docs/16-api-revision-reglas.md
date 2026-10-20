# API de gobierno y revisión de reglas

Fase backend: API autenticada sobre el sellado y la revisión interna. No añade migraciones, interfaz, revisores reales, contenido jurídico, activación ni llamadas a modelos. Se continúa en `funcionalidad/versiones-reglas-audiencia`; publicación y PR siguen pendientes de autorización.

## Acceso

Todas las rutas usan `auth:sanctum`, bajo `/api/v1/reglas-audiencia`. Se reconsulta el rol administrativo; una relación de roles cargada antes de su retirada no concede acceso.

- `administrador_plataforma`: consulta todas las versiones/habilitaciones, sella borradores, registra habilitaciones y revoca registros. Es autoridad técnica: no puede emitir una revisión sin habilitación vigente e independencia.
- Revisor: consulta versiones e historial de decisiones exclusivamente del tipo de audiencia para el cual tiene habilitación no revocada y no vencida. Un nombre de rol no sustituye ese registro. Puede consultar y revocar su propia habilitación, incluso vencida; puede retirar sus propias decisiones aunque haya perdido su habilitación.
- Otras cuentas: colecciones vacías y `404` para registros ajenos. No se acepta un ID de usuario del cliente para ampliar el ámbito de consulta. Habilitar y sellar sin administración devuelve `403`.
- La evidencia de acreditación solo se devuelve al administrador y a su titular; un revisor no obtiene la acreditación de otros revisores del mismo tipo. No se serializan cuentas, correos, contraseñas, tokens ni rutas de almacenamiento.

## Rutas

| Método y ruta relativa | Operación |
| --- | --- |
| `GET /versiones` | Resúmenes autorizados sin el contenido completo |
| `GET /versiones/{version}` | Instantánea con campos permitidos, estado y precondición |
| `POST /tipos/{tipo}/versiones` | Sellar el catálogo de ese tipo; solo administración |
| `GET /habilitaciones` | Todas para administración; propias para el titular |
| `GET /habilitaciones/{habilitacion}` | Evidencia, ámbito, vencimiento y retiro autorizado |
| `POST /habilitaciones` | Habilitar a otra cuenta; solo administración |
| `POST /habilitaciones/{habilitacion}/revocaciones` | Retiro por administración o titular |
| `GET /versiones/{version}/revisiones` | Historial del ámbito autorizado, no evidencia de acreditación |
| `POST /versiones/{version}/revisiones` | Decisión humana independiente con precondición |
| `POST /revisiones/{revision}/revocaciones` | Retiro por administración o autor de la decisión |

No hay `PATCH`, `PUT` ni `DELETE` del historial. Colecciones con `data` y `meta` (`current_page`, `last_page`, `per_page`, `total`); `page >= 1`, `por_pagina` predeterminado 20 y máximo 50. El filtro opcional `id_tipo_audiencia` solo restringe el ámbito permitido. Los cuerpos de entrada se reducen a los campos aceptados; no se asigna masivamente el payload.

Escrituras limitadas a 10 solicitudes por minuto según la clave autenticada de Laravel. Las respuestas del grupo incluyen `Cache-Control: no-store, private`; no compartir ni almacenar acreditaciones en cachés cliente. Una consulta representa el estado observado, no una reserva de autorización; toda escritura vuelve a comprobar permisos y estado.

## Cuerpos

Sellado: `motivo` no vacío de hasta 2.000 caracteres. Tipo, autor, contenido, número y huella se determinan en el servidor, no desde el cuerpo. Respuesta `201`; no aprobar ni activar.

Habilitación: `id_usuario_revisor`, `id_tipo_audiencia`, `alcance` (hasta 2.000 caracteres), `referencia_acreditacion` (hasta 500), `huella_acreditacion` (64 caracteres hexadecimales minúsculos), `acreditacion_verificada: true` booleano JSON y `fecha_vencimiento` futura ISO 8601 con zona horaria. La administración debe comprobar evidencia real antes de confirmar; la API no consulta registros profesionales ni acredita autenticidad documental. No se permite autohabilitación ni dos habilitaciones vigentes del mismo ámbito. Respuesta `201`; no crea roles.

Decisión: `decision` (`aprobado` o `requiere_cambios`), `observacion` no vacía hasta 5.000 caracteres, `fuentes_verificadas` (lista de 1–20 objetos con `referencia` hasta 500 y `huella_contenido` hexadecimal de 64 caracteres), `vigencia_comprobada_en` (`YYYY-MM-DD`, no posterior al día de Bolivia), `confirmacion_humana: true` booleano JSON. Para aprobación se exige `fecha_vencimiento` futura con zona, no posterior al vencimiento de la habilitación; para cambios debe omitirse o ser `null`. Las referencias y huellas son declaraciones humanas, no certificación automática de vigencia jurídica.

Además, copiar sin modificar los tres campos de `data.precondicion_revision` obtenidos de la versión:

```json
{
  "huella_version_esperada": "huella de 64 caracteres obtenida del servidor",
  "id_ultima_revision_esperada": null,
  "estado_revision_esperado": "no_acreditada"
}
```

El ID es `null` solo si no hay decisión previa. Se comparan huella, última decisión y estado después de bloquear habilitación → versión. Una decisión o revocación posterior, o un vencimiento que cambie el estado, invalida la lectura previa: `409`, sin insertar otra decisión. Volver a consultar y revisar antes de repetir. No reintentar automáticamente con una precondición renovada: eso equivaldría a una nueva decisión humana. Dos envíos exitosos con la misma lectura previa no se aceptan; esto no es una clave de idempotencia que recupere una respuesta perdida.

La huella corresponde al JSONB completo sellado en PostgreSQL, no al JSON HTTP con campos permitidos: no recalcularla desde esa proyección. El servidor fija usuario revisor, tipo, habilitación, ámbito y huella de la decisión. Un payload no puede habilitar a su autor, modificar la versión ni autorizar ejecución.

Revocación: `motivo` no vacío hasta 2.000 caracteres; respuesta `200` con el registro y su retiro. Repetir una revocación con autorización devuelve el mismo retiro sin reemplazar motivo, autor o fecha. No recupera una aprobación anterior ni borra historial.

## Errores y límites

`401` sin sesión, `403` sin autoridad para una operación, `404` fuera del ámbito o inexistente, `422` entrada inválida, `409` conflicto o restricción de integridad, `429` exceso de frecuencia y `503` consulta/escritura no disponible. Los errores SQL del controlador se convierten antes del pipeline de excepciones en respuestas fijas sin SQL, bindings, evidencia ni trazas, incluso con depuración habilitada. No se devuelven mensajes privados de PostgreSQL.

`revision_juridica` conserva los estados documentados en [la capa interna](15-revision-juridica-reglas.md). Una decisión histórica aprobada no equivale a aprobación vigente: consultar la versión. Siempre `execution_authorized=false`; la activación y vinculación del orquestador son tareas posteriores. No se ha certificado el procedimiento boliviano ni concedido autoridad profesional a ninguna cuenta real.

## Archivos y verificación

- Controlador: `backend/app/Http/Controllers/Api/V1/GobiernoReglasAudienciaController.php`.
- Recursos permitidos: `VersionReglasAudienciaResource.php`, `HabilitacionRevisionJuridicaResource.php`, `RevisionReglasAudienciaResource.php`, en `backend/app/Http/Resources/Api/V1`.
- Caché: `backend/app/Http/Middleware/NoAlmacenarReglas.php`.
- Servicio extendido con precondición HTTP: `backend/app/Services/Simulaciones/GestionarRevisionJuridicaReglas.php`; los consumidores internos existentes conservan su contrato sin ese argumento opcional.
- Rutas: `backend/routes/api.php`.
- Pruebas: `backend/tests/Feature/Api/GobiernoReglasAudienciaTest.php`; guardia de base efectiva `jurissim_pruebas`, sin `DB_URL` ni caché y HTTP externo bloqueado. No probar POST contra la base habitual para verificar esta fase.
- Documentación: este archivo y enlaces desde `docs/04-simulacion.md` y `docs/15-revision-juridica-reglas.md`.

Verificación del 4 de octubre de 2026: suite completa de la rama, 160 pruebas y 973 aserciones aprobadas. En la carpeta habitual, API, servicios de revisión/versiones y diagnóstico del orquestador: 89 pruebas y 480 aserciones aprobadas. Todas se ejecutaron exclusivamente en `jurissim_pruebas`; Pint correcto, diez rutas registradas y copias de los archivos propios verificadas por SHA-256. No se concedieron habilitaciones reales, emitieron decisiones reales, ejecutaron migraciones nuevas ni contactaron modelos externos.

No hay comandos de migración nuevos. La interfaz administrativa de estas operaciones se documenta en [la fase frontend](17-interfaz-revision-reglas.md), con evidencias, precondición y confirmación explícitas; sin habilitaciones ficticias ni activación implícita.
