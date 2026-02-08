<?php
// WAJIB duluan → database
require_once '../config/database.php';

// Baru panggil check_auth
require_once 'check_auth.php';

// Hanya admin dan superadmin yang bisa akses
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    $_SESSION['error'] = "Akses ditolak. Halaman ini hanya untuk admin.";
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Query dasar
$query = "SELECT s.*, u.username as responder_name FROM suggestions s 
          LEFT JOIN users u ON s.responded_by = u.id WHERE 1=1";
$count_query = "SELECT COUNT(*) FROM suggestions WHERE 1=1";
$params = [];
$count_params = [];

// Apply filters
if ($status !== 'all') {
    $query .= " AND s.status = ?";
    $count_query .= " AND status = ?";
    $params[] = $status;
    $count_params[] = $status;
}

if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.message LIKE ?)";
    $count_query .= " AND (name LIKE ? OR email LIKE ? OR message LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
    $count_params[] = $search_term;
}

// Order dan limit
$query .= " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Eksekusi query
$stmt = $db->prepare($query);
$stmt->execute($params);
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($count_params);
$total_suggestions = $stmt_count->fetchColumn();
$total_pages = ceil($total_suggestions / $limit);

// Stats
$query_stats = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
    SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded
    FROM suggestions";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $suggestion_id = $_POST['suggestion_id'] ?? 0;
    
    if ($action === 'update_status') {
        $new_status = $_POST['status'] ?? '';
        $response_text = $_POST['response'] ?? '';
        
        // Validasi: tidak bisa mengubah dari 'read' atau 'responded' ke 'pending'
        $stmt = $db->prepare("SELECT status FROM suggestions WHERE id = ?");
        $stmt->execute([$suggestion_id]);
        $current_status = $stmt->fetchColumn();
        
        if (($current_status === 'read' || $current_status === 'responded') && $new_status === 'pending') {
            $_SESSION['error'] = "Tidak bisa mengubah status kembali ke 'pending' setelah dibaca.";
            header("Location: saran.php");
            exit();
        }
        
        if (in_array($new_status, ['pending', 'read', 'responded'])) {
            try {
                $update_query = "UPDATE suggestions SET 
                                status = ?, 
                                response = ?,
                                responded_by = ?,
                                responded_at = NOW()
                                WHERE id = ?";
                $update_params = [
                    $new_status,
                    $response_text,
                    $_SESSION['user_id'],
                    $suggestion_id
                ];
                
                $stmt_update = $db->prepare($update_query);
                if ($stmt_update->execute($update_params)) {
                    $_SESSION['message'] = "Status saran berhasil diperbarui";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Gagal memperbarui status: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete' && $_SESSION['role'] === 'superadmin') {
        // Hanya superadmin yang bisa menghapus satu saran
        try {
            $delete_query = "DELETE FROM suggestions WHERE id = ?";
            $stmt_delete = $db->prepare($delete_query);
            if ($stmt_delete->execute([$suggestion_id])) {
                $_SESSION['message'] = "Saran berhasil dihapus";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal menghapus saran: " . $e->getMessage();
        }
    } elseif ($action === 'delete_all' && $_SESSION['role'] === 'superadmin') {
        // Hanya superadmin yang bisa menghapus semua
        $confirm = isset($_POST['confirm_delete_all']) && $_POST['confirm_delete_all'] === '1';
        
        if ($confirm) {
            try {
                $delete_all_query = "DELETE FROM suggestions";
                $stmt_delete_all = $db->prepare($delete_all_query);
                if ($stmt_delete_all->execute()) {
                    $_SESSION['message'] = "Semua saran berhasil dihapus";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Gagal menghapus semua saran: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Konfirmasi penghapusan tidak valid";
        }
    }
    
    header("Location: saran.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kritik & Saran - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
    <style>
        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .badge-pending {
            background-color: #ffc107;
            color: #000;
        }
        .badge-read {
            background-color: #0dcaf0;
            color: #000;
        }
        .badge-responded {
            background-color: #198754;
            color: white;
        }
        .message-preview {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .back-btn {
            margin-right: 15px;
        }
        .header-nav {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 0;
            margin-bottom: 30px;
        }
        .status-info {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }
        
        /* Animasi untuk badge status berubah */
        .badge {
            transition: all 0.3s ease;
        }
        
        /* Animasi untuk update stats */
        .stat-card h2 {
            transition: all 0.5s ease;
        }
        
        .stat-card h2.updated {
            transform: scale(1.1);
            color: #198754;
        }
        
        /* Notification styles */
        #notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .notification {
            min-width: 300px;
            margin-bottom: 10px;
        }
        
        /* Efek untuk pesan yang belum dibaca */
        .blurred-message {
            user-select: none;
            pointer-events: none;
            filter: blur(4px);
            background-color: rgba(255, 255, 255, 0.7);
            padding: 2px 5px;
            border-radius: 3px;
            display: inline-block;
            transition: filter 0.5s ease;
        }
        
        /* Efek untuk pesan yang sudah dibaca */
        .read-message {
            filter: blur(0);
            background-color: transparent;
        }
        
        /* Highlight untuk menarik perhatian */
        .highlight-unread {
            background-color: rgba(255, 193, 7, 0.05) !important;
            border-left: 4px solid #ffc107 !important;
        }
        
        /* Pesan peringatan kecil */
        .unread-hint {
            font-size: 11px;
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            margin-top: 3px;
            border: 1px solid #ffeaa7;
        }
        
        /* Style untuk badge "BARU" */
        .badge-new {
            background: linear-gradient(45deg, #ff0000, #ff6b6b);
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            margin-left: 5px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Fix z-index untuk modal */
        .modal {
            z-index: 1060 !important;
        }
        
        .modal-backdrop {
            z-index: 1050 !important;
        }
        
        /* Animasi untuk menghilangkan blur */
        .unblur-animation {
            animation: unblur 0.5s ease forwards;
        }
        
        @keyframes unblur {
            from { filter: blur(4px); opacity: 0.7; }
            to { filter: blur(0); opacity: 1; }
        }
        
        /* Tombol hapus semua */
        .delete-all-alert {
            border-left: 4px solid #dc3545;
            animation: pulse-alert 2s infinite;
        }
        
        @keyframes pulse-alert {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { box-shadow: 0 0 0 5px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        
        /* Style untuk konfirmasi hapus semua */
        #deleteAllConfirm.is-valid {
            border-color: #198754;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
        }
        
        #deleteAllConfirm.is-invalid {
            border-color: #dc3545;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
        }
        
        /* Style untuk teks konfirmasi acak */
        .confirmation-display {
            border: 2px dashed #dc3545;
            background: linear-gradient(45deg, rgba(255,255,255,0.9), rgba(255,240,240,0.9));
            margin-bottom: 15px;
        }
        
        .confirmation-display code {
            font-family: 'Courier New', monospace;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
            font-weight: bold;
            font-size: 1.1rem;
            color: #dc3545;
            letter-spacing: 1px;
        }
        
        /* Animasi untuk teks konfirmasi */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-2px); }
            20%, 40%, 60%, 80% { transform: translateX(2px); }
        }
        
        .confirmation-display.shaking {
            animation: shake 0.5s;
        }
    </style>
</head>
<body>
    <!-- Header Navigation -->
    <nav class="header-nav">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <a href="dashboard.php" class="btn btn-outline-primary back-btn">
                        <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
                    </a>
                    <div>
                        <h4 class="mb-0">
                            <i class="fas fa-comments me-2 text-primary"></i>Kritik & Saran
                        </h4>
                        <small class="text-muted">Kelola kritik dan saran dari pengguna</small>
                    </div>
                </div>
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <small class="text-muted">Login sebagai:</small>
                        <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a></li>
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

    <div class="container">
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Total</h6>
                        <h2 class="mb-0 total-count"><?php echo $stats['total']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Pending</h6>
                        <h2 class="mb-0 text-warning pending-count"><?php echo $stats['pending']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Sudah Dibaca</h6>
                        <h2 class="mb-0 text-info read-count"><?php echo $stats['read_count']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Ditanggapi</h6>
                        <h2 class="mb-0 text-success responded-count"><?php echo $stats['responded']; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>Semua</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Sudah Dibaca</option>
                            <option value="responded" <?php echo $status === 'responded' ? 'selected' : ''; ?>>Ditanggapi</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Pencarian</label>
                        <input type="text" name="search" class="form-control" placeholder="Cari berdasarkan nama, email, atau pesan..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tombol Hapus Semua untuk Superadmin -->
        <?php if ($_SESSION['role'] === 'superadmin' && $total_suggestions > 0): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-warning delete-all-alert">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Superadmin Action:</strong> Anda dapat menghapus semua data kritik & saran sekaligus
                            <div class="mt-1 text-muted">
                                <small>
                                    <i class="fas fa-info-circle me-1"></i>
                                    Tindakan ini hanya tersedia untuk Superadmin dan akan menghapus semua <?php echo $total_suggestions; ?> data
                                </small>
                            </div>
                        </div>
                        <div>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal">
                                <i class="fas fa-trash-alt me-2"></i>Hapus Semua Data
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Suggestions List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    Daftar Kritik & Saran
                </h5>
                <div>
                    <?php if ($total_suggestions > 0): ?>
                    <span class="badge bg-primary">
                        Halaman <?php echo $page; ?> dari <?php echo $total_pages; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($suggestions)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">Tidak ada kritik dan saran</h5>
                    <p class="text-muted">Belum ada kritik dan saran yang masuk</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="15%">Pengirim</th>
                                <th width="20%">Pesan</th>
                                <th width="15%">Status</th>
                                <th width="15%">Tanggal</th>
                                <th width="30%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suggestions as $index => $suggestion): 
                                $isUnread = $suggestion['status'] === 'pending';
                                $message = htmlspecialchars($suggestion['message']);
                                $messageLength = strlen($message);
                            ?>
                            <tr class="<?php echo $isUnread ? 'highlight-unread' : ''; ?>" 
                                data-id="<?php echo $suggestion['id']; ?>"
                                data-status="<?php echo $suggestion['status']; ?>">
                                <td><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($suggestion['name']); ?></strong>
                                        <?php if ($isUnread): ?>
                                            <span class="badge-new">BARU</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($suggestion['email'] ?: 'Tidak ada email'); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="message-preview" 
                                         data-id="<?php echo $suggestion['id']; ?>"
                                         data-status="<?php echo $suggestion['status']; ?>"
                                         data-full-message="<?php echo htmlspecialchars($suggestion['message']); ?>">
                                        <?php 
                                        if ($isUnread && $messageLength > 0) {
                                            // Untuk pesan pending: tampilkan hanya huruf pertama + bintang
                                            $firstChar = $message[0];
                                            $stars = str_repeat('*', max(0, $messageLength - 1));
                                            $displayText = $firstChar . $stars;
                                            
                                            // Potong jika terlalu panjang
                                            if ($messageLength > 50) {
                                                $displayText = substr($displayText, 0, 50) . '...';
                                            }
                                            
                                            echo '<span class="blurred-message">' . htmlspecialchars($displayText) . '</span>';
                                            echo '<span class="actual-message d-none">' . (strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message) . '</span>';
                                        } else {
                                            // Untuk pesan sudah dibaca: tampilkan normal
                                            echo strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message;
                                        }
                                        ?>
                                    </div>
                                    <?php if ($isUnread): ?>
                                        <small class="unread-hint">
                                            <i class="fas fa-exclamation-circle me-1"></i>Klik detail untuk membaca pesan lengkap
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $badge_class = '';
                                    switch ($suggestion['status']) {
                                        case 'pending': $badge_class = 'badge-pending'; break;
                                        case 'read': $badge_class = 'badge-read'; break;
                                        case 'responded': $badge_class = 'badge-responded'; break;
                                    }
                                    ?>
                                    <span class="badge status-badge <?php echo $badge_class; ?>" data-id="<?php echo $suggestion['id']; ?>">
                                        <?php 
                                        $status_text = [
                                            'pending' => 'Pending',
                                            'read' => 'Sudah Dibaca',
                                            'responded' => 'Ditanggapi'
                                        ];
                                        echo $status_text[$suggestion['status']];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y H:i', strtotime($suggestion['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary detail-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#detailModal<?php echo $suggestion['id']; ?>"
                                                data-id="<?php echo $suggestion['id']; ?>"
                                                data-status="<?php echo $suggestion['status']; ?>">
                                            <i class="fas fa-eye"></i> Detail
                                        </button>
                                        
                                        <!-- Modal Detail -->
                                        <div class="modal fade" id="detailModal<?php echo $suggestion['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-comment-dots me-2"></i> Detail Kritik & Saran
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row mb-3">
                                                            <div class="col-md-6">
                                                                <h6>Informasi Pengirim</h6>
                                                                <p class="mb-1"><strong>Nama:</strong> <?php echo htmlspecialchars($suggestion['name']); ?></p>
                                                                <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($suggestion['email'] ?: 'Tidak ada'); ?></p>
                                                                <p class="mb-1"><strong>IP Address:</strong> <code><?php echo htmlspecialchars($suggestion['ip_address']); ?></code></p>
                                                                <p class="mb-0"><strong>Tanggal:</strong> <?php echo date('d F Y H:i', strtotime($suggestion['created_at'])); ?></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <h6>Status</h6>
                                                                <form method="POST" class="mb-3" id="statusForm<?php echo $suggestion['id']; ?>">
                                                                    <input type="hidden" name="suggestion_id" value="<?php echo $suggestion['id']; ?>">
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <div class="mb-3">
                                                                        <select name="status" class="form-select" id="statusSelect<?php echo $suggestion['id']; ?>">
                                                                            <!-- Opsi pending hanya tersedia jika status saat ini pending -->
                                                                            <?php if ($suggestion['status'] === 'pending'): ?>
                                                                                <option value="pending" <?php echo $suggestion['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                                            <?php endif; ?>
                                                                            <option value="read" <?php echo $suggestion['status'] === 'read' ? 'selected' : ''; ?>>Sudah Dibaca</option>
                                                                            <option value="responded" <?php echo $suggestion['status'] === 'responded' ? 'selected' : ''; ?>>Ditanggapi</option>
                                                                        </select>
                                                                        <?php if ($suggestion['status'] !== 'pending'): ?>
                                                                            <div class="status-info text-muted mt-1">
                                                                                <i class="fas fa-info-circle"></i> Status tidak bisa dikembalikan ke pending setelah dibaca.
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label class="form-label">Tanggapan (opsional)</label>
                                                                        <textarea name="response" class="form-control" rows="3" placeholder="Masukkan tanggapan..."><?php echo htmlspecialchars($suggestion['response'] ?? ''); ?></textarea>
                                                                    </div>
                                                                    <div class="d-flex justify-content-between">
                                                                        <button type="submit" class="btn btn-primary">
                                                                            <i class="fas fa-save me-2"></i> Simpan Perubahan
                                                                        </button>
                                                                        <?php if ($_SESSION['role'] === 'superadmin'): ?>
                                                                        <button type="button" class="btn btn-danger" onclick="confirmDelete(<?php echo $suggestion['id']; ?>)">
                                                                            <i class="fas fa-trash me-2"></i> Hapus
                                                                        </button>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </form>
                                                                <?php if ($suggestion['responded_by']): ?>
                                                                <p class="mb-1"><strong>Ditanggapi oleh:</strong> <?php echo htmlspecialchars($suggestion['responder_name']); ?></p>
                                                                <p class="mb-0"><strong>Tanggal tanggapan:</strong> <?php echo date('d F Y H:i', strtotime($suggestion['responded_at'])); ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <hr>
                                                        <h6>Pesan</h6>
                                                        <div class="border rounded p-3 bg-light">
                                                            <?php echo nl2br(htmlspecialchars($suggestion['message'])); ?>
                                                        </div>
                                                        <?php if (!empty($suggestion['response'])): ?>
                                                        <hr>
                                                        <h6>Tanggapan</h6>
                                                        <div class="border rounded p-3 bg-success text-white">
                                                            <?php echo nl2br(htmlspecialchars($suggestion['response'])); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($_SESSION['role'] === 'superadmin'): ?>
                                        <button type="button" class="btn btn-outline-danger" onclick="confirmDelete(<?php echo $suggestion['id']; ?>)">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Hapus Semua -->
    <div class="modal fade" id="deleteAllModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Penghapusan
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-trash-alt fa-4x text-danger mb-3"></i>
                        <h4 class="text-danger">PERINGATAN!</h4>
                    </div>
                    
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-circle me-2"></i>Tindakan ini akan:</h6>
                        <ul class="mb-0">
                            <li>Menghapus <strong>SEMUA <?php echo $total_suggestions; ?> data</strong> kritik & saran</li>
                            <li>Data yang dihapus <strong>TIDAK DAPAT DIPULIHKAN</strong></li>
                            <li>Statistik akan direset ke 0</li>
                            <li>Riwayat respons juga akan terhapus</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Masukkan konfirmasi berikut:</label>
                        <div class="mb-2">
                            <div class="confirmation-display bg-light p-3 rounded text-center">
                                <code id="randomConfirmationText" class="text-danger fw-bold fs-5" 
                                      style="letter-spacing: 1px;"></code>
                            </div>
                            <small class="text-muted d-block mt-1">
                                <i class="fas fa-info-circle me-1"></i>
                                Ketik teks di atas dengan tepat (huruf kapital) untuk mengaktifkan tombol hapus
                            </small>
                        </div>
                        <div class="input-group">
                            <input type="text" id="deleteAllConfirm" class="form-control" 
                                   placeholder="Ketik teks konfirmasi..." autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" onclick="copyConfirmationText()" title="Salin teks konfirmasi">
                                <i class="fas fa-copy"></i>
                            </button>
                            <button type="button" class="btn btn-outline-warning" onclick="regenerateConfirmationText()" title="Buat teks konfirmasi baru">
                                <i class="fas fa-redo"></i>
                            </button>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">
                                <i class="fas fa-shield-alt me-1"></i>
                                Teks konfirmasi diacak setiap kali untuk keamanan tambahan
                            </small>
                            <small>
                                <span id="charCount">0</span> karakter
                            </small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Batal
                    </button>
                    <form method="POST" id="deleteAllForm">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="confirm_delete_all" value="1">
                        <input type="hidden" id="correctConfirmationText" value="">
                        <button type="submit" id="deleteAllSubmitBtn" class="btn btn-danger" disabled>
                            <i class="fas fa-trash-alt me-2"></i>Ya, Hapus Semua Data
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Form untuk single delete -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="suggestion_id" id="deleteSuggestionId">
        <input type="hidden" name="action" value="delete">
    </form>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fungsi untuk menampilkan notifikasi
        function showNotification(message, type) {
            // Cek apakah sudah ada notifikasi container
            let container = document.getElementById('notification-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'notification-container';
                document.body.appendChild(container);
            }
            
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const notification = document.createElement('div');
            notification.className = `alert ${alertClass} alert-dismissible fade show notification`;
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            container.appendChild(notification);
            
            // Auto dismiss setelah 3 detik
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 150);
            }, 3000);
        }
        
        // Fungsi untuk confirm delete
        function confirmDelete(suggestionId) {
            if (confirm('Apakah Anda yakin ingin menghapus kritik dan saran ini? Tindakan ini tidak dapat dibatalkan.')) {
                document.getElementById('deleteSuggestionId').value = suggestionId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Fungsi untuk menghilangkan blur setelah pesan dibaca
        function removeBlurAfterRead(suggestionId) {
            const messageDiv = document.querySelector(`.message-preview[data-id="${suggestionId}"]`);
            if (messageDiv) {
                const blurredElement = messageDiv.querySelector('.blurred-message');
                const actualElement = messageDiv.querySelector('.actual-message');
                
                if (blurredElement && actualElement) {
                    // Tambahkan animasi
                    blurredElement.classList.add('unblur-animation');
                    
                    // Ganti konten setelah animasi selesai
                    setTimeout(() => {
                        blurredElement.textContent = actualElement.textContent;
                        blurredElement.classList.remove('blurred-message');
                        blurredElement.classList.remove('unblur-animation');
                        blurredElement.classList.add('read-message');
                        actualElement.remove();
                        
                        // Hapus pesan peringatan
                        const hint = messageDiv.nextElementSibling;
                        if (hint && hint.classList.contains('unread-hint')) {
                            hint.style.display = 'none';
                        }
                    }, 500);
                }
            }
            
            // Update badge status di baris tabel
            const tableRow = document.querySelector(`tr[data-id="${suggestionId}"]`);
            if (tableRow) {
                tableRow.classList.remove('highlight-unread');
                
                // Hapus badge "BARU"
                const newBadge = tableRow.querySelector('.badge-new');
                if (newBadge) {
                    newBadge.style.opacity = '0';
                    setTimeout(() => newBadge.remove(), 300);
                }
                
                // Update status badge
                const statusBadge = tableRow.querySelector('.status-badge');
                if (statusBadge) {
                    statusBadge.classList.remove('badge-pending');
                    statusBadge.classList.add('badge-read');
                    statusBadge.textContent = 'Sudah Dibaca';
                    statusBadge.dataset.status = 'read';
                }
            }
            
            // Update button data
            const detailBtn = document.querySelector(`.detail-btn[data-id="${suggestionId}"]`);
            if (detailBtn) {
                detailBtn.dataset.status = 'read';
            }
        }
        
        // Fungsi untuk update statistik
        function updateStatsAfterRead() {
            // Update pending count
            const pendingCount = document.querySelector('.pending-count');
            if (pendingCount) {
                const current = parseInt(pendingCount.textContent);
                if (current > 0) {
                    pendingCount.textContent = current - 1;
                    pendingCount.classList.add('updated');
                    setTimeout(() => pendingCount.classList.remove('updated'), 1000);
                }
            }
            
            // Update read count
            const readCount = document.querySelector('.read-count');
            if (readCount) {
                const current = parseInt(readCount.textContent);
                readCount.textContent = current + 1;
                readCount.classList.add('updated');
                setTimeout(() => readCount.classList.remove('updated'), 1000);
            }
        }
        
        // Fungsi untuk mengecek pesan yang sudah dibaca dari localStorage
        function checkPreviouslyReadMessages() {
            const unreadMessages = document.querySelectorAll('.message-preview[data-status="pending"]');
            
            unreadMessages.forEach(messageDiv => {
                const suggestionId = messageDiv.dataset.id;
                const isRead = localStorage.getItem(`message_read_${suggestionId}`);
                
                if (isRead === 'true') {
                    // Jika sudah dibaca, langsung hilangkan blur
                    removeBlurAfterRead(suggestionId);
                }
            });
        }
        
        // ========== FUNGSI UNTUK TEKS KONFIRMASI ACAK ==========
        
        // Fungsi untuk membuat teks konfirmasi acak dalam bahasa Indonesia
        function generateRandomConfirmationText() {
            // Daftar kata yang akan diacak - menggunakan kata-kata terkait hapus/data
            const words = [
                "HAPUS", "SEMUA", "DATA", "KRITIK", "SARAN", 
                "HAPUSKAN", "SEMUANYA", "HISTORI", "REKAMAN", 
                "KONFIRMASI", "VERIFIKASI", "TINDAKAN", "PERMANEN",
                "BASISDATA", "ARSIP", "EVIDENSI", "CATATAN",
                "PENGHAPUSAN", "RESET", "BERSIHKAN", "KOSONGKAN",
                "BASIS", "DAFTAR", "ENTRI", "LOG", "LAPORAN"
            ];
            
            // Daftar pola kalimat untuk variasi
            const patterns = [
                ["KONFIRMASI", "HAPUS", "SEMUA", "DATA"],
                ["HAPUSKAN", "SEMUA", "KRITIK", "SARAN"],
                ["VERIFIKASI", "PENGHAPUSAN", "SEMUANYA"],
                ["HAPUS", "DATA", "DAN", "ARSIP"],
                ["BERSIHKAN", "SEMUA", "REKAMAN"],
                ["HAPUS", "SEMUA", "ENTRI", "BASISDATA"],
                ["KONFIRMASI", "RESET", "LAPORAN"],
                ["VERIFIKASI", "HAPUS", "HISTORI"],
                ["KOSONGKAN", "SEMUA", "CATATAN"]
            ];
            
            // Pilih antara pola atau acak
            const usePattern = Math.random() > 0.4;
            
            let confirmationText;
            
            if (usePattern) {
                // Gunakan pola yang sudah ditentukan
                const randomPattern = patterns[Math.floor(Math.random() * patterns.length)];
                confirmationText = randomPattern.join(' ');
            } else {
                // Buat acak dari kata-kata (3-5 kata)
                const numWords = Math.floor(Math.random() * 3) + 3; // 3, 4, atau 5 kata
                
                // Acak kata-kata
                const shuffled = [...words]
                    .sort(() => 0.5 - Math.random())
                    .slice(0, numWords);
                
                // Pastikan "HAPUS" atau "HAPUSKAN" selalu ada untuk kejelasan
                if (!shuffled.some(word => word.includes("HAPUS"))) {
                    shuffled[0] = "HAPUS";
                }
                
                confirmationText = shuffled.join(' ');
            }
            
            return confirmationText;
        }
        
        // Fungsi untuk menampilkan teks konfirmasi baru
        function displayNewConfirmationText() {
            const randomText = generateRandomConfirmationText();
            const displayElement = document.getElementById('randomConfirmationText');
            const hiddenElement = document.getElementById('correctConfirmationText');
            
            // Tambahkan efek animasi
            displayElement.parentElement.classList.add('shaking');
            
            setTimeout(() => {
                // Update teks
                displayElement.textContent = randomText;
                hiddenElement.value = randomText;
                
                // Hapus efek animasi
                displayElement.parentElement.classList.remove('shaking');
                
                // Update hitungan karakter
                updateCharCount(randomText.length);
            }, 300);
        }
        
        // Fungsi untuk copy teks konfirmasi
        function copyConfirmationText() {
            const textToCopy = document.getElementById('randomConfirmationText').textContent;
            navigator.clipboard.writeText(textToCopy).then(function() {
                showNotification('Teks konfirmasi berhasil disalin ke clipboard', 'success');
                
                // Auto-paste ke input
                const inputField = document.getElementById('deleteAllConfirm');
                inputField.value = textToCopy;
                inputField.focus();
                inputField.dispatchEvent(new Event('input'));
            }).catch(function(err) {
                console.error('Gagal menyalin teks: ', err);
                // Fallback manual
                const inputField = document.getElementById('deleteAllConfirm');
                inputField.value = textToCopy;
                inputField.focus();
                inputField.dispatchEvent(new Event('input'));
                showNotification('Teks konfirmasi telah diisi', 'success');
            });
        }
        
        // Fungsi untuk membuat ulang teks konfirmasi
        function regenerateConfirmationText() {
            displayNewConfirmationText();
            
            // Reset input field
            const inputField = document.getElementById('deleteAllConfirm');
            inputField.value = '';
            inputField.classList.remove('is-valid', 'is-invalid');
            inputField.focus();
            
            // Disable submit button
            document.getElementById('deleteAllSubmitBtn').disabled = true;
            
            showNotification('Teks konfirmasi baru telah dibuat', 'success');
        }
        
        // Fungsi untuk update karakter count
        function updateCharCount(length) {
            const charCountElement = document.getElementById('charCount');
            if (charCountElement) {
                charCountElement.textContent = length;
                charCountElement.className = length >= 10 ? 'text-success' : 'text-danger';
            }
        }
        
        // Fungsi untuk setup modal hapus semua
        function setupDeleteAllModal() {
            const confirmInput = document.getElementById('deleteAllConfirm');
            const submitBtn = document.getElementById('deleteAllSubmitBtn');
            
            if (confirmInput && submitBtn) {
                // Generate teks konfirmasi acak saat modal dibuka
                $('#deleteAllModal').on('show.bs.modal', function() {
                    displayNewConfirmationText();
                    
                    // Reset input dan button state
                    confirmInput.value = '';
                    confirmInput.classList.remove('is-valid', 'is-invalid');
                    submitBtn.disabled = true;
                    
                    // Focus ke input field
                    setTimeout(() => confirmInput.focus(), 500);
                });
                
                // Validasi input real-time
                confirmInput.addEventListener('input', function() {
                    const inputText = this.value.trim();
                    const correctText = document.getElementById('correctConfirmationText').value;
                    const isCorrect = inputText === correctText;
                    
                    submitBtn.disabled = !isCorrect;
                    
                    if (inputText === '') {
                        this.classList.remove('is-valid', 'is-invalid');
                    } else if (isCorrect) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                    
                    // Update karakter count
                    updateCharCount(inputText.length);
                });
                
                // Tambahkan event listener untuk Enter key
                confirmInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !submitBtn.disabled) {
                        e.preventDefault();
                        submitBtn.click();
                    }
                });
                
                // Reset saat modal ditutup
                $('#deleteAllModal').on('hidden.bs.modal', function() {
                    confirmInput.value = '';
                    confirmInput.classList.remove('is-valid', 'is-invalid');
                    submitBtn.disabled = true;
                    updateCharCount(0);
                });
            }
        }
        
        // ========== AKHIR FUNGSI TEKS KONFIRMASI ACAK ==========
        
        $(document).ready(function() {
            // Auto-show notification if there's a message
            <?php if (isset($_SESSION['message'])): ?>
            showNotification("<?php echo $_SESSION['message']; ?>", 'success');
            <?php unset($_SESSION['message']); endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            showNotification("<?php echo $_SESSION['error']; ?>", 'danger');
            <?php unset($_SESSION['error']); endif; ?>
            
            // Saat modal detail dibuka
            $('.detail-btn').on('click', function() {
                const suggestionId = $(this).data('id');
                const currentStatus = $(this).data('status');
                
                // Jika status pending, update UI
                if (currentStatus === 'pending') {
                    // Simpan di localStorage bahwa pesan sudah dibaca
                    localStorage.setItem(`message_read_${suggestionId}`, 'true');
                    
                    // Update UI
                    removeBlurAfterRead(suggestionId);
                    updateStatsAfterRead();
                    
                    // Update form dalam modal
                    const modalId = `#detailModal${suggestionId}`;
                    const modalElement = $(modalId);
                    
                    // Hapus opsi pending dari select
                    const select = modalElement.find(`#statusSelect${suggestionId}`);
                    select.find('option[value="pending"]').remove();
                    select.val('read');
                    
                    // Tambahkan info text
                    if (!select.next('.status-info').length) {
                        select.after('<div class="status-info text-muted mt-1"><i class="fas fa-info-circle"></i> Status tidak bisa dikembalikan ke pending setelah dibaca.</div>');
                    }
                    
                    // Kirim update status ke server via AJAX
                    $.ajax({
                        url: 'update_suggestion_status.php',
                        type: 'POST',
                        data: {
                            action: 'auto_update',
                            suggestion_id: suggestionId
                        },
                        success: function(response) {
                            console.log('Status updated on server');
                        },
                        error: function() {
                            console.log('Failed to update status on server');
                        }
                    });
                }
            });
            
            // Cek pesan yang sudah dibaca sebelumnya
            checkPreviouslyReadMessages();
            
            // Setup delete all modal
            setupDeleteAllModal();
            
            // Validasi form delete all saat submit
            $('#deleteAllForm').on('submit', function(e) {
                const confirmInput = document.getElementById('deleteAllConfirm');
                const correctText = document.getElementById('correctConfirmationText').value;
                
                if (!confirmInput || confirmInput.value.trim() !== correctText) {
                    e.preventDefault();
                    showNotification('Harap ketik teks konfirmasi dengan tepat', 'danger');
                    
                    // Tambahkan efek visual pada teks konfirmasi
                    const displayElement = document.getElementById('randomConfirmationText').parentElement;
                    displayElement.classList.add('shaking');
                    setTimeout(() => displayElement.classList.remove('shaking'), 500);
                    
                    return false;
                }
                
                // Tampilkan loading state
                const submitBtn = document.getElementById('deleteAllSubmitBtn');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menghapus...';
                    submitBtn.disabled = true;
                    
                    // Kembalikan ke state semula jika submit dibatalkan
                    setTimeout(() => {
                        if (submitBtn.disabled) {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }
                    }, 3000);
                }
                
                // Konfirmasi akhir
                if (!confirm('APAKAH ANDA YAKIN 100%?\n\nTindakan ini akan menghapus SEMUA <?php echo $total_suggestions; ?> data kritik & saran.\n\nTINDAKAN INI TIDAK DAPAT DIBATALKAN!')) {
                    e.preventDefault();
                    // Reset button state
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-trash-alt me-2"></i>Ya, Hapus Semua Data';
                        submitBtn.disabled = false;
                    }
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>