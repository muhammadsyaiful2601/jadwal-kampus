<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../config/helpers.php';

// Mulai session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek autentikasi dan role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Cek apakah user adalah admin atau superadmin
if (!in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    $_SESSION['error'] = "Akses ditolak. Halaman ini hanya untuk admin.";
    header("Location: login.php");
    exit();
}

$database = new \Database();
$db = $database->getConnection();

if (!$db) {
    die("Koneksi database gagal. Periksa konfigurasi database.");
}

// Ambil password hash untuk verifikasi
$query = "SELECT password FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "Akun admin tidak ditemukan. Harap login ulang.";
    header("Location: ../admin/login.php");
    exit();
}

// Simpan password hash untuk verifikasi nanti
$_SESSION['password_hash'] = $user['password'];

// Set current_page untuk sidebar
$current_page = 'manage_schedule.php';

// =======================================================
// AMBIL DATA SEMESTER DARI semester_settings
// =======================================================

// Ambil semester aktif dari semester_settings
$active_semester = getActiveSemester($db);
$tahun_akademik_aktif = $active_semester['tahun_akademik'];
$semester_aktif = $active_semester['semester'];

// Ambil semua tahun akademik dari semester_settings
$tahun_list = getAllTahunAkademik($db);

// Tambahkan tahun akademik aktif jika belum ada dalam list
if (!in_array($tahun_akademik_aktif, $tahun_list)) {
    array_unshift($tahun_list, $tahun_akademik_aktif);
}

// Ambil filter dari URL (jika ada)
$filter_tahun = $_GET['filter_tahun'] ?? $tahun_akademik_aktif;
$filter_semester = $_GET['filter_semester'] ?? $semester_aktif;

// Ambil semua jadwal dengan filter
$query_schedules = "SELECT * FROM schedules WHERE 1=1";
$params = [];

if ($filter_tahun != 'all') {
    $query_schedules .= " AND tahun_akademik = ?";
    $params[] = $filter_tahun;
}

if ($filter_semester != 'all') {
    $query_schedules .= " AND semester = ?";
    $params[] = $filter_semester;
}

$query_schedules .= " ORDER BY tahun_akademik DESC, semester, kelas, hari, jam_ke";
$stmt_schedules = $db->prepare($query_schedules);
$stmt_schedules->execute($params);
$schedules = $stmt_schedules->fetchAll(PDO::FETCH_ASSOC);

// =======================================================
// FUNGSI HELPER UNTUK MAPPING JAM KE -> WAKTU
// =======================================================
function getTimeSlotByJamKe($jam_ke)
{
    // Mapping berdasarkan PDF "Jadwal Genap Rev3"
    // Format: jam_ke => [mulai, selesai]
    $mapping = [
        1  => ['07:30', '08:20'],
        2  => ['08:20', '09:10'],
        3  => ['09:10', '10:00'],
        4  => ['10:00', '10:50'],
        5  => ['10:50', '11:40'],
        6  => ['11:40', '12:30'],
        // istirahat 12:30 - 13:10
        7  => ['13:10', '14:00'],
        8  => ['14:00', '14:50'],
        9  => ['14:50', '15:40'],
        10 => ['15:40', '16:30'],
    ];

    return isset($mapping[$jam_ke]) ? $mapping[$jam_ke] : null;
}

// ========== PROSES TAMBAH MASSAL (MULTI-SLOT) ==========
if (isset($_POST['add_bulk_schedule'])) {
    $errors = [];

    // Validasi input dasar (kelas, hari, semester, tahun_akademik)
    $required_common = ['kelas', 'hari', 'semester', 'tahun_akademik'];
    foreach ($required_common as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " harus diisi.";
        }
    }

    // Validasi groups
    if (empty($_POST['groups']) || !is_array($_POST['groups'])) {
        $errors[] = "Minimal satu slot mata kuliah harus diisi.";
    } else {
        $groups = $_POST['groups'];
        $group_count = 0;
        foreach ($groups as $idx => $group) {
            if (
                empty($group['mata_kuliah']) ||
                empty($group['dosen']) ||
                empty($group['ruang']) ||
                empty($group['jam_ke_list']) || !is_array($group['jam_ke_list'])
            ) {
                $errors[] = "Slot #" . ($idx + 1) . ": Mata kuliah, dosen, ruang, dan minimal satu jam harus diisi.";
                continue;
            }
            $group_count++;
        }
        if ($group_count == 0) {
            $errors[] = "Tidak ada slot yang valid untuk disimpan.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        header('Location: manage_schedule.php');
        exit();
    }

    $kelas          = $_POST['kelas'];
    $hari           = $_POST['hari'];
    $semester       = $_POST['semester'];
    $tahun_akademik = $_POST['tahun_akademik'];

    $entries = [];
    $conflict_messages = [];
    $used_jam_ke_in_day = []; // Untuk cek duplikasi jam antar group

    foreach ($groups as $group_idx => $group) {
        $mata_kuliah = trim($group['mata_kuliah']);
        $dosen       = trim($group['dosen']);
        $ruang       = trim($group['ruang']);
        $jam_ke_list = array_map('intval', $group['jam_ke_list']);
        sort($jam_ke_list);

        // Cek duplikasi jam dengan group sebelumnya dalam request yang sama
        foreach ($jam_ke_list as $jam_ke) {
            if (in_array($jam_ke, $used_jam_ke_in_day)) {
                $conflict_messages[] = "Slot '" . $mata_kuliah . "': Jam ke-$jam_ke sudah digunakan di slot lain pada hari yang sama.";
            } else {
                $used_jam_ke_in_day[] = $jam_ke;
            }
        }
    }

    // Jika ada konflik internal, hentikan
    if (!empty($conflict_messages)) {
        $_SESSION['error'] = "Terdapat duplikasi jam dalam satu hari:<br>" . implode("<br>", $conflict_messages);
        header('Location: manage_schedule.php');
        exit();
    }

    // Proses setiap group untuk membuat entries dan cek bentrok dengan database
    foreach ($groups as $group) {
        $mata_kuliah = trim($group['mata_kuliah']);
        $dosen       = trim($group['dosen']);
        $ruang       = trim($group['ruang']);
        $jam_ke_list = array_map('intval', $group['jam_ke_list']);
        sort($jam_ke_list);

        foreach ($jam_ke_list as $jam_ke) {
            $slot = getTimeSlotByJamKe($jam_ke);
            if (!$slot) {
                $errors[] = "Jam ke-$jam_ke tidak valid.";
                continue;
            }
            list($mulai, $selesai) = $slot;
            $waktu = "$mulai - $selesai";

            // Cek bentrok dengan database (exclude_id = null)
            $conflicts = checkScheduleConflict(
                $db,
                $kelas,
                $hari,
                $mulai,
                $selesai,
                $semester,
                $tahun_akademik,
                $dosen,
                $ruang
            );

            if (!empty($conflicts)) {
                $conflict_messages[] = "Mata kuliah '$mata_kuliah' jam ke-$jam_ke ($waktu): " . implode(', ', $conflicts);
            } else {
                $entries[] = [
                    'kelas'          => $kelas,
                    'hari'           => $hari,
                    'jam_ke'         => $jam_ke,
                    'waktu'          => $waktu,
                    'mata_kuliah'    => $mata_kuliah,
                    'dosen'          => $dosen,
                    'ruang'          => $ruang,
                    'semester'       => $semester,
                    'tahun_akademik' => $tahun_akademik
                ];
            }
        }
    }

    if (!empty($conflict_messages)) {
        $_SESSION['error'] = "Terdapat bentrok jadwal:<br>" . implode("<br>", $conflict_messages);
        header('Location: manage_schedule.php');
        exit();
    }

    if (empty($entries)) {
        $_SESSION['error'] = "Tidak ada data valid untuk disimpan.";
        header('Location: manage_schedule.php');
        exit();
    }

    // Lakukan insert dalam transaksi
    try {
        $db->beginTransaction();

        $query = "INSERT INTO schedules 
                  (kelas, hari, jam_ke, waktu, mata_kuliah, dosen, ruang, semester, tahun_akademik) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);

        foreach ($entries as $entry) {
            $stmt->execute([
                $entry['kelas'],
                $entry['hari'],
                $entry['jam_ke'],
                $entry['waktu'],
                $entry['mata_kuliah'],
                $entry['dosen'],
                $entry['ruang'],
                $entry['semester'],
                $entry['tahun_akademik']
            ]);
        }

        $db->commit();

        // Log activity
        logActivity(
            $db,
            $_SESSION['user_id'],
            'Tambah Massal Jadwal (Multi Slot)',
            "Kelas: $kelas, Hari: $hari, " . count($entries) . " slot dari " . count($groups) . " mata kuliah"
        );

        $_SESSION['message'] = "Berhasil menambahkan " . count($entries) . " jadwal sekaligus.";
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Bulk insert error: " . $e->getMessage());
        $_SESSION['error'] = "Gagal menyimpan jadwal massal. Silakan coba lagi.";
    }

    header('Location: manage_schedule.php');
    exit();
}

