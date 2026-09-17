import { useEffect, useState } from 'react'
import EmptyState from './EmptyState.jsx'

// Keyboard + screen-reader accessible data table (issues #15, #21)
// with client-side pagination (issue #31).
// Rows with onRowClick are focusable (Tab) and activate with Enter/Space.
// Hover/focus feedback uses Tailwind tokens (see styles.css .doc-table).
// Pagination appears only when rows exceed the page size.
const PAGE_SIZE_OPTIONS = [25, 50, 100]

export default function DataTable({
  columns,
  data,
  onRowClick,
  emptyMessage = 'No data found.',
  ariaLabel = 'Data table',
  pageSize: initialPageSize = 25,
}) {
  const [page, setPage] = useState(0)
  const [pageSize, setPageSize] = useState(initialPageSize)

  // New data (search/filter) restarts at the first page.
  useEffect(() => {
    setPage(0)
  }, [data])

  if (!data || data.length === 0) {
    return <EmptyState title={emptyMessage} />
  }

  const clickable = typeof onRowClick === 'function'
  const paginated = data.length > pageSize
  const pageCount = Math.max(1, Math.ceil(data.length / pageSize))
  const safePage = Math.min(page, pageCount - 1)
  const visible = paginated ? data.slice(safePage * pageSize, safePage * pageSize + pageSize) : data
  const from = paginated ? safePage * pageSize + 1 : 1
  const to = paginated ? Math.min(data.length, safePage * pageSize + pageSize) : data.length

  function handleRowKeyDown(e, row) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      onRowClick?.(row)
    }
  }

  return (
    <div className="bg-canvas rounded-lg border border-hairline overflow-hidden shadow-editorial-sm">
      <table className="doc-table" aria-label={ariaLabel} aria-rowcount={data.length + 1}>
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key} scope="col">{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {visible.map((row, i) => (
            <tr
              key={row.id ?? `${safePage}-${i}`}
              onClick={() => onRowClick?.(row)}
              onKeyDown={clickable ? (e) => handleRowKeyDown(e, row) : undefined}
              tabIndex={clickable ? 0 : undefined}
              aria-label={clickable ? `Open details for row ${safePage * pageSize + i + 1}` : undefined}
              className={clickable ? 'cursor-pointer hover:bg-ivory-200 focus-visible:bg-ivory-200 focus-visible:outline-none' : ''}
            >
              {columns.map((col) => (
                <td
                  key={col.key}
                  className={col.className || ''}
                >
                  {col.render ? col.render(row[col.key], row) : row[col.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      {paginated && (
        <div className="flex flex-wrap items-center justify-between gap-sm px-md py-sm border-t border-hairline">
          <p className="text-body-sm text-steel font-sans" aria-live="polite">
            Showing {from}-{to} of {data.length}
          </p>
          <div className="flex items-center gap-sm">
            <label htmlFor="datatable-page-size" className="text-body-sm text-steel font-sans">
              Rows
            </label>
            <select
              id="datatable-page-size"
              value={pageSize}
              onChange={(e) => { setPageSize(Number(e.target.value)); setPage(0) }}
              className="h-9 px-sm bg-canvas text-ink text-body-sm border border-hairline rounded-md font-sans"
            >
              {PAGE_SIZE_OPTIONS.map((n) => (
                <option key={n} value={n}>{n}</option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => setPage((p) => Math.max(0, p - 1))}
              disabled={safePage === 0}
              aria-label="Previous page"
              className="touch-target h-9 px-sm rounded-md border border-hairline text-body-sm font-sans text-ink disabled:opacity-40 hover:bg-ivory-200 focus-visible:ring-2 focus-visible:ring-copper"
            >
              ‹ Prev
            </button>
            <button
              type="button"
              onClick={() => setPage((p) => Math.min(pageCount - 1, p + 1))}
              disabled={safePage >= pageCount - 1}
              aria-label="Next page"
              className="touch-target h-9 px-sm rounded-md border border-hairline text-body-sm font-sans text-ink disabled:opacity-40 hover:bg-ivory-200 focus-visible:ring-2 focus-visible:ring-copper"
            >
              Next ›
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
