// Keyboard + screen-reader accessible data table (issues #15, #21).
// Rows with onRowClick are focusable (Tab) and activate with Enter/Space.
// Hover/focus feedback uses Tailwind tokens (see styles.css .doc-table).
export default function DataTable({ columns, data, onRowClick, emptyMessage = 'No data found.', ariaLabel = 'Data table' }) {
  if (!data || data.length === 0) {
    return (
      <div className="bg-canvas rounded-lg border border-hairline p-xxl text-center">
        <p className="text-body-md text-steel font-sans">{emptyMessage}</p>
      </div>
    )
  }

  const clickable = typeof onRowClick === 'function'

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
          {data.map((row, i) => (
            <tr
              key={row.id || i}
              onClick={() => onRowClick?.(row)}
              onKeyDown={clickable ? (e) => handleRowKeyDown(e, row) : undefined}
              tabIndex={clickable ? 0 : undefined}
              aria-label={clickable ? `Open details for row ${i + 1}` : undefined}
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
    </div>
  )
}
