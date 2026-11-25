# Product Requirements Document (PRD)

# CozyCafe -- Web-Based Cafe Ordering & Table Reservation System

**Status:** Discovery selesai\
**Versi:** 1.0\
**Rilis:** Full Feature Release\
**Platform:** Localhost menggunakan XAMPP

------------------------------------------------------------------------

## 1. Informasi Produk

  Informasi         Detail
  ----------------- -----------------------------
  Nama produk       CozyCafe
  Jenis aplikasi    Web Application
  Platform          Localhost menggunakan XAMPP
  Target pengguna   Customer dan Admin
  Bahasa            Indonesia dan Inggris
  Backend           Native PHP tanpa framework
  Database          MySQL
  Frontend          HTML, CSS, JavaScript
  UI Framework      Bootstrap 5
  Konsep desain     Cozy & Warm
  Versi dokumen     PRD 1.0

## 2. Product Overview

CozyCafe adalah aplikasi web untuk membantu operasional cafe melalui
sistem pemesanan makanan dan minuman, reservasi meja, pembayaran,
pengelolaan menu, stok, serta administrasi cafe.

Aplikasi dirancang untuk memberikan pengalaman pemesanan yang lebih
praktis kepada customer sekaligus membantu admin mengelola operasional
cafe melalui satu dashboard terpusat.

Sistem menyediakan dua area utama:

1.  **Customer Website:** untuk melihat menu, melakukan pemesanan,
    booking meja, membayar, dan memantau transaksi.
2.  **Admin Dashboard:** untuk mengelola produk, kategori, stok,
    pesanan, booking, pembayaran, customer, dan laporan.

## 3. Problem Statement

Permasalahan yang ingin diselesaikan:

-   Customer harus mengantre untuk memesan makanan dan minuman.
-   Customer sulit mengetahui ketersediaan meja.
-   Reservasi masih berpotensi dilakukan secara manual melalui WhatsApp
    atau datang langsung.
-   Admin kesulitan mencatat pesanan dan booking secara terpusat.
-   Pengelolaan stok dan menu rawan terjadi kesalahan.
-   Verifikasi pembayaran dan rekap penjualan membutuhkan waktu.
-   Tidak tersedia sistem terpadu untuk memantau aktivitas cafe.

## 4. Product Goals

1.  Memudahkan customer memesan makanan dan minuman secara online.
2.  Memungkinkan customer melakukan reservasi meja berdasarkan
    ketersediaan.
3.  Menyediakan pembayaran QRIS dan Cash sesuai aturan transaksi.
4.  Mengotomatisasi pengelolaan stok.
5.  Mempermudah admin mengelola operasional cafe.
6.  Menyediakan dashboard dan laporan penjualan.
7.  Mengurangi kesalahan pencatatan transaksi.
8.  Memberikan tampilan yang nyaman dan responsif.

## 5. Target Users dan User Roles

### 5.1 Customer

Customer adalah pengguna yang mengakses website untuk:

-   Registrasi dan login.
-   Melihat katalog menu.
-   Mengelola keranjang.
-   Melakukan pemesanan Dine-in dan Takeaway.
-   Booking meja.
-   Membayar DP booking.
-   Mengunggah bukti pembayaran.
-   Melacak status pesanan.
-   Melihat riwayat transaksi.
-   Mengelola profil.

Booking meja wajib menggunakan akun terdaftar. Guest checkout untuk
pesanan makanan tetap dapat digunakan sesuai kebijakan autentikasi yang
telah ditentukan.

### 5.2 Admin

Admin memiliki satu role dengan akses penuh.

Admin dapat:

-   Mengelola dashboard.
-   Mengelola menu dan kategori.
-   Mengelola stok dan harga.
-   Mengelola meja.
-   Mengelola reservasi.
-   Mengelola pesanan.
-   Memverifikasi pembayaran.
-   Mengelola customer.
-   Mengelola banner.
-   Melihat laporan.
-   Melihat audit log.
-   Melakukan backup database.

## 6. Scope dan Feature Requirements

