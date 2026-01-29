<?php
session_start();

if(isset($_SESSION['user_id'])) {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();

    // Cek apakah user masih ada di tabel users
    $check = $db->prepare("SELECT id FROM users WHERE id = ?");
    $check->execute([$_SESSION['user_id']]);
    $validUser = $check->fetchColumn();

    $user_id = $validUser ? $_SESSION['user_id'] : null;

    $query = "INSERT INTO activity_logs (user_id, action, ip_address, user_agent) 
              VALUES (:user_id, 'Logout', :ip, :agent)";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
    $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);

    $stmt->execute();
}

// Bersihkan session
session_unset();
session_destroy();
header('Location: login.php');
exit();
?>
