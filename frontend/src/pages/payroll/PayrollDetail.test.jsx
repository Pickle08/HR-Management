import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../../lib/api";
import {
    FINANCE_ITEM_TYPES,
    MONTH_LABEL,
    PAYROLL_ITEM_LABEL,
} from "../../lib/labels";
import { rupiah } from "../../lib/money";
import { renderWithProviders } from "../../test/utils";
import PayrollDetail from "./PayrollDetail";

const auth = vi.hoisted(() => ({ role: "hr" }));

// rupiah() bisa mengandung non-breaking space; DOM-nya sudah dinormalisasi.
const money = (value) => rupiah(value).replace(/\s/g, " ");

vi.mock("../../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock("../../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: auth.role } }),
}));

const ALL_TYPES = Object.keys(PAYROLL_ITEM_LABEL);
const [BPJS] = FINANCE_ITEM_TYPES;
const ALLOWANCE = ALL_TYPES.find(
    (type) => type !== "basic_salary" && !FINANCE_ITEM_TYPES.includes(type),
);

function makePayroll(overrides = {}) {
    return {
        id: 7,
        status: "draft",
        month: 3,
        year: 2026,
        revision_no: 0,
        adjusts_payroll_id: null,
        note: null,
        employee: { full_name: "Kiki Karyawan", employee_number: "EMP-005" },
        items: [
            { id: 1, type: "basic_salary", label: null, amount: 5_000_000 },
            { id: 2, type: ALLOWANCE, label: null, amount: 1_000_000 },
            { id: 3, type: BPJS, label: null, amount: 300_000 },
        ],
        totals: { gross: 6_000_000, deductions: 300_000, net: 5_700_000 },
        finalization: null,
        ...overrides,
    };
}

const FINAL = {
    status: "final",
    finalization: {
        by: { name: "Fina Finance" },
        at: "2026-03-31T10:00:00Z",
    },
};

const label = (name) => screen.getByLabelText(name);
const button = (name) => screen.queryByRole("button", { name });
const change = (name, value) =>
    fireEvent.change(label(name), { target: { value } });

function renderDetail(role, payroll = makePayroll()) {
    auth.role = role;
    api.get.mockResolvedValue({ data: { data: payroll } });

    renderWithProviders(<PayrollDetail />, {
        route: "/payroll/7",
        path: "/payroll/:id",
    });

    return screen.findByRole("heading", { level: 1 });
}

