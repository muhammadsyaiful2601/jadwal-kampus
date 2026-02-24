<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin(); // Hanya admin yang bisa akses

$database = new Database();
$db = $database->getConnection();

// Tentukan user_id yang akan diganti passwordnya
if (isset($_GET['id'])) {
    $target_user_id = (int)$_GET['id'];
} else {
    $target_user_id = $_SESSION['user_id']; // Default ke akun sendiri
}

// Cek akses: hanya pemilik akun atau superadmin yang boleh mengakses
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'];

if ($current_user_role !== 'superadmin' && $target_user_id != $current_user_id) {
    $_SESSION['error_message'] = "Anda tidak memiliki izin untuk mengubah password admin lain.";
    header('Location: manage_users.php');
    exit();
}

// Ambil data user target
$query = "SELECT id, username, role FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$target_user_id]);
$target_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_user) {
    $_SESSION['error_message'] = "User tidak ditemukan.";
    header('Location: manage_users.php');
    exit();
}

// Proses perubahan password
if (isset($_POST['change_password'])) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validasi input
    if (empty($password) || empty($confirm_password)) {
        $_SESSION['error_message'] = "Password dan konfirmasi password harus diisi.";
    } elseif (strlen($password) < 6) {
        $_SESSION['error_message'] = "Password minimal 6 karakter.";
    } elseif ($password !== $confirm_password) {
        $_SESSION['error_message'] = "Password dan konfirmasi password tidak cocok.";
    } else {
        // Hash password baru
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Update password di database
        $query = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$password_hash, $target_user_id])) {
            // Log aktivitas
            $action_desc = $target_user_id == $current_user_id ? 
                "Mengganti password sendiri" : 
                "Mengganti password user: {$target_user['username']}";
            
            logActivity($db, $current_user_id, 'Ganti Password', $action_desc);
            
            $_SESSION['message'] = "Password berhasil diubah!";
            
            // Redirect berdasarkan role
            if ($target_user_id == $current_user_id) {
                // Jika mengganti password sendiri, redirect ke dashboard
                header('Location: dashboard.php');
            } else {
                // Jika superadmin mengganti password orang lain, redirect ke manage_users
                header('Location: manage_users.php');
            }
            exit();
        } else {
            $_SESSION['error_message'] = "Terjadi kesalahan saat mengubah password.";
        }
    }
}

