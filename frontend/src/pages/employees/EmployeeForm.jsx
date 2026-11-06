import { useMemo, useState } from "react";
import { Link, Navigate, useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "../../auth/useAuth";
import RegionPicker from "../../components/RegionPicker";
import { ErrorBox, Field, Skeleton, inputClass } from "../../components/ui";
import { api } from "../../lib/api";
import { EMPLOYMENT_LABEL } from "../../lib/labels";
import { isHrAdmin } from "../../lib/roles";

const BLANK = {
    employee_number: "",
    full_name: "",
    department_id: "",
    position_id: "",
    manager_id: "",
    employment_status: "",
    hire_date: "",
    end_date: "",
    nik: "",
    phone: "",
    birth_date: "",
    address: "",
    province_code: "",
    regency_code: "",
    district_code: "",
    village_code: "",
};

const REQUIRED = [
    "employee_number",
    "full_name",
    "department_id",
    "position_id",
    "employment_status",
    "hire_date",
];

const REGION_FIELDS = [
    "province_code",
    "regency_code",
    "district_code",
    "village_code",
];

// Kode wilayah berjenjang: 32.73.01.1002 -> 32 / 32.73 / 32.73.01 / 32.73.01.1002.
function regionFromVillage(village) {
    if (!village) return {};

    const parts = village.split(".");

    return {
        province_code: parts[0],
        regency_code: parts.slice(0, 2).join("."),
        district_code: parts.slice(0, 3).join("."),
        village_code: village,
    };
}

function toForm(employee) {
    if (!employee) return BLANK;

    return {
        ...Object.fromEntries(
            Object.keys(BLANK).map((key) => [key, employee[key] ?? ""]),
        ),
        department_id: employee.department?.id ?? "",
        position_id: employee.position?.id ?? "",
        manager_id: employee.manager_id ?? "",
        ...regionFromVillage(employee.village_code),
    };
}

const blankToNull = (value) => (value === "" ? null : value);
const idOrNull = (value) => (value === "" ? null : Number(value));

function toPayload(form) {
    return {
        employee_number: form.employee_number.trim(),
        full_name: form.full_name.trim(),
        department_id: idOrNull(form.department_id),
        position_id: idOrNull(form.position_id),
        manager_id: idOrNull(form.manager_id),
        employment_status: form.employment_status,
        hire_date: form.hire_date,
        end_date: blankToNull(form.end_date),
        nik: blankToNull(form.nik.trim()),
        phone: blankToNull(form.phone.trim()),
        birth_date: blankToNull(form.birth_date),
        address: blankToNull(form.address.trim()),
        province_code: blankToNull(form.province_code),
        regency_code: blankToNull(form.regency_code),
        district_code: blankToNull(form.district_code),
        village_code: blankToNull(form.village_code),
    };
}

function validate(form) {
    const errors = {};

    for (const field of REQUIRED) {
        if (String(form[field] ?? "").trim() === "") {
            errors[field] = ["Wajib diisi."];
        }
    }

    const filled = REGION_FIELDS.filter((field) => form[field]).length;

    if (filled > 0 && filled < REGION_FIELDS.length) {
        errors.region = [
            "Lengkapi seluruh tingkat wilayah, atau kosongkan semuanya.",
        ];
    }

    return errors;
}

function useOptions(key, url) {
    return useQuery({
        queryKey: ["options", key],
        queryFn: async () => (await api.get(url)).data.data,
        staleTime: 60_000,
    });
}

function FormBody({ employee }) {
    const editing = Boolean(employee);
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(() => toForm(employee));
    const [errors, setErrors] = useState({});

    const departments = useOptions("departments", "/api/departments");
    const positions = useOptions("positions", "/api/positions");
    const managers = useOptions("managers", "/api/employees/managers");

    // Atasan saat ini tetap ditampilkan walau sudah tidak valid, supaya terlihat dan bisa diganti.
    const managerOptions = useMemo(() => {
        const list = managers.data ?? [];
        const current = employee?.manager;

        if (current && !list.some((m) => m.id === current.id)) {
            return [
                {
                    id: current.id,
                    full_name: `${current.full_name} (tidak valid lagi)`,
                },
                ...list,
            ];
        }

        return list;
    }, [managers.data, employee]);

    const save = useMutation({
        mutationFn: (payload) =>
            editing
                ? api.put(`/api/employees/${employee.id}`, payload)
                : api.post("/api/employees", payload),
        onSuccess: (res) => {
            queryClient.invalidateQueries({ queryKey: ["employees"] });
            queryClient.invalidateQueries({ queryKey: ["employee"] });
            navigate(`/employees/${res.data.data.id}`);
        },
        onError: (error) => {
            if (error?.response?.status === 422) {
                setErrors(error.response.data?.errors ?? {});
            }
        },
    });

    const generalError =
        save.isError && save.error?.response?.status !== 422
            ? (save.error?.response?.data?.message ??
              "Gagal menyimpan. Coba lagi.")
            : "";

    const clearErrors = (...fields) =>
        setErrors((previous) => {
            if (!fields.some((field) => previous[field])) return previous;

            const next = { ...previous };
            fields.forEach((field) => delete next[field]);

            return next;
        });

    const set = (field) => (e) => {
        const value = e.target.value;

        setForm((previous) => ({ ...previous, [field]: value }));
        clearErrors(field);
    };

    const err = (field) => errors[field]?.[0];

    const region = {
        province_code: form.province_code,
        regency_code: form.regency_code,
        district_code: form.district_code,
        village_code: form.village_code,
    };

    function handleSubmit(e) {
        e.preventDefault();

        const found = validate(form);
        setErrors(found);

        if (Object.keys(found).length > 0) return;

        save.mutate(toPayload(form));
    }

    const optionError =
        departments.isError || positions.isError || managers.isError;

    return (
        <form
            onSubmit={handleSubmit}
            noValidate
            className="max-w-3xl space-y-6">
            <div>
                <Link
                    to={editing ? `/employees/${employee.id}` : "/employees"}
                    className="text-sm text-slate-500 underline">
                    ← Kembali
                </Link>
                <h1 className="text-xl font-semibold">
                    {editing ? "Ubah karyawan" : "Tambah karyawan"}
                </h1>
            </div>

            {optionError && (
                <p
                    className="rounded bg-amber-50 p-3 text-sm text-amber-800"
                    role="alert">
                    Sebagian pilihan gagal dimuat. Muat ulang halaman sebelum
                    menyimpan.
                </p>
            )}
            {generalError && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {generalError}
                </p>
            )}
            {Object.keys(errors).length > 0 && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    Periksa isian yang ditandai.
                </p>
            )}

            <section className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Data kerja
                </h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        label="Nomor karyawan"
                        error={err("employee_number")}>
                        <input
                            className={inputClass}
                            value={form.employee_number}
                            onChange={set("employee_number")}
                            maxLength={20}
                        />
                    </Field>
                    <Field label="Nama lengkap" error={err("full_name")}>
                        <input
                            className={inputClass}
                            value={form.full_name}
                            onChange={set("full_name")}
                            maxLength={150}
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
                    <Field
                        label="Atasan"
                        error={err("manager_id")}
                        hint="Hanya karyawan aktif dengan akun Atasan yang aktif.">
                        <select
                            className={inputClass}
                            value={form.manager_id}
                            onChange={set("manager_id")}>
                            <option value="">Tanpa atasan</option>
                            {managerOptions.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.full_name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field
                        label="Status kerja"
                        error={err("employment_status")}>
                        <select
                            className={inputClass}
                            value={form.employment_status}
                            onChange={set("employment_status")}>
                            <option value="">Pilih status kerja</option>
                            {Object.entries(EMPLOYMENT_LABEL).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                    <Field label="Tanggal masuk" error={err("hire_date")}>
                        <input
                            type="date"
                            className={inputClass}
                            value={form.hire_date}
                            onChange={set("hire_date")}
                        />
                    </Field>
                    <Field label="Tanggal keluar" error={err("end_date")}>
                        <input
                            type="date"
                            className={inputClass}
                            value={form.end_date}
                            onChange={set("end_date")}
                        />
                    </Field>
                </div>
            </section>

            <section className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Data pribadi
                </h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="NIK" error={err("nik")} hint="16 digit.">
                        <input
                            className={inputClass}
                            inputMode="numeric"
                            maxLength={16}
                            value={form.nik}
                            onChange={set("nik")}
                        />
                    </Field>
                    <Field label="Telepon" error={err("phone")}>
                        <input
                            className={inputClass}
                            inputMode="tel"
                            maxLength={20}
                            value={form.phone}
                            onChange={set("phone")}
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
            </section>

            <section className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Alamat
                </h2>
                <RegionPicker
                    value={region}
                    onChange={(next) => {
                        setForm((previous) => ({ ...previous, ...next }));
                        clearErrors("region", ...REGION_FIELDS);
                    }}
                    errors={errors}
                />
                {err("region") && (
                    <p className="text-sm text-red-600">{err("region")}</p>
                )}
                <Field
                    label="Detail alamat"
                    error={err("address")}
                    hint="Jalan, nomor, RT/RW.">
                    <textarea
                        className={inputClass}
                        rows={3}
                        maxLength={500}
                        value={form.address}
                        onChange={set("address")}
                    />
                </Field>
            </section>

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={save.isPending}
                    className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {save.isPending ? "Menyimpan..." : "Simpan"}
                </button>
                <Link
                    to={editing ? `/employees/${employee.id}` : "/employees"}
                    className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
                    Batal
                </Link>
            </div>
        </form>
    );
}

export default function EmployeeForm() {
    const { id } = useParams();
    const { user } = useAuth();
    const editing = Boolean(id);

    const {
        data: employee,
        isLoading,
        error,
        refetch,
    } = useQuery({
        queryKey: ["employee", user.id, id],
        queryFn: async () => (await api.get(`/api/employees/${id}`)).data.data,
        enabled: editing,
    });

    // Hanya soal tampilan: yang menolak tetap policy di backend.
    if (!isHrAdmin(user.role)) return <Navigate to="/employees" replace />;
    if (editing && isLoading) return <Skeleton />;
    if (editing && error) return <ErrorBox error={error} onRetry={refetch} />;

    return <FormBody key={employee?.id ?? "new"} employee={employee} />;
}
