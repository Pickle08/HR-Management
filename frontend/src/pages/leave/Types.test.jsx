import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import Types from "./Types";

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "hr" } }),
}));

const TYPES = [
    { id: 2, name: "Cuti Tahunan", annual_quota: 12, is_active: true },
    { id: 3, name: "Cuti Lama", annual_quota: 5, is_active: false },
];

const renderPage = () =>
    renderWithProviders(<Types />, { route: "/leave", path: "/leave" });

describe("Types", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        api.put.mockReset();
        api.get.mockResolvedValue({ data: { data: TYPES } });
    });

    it("lists types with their active status", async () => {
        renderPage();

        expect(await screen.findByText("Cuti Tahunan")).toBeInTheDocument();
        expect(screen.getByText("12 hari")).toBeInTheDocument();
        expect(screen.getByText("Aktif")).toBeInTheDocument();
        expect(screen.getByText("Nonaktif")).toBeInTheDocument();
    });

    it("creates a type with a numeric quota", async () => {
        api.post.mockResolvedValue({ data: {} });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Tambah jenis cuti" }),
        );
        fireEvent.change(screen.getByLabelText("Nama"), {
            target: { value: "Cuti Menikah" },
        });
        fireEvent.change(screen.getByLabelText("Jatah tahunan (hari)"), {
            target: { value: "3" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/leave-types", {
                name: "Cuti Menikah",
                annual_quota: 3,
                is_active: true,
            }),
        );
    });

    it("edits an existing type with its values prefilled", async () => {
        api.put.mockResolvedValue({ data: {} });
        renderPage();

        fireEvent.click(
            (await screen.findAllByRole("button", { name: "Ubah" }))[0],
        );
        expect(screen.getByLabelText("Nama")).toHaveValue("Cuti Tahunan");

        fireEvent.change(screen.getByLabelText("Nama"), {
            target: { value: "Cuti Tahunan Baru" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.put).toHaveBeenCalledWith("/api/leave-types/2", {
                name: "Cuti Tahunan Baru",
                annual_quota: 12,
                is_active: true,
            }),
        );
    });

    it("shows the duplicate-name error under the field", async () => {
        api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: "invalid",
                    errors: { name: ["Nama sudah dipakai."] },
                },
            },
        });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Tambah jenis cuti" }),
        );
        fireEvent.change(screen.getByLabelText("Nama"), {
            target: { value: "Cuti Tahunan" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(
            await screen.findByText("Nama sudah dipakai."),
        ).toBeInTheDocument();
    });
});
