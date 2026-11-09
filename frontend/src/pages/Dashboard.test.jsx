import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../lib/api";
import { renderWithProviders } from "../test/utils";
import Dashboard from "./Dashboard";

const auth = vi.hoisted(() => ({ role: "hr", id: 1 }));

vi.mock("../lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: auth.id, role: auth.role } }),
}));
vi.mock("./dashboard/sections", () => ({
    AdminSection: () => <div>section-admin</div>,
    FinanceSection: () => <div>section-finance</div>,
    HrSection: () => <div>section-hr</div>,
    MeSection: () => <div>section-me</div>,
    TeamSection: () => <div>section-team</div>,
}));

const ME = { employee: null };

function renderDashboard(role, payload, id = 1) {
    auth.role = role;
    auth.id = id;
    api.get.mockResolvedValue({
        data: { data: { date: "2026-11-09", role, me: ME, ...payload } },
    });

    renderWithProviders(<Dashboard />, { route: "/", path: "/" });

    return screen.findByRole("heading", { level: 1 });
}

const has = (text) => screen.queryByText(text);

describe("Dashboard", () => {
    beforeEach(() => {
        api.get.mockReset();
    });

    it.each([
        [
            "hr",
            { hr: {} },
            ["section-hr", "section-me"],
            ["section-finance", "section-team", "section-admin"],
        ],
        [
            "finance",
            { finance: {} },
            ["section-finance", "section-me"],
            ["section-hr", "section-team", "section-admin"],
        ],
        [
            "atasan",
            { team: {} },
            ["section-team", "section-me"],
            ["section-hr", "section-finance", "section-admin"],
        ],
        [
            "karyawan",
            {},
            ["section-me"],
            ["section-hr", "section-finance", "section-team", "section-admin"],
        ],
        [
            "super_admin",
            { admin: {} },
            ["section-admin", "section-me"],
            ["section-hr", "section-finance", "section-team"],
        ],
    ])(
        "%s renders only the sections the api sent",
        async (role, payload, shown, hidden) => {
            await renderDashboard(role, payload);
            await screen.findByText("section-me");

            for (const text of shown) expect(has(text)).toBeInTheDocument();
            for (const text of hidden)
                expect(has(text)).not.toBeInTheDocument();
        },
    );

    it("never invents a payroll section the api did not send", async () => {
        await renderDashboard("atasan", { team: {} });
        await screen.findByText("section-team");

        expect(has("section-finance")).not.toBeInTheDocument();
    });

    it("shows the 'Data saya' title for everyone except karyawan", async () => {
        await renderDashboard("hr", { hr: {} });
        expect(await screen.findByText("Data saya")).toBeInTheDocument();
    });

    it("does not show the 'Data saya' title for karyawan", async () => {
        await renderDashboard("karyawan", {});
        await screen.findByText("section-me");

        expect(has("Data saya")).not.toBeInTheDocument();
    });

    it("sends the payroll period only for hr and finance", async () => {
        await renderDashboard("hr", { hr: {} });
        await screen.findByText("section-hr");

        const { params } = api.get.mock.calls[0][1];
        expect(params).toEqual({
            year: new Date().getFullYear(),
            month: new Date().getMonth() + 1,
        });
    });

    it.each(["atasan", "karyawan"])(
        "%s sends no period and has no picker",
        async (role) => {
            await renderDashboard(role, {});
            await screen.findByText("section-me");

            expect(api.get).toHaveBeenCalledWith("/api/dashboard", {
                params: undefined,
            });
            expect(
                screen.queryByLabelText("Periode payroll"),
            ).not.toBeInTheDocument();
        },
    );

    it("refetches with the new month when hr changes the period", async () => {
        await renderDashboard("hr", { hr: {} });
        await screen.findByText("section-hr");

        fireEvent.change(screen.getByLabelText("Periode payroll"), {
            target: { value: "1" },
        });

        await waitFor(() =>
            expect(api.get).toHaveBeenLastCalledWith("/api/dashboard", {
                params: { year: new Date().getFullYear(), month: 1 },
            }),
        );
    });

    it("shows an error box when the first load fails", async () => {
        auth.role = "hr";
        api.get.mockRejectedValue({ response: { status: 500, data: {} } });

        renderWithProviders(<Dashboard />, { route: "/", path: "/" });

        expect(
            await screen.findByRole("button", { name: /coba lagi|ulang/i }),
        ).toBeInTheDocument();
    });
});
