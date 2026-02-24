<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

$database = new Database();
$db = $database->getConnection();

$response = ['conflict' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kelas = $_POST['kelas'] ?? '';
    $hari = $_POST['hari'] ?? '';
    $jam_ke = $_POST['jam_ke'] ?? '';
    $semester = $_POST['semester'] ?? '';
    $tahun_akademik = $_POST['tahun_akademik'] ?? '';
    $dosen = $_POST['dosen'] ?? '';
    $ruang = $_POST['ruang'] ?? '';
    $exclude_id = $_POST['exclude_id'] ?? null;
    
    // Cek bentrok dengan kelas lain
    $query = "SELECT * FROM schedules 
              WHERE kelas = ? 
              AND hari = ? 
              AND jam_ke = ? 
              AND semester = ?
              AND tahun_akademik = ?";
    
    $params = [$kelas, $hari, $jam_ke, $semester, $tahun_akademik];
    
    if ($exclude_id) {
        $query .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $response['conflict'] = true;
        $response['message'] = "Kelas {$kelas} sudah memiliki jadwal {$existing['mata_kuliah']} dengan dosen {$existing['dosen']} pada hari {$hari} jam ke-{$jam_ke}";
    }
    
    // Cek bentrok dosen
    if (!empty($dosen)) {
        $query_dosen = "SELECT * FROM schedules 
                       WHERE dosen = ? 
                       AND hari = ? 
                       AND jam_ke = ? 
                       AND semester = ?
                       AND tahun_akademik = ?";
        
        $params_dosen = [$dosen, $hari, $jam_ke, $semester, $tahun_akademik];
        
        if ($exclude_id) {
            $query_dosen .= " AND id != ?";
            $params_dosen[] = $exclude_id;
        }
        
        $stmt_dosen = $db->prepare($query_dosen);
        $stmt_dosen->execute($params_dosen);
        $existing_dosen = $stmt_dosen->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_dosen) {
            $response['conflict'] = true;
            $response['message'] = "Dosen {$dosen} sudah mengajar {$existing_dosen['mata_kuliah']} di kelas {$existing_dosen['kelas']} pada hari {$hari} jam ke-{$jam_ke}";
        }
    }
    
    // Cek bentrok ruangan
    if (!empty($ruang)) {
        $query_ruang = "SELECT * FROM schedules 
                       WHERE ruang = ? 
                       AND hari = ? 
                       AND jam_ke = ? 
                       AND semester = ?
                       AND tahun_akademik = ?";
        
        $params_ruang = [$ruang, $hari, $jam_ke, $semester, $tahun_akademik];
        
        if ($exclude_id) {
            $query_ruang .= " AND id != ?";
            $params_ruang[] = $exclude_id;
        }
        
        $stmt_ruang = $db->prepare($query_ruang);
        $stmt_ruang->execute($params_ruang);
        $existing_ruang = $stmt_ruang->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_ruang) {
            $response['conflict'] = true;
            $response['message'] = "Ruangan {$ruang} sudah digunakan oleh kelas {$existing_ruang['kelas']} untuk mata kuliah {$existing_ruang['mata_kuliah']} pada hari {$hari} jam ke-{$jam_ke}";
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>