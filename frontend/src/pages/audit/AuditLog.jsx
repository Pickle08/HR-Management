import { useState } from "react";
import {
    Dialog,
    Empty,
    ErrorBox,
    Field,
    Pagination,
    Skeleton,
    inputClass,
} from "../../components/ui";
import { useAuditActions, useAuditLogs } from "../../lib/audit";

const formatTime = (iso) => (iso ? new Date(iso).toLocaleString("id-ID") : "-");

function formatValue(value) {
    if (value === null || value === undefined) return "-";
    if (typeof value === "boolean") return value ? "Ya" : "Tidak";
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
}

function Detail({ log, onClose }) {
    const oldValues = log.old_values ?? {};
    const newValues = log.new_values ?? {};
    // Nilai sebuah kolom hanya ada kalau backend mengizinkannya tampil.
    const visible = (field) => field in oldValues || field in newValues;

    return (
        <Dialog title="Rincian aktivitas" onClose={onClose}>
            <dl className="space-y-2 text-sm">
                <div>
                    <dt className="text-xs text-slate-500">Waktu</dt>
                    <dd>{formatTime(log.created_at)}</dd>
                </div>
                <div>
                    <dt className="text-xs text-slate-500">Pengguna</dt>
                    <dd>
                        {log.actor
                            ? `${log.actor.name} (${log.actor.email})`
                            : "Tanpa pengguna"}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-slate-500">Aksi</dt>
                    <dd className="font-mono">{log.action}</dd>
                </div>
                {log.subject && (
                    <div>
                        <dt className="text-xs text-slate-500">Objek</dt>
                        <dd>
                            {log.subject.type} #{log.subject.id}
                        </dd>
                    </div>
                )}
                {log.ip_address && (
                    <div>
                        <dt className="text-xs text-slate-500">Alamat IP</dt>
                        <dd>{log.ip_address}</dd>
                    </div>
                )}
            </dl>

            <div className="max-h-64 space-y-2 overflow-y-auto border-t border-slate-200 pt-3 text-sm">
                {log.changed_fields.length === 0 && (
                    <p className="text-slate-500">
                        Tidak ada rincian perubahan.
                    </p>
                )}
                {log.changed_fields.map((field) => (
                    <div key={field} className="rounded bg-slate-50 p-2">
                        <p className="text-xs font-medium text-slate-600">
                            {field}
                        </p>
                        {visible(field) ? (
                            <p>
                                <span className="text-slate-500">
                                    {formatValue(oldValues[field])}
                                </span>
                                {" → "}
                                <span>{formatValue(newValues[field])}</span>
                            </p>
                        ) : (
                            <p className="text-xs text-slate-500">
                                Nilai tidak ditampilkan
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </Dialog>
    );
}

export default function AuditLog() {
    const [page, setPage] = useState(1);
    const [action, setAction] = useState("");
    const [from, setFrom] = useState("");
    const [to, setTo] = useState("");
    const [selected, setSelected] = useState(null);

    const rangeInvalid = Boolean(from && to && to < from);
    const actions = useAuditActions();
    const { data, isLoading, isFetching, error, refetch } = useAuditLogs({
        page,
        action,
        from,
        to,
        enabled: !rangeInvalid,
    });

    const onFilter = (setter) => (e) => {
        setter(e.target.value);
        setPage(1);
    };

    const rows = data?.data ?? [];

    return (
        <div className="space-y-4">
            <h1 className="text-xl font-semibold">Audit log</h1>

            <div className="flex flex-wrap items-end gap-3">
                <Field label="Aksi">
                    <select
                        className={inputClass}
                        value={action}
                        onChange={onFilter(setAction)}>
                        <option value="">Semua aksi</option>
                        {(actions.data ?? []).map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Dari tanggal">
                    <input
                        type="date"
                        className={inputClass}
                        value={from}
                        onChange={onFilter(setFrom)}
                    />
                </Field>
                <Field label="Sampai tanggal">
                    <input
                        type="date"
                        className={inputClass}
                        value={to}
                        onChange={onFilter(setTo)}
                    />
                </Field>
                {isFetching && (
                    <span className="pb-2 text-xs text-slate-500">
                        Memperbarui...
                    </span>
                )}
            </div>

            {rangeInvalid && (
                <p
                    className="rounded bg-amber-50 p-3 text-sm text-amber-800"
                    role="alert">
                    Tanggal akhir harus sama dengan atau setelah tanggal awal.
                </p>
            )}

            {isLoading && !rangeInvalid && <Skeleton />}
            {error && !rangeInvalid && !data && (
                <ErrorBox error={error} onRetry={refetch} />
            )}

            {data && !rangeInvalid && (
                <>
                    {rows.length === 0 ? (
                        <Empty>Tidak ada aktivitas yang cocok.</Empty>
                    ) : (
                        <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">Waktu</th>
                                        <th className="px-3 py-2">Pengguna</th>
                                        <th className="px-3 py-2">Aksi</th>
                                        <th className="px-3 py-2">Objek</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((log) => (
                                        <tr
                                            key={log.id}
                                            className="border-b border-slate-100 last:border-0">
                                            <td className="whitespace-nowrap px-3 py-2">
                                                {formatTime(log.created_at)}
                                            </td>
                                            <td className="px-3 py-2">
                                                {log.actor?.name ?? (
                                                    <span className="text-slate-500">
                                                        Tanpa pengguna
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">
                                                {log.action}
                                            </td>
                                            <td className="px-3 py-2">
                                                {log.subject
                                                    ? `${log.subject.type} #${log.subject.id}`
                                                    : "-"}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <button
                                                    onClick={() =>
                                                        setSelected(log)
                                                    }
                                                    className="underline">
                                                    Rincian
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination meta={data.meta} onPage={setPage} />
                </>
            )}

            {selected && (
                <Detail log={selected} onClose={() => setSelected(null)} />
            )}
        </div>
    );
}
