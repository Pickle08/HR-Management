import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import Balances from "./Balances";

const auth = vi.hoisted(() => ({ user: null }));

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({ useAuth: () => ({ user: auth.user }) }));
// EmployeePicker punya pencarian sendiri; di sini cukup stub yang memilih karyawan 5.
vi.mock("../../components/EmployeePicker", async () => {
    const React = await import("react");
    return {
        default: ({ onChange }) =>
            React.createElement(
                "button",
                { type: "button", onClick: () => onChange(5) },
                "Pilih karyawan",
            ),
    };
});

const YEAR = new Date().getFullYear();

const TYPES = [
    { id: 2, name: "Cuti Tahunan", annual_quota: 12, is_active: true },
];

const ROW = {
    id: 1,
    employee: { id: 5, full_name: "Kiki Karyawan", employee_number: "EMP-005" },
    leave_type: { id: 2, name: "Cuti Tahunan" },
    entitled_days: 12,
    used_days: 2,
    pending_days: 1,
    remaining_days: 9,
};

function mockApi() {
    api.get.mockImplementation(async (url) => {
        if (url === "/api/leave-types") return { data: { data: TYPES } };

        return {
            data: {
                data: [ROW],
                meta: { current_page: 1, last_page: 1, total: 1 },
            },
        };
    });
}

const renderPage = (canManage = true) =>
    renderWithProviders(<Balances canManage={canManage} />, {
        route: "/leave",
        path: "/leave",
    });

async function fillSetForm(entitled = "14") {
    fireEvent.click(await screen.findByRole("button", { name: "Atur jatah" }));
    fireEvent.click(screen.getByRole("button", { name: "Pilih karyawan" }));
    await screen.findByRole("option", { name: "Cuti Tahunan" });
    fireEvent.change(screen.getByLabelText("Jenis cuti"), {
        target: { value: "2" },
    });
    fireEvent.change(screen.getByLabelText("Jatah (hari)"), {
        target: { value: entitled },
    });
    fireEvent.click(screen.getByRole("button", { name: "Simpan" }));
}

describe("Balances", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        api.put.mockReset();
        auth.user = { id: 1, role: "hr" };
        window.confirm = vi.fn(() => true);
        mockApi();
    });

    it("lists balances for the current year with the employee column", async () => {
        renderPage();

        expect(await screen.findByText("Kiki Karyawan")).toBeInTheDocument();
        expect(screen.getByText("9")).toBeInTheDocument();
        expect(api.get).toHaveBeenCalledWith("/api/leave-balances", {
            params: { year: YEAR, page: 1 },
        });
    });

    it("hides the employee column and manage buttons for karyawan", async () => {
        auth.user = { id: 5, role: "karyawan" };
        renderPage(false);

        await screen.findByText("Cuti Tahunan", { selector: "td" });

        expect(screen.queryByText("Kiki Karyawan")).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: /Buat jatah/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Atur jatah" }),
        ).not.toBeInTheDocument();
    });

    it("generates balances after confirmation and reports how many were created", async () => {
        api.post.mockResolvedValue({ data: { created: 3 } });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: `Buat jatah ${YEAR}` }),
        );

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith(
                "/api/leave-balances/generate",
                { year: YEAR },
            ),
        );
        expect(
            await screen.findByText(`3 jatah baru dibuat untuk ${YEAR}.`),
        ).toBeInTheDocument();
    });

    it("does not generate when the confirmation is cancelled", async () => {
        window.confirm = vi.fn(() => false);
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: `Buat jatah ${YEAR}` }),
        );

        expect(window.confirm).toHaveBeenCalled();
        expect(api.post).not.toHaveBeenCalled();
    });

    it("sets an individual balance with numeric ids", async () => {
        api.put.mockResolvedValue({ data: {} });
        renderPage();

        await fillSetForm("14");

        await waitFor(() =>
            expect(api.put).toHaveBeenCalledWith("/api/leave-balances", {
                employee_id: 5,
                leave_type_id: 2,
                year: YEAR,
                entitled_days: 14,
            }),
        );
    });

    it("shows the server error under the field when setting a balance fails validation", async () => {
        api.put.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: "invalid",
                    errors: { entitled_days: ["Jatah tidak valid."] },
                },
            },
        });
        renderPage();

        await fillSetForm("14");

        expect(
            await screen.findByText("Jatah tidak valid."),
        ).toBeInTheDocument();
    });
});