### 6.1 Authentication dan Account Management

**Customer:**

-   Registrasi dengan nama lengkap, email, nomor HP, password, dan
    konfirmasi password.
-   Login menggunakan email atau nomor HP dan password.
-   Logout.
-   Lupa password melalui email.
-   Mengubah profil.
-   Mengubah password.
-   Melihat riwayat aktivitas akun.

**Admin:**

-   Login melalui halaman khusus `/admin/login`.
-   Session khusus admin.
-   Logout.
-   Satu role Admin dengan akses penuh.

**Security requirements:**

-   Password menggunakan `password_hash()` dan `password_verify()`.
-   Session management yang aman.
-   Pembatasan percobaan login.
-   CSRF protection.
-   Validasi input server-side.
-   Prepared statements untuk query database.
-   Validasi status dan role pada setiap halaman admin.

### 6.2 Customer Homepage

Homepage menyediakan:

-   Logo CozyCafe.
-   Navigasi website.
-   Banner promosi.
-   Kategori menu.
-   Produk unggulan.
-   Informasi operasional.
-   Tombol Lihat Menu.
-   Tombol Booking Meja.
-   Pilihan bahasa Indonesia dan Inggris.

Jam operasional: **setiap hari pukul 09.00--22.00 WIB**.

### 6.3 Menu Management

Admin dapat melakukan CRUD produk.

  Field        Keterangan
  ------------ -----------------------------
  Product ID   Identitas produk
  Nama         Nama makanan/minuman
  Foto         Upload JPG, JPEG, PNG, WebP
  Deskripsi    Deskripsi produk
  Kategori     Relasi kategori
  Harga        Harga produk
  Stok         Jumlah stok
  Status       Tersedia / Tidak tersedia
  Featured     Produk unggulan

Fitur tambahan:

-   Upload foto produk.
-   Mengubah harga.
-   Mengelola stok.
-   Mengaktifkan dan menonaktifkan produk.
-   Melihat riwayat perubahan harga.
-   Melihat riwayat perubahan stok.

Tidak ada varian ukuran, topping, atau customisasi produk.

### 6.4 Customer Menu Catalog

Katalog menggunakan desain sederhana dengan informasi:

-   Foto produk.
-   Nama produk.
-   Harga.
-   Tombol tambah ke keranjang.

Halaman detail produk menampilkan:

-   Foto.
-   Nama.
-   Harga.
-   Deskripsi.
-   Stok tersedia.
-   Jumlah pesanan.
-   Catatan khusus produk.

Tidak terdapat fitur pencarian atau sortir menu pada versi pertama.

### 6.5 Shopping Cart

Keranjang tersimpan di database dan terhubung dengan akun customer.

Fitur:

-   Tambah produk.
-   Mengubah kuantitas.
-   Menghapus produk.
-   Menyimpan catatan produk.
-   Menambahkan catatan pesanan.
-   Menghitung subtotal.
-   Menghitung pajak.
-   Menampilkan total pembayaran.
-   Memvalidasi stok ketika checkout.

### 6.6 Checkout

Informasi checkout:

-   Nama customer.
-   Nomor HP.
-   Tipe pesanan.
-   Metode pembayaran.
-   Catatan pesanan.
-   Daftar produk.
-   Jumlah produk.
-   Subtotal.
-   Pajak 10%.
-   Total tagihan.

Rumus perhitungan:

``` text
Subtotal = Σ (Harga × Kuantitas)
Pajak = Subtotal × 10%
Total = Subtotal + Pajak
```

Perhitungan dilakukan di server untuk mencegah manipulasi nominal dari
browser.

### 6.7 Ordering System

CozyCafe mendukung dua jenis pemesanan.

#### A. Dine-in

-   Customer melakukan booking atau walk-in.
-   Customer dapat melakukan pre-order saat booking.
-   Customer juga dapat menambah pesanan saat berada di cafe.
-   Semua pesanan dalam satu kunjungan digabung menjadi satu tagihan.
-   Pre-order dijadwalkan untuk mulai disiapkan 15 menit sebelum
    reservasi.
