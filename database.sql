CREATE DATABASE IF NOT EXISTS topspot_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE topspot_pos;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    role ENUM('admin', 'sales', 'cashier', 'purchasing', 'accounting', 'inventory') NOT NULL DEFAULT 'sales',
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS parts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    part_name VARCHAR(150) NOT NULL,
    description TEXT,
    supplier_name VARCHAR(120) NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    threshold_qty INT NOT NULL DEFAULT 5,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sales_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    customer_name VARCHAR(120) NOT NULL,
    status ENUM('pending_stock', 'ready_for_cashier', 'paid', 'cancelled') NOT NULL DEFAULT 'ready_for_cashier',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_orders_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_order_id INT NOT NULL,
    part_id INT NOT NULL,
    qty INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_sales_items_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_items_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(50) NOT NULL UNIQUE,
    part_id INT NOT NULL,
    qty_ordered INT NOT NULL,
    status ENUM('requested', 'received', 'cancelled') NOT NULL DEFAULT 'requested',
    source_reference VARCHAR(50) DEFAULT NULL,
    notes TEXT,
    requested_by INT,
    received_by INT,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    received_at TIMESTAMP NULL,
    CONSTRAINT fk_purchase_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_order_id INT NOT NULL UNIQUE,
    cashier_id INT,
    payment_method ENUM('cash', 'gcash', 'card') NOT NULL DEFAULT 'cash',
    amount_paid DECIMAL(12,2) NOT NULL,
    change_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    part_id INT NOT NULL,
    log_type ENUM('sale', 'restock', 'threshold', 'adjustment') NOT NULL,
    qty_change INT NOT NULL,
    resulting_stock INT NOT NULL,
    reference_no VARCHAR(50) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_part FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS digital_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(80) NOT NULL,
    reference_no VARCHAR(50) DEFAULT NULL,
    log_message VARCHAR(255) NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_validated TINYINT(1) NOT NULL DEFAULT 0,
    validated_at TIMESTAMP NULL,
    validated_by INT,
    CONSTRAINT fk_dlog_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_dlog_validated_by FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS general_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    txn_type VARCHAR(50) NOT NULL,
    reference_no VARCHAR(50) DEFAULT NULL,
    account_title VARCHAR(120) NOT NULL,
    debit DECIMAL(12,2) NOT NULL DEFAULT 0,
    credit DECIMAL(12,2) NOT NULL DEFAULT 0,
    description VARCHAR(255) DEFAULT NULL,
    posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_validated TINYINT(1) NOT NULL DEFAULT 0,
    validated_by INT,
    CONSTRAINT fk_gl_validated_by FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO users (full_name, username, role, password_hash)
SELECT 'System Administrator', 'admin', 'admin', '$2y$10$/.PoO5OyRSSsQwAYCROf/eggwPyUPeIMJTfsUSjysdxR1j8W7usTO'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO parts (sku, part_name, description, supplier_name, cost_price, unit_price, stock_qty, threshold_qty)
VALUES
('BP-001', 'Brake Pad Set', 'Front and rear brake pad kit', 'MotoPrime Supply', 350.00, 550.00, 30, 10),
('CH-002', 'Drive Chain 428', 'Heavy duty chain for underbone motorcycles', 'RiderMax Trading', 420.00, 690.00, 22, 8),
('SP-003', 'Spark Plug NGK', 'Standard spark plug for 125cc to 150cc', 'MotoPrime Supply', 90.00, 150.00, 60, 20),
('OL-004', 'Engine Oil 1L', 'Fully synthetic motorcycle engine oil', 'LubeHub Distributor', 210.00, 320.00, 45, 15),
('CL-005', 'Clutch Cable', 'OEM replacement clutch cable', 'GearFlow Industrial', 130.00, 230.00, 18, 7),
('TR-006', 'Inner Tube 17', 'Inner tube for 17-inch tire', 'WheelPoint Supply', 95.00, 170.00, 35, 12),
('FL-007', 'Air Filter Element', 'High flow replacement air filter', 'RiderMax Trading', 150.00, 260.00, 16, 6),
('LG-008', 'Signal Light Bulb', '12V indicator light bulb pair', 'GearFlow Industrial', 40.00, 95.00, 80, 25)
ON DUPLICATE KEY UPDATE
part_name = VALUES(part_name),
description = VALUES(description),
supplier_name = VALUES(supplier_name),
cost_price = VALUES(cost_price),
unit_price = VALUES(unit_price),
stock_qty = VALUES(stock_qty),
threshold_qty = VALUES(threshold_qty);
