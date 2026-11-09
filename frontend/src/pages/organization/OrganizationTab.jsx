import { useEffect, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
    Dialog,
    Empty,
    ErrorBox,
    Field,
    Pagination,
    Skeleton,
    errorMessage,
    inputClass,
} from "../../components/ui";
import { api } from "../../lib/api";
import { useOrganization } from "../../lib/organization";

function NameForm({ resource, label, item, onClose }) {
    const queryClient = useQueryClient();
    const editing = Boolean(item);
    const [name, setName] = useState(item?.name ?? "");

    const save = useMutation({
        mutationFn: (body) =>
            editing
                ? api.put(`/api/${resource}/${item.id}`, body)
                : api.post(`/api/${resource}`, body),
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: ["organization", resource],
            });
            onClose();
        },
    });

    const validation = save.error?.response?.status === 422;
    const nameError = validation
        ? save.error.response.data.errors?.name?.[0]
        : undefined;
    const general = save.isError && !validation ? errorMessage(save.error) : "";

    function submit(e) {
        e.preventDefault();
        save.mutate({ name: name.trim() });
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
            <h2 className="text-base font-semibold">
                {editing
                    ? `Ubah ${label.toLowerCase()}`
                    : `Tambah ${label.toLowerCase()}`}
            </h2>

            {general && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {general}
                </p>
            )}

            <Field label={`Nama ${label.toLowerCase()}`} error={nameError}>
                <input
                    className={inputClass}
                    maxLength={100}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    autoFocus
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

function DeleteDialog({ resource, label, item, onClose }) {
    const queryClient = useQueryClient();

    const remove = useMutation({
        mutationFn: () => api.delete(`/api/${resource}/${item.id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: ["organization", resource],
            });
            onClose();
        },
    });

    return (
        <Dialog title={`Hapus ${label.toLowerCase()}`} onClose={onClose}>
            <p className="text-sm">
                Hapus <strong>{item.name}</strong>? Tindakan ini tidak bisa
                dibatalkan.
            </p>

            {remove.isError && (
                <p
                    className="rounded bg-red-50 p-3 text-sm text-red-700"
                    role="alert">
                    {errorMessage(remove.error)}
                </p>
            )}

            <div className="flex gap-2">
                <button
                    onClick={() => remove.mutate()}
                    disabled={remove.isPending}
                    className="rounded bg-red-600 px-4 py-2 text-sm text-white disabled:opacity-50">
                    {remove.isPending ? "Menghapus..." : "Hapus"}
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

export default function OrganizationTab({ resource, label }) {
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState("");
    const [search, setSearch] = useState("");
    const [editing, setEditing] = useState(null); // { item: obj | null }
    const [deleting, setDeleting] = useState(null);

    const { data, isLoading, error, refetch } = useOrganization(resource, {
        page,
        search,
    });

    // debounce pencarian, dan kembali ke halaman 1 saat kata kunci berubah
    useEffect(() => {
        const timer = setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, [searchInput]);

    // setelah menghapus item terakhir di halaman terakhir, mundur satu halaman
    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) setPage((p) => p - 1);
    }, [data, page]);

    if (isLoading) return <Skeleton />;
    if (error) return <ErrorBox error={error} onRetry={refetch} />;

    const rows = data.data;

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <input
                    type="search"
                    className="w-full max-w-xs rounded border border-slate-300 bg-white px-3 py-2 text-sm"
                    placeholder={`Cari ${label.toLowerCase()}`}
                    value={searchInput}
                    onChange={(e) => setSearchInput(e.target.value)}
                />
                {!editing && (
                    <button
                        onClick={() => setEditing({ item: null })}
                        className="shrink-0 rounded bg-slate-900 px-3 py-2 text-sm text-white">
                        Tambah {label.toLowerCase()}
                    </button>
                )}
            </div>

            {editing && (
                <NameForm
                    key={editing.item?.id ?? "new"}
                    resource={resource}
                    label={label}
                    item={editing.item}
                    onClose={() => setEditing(null)}
                />
            )}

            {rows.length === 0 ? (
                <Empty>
                    {search
                        ? "Tidak ada hasil."
                        : `Belum ada ${label.toLowerCase()}.`}
                </Empty>
            ) : (
                <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Nama</th>
                                <th className="px-3 py-2">Karyawan</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((item) => (
                                <tr
                                    key={item.id}
                                    className="border-b border-slate-100 last:border-0">
                                    <td className="px-3 py-2">{item.name}</td>
                                    <td className="px-3 py-2">
                                        {item.employees_count ?? 0}
                                    </td>
                                    <td className="space-x-3 px-3 py-2 text-right">
                                        <button
                                            onClick={() => setEditing({ item })}
                                            className="underline">
                                            Ubah
                                        </button>
                                        <button
                                            onClick={() => setDeleting(item)}
                                            className="text-red-600 underline">
                                            Hapus
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Pagination meta={data.meta} onPage={setPage} />

            {deleting && (
                <DeleteDialog
                    resource={resource}
                    label={label}
                    item={deleting}
                    onClose={() => setDeleting(null)}
                />
            )}
        </div>
    );
}
