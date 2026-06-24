CREATE DATABASE IF NOT EXISTS rentacar_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rentacar_db;

CREATE TABLE IF NOT EXISTS companies (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  legal_name VARCHAR(180) NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  tax_office VARCHAR(120) NULL,
  tax_number VARCHAR(30) NULL,
  mersis_number VARCHAR(30) NULL,
  address TEXT NULL,
  district VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  country VARCHAR(120) NOT NULL DEFAULT 'Turkiye',
  website VARCHAR(180) NULL,
  logo_path VARCHAR(255) NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'viewer',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  archived_by_user_id BIGINT NULL,
  archive_reason VARCHAR(255) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_users_company_archived (company_id, archived_at),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auth_login_throttles (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  username_normalized VARCHAR(80) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  lock_until DATETIME NULL,
  last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_auth_login_throttles_username_ip (username_normalized, ip_address),
  KEY idx_auth_login_throttles_lock_until (lock_until),
  KEY idx_auth_login_throttles_last_attempt_at (last_attempt_at)
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NULL,
  user_id BIGINT NULL,
  event_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT NULL,
  description VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_logs_company_created (company_id, created_at),
  KEY idx_audit_logs_user_created (user_id, created_at),
  KEY idx_audit_logs_event_created (event_type, created_at)
);

CREATE TABLE IF NOT EXISTS app_migrations (
  migration_key VARCHAR(100) PRIMARY KEY,
  executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cars (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  plate VARCHAR(50) NOT NULL,
  brand VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  owner_name VARCHAR(100) NULL,
  telematics_enabled TINYINT(1) NOT NULL DEFAULT 0,
  telematics_provider VARCHAR(100) NULL,
  telematics_device_id VARCHAR(150) NULL,
  telematics_last_odometer_km INT NULL,
  telematics_last_latitude DECIMAL(10,7) NULL,
  telematics_last_longitude DECIMAL(10,7) NULL,
  telematics_ignition_on TINYINT(1) NULL,
  telematics_last_sync_at DATETIME NULL,
  year INT NULL,
  available TINYINT(1) NOT NULL DEFAULT 1,
  archived_at DATETIME NULL,
  archived_by_user_id BIGINT NULL,
  archive_reason VARCHAR(255) NULL,
  sold_at DATETIME NULL,
  sold_by_user_id BIGINT NULL,
  sale_note TEXT NULL,
  inspection_date DATE NULL,
  insurance_date DATE NULL,
  maintenance_date DATE NULL,
  maintenance_note VARCHAR(255) NULL,
  KEY idx_cars_company_archived (company_id, archived_at),
  KEY idx_cars_company_sold (company_id, sold_at)
);

CREATE TABLE IF NOT EXISTS business_expenses (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  title VARCHAR(255) NOT NULL,
  owner_name VARCHAR(100) NULL,
  amount DOUBLE NOT NULL DEFAULT 0,
  expense_date DATE NULL,
  archived_at DATETIME NULL,
  archived_by_user_id BIGINT NULL,
  archive_reason VARCHAR(255) NULL,
  KEY idx_business_expenses_company_archived (company_id, archived_at)
);

CREATE TABLE IF NOT EXISTS customer_companies (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  company_name VARCHAR(180) NOT NULL,
  contact_name VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  tax_office VARCHAR(120) NULL,
  tax_number VARCHAR(30) NULL,
  address TEXT NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer_companies_company_id (company_id)
);

CREATE TABLE IF NOT EXISTS rentals (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  customer_company_id BIGINT NULL,
  customer_name VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(30) NULL,
  customer_identity_no VARCHAR(20) NULL,
  start_date DATETIME NULL,
  end_date DATETIME NULL,
  initial_end_date DATETIME NULL,
  departure_km INT NULL,
  return_km INT NULL,
  income DOUBLE NOT NULL DEFAULT 0,
  collected_amount DOUBLE NULL,
  payment_status VARCHAR(20) NULL,
  payment_due_date DATETIME NULL,
  collected_at DATETIME NULL,
  collected_by_user_id BIGINT NULL,
  expense DOUBLE NOT NULL DEFAULT 0,
  net_profit DOUBLE NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  archived_by_user_id BIGINT NULL,
  archive_reason VARCHAR(255) NULL,
  car_id BIGINT NULL,
  KEY idx_rentals_company_archived (company_id, archived_at),
  KEY idx_rentals_customer_company_id (customer_company_id),
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
  KEY idx_rental_extensions_company_rental (company_id, rental_id),
  CONSTRAINT fk_rental_extensions_rental FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rental_extension_collections (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  rental_extension_id BIGINT NOT NULL,
  amount DOUBLE NOT NULL DEFAULT 0,
  payment_method VARCHAR(30) NULL,
  collection_status VARCHAR(20) NOT NULL DEFAULT 'active',
  cancelled_at DATETIME NULL,
  cancelled_by_user_id BIGINT NULL,
  cancel_reason VARCHAR(255) NULL,
  collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  collected_by_user_id BIGINT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rental_extension_collections_extension_status (company_id, rental_extension_id, collection_status, collected_at)
);

CREATE TABLE IF NOT EXISTS rental_extension_revisions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  rental_extension_id BIGINT NOT NULL,
  rental_id BIGINT NOT NULL,
  action_type VARCHAR(30) NOT NULL,
  payload_before LONGTEXT NULL,
  payload_after LONGTEXT NULL,
  created_by_user_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rental_extension_revisions_company_extension (company_id, rental_extension_id, created_at)
);

CREATE TABLE IF NOT EXISTS document_sequences (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  document_type VARCHAR(50) NOT NULL,
  prefix VARCHAR(20) NOT NULL,
  next_number INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_document_sequences_company_type (company_id, document_type)
);

CREATE TABLE IF NOT EXISTS rental_documents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  rental_id BIGINT NOT NULL,
  document_type VARCHAR(50) NOT NULL,
  document_number VARCHAR(50) NOT NULL,
  sequence_number INT NOT NULL,
  created_by_user_id BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rental_documents_company_rental (company_id, rental_id),
  KEY idx_rental_documents_number (document_number)
);

CREATE TABLE IF NOT EXISTS ledger_partners (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NULL,
  name VARCHAR(100) NOT NULL,
  is_settlement_partner TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_partners_company_sort (company_id, sort_order, id)
);

CREATE TABLE IF NOT EXISTS ledger_periods (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NULL,
  label VARCHAR(150) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME NULL,
  manual_shared_income DOUBLE NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_periods_company_status (company_id, status, id)
);

CREATE TABLE IF NOT EXISTS ledger_entries (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NULL,
  period_id BIGINT NOT NULL,
  partner_id BIGINT NULL,
  business_expense_id BIGINT NULL,
  type VARCHAR(20) NOT NULL,
  car_label VARCHAR(150) NULL,
  amount DOUBLE NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  entry_date DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_entries_company_period_date (company_id, period_id, entry_date, id),
  KEY idx_ledger_entries_company_partner (company_id, partner_id, id),
  CONSTRAINT fk_ledger_entries_period FOREIGN KEY (period_id) REFERENCES ledger_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_entries_partner FOREIGN KEY (partner_id) REFERENCES ledger_partners(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS car_telematics_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  car_id BIGINT NOT NULL,
  provider VARCHAR(100) NULL,
  device_id VARCHAR(150) NULL,
  odometer_km INT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  ignition_on TINYINT(1) NULL,
  payload_json LONGTEXT NULL,
  recorded_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_car_telematics_events_company_car_recorded (company_id, car_id, recorded_at),
  KEY idx_car_telematics_events_provider_device (provider, device_id)
);

CREATE TABLE IF NOT EXISTS car_sales (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  car_id BIGINT NOT NULL,
  buyer_name VARCHAR(150) NOT NULL,
  buyer_phone VARCHAR(30) NULL,
  sale_date DATETIME NOT NULL,
  total_amount DOUBLE NOT NULL DEFAULT 0,
  payment_due_date DATETIME NULL,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  sale_status VARCHAR(20) NOT NULL DEFAULT 'active',
  collected_at DATETIME NULL,
  collected_by_user_id BIGINT NULL,
  created_by_user_id BIGINT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_car_sales_company_car (company_id, car_id),
  KEY idx_car_sales_company_status_due (company_id, sale_status, payment_status, payment_due_date),
  KEY idx_car_sales_company_date (company_id, sale_date)
);

CREATE TABLE IF NOT EXISTS car_sale_collections (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  car_sale_id BIGINT NOT NULL,
  amount DOUBLE NOT NULL DEFAULT 0,
  payment_method VARCHAR(40) NULL,
  note TEXT NULL,
  collection_status VARCHAR(20) NOT NULL DEFAULT 'active',
  collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  collected_by_user_id BIGINT NULL,
  cancelled_at DATETIME NULL,
  cancelled_by_user_id BIGINT NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_car_sale_collections_sale_status (company_id, car_sale_id, collection_status, collected_at),
  KEY idx_car_sale_collections_company_collected (company_id, collected_at)
);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  notification_key VARCHAR(190) NOT NULL,
  source_type VARCHAR(30) NOT NULL DEFAULT 'system',
  event_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT NULL,
  severity VARCHAR(20) NOT NULL DEFAULT 'info',
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  title VARCHAR(180) NOT NULL,
  message VARCHAR(255) NOT NULL,
  due_at DATETIME NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  resolved_at DATETIME NULL,
  metadata_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_notifications_company_key (company_id, notification_key),
  KEY idx_notifications_company_status_due (company_id, status, due_at),
  KEY idx_notifications_company_severity_due (company_id, severity, due_at),
  KEY idx_notifications_entity (entity_type, entity_id)
);
