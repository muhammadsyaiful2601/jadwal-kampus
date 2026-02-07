<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

$database = new Database();
$db = $database->getConnection();

$response = ['success' => false, 'message' => '', 'new_status' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $suggestion_id = (int)($_POST['suggestion_id'] ?? 0);
    
    if ($action === 'auto_update' && $suggestion_id > 0) {
        // Pastikan user sudah login
        if (!isset($_SESSION['user_id'])) {
            $response['message'] = 'Anda harus login';
            echo json_encode($response);
            exit();
        }
        
        try {
            // Mulai transaksi untuk konsistensi data
            $db->beginTransaction();
            
            // Ambil status saat ini
            $stmt = $db->prepare("SELECT status, name FROM suggestions WHERE id = ?");
            $stmt->execute([$suggestion_id]);
            $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$suggestion) {
                $response['message'] = 'Saran tidak ditemukan';
                $db->rollBack();
                echo json_encode($response);
                exit();
            }
            
            // Hanya update jika status masih pending
            if ($suggestion['status'] === 'pending') {
                $update_query = "UPDATE suggestions SET 
                                status = 'read',
                                responded_by = ?,
                                responded_at = NOW()
                                WHERE id = ?";
                
                $stmt_update = $db->prepare($update_query);
                if ($stmt_update->execute([$_SESSION['user_id'], $suggestion_id])) {
                    // Commit transaksi
                    $db->commit();
                    
                    $response['success'] = true;
                    $response['message'] = "Status berhasil diperbarui menjadi 'read'";
                    $response['new_status'] = 'read';
                    $response['suggestion_id'] = $suggestion_id;
                    
                    // Log activity
                    logActivity($db, $_SESSION['user_id'], 'read_suggestion', 
                               "Membaca saran #{$suggestion_id} dari {$suggestion['name']}");
                } else {
                    $db->rollBack();
                    $response['message'] = "Gagal memperbarui status";
                }
            } else {
                $response['message'] = "Status sudah diubah sebelumnya";
                $response['current_status'] = $suggestion['status'];
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error updating suggestion status: " . $e->getMessage());
            $response['message'] = "Gagal memperbarui status: " . $e->getMessage();
        }
    } else {
        $response['message'] = 'Aksi tidak valid';
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>