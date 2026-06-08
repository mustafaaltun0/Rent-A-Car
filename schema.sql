CREATE DATABASE IF NOT EXISTS rentacar_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rentacar_db;

CREATE TABLE IF NOT EXISTS cars (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  plate VARCHAR(50) NOT NULL,
  brand VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  year INT DEFAULT NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  inspection_date DATE NULL,
  insurance_date DATE NULL,
  maintenance_date DATE NULL,
  maintenance_note VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS business_expenses (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  amount DOUBLE NOT NULL DEFAULT 0,
  expense_date DATE NULL
);

CREATE TABLE IF NOT EXISTS rentals (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(255) NOT NULL,
  start_date DATETIME NULL,
  end_date DATETIME NULL,
  initial_end_date DATETIME NULL,
  income DOUBLE NOT NULL DEFAULT 0,
  expense DOUBLE NOT NULL DEFAULT 0,
  net_profit DOUBLE NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  car_id BIGINT NULL,
  CONSTRAINT fk_rentals_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS rental_extensions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NULL,
  rental_id BIGINT NOT NULL,
  previous_end_date DATETIME NULL,
  new_end_date DATETIME NOT NULL,
  income DOUBLE NOT NULL DEFAULT 0,
  expense DOUBLE NOT NULL DEFAULT 0,
  net_profit DOUBLE NOT NULL DEFAULT 0,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'collected',
  payment_due_date DATETIME NULL,
  collected_at DATETIME NULL,
  collected_by_user_id BIGINT NULL,
  extension_status VARCHAR(20) NOT NULL DEFAULT 'active',
  cancelled_at DATETIME NULL,
  cancelled_by_user_id BIGINT NULL,
  cancel_reason VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rental_extensions_rental FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
);
