<?php
/**
 * User Authentication Helper Functions
 */

require_once __DIR__ . '/config.php';

/**
 * Create a new user in the database
 */
function create_user($pdo, $username, $email, $password, $full_name = '', $role = 'user') {
    $errors = [];
    
    // Validate input
    if (empty($username) || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address';
    }
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    try {
        // Check if username or email already exists
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            return ['success' => false, 'errors' => ['Username or email already exists']];
        }
        
        // Hash password and insert user
        $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $stmt = $pdo->prepare('
            INSERT INTO users (username, email, password_hash, full_name, role)
            VALUES (?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([$username, $email, $password_hash, $full_name, $role]);
        
        return ['success' => true, 'user_id' => $pdo->lastInsertId()];
        
    } catch (PDOException $e) {
        error_log("Error creating user: " . $e->getMessage());
        return ['success' => false, 'errors' => ['Database error. Please try again.']];
    }
}

/**
 * Authenticate user by username/email and password
 */
function authenticate_user($pdo, $username, $password) {
    if (empty($username) || empty($password)) {
        return ['success' => false, 'error' => 'Username and password are required'];
    }
    
    try {
        // Find user by username or email
        $stmt = $pdo->prepare('
            SELECT id, username, email, password_hash, full_name, role 
            FROM users 
            WHERE (username = ? OR email = ?) AND is_active = 1
        ');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            error_log("Failed login attempt: user '$username' not found from " . $_SERVER['REMOTE_ADDR']);
            return ['success' => false, 'error' => 'Invalid username/email or password'];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            error_log("Failed login attempt: incorrect password for '{$user['username']}' from " . $_SERVER['REMOTE_ADDR']);
            return ['success' => false, 'error' => 'Invalid username/email or password'];
        }
        
        // Update last login time
        $stmt = $pdo->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$user['id']]);
        
        error_log("Successful login: '{$user['username']}' from " . $_SERVER['REMOTE_ADDR']);
        
        return [
            'success' => true,
            'user' => $user
        ];
        
    } catch (PDOException $e) {
        error_log("Authentication error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error. Please try again.'];
    }
}

/**
 * Get user by ID
 */
function get_user($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT id, username, email, full_name, role, last_login 
            FROM users 
            WHERE id = ? AND is_active = 1
        ');
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching user: " . $e->getMessage());
        return null;
    }
}
