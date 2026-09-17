import { Component } from 'react'

// Catches render-time crashes anywhere below it so a single broken widget
// never whitescreens the whole patent office. Wrap <App /> in main.jsx.
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false }
  }

  static getDerivedStateFromError() {
    return { hasError: true }
  }

  componentDidCatch(error, info) {
    // Visible in devtools / log aggregators; no PII leaves the browser.
    console.error('ErrorBoundary caught:', error, info)
  }

  handleRetry = () => {
    this.setState({ hasError: false })
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-ivory flex items-center justify-center p-xl" role="alert">
          <div className="max-w-[480px] text-center bg-canvas border border-hairline rounded-xl p-2xl shadow-editorial-md">
            <div className="mx-auto mb-lg w-12 h-12 rounded-full bg-copper-100 flex items-center justify-center">
              <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-copper" aria-hidden="true">
                <path d="M12 3L2 21h20L12 3z" strokeLinejoin="round" />
                <path d="M12 10v5" strokeLinecap="round" />
                <circle cx="12" cy="18" r="0.5" fill="currentColor" />
              </svg>
            </div>
            <h1 className="font-display text-heading-3 text-ink mb-sm">Something went wrong</h1>
            <p className="text-body-md text-steel font-sans mb-xl">
              This section crashed. Your data is safe — try again or reload the page.
            </p>
            <button
              type="button"
              onClick={this.handleRetry}
              className="h-10 px-lg bg-copper text-white text-body-md font-sans rounded-md hover:bg-copper-600 transition-colors focus-visible:ring-2 focus-visible:ring-copper focus-visible:ring-offset-2"
            >
              Try again
            </button>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}
