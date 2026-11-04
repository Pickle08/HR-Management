export function Card({ title, children }) {
  return (
    <section className="rounded-lg bg-white p-4 shadow-sm">
      {title && (
        <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h2>
      )}
      {children}
    </section>
  )
}

export function Stat({ label, value, tone = 'default' }) {
  const tones = { default: 'text-slate-900', warn: 'text-amber-600', bad: 'text-red-600' }

  return (
    <div>
      <p className={`text-2xl font-semibold ${tones[tone]}`}>{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </div>
  )
}

export function StatRow({ children }) {
  return <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">{children}</div>
}

export function Empty({ children }) {
  return <p className="text-sm text-slate-500">{children}</p>
}

export function SectionTitle({ children }) {
  return <h2 className="pt-2 text-base font-semibold text-slate-800">{children}</h2>
}

export function ErrorBox({ error, onRetry }) {
  const message = error?.response?.data?.message ?? 'Gagal memuat data.'

  return (
    <div className="rounded-lg bg-red-50 p-4 text-sm text-red-700" role="alert">
      <p>{message}</p>
      {onRetry && (
        <button onClick={onRetry} className="mt-2 underline">
          Coba lagi
        </button>
      )}
    </div>
  )
}

export function Skeleton() {
  return (
    <div className="space-y-4" aria-busy="true">
      <div className="h-8 w-48 animate-pulse rounded bg-slate-200" />
      <div className="h-28 animate-pulse rounded-lg bg-slate-200" />
      <div className="h-28 animate-pulse rounded-lg bg-slate-200" />
    </div>
  )
}

export const inputClass =
  'mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100'

export function Field({ label, error, hint, children }) {
  return (
    <label className="block text-sm">
      <span className="text-slate-700">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && (
        <span className="mt-1 block text-xs text-red-600" role="alert">
          {error}
        </span>
      )}
    </label>
  )
}

export function Badge({ tone = 'default', children }) {
  const tones = {
    default: 'bg-slate-100 text-slate-700',
    good: 'bg-green-100 text-green-800',
    bad: 'bg-red-100 text-red-800',
  }

  return <span className={`inline-block rounded px-2 py-0.5 text-xs ${tones[tone]}`}>{children}</span>
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null

  const button = 'rounded border border-slate-300 bg-white px-3 py-1 disabled:opacity-40'

  return (
    <div className="flex items-center justify-between text-sm">
      <span className="text-slate-500">
        Halaman {meta.current_page} dari {meta.last_page} ({meta.total} data)
      </span>
      <div className="flex gap-2">
        <button className={button} disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          Sebelumnya
        </button>
        <button
          className={button}
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Berikutnya
        </button>
      </div>
    </div>
  )
}
