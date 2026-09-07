import { useState } from 'react'
import Button from '../components/ui/Button.jsx'

export default function LoginPage({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await onLogin(email, password)
      // Don't navigate here - App.jsx will re-render and the route logic
      // will redirect to "/" because user state is now set
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="min-h-screen bg-hero-dark flex items-center justify-center px-lg relative overflow-hidden">
      {/* Decorative background elements */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-[30%] -right-[20%] w-[600px] h-[600px] rounded-full bg-copper/5 blur-[120px]" />
        <div className="absolute -bottom-[20%] -left-[15%] w-[500px] h-[500px] rounded-full bg-navy-600/30 blur-[100px]" />
        {/* Subtle grid pattern */}
        <div
          className="absolute inset-0 opacity-[0.03]"
          style={{
            backgroundImage: `
              linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px),
              linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px)
            `,
            backgroundSize: '60px 60px',
          }}
        />
      </div>

      <div className="relative w-full max-w-[440px] flex flex-col items-center animate-fade-in">
        {/* Brand */}
        <div className="text-center mb-xxl">
          <h1 className="text-hero-display text-white font-display tracking-tight mb-xs">
            BLITTO
          </h1>
          <p className="text-subtitle text-white/50 font-sans">
            The intelligent patent management platform
          </p>
          <p className="text-caption text-white/30 font-sans mt-xxs">
            University of Peradeniya &middot; Technology Transfer Office
          </p>
        </div>

        {/* Login card */}
        <div className="w-full bg-white/[0.07] backdrop-blur-xl rounded-xl border border-white/10 p-xxl shadow-navy-deep">
          <h2 className="font-display text-heading-3 text-white mb-xs">Welcome back</h2>
          <p className="text-body-sm text-white/50 mb-xl font-sans">Sign in to access your docket</p>

          <form onSubmit={handleSubmit} className="flex flex-col gap-md">
            <div>
              <label htmlFor="email" className="block text-body-sm-medium text-white/70 mb-xs font-sans">
                Email
              </label>
              <input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoFocus
                placeholder="you@university.edu"
                className="w-full h-11 px-md bg-white/[0.06] text-white text-body-md border border-white/10 rounded-md outline-none transition-all duration-200 font-sans placeholder:text-white/25 focus:border-copper focus:ring-2 focus:ring-copper/20"
              />
            </div>
            <div>
              <label htmlFor="password" className="block text-body-sm-medium text-white/70 mb-xs font-sans">
                Password
              </label>
              <input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                placeholder="Enter your password"
                className="w-full h-11 px-md bg-white/[0.06] text-white text-body-md border border-white/10 rounded-md outline-none transition-all duration-200 font-sans placeholder:text-white/25 focus:border-copper focus:ring-2 focus:ring-copper/20"
              />
            </div>

            {error && (
              <p className="text-body-sm text-status-rejected font-sans animate-slide-up">
                {error}
              </p>
            )}

            <Button
              variant="primary"
              type="submit"
              disabled={loading}
              className="w-full mt-xs h-11"
            >
              {loading ? (
                <span className="flex items-center gap-xs">
                  <svg className="animate-spin" width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <circle cx="8" cy="8" r="6" stroke="currentColor" strokeWidth="2" opacity="0.3" />
                    <path d="M8 2a6 6 0 014.24 1.76" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                  </svg>
                  Signing in...
                </span>
              ) : 'Sign in'}
            </Button>
          </form>
        </div>

        <p className="text-caption text-white/20 font-sans mt-xl">
          BLITTO Patent Management System
        </p>
      </div>
    </main>
  )
}
