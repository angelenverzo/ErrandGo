-- Run this ONCE on your existing errandgo_db (XAMPP uses MariaDB, which supports IF NOT EXISTS on columns).
-- Then run warehouse.sql (it only creates new tables).
USE errandgo_db;

ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'customer';
ALTER TABLE users ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(30) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS capabilities VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_status VARCHAR(10) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_available TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS pickup_area VARCHAR(60) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS accepted_at DATETIME NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS started_at DATETIME NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL;
ALTER TABLE service_requests ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Existing workers keep working: mark them verified. New workers start as 'pending'.
UPDATE users SET verification_status = 'verified' WHERE role = 'worker' AND verification_status IS NULL;

-- Align old service names with the business profile.
UPDATE service_requests SET service_type = 'Office-Supply Purchasing' WHERE service_type = 'Business Errands';
UPDATE service_requests SET service_type = 'Other Errands' WHERE service_type = 'Other';

CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    worker_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment_category VARCHAR(40) NULL,
    comment VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
