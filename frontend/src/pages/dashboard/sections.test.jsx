import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { fmtDate, monthName, rupiah } from "../../lib/format";
import { ATTENDANCE_LABEL, ROLE_LABEL, STAGE_LABEL } from "../../lib/labels";
import {
    AdminSection,
    FinanceSection,
    HrSection,
    MeSection,
    TeamSection,
} from "./sections";

vi.mock("../../components/ui", () => ({
    Card: ({ title, children }) => (
        <section aria-label={title}>{children}</section>
    ),
    Empty: ({ children }) => <p>{children}</p>,
    StatRow: ({ children }) => <div>{children}</div>,
    Stat: ({ label, value, tone }) => (
        <div data-testid={`stat-${label}`} data-tone={tone ?? "default"}>
            {value}
        </div>
    ),
}));

// rupiah() bisa mengandung non-breaking space; DOM-nya sudah dinormalisasi.
const money = (value) => rupiah(value).replace(/\s/g, " ");

const card = (title) => within(screen.getByRole("region", { name: title }));
const stat = (scope, label) => scope.getByTestId(`stat-${label}`);

const ATT = Object.fromEntries(
    Object.keys(ATTENDANCE_LABEL).map((key, i) => [key, i + 1]),
);
const [FIRST_ATT] = Object.keys(ATTENDANCE_LABEL);

const ME = {
    employee: {
        full_name: "Kiki Karyawan",
        employee_number: "EMP-005",
        position: "Staff",
        department: "Operasional",
    },
    attendance_this_month: ATT,
    leave: {
        balances: [
            {
                leave_type: "Cuti Tahunan",
                entitled_days: 12,
                used_days: 3,
                pending_days: 2,
                remaining_days: 7,
            },
        ],
        pending_requests: 1,
        next_leave: {
            start_date: "2026-12-01",
            end_date: "2026-12-03",
            days: 3,
        },
    },
    latest_payslip: {
        month: 3,
        year: 2026,
        gross: 6_000_000,
        deductions: 300_000,
        net: 5_700_000,
        adjustments_net: -200_000,
    },
};

describe("MeSection", () => {
    it("tells the user to contact hr when the account has no employee", () => {
        render(<MeSection me={null} />);

        expect(
            screen.getByText(
                "Akun ini belum terhubung ke data karyawan. Hubungi HR.",
            ),
        ).toBeInTheDocument();
    });

    it("shows profile, attendance and leave balance", () => {
        render(<MeSection me={ME} />);

        const profile = card("Profil");
        expect(profile.getByText("Kiki Karyawan")).toBeInTheDocument();
        expect(
            profile.getByText("EMP-005 · Staff · Operasional"),
        ).toBeInTheDocument();

        expect(
            stat(card("Absensi bulan ini"), ATTENDANCE_LABEL[FIRST_ATT]),
        ).toHaveTextContent(String(ATT[FIRST_ATT]));

        const leave = card("Saldo cuti tahun ini");
        expect(leave.getByText("Cuti Tahunan")).toBeInTheDocument();
        expect(leave.getByText("7")).toBeInTheDocument();
        expect(
            leave.getByText("Pengajuan menunggu keputusan: 1"),
        ).toBeInTheDocument();
        expect(
            leave.getByText(
                `Cuti berikutnya: ${fmtDate("2026-12-01")} – ${fmtDate("2026-12-03")} (3 hari)`,
            ),
        ).toBeInTheDocument();
    });

    it("shows fallbacks for missing position, department, balances and next leave", () => {
        render(
            <MeSection
                me={{
                    ...ME,
                    employee: {
                        ...ME.employee,
                        position: null,
                        department: null,
                    },
                    leave: {
                        balances: [],
                        pending_requests: 0,
                        next_leave: null,
                    },
                }}
            />,
        );

        expect(card("Profil").getByText("EMP-005 · - · -")).toBeInTheDocument();
        const leave = card("Saldo cuti tahun ini");
        expect(
            leave.getByText("Jatah cuti belum diatur. Hubungi HR."),
        ).toBeInTheDocument();
        expect(leave.getByText("Cuti berikutnya: -")).toBeInTheDocument();
    });

    it("adds the adjustment to the net on the latest payslip", () => {
        render(<MeSection me={ME} />);

        const slip = card("Slip gaji terakhir");
        expect(slip.getByText(`${monthName(3)} 2026`)).toBeInTheDocument();
        expect(slip.getByText(money(6_000_000))).toBeInTheDocument();
        expect(slip.getByText(money(300_000))).toBeInTheDocument();
        expect(slip.getByText(money(5_700_000))).toBeInTheDocument();
        expect(slip.getByText(money(-200_000))).toBeInTheDocument();
        expect(slip.getByText("Neto setelah koreksi")).toBeInTheDocument();
        expect(slip.getByText(money(5_500_000))).toBeInTheDocument();
    });

    it("hides the correction rows when there is no adjustment", () => {
        render(
            <MeSection
                me={{
                    ...ME,
                    latest_payslip: {
                        ...ME.latest_payslip,
                        adjustments_net: 0,
                    },
                }}
            />,
        );

        const slip = card("Slip gaji terakhir");
        expect(slip.queryByText("Koreksi")).not.toBeInTheDocument();
        expect(
            slip.queryByText("Neto setelah koreksi"),
        ).not.toBeInTheDocument();
    });

    it("says there is no final payslip yet", () => {
        render(<MeSection me={{ ...ME, latest_payslip: null }} />);

        expect(
            card("Slip gaji terakhir").getByText("Belum ada slip yang final."),
        ).toBeInTheDocument();
    });
});

