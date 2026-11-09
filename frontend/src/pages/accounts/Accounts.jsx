import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
    Badge,
    Dialog,
    Empty,
    ErrorBox,
    Field,
    Pagination,
    Skeleton,
    errorMessage,
    inputClass,
} from "../../components/ui";
import { useAuth } from "../../auth/useAuth";
import { api } from "../../lib/api";
import { useAccounts } from "../../lib/accounts";

const ROLES = [
    { value: "super_admin", label: "Super Admin" },
    { value: "atasan", label: "Atasan" },
    { value: "hr", label: "HR" },
    { value: "finance", label: "Finance" },
    { value: "karyawan", label: "Karyawan" },
];
const roleLabel = (value) =>
    ROLES.find((r) => r.value === value)?.label ?? value;

function RoleSelect({ value, onChange, id, options = ROLES }) {
    return (
        <select
            id={id}
            className={inputClass}
            value={value}
            onChange={(e) => onChange(e.target.value)}>
            {options.map((r) => (
                <option key={r.value} value={r.value}>
                    {r.label}
                </option>
            ))}
        </select>
    );
}

function EmployeePicker({ value, onChange, error, required }) {
    const { user } = useAuth();
    const [input, setInput] = useState("");
    const [search, setSearch] = useState("");

    useEffect(() => {
        const timer = setTimeout(() => setSearch(input.trim()), 300);
        return () => clearTimeout(timer);
    }, [input]);

    const { data, isFetching } = useQuery({
        queryKey: ["account-employee-options", user.id, search],
        queryFn: async () =>
            (
                await api.get("/api/employees", {
                    params: { search: search || undefined },
                })
            ).data.data,
        staleTime: 30_000,
        enabled: !value,
    });

    const options = (data ?? [])
        .filter((e) => e.is_active !== false)
        .slice(0, 8);

    if (value) {
        return (
            <Field label="Karyawan" error={error}>
                <div className="mt-1 flex items-center justify-between rounded border border-slate-300 bg-slate-50 px-3 py-2 text-sm">
                    <span>
                        {value.full_name}{" "}
                        <span className="text-slate-500">
                            ({value.employee_number})
                        </span>
                    </span>
                    <button
                        type="button"
                        onClick={() => onChange(null)}
                        className="underline">
                        Ganti
                    </button>
                </div>
            </Field>
        );
    }

    return (
        <Field
            label={required ? "Karyawan" : "Karyawan (opsional)"}
            error={error}
            hint={
                required
                    ? "Wajib untuk semua role selain Super Admin."
                    : "Kosongkan untuk Super Admin tanpa data karyawan."
            }>
            <input
                type="search"
                className={inputClass}
                placeholder="Cari nama atau nomor karyawan"
                value={input}
                onChange={(e) => setInput(e.target.value)}
            />
            {isFetching && (
                <p className="mt-1 text-xs text-slate-500">Mencari...</p>
            )}
            {!isFetching && options.length === 0 && (
                <p className="mt-1 text-xs text-slate-500">
                    Tidak ada karyawan ditemukan.
                </p>
            )}
            {options.length > 0 && (
                <ul className="mt-1 max-h-48 overflow-y-auto rounded border border-slate-200 bg-white text-sm">
                    {options.map((e) => (
                        <li key={e.id}>
                            <button
                                type="button"
                                onClick={() => onChange(e)}
                                className="block w-full px-3 py-2 text-left hover:bg-slate-50">
                                {e.full_name}{" "}
                                <span className="text-slate-500">
                                    ({e.employee_number})
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Field>
    );
}

function CreateForm({ onCreated, onClose }) {
    const queryClient = useQueryClient();
    const [form, setForm] = useState({
        name: "",
        email: "",
        role: "karyawan",
        employee: null,
    });

    const isSuperAdmin = form.role === "super_admin";

    const save = useMutation({
        mutationFn: (body) => api.post("/api/accounts", body),
        onSuccess: (res) => {
            queryClient.invalidateQueries({ queryKey: ["accounts"] });
            onCreated(res.data.data, res.data.meta.temporary_password);
        },
    });

    const validation = save.error?.response?.status === 422;
    const errors = validation ? (save.error.response.data.errors ?? {}) : {};
    const general = save.isError && !validation ? errorMessage(save.error) : "";
    const err = (field) => errors[field]?.[0];

    function submit(e) {
        e.preventDefault();

        const body = { email: form.email.trim(), role: form.role };
        if (form.employee) body.employee_id = form.employee.id;
        else body.name = form.name.trim();

        save.mutate(body);
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">Tambah akun</h2>

            {general && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {general}
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Role" error={err("role")}>
                    <RoleSelect
                        value={form.role}
                        onChange={(role) => setForm({ ...form, role })}
                    />
                </Field>
                <Field label="Email" error={err("email")}>
                    <input
                        type="email"
                        className={inputClass}
                        value={form.email}
                        onChange={(e) =>
                            setForm({ ...form, email: e.target.value })
                        }
                        required
                    />
                </Field>
            </div>

            <EmployeePicker
                value={form.employee}
                onChange={(employee) => setForm({ ...form, employee })}
                error={err("employee_id")}
                required={!isSuperAdmin}
            />

            {isSuperAdmin && !form.employee && (
                <Field label="Nama" error={err("name")}>
                    <input
                        className={inputClass}
                        maxLength={150}
                        value={form.name}
                        onChange={(e) =>
                            setForm({ ...form, name: e.target.value })
                        }
                        required
                    />
                </Field>
            )}

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={
                        save.isPending || (!isSuperAdmin && !form.employee)
                    }
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

function RoleDialog({ account, onClose }) {
    const options = account.employee
        ? ROLES
        : ROLES.filter((r) => r.value === "super_admin");
    const queryClient = useQueryClient();
    const [role, setRole] = useState(account.role);

    const change = useMutation({
        mutationFn: () => api.put(`/api/accounts/${account.id}/role`, { role }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["accounts"] });
            onClose();
        },
    });

    const validation = change.error?.response?.status === 422;
    const roleError = validation
        ? change.error.response.data.errors?.role?.[0]
        : undefined;

    return (
        <Dialog title="Ubah role" onClose={onClose}>
            <p className="text-sm">
                Akun <strong>{account.name}</strong>
            </p>

            <Field label="Role" error={roleError}>
                <RoleSelect value={role} onChange={setRole} options={options} />
            </Field>

            {change.isError && !roleError && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {errorMessage(change.error)}
                </p>
            )}

            <div className="flex gap-2">
                <button
                    onClick={() => change.mutate()}
                    disabled={change.isPending || role === account.role}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {change.isPending ? "Menyimpan..." : "Simpan"}
                </button>
                <button
                    onClick={onClose}
                    className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
                    Batal
                </button>
            </div>
        </Dialog>
    );
}

// Untuk kunci, buka kunci, dan reset password: satu POST tanpa body.
function ActionDialog({ account, action, onClose, onPassword }) {
    const queryClient = useQueryClient();

    const copy = {
        lock: {
            title: "Kunci akun",
            text: "tidak akan bisa login sampai dibuka kuncinya.",
            button: "Kunci",
        },
        unlock: {
            title: "Buka kunci akun",
            text: "akan bisa login kembali.",
            button: "Buka kunci",
        },
        "reset-password": {
            title: "Reset password",
            text: "akan mendapat password sementara. Password lama tidak berlaku lagi.",
            button: "Reset password",
        },
    }[action];

    const run = useMutation({
        mutationFn: () => api.post(`/api/accounts/${account.id}/${action}`),
        onSuccess: (res) => {
            queryClient.invalidateQueries({ queryKey: ["accounts"] });
            if (action === "reset-password")
                onPassword(account, res.data.meta.temporary_password);
            else onClose();
        },
    });

    return (
        <Dialog title={copy.title} onClose={onClose}>
            <p className="text-sm">
                <strong>{account.name}</strong> {copy.text}
            </p>

            {run.isError && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {errorMessage(run.error)}
                </p>
            )}

            <div className="flex gap-2">
                <button
                    onClick={() => run.mutate()}
                    disabled={run.isPending}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {run.isPending ? "Memproses..." : copy.button}
                </button>
                <button
                    onClick={onClose}
                    className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
                    Batal
                </button>
            </div>
        </Dialog>
    );
}

// Password sementara hanya hidup di state komponen ini, tidak masuk cache query.
function PasswordDialog({ account, password, onClose }) {
    const [copied, setCopied] = useState(false);

    async function copy() {
        try {
            await navigator.clipboard.writeText(password);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    return (
        <Dialog title="Password sementara" onClose={onClose}>
            <p className="text-sm">
                Untuk <strong>{account.email}</strong>. Password ini hanya
                ditampilkan sekarang, jadi catat atau salin sebelum menutup
                dialog.
            </p>
            <p
                className="rounded bg-slate-100 p-3 text-center font-mono text-base select-all"
                data-testid="temporary-password">
                {password}
            </p>
            <div className="flex gap-2">
                <button
                    onClick={copy}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white">
                    {copied ? "Tersalin" : "Salin"}
                </button>
                <button
                    onClick={onClose}
                    className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
                    Tutup
                </button>
            </div>
        </Dialog>
    );
}

export default function Accounts() {
    const { user } = useAuth();
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState("");
    const [search, setSearch] = useState("");
    const [role, setRole] = useState("");
    const [creating, setCreating] = useState(false);
    const [dialog, setDialog] = useState(null); // { type, account, password? }

    const { data, isLoading, error, refetch } = useAccounts({
        page,
        search,
        role,
    });

    useEffect(() => {
        const timer = setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, [searchInput]);

    if (isLoading) return <Skeleton />;
    if (error) return <ErrorBox error={error} onRetry={refetch} />;

    const rows = data.data;
    const showPassword = (account, password) =>
        setDialog({ type: "password", account, password });

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-1 flex-wrap gap-2">
                    <input
                        type="search"
                        className="w-full max-w-xs rounded border border-slate-300 bg-white px-3 py-2 text-sm"
                        placeholder="Cari nama atau email"
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                    />
                    <select
                        aria-label="Filter role"
                        className="rounded border border-slate-300 bg-white px-3 py-2 text-sm"
                        value={role}
                        onChange={(e) => {
                            setRole(e.target.value);
                            setPage(1);
                        }}>
                        <option value="">Semua role</option>
                        {ROLES.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </select>
                </div>
                {!creating && (
                    <button
                        onClick={() => setCreating(true)}
                        className="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm text-white">
                        Tambah akun
                    </button>
                )}
            </div>

            {creating && (
                <CreateForm
                    onClose={() => setCreating(false)}
                    onCreated={(account, password) => {
                        setCreating(false);
                        showPassword(account, password);
                    }}
                />
            )}

            {rows.length === 0 ? (
                <Empty>
                    {search || role ? "Tidak ada hasil." : "Belum ada akun."}
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Nama</th>
                                <th className="px-3 py-2">Email</th>
                                <th className="px-3 py-2">Role</th>
                                <th className="px-3 py-2">Karyawan</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((account) => {
                                const self = account.id === user.id;
                                return (
                                    <tr
                                        key={account.id}
                                        className="border-b border-slate-100 last:border-0">
                                        <td className="px-3 py-2">
                                            {account.name}
                                            {self && (
                                                <span className="ml-1 text-xs text-slate-500">
                                                    (Anda)
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.email}
                                        </td>
                                        <td className="px-3 py-2">
                                            {roleLabel(account.role)}
                                        </td>
                                        <td className="px-3 py-2">
                                            {account.employee
                                                ?.employee_number ?? "-"}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Badge
                                                tone={
                                                    account.is_active
                                                        ? "good"
                                                        : "bad"
                                                }>
                                                {account.is_active
                                                    ? "Aktif"
                                                    : "Terkunci"}
                                            </Badge>
                                        </td>
                                        <td className="space-x-3 px-3 py-2 text-right">
                                            {!self && (
                                                <>
                                                    <button
                                                        onClick={() =>
                                                            setDialog({
                                                                type: "role",
                                                                account,
                                                            })
                                                        }
                                                        className="underline">
                                                        Role
                                                    </button>
                                                    {account.is_active ? (
                                                        <button
                                                            onClick={() =>
                                                                setDialog({
                                                                    type: "lock",
                                                                    account,
                                                                })
                                                            }
                                                            className="underline">
                                                            Kunci
                                                        </button>
                                                    ) : (
                                                        <button
                                                            onClick={() =>
                                                                setDialog({
                                                                    type: "unlock",
                                                                    account,
                                                                })
                                                            }
                                                            className="underline">
                                                            Buka kunci
                                                        </button>
                                                    )}
                                                    <button
                                                        onClick={() =>
                                                            setDialog({
                                                                type: "reset-password",
                                                                account,
                                                            })
                                                        }
                                                        className="underline">
                                                        Reset password
                                                    </button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <Pagination meta={data.meta} onPage={setPage} />

            {dialog?.type === "role" && (
                <RoleDialog
                    account={dialog.account}
                    onClose={() => setDialog(null)}
                />
            )}
            {["lock", "unlock", "reset-password"].includes(dialog?.type) && (
                <ActionDialog
                    account={dialog.account}
                    action={dialog.type}
                    onClose={() => setDialog(null)}
                    onPassword={showPassword}
                />
            )}
            {dialog?.type === "password" && (
                <PasswordDialog
                    account={dialog.account}
                    password={dialog.password}
                    onClose={() => setDialog(null)}
                />
            )}
        </div>
    );
}
