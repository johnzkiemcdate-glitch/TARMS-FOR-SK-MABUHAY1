<?php
/**
 * Database Configuration & Initialization
 * 
 * Uses SQLite for simplicity; easily switchable to MySQL/PostgreSQL
 * Initializes the database schema if it doesn't exist
 */

// Database file path (store outside web root in production)
$db_path = __DIR__ . '/app.db';

try {
    // Create or connect to SQLite database
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Enable foreign keys
    $pdo->exec('PRAGMA foreign_keys = ON');
    
    // Initialize database tables if they don't exist
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            full_name TEXT,
            role TEXT DEFAULT "user",
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME
        )
    ');
    
    // Create index for faster lookups
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_username ON users(username)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email ON users(email)');
    
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please contact the administrator.");
}
