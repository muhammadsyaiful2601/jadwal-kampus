<?php
require_once 'config/database.php';
require_once 'config/helpers.php';

$database = new Database();
$db = $database->getConnection();

// Cek maintenance mode
$maintenance_mode = getSetting($db, 'maintenance_mode');
$maintenance_message = getSetting($db, 'maintenance_message');

if ($maintenance_mode == '1') {
    $is_maintenance = true;
} else {
    $is_maintenance = false;
}

// AMBIL SEMESTER AKTIF DARI SYSTEM
$activeSemester = getActiveSemester($db);
$tahun_akademik = $activeSemester['tahun_akademik'];
$semester_aktif = $activeSemester['semester'];

// Ambil setting untuk header
$institusi_nama = getSetting($db, 'institusi_nama') ?? 'Politeknik Negeri Padang';
$institusi_lokasi = getSetting($db, 'institusi_lokasi') ?? 'PSDKU Tanah Datar';
$program_studi = getSetting($db, 'program_studi') ?? 'D3 Sistem Informasi';
$fakultas = getSetting($db, 'fakultas') ?? 'Fakultas Teknik';

// Ambil semua semester untuk dropdown
$all_semesters = getAllSemesters($db);

// Ambil daftar kelas unik dari database yang memiliki jadwal
$query_kelas = "SELECT DISTINCT kelas FROM schedules 
                WHERE tahun_akademik = ? 
                AND semester = ? 
                ORDER BY kelas";
$stmt_kelas = $db->prepare($query_kelas);
$stmt_kelas->execute([$tahun_akademik, $semester_aktif]);
$kelas_list = $stmt_kelas->fetchAll(PDO::FETCH_COLUMN);

// Tentukan hari dan kelas yang dipilih (dari GET atau default)
$hari_selected = $_GET['hari'] ?? date('N'); // 1=Senin, ..., 7=Minggu
$kelas_selected = $_GET['kelas'] ?? ($kelas_list[0] ?? 'A1');
$tampil_semua_hari = isset($_GET['semua_hari']) && $_GET['semua_hari'] == '1';
$tampil_semua_kelas = isset($_GET['semua_kelas']) && $_GET['semua_kelas'] == '1';

// Konversi angka hari ke teks
$hari_map = [
    1 => 'SENIN',
    2 => 'SELASA',
    3 => 'RABU',
    4 => 'KAMIS',
    5 => 'JUMAT'
];

$hari_teks = $hari_map[$hari_selected] ?? 'SENIN';

// Ambil jadwal berdasarkan filter
$params = [$tahun_akademik, $semester_aktif];