describe("PayrollDetail", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        api.put.mockReset();
        api.delete.mockReset();
        vi.spyOn(window, "confirm").mockReturnValue(true);
    });

    it("shows the period, employee and the totals exactly as the api sends them", async () => {
        const heading = await renderDetail("hr");

        expect(heading).toHaveTextContent(`Payroll ${MONTH_LABEL[2]} 2026`);
        expect(screen.getByText(/Kiki Karyawan/)).toBeInTheDocument();
        expect(screen.getByText(money(6_000_000))).toBeInTheDocument();
        expect(screen.getByText(money(5_700_000))).toBeInTheDocument();
        expect(screen.getByText(money(5_000_000))).toBeInTheDocument();
    });

    it("calls the page a payslip for a role that is not hr or finance", async () => {
        const heading = await renderDetail("karyawan");

        expect(heading).toHaveTextContent(`Slip gaji ${MONTH_LABEL[2]} 2026`);
    });

    const ACTIONS = [
        "Ubah komponen",
        "Finalisasi",
        "Buat penyesuaian",
        "Hapus draf",
    ];

    it.each([
        ["hr", "draft", 0, ["Ubah komponen", "Hapus draf"]],
        ["finance", "draft", 0, ["Ubah komponen", "Finalisasi"]],
        ["karyawan", "draft", 0, []],
        ["hr", "final", 0, ["Buat penyesuaian"]],
        ["hr", "final", 1, []],
        ["finance", "final", 0, []],
        ["karyawan", "final", 0, []],
    ])(
        "%s on a %s payroll (revision %i) sees %j",
        async (role, status, rev, shown) => {
            await renderDetail(role, makePayroll({ status, revision_no: rev }));

            for (const name of ACTIONS) {
                if (shown.includes(name)) {
                    expect(button(name)).toBeInTheDocument();
                } else {
                    expect(button(name)).not.toBeInTheDocument();
                }
            }
        },
    );

    it("links a correction to the original and shows its note", async () => {
        await renderDetail(
            "hr",
            makePayroll({
                ...FINAL,
                id: 12,
                revision_no: 1,
                adjusts_payroll_id: 7,
                note: "Salah input BPJS",
            }),
        );

        expect(screen.getByText("Koreksi 1")).toBeInTheDocument();
        expect(
            screen.getByRole("link", { name: "payroll asli" }),
        ).toHaveAttribute("href", "/payroll/7");
        expect(screen.getByText(/Salah input BPJS/)).toBeInTheDocument();
    });

    it("finalizes only after confirmation, then locks the page", async () => {
        await renderDetail("finance");
        api.post.mockResolvedValue({ data: {} });
        api.get.mockResolvedValue({
            data: { data: makePayroll(FINAL) },
        });

        window.confirm.mockReturnValueOnce(false);
        fireEvent.click(button("Finalisasi"));
        expect(api.post).not.toHaveBeenCalled();

        fireEvent.click(button("Finalisasi"));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith("/api/payrolls/7/finalize"),
        );
        expect(
            await screen.findByText(/Difinalisasi oleh Fina Finance/),
        ).toBeInTheDocument();
        expect(button("Finalisasi")).not.toBeInTheDocument();
        expect(button("Ubah komponen")).not.toBeInTheDocument();
    });

    it("shows an alert when finalizing fails", async () => {
        await renderDetail("finance");
        api.post.mockRejectedValue({
            response: {
                status: 409,
                data: { message: "Payroll sudah final." },
            },
        });

        fireEvent.click(button("Finalisasi"));

        expect(await screen.findByRole("alert")).toBeInTheDocument();
        expect(button("Finalisasi")).toBeEnabled();
    });

    it("deletes a draft after confirmation and returns to the list", async () => {
        await renderDetail("hr");
        api.delete.mockResolvedValue({ data: {} });

        fireEvent.click(button("Hapus draf"));

        await waitFor(() =>
            expect(api.delete).toHaveBeenCalledWith("/api/payrolls/7"),
        );
        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                "/payroll",
            ),
        );
    });

    it("lets finance edit only finance components and never sends the fixed ones", async () => {
        await renderDetail("finance");
        api.put.mockResolvedValue({ data: {} });

        fireEvent.click(button("Ubah komponen"));

        const select = label("Jenis komponen 1");
        expect(select).toHaveValue(BPJS);
        expect(
            within(select).queryByRole("option", {
                name: PAYROLL_ITEM_LABEL.basic_salary,
            }),
        ).not.toBeInTheDocument();
        expect(label("Nominal 1")).toHaveValue("300000");

        change("Nominal 1", "350000");
        fireEvent.click(button("Simpan"));

        await waitFor(() =>
            expect(api.put).toHaveBeenCalledWith("/api/payrolls/7/items", {
                items: [{ type: BPJS, label: null, amount: 350000 }],
            }),
        );
    });

    it("rejects a negative or non-integer amount on a draft before calling the api", async () => {
        await renderDetail("hr");
        fireEvent.click(button("Ubah komponen"));

        change("Nominal 1", "-5");
        fireEvent.click(button("Simpan"));
        expect(
            await screen.findByText("Nominal tidak boleh negatif."),
        ).toBeInTheDocument();

        change("Nominal 1", "abc");
        fireEvent.click(button("Simpan"));
        expect(
            await screen.findByText("Isi dengan bilangan bulat."),
        ).toBeInTheDocument();

        expect(api.put).not.toHaveBeenCalled();
    });

    it("validates the adjustment form, then posts the difference and opens the new payroll", async () => {
        await renderDetail("hr", makePayroll(FINAL));
        api.post.mockResolvedValue({ data: { data: { id: 12 } } });

        fireEvent.click(button("Buat penyesuaian"));
        fireEvent.click(button("Buat penyesuaian"));

        expect(await screen.findByText("Wajib diisi.")).toBeInTheDocument();
        expect(
            screen.getByText("Isi dengan bilangan bulat."),
        ).toBeInTheDocument();

        change("Catatan", "Koreksi BPJS");
        change("Nominal 1", "0");
        fireEvent.click(button("Buat penyesuaian"));
        expect(
            await screen.findByText("Nominal koreksi tidak boleh nol."),
        ).toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();

        change("Nominal 1", "-100000");
        fireEvent.click(button("Buat penyesuaian"));

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith(
                "/api/payrolls/7/adjustments",
                {
                    note: "Koreksi BPJS",
                    items: [
                        { type: ALL_TYPES[0], label: null, amount: -100000 },
                    ],
                },
            ),
        );
        await waitFor(() =>
            expect(screen.getByTestId("location")).toHaveTextContent(
                "/payroll/12",
            ),
        );
    });
});
