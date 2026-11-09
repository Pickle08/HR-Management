import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useAuth } from "../auth/useAuth";
import {
    Empty,
    ErrorBox,
    Field,
    Pagination,
    Skeleton,
    inputClass,
} from "../components/ui";
import { api } from "../lib/api";
import { MONTH_LABEL } from "../lib/labels";

// Cermin dari ReportService::allowedRoles(). Hanya soal tampilan; yang menolak tetap backend.
const REPORTS = [
    {
        key: "employees",
        label: "Karyawan",
        kind: "none",
        roles: ["hr", "super_admin"],
    },
    {
        key: "attendance",
        label: "Absensi",
        kind: "period",
        roles: ["hr", "atasan"],
    },
    { key: "leave", label: "Cuti", kind: "period", roles: ["hr", "atasan"] },
    {
        key: "recruitment",
        label: "Rekrutmen",
        kind: "none",
        roles: ["hr", "super_admin"],
    },
    {
        key: "payroll",
        label: "Payroll",
        kind: "month",
        roles: ["hr", "finance"],
    },
];

// Cermin dari ReportService::MAX_PERIOD_DAYS. Kalau backend berubah, ini harus ikut.
const MAX_PERIOD_DAYS = 92;

const iso = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

function defaultForm() {
    const now = new Date();

    return {
        from: iso(new Date(now.getFullYear(), now.getMonth(), 1)),
        to: iso(now),
        year: String(now.getFullYear()),
        month: String(now.getMonth() + 1),
    };
}

function validate(kind, form) {
    const errors = {};

    if (kind === "period") {
        if (!form.from) errors.from = "Wajib diisi.";
        if (!form.to) errors.to = "Wajib diisi.";

        if (form.from && form.to) {
            const days = Math.round(
                (Date.parse(form.to) - Date.parse(form.from)) / 86_400_000,
            );

            if (days < 0) {
                errors.to = "Tanggal akhir tidak boleh sebelum tanggal awal.";
            } else if (days > MAX_PERIOD_DAYS) {
                errors.to = `Rentang laporan maksimal ${MAX_PERIOD_DAYS} hari.`;
            }
        }
    }

    if (kind === "month") {
        const year = Number(form.year);

        if (!Number.isInteger(year) || year < 2000 || year > 2100) {
            errors.year = "Tahun harus antara 2000 dan 2100.";
        }
    }

    return errors;
}

function buildParams(kind, form) {
    if (kind === "period") return { from: form.from, to: form.to };
    if (kind === "month") {
        return { year: Number(form.year), month: Number(form.month) };
    }

    return {};
}

function messagesFrom(body) {
    const list = Object.values(body?.errors ?? {}).flat();

    if (list.length > 0) return list;

    return body?.message ? [body.message] : [];
}

const blobText = (blob) =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = reject;
        reader.readAsText(blob);
    });

// Dengan responseType "blob", body error (JSON 422) juga datang sebagai Blob.
async function exportErrorMessage(error) {
    const data = error?.response?.data;

    if (data instanceof Blob) {
        try {
            const messages = messagesFrom(JSON.parse(await blobText(data)));

            if (messages.length > 0) return messages.join(" ");
        } catch {
            // body bukan JSON: jatuh ke pesan umum
        }
    }

    return "Gagal mengekspor laporan. Coba lagi.";
}

function filenameFrom(headers, fallback) {
    const match = /filename="?([^";]+)"?/.exec(
        headers?.["content-disposition"] ?? "",
    );

    return match ? match[1] : fallback;
}

function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

