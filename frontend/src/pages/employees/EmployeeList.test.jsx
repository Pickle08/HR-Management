import { act, fireEvent, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import EmployeeList from "./EmployeeList";

vi.mock("../../lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "hr" } }),
}));

const kiki = {
    id: 5,
    employee_number: "EMP-005",
    full_name: "Kiki Karyawan",
    employment_status: "tetap",
    hire_date: "2024-01-15",
    is_active: true,
    department: { id: 1, name: "Operasional" },
    position: { id: 1, name: "Staff" },
    manager: { id: 1, employee_number: "EMP-001", full_name: "Budi Atasan" },
};

const list = (rows, meta = {}) => ({
    data: rows,
    meta: { current_page: 1, last_page: 1, total: rows.length, ...meta },
});

describe("EmployeeList", () => {
    beforeEach(() => api.get.mockReset());
    afterEach(() => vi.useRealTimers());

    it("reads page and search from the url and shows manager names", async () => {
        api.get.mockResolvedValue({
            data: list([kiki], { current_page: 2, last_page: 3, total: 21 }),
        });

        renderWithProviders(<EmployeeList />, {
            route: "/employees?page=2&search=kiki",
        });

        expect(await screen.findByText("Kiki Karyawan")).toBeInTheDocument();
        expect(screen.getByText("Budi Atasan")).toBeInTheDocument();
        expect(screen.getByLabelText("Cari karyawan")).toHaveValue("kiki");
        expect(api.get).toHaveBeenCalledWith("/api/employees", {
            params: { page: 2, search: "kiki" },
        });
    });

    it("debounces typing into the url and resets to page 1", async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        api.get.mockResolvedValue({
            data: list([kiki], { current_page: 2, last_page: 3, total: 21 }),
        });

        renderWithProviders(<EmployeeList />, { route: "/employees?page=2" });
        await screen.findByText("Kiki Karyawan");

        fireEvent.change(screen.getByLabelText("Cari karyawan"), {
            target: { value: "bu" },
        });

        // Belum 300 ms: URL belum berubah.
        expect(screen.getByTestId("location")).toHaveTextContent(
            "/employees?page=2",
        );

        await act(async () => {
            vi.advanceTimersByTime(300);
        });

        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                "/employees?search=bu",
            ),
        );
        await waitFor(() =>
            expect(api.get).toHaveBeenLastCalledWith("/api/employees", {
                params: { page: 1, search: "bu" },
            }),
        );
    });

    it("moves through pages via the url", async () => {
        api.get.mockResolvedValue({
            data: list([kiki], { current_page: 1, last_page: 3, total: 40 }),
        });

        renderWithProviders(<EmployeeList />, { route: "/employees" });

        expect(
            await screen.findByRole("button", { name: "Sebelumnya" }),
        ).toBeDisabled();

        fireEvent.click(screen.getByRole("button", { name: "Berikutnya" }));

        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                "/employees?page=2",
            ),
        );
    });

    it("explains an empty search result", async () => {
        api.get.mockResolvedValue({ data: list([]) });

        renderWithProviders(<EmployeeList />, {
            route: "/employees?search=zzz",
        });

        expect(
            await screen.findByText(
                /Tidak ada karyawan yang cocok dengan "zzz"/,
            ),
        ).toBeInTheDocument();
    });

    it("shows a readable message on 403", async () => {
        // "Once": rejection pada mock modul yang dipakai ulang antar tes dilaporkan
        // Vitest sebagai unhandled walaupun sudah ditangani React Query.
        api.get.mockRejectedValueOnce({ response: { status: 403 } });

        renderWithProviders(<EmployeeList />, { route: "/employees" });

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Anda tidak berhak mengakses data ini.",
        );
    });
});
