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
    updateApplication: vi.fn(),
    applicationHistory: vi.fn(),
  },
}))

import { api } from '../../api.js'

const APPS = [
  { id: 'app-1', title: 'Patent Alpha', status: 'filed', application_number: 'PAT-001', inventors: [{ inventor_name: 'Alice', inventor_email: 'alice@test.com' }], technology_area: 'AI', created_at: '2024-05-01T10:00:00Z', updated_at: '2024-06-01T12:00:00Z' },
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
    api.applicationHistory.mockResolvedValue([])
  })

  it('shows loading then renders patents list', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    expect(screen.getByText('Loading patents...')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    expect(screen.getByText('2 of 2 patents shown')).toBeInTheDocument()
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
      expect(screen.getByText('2 of 2 patents shown')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: /New Patent/ }))
    expect(screen.getByRole('heading', { name: /New Patent/ })).toBeInTheDocument()
    expect(screen.getByPlaceholderText('e.g. Novel Semiconductor Device Architecture')).toBeInTheDocument()
  })

  it('filters patents by search (debounced)', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    const searchInput = screen.getByPlaceholderText('Search patents...')
    await userEvent.type(searchInput, 'Beta')
    // Still unfiltered before the 300ms debounce elapses.
    expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.queryByText('Patent Alpha')).not.toBeInTheDocument()
    }, { timeout: 2000 })
    expect(screen.getByText('Patent Beta')).toBeInTheDocument()
    expect(screen.getByText('1 of 2 patents shown')).toBeInTheDocument()
  })

  it('creates a new patent via modal', async () => {
    api.applications.mockResolvedValue(APPS)
    api.createApplication.mockResolvedValue({ id: 'new' })
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('2 of 2 patents shown')).toBeInTheDocument()
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
    // Step 2: irreversible-action confirmation with explicit checkbox.
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Confirm grant' })).toBeInTheDocument()
    })
    expect(screen.getByText(/This action is irreversible/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('checkbox'))
    await userEvent.click(screen.getByRole('button', { name: 'Grant Patent' }))
    await waitFor(() => {
      expect(api.markGranted).toHaveBeenCalledWith('app-1', 'LK/PAT/2026/999')
    })
  })

  it('rejects a patent through the confirm dialog', async () => {
    api.applications.mockResolvedValue(APPS)
    api.markRejected.mockResolvedValue({})
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Patent Alpha'))
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patent Details' })).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: 'Reject' }))
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Reject patent?' })).toBeInTheDocument()
    })
    // Two Reject buttons now (detail bar + dialog) — confirm in the dialog.
    const rejects = screen.getAllByRole('button', { name: 'Reject' })
    await userEvent.click(rejects[rejects.length - 1])
    await waitFor(() => {
      expect(api.markRejected).toHaveBeenCalledWith('app-1')
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

  it('deep-links to the documents tab via ?tab=documents', async () => {
    api.applications.mockResolvedValue(APPS)
    render(
      <MemoryRouter initialEntries={['/admin/patents?tab=documents']}>
        <PatentsPage user={{ role: 'admin', email: 'admin@test.com' }} />
      </MemoryRouter>
    )
    await waitFor(() => {
      expect(screen.getByText('Choose a patent')).toBeInTheDocument()
    })
    expect(screen.queryByText('2 of 2 patents shown')).not.toBeInTheDocument()
  })

  it('shows Grant and Reject actions directly in the detail modal', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    // Patent Alpha is 'filed' — eligible for a filing decision.
    await userEvent.click(screen.getByText('Patent Alpha'))
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patent Details' })).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: 'Grant Patent' }))
    expect(screen.getByRole('heading', { name: 'Grant Patent' })).toBeInTheDocument()
  })

  it('opens the focused patent from ?focus= deep link', async () => {
    api.applications.mockResolvedValue(APPS)
    api.applicationHistory.mockResolvedValue([])
    render(
      <MemoryRouter initialEntries={['/admin/patents?focus=app-1']}>
        <PatentsPage user={{ role: 'admin', email: 'admin@test.com' }} />
      </MemoryRouter>
    )
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patent Details' })).toBeInTheDocument()
    })
  })

  it('shows timestamps and status history in the detail modal', async () => {
    api.applications.mockResolvedValue(APPS)
    api.applicationHistory.mockResolvedValue([
      { id: 'h1', old_status: 'draft', new_status: 'filed', changed_by: 'md@test.com' },
    ])
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Patent Alpha'))
    await waitFor(() => {
      expect(screen.getByText('Status history')).toBeInTheDocument()
    })
    expect(screen.getByText('Created')).toBeInTheDocument()
    expect(screen.getByText('Last updated')).toBeInTheDocument()
    expect(screen.getByText(/md@test.com/)).toBeInTheDocument()
  })

  it('edits the technology area inline', async () => {
    api.applications.mockResolvedValue(APPS)
    api.updateApplication.mockResolvedValue({ ...APPS[0], technology_area: 'Bio' })
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Patent Alpha'))
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patent Details' })).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: 'Edit technology area' }))
    await userEvent.clear(screen.getByLabelText('Technology area'))
    await userEvent.type(screen.getByLabelText('Technology area'), 'Bio')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(api.updateApplication).toHaveBeenCalledWith('app-1', { technology_area: 'Bio' })
    })
  })

  it('jumps from detail to documents with context bar', async () => {
    api.applications.mockResolvedValue(APPS)
    api.vaultStatus.mockResolvedValue({ locked: true, remaining_seconds: 0 })
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByText('Patent Alpha'))
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Patent Details' })).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: 'View documents' }))
    await waitFor(() => {
      expect(screen.getByTestId('doc-context-bar')).toHaveTextContent('Patent Alpha')
    })
    expect(screen.getByTestId('doc-context-bar')).toHaveTextContent('PAT-001')
  })

  it('writes the tab to the URL when switching tabs', async () => {
    const { useSearchParams } = await import('react-router-dom')
    function Probe() {
      const [params] = useSearchParams()
      return <span data-testid="loc">{params.toString()}</span>
    }
    api.applications.mockResolvedValue(APPS)
    render(
      <MemoryRouter initialEntries={['/admin/patents']}>
        <Probe />
        <PatentsPage user={{ role: 'admin', email: 'admin@test.com' }} />
      </MemoryRouter>
    )
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    await userEvent.click(screen.getByRole('button', { name: 'Documents' }))
    await waitFor(() => {
      expect(screen.getByTestId('loc')).toHaveTextContent('tab=documents')
    })
  })
})
