const ALL = ["super_admin", "atasan", "hr", "finance", "karyawan"];
const NOT_FINANCE = ["super_admin", "atasan", "hr", "karyawan"];

// Peta modul sesuai hak akses di backend. `built` diubah jadi true begitu halamannya ada.
// Ini cuma soal tampilan menu: yang menjaga data tetap policy di backend.
export const NAV_ITEMS = [
    { path: "/", label: "Dashboard", roles: ALL, built: true },
    { path: "/employees", label: "Karyawan", roles: NOT_FINANCE, built: true },
    { path: "/attendance", label: "Absensi", roles: NOT_FINANCE, built: true },
    { path: "/leave", label: "Cuti", roles: NOT_FINANCE, built: true },
    {
        path: "/payroll",
        label: "Payroll & Slip",
        roles: ["hr", "finance", "atasan", "karyawan"],
        built: true,
    },
    {
        path: "/reports",
        label: "Laporan",
        roles: ["hr", "finance", "atasan", "super_admin"],
    },
    {
        path: "/recruitment",
        label: "Rekrutmen",
        roles: ["hr", "super_admin"],
        built: false,
    },
    {
        path: "/organization",
        label: "Departemen & Jabatan",
        roles: ["hr", "super_admin"],
        built: false,
    },
    { path: "/accounts", label: "Akun", roles: ["super_admin"], built: false },
];

export const rolesFor = (path) =>
    NAV_ITEMS.find((item) => item.path === path)?.roles ?? [];

export const visibleNav = (role) =>
    NAV_ITEMS.filter((item) => item.built && item.roles.includes(role));

export const HR_ADMIN_ROLES = ["hr", "super_admin"];
