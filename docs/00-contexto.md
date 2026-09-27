# Contexto mínimo — JURISSIM

## Producto
Plataforma web educativa para practicar audiencias judiciales penales bolivianas.

## Función principal
El usuario carga uno o varios archivos de un expediente penal no preconfigurado.

El sistema debe:
1. extraer el texto;
2. analizar el expediente;
3. identificar información jurídica relevante;
4. detectar información faltante;
5. permitir elegir audiencia y rol;
6. generar una audiencia interactiva;
7. evaluar al usuario.

## Principio obligatorio
La IA NO debe inventar información ausente del expediente.

Cuando falte información:
- marcarla como faltante;
- indicar incertidumbre;
- conservar referencia al archivo/página cuando sea posible.

## Alcance inicial
Audiencia MVP:
- medidas cautelares.

Rol MVP del usuario:
- abogado defensor.

Roles IA iniciales:
- juez;
- fiscal.

## Evolución
Luego añadir:
- juez como usuario;
- fiscal como usuario;
- víctima;
- abogado de víctima;
- imputado;
- incidentales;
- juicio oral;
- voz STT/TTS.

## Requisito crítico
Debe funcionar con expedientes nuevos que nunca hayan sido incluidos durante el desarrollo.
