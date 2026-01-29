<?php
// File ini di-include di index.php
if (!isset($item)) return;
?>
<div class="card schedule-card h-100" data-schedule='<?php echo json_encode($item); ?>'>
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <span class="badge bg-primary"><?php echo $item['waktu']; ?></span>
            <span class="badge bg-secondary ms-1">Jam ke-<?php echo $item['jam_ke']; ?></span>
        </div>
        <?php if ($tampil_semua_hari): ?>
        <span class="badge bg-info"><?php echo $item['hari']; ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <h5 class="card-title text-truncate" title="<?php echo htmlspecialchars($item['mata_kuliah']); ?>">
            <?php echo $item['mata_kuliah']; ?>
        </h5>
        <div class="schedule-info">
            <p class="mb-2">
                <i class="fas fa-user-tie me-2 text-primary"></i>
                <span class="text-truncate d-inline-block" style="max-width: 200px;" 
                      title="<?php echo htmlspecialchars($item['dosen']); ?>">
                    <?php echo $item['dosen']; ?>
                </span>
            </p>
            <p class="mb-2">
                <i class="fas fa-door-open me-2 text-success"></i>
                Ruang: <?php echo $item['ruang']; ?>
            </p>
            <?php if ($tampil_semua_kelas): ?>
            <p class="mb-0">
                <i class="fas fa-users me-2 text-warning"></i>
                Kelas: <?php echo $item['kelas']; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer bg-transparent">
        <button class="btn btn-sm btn-outline-primary w-100 btn-detail" 
                data-schedule='<?php echo json_encode($item); ?>'>
            <i class="fas fa-info-circle me-2"></i> Detail
        </button>
    </div>
</div>