import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Card, Section } from './ui'

/**
 * The standing offer to bring somebody else into the portal.
 *
 * **Unconditional, and on two screens.** It was on Settings only, where a
 * member goes to change something about themselves — which is not the frame of
 * mind in which anybody thinks "my brother is not in here". Mein Profil is:
 * it is the screen a member opens by default, the one with their own family
 * on it, and the one they are looking at when they notice who is missing.
 *
 * A person's own page offers it too, but only for that person and only when
 * they can actually be invited (`invitable` on the record). That offer is
 * about *them*. This one is about the facility, so it is always there — and
 * "always" is what makes it findable: an entry that comes and goes is one
 * nobody learns the location of.
 *
 * It leads to a screen that explains every reason an invitation might not be
 * possible — the family switched it off, the account is not linked to a
 * record, the quota is spent. That is a better place to say so than a button
 * that quietly is not there, which is §2.33's rule the other way up: silence
 * is right for something impossible, and this is merely something not started
 * yet.
 *
 * One component rather than the same markup twice, so the two cannot drift
 * into being two different offers.
 */
export function InviteCard() {
  const { t } = useTranslation()

  return (
    <Section title={t('settings.invite')}>
      <Card>
        <p className="text-base text-slate-700">{t('settings.inviteBody')}</p>
        <Link
          to="/invite"
          className="mt-4 inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
        >
          {t('settings.inviteAction')}
        </Link>
      </Card>
    </Section>
  )
}
