import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import '../css/app.css'

createInertiaApp({
  title: (title) => title ? `${title} — IPFlow` : 'IPFlow — Enterprise IP Management',
  resolve: (name) => {
    const pages = import.meta.glob('./pages/**/*.tsx', { eager: true })
    return pages[`./pages/${name}.tsx`] as any
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />)
  },
})
