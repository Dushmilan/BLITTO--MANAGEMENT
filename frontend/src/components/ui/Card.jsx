const VARIANTS = {
  base:
    'bg-canvas rounded-lg p-xl border border-hairline shadow-editorial-sm',
  feature:
    'bg-ivory-200 rounded-lg p-xxl',
  help:
    'bg-canvas rounded-lg p-xl border border-hairline hover:shadow-editorial-md transition-shadow duration-200',
  'startup-perk':
    'bg-canvas rounded-lg p-xl border border-hairline hover:shadow-editorial-sm transition-shadow duration-200',
  'pricing-card':
    'bg-canvas rounded-lg p-xxl border border-hairline',
  'pricing-card-featured':
    'bg-canvas rounded-lg p-xxl border-2 border-copper shadow-copper-glow',
  'testimonial-card-feature':
    'bg-copper text-white rounded-lg p-section',
  'testimonial-card-quote':
    'bg-canvas text-ink rounded-lg p-xxl border border-hairline',
  'founder-quote':
    'bg-copper text-white rounded-lg p-xxl',
  'startup-program':
    'bg-canvas rounded-lg p-xxl border border-hairline',
  mockup:
    'bg-canvas rounded-lg border border-hairline-soft shadow-editorial-lg',
  stat:
    'bg-canvas rounded-lg p-xl border border-hairline hover:shadow-editorial-md hover:border-copper-200 transition-all duration-200',
}

export default function Card({ variant = 'base', className = '', children, ...props }) {
  return (
    <div className={`${VARIANTS[variant]} ${className}`} {...props}>
      {children}
    </div>
  )
}
