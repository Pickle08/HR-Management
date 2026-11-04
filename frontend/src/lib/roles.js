// Hanya soal tampilan: yang menjaga akses tetap policy di backend.
export const isHrAdmin = (role) => role === 'hr' || role === 'super_admin'
