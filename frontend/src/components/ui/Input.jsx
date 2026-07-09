export default function Input({
  id,
  label,
  type = 'text',
  value,
  onChange,
  required = false,
  autoFocus = false,
  disabled = false,
  placeholder = '',
  className = '',
  ...props
}) {
  return (
    <div className={`flex flex-col gap-xs ${className}`}>
      {label && (
        <label
          htmlFor={id}
          className="text-body-sm-medium text-charcoal font-sans"
        >
          {label}
          {required && (
            <span className="text-copper ml-1 text-caption">*</span>
          )}
        </label>
      )}
      <input
        id={id}
        type={type}
        value={value}
        onChange={onChange}
        required={required}
        autoFocus={autoFocus}
        disabled={disabled}
        placeholder={placeholder}
        className="w-full h-10 px-md bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none transition-all duration-200 font-sans placeholder:text-muted focus:border-copper focus:ring-2 focus:ring-copper-100 disabled:bg-ivory-200 disabled:text-muted disabled:cursor-not-allowed"
        {...props}
      />
    </div>
  )
}
