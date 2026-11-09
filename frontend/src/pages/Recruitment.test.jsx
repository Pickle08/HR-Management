import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { api } from "../lib/api";
import { EMPLOYMENT_LABEL, STAGE_LABEL } from "../lib/labels";
import { renderWithProviders } from "../test/utils";
import Recruitment from "./Recruitment";

vi.mock("../lib/api", () => ({
    api: { get: vi.fn(), post: vi.fn() },
}));
vi.mock("../auth/useAuth", () => ({
    useAuth: () => ({ user: { id: 1, role: "hr" } }),
}));

const META = { current_page: 1, last_page: 1, per_page: 20, total: 2 };

const OPENINGS = [
    {
        id: 1,
        title: "Staff Operasional",
        department: { id: 1, name: "Operasional" },
        position: { id: 7, name: "Staff" },
        headcount: 2,
        description: null,
        status: "open",
        closed_at: null,
        candidates_count: 3,
        hired_count: 1,
    },
    {
        id: 2,
        title: "Staff Lama",
        department: { id: 1, name: "Operasional" },
        position: { id: 7, name: "Staff" },
        headcount: 1,
        description: null,
        status: "closed",
        closed_at: "2030-01-01T00:00:00+00:00",
        candidates_count: 1,
        hired_count: 1,
    },
];

const candidate = (over = {}) => ({
    id: 5,
    job_opening: { id: 1, title: "Staff Operasional" },
    full_name: "Calon Satu",
    email: "calon@example.com",
    phone: "0811111111",
    stage: "applied",
    converted_employee_id: null,
    history: [
        {
            from_stage: null,
            to_stage: "applied",
            by: { id: 1, name: "Hana HR" },
            at: "2030-02-15T09:00:00+00:00",
            note: null,
        },
    ],
    ...over,
});

function mockApi(cand = candidate()) {
    api.get.mockImplementation(async (url) => {
        if (url === "/api/job-openings")
            return { data: { data: OPENINGS, meta: META } };
        if (url === "/api/candidates")
            return { data: { data: [cand], meta: META } };
        if (url === `/api/candidates/${cand.id}`)
            return { data: { data: cand } };
        if (url === "/api/departments")
            return { data: { data: [{ id: 1, name: "Operasional" }] } };
        if (url === "/api/positions")
            return { data: { data: [{ id: 7, name: "Staff" }] } };
        if (url === "/api/employees/managers")
            return {
                data: {
                    data: [
                        {
                            id: 3,
                            employee_number: "E1",
                            full_name: "Budi Atasan",
                        },
                    ],
                },
            };

        return { data: { data: [] } };
    });
}

const label = (name) => screen.getByLabelText(name);
const button = (name) => screen.queryByRole("button", { name });
const change = (name, value) =>
    fireEvent.change(label(name), { target: { value } });

// Sama dengan komponen: kalau label tahap belum ada, yang tampil kode mentah.
const moveName = (stage) => `Pindahkan ke ${STAGE_LABEL[stage] ?? stage}`;

const unprocessable = (errors) => ({
    response: { status: 422, data: { errors } },
});

async function renderTab(tab = "Lowongan") {
    renderWithProviders(<Recruitment />, {
        route: "/recruitment",
        path: "/recruitment",
    });
    await screen.findByRole("heading", { level: 1, name: "Rekrutmen" });
    fireEvent.click(screen.getByRole("tab", { name: tab }));
}

// Select lowongan di form kandidat terisi dari request sendiri; tunggu di dalamnya,
// bukan di seluruh halaman (filter lowongan sudah punya opsi yang sama lebih dulu).
async function waitForOpeningOptions() {
    await within(label("Lowongan")).findByRole("option", {
        name: "Staff Operasional",
    });
}

async function openDetail(cand) {
    mockApi(cand);
    await renderTab("Kandidat");
    fireEvent.click(await screen.findByRole("button", { name: "Detail" }));
    await screen.findByRole("heading", { name: "Detail kandidat" });
}

