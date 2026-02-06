<?php
/**
 * Spacemount WorkHub - CORE ENGINE (STRICT PRODUCTION VERSION)
 * File: core.php
 * Final Fix: Column names synced with database.sql (attendance_date, slot_type, etc.)
 */

namespace Spacemount\WorkHub;

use mysqli;
use Exception;
use DateTime;

// 1. GLOBAL INITIALIZATION & DEBUGGING
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// AS PER YOUR REQUEST: Debugging Enabled
error_reporting(1);
ini_set('display_errors', 1);

class CoreEngine {
    private static $instance = null;
    public $conn;
    private $config = [];

    private function __construct() {
        $this->loadEnv();
        $this->connectDB();
        date_default_timezone_set($this->config['TIMEZONE'] ?? 'Asia/Kolkata');
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadEnv() {
        $path = __DIR__ . '/.env';
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (empty($line) || strpos($line, '=') === false || strpos(trim($line), '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                $this->config[trim($name)] = trim($value);
            }
        }
    }

    private function connectDB() {
        $this->conn = new mysqli(
            $this->config['DB_HOST'] ?? 'localhost',
            $this->config['DB_USER'] ?? 'root',
            $this->config['DB_PASS'] ?? '',
            $this->config['DB_NAME'] ?? 'attendance_system'
        );
        if ($this->conn->connect_error) {
            die("Critical: Database Offline.");
        }
        $this->conn->set_charset("utf8mb4");
    }

    // 2. SECURITY & AUTH
   public function login($employeeId, $pin) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE employee_id = ? AND status = 'Active'");
        $stmt->bind_param("s", $employeeId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
    
        if ($user && password_verify($pin, $user['pin_hash'])) {
            // CSRF Token ko login se pehle pakad lo
            $existingToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
            
            session_regenerate_id(true); // Naya session ID
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_data'] = $user;
            $_SESSION['csrf_token'] = $existingToken; // Token wapas set karo
            
            $this->logAction($user['id'], null, 'Security', 'LOGIN_SUCCESS', "User logged in.");
            return true;
        }
        return false;
    }

    public function requireAuth() {
        if (!isset($_SESSION['user_id'])) throw new Exception("Session Expired.");
    }

    public function requireAdmin() {
        $this->requireAuth();
        if ($_SESSION['role'] !== 'Admin') throw new Exception("Unauthorized.");
    }

    public function logout() {
        session_destroy();
        header("Location: index.php");
        exit;
    }

    public function generateCSRF() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function getCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function validateCSRF() {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            // Purana token clear karo taaki refresh par naya mile
            unset($_SESSION['csrf_token']);
            throw new Exception("Security Token Expired. Please refresh the page and try again.");
        }
    }

    // 3. SYSTEM CONFIGURATION (FIXES Line 71 ERROR)
    public function getConfig() {
        return $this->config;
    }

