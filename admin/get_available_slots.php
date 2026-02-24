<?php
session_start();
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$response = ['available_slots' => [], 'suggested_slot' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kelas = $_POST['kelas'] ?? '';
    $hari = $_POST['hari'] ?? '';
    $semester = $_POST['semester'] ?? '';
    $tahun_akademik = $_POST['tahun_akademik'] ?? '';
    $suggest = isset($_POST['suggest']) ? true : false;
    
    // Ambil semua jam ke yang sudah terpakai
    $query = "SELECT jam_ke FROM schedules 
              WHERE kelas = ? 
              AND hari = ? 
              AND semester = ?
              AND tahun_akademik = ?
              ORDER BY jam_ke";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$kelas, $hari, $semester, $tahun_akademik]);
    $occupied_slots = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Jam ke yang tersedia (1-10)
    $all_slots = range(1, 10);
    $available_slots = array_diff($all_slots, $occupied_slots);
    
    $response['available_slots'] = array_values($available_slots);
    
    // Jika diminta saran, berikan jam ke terdekat yang tersedia
    if ($suggest && !empty($available_slots)) {
        $response['suggested_slot'] = min($available_slots);
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>