async function fillConversion() {
    change("Nomor karyawan", "EMP-900");
    change("Tanggal masuk", "2030-03-01");
    change("Status kerja", Object.keys(EMPLOYMENT_LABEL)[0]);
    fireEvent.click(button("Konversi"));
}

describe("Recruitment", () => {
    beforeEach(() => {
        api.get.mockReset();
        api.post.mockReset();
        vi.spyOn(window, "confirm").mockReturnValue(true);
        mockApi();
    });

    describe("openings", () => {
        it("lists openings with filled quota and offers close only for open ones", async () => {
            await renderTab();

            const open = await screen.findByRole("row", {
                name: /Staff Operasional/,
            });
            expect(within(open).getByText("1/2")).toBeInTheDocument();
            expect(within(open).getByText("Dibuka")).toBeInTheDocument();
            expect(
                within(open).getByRole("button", { name: "Tutup lowongan" }),
            ).toBeInTheDocument();

            const closed = screen.getByRole("row", { name: /Staff Lama/ });
            expect(within(closed).getByText("Ditutup")).toBeInTheDocument();
            expect(
                within(closed).queryByRole("button", {
                    name: "Tutup lowongan",
                }),
            ).not.toBeInTheDocument();
        });

        it("closes an opening only after confirmation", async () => {
            api.post.mockResolvedValue({ data: {} });
            await renderTab();
            const row = await screen.findByRole("row", {
                name: /Staff Operasional/,
            });

            window.confirm.mockReturnValueOnce(false);
            fireEvent.click(
                within(row).getByRole("button", { name: "Tutup lowongan" }),
            );
            expect(api.post).not.toHaveBeenCalled();

            fireEvent.click(
                within(row).getByRole("button", { name: "Tutup lowongan" }),
            );
            await waitFor(() =>
                expect(api.post).toHaveBeenCalledWith(
                    "/api/job-openings/1/close",
                ),
            );
        });

        it("shows the server message when closing is refused with 409", async () => {
            api.post.mockRejectedValue({
                response: {
                    status: 409,
                    data: { message: "Lowongan sudah ditutup." },
                },
            });
            await renderTab();
            const row = await screen.findByRole("row", {
                name: /Staff Operasional/,
            });

            fireEvent.click(
                within(row).getByRole("button", { name: "Tutup lowongan" }),
            );

            expect(await screen.findByRole("alert")).toHaveTextContent(
                "Lowongan sudah ditutup.",
            );
        });

        it("validates the opening form before calling the api", async () => {
            await renderTab();
            fireEvent.click(
                await screen.findByRole("button", { name: "Tambah lowongan" }),
            );
            await screen.findByRole("option", { name: "Operasional" });

            change("Kuota", "101");
            fireEvent.click(button("Simpan"));

            expect(await screen.findAllByText("Wajib diisi.")).toHaveLength(3);
            expect(
                screen.getByText("Kuota harus 1 sampai 100."),
            ).toBeInTheDocument();
            expect(api.post).not.toHaveBeenCalled();
        });

        it("posts a trimmed opening with numeric ids", async () => {
            api.post.mockResolvedValue({ data: { data: { id: 9 } } });
            await renderTab();
            fireEvent.click(
                await screen.findByRole("button", { name: "Tambah lowongan" }),
            );
            await screen.findByRole("option", { name: "Operasional" });

            change("Judul", " Staff Finance ");
            change("Departemen", "1");
            change("Jabatan", "7");
            change("Kuota", "2");
            fireEvent.click(button("Simpan"));

            await waitFor(() =>
                expect(api.post).toHaveBeenCalledWith("/api/job-openings", {
                    title: "Staff Finance",
                    department_id: 1,
                    position_id: 7,
                    headcount: 2,
                    description: null,
                }),
            );
        });
    });

    describe("candidates", () => {
        it("lists candidates and sends the stage filter to the api", async () => {
            await renderTab("Kandidat");

            const row = await screen.findByRole("row", { name: /Calon Satu/ });
            expect(
                within(row).getByText("calon@example.com"),
            ).toBeInTheDocument();

            change("Tahap", "offer");

            await waitFor(() =>
                expect(api.get).toHaveBeenLastCalledWith("/api/candidates", {
                    params: {
                        stage: "offer",
                        job_opening_id: undefined,
                        search: undefined,
                        page: 1,
                    },
                }),
            );
        });

        it("sends the opening filter to the api", async () => {
            await renderTab("Kandidat");
            await screen.findByRole("row", { name: /Calon Satu/ });
            await within(label("Filter lowongan")).findByRole("option", {
                name: "Staff Operasional",
            });

            change("Filter lowongan", "1");

            await waitFor(() =>
                expect(api.get).toHaveBeenLastCalledWith("/api/candidates", {
                    params: {
                        stage: undefined,
                        job_opening_id: "1",
                        search: undefined,
                        page: 1,
                    },
                }),
            );
        });

        it("adds a candidate with a trimmed payload and null for an empty phone", async () => {
            api.post.mockResolvedValue({ data: { data: { id: 8 } } });
            await renderTab("Kandidat");
            fireEvent.click(
                await screen.findByRole("button", { name: "Tambah kandidat" }),
            );
            await waitForOpeningOptions();

            change("Lowongan", "1");
            change("Nama lengkap", " Budi Calon ");
            change("Email", "budi@example.com");
            fireEvent.click(button("Simpan"));

            await waitFor(() =>
                expect(api.post).toHaveBeenCalledWith("/api/candidates", {
                    job_opening_id: 1,
                    full_name: "Budi Calon",
                    email: "budi@example.com",
                    phone: null,
                }),
            );
        });

        it("shows a duplicate email error under the email field", async () => {
            api.post.mockRejectedValue(
                unprocessable({
                    email: [
                        "Kandidat dengan email ini sudah melamar lowongan ini.",
                    ],
                }),
            );
            await renderTab("Kandidat");
            fireEvent.click(
                await screen.findByRole("button", { name: "Tambah kandidat" }),
            );
            await waitForOpeningOptions();

            change("Lowongan", "1");
            change("Nama lengkap", "Budi");
            change("Email", "calon@example.com");
            fireEvent.click(button("Simpan"));

            expect(
                await screen.findByText(
                    "Kandidat dengan email ini sudah melamar lowongan ini.",
                ),
            ).toBeInTheDocument();
        });
    });

    describe("detail", () => {
        it.each([
            ["applied", ["screening"], true],
            ["screening", ["interview"], true],
            ["interview", ["offer"], true],
            ["offer", ["accepted"], true],
            ["accepted", [], false],
            ["rejected", [], false],
        ])(
            "a candidate at %s can move to %j (reject: %s)",
            async (stage, targets, reject) => {
                await openDetail(candidate({ stage }));

                for (const target of [
                    "screening",
                    "interview",
                    "offer",
                    "accepted",
                ]) {
                    if (targets.includes(target)) {
                        expect(button(moveName(target))).toBeInTheDocument();
                    } else {
                        expect(
                            button(moveName(target)),
                        ).not.toBeInTheDocument();
                    }
                }

                if (reject) {
                    expect(button("Tolak")).toBeInTheDocument();
                } else {
                    expect(button("Tolak")).not.toBeInTheDocument();
                }
            },
        );

        it("shows the stage history with who made the move", async () => {
            await openDetail(candidate());

            expect(screen.getByText(/Hana HR/)).toBeInTheDocument();
        });

        it("moves one stage forward without sending a note", async () => {
            api.post.mockResolvedValue({ data: {} });
            await openDetail(candidate());

            fireEvent.click(button(moveName("screening")));

            await waitFor(() =>
                expect(api.post).toHaveBeenCalledWith(
                    "/api/candidates/5/move",
                    { to_stage: "screening" },
                ),
            );
        });

        it("requires a note to reject, then sends it", async () => {
            api.post.mockResolvedValue({ data: {} });
            await openDetail(candidate({ stage: "interview" }));

            fireEvent.click(button("Tolak"));
            fireEvent.click(button("Konfirmasi tolak"));
            expect(await screen.findByText("Wajib diisi.")).toBeInTheDocument();
            expect(api.post).not.toHaveBeenCalled();

            change("Alasan penolakan", " Kurang sesuai ");
            fireEvent.click(button("Konfirmasi tolak"));

            await waitFor(() =>
                expect(api.post).toHaveBeenCalledWith(
                    "/api/candidates/5/move",
                    { to_stage: "rejected", note: "Kurang sesuai" },
                ),
            );
        });

        it("shows the server message when a move is refused with 409", async () => {
            api.post.mockRejectedValue({
                response: {
                    status: 409,
                    data: {
                        message:
                            "Kandidat di tahap offer tidak bisa dipindah ke accepted.",
                    },
                },
            });
            await openDetail(candidate());

            fireEvent.click(button(moveName("screening")));

            expect(await screen.findByRole("alert")).toHaveTextContent(
                "Kandidat di tahap offer tidak bisa dipindah ke accepted.",
            );
            expect(button(moveName("screening"))).toBeEnabled();
        });
    });

    describe("conversion", () => {
        it("offers no conversion form before the candidate is accepted", async () => {
            await openDetail(candidate({ stage: "offer" }));

            expect(
                screen.queryByText("Konversi menjadi karyawan"),
            ).not.toBeInTheDocument();
        });

        it("offers the conversion form to an accepted candidate", async () => {
            await openDetail(candidate({ stage: "accepted" }));

            expect(
                screen.getByText("Konversi menjadi karyawan"),
            ).toBeInTheDocument();
        });

        it("links to the employee instead of the form once converted", async () => {
            await openDetail(
                candidate({ stage: "accepted", converted_employee_id: 42 }),
            );

            expect(
                screen.getByRole("link", { name: "karyawan" }),
            ).toHaveAttribute("href", "/employees/42");
            expect(
                screen.queryByText("Konversi menjadi karyawan"),
            ).not.toBeInTheDocument();
        });

        it("requires the three mandatory fields before calling the api", async () => {
            await openDetail(candidate({ stage: "accepted" }));

            fireEvent.click(button("Konversi"));

            expect(await screen.findAllByText("Wajib diisi.")).toHaveLength(3);
            expect(api.post).not.toHaveBeenCalled();
        });

        it("posts the conversion with nulls for empty optional fields", async () => {
            api.post.mockResolvedValue({ data: {} });
            await openDetail(candidate({ stage: "accepted" }));
            const [status] = Object.keys(EMPLOYMENT_LABEL);

            change("Nomor karyawan", " EMP-900 ");
            change("Tanggal masuk", "2030-03-01");
            change("Status kerja", status);
            fireEvent.click(button("Konversi"));

            await waitFor(() =>
                expect(api.post).toHaveBeenCalledWith(
                    "/api/candidates/5/convert",
                    {
                        employee_number: "EMP-900",
                        hire_date: "2030-03-01",
                        employment_status: status,
                        manager_id: null,
                        nik: null,
                        birth_date: null,
                        address: null,
                    },
                ),
            );
        });

        it("shows a taken employee number under its field", async () => {
            api.post.mockRejectedValue(
                unprocessable({
                    employee_number: ["Nomor karyawan sudah dipakai."],
                }),
            );
            await openDetail(candidate({ stage: "accepted" }));

            await fillConversion();

            expect(
                await screen.findByText("Nomor karyawan sudah dipakai."),
            ).toBeInTheDocument();
        });

        it("shows a full quota as a general alert", async () => {
            api.post.mockRejectedValue(
                unprocessable({
                    job_opening_id: ["Kuota lowongan sudah terpenuhi."],
                }),
            );
            await openDetail(candidate({ stage: "accepted" }));

            await fillConversion();

            expect(await screen.findByRole("alert")).toHaveTextContent(
                "Kuota lowongan sudah terpenuhi.",
            );
        });
    });
});
