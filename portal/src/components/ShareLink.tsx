import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, SuccessNote } from './ui'

/**
 * A link the member has to get out of the portal and into somebody's hands.
 *
 * Both of the portal's links work this way — an invitation and a connection
 * link — and both are handed over *once*: the server keeps a hash, so what is
 * on the screen is the only copy there will ever be. That is precisely why
 * this is not just a text field to select by hand. A phone, a link shown once,
 * and "please select this text carefully" is how a member ends up with half a
 * URL in a chat and an invitation they have to withdraw and re-issue.
 *
 * **Teilen where the browser has it**, because the link is going into
 * WhatsApp or a text message and the share sheet is the shortest way there.
 * It is absent on most desktops, so **Kopieren is always offered** rather than
 * being the fallback nobody sees — §2.33's rule, applied on purpose: silence
 * is right for something impossible and wrong for something merely harder.
 *
 * The field stays regardless. A member who trusts neither button can still
 * read the link, and one whose browser refuses the clipboard has lost nothing.
 */
export function ShareLink({
  id,
  url,
  label,
  shareTitle,
  shareText,
}: {
  id: string
  url: string
  label: string
  shareTitle: string
  shareText?: string
}) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
    } catch {
      // Some browsers refuse without a gesture they recognise, and some refuse
      // outright. The link is on screen and selectable either way, so there is
      // nothing to report and nothing to fix.
    }
  }

  function share() {
    void navigator
      .share({ title: shareTitle, url, ...(shareText === undefined ? {} : { text: shareText }) })
      // Cancelling a share sheet rejects, and cancelling is an answer rather
      // than a failure. Nothing is said either way: the link is still there.
      .catch(() => undefined)
  }

  return (
    <div>
      <label htmlFor={id} className="mb-2 block text-base font-medium text-slate-900">
        {label}
      </label>
      <input
        id={id}
        readOnly
        value={url}
        onFocus={(event) => event.target.select()}
        className="min-h-[48px] w-full rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900"
      />

      <div className="mt-3 flex flex-wrap gap-3">
        <Button onClick={() => void copy()}>{t('link.copy')}</Button>

        {typeof navigator.share === 'function' && (
          <Button variant="secondary" onClick={share}>
            {t('link.share')}
          </Button>
        )}
      </div>

      {copied && (
        <div className="mt-3">
          <SuccessNote>{t('link.copied')}</SuccessNote>
        </div>
      )}
    </div>
  )
}
