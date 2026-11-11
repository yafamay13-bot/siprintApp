# SIPRINT Kabupaten Cirebon

Aplikasi internal pengiriman dan pencetakan dokumen untuk admin dan pegawai. SIPRINT berjalan sebagai aplikasi PHP di Apache dengan MySQL; JavaScript dipakai untuk peningkatan interaksi antarmuka. Node.js, Express, dan EJS tidak diperlukan untuk menjalankan aplikasi. Berkas sumber Node/EJS lama tetap ada sebagai referensi, tetapi tidak dipakai oleh entry point PHP.

## Fitur

- Login dengan hash kata sandi bcrypt, sesi PHP aman, serta pembatasan percobaan masuk.
- Kontrol akses backend untuk peran `admin` dan `pegawai`; sesi dicabut ketika akun dinonaktifkan atau kata sandi berubah.
- Pegawai dapat mengirim PDF, melihat status, dan hanya mengakses dokumennya sendiri.
- Pratinjau privat di browser, cetak, unduh, dan penghapusan dokumen oleh admin.
- Admin mengelola akun pegawai dan aktivasi akun, antrean/status proses, alasan penolakan, dan audit log.
- Profil, perubahan kata sandi, foto profil, dan notifikasi.
- Token CSRF pada semua formulir yang mengubah data, validasi input, PDF dan foto berdasarkan ekstensi/MIME/isi, serta output HTML yang di-escape.
- Penyimpanan dokumen dan foto di luar rute aset publik. Endpoint file memeriksa sesi dan otorisasi sebelum mengirim berkas.
- Antarmuka responsif SIPRINT dengan aset Kabupaten Cirebon yang sudah ada.

Alur status dokumen: `menunggu → diproses → selesai`, atau `ditolak` dengan alasan wajib.

## Persyaratan

- PHP 8.1 atau lebih baru dengan ekstensi `pdo_mysql`, `fileinfo`, dan `mbstring`.
- Apache 2.4 dengan `mod_rewrite` dan izin `AllowOverride All` untuk folder aplikasi.
- MySQL 8.0+ atau MariaDB yang mendukung tipe JSON.
- Browser dengan JavaScript aktif untuk fitur tambahan seperti menu, pratinjau/cetak, pencarian tabel, dan pemilihan berkas.

## Menjalankan di XAMPP

1. Salin folder aplikasi ke `C:\xampp\htdocs\New folder` (atau folder lain di `htdocs`), lalu jalankan Apache dan MySQL dari XAMPP Control Panel. Aktifkan `mod_rewrite` bila belum aktif, dan pastikan konfigurasi Apache mengizinkan `.htaccess` (`AllowOverride All`) untuk folder `htdocs`.
2. Salin `config.example.php` menjadi `config.php`. Sesuaikan nilai koneksi MySQL dan ganti `session_secret` dengan nilai acak minimal 32 karakter. Contoh membuat nilai acak tanpa Node.js:

   ```powershell
   php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"
   ```

   `config.php` dikecualikan dari Git. Jangan menyimpan kata sandi atau rahasia di repository. `.env.example` hanya mendokumentasikan nama variabel yang dapat diberikan ke PHP; XAMPP/Apache tidak membaca `.env` secara otomatis.

3. Buat basis data dan tabel dengan mengimpor `schema.sql` melalui phpMyAdmin (`http://localhost/phpmyadmin`) atau MySQL CLI. Skema menggunakan nama database `siprint`. Jika database SIPRINT sudah ada dari instalasi lama, jangan impor ulang tanpa backup; jalankan hanya migrasi yang belum diterapkan:

   - `migrations/20261111_create_notifications.sql`
   - `migrations/20261111_add_profile_photos.sql`

   Migrasi `add_profile_photos` hanya diperlukan bila tabel `users` belum memiliki kolom `profile_photo`.

4. Buat administrator pertama dari PowerShell. Isi variabel environment dengan milik instansi, lalu hapus setelah selesai:

   ```powershell
   $env:ADMIN_NAME = "Administrator SIPRINT"
   $env:ADMIN_EMAIL = "admin@cirebonkab.go.id"
   $env:ADMIN_PASSWORD = "gunakan-kata-sandi-acak-minimal-16-karakter"
   php .\create-admin.php
   Remove-Item Env:ADMIN_NAME, Env:ADMIN_EMAIL, Env:ADMIN_PASSWORD
   ```

   Akun admin pertama hanya dapat dibuat sekali dengan skrip ini. Password minimal 16 karakter dan maksimal 72 byte (batas bcrypt). Untuk membuat pegawai berikutnya, masuk sebagai admin dan gunakan menu **Data Pegawai**.

5. Buka URL aplikasi di browser, misalnya `http://localhost/New%20folder/`. Apache mengarahkan root URL ke `index.php`; aset CSS/JavaScript dan gambar lama tetap disajikan dari `public/`.

## Penyimpanan berkas

Secara default file disimpan di `storage/uploads` dan `storage/profile-photos`. Akses langsung HTTP ke direktori tersebut ditolak oleh `.htaccess`; berkas hanya dikirim melalui endpoint PHP terautentikasi. Pastikan proses Apache dapat menulis ke folder `storage`.

Untuk menempatkan berkas di luar document root, atur `upload_dir` pada `config.php` ke folder privat di luar `htdocs`, misalnya `C:\xampp\private\siprint`. Aplikasi membuat subfolder `uploads` dan `profile-photos`. Berikan izin baca/tulis hanya kepada akun Apache. Tentukan lokasi ini sebelum pemakaian; jika mengganti lokasi setelah ada dokumen, pindahkan berkas lama dan pastikan database tetap menunjuk pada nama berkas yang sama.

## Batas unggahan dan produksi

PDF dibatasi 15 MB dan foto profil 2 MB. Pastikan `php.ini` mengizinkan unggahan tersebut, misalnya `upload_max_filesize=16M` dan `post_max_size=18M`, kemudian restart Apache. Nilai PHP harus lebih besar dari batas aplikasi agar pesan validasi aplikasi tetap dapat ditampilkan.

Untuk penggunaan di jaringan/produksi:

- Aktifkan HTTPS, lalu set `secure_cookies` menjadi `true` di `config.php`.
- Gunakan rahasia sesi unik, akun MySQL dengan hak minimum, dan password database yang kuat.
- Batasi akses jaringan ke SIPRINT dan backup database/penyimpanan privat sesuai kebijakan instansi.
- Pastikan `config.php`, `src/`, `views/`, `migrations/`, `storage/`, dan file konfigurasi tidak dapat diunduh melalui web. Aturan `.htaccess` memblokir akses tersebut pada Apache.
- Bila aplikasi dipasang di bawah subfolder, gunakan URL melalui Apache dan jangan menaruh `storage` di lokasi yang dapat diakses sebagai aset publik.

## Skema dan kompatibilitas

`schema.sql` tetap menjadi sumber skema MySQL aplikasi, termasuk tabel `users`, `documents`, `audit_logs`, dan `notifications`. Hash bcrypt lama tetap dapat diverifikasi oleh PHP `password_verify()`. Tabel `sessions` yang mungkin tertinggal dari instalasi Node lama tidak digunakan oleh PHP native sessions dan tidak perlu dihapus.
