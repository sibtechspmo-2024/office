<?php
require_once 'db.php';

$error = '';
$success = '';

// --- LOGIN HANDLER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password']) || $password === $user['password']) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            if (strtolower($user['role']) == 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: user_dashboard.php");
            }
            exit;
        } else {
            $error = "Maling password!";
        }
    } else {
        $error = "Hindi mahanap ang user!";
    }
}

// --- REGISTER (ADD ACCOUNT) HANDLER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $role = 'user'; // Automatic na 'user' role

    if ($password !== $confirm_password) {
        $error = "Hindi magkatugma ang password!";
    } else {
        // Suriin kung umiiral na ang username
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $error = "Ang username na ito ay ginagamit na!";
        } else {
            // I-hash ang password para sa seguridad
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            $insert_stmt = $conn->prepare("INSERT INTO users (fullname, username, password, role) VALUES (?, ?, ?, ?)");
            $insert_stmt->bind_param("ssss", $fullname, $username, $hashed_password, $role);

            if ($insert_stmt->execute()) {
                $success = "Matagumpay na nakagawa ng account! Maaari ka nang mag-login.";
            } else {
                $error = "Nagkaroon ng problema sa paggawa ng account. Subukan muli.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIBTECH - Portal Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/index.css">
</head>
<body class="d-flex align-items-center justify-content-center py-5">

<div class="container" style="max-width: 440px;">
    <div class="card card-login shadow">
        <div class="brand-header">
            <img src="logo.jpg" alt="SIBTECH Logo" class="logo-img rounded-circle border border-2 border-light">
            <h4 class="fw-bold mb-0">SIBTECH PORTAL</h4>
            <small class="opacity-75">Supply Order & Management Portal</small>
        </div>
        <div class="card-body p-4">

            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-1"></i> <?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- LOGIN FORM -->
            <form method="POST">
                <input type="hidden" name="login" value="1">
                <div class="mb-3">
                    <label class="form-label fw-semibold small text-secondary">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" required placeholder="Ilagay ang username">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold small text-secondary">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required placeholder="Ilagay ang password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary-logo w-100 fw-bold py-2 mb-3 shadow-sm">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Login
                </button>
            </form>

            <div class="text-center pt-2 border-top">
                <p class="small text-muted mb-1">Wala pang account?</p>
                <button type="button" class="btn btn-link text-logo-blue fw-bold p-0 text-decoration-none small" data-bs-toggle="modal" data-bs-target="#registerModal">
                    <i class="bi bi-person-plus-fill me-1"></i> Gumawa ng Bagong Account
                </button>
            </div>
        </div>
    </div>
</div>

<!-- REGISTER / ADD ACCOUNT MODAL -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white bg-primary-logo" style="background-color: var(--logo-blue);">
                <h5 class="modal-title fw-bold" id="registerModalLabel"><i class="bi bi-person-plus me-2"></i>Add Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="register" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">Buong Pangalan (Full Name)</label>
                        <input type="text" name="fullname" class="form-control" placeholder="Juan Dela Cruz" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="Ilagay ang napiling username" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Gumawa ng password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">Kumpirmahin ang Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Ulitin ang password" required>
                    </div>

                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle-fill me-1"></i> Ang bagong account na ito ay awtomatikong magiging <strong>User</strong> role.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kanselahin</button>
                    <button type="submit" class="btn btn-primary-logo btn-sm fw-bold px-3">I-register</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>