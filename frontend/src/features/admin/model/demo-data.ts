export type Section = 'expedientes' | 'usuarios' | 'documentos' | 'biblioteca' | 'audiencias'
export interface AdminRecord {
  id: string
  title: string
  subtitle: string
  category: string
  status: string
  owner: string
  date: string
  description: string
}
export const sectionConfig: Record<Section, { title: string; singular: string; eyebrow: string; description: string; action: string; categories: string[]; statuses: string[]; columns: [string, string, string] }> = {
  expedientes: { title: 'Expedientes', singular: 'expediente', eyebrow: 'Gestión académica', description: 'Organice los casos y supervise su preparación para la práctica.', action: 'Nuevo expediente', categories: ['Patrimonio', 'Integridad personal', 'Administración pública'], statuses: ['En preparación', 'En revisión', 'Disponible', 'Archivado'], columns: ['Expediente', 'Materia', 'Responsable'] },
  usuarios: { title: 'Usuarios', singular: 'usuario', eyebrow: 'Administración', description: 'Gestione las personas que forman parte de su institución.', action: 'Nuevo usuario', categories: ['Administrador', 'Instructor', 'Estudiante', 'Revisor'], statuses: ['Activo', 'Invitado', 'Suspendido'], columns: ['Nombre y correo', 'Rol', 'Institución'] },
  documentos: { title: 'Documentos', singular: 'documento', eyebrow: 'Archivo institucional', description: 'Mantenga organizadas las piezas documentales de cada expediente.', action: 'Incorporar documento', categories: ['Declaración', 'Informe pericial', 'Acta', 'Resolución'], statuses: ['Verificado', 'Por revisar', 'Observado'], columns: ['Documento', 'Tipo', 'Expediente'] },
  biblioteca: { title: 'Biblioteca jurídica', singular: 'fuente', eyebrow: 'Centro de conocimiento', description: 'Administre las fuentes de consulta y sus referencias bibliográficas.', action: 'Agregar fuente', categories: ['Normativa', 'Jurisprudencia', 'Doctrina'], statuses: ['Publicado', 'Borrador', 'Por revisar'], columns: ['Fuente', 'Colección', 'Referencia'] },
  audiencias: { title: 'Audiencias', singular: 'audiencia', eyebrow: 'Gestión académica', description: 'Programe sesiones y revise la actividad de formación.', action: 'Programar audiencia', categories: ['Juicio oral', 'Audiencia preparatoria', 'Práctica de interrogatorio'], statuses: ['Programada', 'En curso', 'Finalizada', 'Cancelada'], columns: ['Sesión', 'Modalidad', 'Instructor'] },
}
function record(id: string, title: string, subtitle: string, category: string, status: string, owner: string, date: string, description = ''): AdminRecord {
  return { id, title, subtitle, category, status, owner, date, description }
}
export const initialRecords: Record<Section, AdminRecord[]> = {
  expedientes: [
    record('EXP-2026-008', 'Incidente en el almacén central', 'Grupo de práctica A · 8 documentos', 'Patrimonio', 'En revisión', 'Valeria Mendoza', '2026-09-15', 'Caso ficticio para estudiar la organización de la prueba y la preparación del interrogatorio. Las identidades y circunstancias son exclusivamente académicas.'),
    record('EXP-2026-007', 'Intervención en la vía pública', 'Grupo de práctica B · 5 documentos', 'Integridad personal', 'Disponible', 'Diego Salazar', '2026-09-14'),
    record('EXP-2026-006', 'Contratación de servicios municipales', 'Seminario avanzado · 12 documentos', 'Administración pública', 'En preparación', 'Camila Rojas', '2026-09-13'),
    record('EXP-2026-005', 'Acceso a un inmueble privado', 'Grupo de práctica A · 6 documentos', 'Patrimonio', 'Disponible', 'Valeria Mendoza', '2026-09-12'),
    record('EXP-2026-004', 'Declaraciones en conflicto', 'Taller de litigación · 4 documentos', 'Integridad personal', 'En revisión', 'Diego Salazar', '2026-09-11'),
    record('EXP-2026-003', 'Custodia de bienes públicos', 'Seminario avanzado · 9 documentos', 'Administración pública', 'Archivado', 'Camila Rojas', '2026-09-09'),
    record('EXP-2026-002', 'Registro de una entrega', 'Grupo de práctica B · 3 documentos', 'Patrimonio', 'En preparación', 'Diego Salazar', '2026-09-08'),
  ],
  usuarios: [
    record('USR-001', 'Andrea Morales', 'andrea.morales@example.test', 'Administrador', 'Activo', 'Instituto de Práctica Jurídica', '2026-09-15'),
    record('USR-002', 'Valeria Mendoza', 'valeria.mendoza@example.test', 'Instructor', 'Activo', 'Instituto de Práctica Jurídica', '2026-09-14'),
    record('USR-003', 'Diego Salazar', 'diego.salazar@example.test', 'Instructor', 'Activo', 'Instituto de Práctica Jurídica', '2026-09-14'),
    record('USR-004', 'Camila Rojas', 'camila.rojas@example.test', 'Revisor', 'Activo', 'Instituto de Práctica Jurídica', '2026-09-13'),
    record('USR-005', 'Gabriel Flores', 'gabriel.flores@example.test', 'Estudiante', 'Invitado', 'Instituto de Práctica Jurídica', '2026-09-12'),
    record('USR-006', 'Lucía Vargas', 'lucia.vargas@example.test', 'Estudiante', 'Activo', 'Instituto de Práctica Jurídica', '2026-09-11'),
    record('USR-007', 'Mateo Suárez', 'mateo.suarez@example.test', 'Estudiante', 'Suspendido', 'Instituto de Práctica Jurídica', '2026-09-10'),
  ],
  documentos: [
    record('DOC-001', 'Declaración del testigo principal', 'declaracion-testigo.pdf · 240 KB', 'Declaración', 'Por revisar', 'EXP-2026-008', '2026-09-15'),
    record('DOC-002', 'Informe de inspección del lugar', 'informe-inspeccion.pdf · 1.4 MB', 'Informe pericial', 'Verificado', 'EXP-2026-008', '2026-09-14'),
    record('DOC-003', 'Acta de registro de evidencias', 'acta-registro.pdf · 380 KB', 'Acta', 'Verificado', 'EXP-2026-007', '2026-09-14'),
    record('DOC-004', 'Informe técnico complementario', 'informe-tecnico.pdf · 920 KB', 'Informe pericial', 'Observado', 'EXP-2026-006', '2026-09-13'),
    record('DOC-005', 'Resolución de práctica', 'resolucion-practica.pdf · 180 KB', 'Resolución', 'Por revisar', 'EXP-2026-005', '2026-09-12'),
  ],
  biblioteca: [
    record('FUE-001', 'Código Penal', 'Ficha bibliográfica de demostración', 'Normativa', 'Publicado', 'Colección penal · Bolivia', '2026-09-15', 'Registro de muestra. El texto jurídico y su vigencia deberán verificarse al incorporar la fuente oficial.'),
    record('FUE-002', 'Código de Procedimiento Penal', 'Ficha bibliográfica de demostración', 'Normativa', 'Publicado', 'Colección procesal · Bolivia', '2026-09-14'),
    record('FUE-003', 'Constitución Política del Estado', 'Ficha bibliográfica de demostración', 'Normativa', 'Publicado', 'Colección constitucional · Bolivia', '2026-09-13'),
    record('FUE-004', 'Valoración de la prueba testimonial', 'Material didáctico ficticio', 'Doctrina', 'Borrador', 'Cuaderno de práctica 04', '2026-09-12'),
    record('FUE-005', 'Selección de decisiones para el aula', 'Colección de ejemplo sin resoluciones adjuntas', 'Jurisprudencia', 'Por revisar', 'Seminario de litigación', '2026-09-11'),
  ],
  audiencias: [
    record('AUD-001', 'Alegatos iniciales y teoría del caso', '16:00 · 60 min · Grupo A', 'Juicio oral', 'Programada', 'Valeria Mendoza', '2026-09-16'),
    record('AUD-002', 'Taller de contrainterrogatorio', '10:30 · 45 min · Grupo B', 'Práctica de interrogatorio', 'Programada', 'Diego Salazar', '2026-09-17'),
    record('AUD-003', 'Preparación de elementos probatorios', '09:00 · 90 min · Seminario', 'Audiencia preparatoria', 'Programada', 'Camila Rojas', '2026-09-18'),
    record('AUD-004', 'Interrogatorio de testigo presencial', '14:00 · 45 min · Grupo A', 'Práctica de interrogatorio', 'Finalizada', 'Valeria Mendoza', '2026-09-14'),
  ],
}
export const initialActivity = [
  { title: 'Expediente enviado a revisión', detail: 'Valeria Mendoza · EXP-2026-008', time: 'Hace 25 min', kind: 'expediente' },
  { title: 'Documento verificado', detail: 'Camila Rojas · Informe de inspección', time: 'Hace 1 h', kind: 'documento' },
  { title: 'Sesión programada', detail: 'Diego Salazar · Taller de contrainterrogatorio', time: 'Hace 2 h', kind: 'audiencia' },
  { title: 'Usuario incorporado', detail: 'Andrea Morales · Gabriel Flores', time: 'Ayer', kind: 'usuario' },
]