if ($tampil_semua_hari && $tampil_semua_kelas) {
    // Tampilkan semua
    $query = "SELECT * FROM schedules 
              WHERE tahun_akademik = ? 
              AND semester = ? 
              ORDER BY FIELD(hari, 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'), kelas, jam_ke";
} elseif ($tampil_semua_hari) {
    // Semua hari, kelas tertentu
    $query = "SELECT * FROM schedules 
              WHERE kelas = ? 
              AND tahun_akademik = ? 
              AND semester = ? 
              ORDER BY FIELD(hari, 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT'), jam_ke";
    $params = [$kelas_selected, $tahun_akademik, $semester_aktif];
} elseif ($tampil_semua_kelas) {
    // Hari tertentu, semua kelas
    $query = "SELECT * FROM schedules 
              WHERE hari = ? 
              AND tahun_akademik = ? 
              AND semester = ? 
              ORDER BY kelas, jam_ke";
    $params = [$hari_teks, $tahun_akademik, $semester_aktif];
} else {
    // Hari dan kelas tertentu
    $query = "SELECT * FROM schedules 
              WHERE hari = ? 
              AND kelas = ? 
              AND tahun_akademik = ? 
              AND semester = ? 
              ORDER BY jam_ke";
    $params = [$hari_teks, $kelas_selected, $tahun_akademik, $semester_aktif];
}

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $jadwal = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error mengambil jadwal: " . $e->getMessage());
    $jadwal = [];
}

// Kelompokkan jadwal berdasarkan hari (untuk tampilan semua hari)
$jadwal_per_hari = [];
if ($tampil_semua_hari && !empty($jadwal)) {
    foreach ($jadwal as $item) {
        $jadwal_per_hari[$item['hari']][] = $item;
    }
}

// Cari jadwal yang sedang berlangsung sekarang
$jam_sekarang = date('H:i');
$hari_sekarang = date('N'); // 1=Senin, 5=Jumat
$hari_sekarang_teks = $hari_map[$hari_sekarang] ?? null;

$jadwal_sekarang = null;
if ($hari_sekarang_teks && $hari_sekarang_teks != 'SABTU' && $hari_sekarang_teks != 'MINGGU') {
    try {
        // Cari semua jadwal hari ini
        $query_hari_ini = "SELECT * FROM schedules 
                           WHERE hari = ? 
                           AND tahun_akademik = ? 
                           AND semester = ? 
                           ORDER BY jam_ke";
        $stmt_hari_ini = $db->prepare($query_hari_ini);
        $stmt_hari_ini->execute([$hari_sekarang_teks, $tahun_akademik, $semester_aktif]);
        $jadwal_hari_ini = $stmt_hari_ini->fetchAll(PDO::FETCH_ASSOC);
        
        // Cari yang sedang berlangsung
        foreach ($jadwal_hari_ini as $item) {
            if (strpos($item['waktu'], ' - ') !== false) {
                list($waktu_mulai, $waktu_selesai) = explode(' - ', $item['waktu']);
                if ($jam_sekarang >= $waktu_mulai && $jam_sekarang <= $waktu_selesai) {
                    $jadwal_sekarang = $item;
                    break;
                }
            }
        }
        
        // Jika tidak ada yang berlangsung, cari jadwal berikutnya
        if (!$jadwal_sekarang) {
            foreach ($jadwal_hari_ini as $item) {
                if (strpos($item['waktu'], ' - ') !== false) {
                    list($waktu_mulai, $waktu_selesai) = explode(' - ', $item['waktu']);
                    if ($jam_sekarang < $waktu_mulai) {
                        $jadwal_sekarang = $item;
                        break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error mencari jadwal saat ini: " . $e->getMessage());
    }
}

// Ambil data ruangan untuk popup
$ruangan_map = [];
try {
    $query_ruangan = "SELECT nama_ruang, foto_path, deskripsi FROM rooms";
    $stmt_ruangan = $db->prepare($query_ruangan);
    $stmt_ruangan->execute();
    $ruangan_data = $stmt_ruangan->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ruangan_data as $ruang) {
        $ruangan_map[$ruang['nama_ruang']] = $ruang;
    }
} catch (Exception $e) {
    error_log("Error mengambil data ruangan: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Kuliah - <?php echo htmlspecialchars($institusi_nama); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #eef2ff;
            --secondary: #3a0ca3;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #f72585;
            --dark: #1a1a2e;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .hero-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 40px 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .hero-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .logo-container {
            background: white;
            border-radius: 20px;
            padding: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .logo-container:hover {
            transform: translateY(-5px);
        }
        
        .header-info {
            color: white;
            text-align: center;
        }
        
        .header-info h1 {
            font-weight: 800;
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .header-info h2 {
            font-weight: 600;
            font-size: 1.5rem;
            opacity: 0.9;
        }
        
        .info-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            padding: 10px 20px;
            display: inline-block;
            margin-top: 15px;
        }
        
        .filter-section {
            margin-top: -30px;
            position: relative;
            z-index: 10;
        }
        
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }
        
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .filter-tab {
            padding: 12px 25px;
            border-radius: 50px;
            border: 2px solid var(--primary-light);
            background: white;
            color: var(--dark);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-tab:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .filter-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }
        
        .filter-tab input {
            display: none;
        }
        
        .jadwal-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            height: 100%;
        }
        
        .jadwal-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-light);
        }
        
        .jadwal-card.active {
            border-color: var(--success);
            box-shadow: 0 0 25px rgba(76, 201, 240, 0.3);
        }
        
        .jadwal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px;
        }
        
        .jadwal-time {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 50px;
            display: inline-block;
            font-weight: 600;
        }
        
        .jadwal-body {
            padding: 25px;
        }
        
        .jadwal-mata-kuliah {
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .jadwal-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            color: #666;
        }
        
        .jadwal-info i {
            color: var(--primary);
            width: 20px;
        }
        
        .current-jadwal {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
            color: white;
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 30px;
            box-shadow: 0 15px 35px rgba(76, 201, 240, 0.3);
        }
        
        .current-jadwal-header {
            background: rgba(0, 0, 0, 0.1);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .current-jadwal-body {
            padding: 30px;
        }
        
        .hari-section {
            margin-bottom: 40px;
        }
        
        .hari-title {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .jadwal-count {
            background: white;
            color: var(--primary);
            padding: 5px 15px;
            border-radius: 50px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .header-info h1 {
                font-size: 1.8rem;
            }
            
            .header-info h2 {
                font-size: 1.2rem;
            }
            
            .filter-tab {
                padding: 10px 20px;
                font-size: 14px;
            }
            
            .jadwal-card {
                margin-bottom: 20px;
            }
        }
        
        @media (max-width: 576px) {
            .hero-header {
                padding: 30px 0;
            }
            
            .filter-tabs {
                flex-direction: column;
            }
            
            .filter-tab {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body class="<?php echo $is_maintenance ? 'maintenance-active' : ''; ?>">
    <!-- Maintenance Modal -->
    <?php if ($is_maintenance): ?>
    <div class="maintenance-modal" id="maintenanceModal">
        <div class="maintenance-content">
            <div class="maintenance-icon">
                <i class="fas fa-tools"></i>
            </div>
            <h2>Sistem Sedang Dalam Perawatan</h2>
            <p class="maintenance-message"><?php echo htmlspecialchars($maintenance_message); ?></p>
            <div class="maintenance-info">
                <i class="fas fa-clock me-2"></i>
                <span><?php echo date('d F Y, H:i'); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="hero-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-3 text-center mb-4 mb-md-0">
                    <div class="logo-container">
                        <img src="assets/images/logo Kampus.png" alt="Logo Kampus" class="img-fluid" 
                             style="max-height: 100px;"
                             onerror="this.onerror=null; this.src='https://via.placeholder.com/100x100/4361ee/ffffff?text=LOGO'">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="header-info">
                        <h1><?php echo htmlspecialchars($institusi_nama); ?></h1>
                        <h2><?php echo htmlspecialchars($institusi_lokasi); ?></h2>
                        <div class="info-badge">
                            <i class="fas fa-graduation-cap me-2"></i>
                            <?php echo htmlspecialchars($program_studi); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 text-center mt-4 mt-md-0">
                    <div class="logo-container">
                        <img src="assets/images/logo jurusan.png" alt="Logo Jurusan" class="img-fluid"
                             style="max-height: 100px;"
                             onerror="this.onerror=null; this.src='https://via.placeholder.com/100x100/3a0ca3/ffffff?text=SI'">
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Filter Section -->
    <section class="filter-section">
        <div class="container">
            <div class="filter-card">
                <div class="row align-items-center mb-4">
                    <div class="col-md-8">
                        <h3 class="mb-2">
                            <i class="fas fa-filter me-2 text-primary"></i> Filter Jadwal
                        </h3>
                        <p class="text-muted mb-0">
                            Tahun Akademik: <strong><?php echo htmlspecialchars($tahun_akademik); ?></strong> | 
                            Semester: <strong><?php echo htmlspecialchars($semester_aktif); ?></strong>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <button class="btn btn-primary" onclick="showAllSchedule()">
                            <i class="fas fa-eye me-2"></i> Tampilkan Semua
                        </button>
                    </div>
                </div>
                
                <!-- Filter Hari -->
                <div class="mb-4">
                    <h5 class="mb-3">
                        <i class="fas fa-calendar-day me-2"></i> Pilih Hari
                    </h5>
                    <div class="filter-tabs">
                        <?php foreach ($hari_map as $num => $hari): ?>
                        <label class="filter-tab <?php echo (!$tampil_semua_hari && $hari_selected == $num) ? 'active' : ''; ?>">
                            <input type="radio" name="hari" value="<?php echo $num; ?>" 
                                   <?php echo (!$tampil_semua_hari && $hari_selected == $num) ? 'checked' : ''; ?>
                                   onchange="updateFilter()">
                            <i class="fas fa-calendar-day"></i> <?php echo $hari; ?>
                        </label>
                        <?php endforeach; ?>
                        
                        <label class="filter-tab <?php echo $tampil_semua_hari ? 'active' : ''; ?>">
                            <input type="radio" name="semua_hari" value="1" 
                                   <?php echo $tampil_semua_hari ? 'checked' : ''; ?>
                                   onchange="updateFilter()">
                            <i class="fas fa-calendar-week"></i> Semua Hari
                        </label>
                    </div>
                </div>
                
                <!-- Filter Kelas -->
                <div>
                    <h5 class="mb-3">
                        <i class="fas fa-users me-2"></i> Pilih Kelas
                    </h5>
                    <?php if (!empty($kelas_list)): ?>
                    <div class="filter-tabs">
                        <?php foreach ($kelas_list as $kelas): ?>
                        <label class="filter-tab <?php echo (!$tampil_semua_kelas && $kelas_selected == $kelas) ? 'active' : ''; ?>">
                            <input type="radio" name="kelas" value="<?php echo htmlspecialchars($kelas); ?>" 
                                   <?php echo (!$tampil_semua_kelas && $kelas_selected == $kelas) ? 'checked' : ''; ?>
                                   onchange="updateFilter()">
                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($kelas); ?>
                        </label>
                        <?php endforeach; ?>
                        
                        <label class="filter-tab <?php echo $tampil_semua_kelas ? 'active' : ''; ?>">
                            <input type="radio" name="semua_kelas" value="1" 
                                   <?php echo $tampil_semua_kelas ? 'checked' : ''; ?>
                                   onchange="updateFilter()">
                            <i class="fas fa-layer-group"></i> Semua Kelas
                        </label>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Tidak ada kelas tersedia untuk semester <?php echo htmlspecialchars($semester_aktif); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Jadwal Saat Ini -->
    <?php if ($jadwal_sekarang): ?>
    <section class="current-jadwal-section py-4">
        <div class="container">
            <div class="current-jadwal">
                <div class="current-jadwal-header">
                    <h4 class="mb-0">
                        <i class="fas fa-play-circle me-2"></i> Sedang Berlangsung
                    </h4>
                    <span class="badge bg-light text-dark">
                        <i class="fas fa-clock me-1"></i>
                        <span id="currentTime"><?php echo date('H:i'); ?></span>
                    </span>
                </div>
                <div class="current-jadwal-body">
                    <div class="row align-items-center">
                        <div class="col-md-2 text-center mb-3 mb-md-0">
                            <div class="display-4 fw-bold text-light"><?php echo htmlspecialchars($jadwal_sekarang['jam_ke']); ?></div>
                            <small>Jam ke-<?php echo htmlspecialchars($jadwal_sekarang['jam_ke']); ?></small>
                        </div>
                        <div class="col-md-6">
                            <h3 class="text-light mb-3"><?php echo htmlspecialchars($jadwal_sekarang['mata_kuliah']); ?></h3>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-tie me-3 text-light"></i>
                                        <span class="text-light"><?php echo htmlspecialchars($jadwal_sekarang['dosen']); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-door-open me-3 text-light"></i>
                                        <span class="text-light">Ruang <?php echo htmlspecialchars($jadwal_sekarang['ruang']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="mb-3">
                                <span class="badge bg-light text-dark fs-6 p-2">
                                    <?php echo htmlspecialchars($jadwal_sekarang['waktu']); ?>
                                </span>
                            </div>
                            <button class="btn btn-light btn-detail" 
                                    data-schedule='<?php echo htmlspecialchars(json_encode($jadwal_sekarang), ENT_QUOTES, 'UTF-8'); ?>'>
                                <i class="fas fa-info-circle me-2"></i> Detail
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Daftar Jadwal -->
    <section class="jadwal-section py-5">
        <div class="container">
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="fw-bold">
                        <i class="fas fa-calendar-alt me-3 text-primary"></i>
                        <?php if ($tampil_semua_hari): ?>
                            Semua Hari
                        <?php else: ?>
                            Hari <?php echo $hari_teks; ?>
                        <?php endif; ?>
                        
                        <?php if ($tampil_semua_kelas): ?>
                            - Semua Kelas
                        <?php else: ?>
                            - Kelas <?php echo htmlspecialchars($kelas_selected); ?>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="badge bg-primary fs-6 p-3">
                        <i class="fas fa-calendar-check me-2"></i>
                        <?php echo count($jadwal); ?> Jadwal
                    </span>
                </div>
            </div>

            <?php if (empty($jadwal)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3 class="text-muted mb-3">Tidak ada jadwal</h3>
                <p class="text-muted mb-4">Tidak ada jadwal kuliah untuk kriteria yang dipilih</p>
                <button class="btn btn-primary" onclick="showAllSchedule()">
                    <i class="fas fa-eye me-2"></i> Tampilkan Semua Jadwal
                </button>
            </div>
            <?php elseif ($tampil_semua_hari): ?>
                <!-- Tampilan semua hari -->
                <?php foreach ($hari_map as $num => $hari): ?>
                    <?php if (isset($jadwal_per_hari[$hari]) && !empty($jadwal_per_hari[$hari])): ?>
                    <div class="hari-section">
                        <div class="hari-title">
                            <h4 class="mb-0">
                                <i class="fas fa-calendar-day me-2"></i> <?php echo $hari; ?>
                            </h4>
                            <span class="jadwal-count">
                                <?php echo count($jadwal_per_hari[$hari]); ?> Jadwal
                            </span>
                        </div>
                        <div class="row">
                            <?php foreach ($jadwal_per_hari[$hari] as $item): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="jadwal-card <?php echo ($item['hari'] == $hari_sekarang_teks && $item['jam_ke'] == ($jadwal_sekarang['jam_ke'] ?? '')) ? 'active' : ''; ?>">
                                    <div class="jadwal-header">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <span class="jadwal-time"><?php echo htmlspecialchars($item['waktu']); ?></span>
                                            <span class="badge bg-light text-dark">Jam ke-<?php echo htmlspecialchars($item['jam_ke']); ?></span>
                                        </div>
                                        <h5 class="text-light mb-0 text-truncate"><?php echo htmlspecialchars($item['mata_kuliah']); ?></h5>
                                    </div>
                                    <div class="jadwal-body">
                                        <div class="jadwal-mata-kuliah"><?php echo htmlspecialchars($item['mata_kuliah']); ?></div>
                                        <div class="jadwal-info">
                                            <i class="fas fa-user-tie"></i>
                                            <span><?php echo htmlspecialchars($item['dosen']); ?></span>
                                        </div>
                                        <div class="jadwal-info">
                                            <i class="fas fa-door-open"></i>
                                            <span>Ruang <?php echo htmlspecialchars($item['ruang']); ?></span>
                                        </div>
                                        <div class="jadwal-info">
                                            <i class="fas fa-users"></i>
                                            <span>Kelas <?php echo htmlspecialchars($item['kelas']); ?></span>
                                        </div>
                                        <div class="mt-4">
                                            <button class="btn btn-outline-primary w-100 btn-detail" 
                                                    data-schedule='<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>'>
                                                <i class="fas fa-info-circle me-2"></i> Detail Jadwal
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Tampilan per hari -->
                <div class="row">
                    <?php foreach ($jadwal as $item): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="jadwal-card <?php echo ($item['hari'] == $hari_sekarang_teks && $item['jam_ke'] == ($jadwal_sekarang['jam_ke'] ?? '')) ? 'active' : ''; ?>">
                            <div class="jadwal-header">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="jadwal-time"><?php echo htmlspecialchars($item['waktu']); ?></span>
                                    <span class="badge bg-light text-dark">Jam ke-<?php echo htmlspecialchars($item['jam_ke']); ?></span>
                                </div>
                                <h5 class="text-light mb-0 text-truncate"><?php echo htmlspecialchars($item['mata_kuliah']); ?></h5>
                            </div>
                            <div class="jadwal-body">
                                <div class="jadwal-mata-kuliah"><?php echo htmlspecialchars($item['mata_kuliah']); ?></div>
                                <div class="jadwal-info">
                                    <i class="fas fa-user-tie"></i>
                                    <span><?php echo htmlspecialchars($item['dosen']); ?></span>
                                </div>
                                <div class="jadwal-info">
                                    <i class="fas fa-door-open"></i>
                                    <span>Ruang <?php echo htmlspecialchars($item['ruang']); ?></span>
                                </div>
                                <div class="jadwal-info">
                                    <i class="fas fa-users"></i>
                                    <span>Kelas <?php echo htmlspecialchars($item['kelas']); ?></span>
                                </div>
                                <div class="mt-4">
                                    <button class="btn btn-outline-primary w-100 btn-detail" 
                                            data-schedule='<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>'>
                                        <i class="fas fa-info-circle me-2"></i> Detail Jadwal
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer bg-dark text-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5 class="mb-3">
                        <i class="fas fa-university me-2"></i>
                        <?php echo htmlspecialchars($institusi_nama); ?>
                    </h5>
                    <p class="mb-2">
                        <i class="fas fa-map-marker-alt me-2"></i>
                        <?php echo htmlspecialchars($institusi_lokasi); ?>
                    </p>
                    <p class="mb-2">
                        <i class="fas fa-graduation-cap me-2"></i>
                        <?php echo htmlspecialchars($program_studi); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-building me-2"></i>
                        <?php echo htmlspecialchars($fakultas); ?>
                    </p>
                </div>
                <div class="col-md-6">
                    <h5 class="mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        Informasi Sistem
                    </h5>
                    <p class="mb-2">
                        <i class="fas fa-calendar me-2"></i>
                        Tahun Akademik: <?php echo htmlspecialchars($tahun_akademik); ?>
                    </p>
                    <p class="mb-2">
                        <i class="fas fa-book me-2"></i>
                        Semester: <?php echo htmlspecialchars($semester_aktif); ?>
                    </p>
                    <p class="mb-2">
                        <i class="fas fa-clock me-2"></i>
                        Update Terakhir: <?php echo date('d/m/Y H:i'); ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-database me-2"></i>
                        Total Jadwal: <?php echo count($jadwal); ?>
                    </p>
                </div>
            </div>
            <hr class="my-4 bg-light">
            <div class="text-center">
                <p class="mb-2">
                    © <?php echo date('Y'); ?> Sistem Informasi Jadwal Kuliah v4.0
                </p>
                <small class="text-light opacity-75">
                    Sistem menampilkan <?php echo count($jadwal); ?> jadwal untuk semester <?php echo htmlspecialchars($semester_aktif); ?> <?php echo htmlspecialchars($tahun_akademik); ?>
                </small>
            </div>
        </div>
    </footer>

    <!-- Modal Detail Jadwal -->
    <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i> Detail Jadwal
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="scheduleDetail">
                    <!-- Detail akan diisi oleh JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update filter and reload page
        function updateFilter() {
            const semuaHari = document.querySelector('input[name="semua_hari"]:checked') ? '1' : '0';
            const semuaKelas = document.querySelector('input[name="semua_kelas"]:checked') ? '1' : '0';
            const hari = document.querySelector('input[name="hari"]:checked')?.value;
            const kelas = document.querySelector('input[name="kelas"]:checked')?.value;
            
            let params = new URLSearchParams();
            
            if (semuaHari === '1') {
                params.append('semua_hari', '1');
            } else if (hari) {
                params.append('hari', hari);
            }
            
            if (semuaKelas === '1') {
                params.append('semua_kelas', '1');
            } else if (kelas) {
                params.append('kelas', kelas);
            }
            
            window.location.href = 'index.php?' + params.toString();
        }

        // Show all schedule
        function showAllSchedule() {
            window.location.href = 'index.php?semua_hari=1&semua_kelas=1';
        }

        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = `${hours}:${minutes}`;
            }
        }

        // Show schedule detail
        function showScheduleDetail(schedule) {
            const ruang = <?php echo json_encode($ruangan_map); ?>[schedule.ruang] || {};
            
            const modalBody = document.getElementById('scheduleDetail');
            
            // Determine status
            const now = new Date();
            const currentTime = now.getHours() * 60 + now.getMinutes();
            const [startHour, startMinute] = schedule.waktu.split(' - ')[0].split(':').map(Number);
            const startTime = startHour * 60 + startMinute;
            const [endHour, endMinute] = schedule.waktu.split(' - ')[1]?.split(':').map(Number) || [0, 0];
            const endTime = endHour * 60 + endMinute;
            
            let statusBadge = '';
            if (currentTime >= startTime && currentTime <= endTime) {
                statusBadge = '<span class="badge bg-success mb-3">Sedang Berlangsung</span>';
            } else if (currentTime < startTime) {
                statusBadge = '<span class="badge bg-primary mb-3">Akan Datang</span>';
            } else {
                statusBadge = '<span class="badge bg-secondary mb-3">Selesai</span>';
            }
            
            let html = `
                <div class="schedule-detail">
                    ${statusBadge}
                    <div class="mb-4">
                        <h3 class="text-primary fw-bold mb-3">${schedule.mata_kuliah}</h3>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-calendar-day me-3 text-primary"></i>
                                    <div>
                                        <small class="text-muted d-block">Hari</small>
                                        <strong>${schedule.hari}</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-clock me-3 text-primary"></i>
                                    <div>
                                        <small class="text-muted d-block">Waktu</small>
                                        <strong>${schedule.waktu}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 bg-light p-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary text-white rounded-circle p-2 me-3">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block">Kelas</small>
                                        <strong class="fs-5">${schedule.kelas}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 bg-light p-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-success text-white rounded-circle p-2 me-3">
                                        <i class="fas fa-door-open"></i>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block">Ruang</small>
                                        <strong class="fs-5">${schedule.ruang}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card border-0 bg-primary-light p-3 mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-user-tie me-2"></i> Dosen Pengampu
                        </h6>
                        <p class="fs-5 fw-semibold mb-0">${schedule.dosen}</p>
                    </div>
                    
                    ${ruang.deskripsi ? `
                    <div class="card border-0 bg-info-light p-3 mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-info-circle me-2"></i> Informasi Ruangan
                        </h6>
                        <p class="mb-0">${ruang.deskripsi}</p>
                    </div>
                    ` : ''}
                    
                    ${ruang.foto_path ? `
                    <div class="mb-4">
                        <h6 class="mb-3">
                            <i class="fas fa-image me-2"></i> Foto Ruangan
                        </h6>
                        <img src="${ruang.foto_path}" 
                             alt="Ruang ${schedule.ruang}" 
                             class="img-fluid rounded-3 w-100"
                             style="max-height: 300px; object-fit: cover;"
                             onerror="this.onerror=null; this.src='https://via.placeholder.com/800x400/4361ee/ffffff?text=RUANG+${schedule.ruang}'">
                    </div>
                    ` : ''}
                </div>
            `;
            
            modalBody.innerHTML = html;
            
            const modal = new bootstrap.Modal(document.getElementById('scheduleModal'));
            modal.show();
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Update time
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            // Setup detail buttons
            document.querySelectorAll('.btn-detail').forEach(button => {
                button.addEventListener('click', function() {
                    try {
                        const scheduleData = JSON.parse(this.getAttribute('data-schedule'));
                        showScheduleDetail(scheduleData);
                    } catch (e) {
                        console.error('Error:', e);
                        alert('Terjadi kesalahan saat memuat detail');
                    }
                });
            });
            
            // Auto-refresh every 5 minutes
            setTimeout(() => {
                window.location.reload();
            }, 5 * 60 * 1000);
            
            // Highlight active filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                const radio = tab.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    tab.classList.add('active');
                }
                
                tab.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                        this.classList.add('active');
                        updateFilter();
                    }
                });
            });
        });
    </script>
</body>
</html>