export default function Reports() {
    const { user } = useAuth();
    const available = REPORTS.filter((r) => r.roles.includes(user.role));

    const [key, setKey] = useState(available[0]?.key ?? "");
    const [form, setForm] = useState(defaultForm);
    const [fieldErrors, setFieldErrors] = useState({});
    const [applied, setApplied] = useState(null);
    const [exporting, setExporting] = useState(false);
    const [exportError, setExportError] = useState("");

    const report = available.find((r) => r.key === key);

    const { data, isLoading, error, refetch } = useQuery({
        queryKey: ["report", user.id, applied],
        queryFn: async () =>
            (
                await api.get(`/api/reports/${applied.report}`, {
                    params: { ...applied.params, page: applied.page },
                })
            ).data,
        enabled: Boolean(applied),
    });

    if (!report) {
        return (
            <div className="space-y-4">
                <h1 className="text-xl font-semibold">Laporan</h1>
                <Empty>Tidak ada laporan untuk peran Anda.</Empty>
            </div>
        );
    }

    const set = (field) => (e) => {
        const value = e.target.value;

        setForm((previous) => ({ ...previous, [field]: value }));
        setFieldErrors((previous) => ({ ...previous, [field]: undefined }));
    };

    function changeReport(e) {
        setKey(e.target.value);
        setApplied(null);
        setFieldErrors({});
        setExportError("");
    }

    function submit(e) {
        e.preventDefault();

        const found = validate(report.kind, form);
        setFieldErrors(found);
        setExportError("");

        if (Object.keys(found).length > 0) return;

        const next = {
            report: report.key,
            params: buildParams(report.kind, form),
            page: 1,
        };

        // Filter yang sama: klik "Tampilkan" lagi berarti muat ulang.
        if (JSON.stringify(next) === JSON.stringify(applied)) {
            refetch();
        } else {
            setApplied(next);
        }
    }

    async function exportCsv() {
        setExporting(true);
        setExportError("");

        try {
            const res = await api.get(`/api/reports/${applied.report}`, {
                params: { ...applied.params, format: "csv" },
                responseType: "blob",
            });

            saveBlob(
                res.data,
                filenameFrom(res.headers, `laporan-${applied.report}.csv`),
            );
        } catch (err) {
            setExportError(await exportErrorMessage(err));
        } finally {
            setExporting(false);
        }
    }

    const queryMessages =
        error?.response?.status === 422
            ? messagesFrom(error.response.data)
            : [];
    const columns = Object.entries(data?.meta?.columns ?? {});
    const rows = data?.data ?? [];

    return (
        <div className="space-y-4">
            <h1 className="text-xl font-semibold">Laporan</h1>

            <form
                onSubmit={submit}
                noValidate
                className="flex flex-wrap items-end gap-3">
                <Field label="Laporan">
                    <select
                        className={inputClass}
                        value={key}
                        onChange={changeReport}>
                        {available.map((r) => (
                            <option key={r.key} value={r.key}>
                                {r.label}
                            </option>
                        ))}
                    </select>
                </Field>

                {report.kind === "period" && (
                    <>
                        <Field label="Dari" error={fieldErrors.from}>
                            <input
                                type="date"
                                className={inputClass}
                                value={form.from}
                                onChange={set("from")}
                            />
                        </Field>
                        <Field label="Sampai" error={fieldErrors.to}>
                            <input
                                type="date"
                                className={inputClass}
                                value={form.to}
                                onChange={set("to")}
                            />
                        </Field>
                    </>
                )}

                {report.kind === "month" && (
                    <>
                        <Field label="Bulan">
                            <select
                                className={inputClass}
                                value={form.month}
                                onChange={set("month")}>
                                {MONTH_LABEL.map((name, index) => (
                                    <option key={name} value={index + 1}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Tahun" error={fieldErrors.year}>
                            <input
                                type="number"
                                className={inputClass}
                                value={form.year}
                                onChange={set("year")}
                            />
                        </Field>
                    </>
                )}

                <button
                    type="submit"
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white">
                    Tampilkan
                </button>
                <button
                    type="button"
                    onClick={exportCsv}
                    disabled={!applied || exporting}
                    className="rounded border border-slate-300 bg-white px-4 py-2 text-sm disabled:opacity-50">
                    {exporting ? "Mengekspor..." : "Ekspor CSV"}
                </button>
            </form>

            {applied && (
                <p className="text-xs text-slate-500">
                    Ekspor memakai filter yang sedang ditampilkan di tabel.
                </p>
            )}

            {exportError && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {exportError}
                </p>
            )}

            {applied &&
                (isLoading ? (
                    <Skeleton />
                ) : queryMessages.length > 0 ? (
                    <div
                        className="rounded bg-red-50 p-3 text-sm text-red-700"
                        role="alert">
                        {queryMessages.map((message) => (
                            <p key={message}>{message}</p>
                        ))}
                    </div>
                ) : error ? (
                    <ErrorBox error={error} onRetry={refetch} />
                ) : data ? (
                    rows.length === 0 ? (
                        <Empty>Tidak ada data untuk filter ini.</Empty>
                    ) : (
                        <>
                            <p className="text-sm text-slate-600">
                                {data.meta.total} baris
                            </p>
                            <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                                        <tr>
                                            {columns.map(
                                                ([columnKey, title]) => (
                                                    <th
                                                        key={columnKey}
                                                        className="px-3 py-2">
                                                        {title}
                                                    </th>
                                                ),
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row, index) => (
                                            <tr
                                                key={index}
                                                className="border-b border-slate-100 last:border-0">
                                                {columns.map(([columnKey]) => (
                                                    <td
                                                        key={columnKey}
                                                        className="px-3 py-2">
                                                        {row[columnKey] ?? "-"}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pagination
                                meta={data.meta}
                                onPage={(page) =>
                                    setApplied((previous) => ({
                                        ...previous,
                                        page,
                                    }))
                                }
                            />
                        </>
                    )
                ) : null)}
        </div>
    );
}
