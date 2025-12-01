<?php
/**
 * Modern, Professional Login & Registration Form
 */

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Determine which tab is active (login or register)
$active_tab = $_GET['tab'] ?? 'login';
if (!in_array($active_tab, ['login', 'register'], true)) {
    $active_tab = 'login';
}

// Retrieve messages
$success = isset($_GET['success']) ? htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8') : '';
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TARMS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0b6ea8;
            --primary-dark: #084f7a;
            --primary-light: #1b8ec9;
            --success: #22863a;
            --danger: #cb2431;
            --warning: #fbca04;
            --gray-100: #f6f8fb;
            --gray-200: #e0e0e0;
            --gray-400: #999;
            --gray-700: #333;
            --white: #ffffff;
        }

        html, body {
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            width: 100%;
            max-width: 450px;
        }

        .auth-card {
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.4s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            padding: 30px 30px 20px;
            text-align: center;
        }

        .auth-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .auth-header p {
            font-size: 14px;
            opacity: 0.95;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid var(--gray-200);
            margin: 0 30px;
        }

        .tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-400);
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.3s ease;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab:hover:not(.active) {
            color: var(--gray-700);
        }

        .auth-body {
            padding: 30px;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }

        .alert.show {
            display: block;
        }

        .alert-success {
            background-color: #deffed;
            color: var(--success);
            border: 1px solid #7cda5f;
        }

        .alert-error {
            background-color: #ffeef0;
            color: var(--danger);
            border: 1px solid #f85149;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(11, 110, 168, 0.1);
        }

        .form-group input::placeholder {
            color: var(--gray-400);
        }

        .form-group input:invalid:not(:placeholder-shown) {
            border-color: var(--danger);
        }

        .submit-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-top: 10px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(11, 110, 168, 0.3);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .form-footer {
            text-align: center;
            font-size: 14px;
            color: var(--gray-400);
            margin-top: 20px;
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .password-requirements {
            background: var(--gray-100);
            border-left: 4px solid var(--warning);
            padding: 12px 15px;
            border-radius: 6px;
            font-size: 12px;
            color: var(--gray-700);
            margin-bottom: 15px;
        }

        .password-requirements ul {
            list-style: none;
            margin: 8px 0 0 0;
        }

        .password-requirements li {
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .password-requirements li:before {
            content: "✓";
            color: var(--success);
            font-weight: bold;
            width: 16px;
        }

        .password-requirements li.unmet:before {
            content: "○";
            color: var(--gray-400);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 480px) {
            .auth-card {
                border-radius: 8px;
            }

            .auth-header {
                padding: 25px 20px 15px;
            }

            .auth-header h1 {
                font-size: 24px;
            }

            .auth-body {
                padding: 20px;
            }

            .tabs {
                margin: 0 20px;
            }

            .tab {
                padding: 12px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <!-- Header -->
            <div class="auth-header">
                <h1>🔐 TARMS</h1>
                <p>Transaction Account Record Management System</p>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab <?php echo $active_tab === 'login' ? 'active' : ''; ?>" onclick="switchTab('login')">
                    📝 Sign In
                </div>
                <div class="tab <?php echo $active_tab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register')">
                    ✍️ Register
                </div>
            </div>

            <!-- Body -->
            <div class="auth-body">
                <!-- Login Tab -->
                <div id="login-tab" class="tab-content <?php echo $active_tab === 'login' ? 'active' : ''; ?>">
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success show">✓ <?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error show">✕ <?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="authenticate.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="login">

                        <div class="form-group">
                            <label for="login_username">Username or Email</label>
                            <input 
                                type="text" 
                                id="login_username" 
                                name="username" 
                                placeholder="Enter your username or email" 
                                required 
                                autocomplete="username"
                                maxlength="100"
                            >
                        </div>

                        <div class="form-group">
                            <label for="login_password">Password</label>
                            <input 
                                type="password" 
                                id="login_password" 
                                name="password" 
                                placeholder="Enter your password" 
                                required 
                                autocomplete="current-password"
                                maxlength="255"
                            >
                        </div>

                        <button type="submit" class="submit-btn">Sign In</button>

                        <div class="form-footer">
                            Don't have an account? <a onclick="switchTab('register')">Register here</a>
                        </div>
                    </form>
                </div>

                <!-- Register Tab -->
                <div id="register-tab" class="tab-content <?php echo $active_tab === 'register' ? 'active' : ''; ?>">
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success show">✓ <?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error show">✕ <?php echo $error; ?></div>
                    <?php endif; ?>

                    <div class="password-requirements">
                        <strong>Password must contain:</strong>
                        <ul id="password-checklist">
                            <li class="unmet" data-check="length">At least 8 characters</li>
                            <li class="unmet" data-check="uppercase">One uppercase letter</li>
                            <li class="unmet" data-check="lowercase">One lowercase letter</li>
                            <li class="unmet" data-check="number">One number</li>
                        </ul>
                    </div>

                    <form method="POST" action="authenticate.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="register">

                        <div class="form-group">
                            <label for="reg_full_name">Full Name</label>
                            <input 
                                type="text" 
                                id="reg_full_name" 
                                name="full_name" 
                                placeholder="Enter your full name" 
                                maxlength="100"
                            >
                        </div>

                        <div class="form-group">
                            <label for="reg_username">Username</label>
                            <input 
                                type="text" 
                                id="reg_username" 
                                name="username" 
                                placeholder="Choose a username (3+ characters)" 
                                required 
                                autocomplete="username"
                                maxlength="50"
                                minlength="3"
                            >
                        </div>

                        <div class="form-group">
                            <label for="reg_email">Email</label>
                            <input 
                                type="email" 
                                id="reg_email" 
                                name="email" 
                                placeholder="Enter your email address" 
                                required 
                                autocomplete="email"
                                maxlength="100"
                            >
                        </div>

                        <div class="form-group">
                            <label for="reg_password">Password</label>
                            <input 
                                type="password" 
                                id="reg_password" 
                                name="password" 
                                placeholder="Create a strong password" 
                                required 
                                autocomplete="new-password"
                                maxlength="255"
                            >
                        </div>

                        <div class="form-group">
                            <label for="reg_password_confirm">Confirm Password</label>
                            <input 
                                type="password" 
                                id="reg_password_confirm" 
                                name="password_confirm" 
                                placeholder="Confirm your password" 
                                required 
                                autocomplete="new-password"
                                maxlength="255"
                            >
                        </div>

                        <button type="submit" class="submit-btn">Create Account</button>

                        <div class="form-footer">
                            Already have an account? <a onclick="switchTab('login')">Sign in here</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            // Hide all tabs
            document.getElementById('login-tab').classList.remove('active');
            document.getElementById('register-tab').classList.remove('active');
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tab + '-tab').classList.add('active');
            document.event.target?.closest('.tab')?.classList.add('active');
            
            // Update URL without page reload
            history.replaceState(null, '', '?tab=' + tab);
        }

        // Real-time password validation
        document.getElementById('reg_password')?.addEventListener('input', function() {
            const password = this.value;
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /\d/.test(password)
            };

            document.querySelectorAll('#password-checklist li').forEach(li => {
                const check = li.dataset.check;
                if (checks[check]) {
                    li.classList.remove('unmet');
                } else {
                    li.classList.add('unmet');
                }
            });
        });

        // Password confirmation validation
        document.getElementById('reg_password_confirm')?.addEventListener('blur', function() {
            const password = document.getElementById('reg_password').value;
            if (this.value && this.value !== password) {
                alert('Passwords do not match');
            }
        });
    </script>
</body>
</html>
