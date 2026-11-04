import { useState } from "react";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth } from "../auth/useAuth";

export default function Login() {
    const { user, login, notice } = useAuth();
    const location = useLocation();
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [error, setError] = useState("");
    const [submitting, setSubmitting] = useState(false);

    // Setelah login sukses, `user` terisi dan komponen ini redirect sendiri.
    if (user)
        return <Navigate to={location.state?.from?.pathname ?? "/"} replace />;

    async function handleSubmit(e) {
        e.preventDefault();
        setError("");
        setSubmitting(true);
        try {
            await login(email, password);
        } catch (err) {
            const status = err.response?.status;
            if (status === 422)
                setError(
                    err.response.data.errors?.email?.[0] ?? "Data tidak valid.",
                );
            else if (status === 429)
                setError("Terlalu banyak percobaan. Coba lagi sebentar.");
            else setError("Terjadi kesalahan. Coba lagi.");
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
            <form
                onSubmit={handleSubmit}
                className="w-full max-w-sm space-y-4 rounded-lg bg-white p-6 shadow">
                <h1 className="text-xl font-semibold">Masuk HRM</h1>

                {notice && !error && (
                    <p className="rounded bg-amber-50 p-2 text-sm text-amber-800">
                        {notice}
                    </p>
                )}
                {error && (
                    <p className="rounded bg-red-50 p-2 text-sm text-red-700">
                        {error}
                    </p>
                )}

                <label className="block text-sm">
                    Email
                    <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                        className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </label>

                <label className="block text-sm">
                    Password
                    <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        required
                        className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                    />
                </label>

                <button
                    type="submit"
                    disabled={submitting}
                    className="w-full rounded bg-slate-900 py-2 text-white disabled:opacity-50">
                    {submitting ? "Memproses..." : "Masuk"}
                </button>
            </form>
        </div>
    );
}
