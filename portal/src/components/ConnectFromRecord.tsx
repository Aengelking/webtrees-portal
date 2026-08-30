import { useTranslation } from 'react-i18next'
import { useConnect } from '../api/queries'
import { Button, ErrorNotice, Notice, SuccessNote } from './ui'

/**
 * "Verbinden", on the page of the person themselves.
 *
 * Walking the tree is how a member finds a relative. Until now, asking that
 * relative to connect meant reading their number off this screen and typing
 * it into the one under Kontakte — a detour past the only part of it that was
 * ever difficult.
 *
 * **The offer says nothing about whether they have an account**, and that is
 * not an oversight. A member who stayed out of the directory decided that the
 * portal must not confirm they are in it; a button that appeared only for
 * account holders would answer that question to every relative who can see
 * the record. So the server answers `open` for the unlisted member, for the
 * relative with no account at all, and for a request sent yesterday that
 * nobody has answered — one word for the three (`Connections::recordState`).
 *
 * The confirmation keeps the same silence: without a name, all it can say is
 * that *if* there is somebody there, they have been asked. Where the server
 * does name them — a member of the directory, or a contact already — it is
 * saying nothing that screen was not already showing.
 *
 * A request still waiting for an answer is reported for the same reason and
 * under the same limit: the server says `requested` only about somebody the
 * directory already names. For everybody else it stays `open`, and pressing
 * again is harmless — the request that exists is not duplicated.
 */
export function ConnectFromRecord({
  xref,
  name,
  state,
}: {
  xref: string
  name: string
  state: 'connected' | 'requested' | 'open'
}) {
  const { t } = useTranslation()
  const connect = useConnect()

  if (state === 'connected') {
    return <Notice title={t('person.connect.title')} body={t('person.connect.connected')} />
  }

  // Only ever said about somebody the directory already names, so it says
  // nothing the directory does not — see `Connections::recordState`.
  if (state === 'requested') {
    return <Notice title={t('person.connect.title')} body={t('person.connect.waiting')} />
  }

  if (connect.isSuccess) {
    return (
      <SuccessNote>
        {connect.data.name === null
          ? t('person.connect.quiet', { name })
          : connect.data.status === 'already_connected'
            ? t('person.connect.connected')
            : connect.data.status === 'connected'
              ? t('contacts.connected', { name: connect.data.name })
              : t('contacts.requested', { name: connect.data.name })}
      </SuccessNote>
    )
  }

  return (
    <>
      {connect.isError && (
        <div className="mb-4">
          <ErrorNotice error={connect.error} />
        </div>
      )}

      <Notice
        title={t('person.connect.title')}
        body={t('person.connect.body', { name })}
        action={
          <Button disabled={connect.isPending} onClick={() => connect.mutate({ xref })}>
            {connect.isPending ? t('contacts.asking') : t('person.connect.action')}
          </Button>
        }
      />
    </>
  )
}
