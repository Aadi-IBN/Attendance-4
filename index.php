<?php
/**
 * Spacemount WorkHub - LOGIN INTERFACE
 * File: index.php
 * * DESCRIPTION:
 * High-security login portal for the Spacemount WorkHub HRMS.
 * Enforces corporate-grade UI and strictly interfaces with CoreEngine.
 */

require_once 'core.php';
use Spacemount\WorkHub\CoreEngine;

$core = CoreEngine::getInstance();
$error = '';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    $redirect = ($_SESSION['role'] === 'Admin') ? 'admin.php' : 'employee.php';
    header("Location: $redirect");
    exit;
}

// Handle Login Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. CSRF Validation
        $core->validateCSRF();

        // 2. Input Sanitization
        $employeeId = trim(filter_input(INPUT_POST, 'employee_id', FILTER_SANITIZE_SPECIAL_CHARS));
        $pin = trim($_POST['pin'] ?? '');

        if (empty($employeeId) || empty($pin)) {
            throw new Exception("Please enter both Employee ID and PIN.");
        }

        // 3. Authentication Attempt
        if ($core->login($employeeId, $pin)) {
            // Success: Redirect based on role
            $redirect = ($_SESSION['role'] === 'Admin') ? 'admin.php' : 'employee.php';
            header("Location: $redirect");
            exit;
        } else {
            throw new Exception("Invalid Employee ID or Security PIN.");
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Generate fresh CSRF token for the form
$csrfToken = $core->getCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login | Spacemount WorkHub</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #F5F6F8;
            color: #111827;
        }
        .login-card {
            background: #FFFFFF;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        .input-focus:focus {
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .btn-indigo {
            background-color: #4F46E5;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-indigo:hover {
            background-color: #4338CA;
            transform: scale(1.02);
        }
        .btn-indigo:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-600 rounded-2xl mb-4 shadow-lg shadow-indigo-200">
                <i class="fas fa-fingerprint text-white text-3xl"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">WorkHub Portal</h1>
            <p class="text-gray-500 mt-1">Attendance & Payroll Management</p>
        </div>

        <div class="login-card p-8 sm:p-10">
            <h2 class="text-xl font-semibold mb-6 text-gray-800">Secure Sign-In</h2>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 flex items-center animate-pulse">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <p class="text-sm text-red-700 font-medium"><?php echo $error; ?></p>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div>
                    <label for="employee_id" class="block text-sm font-medium text-gray-700 mb-1.5">Employee ID</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400">
                            <i class="fas fa-id-badge"></i>
                        </span>
                        <input 
                            type="text" 
                            name="employee_id" 
                            id="employee_id" 
                            placeholder="e.g. SM-1001"
                            required
                            class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl outline-none input-focus transition-all text-gray-900 placeholder-gray-400"
                        >
                    </div>
                </div>

                <div>
                    <label for="pin" class="block text-sm font-medium text-gray-700 mb-1.5">Security PIN</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input 
                            type="password" 
                            name="pin" 
                            id="pin" 
                            placeholder="Enter 4-6 digit PIN"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            required
                            class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl outline-none input-focus transition-all text-gray-900 tracking-widest placeholder-gray-400"
                        >
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full btn-indigo text-white font-semibold py-3.5 rounded-xl shadow-lg shadow-indigo-100 focus:outline-none focus:ring-4 focus:ring-indigo-500/20">
                        Access Account <i class="fas fa-arrow-right ml-2 text-sm opacity-70"></i>
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-8 text-center">
            <p class="text-xs text-gray-400 uppercase tracking-widest font-semibold">
                &copy; <?php echo date('Y'); ?> Spacemount. All rights reserved.
            </p>
            <div class="flex justify-center space-x-4 mt-2">
                <span class="text-[10px] text-gray-400"><i class="fas fa-shield-alt mr-1"></i> SSL Secure</span>
                <span class="text-[10px] text-gray-400"><i class="fas fa-map-marker-alt mr-1"></i> Geofence Active</span>
            </div>
        </div>
    </div>

    <script>
        // Smooth transition for errors
        if (document.querySelector('.bg-red-50')) {
            setTimeout(() => {
                document.querySelector('.bg-red-50').classList.remove('animate-pulse');
            }, 2000);
        }
    </script>
</body>
</html>