-   Pembayaran dilakukan setelah selesai makan.
-   Metode pembayaran Cash atau QRIS.
-   Admin mengonfirmasi pembayaran.

#### B. Takeaway

-   Customer memilih produk.
-   Customer melakukan checkout.
-   Pembayaran wajib QRIS.
-   Customer mengunggah bukti pembayaran.
-   Admin memverifikasi pembayaran.
-   Pesanan mulai diproses setelah pembayaran dikonfirmasi.
-   Estimasi persiapan 15 menit.
-   Customer mengambil pesanan di cafe.
-   Pesanan tidak dapat dibatalkan customer setelah pembayaran berhasil.

### 6.8 Order Status Management

Status pesanan:

  Status                Deskripsi
  --------------------- --------------------------
  Pending               Pesanan dibuat
  Waiting for Payment   Menunggu pembayaran
  Paid                  Pembayaran dikonfirmasi
  Processing            Pesanan sedang disiapkan
  Ready                 Pesanan siap
  Completed             Pesanan selesai
  Cancelled             Pesanan dibatalkan

Alur utama:

``` mermaid
flowchart TD
 A[Customer membuat pesanan] --> B{Metode pembayaran}
 B -->|QRIS| C[Upload bukti pembayaran]
 B -->|Cash| D[Bayar di kasir]
 C --> E[Admin verifikasi]
 D --> E
 E --> F[Paid]
 F --> G[Processing]
 G --> H[Ready]
 H --> I[Completed]
 A --> J[Cancelled]
```

Pembayaran QRIS menggunakan verifikasi manual, bukan payment gateway.
Status `Paid` hanya diberikan setelah admin menyetujui bukti atau
mengonfirmasi penerimaan tunai.

### 6.9 Payment Management

Metode pembayaran yang didukung:

-   Cash.
-   QRIS statis.

QRIS:

-   Gambar QRIS disimpan sebagai aset dalam project.
-   Customer melihat gambar QRIS.
-   Customer melakukan pembayaran melalui aplikasi pembayaran.
-   Customer mengunggah bukti pembayaran.
-   Admin memverifikasi secara manual.

Aturan pembayaran:

-   Timeout pembayaran QRIS: 15 menit.
-   Jika bukti belum diunggah dalam batas waktu, transaksi dibatalkan.
-   Jika bukti diunggah tepat waktu, transaksi menunggu verifikasi admin
    dan tidak boleh otomatis kedaluwarsa hanya karena admin belum
    memeriksa.
-   Pembayaran tunai dikonfirmasi manual oleh admin.
-   Riwayat pembayaran tersimpan dalam database.

### 6.10 Tax Management

Pajak ditetapkan sebesar **10%** dari subtotal.

-   Tidak ada service charge.
-   Pajak berlaku untuk Dine-in dan Takeaway.
-   Pajak tidak dikenakan pada DP booking.
-   Perhitungan dilakukan di server.

### 6.11 Table Reservation

Customer wajib login untuk booking.

Customer dapat:

-   Melihat daftar meja.
-   Melihat kapasitas meja.
-   Melihat status ketersediaan.
-   Memilih meja.
-   Memilih tanggal.
-   Memilih waktu mulai.
-   Menentukan durasi.
-   Melihat waktu selesai.
-   Membayar DP.
-   Mengunggah bukti DP.
-   Melihat detail booking.

Aturan reservasi:

-   Jam operasional 09.00--22.00 WIB.
-   Durasi booking fleksibel.
-   Customer memilih meja tertentu.
-   Sistem menghitung waktu selesai.
-   Sistem mencegah jadwal yang bertabrakan.
-   Booking otomatis diperiksa ketersediaannya.
-   Booking baru berstatus menunggu DP sampai pembayaran diverifikasi.
-   Booking menjadi Confirmed setelah DP disetujui admin.

### 6.12 Down Payment (DP)

