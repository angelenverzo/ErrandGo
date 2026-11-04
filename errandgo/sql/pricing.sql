-- ErrandGo fees (service fee + delivery fee, flat within Mandaluyong City). Import once.
-- The rates below are SAMPLE / PROPOSED values for the prototype; management edits them in admin_pricing.php.
-- Needs MariaDB (XAMPP) for ADD COLUMN IF NOT EXISTS.
USE errandgo_db;

CREATE TABLE IF NOT EXISTS pricing_rules (
    service_type VARCHAR(50) PRIMARY KEY,
    service_fee DECIMAL(8,2) NOT NULL,
    delivery_fee DECIMAL(8,2) NOT NULL,
    worker_share_pct DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO pricing_rules (service_type, service_fee, delivery_fee, worker_share_pct) VALUES
('Food Delivery', 20.00, 30.00, 80.00),
('Grocery Shopping', 40.00, 30.00, 80.00),
('Parcel/Document Delivery', 20.00, 30.00, 80.00),
('Office-Supply Purchasing', 30.00, 30.00, 80.00),
('Moving Assistance', 150.00, 50.00, 80.00),
('Other Errands', 30.00, 30.00, 80.00);

-- Fee snapshot saved on each request (old requests keep NULL fees).
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS service_fee DECIMAL(8,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(8,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS item_cost_est DECIMAL(10,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS est_total DECIMAL(10,2) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS worker_pay DECIMAL(8,2) NULL;

-- Warehouse: staging + fact measures
ALTER TABLE stg_service_request ADD COLUMN IF NOT EXISTS service_fee DECIMAL(8,2) NULL;
ALTER TABLE stg_service_request ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(8,2) NULL;
ALTER TABLE stg_service_request ADD COLUMN IF NOT EXISTS worker_pay DECIMAL(8,2) NULL;
ALTER TABLE dw_fact_service_request ADD COLUMN IF NOT EXISTS est_fee DECIMAL(9,2) NULL;
ALTER TABLE dw_fact_service_request ADD COLUMN IF NOT EXISTS worker_pay DECIMAL(9,2) NULL;
-- Re-extract old requests on the next ETL run so the new columns are filled where available.
UPDATE service_requests SET updated_at = NOW();
