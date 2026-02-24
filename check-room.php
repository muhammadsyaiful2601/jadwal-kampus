<?php
// check-room.php - API untuk mendapatkan detail ruangan
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$roomName = $_GET['room'] ?? '';

if (empty($roomName)) {
    echo json_encode([
        'success' => false,
        'message' => 'Nama ruangan tidak diberikan'
    ]);
    exit();
}

try {
    $query = "SELECT * FROM rooms WHERE nama_ruang = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$roomName]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($room) {
        echo json_encode([
            'success' => true,
            'room' => $room
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Ruangan tidak ditemukan'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan: ' . $e->getMessage()
    ]);
}
?>