Ketentuan DP:

  Ketentuan           Nilai
  ------------------- -------------------
  DP per reservasi    Rp50.000
  Metode pembayaran   QRIS saja
  Batas pembayaran    15 menit
  Verifikasi          Manual oleh admin

DP merupakan biaya booking terpisah dan bukan potongan dari tagihan
makanan.

Contoh:

-   DP: Rp50.000.
-   Subtotal makanan: Rp100.000.
-   Pajak makanan: Rp10.000.
-   Tagihan makanan: Rp110.000.
-   Total pengeluaran: Rp160.000.

Kebijakan pembatalan:

-   Customer membatalkan: DP tidak dikembalikan.
-   Customer no-show: DP hangus.
-   Cafe membatalkan: DP dikembalikan 100%.
-   Grace period kedatangan: 15 menit setelah jadwal mulai.

### 6.13 Booking Status

  Status           Keterangan
  ---------------- ------------------------------------------------
  Waiting for DP   Menunggu pembayaran DP
  Confirmed        DP telah diverifikasi
  Cancelled        Booking dibatalkan
  Completed        Reservasi selesai
  No Show          Customer tidak datang
  Expired          Booking sementara melewati batas pembayaran DP

### 6.14 Walk-in Management

Customer walk-in dapat memilih meja melalui website.

Alur:

1.  Customer melihat daftar meja tersedia.
2.  Customer memilih meja.
3.  Permintaan masuk ke dashboard admin.
4.  Admin mengonfirmasi penempatan.
5.  Sistem menandai meja digunakan.
6.  Durasi penggunaan meja otomatis dua jam.

Status meja:

-   Tersedia.
-   Digunakan.
-   Tidak Aktif.

Admin dapat mengubah status meja secara manual. Sistem harus memeriksa
reservasi dan penggunaan aktual sebelum menerima perubahan status.

### 6.15 Booking Detail dan Customer Dashboard

Dashboard customer menampilkan:

-   Ringkasan pesanan aktif.
-   Booking mendatang.
-   Status pembayaran DP.
-   Riwayat pesanan.
-   Riwayat booking.
-   Tombol cepat ke menu.
-   Tombol cepat ke booking.

Halaman detail booking menampilkan:

-   Nomor booking.
-   Nomor meja.
-   Tanggal.
-   Jam mulai.
-   Jam selesai.
-   Nominal DP.
-   Status pembayaran.
-   QRIS.
-   Bukti pembayaran.
-   Countdown jika pembayaran belum dilakukan.
-   Ketentuan pembatalan.

### 6.16 Admin Dashboard

Dashboard admin menyediakan:

-   Statistik penjualan.
-   Jumlah transaksi.
-   Jumlah booking.
-   Jumlah customer.
-   Pesanan aktif.
-   Booking mendatang.
-   Status pembayaran.
-   Produk terlaris.
-   Grafik penjualan.

Modul dashboard:

1.  Overview.
2.  Menu Management.
3.  Category Management.
4.  Inventory Management.
5.  Order Management.
6.  Payment Verification.
7.  Reservation Management.
8.  Table Management.
9.  Customer Management.
10. Banner Management.
11. Reports.
12. Admin Activity Log.
13. Database Backup.

### 6.17 Banner Management

Admin dapat:

-   Upload banner.
-   Mengubah banner.
-   Menghapus banner.
-   Mengaktifkan/nonaktifkan banner.
-   Mengatur urutan banner.

Data banner:

-   Gambar.
-   Judul.
-   Deskripsi.
-   Tombol aksi.
-   Tanggal mulai.
-   Tanggal selesai.
-   Status.

Banner hanya ditampilkan jika aktif dan berada dalam periode promosi.

### 6.18 Inventory Management

Sistem menggunakan stok otomatis.

Aturan:

-   Admin menentukan stok awal.
-   Sistem memvalidasi ketersediaan stok.
-   Stok tidak boleh negatif.
-   Stok diperbarui sesuai proses konfirmasi pesanan.
-   Pembatalan sebelum pesanan diproses mengembalikan stok.
-   Admin dapat menambah stok.
-   Setiap perubahan dicatat dalam riwayat.

