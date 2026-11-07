import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { MONTH_LABEL, PAYROLL_STATUS_LABEL } from "../../lib/labels";
import { rupiah } from "../../lib/money";
import { renderWithProviders } from "../../test/utils";
import PayrollList from "./PayrollList";

const auth = vi.hoisted(() => ({ role: "hr" }));

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: auth.role } }),
}));
vi.mock("../../components/EmployeePicker", () => ({
    default: ({ onChange, error }) => (
        <div>
            <button type="button" onClick={() => onChange(5)}>
                Pilih karyawan
            </button>
            {error && <p>{error}</p>}
        </div>
    ),
}));

// rupiah() bisa mengandung non-breaking space; DOM-nya sudah dinormalisasi.
const money = (value) => rupiah(value).replace(/\s/g, " ");

const THIS_YEAR = String(new Date().getFullYear());

const ROWS = [
    {
        id: 1,
        month: 3,
        year: 2026,
        status: "draft",
        adjusts_payroll_id: null,
        employee: { full_name: "Kiki Karyawan", employee_number: "EMP-005" },
        totals: { net: 5_700_000 },
    },
    {
        id: 2,
        month: 4,
        year: 2026,
        status: "final",
        adjusts_payroll_id: 1,
        employee: { full_name: "Budi Atasan", employee_number: "EMP-001" },
        totals: { net: -100_000 },
    },
];

const META = { current_page: 1, last_page: 1, per_page: 15, total: 2 };

const label = (name) => screen.getByLabelText(name);
const button = (name) => screen.queryByRole("button", { name });
const change = (name, value) =>
    fireEvent.change(label(name), { target: { value } });

function renderList(role, { rows = ROWS, route = "/payroll" } = {}) {
    auth.role = role;
    api.get.mockResolvedValue({ data: { data: rows, meta: META } });

    renderWithProviders(<PayrollList />, { route, path: "/payroll" });

    return screen.findByRole("heading", { level: 1 });
}

describe("PayrollList", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
    });

    it("lists periods as links with employee, net and an adjustment badge for hr", async () => {
        const heading = await renderList("hr");

        expect(heading).toHaveTextContent("Payroll");
        expect(
            await screen.findByRole("link", { name: `${MONTH_LABEL[2]} 2026` }),
        ).toHaveAttribute("href", "/payroll/1");
        expect(
            screen.getByRole("link", { name: `${MONTH_LABEL[3]} 2026` }),
        ).toHaveAttribute("href", "/payroll/2");
        expect(screen.getByText("Kiki Karyawan")).toBeInTheDocument();
        expect(screen.getByText("EMP-005")).toBeInTheDocument();
        expect(screen.getByText(money(5_700_000))).toBeInTheDocument();
        expect(screen.getAllByText("Penyesuaian")).toHaveLength(1);
    });

    it("marks a negative net in red", async () => {
        await renderList("hr");

        expect(await screen.findByText(money(-100_000))).toHaveClass(
            "text-red-600",
        );
        expect(screen.getByText(money(5_700_000))).not.toHaveClass(
            "text-red-600",
        );
    });

    it("shows a payslip view for karyawan: no employee column, no status filter, own empty message", async () => {
        const heading = await renderList("karyawan", { rows: [] });

        expect(heading).toHaveTextContent("Slip gaji");
        expect(
            await screen.findByText("Belum ada slip gaji yang sudah final."),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText("Status")).not.toBeInTheDocument();

        expect(label("Tahun")).toBeInTheDocument();
        expect(label("Bulan")).toBeInTheDocument();
    });

    it("hides the employee column from karyawan when rows exist", async () => {
        await renderList("karyawan");

        await screen.findByRole("link", { name: `${MONTH_LABEL[2]} 2026` });
        expect(
            screen.queryByRole("columnheader", { name: "Karyawan" }),
        ).not.toBeInTheDocument();
    });

    it("shows the staff empty message and the status filter for finance", async () => {
        await renderList("finance", { rows: [] });

        expect(
            await screen.findByText("Tidak ada payroll yang cocok."),
        ).toBeInTheDocument();
        expect(label("Status")).toBeInTheDocument();
    });

    it.each([
        ["hr", true],
        ["finance", false],
        ["karyawan", false],
    ])("%s sees the create button: %s", async (role, visible) => {
        await renderList(role);
        await screen.findByRole("link", { name: `${MONTH_LABEL[2]} 2026` });

        if (visible) {
            expect(button("Buat payroll")).toBeInTheDocument();
        } else {
            expect(button("Buat payroll")).not.toBeInTheDocument();
        }
    });

    it("reads the page from the url and sends it to the api", async () => {
        await renderList("hr", { route: "/payroll?page=2" });

        await waitFor(() =>
            expect(api.get).toHaveBeenCalledWith("/api/payrolls", {
                params: {
                    year: undefined,
                    month: undefined,
                    status: undefined,
                    page: 2,
                },
            }),
        );
    });

    it("applies a filter to the url and query, and drops the page back to 1", async () => {
        await renderList("hr", { route: "/payroll?page=3" });
        await screen.findByRole("link", { name: `${MONTH_LABEL[2]} 2026` });

        change("Tahun", THIS_YEAR);

        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                `/payroll?year=${THIS_YEAR}`,
            ),
        );
        expect(screen.getByTestId("location")).not.toHaveTextContent("page=");
        await waitFor(() =>
            expect(api.get).toHaveBeenLastCalledWith("/api/payrolls", {
                params: {
                    year: THIS_YEAR,
                    month: undefined,
                    status: undefined,
                    page: 1,
                },
            }),
        );
    });

    it("lets staff filter by status and clears the param when set back to all", async () => {
        await renderList("finance");
        await screen.findByRole("link", { name: `${MONTH_LABEL[2]} 2026` });
        const [status] = Object.keys(PAYROLL_STATUS_LABEL);

        change("Status", status);
        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                `status=${status}`,
            ),
        );

        change("Status", "");
        await waitFor(() =>
            expect(screen.getByTestId("location")).not.toHaveTextContent(
                "status=",
            ),
        );
    });

    it("refuses to create without an employee, then posts and opens the new payroll", async () => {
        await renderList("hr");
        api.post.mockResolvedValue({ data: { data: { id: 12 } } });

        fireEvent.click(button("Buat payroll"));
        fireEvent.click(button("Buat"));

        expect(await screen.findByText("Wajib diisi.")).toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();

        fireEvent.click(button("Pilih karyawan"));
        expect(screen.queryByText("Wajib diisi.")).not.toBeInTheDocument();

        fireEvent.click(button("Buat"));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/payrolls", {
                employee_id: 5,
                year: expect.any(Number),
                month: expect.any(Number),
            }),
        );
        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                "/payroll/12",
            ),
        );
    });

    it("shows a server rule violation from a 422 in the alert", async () => {
        await renderList("hr");
        api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    errors: { period: ["Payroll periode ini sudah ada."] },
                },
            },
        });

        fireEvent.click(button("Buat payroll"));
        fireEvent.click(button("Pilih karyawan"));
        fireEvent.click(button("Buat"));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Payroll periode ini sudah ada.",
        );
        await waitFor(() => expect(button("Buat")).toBeEnabled());
    });

    it("falls back to a generic message when creating fails without a server message", async () => {
        await renderList("hr");
        api.post.mockRejectedValue(new Error("Network Error"));

        fireEvent.click(button("Buat payroll"));
        fireEvent.click(button("Pilih karyawan"));
        fireEvent.click(button("Buat"));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Gagal membuat payroll. Coba lagi.",
        );
        await waitFor(() => expect(button("Buat")).toBeEnabled());
    });
});
