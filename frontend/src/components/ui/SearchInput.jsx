export default function SearchInput({ value, onChange, placeholder = 'Search...', className = '' }) {
  return (
    <div className={`relative ${className}`}>
      <svg aria-hidden="true"
        className="absolute left-md top-1/2 -translate-y-1/2 text-muted pointer-events-none"
        width="16"
        height="16"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
      >
        <circle cx="7" cy="7" r="5" />
        <path d="M11 11l3.5 3.5" />
      </svg>
      <input
        type="text"
        data-search-input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="touch-target w-full h-9 pl-10 pr-10 bg-ivory-200 dark:bg-navy-700 text-ink dark:text-white text-body-sm border border-transparent rounded-md outline-none transition-all duration-200 font-sans placeholder:text-muted dark:placeholder:text-white/30 focus:bg-canvas dark:focus:bg-navy-700 focus:border-hairline dark:focus:border-hairline-dark focus-visible:ring-2 focus-visible:ring-copper-100"
      />
      {value && (
        <button
          type="button"
          onClick={() => onChange('')}
          aria-label="Clear search"
          className="touch-target absolute right-xs top-1/2 -translate-y-1/2 flex items-center justify-center rounded text-muted dark:text-white/50 hover:text-ink dark:hover:text-white focus-visible:ring-2 focus-visible:ring-copper"
        >
          <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
            <path d="M3 3l8 8M11 3l-8 8" />
          </svg>
        </button>
      )}
    </div>
  )
}
