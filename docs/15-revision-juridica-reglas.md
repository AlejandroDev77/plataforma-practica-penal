# Habilitación y decisiones jurídicas de reglas

Estado: capa interna de persistencia y consulta implementada. Sin endpoints, comandos públicos, frontend, asignación automática de roles ni activación de reglas. Las pruebas usan únicamente identidades, referencias y aprobaciones sintéticas.

## Autoridad y evidencia

`GestionarRevisionJuridicaReglas` recibe al actor desde el contexto autenticado del servidor. Un administrador de plataforma puede habilitar a otra cuenta para un tipo de audiencia y alcance concretos. Debe declarar expresamente que verificó la acreditación, registrar su referencia y huella y fijar una fecha de vencimiento con zona horaria. No puede habilitarse a sí mismo ni mantener dos habilitaciones vigentes del mismo ámbito para la misma cuenta.

No se crea ni asigna un rol `revisor_juridico`: el permiso efectivo proviene del registro vigente y no revocado. Tener ese nombre de rol no habilita a revisar. Los permisos técnicos de habilitación se reconsultan, incluidos los intentos por SQL frente a los roles `web` del modelo `App\Models\User`. Un futuro cambio de morph map/guard requiere adaptar y probar esa comprobación; no se admite de forma silenciosa.

La referencia y la huella de acreditación son evidencia declarada por el responsable humano. Esta fase no sube documentos, consulta un registro profesional ni comprueba automáticamente que la acreditación sea auténtica o suficiente. La organización debe realizar esa comprobación antes de habilitar una cuenta; no se presume competencia por una casilla o por ser administrador. Las evidencias deben conservarse fuera de este registro con acceso controlado para poder contrastar su huella.

## Decisión sobre una versión exacta

El revisor habilitado puede registrar `aprobado` o `requiere_cambios` sobre una versión sellada. Se conservan la habilitación utilizada, identidad del revisor, huella de la versión, alcance, observación, referencias y huellas de una a veinte fuentes, fecha declarada de comprobación de vigencia y confirmación humana explícita (`true`, no un valor implícito).

El servidor fija identidad, ámbito, habilitación y huella; no acepta esos campos como autoridad desde el payload. El alcance revisado es exactamente el de la habilitación, no otro elegido por el cliente. La fecha de comprobación no puede estar en el futuro según `America/La_Paz`; no se impone una antigüedad legal ficticia. La persona revisora debe evaluar si esa comprobación sigue siendo suficiente.

Un revisor no puede ser autor de la versión ni de ninguna propuesta contenida. Si falta la autoría de una propuesta, la independencia no se acredita y se bloquea la decisión; no se deduce de que la cuenta original ya no exista. Una aprobación exige vencimiento explícito, posterior al registro y no posterior al vencimiento de la habilitación. `requiere_cambios` no lleva fecha de vencimiento ni se interpreta como aprobación.

El sistema comprueba el enlace y la huella de la versión, los permisos y la integridad del registro. Las huellas/referencias normativas son declaraciones de la revisión humana: no se descargan fuentes ni se verifica automáticamente su contenido, vigencia o suficiencia jurídica. Una aprobación registrada no es por sí misma permiso de ejecución y no demuestra que una audiencia haya sido realizada conforme al derecho vigente.

## Revocación e historial

La propia persona revisora o un administrador de plataforma pueden retirar una habilitación o decisión con motivo obligatorio. Un administrador puede bloquear una aprobación por seguridad, no emitirla sin habilitación. Los reintentos autorizados de una misma revocación devuelven el registro original, sin reemplazar autor, motivo o fecha.

Las cuatro tablas son anexas e inmutables:

- `habilitaciones_revision_juridica`;
- `revocaciones_habilitacion_juridica`;
- `revisiones_reglas_audiencia`;
- `revocaciones_revision_reglas`.

El modelo y PostgreSQL rechazan edición, borrado y truncado. Las FK no destruyen historial en cascada. El rollback se niega a retirar tablas con registros. Se conserva la restricción de borrado de cuentas/tipos con historial; anonimización y conservación requieren una política posterior explícita. Un propietario de PostgreSQL puede alterar DDL o protecciones: esto no es una firma digital ni auditoría inviolable frente a privilegios administrativos de base de datos. Un acceso directo a la base tampoco acredita la identidad de una sesión; debe protegerse por separado.

