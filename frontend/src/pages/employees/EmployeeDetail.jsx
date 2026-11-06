import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { useAuth } from "../../auth/useAuth";
import { Card, ErrorBox, errorMessage, Skeleton } from "../../components/ui";
import { api } from "../../lib/api";
import { fmtDate } from "../../lib/format";
import { EMPLOYMENT_LABEL } from "../../lib/labels";
import { ActiveBadge, Field, MaskedValue } from "./parts";
import { HR_ADMIN_ROLES } from "../../auth/nav";

export default function EmployeeDetail() {
    const { id } = useParams();
    const { user } = useAuth();
    const queryClient = useQueryClient();

    const key = ["employee", user.id, id];
    const canManage = HR_ADMIN_ROLES.includes(user.role);

    const {
        data: employee,
        isLoading,
        error,
        refetch,
    } = useQuery({
        queryKey: key,
        queryFn: async () => (await api.get(`/api/employees/${id}`)).data.data,
    });

    const toggle = useMutation({
        mutationFn: async (activate) =>
            (
                await api.post(
                    `/api/employees/${id}/${activate ? "activate" : "deactivate"}`,
                )
            ).data.data,
        onSuccess: (updated) => {
            queryClient.setQueryData(key, updated);
            queryClient.invalidateQueries({ queryKey: ["employees"] });
        },
    });

    function onToggle() {
        const activate = !employee.is_active;
        const question = activate
            ? "Aktifkan kembali karyawan ini? Akun loginnya tidak ikut dibuka otomatis."
            : "Nonaktifkan karyawan ini? Akun loginnya juga akan dikunci.";

        if (window.confirm(question)) toggle.mutate(activate);
    }

    if (isLoading) return <Skeleton />;
    if (error) return <ErrorBox error={error} onRetry={refetch} />;

    // Backend hanya mengirim field pribadi kalau Anda berhak (HR, Super Admin, atau pemilik data).
    const hasPersonal = ["nik", "phone", "birth_date", "address"].some(
        (field) => field in employee,
    );

    return (
        <div className="space-y-4">
            <Link to="/employees" className="text-sm text-slate-600 underline">
                ← Daftar karyawan
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold">
                        {employee.full_name}
                    </h1>
                    <p className="mt-1 flex items-center gap-2 text-sm text-slate-600">
                        <span className="font-mono">
                            {employee.employee_number}
                        </span>
                        <ActiveBadge active={employee.is_active} />
                    </p>
                </div>

                {canManage && (
                    <div className="flex gap-2">
                        <Link
                            to={`/employees/${id}/edit`}
                            className="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm">
                            Ubah
                        </Link>
                        <button
                            onClick={onToggle}
                            disabled={toggle.isPending}
                            className="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm disabled:opacity-50">
                            {employee.is_active ? "Nonaktifkan" : "Aktifkan"}
                        </button>
                    </div>
                )}
            </div>

            {toggle.error && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {errorMessage(toggle.error)}
                </p>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Data kepegawaian">
                    <dl className="grid grid-cols-2 gap-4">
                        <Field label="Departemen">
                            {employee.department?.name}
                        </Field>
                        <Field label="Jabatan">{employee.position?.name}</Field>
                        <Field label="Atasan">
                            {employee.manager?.full_name}
                        </Field>
                        <Field label="Status kerja">
                            {EMPLOYMENT_LABEL[employee.employment_status] ??
                                employee.employment_status}
                        </Field>
                        <Field label="Tanggal masuk">
                            {fmtDate(employee.hire_date)}
                        </Field>
                        <Field label="Tanggal berakhir">
                            {employee.end_date
                                ? fmtDate(employee.end_date)
                                : null}
                        </Field>
                    </dl>
                </Card>

                {hasPersonal && (
                    <Card title="Data pribadi">
                        <dl className="grid grid-cols-2 gap-4">
                            <div>
                                <dt className="text-xs text-slate-500">NIK</dt>
                                <dd className="text-sm">
                                    <MaskedValue value={employee.nik} />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-slate-500">
                                    Telepon
                                </dt>
                                <dd className="text-sm">
                                    <MaskedValue value={employee.phone} />
                                </dd>
                            </div>
                            <Field label="Tanggal lahir">
                                {employee.birth_date
                                    ? fmtDate(employee.birth_date)
                                    : null}
                            </Field>
                            <Field label="Alamat">{employee.address}</Field>
                            <Field label="Wilayah">
                                {employee.region
                                    ? [
                                          employee.region.village,
                                          employee.region.district,
                                          employee.region.regency,
                                          employee.region.province,
                                      ]
                                          .filter(Boolean)
                                          .join(", ")
                                    : null}
                            </Field>
                        </dl>
                    </Card>
                )}
            </div>
        </div>
    );
}
