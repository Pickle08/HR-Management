import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import AuditLog from "./AuditLog";

vi.mock("../../lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "super_admin" } }),
}));

const ACTIONS = ["auth.login", "department.updated", "payroll.updated"];

const DEPARTMENT_LOG = {
    id: 5,
    action: "department.updated",
    created_at: "2026-03-15T08:00:00Z",
    actor: { id: 2, name: "Hana HR", email: "hana@t.test" },
    subject: { type: "Department", id: 3 },
    changed_fields: ["name"],
    old_values: { name: "Keuangan" },
    new_values: { name: "Finance" },
    ip_address: "10.0.0.1",
};

// Backend mengosongkan nilai untuk model yang tidak ada di daftar tampil.
const PAYROLL_LOG = {
    id: 6,
    action: "payroll.updated",
    created_at: "2026-03-15T09:00:00Z",
    actor: { id: 3, name: "Fina Finance", email: "fina@t.test" },
    subject: { type: "Payroll", id: 9 },
    changed_fields: ["net_salary"],
    old_values: [],
    new_values: [],
    ip_address: null,
};

const LOGIN_FAILED_LOG = {
    id: 7,
    action: "auth.login_failed",
    created_at: "2026-03-15T10:00:00Z",
    actor: null,
    subject: null,
    changed_fields: ["email"],
    old_values: null,
    new_values: { email: "x@t.test" },
    ip_address: "10.0.0.2",
};

const LOGS = [DEPARTMENT_LOG, PAYROLL_LOG, LOGIN_FAILED_LOG];

function mockApi(rows = LOGS, meta = {}) {
    api.get.mockImplementation(async (url) => {
        if (url === "/api/audit-logs/actions")
            return { data: { data: ACTIONS } };

        return {
            data: {
                data: rows,
                meta: {
                    current_page: 1,
                    last_page: 1,
                    total: rows.length,
                    ...meta,
                },
            },
        };
    });
}

const renderPage = () =>
    renderWithProviders(<AuditLog />, {
        route: "/audit-logs",
        path: "/audit-logs",
    });

const rowOf = (action) =>
    screen.getByText(action, { selector: "td" }).closest("tr");

const openDetail = (action) =>
    fireEvent.click(
        within(rowOf(action)).getByRole("button", { name: "Rincian" }),
    );

describe("AuditLog", () => {
    beforeEach(() => {
        api.get.mockReset();
        mockApi();
    });

    it("lists the activity with actor, action and subject", async () => {
        renderPage();

        expect(await screen.findByText("Hana HR")).toBeInTheDocument();
        expect(screen.getByText("Fina Finance")).toBeInTheDocument();
        expect(screen.getByText("Department #3")).toBeInTheDocument();
        expect(screen.getByText("Payroll #9")).toBeInTheDocument();
        expect(screen.getByText("Tanpa pengguna")).toBeInTheDocument();
    });

    it("shows old and new values for a model whose values are visible", async () => {
        renderPage();
        await screen.findByText("Hana HR");

        openDetail("department.updated");
        const dialog = screen.getByRole("dialog");

        expect(
            within(dialog).getByText("Hana HR (hana@t.test)"),
        ).toBeInTheDocument();
        expect(within(dialog).getByText("Department #3")).toBeInTheDocument();
        expect(within(dialog).getByText("name")).toBeInTheDocument();
        expect(within(dialog).getByText("Keuangan")).toBeInTheDocument();
        expect(within(dialog).getByText("Finance")).toBeInTheDocument();
    });

    it("shows only the column name when the backend hides the values", async () => {
        renderPage();
        await screen.findByText("Fina Finance");

        openDetail("payroll.updated");
        const dialog = screen.getByRole("dialog");

        expect(within(dialog).getByText("net_salary")).toBeInTheDocument();
        expect(
            within(dialog).getByText("Nilai tidak ditampilkan"),
        ).toBeInTheDocument();
    });

    it("handles an entry without actor or subject", async () => {
        renderPage();
        await screen.findByText("Hana HR");

        openDetail("auth.login_failed");
        const dialog = screen.getByRole("dialog");

        expect(within(dialog).getByText("Tanpa pengguna")).toBeInTheDocument();
        expect(within(dialog).queryByText("Objek")).not.toBeInTheDocument();
        expect(within(dialog).getByText("x@t.test")).toBeInTheDocument();
        expect(within(dialog).getByText("10.0.0.2")).toBeInTheDocument();
    });

    it("closes the detail dialog", async () => {
        renderPage();
        await screen.findByText("Hana HR");

        openDetail("department.updated");
        fireEvent.click(screen.getByRole("button", { name: "Tutup" }));

        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });

    it("sends the chosen action and dates to the api and resets to page 1", async () => {
        renderPage();
        await screen.findByRole("option", { name: "auth.login" });

        fireEvent.change(screen.getByLabelText("Aksi"), {
            target: { value: "auth.login" },
        });
        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith("/api/audit-logs", {
                params: expect.objectContaining({
                    action: "auth.login",
                    page: 1,
                }),
            }),
        );

        fireEvent.change(screen.getByLabelText("Dari tanggal"), {
            target: { value: "2026-03-01" },
        });
        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith("/api/audit-logs", {
                params: expect.objectContaining({
                    action: "auth.login",
                    from: "2026-03-01",
                }),
            }),
        );
    });

    it("warns about a reversed date range and does not send it", async () => {
        renderPage();
        await screen.findByText("Hana HR");

        fireEvent.change(screen.getByLabelText("Dari tanggal"), {
            target: { value: "2026-03-16" },
        });
        fireEvent.change(screen.getByLabelText("Sampai tanggal"), {
            target: { value: "2026-03-15" },
        });

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Tanggal akhir harus sama dengan atau setelah tanggal awal.",
        );
        expect(screen.queryByRole("table")).not.toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalledWith("/api/audit-logs", {
            params: expect.objectContaining({
                from: "2026-03-16",
                to: "2026-03-15",
            }),
        });
    });

    it("requests the next page from the pagination", async () => {
        mockApi(LOGS, { last_page: 3, total: 90 });
        renderPage();

        expect(await screen.findByText(/Halaman 1 dari 3/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole("button", { name: "Berikutnya" }));

        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith("/api/audit-logs", {
                params: expect.objectContaining({ page: 2 }),
            }),
        );
    });

    it("says so when nothing matches", async () => {
        mockApi([]);
        renderPage();

        expect(
            await screen.findByText("Tidak ada aktivitas yang cocok."),
        ).toBeInTheDocument();
    });
});
