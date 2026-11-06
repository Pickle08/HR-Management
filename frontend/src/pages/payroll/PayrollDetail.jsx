import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useAuth } from "../../auth/useAuth";
import {
    Badge,
    Card,
    Empty,
    ErrorBox,
    Field,
    Skeleton,
    Stat,
    StatRow,
    errorMessage,
    inputClass,
} from "../../components/ui";
import { api } from "../../lib/api";
import {
    FINANCE_ITEM_TYPES,
    MONTH_LABEL,
    PAYROLL_DEDUCTION_TYPES,
    PAYROLL_ITEM_LABEL,
    PAYROLL_STATUS_LABEL,
    PAYROLL_STATUS_TONE,
} from "../../lib/labels";
import { rupiah } from "../../lib/money";

const ROW_ERROR = /^items\.\d+\./;
const ALL_TYPES = Object.keys(PAYROLL_ITEM_LABEL);

function validateRows(rows, adjustment) {
    const errors = {};
    let basicCount = 0;

    rows.forEach((row, index) => {
        const raw = String(row.amount).trim();
        const amount = Number(raw);

        if (raw === "" || !Number.isInteger(amount)) {
            errors[`items.${index}.amount`] = ["Isi dengan bilangan bulat."];
        } else if (adjustment && amount === 0) {
            errors[`items.${index}.amount`] = [
                "Nominal koreksi tidak boleh nol.",
            ];
        } else if (!adjustment && amount < 0) {
            errors[`items.${index}.amount`] = ["Nominal tidak boleh negatif."];
        }

        if (row.type === "basic_salary" && ++basicCount > 1) {
            errors[`items.${index}.type`] = ["Gaji pokok hanya boleh satu."];
        }
    });

    return errors;
}

const toItems = (rows) =>
    rows.map((row) => ({
        type: row.type,
        label: row.label.trim() || null,
        amount: Number(row.amount),
    }));

const extraErrors = (errors, ignore = []) =>
    Object.entries(errors)
        .filter(([key]) => !ROW_ERROR.test(key) && !ignore.includes(key))
        .flatMap(([, messages]) => messages);

function useErrors() {
    const [errors, setErrors] = useState({});

    const clear = (key) =>
        setErrors((previous) => {
            if (key === undefined) return {};
            if (!previous[key]) return previous;

            const next = { ...previous };
            delete next[key];

            return next;
        });

    return [errors, setErrors, clear];
}

