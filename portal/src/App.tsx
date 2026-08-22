import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import type { ReactElement } from 'react'
import { useAuth } from './auth/AuthProvider'
import { Layout } from './components/Layout'
import { OfflineNotice } from './components/OfflineNotice'
import { Loading } from './components/ui'
import { Connect } from './routes/Connect'
import { Contacts } from './routes/Contacts'
import { EditProfile } from './routes/EditProfile'
import { Invitation } from './routes/Invitation'
import { Invite } from './routes/Invite'
import { Login } from './routes/Login'
import { PasswordRequest } from './routes/PasswordRequest'
import { PasswordReset } from './routes/PasswordReset'
import { MemberDetail } from './routes/MemberDetail'
import { Ancestors } from './routes/Ancestors'
import { Members } from './routes/Members'
import { Conversation } from './routes/Conversation'
import { NewConversation } from './routes/NewConversation'
import { Messages } from './routes/Messages'
import { PersonDetail } from './routes/PersonDetail'
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
    <>
      {/*
        Above the router, not inside a screen: losing the connection is a fact
        about the whole portal, and the login screen — the one a member is most
        likely to be staring at when it happens — is not inside the Layout.
      */}
      <OfflineNotice />

      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/password/request" element={<PasswordRequest />} />
        <Route path="/password/reset" element={<PasswordReset />} />
        <Route path="/invitation" element={<Invitation />} />

        <Route
          element={
            <RequireSession>
              <Layout />
            </RequireSession>
          }
        >
          <Route path="/" element={<Navigate to="/me" replace />} />
          <Route path="/me" element={<MyProfile />} />
          <Route path="/me/edit" element={<EditProfile />} />
          <Route path="/individuals/:xref" element={<PersonDetail />} />
          <Route path="/individuals/:xref/ancestors" element={<Ancestors />} />
          <Route path="/invite" element={<Invite />} />
          <Route path="/members" element={<Members />} />
          <Route path="/contacts" element={<Contacts />} />
          {/*
            Behind the session like everything else: a scanned code that
            arrives before signing in is kept by the router and opened again
            afterwards, so nothing is lost by asking who this is first.
          */}
          <Route path="/connect" element={<Connect />} />
          <Route path="/messages" element={<Messages />} />
          {/* Static before dynamic, so that "new" is a screen and not an id. */}
          <Route path="/conversations/new" element={<NewConversation />} />
          <Route path="/conversations/:id" element={<Conversation />} />
          <Route path="/members/:id" element={<MemberDetail />} />
          <Route path="/settings" element={<Settings />} />
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </>
  )
}
