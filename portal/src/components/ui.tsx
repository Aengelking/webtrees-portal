/**
 * The portal's handful of local components.
 *
 * Deliberately not a component library: the audience is older members on
 * phones, and the constraints that matter (16px minimum, 44px targets, real
 * contrast, plain-language states) are easier to hold onto in a few files we
 * own than to fight a design system for.
 */

import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../api/client'

export function PageHeading({ children }: { children: ReactNode }) {
  return <h1 className="text-2xl font-semibold text-slate-900 sm:text-3xl">{children}</h1>
}

export function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="mt-8">
      <h2 className="mb-3 text-lg font-semibold text-slate-900">{title}</h2>
      {children}
    </section>
  )
}

export function Card({ children }: { children: ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">{children}</div>
  )
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary'
}

export function Button({ variant = 'primary', className = '', ...props }: ButtonProps) {
  const base =
    'inline-flex min-h-[44px] items-center justify-center gap-2 rounded-lg px-5 py-3 text-base font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 disabled:opacity-60'

  const variants = {
    primary: 'bg-sky-800 text-white hover:bg-sky-900',
    secondary: 'border border-slate-400 bg-white text-slate-900 hover:bg-slate-100',
  }

  return <button className={`${base} ${variants[variant]} ${className}`} {...props} />
}

type FieldProps = InputHTMLAttributes<HTMLInputElement> & {
  label: string
  hint?: string
}

export function Field({ label, hint, ...props }: FieldProps) {
  const id = useId()
  const hintId = `${id}-hint`

  return (
    <div className="mb-5">
      <label htmlFor={id} className="mb-2 block text-base font-medium text-slate-900">
        {label}
      </label>
      <input
        id={id}
        className="min-h-[48px] w-full rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-sky-700"
        {...(hint === undefined ? {} : { 'aria-describedby': hintId })}
        {...props}
      />
      {hint !== undefined && (
        <p id={hintId} className="mt-2 text-base text-slate-700">
          {hint}
        </p>
      )}
    </div>
  )
}

export function Loading() {
  const { t } = useTranslation()

  return (
    <p role="status" className="py-10 text-center text-base text-slate-700">
      {t('common.loading')}
    </p>
  )
}

export function Notice({
  title,
  body,
  action,
}: {
  title: string
  body: string
  action?: ReactNode
}) {
  return (
    <div className="rounded-xl border border-slate-300 bg-slate-50 p-5 text-center">
      <p className="text-lg font-semibold text-slate-900">{title}</p>
      <p className="mt-2 text-base text-slate-700">{body}</p>
      {action !== undefined && <div className="mt-4 flex justify-center">{action}</div>}
    </div>
  )
}

/**
 * Errors are shown as a sentence a member can act on, never as a code. The
 * code is only used to pick which sentence.
 */
export function ErrorNotice({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const { t } = useTranslation()

  const key =
    error instanceof ApiError &&
    ['network_error', 'not_found', 'not_configured', 'server_error'].includes(error.code)
      ? error.code.replace('network_error', 'network')
      : 'unknown'

  return (
    <div role="alert" className="rounded-xl border border-amber-400 bg-amber-50 p-5">
      <p className="text-lg font-semibold text-slate-900">{t('error.title')}</p>
      <p className="mt-2 text-base text-slate-800">{t(`error.${key}`)}</p>
      {onRetry !== undefined && (
        <Button variant="secondary" className="mt-4" onClick={onRetry}>
          {t('error.retry')}
        </Button>
      )}
    </div>
  )
}
