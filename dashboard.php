<?php
/**
 * Dashboard / Protected Page
 * 
 * This page is only accessible after successful login.
 * It demonstrates session-based access control and secure logout.
 */

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Security: Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header('Location: login.php?error=' . urlencode('Please log in first'));
    exit;
}

// Get current user data from database
$user = get_user($pdo, $_SESSION['user_id']);
if (!$user) {
    session_destroy();
    header('Location: login.php?error=' . urlencode('User not found'));
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    // Destroy all session data
    session_unset();
    session_destroy();
    
    // Redirect to login page
    header('Location: login.php?success=' . urlencode('You have been logged out successfully'));
    exit;
}

// Retrieve user info
$username = $_SESSION['username'] ?? 'User';
$email = $_SESSION['email'] ?? 'N/A';
$full_name = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'N/A';
$login_time = $_SESSION['login_time'] ?? time();

// Calculate session duration
$session_duration = time() - $login_time;
$duration_text = sprintf('%d minute%s', floor($session_duration / 60), floor($session_duration / 60) !== 1 ? 's' : '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - TARMS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0b6ea8;
            --primary-dark: #084f7a;
            --gray-100: #f6f8fb;
            --gray-200: #e0e0e0;
            --gray-400: #999;
            --gray-700: #333;
            --white: #ffffff;
            --success: #22863a;
        }

        html, body {
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--gray-100);
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .navbar h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .navbar-right {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .user-info {
            font-size: 14px;
            text-align: right;
        }

        .user-info strong {
            display: block;
            font-size: 15px;
        }

        .user-role {
            font-size: 12px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
            border: 2px solid var(--white);
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .logout-btn:hover {
            background: var(--white);
            color: var(--primary);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .welcome-section {
            background: var(--white);
            border-radius: 10px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .welcome-section h2 {
            color: var(--gray-700);
            margin-bottom: 15px;
            font-size: 28px;
        }

        .welcome-section p {
            color: var(--gray-400);
            font-size: 16px;
            line-height: 1.6;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .profile-card {
            background: var(--white);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--primary);
        }

        .profile-card h3 {
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .profile-card p {
            color: var(--gray-700);
            font-size: 18px;
            font-weight: 500;
            word-break: break-all;
        }

        .features-section {
            background: var(--white);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .features-section h3 {
            color: var(--gray-700);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .feature-list {
            list-style: none;
            padding: 0;
        }

        .feature-list li {
            padding: 12px 0;
            color: var(--gray-700);
            font-size: 15px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            gap: 10px;
        }

        .feature-list li:before {
            content: "✓";
            color: var(--success);
            font-weight: bold;
            width: 20px;
        }

        .feature-list li:last-child {
            border-bottom: none;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            color: var(--gray-400);
            font-size: 12px;
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 20px;
            }

            .container {
                padding: 20px;
            }

            .welcome-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <div class="navbar">
        <div>
            <h1>🎯 TARMS Dashboard</h1>
        </div>
        <div class="navbar-right">
            <div class="user-info">
                <strong><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="user-role">Role: <?php echo htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <a href="?logout=1" class="logout-btn">Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <div class="welcome-section">
            <h2>Welcome, <?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>! 👋</h2>
            <p>You have successfully logged in to the Transaction Account Record Management System (TARMS). This is your dashboard where you can manage transactions, generate reports, and control your account.</p>
            <p style="margin-top: 15px; font-size: 14px; color: var(--gray-400);">Session Duration: <?php echo htmlspecialchars($duration_text, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <div class="profile-grid">
            <div class="profile-card">
                <h3>📧 Email</h3>
                <p><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="profile-card">
                <h3>🔐 Account Role</h3>
                <p><?php echo htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="profile-card">
                <h3>⏱️ Login Time</h3>
                <p><?php echo htmlspecialchars(date('Y-m-d H:i:s', $login_time), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>

        <div class="features-section" style="margin-top: 30px;">
            <h3>🚀 Available Features</h3>
            <ul class="feature-list">
                <li>Manage transactions (income, expenses, allocations)</li>
                <li>View transaction history and records</li>
                <li>Generate CSV reports and exports</li>
                <li>Search and filter transactions</li>
                <li>Real-time balance calculations</li>
                <li>User authentication with secure sessions</li>
                <li>Role-based access control</li>
                <li>QR code generation for transaction IDs</li>
            </ul>
        </div>

        <div class="features-section" style="margin-top: 30px;">
            <h3>🔒 Security Features</h3>
            <ul class="feature-list">
                <li>CSRF token protection on all forms</li>
                <li>Bcrypt password hashing with cost 12</li>
                <li>Session fixation prevention</li>
                <li>XSS protection via output escaping</li>
                <li>SQL injection prevention with prepared statements</li>
                <li>Secure password validation and confirmation</li>
                <li>Login attempt logging and monitoring</li>
                <li>HTTPS encryption recommended for production</li>
            </ul>
        </div>

        <div class="footer">
            <p>🔐 Your session is secure and protected. All data is encrypted in transit and at rest.</p>
        </div>
    </div>
</body>
</html>
