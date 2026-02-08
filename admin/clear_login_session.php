<?php
session_start();
// Hapus session data yang digunakan untuk deteksi refresh login
unset(
    $_SESSION['last_login_check'],
    $_SESSION['last_login_time'], 
    $_SESSION['last_form_token'],
    $_SESSION['post_token']
);
echo "OK";
?>