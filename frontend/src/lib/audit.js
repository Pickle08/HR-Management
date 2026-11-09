import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useAuth } from "../auth/useAuth";
import { api } from "./api";

export function useAuditLogs({ page, action, from, to, enabled = true }) {
    const { user } = useAuth();

    return useQuery({
        queryKey: ["audit-logs", user.id, { page, action, from, to }],
        queryFn: async () =>
            (
                await api.get("/api/audit-logs", {
                    params: {
                        page,
                        action: action || undefined,
                        from: from || undefined,
                        to: to || undefined,
                    },
                })
            ).data, // { data: [...], meta: {...} }
        placeholderData: keepPreviousData,
        enabled,
    });
}

export function useAuditActions() {
    const { user } = useAuth();

    return useQuery({
        queryKey: ["audit-actions", user.id],
        queryFn: async () =>
            (await api.get("/api/audit-logs/actions")).data.data,
        staleTime: 60_000,
    });
}