// Tambah jadwal (single)
if (isset($_POST['add_schedule'])) {
    // Validasi input
    $errors = [];

    if (empty($_POST['kelas'])) $errors[] = "Kelas harus diisi";
    if (empty($_POST['hari'])) $errors[] = "Hari harus dipilih";
    if (empty($_POST['jam_ke']) || $_POST['jam_ke'] < 1 || $_POST['jam_ke'] > 10) $errors[] = "Jam ke harus 1-10";
    if (empty($_POST['waktu_mulai'])) $errors[] = "Waktu mulai harus diisi";
    if (empty($_POST['waktu_selesai'])) $errors[] = "Waktu selesai harus diisi";
    if (empty($_POST['mata_kuliah'])) $errors[] = "Mata kuliah harus diisi";
    if (empty($_POST['dosen'])) $errors[] = "Dosen harus diisi";
    if (empty($_POST['ruang'])) $errors[] = "Ruang harus dipilih";
    if (empty($_POST['semester'])) $errors[] = "Semester harus dipilih";
    if (empty($_POST['tahun_akademik'])) $errors[] = "Tahun akademik harus dipilih";

    // Validasi waktu
    $time_error = validateScheduleTime($_POST['waktu_mulai'], $_POST['waktu_selesai']);
    if ($time_error) $errors[] = $time_error;

    if (count($errors) > 0) {
        $_SESSION['error'] = implode("<br>", $errors);
        header('Location: manage_schedule.php');
        exit();
    }

    // Gabungkan waktu mulai dan selesai
    $waktu = $_POST['waktu_mulai'] . " - " . $_POST['waktu_selesai'];

    // Cek bentrok jadwal
    $conflicts = checkScheduleConflict(
        $db,
        $_POST['kelas'],
        $_POST['hari'],
        $_POST['waktu_mulai'],
        $_POST['waktu_selesai'],
        $_POST['semester'],
        $_POST['tahun_akademik'],
        $_POST['dosen'],
        $_POST['ruang']
    );

    if (!empty($conflicts)) {
        $_SESSION['error'] = implode("<br>", $conflicts);
        header('Location: manage_schedule.php');
        exit();
    }

    // Insert jadwal
    $query = "INSERT INTO schedules (kelas, hari, jam_ke, waktu, mata_kuliah, dosen, ruang, semester, tahun_akademik) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $success = $stmt->execute([
        $_POST['kelas'],
        $_POST['hari'],
        $_POST['jam_ke'],
        $waktu,
        $_POST['mata_kuliah'],
        $_POST['dosen'],
        $_POST['ruang'],
        $_POST['semester'],
        $_POST['tahun_akademik']
    ]);

    if ($success) {
        logActivity(
            $db,
            $_SESSION['user_id'],
            'Tambah Jadwal',
            "Kelas: {$_POST['kelas']}, Matkul: {$_POST['mata_kuliah']}, Hari: {$_POST['hari']}"
        );
        $_SESSION['message'] = "Jadwal berhasil ditambahkan!";
    } else {
        $_SESSION['error'] = "Gagal menambahkan jadwal.";
    }

    header('Location: manage_schedule.php');
    exit();
}

// Edit jadwal
if (isset($_POST['edit_schedule'])) {
    // Validasi input
    $errors = [];

    if (empty($_POST['id'])) $errors[] = "ID jadwal tidak valid";
    if (empty($_POST['kelas'])) $errors[] = "Kelas harus diisi";
    if (empty($_POST['hari'])) $errors[] = "Hari harus dipilih";
    if (empty($_POST['jam_ke']) || $_POST['jam_ke'] < 1 || $_POST['jam_ke'] > 10) $errors[] = "Jam ke harus 1-10";
    if (empty($_POST['waktu_mulai'])) $errors[] = "Waktu mulai harus diisi";
    if (empty($_POST['waktu_selesai'])) $errors[] = "Waktu selesai harus diisi";
    if (empty($_POST['mata_kuliah'])) $errors[] = "Mata kuliah harus diisi";
    if (empty($_POST['dosen'])) $errors[] = "Dosen harus diisi";
    if (empty($_POST['ruang'])) $errors[] = "Ruang harus dipilih";
    if (empty($_POST['semester'])) $errors[] = "Semester harus dipilih";
    if (empty($_POST['tahun_akademik'])) $errors[] = "Tahun akademik harus dipilih";

    // Validasi waktu
    $time_error = validateScheduleTime($_POST['waktu_mulai'], $_POST['waktu_selesai']);
    if ($time_error) $errors[] = $time_error;

    if (count($errors) > 0) {
        $_SESSION['error'] = implode("<br>", $errors);
        header('Location: manage_schedule.php');
        exit();
    }

    // Gabungkan waktu mulai dan selesai
    $waktu = $_POST['waktu_mulai'] . " - " . $_POST['waktu_selesai'];

    // Cek bentrok jadwal (kecuali dengan diri sendiri)
    $conflicts = checkScheduleConflict(
        $db,
        $_POST['kelas'],
        $_POST['hari'],
        $_POST['waktu_mulai'],
        $_POST['waktu_selesai'],
        $_POST['semester'],
        $_POST['tahun_akademik'],
        $_POST['dosen'],
        $_POST['ruang'],
        $_POST['id']
    );

    if (!empty($conflicts)) {
        $_SESSION['error'] = implode("<br>", $conflicts);
        header('Location: manage_schedule.php');
        exit();
    }

    // Update jadwal
    $query = "UPDATE schedules SET 
              kelas = ?, hari = ?, jam_ke = ?, waktu = ?, mata_kuliah = ?, 
              dosen = ?, ruang = ?, semester = ?, tahun_akademik = ? 
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $success = $stmt->execute([
        $_POST['kelas'],
        $_POST['hari'],
        $_POST['jam_ke'],
        $waktu,
        $_POST['mata_kuliah'],
        $_POST['dosen'],
        $_POST['ruang'],
        $_POST['semester'],
        $_POST['tahun_akademik'],
        $_POST['id']
    ]);

    if ($success) {
        logActivity($db, $_SESSION['user_id'], 'Edit Jadwal', "ID: {$_POST['id']}");
        $_SESSION['message'] = "Jadwal berhasil diperbarui!";
    } else {
        $_SESSION['error'] = "Gagal memperbarui jadwal.";
    }

    header('Location: manage_schedule.php');
    exit();
}

