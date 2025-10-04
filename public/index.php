<?php
declare(strict_types=1);

// Relief Hub - Admin Login Page

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Util.php';

use ReliefHub\Backend\Env;
use ReliefHub\Backend\Database;
use ReliefHub\Backend\Util;

// Initialize database connection
try {
    $env = Env::load(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');
    $pdo = Database::connect($env);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Relief Hub Admin</title><pre>Service temporarily unavailable</pre>';
    exit;
}

// Handle login form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required';
    } else {
        // Simple admin authentication (you should implement proper password hashing)
        // For now, using basic credentials - replace with proper admin table
        if ($username === 'admin' && $password === 'admin123') {
            session_start();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Relief Hub - Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
        :root {
            --primary: #0ea5e9;
            --primary-dark: #0284c7;
            --success: #10b981;
            --error: #ef4444;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        * { 
            box-sizing: border-box; 
        }
        
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: var(--card);
            border-radius: 16px;
            padding: 48px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 400px;
            margin: 20px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .logo h1 {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
            margin: 0 0 8px 0;
        }
        
        .logo p {
            color: var(--text-muted);
            margin: 0;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text);
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }
        
        .btn:hover {
            background: var(--primary-dark);
        }
        
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-success {
            background: #dcfce7;
            color: #065f46;
            border: 1px solid #bbf7d0;
        }
        
        .admin-info {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .admin-info h4 {
            margin: 0 0 8px 0;
            color: var(--primary);
            font-size: 14px;
        }
        
        .admin-info p {
            margin: 4px 0;
            font-family: monospace;
            background: white;
            padding: 4px 8px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>Relief Hub</h1>
            <p>Admin Portal</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Enter your username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter your password">
            </div>

            <button type="submit" class="btn">Sign In</button>
        </form>

        <div class="admin-info">
            <h4>Default Admin Credentials</h4>
            <p><strong>Username:</strong> admin</p>
            <p><strong>Password:</strong> admin123</p>
            <p><em>Please change these credentials in production!</em></p>
        </div>
    </div>
</body>
</html>


