import { Navigate, Route, Routes } from 'react-router-dom'
import { Shell } from './components/Shell'
import { useApp } from './store'
import { LoginPage } from './pages/Login'
import { DashboardPage } from './pages/Dashboard'
import { PipelinePage } from './pages/Pipeline'
import { CustomersPage } from './pages/Customers'
import { CustomerCardPage } from './pages/CustomerCard'
import { DeliveriesPage, InventoryPage, OrdersPage, ProgramsPage, SubscriptionsPage } from './pages/Operations'
import { AnalyticsPage, AuditPage, EmployeesPage, FinancePage, NotificationsPage, PayPage, SettingsPage, WhatsAppPage } from './pages/More'
import { Skeleton } from './components/ui'

function Guard() {
  const { user, ready } = useApp()
  if (!ready) return <div className="p-8"><Skeleton className="h-40" /></div>
  if (!user) return <Navigate to="/login" replace />
  return <Shell />
}

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/pay/:token" element={<PayPage />} />
      <Route element={<Guard />}>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/pipeline" element={<PipelinePage />} />
        <Route path="/customers" element={<CustomersPage />} />
        <Route path="/customers/:id" element={<CustomerCardPage />} />
        <Route path="/subscriptions" element={<SubscriptionsPage />} />
        <Route path="/deliveries" element={<DeliveriesPage />} />
        <Route path="/orders" element={<OrdersPage />} />
        <Route path="/inventory" element={<InventoryPage />} />
        <Route path="/programs" element={<ProgramsPage />} />
        <Route path="/employees" element={<EmployeesPage />} />
        <Route path="/finance" element={<FinancePage />} />
        <Route path="/whatsapp" element={<WhatsAppPage />} />
        <Route path="/analytics" element={<AnalyticsPage />} />
        <Route path="/notifications" element={<NotificationsPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="/audit" element={<AuditPage />} />
      </Route>
    </Routes>
  )
}
