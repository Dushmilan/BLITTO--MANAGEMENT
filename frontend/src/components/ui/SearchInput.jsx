export default function SearchInput({ value, onChange, placeholder = 'Search...', className = '' }) {
  return (
    <div className={`relative ${className}`}>
      <svg
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
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full h-9 pl-10 pr-md bg-ivory-200 text-ink text-body-sm border border-transparent rounded-md outline-none transition-all duration-200 font-sans placeholder:text-muted focus:bg-canvas focus:border-hairline focus:ring-2 focus:ring-copper-100"
      />
    </div>
  )
}
