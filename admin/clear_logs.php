<?php
// clear_logs.php - Hanya untuk superadmin
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireSuperAdmin(); // Hanya superadmin yang bisa akses

$database = new Database();
$db = $database->getConnection();

if(isset($_POST['clear_logs'])) {
    // Verifikasi password superadmin
    if(empty($_POST['confirm_password'])) {
        $_SESSION['error_message'] = "Password konfirmasi harus diisi!";
        header('Location: clear_logs.php');
        exit();
    }
    
    // Ambil password hash user
    $query = "SELECT password FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$user || !password_verify($_POST['confirm_password'], $user['password'])) {
        $_SESSION['error_message'] = "Password salah!";
        header('Location: clear_logs.php');
        exit();
    }
    
    // Hapus semua log aktivitas
    $query = "DELETE FROM activity_logs";
    $stmt = $db->prepare($query);
    $success = $stmt->execute();
    
    if($success) {
        // Log activity sebelum dihapus (log terakhir)
        $logQuery = "INSERT INTO activity_logs (user_id, action, ip_address, user_agent) 
                    VALUES (:user_id, :action, :ip, :agent)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->bindParam(':user_id', $_SESSION['user_id']);
        $action = 'Clear All Logs';
        $logStmt->bindParam(':action', $action);
        $logStmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $logStmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $logStmt->execute();
        
        $_SESSION['message'] = "Semua log aktivitas berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "Gagal menghapus log aktivitas.";
    }
    
    header('Location: clear_logs.php');
    exit();
}

// Hitung total log
$query = "SELECT COUNT(*) as total FROM activity_logs";
$stmt = $db->prepare($query);
$stmt->execute();
$total_logs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Ambil 5 log terbaru untuk preview
$query = "SELECT al.*, u.username, u.role FROM activity_logs al 
          LEFT JOIN users u ON al.user_id = u.id 
          ORDER BY al.created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hapus Log Aktivitas - Admin Panel</title>
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
        .log-preview {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .log-item {
            border-left: 4px solid #dc3545;
            padding: 10px 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        .badge-superadmin {
            background-color: #dc3545;
        }
        .badge-admin {
            background-color: #6c757d;
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
            .log-preview {
                padding: 15px;
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
            .log-item {
                flex-direction: column;
                gap: 10px;
            }
            .log-item .text-end {
                text-align: left !important;
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
                    <h4 class="mb-0">Hapus Log Aktivitas</h4>
                </a>
                <span class="badge bg-danger ms-2">Superadmin Only</span>
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
                            <?php echo htmlspecialchars($_SESSION['username']); ?>
                            <span class="badge bg-danger ms-1">Superadmin</span>
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
                        <h5 class="mb-1">Hapus Log Aktivitas</h5>
                        <p class="text-muted mb-0">Hapus semua catatan aktivitas sistem</p>
                        <small class="text-danger">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Halaman ini hanya dapat diakses oleh Superadmin
                        </small>
                    </div>
                    <div class="btn-group-responsive">
                        <a href="manage_settings.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Kembali ke Pengaturan
                        </a>
                    </div>
                </div>
            </div>

            <?php if(isset($_SESSION['message'])): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <?php echo $_SESSION['message']; ?>
                    <?php unset($_SESSION['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <?php echo $_SESSION['error_message']; ?>
                    <?php unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Log Preview -->
            <div class="log-preview mb-4">
                <h5><i class="fas fa-history me-2"></i> Preview Log Aktivitas</h5>
                <p class="text-muted mb-3">
                    Total log yang akan dihapus: <strong><?php echo $total_logs; ?> entri</strong>
                </p>
                
                <?php if($total_logs > 0): ?>
                    <div class="mb-3">
                        <h6>5 Log Terbaru:</h6>
                        <?php foreach($recent_logs as $log): ?>
                        <div class="log-item">
                            <div class="d-flex justify-content-between flex-wrap">
                                <div>
                                    <strong><?php echo htmlspecialchars($log['action']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($log['username']); ?>
                                        <span class="badge <?php echo $log['role'] == 'superadmin' ? 'badge-superadmin' : 'badge-admin'; ?> ms-1">
                                            <?php echo strtoupper($log['role']); ?>
                                        </span>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?>
                                    </small>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-network-wired me-1"></i>
                                        <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Tidak ada log aktivitas yang tersimpan.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Peringatan Tinggi!</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-circle me-2"></i> PERINGATAN TINGGI!</h6>
                        <p class="mb-3">Tindakan ini akan menghapus <strong>SELURUH LOG AKTIVITAS</strong> dari sistem.</p>
                        <ul class="mb-3">
                            <li>Semua catatan aktivitas admin akan hilang permanen</li>
                            <li>Tidak dapat dikembalikan (irreversible)</li>
                            <li>Total data yang akan dihapus: <strong><?php echo $total_logs; ?> entri log</strong></li>
                            <li>Hanya superadmin yang dapat melakukan aksi ini</li>
                        </ul>
                        <p class="mb-0 fw-bold">Log aktivitas penting untuk audit dan keamanan sistem.</p>
                    </div>
                    
                    <form method="POST">
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label fw-bold">
                                <i class="fas fa-key me-2"></i>Konfirmasi Password Superadmin
                            </label>
                            <input type="password" class="form-control form-control-lg" id="confirm_password" 
                                   name="confirm_password" required 
                                   placeholder="Masukkan password superadmin Anda untuk konfirmasi">
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Masukkan password akun superadmin Anda untuk mengonfirmasi penghapusan semua log
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between flex-wrap gap-3">
                            <a href="manage_settings.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i> Kembali
                            </a>
                            <button type="submit" name="clear_logs" class="btn btn-danger btn-lg" 
                                    onclick="return confirmDelete()">
                                <i class="fas fa-trash-alt me-2"></i> Ya, Hapus Semua Log
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete() {
            const password = document.getElementById('confirm_password').value;
            if (!password) {
                alert('Silakan masukkan password untuk konfirmasi!');
                document.getElementById('confirm_password').focus();
                return false;
            }
            
            return confirm('APAKAH ANDA YAKIN?\n\nTindakan ini akan menghapus SEMUA LOG AKTIVITAS (' + <?php echo $total_logs; ?> + ' entri).\nTindakan ini TIDAK DAPAT DIBATALKAN!');
        }
    </script>
</body>
</html>