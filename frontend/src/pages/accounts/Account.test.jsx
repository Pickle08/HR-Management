import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import { renderWithProviders } from "../../test/utils";
import Accounts from "./Accounts";

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "super_admin" } }),
}));

const EMP = (id, number, name) => ({
    id,
    employee_number: number,
    full_name: name,
    is_active: true,
});

const ACCOUNTS = [
    {
        id: 1,
        name: "Super",
        email: "super@t.test",
        role: "super_admin",
        is_active: true,
        employee: null,
    },
    {
        id: 2,
        name: "Hana HR",
        email: "hana@t.test",
        role: "hr",
        is_active: true,
        employee: EMP(10, "EMP-010", "Hana HR"),
    },
    {
        id: 3,
        name: "Lia Terkunci",
        email: "lia@t.test",
        role: "karyawan",
        is_active: false,
        employee: EMP(11, "EMP-011", "Lia Terkunci"),
    },
];

const FREE_EMPLOYEE = EMP(20, "EMP-020", "Budi Baru");

function mockApi() {
    api.get.mockImplementation(async (url) => {
        if (url === "/api/employees")
            return { data: { data: [FREE_EMPLOYEE] } };

        return {
            data: {
                data: ACCOUNTS,
                meta: { current_page: 1, last_page: 1, total: 3 },
            },
        };
    });
}

const renderPage = () =>
    renderWithProviders(<Accounts />, {
        route: "/accounts",
        path: "/accounts",
    });

const rowOf = (name) => screen.getByText(name).closest("tr");

async function pickFreeEmployee() {
    fireEvent.click(await screen.findByRole("button", { name: /Budi Baru/ }));
}

describe("Accounts", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        api.put.mockReset();
        mockApi();
    });

    it("gives the signed-in account no action buttons", async () => {
        renderPage();
        await screen.findByText("Hana HR");

        expect(
            within(rowOf("Super")).queryByRole("button"),
        ).not.toBeInTheDocument();
        expect(
            within(rowOf("Hana HR")).getByRole("button", { name: "Kunci" }),
        ).toBeInTheDocument();
    });

    it("locks an account through the confirmation dialog", async () => {
        api.post.mockResolvedValue({ data: {} });
        renderPage();
        await screen.findByText("Hana HR");

        fireEvent.click(
            within(rowOf("Hana HR")).getByRole("button", { name: "Kunci" }),
        );
        fireEvent.click(
            within(screen.getByRole("dialog")).getByRole("button", {
                name: "Kunci",
            }),
        );

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/accounts/2/lock"),
        );
        await waitFor(() =>
            expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
        );
    });

    it("keeps the dialog open and shows the server message when locking is refused", async () => {
        api.post.mockRejectedValue({
            response: {
                status: 409,
                data: {
                    message:
                        "Atasan ini masih punya bawahan aktif. Pindahkan bawahannya dulu.",
                },
            },
        });
        renderPage();
        await screen.findByText("Hana HR");

        fireEvent.click(
            within(rowOf("Hana HR")).getByRole("button", { name: "Kunci" }),
        );
        const dialog = screen.getByRole("dialog");
        fireEvent.click(within(dialog).getByRole("button", { name: "Kunci" }));

        expect(
            await within(dialog).findByText(
                "Atasan ini masih punya bawahan aktif. Pindahkan bawahannya dulu.",
            ),
        ).toBeInTheDocument();
    });

    it("unlocks a locked account", async () => {
        api.post.mockResolvedValue({ data: {} });
        renderPage();
        await screen.findByText("Lia Terkunci");

        fireEvent.click(
            within(rowOf("Lia Terkunci")).getByRole("button", {
                name: "Buka kunci",
            }),
        );
        fireEvent.click(
            within(screen.getByRole("dialog")).getByRole("button", {
                name: "Buka kunci",
            }),
        );

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/accounts/3/unlock"),
        );
    });

    it("shows the temporary password after a reset and drops it when closed", async () => {
        api.post.mockResolvedValue({
            data: {
                data: ACCOUNTS[1],
                meta: { temporary_password: "Abc123xyz456Q" },
            },
        });
        renderPage();
        await screen.findByText("Hana HR");

        fireEvent.click(
            within(rowOf("Hana HR")).getByRole("button", {
                name: "Reset password",
            }),
        );
        fireEvent.click(
            within(screen.getByRole("dialog")).getByRole("button", {
                name: "Reset password",
            }),
        );

        expect(
            await screen.findByTestId("temporary-password"),
        ).toHaveTextContent("Abc123xyz456Q");
        expect(api.post).toHaveBeenCalledWith("/api/accounts/2/reset-password");

        fireEvent.click(
            within(screen.getByRole("dialog")).getAllByRole("button", {
                name: "Tutup",
            })[0],
        );

        expect(
            screen.queryByTestId("temporary-password"),
        ).not.toBeInTheDocument();
    });

    it("changes a role through the dialog", async () => {
        api.put.mockResolvedValue({ data: {} });
        renderPage();
        await screen.findByText("Hana HR");

        fireEvent.click(
            within(rowOf("Hana HR")).getByRole("button", { name: "Role" }),
        );
        const dialog = screen.getByRole("dialog");
        fireEvent.change(within(dialog).getByLabelText("Role"), {
            target: { value: "finance" },
        });
        fireEvent.click(within(dialog).getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.put).toHaveBeenCalledWith("/api/accounts/2/role", {
                role: "finance",
            }),
        );
    });

    it("requires an employee for non-super-admin roles and shows the one-time password", async () => {
        api.post.mockResolvedValue({
            data: {
                data: ACCOUNTS[2],
                meta: { temporary_password: "Pwd123abc456" },
            },
        });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        expect(screen.getByRole("button", { name: "Simpan" })).toBeDisabled();

        await pickFreeEmployee();
        expect(screen.getByRole("button", { name: "Simpan" })).toBeEnabled();

        fireEvent.change(screen.getByLabelText("Email"), {
            target: { value: "budi@t.test" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/accounts", {
                email: "budi@t.test",
                role: "karyawan",
                employee_id: 20,
            }),
        );
        expect(
            await screen.findByTestId("temporary-password"),
        ).toHaveTextContent("Pwd123abc456");
    });

    it("creates a super admin without an employee by asking for a name", async () => {
        api.post.mockResolvedValue({
            data: { data: ACCOUNTS[0], meta: { temporary_password: "Pwd789" } },
        });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        fireEvent.change(screen.getByLabelText("Role"), {
            target: { value: "super_admin" },
        });
        fireEvent.change(screen.getByLabelText("Nama"), {
            target: { value: "Admin Baru" },
        });
        fireEvent.change(screen.getByLabelText("Email"), {
            target: { value: "admin@t.test" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/accounts", {
                email: "admin@t.test",
                role: "super_admin",
                name: "Admin Baru",
            }),
        );
    });

    it("shows the employee error under the picker when the employee already has an account", async () => {
        api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: "invalid",
                    errors: { employee_id: ["Karyawan ini sudah punya akun."] },
                },
            },
        });
        renderPage();

        fireEvent.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        await pickFreeEmployee();
        fireEvent.change(screen.getByLabelText("Email"), {
            target: { value: "budi@t.test" },
        });
        fireEvent.click(screen.getByRole("button", { name: "Simpan" }));

        expect(
            await screen.findByText("Karyawan ini sudah punya akun."),
        ).toBeInTheDocument();
    });
});
