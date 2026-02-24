<?php
// File: admin/index.php
// Fungsi: Mengarahkan ke halaman login untuk admin

session_start();

// Cek apakah pengguna sudah login
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Jika sudah login, redirect ke dashboard berdasarkan role
    switch ($_SESSION['role']) {
        case 'superadmin':
        case 'admin':
            header('Location: dashboard.php');
            break;
        default:
            // Untuk role lain, redirect ke login
            header('Location: login.php');
            break;
    }
} else {
    // Jika belum login, redirect ke halaman login
    header('Location: login.php');
}

exit();
?>