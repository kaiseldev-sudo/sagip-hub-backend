<?php
declare(strict_types=1);

// Relief Hub - Main Landing Page

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

// Handle form submission
$success = false;
$error = '';
$submittedData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $requestType = $_POST['request_type'] ?? '';
    $urgency = $_POST['urgency'] ?? '';
    $people = (int)($_POST['people_affected'] ?? 1);
    $lat = (float)($_POST['latitude'] ?? 0);
    $lng = (float)($_POST['longitude'] ?? 0);
    $contact = preg_replace('/\s+/', '', $_POST['contact_number'] ?? '');

    // Validation
    if ($title === '' || $description === '') {
        $error = 'Title and description are required';
    } elseif (!in_array($requestType, ['medical','rescue','food','shelter','supplies','other'], true)) {
        $error = 'Please select a valid request type';
    } elseif (!in_array($urgency, ['critical','high','medium','low'], true)) {
        $error = 'Please select a valid urgency level';
    } elseif ($people < 1) {
        $error = 'Number of people affected must be at least 1';
    } elseif ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        $error = 'Please provide valid coordinates';
    } else {
        // Create the request
        $publicId = Util::generateUuidV4();
        $editToken = Util::generateToken(24);
        $editTokenHash = Util::sha256($editToken);
        $contactLast4 = $contact !== '' ? substr($contact, -4) : null;
        $contactCipher = $contact !== '' ? $contact : null;
        $ip = Util::ipToBinary($_SERVER['REMOTE_ADDR'] ?? null);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO help_requests (public_id, title, description, request_type, urgency, people_affected, latitude, longitude, location, contact_number, contact_last4, edit_token_hash, status, submitted_ip, submitted_user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, POINT(?, ?), ?, ?, ?, "active", ?, ?)'
            );
            $stmt->execute([
                $publicId,
                $title,
                $description,
                $requestType,
                $urgency,
                $people,
                $lat,
                $lng,
                $lng,
                $lat,
                $contactCipher,
                $contactLast4,
                $editTokenHash,
                $ip,
                $ua,
            ]);

            $requestId = (int)$pdo->lastInsertId();
            $evt = $pdo->prepare('INSERT INTO request_events (request_id, event_type, event_data) VALUES (?, "created", JSON_OBJECT("ip", ?))');
            $evt->execute([$requestId, $_SERVER['REMOTE_ADDR'] ?? null]);
            $pdo->commit();

            $success = true;
            $submittedData = [
                'public_id' => $publicId,
                'edit_token' => $editToken,
                'title' => $title
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Failed to submit request. Please try again.';
        }
    }
}

