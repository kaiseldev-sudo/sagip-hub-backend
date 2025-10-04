<?php
declare(strict_types=1);

// Relief Hub - Settings Page

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
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Relief Hub</title><pre>Service temporarily unavailable</pre>';
    exit;
}

// Handle form submissions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_profile':
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                
                if ($username === '' || $email === '') {
                    $error = 'Username and email are required';
                } else {
                    // Update admin profile (assuming admin user ID is 1)
                    $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, updated_at = NOW() WHERE id = 1');
                    $stmt->execute([$username, $email, $firstName, $lastName]);
                    $success = 'Profile updated successfully';
                }
                break;
                
            case 'change_password':
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if ($newPassword !== $confirmPassword) {
                    $error = 'New passwords do not match';
                } elseif (strlen($newPassword) < 8) {
                    $error = 'Password must be at least 8 characters long';
                } else {
                    // Verify current password and update
                    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = 1');
                    $stmt->execute();
                    $user = $stmt->fetch();
                    
                    if ($user && password_verify($currentPassword, $user['password_hash'])) {
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = 1');
                        $stmt->execute([$newHash]);
                        $success = 'Password changed successfully';
                    } else {
                        $error = 'Current password is incorrect';
                    }
                }
                break;
                
        }
    } catch (Throwable $e) {
        $error = 'An error occurred: ' . $e->getMessage();
    }
}

