import { Navigate, Route, Routes } from "react-router-dom";
import ProtectedRoute from "./auth/ProtectedRoute";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Employees from "./pages/Employees";
import EmployeeDetail from "./pages/EmployeeDetail";
import EmployeeForm from "./pages/EmployeeForm";
import Attendance from "./pages/Attendance";
import Leave from "./pages/Leave";
import Layout from "./components/Layout";

export default function App() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route element={<ProtectedRoute/>}>
                <Route element={<Layout />}>
                    <Route path="/" element={<Dashboard />} />
                    <Route path="/employees" element={<Employees />} />
                    <Route path="/employees/new" element={<EmployeeForm />} />
                    <Route path="/employees/:id" element={<EmployeeDetail />} />
                    <Route path="/employees/:id/edit" element={<EmployeeForm />} />
                    <Route path="/attendance" element={<Attendance />} />
                    <Route path="/leave" element={<Leave />} />
                </Route>
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}
