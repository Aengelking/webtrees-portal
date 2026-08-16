import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import type { ReactElement } from 'react'
import { useAuth } from './auth/AuthProvider'
import { Layout } from './components/Layout'
import { Loading } from './components/ui'
import { Login } from './routes/Login'
import { MemberDetail } from './routes/MemberDetail'
import { Members } from './routes/Members'
import { MyProfile } from './routes/MyProfile'
import { NotFound } from './routes/NotFound'
import { Settings } from './routes/Settings'

/**
 * Nothing behind this renders until the server has confirmed the session.
 * A 401 from any request flips `status` and lands the member back here.
 */
function RequireSession({ children }: { children: ReactElement }) {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'checking') {
    return <Loading />
  }

  if (status === 'signed-out') {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  }

  return children
}

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />

      <Route
        element={
          <RequireSession>
            <Layout />
          </RequireSession>
        }
      >
        <Route path="/" element={<Navigate to="/me" replace />} />
        <Route path="/me" element={<MyProfile />} />
        <Route path="/members" element={<Members />} />
        <Route path="/members/:id" element={<MemberDetail />} />
        <Route path="/settings" element={<Settings />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
