<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

$type = $_GET['type'] ?? 'jadwal';

switch($type) {
    case 'jadwal':
        $query = "SELECT * FROM schedules ORDER BY kelas, hari, jam_ke";
        $filename = "jadwal_kuliah_" . date('Y-m-d') . ".csv";
        $headers = ['Kelas', 'Hari', 'Jam Ke', 'Waktu', 'Mata Kuliah', 'Dosen', 'Ruang', 'Semester', 'Tahun Akademik'];
        break;
        
    case 'ruangan':
        $query = "SELECT * FROM rooms ORDER BY nama_ruang";
        $filename = "ruangan_" . date('Y-m-d') . ".csv";
        $headers = ['Nama Ruangan', 'Deskripsi', 'Tanggal Dibuat'];
        break;
        
    case 'aktivitas':
        $query = "SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC";
        $filename = "aktivitas_" . date('Y-m-d') . ".csv";
        $headers = ['Waktu', 'Username', 'Aksi', 'Deskripsi', 'IP Address'];
        break;
        
    default:
        redirect('reports.php', 'Tipe ekspor tidak valid!');
}

$stmt = $db->prepare($query);
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set header untuk download file CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output file CSV
$output = fopen('php://output', 'w');
fputcsv($output, $headers);

foreach($data as $row) {
    if($type == 'jadwal') {
        fputcsv($output, [
            $row['kelas'],
            $row['hari'],
            $row['jam_ke'],
            $row['waktu'],
            $row['mata_kuliah'],
            $row['dosen'],
            $row['ruang'],
            $row['semester'],
            $row['tahun_akademik']
        ]);
    } elseif($type == 'ruangan') {
        fputcsv($output, [
            $row['nama_ruang'],
            $row['deskripsi'],
            $row['created_at']
        ]);
    } elseif($type == 'aktivitas') {
        fputcsv($output, [
            $row['created_at'],
            $row['username'],
            $row['action'],
            $row['description'],
            $row['ip_address']
        ]);
    }
}

fclose($output);
exit;