-- CozyCafe database schema
-- Import this file into MySQL 8.0 or a compatible MariaDB server.
-- Temporary seeded admin login: admin@cozycafe.com / CozyCafe@2026
-- Change the temporary password after the first login.

CREATE DATABASE IF NOT EXISTS cozycafe
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE cozycafe;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone),
    KEY idx_users_active_created (is_active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tables` (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_number VARCHAR(20) NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    location_area VARCHAR(100) NOT NULL,
    status ENUM('Tersedia','Digunakan','Tidak Aktif') NOT NULL DEFAULT 'Tersedia',
    occupied_until DATETIME NULL,
    current_reservation_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tables_number (table_number),
    KEY idx_tables_status_occupied (status, occupied_until),
    KEY idx_tables_current_reservation (current_reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    image VARCHAR(255) NULL,
    description TEXT NULL,
    price DECIMAL(12,2) UNSIGNED NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_category_available (category_id, is_available),
    KEY idx_products_featured_available (is_featured, is_available),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    order_notes TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carts_user (user_id),
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    item_notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_items_cart_product (cart_id, product_id),
    KEY idx_cart_items_product (product_id),
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id) REFERENCES carts (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_code VARCHAR(40) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    table_id INT UNSIGNED NOT NULL,
    reservation_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    guest_count INT UNSIGNED NOT NULL,
    dp_amount DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 50000.00,
    status ENUM('Waiting for DP','Confirmed','Cancelled','Completed','No Show','Expired') NOT NULL DEFAULT 'Waiting for DP',
    cancelled_by ENUM('customer','admin','system') NULL,
    cancel_reason TEXT NULL,
    dp_refund_status ENUM('none','forfeited','pending_refund','refunded') NOT NULL DEFAULT 'none',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reservations_booking_code (booking_code),
    KEY idx_reservations_table_slot (table_id, reservation_date, status, start_time, end_time),
    KEY idx_reservations_user_date (user_id, reservation_date, status),
    CONSTRAINT fk_reservations_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservations_table
        FOREIGN KEY (table_id) REFERENCES `tables` (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code VARCHAR(40) NOT NULL,
    user_id INT UNSIGNED NULL,
    guest_name VARCHAR(150) NULL,
    guest_phone VARCHAR(20) NULL,
    order_type ENUM('dine_in','takeaway') NOT NULL,
    reservation_id INT UNSIGNED NULL,
    table_id INT UNSIGNED NULL,
    parent_order_id INT UNSIGNED NULL,
    subtotal DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
    payment_method ENUM('qris','cash') NOT NULL,
    status ENUM('Pending','Waiting for Payment','Paid','Processing','Ready','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    order_notes TEXT NULL,
    scheduled_prep_at DATETIME NULL,
    payment_deadline DATETIME NULL,
    cancel_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_order_code (order_code),
    KEY idx_orders_user_created (user_id, created_at),
    KEY idx_orders_type_status_created (order_type, status, created_at),
    KEY idx_orders_reservation (reservation_id),
    KEY idx_orders_table_status (table_id, status),
    KEY idx_orders_parent (parent_order_id),
    KEY idx_orders_payment_deadline (status, payment_deadline),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_orders_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_orders_table
        FOREIGN KEY (table_id) REFERENCES `tables` (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_orders_parent
        FOREIGN KEY (parent_order_id) REFERENCES orders (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    product_name_snapshot VARCHAR(150) NOT NULL,
    price_snapshot DECIMAL(12,2) UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    subtotal DECIMAL(12,2) UNSIGNED NOT NULL,
    item_notes VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_product (product_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    old_status ENUM('Pending','Waiting for Payment','Paid','Processing','Ready','Completed','Cancelled') NULL,
    new_status ENUM('Pending','Waiting for Payment','Paid','Processing','Ready','Completed','Cancelled') NOT NULL,
    changed_by_type ENUM('customer','admin','system') NOT NULL,
    changed_by_id INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_status_logs_order_created (order_id, created_at),
    CONSTRAINT fk_order_status_logs_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_stock_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NULL,
    order_id INT UNSIGNED NULL,
    change_type ENUM('initial','restock','order_deduct','order_restore','adjustment') NOT NULL,
    qty_change INT NOT NULL,
    stock_before INT UNSIGNED NOT NULL,
    stock_after INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_stock_logs_product_created (product_id, created_at),
    KEY idx_product_stock_logs_order (order_id),
    KEY idx_product_stock_logs_admin (admin_id),
    CONSTRAINT fk_product_stock_logs_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_product_stock_logs_admin
        FOREIGN KEY (admin_id) REFERENCES admins (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_product_stock_logs_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_price_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    old_price DECIMAL(12,2) UNSIGNED NOT NULL,
    new_price DECIMAL(12,2) UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_price_logs_product_created (product_id, created_at),
    KEY idx_product_price_logs_admin (admin_id),
    CONSTRAINT fk_product_price_logs_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_product_price_logs_admin
        FOREIGN KEY (admin_id) REFERENCES admins (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_code VARCHAR(40) NOT NULL,
    payable_type ENUM('order','reservation_dp') NOT NULL,
    payable_id INT UNSIGNED NOT NULL,
    payment_method ENUM('qris','cash') NOT NULL,
    amount DECIMAL(12,2) UNSIGNED NOT NULL,
    status ENUM('Pending','Waiting Verification','Verified','Rejected','Expired','Refunded') NOT NULL DEFAULT 'Pending',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    rejection_reason TEXT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_payment_code (payment_code),
    KEY idx_payments_payable_status (payable_type, payable_id, status),
    KEY idx_payments_status_expires (status, expires_at),
    KEY idx_payments_verified_by (verified_by),
    CONSTRAINT fk_payments_verified_by
        FOREIGN KEY (verified_by) REFERENCES admins (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_proofs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_proofs_payment_uploaded (payment_id, uploaded_at),
    CONSTRAINT fk_payment_proofs_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation_status_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    old_status ENUM('Waiting for DP','Confirmed','Cancelled','Completed','No Show','Expired') NULL,
    new_status ENUM('Waiting for DP','Confirmed','Cancelled','Completed','No Show','Expired') NOT NULL,
    changed_by_type ENUM('customer','admin','system') NOT NULL,
    changed_by_id INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reservation_status_logs_reservation_created (reservation_id, created_at),
    CONSTRAINT fk_reservation_status_logs_reservation
        FOREIGN KEY (reservation_id) REFERENCES reservations (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banners (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    image VARCHAR(255) NOT NULL,
    button_text VARCHAR(80) NULL,
    button_url VARCHAR(255) NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_banners_active_dates_order (is_active, start_date, end_date, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS walkin_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    guest_name VARCHAR(150) NULL,
    guest_phone VARCHAR(20) NULL,
    guest_count INT UNSIGNED NOT NULL,
    status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
    approved_by INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_walkin_requests_status_created (status, created_at),
    KEY idx_walkin_requests_table_status (table_id, status),
    KEY idx_walkin_requests_user (user_id),
    KEY idx_walkin_requests_approved_by (approved_by),
    CONSTRAINT fk_walkin_requests_table
        FOREIGN KEY (table_id) REFERENCES `tables` (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_walkin_requests_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_walkin_requests_admin
        FOREIGN KEY (approved_by) REFERENCES admins (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_logs_admin_created (admin_id, created_at),
    KEY idx_admin_logs_action_created (action, created_at),
    KEY idx_admin_logs_entity (entity_type, entity_id),
    CONSTRAINT fk_admin_logs_admin
        FOREIGN KEY (admin_id) REFERENCES admins (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_resets_email_expiry (email, expires_at),
    KEY idx_password_resets_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_type ENUM('customer','admin') NOT NULL,
    recipient_id INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    link_url VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_recipient_read_created (recipient_type, recipient_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    backup_type ENUM('auto','manual') NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_backups_type_created (backup_type, created_at),
    KEY idx_backups_created_by (created_by),
    CONSTRAINT fk_backups_admin
        FOREIGN KEY (created_by) REFERENCES admins (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `tables`
    ADD CONSTRAINT fk_tables_current_reservation
        FOREIGN KEY (current_reservation_id) REFERENCES reservations (id)
        ON UPDATE CASCADE ON DELETE SET NULL;

INSERT IGNORE INTO admins
    (id, name, username, email, password_hash)
VALUES
    (1, 'CozyCafe Administrator', 'admin', 'admin@cozycafe.com',
     '$2y$10$uYuDnQqwbPYaF2GSqSsXtOJpry.1JsXNS7nAQ6.Vsb9HG.e48Lg4e');

INSERT IGNORE INTO categories (id, name, slug, sort_order, is_active) VALUES
    (1, 'Coffee', 'coffee', 1, 1),
    (2, 'Non-Coffee', 'non-coffee', 2, 1),
    (3, 'Main Course', 'main-course', 3, 1),
    (4, 'Pastry & Dessert', 'pastry-dessert', 4, 1);

INSERT IGNORE INTO products
    (id, category_id, name, slug, image, description, price, stock, is_available, is_featured)
VALUES
    (1, 1, 'Cozy Latte', 'cozy-latte', 'uploads/products/cozy-latte.jpg', 'Espresso dengan susu steamed yang lembut.', 32000.00, 30, 1, 1),
    (2, 1, 'Caramel Macchiato', 'caramel-macchiato', 'uploads/products/caramel-macchiato.jpg', 'Espresso, susu, dan sentuhan saus karamel.', 36000.00, 25, 1, 1),
    (3, 2, 'Matcha Cream', 'matcha-cream', 'uploads/products/matcha-cream.jpg', 'Matcha premium dengan susu dan krim lembut.', 34000.00, 20, 1, 1),
    (4, 2, 'Chocolate Hazelnut', 'chocolate-hazelnut', 'uploads/products/chocolate-hazelnut.jpg', 'Cokelat hangat dengan aroma hazelnut.', 33000.00, 24, 1, 1),
    (5, 3, 'Chicken Teriyaki Rice', 'chicken-teriyaki-rice', 'uploads/products/chicken-teriyaki-rice.jpg', 'Ayam teriyaki disajikan dengan nasi hangat.', 48000.00, 18, 1, 1),
    (6, 3, 'Creamy Mushroom Pasta', 'creamy-mushroom-pasta', 'uploads/products/creamy-mushroom-pasta.jpg', 'Pasta dengan saus krim jamur gurih.', 52000.00, 16, 1, 1),
    (7, 4, 'Butter Croissant', 'butter-croissant', 'uploads/products/butter-croissant.jpg', 'Croissant renyah dengan lapisan mentega.', 24000.00, 20, 1, 1),
    (8, 4, 'Chocolate Lava Cake', 'chocolate-lava-cake', 'uploads/products/chocolate-lava-cake.jpg', 'Kue cokelat dengan bagian tengah yang lumer.', 38000.00, 15, 1, 1);

INSERT IGNORE INTO product_stock_logs
    (id, product_id, admin_id, change_type, qty_change, stock_before, stock_after, notes)
VALUES
    (1, 1, 1, 'initial', 30, 0, 30, 'Stok awal produk seed.'),
    (2, 2, 1, 'initial', 25, 0, 25, 'Stok awal produk seed.'),
    (3, 3, 1, 'initial', 20, 0, 20, 'Stok awal produk seed.'),
    (4, 4, 1, 'initial', 24, 0, 24, 'Stok awal produk seed.'),
    (5, 5, 1, 'initial', 18, 0, 18, 'Stok awal produk seed.'),
    (6, 6, 1, 'initial', 16, 0, 16, 'Stok awal produk seed.'),
    (7, 7, 1, 'initial', 20, 0, 20, 'Stok awal produk seed.'),
    (8, 8, 1, 'initial', 15, 0, 15, 'Stok awal produk seed.');

INSERT IGNORE INTO `tables`
    (id, table_number, capacity, location_area, status)
VALUES
    (1, 'T-01', 2, 'Indoor', 'Tersedia'),
    (2, 'T-02', 2, 'Indoor', 'Tersedia'),
    (3, 'T-03', 4, 'Indoor', 'Tersedia'),
    (4, 'T-04', 4, 'Indoor', 'Tersedia'),
    (5, 'T-05', 4, 'Outdoor', 'Tersedia'),
    (6, 'T-06', 4, 'Outdoor', 'Tersedia'),
    (7, 'T-07', 6, 'Outdoor', 'Tersedia'),
    (8, 'T-08', 6, 'Private Area', 'Tersedia');

INSERT IGNORE INTO banners
    (id, title, description, image, button_text, button_url, start_date, end_date, sort_order, is_active)
VALUES
    (1, 'A Warm Cup for Your Day', 'Nikmati pilihan kopi favorit di CozyCafe.', 'uploads/banners/warm-cup.jpg', 'Lihat Menu', 'customer/menu.php', CURDATE() - INTERVAL 1 DAY, CURDATE() + INTERVAL 30 DAY, 1, 1),
    (2, 'Comfort Food, Cozy Moments', 'Temukan menu andalan untuk menemani waktu santai Anda.', 'uploads/banners/comfort-food.jpg', 'Pesan Sekarang', 'customer/menu.php', CURDATE() - INTERVAL 1 DAY, CURDATE() + INTERVAL 30 DAY, 2, 1);
