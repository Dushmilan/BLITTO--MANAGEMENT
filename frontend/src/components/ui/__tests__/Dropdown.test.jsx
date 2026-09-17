import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Dropdown from '../Dropdown.jsx'

const ITEMS = [
  { label: 'Notify Filed', onClick: vi.fn() },
  { label: 'Notify Granted', onClick: vi.fn() },
]

function TriggerButton() {
  return <button>Open</button>
}

describe('Dropdown', () => {
  it('renders the trigger element', () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    expect(screen.getByRole('button', { name: 'Open' })).toBeInTheDocument()
  })

  it('opens the menu when trigger is clicked', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    expect(screen.getByText('Notify Filed')).toBeInTheDocument()
    expect(screen.getByText('Notify Granted')).toBeInTheDocument()
  })

  it('calls the item onClick when an item is clicked', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    await userEvent.click(screen.getByText('Notify Filed'))
    expect(ITEMS[0].onClick).toHaveBeenCalledTimes(1)
  })

  it('closes the menu after an item is clicked', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    await userEvent.click(screen.getByText('Notify Filed'))
    expect(screen.queryByText('Notify Filed')).not.toBeInTheDocument()
  })

  it('closes the menu when clicking outside', async () => {
    render(
      <div>
        <Dropdown trigger={<TriggerButton />} items={ITEMS} />
        <span>Outside</span>
      </div>
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    expect(screen.getByText('Notify Filed')).toBeInTheDocument()
    await userEvent.click(screen.getByText('Outside'))
    expect(screen.queryByText('Notify Filed')).not.toBeInTheDocument()
  })

  it('closes the menu on Escape key', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    expect(screen.getByText('Notify Filed')).toBeInTheDocument()
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByText('Notify Filed')).not.toBeInTheDocument()
  })

  it('does not render the menu when not open', () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    expect(screen.queryByText('Notify Filed')).not.toBeInTheDocument()
  })

  it('moves focus with arrow keys and wraps around', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    const first = screen.getByRole('menuitem', { name: 'Notify Filed' })
    const second = screen.getByRole('menuitem', { name: 'Notify Granted' })
    expect(first).toHaveFocus()
    await userEvent.keyboard('{ArrowDown}')
    expect(second).toHaveFocus()
    await userEvent.keyboard('{ArrowDown}')
    expect(first).toHaveFocus()
    await userEvent.keyboard('{ArrowUp}')
    expect(second).toHaveFocus()
  })

  it('selects the focused item with Enter', async () => {
    const onFirst = vi.fn()
    const onSecond = vi.fn()
    render(
      <Dropdown trigger={<TriggerButton />} items={[{ label: 'One', onClick: onFirst }, { label: 'Two', onClick: onSecond }]} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    await userEvent.keyboard('{ArrowDown}{Enter}')
    expect(onSecond).toHaveBeenCalledTimes(1)
    expect(onFirst).not.toHaveBeenCalled()
    expect(screen.queryByText('One')).not.toBeInTheDocument()
  })

  it('returns focus to the trigger on Escape', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    await userEvent.keyboard('{Escape}')
    expect(screen.getByRole('button', { name: 'Open' })).toHaveFocus()
  })

  it('closes the menu on Tab', async () => {
    render(
      <Dropdown trigger={<TriggerButton />} items={ITEMS} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Open' }))
    expect(screen.getByText('Notify Filed')).toBeInTheDocument()
    await userEvent.tab()
    expect(screen.queryByText('Notify Filed')).not.toBeInTheDocument()
  })
})
