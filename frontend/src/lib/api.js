import axios from 'axios'

export const api = axios.create({
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  withXSRFToken: true,
})

let onSessionExpired = null

export function setSessionExpiredHandler(handler) {
  onSessionExpired = handler
}

// 401 di endpoint ini memang diharapkan (cek sesi awal), bukan "sesi berakhir".
const SILENT_401 = ['/api/user', '/api/login']
const LOCKED_MESSAGE = 'Akun dinonaktifkan.'

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const { response, config } = error

    // 419: token CSRF kedaluwarsa. Ambil yang baru, ulangi sekali.
    if (response?.status === 419 && config && !config._retried) {
      config._retried = true
      await api.get('/sanctum/csrf-cookie')
      return api(config)
    }

    if (response?.status === 401 && !SILENT_401.includes(config?.url)) {
      const locked = response.data?.message === LOCKED_MESSAGE
      onSessionExpired?.(locked ? LOCKED_MESSAGE : undefined)
    }

    return Promise.reject(error)
  },
)
