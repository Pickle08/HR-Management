import { useEffect, useRef, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router-dom";
import { useAuth } from "../../auth/useAuth";
import {
    Card,
    Empty,
    ErrorBox,
    Pagination,
    Skeleton,
} from "../../components/ui";
import { api } from "../../lib/api";
import { fmtDate } from "../../lib/format";
import { EMPLOYMENT_LABEL } from "../../lib/labels";
import { ActiveBadge } from "./parts";
import { HR_ADMIN_ROLES } from "../../auth/nav";

export default function EmployeeList() {
    const { user } = useAuth();
    const [params, setParams] = useSearchParams();

    // Halaman dan pencarian disimpan di URL: refresh, tombol Back, dan tautan tetap bekerja.
    const page = Math.max(1, Number(params.get("page")) || 1);
    const search = params.get("search") ?? "";

    const [text, setText] = useState(search);
    const timer = useRef(null);

    useEffect(() => setText(search), [search]);
    useEffect(() => () => clearTimeout(timer.current), []);

    function updateParams(changes, replace = false) {
        setParams(
            (previous) => {
                const next = new URLSearchParams(previous);

                for (const [key, value] of Object.entries(changes)) {
                    value === null || value === ""
                        ? next.delete(key)
                        : next.set(key, value);
                }

                return next;
            },
            { replace },
        );
    }

    function onSearchChange(event) {
        const value = event.target.value;

        setText(value);
        clearTimeout(timer.current);
        timer.current = setTimeout(
            () => updateParams({ search: value.trim(), page: null }, true),
            300,
        );
    }

    const { data, isLoading, isFetching, error, refetch } = useQuery({
        queryKey: ["employees", user.id, { page, search }],
        queryFn: async () =>
            (
                await api.get("/api/employees", {
                    params: { page, search: search || undefined },
                })
            ).data,
        placeholderData: keepPreviousData,
    });

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-3">
                <h1 className="text-xl font-semibold">Karyawan</h1>
                {HR_ADMIN_ROLES.includes(user.role) && (
                    <Link
                        to="/employees/new"
                        className="rounded bg-slate-900 px-3 py-1.5 text-sm text-white">
                        Tambah karyawan
                    </Link>
                )}
            </div>

            <div>
                <label htmlFor="employee-search" className="sr-only">
                    Cari karyawan
                </label>
                <input
                    id="employee-search"
                    type="search"
                    value={text}
                    onChange={onSearchChange}
                    placeholder="Cari nama atau nomor karyawan..."
                    className="w-full max-w-sm rounded border border-slate-300 bg-white px-3 py-1.5 text-sm"
                />
            </div>

            {isLoading ? (
                <Skeleton />
            ) : error && !data ? (
                <ErrorBox error={error} onRetry={refetch} />
            ) : (
                <Card>
                    {data.data.length === 0 ? (
                        <Empty>
                            {search
                                ? `Tidak ada karyawan yang cocok dengan "${search}".`
                                : "Belum ada data karyawan."}
                            {page > 1 && (
                                <>
                                    {" "}
                                    <button
                                        className="underline"
                                        onClick={() =>
                                            updateParams({ page: null })
                                        }>
                                        Ke halaman pertama
                                    </button>
                                </>
                            )}
                        </Empty>
                    ) : (
                        <div
                            className={`overflow-x-auto ${isFetching ? "opacity-60" : ""}`}>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-slate-500">
                                        <th className="pb-2 font-medium">
                                            Nomor
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Nama
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Departemen
                                        </th>
                                        <th className="hidden pb-2 font-medium sm:table-cell">
                                            Jabatan
                                        </th>
                                        <th className="hidden pb-2 font-medium md:table-cell">
                                            Status kerja
                                        </th>
                                        <th className="hidden pb-2 font-medium lg:table-cell">
                                            Atasan
                                        </th>
                                        <th className="hidden pb-2 font-medium lg:table-cell">
                                            Masuk
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.data.map((employee) => (
                                        <tr
                                            key={employee.id}
                                            className="border-t border-slate-100">
                                            <td className="py-2 pr-3 font-mono text-xs">
                                                {employee.employee_number}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <Link
                                                    to={`/employees/${employee.id}`}
                                                    className="font-medium text-slate-900 underline">
                                                    {employee.full_name}
                                                </Link>
                                            </td>
                                            <td className="py-2 pr-3">
                                                {employee.department?.name ??
                                                    "-"}
                                            </td>
                                            <td className="hidden py-2 pr-3 sm:table-cell">
                                                {employee.position?.name ?? "-"}
                                            </td>
                                            <td className="hidden py-2 pr-3 md:table-cell">
                                                {EMPLOYMENT_LABEL[
                                                    employee.employment_status
                                                ] ?? employee.employment_status}
                                            </td>
                                            <td className="hidden py-2 pr-3 lg:table-cell">
                                                {employee.manager?.full_name ??
                                                    "-"}
                                            </td>
                                            <td className="hidden py-2 pr-3 lg:table-cell">
                                                {fmtDate(employee.hire_date)}
                                            </td>
                                            <td className="py-2">
                                                <ActiveBadge
                                                    active={employee.is_active}
                                                />
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
                </Card>
            )}
        </div>
    );
}