### 6.19 Reports and Analytics

  Jenis laporan   Isi
  --------------- -----------------------------
  Harian          Transaksi dan pendapatan
  Mingguan        Ringkasan penjualan
  Bulanan         Ringkasan bulanan
  Produk          Produk terlaris
  Pembayaran      Cash dan QRIS
  Booking         Jumlah dan status reservasi
  Customer        Aktivitas customer

Fitur:

-   Filter rentang tanggal.
-   Grafik penjualan.
-   Statistik transaksi.
-   Total pendapatan.
-   Tidak ada export PDF/Excel pada versi pertama.

### 6.20 Notifications

Notifikasi dilakukan di dalam website.

-   Customer melihat perubahan status pesanan.
-   Customer melihat perubahan status booking.
-   Admin melihat pesanan baru.
-   Admin melihat booking baru.
-   Admin melihat bukti pembayaran yang menunggu verifikasi.

Pembaruan menggunakan JavaScript polling atau refresh berkala, bukan
push notification.

### 6.21 Multilingual

Bahasa yang tersedia:

-   Bahasa Indonesia.
-   Bahasa Inggris.

Konten produk dan banner diinput admin dalam Bahasa Indonesia. Tampilan
Inggris menggunakan terjemahan otomatis atau teks default untuk konten
yang didukung. Status, navigasi, dan pesan sistem menggunakan file
bahasa terpisah.

### 6.22 Backup and Audit Log

**Backup:**

-   Otomatis setiap hari.
-   Manual melalui dashboard.
-   Admin dapat mengunduh file backup.

**Audit log mencatat:**

-   Login admin.
-   Perubahan produk.
-   Perubahan harga.
-   Perubahan stok.
-   Verifikasi pembayaran.
-   Perubahan status pesanan.
-   Perubahan booking.
-   Pengelolaan customer.

## 7. Business Rules

  -----------------------------------------------------------------------
  ID                                  Aturan
  ----------------------------------- -----------------------------------
  BR-01                               Jam operasional 09.00--22.00 WIB
                                      setiap hari

  BR-02                               Pajak 10% dari subtotal

  BR-03                               DP booking Rp50.000

  BR-04                               DP dibayar melalui QRIS

  BR-05                               Timeout pembayaran 15 menit

  BR-06                               Booking dikonfirmasi setelah DP
                                      diverifikasi

  BR-07                               Customer booking wajib login

  BR-08                               Durasi booking fleksibel

  BR-09                               Sistem mencegah bentrok reservasi

  BR-10                               Grace period no-show 15 menit

  BR-11                               DP tidak dikembalikan untuk
                                      pembatalan customer/no-show

  BR-12                               Cafe membatalkan booking: DP
                                      dikembalikan penuh

  BR-13                               Walk-in menggunakan durasi dua jam

  BR-14                               Takeaway wajib dibayar sebelum
                                      diproses

  BR-15                               Takeaway menggunakan QRIS

  BR-16                               Dine-in dibayar setelah makan

  BR-17                               Pesanan dalam satu kunjungan
                                      digabung

  BR-18                               Estimasi Takeaway 15 menit

  BR-19                               Pesanan Takeaway tidak dapat
                                      dibatalkan setelah pembayaran
                                      berhasil

  BR-20                               Admin memiliki akses penuh
  -----------------------------------------------------------------------

## 8. User Flows

### 8.1 Customer Registration and Login

``` mermaid
flowchart TD
 A[Open Website] --> B{Sudah punya akun?}
 B -->|Tidak| C[Register]
 C --> D[Validasi data]
 D --> E[Account Created]
 B -->|Ya| F[Login Email atau HP]
 E --> F
 F --> G[Customer Dashboard]
```

### 8.2 Takeaway Order

