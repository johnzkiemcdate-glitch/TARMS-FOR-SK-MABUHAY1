<?php
/**
 * Authentication Handler
 * 
 * Handles:
 * - POST request validation
 * - CSRF token verification
 * - User login and registration
 * - Session management
 * - Database operations
 */

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Security: Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?error=' . urlencode('Invalid request method'));
    exit;
}

// Security: Validate CSRF token
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    error_log("CSRF token validation failed from " . $_SERVER['REMOTE_ADDR']);
    unset($_SESSION['csrf_token']);
    header('Location: login.php?error=' . urlencode('Security token expired. Please try again.'));
    exit;
}

$action = $_POST['action'] ?? 'login';

if ($action === 'login') {
    // ============================================
    // LOGIN HANDLER
    // ============================================
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validation
    $errors = [];
    if (empty($username)) {
        $errors[] = 'Username or email is required';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (!empty($errors)) {
        header('Location: login.php?error=' . urlencode(implode(', ', $errors)));
        exit;
    }
    
    // Authenticate
    $result = authenticate_user($pdo, $username, $password);
    
    if (!$result['success']) {
        header('Location: login.php?error=' . urlencode($result['error']));
        exit;
    }
    
    $user = $result['user'];
    
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    // Store user data in session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
    $_SESSION['email'] = htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8');
    $_SESSION['full_name'] = htmlspecialchars($user['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
    $_SESSION['role'] = htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8');
    $_SESSION['login_time'] = time();
    
    // Clear CSRF token
    unset($_SESSION['csrf_token']);
    
    // Redirect to dashboard
    header('Location: dashboard.php');
    exit;

} elseif ($action === 'register') {
    // ============================================
    // REGISTRATION HANDLER
    // ============================================
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($username) || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long';
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, hyphens, and underscores';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain uppercase, lowercase, and numbers';
    }
    if ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match';
    }
    
    if (!empty($errors)) {
        header('Location: login.php?tab=register&error=' . urlencode(implode('; ', $errors)));
        exit;
    }
    
    // Create user
    $result = create_user($pdo, $username, $email, $password, $full_name, 'user');
    
    if (!$result['success']) {
        header('Location: login.php?tab=register&error=' . urlencode(implode('; ', $result['errors'])));
        exit;
    }
    
    error_log("New user registered: '$username' ($email) from " . $_SERVER['REMOTE_ADDR']);
    
    // Success message and redirect to login
    header('Location: login.php?tab=login&success=' . urlencode('Account created successfully! Please sign in.'));
    exit;

} else {
    header('Location: login.php?error=' . urlencode('Invalid action'));
    exit;
}
