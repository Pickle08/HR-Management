export const ROLE_LABEL = {
  super_admin: 'Super Admin',
  atasan: 'Atasan',
  hr: 'HR',
  finance: 'Finance',
  karyawan: 'Karyawan',
}

export const ATTENDANCE_LABEL = { hadir: 'Hadir', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa' }

export const EMPLOYMENT_LABEL = { tetap: 'Tetap', kontrak: 'Kontrak', magang: 'Magang' }

export const STAGE_LABEL = {
  applied: 'Melamar',
  screening: 'Seleksi',
  interview: 'Wawancara',
  offer: 'Penawaran',
}

// Samakan nilainya dengan backend: cat backend/app/Enums/LeaveStatus.php
export const LEAVE_STATUS_LABEL = {
  pending: 'Menunggu',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
}

export const LEAVE_STATUS_TONE = { approved: 'good', rejected: 'bad' }
