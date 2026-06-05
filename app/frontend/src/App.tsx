import { lazy, Suspense } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ThemeProvider } from '@/lib/theme'
import { ToastProvider } from '@/components/ui/Toast'
import { AppShell } from '@/components/layout/AppShell'
import { RequireAdmin } from '@/components/RequireAdmin'
import { ReauthProvider } from '@/components/ReauthProvider'
import { LocaleProvider } from '@/i18n/LocaleContext'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import Dashboard from '@/routes/Dashboard'

const Calendar = lazy(() => import('@/routes/Calendar'))
const Contacts = lazy(() => import('@/routes/Contacts'))
const UserSharing = lazy(() => import('@/routes/Sharing'))
const PublicLinks = lazy(() => import('@/routes/PublicLinks'))
const ImportExport = lazy(() => import('@/routes/ImportExport'))
const Account = lazy(() => import('@/routes/Account'))
const AdminStatus = lazy(() => import('@/routes/admin/AdminStatus'))
const Users = lazy(() => import('@/routes/admin/Users'))
const Collections = lazy(() => import('@/routes/admin/Collections'))
const Sharing = lazy(() => import('@/routes/admin/Sharing'))
const Backups = lazy(() => import('@/routes/admin/Backups'))
const Activity = lazy(() => import('@/routes/admin/Activity'))
const Settings = lazy(() => import('@/routes/admin/Settings'))
const Security = lazy(() => import('@/routes/admin/Security'))

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 30_000 } },
})

const admin = (el: React.ReactNode) => (
  <RequireAdmin>
    <Suspense fallback={null}>{el}</Suspense>
  </RequireAdmin>
)

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <LocaleProvider>
      <ThemeProvider>
        <ToastProvider>
          <ReauthProvider>
            <BrowserRouter basename="/app">
              <ErrorBoundary>
              <Routes>
                <Route element={<AppShell />}>
                  <Route index element={<Dashboard />} />
                  <Route path="calendar" element={<Calendar />} />
                  <Route path="contacts" element={<Contacts />} />
                  <Route path="sharing" element={<UserSharing />} />
                  <Route path="links" element={<PublicLinks />} />
                  <Route path="import" element={<ImportExport />} />
                  <Route path="account" element={<Account />} />

                  <Route path="admin" element={admin(<AdminStatus />)} />
                  <Route path="admin/users" element={admin(<Users />)} />
                  <Route path="admin/collections" element={admin(<Collections />)} />
                  <Route path="admin/sharing" element={admin(<Sharing />)} />
                  <Route path="admin/backups" element={admin(<Backups />)} />
                  <Route path="admin/activity" element={admin(<Activity />)} />
                  <Route path="admin/security" element={admin(<Security />)} />
                  <Route path="admin/settings" element={admin(<Settings />)} />

                  <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
              </Routes>
              </ErrorBoundary>
            </BrowserRouter>
          </ReauthProvider>
        </ToastProvider>
      </ThemeProvider>
      </LocaleProvider>
    </QueryClientProvider>
  )
}
