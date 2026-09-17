const VARIANTS = {
  primary:
    'bg-copper text-white rounded-full px-lg py-xs text-button-md shadow-editorial-sm hover:bg-copper-600 hover:shadow-copper-glow',
  'primary-dark':
    'bg-navy text-white rounded-full px-lg py-xs text-button-md shadow-editorial-sm hover:bg-navy-800',
  secondary:
    'bg-transparent text-ink dark:text-white rounded-full px-lg py-xs text-button-md border border-hairline dark:border-hairline-dark hover:bg-ivory-200 dark:hover:bg-white/10',
  ghost:
    'bg-transparent text-slate dark:text-white/60 rounded-md px-3 py-2 text-body-sm-medium hover:bg-ivory-200 dark:hover:bg-white/10 hover:text-ink dark:hover:text-white',
  link:
    'bg-transparent text-copper p-0 text-body-sm-medium hover:text-copper-700 underline-offset-2 hover:underline',
  'icon-circular':
    'bg-canvas dark:bg-navy-700 text-slate dark:text-white/70 rounded-full border border-hairline dark:border-hairline-dark w-8 h-8 flex items-center justify-center hover:bg-ivory-200 dark:hover:bg-white/10 hover:text-ink dark:hover:text-white',
  'danger':
    'bg-status-rejected text-white rounded-full px-lg py-xs text-button-md hover:bg-red-700',
}

const SIZES = {
  sm: 'h-8 text-body-sm px-sm py-xxs',
  md: 'h-10',
  lg: 'h-12 px-xxl text-button-md',
}

export default function Button({
  variant = 'primary',
  size = 'md',
  className = '',
  type = 'button',
  children,
  ...props
}) {
  const base = 'touch-target inline-flex items-center justify-center cursor-pointer transition-all duration-200 ease-out font-sans focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-copper focus-visible:ring-offset-2'
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
