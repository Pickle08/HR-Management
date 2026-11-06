export function Card({ title, children }) {
    return (
        <section className="rounded-lg bg-white p-4 shadow-sm">
            {title && (
                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {title}
                </h2>
            )}
            {children}
        </section>
    );
}

export function Stat({ label, value, tone = "default" }) {
    const tones = {
        default: "text-slate-900",
        warn: "text-amber-600",
        bad: "text-red-600",
    };

    return (
        <div>
            <p className={`text-2xl font-semibold ${tones[tone]}`}>{value}</p>
            <p className="text-xs text-slate-500">{label}</p>
        </div>
    );
}

export function StatRow({ children }) {
    return (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">{children}</div>
    );
}

export function Empty({ children }) {
    return <p className="text-sm text-slate-500">{children}</p>;
}

export function SectionTitle({ children }) {
    return (
        <h2 className="pt-2 text-base font-semibold text-slate-800">
            {children}
        </h2>
    );
}

export function errorMessage(error) {
    const status = error?.response?.status;

    if (status === 403) return "Anda tidak berhak mengakses data ini.";
    if (status === 404) return "Data tidak ditemukan.";
    if (status === 409 || status === 422)
        return error.response.data?.message ?? "Permintaan ditolak.";

    return "Terjadi kesalahan. Coba lagi.";
}

export function ErrorBox({ error, onRetry }) {
    return (
        <div
            className="rounded-lg bg-red-50 p-4 text-sm text-red-700"
            role="alert">
            <p>{errorMessage(error)}</p>
            {onRetry && (
                <button onClick={onRetry} className="mt-2 underline">
                    Coba lagi
                </button>
            )}
        </div>
    );
}

export function Skeleton() {
    return (
        <div className="space-y-4" aria-busy="true">
            <div className="h-8 w-48 animate-pulse rounded bg-slate-200" />
            <div className="h-28 animate-pulse rounded-lg bg-slate-200" />
            <div className="h-28 animate-pulse rounded-lg bg-slate-200" />
        </div>
    );
}

export const inputClass =
    "mt-1 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100";

export function Field({ label, error, hint, children }) {
    return (
        <div className="text-sm">
            <label className="block">
                <span className="text-slate-700">{label}</span>
                {children}
            </label>
            {hint && !error && (
                <span className="mt-1 block text-xs text-slate-500">
                    {hint}
                </span>
            )}
            {error && (
                <span className="mt-1 block text-xs text-red-600">{error}</span>
            )}
        </div>
    );
}

export function Badge({ tone = "default", children }) {
    const tones = {
        default: "bg-slate-100 text-slate-700",
        good: "bg-green-100 text-green-800",
        bad: "bg-red-100 text-red-800",
    };

    return (
        <span
            className={`inline-block rounded px-2 py-0.5 text-xs ${tones[tone]}`}>
            {children}
        </span>
    );
}

export function Pagination({ meta, onPage }) {
    if (!meta || meta.last_page <= 1) return null;

    const button =
        "rounded border border-slate-300 bg-white px-3 py-1 disabled:opacity-40";

    return (
        <div className="flex items-center justify-between text-sm">
            <span className="text-slate-500">
                Halaman {meta.current_page} dari {meta.last_page} ({meta.total}{" "}
                data)
            </span>
            <div className="flex gap-2">
                <button
                    className={button}
                    disabled={meta.current_page <= 1}
                    onClick={() => onPage(meta.current_page - 1)}>
                    Sebelumnya
                </button>
                <button
                    className={button}
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => onPage(meta.current_page + 1)}>
                    Berikutnya
                </button>
            </div>
        </div>
    );
}

export function Dialog({ title, children, onClose }) {
    return (
        <div
            className="fixed inset-0 z-20 flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-label={title}>
            <div className="w-full max-w-md space-y-3 rounded-lg bg-white p-4 shadow-lg">
                <div className="flex items-center justify-between">
                    <h2 className="text-base font-semibold">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Tutup"
                        className="text-slate-500">
                        ✕
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

export function Tabs({ tabs, value, onChange }) {
    return (
        <div className="flex gap-1 border-b border-slate-200" role="tablist">
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    role="tab"
                    aria-selected={value === tab.key}
                    onClick={() => onChange(tab.key)}
                    className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                        value === tab.key
                            ? "border-slate-900 font-medium text-slate-900"
                            : "border-transparent text-slate-500"
                    }`}>
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
