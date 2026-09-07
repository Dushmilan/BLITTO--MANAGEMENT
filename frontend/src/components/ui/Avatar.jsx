export default function Avatar({ name, size = 'md', className = '' }) {
  const initials = (name || '?')
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)

  const sizes = {
    sm: 'w-7 h-7 text-micro',
    md: 'w-9 h-9 text-body-sm',
    lg: 'w-12 h-12 text-heading-5',
  }

  return (
    <div
      className={`inline-flex items-center justify-center rounded-full bg-copper-50 text-copper font-sans font-semibold ${sizes[size]} ${className}`}
      title={name}
    >
      {initials}
    </div>
  )
}