``` mermaid
flowchart TD
 A[Open Menu] --> B[Select Product]
 B --> C[Add to Cart]
 C --> D[Checkout]
 D --> E[Calculate 10% Tax]
 E --> F[Show QRIS]
 F --> G[Upload Payment Proof]
 G --> H[Admin Verification]
 H --> I{Approved?}
 I -->|Yes| J[Paid]
 I -->|No| K[Rejected / Resubmit]
 J --> L[Processing]
 L --> M[Ready]
 M --> N[Customer Pickup]
 N --> O[Completed]
```

### 8.3 Table Reservation

``` mermaid
flowchart TD
 A[Login Customer] --> B[Open Reservation]
 B --> C[Choose Date and Time]
 C --> D[Choose Table]
 D --> E[Select Duration]
 E --> F[Check Availability]
 F --> G{Available?}
 G -->|No| D
 G -->|Yes| H[Temporary Reservation]
 H --> I[Pay DP Rp50.000]
 I --> J[Upload Proof]
 J --> K[Admin Verification]
 K --> L{Approved?}
 L -->|Yes| M[Confirmed]
 L -->|No| N[Payment Rejected / Retry]
```

## 9. Technical Requirements

### 9.1 Technology Stack

  Layer               Technology
  ------------------- -------------
  Frontend            HTML5
  Styling             CSS3
  Framework           Bootstrap 5
  Interaction         JavaScript
  Backend             Native PHP
  Database            MySQL
  Web server          Apache
  Local environment   XAMPP
  Email               Gmail SMTP

### 9.2 Recommended Project Structure

``` text
cozycafe/
│
├── admin/
│   ├── dashboard.php
│   ├── products/
│   ├── categories/
│   ├── inventory/
│   ├── orders/
│   ├── reservations/
│   ├── tables/
│   ├── payments/
│   ├── customers/
│   ├── banners/
│   ├── reports/
│   └── backup/
│
├── customer/
│   ├── dashboard.php
│   ├── menu.php
│   ├── product-detail.php
│   ├── cart.php
│   ├── checkout.php
│   ├── reservations/
│   ├── orders/
│   └── profile.php
│
├── auth/
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   └── forgot-password.php
│
├── config/
│   └── database.php
│
├── includes/
│   ├── header.php
│   ├── footer.php
│   ├── navbar.php
│   └── functions.php
│
├── assets/
│   ├── css/
│   ├── js/
│   ├── images/
│   └── qris/
│
├── uploads/
│   ├── products/
│   ├── banners/
│   └── payment-proofs/
│
├── languages/
│   ├── id.php
│   └── en.php
│
├── database/
│   └── cozycafe.sql
│
└── index.php
```

### 9.3 Database Design

Recommended database tables:

  Table                     Purpose
  ------------------------- ---------------------------
  users                     Customer accounts
  admins                    Admin accounts
  categories                Menu categories
  products                  Menu products
  product_stock_logs        Stock history
  product_price_logs        Price history
  carts                     Customer carts
  cart_items                Cart products
  orders                    Order headers
  order_items               Order details
  order_status_logs         Status history
  payments                  Payment transactions
  payment_proofs            Uploaded payment evidence
  tables                    Cafe tables
  reservations              Booking headers
  reservation_status_logs   Booking status history
  banners                   Homepage promotions
  walkin_requests           Walk-in table requests
  admin_logs                Audit records
  password_resets           Password reset tokens
  notifications             In-app notifications
  backups                   Backup records

Recommended relationships:

``` mermaid
erDiagram
 USERS ||--o{ ORDERS : creates
 USERS ||--o{ RESERVATIONS : books
 USERS ||--o{ CARTS : owns
 CATEGORIES ||--o{ PRODUCTS : contains
 PRODUCTS ||--o{ ORDER_ITEMS : included
 ORDERS ||--|{ ORDER_ITEMS : contains
 ORDERS ||--o{ PAYMENTS : has
 PAYMENTS ||--o{ PAYMENT_PROOFS : includes
 TABLES ||--o{ RESERVATIONS : assigned
 RESERVATIONS ||--o{ PAYMENTS : requires
 ADMINS ||--o{ ADMIN_LOGS : creates
```

Important implementation considerations:

