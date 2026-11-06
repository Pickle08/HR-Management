import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'

// Menampilkan URL saat ini supaya test bisa memeriksa perubahan query string.
function LocationProbe() {
  const location = useLocation()

  return <output data-testid="location">{location.pathname + location.search}</output>
}

export function renderWithProviders(ui, { route = '/', path = '*' } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route path={path} element={ui} />
        </Routes>
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