Los bloqueos siguen el orden habilitación → versión. Registrar una decisión y revocar su habilitación se coordinan sobre la misma fila; tras obtener el bloqueo se comprueba otra vez la revocación. Los triggers verifican contexto, vencimiento, independencia, fuente mínima y permisos de habilitación/revocación también frente a escrituras directas. Las fechas de registro las fija el reloj de PostgreSQL, no el payload.

Las escrituras exigen aislamiento `READ COMMITTED`; los triggers rechazan otro nivel para no utilizar una vista antigua que omita una revocación después de esperar un bloqueo. No cambiar el aislamiento de estos comandos sin rediseñar y probar la coordinación.

## Consulta y ejecución pendiente

`ConsultarRevisionJuridicaReglas::ejecutar(idVersion)` obtiene en una única vista de consulta la última decisión (fecha de registro e ID como desempate), su habilitación y revocaciones. Estados: `no_acreditada`, `aprobado`, `requiere_cambios`, `revision_revocada`, `habilitacion_revocada`, `habilitacion_vencida` o `revision_vencida`. El vencimiento se evalúa con el reloj de PostgreSQL.

Nunca recupera una aprobación anterior si la última decisión fue revocada o requiere cambios. Renovar una habilitación no rehabilita aprobaciones ligadas a la anterior: se requiere una nueva revisión. Siempre devuelve `execution_authorized=false` y `requires_separate_activation=true`. Es un diagnóstico interno, no un endpoint autorizado ni un permiso reservado para ejecutar comandos; los futuros consumidores deben comprobar acceso y volver a validar el estado dentro de una transacción.

El orquestador de prácticas no se modifica en este hito: aún no hay un vínculo ejecutable entre una simulación y una versión revisada. Las propuestas no se convierten automáticamente en instrucciones ni se copian a turnos activos. Falta una operación separada de activación con validación estructural completa y compatibilidad de roles, y la captura de esa versión en nuevas simulaciones sin alterar las existentes.

## Instalación y pruebas

Migración aditiva `2026_10_01_000002_registrar_revision_juridica_reglas.php`, dependiente de la migración de versiones selladas. No introduce registros reales ni modifica expedientes:

```powershell
php artisan migrate --path=database/migrations/2026_10_01_000002_registrar_revision_juridica_reglas.php
```

`RevisionJuridicaReglasTest` comprueba aislamiento de pruebas en PostgreSQL `jurissim_pruebas` antes de refrescar esquema y bloquea HTTP real. Cubre autoridad, evidencia, independencia, ámbito, huellas, confirmación, revocación, renovación, vencimiento, historial y rollback. La prueba de versiones vacías revierte primero las dependencias de revisión para respetar el orden de migraciones; las pruebas de truncado usan `CASCADE` para comprobar los triggers, no obtener un falso positivo por una FK.

Verificación del 1 de octubre de 2026: suite de la rama, 140 pruebas y 795 aserciones aprobadas. En la carpeta habitual pasaron las 27 pruebas nuevas, las 22 de versiones y las 20 del orquestador (69 pruebas, 302 aserciones), siempre en `jurissim_pruebas`. Pint correcto y copias de archivos verificadas. Se instaló únicamente esta migración aditiva en `jurissim` local: cuatro tablas con cero registros reales y doce triggers activos. No se habilitaron cuentas, emitieron decisiones reales, alteraron expedientes ni activaron reglas.

## Archivos de este hito

- Migración: `backend/database/migrations/2026_10_01_000002_registrar_revision_juridica_reglas.php`.
- Modelos: `RegistroJuridicoInmutable.php`, `HabilitacionRevisionJuridica.php`, `RevisionReglasAudiencia.php`, `RevocacionHabilitacionJuridica.php` y `RevocacionRevisionReglas.php`, en `backend/app/Models`.
- Servicios: `GestionarRevisionJuridicaReglas.php` y `ConsultarRevisionJuridicaReglas.php`, en `backend/app/Services/Simulaciones`.
- Pruebas: `RevisionJuridicaReglasTest.php` y `VersionReglasAudienciaTest.php`, en `backend/tests/Feature`.
- Documentación: `docs/04-simulacion.md`, `docs/14-versiones-reglas-audiencia.md` y este documento.

La fase siguiente implementa la [API de gobierno de reglas](16-api-revision-reglas.md): consultas acotadas, sellado, habilitaciones, decisiones con precondición y revocaciones. Mantiene intactos los registros, sin asignar habilitaciones reales ni activar reglas. Sigue pendiente la interfaz que presente evidencias y versión exacta; la activación permanece separada y exige revisión profesional real.
