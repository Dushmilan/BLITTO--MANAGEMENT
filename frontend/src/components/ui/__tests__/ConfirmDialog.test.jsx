import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import ConfirmDialog from '../ConfirmDialog.jsx'

describe('ConfirmDialog', () => {
  it('renders nothing when closed', () => {
    render(<ConfirmDialog open={false} title="T" message="M" onConfirm={() => {}} onCancel={() => {}} />)
    expect(screen.queryByText('T')).not.toBeInTheDocument()
  })

  it('shows title, message and both actions when open', () => {
    render(<ConfirmDialog open title="Delete it?" message="Gone forever." onConfirm={() => {}} onCancel={() => {}} />)
    expect(screen.getByRole('heading', { name: 'Delete it?' })).toBeInTheDocument()
    expect(screen.getByText('Gone forever.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument()
  })

  it('calls onConfirm and onCancel', async () => {
    const onConfirm = vi.fn()
    const onCancel = vi.fn()
    render(<ConfirmDialog open title="T" message="M" onConfirm={onConfirm} onCancel={onCancel} />)
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onCancel).toHaveBeenCalledTimes(1)
    await userEvent.click(screen.getByRole('button', { name: 'Confirm' }))
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('gates confirm behind a checkbox when requireConfirm is set', async () => {
    const onConfirm = vi.fn()
    render(
      <ConfirmDialog
        open
        title="Grant?"
        message="Irreversible."
        requireConfirm="I understand this is irreversible"
        onConfirm={onConfirm}
        onCancel={() => {}}
      />
    )
    const confirm = screen.getByRole('button', { name: 'Confirm' })
    expect(confirm).toBeDisabled()
    await userEvent.click(screen.getByRole('checkbox'))
    expect(confirm).not.toBeDisabled()
    await userEvent.click(confirm)
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('styles destructive confirms distinctly', () => {
    render(<ConfirmDialog open danger title="T" message="M" onConfirm={() => {}} onCancel={() => {}} />)
    expect(screen.getByRole('button', { name: 'Confirm' }).className).toContain('bg-status-rejected')
  })
})
