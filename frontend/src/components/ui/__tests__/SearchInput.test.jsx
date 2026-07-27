import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import SearchInput from '../SearchInput.jsx'

describe('SearchInput', () => {
  it('renders an input with the given value', () => {
    render(<SearchInput value="hello" onChange={vi.fn()} />)
    expect(screen.getByRole('textbox')).toHaveValue('hello')
  })

  it('calls onChange when user types', async () => {
    const onChange = vi.fn()
    render(<SearchInput value="" onChange={onChange} />)
    const input = screen.getByRole('textbox')
    await userEvent.type(input, 'a')
    expect(onChange).toHaveBeenCalledWith('a')
  })

  it('shows custom placeholder', () => {
    render(<SearchInput value="" onChange={vi.fn()} placeholder="Find patents..." />)
    expect(screen.getByPlaceholderText('Find patents...')).toBeInTheDocument()
  })

  it('applies custom className to wrapper', () => {
    render(<SearchInput value="" onChange={vi.fn()} className="my-wrapper" />)
    const wrapper = screen.getByRole('textbox').parentElement
    expect(wrapper.className).toContain('my-wrapper')
  })
})