// Get recent requests for display
$recentRequests = [];
try {
    $stmt = $pdo->query("SELECT public_id, title, request_type, urgency, people_affected, created_at FROM v_public_help_requests ORDER BY created_at DESC LIMIT 5");
    $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Ignore errors for recent requests display
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Relief Hub - Emergency Help Request System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
        :root {
            --primary: #0ea5e9;
            --primary-dark: #0284c7;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        * { box-sizing: border-box; }
        
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 20px 0;
            box-shadow: var(--shadow);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            text-decoration: none;
        }
        
        .nav {
            display: flex;
            gap: 20px;
        }
        
        .nav a {
            color: var(--text);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .nav a:hover {
            background: var(--bg);
        }
        
        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        
        .hero h1 {
            font-size: 48px;
            font-weight: 800;
            margin: 0 0 20px 0;
            line-height: 1.2;
        }
        
        .hero p {
            font-size: 20px;
            margin: 0 0 40px 0;
            opacity: 0.9;
        }
        
        .cta-button {
            display: inline-block;
            background: white;
            color: var(--primary);
            padding: 16px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 18px;
            box-shadow: var(--shadow-lg);
            transition: all 0.2s;
        }
        
        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .main-content {
            padding: 80px 0;
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 80px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }
        }
        
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            box-shadow: var(--shadow);
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
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
            }
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
        
        .recent-requests {
            margin-top: 40px;
        }
        
        .request-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .request-info h4 {
            margin: 0 0 4px 0;
            font-size: 16px;
        }
        
        .request-meta {
            font-size: 14px;
            color: var(--text-muted);
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .urgency-critical { background: #fee2e2; color: #991b1b; }
        .urgency-high { background: #fef3c7; color: #92400e; }
        .urgency-medium { background: #cffafe; color: #155e75; }
        .urgency-low { background: #dcfce7; color: #065f46; }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 32px;
            margin: 80px 0;
        }
        
        .feature {
            text-align: center;
            padding: 32px;
        }
        
        .feature-icon {
            width: 64px;
            height: 64px;
            background: var(--primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 24px;
        }
        
        .feature h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 12px 0;
        }
        
        .feature p {
            color: var(--text-muted);
            margin: 0;
        }
        
        footer {
            background: var(--text);
            color: white;
            padding: 40px 0;
            text-align: center;
        }
        
        .success-details {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .success-details h3 {
            color: var(--primary);
            margin: 0 0 12px 0;
        }
        
        .success-details p {
            margin: 8px 0;
            font-family: monospace;
            background: white;
            padding: 8px;
            border-radius: 4px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <a href="#" class="logo">Relief Hub</a>
                <nav class="nav">
                    <a href="#submit">Submit Request</a>
                    <a href="dashboard.php">Dashboard</a>
                    <a href="reports.php">Reports</a>
                </nav>
            </div>
        </div>
    </header>

    <section class="hero">
        <div class="container">
            <h1>Emergency Help Request System</h1>
            <p>Connect those in need with emergency responders and volunteers in your community</p>
            <a href="#submit" class="cta-button">Submit Help Request</a>
        </div>
    </section>

    <main class="main-content">
        <div class="container">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Request submitted successfully!</strong> Your help request has been recorded and will be reviewed by emergency responders.
                </div>
                <div class="success-details">
                    <h3>Request Details</h3>
                    <p><strong>Request ID:</strong> <?= htmlspecialchars($submittedData['public_id']) ?></p>
                    <p><strong>Edit Token:</strong> <?= htmlspecialchars($submittedData['edit_token']) ?></p>
                    <p><strong>Title:</strong> <?= htmlspecialchars($submittedData['title']) ?></p>
                    <p><em>Save your edit token to withdraw this request if needed.</em></p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="grid">
                <div class="card">
                    <h2 style="margin: 0 0 24px 0; font-size: 24px;">Submit Help Request</h2>
                    <form method="POST" id="submit">
                        <div class="form-group">
                            <label for="title">Request Title *</label>
                            <input type="text" id="title" name="title" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" placeholder="Brief description of your emergency">
                        </div>

                        <div class="form-group">
                            <label for="description">Description *</label>
                            <textarea id="description" name="description" required placeholder="Provide detailed information about your situation"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="request_type">Type of Request *</label>
                                <select id="request_type" name="request_type" required>
                                    <option value="">Select type</option>
                                    <option value="medical" <?= ($_POST['request_type'] ?? '') === 'medical' ? 'selected' : '' ?>>Medical Emergency</option>
                                    <option value="rescue" <?= ($_POST['request_type'] ?? '') === 'rescue' ? 'selected' : '' ?>>Rescue/Search</option>
                                    <option value="food" <?= ($_POST['request_type'] ?? '') === 'food' ? 'selected' : '' ?>>Food/Water</option>
                                    <option value="shelter" <?= ($_POST['request_type'] ?? '') === 'shelter' ? 'selected' : '' ?>>Shelter</option>
                                    <option value="supplies" <?= ($_POST['request_type'] ?? '') === 'supplies' ? 'selected' : '' ?>>Supplies</option>
                                    <option value="other" <?= ($_POST['request_type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="urgency">Urgency Level *</label>
                                <select id="urgency" name="urgency" required>
                                    <option value="">Select urgency</option>
                                    <option value="critical" <?= ($_POST['urgency'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical</option>
                                    <option value="high" <?= ($_POST['urgency'] ?? '') === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="medium" <?= ($_POST['urgency'] ?? '') === 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="low" <?= ($_POST['urgency'] ?? '') === 'low' ? 'selected' : '' ?>>Low</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="people_affected">People Affected *</label>
                                <input type="number" id="people_affected" name="people_affected" min="1" required value="<?= htmlspecialchars($_POST['people_affected'] ?? '1') ?>">
                            </div>

                            <div class="form-group">
                                <label for="contact_number">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>" placeholder="Optional">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="latitude">Latitude *</label>
                                <input type="number" id="latitude" name="latitude" step="any" required value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>" placeholder="e.g., 14.5995">
                            </div>

                            <div class="form-group">
                                <label for="longitude">Longitude *</label>
                                <input type="number" id="longitude" name="longitude" step="any" required value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>" placeholder="e.g., 120.9842">
                            </div>
                        </div>

                        <button type="submit" class="btn">Submit Help Request</button>
                    </form>
                </div>

                <div class="card">
                    <h2 style="margin: 0 0 24px 0; font-size: 24px;">Recent Requests</h2>
                    <?php if (empty($recentRequests)): ?>
                        <p style="color: var(--text-muted); text-align: center; padding: 40px 0;">No recent requests</p>
                    <?php else: ?>
                        <div class="recent-requests">
                            <?php foreach ($recentRequests as $request): ?>
                                <div class="request-item">
                                    <div class="request-info">
                                        <h4><?= htmlspecialchars($request['title']) ?></h4>
                                        <div class="request-meta">
                                            <?= htmlspecialchars($request['request_type']) ?> • 
                                            <?= (int)$request['people_affected'] ?> people • 
                                            <?= date('M j, Y H:i', strtotime($request['created_at'])) ?>
                                        </div>
                                    </div>
                                    <span class="badge urgency-<?= htmlspecialchars($request['urgency']) ?>">
                                        <?= htmlspecialchars($request['urgency']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="text-align: center; margin-top: 20px;">
                            <a href="dashboard.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">View All Requests →</a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="features">
                <div class="feature">
                    <div class="feature-icon">🚨</div>
                    <h3>Emergency Response</h3>
                    <p>Connect directly with emergency responders and first aid teams in your area for critical situations.</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">📍</div>
                    <h3>Location-Based</h3>
                    <p>Requests are automatically geotagged to help responders locate and reach you quickly.</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">👥</div>
                    <h3>Community Support</h3>
                    <p>Get help from volunteers and community members who can provide immediate assistance.</p>
                </div>
                <div class="feature">
                    <div class="feature-icon">📱</div>
                    <h3>Real-Time Updates</h3>
                    <p>Track your request status and receive updates as responders work to help you.</p>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; 2024 Relief Hub. Emergency Help Request System.</p>
        </div>
    </footer>

    <script>
        // Auto-fill coordinates if geolocation is available
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                document.getElementById('latitude').value = position.coords.latitude.toFixed(6);
                document.getElementById('longitude').value = position.coords.longitude.toFixed(6);
            });
        }

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>


