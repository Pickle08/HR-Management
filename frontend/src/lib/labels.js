export const ROLE_LABEL = {
    super_admin: "Super Admin",
    atasan: "Atasan",
    hr: "HR",
    finance: "Finance",
    karyawan: "Karyawan",
};

export const ATTENDANCE_LABEL = {
    hadir: "Hadir",
    izin: "Izin",
    sakit: "Sakit",
    alpa: "Alpa",
};

export const EMPLOYMENT_LABEL = {
    tetap: "Tetap",
    kontrak: "Kontrak",
    magang: "Magang",
};

export const STAGE_LABEL = {
    applied: "Melamar",
    screening: "Seleksi",
    interview: "Wawancara",
    offer: "Penawaran",
};

// Samakan nilainya dengan backend: cat backend/app/Enums/LeaveStatus.php
export const LEAVE_STATUS_LABEL = {
    pending: "Menunggu",
    approved: "Disetujui",
    rejected: "Ditolak",
    cancelled: "Dibatalkan",
};

export const LEAVE_STATUS_TONE = { approved: "good", rejected: "bad" };

export const MONTH_LABEL = [
    "Januari",
    "Februari",
    "Maret",
    "April",
    "Mei",
    "Juni",
    "Juli",
    "Agustus",
    "September",
    "Oktober",
    "November",
    "Desember",
];

export const PAYROLL_STATUS_LABEL = { draft: "Draf", finalized: "Final" };

export const PAYROLL_STATUS_TONE = { finalized: "good" };

export const PAYROLL_ITEM_LABEL = {
    basic_salary: "Gaji pokok",
    allowance: "Tunjangan",
    bonus: "Bonus",
    overtime: "Lembur",
    deduction: "Potongan",
    bpjs: "BPJS",
    pph21: "PPh 21",
};

export const PAYROLL_DEDUCTION_TYPES = ["deduction", "bpjs", "pph21"];

export const FINANCE_ITEM_TYPES = ["bpjs", "pph21"];
