<?php
// test-photo.php
$photo_path = 'uploads/rooms/room_1_1769660461.png';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Foto</title>
</head>
<body>
    <h1>Test Path Foto</h1>
    <p>Path: <?php echo $photo_path; ?></p>
    <p>File exists: <?php echo file_exists($photo_path) ? 'YA' : 'TIDAK'; ?></p>
    <img src="<?php echo $photo_path; ?>" alt="Test Foto" style="max-width: 500px;">
</body>
</html>