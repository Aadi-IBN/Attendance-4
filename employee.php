<?php
/**
 * Spacemount WorkHub - EMPLOYEE/INTERN DASHBOARD (FINAL PRODUCTION READY)
 * File: employee.php
 * * THE SINGLE USER-FACING INTERFACE FOR EMPLOYEES AND INTERNS.
 * ALL ACTIONS ARE ROUTED THROUGH THE CoreEngine CLASS.
 */

require_once 'core.php';
use Spacemount\WorkHub\CoreEngine;

$core = CoreEngine::getInstance();

// 1. AUTHENTICATION & SESSION VERIFICATION
try {
    $core->requireAuth();
} catch (Exception $e) {
    header("Location: index.php?error=session_expired");
    exit;
}

// Hydrate user context from session data populated by core.php
$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$fullName = $_SESSION['user_data']['full_name'] ?? 'Team Member';
$isIntern = ($userRole === 'Intern');

// 2. STATE INITIALIZATION (REAL DATA BINDING)
$today        = date('Y-m-d');
$currentMonth = date('m');
$currentYear  = date('Y');
$message      = '';
$error        = '';

// Real-time check for active attendance (if user is currently punched in)
$currentSession = null;
try {
    // Queries the database for a record where punch_out_time IS NULL for today
    $currentSession = $core->getActiveAttendance($userId, $today);
} catch (Exception $e) {
    $error = "State Error: Unable to fetch current status.";
}

// Real-time check for approved leave today (triggers UI blocking)
$onApprovedLeave = false;
try {
    $onApprovedLeave = $core->isUserOnLeaveToday($userId, $today);
} catch (Exception $e) {
    // Fail silent for banner logic
}

// Late Window Check (10:00 AM - 10:15 AM)
$currentTime  = date('H:i');
$isLateWindow = ($currentTime >= '10:00' && $currentTime <= '10:15');

// SECTION 1: ACTION HANDLING (PHP CONTROLLER)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Enforce CSRF protection for all destructive/write actions
        $core->validateCSRF();
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'punch_in':
                if ($onApprovedLeave) throw new Exception("Action Denied: You have an approved leave for today.");
                
                $slot  = $_POST['slot_type'] ?? 'Office';
                $lat   = (float)($_POST['latitude'] ?? 0);
                $lng   = (float)($_POST['longitude'] ?? 0);
                $photo = $_POST['photo_data'] ?? ''; // Received as Base64 from Canvas

                if (empty($photo)) throw new Exception("Camera proof is mandatory to start work.");
                if ($lat == 0 || $lng == 0) throw new Exception("GPS coordinates missing. Please enable location.");

                if ($core->punchIn($slot, $lat, $lng, $photo)) {
                    $message = "Shift started successfully. Work mode: $slot.";
                    header("Refresh:1"); // Refresh state to show Punch-Out UI
                }
                break;

            case 'punch_out':
                $summary = trim($_POST['work_summary'] ?? '');
                // Yahan POST se 'latitude' aa raha hai, use $lat mein daalo
                $lat = $_POST['latitude'] ?? '0'; 
                $lng = $_POST['longitude'] ?? '0';
            
                if (empty($summary)) throw new Exception("Daily Work Summary is mandatory to end shift.");
            
                // Core function ko 3 arguments chahiye: $lat, $lng, $summary
                if ($core->punchOut($lat, $lng, $summary)) {
                    $message = "Work day closed. Calculated day count has been logged.";
                    header("Refresh:1");
                }
                break;

            case 'apply_leave':
                if ($isIntern) throw new Exception("Policy Violation: Interns are prohibited from applying for leaves.");
                
                $type   = $_POST['leave_type'] ?? 'Casual Leave';
                $start  = $_POST['start_date'] ?? '';
                $end    = $_POST['end_date'] ?? '';
                $reason = htmlspecialchars($_POST['reason'] ?? '', ENT_QUOTES, 'UTF-8');

                if ($core->applyLeave($type, $start, $end, $reason)) {
                    $message = "Leave application submitted to HR for approval.";
                }
                break;

            case 'submit_expense':
                $amount  = (float)($_POST['amount'] ?? 0);
                $desc    = htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8');
                $receipt = $_POST['receipt_data'] ?? ''; // Base64 receipt image

                if ($amount <= 0) throw new Exception("Invalid amount entered.");

                if ($core->submitExpense($amount, $desc, $receipt)) {
                    $message = "Expense claim submitted successfully.";
                }
                break;

            case 'logout':
                $core->logout();
                header("Location: index.php");
                exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 5. FETCH DATA FOR HISTORY TABLES
