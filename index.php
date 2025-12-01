<?php
session_start();

// Use Philippine timezone for all date/time operations
date_default_timezone_set('Asia/Manila');

// Include config and auth
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Demo user store with roles (for backwards compatibility)
$users = [
    'admin' => [
        'password' => password_hash('password123', PASSWORD_DEFAULT),
        'role' => 'admin',
    ],
    'clerk' => [
        'password' => password_hash('clerk123', PASSWORD_DEFAULT),
        'role' => 'user',
    ],
];

$errors = [];
$is_logged_in = !empty($_SESSION['user_id']) || !empty($_SESSION['user']);

// Data file for transactions
$dataFile = __DIR__ . '/transactions.json';

function load_transactions($file) {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function save_transactions($file, $items) {
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $fp = fopen($file, 'c');
    if ($fp === false) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    return false;
}

function e($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function build_url($extra = []) {
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $params = array_merge($_GET, $extra);
    // remove null values
    foreach ($params as $k => $v) if ($v === null) unset($params[$k]);
    return $base . (empty($params) ? '' : ('?' . http_build_query($params)));
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

// Retrieve messages from query string
$success = isset($_GET['success']) ? htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8') : '';
$error_msg = isset($_GET['error']) ? htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') : '';

// Logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle login from form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } else {
        // Try database login first
        $auth_result = authenticate_user($pdo, $username, $password);
        if ($auth_result && isset($auth_result['success']) && $auth_result['success']) {
            $db_user = $auth_result['user'];
            $_SESSION['user_id'] = $db_user['id'];
            $_SESSION['username'] = $db_user['username'];
            $_SESSION['email'] = $db_user['email'];
            $_SESSION['full_name'] = $db_user['full_name'];
            $_SESSION['role'] = $db_user['role'];
            $_SESSION['user'] = htmlspecialchars($db_user['username'], ENT_QUOTES, 'UTF-8');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } elseif (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            // Fallback to demo mode
            $_SESSION['user'] = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $_SESSION['role'] = $users[$username]['role'];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $errors[] = 'Invalid username or password.';
        }
    }
}

// Handle registration from form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');

    if (!$username || !$email || !$password || !$full_name) {
        $errors[] = 'All fields are required.';
    } elseif ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $result = create_user($pdo, $username, $email, $password, $full_name, 'user');
        if ($result && isset($result['success']) && $result['success']) {
            header('Location: ' . build_url(['tab' => 'login', 'success' => 'Registration successful! Please log in.']));
            exit;
        } else {
            // Extract error messages from result
            if ($result && isset($result['errors']) && is_array($result['errors'])) {
                $errors = array_merge($errors, $result['errors']);
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

// Transaction actions (only for logged-in users)
if (!empty($_SESSION['user'])) {
    // Add transaction (allowed for all logged-in users)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tx'])) {
        $type = $_POST['type'] ?? 'income';
        $amount = floatval($_POST['amount'] ?? 0);
        $person = trim($_POST['person'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $dateInput = trim($_POST['tx_date'] ?? '');

        // Parse datetimes using Asia/Manila
        $tz = new DateTimeZone('Asia/Manila');
        if ($dateInput !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $dateInput, $tz);
            if (!$dt) {
                // fallback
                $dt = new DateTime($dateInput, $tz);
            }
        } else {
            $dt = new DateTime('now', $tz);
        }
        $date = $dt->format(DATE_ATOM);

        if (!in_array($type, ['income','expense','allocation'], true)) {
            $errors[] = 'Invalid transaction type.';
        } elseif ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        } elseif ($person === '') {
            $errors[] = 'Person name is required.';
        } elseif ($desc === '') {
            $errors[] = 'Description is required.';
        } else {
            $items = load_transactions($dataFile);
            $items[] = [
                'id' => uniqid('t_', true),
                'type' => $type,
                'amount' => $amount,
                'person' => $person,
                'description' => $desc,
                'tx_date' => $date,
                'created_at' => date('c'),
            ];
            if (!save_transactions($dataFile, $items)) {
                $errors[] = 'Failed to save transaction.';
            } else {
                header('Location: ' . build_url(['page' => $_GET['page'] ?? 1]));
                exit;
            }
        }
    }

    // Update transaction (admin only)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tx'])) {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            header('Location: ' . build_url());
            exit;
        }
        $id = $_POST['id'] ?? '';
        $type = $_POST['type'] ?? 'income';
        $amount = floatval($_POST['amount'] ?? 0);
        $person = trim($_POST['person'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $dateInput = trim($_POST['tx_date'] ?? '');

        // Parse datetimes using Asia/Manila
        $tz = new DateTimeZone('Asia/Manila');
        if ($dateInput !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $dateInput, $tz);
            if (!$dt) {
                $dt = new DateTime($dateInput, $tz);
            }
        } else {
            $dt = new DateTime('now', $tz);
        }
        $date = $dt->format(DATE_ATOM);

        if ($id === '') {
            $errors[] = 'Missing transaction id.';
        } elseif (!in_array($type, ['income','expense','allocation'], true)) {
            $errors[] = 'Invalid transaction type.';
        } elseif ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        } elseif ($person === '') {
            $errors[] = 'Person name is required.';
        } elseif ($desc === '') {
            $errors[] = 'Description is required.';
        } else {
            $items = load_transactions($dataFile);
            $found = false;
            foreach ($items as &$it) {
                if (isset($it['id']) && $it['id'] === $id) {
                    $it['type'] = $type;
                    $it['amount'] = $amount;
                    $it['person'] = $person;
                    $it['description'] = $desc;
                    $it['tx_date'] = $date;
                    $found = true;
                    break;
                }
            }
            unset($it);
            if (!$found) {
                $errors[] = 'Transaction not found.';
            } else {
                if (!save_transactions($dataFile, $items)) {
                    $errors[] = 'Failed to save transaction.';
                } else {
                    header('Location: ' . build_url(['page' => $_GET['page'] ?? 1]));
                    exit;
                }
            }
        }
    }

    // Delete transaction (admin only)
    if (isset($_GET['delete'])) {
        if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            header('Location: ' . build_url());
            exit;
        }
        $delId = $_GET['delete'];
        $items = load_transactions($dataFile);
        $new = [];
        foreach ($items as $it) {
            if (!isset($it['id']) || $it['id'] === $delId) continue;
            $new[] = $it;
        }
        save_transactions($dataFile, $new);
        header('Location: ' . build_url(['page' => $_GET['page'] ?? 1]));
        exit;
    }

    // Export CSV (exports filtered results)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
        $all = load_transactions($dataFile);
        // apply filters
        $q = trim($_GET['q'] ?? '');
        $typeFilter = $_GET['type'] ?? '';
        $from = $_GET['from'] ?? '';
        $to = $_GET['to'] ?? '';

        // filter without using anonymous function to avoid syntax issues
        $filtered = [];
        foreach ($all as $it) {
            if ($q !== '' && stripos($it['description'] ?? '', $q) === false && stripos($it['id'] ?? '', $q) === false && stripos($it['person'] ?? '', $q) === false) continue;
            if ($typeFilter !== '' && ($it['type'] ?? '') !== $typeFilter) continue;
            // robust date comparisons
            $txTs = isset($it['tx_date']) ? strtotime($it['tx_date']) : false;
            if ($from !== '') {
                $fromTs = strtotime($from);
                if ($txTs === false || $txTs < $fromTs) continue;
            }
            if ($to !== '') {
                $toTs = strtotime($to . ' 23:59:59');
                if ($txTs === false || $txTs > $toTs) continue;
            }
            $filtered[] = $it;
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="tarms_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        // compute totals for the filtered set
        $csvTotalIncome = 0; $csvTotalExpense = 0; $csvTotalAllocation = 0;
        foreach ($filtered as $it) {
            $t = $it['type'] ?? '';
            $amt = floatval($it['amount'] ?? 0);
            if ($t === 'income') $csvTotalIncome += $amt;
            if ($t === 'expense') $csvTotalExpense += $amt;
            if ($t === 'allocation') $csvTotalAllocation += $amt;
        }
        $csvBalance = $csvTotalIncome - $csvTotalExpense - $csvTotalAllocation;

        // write a small summary block at top of CSV
        fputcsv($out, ['Summary']);
        fputcsv($out, ['Total income', number_format($csvTotalIncome, 2, '.', '')]);
        fputcsv($out, ['Total expense', number_format($csvTotalExpense, 2, '.', '')]);
        fputcsv($out, ['Total allocation', number_format($csvTotalAllocation, 2, '.', '')]);
        fputcsv($out, ['Balance', number_format($csvBalance, 2, '.', '')]);
        fputcsv($out, []);

        // main header and rows
        fputcsv($out, ['id','type','amount','person','description','tx_date','created_at']);
        foreach ($filtered as $it) {
            fputcsv($out, [
                $it['id'] ?? '',
                $it['type'] ?? '',
                $it['amount'] ?? 0,
                $it['person'] ?? '',
                $it['description'] ?? '',
                $it['tx_date'] ?? '',
                $it['created_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}

// Helpers: search + pagination
function filter_items($items, $q, $typeFilter, $from, $to) {
    $out = [];
    foreach ($items as $it) {
        if ($q !== '' && stripos($it['description'] ?? '', $q) === false && stripos($it['id'] ?? '', $q) === false && stripos($it['person'] ?? '', $q) === false) continue;
        if ($typeFilter !== '' && ($it['type'] ?? '') !== $typeFilter) continue;
        $txTs = isset($it['tx_date']) ? strtotime($it['tx_date']) : false;
        if ($from !== '') {
            $fromTs = strtotime($from);
            if ($txTs === false || $txTs < $fromTs) continue;
        }
        if ($to !== '') {
            $toTs = strtotime($to . ' 23:59:59');
            if ($txTs === false || $txTs > $toTs) continue;
        }
        $out[] = $it;
    }
    return $out;
}

function paginate_items($items, $page, $perPage) {
    $total = count($items);
    $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;
    $page = max(1, min($page, max(1, $totalPages)));
    $offset = ($page - 1) * $perPage;
    $pageItems = array_slice($items, $offset, $perPage);
    return [$pageItems, $total, $totalPages, $page];
}

// Read query params
$q = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPageOptions = [5,10,15,25,50];
$perPage = intval($_GET['per_page'] ?? 15);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 15;
$typeFilter = $_GET['type'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

$allItems = load_transactions($dataFile);
$filteredItems = filter_items($allItems, $q, $typeFilter, $from, $to);
list($pageItems, $totalCount, $totalPages, $page) = paginate_items($filteredItems, $page, $perPage);

// Stats
$totalIncome = array_sum(array_map(function($it){ return ($it['type']??'')==='income' ? floatval($it['amount']??0) : 0; }, $allItems));
$totalExpense = array_sum(array_map(function($it){ return ($it['type']??'')==='expense' ? floatval($it['amount']??0) : 0; }, $allItems));
$totalAllocation = array_sum(array_map(function($it){ return ($it['type']??'')==='allocation' ? floatval($it['amount']??0) : 0; }, $allItems));
$balance = $totalIncome - $totalExpense - $totalAllocation;

?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TARMS — Transaction Account Record Management System</title>
<style>
:root{--bg:#f6f8fb;--card:#fff;--accent:#0b6ea8;--muted:#64748b}
*{box-sizing:border-box}
html,body{height:100%;min-width:0}
body{font-family:Segoe UI,Roboto,Arial;background:linear-gradient(135deg,#0b6ea8 0%,#084f7a 100%);margin:0;padding:10px;font-size:14px;line-height:1.3;min-height:100vh}
.container{max-width:1200px;margin:0 auto;padding:0 12px;overflow-x:hidden;width:100%}
.header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-bottom:14px;gap:8px}
.brand{font-size:18px;font-weight:700;color:#0f1724}
.card{background:var(--card);padding:14px;border-radius:10px;box-shadow:0 10px 30px rgba(2,6,23,0.05);width:100%}
/* Responsive grid: main + sidebar */
.grid{display:grid;grid-template-columns:1fr 320px;gap:12px;align-items:start;min-width:0}
/* Sidebar: make manage column scrollable when content is tall */
.sidebar{max-height:calc(100vh - 160px);overflow:auto;padding-left:0;padding-right:0}
.sidebar .card{margin-bottom:12px}
/* Table container allows horizontal scroll on very small screens */
.table-wrapper{overflow:auto; -webkit-overflow-scrolling:touch; width:100%}
/* Table: fixed layout and force wrapping so every character can fit */
.table{width:150%;border-collapse:collapse;margin-top:12px;table-layout:fixed;min-width:100%}
.table th,.table td{border:1px solid #e6eef8;padding:6px 8px;text-align:left;vertical-align:top;word-break:break-all;white-space:normal;overflow-wrap:anywhere;font-size:13px}
.table th{background:#f8fafc;font-weight:600}
/* Column widths to guide shrinking */
.table th:nth-child(1){width:15%} /* ID */
.table th:nth-child(2){width:8%}  /* Type */
.table th:nth-child(3){width:14%} /* Person */
.table th:nth-child(4){width:10%} /* Amount */
.table th:nth-child(5){width:36%} /* Description */
.table th:nth-child(6){width:10%} /* Date */
.table th:nth-child(7){width:10%} /* Recorded */
.table th:nth-child(8){width:8%}  /* Actions */
.controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
/* Manage column: stack controls vertically by default to avoid overflow */
.sidebar .form-inline{display:grid;grid-template-columns:1fr;gap:8px;align-items:center}
/* Inputs */
input,select,textarea,button{font-family:inherit}
input[type=text],input[type=number],select,input[type=date],input[type=datetime-local]{width:100%;padding:8px;border:1px solid #e6eef8;border-radius:8px;font-size:13px}
.form-inline input,.form-inline select,.form-inline button{min-width:0}
.btn{padding:7px 10px;background:var(--accent);color:#fff;border:0;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px}
.btn.secondary{background:#475569}
.error{background:#fff5f5;color:#9b1c1c;padding:10px;border-radius:8px;margin-bottom:12px;border:1px solid #fed7d7;font-size:13px}
.stat{background:#f1f5f9;padding:8px;border-radius:8px;font-size:13px}
.small{font-size:12px;color:var(--muted)}
.pagination{display:flex;gap:6px;align-items:center;margin-top:12px;flex-wrap:wrap}
.page-btn{padding:6px 8px;border-radius:6px;border:1px solid #e6eef8;background:#fff;cursor:pointer;font-size:13px}
.page-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.tag{display:inline-block;padding:4px 8px;border-radius:6px;background:#eef6fb;color:#0b6ea8;font-weight:600;font-size:12px}
.actions a, .actions span{display:inline-block;max-width:100%;word-break:break-all}

/* QR image styling */
.qr-img{width:72px;height:72px;object-fit:contain;border-radius:6px;border:1px solid #e6eef8}

/* Restore inline form layout on wider screens */
@media (min-width:861px){
    /* Give the manage inline form explicit column widths: select, amount, description, date, person, button */
    /* Set description to a fixed 300px to ensure consistent width */
    .sidebar .form-inline{grid-template-columns:80px 90px 300px 150px 120px 70px;gap:8px}
    .sidebar .form-inline select,
    .sidebar .form-inline input,
    .sidebar .form-inline button{
        width:100%;box-sizing:border-box;
    }
    /* Make date input a bit more flexible */
    .sidebar .form-inline input[type=datetime-local]{min-width:120px}
    /* Also make table description column a bit wider on large screens */
    .table th:nth-child(5){width:42%}
}
/* Responsive breakpoints */
@media (max-width:1100px){
    .grid{grid-template-columns:1fr 300px}
    .table th:nth-child(5){width:40%}
}
@media (max-width:860px){
    .grid{grid-template-columns:1fr}
    .form-inline{grid-template-columns:1fr 1fr}
    .table{table-layout:auto}
}
@media (max-width:600px){
    body{padding:8px;font-size:13px}
    .brand{font-size:16px}
    .form-inline{grid-template-columns:1fr}
    .table th,.table td{padding:6px;font-size:12px}
    .stat{display:block}
}

/* Login Form Styles */
.auth-container{width:100%;max-width:450px;margin:auto}
.auth-card{background:var(--card);border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;animation:slideUp 0.4s ease-out}
@keyframes slideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
.auth-header{background:linear-gradient(135deg,#0b6ea8 0%,#084f7a 100%);color:#fff;padding:30px 30px 20px;text-align:center}
.auth-header h1{margin:0 0 10px;font-size:28px}
.auth-header p{margin:0;font-size:14px;opacity:0.9}
.tabs{display:flex;border-bottom:1px solid #e0e0e0}
.tab{flex:1;padding:15px;text-align:center;cursor:pointer;font-weight:600;border-bottom:3px solid transparent;transition:all 0.3s;color:#999}
.tab.active{border-bottom-color:#0b6ea8;color:#0b6ea8}
.auth-body{padding:30px}
.tab-content{display:none}
.tab-content.active{display:block}
.form-group{margin-bottom:15px}
.form-group label{display:block;margin-bottom:5px;font-weight:600;color:#333;font-size:13px}
.form-group input,.form-group select{width:100%;padding:8px;border:1px solid #e6eef8;border-radius:8px;font-size:13px}
.submit-btn{width:100%;padding:12px;background:linear-gradient(135deg,#0b6ea8 0%,#084f7a 100%);color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;transition:transform 0.2s}
.submit-btn:hover{transform:translateY(-2px)}
.alert{padding:10px;border-radius:8px;margin-bottom:15px;font-size:13px}
.alert-success{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9}
.alert-error{background:#ffebee;color:#c62828;border:1px solid #ffcdd2}
.form-footer{text-align:center;font-size:13px;margin-top:15px}
.form-footer a{color:#0b6ea8;text-decoration:none;cursor:pointer;font-weight:600}
.password-requirements{background:#f1f5f9;border-left:4px solid #fbca04;padding:12px;border-radius:6px;font-size:12px;margin-bottom:15px}
.password-requirements ul{list-style:none;margin:8px 0 0}
.password-requirements li{padding:4px 0;display:flex;align-items:center;gap:8px}
.password-requirements li:before{content:"✓";color:#22863a;font-weight:bold;width:16px}
.password-requirements li.unmet:before{content:"○";color:#999}

/* Show login form when not logged in */
.login-page{display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#0b6ea8 0%,#084f7a 100%)}
.login-page body{background:transparent;padding:0;min-height:auto}
</style>
</head>
<body>

<?php if (!$is_logged_in): ?>
    <!-- LOGIN FORM -->
    <div class="login-page" style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#0b6ea8 0%,#084f7a 100%);padding:20px">
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
                        <?php if ($success): ?>
                            <div class="alert alert-success">✓ <?php echo $success; ?></div>
                        <?php endif; ?>
                        <?php if ($error_msg || !empty($errors)): ?>
                            <div class="alert alert-error">✕ <?php echo $error_msg ?: (isset($errors[0]) ? $errors[0] : 'An error occurred'); ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="login" value="1">
                            <div class="form-group">
                                <label>Username or Email</label>
                                <input type="text" name="username" placeholder="Enter your username or email" required autocomplete="username" maxlength="100">
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" placeholder="Enter your password" required autocomplete="current-password" maxlength="255">
                            </div>
                            <button type="submit" class="submit-btn">Sign In</button>
                            <div class="form-footer">
                                Don't have an account? <a onclick="switchTab('register')">Register here</a>
                            </div>
                        </form>
                    </div>

                    <!-- Register Tab -->
                    <div id="register-tab" class="tab-content <?php echo $active_tab === 'register' ? 'active' : ''; ?>">
                        <?php if ($success): ?>
                            <div class="alert alert-success">✓ <?php echo $success; ?></div>
                        <?php endif; ?>
                        <?php if ($error_msg || !empty($errors)): ?>
                            <div class="alert alert-error">✕ <?php echo $error_msg ?: (isset($errors[0]) ? $errors[0] : 'An error occurred'); ?></div>
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

                        <form method="POST" action="" id="register-form">
                            <input type="hidden" name="register" value="1">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" placeholder="Enter your full name" required maxlength="100">
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="username" placeholder="Choose a username" required maxlength="50">
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" placeholder="Enter your email" required maxlength="100">
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" id="register_password" name="password" placeholder="Enter password" required maxlength="255" onchange="checkPassword()">
                            </div>
                            <div class="form-group">
                                <label>Confirm Password</label>
                                <input type="password" name="password_confirm" placeholder="Confirm password" required maxlength="255">
                            </div>
                            <button type="submit" class="submit-btn" id="register-btn" disabled>Create Account</button>
                            <div class="form-footer">
                                Already have an account? <a onclick="switchTab('login')">Sign in here</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        document.getElementById('login-tab').classList.remove('active');
        document.getElementById('register-tab').classList.remove('active');
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        
        if (tab === 'login') {
            document.getElementById('login-tab').classList.add('active');
            document.querySelector('.tabs .tab:first-child').classList.add('active');
        } else {
            document.getElementById('register-tab').classList.add('active');
            document.querySelector('.tabs .tab:last-child').classList.add('active');
        }
    }

    function checkPassword() {
        const pwd = document.getElementById('register_password').value;
        const checks = {
            length: pwd.length >= 8,
            uppercase: /[A-Z]/.test(pwd),
            lowercase: /[a-z]/.test(pwd),
            number: /\d/.test(pwd)
        };
        
        document.querySelectorAll('#password-checklist li').forEach(li => {
            const check = li.dataset.check;
            if (checks[check]) {
                li.classList.remove('unmet');
            } else {
                li.classList.add('unmet');
            }
        });

        const allMet = Object.values(checks).every(v => v);
        document.getElementById('register-btn').disabled = !allMet;
    }
    </script>

<?php else: ?>
    <!-- TARMS DASHBOARD -->
<div class="container">
    <div class="header">
        <div>
            <div class="brand" style="color:#000">TARMS</div>
            <div class="small" style="color:#000">Transaction Account Record Management System — SK Barangay Mabuhay</div>
        </div>
        <div class="small" style="color:#000">
            <?php if (!empty($_SESSION['user'])): ?>
                Signed in as <?php echo e($_SESSION['user']); ?> (<?php echo e($_SESSION['role'] ?? 'user'); ?>) &nbsp; <a class="btn secondary" href="?logout=1">Log out</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="grid">
            <?php if (!empty($_SESSION['user'])): ?>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <h2 style="margin:0">Transactions</h2>
                        <div class="small">Showing <?php echo e(count($pageItems)); ?> of <?php echo e($totalCount); ?> record(s)</div>
                    </div>
                    <div style="display:flex;gap:12px;align-items:center">
                        <div class="stat"><div class="small">Total income</div><div>₱<?php echo e(number_format($totalIncome,2)); ?></div></div>
                        <div class="stat"><div class="small">Total expense</div><div>₱<?php echo e(number_format($totalExpense,2)); ?></div></div>
                        <div class="stat"><div class="small">Total allocation</div><div>₱<?php echo e(number_format($totalAllocation,2)); ?></div></div>
                        <div class="stat"><div class="small">Balance</div><div>₱<?php echo e(number_format($balance,2)); ?></div></div>
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
                    <form method="get" style="display:flex;gap:8px;align-items:center">
                        <input type="text" name="q" placeholder="Search description or id..." value="<?php echo e($q); ?>">
                        <select name="type">
                            <option value="">All types</option>
                            <option value="income" <?php echo $typeFilter==='income' ? 'selected': ''; ?>>Income</option>
                            <option value="expense" <?php echo $typeFilter==='expense' ? 'selected': ''; ?>>Expense</option>
                            <option value="allocation" <?php echo $typeFilter==='allocation' ? 'selected': ''; ?>>Allocation</option>
                        </select>
                        <input type="date" name="from" value="<?php echo e($from); ?>">
                        <input type="date" name="to" value="<?php echo e($to); ?>">
                        <select name="per_page">
                            <?php foreach ($perPageOptions as $opt): ?>
                                <option value="<?php echo e($opt); ?>" <?php echo $perPage===$opt? 'selected':''; ?>><?php echo e($opt); ?>/page</option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Filter</button>
                    </form>

                    <div class="controls">
                        <form method="post" style="display:inline;margin:0">
                            <button class="btn" name="export_csv" type="submit">Export CSV</button>
                        </form>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="error" style="margin-top:12px">
                        <?php foreach ($errors as $err): ?>
                            <div><?php echo e($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr><th>ID</th><th>Type</th><th>Person</th><th>Amount</th><th>Description</th><th>Date</th><th>Recorded</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pageItems)): ?>
                                <tr><td colspan="8" style="text-align:center;color:#64748b">No records.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pageItems as $it): ?>
                                    <tr>
                                        <td>
                                            <?php $idVal = $it['id'] ?? ''; ?>
                                            <?php if ($idVal !== ''): ?>
                                                <div style="display:flex;gap:8px;align-items:center">
                                                    <img class="qr-img" src="<?php echo 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($idVal); ?>" alt="QR">
                                                    <div style="word-break:break-all;max-width:140px"><div class="small"><?php echo e($idVal); ?></div></div>
                                                </div>
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo '<span class="tag">' . e(ucfirst($it['type'] ?? '')) . '</span>'; ?></td>
                                        <td><?php echo e($it['person'] ?? ''); ?></td>
                                        <td>₱<?php echo e(number_format($it['amount'] ?? 0, 2)); ?></td>
                                        <td><?php echo e($it['description'] ?? ''); ?></td>
                                        <td><?php echo e(!empty($it['tx_date']) ? date('Y-m-d g:i a', strtotime($it['tx_date'])) : ''); ?></td>
                                        <td><?php echo e(!empty($it['created_at']) ? date('Y-m-d g:i a', strtotime($it['created_at'])) : ''); ?></td>
                                        <td class="actions">
                                            <?php if (!empty($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                                <a href="<?php echo e(build_url(['edit' => $it['id'] ?? '', 'page' => $page])); ?>">Edit</a>
                                                <a href="<?php echo e(build_url(['delete' => $it['id'] ?? '', 'page' => $page])); ?>" onclick="return confirm('Delete this transaction?')">Delete</a>
                                            <?php else: ?>
                                                <span class="small">Add only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="<?php echo e(build_url(['page' => $page-1])); ?>">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 3);
                    $end = min($totalPages, $page + 3);
                    for ($p = $start; $p <= $end; $p++):
                    ?>
                        <a class="page-btn <?php echo $p===$page? 'active':''; ?>" href="<?php echo e(build_url(['page' => $p])); ?>"><?php echo e($p); ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?php echo e(build_url(['page' => $page+1])); ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>

            </div>
            <?php else: ?>
            <div>
                <div class="card">
                    <h2 style="margin-top:0">Sign in to TARMS</h2>
                    <?php if (!empty($errors)): ?>
                        <div class="error">
                            <?php foreach ($errors as $err): ?>
                                <div><?php echo e($err); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo e($_SERVER['PHP_SELF']); ?>">
                        <div class="field" style="margin-bottom:8px">
                            <label for="username">Username</label>
                            <input id="username" name="username" type="text" required>
                        </div>
                        <div class="field" style="margin-bottom:8px">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" required>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center">
                            <button class="btn" type="submit" name="login">Sign in</button>
                            <div class="small" style="margin-left:12px">Demo: <strong>admin</strong>/<strong>password123</strong> or <strong>clerk</strong>/<strong>clerk123</strong></div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="sidebar">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <h3 style="margin:5px">Manage</h3>
                </div>

                <?php if (!empty($_SESSION['user'])): ?>

                    <?php if (!empty($_GET['edit'])):
                        $editId = $_GET['edit'];
                        $toEdit = null;
                        foreach ($allItems as $it) { if (isset($it['id']) && $it['id'] === $editId) { $toEdit = $it; break; }}
                    ?>

                        <div class="card" style="margin-bottom:12px">a
                            <h4 style="margin-top:0">Edit transaction</h4>
                            <?php if ($toEdit === null): ?>
                                <div class="small">Transaction not found.</div>
                            <?php else: ?>
                                <?php if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin'): ?>
                                    <div class="small">You do not have permission to edit transactions.</div>
                                    <div style="margin-top:8px"><a class="btn secondary" href="<?php echo e(build_url(['edit'=>null])); ?>">Back</a></div>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="id" value="<?php echo e($toEdit['id']); ?>">
                                        <div style="margin-bottom:8px">
                                            <img class="qr-img" src="<?php echo 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($toEdit['id']); ?>" alt="QR">
                                            <div class="small" style="word-break:break-all;margin-top:6px"><?php echo e($toEdit['id']); ?></div>
                                        </div>
                                         <div style="margin-bottom:8px">
                                             <select name="type">
                                                 <option value="income" <?php echo ($toEdit['type']??'')==='income' ? 'selected':''; ?>>Income</option>
                                                 <option value="expense" <?php echo ($toEdit['type']??'')==='expense' ? 'selected':''; ?>>Expense</option>
                                                 <option value="allocation" <?php echo ($toEdit['type']??'')==='allocation' ? 'selected':''; ?>>Allocation</option>
                                             </select>
                                         </div>
                                        <div style="margin-bottom:8px"><input name="amount" type="number" step="0.01" min="0" value="<?php echo e($toEdit['amount']); ?>" required></div>
                                        <div style="margin-bottom:8px"><input name="description" type="text" value="<?php echo e($toEdit['description']); ?>" required></div>
                                        <div style="margin-bottom:8px"><input name="tx_date" type="datetime-local" value="<?php echo e(!empty($toEdit['tx_date'])? (new DateTime($toEdit['tx_date'], new DateTimeZone('Asia/Manila')))->format('Y-m-d\\TH:i') : ''); ?>"></div>
                                        <div style="margin-bottom:8px"><input name="person" type="text" value="<?php echo e($toEdit['person']); ?>" required></div>
                                        <div style="display:flex;gap:8px"><button class="btn" name="update_tx" type="submit">Save</button><a class="btn secondary" href="<?php echo e(build_url(['edit'=>null])); ?>">Cancel</a></div>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>

                        <div class="card" style="margin-bottom:12px">
                            <h4 style="margin-top:0">Add transaction</h4>
                            <form method="post" class="form-inline">
                                <select name="type">
                                    <option value="income">Income</option>
                                    <option value="expense">Expense</option>
                                    <option value="allocation">Allocation</option>
                                </select>
                                <input name="amount" type="number" step="0.01" min="0" value="0.00" required>
                                <input name="description" type="text" placeholder="Description" required>
                                <input name="tx_date" type="datetime-local" value="<?php echo e((new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d\\TH:i')); ?>">
                                <input name="person" type="text" placeholder="Person" required>
                                <button class="btn" type="submit" name="add_tx">Add</button>
                            </form>
                        </div>

                    <?php endif; ?>

                    <!-- Import / Bulk removed as requested -->

                <?php else: ?>
                    <div class="small">Sign in to manage transactions.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="margin-top:12px" class="small card">
        <strong>About TARMS</strong>
        <p>This Transaction Account Record Management System (TARMS) helps SK Barangay Mabuhay record income, expenses, and fund allocations, generate simple reports, and improve transparency. For production, move users and data to a database and enable HTTPS, stronger authentication, and backups.</p>
    </div>

    <div style="margin-top:12px;color:#64748b" class="small">
        Demo accounts: <strong>admin</strong> / <strong>password123</strong> (admin) — <strong>clerk</strong> / <strong>clerk123</strong> (add-only)
    </div>
</div>
</div><!-- close .container -->
<?php endif; ?><!-- close if not logged in -->
</body>
</html>