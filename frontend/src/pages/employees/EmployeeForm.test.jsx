import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import EmployeeForm from "./EmployeeForm";

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "hr" } }),
}));

const LISTS = {
    "/api/departments": [
        { id: 1, name: "Operasional" },
        { id: 2, name: "Keuangan" },
    ],
    "/api/positions": [{ id: 7, name: "Staff" }],
    "/api/employees/managers": [
        { id: 3, employee_number: "E1", full_name: "Budi Atasan" },
    ],
    "/api/regions/provinces": [
        { code: "32", name: "Jawa Barat" },
        { code: "35", name: "Jawa Timur" },
    ],
};

const REGENCIES = {
    32: [{ code: "32.73", name: "Kota Bandung" }],
    35: [{ code: "35.77", name: "Kota Madiun" }],
};
const DISTRICTS = { 32.73: [{ code: "32.73.01", name: "Sukasari" }] };
const VILLAGES = {
    "32.73.01": [{ code: "32.73.01.1002", name: "Gegerkalong" }],
};

function mockApi(single = {}) {
    api.get.mockImplementation(async (url, config) => {
        if (single[url]) return { data: { data: single[url] } };
        if (url === "/api/regions/regencies")
            return {
                data: { data: REGENCIES[config.params.province_code] ?? [] },
            };
        if (url === "/api/regions/districts")
            return {
                data: { data: DISTRICTS[config.params.regency_code] ?? [] },
            };
        if (url === "/api/regions/villages")
            return {
                data: { data: VILLAGES[config.params.district_code] ?? [] },
            };

        return { data: { data: LISTS[url] ?? [] } };
    });
}

const label = (name) => screen.getByLabelText(name);
const change = (name, value) =>
    fireEvent.change(label(name), { target: { value } });

async function fillRequired(overrides = {}) {
    await screen.findByRole("option", { name: "Operasional" });
    await screen.findByRole("option", { name: "Staff" });

    change("Nomor karyawan", overrides.number ?? "EMP-100");
    change("Nama lengkap", overrides.name ?? "Siti Aminah");
    change("Departemen", "1");
    change("Jabatan", "7");
    change("Status kerja", "kontrak");
    change("Tanggal masuk", "2030-03-01");
}

const renderCreate = () =>
    renderWithProviders(<EmployeeForm />, {
        route: "/employees/new",
        path: "/employees/new",
    });