function ErrorAlert({ general, extra }) {
    if (!general && extra.length === 0) return null;

    return (
        <div
            className="rounded bg-red-50 p-3 text-sm text-red-700"
            role="alert">
            {general && <p>{general}</p>}
            {extra.map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
    );
}

function RowsFields({ rows, setRows, types, errors, clear }) {
    function update(index, field) {
        return (e) => {
            const value = e.target.value;

            setRows((previous) =>
                previous.map((row, i) =>
                    i === index ? { ...row, [field]: value } : row,
                ),
            );
            clear(`items.${index}.${field}`);
        };
    }

    function remove(index) {
        setRows((previous) => previous.filter((_, i) => i !== index));
        clear();
    }

    function add() {
        setRows((previous) => [
            ...previous,
            { type: types[0], label: "", amount: "" },
        ]);
        clear();
    }

    return (
        <div className="space-y-2">
            {rows.length === 0 && (
                <p className="text-sm text-slate-500">Belum ada komponen.</p>
            )}

            {rows.map((row, index) => {
                const messages = ["type", "label", "amount"]
                    .map((field) => errors[`items.${index}.${field}`]?.[0])
                    .filter(Boolean);

                return (
                    <div key={index} className="space-y-1">
                        <div className="grid gap-2 sm:grid-cols-[10rem_1fr_9rem_auto] sm:items-center">
                            <select
                                aria-label={`Jenis komponen ${index + 1}`}
                                className={inputClass}
                                value={row.type}
                                onChange={update(index, "type")}>
                                {types.map((type) => (
                                    <option key={type} value={type}>
                                        {PAYROLL_ITEM_LABEL[type]}
                                    </option>
                                ))}
                            </select>
                            <input
                                aria-label={`Keterangan ${index + 1}`}
                                className={inputClass}
                                maxLength={100}
                                placeholder="Keterangan (opsional)"
                                value={row.label}
                                onChange={update(index, "label")}
                            />
                            <input
                                aria-label={`Nominal ${index + 1}`}
                                className={inputClass}
                                inputMode="numeric"
                                placeholder="Nominal"
                                value={row.amount}
                                onChange={update(index, "amount")}
                            />
                            <button
                                type="button"
                                onClick={() => remove(index)}
                                className="text-sm text-slate-600 underline">
                                Hapus
                            </button>
                        </div>
                        {messages.map((message) => (
                            <p key={message} className="text-xs text-red-600">
                                {message}
                            </p>
                        ))}
                    </div>
                );
            })}

            <button
                type="button"
                onClick={add}
                className="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm">
                Tambah komponen
            </button>
        </div>
    );
}

function EditItemsForm({ payroll, role, onClose }) {
    const queryClient = useQueryClient();
    const types = role === "finance" ? FINANCE_ITEM_TYPES : ALL_TYPES;
    const adjustment = payroll.revision_no > 0;
    const fixed = payroll.items.filter((item) => !types.includes(item.type));

    const [rows, setRows] = useState(() =>
        payroll.items
            .filter((item) => types.includes(item.type))
            .map((item) => ({
                type: item.type,
                label: item.label ?? "",
                amount: String(item.amount),
            })),
    );
    const [errors, setErrors, clear] = useErrors();

    const save = useMutation({
        mutationFn: (items) =>
            api.put(`/api/payrolls/${payroll.id}/items`, { items }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["payroll"] });
            queryClient.invalidateQueries({ queryKey: ["payrolls"] });
            onClose();
        },
        onError: (error) => {
            if (error?.response?.status === 422) {
                setErrors(error.response.data?.errors ?? {});
            }
        },
    });

    const general =
        save.isError && save.error?.response?.status !== 422
            ? errorMessage(save.error)
            : "";

    function submit(e) {
        e.preventDefault();

        const found = validateRows(rows, adjustment);
        setErrors(found);

        if (Object.keys(found).length > 0) return;

        save.mutate(toItems(rows));
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">Ubah komponen</h2>

            {role === "finance" && (
                <p className="text-xs text-slate-500">
                    Finance hanya mengubah BPJS dan PPh 21. Komponen lain diatur
                    HR.
                </p>
            )}
            {adjustment && (
                <p className="text-xs text-slate-500">
                    Ini koreksi: isi selisih terhadap payroll asli. Nominal
                    boleh negatif tapi tidak boleh nol.
                </p>
            )}

            <ErrorAlert general={general} extra={extraErrors(errors)} />

            {fixed.length > 0 && (
                <ul className="rounded bg-slate-50 p-3 text-sm text-slate-600">
                    {fixed.map((item) => (
                        <li key={item.id}>
                            {PAYROLL_ITEM_LABEL[item.type]}
                            {item.label ? ` (${item.label})` : ""}:{" "}
                            {rupiah(item.amount)}
                        </li>
                    ))}
                </ul>
            )}

            <RowsFields
                rows={rows}
                setRows={setRows}
                types={types}
                errors={errors}
                clear={clear}
            />

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={save.isPending}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {save.isPending ? "Menyimpan..." : "Simpan"}
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

function AdjustmentForm({ payroll, onClose }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [note, setNote] = useState("");
    const [rows, setRows] = useState([
        { type: ALL_TYPES[0], label: "", amount: "" },
    ]);
    const [errors, setErrors, clear] = useErrors();

    const save = useMutation({
        mutationFn: (body) =>
            api.post(`/api/payrolls/${payroll.id}/adjustments`, body),
        onSuccess: (res) => {
            queryClient.invalidateQueries({ queryKey: ["payrolls"] });
            navigate(`/payroll/${res.data.data.id}`);
        },
        onError: (error) => {
            if (error?.response?.status === 422) {
                setErrors(error.response.data?.errors ?? {});
            }
        },
    });

    const general =
        save.isError && save.error?.response?.status !== 422
            ? errorMessage(save.error)
            : "";

    function submit(e) {
        e.preventDefault();

        const found = validateRows(rows, true);

        if (note.trim() === "") found.note = ["Wajib diisi."];
        if (rows.length === 0) found.items = ["Minimal satu komponen."];

        setErrors(found);

        if (Object.keys(found).length > 0) return;

        save.mutate({ note: note.trim(), items: toItems(rows) });
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">Buat penyesuaian</h2>
            <p className="text-xs text-slate-500">
                Isi selisih terhadap payroll asli, bukan nominal baru. Nominal
                negatif mengurangi komponen itu.
            </p>

            <ErrorAlert
                general={general}
                extra={extraErrors(errors, ["note"])}
            />

            <Field label="Catatan" error={errors.note?.[0]}>
                <textarea
                    className={inputClass}
                    rows={2}
                    maxLength={500}
                    value={note}
                    onChange={(e) => {
                        setNote(e.target.value);
                        clear("note");
                    }}
                />
            </Field>

            <RowsFields
                rows={rows}
                setRows={setRows}
                types={ALL_TYPES}
                errors={errors}
                clear={clear}
            />

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={save.isPending}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {save.isPending ? "Membuat..." : "Buat penyesuaian"}
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

export default function PayrollDetail() {
    const { id } = useParams();
    const { user } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [panel, setPanel] = useState(null);
    const [actionError, setActionError] = useState("");

    const {
        data: payroll,
        isLoading,
        error,
        refetch,
    } = useQuery({
        queryKey: ["payroll", user.id, id],
        queryFn: async () => (await api.get(`/api/payrolls/${id}`)).data.data,
    });

    const finalize = useMutation({
        mutationFn: () => api.post(`/api/payrolls/${id}/finalize`),
        onSuccess: () => {
            setActionError("");
            queryClient.invalidateQueries({ queryKey: ["payroll"] });
            queryClient.invalidateQueries({ queryKey: ["payrolls"] });
        },
        onError: (err) => setActionError(errorMessage(err)),
    });

    const remove = useMutation({
        mutationFn: () => api.delete(`/api/payrolls/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["payrolls"] });
            navigate("/payroll");
        },
        onError: (err) => setActionError(errorMessage(err)),
    });

    if (isLoading) return <Skeleton />;
    if (error) return <ErrorBox error={error} onRetry={refetch} />;

    // Hanya soal tampilan: yang menolak tetap policy dan service di backend.
    const draft = payroll.status === "draft";
    const isStaff = user.role === "hr" || user.role === "finance";
    const canEdit = draft && isStaff;
    const canFinalize = draft && user.role === "finance";
    const canDelete = draft && user.role === "hr";
    const canAdjust = !draft && user.role === "hr" && payroll.revision_no === 0;

    const totals = payroll.totals;
    const title = `${MONTH_LABEL[payroll.month - 1]} ${payroll.year}`;
    const busy = finalize.isPending || remove.isPending;

    function handleFinalize() {
        if (
            window.confirm(
                "Finalisasi payroll ini? Setelah final, payroll terkunci dan tidak bisa diubah.",
            )
        ) {
            finalize.mutate();
        }
    }

    function handleDelete() {
        if (window.confirm("Hapus draf payroll ini?")) remove.mutate();
    }

    return (
        <div className="max-w-3xl space-y-4">
            <div>
                <Link
                    to="/payroll"
                    className="text-sm text-slate-500 underline">
                    ← Kembali
                </Link>
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-semibold">
                        {isStaff ? "Payroll" : "Slip gaji"} {title}
                    </h1>
                    <Badge
                        tone={PAYROLL_STATUS_TONE[payroll.status] ?? "default"}>
                        {PAYROLL_STATUS_LABEL[payroll.status] ?? payroll.status}
                    </Badge>
                    {payroll.revision_no > 0 && (
                        <Badge>Koreksi {payroll.revision_no}</Badge>
                    )}
                </div>
                {payroll.employee && (
                    <p className="text-sm text-slate-600">
                        {payroll.employee.full_name} ·{" "}
                        {payroll.employee.employee_number}
                    </p>
                )}
            </div>

            {payroll.adjusts_payroll_id && (
                <p className="text-sm text-slate-600">
                    Koreksi untuk{" "}
                    <Link
                        to={`/payroll/${payroll.adjusts_payroll_id}`}
                        className="underline">
                        payroll asli
                    </Link>
                    {payroll.note ? `: ${payroll.note}` : "."}
                </p>
            )}

            {actionError && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {actionError}
                </p>
            )}

            <Card>
                <StatRow>
                    <Stat label="Pendapatan" value={rupiah(totals.gross)} />
                    <Stat label="Potongan" value={rupiah(totals.deductions)} />
                    <Stat
                        label="Neto"
                        value={rupiah(totals.net)}
                        tone={totals.net < 0 ? "bad" : "default"}
                    />
                </StatRow>
            </Card>

            {!panel && (canEdit || canFinalize || canDelete || canAdjust) && (
                <div className="flex flex-wrap gap-2">
                    {canEdit && (
                        <button
                            onClick={() => setPanel("edit")}
                            className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                            Ubah komponen
                        </button>
                    )}
                    {canFinalize && (
                        <button
                            onClick={handleFinalize}
                            disabled={busy}
                            className="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50">
                            {finalize.isPending ? "Memproses..." : "Finalisasi"}
                        </button>
                    )}
                    {canAdjust && (
                        <button
                            onClick={() => setPanel("adjust")}
                            className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                            Buat penyesuaian
                        </button>
                    )}
                    {canDelete && (
                        <button
                            onClick={handleDelete}
                            disabled={busy}
                            className="rounded border border-slate-300 bg-white px-3 py-2 text-sm disabled:opacity-50">
                            Hapus draf
                        </button>
                    )}
                </div>
            )}

            {panel === "edit" && canEdit && (
                <EditItemsForm
                    payroll={payroll}
                    role={user.role}
                    onClose={() => setPanel(null)}
                />
            )}
            {panel === "adjust" && canAdjust && (
                <AdjustmentForm
                    payroll={payroll}
                    onClose={() => setPanel(null)}
                />
            )}

            {payroll.items.length === 0 ? (
                <Empty>Belum ada komponen gaji.</Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Komponen</th>
                                <th className="px-3 py-2">Keterangan</th>
                                <th className="px-3 py-2 text-right">
                                    Nominal
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {payroll.items.map((item) => (
                                <tr
                                    key={item.id}
                                    className="border-b border-slate-100 last:border-0">
                                    <td className="px-3 py-2">
                                        {PAYROLL_ITEM_LABEL[item.type] ??
                                            item.type}
                                    </td>
                                    <td className="px-3 py-2 text-slate-600">
                                        {item.label ?? "-"}
                                    </td>
                                    <td
                                        className={`px-3 py-2 text-right ${
                                            PAYROLL_DEDUCTION_TYPES.includes(
                                                item.type,
                                            )
                                                ? "text-red-700"
                                                : ""
                                        }`}>
                                        {rupiah(item.amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {payroll.finalization && (
                <p className="text-xs text-slate-500">
                    Difinalisasi
                    {payroll.finalization.by
                        ? ` oleh ${payroll.finalization.by.name}`
                        : ""}{" "}
                    pada{" "}
                    {new Date(payroll.finalization.at).toLocaleString("id-ID")}.
                </p>
            )}
        </div>
    );
}
