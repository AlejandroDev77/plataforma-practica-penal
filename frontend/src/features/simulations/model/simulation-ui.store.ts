import { create } from 'zustand'

type WorkspacePanel = 'transcript' | 'evidence' | 'sources'

interface SimulationUiState {
  activePanel: WorkspacePanel
  isMicrophoneOpen: boolean
  setActivePanel: (panel: WorkspacePanel) => void
  setMicrophoneOpen: (open: boolean) => void
}

export const useSimulationUiStore = create<SimulationUiState>((set) => ({
  activePanel: 'transcript',
  isMicrophoneOpen: false,
  setActivePanel: (activePanel) => set({ activePanel }),
  setMicrophoneOpen: (isMicrophoneOpen) => set({ isMicrophoneOpen }),
}))
