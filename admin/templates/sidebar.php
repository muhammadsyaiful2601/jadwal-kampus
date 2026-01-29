<?php
// Sidebar untuk desktop
?>
<div class="sidebar d-none d-md-block">
    <div class="p-4">
        <h3 class="mb-4"><i class="fas fa-calendar-alt"></i> Admin Panel</h3>
        <div class="user-info mb-4">
            <div class="d-flex align-items-center">
                <div class="user-avatar me-3">
                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                </div>
                <div>
                    <h6 class="mb-0"><?php echo htmlspecialchars($_SESSION['username']); ?></h6>
                    <small class="text-muted"><?php echo ucfirst($_SESSION['role']); ?></small>
                </div>
            </div>
        </div>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_schedule.php' ? 'active' : ''; ?>" href="manage_schedule.php">
            <i class="fas fa-calendar"></i> Kelola Jadwal
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_rooms.php' ? 'active' : ''; ?>" href="manage_rooms.php">
            <i class="fas fa-door-open"></i> Kelola Ruangan
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_semester.php' ? 'active' : ''; ?>" href="manage_semester.php">
            <i class="fas fa-calendar-alt"></i> Kelola Semester
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_settings.php' ? 'active' : ''; ?>" href="manage_settings.php">
            <i class="fas fa-cog"></i> Pengaturan
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'active' : ''; ?>" href="manage_users.php">
            <i class="fas fa-users"></i> Kelola Admin
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
            <i class="fas fa-chart-bar"></i> Laporan
        </a>
        <div class="mt-4"></div>
        <a class="nav-link" href="profile.php">
            <i class="fas fa-user"></i> Profile
        </a>
        <a class="nav-link" href="logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div>

<!-- Mobile Sidebar (hidden by default) -->
<div class="sidebar d-md-none mobile-sidebar" style="display: none; position: fixed; top: 0; left: 0; height: 100%; z-index: 1001; overflow-y: auto;">
    <div class="p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0"><i class="fas fa-calendar-alt"></i> Admin</h3>
            <button class="btn btn-sm btn-light" onclick="toggleMobileSidebar()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="user-info mb-4">
            <div class="d-flex align-items-center">
                <div class="user-avatar me-3">
                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                </div>
                <div>
                    <h6 class="mb-0"><?php echo htmlspecialchars($_SESSION['username']); ?></h6>
                    <small class="text-muted"><?php echo ucfirst($_SESSION['role']); ?></small>
                </div>
            </div>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_schedule.php' ? 'active' : ''; ?>" href="manage_schedule.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-calendar"></i> Kelola Jadwal
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_rooms.php' ? 'active' : ''; ?>" href="manage_rooms.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-door-open"></i> Kelola Ruangan
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_semester.php' ? 'active' : ''; ?>" href="manage_semester.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-calendar-alt"></i> Kelola Semester
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_settings.php' ? 'active' : ''; ?>" href="manage_settings.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-cog"></i> Pengaturan
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'active' : ''; ?>" href="manage_users.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-users"></i> Kelola Admin
            </a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-chart-bar"></i> Laporan
            </a>
            <div class="mt-4"></div>
            <a class="nav-link" href="profile.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-user"></i> Profile
            </a>
            <a class="nav-link" href="logout.php" onclick="toggleMobileSidebar()">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>
</div>