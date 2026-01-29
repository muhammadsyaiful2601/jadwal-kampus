<?php
// manage_semester.php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Tambah semester baru
if (isset($_POST['add_semester'])) {
    $tahun_akademik = $_POST['tahun_akademik'] ?? '';
    $semester = $_POST['semester'] ?? '';
    
    if ($tahun_akademik && $semester) {
        if (addSemester($db, $tahun_akademik, $semester)) {
            $_SESSION['message'] = "Semester berhasil ditambahkan!";
        } else {
            $_SESSION['error_message'] = "Gagal menambahkan semester!";
        }
    }
    header('Location: manage_semester.php');
    exit();
}

// Set semester aktif
if (isset($_POST['set_active'])) {
    $id = $_POST['semester_id'] ?? '';
    
    if ($id) {
        $stmt = $db->prepare("SELECT tahun_akademik, semester FROM semester_settings WHERE id = ?");
        $stmt->execute([$id]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($semester && setActiveSemester($db, $semester['tahun_akademik'], $semester['semester'])) {
            logActivity($db, $_SESSION['user_id'], 'Change Active Semester', 
                       'Mengubah semester aktif menjadi ' . $semester['semester'] . ' ' . $semester['tahun_akademik']);
            $_SESSION['message'] = "Semester aktif berhasil diubah!";
        } else {
            $_SESSION['error_message'] = "Gagal mengubah semester aktif!";
        }
    }
    header('Location: manage_semester.php');
    exit();
}

// Hapus semester
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    if (deleteSemester($db, $id)) {
        $_SESSION['message'] = "Semester berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "Tidak bisa menghapus semester aktif!";
    }
    header('Location: manage_semester.php');
    exit();
}

// Ambil semua semester
$semesters = getAllSemesters($db);
$activeSemester = getActiveSemester($db);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Semester - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .active-semester-card {
            background: linear-gradient(135deg, #4a6491, #2c3e50);
            color: white;
            border: none;
        }
        .semester-card {
            transition: all 0.3s ease;
        }
        .semester-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .badge-semester {
            font-size: 0.8rem;
            padding: 5px 10px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'templates/header.php'; ?>
    <?php include 'templates/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calendar-alt me-2"></i> Kelola Semester</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSemesterModal">
                    <i class="fas fa-plus me-2"></i> Tambah Semester
                </button>
            </div>
            
            <?php echo displayMessage(); ?>
            
            <!-- Semester Aktif -->
            <div class="card active-semester-card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5><i class="fas fa-star me-2"></i> Semester Aktif</h5>
                            <h3 class="mb-1">
                                <?php echo htmlspecialchars($activeSemester['semester']); ?> - 
                                <?php echo htmlspecialchars($activeSemester['tahun_akademik']); ?>
                            </h3>
                            <small>Semester ini yang akan ditampilkan di halaman utama</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-light text-dark px-3 py-2">
                                <i class="fas fa-users me-1"></i>
                                <?php 
                                    // Hitung jumlah jadwal di semester aktif
                                    $stmt = $db->prepare("SELECT COUNT(*) FROM schedules WHERE tahun_akademik = ? AND semester = ?");
                                    $stmt->execute([$activeSemester['tahun_akademik'], $activeSemester['semester']]);
                                    $jumlah_jadwal = $stmt->fetchColumn();
                                    echo $jumlah_jadwal . " Jadwal";
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Daftar Semua Semester -->
            <div class="row">
                <?php foreach ($semesters as $semester): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card semester-card <?php echo $semester['is_active'] ? 'border-primary border-2' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="card-title mb-1">
                                        <?php echo htmlspecialchars($semester['semester']); ?>
                                    </h5>
                                    <h6 class="text-muted mb-3">
                                        <?php echo htmlspecialchars($semester['tahun_akademik']); ?>
                                    </h6>
                                </div>
                                <?php if ($semester['is_active']): ?>
                                <span class="badge bg-success">Aktif</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <?php 
                                    // Hitung jumlah jadwal di semester ini
                                    $stmt = $db->prepare("SELECT COUNT(*) FROM schedules WHERE tahun_akademik = ? AND semester = ?");
                                    $stmt->execute([$semester['tahun_akademik'], $semester['semester']]);
                                    $jumlah_jadwal = $stmt->fetchColumn();
                                ?>
                                <small class="text-muted">
                                    <i class="fas fa-calendar-check me-1"></i>
                                    <?php echo $jumlah_jadwal; ?> jadwal kuliah
                                </small>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <?php if (!$semester['is_active']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="semester_id" value="<?php echo $semester['id']; ?>">
                                    <button type="submit" name="set_active" class="btn btn-sm btn-primary">
                                        <i class="fas fa-check me-1"></i> Set Aktif
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="btn btn-sm btn-success disabled">
                                    <i class="fas fa-check-circle me-1"></i> Aktif
                                </span>
                                <?php endif; ?>
                                
                                <?php if (!$semester['is_active']): ?>
                                <a href="manage_semester.php?delete=<?php echo $semester['id']; ?>" 
                                   class="btn btn-sm btn-danger" 
                                   onclick="return confirm('Yakin hapus semester <?php echo $semester['semester']; ?> <?php echo $semester['tahun_akademik']; ?>?')">
                                    <i class="fas fa-trash me-1"></i> Hapus
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Info Box -->
            <div class="alert alert-info mt-4">
                <h5><i class="fas fa-info-circle me-2"></i> Informasi</h5>
                <ul class="mb-0">
                    <li>Hanya satu semester yang dapat aktif pada satu waktu</li>
                    <li>Semester yang aktif akan ditampilkan di halaman utama</li>
                    <li>Jadwal kuliah akan difilter berdasarkan semester aktif</li>
                    <li>Pastikan jadwal sudah dimasukkan untuk semester yang akan diaktifkan</li>
                    <li>Semester aktif tidak dapat dihapus</li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Modal Tambah Semester -->
    <div class="modal fade" id="addSemesterModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Semester Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tahun_akademik" class="form-label">Tahun Akademik</label>
                            <input type="text" class="form-control" id="tahun_akademik" 
                                   name="tahun_akademik" required 
                                   placeholder="Contoh: 2024/2025">
                            <small class="text-muted">Format: YYYY/YYYY</small>
                        </div>
                        <div class="mb-3">
                            <label for="semester" class="form-label">Semester</label>
                            <select class="form-select" id="semester" name="semester" required>
                                <option value="GANJIL">GANJIL</option>
                                <option value="GENAP">GENAP</option>
                            </select>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <small>Pastikan jadwal sudah dimasukkan untuk semester ini sebelum mengaktifkannya</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_semester" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-generate tahun akademik berdasarkan tahun saat ini
        document.addEventListener('DOMContentLoaded', function() {
            const tahunInput = document.getElementById('tahun_akademik');
            if (tahunInput && !tahunInput.value) {
                const currentYear = new Date().getFullYear();
                const nextYear = currentYear + 1;
                tahunInput.value = `${currentYear}/${nextYear}`;
            }
        });
    </script>
</body>
</html>