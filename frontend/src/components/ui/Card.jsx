const VARIANTS = {
  base:
    'bg-canvas dark:bg-navy-800 rounded-lg p-xl border border-hairline dark:border-hairline-dark shadow-editorial-sm',
  feature:
    'bg-ivory-200 dark:bg-navy-700 rounded-lg p-xxl',
  help:
    'bg-canvas dark:bg-navy-800 rounded-lg p-xl border border-hairline dark:border-hairline-dark hover:shadow-editorial-md transition-shadow duration-200',
  'startup-perk':
    'bg-canvas dark:bg-navy-800 rounded-lg p-xl border border-hairline dark:border-hairline-dark hover:shadow-editorial-sm transition-shadow duration-200',
  'pricing-card':
    'bg-canvas dark:bg-navy-800 rounded-lg p-xxl border border-hairline dark:border-hairline-dark',
  'pricing-card-featured':
    'bg-canvas rounded-lg p-xxl border-2 border-copper shadow-copper-glow',
  'testimonial-card-feature':
    'bg-copper text-white rounded-lg p-section',
  'testimonial-card-quote':
    'bg-canvas dark:bg-navy-800 text-ink dark:text-white rounded-lg p-xxl border border-hairline dark:border-hairline-dark',
  'founder-quote':
    'bg-copper text-white rounded-lg p-xxl',
  'startup-program':
    'bg-canvas dark:bg-navy-800 rounded-lg p-xxl border border-hairline dark:border-hairline-dark',
  mockup:
    'bg-canvas dark:bg-navy-800 rounded-lg border border-hairline-soft dark:border-hairline-dark shadow-editorial-lg',
  stat:
    'bg-canvas dark:bg-navy-800 rounded-lg p-xl border border-hairline dark:border-hairline-dark hover:shadow-editorial-md hover:border-copper-200 transition-all duration-200',
}

export default function Card({ variant = 'base', className = '', children, ...props }) {
  return (
    <div className={`${VARIANTS[variant]} ${className}`} {...props}>
      {children}
    </div>
  )
}
