CREATE DATABASE IF NOT EXISTS vendlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vendlink;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    businessName VARCHAR(100) NULL,
    address TEXT NULL,
    contactNumber VARCHAR(30) NULL,
    gcashNumber VARCHAR(30) NULL,
    paymayaNumber VARCHAR(30) NULL,
    username VARCHAR(50) UNIQUE NULL,
    role ENUM('admin', 'vendor', 'supplier') NOT NULL DEFAULT 'vendor',
    profileImage VARCHAR(255) NULL,
    isActive TINYINT(1) DEFAULT 1,
    twoFactorEnabled TINYINT(1) DEFAULT 0,
    twoFactorTempCode VARCHAR(10) NULL,
    twoFactorExpires DATETIME NULL,
    notificationsEnabled TINYINT(1) NOT NULL DEFAULT 1,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 1b. Vendor Delivery Addresses
CREATE TABLE IF NOT EXISTS delivery_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendorId VARCHAR(36) NOT NULL,
    recipientName VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    addressLine1 VARCHAR(255) NOT NULL,
    addressLine2 VARCHAR(255) NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NULL,
    postalCode VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'Philippines',
    deliveryNotes TEXT NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    isDefault TINYINT(1) NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_delivery_addresses_vendor FOREIGN KEY (vendorId) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_delivery_addresses_vendor_default (vendorId, isDefault, updatedAt)
);

-- 2. Products Table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description TEXT NULL,
    stockQuantity INT NOT NULL DEFAULT 0,
    demandStatus ENUM('low', 'medium', 'high') DEFAULT 'medium',
    category VARCHAR(50) NULL,
    supplierId VARCHAR(36) NOT NULL,
    supplierName VARCHAR(100) NULL,
    imageUrl VARCHAR(255) NULL,
    hasVariants TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'approved', 'denied') DEFAULT 'approved',
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplierId) REFERENCES users(id) ON DELETE CASCADE
);

-- 2b. Product Variants
CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    productId INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stockQuantity INT NOT NULL DEFAULT 0,
    imageUrl VARCHAR(255) NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_variants_product FOREIGN KEY (productId) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_product_variant_name (productId, name),
    UNIQUE KEY uq_product_variant_sku (productId, sku),
    INDEX idx_product_variants_product (productId)
);

-- 3. Orders Table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendorId VARCHAR(36) NOT NULL,
    vendorName VARCHAR(100) NULL,
    vendorBusinessName VARCHAR(100) NULL,
    vendorAddress TEXT NULL,
    vendorContactNumber VARCHAR(30) NULL,
    supplierId VARCHAR(36) NOT NULL,
    supplierName VARCHAR(100) NULL,
    supplierBusinessName VARCHAR(100) NULL,
    supplierAddress TEXT NULL,
    supplierContactNumber VARCHAR(30) NULL,
    status ENUM('pending', 'confirmed', 'delivered', 'cancelled') DEFAULT 'pending',
    totalAmount DECIMAL(10, 2) NOT NULL,
    paymentMethod VARCHAR(50) DEFAULT 'Cash on Delivery',
    deliveryAddress TEXT NULL,
    deliveryNotes TEXT NULL,
    supplierOrderNumber INT NOT NULL DEFAULT 1,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_vendor FOREIGN KEY (vendorId) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_supplier FOREIGN KEY (supplierId) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_orders_supplier_number (supplierId, supplierOrderNumber)
);

-- 4. Order Items
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    orderId INT NOT NULL,
    productId INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    priceAtOrder DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL,
    imageUrl VARCHAR(255) NULL,
    variantId INT NULL,
    variantName VARCHAR(100) NULL,
    CONSTRAINT fk_items_order FOREIGN KEY (orderId) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_product FOREIGN KEY (productId) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_items_variant FOREIGN KEY (variantId) REFERENCES product_variants(id) ON DELETE RESTRICT
);

-- 5. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    isRead TINYINT(1) DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed Default Admin, Vendor, Supplier (Password is: password / admin123)
-- Passwords hashed via password_hash('admin123', PASSWORD_BCRYPT)
INSERT IGNORE INTO users (id, email, password, name, role, username) 
VALUES ('admin-01', 'admin@vendlink.com', '$2y$10$w8TKnNvhq5dZ0Uj0xW13jO2B.qUe.gH2Vq0u8B0qUe.gH2Vq0u8B0', 'System Admin', 'admin', 'admin');

-- Add missing recommended columns for security and tracking (safe ALTERs)
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS failedAttempts INT DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lockedUntil DATETIME NULL,
    ADD COLUMN IF NOT EXISTS twoFactorTempCode VARCHAR(10) NULL,
    ADD COLUMN IF NOT EXISTS twoFactorExpires DATETIME NULL,
    ADD COLUMN IF NOT EXISTS backupCodes TEXT NULL,
    ADD COLUMN IF NOT EXISTS gcashNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS paymayaNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS notificationsEnabled TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS rejectionReason TEXT NULL,
    ADD COLUMN IF NOT EXISTS reviewedAt DATETIME NULL,
    ADD COLUMN IF NOT EXISTS updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS hasVariants TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS variantId INT NULL,
    ADD COLUMN IF NOT EXISTS variantName VARCHAR(100) NULL;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS deliveryAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS deliveryNotes TEXT NULL,
    ADD COLUMN IF NOT EXISTS vendorBusinessName VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS vendorAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS vendorContactNumber VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS supplierBusinessName VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS supplierAddress TEXT NULL,
    ADD COLUMN IF NOT EXISTS supplierContactNumber VARCHAR(30) NULL;

ALTER TABLE orders
    ADD INDEX IF NOT EXISTS idx_orders_vendor_created (vendorId, createdAt),
    ADD INDEX IF NOT EXISTS idx_orders_supplier_created (supplierId, createdAt),
    ADD INDEX IF NOT EXISTS idx_orders_status (status),
    ADD UNIQUE INDEX IF NOT EXISTS uq_orders_supplier_number (supplierId, supplierOrderNumber);

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS imageUrl VARCHAR(255) NULL;

ALTER TABLE products
    ADD INDEX IF NOT EXISTS idx_products_marketplace (status, supplierId),
    ADD INDEX IF NOT EXISTS idx_products_supplier_created (supplierId, createdAt);

ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notifications_user_read (userId, isRead, createdAt);

-- Security logs table
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(36) NULL,
    ip VARCHAR(45) NULL,
    action VARCHAR(100) NOT NULL,
    meta TEXT NULL,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(id) ON DELETE SET NULL
);

-- Simple key/value settings
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NULL,
    updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Note: Use the provided `scripts/seed.php` to create default seeded accounts with secure bcrypt hashes.