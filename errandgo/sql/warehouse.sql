-- ErrandGo staging + data warehouse tables. Run after schema.sql (or after upgrade_existing_db.sql).
USE errandgo_db;

-- ===================== STAGING =====================
CREATE TABLE IF NOT EXISTS stg_service_request (
    request_id INT PRIMARY KEY,
    customer_id INT NOT NULL,
    worker_id INT NULL,
    service_type VARCHAR(50),
    pickup_area VARCHAR(60),
    payment_method VARCHAR(20),
    status VARCHAR(20),
    created_at DATETIME,
    accepted_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    rating TINYINT NULL,
    extracted_at DATETIME NOT NULL,
    is_valid TINYINT(1) NOT NULL DEFAULT 1,
    reject_reason VARCHAR(100) NULL
) ENGINE=InnoDB;

-- ===================== DATA WAREHOUSE (star schema) =====================
-- Grain of the fact table: ONE ROW PER SERVICE REQUEST (latest known state).
CREATE TABLE IF NOT EXISTS dw_dim_date (
    date_key INT PRIMARY KEY,                -- YYYYMMDD
    full_date DATE NOT NULL, day_of_month TINYINT, month_num TINYINT, month_name VARCHAR(12),
    quarter_num TINYINT, year_num SMALLINT, weekday_name VARCHAR(12), is_weekend TINYINT(1)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dw_dim_customer (
    customer_key INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL UNIQUE, registration_date DATE NULL
) ENGINE=InnoDB;                              -- no name/email/phone: personal data minimised
CREATE TABLE IF NOT EXISTS dw_dim_worker (
    worker_key INT AUTO_INCREMENT PRIMARY KEY, worker_id INT NOT NULL UNIQUE,
    vehicle_type VARCHAR(30) NULL, verification_status VARCHAR(10) NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dw_dim_service (
    service_key INT AUTO_INCREMENT PRIMARY KEY, service_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dw_dim_location (
    location_key INT AUTO_INCREMENT PRIMARY KEY, pickup_area VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dw_fact_service_request (
    request_id INT PRIMARY KEY,
    date_key INT NOT NULL, hour_of_day TINYINT NOT NULL,
    customer_key INT NOT NULL, worker_key INT NULL, service_key INT NOT NULL, location_key INT NOT NULL,
    payment_method VARCHAR(20) NULL, status VARCHAR(20) NOT NULL,
    is_assigned TINYINT(1) NOT NULL, is_completed TINYINT(1) NOT NULL, is_cancelled TINYINT(1) NOT NULL,
    acceptance_minutes INT NULL, duration_minutes INT NULL, rating TINYINT NULL,
    created_at DATETIME NOT NULL, etl_loaded_at DATETIME NOT NULL,
    FOREIGN KEY (date_key) REFERENCES dw_dim_date(date_key),
    FOREIGN KEY (customer_key) REFERENCES dw_dim_customer(customer_key),
    FOREIGN KEY (worker_key) REFERENCES dw_dim_worker(worker_key),
    FOREIGN KEY (service_key) REFERENCES dw_dim_service(service_key),
    FOREIGN KEY (location_key) REFERENCES dw_dim_location(location_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS dw_etl_log (
    run_id INT AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL, finished_at DATETIME NULL,
    rows_extracted INT DEFAULT 0, rows_loaded INT DEFAULT 0, rows_rejected INT DEFAULT 0,
    status VARCHAR(10) NOT NULL DEFAULT 'running', message VARCHAR(255) NULL
) ENGINE=InnoDB;
