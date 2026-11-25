# AGENTS.md — CozyCafe Development & Dokploy Deployment Playbook

Dokumen ini adalah panduan instruksi utama (*system context* & *step-by-step execution prompts*) untuk membangun aplikasi web **CozyCafe** dari tahap inisialisasi hingga siap *deploy* di produksi menggunakan **Dokploy**.

---

## Bagian 1: Arsitektur & Aturan Wajib AI Agent (System Guardrails)

Sebelum mengeksekusi *prompt* tahap demi tahap, AI Agent **WAJIB** mematuhi batasan teknis dan aturan bisnis berikut:

### 1.1 Tech Stack & Lingkungan
* **Backend:** Native PHP 8.2+ (**DILARANG** menggunakan framework seperti Laravel, CodeIgniter, atau Symfony). Gunakan PDO dengan *Prepared Statements* untuk seluruh interaksi database.
* **Database:** MySQL 8.0 / MariaDB (Engine `InnoDB`, `utf8mb4_unicode_ci`, gunakan `FOREIGN KEY` dan `DB Transaction` pada proses kritis).
* **Frontend:** HTML5, CSS3, Vanilla JavaScript (ES6+), dan **Bootstrap 5** (via CDN atau aset lokal).
* **Zona Waktu:** Wajib `Asia/Jakarta` (WIB) baik di level PHP (`date_default_timezone_set('Asia/Jakarta')`) maupun sesi MySQL (`SET time_zone = '+07:00'`).
* **Kompatibilitas Dual-Environment:** Konfigurasi aplikasi harus membaca variabel dari `.env` (dengan fallback default XAMPP lokal) agar dapat berjalan mulus di XAMPP saat *development* maupun di container Docker pada **Dokploy** saat *production*.

### 1.2 Identitas Visual (Cozy & Warm UI)
* **Palet Warna:**
  * Primary: Coffee Brown (`#5C3A21` / `#4A2E1B`)
  * Secondary: Cream (`#F5EFE6`)
  * Accent: Beige (`#D9C5B2` / `#C8A98E`)
  * Background: Warm White (`#FAF8F5`)
  * Text Dark: Espresso (`#2C1E16`)
* **Gaya Komponen:** *Mobile-first responsive*, sudut membulat (*rounded corners* `12px`–`16px`), bayangan lembut (*soft shadows*), indikator *loading* pada aksi penting, serta alert/toast yang jelas.

