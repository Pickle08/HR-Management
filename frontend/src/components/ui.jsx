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
