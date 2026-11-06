import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import Requests from "./Requests";

const auth = vi.hoisted(() => ({ user: null }));

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({ useAuth: () => ({ user: auth.user }) }));

const TYPES = [
    { id: 2, name: "Cuti Tahunan", annual_quota: 12, is_active: true },
];

const ROW = {
    id: 7,
    employee: { id: 5, full_name: "Kiki Karyawan", employee_number: "EMP-005" },
    leave_type: { id: 2, name: "Cuti Tahunan" },
    start_date: "2030-03-04",
    end_date: "2030-03-05",
    days: 2,
    status: "pending",
    reason: "Keperluan keluarga",
    decision: null,
    cancellation: null,
    can_decide: true,
    can_cancel: true,
};

function mockApi(row = ROW) {
    api.get.mockImplementation(async (url) => {
        if (url === "/api/leave-types") return { data: { data: TYPES } };

        return {
            data: {
                data: [row],
                meta: { current_page: 1, last_page: 1, total: 1 },
            },
        };
    });
}

const renderPage = () =>
    renderWithProviders(<Requests />, { route: "/leave", path: "/leave" });

describe("Requests", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        auth.user = { id: 1, role: "atasan" };
        mockApi();
    });

    it("starts the atasan on pending requests and shows the employee column", async () => {
        renderPage();

        expect(await screen.findByText("Kiki Karyawan")).toBeInTheDocument();
        expect(api.get).toHaveBeenCalledWith("/api/leave-requests", {
            params: expect.objectContaining({ status: "pending", page: 1 }),
        });
    });

    it("hides the employee column for karyawan and shows no decision buttons without permission", async () => {
        auth.user = { id: 5, role: "karyawan" };
        mockApi({ ...ROW, can_decide: false, can_cancel: true });
        renderPage();

        await screen.findByText("Cuti Tahunan", { selector: "td" });

        expect(screen.queryByText("Kiki Karyawan")).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Setujui" }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Batalkan" }),
        ).toBeInTheDocument();
    });

    it("approves a request through the dialog", async () => {
        api.post.mockResolvedValue({ data: {} });
        renderPage();

        fireEvent.click(await screen.findByRole("button", { name: "Setujui" }));
        fireEvent.click(screen.getByRole("button", { name: "Konfirmasi" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith(
                "/api/leave-requests/7/approve",
                { note: undefined },
            ),
        );
        await waitFor(() =>
            expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
        );
    });

    it("shows the server error under the note field when rejecting without a note", async () => {
        api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: "invalid",
                    errors: { note: ["Catatan wajib diisi."] },
                },
            },
        });
        renderPage();

        fireEvent.click(await screen.findByRole("button", { name: "Tolak" }));
        fireEvent.click(screen.getByRole("button", { name: "Konfirmasi" }));

        expect(
            await screen.findByText("Catatan wajib diisi."),
        ).toBeInTheDocument();
        expect(screen.getByRole("dialog")).toBeInTheDocument();
    });

    it("submits a new leave request with numeric type id and null reason", async () => {
        auth.user = { id: 5, role: "karyawan" };
        api.post.mockResolvedValue({ data: {} });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Ajukan cuti" }),
        );
        await screen.findByRole("option", { name: "Cuti Tahunan" });
        fireEvent.change(screen.getByLabelText("Jenis cuti"), {
            target: { value: "2" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Ajukan" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith(
                "/api/leave-requests",
                expect.objectContaining({ leave_type_id: 2, reason: null }),
            ),
        );
    });
});
