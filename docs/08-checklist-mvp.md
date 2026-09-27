# Checklist MVP

El MVP está listo cuando:

## Base técnica ya validada localmente
- [x] Migraciones PostgreSQL de dominio, modelos y relaciones iniciales.
- [x] Integridad referencial y restricciones cubiertas por pruebas.
- [x] Pruebas locales ejecutadas contra PostgreSQL aislado; no se tocó la base PostgreSQL habitual.
- [ ] Merge a `develop` después de que GitHub permita ejecutar los checks obligatorios.

## Usuario
- [ ] registro/login funcionan;
- [ ] usuario solo ve sus expedientes.

## Expediente
- [ ] admite varios PDF;
- [ ] admite PDF digital;
- [ ] admite PDF escaneado;
- [ ] OCR funciona;
- [ ] conserva página/origen.

## Análisis
- [ ] genera resumen;
- [ ] detecta participantes;
- [ ] detecta delitos;
- [ ] extrae hechos;
- [ ] extrae pruebas;
- [ ] genera cronología;
- [ ] marca información faltante;
- [ ] no inventa datos.

## Prueba principal
- [ ] funciona con un expediente nunca usado en desarrollo.

## Audiencia
- [ ] medidas cautelares;
- [ ] usuario = defensa;
- [ ] juez IA;
- [ ] fiscal IA;
- [ ] turnos controlados;
- [ ] memoria funcional;
- [ ] respuestas usan expediente;
- [ ] respuestas pueden usar normativa.

## Evaluación
- [ ] analiza transcripción;
- [ ] usa rúbrica;
- [ ] muestra fortalezas;
- [ ] muestra errores;
- [ ] muestra recomendaciones.

## Interfaz
- [ ] sala R3F;
- [ ] personajes básicos;
- [ ] indicador de hablante;
- [ ] chat usable.

## Seguridad
- [ ] expedientes privados;
- [ ] eliminación completa;
- [ ] archivos protegidos.
