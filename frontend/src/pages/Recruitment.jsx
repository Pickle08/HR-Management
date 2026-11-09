import { useState } from "react";
import {
    keepPreviousData,
    useMutation,
    useQuery,
    useQueryClient,
} from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { useAuth } from "../auth/useAuth";
import {
    Badge,
    Empty,
    ErrorBox,
    Field,
    Pagination,
    Skeleton,
    inputClass,
} from "../components/ui";
import { api } from "../lib/api";
import { EMPLOYMENT_LABEL, STAGE_LABEL } from "../lib/labels";
import { nextStages } from "../lib/recruitment";

const blankToNull = (value) => (value === "" ? null : value);
const REQUIRED = ["Wajib diisi."];

// 422 -> pesan per field. Selain itu (409, 500, jaringan) -> satu pesan umum dari server.
function parseError(error) {
    const response = error?.response;

    if (response?.status === 422) {
        return { fields: response.data?.errors ?? {}, general: "" };
    }

    return {
        fields: {},
        general: response?.data?.message ?? "Gagal menyimpan. Coba lagi.",
    };
}

const NO_ERRORS = { fields: {}, general: "" };

function useOptions(key, url, params) {
    return useQuery({
        queryKey: ["options", key, params ?? null],
        queryFn: async () =>
            (await api.get(url, params && { params })).data.data,
        staleTime: 60_000,
    });
}

function Alert({ message }) {
    if (!message) return null;

    return (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
            {message}
        </p>
    );
}

function useInvalidate() {
    const queryClient = useQueryClient();

    return () => {
        queryClient.invalidateQueries({ queryKey: ["openings"] });
        queryClient.invalidateQueries({ queryKey: ["candidates"] });
        queryClient.invalidateQueries({ queryKey: ["candidate"] });
    };
}

