# Panduan Deployment CozyCafe

Panduan ini mencakup pengujian Docker lokal dan deployment production di Dokploy. Jangan mengunggah `.env`, kredensial database, SMTP, atau `CRON_SECRET` ke repository.

## Uji lokal dengan Docker Compose

1. Pastikan Docker Engine dan Docker Compose v2 tersedia.
2. Salin `.env.example` menjadi `.env`, lalu tetapkan password lokal untuk `MYSQL_ROOT_PASSWORD` dan `MYSQL_APP_PASSWORD`. `DB_NAME` boleh tetap `cozycafe`; isi `DOCKER_APP_URL=http://localhost:8080`.
3. Dari root repository, jalankan `docker compose up --build -d`.
4. Tunggu database sehat dengan `docker compose ps`, lalu buka `http://localhost:8080`.
5. Untuk memeriksa log, jalankan `docker compose logs -f app db`. Jangan gunakan password production untuk stack lokal.
6. Skrip SQL di `database/cozycafe.sql` diimpor otomatis hanya saat volume MySQL masih kosong. Jangan menghapus volume `mysql_data` untuk memperbarui aplikasi karena tindakan itu menghapus database lokal.
7. Scheduler lokal dapat menjalankan tugas melalui container aplikasi:
   - `docker compose exec app php /var/www/html/cron/cleanup_expired.php`
   - `docker compose exec app php /var/www/html/cron/daily_backup.php`

Compose menyediakan volume bernama `mysql_data`, `uploads_data`, dan `backups_data`. File SQL backup maupun unggahan tetap tersimpan saat container dibuat ulang.

## Deployment production di Dokploy

1. **Buat Project dan database MySQL.** Buat database MySQL 8.0 di Dokploy. Impor `database/cozycafe.sql` ke database tersebut satu kali. Siapkan host internal, nama database, user aplikasi, dan password dari Dokploy; jangan memakai kredensial contoh lokal.
2. **Hubungkan repository.** Tambahkan repository Git sebagai Application, pilih build type **Dockerfile**, dan gunakan Dockerfile pada root repository.
3. **Isi Environment Variables** pada Application:
   - `APP_ENV=production`
   - `APP_URL=https://domain-anda`
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASS` sesuai database Dokploy
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, dan `SMTP_FROM_NAME` sesuai SMTP yang digunakan
   - `CRON_SECRET` dengan nilai acak sekurang-kurangnya 32 karakter jika endpoint cleanup akan dipanggil melalui HTTP. Cron Dokploy yang menjalankan PHP CLI tidak memerlukan nilai ini.
   - `TZ=Asia/Jakarta`

   Gunakan fitur secret/environment variable Dokploy untuk semua kredensial. `DB_HOST` harus hostname internal database yang Dokploy sediakan, bukan `localhost`.
4. **Deploy dan persistent storage.** Setelah deploy berhasil, tambahkan Persistent Volumes pada Application:
   - `/var/www/html/uploads` untuk gambar produk, banner, dan bukti pembayaran.
   - `/var/www/html/database/backups` untuk file backup SQL.

   Pertahankan volume tersebut ketika membuat release atau mengganti container.
5. **Atur domain dan SSL.** Tambahkan domain pada bagian Domains di Application, arahkan DNS ke server Dokploy, aktifkan sertifikat Let's Encrypt, lalu pastikan `APP_URL` menggunakan domain HTTPS yang sama.
6. **Tambahkan Scheduled Tasks / Cron Jobs** untuk container Application. Atur zona waktu scheduler ke `Asia/Jakarta`:
   - Setiap dua menit: `php /var/www/html/cron/cleanup_expired.php`
   - Setiap hari pukul 23:55 WIB: `php /var/www/html/cron/daily_backup.php`

   Kedua perintah harus dijalankan di container aplikasi dan menggunakan environment variables Application. Perintah backup memerlukan volume persistent pada direktori backup.
7. **Verifikasi setelah deployment.** Periksa log container dan koneksi database; uji halaman customer dan admin melalui HTTPS; pastikan `/config/`, `/includes/`, `/database/`, `/languages/`, file `.env`, `.sql`, dan `.md` tidak dapat diakses melalui web; buat dan unduh backup dari panel admin; lalu periksa bahwa file unggahan dan backup tetap ada setelah redeploy.

## Keamanan operasional

- Apache menolak akses web langsung ke direktori konfigurasi, kode internal, SQL, dan backup. Endpoint `cron/cleanup_expired.php` adalah satu-satunya route cron yang dikecualikan dari aturan direktori; akses HTTP-nya hanya menerima `POST` dengan header `X-Cron-Secret` yang cocok. Gunakan CLI dari Dokploy Scheduler sebagai pilihan utama.
- ErrorDocument pada konfigurasi sumber memakai path XAMPP `/cozycafe`; Dockerfile menyesuaikannya ke root domain saat image dibangun.
- `cron/daily_backup.php` hanya dapat dijalankan melalui CLI.
- Halaman error 403, 404, dan 500 tidak menampilkan detail teknis aplikasi. Detail runtime PHP diarahkan ke log container dan `display_errors` dimatikan dalam image.
- Ganti kredensial seed admin setelah login pertama, gunakan secret unik untuk setiap environment, dan lindungi akses dashboard Dokploy serta backup yang telah diunduh.