// Get current admin profile
$adminProfile = null;
try {
    $stmt = $pdo->prepare('SELECT username, email, first_name, last_name, role, status, created_at FROM users WHERE id = 1');
    $stmt->execute();
    $adminProfile = $stmt->fetch();
} catch (Throwable $e) {
    // Handle error silently
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Relief Hub - Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <style>
        :root { 
            --bg: #f8fafc; 
            --card: #ffffff; 
            --muted: #64748b; 
            --text: #0f172a; 
            --accent: #0ea5e9; 
            --border: #e5e7eb;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
        }
        * { box-sizing: border-box; }
        body { 
            margin: 0; 
            font-family: Poppins, Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; 
            background: var(--bg); 
            color: var(--text); 
        }
        .sidebar { 
            position: fixed; 
            top: 0; 
            bottom: 0; 
            left: 0; 
            width: 220px; 
            background: #ffffff; 
            border-right: 1px solid var(--border); 
            padding: 18px 14px; 
            transition: transform .25s ease; 
            z-index: 950; 
        }
        .sidebar .brand { font-weight: 800; letter-spacing: .4px; }
        .sidebar nav { margin-top: 18px; display: flex; flex-direction: column; gap: 6px; }
        .sidebar a { 
            display: block; 
            color: var(--text); 
            text-decoration: none; 
            padding: 10px 12px; 
            border-radius: 8px; 
        }
        .sidebar a:hover { background: #f1f5f9; }
        .sidebar a.active { background: var(--accent); color: #ffffff; }
        .content { margin-left: 220px; transition: margin-left .25s ease; }
        header { 
            padding: 16px 20px; 
            border-bottom: 1px solid var(--border); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            position: sticky; 
            top: 0; 
            background: #ffffffcc; 
            backdrop-filter: blur(6px); 
            z-index: 1000; 
        }
        header h1 { margin: 0; font-size: 18px; letter-spacing: 0.2px; text-transform: uppercase; }
        #menu-btn { 
            display: none; 
            background: #ffffff; 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            padding: 8px 10px; 
            cursor: pointer; 
        }
        main { padding: 20px; display: grid; grid-template-columns: 1fr; gap: 16px; }
        .card { 
            background: var(--card); 
            border: 1px solid var(--border); 
            border-radius: 12px; 
            padding: 20px; 
            box-shadow: 0 6px 18px rgba(2,6,23,0.05); 
        }
        .card h2 { 
            margin: 0 0 16px 0; 
            font-size: 18px; 
            font-weight: 700; 
            color: var(--text); 
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { 
            display: block; 
            font-weight: 600; 
            margin-bottom: 6px; 
            color: var(--text); 
        }
        .form-group input, 
        .form-group select { 
            width: 100%; 
            padding: 10px 12px; 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            font-size: 14px; 
            transition: border-color 0.2s; 
        }
        .form-group input:focus, 
        .form-group select:focus { 
            outline: none; 
            border-color: var(--accent); 
        }
        .form-row { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 16px; 
        }
        @media (max-width: 600px) { 
            .form-row { grid-template-columns: 1fr; } 
        }
        .btn { 
            background: var(--accent); 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            border-radius: 8px; 
            font-size: 14px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s; 
        }
        .btn:hover { background: #0284c7; }
        .btn-danger { background: var(--error); }
        .btn-danger:hover { background: #dc2626; }
        .btn-success { background: var(--success); }
        .btn-success:hover { background: #059669; }
        .alert { 
            padding: 12px 16px; 
            border-radius: 8px; 
            margin-bottom: 16px; 
            font-weight: 500; 
        }
        .alert-success { 
            background: #dcfce7; 
            color: #065f46; 
            border: 1px solid #bbf7d0; 
        }
        .alert-error { 
            background: #fee2e2; 
            color: #991b1b; 
            border: 1px solid #fecaca; 
        }
        .table-container { 
            overflow-x: auto; 
            margin-top: 16px; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 14px; 
        }
        thead th { 
            text-align: left; 
            color: var(--muted); 
            font-weight: 700; 
            padding: 12px; 
            border-bottom: 1px solid var(--border); 
            background: var(--bg); 
        }
        tbody td { 
            padding: 12px; 
            border-bottom: 1px solid var(--border); 
        }
        .badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 999px; 
            font-size: 11px; 
            font-weight: 600; 
            text-transform: uppercase; 
        }
        .role-admin { background: #fee2e2; color: #991b1b; }
        .role-moderator { background: #fef3c7; color: #92400e; }
        .role-volunteer { background: #cffafe; color: #155e75; }
        .role-public { background: #dcfce7; color: #065f46; }
        .status-active { background: #dcfce7; color: #065f46; }
        .status-inactive { background: #f3f4f6; color: #6b7280; }
        .status-suspended { background: #fee2e2; color: #991b1b; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .tabs { 
            display: flex; 
            border-bottom: 1px solid var(--border); 
            margin-bottom: 20px; 
        }
        .tab { 
            padding: 12px 20px; 
            cursor: pointer; 
            border-bottom: 2px solid transparent; 
            transition: all 0.2s; 
        }
        .tab.active { 
            border-bottom-color: var(--accent); 
            color: var(--accent); 
            font-weight: 600; 
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); top: 60px; height: calc(100% - 60px); }
            .sidebar.open { transform: translateX(0); box-shadow: 0 6px 24px rgba(2,6,23,0.2); }
            .content { margin-left: 0; }
            #menu-btn { display: inline-block; }
        }
        .backdrop { 
            position: fixed; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            top: 60px; 
            background: rgba(2,6,23,0.35); 
            display: none; 
            z-index: 900; 
        }
        .backdrop.show { display: block; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">Relief Hub</div>
        <nav>
            <a href="./dashboard.php">Dashboard</a>
            <a href="./reports.php">Reports</a>
            <a href="./users.php">Users</a>
            <a href="./settings.php" class="active" aria-current="page">Settings</a>
        </nav>
    </aside>
    <div class="backdrop" id="backdrop"></div>
    <div class="content">
        <header>
            <h1>Settings</h1>
            <button id="menu-btn" aria-label="Toggle menu">☰</button>
        </header>
        <main>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="tabs">
                    <div class="tab active" data-tab="profile">Profile</div>
                    <div class="tab" data-tab="security">Security</div>
                    <div class="tab" data-tab="system">System</div>
                </div>

                <!-- Profile Tab -->
                <div class="tab-content active" id="profile">
                    <h2>Profile Settings</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" value="<?= htmlspecialchars($adminProfile['username'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($adminProfile['email'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($adminProfile['first_name'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($adminProfile['last_name'] ?? '') ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn">Update Profile</button>
                    </form>
                </div>

                <!-- Security Tab -->
                <div class="tab-content" id="security">
                    <h2>Security Settings</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" required minlength="8">
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-danger">Change Password</button>
                    </form>
                </div>


                <!-- System Tab -->
                <div class="tab-content" id="system">
                    <h2>System Settings</h2>
                    <div class="form-group">
                        <label>Database Status</label>
                        <div style="padding: 10px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
                            <strong>Status:</strong> <span style="color: var(--success);">Connected</span><br>
                            <strong>Database:</strong> <?= htmlspecialchars($env['DB_NAME'] ?? 'sagiphub') ?><br>
                            <strong>Host:</strong> <?= htmlspecialchars($env['DB_HOST'] ?? '127.0.0.1') ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>System Information</label>
                        <div style="padding: 10px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
                            <strong>PHP Version:</strong> <?= PHP_VERSION ?><br>
                            <strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?><br>
                            <strong>Document Root:</strong> <?= $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown' ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Application Statistics</label>
                        <div style="padding: 10px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
                            <?php
                            try {
                                $totalRequests = $pdo->query('SELECT COUNT(*) FROM help_requests')->fetchColumn();
                                $totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                                $activeRequests = $pdo->query('SELECT COUNT(*) FROM help_requests WHERE status = "active"')->fetchColumn();
                            } catch (Throwable $e) {
                                $totalRequests = $totalUsers = $activeRequests = 'Error';
                            }
                            ?>
                            <strong>Total Help Requests:</strong> <?= $totalRequests ?><br>
                            <strong>Total Users:</strong> <?= $totalUsers ?><br>
                            <strong>Active Requests:</strong> <?= $activeRequests ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                
                // Remove active class from all tabs and content
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                document.getElementById(targetTab).classList.add('active');
            });
        });

        // Mobile sidebar toggle
        const menuBtn = document.getElementById('menu-btn');
        const sidebar = document.querySelector('.sidebar');
        const backdrop = document.getElementById('backdrop');
        
        menuBtn?.addEventListener('click', function() {
            const open = sidebar?.classList.toggle('open');
            if (open) { 
                backdrop?.classList.add('show'); 
            } else { 
                backdrop?.classList.remove('show'); 
            }
        });
        
        backdrop?.addEventListener('click', function() {
            sidebar?.classList.remove('open');
            backdrop?.classList.remove('show');
        });

        // Password confirmation validation
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
