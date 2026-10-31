import { createContext, useContext } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "../lib/api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const queryClient = useQueryClient();

    // Satu sumber kebenaran: siapa yang sedang login menurut server.
    const { data: user, isLoading } = useQuery({
        queryKey: ["me"],
        queryFn: async () => {
            try {
                const { data } = await api.get("/api/user");
                return data;
            } catch (err) {
                if (err.response?.status === 401) return null;
                throw err;
            }
        },
        staleTime: Infinity,
        retry: false,
    });

    async function login(email, password) {
        await api.get("/sanctum/csrf-cookie");
        const { data } = await api.post("/api/login", { email, password });
        queryClient.setQueryData(["me"], data);
    }

    async function logout() {
        try {
            await api.post("/api/logout");
        } catch (err) {
            // 401 = session sudah habis di server, tetap lanjut bersihin state lokal.
            if (err.response?.status !== 401) throw err;
        }

        // Set 'me' ke null DULU supaya observer ke-notify dan UI re-render,
        // baru buang cache lain biar data user sebelumnya tidak bocor.
        queryClient.setQueryData(["me"], null);
        queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== "me" });
    }

    return (
        <AuthContext.Provider
            value={{ user: user ?? null, isLoading, login, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    return useContext(AuthContext);
}