function OpeningForm({ onClose }) {
    const invalidate = useInvalidate();
    const departments = useOptions("departments", "/api/departments");
    const positions = useOptions("positions", "/api/positions");
    const [form, setForm] = useState({
        title: "",
        department_id: "",
        position_id: "",
        headcount: "1",
        description: "",
    });
    const [errors, setErrors] = useState(NO_ERRORS);

    const save = useMutation({
        mutationFn: (body) => api.post("/api/job-openings", body),
        onSuccess: () => {
            invalidate();
            onClose();
        },
        onError: (error) => setErrors(parseError(error)),
    });

    const err = (field) => errors.fields[field]?.[0];
    const set = (field) => (e) => {
        const value = e.target.value;

        setForm((previous) => ({ ...previous, [field]: value }));
        setErrors((previous) => ({
            ...previous,
            fields: { ...previous.fields, [field]: undefined },
        }));
    };

    function submit(e) {
        e.preventDefault();

        const found = {};
        const headcount = Number(form.headcount);

        if (form.title.trim() === "") found.title = REQUIRED;
        if (form.department_id === "") found.department_id = REQUIRED;
        if (form.position_id === "") found.position_id = REQUIRED;
        if (!Number.isInteger(headcount) || headcount < 1 || headcount > 100) {
            found.headcount = ["Kuota harus 1 sampai 100."];
        }

        setErrors({ fields: found, general: "" });

        if (Object.keys(found).length > 0) return;

        save.mutate({
            title: form.title.trim(),
            department_id: Number(form.department_id),
            position_id: Number(form.position_id),
            headcount,
            description: blankToNull(form.description.trim()),
        });
    }

    return (
        <form
            onSubmit={submit}
            noValidate
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">Tambah lowongan</h2>
            <Alert message={errors.general} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Judul" error={err("title")}>
                    <input
                        className={inputClass}
                        maxLength={150}
                        value={form.title}
                        onChange={set("title")}
                    />
                </Field>
                <Field label="Kuota" error={err("headcount")}>
                    <input
                        className={inputClass}
                        inputMode="numeric"
                        value={form.headcount}
                        onChange={set("headcount")}
                    />
                </Field>
                <Field label="Departemen" error={err("department_id")}>
                    <select
                        className={inputClass}
                        value={form.department_id}
                        onChange={set("department_id")}>
                        <option value="">Pilih departemen</option>
                        {(departments.data ?? []).map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Jabatan" error={err("position_id")}>
                    <select
                        className={inputClass}
                        value={form.position_id}
                        onChange={set("position_id")}>
                        <option value="">Pilih jabatan</option>
                        {(positions.data ?? []).map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
            <Field label="Deskripsi" error={err("description")}>
                <textarea
                    className={inputClass}
                    rows={3}
                    maxLength={5000}
                    value={form.description}
                    onChange={set("description")}
                />
            </Field>
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

function OpeningsTab() {
    const { user } = useAuth();
    const invalidate = useInvalidate();
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const [showForm, setShowForm] = useState(false);
    const [actionError, setActionError] = useState("");

    const { data, isLoading, error, refetch } = useQuery({
        queryKey: ["openings", user.id, { status, page }],
        queryFn: async () =>
            (
                await api.get("/api/job-openings", {
                    params: { status: status || undefined, page },
                })
            ).data,
        placeholderData: keepPreviousData,
    });

    const close = useMutation({
        mutationFn: (id) => api.post(`/api/job-openings/${id}/close`),
        onSuccess: () => {
            setActionError("");
            invalidate();
        },
        onError: (err) => setActionError(parseError(err).general),
    });

    function handleClose(opening) {
        if (
            window.confirm(
                `Tutup lowongan "${opening.title}"? Lowongan yang ditutup tidak bisa dibuka lagi.`,
            )
        ) {
            close.mutate(opening.id);
        }
    }

    const rows = data?.data ?? [];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <Field label="Status">
                    <select
                        className={inputClass}
                        value={status}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            setPage(1);
                        }}>
                        <option value="">Semua</option>
                        <option value="open">Dibuka</option>
                        <option value="closed">Ditutup</option>
                    </select>
                </Field>
                {!showForm && (
                    <button
                        onClick={() => setShowForm(true)}
                        className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                        Tambah lowongan
                    </button>
                )}
            </div>

            {showForm && <OpeningForm onClose={() => setShowForm(false)} />}
            <Alert message={actionError} />

            {isLoading ? (
                <Skeleton />
            ) : error && !data ? (
                <ErrorBox error={error} onRetry={refetch} />
            ) : rows.length === 0 ? (
                <Empty>Belum ada lowongan.</Empty>
            ) : (
                <>
                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Lowongan</th>
                                    <th className="px-3 py-2">Terisi</th>
                                    <th className="px-3 py-2">Pelamar</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-slate-100 last:border-0">
                                        <td className="px-3 py-2">
                                            <span className="font-medium">
                                                {row.title}
                                            </span>
                                            <span className="block text-xs text-slate-500">
                                                {row.department?.name ?? "-"} ·{" "}
                                                {row.position?.name ?? "-"}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.hired_count}/{row.headcount}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.candidates_count}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Badge>
                                                {row.status === "open"
                                                    ? "Dibuka"
                                                    : "Ditutup"}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {row.status === "open" && (
                                                <button
                                                    onClick={() =>
                                                        handleClose(row)
                                                    }
                                                    disabled={close.isPending}
                                                    className="text-sm underline disabled:opacity-50">
                                                    Tutup lowongan
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination meta={data.meta} onPage={setPage} />
                </>
            )}
        </div>
    );
}

function CandidateForm({ onClose }) {
    const invalidate = useInvalidate();
    const openings = useOptions("open-openings", "/api/job-openings", {
        status: "open",
    });
    const [form, setForm] = useState({
        job_opening_id: "",
        full_name: "",
        email: "",
        phone: "",
    });
    const [errors, setErrors] = useState(NO_ERRORS);

    const save = useMutation({
        mutationFn: (body) => api.post("/api/candidates", body),
        onSuccess: () => {
            invalidate();
            onClose();
        },
        onError: (error) => setErrors(parseError(error)),
    });

    const err = (field) => errors.fields[field]?.[0];
    const set = (field) => (e) => {
        const value = e.target.value;

        setForm((previous) => ({ ...previous, [field]: value }));
        setErrors((previous) => ({
            ...previous,
            fields: { ...previous.fields, [field]: undefined },
        }));
    };

    function submit(e) {
        e.preventDefault();

        const found = {};

        if (form.job_opening_id === "") found.job_opening_id = REQUIRED;
        if (form.full_name.trim() === "") found.full_name = REQUIRED;
        if (form.email.trim() === "") found.email = REQUIRED;

        setErrors({ fields: found, general: "" });

        if (Object.keys(found).length > 0) return;

        save.mutate({
            job_opening_id: Number(form.job_opening_id),
            full_name: form.full_name.trim(),
            email: form.email.trim(),
            phone: blankToNull(form.phone.trim()),
        });
    }

    return (
        <form
            onSubmit={submit}
            noValidate
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">Tambah kandidat</h2>
            <Alert message={errors.general} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Lowongan" error={err("job_opening_id")}>
                    <select
                        className={inputClass}
                        value={form.job_opening_id}
                        onChange={set("job_opening_id")}>
                        <option value="">Pilih lowongan</option>
                        {(openings.data ?? []).map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.title}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Nama lengkap" error={err("full_name")}>
                    <input
                        className={inputClass}
                        maxLength={150}
                        value={form.full_name}
                        onChange={set("full_name")}
                    />
                </Field>
                <Field label="Email" error={err("email")}>
                    <input
                        type="email"
                        className={inputClass}
                        value={form.email}
                        onChange={set("email")}
                    />
                </Field>
                <Field label="Telepon" error={err("phone")}>
                    <input
                        className={inputClass}
                        inputMode="tel"
                        value={form.phone}
                        onChange={set("phone")}
                    />
                </Field>
            </div>
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

function ConvertForm({ candidate }) {
    const invalidate = useInvalidate();
    const managers = useOptions("managers", "/api/employees/managers");
    const [form, setForm] = useState({
        employee_number: "",
        hire_date: "",
        employment_status: "",
        manager_id: "",
        nik: "",
        birth_date: "",
        address: "",
    });
    const [errors, setErrors] = useState(NO_ERRORS);

    const convert = useMutation({
        mutationFn: (body) =>
            api.post(`/api/candidates/${candidate.id}/convert`, body),
        onSuccess: invalidate,
        onError: (error) => setErrors(parseError(error)),
    });

    // Kuota penuh datang sebagai error job_opening_id; tampilkan sebagai pesan umum.
    const general = errors.general || errors.fields.job_opening_id?.[0] || "";
    const err = (field) => errors.fields[field]?.[0];
    const set = (field) => (e) => {
        const value = e.target.value;

        setForm((previous) => ({ ...previous, [field]: value }));
        setErrors((previous) => ({
            ...previous,
            fields: { ...previous.fields, [field]: undefined },
        }));
    };

    function submit(e) {
        e.preventDefault();

        const found = {};

        if (form.employee_number.trim() === "")
            found.employee_number = REQUIRED;
        if (form.hire_date === "") found.hire_date = REQUIRED;
        if (form.employment_status === "") found.employment_status = REQUIRED;

        setErrors({ fields: found, general: "" });

        if (Object.keys(found).length > 0) return;

        convert.mutate({
            employee_number: form.employee_number.trim(),
            hire_date: form.hire_date,
            employment_status: form.employment_status,
            manager_id: form.manager_id === "" ? null : Number(form.manager_id),
            nik: blankToNull(form.nik.trim()),
            birth_date: blankToNull(form.birth_date),
            address: blankToNull(form.address.trim()),
        });
    }

    return (
        <form
            onSubmit={submit}
            noValidate
            className="space-y-3 rounded border border-slate-200 p-3">
            <h3 className="text-sm font-semibold">Konversi menjadi karyawan</h3>
            <p className="text-xs text-slate-500">
                Nama dan telepon diambil dari kandidat. Departemen dan jabatan
                mengikuti lowongan.
            </p>
            <Alert message={general} />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Nomor karyawan" error={err("employee_number")}>
                    <input
                        className={inputClass}
                        maxLength={20}
                        value={form.employee_number}
                        onChange={set("employee_number")}
                    />
                </Field>
                <Field label="Tanggal masuk" error={err("hire_date")}>
                    <input
                        type="date"
                        className={inputClass}
                        value={form.hire_date}
                        onChange={set("hire_date")}
                    />
                </Field>
                <Field label="Status kerja" error={err("employment_status")}>
                    <select
                        className={inputClass}
                        value={form.employment_status}
                        onChange={set("employment_status")}>
                        <option value="">Pilih status kerja</option>
                        {Object.entries(EMPLOYMENT_LABEL).map(
                            ([value, text]) => (
                                <option key={value} value={value}>
                                    {text}
                                </option>
                            ),
                        )}
                    </select>
                </Field>
                <Field label="Atasan" error={err("manager_id")}>
                    <select
                        className={inputClass}
                        value={form.manager_id}
                        onChange={set("manager_id")}>
                        <option value="">Tanpa atasan</option>
                        {(managers.data ?? []).map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.full_name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="NIK" error={err("nik")} hint="16 digit.">
                    <input
                        className={inputClass}
                        inputMode="numeric"
                        maxLength={16}
                        value={form.nik}
                        onChange={set("nik")}
                    />
                </Field>
                <Field label="Tanggal lahir" error={err("birth_date")}>
                    <input
                        type="date"
                        className={inputClass}
                        value={form.birth_date}
                        onChange={set("birth_date")}
                    />
                </Field>
            </div>
            <Field label="Alamat" error={err("address")}>
                <textarea
                    className={inputClass}
                    rows={2}
                    maxLength={500}
                    value={form.address}
                    onChange={set("address")}
                />
            </Field>
            <button
                type="submit"
                disabled={convert.isPending}
                className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                {convert.isPending ? "Memproses..." : "Konversi"}
            </button>
        </form>
    );
}

function CandidateDetail({ id, onClose }) {
    const { user } = useAuth();
    const invalidate = useInvalidate();
    const [rejecting, setRejecting] = useState(false);
    const [note, setNote] = useState("");
    const [noteError, setNoteError] = useState("");
    const [moveError, setMoveError] = useState("");

    const {
        data: candidate,
        isLoading,
        error,
        refetch,
    } = useQuery({
        queryKey: ["candidate", user.id, id],
        queryFn: async () => (await api.get(`/api/candidates/${id}`)).data.data,
    });

    const move = useMutation({
        mutationFn: (body) => api.post(`/api/candidates/${id}/move`, body),
        onSuccess: () => {
            setRejecting(false);
            setNote("");
            setMoveError("");
            invalidate();
        },
        onError: (err) => {
            const parsed = parseError(err);

            setMoveError(
                parsed.general || Object.values(parsed.fields).flat().join(" "),
            );
        },
    });

    if (isLoading) return <Skeleton />;
    if (error) return <ErrorBox error={error} onRetry={refetch} />;

    const targets = nextStages(candidate.stage).filter((s) => s !== "rejected");
    const canReject = nextStages(candidate.stage).includes("rejected");
    const canConvert =
        candidate.stage === "accepted" && !candidate.converted_employee_id;

    function reject(e) {
        e.preventDefault();

        if (note.trim() === "") {
            setNoteError("Wajib diisi.");
            return;
        }

        setNoteError("");
        move.mutate({ to_stage: "rejected", note: note.trim() });
    }

    return (
        <div className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 className="text-base font-semibold">Detail kandidat</h2>
                    <p className="font-medium">{candidate.full_name}</p>
                    <p className="text-sm text-slate-600">
                        {candidate.email} · {candidate.phone ?? "-"}
                    </p>
                    <p className="text-sm text-slate-600">
                        {candidate.job_opening?.title ?? "-"}
                    </p>
                </div>
                <button onClick={onClose} className="text-sm underline">
                    Tutup detail
                </button>
            </div>

            <Alert message={moveError} />

            <div className="flex flex-wrap gap-2">
                {targets.map((stage) => (
                    <button
                        key={stage}
                        disabled={move.isPending}
                        onClick={() => move.mutate({ to_stage: stage })}
                        className="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50">
                        {`Pindahkan ke ${STAGE_LABEL[stage] ?? stage}`}
                    </button>
                ))}
                {canReject && !rejecting && (
                    <button
                        onClick={() => setRejecting(true)}
                        className="rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                        Tolak
                    </button>
                )}
            </div>

            {rejecting && (
                <form onSubmit={reject} noValidate className="space-y-2">
                    <Field label="Alasan penolakan" error={noteError}>
                        <textarea
                            className={inputClass}
                            rows={2}
                            maxLength={500}
                            value={note}
                            onChange={(e) => {
                                setNote(e.target.value);
                                setNoteError("");
                            }}
                        />
                    </Field>
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={move.isPending}
                            className="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50">
                            Konfirmasi tolak
                        </button>
                        <button
                            type="button"
                            onClick={() => setRejecting(false)}
                            className="rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                            Batal
                        </button>
                    </div>
                </form>
            )}

            {candidate.converted_employee_id && (
                <p className="text-sm">
                    Sudah dikonversi menjadi{" "}
                    <Link
                        to={`/employees/${candidate.converted_employee_id}`}
                        className="underline">
                        karyawan
                    </Link>
                    .
                </p>
            )}
            {canConvert && <ConvertForm candidate={candidate} />}

            <div>
                <h3 className="mb-1 text-sm font-semibold">Riwayat tahap</h3>
                <ul className="divide-y divide-slate-100 text-sm">
                    {(candidate.history ?? []).map((h, index) => (
                        <li key={index} className="py-1.5">
                            {h.from_stage
                                ? (STAGE_LABEL[h.from_stage] ?? h.from_stage)
                                : "-"}{" "}
                            → {STAGE_LABEL[h.to_stage] ?? h.to_stage}
                            <span className="block text-xs text-slate-500">
                                {h.by?.name ?? "-"} ·{" "}
                                {new Date(h.at).toLocaleString("id-ID")}
                                {h.note ? ` · ${h.note}` : ""}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function CandidatesTab() {
    const { user } = useAuth();
    const [filters, setFilters] = useState({
        stage: "",
        job_opening_id: "",
        search: "",
    });
    const [page, setPage] = useState(1);
    const [showForm, setShowForm] = useState(false);
    const [selected, setSelected] = useState(null);
    const allOpenings = useOptions("all-openings", "/api/job-openings");

    const { data, isLoading, error, refetch } = useQuery({
        queryKey: ["candidates", user.id, { ...filters, page }],
        queryFn: async () =>
            (
                await api.get("/api/candidates", {
                    params: {
                        stage: filters.stage || undefined,
                        job_opening_id: filters.job_opening_id || undefined,
                        search: filters.search || undefined,
                        page,
                    },
                })
            ).data,
        placeholderData: keepPreviousData,
    });

    const onFilter = (field) => (e) => {
        setFilters((previous) => ({ ...previous, [field]: e.target.value }));
        setPage(1);
    };

    const rows = data?.data ?? [];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="Cari">
                        <input
                            className={inputClass}
                            maxLength={100}
                            placeholder="Nama atau email"
                            value={filters.search}
                            onChange={onFilter("search")}
                        />
                    </Field>
                    <Field label="Tahap">
                        <select
                            className={inputClass}
                            value={filters.stage}
                            onChange={onFilter("stage")}>
                            <option value="">Semua</option>
                            {Object.entries(STAGE_LABEL).map(
                                ([value, text]) => (
                                    <option key={value} value={value}>
                                        {text}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field label="Filter lowongan">
                        <select
                            className={inputClass}
                            value={filters.job_opening_id}
                            onChange={onFilter("job_opening_id")}>
                            <option value="">Semua</option>
                            {(allOpenings.data ?? []).map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.title}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                {!showForm && (
                    <button
                        onClick={() => setShowForm(true)}
                        className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                        Tambah kandidat
                    </button>
                )}
            </div>

            {showForm && <CandidateForm onClose={() => setShowForm(false)} />}

            {selected && (
                <CandidateDetail
                    key={selected}
                    id={selected}
                    onClose={() => setSelected(null)}
                />
            )}

            {isLoading ? (
                <Skeleton />
            ) : error && !data ? (
                <ErrorBox error={error} onRetry={refetch} />
            ) : rows.length === 0 ? (
                <Empty>Tidak ada kandidat yang cocok.</Empty>
            ) : (
                <>
                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">Kandidat</th>
                                    <th className="px-3 py-2">Lowongan</th>
                                    <th className="px-3 py-2">Tahap</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-slate-100 last:border-0">
                                        <td className="px-3 py-2">
                                            <span className="font-medium">
                                                {row.full_name}
                                            </span>
                                            <span className="block text-xs text-slate-500">
                                                {row.email}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.job_opening?.title ?? "-"}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Badge>
                                                {STAGE_LABEL[row.stage] ??
                                                    row.stage}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                onClick={() =>
                                                    setSelected(row.id)
                                                }
                                                className="text-sm underline">
                                                Detail
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination meta={data.meta} onPage={setPage} />
                </>
            )}
        </div>
    );
}

export default function Recruitment() {
    const [tab, setTab] = useState("openings");
    const tabs = [
        { key: "openings", label: "Lowongan" },
        { key: "candidates", label: "Kandidat" },
    ];

    return (
        <div className="space-y-4">
            <h1 className="text-xl font-semibold">Rekrutmen</h1>

            <div
                role="tablist"
                className="flex gap-2 border-b border-slate-200">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        role="tab"
                        aria-selected={tab === t.key}
                        onClick={() => setTab(t.key)}
                        className={`px-3 py-2 text-sm ${
                            tab === t.key
                                ? "border-b-2 border-slate-900 font-medium"
                                : "text-slate-500"
                        }`}>
                        {t.label}
                    </button>
                ))}
            </div>

            {tab === "openings" ? <OpeningsTab /> : <CandidatesTab />}
        </div>
    );
}