// Hapus jadwal
if (isset($_GET['delete'])) {
    $query = "DELETE FROM schedules WHERE id = ?";
    $stmt = $db->prepare($query);
    $success = $stmt->execute([$_GET['delete']]);

    if ($success) {
        logActivity($db, $_SESSION['user_id'], 'Hapus Jadwal', "ID: {$_GET['delete']}");
        $_SESSION['message'] = "Jadwal berhasil dihapus!";
    } else {
        $_SESSION['error'] = "Gagal menghapus jadwal.";
    }

    header('Location: manage_schedule.php');
    exit();
}

// Hapus semua jadwal
if (isset($_POST['delete_all_schedules'])) {
    // Hitung jumlah data sebelum dihapus
    $total_data = count($schedules);

    // Verifikasi password
    if (password_verify($_POST['confirm_password'], $_SESSION['password_hash'])) {
        $query = "DELETE FROM schedules";
        $stmt = $db->prepare($query);
        $success = $stmt->execute();

        if ($success) {
            logActivity($db, $_SESSION['user_id'], 'Hapus Semua Jadwal', "Semua jadwal dihapus, total: $total_data data");
            $_SESSION['message'] = "Semua jadwal ($total_data data) berhasil dihapus!";
        } else {
            $_SESSION['error'] = "Gagal menghapus semua jadwal.";
        }

        header('Location: manage_schedule.php');
        exit();
    } else {
        $_SESSION['error'] = "Password salah! Hapus semua data dibatalkan.";
        header('Location: manage_schedule.php');
        exit();
    }
}

// Ambil semua ruangan untuk dropdown
$query = "SELECT nama_ruang FROM rooms ORDER BY nama_ruang";
$stmt = $db->prepare($query);
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Ambil semua kelas yang ada
$query_kelas_all = "SELECT DISTINCT kelas FROM schedules ORDER BY kelas";
$stmt_kelas_all = $db->prepare($query_kelas_all);
$stmt_kelas_all->execute();
$kelas_list_all = $stmt_kelas_all->fetchAll(PDO::FETCH_COLUMN);

