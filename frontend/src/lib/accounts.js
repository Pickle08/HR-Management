import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useAuth } from "../auth/useAuth";
import { api } from "./api";

export function useAccounts({ page, search, role }) {
    const { user } = useAuth();

    return useQuery({
        queryKey: ["accounts", user.id, page, search, role],
        queryFn: async () => {
            const { data } = await api.get("/api/accounts", {
                params: {
                    page,
                    search: search || undefined,
                    role: role || undefined,
                },
            });
            return data; // { data: [...], meta: {...} }
        },
        placeholderData: keepPreviousData,
    });
}
