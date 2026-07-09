const VARIANTS = {
  default:
    'bg-ivory-200 text-slate',
  status:
    'bg-navy-50 text-navy',
  required:
    'bg-status-rejected/10 text-status-rejected',
  type:
    'bg-ivory-200 text-steel',
  tag:
    'bg-copper-50 text-copper',
  draft:
    'bg-status-draft/10 text-status-draft',
  filed:
    'bg-status-filed/10 text-status-filed',
  examination:
    'bg-status-examination/10 text-status-examination',
  granted:
    'bg-status-granted/10 text-status-granted',
  rejected:
    'bg-status-rejected/10 text-status-rejected',
  maintenance:
    'bg-status-maintenance/10 text-status-maintenance',
}

const STATUS_MAP = {
  draft: 'draft',
  filed: 'filed',
  published: 'filed',
  examination: 'examination',
  'under examination': 'examination',
  granted: 'granted',
  rejected: 'rejected',
  maintenance: 'maintenance',
}

export default function Badge({ variant = 'default', className = '', children, ...props }) {
  const normalizedStatus = typeof children === 'string'
    ? STATUS_MAP[children.toLowerCase()] || variant
    : variant

  const variantClasses = VARIANTS[normalizedStatus] || VARIANTS[variant] || VARIANTS.default

  return (
    <span
      className={`inline-flex items-center px-xs py-xxs text-caption-bold rounded-sm font-sans ${variantClasses} ${className}`}
      {...props}
    >
      {children}
    </span>
  )
}