    // 4. ATTENDANCE ENGINE (Synced with database.sql)
    public function getActiveAttendance($userId, $date) {
        $stmt = $this->conn->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? AND punch_out_time IS NULL");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

   public function punchIn($slot, $lat, $lng, $photo) {
        $userId = $_SESSION['user_id'];
        $today = date('Y-m-d');
        // ... logic ...
        $stmt = $this->conn->prepare("INSERT INTO attendance (user_id, attendance_date, slot_type, punch_in_time, punch_in_lat, punch_in_lng, punch_in_photo) VALUES (?, ?, ?, NOW(), ?, ?, ?)");
        
        // Yahan 6 characters hain ("isssss") aur 6 variables hain. Ye sahi hai.
        $stmt->bind_param("isssss", $userId, $today, $slot, $lat, $lng, $photo);
        // ... logic ...
    }

    public function punchOut($lat, $lng, $summary) {
        $userId = $_SESSION['user_id'];
        $today = date('Y-m-d');
        
        // Active session dhoondna zaroori hai ID ke liye
        $session = $this->getActiveAttendance($userId, $today);
        if (!$session) throw new Exception("No active session found.");
    
        // Day count calculation
        $dayCount = $this->calculateDayCount($session['punch_in_time'], date('Y-m-d H:i:s'), $session['slot_type']);
    
        // SQL: 5 placeholders (?, ?, ?, ?, ?)
        $stmt = $this->conn->prepare("UPDATE attendance SET punch_out_time = NOW(), punch_out_lat = ?, punch_out_lng = ?, work_summary = ?, day_count = ? WHERE id = ?");
        
        // FIX: 5 variables ($lat, $lng, $summary, $dayCount, $session['id'])
        // Types: "s" (lat), "s" (lng), "s" (summary), "d" (dayCount), "i" (id)
        $stmt->bind_param("sssdi", $lat, $lng, $summary, $dayCount, $session['id']);
        
        if ($stmt->execute()) {
            $this->logAction($userId, null, 'Attendance', 'PUNCH_OUT', "Shift ended. Day count: $dayCount");
            return true;
        }
        return false;
    }

    private function calculateDayCount($in, $out, $slot) {
        if ($slot === 'Site Visit') return 1.0;
        $t1 = new DateTime($in); $t2 = new DateTime($out);
        $hours = $t1->diff($t2)->h + ($t1->diff($t2)->i / 60);

        // Late Policy: 10:00-10:15 AM
        if ($t1->format('H:i:s') > '10:00:00' && $t1->format('H:i:s') <= '10:15:00' && $t2->format('H:i:s') < '19:00:00') {
            return ($hours >= 4) ? 0.5 : 0.0;
        }
        return ($hours >= 8) ? 1.0 : (($hours >= 4) ? 0.5 : 0.0);
    }

    public function runAutoPunchOut() {
        $stmt = $this->conn->prepare("SELECT * FROM attendance WHERE punch_out_time IS NULL AND attendance_date < CURDATE()");
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dc = ($row['slot_type'] === 'Site Visit') ? 1.0 : 0.0;
            $upd = $this->conn->prepare("UPDATE attendance SET punch_out_time = CONCAT(attendance_date, ' 23:59:59'), day_count = ?, is_auto_punch_out = 1, work_summary = '[AUTO-SYSTEM]' WHERE id = ?");
            $upd->bind_param("di", $dc, $row['id']);
            $upd->execute();
        }
    }

