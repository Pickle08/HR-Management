import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useAuth } from "../auth/useAuth";
import { api } from "./api";

// resource: 'departments' atau 'positions'
export function useOrganization(resource, { page, search }) {
    const { user } = useAuth();

    return useQuery({
        queryKey: ["organization", resource, user.id, page, search],
        queryFn: async () => {
            const { data } = await api.get(`/api/${resource}`, {
                params: { page, search: search || undefined },
            });
            return data; // { data: [...], meta: {...} }
        },
        placeholderData: keepPreviousData,
    });
}
