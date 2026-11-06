import { useState } from "react";
import {
    keepPreviousData,
    useMutation,
    useQuery,
    useQueryClient,
} from "@tanstack/react-query";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "../../auth/useAuth";
import EmployeePicker from "../../components/EmployeePicker";
import {
    Badge,
    Empty,
    ErrorBox,
    Field,
    Pagination,
    Skeleton,
    inputClass,
} from "../../components/ui";
import { api } from "../../lib/api";
import {
    MONTH_LABEL,
    PAYROLL_STATUS_LABEL,
    PAYROLL_STATUS_TONE,
} from "../../lib/labels";
import { rupiah } from "../../lib/money";

const KNOWN_FIELDS = ["employee_id", "year", "month"];

function CreateForm({ onClose }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const now = new Date();
    const [missing, setMissing] = useState(false);
    const [form, setForm] = useState({
        employee_id: "",
        year: String(now.getFullYear()),
        month: String(now.getMonth() + 1),
    });

    const create = useMutation({
        mutationFn: (body) => api.post("/api/payrolls", body),
        onSuccess: (res) => {
            queryClient.invalidateQueries({ queryKey: ["payrolls"] });
            navigate(`/payroll/${res.data.data.id}`);
        },
    });

    const status = create.error?.response?.status;
    const errors =
        status === 422 ? (create.error.response.data.errors ?? {}) : {};
    const extra = Object.entries(errors)
        .filter(([key]) => !KNOWN_FIELDS.includes(key))
        .flatMap(([, messages]) => messages);
    const general =
        create.isError && status !== 422
            ? (create.error?.response?.data?.message ??
              "Gagal membuat payroll. Coba lagi.")
            : "";

    const err = (field) => errors[field]?.[0];
    const set = (field) => (e) =>
        setForm((previous) => ({ ...previous, [field]: e.target.value }));

    function submit(e) {
        e.preventDefault();

        if (!form.employee_id) {
            setMissing(true);
            return;
        }

        create.mutate({
            employee_id: Number(form.employee_id),
            year: Number(form.year),
            month: Number(form.month),
        });
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">Buat payroll</h2>

            {(general || extra.length > 0) && (
                <div
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {general && <p>{general}</p>}
                    {extra.map((message) => (
                        <p key={message}>{message}</p>
                    ))}
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <EmployeePicker
                    value={form.employee_id}
                    onChange={(id) => {
                        setForm((previous) => ({
                            ...previous,
                            employee_id: id,
                        }));
                        setMissing(false);
                    }}
                    error={
                        err("employee_id") ??
                        (missing ? "Wajib diisi." : undefined)
                    }
                />
                <Field label="Bulan" error={err("month")}>
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
                <Field label="Tahun" error={err("year")}>
                    <input
                        type="number"
                        min="2000"
                        max="2100"
                        className={inputClass}
                        value={form.year}
                        onChange={set("year")}
                    />
                </Field>
            </div>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={create.isPending}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {create.isPending ? "Membuat..." : "Buat"}
                </button>
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
                    Batal
                </button>
            </div>
        </form>
    );
}

export default function PayrollList() {
    const { user } = useAuth();
    const [params, setParams] = useSearchParams();
    const [showForm, setShowForm] = useState(false);

    const thisYear = new Date().getFullYear();
    const years = [thisYear - 2, thisYear - 1, thisYear, thisYear + 1];

    const page = Math.max(1, Number(params.get("page")) || 1);
    const year = params.get("year") ?? "";
    const month = params.get("month") ?? "";
    const status = params.get("status") ?? "";

    // Hanya soal tampilan: yang menentukan data tetap policy di backend.
    const isStaff = user.role === "hr" || user.role === "finance";
    const canCreate = user.role === "hr";

    function updateParams(changes) {
        setParams((previous) => {
            const next = new URLSearchParams(previous);

            for (const [key, value] of Object.entries(changes)) {
                value === null || value === ""
                    ? next.delete(key)
                    : next.set(key, value);
            }

            return next;
        });
    }

    const onFilter = (key) => (e) =>
        updateParams({ [key]: e.target.value, page: null });

    const { data, isLoading, isFetching, error, refetch } = useQuery({
        queryKey: ["payrolls", user.id, { year, month, status, page }],
        queryFn: async () =>
            (
                await api.get("/api/payrolls", {
                    params: {
                        year: year || undefined,
                        month: month || undefined,
                        status: status || undefined,
                        page,
                    },
                })
            ).data,
        placeholderData: keepPreviousData,
    });

    const rows = data?.data ?? [];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-xl font-semibold">
                    {isStaff ? "Payroll" : "Slip gaji"}
                </h1>
                {canCreate && !showForm && (
                    <button
                        onClick={() => setShowForm(true)}
                        className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                        Buat payroll
                    </button>
                )}
            </div>

            {showForm && canCreate && (
                <CreateForm onClose={() => setShowForm(false)} />
            )}

            <div className="flex flex-wrap items-end gap-3">
                <Field label="Tahun">
                    <select
                        className={inputClass}
                        value={year}
                        onChange={onFilter("year")}>
                        <option value="">Semua</option>
                        {years.map((value) => (
                            <option key={value} value={value}>
                                {value}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Bulan">
                    <select
                        className={inputClass}
                        value={month}
                        onChange={onFilter("month")}>
                        <option value="">Semua</option>
                        {MONTH_LABEL.map((name, index) => (
                            <option key={name} value={index + 1}>
                                {name}
                            </option>
                        ))}
                    </select>
                </Field>
                {isStaff && (
                    <Field label="Status">
                        <select
                            className={inputClass}
                            value={status}
                            onChange={onFilter("status")}>
                            <option value="">Semua</option>
                            {Object.entries(PAYROLL_STATUS_LABEL).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                )}
                {isFetching && (
                    <span className="pb-2 text-xs text-slate-500">
                        Memperbarui...
                    </span>
                )}
            </div>

            {isLoading ? (
                <Skeleton />
            ) : error && !data ? (
                <ErrorBox error={error} onRetry={refetch} />
            ) : (
                <>
                    {rows.length === 0 ? (
                        <Empty>
                            {isStaff
                                ? "Tidak ada payroll yang cocok."
                                : "Belum ada slip gaji yang sudah final."}
                        </Empty>
                    ) : (
                        <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">Periode</th>
                                        {isStaff && (
                                            <th className="px-3 py-2">
                                                Karyawan
                                            </th>
                                        )}
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2 text-right">
                                            Neto
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b border-slate-100 last:border-0">
                                            <td className="px-3 py-2">
                                                <Link
                                                    to={`/payroll/${row.id}`}
                                                    className="font-medium text-slate-900 underline">
                                                    {MONTH_LABEL[row.month - 1]}{" "}
                                                    {row.year}
                                                </Link>
                                                {row.adjusts_payroll_id && (
                                                    <span className="ml-2">
                                                        <Badge>
                                                            Penyesuaian
                                                        </Badge>
                                                    </span>
                                                )}
                                            </td>
                                            {isStaff && (
                                                <td className="px-3 py-2">
                                                    {row.employee?.full_name}
                                                    <span className="block text-xs text-slate-500">
                                                        {
                                                            row.employee
                                                                ?.employee_number
                                                        }
                                                    </span>
                                                </td>
                                            )}
                                            <td className="px-3 py-2">
                                                <Badge
                                                    tone={
                                                        PAYROLL_STATUS_TONE[
                                                            row.status
                                                        ] ?? "default"
                                                    }>
                                                    {PAYROLL_STATUS_LABEL[
                                                        row.status
                                                    ] ?? row.status}
                                                </Badge>
                                            </td>
                                            <td
                                                className={`px-3 py-2 text-right ${
                                                    row.totals?.net < 0
                                                        ? "text-red-600"
                                                        : ""
                                                }`}>
                                                {rupiah(row.totals?.net)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination
                        meta={data.meta}
                        onPage={(target) =>
                            updateParams({ page: target === 1 ? null : target })
                        }
                    />
                </>
            )}
        </div>
    );
}