$attendanceHistory = $core->getAttendanceReport($userId, $currentMonth, $currentYear) ?? [];
$expenseClaims      = $core->getExpenses($userId) ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Employee Hub | Spacemount WorkHub</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F5F6F8; color: #111827; }
        .dashboard-card { background: #FFFFFF; border-radius: 16px; border: 1px solid #E5E7EB; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .btn-indigo { background-color: #4F46E5; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-indigo:hover { background-color: #4338CA; transform: translateY(-1px); }
        .btn-indigo:active { transform: scale(0.98); }
        .tab-btn.active { border-bottom: 2px solid #4F46E5; color: #4F46E5; font-weight: 700; }
        .status-badge { @apply px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider; }
        
        /* Modal Backdrop */
        .modal { transition: opacity 0.25s ease; }
        body.modal-active { overflow: hidden; }

        /* Animation for Banners */
        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .banner-animate { animation: slideIn 0.4s ease-out forwards; }
    </style>
</head>
<body class="antialiased pb-20">

    <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
        <div class="max-w-5xl mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-indigo-100">
                    <i class="fas fa-user-circle text-2xl"></i>
                </div>
                <div>
                    <h1 class="font-bold text-lg text-gray-900 leading-tight"><?php echo htmlspecialchars($fullName); ?></h1>
                    <div class="flex items-center space-x-2">
                        <span class="text-[10px] uppercase font-extrabold text-indigo-600 tracking-widest"><?php echo $userRole; ?></span>
                        <span class="text-gray-300">•</span>
                        <span class="text-[10px] uppercase font-bold text-gray-400"><?php echo date('D, d M Y'); ?></span>
                    </div>
                </div>
            </div>
            
            <form action="employee.php" method="POST">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?php echo $core->getCSRFToken(); ?>">
                <button type="submit" class="w-10 h-10 flex items-center justify-center rounded-xl bg-gray-50 text-gray-400 hover:bg-red-50 hover:text-red-500 transition-all">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </form>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 mt-6 space-y-6">

        <?php if ($error): ?>
            <div class="banner-animate bg-red-50 border-l-4 border-red-500 p-4 rounded-r-xl shadow-sm text-red-800 flex items-center">
                <i class="fas fa-exclamation-triangle mr-3 text-lg"></i>
                <p class="text-sm font-semibold"><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="banner-animate bg-green-50 border-l-4 border-green-500 p-4 rounded-r-xl shadow-sm text-green-800 flex items-center">
                <i class="fas fa-check-circle mr-3 text-lg"></i>
                <p class="text-sm font-semibold"><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($onApprovedLeave): ?>
            <div class="banner-animate bg-red-600 p-4 rounded-2xl shadow-xl text-white flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest opacity-80">Operational Restriction</p>
                        <p class="font-bold">Leave is Active: Punch-In Blocked.</p>
                    </div>
                </div>
                <i class="fas fa-lock opacity-50 text-2xl"></i>
            </div>
        <?php elseif ($isLateWindow && !$currentSession): ?>
            <div class="banner-animate bg-indigo-700 p-4 rounded-2xl shadow-xl text-white flex items-center">
                <i class="fas fa-hourglass-half mr-4 text-xl"></i>
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest opacity-70">Policy Alert: Late Entry Window</p>
                    <p class="text-sm font-medium">Entering now? You MUST stay until 7:00 PM for a Full Day credit.</p>
                </div>
            </div>
        <?php endif; ?>

        <section class="dashboard-card p-6 md:p-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Attendance Terminal</h2>
                    <p class="text-xs text-gray-500 font-medium mt-1">Status: <?php echo $currentSession ? 'Currently Working' : 'Off Duty'; ?></p>
                </div>
                <div class="flex items-center space-x-3">
                    <?php if ($currentSession): ?>
                        <span class="px-4 py-2 bg-green-50 text-green-700 text-xs font-bold rounded-lg flex items-center">
                            <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                            IN: <?php echo date('H:i', strtotime($currentSession['punch_in_time'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$currentSession): ?>
                <form id="punchForm" method="POST" action="employee.php" class="space-y-6">
                    <input type="hidden" name="action" value="punch_in">
                    <input type="hidden" name="csrf_token" value="<?php echo $core->getCSRFToken(); ?>">
                    <input type="hidden" name="latitude" id="lat">
                    <input type="hidden" name="longitude" id="lng">
                    <input type="hidden" name="photo_data" id="photo">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <label class="relative group cursor-pointer">
                            <input type="radio" name="slot_type" value="Office" checked class="peer sr-only">
                            <div class="p-4 rounded-2xl border-2 border-gray-100 peer-checked:border-indigo-600 peer-checked:bg-indigo-50 transition-all">
                                <i class="fas fa-building text-gray-400 group-hover:text-indigo-600 mb-2 block"></i>
                                <p class="text-xs font-bold uppercase text-gray-400 peer-checked:text-indigo-600">Office</p>
                                <p class="text-[9px] text-gray-400 mt-1">GPS Verified (100m)</p>
                            </div>
                        </label>
                        <label class="relative group cursor-pointer">
                            <input type="radio" name="slot_type" value="WFH" class="peer sr-only">
                            <div class="p-4 rounded-2xl border-2 border-gray-100 peer-checked:border-indigo-600 peer-checked:bg-indigo-50 transition-all">
                                <i class="fas fa-laptop-house text-gray-400 group-hover:text-indigo-600 mb-2 block"></i>
                                <p class="text-xs font-bold uppercase text-gray-400 peer-checked:text-indigo-600">WFH</p>
                                <p class="text-[9px] text-gray-400 mt-1">Remote Access</p>
                            </div>
                        </label>
                        <label class="relative group cursor-pointer">
                            <input type="radio" name="slot_type" value="Site Visit" class="peer sr-only">
                            <div class="p-4 rounded-2xl border-2 border-gray-100 peer-checked:border-orange-500 peer-checked:bg-orange-50 transition-all">
                                <i class="fas fa-map-marker-alt text-gray-400 group-hover:text-orange-600 mb-2 block"></i>
                                <p class="text-xs font-bold uppercase text-gray-400 peer-checked:text-orange-600">Site Visit</p>
                                <p class="text-[9px] text-gray-400 mt-1">Full Day Locked</p>
                            </div>
                        </label>
                    </div>

                    <div class="relative max-w-sm mx-auto rounded-3xl overflow-hidden bg-gray-900 aspect-video shadow-2xl border-4 border-white">
                        <video id="video" autoplay playsinline class="w-full h-full object-cover"></video>
                        <canvas id="canvas" class="hidden"></canvas>
                        <div id="cameraStatus" class="absolute inset-0 flex items-center justify-center bg-black/60 hidden">
                            <p class="text-white text-xs font-bold animate-pulse">CAPTURING PROOF...</p>
                        </div>
                    </div>

                    <button type="button" id="punchInBtn" onclick="initiatePunchIn()" class="w-full btn-indigo text-white font-bold py-5 rounded-2xl shadow-xl shadow-indigo-100 flex items-center justify-center">
                        <i class="fas fa-fingerprint mr-2 text-xl"></i>
                        PUNCH IN & START WORK
                    </button>
                    <p class="text-center text-[10px] text-gray-400 font-bold uppercase tracking-tighter">Verified by Geofence & Identity Engine</p>
                </form>

            <?php else: ?>
                <form id="punchOutForm" method="POST" action="employee.php" class="space-y-6">
                    <input type="hidden" name="action" value="punch_out">
                    <input type="hidden" name="csrf_token" value="<?php echo $core->getCSRFToken(); ?>">
                    <input type="hidden" name="latitude" id="outLat">
                    <input type="hidden" name="longitude" id="outLng">

                    <div class="bg-gray-50 rounded-2xl p-4 border border-gray-200">
                        <label class="block text-[10px] font-extrabold text-gray-400 uppercase tracking-widest mb-3">Daily Work Summary (Mandatory)</label>
                        <textarea name="work_summary" required class="w-full bg-white border border-gray-200 rounded-xl p-4 text-sm outline-none focus:ring-2 focus:ring-indigo-200 h-32 transition-all" placeholder="What were your key accomplishments today?"></textarea>
                    </div>

                    <button type="submit" onclick="captureOutLocation()" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-5 rounded-2xl shadow-xl shadow-red-100 transition-all flex items-center justify-center">
                        <i class="fas fa-sign-out-alt mr-2 text-xl"></i>
                        END SHIFT & PUNCH OUT
                    </button>
                    <p class="text-center text-[10px] text-gray-400 font-bold uppercase tracking-tighter italic">Shift end will trigger automated day-count calculation</p>
                </form>
            <?php endif; ?>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <div class="dashboard-card p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="font-extrabold text-xs uppercase tracking-widest text-gray-400">Monthly Attendance</h3>
                    <i class="fas fa-history text-gray-300"></i>
                </div>
                <div class="space-y-3 overflow-y-auto max-h-[350px] pr-2">
                    <?php if (empty($attendanceHistory)): ?>
                        <div class="text-center py-10">
                            <p class="text-xs text-gray-400 italic">No logs recorded for this month.</p>
                        </div>
                    <?php else: foreach ($attendanceHistory as $log): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-100">
                            <div>
                                <p class="text-xs font-bold text-gray-800"><?php echo date('d M, Y', strtotime($log['attendance_date'])); ?></p>
                                <div class="flex items-center space-x-2 mt-1">
                                    <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 bg-indigo-100 text-indigo-600 rounded"><?php echo $log['slot_type']; ?></span>
                                    <?php if ($log['is_late']): ?>
                                        <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 bg-orange-100 text-orange-600 rounded">Late</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-black text-indigo-700"><?php echo number_format($log['day_count'], 1); ?> Day</p>
                                <p class="text-[9px] text-gray-400 mt-0.5"><?php echo date('H:i', strtotime($log['punch_in_time'])); ?> - <?php echo $log['punch_out_time'] ? date('H:i', strtotime($log['punch_out_time'])) : '--:--'; ?></p>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="dashboard-card p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="font-extrabold text-xs uppercase tracking-widest text-gray-400">Expense Claims</h3>
                    <button onclick="toggleModal('expenseModal')" class="text-[10px] font-bold text-indigo-600 hover:text-indigo-800">+ NEW CLAIM</button>
                </div>
                <div class="space-y-3 overflow-y-auto max-h-[350px] pr-2">
                    <?php if (empty($expenseClaims)): ?>
                        <div class="text-center py-10">
                            <p class="text-xs text-gray-400 italic">No expenses submitted yet.</p>
                        </div>
                    <?php else: foreach ($expenseClaims as $exp): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl border border-gray-100">
                            <div class="flex-1 mr-4">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-bold text-gray-800">₹<?php echo number_format($exp['amount'], 2); ?></p>
                                    <span class="status-badge <?php 
                                        echo $exp['status'] === 'Approved' ? 'bg-green-100 text-green-700' : 
                                            ($exp['status'] === 'Rejected' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-600'); 
                                    ?>">
                                        <?php echo $exp['status']; ?>
                                    </span>
                                </div>
                                <p class="text-[10px] text-gray-500 mt-1 truncate"><?php echo htmlspecialchars($exp['description']); ?></p>
                                <p class="text-[9px] text-gray-400 italic mt-0.5"><?php echo date('d M, Y', strtotime($exp['expense_date'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

        </div>

        <div class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 px-6 py-3 flex justify-around items-center lg:hidden z-50">
            <a href="employee.php" class="text-indigo-600 flex flex-col items-center">
                <i class="fas fa-th-large text-lg"></i>
                <span class="text-[9px] font-bold mt-1">HOME</span>
            </a>
            <?php if (!$isIntern): ?>
            <button onclick="toggleModal('leaveModal')" class="text-gray-400 flex flex-col items-center">
                <i class="fas fa-calendar-plus text-lg"></i>
                <span class="text-[9px] font-bold mt-1">LEAVE</span>
            </button>
            <?php endif; ?>
            <button onclick="toggleModal('expenseModal')" class="text-gray-400 flex flex-col items-center">
                <i class="fas fa-file-invoice-dollar text-lg"></i>
                <span class="text-[9px] font-bold mt-1">EXPENSE</span>
            </button>
        </div>

        <div class="hidden lg:flex fixed top-1/2 right-4 -translate-y-1/2 flex-col space-y-4">
            <?php if (!$isIntern): ?>
            <button onclick="toggleModal('leaveModal')" class="w-12 h-12 bg-white rounded-full shadow-xl border border-gray-200 flex items-center justify-center text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all group" title="Apply Leave">
                <i class="fas fa-calendar-day"></i>
            </button>
            <?php endif; ?>
            <button onclick="toggleModal('expenseModal')" class="w-12 h-12 bg-white rounded-full shadow-xl border border-gray-200 flex items-center justify-center text-green-600 hover:bg-green-600 hover:text-white transition-all group" title="Submit Expense">
                <i class="fas fa-receipt"></i>
            </button>
        </div>

    </main>

    <div id="leaveModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-[100]">
        <div class="modal-overlay absolute w-full h-full bg-black/50 backdrop-blur-sm" onclick="toggleModal('leaveModal')"></div>
        <div class="modal-container bg-white w-full max-w-md mx-auto rounded-3xl shadow-2xl z-50 overflow-y-auto">
            <div class="modal-content py-6 text-left px-6">
                <div class="flex justify-between items-center pb-4 border-b border-gray-100 mb-6">
                    <p class="text-lg font-bold text-gray-800">Apply Leave</p>
                    <button onclick="toggleModal('leaveModal')" class="text-gray-400"><i class="fas fa-times"></i></button>
                </div>
                <form action="employee.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="apply_leave">
                    <input type="hidden" name="csrf_token" value="<?php echo $core->getCSRFToken(); ?>">
                    
                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Leave Category</label>
                        <select name="leave_type" class="w-full border border-gray-200 rounded-xl p-3 text-sm focus:ring-2 focus:ring-indigo-100 outline-none">
                            <option>Casual Leave</option>
                            <option>Sick Leave</option>
                            <option>Work From Home (Special)</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Start Date</label>
                            <input type="date" name="start_date" required class="w-full border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-indigo-100">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">End Date</label>
                            <input type="date" name="end_date" required class="w-full border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-indigo-100">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Reason for Leave</label>
                        <textarea name="reason" required class="w-full border border-gray-200 rounded-xl p-3 text-sm h-24 outline-none focus:ring-2 focus:ring-indigo-100"></textarea>
                    </div>

                    <button type="submit" class="w-full btn-indigo text-white font-bold py-4 rounded-2xl shadow-lg mt-4">SUBMIT REQUEST</button>
                </form>
            </div>
        </div>
    </div>

    <div id="expenseModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-[100]">
        <div class="modal-overlay absolute w-full h-full bg-black/50 backdrop-blur-sm" onclick="toggleModal('expenseModal')"></div>
        <div class="modal-container bg-white w-full max-w-md mx-auto rounded-3xl shadow-2xl z-50 overflow-y-auto">
            <div class="modal-content py-6 text-left px-6">
                <div class="flex justify-between items-center pb-4 border-b border-gray-100 mb-6">
                    <p class="text-lg font-bold text-gray-800">New Expense Claim</p>
                    <button onclick="toggleModal('expenseModal')" class="text-gray-400"><i class="fas fa-times"></i></button>
                </div>
                <form action="employee.php" id="expenseForm" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="submit_expense">
                    <input type="hidden" name="csrf_token" value="<?php echo $core->getCSRFToken(); ?>">
                    <input type="hidden" name="receipt_data" id="receiptBase64">

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Claim Amount (₹)</label>
                        <input type="number" name="amount" required class="w-full border border-gray-200 rounded-xl p-3 text-sm font-bold text-indigo-600 outline-none focus:ring-2 focus:ring-indigo-100" placeholder="0.00">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Detailed Description</label>
                        <textarea name="description" required class="w-full border border-gray-200 rounded-xl p-3 text-sm h-24 outline-none focus:ring-2 focus:ring-indigo-100" placeholder="Travel, Hardware, or Meal details..."></textarea>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-2">Upload Proof (Receipt)</label>
                        <div class="flex items-center justify-center w-full">
                            <label class="flex flex-col w-full h-32 border-4 border-dashed border-gray-100 hover:bg-gray-50 hover:border-indigo-300 transition-all rounded-3xl cursor-pointer">
                                <div class="flex flex-col items-center justify-center pt-7" id="uploadPlaceholder">
                                    <i class="fas fa-cloud-upload-alt text-2xl text-gray-300"></i>
                                    <p class="pt-1 text-xs tracking-wider text-gray-400 font-bold uppercase">Upload Receipt</p>
                                </div>
                                <input type="file" class="hidden" accept="image/*" onchange="processReceipt(this)" />
                                <img id="receiptPreview" class="hidden w-full h-full object-cover rounded-3xl" />
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-2xl shadow-lg mt-4 transition-all">SUBMIT FOR APPROVAL</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const punchInBtn = document.getElementById('punchInBtn');

        // Camera Feed Initialization
        async function startCamera() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'user' }, 
                    audio: false 
                });
                video.srcObject = stream;
            } catch (err) {
                console.error("Camera Error:", err);
                alert("Camera Access Required: We need a live proof to allow Punch-In.");
            }
        }

        // Section 3: Geolocation Capture (In)
        function initiatePunchIn() {
            if (!navigator.geolocation) {
                alert("GPS Not Supported: Unable to verify location.");
                return;
            }

            punchInBtn.disabled = true;
            punchInBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> VERIFYING...';
            document.getElementById('cameraStatus').classList.remove('hidden');

            navigator.geolocation.getCurrentPosition((pos) => {
                document.getElementById('lat').value = pos.coords.latitude;
                document.getElementById('lng').value = pos.coords.longitude;

                // Snap Photo from Video
                const context = canvas.getContext('2d');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                // Set Base64 Data
                document.getElementById('photo').value = canvas.toDataURL('image/jpeg', 0.7);

                // Delay slightly for UX feedback
                setTimeout(() => {
                    document.getElementById('punchForm').submit();
                }, 800);

            }, (err) => {
                alert("GPS Access Denied: We must verify you are at the office.");
                punchInBtn.disabled = false;
                punchInBtn.innerHTML = '<i class="fas fa-fingerprint mr-2 text-xl"></i> PUNCH IN & START WORK';
                document.getElementById('cameraStatus').classList.add('hidden');
            });
        }

        // Section 3: Geolocation Capture (Out)
        function captureOutLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition((pos) => {
                    document.getElementById('outLat').value = pos.coords.latitude;
                    document.getElementById('outLng').value = pos.coords.longitude;
                });
            }
        }

        // Section 6: Modal Logic
        function toggleModal(id) {
            const body = document.querySelector('body');
            const modal = document.getElementById(id);
            modal.classList.toggle('opacity-0');
            modal.classList.toggle('pointer-events-none');
            body.classList.toggle('modal-active');
        }

        // Section 6: Receipt Image Pre-processing
        function processReceipt(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('receiptBase64').value = e.target.result;
                    document.getElementById('receiptPreview').src = e.target.result;
                    document.getElementById('receiptPreview').classList.remove('hidden');
                    document.getElementById('uploadPlaceholder').classList.add('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Start Camera automatically on load if Punch In is required
        if (video) { startCamera(); }
    </script>
</body>
</html>