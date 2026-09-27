# Checklist MVP

El MVP está listo cuando:

## Base técnica ya validada localmente
- [x] Migraciones PostgreSQL de dominio, modelos y relaciones iniciales.
- [x] Integridad referencial y restricciones cubiertas por pruebas.
- [x] Pruebas locales ejecutadas contra PostgreSQL aislado; no se tocó la base PostgreSQL habitual.
- [ ] Merge a `develop` después de que GitHub permita ejecutar los checks obligatorios.

## Usuario
- [x] registro, login, logout y recuperación de contraseña usan sesión real;
- [x] las rutas administrativas exigen sesión y rol autorizado;
- [x] el registro asigna rol básico; el primer administrador se habilita por consola;
- [x] API de expedientes limita lectura, cambios, descarga y eliminación al propietario autenticado;

## Expediente
- [x] crea, consulta, edita y elimina expedientes propios;
- [x] acepta varios PDF/imágenes, limita tamaño y los guarda privados en desarrollo;
- [ ] configurar y probar bucket R2 real;
- [x] acepta DOCX Office Open XML válido, verificado sin extraer el ZIP;
- [x] extrae texto de PDF digital por página;
- [x] extrae texto DOCX y avisa que no puede asegurar paginación física;
- [x] OCR selectivo está implementado para páginas escaneadas e imágenes;
- [ ] instalar Tesseract/idioma español y verificar OCR con un escaneo sintético;
- [x] conserva archivo, página/localizador, uso de OCR y legibilidad.

## Análisis
- [x] contrato Pydantic acotado con afirmaciones y extractos de origen;
- [x] referencias verificadas contra las páginas entregadas al lote;
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