describe("EmployeeForm", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        api.put.mockReset();
        mockApi();
    });

    it("sends a trimmed payload with numeric ids and nulls for empty fields, then opens the detail page", async () => {
        api.post.mockResolvedValue({ data: { data: { id: 9 } } });
        renderCreate();

        await fillRequired({ number: " EMP-100 ", name: " Siti Aminah " });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/employees", {
                employee_number: "EMP-100",
                full_name: "Siti Aminah",
                department_id: 1,
                position_id: 7,
                manager_id: null,
                employment_status: "kontrak",
                hire_date: "2030-03-01",
                end_date: null,
                birth_date: null,
                nik: null,
                phone: null,
                address: null,
                province_code: null,
                regency_code: null,
                district_code: null,
                village_code: null,
            }),
        );

        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                "/employees/9",
            ),
        );
    });

    it("reports every missing required field in Indonesian without calling the api", async () => {
        renderCreate();
        await screen.findByRole("option", { name: "Operasional" });

        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(await screen.findAllByText("Wajib diisi.")).toHaveLength(6);
        expect(screen.getByRole("alert")).toHaveTextContent(
            "Periksa isian yang ditandai.",
        );
        expect(api.post).not.toHaveBeenCalled();
    });

    it("loads each region level from its parent and resets the children when the parent changes", async () => {
        renderCreate();

        expect(label("Kabupaten/Kota")).toBeDisabled();
        expect(label("Kecamatan")).toBeDisabled();

        await screen.findByRole("option", { name: "Jawa Barat" });
        change("Provinsi", "32");
        expect(api.get).toHaveBeenCalledWith("/api/regions/regencies", {
            params: { province_code: "32" },
        });

        await screen.findByRole("option", { name: "Kota Bandung" });
        change("Kabupaten/Kota", "32.73");
        await screen.findByRole("option", { name: "Sukasari" });
        change("Kecamatan", "32.73.01");
        await screen.findByRole("option", { name: "Gegerkalong" });
        change("Desa/Kelurahan", "32.73.01.1002");

        expect(label("Desa/Kelurahan")).toHaveValue("32.73.01.1002");

        change("Provinsi", "35");

        await screen.findByRole("option", { name: "Kota Madiun" });
        expect(label("Kabupaten/Kota")).toHaveValue("");
        expect(label("Kecamatan")).toHaveValue("");
        expect(label("Kecamatan")).toBeDisabled();
        expect(label("Desa/Kelurahan")).toHaveValue("");
    });

    it("refuses a half-filled region chain before calling the api", async () => {
        renderCreate();

        await fillRequired();
        await screen.findByRole("option", { name: "Jawa Barat" });
        change("Provinsi", "32");

        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(
            await screen.findByText(
                "Lengkapi seluruh tingkat wilayah, atau kosongkan semuanya.",
            ),
        ).toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();
    });

    it("shows server validation errors under their fields, keeps the input, and clears an error when edited", async () => {
        api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: "invalid",
                    errors: {
                        employee_number: ["Nomor karyawan sudah dipakai."],
                        manager_id: ["Atasan harus karyawan aktif."],
                    },
                },
            },
        });
        renderCreate();

        await fillRequired({ number: "EMP-100" });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(
            await screen.findByText("Nomor karyawan sudah dipakai."),
        ).toBeInTheDocument();
        expect(
            screen.getByText("Atasan harus karyawan aktif."),
        ).toBeInTheDocument();
        expect(label("Nomor karyawan")).toHaveValue("EMP-100");

        change("Nomor karyawan", "EMP-101");

        expect(
            screen.queryByText("Nomor karyawan sudah dipakai."),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText("Atasan harus karyawan aktif."),
        ).toBeInTheDocument();
    });

    it("prefills an employee for editing, derives the region chain from the village code, and saves with PUT", async () => {
        mockApi({
            "/api/employees/5": {
                id: 5,
                employee_number: "EMP-005",
                full_name: "Kiki Karyawan",
                employment_status: "tetap",
                hire_date: "2024-01-15",
                end_date: null,
                birth_date: "1995-05-20",
                is_active: true,
                manager_id: 3,
                department: { id: 1, name: "Operasional" },
                position: { id: 7, name: "Staff" },
                nik: "3201010101010001",
                phone: "081234567890",
                address: "Jl. Contoh No. 1",
                province_code: "32",
                village_code: "32.73.01.1002",
            },
        });
        api.put.mockResolvedValue({ data: { data: { id: 5 } } });

        renderWithProviders(<EmployeeForm />, {
            route: "/employees/5/edit",
            path: "/employees/:id/edit",
        });

        await waitFor(() =>
            expect(label("Nama lengkap")).toHaveValue("Kiki Karyawan"),
        );
        await waitFor(() => expect(label("Departemen")).toHaveValue("1"));
        await waitFor(() => expect(label("Atasan")).toHaveValue("3"));
        await waitFor(() => expect(label("Provinsi")).toHaveValue("32"));
        await waitFor(() =>
            expect(label("Kabupaten/Kota")).toHaveValue("32.73"),
        );
        await waitFor(() => expect(label("Kecamatan")).toHaveValue("32.73.01"));
        await waitFor(() =>
            expect(label("Desa/Kelurahan")).toHaveValue("32.73.01.1002"),
        );

        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.put).toHaveBeenCalledWith(
                "/api/employees/5",
                expect.objectContaining({
                    department_id: 1,
                    position_id: 7,
                    manager_id: 3,
                    nik: "3201010101010001",
                    province_code: "32",
                    regency_code: "32.73",
                    district_code: "32.73.01",
                    village_code: "32.73.01.1002",
                }),
            ),
        );
        expect(api.post).not.toHaveBeenCalled();
    });
});
