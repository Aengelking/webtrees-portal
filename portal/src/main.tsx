import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import { ApiError } from './api/client'
import { installStore } from './pwa/install'
import { registerServiceWorker } from './pwa/register'
import './i18n'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Genealogy data is living people's personal data on a possibly shared
      // device. Keep it in memory only while a screen needs it, and never
      // re-serve it from cache after the member navigates away.
      gcTime: 0,
      staleTime: 0,
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status !== 0 && error.status < 500) {
          return false
        }

        return failureCount < 2
      },
    },
  },
})

const container = document.getElementById('root')

if (container === null) {
  throw new Error('No #root element')
}

createRoot(container).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)

// Makes the portal installable: a home screen icon that opens without
// browser furniture, and says something useful when there is no connection.
registerServiceWorker()

// Here rather than in the screen that shows the offer: a browser fires
// `beforeinstallprompt` once, early, and whoever is not listening by then does
// not get a second chance.
installStore.watch()
