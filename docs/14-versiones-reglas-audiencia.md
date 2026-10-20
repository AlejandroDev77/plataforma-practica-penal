# Versiones selladas de reglas de audiencia

Estado: fase de persistencia. Las versiones son material para revisión, no reglas aprobadas ni ejecutables. No se crea un endpoint, una pantalla, un permiso de revisión ni una activación en este hito.

## Criterio de gobierno

Por delegación del usuario se adopta esta separación para el próximo flujo:

- El administrador de plataforma prepara propuestas y sella una versión para revisión. Esto es una atribución técnica, no una acreditación profesional.
- La aprobación deberá realizarla un revisor jurídico habilitado explícitamente, con competencia acreditada en el procedimiento boliviano del ámbito revisado. No puede ser el autor de la versión ni de las propuestas que revisa.
- La habilitación y sus evidencias deberán registrarse antes de conceder autoridad; no basta crear un nombre de rol. El registro público, la IA y el administrador por defecto no pueden autoatribuirse esa facultad.
- La decisión deberá identificar versión, huella, alcance, fuentes consultadas, vigencia comprobada, observaciones, responsable y fecha. Requerirá una operación humana explícita; no se deducirá de un flag JSON, un turno activo o la aprobación de un análisis.
- Revocar o cambiar el contenido no debe sobrescribir una decisión ni una versión histórica. La activación será una operación posterior y separada; las simulaciones existentes no se actualizarán silenciosamente.

La [capa interna de habilitación, decisiones y revocación](15-revision-juridica-reglas.md) está implementada por separado. No se atribuye ninguna revisión profesional a las cuentas existentes; todavía no hay habilitaciones reales, interfaz de revisión ni activación.

## Persistencia implementada

`versiones_reglas_audiencia` guarda `id_version_reglas`, `id_tipo_audiencia`, `id_usuario_autor`, `numero_version`, `motivo`, `contenido`, `huella_contenido` y `fecha_creacion`. No contiene una columna de aprobación o activación.

`SellarVersionReglasAudiencia::ejecutar(User $actor, int $idTipoAudiencia, string $motivo)` reconsulta el rol técnico del actor, exige un motivo no vacío de hasta 2000 caracteres y al menos una propuesta. La identidad procede del contexto autenticado del servidor, nunca de un campo de autor del navegador. Es un servicio de persistencia interno, todavía sin exposición por HTTP ni comando administrativo.

El servicio bloquea la fila del tipo de audiencia durante la numeración y captura en una única sentencia SQL una vista coherente de:

- el tipo de audiencia;
- todas sus etapas y transiciones, incluidos los elementos inactivos;
- sus propuestas editables, con autoría y referencias propuestas;
- sus turnos de catálogo existentes, identificados solo como referencia.

El contenido tiene `formato=1`, arrays ordenados de manera determinista y límite de 2.000.000 bytes en la representación capturada. Cada tipo tiene su propia numeración, con restricción única. No se convierten descripciones propuestas en instrucciones ni se copian propuestas a turnos activos. Cambiar o eliminar el borrador después no altera la versión sellada.

## Integridad y límites

PostgreSQL calcula la huella de contenido al insertar: `encode(sha256(convert_to(contenido::text, 'UTF8')), 'hex')`. No confía en una huella suministrada por el cliente. Estas funciones están documentadas en la [referencia oficial de PostgreSQL](https://www.postgresql.org/docs/16/functions-binarystring.html). La huella corresponde al texto de JSONB producido por PostgreSQL; no compararla con JSON serializado de otra manera por el navegador. No es una firma digital ni certifica corrección jurídica.

El modelo impide editar/eliminar versiones; triggers de base de datos también rechazan `UPDATE`, `DELETE` y `TRUNCATE`, incluidos los intentos mediante query builder o SQL directo. Un propietario de base o superusuario todavía puede modificar DDL o deshabilitar protecciones: esto no ofrece auditoría inviolable frente a un administrador de PostgreSQL.

Las claves foráneas restringen eliminar el tipo o la cuenta autora si conserva versiones. No hay cascada destructiva ni anonimización automática. Antes de implementar eliminación/anonimización de cuentas con versiones será necesario definir la política de conservación de autoría; esta fase no elimina cuentas ni datos personales.

Las restricciones de JSON verifican estructura general y correspondencia del tipo. No certifican roles, fuentes, suficiencia del debate, vigencia normativa ni aptitud para ejecutar una audiencia. Un acceso directo a PostgreSQL no pasa por la autorización del servicio: la cuenta de aplicación y los accesos administrativos deben controlarse por separado.

El rollback de la migración obtiene un bloqueo exclusivo y se niega a retirar la tabla si hay versiones. Solo permite revertir una tabla vacía. `migrate:fresh` o DDL privilegiado siguen siendo destructivos: están prohibidos contra la base habitual.

## Instalación y pruebas

Migración: `2026_10_01_000001_crear_versiones_reglas_audiencia.php`. Es aditiva y no importa reglas ni cambia expedientes, turnos o simulaciones. Al instalar este hito, ejecutar únicamente su migración desde `backend`, sin aplicar por accidente otras migraciones pendientes:

```powershell
php artisan migrate --path=database/migrations/2026_10_01_000001_crear_versiones_reglas_audiencia.php
```

`VersionReglasAudienciaTest` usa escenarios sintéticos, bloquea HTTP real y comprueba PostgreSQL, base configurada y efectiva `jurissim_pruebas`, ausencia de `DB_URL` y de configuración cacheada antes de refrescar el esquema. Cubre autorización, revocación de permisos, numeración, aislamiento de catálogos, huella, estructura, inmutabilidad, referencias, rollback y conservación frente a cascadas. Nunca ejecutar estas pruebas contra `jurissim`.

Verificación del 1 de octubre de 2026: suite de la rama, 113 pruebas y 683 aserciones aprobadas. En la carpeta habitual también pasaron las 22 pruebas de versiones y las 20 del orquestador (42 pruebas, 190 aserciones), siempre contra `jurissim_pruebas`. Pint correcto. Se instaló exclusivamente la migración aditiva en `jurissim` local; comprobación de solo lectura posterior: cero versiones reales y tres triggers activos. No se sellaron borradores, asignaron revisores ni activaron reglas.

Siguiente hito: operaciones administrativas e interfaz de revisión sobre esta capa interna, manteniendo la activación como operación posterior y separada.
