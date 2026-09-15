import { create } from 'zustand'
import { initialActivity, initialRecords, type AdminRecord, type Section } from './demo-data'

interface AdminState {
  records: Record<Section, AdminRecord[]>
  activity: typeof initialActivity
  profile: { name: string; email: string; institution: string }
  saveRecord: (section: Section, record: AdminRecord) => void
  deleteRecord: (section: Section, id: string) => void
  updateProfile: (profile: AdminState['profile']) => void
  notificationsRead: boolean
  readNotifications: () => void
}
// Deliberately memory-only: no personal data or credentials are persisted by this UI demo.
export const useAdminStore = create<AdminState>((set) => ({
  records: structuredClone(initialRecords),
  activity: initialActivity,
  profile: { name: 'Andrea Morales', email: 'andrea.morales@example.test', institution: 'Instituto de Práctica Jurídica' },
  notificationsRead: false,
  readNotifications: () => set({ notificationsRead: true }),
  updateProfile: (profile) => set({ profile }),
  saveRecord: (section, record) => set((state) => ({
    records: { ...state.records, [section]: state.records[section].some((item) => item.id === record.id)
      ? state.records[section].map((item) => item.id === record.id ? record : item)
      : [record, ...state.records[section]] },
    activity: [{ title: 'Registro guardado', detail: `${state.profile.name} · ${record.title}`, time: 'Ahora', kind: section }, ...state.activity],
  })),
  deleteRecord: (section, id) => set((state) => ({
    records: { ...state.records, [section]: state.records[section].filter((item) => item.id !== id) },
    activity: [{ title: 'Registro eliminado de la demostración', detail: `${state.profile.name} · ${id}`, time: 'Ahora', kind: section }, ...state.activity],
  })),
}))
