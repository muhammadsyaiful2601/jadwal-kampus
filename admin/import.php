<?php
session_start();
require_once '../config/database.php';
require_once '../config/helpers.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

if(isset($_POST['import'])) {
    if(isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        
        // Baca file CSV
        if(($handle = fopen($file, "r")) !== FALSE) {
            $row = 0;
            $imported = 0;
            
            while(($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Skip header
                if($row == 0) {
                    $row++;
                    continue;
                }
                
                // Validasi data
                if(count($data) >= 9) {
                    $query = "INSERT INTO schedules (kelas, hari, jam_ke, waktu, mata_kuliah, dosen, ruang, semester, tahun_akademik) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($query);
                    
                    try {
                        $stmt->execute([
                            $data[0], // kelas
                            $data[1], // hari
                            $data[2], // jam_ke
                            $data[3], // waktu
                            $data[4], // mata_kuliah
                            $data[5], // dosen
                            $data[6], // ruang
                            $data[7], // semester
                            $data[8]  // tahun_akademik
                        ]);
                        $imported++;
                    } catch(Exception $e) {
                        $error .= "Baris $row: " . $e->getMessage() . "<br>";
                    }
                } else {
                    $error .= "Baris $row: Format data tidak valid<br>";
                }
                $row++;
            }
            fclose($handle);
            
            if($imported > 0) {
                $success = "Berhasil mengimpor $imported data jadwal!";
                logActivity($db, $_SESSION['user_id'], 'Import Jadwal', "Mengimpor $imported data dari CSV");
            }
        } else {
            $error = "Gagal membuka file CSV!";
        }
    } else {
        $error = "Silakan pilih file CSV yang valid!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Jadwal - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include 'templates/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'templates/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Import Jadwal dari CSV</h1>
                </div>
                
                <?php if($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Import File CSV</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Pilih File CSV</label>
                                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                                        <small class="text-muted">Format CSV harus sesuai dengan template di bawah</small>
                                    </div>
                                    <button type="submit" name="import" class="btn btn-primary">
                                        <i class="fas fa-upload me-2"></i>Import Data
                                    </button>
                                    <a href="manage_schedule.php" class="btn btn-secondary">Kembali</a>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Template CSV</h5>
                            </div>
                            <div class="card-body">
                                <p>Format file CSV harus seperti berikut:</p>
                                <pre class="bg-light p-3">
kelas,hari,jam_ke,waktu,mata_kuliah,dosen,ruang,semester,tahun_akademik
1A,SENIN,1,08:00 - 09:40,Pemrograman Web,Dr. Ahmad,Lab 1,GANJIL,2024/2025
1A,SENIN,2,09:50 - 11:30,Basis Data,Prof. Budi,Ruang 2,GANJIL,2024/2025
                                </pre>
                                <a href="template_jadwal.csv" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download me-1"></i>Download Template
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Petunjuk Import</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li>Download template CSV di atas</li>
                            <li>Isi data jadwal sesuai format</li>
                            <li>Pastikan data sesuai dengan aturan:
                                <ul>
                                    <li>Hari: SENIN, SELASA, RABU, KAMIS, JUMAT</li>
                                    <li>Jam ke: Angka 1-10</li>
                                    <li>Semester: GANJIL atau GENAP</li>
                                </ul>
                            </li>
                            <li>Upload file CSV melalui form di atas</li>
                            <li>Sistem akan memvalidasi dan mengimpor data secara otomatis</li>
                        </ol>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>