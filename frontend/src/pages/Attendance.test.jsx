import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../lib/api";
import { renderWithProviders } from "../test/utils";
import Attendance from "./Attendance";

const auth = vi.hoisted(() => ({ user: null }));

vi.mock("../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));
vi.mock("../auth/useAuth", () => ({ useAuth: () => ({ user: auth.user }) }));

const ROW = {
    id: 11,
    date: "2030-03-04",
    status: "hadir",
    check_in: "08:00",
    check_out: "17:00",
    note: null,
    employee: { id: 5, full_name: "Kiki Karyawan", employee_number: "EMP-005" },
};

const renderPage = () =>
    renderWithProviders(<Attendance />, {
        route: "/attendance",
        path: "/attendance",
    });

describe("Attendance", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.put.mockReset();
        auth.user = { id: 1, role: "hr" };
        api.get.mockResolvedValue({
            data: {
                data: [ROW],
                meta: { current_page: 1, last_page: 1, total: 1 },
            },
        });
    });

    it("lets hr record and correct attendance", async () => {
        renderPage();

        expect(await screen.findByText("Kiki Karyawan")).toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Catat absensi" }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Koreksi" }),
        ).toBeInTheDocument();
    });

    it("shows karyawan only their own view without employee column or management buttons", async () => {
        auth.user = { id: 5, role: "karyawan" };
        renderPage();

        await screen.findByText("08:00");

        expect(screen.queryByText("Kiki Karyawan")).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Catat absensi" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Koreksi" }),
        ).not.toBeInTheDocument();
    });

    it("warns when the end date is before the start date", async () => {
        renderPage();
        await screen.findByText("Kiki Karyawan");

        fireEvent.change(screen.getByLabelText("Sampai"), {
            target: { value: "2000-01-01" },
        });

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Tanggal akhir tidak boleh sebelum tanggal awal.",
        );
    });

    it("corrects a record to alpa and clears the times in the payload", async () => {
        api.put.mockResolvedValue({ data: {} });
        renderPage();

        fireEvent.click(await screen.findByRole("button", { name: "Koreksi" }));
        fireEvent.change(screen.getByLabelText("Status"), {
            target: { value: "alpa" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.put).toHaveBeenCalledWith("/api/attendances/11", {
                status: "alpa",
                check_in: null,
                check_out: null,
                note: null,
            }),
        );
    });
});
