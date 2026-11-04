-- ErrandGo database (XAMPP / MariaDB). Fresh install.
CREATE DATABASE IF NOT EXISTS errandgo_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE errandgo_db;

-- ===================== OPERATIONAL (OLTP) =====================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'customer',          -- customer | worker | admin
    vehicle_type VARCHAR(30) NULL,                          -- workers only
    capabilities VARCHAR(255) NULL,                         -- workers only: comma-separated service types
    verification_status VARCHAR(10) NULL,                   -- workers only: pending | verified | rejected
    is_available TINYINT(1) NOT NULL DEFAULT 1,             -- workers only
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    worker_id INT NULL,
    service_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    pickup_location VARCHAR(255) NOT NULL,
    pickup_area VARCHAR(60) NULL,
    dropoff_location VARCHAR(255) NULL,
    preferred_date DATE NOT NULL,
    preferred_time TIME NOT NULL,
    payment_method VARCHAR(20) NULL,                        -- preference only (no payment processing yet)
    status VARCHAR(20) NOT NULL DEFAULT 'pending',          -- pending|accepted|in_progress|completed|cancelled
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (worker_id) REFERENCES users(id),
    INDEX idx_status (status), INDEX idx_created (created_at), INDEX idx_updated (updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    customer_id INT NOT NULL,
    worker_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment_category VARCHAR(40) NULL,
    comment VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES service_requests(id)
) ENGINE=InnoDB;

-- In-app notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(100) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Next: run warehouse.sql (staging + data warehouse tables).