-   Use InnoDB and foreign keys.
-   Use transactions when reserving tables or confirming orders.
-   Use unique constraints for email and phone.
-   Store timestamps consistently and display in WIB.
-   Store payment proofs outside publicly executable directories.
-   Use indexes on booking dates, table IDs, and order statuses.

## 10. UI/UX Requirements

Visual identity:

  Element      Style
  ------------ --------------------
  Primary      Coffee Brown
  Secondary    Cream
  Accent       Beige
  Background   Warm White
  Typography   Clean and friendly
  Buttons      Rounded corners
  Cards        Soft shadows
  Layout       Responsive

UI requirements:

-   Bootstrap 5 responsive.
-   Mobile-first design.
-   Navigasi customer sederhana.
-   Dashboard admin terstruktur.
-   Konsistensi komponen.
-   Form validasi yang jelas.
-   Feedback sukses dan error.
-   Loading indicator saat proses penting.

## 11. Non-Functional Requirements

### Performance

-   Halaman utama dimuat secara efisien.
-   Query database dioptimalkan.
-   Gambar dikompresi.
-   Pagination untuk data besar.

### Security

-   Password hashing.
-   Prepared statements.
-   CSRF protection.
-   XSS prevention.
-   Validasi file upload.
-   Validasi session.
-   Role-based access control.
-   Rate limiting login.
-   Backup database.

### Reliability

-   Transaksi database untuk proses kritis.
-   Pencegahan double booking.
-   Pencegahan stok negatif.
-   Audit trail.
-   Penanganan error yang aman.

### Compatibility

-   Google Chrome.
-   Microsoft Edge.
-   Firefox.
-   Browser mobile modern.

## 12. Acceptance Criteria

  -----------------------------------------------------------------------
  ID                                  Acceptance Criteria
  ----------------------------------- -----------------------------------
  AC-01                               Customer dapat registrasi dan login

  AC-02                               Admin dapat login melalui halaman
                                      khusus

  AC-03                               Customer dapat melihat katalog

  AC-04                               Customer dapat mengelola keranjang

  AC-05                               Checkout menghitung pajak 10%
                                      dengan benar

  AC-06                               Takeaway hanya dapat diproses
                                      setelah pembayaran QRIS
                                      diverifikasi

  AC-07                               Dine-in dapat dibayar setelah makan

  AC-08                               Sistem mencegah stok negatif

  AC-09                               Sistem mencegah booking meja yang
                                      bentrok

  AC-10                               DP booking ditetapkan Rp50.000

  AC-11                               Booking baru dikonfirmasi setelah
                                      DP diverifikasi

  AC-12                               Sistem membatalkan booking yang
                                      melewati timeout DP

  AC-13                               Customer dapat melihat status
                                      pesanan dan booking

  AC-14                               Admin dapat mengelola meja dan
                                      produk

  AC-15                               Admin dapat melihat laporan

  AC-16                               Admin dapat mengunduh backup

  AC-17                               Aktivitas admin tercatat

  AC-18                               Website mendukung dua bahasa

  AC-19                               Website responsif pada perangkat
                                      mobile dan desktop

  AC-20                               Semua halaman admin terlindungi
                                      dari akses customer
  -----------------------------------------------------------------------

## 13. Risks and Mitigation

  -----------------------------------------------------------------------
  Risiko                              Mitigasi
  ----------------------------------- -----------------------------------
  Bukti pembayaran palsu              Verifikasi manual oleh admin

  Double booking                      Database transaction dan
                                      availability locking

  Stok tidak konsisten                Transaction dan stock validation

  Customer tidak membayar DP          Timeout 15 menit

  Customer tidak datang               Grace period dan No Show

  Admin terlambat memverifikasi       Dashboard pembayaran menunggu
                                      verifikasi

  QRIS berubah                        Penggantian aset oleh developer

  Data hilang                         Backup harian dan manual

  Manipulasi request                  Server-side validation dan
                                      authorization
  -----------------------------------------------------------------------

## 14. Assumptions and Decisions Requiring Technical Clarification

