import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import Organization from "./Organization";

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "hr" } }),
}));

const ROW = { id: 3, name: "Keuangan", employees_count: 4 };

function mockApi() {
    api.get.mockImplementation(async () => ({
        data: {
            data: [ROW],
            meta: { current_page: 1, last_page: 1, total: 1 },
        },
    }));
}

const renderPage = () =>
    renderWithProviders(<Organization />, {
        route: "/organization",
        path: "/organization",
    });

describe("Organization", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        api.put.mockReset();
        api.delete.mockReset();
        mockApi();
    });

    it("lists departments with their employee count", async () => {
        renderPage();

        expect(await screen.findByText("Keuangan")).toBeInTheDocument();
        expect(screen.getByText("4")).toBeInTheDocument();
        expect(api.get).toHaveBeenCalledWith("/api/departments", {
            params: expect.objectContaining({ page: 1 }),
        });
    });

    it("switches to positions", async () => {
        renderPage();
        await screen.findByText("Keuangan");

        fireEvent.click(screen.getByRole("tab", { name: "Jabatan" }));

        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith(
                "/api/positions",
                expect.anything(),
            ),
        );
    });

    it("shows the duplicate-name error under the field and keeps the form open", async () => {
        api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: "invalid",
                    errors: {
                        name: ["Departemen dengan nama ini sudah ada."],
                    },
                },
            },
        });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Tambah departemen" }),
        );
        fireEvent.change(screen.getByLabelText("Nama departemen"), {
            target: { value: "Keuangan" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(
            await screen.findByText("Departemen dengan nama ini sudah ada."),
        ).toBeInTheDocument();
        expect(api.post).toHaveBeenCalledWith("/api/departments", {
            name: "Keuangan",
        });
    });

    it("shows the 409 message inside the delete dialog when the item is still used", async () => {
        api.delete.mockRejectedValue({
            response: {
                status: 409,
                data: {
                    message:
                        "Departemen masih dipakai, jadi tidak bisa dihapus.",
                },
            },
        });
        renderPage();

        fireEvent.click(await screen.findByRole("button", { name: "Hapus" }));
        const dialog = screen.getByRole("dialog");
        fireEvent.click(within(dialog).getByRole("button", { name: "Hapus" }));

        expect(
            await within(dialog).findByText(
                "Departemen masih dipakai, jadi tidak bisa dihapus.",
            ),
        ).toBeInTheDocument();
        expect(api.delete).toHaveBeenCalledWith("/api/departments/3");
    });
});
