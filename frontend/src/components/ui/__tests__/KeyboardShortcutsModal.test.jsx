import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import KeyboardShortcutsModal from '../KeyboardShortcutsModal.jsx'

describe('KeyboardShortcutsModal', () => {
  it('renders nothing when closed', () => {
    render(<KeyboardShortcutsModal open={false} onClose={() => {}} />)
    expect(screen.queryByText('Keyboard shortcuts')).not.toBeInTheDocument()
  })

  it('lists all shortcuts when open', () => {
    render(<KeyboardShortcutsModal open onClose={() => {}} />)
    expect(screen.getByRole('heading', { name: 'Keyboard shortcuts' })).toBeInTheDocument()
    expect(screen.getByText('Focus the search box')).toBeInTheDocument()
    expect(screen.getByText('Close dialogs and menus')).toBeInTheDocument()
    expect(screen.getByText('Open this help')).toBeInTheDocument()
  })
})
