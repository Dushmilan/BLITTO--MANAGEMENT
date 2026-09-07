import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import DataTable from '../DataTable.jsx'

describe('DataTable', () => {
  it('shows empty message when there is no data', () => {
    render(<DataTable columns={[]} data={[]} emptyMessage="Nothing here" />)
    expect(screen.getByText('Nothing here')).toBeInTheDocument()
  })

  it('shows default empty message', () => {
    render(<DataTable columns={[]} data={[]} />)
    expect(screen.getByText('No data found.')).toBeInTheDocument()
  })

  it('renders rows from data', () => {
    const columns = [{ key: 'name', label: 'Name' }]
    const data = [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }]
    render(<DataTable columns={columns} data={data} />)
    expect(screen.getByText('Alice')).toBeInTheDocument()
    expect(screen.getByText('Bob')).toBeInTheDocument()
  })

  it('renders column headers', () => {
    const columns = [
      { key: 'name', label: 'Name' },
      { key: 'role', label: 'Role' },
    ]
    render(<DataTable columns={columns} data={[{ id: 1, name: 'A', role: 'admin' }]} />)
    expect(screen.getByText('Name')).toBeInTheDocument()
    expect(screen.getByText('Role')).toBeInTheDocument()
  })

  it('uses col.render when provided', () => {
    const columns = [
      { key: 'name', label: 'Name', render: (val) => `Mr. ${val}` },
    ]
    render(<DataTable columns={columns} data={[{ id: 1, name: 'Smith' }]} />)
    expect(screen.getByText('Mr. Smith')).toBeInTheDocument()
  })

  it('calls onRowClick when a row is clicked', async () => {
    const onRowClick = vi.fn()
    const columns = [{ key: 'name', label: 'Name' }]
    const data = [{ id: 1, name: 'Alice' }]
    render(<DataTable columns={columns} data={data} onRowClick={onRowClick} />)
    await userEvent.click(screen.getByText('Alice'))
    expect(onRowClick).toHaveBeenCalledWith(data[0])
  })

  it('applies col.className to td elements', () => {
    const columns = [
      { key: 'name', label: 'Name', className: 'custom-cell' },
    ]
    render(<DataTable columns={columns} data={[{ id: 1, name: 'X' }]} />)
    const td = screen.getByText('X').closest('td')
    expect(td.className).toContain('custom-cell')
  })
})
