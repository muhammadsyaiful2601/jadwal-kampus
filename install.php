<?php
// File setup database dengan data contoh jadwal
echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Database - Jadwal Kuliah</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; background: #f5f7fa; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #4a6491; background: #f8f9fa; }
        code { background: #e9ecef; padding: 2px 5px; border-radius: 3px; }
        .btn { display: inline-block; padding: 10px 20px; background: #4a6491; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn:hover { background: #3a5479; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-database"></i> Setup Database - Jadwal Kuliah</h1>';

$host = 'localhost';
$username = 'root';
$password = '';
$database_name = 'jadwal_kuliah';

try {
    echo '<div class="step">Step 1: Membuat koneksi ke MySQL...</div>';
    
    // Buat koneksi tanpa database
    $conn = new PDO("mysql:host=$host", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo '<div class="success"><i class="fas fa-check-circle"></i> Koneksi ke MySQL berhasil</div>';
    
    // Buat database jika belum ada
    $sql = "CREATE DATABASE IF NOT EXISTS $database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $conn->exec($sql);
    
    echo '<div class="success"><i class="fas fa-check-circle"></i> Database ' . $database_name . ' berhasil dibuat</div>';
    
    // Gunakan database
    $conn->exec("USE $database_name");
    echo '<div class="success"><i class="fas fa-check-circle"></i> Menggunakan database ' . $database_name . '</div>';
    
    echo '<div class="step">Step 2: Membuat tabel-tabel...</div>';
    
    // Buat tabel-tabel
    $tables = [
        "users" => "CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            role ENUM('superadmin', 'admin') DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            is_active BOOLEAN DEFAULT TRUE
        )",
        
        "settings" => "CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(50) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        
        "schedules" => "CREATE TABLE IF NOT EXISTS schedules (
            id INT PRIMARY KEY AUTO_INCREMENT,
            kelas VARCHAR(10) NOT NULL,
            hari ENUM('SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT') NOT NULL,
            jam_ke INT NOT NULL,
            waktu VARCHAR(50) NOT NULL,
            mata_kuliah VARCHAR(100) NOT NULL,
            dosen TEXT NOT NULL,
            ruang VARCHAR(50) NOT NULL,
            semester ENUM('GANJIL', 'GENAP') DEFAULT 'GANJIL',
            tahun_akademik VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_kelas (kelas),
            INDEX idx_hari (hari)
        )",
        
        "rooms" => "CREATE TABLE IF NOT EXISTS rooms (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nama_ruang VARCHAR(50) UNIQUE NOT NULL,
            foto_path VARCHAR(255),
            deskripsi TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        
        "activity_logs" => "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    ];
    
    foreach($tables as $tableName => $sql) {
        $conn->exec($sql);
        echo '<div class="success"><i class="fas fa-check-circle"></i> Tabel ' . $tableName . ' berhasil dibuat</div>';
    }
    
    echo '<div class="step">Step 3: Mengisi data awal...</div>';
    
    // Insert default settings
    $settings_sql = "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
        ('tahun_akademik', '2025/2026'),
        ('institusi_nama', 'Politeknik Negeri Padang'),
        ('institusi_lokasi', 'PSDKU Tanah Datar'),
        ('program_studi', 'D3 Sistem Informasi'),
        ('semester_aktif', 'GANJIL'),
        ('superadmin_registered', '0')";
    
    $conn->exec($settings_sql);
    echo '<div class="success"><i class="fas fa-check-circle"></i> Data settings berhasil diisi</div>';
    
    // Insert sample room data
    $rooms_sql = "INSERT IGNORE INTO rooms (nama_ruang, deskripsi) VALUES 
        ('Ruang Serbaguna', 'Ruang serbaguna berada di tengah, dilengkapi dengan Smart Tv, 2AC. Digunakan untuk kuliah teori dan presentasi.'),
        ('Labor Jaringan', 'Laboratorium jaringan komputer dengan 30 unit PC, switch, router, dan perangkat jaringan lainnya. Dilengkapi dengan AC dan akses internet cepat.'),
        ('Labor Multimedia', 'Labor multimedia (mulmed) terletak di sebelah kanan dari pintu masuk utama di lengkapi dengan 2 AC, Smart TV dan komputer dengan spesifikasi tinggi.'),
        ('Labor Perakitan', 'Laboratorium perakitan dan perawatan komputer dengan berbagai komponen hardware dan tools untuk praktikum perakitan PC.')";
    
    $conn->exec($rooms_sql);
    echo '<div class="success"><i class="fas fa-check-circle"></i> Data ruangan contoh berhasil diisi</div>';
    
    // Insert sample schedule data untuk testing
    echo '<div class="step">Step 4: Menambahkan data jadwal contoh...</div>';
    
    $sampleSchedules = [
        // Kelas 1A
        "('1A', 'SENIN', 3, '09.10 - 10.00', 'Algoritma Pemrograman (P)', 'Novi Efendi,S.Pd.,M.Kom', 'Labor Multimedia', 'GANJIL', '2025/2026')",
        "('1A', 'SENIN', 4, '10.00 - 10.50', 'Algoritma Pemrograman (P)', 'Novi Efendi,S.Pd.,M.Kom', 'Labor Multimedia', 'GANJIL', '2025/2026')",
        "('1A', 'SELASA', 3, '09.10 - 10.00', 'Matematika', 'Dedi Mardianto,Spd.,M.Pd', 'Ruang Serbaguna', 'GANJIL', '2025/2026')",
        "('1A', 'RABU', 5, '10.50 - 11.40', 'Pengantar Teknologi Informasi', 'Widya Wahyuni,S.Pd.,M.Kom', 'Ruang Serbaguna', 'GANJIL', '2025/2026')",
        
        // Kelas 1B
        "('1B', 'SENIN', 4, '10.00 - 10.50', 'Agama', 'Sri Handayani,S.Pd.,M.Pd', 'Ruang Sipil', 'GANJIL', '2025/2026')",
        "('1B', 'SELASA', 1, '07.30 - 08.20', 'Matematika', 'Dedi Mardianto,Spd.,M.Pd', 'Ruang Serbaguna', 'GANJIL', '2025/2026')",
        "('1B', 'RABU', 3, '09.10 - 10.00', 'Keterampilan Komputer (P)', 'Ideva Gaputra,S.Kom.,M.kom', 'Labor Jaringan', 'GANJIL', '2025/2026')",
        
        // Kelas 2A
        "('2A', 'SENIN', 1, '07.30 - 08.20', 'Jaringan Komputer (Teori)', 'Ardi Syawaldipa, S.Kom.,M.T', 'Labor Jaringan', 'GANJIL', '2025/2026')",
        "('2A', 'SELASA', 1, '07.30 - 08.20', 'Analisa dan Perancangan Sistem Informasi', 'Novi Efendi,S.Pd.,M.Kom', 'Labor Jaringan', 'GANJIL', '2025/2026')",
        "('2A', 'RABU', 4, '10.00 - 10.50', 'Web Dinamis (T)', 'Riyang Gumelta,S.Kom.,M.Kom', 'Labor Jaringan', 'GANJIL', '2025/2026')",
        
        // Kelas 2B
        "('2B', 'SENIN', 1, '07.30 - 08.20', 'Pemrograman Mobile (T)', 'Ulia Ulfa,S.Kom.,M.Kom', 'Labor Multimedia', 'GANJIL', '2025/2026')",
        "('2B', 'SELASA', 1, '07.30 - 08.20', 'Analisis dan Perancangan Sistem Informasi', 'Riyang Gumelta,S.Kom.,M.kom', 'Ruang Sipil', 'GANJIL', '2025/2026')",
        "('2B', 'RABU', 1, '07.30 - 08.20', 'Web Dinamis (T)', 'Riyang Gumelta,S.Kom.,M.Kom', 'Labor Jaringan', 'GANJIL', '2025/2026')",
        
        // Kelas 3
        "('3', 'SENIN', 3, '09.10 - 10.00', 'Etika Profesi', 'Riyang Gumelta,S.Kom.,M.Kom', 'Labor Perakitan', 'GANJIL', '2025/2026')",
        "('3', 'SELASA', 1, '07.30 - 08.20', 'Manajemen Proyek SI (T)', 'M.IBRAHIM NASUTION,S.Pd.,M.Kom', 'Labor Perakitan', 'GANJIL', '2025/2026')",
        "('3', 'RABU', 2, '08.20 - 09.10', 'Pemrograman Mobile Lanjut (P)', 'ULIA ULFA,S.Kom.,M.Kom', 'Labor Multimedia', 'GANJIL', '2025/2026')"
    ];
    
    $schedule_sql = "INSERT IGNORE INTO schedules (kelas, hari, jam_ke, waktu, mata_kuliah, dosen, ruang, semester, tahun_akademik) VALUES " . implode(',', $sampleSchedules);
    $conn->exec($schedule_sql);
    echo '<div class="success"><i class="fas fa-check-circle"></i> Data jadwal contoh berhasil ditambahkan (16 jadwal)</div>';
    
    echo '<div class="step">Step 5: Setup selesai!</div>';
    
    echo '<div class="success" style="font-size: 1.2em;">
        <strong><i class="fas fa-party-horn"></i> 🎉 Setup berhasil diselesaikan!</strong>
        <p>Database telah diisi dengan data contoh.</p>
    </div>';
    
    echo '<div class="warning">
        <strong><i class="fas fa-clipboard-list"></i> 📋 Langkah selanjutnya:</strong>
        <ol>
            <li>Hapus file <code>install.php</code> untuk keamanan</li>
            <li>Buka <a href="admin/register_superadmin.php">admin/register_superadmin.php</a> untuk mendaftarkan Super Admin pertama</li>
            <li>Setelah itu, login di <a href="admin/login.php">admin/login.php</a></li>
            <li>Kunjungi <a href="index.php">halaman utama</a> untuk melihat jadwal</li>
        </ol>
    </div>';
    
    echo '<div class="warning">
        <strong><i class="fas fa-exclamation-triangle"></i> ⚠️ Penting:</strong>
        <ul>
            <li>File <code>install.php</code> harus dihapus setelah setup selesai</li>
            <li>Pastikan folder <code>assets/uploads/</code> memiliki permission writeable (chmod 755)</li>
            <li>Ubah konfigurasi database di <code>config/database.php</code> sesuai server Anda</li>
        </ul>
    </div>';
    
    echo '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
        <a href="index.php" class="btn btn-success"><i class="fas fa-home"></i> Lihat Halaman Utama</a>
        <a href="admin/register_superadmin.php" class="btn"><i class="fas fa-user-shield"></i> Daftar Super Admin</a>
        <button onclick="if(confirm(\'Hapus file install.php?\\n\\nFile ini harus dihapus untuk keamanan.\')) { window.location.href=\'?delete=1\'; }" class="btn btn-danger">
            <i class="fas fa-trash"></i> Hapus File Install
        </button>
    </div>';
    
    // Hapus file install.php jika diminta
    if (isset($_GET['delete']) && $_GET['delete'] == '1') {
        if (unlink(__FILE__)) {
            echo '<script>alert("File install.php berhasil dihapus!"); window.location.href="index.php";</script>';
        } else {
            echo '<div class="error">Gagal menghapus file install.php. Harap hapus manual.</div>';
        }
    }
    
} catch(PDOException $e) {
    echo '<div class="error">
        <strong><i class="fas fa-times-circle"></i> ❌ Error:</strong> ' . $e->getMessage() . '
    </div>';
    
    echo '<div class="warning">
        <strong><i class="fas fa-tools"></i> 🔧 Troubleshooting:</strong>
        <ul>
            <li>Pastikan MySQL server berjalan</li>
            <li>Periksa username dan password MySQL di file <code>config/database.php</code></li>
            <li>Pastikan user MySQL memiliki hak akses untuk membuat database</li>
        </ul>
    </div>';
}

echo '</div></body></html>';
?>