Beberapa detail perlu ditetapkan saat implementasi agar semua fitur
berjalan konsisten:

1.  **Guest checkout:** Booking wajib login, tetapi dukungan guest order
    perlu menggunakan identitas kontak dan kode transaksi untuk
    pelacakan tanpa akses akun.
2.  **DP dan pembayaran:** DP merupakan transaksi terpisah dari tagihan
    makanan.
3.  **Order QRIS timeout:** Timeout 15 menit berlaku sebelum bukti
    pembayaran diterima; bukti yang sudah masuk harus menunggu keputusan
    admin.
4.  **Pre-order:** Pesanan mulai disiapkan 15 menit sebelum booking.
    Perlu mekanisme penjadwalan agar tidak diproses terlalu awal.
5.  **Penggabungan pesanan:** Pesanan tambahan hanya dapat digabung
    selama kunjungan yang sama dan sebelum tagihan ditutup.
6.  **Walk-in:** Permintaan meja harus dikonfirmasi admin sebelum
    penggunaan.
7.  **Refund:** Kebijakan DP cafe cancellation memerlukan proses
    pengembalian manual karena QRIS statis tidak terintegrasi dengan
    refund otomatis.
8.  **Terjemahan:** Konten Inggris otomatis perlu ditinjau karena
    terjemahan otomatis tidak selalu akurat.
9.  **Localhost:** Email SMTP memerlukan koneksi internet meskipun
    website berjalan di localhost.
10. **No-show:** Penandaan No Show dilakukan admin setelah grace period
    15 menit.

## 15. Suggested Development Milestones

Walaupun seluruh fitur masuk rilis pertama, implementasi sebaiknya
dilakukan bertahap secara internal.

  Tahap   Pekerjaan
  ------- ---------------------------------------------
  1       Setup XAMPP, database, dan struktur project
  2       Authentication dan role management
  3       Menu, kategori, stok, dan banner
  4       Cart dan checkout
  5       Order management dan pembayaran
  6       Booking, DP, dan pengelolaan meja
  7       Dashboard customer dan admin
  8       Reports, audit log, dan backup
  9       Multilingual dan UI refinement
  10      Security testing dan acceptance testing

## 16. Definition of Done

CozyCafe siap digunakan secara lokal apabila:

-   Semua fitur wajib telah diimplementasikan.
-   Database terhubung dan konsisten.
-   Tidak ada akses customer ke halaman admin.
-   Tidak ada double booking.
-   Tidak ada stok negatif.
-   Pembayaran dan DP dapat diverifikasi.
-   Semua status transaksi berjalan sesuai aturan.
-   Tampilan responsif.
-   Backup dapat dilakukan dan dipulihkan.
-   Pengujian fungsional dan keamanan dasar telah lulus.

## 17. Product Success Metrics

Metrik keberhasilan yang disarankan:

-   Persentase checkout berhasil.
-   Jumlah transaksi Dine-in dan Takeaway.
-   Jumlah booking berhasil.
-   Persentase booking yang no-show.
-   Waktu rata-rata verifikasi pembayaran.
-   Akurasi stok.
-   Jumlah transaksi yang perlu koreksi manual.
-   Waktu yang dibutuhkan admin untuk memproses pesanan.

## 18. Product Manager's Final Recommendation

CozyCafe memiliki cakupan fitur yang cukup lengkap untuk aplikasi
pengelolaan cafe skala kecil hingga menengah.

Prioritas kualitas implementasi yang paling penting adalah:

1.  Konsistensi stok dan transaksi.
2.  Pencegahan bentrok booking.
3.  Keamanan autentikasi dan pembayaran.
4.  Kemudahan penggunaan customer.
5.  Kemudahan operasional admin.

Karena aplikasi menggunakan PHP Native dan MySQL, disarankan menerapkan
pemisahan modul, reusable functions, validasi terpusat, serta database
transaction pada seluruh proses kritis.

**Status dokumen:** PRD CozyCafe versi 1.0 -- selesai disusun
berdasarkan hasil discovery.
