import { Navigate, Route, Routes } from "react-router-dom";
import ProtectedRoute from "./auth/ProtectedRoute";
import RequireRole from "./auth/RequireRole";
import { HR_ADMIN_ROLES, rolesFor } from "./auth/nav";
import Layout from "./components/Layout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import EmployeeList from "./pages/employees/EmployeeList";
import EmployeeDetail from "./pages/employees/EmployeeDetail";
import EmployeeForm from "./pages/employees/EmployeeForm";
import Attendance from "./pages/Attendance";
import PayrollList from "./pages/payroll/PayrollList";
import PayrollDetail from "./pages/payroll/PayrollDetail";
import Leave from "./pages/Leave";

export default function App() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route element={<ProtectedRoute />}>
                <Route element={<Layout />}>
                    <Route path="/" element={<Dashboard />} />

                    <Route
                        element={
                            <RequireRole roles={rolesFor("/employees")} />
                        }>
                        <Route path="/employees" element={<EmployeeList />} />
                        <Route
                            path="/employees/:id"
                            element={<EmployeeDetail />}
                        />
                    </Route>

                    <Route element={<RequireRole roles={HR_ADMIN_ROLES} />}>
                        <Route
                            path="/employees/new"
                            element={<EmployeeForm />}
                        />
                        <Route
                            path="/employees/:id/edit"
                            element={<EmployeeForm />}
                        />
                    </Route>

                    <Route
                        element={
                            <RequireRole roles={rolesFor("/attendance")} />
                        }>
                        <Route path="/attendance" element={<Attendance />} />
                    </Route>

                    <Route element={<RequireRole roles={rolesFor("/leave")} />}>
                        <Route path="/leave" element={<Leave />} />
                    </Route>

                    <Route
                        element={<RequireRole roles={rolesFor("/payroll")} />}>
                        <Route path="/payroll" element={<PayrollList />} />
                        <Route
                            path="/payroll/:id"
                            element={<PayrollDetail />}
                        />
                    </Route>
                </Route>
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}
