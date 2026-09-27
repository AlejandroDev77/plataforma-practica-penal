# Frontend y sala 3D

Estado: guía objetivo. Primero se completa una interfaz administrativa funcional y los flujos de autenticación/expedientes; la sala 3D sigue aplazada hasta validar la simulación textual. No añadir dependencias 3D antes de esa fase.

## Estructura

```text
src/
├── api/
├── components/
├── features/
│   ├── auth/
│   ├── cases/
│   ├── simulation/
│   └── evaluation/
├── hooks/
├── layouts/
├── pages/
├── services/
├── store/
├── types/
├── utils/
└── three/
    ├── courtroom/
    ├── characters/
    ├── cameras/
    └── animations/
```

## Principio
UI normal con React/Tailwind.

React Three Fiber solo para:
- sala;
- personajes;
- luces;
- cámara;
- animaciones.

## MVP visual
- cámara fija;
- juez;
- fiscal;
- defensa;
- mesa/sala;
- indicador de quién habla;
- animación `idle`;
- animación `talk`.

## NO implementar inicialmente
- movimiento libre;
- físicas;
- puertas;
- mapa;
- navegación del personaje;
- lip sync avanzado;
- multijugador.

## Flujo
La simulación funciona aunque el 3D falle.

El 3D es capa visual, no lógica de negocio.