const TEAM = {
    size: 4,
    pending_leave: {
        count: 2,
        oldest: [
            {
                id: 1,
                employee: { full_name: "Siti Aminah" },
                leave_type: "Cuti Tahunan",
                start_date: "2026-12-01",
                end_date: "2026-12-02",
                days: 2,
            },
        ],
    },
    attendance_today: { by_status: ATT, not_recorded: 3 },
    on_leave_today: [
        {
            employee: { id: 9, full_name: "Budi Atasan" },
            end_date: "2026-11-12",
        },
    ],
};

describe("TeamSection", () => {
    it("tells that the account has no employee data", () => {
        render(<TeamSection team={null} />);

        expect(
            screen.getByText("Akun ini belum terhubung ke data karyawan."),
        ).toBeInTheDocument();
    });

    it("shows size, pending count with a warning and unrecorded attendance", () => {
        render(<TeamSection team={TEAM} />);

        const team = card("Tim saya");
        expect(stat(team, "Anggota aktif")).toHaveTextContent("4");
        expect(stat(team, "Cuti menunggu keputusan")).toHaveTextContent("2");
        expect(stat(team, "Cuti menunggu keputusan")).toHaveAttribute(
            "data-tone",
            "warn",
        );
        expect(
            stat(card("Absensi tim hari ini"), "Belum tercatat"),
        ).toHaveAttribute("data-tone", "warn");
    });

    it("lists pending requests and people on leave today", () => {
        render(<TeamSection team={TEAM} />);

        const pending = card("Pengajuan cuti menunggu (terlama dulu)");
        expect(pending.getByText("Siti Aminah")).toBeInTheDocument();
        expect(
            pending.getByText(
                `Cuti Tahunan · ${fmtDate("2026-12-01")} – ${fmtDate("2026-12-02")} · 2 hari`,
            ),
        ).toBeInTheDocument();

        const today = card("Sedang cuti hari ini");
        expect(today.getByText("Budi Atasan")).toBeInTheDocument();
        expect(
            today.getByText(`sampai ${fmtDate("2026-11-12")}`),
        ).toBeInTheDocument();
    });

    it("uses neutral tone and empty messages when nothing is pending", () => {
        render(
            <TeamSection
                team={{
                    ...TEAM,
                    pending_leave: { count: 0, oldest: [] },
                    attendance_today: { by_status: ATT, not_recorded: 0 },
                    on_leave_today: [],
                }}
            />,
        );

        expect(
            stat(card("Tim saya"), "Cuti menunggu keputusan"),
        ).toHaveAttribute("data-tone", "default");
        expect(
            stat(card("Absensi tim hari ini"), "Belum tercatat"),
        ).toHaveAttribute("data-tone", "default");
        expect(
            screen.getByText("Tidak ada pengajuan yang menunggu."),
        ).toBeInTheDocument();
        expect(screen.getByText("Tidak ada.")).toBeInTheDocument();
    });
});

const STAGES = Object.fromEntries(
    Object.keys(STAGE_LABEL).map((key, i) => [key, i + 10]),
);
const [FIRST_STAGE] = Object.keys(STAGE_LABEL);

const HR = {
    employees: {
        active: 40,
        inactive: 5,
        by_employment_status: { tetap: 30, kontrak: 10, tidak_dikenal: 1 },
        by_department: [
            { id: 1, name: "Operasional", active_employees: 25 },
            { id: 2, name: "Keuangan", active_employees: 15 },
        ],
    },
    leave: { pending_count: 3, on_leave_today: 2 },
    attendance_today: { by_status: ATT, not_recorded: 0 },
    recruitment: {
        open_openings: 6,
        pipeline: STAGES,
        accepted_awaiting_conversion: 2,
    },
    payroll: {
        period: { month: 3, year: 2026 },
        draft: 4,
        finalized: 20,
        missing: 0,
    },
};

