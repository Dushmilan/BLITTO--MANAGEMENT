import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import PatentsPage from '../PatentsPage.jsx'

vi.mock('../../api.js', () => ({
  api: {
    applications: vi.fn(),
    createApplication: vi.fn(),
    markFiled: vi.fn(),
    acknowledgeNipo: vi.fn(),
    recordDefectSheet: vi.fn(),
    markGranted: vi.fn(),
    markRejected: vi.fn(),
    documents: vi.fn(),
    uploadDocument: vi.fn(),
    deleteDocument: vi.fn(),
    downloadDocument: vi.fn(),
    vaultStatus: vi.fn(),
    vaultUnlock: vi.fn(),
    vaultLock: vi.fn(),
  },
}))

import { api } from '../../api.js'

const APPS = [
  { id: 'app-1', title: 'Patent Alpha', status: 'filed', application_number: 'PAT-001', inventors: [{ inventor_name: 'Alice', inventor_email: 'alice@test.com' }], technology_area: 'AI' },
  { id: 'app-2', title: 'Patent Beta', status: 'draft', inventors: [], technology_area: '' },
]

const DOCS = [
  { id: 'doc-1', filename: 'spec.pdf', file_size: 102400, uploaded_by: 'admin', uploaded_at: '2024-06-01T12:00:00Z' },
]

function renderPage(user = { role: 'admin', email: 'admin@test.com' }) {
  return render(
    <MemoryRouter>
      <PatentsPage user={user} />
    </MemoryRouter>
  )
}

describe('PatentsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows loading then renders patents list', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    expect(screen.getByText('Loading patents...')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    expect(screen.getByText('2 patents tracked')).toBeInTheDocument()
  })

  it('shows empty state when no patents', async () => {
    api.applications.mockResolvedValue([])
    renderPage()
    await waitFor(() => {
      expect(screen.getByText(/No patents found/)).toBeInTheDocument()
    })
  })

  it('switches to documents tab', async () => {
    api.applications.mockResolvedValue(APPS)
    api.vaultStatus.mockResolvedValue({ locked: true, remaining_seconds: 0 })
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Documents'))
    expect(screen.getByText(/Select Patent/)).toBeInTheDocument()
  })

  it('opens create patent modal', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('2 patents tracked')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: /New Patent/ }))
    expect(screen.getByRole('heading', { name: /New Patent/ })).toBeInTheDocument()
    expect(screen.getByPlaceholderText('e.g. Novel Semiconductor Device Architecture')).toBeInTheDocument()
  })

  it('filters patents by search', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    const searchInput = screen.getByPlaceholderText('Search patents...')
    await userEvent.type(searchInput, 'Beta')
    expect(screen.queryByText('Patent Alpha')).not.toBeInTheDocument()
    expect(screen.getByText('Patent Beta')).toBeInTheDocument()
  })

  it('creates a new patent via modal', async () => {
    api.applications.mockResolvedValue(APPS)
    api.createApplication.mockResolvedValue({ id: 'new' })
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('2 patents tracked')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: /New Patent/ }))
    const titleInput = screen.getByPlaceholderText('e.g. Novel Semiconductor Device Architecture')
    await userEvent.type(titleInput, 'New Patent')
    await userEvent.click(screen.getByRole('button', { name: /Create Patent/ }))
    await waitFor(() => {
      expect(api.createApplication).toHaveBeenCalled()
    })
  })

  it('shows vault locked overlay on documents tab', async () => {
    api.applications.mockResolvedValue(APPS)
    api.vaultStatus.mockResolvedValue({ locked: true, remaining_seconds: 0 })
    api.documents.mockResolvedValue([])
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Documents'))
    await waitFor(() => {
      expect(screen.getByText('Vault Locked')).toBeInTheDocument()
    })
  })

  it('opens grant modal from dropdown and confirms grant', async () => {
    api.applications.mockResolvedValue(APPS)
    api.markGranted.mockResolvedValue({})
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    const filingButtons = screen.getAllByLabelText('Filing actions')
    await userEvent.click(filingButtons[0])
    await userEvent.click(screen.getByRole('menuitem', { name: 'Grant' }))
    expect(screen.getByRole('heading', { name: /Grant Patent/ })).toBeInTheDocument()
    const patentInput = screen.getByPlaceholderText('e.g. LK/PAT/2026/00123')
    await userEvent.type(patentInput, 'LK/PAT/2026/999')
    await userEvent.click(screen.getByText('Confirm Grant'))
    await waitFor(() => {
      expect(api.markGranted).toHaveBeenCalledWith('app-1', 'LK/PAT/2026/999')
    })
  })

  it('unlocks vault with master key', async () => {
    api.applications.mockResolvedValue(APPS)
    api.vaultStatus.mockResolvedValue({ locked: true, remaining_seconds: 0 })
    api.vaultUnlock.mockResolvedValue({})
    api.documents.mockResolvedValue(DOCS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Documents'))
    await waitFor(() => {
      expect(screen.getByText('Vault Locked')).toBeInTheDocument()
    })
    const keyInput = screen.getByPlaceholderText('Master key')
    await userEvent.type(keyInput, 'secret-key')
    await userEvent.click(screen.getByText('Unlock Vault'))
    await waitFor(() => {
      expect(api.vaultUnlock).toHaveBeenCalledWith('secret-key')
    })
  })
})
