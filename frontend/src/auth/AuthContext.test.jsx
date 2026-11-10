import { useContext } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../lib/api";
import { AuthProvider } from "./AuthContext";
import { AuthContext } from "./useAuth";

const session = vi.hoisted(() => ({ handler: null }));

vi.mock("../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn() },
    setSessionExpiredHandler: (fn) => {
        session.handler = fn;
    },
}));

const USER = { id: 1, name: "Hana HR", role: "hr" };

let ctx;

function Probe() {
    ctx = useContext(AuthContext);

    return <p>{ctx.user ? ctx.user.name : "guest"}</p>;
}

async function setup({ loggedIn = true } = {}) {
    api.get.mockImplementation(async (url) => {
        if (url === "/api/user") {
            if (loggedIn) return { data: USER };
            throw { response: { status: 401 } };
        }

        return { data: {} };
    });

    const client = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    render(
        <QueryClientProvider client={client}>
            <AuthProvider>
                <Probe />
            </AuthProvider>
        </QueryClientProvider>,
    );

    await screen.findByText(loggedIn ? USER.name : "guest");

    return client;
}

const seedOtherUserData = (client) => {
    client.setQueryData(["payroll", 1, "7"], { net: 5_700_000 });
    client.setQueryData(["dashboard", 1, null], { role: "hr" });
};

describe("AuthProvider", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        session.handler = null;
    });

    it("clears every cached query except the session on logout", async () => {
        const client = await setup();
        seedOtherUserData(client);
        api.post.mockResolvedValue({ data: {} });

        await act(() => ctx.logout());

        expect(api.post).toHaveBeenCalledWith("/api/logout");
        expect(client.getQueryData(["payroll", 1, "7"])).toBeUndefined();
        expect(client.getQueryData(["dashboard", 1, null])).toBeUndefined();
        expect(client.getQueryData(["me"])).toBeNull();
        expect(await screen.findByText("guest")).toBeInTheDocument();
    });

    it("still clears local state when the server session is already gone (401)", async () => {
        const client = await setup();
        seedOtherUserData(client);
        api.post.mockRejectedValue({ response: { status: 401 } });

        await act(() => ctx.logout());

        expect(client.getQueryData(["payroll", 1, "7"])).toBeUndefined();
        expect(await screen.findByText("guest")).toBeInTheDocument();
    });

    it("keeps the user and the cache when logout fails for another reason", async () => {
        const client = await setup();
        seedOtherUserData(client);
        api.post.mockRejectedValue({ response: { status: 500 } });

        await expect(act(() => ctx.logout())).rejects.toBeTruthy();

        expect(screen.getByText(USER.name)).toBeInTheDocument();
        expect(client.getQueryData(["payroll", 1, "7"])).toEqual({
            net: 5_700_000,
        });
    });

    it("clears the cache and shows a notice when the session expires", async () => {
        const client = await setup();
        seedOtherUserData(client);

        act(() => session.handler());

        expect(await screen.findByText("guest")).toBeInTheDocument();
        expect(ctx.notice).toBe("Sesi Anda berakhir. Silakan masuk lagi.");
        expect(client.getQueryData(["payroll", 1, "7"])).toBeUndefined();
    });

    it("passes the server message through when the account is locked", async () => {
        await setup();

        act(() => session.handler("Akun dinonaktifkan."));

        expect(ctx.notice).toBe("Akun dinonaktifkan.");
    });

    it("logs in after fetching the csrf cookie and clears the old notice", async () => {
        await setup({ loggedIn: false });
        act(() => session.handler());
        expect(ctx.notice).not.toBe("");
        api.post.mockResolvedValue({ data: USER });

        await act(() => ctx.login("hana@example.com", "rahasia"));

        expect(api.get).toHaveBeenCalledWith("/sanctum/csrf-cookie");
        expect(api.post).toHaveBeenCalledWith("/api/login", {
            email: "hana@example.com",
            password: "rahasia",
        });
        expect(await screen.findByText(USER.name)).toBeInTheDocument();
        expect(ctx.notice).toBe("");
    });
});
