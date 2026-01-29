<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

require_once 'check_auth.php';
requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Fungsi untuk upload foto
function uploadFoto($file, $roomId) {
    $targetDir = "../uploads/rooms/";
    
    // Create directory if not exists
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Validasi file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Error upload file. Kode error: ' . $file['error']];
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'Format file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP.'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'Ukuran file maksimal 2MB'];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'room_' . $roomId . '_' . time() . '.' . $extension;
    $targetFile = $targetDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'message' => 'Gagal mengupload file'];
}

// Tambah ruangan
if(isset($_POST['add_room'])) {
    $nama_ruang = trim($_POST['nama_ruang']);
    $deskripsi = trim($_POST['deskripsi']);
    $kapasitas = isset($_POST['kapasitas']) ? (int)$_POST['kapasitas'] : 0;
    $fasilitas = trim($_POST['fasilitas'] ?? '');
    $foto_path = null;
    
    // Validasi
    if (empty($nama_ruang)) {
        $_SESSION['error_message'] = "Nama ruangan harus diisi";
        header('Location: manage_rooms.php');
        exit();
    }
    
    // Cek apakah nama ruangan sudah ada
    $check_query = "SELECT COUNT(*) FROM rooms WHERE nama_ruang = ?";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([$nama_ruang]);
    
    if ($check_stmt->fetchColumn() > 0) {
        $_SESSION['error_message'] = "Nama ruangan sudah digunakan";
        header('Location: manage_rooms.php');
        exit();
    }
    
    // Insert data
    $query = "INSERT INTO rooms (nama_ruang, deskripsi, kapasitas, fasilitas) VALUES (?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$nama_ruang, $deskripsi, $kapasitas, $fasilitas]);
    
    $roomId = $db->lastInsertId();
    
    // Upload foto jika ada
    if(isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $uploadResult = uploadFoto($_FILES['foto'], $roomId);
        
        if($uploadResult['success']) {
            $foto_path = $uploadResult['filename'];
            
            // Update database dengan foto path
            $updateQuery = "UPDATE rooms SET foto_path = ? WHERE id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$foto_path, $roomId]);
        } else {
            $_SESSION['error_message'] = $uploadResult['message'];
        }
    }
    
    logActivity($db, $_SESSION['user_id'], 'Tambah Ruangan', $nama_ruang);
    $_SESSION['message'] = "Ruangan berhasil ditambahkan!";
    header('Location: manage_rooms.php');
    exit();
}

// Edit ruangan
if(isset($_POST['edit_room'])) {
    $id = $_POST['id'];
    $nama_ruang = trim($_POST['nama_ruang']);
    $deskripsi = trim($_POST['deskripsi']);
    $kapasitas = isset($_POST['kapasitas']) ? (int)$_POST['kapasitas'] : 0;
    $fasilitas = trim($_POST['fasilitas'] ?? '');
    
    // Validasi
    if (empty($nama_ruang)) {
        $_SESSION['error_message'] = "Nama ruangan harus diisi";
        header('Location: manage_rooms.php');
        exit();
    }
    
    // Cek apakah nama ruangan sudah ada (kecuali untuk dirinya sendiri)
    $check_query = "SELECT COUNT(*) FROM rooms WHERE nama_ruang = ? AND id != ?";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute([$nama_ruang, $id]);
    
    if ($check_stmt->fetchColumn() > 0) {
        $_SESSION['error_message'] = "Nama ruangan sudah digunakan";
        header('Location: manage_rooms.php');
        exit();
    }
    
    $query = "UPDATE rooms SET nama_ruang = ?, deskripsi = ?, kapasitas = ?, fasilitas = ? WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$nama_ruang, $deskripsi, $kapasitas, $fasilitas, $id]);
    
    // Upload foto baru jika ada
    if(isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $uploadResult = uploadFoto($_FILES['foto'], $id);
        
        if($uploadResult['success']) {
            $foto_path = $uploadResult['filename'];
            
            // Hapus foto lama jika ada
            $get_old_foto = "SELECT foto_path FROM rooms WHERE id = ?";
            $stmt_old = $db->prepare($get_old_foto);
            $stmt_old->execute([$id]);
            $old_foto = $stmt_old->fetch(PDO::FETCH_ASSOC);
            
            if ($old_foto && $old_foto['foto_path']) {
                $old_path = "../uploads/rooms/" . $old_foto['foto_path'];
                if (file_exists($old_path)) {
                    unlink($old_path);
                }
            }
            
            // Update foto path
            $updateQuery = "UPDATE rooms SET foto_path = ? WHERE id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$foto_path, $id]);
        } else {
            $_SESSION['error_message'] = $uploadResult['message'];
        }
    }
    
    logActivity($db, $_SESSION['user_id'], 'Edit Ruangan', $nama_ruang);
    $_SESSION['message'] = "Ruangan berhasil diperbarui!";
    header('Location: manage_rooms.php');
    exit();
}

