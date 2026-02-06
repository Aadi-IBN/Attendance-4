-- Spacemount WorkHub - Production Database Schema
-- Generated for Hostinger Shared Hosting (MySQL)
-- Version: 1.0.0
-- Charset: utf8mb4 | Engine: InnoDB

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+05:30";

-- ---------------------------------------------------------
-- 1. USERS & ROLES
-- ---------------------------------------------------------
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` VARCHAR(50) NOT NULL UNIQUE,
    `pin_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('Admin', 'Employee', 'Intern') NOT NULL,
    `position` VARCHAR(100),
    `phone` VARCHAR(20) NOT NULL,
    `status` ENUM('Active', 'Disabled') DEFAULT 'Active',
    `stipend_type` ENUM('Hourly', 'Fixed') NULL COMMENT 'Applicable to Interns only',
    `base_salary_amount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00 COMMENT 'Monthly for Emp/Fixed Intern, Hourly for Hourly Intern',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2. ATTENDANCE SYSTEM
-- ---------------------------------------------------------
CREATE TABLE `attendance` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `attendance_date` DATE NOT NULL,
    `slot_type` ENUM('Office', 'WFH', 'Site Visit') NOT NULL,
    `punch_in_time` DATETIME NOT NULL,
    `punch_out_time` DATETIME DEFAULT NULL,
    `punch_in_lat` DECIMAL(10, 8) NOT NULL,
    `punch_in_lng` DECIMAL(11, 8) NOT NULL,
    `punch_out_lat` DECIMAL(10, 8) DEFAULT NULL,
    `punch_out_lng` DECIMAL(11, 8) DEFAULT NULL,
    `punch_in_photo` VARCHAR(255) NOT NULL COMMENT 'Live camera capture path',
    `punch_out_photo` VARCHAR(255) DEFAULT NULL,
    `is_late` TINYINT(1) DEFAULT 0,
    `work_summary` TEXT DEFAULT NULL COMMENT 'Mandatory for Punch Out',
    `day_count` DECIMAL(2, 1) DEFAULT 0.0 COMMENT 'Calculated: 0, 0.5, or 1',
    `is_auto_punch_out` TINYINT(1) DEFAULT 0,
    `auto_punch_out_reason` VARCHAR(255) DEFAULT NULL,
    `site_visit_switch_flag` TINYINT(1) DEFAULT 0 COMMENT 'Audit for Office -> Site switch',
    `site_visit_switch_time` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Prevent manual edits via trigger-like logic or app-level enforcement; schema ensures unique daily records.
    UNIQUE KEY `unique_daily_attendance` (`user_id`, `attendance_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_date_user` (`attendance_date`, `user_id`),
    INDEX `idx_slot` (`slot_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. LEAVE MANAGEMENT
-- ---------------------------------------------------------
CREATE TABLE `leave_allocations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `cycle_start_date` DATE NOT NULL,
    `cycle_end_date` DATE NOT NULL,
    `sick_leave_total` INT DEFAULT 2,
    `casual_leave_total` INT DEFAULT 2,
    `sick_leave_used` INT DEFAULT 0,
    `casual_leave_used` INT DEFAULT 0,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_cycle` (`user_id`, `cycle_start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `leave_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `leave_type` ENUM('Sick Leave', 'Casual Leave') NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    `admin_remark` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_leave_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 4. HOLIDAYS
-- ---------------------------------------------------------
CREATE TABLE `holidays` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `holiday_date` DATE NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 5. EXPENSE MANAGEMENT
-- ---------------------------------------------------------
CREATE TABLE `expenses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `expense_date` DATE NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `description` TEXT NOT NULL,
    `receipt_path` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    `is_included_in_salary` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 6. PAYROLL & SALARY
-- ---------------------------------------------------------
CREATE TABLE `salary_adjustments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `type` ENUM('Incentive', 'Appraisal') NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `reason` TEXT NOT NULL,
    `effective_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payroll_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `month` TINYINT UNSIGNED NOT NULL COMMENT '1-12',
    `year` YEAR NOT NULL,
    `base_salary` DECIMAL(12, 2) NOT NULL,
    `total_working_days` DECIMAL(4, 1) DEFAULT 0.0,
    `total_approved_leaves` INT DEFAULT 0,
    `calculated_salary` DECIMAL(12, 2) NOT NULL,
    `incentives_total` DECIMAL(12, 2) DEFAULT 0.00,
    `appraisal_total` DECIMAL(12, 2) DEFAULT 0.00,
    `expenses_total` DECIMAL(12, 2) DEFAULT 0.00,
    `actual_paid_salary` DECIMAL(12, 2) DEFAULT NULL COMMENT 'Admin override',
    `is_locked` TINYINT(1) DEFAULT 0,
    `locked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_payroll_entry` (`user_id`, `month`, `year`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 7. COMPANY DIRECTORY
-- ---------------------------------------------------------
CREATE TABLE `company_directory` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `role_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- 8. AUDIT LOG SYSTEM (IMMUTABLE)
-- ---------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `performer_id` INT UNSIGNED NULL COMMENT 'User performing action',
    `target_id` INT UNSIGNED NULL COMMENT 'User affected by action',
    `action_category` ENUM('Attendance', 'Leave', 'Expense', 'Payroll', 'Security') NOT NULL,
    `action_type` VARCHAR(100) NOT NULL COMMENT 'PUNCH_IN, PUNCH_OUT, AUTO_PUNCH, SITE_SWITCH, LEAVE_APPROVE, etc',
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `old_values` JSON DEFAULT NULL,
    `new_values` JSON DEFAULT NULL,
    `log_message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- No ON UPDATE or ON DELETE allowed to maintain immutability
    INDEX `idx_action_time` (`created_at`),
    INDEX `idx_category` (`action_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 9. SYSTEM CONFIGURATION
-- ---------------------------------------------------------
CREATE TABLE `system_rules` (
    `rule_key` VARCHAR(50) PRIMARY KEY,
    `rule_value` TEXT NOT NULL,
    `description` VARCHAR(255),
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- SECURITY MEASURES: PREVENT DELETION ON AUDIT LOGS
-- ---------------------------------------------------------
DELIMITER //
CREATE TRIGGER `tg_prevent_audit_delete` BEFORE DELETE ON `audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be deleted.';
END;
//

CREATE TRIGGER `tg_prevent_audit_update` BEFORE UPDATE ON `audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be updated.';
END;
//

CREATE TRIGGER `tg_prevent_locked_payroll_update` BEFORE UPDATE ON `payroll_logs`
FOR EACH ROW
BEGIN
    IF OLD.is_locked = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked payroll records cannot be modified.';
    END IF;
END;
//
DELIMITER ;

COMMIT;