describe("HrSection", () => {
    it("shows employee counts and the active headcount per department", () => {
        render(<HrSection hr={HR} />);

        const employees = card("Karyawan");
        expect(stat(employees, "Aktif")).toHaveTextContent("40");
        expect(stat(employees, "Nonaktif")).toHaveTextContent("5");
        expect(employees.getByText("Operasional")).toBeInTheDocument();
        expect(employees.getByText("25")).toBeInTheDocument();
        expect(employees.getByText("Keuangan")).toBeInTheDocument();
    });

    it("falls back to the raw key for an unknown employment status", () => {
        render(<HrSection hr={HR} />);

        expect(stat(card("Karyawan"), "tidak_dikenal")).toHaveTextContent("1");
    });

    it("warns on pending leave and on accepted candidates not yet converted", () => {
        render(<HrSection hr={HR} />);

        expect(stat(card("Cuti"), "Menunggu keputusan")).toHaveAttribute(
            "data-tone",
            "warn",
        );

        const recruitment = card("Rekrutmen");
        expect(stat(recruitment, "Lowongan dibuka")).toHaveTextContent("6");
        expect(stat(recruitment, STAGE_LABEL[FIRST_STAGE])).toHaveTextContent(
            String(STAGES[FIRST_STAGE]),
        );
        expect(
            stat(recruitment, "Diterima, belum jadi karyawan"),
        ).toHaveAttribute("data-tone", "warn");
    });

    it("shows the payroll process for the period the api returned", () => {
        render(<HrSection hr={HR} />);

        const payroll = card("Proses payroll");
        expect(payroll.getByText(`${monthName(3)} 2026`)).toBeInTheDocument();
        expect(stat(payroll, "Draft")).toHaveAttribute("data-tone", "warn");
        expect(stat(payroll, "Final (termasuk koreksi)")).toHaveTextContent(
            "20",
        );
        expect(stat(payroll, "Belum dibuat")).toHaveAttribute(
            "data-tone",
            "default",
        );
    });
});

describe("FinanceSection", () => {
    const FINANCE = {
        period: { month: 3, year: 2026 },
        draft: 0,
        finalized: 20,
        missing: 2,
        finalized_totals: {
            gross: 120_000_000,
            deductions: 6_000_000,
            net: 114_000_000,
        },
    };

    it("shows the process counts and the final totals", () => {
        render(<FinanceSection finance={FINANCE} />);

        const process = card("Proses payroll");
        expect(process.getByText(`${monthName(3)} 2026`)).toBeInTheDocument();
        expect(stat(process, "Belum dibuat")).toHaveAttribute(
            "data-tone",
            "warn",
        );

        const totals = card("Total payroll final");
        expect(totals.getByText(money(120_000_000))).toBeInTheDocument();
        expect(totals.getByText(money(6_000_000))).toBeInTheDocument();
        expect(totals.getByText(money(114_000_000))).toBeInTheDocument();
        expect(
            totals.getByText(
                "Payroll asli dan koreksinya dijumlahkan. Draft tidak ikut.",
            ),
        ).toBeInTheDocument();
    });
});

describe("AdminSection", () => {
    const [KNOWN_ROLE] = Object.keys(ROLE_LABEL);
    const ADMIN = {
        accounts: {
            total: 12,
            active: 10,
            locked: 1,
            by_role: { [KNOWN_ROLE]: 7, role_baru: 2 },
        },
        employees_without_account: 0,
        leave_awaiting_decision: 3,
    };

    it("shows account counts, role labels and a raw fallback for unknown roles", () => {
        render(<AdminSection admin={ADMIN} />);

        const accounts = card("Akun");
        expect(stat(accounts, "Total")).toHaveTextContent("12");
        expect(stat(accounts, "Terkunci")).toHaveAttribute("data-tone", "warn");
        expect(accounts.getByText(ROLE_LABEL[KNOWN_ROLE])).toBeInTheDocument();
        expect(accounts.getByText("role_baru")).toBeInTheDocument();
    });

    it("flags only the action items that need attention", () => {
        render(<AdminSection admin={ADMIN} />);

        const actions = card("Perlu tindakan");
        expect(stat(actions, "Karyawan aktif tanpa akun")).toHaveAttribute(
            "data-tone",
            "default",
        );
        expect(stat(actions, "Cuti menunggu keputusan Anda")).toHaveAttribute(
            "data-tone",
            "warn",
        );
    });
});