// Hapus ruangan
if(isset($_GET['delete'])) {
    $room_id = $_GET['delete'];
    
    // Cek apakah ruangan digunakan di jadwal
    $check = "SELECT COUNT(*) FROM schedules WHERE ruang = (SELECT nama_ruang FROM rooms WHERE id = ?)";
    $stmt = $db->prepare($check);
    $stmt->execute([$room_id]);
    $used = $stmt->fetchColumn();
    
    if($used > 0) {
        $_SESSION['error_message'] = "Ruangan tidak dapat dihapus karena masih digunakan dalam jadwal!";
    } else {
        // Hapus foto jika ada
        $query = "SELECT foto_path FROM rooms WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($room && $room['foto_path']) {
            $fotoPath = "../uploads/rooms/" . $room['foto_path'];
            if(file_exists($fotoPath)) {
                unlink($fotoPath);
            }
        }
        
        $query = "DELETE FROM rooms WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$room_id]);
        
        logActivity($db, $_SESSION['user_id'], 'Hapus Ruangan', "ID: {$room_id}");
        $_SESSION['message'] = "Ruangan berhasil dihapus!";
    }
    header('Location: manage_rooms.php');
    exit();
}

// Hapus foto
if(isset($_GET['delete_foto'])) {
    $roomId = $_GET['delete_foto'];
    
    // Get foto path
    $query = "SELECT foto_path, nama_ruang FROM rooms WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($room && $room['foto_path']) {
        $fotoPath = "../uploads/rooms/" . $room['foto_path'];
        if(file_exists($fotoPath)) {
            unlink($fotoPath);
        }
        
        // Update database
        $updateQuery = "UPDATE rooms SET foto_path = NULL WHERE id = ?";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([$roomId]);
        
        logActivity($db, $_SESSION['user_id'], 'Hapus Foto Ruangan', "Room: {$room['nama_ruang']}");
        $_SESSION['message'] = "Foto ruangan berhasil dihapus!";
    }
    
    header('Location: manage_rooms.php');
    exit();
}

