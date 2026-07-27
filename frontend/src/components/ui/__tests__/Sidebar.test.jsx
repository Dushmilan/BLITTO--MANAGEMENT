import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import Sidebar from '../Sidebar.jsx'

describe('Sidebar', () => {
  it('renders nav items Overview and Patents', () => {
    render(
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>
    )
    expect(screen.getByText('Overview')).toBeInTheDocument()
    expect(screen.getByText('Patents')).toBeInTheDocument()
  })

  it('shows expanded state by default', () => {
    render(
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>
    )
    const aside = screen.getByRole('complementary')
    expect(aside.className).toContain('w-[240px]')
  })

  it('shows collapsed state when collapsed is true', () => {
    render(
      <MemoryRouter>
        <Sidebar collapsed={true} />
      </MemoryRouter>
    )
    const aside = screen.getByRole('complementary')
    expect(aside.className).toContain('w-[64px]')
  })

  it('hides labels when collapsed', () => {
    render(
      <MemoryRouter>
        <Sidebar collapsed={true} />
      </MemoryRouter>
    )
    expect(screen.queryByText('Overview')).not.toBeInTheDocument()
    expect(screen.queryByText('Patents')).not.toBeInTheDocument()
  })

  it('calls onToggle when collapse button is clicked', async () => {
    const onToggle = vi.fn()
    render(
      <MemoryRouter>
        <Sidebar onToggle={onToggle} />
      </MemoryRouter>
    )
    await userEvent.click(screen.getByLabelText('Collapse sidebar'))
    expect(onToggle).toHaveBeenCalledTimes(1)
  })

  it('shows expand label when collapsed', () => {
    render(
      <MemoryRouter>
        <Sidebar collapsed={true} onToggle={vi.fn()} />
      </MemoryRouter>
    )
    expect(screen.getByLabelText('Expand sidebar')).toBeInTheDocument()
  })

  it('renders brand name BLITTO when expanded', () => {
    render(
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>
    )
    expect(screen.getByText('BLITTO')).toBeInTheDocument()
  })

  it('does not render brand name when collapsed', () => {
    render(
      <MemoryRouter>
        <Sidebar collapsed={true} />
      </MemoryRouter>
    )
    expect(screen.queryByText('BLITTO')).not.toBeInTheDocument()
  })
})
