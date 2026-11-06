import { AxiosError } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, setSessionExpiredHandler } from './api'

// Adapter palsu: tiap URL punya daftar respons berurutan (percobaan ke-1, ke-2, ...).
function scripted(steps) {
  const calls = []
  const seen = {}

  const adapter = async (config) => {
    calls.push(config.url)
    seen[config.url] = (seen[config.url] ?? 0) + 1

    const list = steps[config.url]
    const step = list[Math.min(seen[config.url], list.length) - 1]
    const response = {
      data: step.data ?? {},
      status: step.status,
      statusText: '',
      headers: {},
      config,
      request: {},
    }

    if (step.status < 400) return response

    throw new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, {}, response)
  }

  return { adapter, calls }
}

describe('api interceptor', () => {
  const handler = vi.fn()

  beforeEach(() => {
    handler.mockReset()
    setSessionExpiredHandler(handler)
  })

  afterEach(() => setSessionExpiredHandler(null))

  it('reports an expired session on 401 from a normal endpoint', async () => {
    const { adapter } = scripted({ '/api/payrolls': [{ status: 401, data: { message: 'Unauthenticated.' } }] })
    api.defaults.adapter = adapter

    await expect(api.get('/api/payrolls')).rejects.toMatchObject({ response: { status: 401 } })

    expect(handler).toHaveBeenCalledTimes(1)
    expect(handler).toHaveBeenCalledWith(undefined)
  })

  it('passes the locked-account message through', async () => {
    const { adapter } = scripted({ '/api/payrolls': [{ status: 401, data: { message: 'Akun dinonaktifkan.' } }] })
    api.defaults.adapter = adapter

    await expect(api.get('/api/payrolls')).rejects.toBeDefined()

    expect(handler).toHaveBeenCalledWith('Akun dinonaktifkan.')
  })

  it('treats 401 on the initial session check and on login as expected', async () => {
    const { adapter } = scripted({
      '/api/user': [{ status: 401 }],
      '/api/login': [{ status: 401 }],
    })
    api.defaults.adapter = adapter

    await expect(api.get('/api/user')).rejects.toBeDefined()
    await expect(api.post('/api/login')).rejects.toBeDefined()

    expect(handler).not.toHaveBeenCalled()
  })

  it('refreshes the csrf cookie and retries once on 419', async () => {
    const { adapter, calls } = scripted({
      '/api/payrolls': [{ status: 419 }, { status: 200, data: { ok: true } }],
      '/sanctum/csrf-cookie': [{ status: 204 }],
    })
    api.defaults.adapter = adapter

    const response = await api.post('/api/payrolls')

    expect(response.data).toEqual({ ok: true })
    expect(calls).toEqual(['/api/payrolls', '/sanctum/csrf-cookie', '/api/payrolls'])
    expect(handler).not.toHaveBeenCalled()
  })

  it('does not retry forever when 419 persists', async () => {
    const { adapter, calls } = scripted({
      '/api/payrolls': [{ status: 419 }, { status: 419 }],
      '/sanctum/csrf-cookie': [{ status: 204 }],
    })
    api.defaults.adapter = adapter

    await expect(api.post('/api/payrolls')).rejects.toMatchObject({ response: { status: 419 } })

    expect(calls).toEqual(['/api/payrolls', '/sanctum/csrf-cookie', '/api/payrolls'])
  })
})
