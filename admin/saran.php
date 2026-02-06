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
        $response = $_POST['response'] ?? '';
        
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
                    $response,
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
        // Hanya superadmin yang bisa menghapus
        try {
            $delete_query = "DELETE FROM suggestions WHERE id = ?";
            $stmt_delete = $db->prepare($delete_query);
            if ($stmt_delete->execute([$suggestion_id])) {
                $_SESSION['message'] = "Saran berhasil dihapus";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Gagal menghapus saran: " . $e->getMessage();
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
                        <h2 class="mb-0"><?php echo $stats['total']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Pending</h6>
                        <h2 class="mb-0 text-warning"><?php echo $stats['pending']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Sudah Dibaca</h6>
                        <h2 class="mb-0 text-info"><?php echo $stats['read_count']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Ditanggapi</h6>
                        <h2 class="mb-0 text-success"><?php echo $stats['responded']; ?></h2>
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
                            <?php foreach ($suggestions as $index => $suggestion): ?>
                            <tr>
                                <td><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($suggestion['name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($suggestion['email'] ?: 'Tidak ada email'); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="message-preview">
                                        <?php 
                                        $message = htmlspecialchars($suggestion['message']);
                                        echo strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message;
                                        ?>
                                    </div>
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
                                    <span class="badge <?php echo $badge_class; ?>">
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
                                        <button type="button" class="btn btn-outline-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#detailModal<?php echo $suggestion['id']; ?>">
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
                                                                <form method="POST" class="mb-3">
                                                                    <input type="hidden" name="suggestion_id" value="<?php echo $suggestion['id']; ?>">
                                                                    <input type="hidden" name="action" value="update_status">
                                                                    <div class="mb-3">
                                                                        <select name="status" class="form-select">
                                                                            <option value="pending" <?php echo $suggestion['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                                            <option value="read" <?php echo $suggestion['status'] === 'read' ? 'selected' : ''; ?>>Sudah Dibaca</option>
                                                                            <option value="responded" <?php echo $suggestion['status'] === 'responded' ? 'selected' : ''; ?>>Ditanggapi</option>
                                                                        </select>
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

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="suggestion_id" id="deleteSuggestionId">
        <input type="hidden" name="action" value="delete">
    </form>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <script>
        function confirmDelete(suggestionId) {
            if (confirm('Apakah Anda yakin ingin menghapus kritik dan saran ini? Tindakan ini tidak dapat dibatalkan.')) {
                document.getElementById('deleteSuggestionId').value = suggestionId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        $(document).ready(function() {
            // Auto-show modal if there's a message
            <?php if (isset($_SESSION['message'])): ?>
            alert("<?php echo $_SESSION['message']; ?>");
            <?php unset($_SESSION['message']); endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            alert("<?php echo $_SESSION['error']; ?>");
            <?php unset($_SESSION['error']); endif; ?>
        });
    </script>
</body>
</html>