-- ErrandGo Update 6. Import ONCE (fresh or existing database), AFTER schema/upgrade, warehouse.sql, notifications.sql and pricing.sql.
-- Needs MariaDB (XAMPP) for ADD COLUMN IF NOT EXISTS. Safe to re-run.
USE errandgo_db;

-- ===== 2. Audit logs: who / when / where / what =====
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- when
    user_id INT NULL,                                         -- who (NULL = not logged in / system)
    user_name VARCHAR(100) NULL,
    user_role VARCHAR(20) NULL,
    action VARCHAR(50) NOT NULL,                              -- what (code)
    entity_type VARCHAR(30) NULL,
    entity_id INT NULL,
    details VARCHAR(500) NULL,                                -- what (readable)
    ip_address VARCHAR(45) NULL,                              -- where
    page VARCHAR(120) NULL,                                   -- where (page that did it)
    user_agent VARCHAR(255) NULL,                             -- where (browser / device)
    INDEX idx_created (created_at), INDEX idx_user (user_id), INDEX idx_action (action)
) ENGINE=InnoDB;

-- ===== 1 + 7. Automatic tasks (ETL refresh, release of un-started acceptances) =====
CREATE TABLE IF NOT EXISTS scheduler_state (
    task VARCHAR(40) PRIMARY KEY,
    last_run DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO scheduler_state (task, last_run) VALUES
('expire_accepted', '2000-01-01 00:00:00'),
('etl', '2000-01-01 00:00:00');

-- ===== 8. Several errands in one booking =====
CREATE TABLE IF NOT EXISTS request_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    errand_count TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===== 3 + 4. Item specification (only when the worker must buy items) =====
CREATE TABLE IF NOT EXISTS errand_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    quantity SMALLINT NOT NULL DEFAULT 1,
    specification VARCHAR(255) NULL,                          -- brand / size / variant / notes
    INDEX idx_request (request_id),
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== 10. Additional riders on a bulk errand =====
CREATE TABLE IF NOT EXISTS request_riders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    worker_id INT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',             -- active | released
    pay DECIMAL(8,2) NOT NULL DEFAULT 0,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL,
    UNIQUE KEY uq_request_worker (request_id, worker_id),
    INDEX idx_worker (worker_id, status),
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (worker_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===== 7. Who was released from which errand (they cannot re-take that same errand) =====
CREATE TABLE IF NOT EXISTS request_releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    worker_id INT NOT NULL,
    reason VARCHAR(100) NULL,
    released_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request_worker (request_id, worker_id),
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== 9. Weight tiers (admin edits the fees in Pricing) =====
CREATE TABLE IF NOT EXISTS weight_tiers (
    tier_no TINYINT PRIMARY KEY,
    label VARCHAR(40) NOT NULL,
    max_kg DECIMAL(6,1) NOT NULL,
    fee DECIMAL(8,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;
-- SAMPLE / PROPOSED values for the prototype.
INSERT IGNORE INTO weight_tiers (tier_no, label, max_kg, fee) VALUES
(1, 'Light', 5.0, 0.00),
(2, 'Medium', 15.0, 30.00),
(3, 'Heavy', 30.0, 80.00),
(4, 'Very heavy', 60.0, 150.00),
(5, 'Extra heavy', 100.0, 250.00),
(6, 'Bulky load', 300.0, 400.00);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(40) PRIMARY KEY,
    setting_value VARCHAR(100) NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('extra_rider_fee', '50.00');  -- SAMPLE, per additional rider

-- ===== 6. Per-kilometre fee (added on top of the current service + delivery fee) =====
ALTER TABLE pricing_rules ADD COLUMN IF NOT EXISTS per_km_fee DECIMAL(8,2) NOT NULL DEFAULT 10.00;   -- SAMPLE rate

-- ===== Errand request columns =====
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS batch_id INT NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS dropoff_area VARCHAR(60) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS needs_purchase TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(6,1) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS weight_desc VARCHAR(255) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS weight_tier TINYINT NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS weight_fee DECIMAL(8,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS distance_km DECIMAL(5,1) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS per_km_fee DECIMAL(8,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS distance_fee DECIMAL(8,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS is_bulk TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS riders_needed TINYINT NOT NULL DEFAULT 1;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS extra_rider_fee DECIMAL(8,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS extra_riders_fee DECIMAL(8,2) NOT NULL DEFAULT 0;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS fee_total DECIMAL(10,2) NULL;   -- all fees, WITHOUT the item cost

-- ===== 11. Rating: several tapped checkboxes =====
ALTER TABLE ratings ADD COLUMN IF NOT EXISTS tags VARCHAR(255) NULL;

-- ===== Warehouse: total fee measure =====
ALTER TABLE stg_service_request ADD COLUMN IF NOT EXISTS est_fee DECIMAL(9,2) NULL;

-- ===== Back-fill old requests =====
UPDATE service_requests SET needs_purchase = 1 WHERE item_cost_est IS NOT NULL AND needs_purchase = 0;
UPDATE service_requests SET fee_total = service_fee + COALESCE(delivery_fee, 0) WHERE fee_total IS NULL AND service_fee IS NOT NULL;
-- Re-extract every request on the next automatic ETL run.
UPDATE service_requests SET updated_at = NOW();
