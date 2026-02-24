<?php
require_once 'config/database.php';
require_once 'config/helpers.php';

$database = new Database();
$db = $database->getConnection();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    // Validasi
    if (empty($name)) {
        $response['message'] = 'Nama wajib diisi';
    } elseif (strlen($name) < 2) {
        $response['message'] = 'Nama minimal 2 karakter';
    } elseif (empty($message)) {
        $response['message'] = 'Pesan wajib diisi';
    } elseif (strlen($message) < 10) {
        $response['message'] = 'Pesan minimal 10 karakter';
    } else {
        try {
            // Clean inputs
            $name = htmlspecialchars($name);
            $email = filter_var($email, FILTER_SANITIZE_EMAIL);
            $message = htmlspecialchars($message);
            
            // Get IP and user agent
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
            
            $stmt = $db->prepare("
                INSERT INTO suggestions (name, email, message, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            if ($stmt->execute([$name, $email, $message, $ip_address, $user_agent])) {
                $response['success'] = true;
                $response['message'] = 'Terima kasih atas kritik dan saran Anda! Pesan telah berhasil dikirim.';
            } else {
                $response['message'] = 'Gagal mengirim pesan. Silakan coba lagi.';
            }
        } catch (Exception $e) {
            error_log("Error submitting suggestion: " . $e->getMessage());
            $response['message'] = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
        }
    }
} else {
    $response['message'] = 'Metode request tidak valid';
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);