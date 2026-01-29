<?php
require_once '../config/database.php';
require_once '../config/helpers.php';

$database = new Database();
$db = $database->getConnection();

// Ambil semester aktif
$activeSemester = getActiveSemester($db);
$tahun_akademik = $activeSemester['tahun_akademik'];
$semester_aktif = $activeSemester['semester'];

// Cari jadwal yang sedang berlangsung
$jam_sekarang = date('H:i');
$hari_sekarang = date('N');
$hari_map = [
    1 => 'SENIN',
    2 => 'SELASA',
    3 => 'RABU',
    4 => 'KAMIS',
    5 => 'JUMAT'
];
$hari_sekarang_teks = $hari_map[$hari_sekarang] ?? null;

header('Content-Type: application/json');

if ($hari_sekarang_teks) {
    // Cari semua jadwal hari ini
    $query = "SELECT * FROM schedules 
              WHERE hari = ? AND semester = ? AND tahun_akademik = ? 
              ORDER BY jam_ke";
    $stmt = $db->prepare($query);
    $stmt->execute([$hari_sekarang_teks, $semester_aktif, $tahun_akademik]);
    $jadwal_hari_ini = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $jadwal_berlangsung = null;
    $jadwal_selanjutnya = null;
    
    // Cari yang sedang berlangsung dan akan berlangsung
    $found_current = false;
    foreach ($jadwal_hari_ini as $item) {
        if (strpos($item['waktu'], ' - ') !== false) {
            list($waktu_mulai, $waktu_selesai) = explode(' - ', $item['waktu']);
            
            // Cek apakah sedang berlangsung
            if ($jam_sekarang >= $waktu_mulai && $jam_sekarang <= $waktu_selesai) {
                $jadwal_berlangsung = $item;
                $found_current = true;
                break;
            }
            // Cek untuk jadwal selanjutnya (hanya jika belum ada yang berlangsung)
            elseif (!$found_current && $jam_sekarang < $waktu_mulai) {
                if (!$jadwal_selanjutnya || $waktu_mulai < $jadwal_selanjutnya_waktu) {
                    $jadwal_selanjutnya = $item;
                    $jadwal_selanjutnya_waktu = $waktu_mulai;
                }
            }
        }
    }
    
    if ($jadwal_berlangsung) {
        echo json_encode([
            'exists' => true,
            'type' => 'berlangsung',
            ...$jadwal_berlangsung
        ]);
    } elseif ($jadwal_selanjutnya) {
        echo json_encode([
            'exists' => true,
            'type' => 'selanjutnya',
            ...$jadwal_selanjutnya
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
} else {
    echo json_encode(['exists' => false]);
}