    // 5. ADMIN DASHBOARD & REPORTING
    public function getAdminDashboardStats() {
        $res = [];
        $res['active_employees'] = $this->conn->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetch_row()[0] ?? 0;
        $res['punched_in_today'] = $this->conn->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND punch_out_time IS NULL")->fetch_row()[0] ?? 0;
        $res['pending_leaves'] = $this->conn->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
        $res['pending_expenses'] = $this->conn->query("SELECT COUNT(*) FROM expenses WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
        return $res;
    }

    public function getAllAttendanceLogs($date) {
        $stmt = $this->conn->prepare("SELECT a.*, u.full_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.attendance_date = ? ORDER BY a.punch_in_time DESC");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getPendingLeaveRequests() {
        return $this->conn->query("SELECT l.*, u.full_name FROM leave_requests l JOIN users u ON l.user_id = u.id WHERE l.status = 'Pending' ORDER BY l.id DESC");
    }

    public function getAttendanceReport($userId, $m, $y) {
        $stmt = $this->conn->prepare("SELECT * FROM attendance WHERE user_id = ? AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ? ORDER BY attendance_date ASC");
        $stmt->bind_param("iii", $userId, $m, $y);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getExpenses($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM expenses WHERE user_id = ? ORDER BY expense_date DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getAuditLogs() {
        return $this->conn->query("SELECT l.*, u.full_name as performer FROM audit_logs l LEFT JOIN users u ON l.performer_id = u.id ORDER BY l.id DESC LIMIT 100");
    }

    public function getDirectory() {
        return $this->conn->query("SELECT * FROM company_directory ORDER BY name ASC");
    }

    public function getPayrollPreview($month) {
        $year = date('Y');
        $stmt = $this->conn->prepare("SELECT u.full_name, u.role, u.base_salary_amount, SUM(a.day_count) as total_days FROM users u LEFT JOIN attendance a ON u.id = a.user_id AND MONTH(a.attendance_date) = ? AND YEAR(a.attendance_date) = ? WHERE u.status = 'Active' GROUP BY u.id");
        $stmt->bind_param("ii", $month, $year);
        $stmt->execute();
        return $stmt->get_result();
    }

    // 6. ACTIONS (CSRF Protected in Frontend)
    public function handleLeaveRequest($id, $status, $remark) {
        $stmt = $this->conn->prepare("UPDATE leave_requests SET status = ?, admin_remark = ? WHERE id = ?");
        $stmt->bind_param("ssi", $status, $remark, $id);
        return $stmt->execute();
    }

    public function updateExpenseStatus($id, $status) {
        $stmt = $this->conn->prepare("UPDATE expenses SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        return $stmt->execute();
    }
    
    public function addUser($data) {
        $this->requireAdmin();
        // PIN hashing logic: Plain PIN ko database ke liye secure banana
        $pinHash = password_hash($data['pin'], PASSWORD_BCRYPT);
    
        $stmt = $this->conn->prepare("INSERT INTO users (employee_id, pin_hash, full_name, role, position, phone, stipend_type, base_salary_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssd", 
            $data['employee_id'], 
            $pinHash, 
            $data['full_name'], 
            $data['role'], 
            $data['position'], 
            $data['phone'], 
            $data['stipend_type'], 
            $data['base_salary_amount']
        );
        return $stmt->execute();
    }

    public function applyLeave($type, $start, $end, $reason) {
        if ($_SESSION['role'] === 'Intern') throw new Exception("Interns cannot apply for leave.");
        $stmt = $this->conn->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $_SESSION['user_id'], $type, $start, $end, $reason);
        return $stmt->execute();
    }

    public function submitExpense($amount, $desc, $receipt) {
        $stmt = $this->conn->prepare("INSERT INTO expenses (user_id, expense_date, amount, description, receipt_path) VALUES (?, CURDATE(), ?, ?, ?)");
        $stmt->bind_param("idss", $_SESSION['user_id'], $amount, $desc, $receipt);
        return $stmt->execute();
    }

    public function closeMonth($month, $year) {
        $this->requireAdmin();
        $stmt = $this->conn->prepare("UPDATE payroll_logs SET is_locked = 1, locked_at = NOW() WHERE month = ? AND year = ?");
        $stmt->bind_param("ii", $month, $year);
        return $stmt->execute();
    }

    // 7. UTILITIES
    public function isUserOnLeaveToday($userId, $date) {
        $stmt = $this->conn->prepare("SELECT id FROM leave_requests WHERE user_id = ? AND status = 'Approved' AND ? BETWEEN start_date AND end_date");
        $stmt->bind_param("is", $userId, $date);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function logAction($perfId, $targetId, $cat, $type, $msg) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $this->conn->prepare("INSERT INTO audit_logs (performer_id, target_id, action_category, action_type, ip_address, log_message) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $perfId, $targetId, $cat, $type, $ip, $msg);
        $stmt->execute();
    }

    private function isWithinRange($lat, $lng) {
        $earth = 6371000;
        $dLat = deg2rad(($this->config['OFFICE_LAT'] ?? 0) - $lat);
        $dLon = deg2rad(($this->config['OFFICE_LNG'] ?? 0) - $lng);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat)) * cos(deg2rad($this->config['OFFICE_LAT'] ?? 0)) * sin($dLon/2)**2;
        return ($earth * 2 * atan2(sqrt($a), sqrt(1-$a))) <= ($this->config['OFFICE_RADIUS'] ?? 200);
    }
}