<?php
session_start();
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Cek apakah superadmin sudah terdaftar
$query = "SELECT setting_value FROM settings WHERE setting_key = 'superadmin_registered'";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if($result && $result['setting_value'] == '1') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if(empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'Semua field harus diisi';
    } elseif($password !== $confirm_password) {
        $error = 'Password tidak cocok';
    } elseif(strlen($password) < 6) {
        $error = 'Password minimal 6 karakter';
    } else {
        try {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert superadmin
            $query = "INSERT INTO users (username, password, email, role) 
                     VALUES (:username, :password, :email, 'superadmin')";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            // Update setting bahwa superadmin sudah terdaftar
            $updateQuery = "UPDATE settings SET setting_value = '1' WHERE setting_key = 'superadmin_registered'";
            $db->exec($updateQuery);
            
            $success = 'Super Admin berhasil didaftarkan! Silakan login.';
            
        } catch(PDOException $e) {
            if($e->getCode() == 23000) { // Duplicate entry
                $error = 'Username sudah digunakan';
            } else {
                $error = 'Terjadi kesalahan: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Super Admin - Jadwal Kuliah</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            height: 100vh;
            display: flex;
            align-items: center;
        }
        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 500px;
            margin: 0 auto;
        }
        .register-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .register-body {
            padding: 40px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: #f093fb;
            box-shadow: 0 0 0 0.2rem rgba(240, 147, 251, 0.25);
        }
        .btn-register {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(240, 147, 251, 0.3);
        }
        .alert-warning-custom {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            border: none;
            color: #856404;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-card">
            <div class="register-header">
                <h2><i class="fas fa-user-shield"></i> Pendaftaran Super Admin</h2>
                <p class="mb-0">Hanya sekali akses!</p>
            </div>
            <div class="register-body">
                <?php if($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="btn btn-register w-100">Login Sekarang</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning-custom alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Perhatian!</strong> Halaman ini hanya bisa diakses sekali untuk mendaftarkan Super Admin pertama.
                    </div>
                    
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control" id="username" name="username" 
                                       required autofocus placeholder="Masukkan username">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" 
                                       placeholder="Masukkan email (opsional)">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" 
                                       required placeholder="Minimal 6 karakter">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Konfirmasi Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       required placeholder="Ulangi password">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-register w-100 mb-3">
                            <i class="fas fa-user-plus"></i> Daftarkan Super Admin
                        </button>
                        
                        <div class="text-center">
                            <a href="login.php" class="text-decoration-none">
                                <i class="fas fa-arrow-left"></i> Kembali ke Login
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>