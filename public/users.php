<?php
declare(strict_types=1);

// Relief Hub - User Management Page

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
            case 'create_user':
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'public';
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                
                if ($username === '' || $email === '' || $password === '') {
                    $error = 'Username, email, and password are required';
                } elseif (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters long';
                } else {
                    $publicId = Util::generateUuidV4();
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare('INSERT INTO users (public_id, username, email, password_hash, first_name, last_name, role, status, email_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, "active", 1, NOW())');
                    $stmt->execute([$publicId, $username, $email, $passwordHash, $firstName, $lastName, $role]);
                    $success = 'User created successfully';
                }
                break;
                
            case 'update_user':
                $userId = (int)($_POST['user_id'] ?? 0);
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $role = $_POST['role'] ?? 'public';
                $status = $_POST['status'] ?? 'active';
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                
                if ($userId === 0 || $username === '' || $email === '') {
                    $error = 'User ID, username, and email are required';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, role = ?, status = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute([$username, $email, $firstName, $lastName, $role, $status, $userId]);
                    $success = 'User updated successfully';
                }
                break;
                
            case 'delete_user':
                $userId = (int)($_POST['user_id'] ?? 0);
                
                if ($userId === 0) {
                    $error = 'User ID is required';
                } elseif ($userId === 1) {
                    $error = 'Cannot delete the main admin user';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$userId]);
                    $success = 'User deleted successfully';
                }
                break;
        }
    } catch (Throwable $e) {
        $error = 'An error occurred: ' . $e->getMessage();
    }
}

// Get all users for management
$users = [];
try {
    $stmt = $pdo->query('SELECT id, username, email, first_name, last_name, role, status, email_verified, created_at FROM users ORDER BY created_at DESC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Handle error silently
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Relief Hub - User Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css" />
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
        .btn-sm { padding: 6px 12px; font-size: 12px; }
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
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 2000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.5); 
        }
        .modal-content { 
            background-color: var(--card); 
            margin: 5% auto; 
            padding: 20px; 
            border-radius: 12px; 
            width: 90%; 
            max-width: 500px; 
        }
        .modal-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px; 
        }
        .close { 
            color: var(--muted); 
            font-size: 28px; 
            font-weight: bold; 
            cursor: pointer; 
        }
        .close:hover { color: var(--text); }
        .actions { 
            display: flex; 
            gap: 8px; 
        }
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
        
        /* DataTables customization */
        .dataTables_wrapper {
            margin-top: 16px;
        }
        
        .dataTables_filter {
            margin-bottom: 16px;
        }
        
        .dataTables_filter input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
        }
        
        .dataTables_length {
            margin-bottom: 16px;
        }
        
        .dataTables_length select {
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }
        
        .dataTables_info {
            color: var(--muted);
            font-size: 14px;
        }
        
        .dataTables_paginate {
            margin-top: 16px;
        }
        
        .dataTables_paginate .paginate_button {
            padding: 8px 12px;
            margin: 0 2px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--card);
            color: var(--text);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .dataTables_paginate .paginate_button:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        
        .dataTables_paginate .paginate_button.current {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }
        
        .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .dataTables_paginate .paginate_button.disabled:hover {
            background: var(--card);
            color: var(--text);
            border-color: var(--border);
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">Relief Hub</div>
        <nav>
            <a href="./dashboard.php">Dashboard</a>
            <a href="./reports.php">Reports</a>
            <a href="./users.php" class="active" aria-current="page">Users</a>
            <a href="./settings.php">Settings</a>
        </nav>
    </aside>
    <div class="backdrop" id="backdrop"></div>
    <div class="content">
        <header>
            <h1>User Management</h1>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h2 style="margin: 0;">All Users</h2>
                    <button class="btn btn-success" onclick="openCreateModal()">Create New User</button>
                </div>
                <div class="table-container">
                    <table id="usersTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?></td>
                                    <td>
                                        <span class="badge role-<?= htmlspecialchars($user['role']) ?>">
                                            <?= htmlspecialchars($user['role']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge status-<?= htmlspecialchars($user['status']) ?>">
                                            <?= htmlspecialchars($user['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-sm" onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">Edit</button>
                                            <?php if ($user['id'] != 1): ?>
                                                <button class="btn btn-sm btn-danger" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">Delete</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit User</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_username">Username</label>
                        <input type="text" id="edit_username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_first_name">First Name</label>
                        <input type="text" id="edit_first_name" name="first_name">
                    </div>
                    <div class="form-group">
                        <label for="edit_last_name">Last Name</label>
                        <input type="text" id="edit_last_name" name="last_name">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_role">Role</label>
                        <select id="edit_role" name="role" required>
                            <option value="public">Public</option>
                            <option value="volunteer">Volunteer</option>
                            <option value="moderator">Moderator</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                            <option value="pending_verification">Pending Verification</option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create User Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New User</h3>
                <span class="close" onclick="closeCreateModal()">&times;</span>
            </div>
            <form method="POST" id="createForm">
                <input type="hidden" name="action" value="create_user">
                <div class="form-row">
                    <div class="form-group">
                        <label for="create_username">Username</label>
                        <input type="text" id="create_username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="create_email">Email</label>
                        <input type="email" id="create_email" name="email" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="create_password">Password</label>
                        <input type="password" id="create_password" name="password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="create_role">Role</label>
                        <select id="create_role" name="role" required>
                            <option value="public">Public</option>
                            <option value="volunteer">Volunteer</option>
                            <option value="moderator">Moderator</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="create_first_name">First Name</label>
                        <input type="text" id="create_first_name" name="first_name">
                    </div>
                    <div class="form-group">
                        <label for="create_last_name">Last Name</label>
                        <input type="text" id="create_last_name" name="last_name">
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <span class="close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <p>Are you sure you want to delete user <strong id="delete_username"></strong>? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#usersTable').DataTable({
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "order": [[5, "desc"]], // Sort by Created date descending
                "columnDefs": [
                    { "orderable": false, "targets": 6 } // Disable sorting on Actions column
                ],
                "language": {
                    "search": "Search users:",
                    "lengthMenu": "Show _MENU_ users per page",
                    "info": "Showing _START_ to _END_ of _TOTAL_ users",
                    "infoEmpty": "No users found",
                    "infoFiltered": "(filtered from _MAX_ total users)",
                    "zeroRecords": "No matching users found",
                    "paginate": {
                        "first": "First",
                        "last": "Last",
                        "next": "Next",
                        "previous": "Previous"
                    }
                },
                "dom": '<"top"lf>rt<"bottom"ip><"clear">',
                "responsive": true
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

        // Modal functions
        function openCreateModal() {
            // Clear form
            document.getElementById('createForm').reset();
            document.getElementById('createModal').style.display = 'block';
        }

        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }

        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_first_name').value = user.first_name || '';
            document.getElementById('edit_last_name').value = user.last_name || '';
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_status').value = user.status;
            document.getElementById('editModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function deleteUser(userId, username) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_username').textContent = username;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const createModal = document.getElementById('createModal');
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === createModal) {
                closeCreateModal();
            }
            if (event.target === editModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>
