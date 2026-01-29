<?php
// Cek status session sebelum memulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Jika tidak ada session login → paksa login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Koneksi database (diperlukan untuk verifikasi user)
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $_SESSION['error'] = "Koneksi database gagal.";
    header("Location: login.php");
    exit();
}

// Ambil user dari database
$query = "SELECT id, role, is_active FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Jika user tidak ditemukan atau dihapus oleh superadmin
if (!$user) {
    $_SESSION['error'] = "Akun telah dihapus. Silakan login kembali.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// Jika user di-nonaktifkan
if (!$user['is_active']) {
    $_SESSION['error'] = "Akun Anda dinonaktifkan.";
    session_destroy();
    header("Location: login.php");
    exit();
}
?>