### 1.3 Ringkasan Aturan Bisnis Kritis (BR-01 s/d BR-20)
1. **Jam Operasional:** Setiap hari pukul `09:00 – 22:00 WIB` (`BR-01`).
2. **Pajak:** `10%` dari subtotal makanan/minuman (`BR-02`). Tidak ada *service charge*. Pajak berlaku untuk Dine-in & Takeaway, **tidak berlaku** pada DP booking. Perhitungan wajib dilakukan di sisi server.
3. **Katalog & Produk:** Tidak ada varian ukuran, *topping*, atau kustomisasi menu. Tidak ada fitur pencarian/sortir menu pada versi 1.0.
4. **Takeaway (`BR-14`, `BR-15`, `BR-18`, `BR-19`):** Pembayaran wajib **QRIS** di muka. Timeout unggah bukti bayar **15 menit**. Jika bukti sudah diunggah sebelum 15 menit, transaksi **tidak boleh kedaluwarsa otomatis** dan menunggu verifikasi Admin. Estimasi persiapan **15 menit** setelah status `Paid`. Tidak dapat dibatalkan customer setelah `Paid`.
5. **Dine-in (`BR-16`, `BR-17`):** Bisa melalui reservasi (termasuk *pre-order* yang mulai disiapkan 15 menit sebelum jam kedatangan) atau *walk-in*. Semua pesanan tambahan dalam satu kunjungan digabung ke satu tagihan. Pembayaran dilakukan **setelah selesai makan** (Cash atau QRIS) dengan konfirmasi Admin.
6. **Reservasi Meja & DP (`BR-03` s/d `BR-12`):** Wajib login customer. Durasi fleksibel dalam jam operasional. Wajib cegah bentrok jadwal meja dengan locking database. DP tetap **Rp50.000** via **QRIS saja** (biaya terpisah, bukan pemotong tagihan makanan). Timeout bayar DP **15 menit**. Grace period keterlambatan **15 menit** sebelum ditandai `No Show`. DP hangus jika customer batal/*no-show*; dikembalikan 100% (manual) jika cafe yang membatalkan.
7. **Walk-in (`BR-13`):** Customer memilih meja tersedia -> masuk permintaan ke Admin -> Admin konfirmasi -> status meja menjadi `Digunakan` otomatis selama **2 jam**.
8. **Stok Otomatis:** Tidak boleh negatif. Berkurang saat pesanan dikonfirmasi/diproses; kembali otomatis jika pesanan dibatalkan sebelum diproses.
9. **Keamanan:** `password_hash()` / `password_verify()`, token CSRF pada semua form POST, sanitasi output XSS (`htmlspecialchars`), rate limiting login, validasi MIME file upload, dan proteksi direktori bukti pembayaran.

---

## Bagian 2: Step-by-Step Prompts Pembangunan Aplikasi

Gunakan *prompt* berikut secara berurutan (Step 0 hingga Step 11). Pastikan setiap tahap diuji dan berjalan tanpa error sebelum melanjutkan ke tahap berikutnya.

---

### Step 0:@ Inisialisasi Struktur Proyek, Environment Loader, & Konfigurasi Dasar

> **Prompt untuk AI Agent:**
> Saya ingin membangun aplikasi web **CozyCafe** menggunakan **Native PHP 8.2+, MySQL, HTML5, CSS3, JavaScript, dan Bootstrap 5** sesuai spesifikasi di `AGENTS.md` dan `CozyCafe_PRD.md`.
>
> Pada tahap awal ini, buatkan fondasi proyek dengan detail berikut:
> 1. Buat struktur folder lengkap:
>    - `admin/` (sub-folder: `products/`, `categories/`, `inventory/`, `orders/`, `reservations/`, `tables/`, `payments/`, `customers/`, `banners/`, `reports/`, `backup/`, `logs/`)
>    - `customer/` (sub-folder: `reservations/`, `orders/`)
>    - `auth/`
>    - `config/`
>    - `includes/`
>    - `assets/` (`css/`, `js/`, `images/`, `qris/`)
>    - `uploads/` (`products/`, `banners/`, `payment-proofs/` — sertakan `.htaccess` di `uploads/` untuk mencegah eksekusi file PHP dan melindungi akses langsung ke `payment-proofs/`)
>    - `languages/`
>    - `database/`
>    - `cron/`
> 2. Buat `.env.example` dan `config/env.php` (parser `.env` sederhana dalam Native PHP tanpa library eksternal) untuk memuat variabel `APP_ENV`, `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_NAME`.
> 3. Buat `config/database.php` yang menginisialisasi koneksi **PDO MySQL** dengan `ATTR_ERRMODE => ERRMODE_EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`, `ATTR_EMULATE_PREPARES => false`, serta mengatur zona waktu PHP ke `Asia/Jakarta` dan MySQL ke `+07:00`.
> 4. Buat placeholder gambar QRIS statis di `assets/qris/qris-cozycafe.png` (atau SVG/PNG siap pakai) serta file CSS dasar `assets/css/style.css` yang mendefinisikan CSS variables palet warna *Cozy & Warm* (`#5C3A21`, `#F5EFE6`, `#D9C5B2`, `#FAF8F5`).

---

### Step 1: Perancangan & Pembuatan Skema Database MySQL (`cozycafe.sql`)     

> **Prompt untuk AI Agent:**
> Berdasarkan `CozyCafe_PRD.md` bagian 9.3, buatkan file skema SQL lengkap di `database/cozycafe.sql` menggunakan engine `InnoDB`, `utf8mb4_unicode_ci`, *foreign keys*, *unique constraints*, dan *indexes* yang optimal.
>
> Pastikan mencakup seluruh **22 tabel** beserta kolom yang relevan untuk setiap aturan bisnis:
> 1. `users` (id, full_name, email UNIQUE, phone UNIQUE, password_hash, is_active, created_at, updated_at)
> 2. `admins` (id, name, username/email UNIQUE, password_hash, last_login_at, created_at)
> 3. `categories` (id, name, slug, sort_order, is_active, created_at)
> 4. `products` (id, category_id FK, name, slug, image, description, price DECIMAL(12,2), stock INT UNSIGNED, is_available TINYINT, is_featured TINYINT, created_at, updated_at)
> 5. `product_stock_logs` (id, product_id FK, admin_id FK NULL, order_id NULL, change_type ENUM('initial','restock','order_deduct','order_restore','adjustment'), qty_change INT, stock_before INT, stock_after INT, notes, created_at)
> 6. `product_price_logs` (id, product_id FK, admin_id FK, old_price DECIMAL(12,2), new_price DECIMAL(12,2), created_at)
> 7. `carts` (id, user_id FK UNIQUE, order_notes TEXT, updated_at)
> 8. `cart_items` (id, cart_id FK, product_id FK, quantity INT, item_notes VARCHAR(255), created_at)
> 9. `orders` (id, order_code UNIQUE, user_id FK NULL, guest_name, guest_phone, order_type ENUM('dine_in','takeaway'), reservation_id FK NULL, table_id FK NULL, parent_order_id FK NULL untuk penggabungan tagihan kunjungan dine-in, subtotal DECIMAL(12,2), tax_amount DECIMAL(12,2), total_amount DECIMAL(12,2), payment_method ENUM('qris','cash'), status ENUM('Pending','Waiting for Payment','Paid','Processing','Ready','Completed','Cancelled'), order_notes TEXT, scheduled_prep_at DATETIME NULL, payment_deadline DATETIME NULL, cancel_reason TEXT NULL, created_at, updated_at)
> 10. `order_items` (id, order_id FK, product_id FK, product_name_snapshot, price_snapshot DECIMAL(12,2), quantity INT, subtotal DECIMAL(12,2), item_notes VARCHAR(255))
> 11. `order_status_logs` (id, order_id FK, old_status, new_status, changed_by_type ENUM('customer','admin','system'), changed_by_id NULL, notes, created_at)
> 12. `payments` (id, payment_code UNIQUE, payable_type ENUM('order','reservation_dp'), payable_id INT, payment_method ENUM('qris','cash'), amount DECIMAL(12,2), status ENUM('Pending','Waiting Verification','Verified','Rejected','Expired','Refunded'), verified_by FK admins NULL, verified_at DATETIME NULL, rejection_reason TEXT NULL, expires_at DATETIME NULL, created_at)
> 13. `payment_proofs` (id, payment_id FK, file_path, original_name, mime_type, uploaded_at)
> 14. `tables` (id, table_number UNIQUE, capacity INT, location_area VARCHAR(100), status ENUM('Tersedia','Digunakan','Tidak Aktif'), occupied_until DATETIME NULL, current_reservation_id NULL, updated_at)
> 15. `reservations` (id, booking_code UNIQUE, user_id FK, table_id FK, reservation_date DATE, start_time TIME, end_time TIME, duration_minutes INT, guest_count INT, dp_amount DECIMAL(12,2) DEFAULT 50000.00, status ENUM('Waiting for DP','Confirmed','Cancelled','Completed','No Show','Expired'), cancelled_by ENUM('customer','admin','system') NULL, cancel_reason TEXT NULL, dp_refund_status ENUM('none','forfeited','pending_refund','refunded') DEFAULT 'none', created_at, updated_at)
> 16. `reservation_status_logs` (id, reservation_id FK, old_status, new_status, changed_by_type, changed_by_id NULL, notes, created_at)
> 17. `banners` (id, title, description, image, button_text, button_url, start_date DATE, end_date DATE, sort_order INT, is_active TINYINT, created_at)
> 18. `walkin_requests` (id, table_id FK, user_id FK NULL, guest_name, guest_phone, guest_count INT, status ENUM('Pending','Approved','Rejected','Completed'), approved_by FK admins NULL, expires_at DATETIME NULL, created_at)
> 19. `admin_logs` (id, admin_id FK, action VARCHAR(100), entity_type VARCHAR(50), entity_id INT NULL, description TEXT, ip_address VARCHAR(45), created_at)
> 20. `password_resets` (id, email, token_hash, expires_at, used_at NULL, created_at)
> 21. `notifications` (id, recipient_type ENUM('customer','admin'), recipient_id INT NULL, title, message, link_url, is_read TINYINT DEFAULT 0, created_at)
> 22. `backups` (id, file_name, file_path, file_size BIGINT, backup_type ENUM('auto','manual'), created_by FK admins NULL, created_at)
>
> Sertakan juga `INSERT` data awal (*seed data*): 1 akun Admin default (`admin@cozycafe.com` / password ter-hash), 4 kategori menu (Coffee, Non-Coffee, Main Course, Pastry & Dessert), 8 contoh produk unggulan, 8 meja cafe (`T-01` s/d `T-08`), dan 2 banner promosi aktif.

---

### Step 2: Core Functions, Keamanan (CSRF, XSS, Rate Limit), & Sistem Multilingual

> **Prompt untuk AI Agent:**
> Buatkan lapisan utilitas inti dan keamanan di `includes/` serta dukungan dwibahasa di `languages/`:
> 1. `languages/id.php` dan `languages/en.php`: Array terjemahan lengkap untuk navigasi, label tombol, status pesanan, status booking, pesan validasi form, pesan error, dan notifikasi sistem. Buat helper `__($key)` untuk mengambil teks sesuai bahasa aktif yang disimpan di `$_SESSION['lang']` (default `'id'`), serta endpoint `change-lang.php` untuk berpindah bahasa secara mulus.
> 2. `includes/functions.php`:
>    - Manajemen Session aman (`session_set_cookie_params` dengan `httponly`, `samesite=Lax`).
>    - Fungsi CSRF: `generate_csrf_token()`, `csrf_field()`, dan `verify_csrf_token()` (wajib dipanggil pada semua endpoint `POST`).
>    - Sanitasi output: `e($string)` menggunakan `htmlspecialchars($string, ENT_QUOTES, 'UTF-8')`.
>    - Format mata uang `format_rupiah($amount)` dan format tanggal/jam WIB `format_wib($datetime)`.
>    - Fungsi upload file aman `secure_upload($file, $targetDir, $allowedMimes, $maxSizeMB)`: validasi MIME asli menggunakan `finfo_file()`, rename file menggunakan `bin2hex(random_bytes(16))`, dan cegah ekstensi berbahaya.
>    - Fungsi pencatatan log: `log_admin_action(...)`, `log_order_status(...)`, `log_reservation_status(...)`, dan `create_notification(...)`.
>    - Fungsi `check_rate_limit($identifier, $maxAttempts = 5, $decaySeconds = 900)` berbasis session/database untuk mencegah *brute-force* login.
> 3. `includes/auth_middleware.php`: Fungsi `require_customer_login()`, `require_admin_login()`, dan `redirect_if_authenticated()` untuk memastikan pemisahan ketat antara sesi Customer (`$_SESSION['customer_id']`) dan sesi Admin (`$_SESSION['admin_id']`).

---

### Step 3: Sistem Autentikasi & Manajemen Akun (Customer & Admin)

> **Prompt untuk AI Agent:**
> Implementasikan seluruh fitur autentikasi dan profil pada folder `auth/`, `customer/`, dan `admin/` sesuai PRD bagian 6.1:
> 1. **Customer Registration (`auth/register.php`):** Form dengan Nama Lengkap, Email, Nomor HP (normalisasi format `08...`), Password, Konfirmasi Password. Validasi server-side keunikan email & nomor HP, hash password dengan `password_hash(..., PASSWORD_DEFAULT)`.
> 2. **Customer Login (`auth/login.php`):** Login menggunakan **Email ATAU Nomor HP** + Password. Terapkan *rate limiting* dan regenerasi ID sesi (`session_regenerate_id(true)`) setelah berhasil login.
> 3. **Lupa Password (`auth/forgot-password.php` & `auth/reset-password.php`):** Input email -> generate token aman -> simpan hash token ke tabel `password_resets` (berlaku 30 menit) -> kirim link reset via SMTP Gmail (implementasikan helper SMTP socket native PHP atau PHPMailer ringan di `includes/mailer.php` menggunakan kredensial dari `.env`).
> 4. **Customer Profile (`customer/profile.php`):** Edit nama, email, nomor HP, ubah password (wajib verifikasi password lama), serta tampilan riwayat aktivitas akun (riwayat pesanan & reservasi terbaru).
> 5. **Admin Login (`admin/login.php` & `admin/logout.php`):** Halaman login terpisah khusus Admin dengan tema profesional CozyCafe, *rate limiting*, dan pencatatan ke `admin_logs` setiap kali admin berhasil login.
> 6. **Logout (`auth/logout.php`):** Hancurkan sesi secara bersih dengan proteksi CSRF/POST atau token logout.

---

### Step 4: Layout UI Cozy & Warm, Homepage, & Katalog Menu Customer

> **Prompt untuk AI Agent:**
> Buatkan antarmuka publik dan customer dengan desain **Cozy & Warm** menggunakan Bootstrap 5:
> 1. `includes/navbar.php`, `includes/header.php`, `includes/footer.php`:
>    - Navbar responsif menampilkan Logo CozyCafe, menu navigasi (Beranda, Menu, Reservasi Meja, Lacak Pesanan Guest, Dashboard/Akun), badge jumlah item keranjang secara real-time, dropdown switch bahasa (`ID` / `EN`), serta ikon lonceng notifikasi in-app untuk customer yang login.
>    - Footer menampilkan alamat cafe, informasi **Jam Operasional: Setiap Hari 09.00 – 22.00 WIB**, dan tautan cepat.
> 2. `index.php` (Customer Homepage):
>    - Carousel/Slider Banner Promosi aktif yang hanya tampil jika `is_active = 1` dan tanggal hari ini berada di antara `start_date` dan `end_date`.
>    - Hero section hangat dengan tombol CTA utama **"Lihat Menu"** dan **"Booking Meja"**.
>    - Bagian Kategori Menu & Grid Produk Unggulan (*Featured Products*).
>    - Bagian informasi ketersediaan meja saat ini (ringkasan jumlah meja tersedia untuk Walk-in/Booking).
> 3. `customer/menu.php` & `customer/product-detail.php`:
>    - Katalog menu dikelompokkan/difilter berdasarkan tab kategori (tanpa kotak pencarian dan tanpa fitur sortir sesuai PRD 6.4).
>    - Kartu produk menampilkan foto, nama, harga Rupiah, label stok habis jika `stock == 0` atau `is_available == 0`, serta tombol cepat "+ Keranjang".
>    - Halaman `product-detail.php` menampilkan foto besar, deskripsi, sisa stok, input jumlah (`1` s/d `stock`), input catatan khusus produk, dan tombol tambah ke keranjang.

---

### Step 5: Keranjang Belanja (Shopping Cart) & Sistem Checkout (10% Tax)

> **Prompt untuk AI Agent:**
> Bangun sistem keranjang belanja dan checkout di `customer/cart.php`, `customer/cart_action.php` (endpoint AJAX JSON), dan `customer/checkout.php` sesuai PRD 6.5, 6.6, 6.7, dan 6.10:
> 1. **Keranjang Hybrid (Database & Session):**
>    - Jika customer sudah login, simpan dan sinkronkan keranjang ke tabel `carts` & `cart_items` di database.
>    - Jika belum login (*Guest*), simpan keranjang di `$_SESSION['guest_cart']` (agar *guest checkout* untuk pesanan makanan tetap berfungsi). Saat guest login, gabungkan otomatis item session ke keranjang database.
>    - Validasi agar kuantitas tidak pernah melebihi `products.stock`.
> 2. **Halaman Keranjang (`customer/cart.php`):**
>    - Ubah kuantitas (+/-), hapus item, edit catatan per produk, isi catatan keseluruhan pesanan.
>    - Tampilkan rincian kalkulasi: `Subtotal`, `Pajak (10%)`, dan `Total Pembayaran`.
> 3. **Halaman Checkout (`customer/checkout.php`):**
>    - Jika Guest: wajib isi Nama Lengkap dan Nomor HP. Jika Login: otomatis terisi dari profil (dapat diedit untuk kontak pesanan).
>    - Pilih Tipe Pesanan:
>      - **Takeaway:** Metode pembayaran otomatis terkunci ke **QRIS** (`BR-15`).
>      - **Dine-in:** Pilih apakah untuk kunjungan baru/walk-in atau digabung ke reservasi aktif/pesanan dine-in yang belum dibayar (`BR-17`). Metode pembayaran bisa pilih **QRIS** atau **Cash** (dibayar setelah makan, `BR-16`).
>    - **Proses Submit Checkout (Server-Side Transaction):**
>      - Hitung ulang seluruh harga dari tabel `products` di server (abaikan nominal dari form client).
>      - Hitung `subtotal`, `tax_amount = round($subtotal * 0.10)`, dan `total_amount = $subtotal + $tax_amount`.
>      - Validasi ulang stok produk dengan `SELECT ... FOR UPDATE` di dalam transaksi database.
>      - Untuk **Takeaway**: set status order ke `Waiting for Payment`, buat record `payments` dengan `expires_at = NOW() + INTERVAL 15 MINUTE`, lalu redirect ke halaman pembayaran QRIS.
>      - Untuk **Dine-in**: set status order ke `Pending` (atau `Processing` sesuai alur), kurangi stok secara aman, dan arahkan ke halaman detail/lacak pesanan.
> 4. **Pelacakan Guest (`customer/track-order.php`):** Halaman publik untuk melacak status pesanan menggunakan `order_code` + `Nomor HP` tanpa harus login.

---

### Step 6: Sistem Reservasi Meja, DP Rp50.000, & Manajemen Walk-in

> **Prompt untuk AI Agent:**
> Implementasikan modul reservasi meja dan walk-in di `customer/reservations/` dan `customer/walkin.php` sesuai PRD 6.11 s/d 6.14:
> 1. **Daftar & Ketersediaan Meja (`customer/reservations/index.php`):**
>    - Wajib login customer (`require_customer_login()`).
>    - Form filter interaktif: pilih Tanggal Reservasi, Jam Mulai (rentang `09:00` hingga `21:00` WIB), dan Durasi (misal 1 jam, 1.5 jam, 2 jam, 3 jam — pastikan `start_time + duration <= 22:00 WIB`).
>    - Tampilkan kartu setiap meja (Nomor Meja, Kapasitas, Area, Status ketersediaan pada slot waktu tersebut).
> 2. **Logika Anti-Bentrok (`Overlap Prevention`):**
>    - Dua reservasi pada `table_id` yang sama dianggap bentrok jika status reservasi eksisting adalah `Confirmed` atau (`Waiting for DP` yang belum melewati batas 15 menit) DAN memenuhi kondisi irisan waktu:
>      `existing.start_time < requested.end_time AND existing.end_time > requested.start_time`.
>    - Cek juga apakah meja sedang berstatus `Tidak Aktif` atau sedang `Digunakan` walk-in pada waktu tersebut.
> 3. **Pembuatan Reservasi & DP (`customer/reservations/create.php`):**
>    - Gunakan `DB Transaction` + lock baris meja agar dua user tidak bisa mem-booking slot yang sama secara bersamaan.
>    - Simpan ke `reservations` dengan status `Waiting for DP`, `dp_amount = 50000`.
>    - Buat record di `payments` (`payable_type = 'reservation_dp'`, `payment_method = 'qris'`, `amount = 50000`, `expires_at = NOW() + INTERVAL 15 MINUTE`).
>    - Opsional: sediakan opsi **"Pre-Order Menu Sekarang"** setelah booking dibuat (pesanan pre-order otomatis diberi `scheduled_prep_at = reservation_datetime - 15 minutes` sesuai `BR` & bagian 6.7.A).
> 4. **Permintaan Meja Walk-in (`customer/walkin.php`):**
>    - Customer walk-in (login atau guest isi nama & HP) melihat daftar meja yang berstatus `Tersedia` saat ini -> klik "Gunakan Meja Ini" -> masuk ke tabel `walkin_requests` dengan status `Pending` dan mengirim notifikasi ke Admin.

---

### Step 7: Alur Pembayaran QRIS Statis, Unggah Bukti Bayar, & Countdown Timeout

> **Prompt untuk AI Agent:**
> Buatkan halaman dan logika pembayaran QRIS serta pengelolaan bukti bayar di `customer/payment.php` dan `customer/reservations/detail.php` sesuai PRD 6.9, 6.12, dan 6.15:
> 1. **Tampilan Halaman Pembayaran QRIS (`customer/payment.php`):**
>    - Menampilkan kode transaksi (`order_code` atau `booking_code`), rincian tagihan, gambar **QRIS Statis CozyCafe** (`assets/qris/qris-cozycafe.png`), instruksi pembayaran, dan **JavaScript Countdown Timer** real-time menuju `expires_at` (15 menit).
>    - Jika waktu habis dan belum ada bukti yang diunggah, UI otomatis menonaktifkan form unggah dan memperbarui status menjadi `Cancelled` / `Expired`.
>    - Jika bukti pembayaran sudah diunggah sebelum 15 menit: sembunyikan timer kedaluwarsa, tampilkan pratinjau bukti bayar yang telah diunggah, dan tampilkan status **"Menunggu Verifikasi Admin"** (pastikan sistem tidak membatalkan transaksi ini walau lewat 15 menit karena sudah ada bukti masuk, sesuai aturan 6.9).
>    - Jika bukti sebelumnya ditolak Admin (`Rejected`), tampilkan alasan penolakan dari Admin dan izinkan customer mengunggah ulang bukti pembayaran yang valid.
> 2. **Proses Unggah Bukti (`customer/upload_proof.php`):**
>    - Validasi file gambar (`JPG`, `JPEG`, `PNG`, `WebP`, maks 5MB) menggunakan `secure_upload()`.
>    - Simpan file ke `uploads/payment-proofs/` dan buat endpoint aman `view-proof.php?id=...` yang hanya bisa diakses oleh pemilik transaksi atau Admin yang sedang login (mencegah akses publik langsung ke folder bukti bayar).
>    - Ubah status `payments` menjadi `Waiting Verification` dan kirim notifikasi in-app ke Admin.
> 3. **Halaman Detail Booking (`customer/reservations/detail.php`):**
>    - Menampilkan Nomor Booking, Nomor Meja, Tanggal, Jam Mulai & Selesai, Nominal DP Rp50.000, status DP, QRIS & form upload bukti (jika belum bayar), ketentuan pembatalan (DP hangus jika customer membatalkan), serta tombol **Batalkan Reservasi** oleh customer.

---

### Step 8: Siklus Hidup Pesanan, Penggabungan Tagihan Dine-in, & Manajemen Stok Otomatis

> **Prompt untuk AI Agent:**
> Implementasikan manajemen siklus hidup pesanan dan integritas stok di `customer/orders/` dan `includes/order_service.php` sesuai PRD 6.7, 6.8, dan 6.18:
> 1. **Dashboard Customer (`customer/dashboard.php`) & Riwayat Pesanan (`customer/orders/index.php` & `detail.php`):**
>    - Ringkasan pesanan aktif dengan *stepper* visual status (`Pending` -> `Waiting for Payment` -> `Paid` -> `Processing` -> `Ready` -> `Completed`).
>    - Ringkasan booking mendatang & status DP.
>    - Tombol cepat ke Menu, Booking Meja, dan **"Tambah Pesanan ke Meja Ini"** untuk customer Dine-in yang kunjungannya masih aktif.
> 2. **Penggabungan Pesanan Dine-in (`BR-17`):**
>    - Jika customer Dine-in menambah pesanan dalam kunjungan yang sama sebelum tagihan ditutup (`Completed`/`Paid`), sistem menautkan item/pesanan tambahan ke tagihan kunjungan tersebut dan menghitung ulang `subtotal`, `tax_amount (10%)`, dan `total_amount` secara akurat.
> 3. **Aturan Stok Otomatis & Anti-Minus (`BR` Bagian 6.18):**
>    - Buat fungsi layanan terpusat `deduct_order_stock($pdo, $orderId, $adminId = null)` dan `restore_order_stock($pdo, $orderId, $adminId = null)` yang berjalan di dalam transaksi database (`FOR UPDATE`).
>    - Untuk **Takeaway**: stok dikunci/dikurangi saat checkout (atau saat pembayaran `Paid` dengan validasi stok ketat; jika stok habis saat checkout langsung ditolak). Jika pesanan Takeaway kedaluwarsa (15 menit tidak upload bukti) atau dibatalkan sebelum `Processing`, kembalikan stok otomatis dan catat di `product_stock_logs`.
>    - Pastikan pesanan Takeaway yang sudah `Paid` tidak menampilkan tombol batal di sisi customer (`BR-19`).

---

### Step 9: Admin Dashboard & Seluruh Modul Operasional Cafe

> **Prompt untuk AI Agent:**
> Bangun **Admin Dashboard** yang lengkap, responsif, dan terproteksi `require_admin_login()` di folder `admin/` sesuai PRD 6.16 s/d 6.18:
> 1. **Layout Admin (`admin/includes/sidebar.php` & `topbar.php`):** Sidebar navigasi terstruktur untuk ke-13 modul, indikator badge jumlah pembayaran & walk-in yang menunggu verifikasi, dan tombol logout.
> 2. **Dashboard Overview (`admin/dashboard.php`):**
>    - Kartu KPI: Total Pendapatan Hari Ini, Jumlah Transaksi, Booking Hari Ini, Total Customer Terdaftar.
>    - Grafik Penjualan 7 hari terakhir (menggunakan Chart.js via CDN).
>    - Daftar Pesanan Aktif, Booking Mendatang (termasuk penanda Pre-Order yang harus disiapkan 15 menit sebelum jam booking), Bukti Pembayaran Menunggu Verifikasi, dan Top 5 Produk Terlaris.
> 3. **CRUD Menu, Kategori, & Stok (`admin/categories/`, `admin/products/`, `admin/inventory/`):**
>    - CRUD Kategori & Produk (upload gambar WebP/JPG/PNG, set status tersedia, set `is_featured`).
>    - Setiap perubahan harga otomatis tercatat di `product_price_logs`.
>    - Halaman `admin/inventory/index.php`: tambah/sesuaikan stok cepat dan lihat riwayat mutasi stok lengkap (`product_stock_logs`).
> 4. **Verifikasi Pembayaran (`admin/payments/index.php`):**
>    - Daftar pembayaran QRIS (`order` maupun `reservation_dp`) berstatus `Waiting Verification`.
>    - Modal pratinjau bukti bayar resolusi penuh -> tombol **Setujui (`Verified`)** atau **Tolak (`Rejected` dengan alasan)**.
>    - Jika menyetujui pembayaran Takeaway -> status order menjadi `Paid` & siap diproses. Jika menyetujui DP -> status reservasi menjadi `Confirmed`.
>    - Sediakan juga tombol konfirmasi **Pembayaran Tunai (Cash)** untuk pesanan Dine-in yang selesai makan.
> 5. **Manajemen Pesanan (`admin/orders/index.php` & `detail.php`):**
>    - Filter berdasarkan tipe (`Dine-in` / `Takeaway`) dan status.
>    - Ubah status pesanan bertahap (`Paid` -> `Processing` -> `Ready` -> `Completed`, atau `Cancelled` dengan pengembalian stok jika belum diproses).
> 6. **Manajemen Reservasi & Meja (`admin/reservations/index.php` & `admin/tables/index.php`):**
>    - Daftar reservasi dengan tombol aksi: Tandai `Completed`, Tandai `No Show` (tersedia jika melewati `start_time + 15 menit` grace period -> DP hangus), atau Batalkan oleh Cafe (status DP menjadi `refunded` / `pending_refund` sesuai `BR-12`).
>    - Daftar permintaan Walk-in (`walkin_requests`): tombol **Konfirmasi Penempatan** -> otomatis set meja menjadi `Digunakan` dengan `occupied_until = NOW() + INTERVAL 2 HOUR` (`BR-13`).
>    - CRUD Meja (`admin/tables/index.php`): ubah status meja (`Tersedia`, `Digunakan`, `Tidak Aktif`) dengan pengecekan validasi apakah ada reservasi aktif pada meja tersebut.
> 7. **Manajemen Banner & Customer (`admin/banners/index.php` & `admin/customers/index.php`):**
>    - CRUD Banner promosi (gambar, judul, deskripsi, tombol aksi, periode tanggal mulai & selesai, urutan, status aktif).
>    - Daftar Customer, detail riwayat transaksi/booking customer, serta toggle aktif/nonaktif akun customer.
>
> *Catatan: Pastikan setiap aksi perubahan data oleh Admin memanggil `log_admin_action()` dan mengirim notifikasi ke customer terkait.*

---

### Step 10: Laporan Penjualan, Notifikasi Polling, Audit Log, & Database Backup

> **Prompt untuk AI Agent:**
> Lengkapi fitur analitik, notifikasi real-time polling, audit log, dan backup database sesuai PRD 6.19, 6.20, dan 6.22:
> 1. **Reports & Analytics (`admin/reports/index.php`):**
>    - Filter rentang tanggal (`start_date` – `end_date`) serta tab jenis laporan: Harian, Mingguan, Bulanan, Produk Terlaris, Metode Pembayaran (Cash vs QRIS), Status Booking, dan Aktivitas Customer.
>    - Tampilkan grafik visual (Chart.js) dan tabel ringkasan statistik pendapatan bersih (subtotal + pajak + DP terverifikasi). Sesuai PRD 6.19, tidak perlu export PDF/Excel pada versi 1.0.
> 2. **Sistem Notifikasi In-App Polling (`api/notifications.php` & `assets/js/notifications.js`):**
>    - Endpoint JSON yang mengembalikan jumlah notifikasi belum dibaca dan 5 notifikasi terbaru untuk Customer maupun Admin yang sedang login.
>    - JavaScript polling setiap **15 detik** untuk memperbarui badge lonceng notifikasi dan memunculkan toast ringan jika ada pesanan baru, booking baru, bukti bayar masuk (Admin), atau perubahan status pesanan/booking (Customer).
>    - Endpoint untuk menandai notifikasi telah dibaca (`mark_read`).
> 3. **Admin Activity Log (`admin/logs/index.php`):**
>    - Tabel berhalaman (*pagination*) menampilkan seluruh jejak audit (`admin_logs`) lengkap dengan filter berdasarkan Admin, jenis aksi, dan tanggal.
> 4. **Database Backup (`admin/backup/index.php` & `cron/daily_backup.php`):**
>    - Fungsi PHP murni (menggunakan PDO `SHOW TABLES`, `SHOW CREATE TABLE`, dan `SELECT` untuk menghasilkan file `.sql` lengkap tanpa bergantung pada binary `mysqldump` yang mungkin berbeda path di container).
>    - Admin dapat membuat backup manual kapan saja, melihat daftar file backup di tabel `backups`, dan mengunduh file `.sql` dengan aman.
>    - Buat script `cron/daily_backup.php` untuk menjalankan backup otomatis harian.

---

### Step 11: Otomatisasi Cron Tasks, Containerisasi Docker, & Deployment ke Dokploy

> **Prompt untuk AI Agent:**
> Siapkan seluruh kebutuhan otomatisasi latar belakang (*background cron jobs*), konfigurasi keamanan web server, serta file kontainerisasi agar aplikasi **CozyCafe** dapat di-*deploy* secara penuh dan stabil menggunakan **Dokploy**:
> 1. **Script Cron Pembersihan Otomatis (`cron/cleanup_expired.php`):**
>    - Lindungi script agar hanya bisa dijalankan via CLI (`php_sapi_name() === 'cli'`) atau menggunakan header/token rahasia `CRON_SECRET` dari `.env`.
>    - Otomatis membatalkan (`Cancelled` / `Expired`) pesanan Takeaway & Reservasi DP yang melewati `expires_at` (15 menit) **DAN** belum memiliki record di `payment_proofs` berstatus `Waiting Verification`, serta mengembalikan stok produk yang tertahan.
>    - Otomatis membebaskan status meja walk-in (`Digunakan` -> `Tersedia`) apabila `occupied_until <= NOW()` dan tidak ada reservasi aktif berikutnya.
> 2. **Konfigurasi Apache (`.htaccess` di root proyek):**
>    - Blokir akses langsung ke folder sensitif (`config/`, `includes/`, `database/`, `cron/`, `languages/`, dan file `.env`, `.sql`, `.md`).
>    - Atur custom error pages (`403.php`, `404.php`, `500.php`) bertema CozyCafe agar pesan error teknis PHP tidak pernah bocor ke pengguna di mode production.
> 3. **File Deployment Docker & Dokploy:**
>    - Buat `Dockerfile` berbasis `php:8.2-apache`:
>      - Install & aktifkan ekstensi PHP: `pdo_mysql`, `mysqli`, `gd` (dengan dukungan JPEG/PNG/WebP), `zip`, `mbstring`, `intl`.
>      - Aktifkan modul Apache `rewrite` dan `headers`.
>      - Atur `TZ=Asia/Jakarta` pada sistem container dan `php.ini` (`upload_max_filesize = 10M`, `post_max_size = 12M`, `display_errors = Off` untuk production).
>      - Atur kepemilikan (`chown -R www-data:www-data`) dan hak akses tulis pada direktori `uploads/` dan `database/backups/`.
>    - Buat `docker-compose.yml` untuk pengujian kontainer lokal sebelum push ke Git repository Dokploy (meliputi service `app` dan `db` MySQL 8.0 dengan auto-import `database/cozycafe.sql` saat inisialisasi pertama serta named volumes untuk `mysql_data` dan `uploads_data`).
>    - Buat panduan langkah demi langkah di bagian bawah `README_DEPLOY.md` berisi cara konfigurasi di dashboard **Dokploy**:
>      1. Membuat Project & Database MySQL di Dokploy + import `database/cozycafe.sql`.
>      2. Menghubungkan repository Git ke Dokploy Application (Build Type: `Dockerfile`).
>      3. Mengisi **Environment Variables** di Dokploy (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `SMTP_*`, `APP_URL`, `CRON_SECRET`).
>      4. Menambahkan **Persistent Volumes** untuk `/var/www/html/uploads` dan `/var/www/html/database/backups` agar foto produk, banner, bukti bayar, dan file backup tidak hilang saat re-deploy.
>      5. Mengatur **Domains & SSL (Let's Encrypt)** di Dokploy.
>      6. Mengatur **Scheduled Tasks (Cron Jobs)** di Dokploy:
>         - Setiap 2 menit: `php /var/www/html/cron/cleanup_expired.php`
>         - Setiap hari pukul 23:55 WIB: `php /var/www/html/cron/daily_backup.php`

---

## Bagian 3: Checklist Pengujian Akhir (Definition of Done Verification)

Sebelum aplikasi dinyatakan siap rilis di Dokploy, minta AI Agent memverifikasi seluruh Acceptance Criteria (`AC-01` s/d `AC-20`):
- [ ] Customer dapat registrasi, login (email/HP), reset password via SMTP, dan ubah profil.
- [ ] Halaman `/admin/*` tidak bisa diakses oleh user yang belum login admin atau login sebagai customer (`AC-20`).
- [ ] Checkout menghitung pajak 10% di server dan tidak bisa dimanipulasi dari inspect element (`AC-05`).
- [ ] Takeaway hanya bisa diproses setelah bukti QRIS disetujui Admin (`AC-06`).
- [ ] Dine-in dapat menggabungkan pesanan tambahan dalam kunjungan yang sama dan dibayar setelah makan (`AC-07`, `BR-17`).
- [ ] Stok tidak pernah menjadi negatif pada simulasi checkout bersamaan (`AC-08`).
- [ ] Reservasi meja pada tanggal, meja, dan jam yang beririsan ditolak sistem (`AC-09`).
- [ ] Pembayaran QRIS/DP yang belum unggah bukti setelah 15 menit otomatis batal/expired, sedangkan yang sudah unggah bukti tetap aman menunggu verifikasi Admin (`AC-12`).
- [ ] Seluruh perubahan penting oleh Admin tercatat di `admin_logs`, backup `.sql` dapat diunduh, dan tampilan responsif di mobile maupun desktop (`AC-16`, `AC-17`, `AC-19`).