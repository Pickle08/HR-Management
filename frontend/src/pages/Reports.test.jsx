import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../lib/api";
import { renderWithProviders } from "../test/utils";
import Reports from "./Reports";

const auth = vi.hoisted(() => ({ role: "hr" }));

vi.mock("../lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: auth.role } }),
}));

const PAYLOAD = {
    data: [
        {
            employee_number: "EMP-001",
            full_name: "Kiki Karyawan",
            department: null,
        },
    ],
    meta: {
        total: 1,
        per_page: 50,
        current_page: 1,
        last_page: 1,
        columns: {
            employee_number: "Nomor",
            full_name: "Nama",
            department: "Departemen",
        },
    },
};

const label = (name) => screen.getByLabelText(name);
const button = (name) => screen.getByRole("button", { name });
const change = (name, value) =>
    fireEvent.change(label(name), { target: { value } });

function renderReports(role, payload = PAYLOAD) {
    auth.role = role;
    api.get.mockResolvedValue({ data: payload });

    renderWithProviders(<Reports />, { route: "/reports", path: "/reports" });

    return screen.findByRole("heading", { level: 1 });
}

async function show() {
    fireEvent.click(button("Tampilkan"));
    await screen.findByRole("columnheader", { name: "Nomor" });
}

const csvBlobError = (body) => ({
    response: { status: 422, data: new Blob([JSON.stringify(body)]) },
});

