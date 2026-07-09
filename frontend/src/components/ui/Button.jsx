const VARIANTS = {
  primary:
    'bg-copper text-white rounded-full px-5 py-[10px] text-button-md shadow-editorial-sm hover:bg-copper-600 hover:shadow-copper-glow',
  'primary-dark':
    'bg-navy text-white rounded-full px-5 py-[10px] text-button-md shadow-editorial-sm hover:bg-navy-800',
  secondary:
    'bg-transparent text-ink rounded-full px-5 py-[10px] text-button-md border border-hairline hover:bg-ivory-200',
  ghost:
    'bg-transparent text-slate rounded-md px-3 py-2 text-body-sm-medium hover:bg-ivory-200 hover:text-ink',
  link:
    'bg-transparent text-copper p-0 text-body-sm-medium hover:text-copper-700 underline-offset-2 hover:underline',
  'icon-circular':
    'bg-canvas text-slate rounded-full border border-hairline w-8 h-8 flex items-center justify-center hover:bg-ivory-200 hover:text-ink',
  'danger':
    'bg-status-rejected text-white rounded-full px-5 py-[10px] text-button-md hover:bg-red-700',
}

const SIZES = {
  sm: 'h-8 text-body-sm px-3 py-1',
  md: 'h-10',
  lg: 'h-12 px-7 text-button-md',
}

export default function Button({
  variant = 'primary',
  size = 'md',
  className = '',
  type = 'button',
  children,
  ...props
}) {
  const base = 'inline-flex items-center justify-center cursor-pointer transition-all duration-200 ease-out font-sans'
  const variantClasses = VARIANTS[variant] || VARIANTS.primary
  const sizeClasses = SIZES[size] || SIZES.md

  return (
    <button
      type={type}
      className={`${base} ${variantClasses} ${sizeClasses} ${className}`}
      {...props}
    >
      {children}
    </button>
  )
}
