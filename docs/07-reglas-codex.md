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
- Al terminar, revisar el diff, añadir solo archivos de la tarea, hacer commit,
  push con upstream y abrir PR hacia `develop`.
- No incluir cambios ajenos en el commit, hacer merge automático a `main`,
  eliminar ramas principales ni force push.
- La primera creación de `develop` puede usar el commit estable de `main`;
  las ramas de funcionalidades salen posteriormente desde `develop`.