// Ambil data user yang sedang login untuk tampilan
$query = "SELECT username, role FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 0;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .content-wrapper {
            padding-top: 20px;
            padding-bottom: 30px;
        }
        .page-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .password-input-group {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            z-index: 10;
        }
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 3px;
            transition: all 0.3s;
        }
        .password-strength.weak {
            background-color: #dc3545;
            width: 25%;
        }
        .password-strength.fair {
            background-color: #ffc107;
            width: 50%;
        }
        .password-strength.good {
            background-color: #28a745;
            width: 75%;
        }
        .password-strength.strong {
            background-color: #20c997;
            width: 100%;
        }
        .container-fluid {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Responsive styles */
        @media (max-width: 768px) {
            .page-header {
                padding: 15px;
            }
            .content-wrapper {
                padding-top: 10px;
            }
            .navbar-brand h4 {
                font-size: 1.2rem;
            }
            .btn-group-responsive {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .btn-group-responsive .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .page-header .d-flex {
                flex-direction: column;
                gap: 15px;
            }
            .page-header .text-end {
                text-align: left !important;
            }
            .stats-card .number {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <a class="navbar-brand" href="dashboard.php">
                    <h4 class="mb-0">Ganti Password</h4>
                </a>
                <?php if($current_user_role !== 'superadmin'): ?>
                <span class="badge bg-info ms-2">Mode Terbatas</span>
                <?php endif; ?>
            </div>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent">
                <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
                <div class="d-flex align-items-center ms-auto">
                    <span class="me-3 d-none d-md-block"><?php echo date('d F Y'); ?></span>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" 
                                data-bs-toggle="dropdown">
                            <?php echo htmlspecialchars($current_user['username']); ?>
                            <?php if($_SESSION['is_last_active'] ?? false): ?>
                                <span class="badge bg-warning ms-1" title="Akun aktif terakhir">!</span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid">
        <div class="content-wrapper">
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="mb-3 mb-md-0">
                        <h5 class="mb-1">Ganti Password</h5>
                        <p class="text-muted mb-0">
                            <?php if($target_user_id == $current_user_id): ?>
                                Ubah password akun Anda sendiri
                            <?php else: ?>
                                Ubah password untuk user: <strong><?php echo htmlspecialchars($target_user['username']); ?></strong>
                            <?php endif; ?>
                        </p>
                        <small class="text-info">
                            <i class="fas fa-info-circle"></i> 
                            Password minimal 6 karakter dan sebaiknya menggunakan kombinasi huruf, angka, dan simbol
                        </small>
                    </div>
                    <div class="btn-group-responsive">
                        <a href="manage_users.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Kembali
                        </a>
                    </div>
                </div>
            </div>

            <?php echo displayMessage(); ?>

            <!-- Informasi Hak Akses -->
            <?php if($current_user_role !== 'superadmin' && $target_user_id != $current_user_id): ?>
            <div class="alert alert-danger mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Anda tidak memiliki izin untuk mengubah password admin lain. Hanya superadmin yang dapat melakukannya.
            </div>
            <?php endif; ?>

            <!-- Card Form -->
            <div class="card">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-key me-2"></i>Form Ganti Password
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="passwordForm">
                        <div class="row">
                            <div class="col-lg-6">
                                <!-- Informasi Akun -->
                                <div class="mb-4">
                                    <h6>Informasi Akun</h6>
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="user-avatar me-3" style="width: 50px; height: 50px;">
                                            <?php echo strtoupper(substr($target_user['username'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($target_user['username']); ?></h6>
                                            <span class="badge bg-<?php echo $target_user['role'] == 'superadmin' ? 'danger' : 'primary'; ?>">
                                                <?php echo strtoupper($target_user['role']); ?>
                                            </span>
                                            <?php if($target_user_id == $current_user_id): ?>
                                                <span class="badge bg-info">Akun Anda</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if($current_user_role === 'superadmin' && $target_user_id != $current_user_id): ?>
                                    <div class="alert alert-warning">
                                        <small>
                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                            Anda sebagai superadmin sedang mengganti password admin lain.
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="col-lg-6">
                                <!-- Form Password -->
                                <div class="mb-3">
                                    <label class="form-label">Password Baru</label>
                                    <div class="password-input-group">
                                        <input type="password" name="password" id="password" 
                                               class="form-control" required minlength="6"
                                               placeholder="Masukkan password baru">
                                        <button type="button" class="password-toggle" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength weak" id="passwordStrength"></div>
                                    <div class="form-text" id="passwordFeedback"></div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Konfirmasi Password</label>
                                    <div class="password-input-group">
                                        <input type="password" name="confirm_password" id="confirm_password" 
                                               class="form-control" required minlength="6"
                                               placeholder="Ulangi password baru">
                                        <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text" id="confirmFeedback"></div>
                                </div>
                                
                                <!-- Persyaratan Password -->
                                <div class="mb-4">
                                    <small class="text-muted">Persyaratan Password:</small>
                                    <ul class="small text-muted mb-0">
                                        <li id="reqLength">Minimal 6 karakter</li>
                                        <li id="reqUppercase">Mengandung huruf besar</li>
                                        <li id="reqLowercase">Mengandung huruf kecil</li>
                                        <li id="reqNumber">Mengandung angka</li>
                                        <li id="reqMatch">Password cocok</li>
                                    </ul>
                                </div>
                                
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Simpan Password
                                    </button>
                                    <a href="manage_users.php" class="btn btn-outline-secondary">
                                        Batal
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tips Keamanan -->
            <div class="card mt-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-shield-alt me-2"></i>Tips Keamanan Password
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span>Gunakan password yang unik</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span>Kombinasikan huruf, angka, dan simbol</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span>Jangan gunakan informasi pribadi</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle password visibility
            $('#togglePassword').click(function() {
                const passwordInput = $('#password');
                const icon = $(this).find('i');
                
                if (passwordInput.attr('type') === 'password') {
                    passwordInput.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordInput.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            $('#toggleConfirmPassword').click(function() {
                const confirmInput = $('#confirm_password');
                const icon = $(this).find('i');
                
                if (confirmInput.attr('type') === 'password') {
                    confirmInput.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    confirmInput.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Validasi password
            function checkPasswordStrength(password) {
                let strength = 0;
                
                // Panjang minimal 6 karakter
                if (password.length >= 6) strength++;
                if (password.length >= 8) strength++;
                
                // Mengandung huruf besar
                if (/[A-Z]/.test(password)) strength++;
                
                // Mengandung huruf kecil
                if (/[a-z]/.test(password)) strength++;
                
                // Mengandung angka
                if (/[0-9]/.test(password)) strength++;
                
                // Mengandung simbol
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                return strength;
            }
            
            function updatePasswordRequirements(password, confirmPassword) {
                // Panjang minimal
                $('#reqLength').toggleClass('text-success', password.length >= 6);
                
                // Huruf besar
                $('#reqUppercase').toggleClass('text-success', /[A-Z]/.test(password));
                
                // Huruf kecil
                $('#reqLowercase').toggleClass('text-success', /[a-z]/.test(password));
                
                // Angka
                $('#reqNumber').toggleClass('text-success', /[0-9]/.test(password));
                
                // Cocok
                $('#reqMatch').toggleClass('text-success', password === confirmPassword && password.length >= 6);
            }
            
            function updatePasswordStrength(password) {
                const strength = checkPasswordStrength(password);
                const strengthBar = $('#passwordStrength');
                const feedback = $('#passwordFeedback');
                
                strengthBar.removeClass('weak fair good strong');
                
                if (password.length === 0) {
                    strengthBar.css('width', '0');
                    feedback.text('');
                } else if (strength <= 2) {
                    strengthBar.addClass('weak').css('width', '25%');
                    feedback.text('Password lemah').removeClass().addClass('text-danger');
                } else if (strength <= 4) {
                    strengthBar.addClass('fair').css('width', '50%');
                    feedback.text('Password cukup').removeClass().addClass('text-warning');
                } else if (strength <= 6) {
                    strengthBar.addClass('good').css('width', '75%');
                    feedback.text('Password baik').removeClass().addClass('text-success');
                } else {
                    strengthBar.addClass('strong').css('width', '100%');
                    feedback.text('Password kuat').removeClass().addClass('text-success');
                }
            }
            
            // Event listeners untuk validasi real-time
            $('#password').on('input', function() {
                const password = $(this).val();
                const confirmPassword = $('#confirm_password').val();
                
                updatePasswordStrength(password);
                updatePasswordRequirements(password, confirmPassword);
                
                // Validasi konfirmasi password
                if (confirmPassword && password !== confirmPassword) {
                    $('#confirmFeedback').text('Password tidak cocok').removeClass().addClass('text-danger');
                } else if (confirmPassword && password === confirmPassword) {
                    $('#confirmFeedback').text('Password cocok').removeClass().addClass('text-success');
                } else {
                    $('#confirmFeedback').text('');
                }
            });
            
            $('#confirm_password').on('input', function() {
                const password = $('#password').val();
                const confirmPassword = $(this).val();
                
                updatePasswordRequirements(password, confirmPassword);
                
                if (password && confirmPassword) {
                    if (password !== confirmPassword) {
                        $('#confirmFeedback').text('Password tidak cocok').removeClass().addClass('text-danger');
                    } else {
                        $('#confirmFeedback').text('Password cocok').removeClass().addClass('text-success');
                    }
                } else {
                    $('#confirmFeedback').text('');
                }
            });
            
            // Validasi form sebelum submit
            $('#passwordForm').submit(function(e) {
                const password = $('#password').val();
                const confirmPassword = $('#confirm_password').val();
                
                // Validasi panjang
                if (password.length < 6) {
                    e.preventDefault();
                    alert('Password minimal 6 karakter.');
                    return false;
                }
                
                // Validasi kecocokan
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Password dan konfirmasi password tidak cocok.');
                    return false;
                }
                
                // Konfirmasi untuk superadmin yang mengganti password orang lain
                <?php if($current_user_role === 'superadmin' && $target_user_id != $current_user_id): ?>
                if (!confirm('Anda yakin ingin mengganti password untuk user <?php echo addslashes($target_user['username']); ?>?')) {
                    e.preventDefault();
                    return false;
                }
                <?php endif; ?>
                
                return true;
            });
        });
    </script>
</body>
</html>