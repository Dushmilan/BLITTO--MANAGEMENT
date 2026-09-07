export default function DataTable({ columns, data, onRowClick, emptyMessage = 'No data found.' }) {
  if (!data || data.length === 0) {
    return (
      <div className="bg-canvas rounded-lg border border-hairline p-xxl text-center">
        <p className="text-body-md text-steel font-sans">{emptyMessage}</p>
      </div>
    )
  }

  return (
    <div className="bg-canvas rounded-lg border border-hairline overflow-hidden shadow-editorial-sm">
      <table className="doc-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key}>{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row, i) => (
            <tr
              key={row.id || i}
              onClick={() => onRowClick?.(row)}
              className={onRowClick ? 'cursor-pointer' : ''}
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