describe("Reports", () => {
    let clicks;

    beforeEach(() => {
        api.get.mockReset();
        clicks = [];
        URL.createObjectURL = vi.fn(() => "blob:test");
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(
            function () {
                clicks.push(this.download);
            },
        );
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it.each([
        ["hr", ["Karyawan", "Absensi", "Cuti", "Rekrutmen", "Payroll"]],
        ["finance", ["Payroll"]],
        ["atasan", ["Absensi", "Cuti"]],
        ["super_admin", ["Karyawan", "Rekrutmen"]],
    ])("%s can pick only %j", async (role, expected) => {
        await renderReports(role);

        const options = within(label("Laporan")).getAllByRole("option");
        expect(options.map((o) => o.textContent)).toEqual(expected);
    });

    it("tells karyawan there is no report and offers no picker", async () => {
        await renderReports("karyawan");

        expect(
            screen.getByText("Tidak ada laporan untuk peran Anda."),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText("Laporan")).not.toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalled();
    });

    it("makes no request until Tampilkan is pressed, and export is disabled until then", async () => {
        await renderReports("hr");

        expect(api.get).not.toHaveBeenCalled();
        expect(button("Ekspor CSV")).toBeDisabled();
    });

    it("renders columns from meta.columns and shows a dash for empty cells", async () => {
        await renderReports("hr");
        await show();

        expect(api.get).toHaveBeenCalledWith("/api/reports/employees", {
            params: { page: 1 },
        });
        expect(
            screen.getByRole("columnheader", { name: "Nama" }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("cell", { name: "EMP-001" }),
        ).toBeInTheDocument();
        expect(screen.getByRole("cell", { name: "-" })).toBeInTheDocument();
        expect(screen.getByText("1 baris")).toBeInTheDocument();
    });

    it("sends the period for attendance", async () => {
        await renderReports("hr");
        change("Laporan", "attendance");
        change("Dari", "2026-01-01");
        change("Sampai", "2026-01-31");
        await show();

        expect(api.get).toHaveBeenCalledWith("/api/reports/attendance", {
            params: { from: "2026-01-01", to: "2026-01-31", page: 1 },
        });
    });

    it("requires both dates before calling the api", async () => {
        await renderReports("atasan");
        change("Dari", "");
        fireEvent.click(button("Tampilkan"));

        expect(await screen.findByText("Wajib diisi.")).toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalled();
    });

    it("rejects an end date before the start date", async () => {
        await renderReports("atasan");
        change("Dari", "2026-02-10");
        change("Sampai", "2026-02-01");
        fireEvent.click(button("Tampilkan"));

        expect(
            await screen.findByText(
                "Tanggal akhir tidak boleh sebelum tanggal awal.",
            ),
        ).toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalled();
    });

    it("allows exactly 92 days and refuses 93, like the backend", async () => {
        await renderReports("atasan");

        change("Dari", "2026-01-01");
        change("Sampai", "2026-04-04");
        fireEvent.click(button("Tampilkan"));
        expect(
            await screen.findByText("Rentang laporan maksimal 92 hari."),
        ).toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalled();

        change("Sampai", "2026-04-03");
        await show();
        expect(api.get).toHaveBeenCalledTimes(1);
    });

    it("sends year and month as numbers for payroll and validates the year", async () => {
        await renderReports("finance");

        change("Tahun", "1999");
        fireEvent.click(button("Tampilkan"));
        expect(
            await screen.findByText("Tahun harus antara 2000 dan 2100."),
        ).toBeInTheDocument();
        expect(api.get).not.toHaveBeenCalled();

        change("Tahun", "2026");
        change("Bulan", "3");
        await show();

        expect(api.get).toHaveBeenCalledWith("/api/reports/payroll", {
            params: { year: 2026, month: 3, page: 1 },
        });
    });

    it("shows a server validation message from a 422 instead of the table", async () => {
        await renderReports("hr");
        api.get.mockRejectedValue({
            response: {
                status: 422,
                data: { errors: { to: ["Rentang laporan maksimal 92 hari."] } },
            },
        });

        fireEvent.click(button("Tampilkan"));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Rentang laporan maksimal 92 hari.",
        );
        expect(screen.queryByRole("table")).not.toBeInTheDocument();
    });

    it("drops the table and disables export when another report is picked", async () => {
        await renderReports("hr");
        await show();
        expect(button("Ekspor CSV")).toBeEnabled();

        change("Laporan", "recruitment");

        expect(screen.queryByRole("table")).not.toBeInTheDocument();
        expect(button("Ekspor CSV")).toBeDisabled();
    });

    it("exports the applied filters as a blob and saves it under the server filename", async () => {
        await renderReports("hr");
        await show();
        api.get.mockResolvedValueOnce({
            data: new Blob(["a,b"]),
            headers: {
                "content-disposition":
                    'attachment; filename="laporan-employees-20261109-101010.csv"',
            },
        });

        fireEvent.click(button("Ekspor CSV"));

        await waitFor(() =>
            expect(URL.createObjectURL).toHaveBeenCalledTimes(1),
        );
        expect(api.get).toHaveBeenLastCalledWith("/api/reports/employees", {
            params: { format: "csv" },
            responseType: "blob",
        });
        expect(clicks).toEqual(["laporan-employees-20261109-101010.csv"]);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:test");
        await waitFor(() => expect(button("Ekspor CSV")).toBeEnabled());
    });

    it("falls back to a generic filename when the header is not readable", async () => {
        await renderReports("hr");
        await show();
        api.get.mockResolvedValueOnce({ data: new Blob(["a,b"]), headers: {} });

        fireEvent.click(button("Ekspor CSV"));

        await waitFor(() => expect(clicks).toEqual(["laporan-employees.csv"]));
    });

    it("shows the server message when the export is oversized, and saves nothing", async () => {
        await renderReports("hr");
        await show();
        api.get.mockRejectedValueOnce(
            csvBlobError({
                message: "invalid",
                errors: {
                    format: [
                        "Ekspor melebihi batas 5000 baris. Persempit filter laporan.",
                    ],
                },
            }),
        );

        fireEvent.click(button("Ekspor CSV"));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Ekspor melebihi batas 5000 baris. Persempit filter laporan.",
        );
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        await waitFor(() => expect(button("Ekspor CSV")).toBeEnabled());
    });

    it("falls back to a generic export error when the body is not JSON", async () => {
        await renderReports("hr");
        await show();
        api.get.mockRejectedValueOnce({
            response: { status: 500, data: new Blob(["<html>oops</html>"]) },
        });

        fireEvent.click(button("Ekspor CSV"));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Gagal mengekspor laporan. Coba lagi.",
        );
    });
});