// Siapkan mapping waktu untuk JavaScript
$time_slots_js = [];
for ($i = 1; $i <= 10; $i++) {
    $slot = getTimeSlotByJamKe($i);
    $time_slots_js[$i] = $slot ? implode(' - ', $slot) : 'Tidak tersedia';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>Kelola Jadwal - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
    <style>
        /* Reset CSS untuk mobile */
        * {
            box-sizing: border-box;
            max-width: 100%;
        }

        html,
        body {
            width: 100%;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }

        .sidebar {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
            z-index: 1050;
            overflow-y: auto;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background-color: #f8f9fa;
            width: calc(100% - 250px);
        }

        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 15px 0;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 5px 10px;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
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

        /* ========== PERBAIKAN UNTUK MOBILE ========== */
        @media (max-width: 768px) {

            /* Main content mobile */
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px 10px;
            }

            /* Navbar fixed untuk mobile */
            .mobile-nav-fixed {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 1040;
                background: white;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .content-with-fixed-nav {
                padding-top: 70px;
            }

            /* Perbaikan untuk semua elemen agar tidak keluar layar */
            .container-fluid,
            .container {
                padding-left: 10px !important;
                padding-right: 10px !important;
                max-width: 100% !important;
            }

            .row {
                margin-left: -5px;
                margin-right: -5px;
            }

            .col,
            .col-1,
            .col-2,
            .col-3,
            .col-4,
            .col-5,
            .col-6,
            .col-7,
            .col-8,
            .col-9,
            .col-10,
            .col-11,
            .col-12,
            .col-md,
            .col-md-1,
            .col-md-2,
            .col-md-3,
            .col-md-4,
            .col-md-5,
            .col-md-6,
            .col-md-7,
            .col-md-8,
            .col-md-9,
            .col-md-10,
            .col-md-11,
            .col-md-12 {
                padding-left: 5px;
                padding-right: 5px;
            }

            /* Page header mobile */
            .page-header {
                padding: 15px;
                margin: 10px 0 15px 0;
                border-radius: 8px;
            }

            /* Filter section mobile */
            .filter-section {
                padding: 15px 10px;
                margin: 0 0 15px 0;
                border-radius: 8px;
            }

            /* Table container mobile */
            .table-container {
                padding: 10px;
                border-radius: 8px;
                margin: 0;
            }

            /* Table responsive */
            .table-responsive {
                border-radius: 8px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            /* Stat cards mobile */
            .stat-card {
                padding: 12px 8px;
                border-radius: 8px;
                margin-bottom: 8px;
                text-align: center;
            }

            .stat-card i {
                font-size: 1.5rem;
                margin-bottom: 5px;
            }

            .stat-card .number {
                font-size: 1.2rem;
            }

            .stat-card .label {
                font-size: 0.8rem;
            }

            /* Tombol mobile */
            .btn {
                padding: 8px 12px;
                font-size: 14px;
                white-space: nowrap;
            }

            .btn-sm {
                padding: 4px 8px;
                font-size: 12px;
            }

            /* Form controls mobile */
            .form-control,
            .form-select {
                font-size: 14px;
                padding: 8px 12px;
            }

            /* Tabel mobile */
            table {
                font-size: 12px;
            }

            .table td,
            .table th {
                padding: 8px 5px;
            }

            /* Badge mobile */
            .badge {
                font-size: 11px;
                padding: 4px 8px;
            }

            /* Modal mobile */
            .modal-dialog {
                margin: 10px;
                max-width: calc(100vw - 20px);
            }

            .modal-body {
                max-height: 65vh;
                overflow-y: auto;
                padding: 15px;
            }

            /* DataTables mobile */
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                padding: 0 5px;
                font-size: 12px;
            }

            /* Alert mobile */
            .alert {
                padding: 10px 15px;
                font-size: 14px;
                margin: 10px 0;
            }

            /* Toggler button styling */
            .navbar-toggler.btn-light {
                border: 1px solid #ddd;
                padding: 6px 10px;
            }

            /* Atur konten untuk mobile dengan navbar fixed */
            .content-with-fixed-nav {
                padding-top: 70px;
            }

            /* Hilangkan overflow horizontal di body */
            body {
                overflow-x: hidden;
                position: relative;
            }

            /* Perbaikan time-row di mobile */
            .time-row {
                flex-direction: column;
                gap: 10px;
            }

            .time-row>div {
                width: 100%;
            }
        }

        /* Untuk layar sangat kecil (di bawah 576px) */
        @media (max-width: 576px) {
            .main-content {
                padding: 10px 8px;
            }

            .page-header h5 {
                font-size: 1.1rem;
            }

            .page-header p {
                font-size: 0.9rem;
            }

            .filter-section h6 {
                font-size: 1rem;
            }

            /* Grid system untuk mobile kecil */
            .row.g-2 {
                margin-left: -4px;
                margin-right: -4px;
            }

            .row.g-2>[class*="col-"] {
                padding-left: 4px;
                padding-right: 4px;
            }

            /* Tombol aksi di tabel */
            .btn-group-mobile {
                display: flex;
                gap: 4px;
            }

            .btn-group-mobile .btn {
                padding: 3px 6px;
                min-width: 32px;
            }

            /* Modal untuk mobile kecil */
            .modal-header,
            .modal-footer {
                padding: 12px 15px;
            }

            .modal-title {
                font-size: 1.1rem;
            }
        }

        /* ========== STYLE UMUM (Desktop & Mobile) ========== */
        .content-wrapper {
            padding-top: 20px;
        }

        .page-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: none;
            transition: all 0.3s;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }

        .modal-header.bg-danger {
            background: linear-gradient(135deg, #dc3545, #c82333) !important;
        }

        .btn-close-white {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            margin: 10px 0;
        }

        .alert {
            border: none;
            border-radius: 10px;
        }

        .badge-count {
            font-size: 0.8em;
            padding: 3px 8px;
            border-radius: 10px;
        }

        .time-slot {
            font-size: 0.85em;
            color: #666;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .time-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .time-row>div {
            flex: 1;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .stat-card .number {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .stat-card .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filter-section h6 {
            color: #2c3e50;
            font-weight: 600;
        }

        .active-filter {
            border-left: 4px solid #4a6491;
            background-color: #f8f9fa;
        }

        /* DataTables responsive */
        .dataTables_scroll {
            width: 100% !important;
        }

        .dataTables_scrollBody {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }

        /* Perbaikan untuk modal di mobile */
        .modal {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .modal-backdrop {
            z-index: 1040;
        }

        /* Utility classes */
        .flex-fill {
            flex: 1 1 auto;
        }

        .gap-1 {
            gap: 0.25rem;
        }

        .gap-2 {
            gap: 0.5rem;
        }

        .gap-3 {
            gap: 1rem;
        }

        /* Style untuk input time */
        input[type="time"] {
            min-height: 38px;
            padding: 0.375rem 0.75rem;
        }

        @media (max-width: 768px) {
            input[type="time"] {
                font-size: 16px;
                /* Mencegah zoom otomatis di iOS */
                padding: 0.5rem 0.75rem;
            }
        }

        /* Style tambahan untuk slot group */
        .slot-group {
            border: 1px solid #dee2e6;
            border-radius: 10px;
        }

        .slot-group .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0 !important;
        }
    </style>
</head>

<body>
    <div class="d-flex">
        <!-- Sidebar Desktop -->
        <div class="sidebar d-none d-md-block">
            <div class="p-4">
                <h3 class="mb-4"><i class="fas fa-calendar-alt"></i> Admin Panel</h3>
                <div class="user-info mb-4">
                    <div class="d-flex align-items-center">
                        <div class="user-avatar me-3">
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($_SESSION['username']); ?></h6>
                            <small class="text-muted"><?php echo ucfirst($_SESSION['role']); ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <nav class="nav flex-column">
                <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_schedule.php' ? 'active' : ''; ?>" href="manage_schedule.php">
                    <i class="fas fa-calendar"></i> Kelola Jadwal
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_rooms.php' ? 'active' : ''; ?>" href="manage_rooms.php">
                    <i class="fas fa-door-open"></i> Kelola Ruangan
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_semester.php' ? 'active' : ''; ?>" href="manage_semester.php">
                    <i class="fas fa-calendar-alt"></i> Kelola Semester
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_settings.php' ? 'active' : ''; ?>" href="manage_settings.php">
                    <i class="fas fa-cog"></i> Pengaturan
                </a>
                <a class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>" href="manage_users.php">
                    <i class="fas fa-users"></i> Kelola Admin
                </a>
                <a class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                    <i class="fas fa-chart-bar"></i> Laporan
                </a>
                <div class="mt-4"></div>
                <a class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
                    <i class="fas fa-user"></i> Profile
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- NAVBAR MOBILE -->
            <nav class="navbar navbar-expand-lg navbar-custom d-md-none mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" data-bs-toggle="collapse"
                        data-bs-target="#mobileSidebar">
                        <i class="fas fa-bars"></i>
                    </button>

                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Kelola Jadwal</h4>
                    </div>

                    <div class="d-flex align-items-center">
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button"
                                data-bs-toggle="dropdown">
                                <i class="fas fa-user"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="profile.php">
                                        <i class="fas fa-user me-2"></i>Profile
                                    </a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item text-danger" href="logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                                    </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Mobile Sidebar Collapse -->
            <div class="collapse d-md-none mb-4" id="mobileSidebar">
                <div class="card">
                    <div class="card-body">
                        <nav class="nav flex-column">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                            <a class="nav-link" href="manage_schedule.php">
                                <i class="fas fa-calendar"></i> Kelola Jadwal
                            </a>
                            <a class="nav-link" href="manage_rooms.php">
                                <i class="fas fa-door-open"></i> Kelola Ruangan
                            </a>
                            <a class="nav-link" href="manage_semester.php">
                                <i class="fas fa-calendar-alt"></i> Kelola Semester
                            </a>
                            <a class="nav-link active" href="manage_settings.php">
                                <i class="fas fa-cog"></i> Pengaturan
                            </a>
                            <a class="nav-link" href="manage_users.php">
                                <i class="fas fa-users"></i> Kelola Admin
                            </a>
                            <a class="nav-link" href="reports.php">
                                <i class="fas fa-chart-bar"></i> Laporan
                            </a>
                            <hr>
                            <a class="nav-link" href="profile.php">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a class="nav-link" href="logout.php">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Content untuk Mobile -->
            <div class="content-wrapper d-md-none content-with-fixed-nav">
                <!-- Page Header untuk Mobile -->
                <div class="page-header">
                    <div>
                        <h5 class="mb-1">Daftar Jadwal Kuliah</h5>
                        <p class="text-muted mb-1">
                            Kelola jadwal kuliah untuk semua kelas
                            <span class="badge bg-primary badge-count"><?php echo count($schedules); ?> data</span>
                        </p>
                        <small class="text-muted d-block mb-2">
                            Semester Aktif: <strong><?php echo $semester_aktif; ?> - <?php echo $tahun_akademik_aktif; ?></strong>
                        </small>

                        <!-- Tombol untuk Mobile -->
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button class="btn btn-danger flex-fill" data-bs-toggle="modal" data-bs-target="#deleteAllModal" <?php echo count($schedules) == 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash-alt me-1"></i>Hapus Semua
                            </button>
                            <button class="btn btn-success flex-fill" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
                                <i class="fas fa-layer-group me-1"></i>Massal
                            </button>
                            <button class="btn btn-primary flex-fill" data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus me-1"></i>Tambah
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filter Section untuk Mobile -->
                <div class="filter-section">
                    <h6><i class="fas fa-filter me-2"></i>Filter Jadwal</h6>
                    <form method="GET" class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Tahun Akademik</label>
                            <select name="filter_tahun" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="all">Semua Tahun</option>
                                <?php foreach ($tahun_list as $tahun): ?>
                                    <option value="<?php echo htmlspecialchars($tahun); ?>"
                                        <?php echo $filter_tahun == $tahun ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tahun); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Semester</label>
                            <select name="filter_semester" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="all">Semua Semester</option>
                                <option value="GANJIL" <?php echo $filter_semester == 'GANJIL' ? 'selected' : ''; ?>>GANJIL</option>
                                <option value="GENAP" <?php echo $filter_semester == 'GENAP' ? 'selected' : ''; ?>>GENAP</option>
                            </select>
                        </div>
                        <div class="col-12 mt-2">
                            <a href="manage_schedule.php" class="btn btn-secondary btn-sm w-100">
                                <i class="fas fa-redo me-1"></i>Reset Filter
                            </a>
                        </div>
                    </form>
                    <?php if ($filter_tahun != 'all' || $filter_semester != 'all'): ?>
                        <div class="mt-2 alert alert-info py-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Filter Aktif:
                            <?php if ($filter_tahun != 'all'): ?>
                                <span class="badge bg-info me-1">Tahun: <?php echo $filter_tahun; ?></span>
                            <?php endif; ?>
                            <?php if ($filter_semester != 'all'): ?>
                                <span class="badge bg-info">Semester: <?php echo $filter_semester; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Statistics Cards untuk Mobile -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="stat-card text-center py-2">
                            <i class="fas fa-calendar-alt fa-lg"></i>
                            <div class="number"><?php echo count($schedules); ?></div>
                            <div class="label">Total Jadwal</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center py-2" style="background: linear-gradient(135deg, #4CAF50, #2E7D32);">
                            <i class="fas fa-users fa-lg"></i>
                            <div class="number"><?php echo count($kelas_list_all); ?></div>
                            <div class="label">Total Kelas</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center py-2" style="background: linear-gradient(135deg, #FF9800, #EF6C00);">
                            <i class="fas fa-door-open fa-lg"></i>
                            <div class="number"><?php echo count($rooms); ?></div>
                            <div class="label">Total Ruangan</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center py-2" style="background: linear-gradient(135deg, #9C27B0, #6A1B9A);">
                            <i class="fas fa-graduation-cap fa-lg"></i>
                            <div class="number"><?php echo count($tahun_list); ?></div>
                            <div class="label">Tahun Akademik</div>
                        </div>
                    </div>
                </div>

                <?php
                // Tampilkan pesan flash
                if (isset($_SESSION['message'])) {
                    echo "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            {$_SESSION['message']}
                            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                          </div>";
                    unset($_SESSION['message']);
                }
                if (isset($_SESSION['error'])) {
                    echo "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                            {$_SESSION['error']}
                            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                          </div>";
                    unset($_SESSION['error']);
                }
                ?>

                <!-- Data Table untuk Mobile -->
                <div class="table-container">
                    <?php if (count($schedules) == 0): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">Belum ada data jadwal</h6>
                            <p class="text-muted small">Mulai dengan menambahkan jadwal baru</p>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus me-1"></i>Tambah Jadwal
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="scheduleTableMobile">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Kelas</th>
                                        <th>Hari</th>
                                        <th>Jam</th>
                                        <th>Matkul</th>
                                        <th>Dosen</th>
                                        <th>Ruang</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; ?>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($schedule['kelas']); ?></span>
                                            </td>
                                            <td><small><?php echo substr($schedule['hari'], 0, 3); ?></small></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $schedule['jam_ke']; ?></span>
                                            </td>
                                            <td><small><?php echo substr(htmlspecialchars($schedule['mata_kuliah']), 0, 15); ?>...</small></td>
                                            <td><small><?php echo substr(htmlspecialchars($schedule['dosen']), 0, 10); ?>...</small></td>
                                            <td>
                                                <span class="badge bg-success"><?php echo htmlspecialchars($schedule['ruang']); ?></span>
                                            </td>
                                            <td>
                                                <div class="btn-group-mobile">
                                                    <button class="btn btn-sm btn-warning p-1" onclick="editSchedule(<?php echo htmlspecialchars(json_encode($schedule), ENT_QUOTES, 'UTF-8'); ?>)">
                                                        <i class="fas fa-edit fa-xs"></i>
                                                    </button>
                                                    <a href="?delete=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-danger p-1" onclick="return confirm('Hapus jadwal ini?')">
                                                        <i class="fas fa-trash fa-xs"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Content untuk Desktop -->
            <div class="content-wrapper d-none d-md-block">
                <!-- Navbar untuk Desktop -->
                <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                    <div class="container-fluid">
                        <div class="d-flex align-items-center">
                            <h4 class="mb-0">Kelola Jadwal Kuliah</h4>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="me-3"><?php echo date('d F Y'); ?></span>
                            <div class="dropdown">
                                <button class="btn btn-light dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown">
                                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="profile.php">
                                            <i class="fas fa-user me-2"></i>Profile
                                        </a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li><a class="dropdown-item text-danger" href="logout.php">
                                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                                        </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </nav>

                <!-- Page Header untuk Desktop -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">Daftar Jadwal Kuliah</h5>
                            <p class="text-muted mb-0">
                                Kelola jadwal kuliah untuk semua kelas
                                <span class="badge bg-primary badge-count"><?php echo count($schedules); ?> data</span>
                            </p>
                            <small class="text-muted">
                                Semester Aktif: <strong><?php echo $semester_aktif; ?> - <?php echo $tahun_akademik_aktif; ?></strong>
                            </small>
                        </div>
                        <div>
                            <!-- Tombol Hapus Semua -->
                            <button class="btn btn-danger me-2" data-bs-toggle="modal" data-bs-target="#deleteAllModal" <?php echo count($schedules) == 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash-alt me-2"></i>Hapus Semua
                            </button>
                            <!-- Tombol Tambah Massal -->
                            <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
                                <i class="fas fa-layer-group me-2"></i>Tambah Massal
                            </button>
                            <!-- Tombol Tambah Jadwal -->
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus me-2"></i>Tambah Jadwal
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filter Section untuk Desktop -->
                <div class="filter-section">
                    <h6><i class="fas fa-filter me-2"></i>Filter Jadwal</h6>
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Tahun Akademik</label>
                            <select name="filter_tahun" class="form-control" onchange="this.form.submit()">
                                <option value="all">Semua Tahun Akademik</option>
                                <?php foreach ($tahun_list as $tahun): ?>
                                    <option value="<?php echo htmlspecialchars($tahun); ?>"
                                        <?php echo $filter_tahun == $tahun ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tahun); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Semester</label>
                            <select name="filter_semester" class="form-control" onchange="this.form.submit()">
                                <option value="all">Semua Semester</option>
                                <option value="GANJIL" <?php echo $filter_semester == 'GANJIL' ? 'selected' : ''; ?>>GANJIL</option>
                                <option value="GENAP" <?php echo $filter_semester == 'GENAP' ? 'selected' : ''; ?>>GENAP</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <a href="manage_schedule.php" class="btn btn-secondary w-100">
                                <i class="fas fa-redo me-2"></i>Reset Filter
                            </a>
                        </div>
                    </form>
                    <?php if ($filter_tahun != 'all' || $filter_semester != 'all'): ?>
                        <div class="mt-3 alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Filter Aktif:
                            <?php if ($filter_tahun != 'all'): ?>
                                <span class="badge bg-info me-2">Tahun: <?php echo $filter_tahun; ?></span>
                            <?php endif; ?>
                            <?php if ($filter_semester != 'all'): ?>
                                <span class="badge bg-info">Semester: <?php echo $filter_semester; ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Statistics Cards untuk Desktop -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <i class="fas fa-calendar-alt"></i>
                            <div class="number"><?php echo count($schedules); ?></div>
                            <div class="label">Total Jadwal</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #4CAF50, #2E7D32);">
                            <i class="fas fa-users"></i>
                            <div class="number"><?php echo count($kelas_list_all); ?></div>
                            <div class="label">Total Kelas</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #FF9800, #EF6C00);">
                            <i class="fas fa-door-open"></i>
                            <div class="number"><?php echo count($rooms); ?></div>
                            <div class="label">Total Ruangan</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, #9C27B0, #6A1B9A);">
                            <i class="fas fa-graduation-cap"></i>
                            <div class="number"><?php echo count($tahun_list); ?></div>
                            <div class="label">Tahun Akademik</div>
                        </div>
                    </div>
                </div>

                <?php
                // Tampilkan pesan flash untuk desktop
                if (isset($_SESSION['message'])) {
                    echo "<div class='alert alert-success'>{$_SESSION['message']}</div>";
                }
                if (isset($_SESSION['error'])) {
                    echo "<div class='alert alert-danger'>{$_SESSION['error']}</div>";
                }
                ?>

                <!-- Data Table untuk Desktop -->
                <div class="table-container">
                    <?php if (count($schedules) == 0): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">Belum ada data jadwal</h5>
                            <p class="text-muted">Mulai dengan menambahkan jadwal baru</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus me-2"></i>Tambah Jadwal Pertama
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="scheduleTableDesktop">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Kelas</th>
                                        <th>Hari</th>
                                        <th>Jam Ke</th>
                                        <th>Waktu</th>
                                        <th>Mata Kuliah</th>
                                        <th>Dosen</th>
                                        <th>Ruang</th>
                                        <th>Semester</th>
                                        <th>Tahun</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; ?>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($schedule['kelas']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($schedule['hari']); ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $schedule['jam_ke']; ?></span>
                                            </td>
                                            <td>
                                                <span class="time-slot"><?php echo htmlspecialchars($schedule['waktu']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($schedule['mata_kuliah']); ?></td>
                                            <td><?php echo htmlspecialchars($schedule['dosen']); ?></td>
                                            <td>
                                                <span class="badge bg-success"><?php echo htmlspecialchars($schedule['ruang']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $schedule['semester'] == 'GANJIL' ? 'warning' : 'success'; ?>">
                                                    <?php echo $schedule['semester']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo $schedule['tahun_akademik']; ?></small>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" onclick="editSchedule(<?php echo htmlspecialchars(json_encode($schedule), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $schedule['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin hapus jadwal ini?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tambah (single) -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Jadwal Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Kelas</label>
                                <input type="text" name="kelas" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Hari</label>
                                <select name="hari" class="form-control" required>
                                    <option value="">Pilih Hari</option>
                                    <option value="SENIN">SENIN</option>
                                    <option value="SELASA">SELASA</option>
                                    <option value="RABU">RABU</option>
                                    <option value="KAMIS">KAMIS</option>
                                    <option value="JUMAT">JUMAT</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Jam Ke</label>
                                <input type="number" name="jam_ke" class="form-control" min="1" max="10" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Waktu</label>
                                <div class="time-row">
                                    <input type="time" name="waktu_mulai" class="form-control" required>
                                    <span>-</span>
                                    <input type="time" name="waktu_selesai" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Mata Kuliah</label>
                            <input type="text" name="mata_kuliah" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Dosen</label>
                            <input type="text" name="dosen" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Ruang</label>
                            <select name="ruang" class="form-control" required>
                                <option value="">Pilih Ruangan</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Semester</label>
                                <select name="semester" class="form-control" required>
                                    <option value="GANJIL" <?php echo $semester_aktif == 'GANJIL' ? 'selected' : ''; ?>>GANJIL</option>
                                    <option value="GENAP" <?php echo $semester_aktif == 'GENAP' ? 'selected' : ''; ?>>GENAP</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Tahun Akademik</label>
                                <select name="tahun_akademik" class="form-control" required>
                                    <?php foreach ($tahun_list as $tahun): ?>
                                        <option value="<?php echo htmlspecialchars($tahun); ?>" <?php echo $tahun == $tahun_akademik_aktif ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tahun); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_schedule" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="editForm">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Jadwal</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Kelas</label>
                                <input type="text" name="kelas" id="edit_kelas" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Hari</label>
                                <select name="hari" id="edit_hari" class="form-control" required>
                                    <option value="">Pilih Hari</option>
                                    <option value="SENIN">SENIN</option>
                                    <option value="SELASA">SELASA</option>
                                    <option value="RABU">RABU</option>
                                    <option value="KAMIS">KAMIS</option>
                                    <option value="JUMAT">JUMAT</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Jam Ke</label>
                                <input type="number" name="jam_ke" id="edit_jam_ke" class="form-control" min="1" max="10" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Waktu</label>
                                <div class="time-row">
                                    <input type="time" name="waktu_mulai" id="edit_waktu_mulai" class="form-control" required>
                                    <span>-</span>
                                    <input type="time" name="waktu_selesai" id="edit_waktu_selesai" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Mata Kuliah</label>
                            <input type="text" name="mata_kuliah" id="edit_mata_kuliah" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Dosen</label>
                            <input type="text" name="dosen" id="edit_dosen" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Ruang</label>
                            <select name="ruang" id="edit_ruang" class="form-control" required>
                                <option value="">Pilih Ruangan</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo htmlspecialchars($room); ?>"><?php echo htmlspecialchars($room); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Semester</label>
                                <select name="semester" id="edit_semester" class="form-control" required>
                                    <option value="GANJIL">GANJIL</option>
                                    <option value="GENAP">GENAP</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Tahun Akademik</label>
                                <select name="tahun_akademik" id="edit_tahun_akademik" class="form-control" required>
                                    <?php foreach ($tahun_list as $tahun): ?>
                                        <option value="<?php echo htmlspecialchars($tahun); ?>"><?php echo htmlspecialchars($tahun); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit_schedule" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Hapus Semua -->
    <div class="modal fade" id="deleteAllModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus Semua</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Anda akan menghapus <strong>semua data jadwal</strong> (<?php echo count($schedules); ?> data). Tindakan ini tidak dapat dibatalkan!</p>
                        <div class="mb-3">
                            <label>Masukkan password Anda untuk konfirmasi:</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete_all_schedules" class="btn btn-danger">
                            <i class="fas fa-trash-alt me-2"></i>Hapus Semua
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ==================== MODAL TAMBAH MASSAL (MULTI-SLOT) ==================== -->
    <div class="modal fade" id="bulkAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="bulkAddForm">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-layer-group me-2"></i>Tambah Jadwal Massal (Multi Slot)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Kelas <span class="text-danger">*</span></label>
                                    <input type="text" name="kelas" class="form-control" required placeholder="Contoh: 1A, 2B">
                                </div>
                                <div class="mb-3">
                                    <label>Hari <span class="text-danger">*</span></label>
                                    <select name="hari" class="form-control" required>
                                        <option value="">Pilih Hari</option>
                                        <option value="SENIN">SENIN</option>
                                        <option value="SELASA">SELASA</option>
                                        <option value="RABU">RABU</option>
                                        <option value="KAMIS">KAMIS</option>
                                        <option value="JUMAT">JUMAT</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label>Semester <span class="text-danger">*</span></label>
                                    <select name="semester" class="form-control" required>
                                        <option value="GANJIL" <?php echo $semester_aktif == 'GANJIL' ? 'selected' : ''; ?>>GANJIL</option>
                                        <option value="GENAP" <?php echo $semester_aktif == 'GENAP' ? 'selected' : ''; ?>>GENAP</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label>Tahun Akademik <span class="text-danger">*</span></label>
                                    <select name="tahun_akademik" class="form-control" required>
                                        <option value="">Pilih Tahun</option>
                                        <?php foreach ($tahun_list as $tahun): ?>
                                            <option value="<?php echo htmlspecialchars($tahun); ?>" <?php echo $tahun == $tahun_akademik_aktif ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tahun); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <h6 class="mb-3"><i class="fas fa-list-ul me-2"></i>Slot Mata Kuliah</h6>

                        <!-- Container untuk semua slot group -->
                        <div id="slotGroupsContainer">
                            <!-- Slot group pertama akan dirender oleh JavaScript -->
                        </div>

                        <!-- Tombol Tambah Slot -->
                        <div class="mt-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addSlotGroupBtn">
                                <i class="fas fa-plus me-1"></i>Tambah Slot Mata Kuliah
                            </button>
                            <small class="text-muted ms-2">Maksimal 5 slot per hari</small>
                        </div>

                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Petunjuk:</strong> Pilih jam untuk setiap mata kuliah. Jam yang sudah dipilih di slot lain akan dinonaktifkan.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_bulk_schedule" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Simpan Massal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Data dari PHP untuk JavaScript
        const timeSlots = <?php echo json_encode($time_slots_js); ?>;
        const roomsList = <?php echo json_encode($rooms); ?>;

        $(document).ready(function() {
            // Inisialisasi DataTables
            <?php if (count($schedules) > 0): ?>
                $('#scheduleTableDesktop').DataTable({
                    "language": {
                        "url": "https://cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                    },
                    "pageLength": 25,
                    "order": [
                        [0, 'asc']
                    ],
                    "columnDefs": [{
                        "orderable": false,
                        "targets": [10]
                    }],
                    "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                    "responsive": true,
                    "stateSave": true,
                    "scrollX": false
                });

                $('#scheduleTableMobile').DataTable({
                    "language": {
                        "url": "https://cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                    },
                    "pageLength": 10,
                    "order": [
                        [0, 'asc']
                    ],
                    "columnDefs": [{
                        "orderable": false,
                        "targets": [7]
                    }],
                    "responsive": true,
                    "scrollX": true,
                    "autoWidth": false,
                    "dom": '<"row"<"col-sm-12"f>>rt<"row"<"col-sm-12"ip>>',
                    "stateSave": true,
                    "pagingType": "simple_numbers"
                });
            <?php endif; ?>

            // ========== MULTI-SLOT BULK ADD (REVISED) ==========
            let slotGroupCounter = 0;
            const MAX_SLOTS = 5;
            const $container = $('#slotGroupsContainer');

            // Fungsi untuk membuat satu slot group (HTML string)
            function createSlotGroup(index) {
                const groupId = `group_${index}`;
                let html = `
                    <div class="card mb-3 slot-group" id="${groupId}">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                            <span class="fw-bold">Slot #${index + 1}</span>
                            ${index > 0 ? '<button type="button" class="btn btn-sm btn-outline-danger remove-slot-btn"><i class="fas fa-trash"></i></button>' : ''}
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <label>Mata Kuliah <span class="text-danger">*</span></label>
                                    <input type="text" name="groups[${index}][mata_kuliah]" class="form-control" required placeholder="Nama mata kuliah">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label>Dosen <span class="text-danger">*</span></label>
                                    <input type="text" name="groups[${index}][dosen]" class="form-control" required placeholder="Nama dosen">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label>Ruang <span class="text-danger">*</span></label>
                                    <select name="groups[${index}][ruang]" class="form-control" required>
                                        <option value="">Pilih Ruangan</option>
                `;
                roomsList.forEach(room => {
                    html += `<option value="${room}">${room}</option>`;
                });
                html += `
                                    </select>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label>Pilih Jam Ke:</label>
                                <div class="row">
                `;
                for (let i = 1; i <= 10; i++) {
                    html += `
                        <div class="col-6 col-md-4 col-lg-3 mb-2">
                            <div class="form-check">
                                <input class="form-check-input jam-checkbox" type="checkbox" 
                                       name="groups[${index}][jam_ke_list][]" value="${i}" 
                                       id="jam_${index}_${i}" data-group="${index}" data-jam="${i}">
                                <label class="form-check-label" for="jam_${index}_${i}">
                                    <strong>Jam ke-${i}</strong><br>
                                    <small class="text-muted">${timeSlots[i] || 'Tidak tersedia'}</small>
                                </label>
                            </div>
                        </div>
                    `;
                }
                html += `
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                return html;
            }

            // Fungsi untuk menambahkan slot group baru (tanpa merender ulang semua)
            function addNewSlotGroup() {
                if (slotGroupCounter >= MAX_SLOTS - 1) return;

                slotGroupCounter++;
                const newGroupHtml = createSlotGroup(slotGroupCounter);
                $container.append(newGroupHtml);

                // Update status disable checkbox
                updateCheckboxState();

                // Nonaktifkan tombol tambah jika sudah mencapai maksimum
                if (slotGroupCounter >= MAX_SLOTS - 1) {
                    $('#addSlotGroupBtn').prop('disabled', true);
                }
            }

            // Fungsi re-index setelah penghapusan (nama input dan id diperbarui)
            function reindexGroups() {
                $('.slot-group').each(function(newIndex) {
                    const $group = $(this);
                    const oldId = $group.attr('id');
                    const newId = `group_${newIndex}`;
                    $group.attr('id', newId);

                    // Update header
                    $group.find('.card-header span.fw-bold').text(`Slot #${newIndex + 1}`);

                    // Update semua atribut name yang mengandung groups[index]
                    $group.find('[name^="groups["]').each(function() {
                        const name = $(this).attr('name');
                        const newName = name.replace(/groups\[\d+\]/, `groups[${newIndex}]`);
                        $(this).attr('name', newName);
                    });

                    // Update checkbox id, data-group, dan label for
                    $group.find('.jam-checkbox').each(function() {
                        const $cb = $(this);
                        const jam = $cb.data('jam');
                        const newCheckboxId = `jam_${newIndex}_${jam}`;
                        $cb.attr('id', newCheckboxId);
                        $cb.data('group', newIndex);
                        $cb.closest('.form-check').find('label').attr('for', newCheckboxId);
                    });

                    // Tampilkan tombol hapus hanya untuk index > 0
                    if (newIndex === 0) {
                        $group.find('.remove-slot-btn').remove();
                    } else {
                        if ($group.find('.remove-slot-btn').length === 0) {
                            $group.find('.card-header').append('<button type="button" class="btn btn-sm btn-outline-danger remove-slot-btn"><i class="fas fa-trash"></i></button>');
                        }
                    }
                });

                // Update counter sesuai jumlah group yang tersisa
                slotGroupCounter = $('.slot-group').length - 1;

                // Aktifkan kembali tombol tambah jika di bawah maksimum
                if (slotGroupCounter < MAX_SLOTS - 1) {
                    $('#addSlotGroupBtn').prop('disabled', false);
                }
            }

            // Fungsi updateCheckboxState (tidak berubah, hanya perlu dipanggil saat diperlukan)
            function updateCheckboxState() {
                // Reset semua disable terlebih dahulu
                $('.jam-checkbox').prop('disabled', false);

                // Kumpulkan jam yang sudah dipilih per group
                let selectedPerGroup = {};
                $('.jam-checkbox:checked').each(function() {
                    const group = $(this).data('group');
                    const jam = $(this).data('jam');
                    if (!selectedPerGroup[group]) selectedPerGroup[group] = [];
                    selectedPerGroup[group].push(jam);
                });

                // Nonaktifkan checkbox dengan jam yang sama di group lain
                $('.jam-checkbox').each(function() {
                    const $cb = $(this);
                    const group = $cb.data('group');
                    const jam = $cb.data('jam');

                    for (let g in selectedPerGroup) {
                        if (g != group && selectedPerGroup[g].includes(jam)) {
                            $cb.prop('disabled', true);
                            break;
                        }
                    }
                });
            }

            // Event handler untuk checkbox jam
            $(document).on('change', '.jam-checkbox', function() {
                updateCheckboxState();
            });

            // Tombol Tambah Slot Group
            $('#addSlotGroupBtn').click(function() {
                addNewSlotGroup();
            });

            // Hapus slot group (delegasi event)
            $(document).on('click', '.remove-slot-btn', function() {
                const $group = $(this).closest('.slot-group');
                $group.remove();
                reindexGroups();
                updateCheckboxState();
            });

            // Inisialisasi saat modal dibuka
            $('#bulkAddModal').on('shown.bs.modal', function() {
                // Reset container dan tambahkan satu group awal
                $container.empty();
                slotGroupCounter = 0;
                $container.append(createSlotGroup(0));
                $('#addSlotGroupBtn').prop('disabled', false);
                updateCheckboxState();
            });

            // Reset saat modal ditutup (opsional)
            $('#bulkAddModal').on('hidden.bs.modal', function() {
                $container.empty();
                slotGroupCounter = 0;
                $('#addSlotGroupBtn').prop('disabled', false);
                $(this).find('form')[0].reset();
                $(this).find('select[name="semester"]').val('<?php echo $semester_aktif; ?>');
                $(this).find('select[name="tahun_akademik"]').val('<?php echo $tahun_akademik_aktif; ?>');
            });

            // Validasi form bulk sebelum submit
            $('#bulkAddForm').on('submit', function(e) {
                let valid = true;
                let errorMsg = '';

                $('.slot-group').each(function() {
                    const $group = $(this);
                    const groupIndex = $group.attr('id').split('_')[1];
                    const checkedCount = $group.find('.jam-checkbox:checked').length;

                    if (checkedCount === 0) {
                        valid = false;
                        errorMsg += `Slot #${parseInt(groupIndex)+1}: Pilih minimal satu jam.\n`;
                    }

                    const matkul = $group.find('input[name$="[mata_kuliah]"]').val();
                    const dosen = $group.find('input[name$="[dosen]"]').val();
                    const ruang = $group.find('select[name$="[ruang]"]').val();

                    if (!matkul || !dosen || !ruang) {
                        valid = false;
                        errorMsg += `Slot #${parseInt(groupIndex)+1}: Mata kuliah, dosen, dan ruang harus diisi.\n`;
                    }
                });

                if (!valid) {
                    e.preventDefault();
                    alert(errorMsg);
                    return false;
                }

                const totalSlots = $('.slot-group').length;
                const totalJam = $('.jam-checkbox:checked').length;
                if (!confirm(`Tambahkan ${totalJam} jadwal dari ${totalSlots} mata kuliah?`)) {
                    e.preventDefault();
                    return false;
                }
                return true;
            });

            // Fungsi editSchedule
            window.editSchedule = function(schedule) {
                $('#edit_id').val(schedule.id);
                $('#edit_kelas').val(schedule.kelas);
                $('#edit_hari').val(schedule.hari);
                $('#edit_jam_ke').val(schedule.jam_ke);

                var waktuParts = schedule.waktu.split(' - ');
                if (waktuParts.length === 2) {
                    $('#edit_waktu_mulai').val(waktuParts[0].trim());
                    $('#edit_waktu_selesai').val(waktuParts[1].trim());
                } else {
                    $('#edit_waktu_mulai').val('07:30');
                    $('#edit_waktu_selesai').val('09:00');
                }

                $('#edit_mata_kuliah').val(schedule.mata_kuliah);
                $('#edit_dosen').val(schedule.dosen);
                $('#edit_ruang').val(schedule.ruang);
                $('#edit_semester').val(schedule.semester);
                $('#edit_tahun_akademik').val(schedule.tahun_akademik);

                $('#editModal').modal('show');
            };

            // Validasi waktu untuk form tambah dan edit (jika diperlukan)
            $('#addForm, #editForm').on('submit', function(e) {
                const mulai = $(this).find('input[name="waktu_mulai"]').val();
                const selesai = $(this).find('input[name="waktu_selesai"]').val();
                if (mulai && selesai && mulai >= selesai) {
                    alert('Waktu selesai harus lebih besar dari waktu mulai.');
                    e.preventDefault();
                    return false;
                }
                return true;
            });

        });
    </script>
</body>

</html>
<?php
// Reset session messages
unset($_SESSION['message']);
unset($_SESSION['error']);
?>