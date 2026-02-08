<?php
// =======================================================
// CEK SESSION & LOGIN DENGAN PENCEGAHAN BYPASS
// =======================================================

require_once __DIR__ . '/../config/helpers.php';

// =======================================================
// SESSION TIMEOUT 1 JAM (3600 detik) - DIPINDAHKAN KE ATAS
// =======================================================

// Set pengaturan session HANYA jika session belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie lifetime ke 1 jam
    ini_set('session.gc_maxlifetime', 3600);
    session_set_cookie_params(3600);
}

// Panggil fungsi validasi session (setelah pengaturan)
validateSession();

// =======================================================
// CEK SESSION CREATED
// =======================================================

// Jika session baru dibuat, set waktu awal
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} 

// Cek apakah session sudah lebih dari 1 jam
else if (time() - $_SESSION['CREATED'] > 3600) {
    // Session sudah expired
    $username = $_SESSION['username'] ?? 'User';
    
    // Hapus semua data session
    session_unset();
    session_destroy();
    
    // Mulai session baru untuk pesan
    session_start();
    $_SESSION['session_expired'] = true;
    $_SESSION['expired_username'] = $username;
    
    // Redirect ke halaman login admin (bukan root index.php)
    header("Location: login.php?expired=1");
    exit();
}

// Cek apakah ada waktu aktivitas terakhir
if (isset($_SESSION['LAST_ACTIVITY'])) {
    // Hitung waktu sejak aktivitas terakhir
    $seconds_inactive = time() - $_SESSION['LAST_ACTIVITY'];
    
    // Jika tidak aktif selama lebih dari 1 jam (3600 detik)
    if ($seconds_inactive > 3600) {
        // Simpan informasi sebelum menghapus session
        $username = $_SESSION['username'] ?? 'User';
        
        // Hapus semua data session
        session_unset();
        session_destroy();
        
        // Mulai session baru untuk pesan
        session_start();
        $_SESSION['session_expired'] = true;
        $_SESSION['expired_username'] = $username;
        
        // Redirect ke login.php (admin folder) BUKAN ../index.php
        header("Location: login.php?expired=1");
        exit();
    }
}

// Update waktu aktivitas terakhir ke waktu sekarang
$_SESSION['LAST_ACTIVITY'] = time();

// =======================================================
// CEGAH AKSES LANGSUNG TANPA LOGIN
// =======================================================
preventDirectAccess();

// Jika tidak ada session login → paksa login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// =======================================================
// KONEKSI DATABASE
// =======================================================
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $_SESSION['error'] = "Koneksi database gagal.";
    header("Location: login.php");
    exit();
}

// =======================================================
// AMBIL DATA USER LOGIN DAN CEK STATUS
// =======================================================
$query = "SELECT id, username, role, is_active, locked_until FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Jika user tidak ditemukan atau dihapus
if (!$user) {
    $_SESSION['error'] = "Akun telah dihapus. Silakan login kembali.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// Jika user dinonaktifkan
if (!$user['is_active']) {
    $_SESSION['error'] = "Akun Anda dinonaktifkan.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// Jika akun terkunci
$lockout_result = checkAccountLockout($db, $user);
if ($lockout_result !== false) {
    $_SESSION['error'] = "Akun Anda terkunci. Silakan hubungi superadmin.";
    session_destroy();
    header("Location: login.php");
    exit();
}

// =======================================================
// FUNGSI CEK AKUN AKTIF TERAKHIR
// =======================================================

function isLastActiveAccount($db, $user_id) {
    try {
        // Hitung jumlah akun aktif
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE");
        $stmt->execute();
        $active_count = $stmt->fetchColumn();
        
        // Jika hanya ada 1 akun aktif, cek apakah user ini
        if ($active_count == 1) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_active = TRUE AND id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() == 1;
        }

        return false;

    } catch (Exception $e) {
        error_log("Error checking last active account: " . $e->getMessage());
        return false;
    }
}

// Simpan status ke session
$_SESSION['is_last_active'] = isLastActiveAccount($db, $_SESSION['user_id']);

// =======================================================
// GENERATE CSRF TOKEN JIKA BELUM ADA
// =======================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================
// CEK AKSES BERDASARKAN ROLE & HALAMAN
// =======================================================

$current_page = basename($_SERVER['PHP_SELF']);

// Halaman khusus superadmin
$superadmin_only_pages = [
    'clear_logs.php',
    'view_admin_activity.php',
    'clear_user_activity.php',
    'export_activity.php',
    'print_activity.php'
];

// Jika halaman khusus superadmin, tapi role bukan superadmin → tolak
if (in_array($current_page, $superadmin_only_pages) && $_SESSION['role'] !== 'superadmin') {
    $_SESSION['error_message'] = "Akses ditolak. Hanya superadmin yang dapat mengakses halaman ini.";
    header("Location: dashboard.php");
    exit();
}

// =======================================================
// FUNGSI UNTUK MENAMPILKAN WAKTU SESSION YANG TERSISA
// =======================================================
function getRemainingSessionTime() {
    if (!isset($_SESSION['LAST_ACTIVITY'])) {
        return 3600; // 1 jam default
    }
    
    $seconds_inactive = time() - $_SESSION['LAST_ACTIVITY'];
    $remaining = 3600 - $seconds_inactive;
    
    return max(0, $remaining); // Tidak boleh negatif
}

// Simpan waktu session tersisa ke session (opsional)
$_SESSION['session_remaining'] = getRemainingSessionTime();

// =======================================================
// UPDATE WAKTU SESSION CREATED SETIAP KALI DIAKSES
// =======================================================
// Reset waktu session jika sudah 30 menit berlalu dari pembuatan
if (isset($_SESSION['CREATED']) && (time() - $_SESSION['CREATED'] > 1800)) {
    // Regenerate session ID setiap 30 menit untuk keamanan
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

// =======================================================
// CEK JIKA ADA PARAMETER expired DI URL
// =======================================================
if (isset($_GET['expired']) && $_GET['expired'] == 1) {
    // Hapus parameter dari URL
    echo '<script>
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.pathname);
        }
    </script>';
}
?>