// Ambil semua ruangan
$query = "SELECT * FROM rooms ORDER BY nama_ruang";
$stmt = $db->prepare($query);
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Ruangan - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
    <style>
        .sidebar {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
            z-index: 1000;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        .navbar-custom {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 0;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            margin: 5px 10px;
            border-radius: 10px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
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
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
                display: none;
            }
            .sidebar.mobile-show {
                display: block;
            }
            .main-content {
                margin-left: 0;
            }
            .mobile-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
        }
        .content-wrapper {
            padding-top: 20px;
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
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .foto-preview {
            max-width: 150px;
            max-height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 10px;
        }
        .upload-container {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-container:hover {
            border-color: #4a6491;
            background-color: #f8f9fa;
        }
        .upload-icon {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        .room-stat {
            background: linear-gradient(135deg, #4a6491, #2c3e50);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 15px;
        }
        .room-stat i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .room-stat .number {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .room-stat .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .fasilitas-badge {
            font-size: 0.8em;
            margin: 2px;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <?php include 'templates/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Navbar -->
            <nav class="navbar navbar-expand-lg navbar-custom mb-4">
                <div class="container-fluid">
                    <button class="navbar-toggler d-md-none" type="button" onclick="toggleMobileSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <h4 class="mb-0">Kelola Ruangan</h4>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="me-3"><?php echo date('d F Y'); ?></span>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle" type="button" 
                                    data-bs-toggle="dropdown">
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
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
            </nav>

            <!-- Content -->
            <div class="content-wrapper">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">Daftar Ruangan</h5>
                            <p class="text-muted mb-0">Kelola ruangan untuk jadwal kuliah</p>
                        </div>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                            <i class="fas fa-plus me-2"></i>Tambah Ruangan
                        </button>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="room-stat">
                            <i class="fas fa-door-open"></i>
                            <div class="number"><?php echo count($rooms); ?></div>
                            <div class="label">Total Ruangan</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="room-stat" style="background: linear-gradient(135deg, #4CAF50, #2E7D32);">
                            <i class="fas fa-camera"></i>
                            <div class="number">
                                <?php 
                                $with_photo = 0;
                                foreach ($rooms as $room) {
                                    if ($room['foto_path']) $with_photo++;
                                }
                                echo $with_photo;
                                ?>
                            </div>
                            <div class="label">Dengan Foto</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="room-stat" style="background: linear-gradient(135deg, #FF9800, #EF6C00);">
                            <i class="fas fa-users"></i>
                            <div class="number">
                                <?php 
                                $total_capacity = 0;
                                foreach ($rooms as $room) {
                                    $total_capacity += $room['kapasitas'] ?? 0;
                                }
                                echo $total_capacity;
                                ?>
                            </div>
                            <div class="label">Total Kapasitas</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="room-stat" style="background: linear-gradient(135deg, #9C27B0, #6A1B9A);">
                            <i class="fas fa-calendar-check"></i>
                            <div class="number">
                                <?php 
                                // Hitung ruangan yang digunakan di jadwal
                                $used_rooms = [];
                                $query_used = "SELECT DISTINCT ruang FROM schedules";
                                $stmt_used = $db->prepare($query_used);
                                $stmt_used->execute();
                                $used_rooms = $stmt_used->fetchAll(PDO::FETCH_COLUMN);
                                echo count($used_rooms);
                                ?>
                            </div>
                            <div class="label">Digunakan</div>
                        </div>
                    </div>
                </div>

                <?php echo displayMessage(); ?>

                <!-- Data Table -->
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover" id="roomsTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Ruangan</th>
                                    <th>Foto</th>
                                    <th>Deskripsi</th>
                                    <th>Kapasitas</th>
                                    <th>Fasilitas</th>
                                    <th>Tanggal Dibuat</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach($rooms as $room): ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($room['nama_ruang']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if($room['foto_path']): ?>
                                            <img src="../uploads/rooms/<?php echo htmlspecialchars($room['foto_path']); ?>" 
                                                 class="foto-preview" 
                                                 alt="Foto <?php echo htmlspecialchars($room['nama_ruang']); ?>"
                                                 onclick="viewPhoto(this.src, '<?php echo htmlspecialchars($room['nama_ruang']); ?>')"
                                                 style="cursor: pointer;">
                                            <br>
                                            <a href="?delete_foto=<?php echo $room['id']; ?>" 
                                               class="btn btn-sm btn-danger mt-1"
                                               onclick="return confirm('Yakin hapus foto ini?')">
                                                <i class="fas fa-trash"></i> Hapus Foto
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Tidak ada foto</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($room['deskripsi']); ?></td>
                                    <td>
                                        <?php if($room['kapasitas'] > 0): ?>
                                            <span class="badge bg-info"><?php echo $room['kapasitas']; ?> orang</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($room['fasilitas'])) {
                                            $fasilitas = explode(',', $room['fasilitas']);
                                            foreach ($fasilitas as $fas) {
                                                $fas = trim($fas);
                                                if (!empty($fas)) {
                                                    echo '<span class="badge bg-secondary fasilitas-badge">' . htmlspecialchars($fas) . '</span> ';
                                                }
                                            }
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($room['created_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editRoom(<?php echo htmlspecialchars(json_encode($room), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?delete=<?php echo $room['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Yakin hapus ruangan ini?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tambah -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Ruangan Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nama Ruangan <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_ruang" class="form-control" required 
                                           placeholder="Contoh: R.101, Lab. Komputer 1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Kapasitas</label>
                                    <input type="number" name="kapasitas" class="form-control" 
                                           placeholder="Jumlah maksimal orang" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fasilitas</label>
                                    <textarea name="fasilitas" class="form-control" rows="2" 
                                              placeholder="Pisahkan dengan koma (AC, Proyektor, Papan Tulis)"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Deskripsi</label>
                                    <textarea name="deskripsi" class="form-control" rows="4" 
                                              placeholder="Deskripsi ruangan..."></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Foto Ruangan</label>
                                    <div class="upload-container" onclick="document.getElementById('fotoInput').click()">
                                        <div class="upload-icon">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                        </div>
                                        <p class="text-muted mb-1">Klik untuk upload foto</p>
                                        <small class="text-muted">Format: JPG, PNG, GIF, WebP - Maksimal 2MB</small>
                                        <input type="file" name="foto" id="fotoInput" class="d-none" 
                                               accept="image/*" onchange="previewFoto(this, 'addFotoPreview')">
                                    </div>
                                    <div id="addFotoPreview" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="add_room" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Ruangan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nama Ruangan <span class="text-danger">*</span></label>
                                    <input type="text" name="nama_ruang" id="edit_nama_ruang" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Kapasitas</label>
                                    <input type="number" name="kapasitas" id="edit_kapasitas" class="form-control" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Fasilitas</label>
                                    <textarea name="fasilitas" id="edit_fasilitas" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Deskripsi</label>
                                    <textarea name="deskripsi" id="edit_deskripsi" class="form-control" rows="4"></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Foto Ruangan</label>
                                    <div id="currentFoto" class="mb-2"></div>
                                    <div class="upload-container" onclick="document.getElementById('editFotoInput').click()">
                                        <div class="upload-icon">
                                            <i class="fas fa-sync-alt"></i>
                                        </div>
                                        <p class="text-muted mb-1">Klik untuk ganti foto</p>
                                        <small class="text-muted">Biarkan kosong jika tidak ingin mengubah foto</small>
                                        <input type="file" name="foto" id="editFotoInput" class="d-none" 
                                               accept="image/*" onchange="previewFoto(this, 'editFotoPreview')">
                                    </div>
                                    <div id="editFotoPreview" class="mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="edit_room" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal View Photo -->
    <div class="modal fade" id="viewPhotoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="photoTitle">Foto Ruangan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="viewPhotoImg" src="" alt="" class="img-fluid" style="max-height: 500px;">
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#roomsTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.13.1/i18n/id.json"
                },
                "pageLength": 10
            });
        });
        
        function editRoom(room) {
            $('#edit_id').val(room.id);
            $('#edit_nama_ruang').val(room.nama_ruang);
            $('#edit_deskripsi').val(room.deskripsi);
            $('#edit_kapasitas').val(room.kapasitas || '');
            $('#edit_fasilitas').val(room.fasilitas || '');
            
            // Show current photo
            const currentFotoDiv = document.getElementById('currentFoto');
            if (room.foto_path) {
                currentFotoDiv.innerHTML = `
                    <p class="mb-1">Foto saat ini:</p>
                    <img src="../uploads/rooms/${room.foto_path}" 
                         class="foto-preview" 
                         alt="Foto ${room.nama_ruang}"
                         onclick="viewPhoto(this.src, '${room.nama_ruang}')"
                         style="cursor: pointer;">
                `;
            } else {
                currentFotoDiv.innerHTML = '<p class="text-muted mb-1">Tidak ada foto</p>';
            }
            
            // Clear preview
            document.getElementById('editFotoPreview').innerHTML = '';
            
            $('#editModal').modal('show');
        }
        
        function previewFoto(input, previewId) {
            const preview = document.getElementById(previewId);
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validasi ukuran file
                if (file.size > 2 * 1024 * 1024) {
                    alert('Ukuran file maksimal 2MB');
                    input.value = '';
                    return;
                }
                
                // Validasi tipe file
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Format file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP.');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <div class="border rounded p-2">
                            <img src="${e.target.result}" class="img-thumbnail" style="max-height: 150px;">
                            <small class="d-block mt-1">${file.name} (${(file.size / 1024).toFixed(1)} KB)</small>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            }
        }
        
        function toggleMobileSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.mobile-overlay');
            
            if (sidebar.classList.contains('mobile-show')) {
                sidebar.classList.remove('mobile-show');
                if (overlay) overlay.remove();
            } else {
                sidebar.classList.add('mobile-show');
                // Tambah overlay
                if (!overlay) {
                    const overlayDiv = document.createElement('div');
                    overlayDiv.className = 'mobile-overlay';
                    overlayDiv.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0,0,0,0.5);
                        z-index: 999;
                    `;
                    overlayDiv.onclick = toggleMobileSidebar;
                    document.body.appendChild(overlayDiv);
                }
            }
        }
        
        function viewPhoto(src, title) {
            document.getElementById('viewPhotoImg').src = src;
            document.getElementById('photoTitle').textContent = 'Foto: ' + title;
            const modal = new bootstrap.Modal(document.getElementById('viewPhotoModal'));
            modal.show();
        }
        
        // Validate room form before submit
        document.querySelector('#addModal form').addEventListener('submit', function(e) {
            const nama_ruang = document.querySelector('#addModal input[name="nama_ruang"]').value.trim();
            if (!nama_ruang) {
                e.preventDefault();
                alert('Nama ruangan harus diisi');
                return false;
            }
            return true;
        });
        
        document.querySelector('#editModal form').addEventListener('submit', function(e) {
            const nama_ruang = document.querySelector('#editModal input[name="nama_ruang"]').value.trim();
            if (!nama_ruang) {
                e.preventDefault();
                alert('Nama ruangan harus diisi');
                return false;
            }
            return true;
        });
    </script>
</body>
</html>