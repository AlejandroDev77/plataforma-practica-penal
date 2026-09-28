# Reglas para Codex/Astra

## Antes de programar
Lee:
- `00-contexto.md`;
- solo el documento de la fase actual.

No leas toda `docs/` salvo que sea necesario.

## Desarrollo
1. Implementa una sola fase por tarea.
2. No cambies arquitectura sin justificar.
3. Reutiliza componentes.
4. Usa TypeScript estricto.
5. Usa DTO/Request validation.
6. Usa Pydantic en FastAPI.
7. Controladores delgados.
8. Lógica en servicios/casos de uso.
9. Añade pruebas para lógica crítica.
10. No crear código "placeholder" si puede implementarse correctamente.

## IA
- respuestas estructuradas;
- schemas estrictos;
- trazabilidad;
- no inventar datos;
- prompts pequeños;
- recuperar contexto relevante;
- no mandar expedientes completos al LLM.

## Seguridad
- validar MIME/tamaño;
- archivos privados;
- authorization checks;
- el registro nunca puede asignar roles administrativos ni permisos enviados por el navegador;
- las rutas administrativas requieren sesión y rol autorizado; la autorización final de cada recurso se valida también en Laravel;
- no exponer storage keys;
- URLs firmadas;
- sanitizar inputs;
- eliminar embeddings al eliminar expediente.

## Cuando termines una tarea
Entregar solo:
1. archivos modificados;
2. resumen corto;
3. migraciones/comandos necesarios;
4. pruebas ejecutadas;
5. siguiente paso recomendado.

No repetir la documentación completa.

## Flujo obligatorio de ramas

`main ← develop ← ramas de trabajo`.

- Antes de editar, revisar rama y cambios pendientes.
- `main`: estable; únicamente PR desde `develop`.
- `develop`: integración mediante PR; no desarrollar funcionalidades allí.
- Crear ramas desde `develop` actualizado:

```bash
git switch develop
git pull --ff-only origin develop
git switch -c funcionalidad/base-de-datos
```

- Usar nombres en español, minúsculas y guiones: `tipo/nombre-descriptivo`.
- Tipos: `funcionalidad/`, `correccion/`, `mejora/`, `refactorizacion/`,
  `prueba/`, `documentacion/`, `configuracion/`.
- Al terminar, revisar el diff y añadir solo archivos de la tarea. Hacer commits locales; el push con upstream y el PR a `develop` se realizan cuando la publicación esté autorizada.
- Si el bloqueo de facturación de GitHub impide ejecutar Actions, conservar el trabajo local y no omitir ni desactivar checks. Estado actual: el usuario confirmó el desbloqueo; los checks requeridos pasaron antes de integrar los PR hasta el #11.
- La rama existente `funcionalidad/analisis-expedientes` se sincroniza con `origin/develop` antes de abrir su PR; no se crea una rama sustituta.
- Integrar en `develop` solo cuando la rama esté validada y los checks requeridos estén verdes; no hacer merge local como atajo a protecciones remotas.
- No incluir cambios ajenos en el commit, hacer merge automático a `main`,
  eliminar ramas principales ni force push.
- La primera creación de `develop` puede usar el commit estable de `main`;
  las ramas de funcionalidades salen posteriormente desde `develop`.
