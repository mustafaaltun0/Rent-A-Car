<?php

function ensureAuthSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(180) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $companyColumns = [
        'legal_name' => "ALTER TABLE companies ADD COLUMN legal_name VARCHAR(180) NULL AFTER name",
        'email' => "ALTER TABLE companies ADD COLUMN email VARCHAR(150) NULL AFTER legal_name",
        'phone' => "ALTER TABLE companies ADD COLUMN phone VARCHAR(30) NULL AFTER email",
        'tax_office' => "ALTER TABLE companies ADD COLUMN tax_office VARCHAR(120) NULL AFTER phone",
        'tax_number' => "ALTER TABLE companies ADD COLUMN tax_number VARCHAR(30) NULL AFTER tax_office",
        'mersis_number' => "ALTER TABLE companies ADD COLUMN mersis_number VARCHAR(30) NULL AFTER tax_number",
        'address' => "ALTER TABLE companies ADD COLUMN address TEXT NULL AFTER mersis_number",
        'district' => "ALTER TABLE companies ADD COLUMN district VARCHAR(120) NULL AFTER address",
        'city' => "ALTER TABLE companies ADD COLUMN city VARCHAR(120) NULL AFTER district",
        'country' => "ALTER TABLE companies ADD COLUMN country VARCHAR(120) NOT NULL DEFAULT 'Turkiye' AFTER city",
        'website' => "ALTER TABLE companies ADD COLUMN website VARCHAR(180) NULL AFTER country",
        'logo_path' => "ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) NULL AFTER website",
        'updated_at' => "ALTER TABLE companies ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($companyColumns as $column => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM companies LIKE '{$column}'")->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        birth_date DATE NULL,
        bio TEXT NULL,
        avatar_path VARCHAR(255) NULL,
        avatar_focus_x TINYINT UNSIGNED NULL,
        avatar_focus_y TINYINT UNSIGNED NULL,
        role VARCHAR(40) NOT NULL DEFAULT 'viewer',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_roles (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        name VARCHAR(120) NOT NULL,
        role_key VARCHAR(80) NOT NULL,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        UNIQUE KEY uniq_company_roles_company_key (company_id, role_key),
        KEY idx_company_roles_company_active (company_id, is_active, archived_at),
        CONSTRAINT fk_company_roles_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_role_permissions (
        role_id BIGINT NOT NULL,
        permission_key VARCHAR(80) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (role_id, permission_key),
        CONSTRAINT fk_company_role_permissions_role FOREIGN KEY (role_id) REFERENCES company_roles(id) ON DELETE CASCADE
    )");

    $userColumns = [
        'birth_date' => "ALTER TABLE users ADD COLUMN birth_date DATE NULL AFTER password_hash",
        'bio' => "ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER birth_date",
        'avatar_path' => "ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER bio",
        'avatar_focus_x' => "ALTER TABLE users ADD COLUMN avatar_focus_x TINYINT UNSIGNED NULL AFTER avatar_path",
        'avatar_focus_y' => "ALTER TABLE users ADD COLUMN avatar_focus_y TINYINT UNSIGNED NULL AFTER avatar_focus_x",
        'archived_at' => "ALTER TABLE users ADD COLUMN archived_at DATETIME NULL AFTER is_active",
        'archived_by_user_id' => "ALTER TABLE users ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE users ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
        'custom_role_id' => "ALTER TABLE users ADD COLUMN custom_role_id BIGINT NULL AFTER role",
        'updated_at' => "ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($userColumns as $column => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM users LIKE '{$column}'")->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    try {
        $userArchiveIndex = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_company_archived'")->fetch();
        if (!$userArchiveIndex) {
            $pdo->exec("ALTER TABLE users ADD INDEX idx_users_company_archived (company_id, archived_at)");
        }
    } catch (Throwable $e) {
    }

    try {
        $userCustomRoleIndex = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_company_custom_role'")->fetch();
        if (!$userCustomRoleIndex) {
            $pdo->exec("ALTER TABLE users ADD INDEX idx_users_company_custom_role (company_id, custom_role_id)");
        }
    } catch (Throwable $e) {
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_throttles (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username_normalized VARCHAR(80) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        failed_attempts INT NOT NULL DEFAULT 0,
        lock_until DATETIME NULL,
        last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_auth_login_throttles_username_ip (username_normalized, ip_address),
        KEY idx_auth_login_throttles_lock_until (lock_until),
        KEY idx_auth_login_throttles_last_attempt_at (last_attempt_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NULL,
        user_id BIGINT NULL,
        event_type VARCHAR(80) NOT NULL,
        entity_type VARCHAR(80) NULL,
        entity_id BIGINT NULL,
        description VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_audit_logs_company_created (company_id, created_at),
        KEY idx_audit_logs_user_created (user_id, created_at),
        KEY idx_audit_logs_event_created (event_type, created_at)
    )");

    ensureNotificationSchema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (
        migration_key VARCHAR(100) PRIMARY KEY,
        executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $companyCount = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    if ($companyCount === 0) {
        $insert = $pdo->prepare('INSERT INTO companies (name, slug) VALUES (?, ?)');
        $insert->execute(['RentecarWeb Demo', 'rentecarweb-demo']);
    }

    try {
        $pdo->exec("UPDATE companies SET legal_name = name WHERE legal_name IS NULL OR legal_name = ''");
        $pdo->exec("UPDATE companies SET country = 'Turkiye' WHERE country IS NULL OR country = ''");
        $pdo->exec("UPDATE companies SET updated_at = created_at WHERE updated_at IS NULL");
    } catch (Throwable $e) {
    }

    $defaultCompanyId = (int) $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();

    $columns = [
        'cars' => "ALTER TABLE cars ADD COLUMN company_id BIGINT NULL AFTER id",
        'rentals' => "ALTER TABLE rentals ADD COLUMN company_id BIGINT NULL AFTER id",
        'business_expenses' => "ALTER TABLE business_expenses ADD COLUMN company_id BIGINT NULL AFTER id",
        'ledger_partners' => "ALTER TABLE ledger_partners ADD COLUMN company_id BIGINT NULL AFTER id",
        'ledger_periods' => "ALTER TABLE ledger_periods ADD COLUMN company_id BIGINT NULL AFTER id",
        'ledger_entries' => "ALTER TABLE ledger_entries ADD COLUMN company_id BIGINT NULL AFTER id",
    ];

    foreach ($columns as $table => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'company_id'")->fetch();
            if (!$exists) {
                $pdo->exec($sql);
            }
            $update = $pdo->prepare("UPDATE {$table} SET company_id = ? WHERE company_id IS NULL");
            $update->execute([$defaultCompanyId]);
        } catch (Throwable $e) {
        }
    }

    $cleanupDate = date('Y-m-d H:i:s', time() - (auth_login_rate_limit_window_minutes() * 60 * 2));
    try {
        $cleanup = $pdo->prepare('DELETE FROM auth_login_throttles WHERE (lock_until IS NULL AND last_attempt_at < ?) OR (lock_until IS NOT NULL AND lock_until < NOW() AND last_attempt_at < ?)');
        $cleanup->execute([$cleanupDate, $cleanupDate]);
    } catch (Throwable $e) {
    }

    $initialized = true;
}

function ensureNotificationSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
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
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_notifications_company_key (company_id, notification_key),
        KEY idx_notifications_company_status_due (company_id, status, due_at),
        KEY idx_notifications_company_severity_due (company_id, severity, due_at),
        KEY idx_notifications_entity (entity_type, entity_id)
    )");

    $initialized = true;
}

function ensureRentalArchiveSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'archived_at' => "ALTER TABLE rentals ADD COLUMN archived_at DATETIME NULL AFTER completed",
        'archived_by_user_id' => "ALTER TABLE rentals ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE rentals ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $archiveIndexCheck = $pdo->query("SHOW INDEX FROM rentals WHERE Key_name = 'idx_rentals_company_archived'")->fetch();
    if (!$archiveIndexCheck) {
        $pdo->exec("ALTER TABLE rentals ADD INDEX idx_rentals_company_archived (company_id, archived_at)");
    }

    $initialized = true;
}

function ensureRentalExtensionSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $checks = [
        ['initial_end_date', "ALTER TABLE rentals ADD COLUMN initial_end_date DATETIME NULL AFTER end_date"],
        ['customer_phone', "ALTER TABLE rentals ADD COLUMN customer_phone VARCHAR(30) NULL AFTER customer_name"],
        ['customer_identity_no', "ALTER TABLE rentals ADD COLUMN customer_identity_no VARCHAR(20) NULL AFTER customer_phone"],
        ['departure_km', "ALTER TABLE rentals ADD COLUMN departure_km INT NULL AFTER initial_end_date"],
        ['return_km', "ALTER TABLE rentals ADD COLUMN return_km INT NULL AFTER departure_km"],
    ];
    foreach ($checks as [$column, $sql]) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $rentalPaymentColumns = [
        'collected_amount' => "ALTER TABLE rentals ADD COLUMN collected_amount DOUBLE NULL AFTER income",
        'payment_status' => "ALTER TABLE rentals ADD COLUMN payment_status VARCHAR(20) NULL AFTER collected_amount",
        'payment_due_date' => "ALTER TABLE rentals ADD COLUMN payment_due_date DATETIME NULL AFTER payment_status",
        'collected_at' => "ALTER TABLE rentals ADD COLUMN collected_at DATETIME NULL AFTER payment_due_date",
        'collected_by_user_id' => "ALTER TABLE rentals ADD COLUMN collected_by_user_id BIGINT NULL AFTER collected_at",
    ];
    foreach ($rentalPaymentColumns as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'rental_extensions'")->fetch();
    if (!$tableCheck) {
        $pdo->exec("CREATE TABLE rental_extensions (
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
        )");
    }

    $columnMap = [
        'company_id' => "ALTER TABLE rental_extensions ADD COLUMN company_id BIGINT NULL AFTER id",
        'payment_status' => "ALTER TABLE rental_extensions ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'collected' AFTER net_profit",
        'payment_due_date' => "ALTER TABLE rental_extensions ADD COLUMN payment_due_date DATETIME NULL AFTER payment_status",
        'collected_at' => "ALTER TABLE rental_extensions ADD COLUMN collected_at DATETIME NULL AFTER payment_due_date",
        'collected_by_user_id' => "ALTER TABLE rental_extensions ADD COLUMN collected_by_user_id BIGINT NULL AFTER collected_at",
        'extension_status' => "ALTER TABLE rental_extensions ADD COLUMN extension_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER collected_by_user_id",
        'cancelled_at' => "ALTER TABLE rental_extensions ADD COLUMN cancelled_at DATETIME NULL AFTER extension_status",
        'cancelled_by_user_id' => "ALTER TABLE rental_extensions ADD COLUMN cancelled_by_user_id BIGINT NULL AFTER cancelled_at",
        'cancel_reason' => "ALTER TABLE rental_extensions ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by_user_id",
    ];
    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rental_extensions LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $companyBackfillCheck = $pdo->query("SHOW COLUMNS FROM rental_extensions LIKE 'company_id'")->fetch();
    if ($companyBackfillCheck) {
        $pdo->exec("UPDATE rental_extensions re INNER JOIN rentals r ON r.id = re.rental_id SET re.company_id = r.company_id WHERE re.company_id IS NULL");
    }

    $companyIndexCheck = $pdo->query("SHOW INDEX FROM rental_extensions WHERE Key_name = 'idx_rental_extensions_company_rental'")->fetch();
    if (!$companyIndexCheck) {
        $pdo->exec("ALTER TABLE rental_extensions ADD INDEX idx_rental_extensions_company_rental (company_id, rental_id)");
    }

    $collectionTableCheck = $pdo->query("SHOW TABLES LIKE 'rental_extension_collections'")->fetch();
    if (!$collectionTableCheck) {
        $pdo->exec("CREATE TABLE rental_extension_collections (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT NOT NULL,
            rental_extension_id BIGINT NOT NULL,
            amount DOUBLE NOT NULL DEFAULT 0,
            payment_method VARCHAR(30) NULL,
            collection_status VARCHAR(20) NOT NULL DEFAULT 'active',
            cancelled_at DATETIME NULL,
            cancelled_by_user_id BIGINT NULL,
            cancel_reason VARCHAR(255) NULL,
            collected_at DATETIME NOT NULL,
            collected_by_user_id BIGINT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rental_extension_collections_company_extension (company_id, rental_extension_id),
            CONSTRAINT fk_rental_extension_collections_extension FOREIGN KEY (rental_extension_id) REFERENCES rental_extensions(id) ON DELETE CASCADE
        )");
    }

    $collectionColumnCheck = $pdo->query("SHOW COLUMNS FROM rental_extension_collections LIKE 'payment_method'")->fetch();
    if (!$collectionColumnCheck) {
        $pdo->exec("ALTER TABLE rental_extension_collections ADD COLUMN payment_method VARCHAR(30) NULL AFTER amount");
    }

    $collectionColumnMap = [
        'collection_status' => "ALTER TABLE rental_extension_collections ADD COLUMN collection_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER payment_method",
        'cancelled_at' => "ALTER TABLE rental_extension_collections ADD COLUMN cancelled_at DATETIME NULL AFTER collection_status",
        'cancelled_by_user_id' => "ALTER TABLE rental_extension_collections ADD COLUMN cancelled_by_user_id BIGINT NULL AFTER cancelled_at",
        'cancel_reason' => "ALTER TABLE rental_extension_collections ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by_user_id",
    ];
    foreach ($collectionColumnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM rental_extension_collections LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $revisionTableCheck = $pdo->query("SHOW TABLES LIKE 'rental_extension_revisions'")->fetch();
    if (!$revisionTableCheck) {
        $pdo->exec("CREATE TABLE rental_extension_revisions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            company_id BIGINT NOT NULL,
            rental_extension_id BIGINT NOT NULL,
            rental_id BIGINT NOT NULL,
            action_type VARCHAR(30) NOT NULL,
            payload_before LONGTEXT NULL,
            payload_after LONGTEXT NULL,
            created_by_user_id BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rental_extension_revisions_company_extension (company_id, rental_extension_id),
            KEY idx_rental_extension_revisions_company_rental (company_id, rental_id),
            CONSTRAINT fk_rental_extension_revisions_extension FOREIGN KEY (rental_extension_id) REFERENCES rental_extensions(id) ON DELETE CASCADE
        )");
    }

    $initialized = true;
}

function ensureRentalDocumentSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS document_sequences (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        document_type VARCHAR(50) NOT NULL,
        prefix VARCHAR(20) NOT NULL,
        next_number INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_document_sequences_company_type (company_id, document_type)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rental_documents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NOT NULL,
        rental_id BIGINT NOT NULL,
        document_type VARCHAR(50) NOT NULL,
        document_number VARCHAR(50) NOT NULL,
        sequence_number INT NOT NULL,
        created_by_user_id BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rental_documents_company_rental_type (company_id, rental_id, document_type),
        UNIQUE KEY uniq_rental_documents_company_type_seq (company_id, document_type, sequence_number),
        KEY idx_rental_documents_company_rental (company_id, rental_id),
        CONSTRAINT fk_rental_documents_rental FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
    )");

    $initialized = true;
}

function ensureCarOwnerSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $ownerCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE 'owner_name'")->fetch();
    if (!$ownerCheck) {
        $pdo->exec("ALTER TABLE cars ADD COLUMN owner_name VARCHAR(100) NULL AFTER model");
    }

    $initialized = true;
}

function ensureCarPhotoSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'photo_path' => "ALTER TABLE cars ADD COLUMN photo_path VARCHAR(255) NULL AFTER model",
        'photo_position_x' => "ALTER TABLE cars ADD COLUMN photo_position_x VARCHAR(10) NOT NULL DEFAULT 'center' AFTER photo_path",
        'photo_position_y' => "ALTER TABLE cars ADD COLUMN photo_position_y VARCHAR(10) NOT NULL DEFAULT 'center' AFTER photo_position_x",
        'photo_focus_x' => "ALTER TABLE cars ADD COLUMN photo_focus_x TINYINT UNSIGNED NULL AFTER photo_position_y",
        'photo_focus_y' => "ALTER TABLE cars ADD COLUMN photo_focus_y TINYINT UNSIGNED NULL AFTER photo_focus_x",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $initialized = true;
}

function ensureCarTelematicsSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'telematics_enabled' => "ALTER TABLE cars ADD COLUMN telematics_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_name",
        'telematics_provider' => "ALTER TABLE cars ADD COLUMN telematics_provider VARCHAR(100) NULL AFTER telematics_enabled",
        'telematics_device_id' => "ALTER TABLE cars ADD COLUMN telematics_device_id VARCHAR(150) NULL AFTER telematics_provider",
        'telematics_last_odometer_km' => "ALTER TABLE cars ADD COLUMN telematics_last_odometer_km INT NULL AFTER telematics_device_id",
        'telematics_last_latitude' => "ALTER TABLE cars ADD COLUMN telematics_last_latitude DECIMAL(10,7) NULL AFTER telematics_last_odometer_km",
        'telematics_last_longitude' => "ALTER TABLE cars ADD COLUMN telematics_last_longitude DECIMAL(10,7) NULL AFTER telematics_last_latitude",
        'telematics_ignition_on' => "ALTER TABLE cars ADD COLUMN telematics_ignition_on TINYINT(1) NULL AFTER telematics_last_longitude",
        'telematics_last_sync_at' => "ALTER TABLE cars ADD COLUMN telematics_last_sync_at DATETIME NULL AFTER telematics_ignition_on",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $initialized = true;
}

function ensureCarTelematicsEventSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS car_telematics_events (
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
    )");

    $initialized = true;
}

function ensureCarArchiveSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'archived_at' => "ALTER TABLE cars ADD COLUMN archived_at DATETIME NULL AFTER available",
        'archived_by_user_id' => "ALTER TABLE cars ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE cars ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $archiveIndexCheck = $pdo->query("SHOW INDEX FROM cars WHERE Key_name = 'idx_cars_company_archived'")->fetch();
    if (!$archiveIndexCheck) {
        $pdo->exec("ALTER TABLE cars ADD INDEX idx_cars_company_archived (company_id, archived_at)");
    }

    $initialized = true;
}

function ensureCarSaleSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $carColumns = [
        'sold_at' => "ALTER TABLE cars ADD COLUMN sold_at DATETIME NULL AFTER archive_reason",
        'sold_by_user_id' => "ALTER TABLE cars ADD COLUMN sold_by_user_id BIGINT NULL AFTER sold_at",
        'sale_note' => "ALTER TABLE cars ADD COLUMN sale_note TEXT NULL AFTER sold_by_user_id",
    ];

    foreach ($carColumns as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM cars LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $soldIndexCheck = $pdo->query("SHOW INDEX FROM cars WHERE Key_name = 'idx_cars_company_sold'")->fetch();
    if (!$soldIndexCheck) {
        $pdo->exec("ALTER TABLE cars ADD INDEX idx_cars_company_sold (company_id, sold_at)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS car_sales (
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
    )");

    $carSaleColumns = [
        'buyer_phone' => "ALTER TABLE car_sales ADD COLUMN buyer_phone VARCHAR(30) NULL AFTER buyer_name",
        'payment_due_date' => "ALTER TABLE car_sales ADD COLUMN payment_due_date DATETIME NULL AFTER total_amount",
        'payment_status' => "ALTER TABLE car_sales ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER payment_due_date",
        'sale_status' => "ALTER TABLE car_sales ADD COLUMN sale_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER payment_status",
        'collected_at' => "ALTER TABLE car_sales ADD COLUMN collected_at DATETIME NULL AFTER sale_status",
        'collected_by_user_id' => "ALTER TABLE car_sales ADD COLUMN collected_by_user_id BIGINT NULL AFTER collected_at",
        'created_by_user_id' => "ALTER TABLE car_sales ADD COLUMN created_by_user_id BIGINT NULL AFTER collected_by_user_id",
        'note' => "ALTER TABLE car_sales ADD COLUMN note TEXT NULL AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE car_sales ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($carSaleColumns as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM car_sales LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $carSaleIndexes = [
        'idx_car_sales_company_car' => "ALTER TABLE car_sales ADD INDEX idx_car_sales_company_car (company_id, car_id)",
        'idx_car_sales_company_status_due' => "ALTER TABLE car_sales ADD INDEX idx_car_sales_company_status_due (company_id, sale_status, payment_status, payment_due_date)",
        'idx_car_sales_company_date' => "ALTER TABLE car_sales ADD INDEX idx_car_sales_company_date (company_id, sale_date)",
    ];
    foreach ($carSaleIndexes as $indexName => $sql) {
        $indexCheck = $pdo->query("SHOW INDEX FROM car_sales WHERE Key_name = '{$indexName}'")->fetch();
        if (!$indexCheck) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS car_sale_collections (
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
    )");

    $carSaleCollectionColumns = [
        'payment_method' => "ALTER TABLE car_sale_collections ADD COLUMN payment_method VARCHAR(40) NULL AFTER amount",
        'note' => "ALTER TABLE car_sale_collections ADD COLUMN note TEXT NULL AFTER payment_method",
        'collection_status' => "ALTER TABLE car_sale_collections ADD COLUMN collection_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER note",
        'cancelled_at' => "ALTER TABLE car_sale_collections ADD COLUMN cancelled_at DATETIME NULL AFTER collected_by_user_id",
        'cancelled_by_user_id' => "ALTER TABLE car_sale_collections ADD COLUMN cancelled_by_user_id BIGINT NULL AFTER cancelled_at",
        'cancel_reason' => "ALTER TABLE car_sale_collections ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by_user_id",
    ];

    foreach ($carSaleCollectionColumns as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM car_sale_collections LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $carSaleCollectionIndexes = [
        'idx_car_sale_collections_sale_status' => "ALTER TABLE car_sale_collections ADD INDEX idx_car_sale_collections_sale_status (company_id, car_sale_id, collection_status, collected_at)",
        'idx_car_sale_collections_company_collected' => "ALTER TABLE car_sale_collections ADD INDEX idx_car_sale_collections_company_collected (company_id, collected_at)",
    ];
    foreach ($carSaleCollectionIndexes as $indexName => $sql) {
        $indexCheck = $pdo->query("SHOW INDEX FROM car_sale_collections WHERE Key_name = '{$indexName}'")->fetch();
        if (!$indexCheck) {
            $pdo->exec($sql);
        }
    }

    $initialized = true;
}

function ensureExpenseArchiveSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $columnMap = [
        'archived_at' => "ALTER TABLE business_expenses ADD COLUMN archived_at DATETIME NULL AFTER expense_date",
        'archived_by_user_id' => "ALTER TABLE business_expenses ADD COLUMN archived_by_user_id BIGINT NULL AFTER archived_at",
        'archive_reason' => "ALTER TABLE business_expenses ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by_user_id",
    ];

    foreach ($columnMap as $column => $sql) {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM business_expenses LIKE '{$column}'")->fetch();
        if (!$columnCheck) {
            $pdo->exec($sql);
        }
    }

    $archiveIndexCheck = $pdo->query("SHOW INDEX FROM business_expenses WHERE Key_name = 'idx_business_expenses_company_archived'")->fetch();
    if (!$archiveIndexCheck) {
        $pdo->exec("ALTER TABLE business_expenses ADD INDEX idx_business_expenses_company_archived (company_id, archived_at)");
    }

    $initialized = true;
}

function ensureBusinessExpenseOwnerSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $ownerCheck = $pdo->query("SHOW COLUMNS FROM business_expenses LIKE 'owner_name'")->fetch();
    if (!$ownerCheck) {
        $pdo->exec("ALTER TABLE business_expenses ADD COLUMN owner_name VARCHAR(100) NULL AFTER title");
    }

    $initialized = true;
}

function ensureBusinessAccountsSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_partners (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NULL,
        name VARCHAR(100) NOT NULL,
        is_settlement_partner TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ledger_partners_company_sort (company_id, sort_order, id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_periods (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NULL,
        label VARCHAR(150) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        settled_at DATETIME NULL,
        manual_shared_income DOUBLE NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ledger_periods_company_status (company_id, status, id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ledger_entries (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NULL,
        period_id BIGINT NOT NULL,
        partner_id BIGINT NULL,
        car_id BIGINT NULL,
        business_expense_id BIGINT NULL,
        type VARCHAR(20) NOT NULL,
        car_label VARCHAR(150) NULL,
        amount DOUBLE NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        entry_date DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_ledger_entries_period FOREIGN KEY (period_id) REFERENCES ledger_periods(id) ON DELETE CASCADE,
        CONSTRAINT fk_ledger_entries_partner FOREIGN KEY (partner_id) REFERENCES ledger_partners(id) ON DELETE SET NULL,
        KEY idx_ledger_entries_company_period_date (company_id, period_id, entry_date, id),
        KEY idx_ledger_entries_company_partner (company_id, partner_id, id),
        KEY idx_ledger_entries_company_car (company_id, car_id, id)
    )");

    $periodManualIncomeCheck = $pdo->query("SHOW COLUMNS FROM ledger_periods LIKE 'manual_shared_income'")->fetch();
    if (!$periodManualIncomeCheck) {
        $pdo->exec("ALTER TABLE ledger_periods ADD COLUMN manual_shared_income DOUBLE NOT NULL DEFAULT 0 AFTER settled_at");
    }

    $partnerCompanyColumn = $pdo->query("SHOW COLUMNS FROM ledger_partners LIKE 'company_id'")->fetch();
    if (!$partnerCompanyColumn) {
        $pdo->exec("ALTER TABLE ledger_partners ADD COLUMN company_id BIGINT NULL AFTER id");
    }

    $periodCompanyColumn = $pdo->query("SHOW COLUMNS FROM ledger_periods LIKE 'company_id'")->fetch();
    if (!$periodCompanyColumn) {
        $pdo->exec("ALTER TABLE ledger_periods ADD COLUMN company_id BIGINT NULL AFTER id");
    }

    $entryCompanyColumn = $pdo->query("SHOW COLUMNS FROM ledger_entries LIKE 'company_id'")->fetch();
    if (!$entryCompanyColumn) {
        $pdo->exec("ALTER TABLE ledger_entries ADD COLUMN company_id BIGINT NULL AFTER id");
    }

    $entryCarIdColumn = $pdo->query("SHOW COLUMNS FROM ledger_entries LIKE 'car_id'")->fetch();
    if (!$entryCarIdColumn) {
        $pdo->exec("ALTER TABLE ledger_entries ADD COLUMN car_id BIGINT NULL AFTER partner_id");
    }

    $partnerCompanyIndex = $pdo->query("SHOW INDEX FROM ledger_partners WHERE Key_name = 'idx_ledger_partners_company_sort'")->fetch();
    if (!$partnerCompanyIndex) {
        $pdo->exec("ALTER TABLE ledger_partners ADD INDEX idx_ledger_partners_company_sort (company_id, sort_order, id)");
    }

    $periodCompanyIndex = $pdo->query("SHOW INDEX FROM ledger_periods WHERE Key_name = 'idx_ledger_periods_company_status'")->fetch();
    if (!$periodCompanyIndex) {
        $pdo->exec("ALTER TABLE ledger_periods ADD INDEX idx_ledger_periods_company_status (company_id, status, id)");
    }

    $entryPeriodIndex = $pdo->query("SHOW INDEX FROM ledger_entries WHERE Key_name = 'idx_ledger_entries_company_period_date'")->fetch();
    if (!$entryPeriodIndex) {
        $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_entries_company_period_date (company_id, period_id, entry_date, id)");
    }

    $entryPartnerIndex = $pdo->query("SHOW INDEX FROM ledger_entries WHERE Key_name = 'idx_ledger_entries_company_partner'")->fetch();
    if (!$entryPartnerIndex) {
        $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_entries_company_partner (company_id, partner_id, id)");
    }

    $entryCarIndex = $pdo->query("SHOW INDEX FROM ledger_entries WHERE Key_name = 'idx_ledger_entries_company_car'")->fetch();
    if (!$entryCarIndex) {
        $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_entries_company_car (company_id, car_id, id)");
    }

    $initialized = true;
}

function ensureCustomerCompanySchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_companies (
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
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $customerCompanyIndexCheck = $pdo->query("SHOW INDEX FROM customer_companies WHERE Key_name = 'idx_customer_companies_company_id'")->fetch();
    if (!$customerCompanyIndexCheck) {
        $pdo->exec("ALTER TABLE customer_companies ADD INDEX idx_customer_companies_company_id (company_id)");
    }

    $rentalCustomerCompanyCheck = $pdo->query("SHOW COLUMNS FROM rentals LIKE 'customer_company_id'")->fetch();
    if (!$rentalCustomerCompanyCheck) {
        $pdo->exec("ALTER TABLE rentals ADD COLUMN customer_company_id BIGINT NULL AFTER company_id");
    }

    $rentalCustomerCompanyIndexCheck = $pdo->query("SHOW INDEX FROM rentals WHERE Key_name = 'idx_rentals_customer_company_id'")->fetch();
    if (!$rentalCustomerCompanyIndexCheck) {
        $pdo->exec("ALTER TABLE rentals ADD INDEX idx_rentals_customer_company_id (customer_company_id)");
    }

    $initialized = true;
}

function app_ensure_schema(PDO $pdo, string ...$modules): void
{
    static $dispatch = [
        'auth' => ['ensureAuthSchema'],
        'rental_archive' => ['ensureRentalArchiveSchema'],
        'rental_core' => ['rental_archive', 'ensureRentalExtensionSchema'],
        'rental_documents' => ['rental_core', 'ensureRentalDocumentSchema'],
        'car_core' => [
            'ensureCarOwnerSchema',
            'ensureCarPhotoSchema',
            'ensureCarTelematicsSchema',
            'ensureCarTelematicsEventSchema',
            'ensureCarArchiveSchema',
        ],
        'car_sales' => ['car_core', 'ensureCarSaleSchema'],
        'finance_core' => [
            'ensureExpenseArchiveSchema',
            'ensureBusinessExpenseOwnerSchema',
            'ensureBusinessAccountsSchema',
        ],
        'customer_companies' => ['ensureCustomerCompanySchema'],
        'notifications' => ['ensureNotificationSchema'],
        'support_modules' => ['customer_companies', 'notifications'],
    ];

    $resolved = [];
    $pending = $modules;

    while ($pending !== []) {
        $module = array_shift($pending);
        $module = trim($module);

        if ($module === '' || isset($resolved[$module])) {
            continue;
        }

        if (isset($dispatch[$module])) {
            $resolved[$module] = true;
            foreach ($dispatch[$module] as $item) {
                if (isset($dispatch[$item])) {
                    $pending[] = $item;
                    continue;
                }

                if (!function_exists($item)) {
                    throw new RuntimeException('Schema helper bulunamadi: ' . $item);
                }

                $item($pdo);
            }

            continue;
        }

        if (!function_exists($module)) {
            throw new RuntimeException('Schema modulu tanimsiz: ' . $module);
        }

        $resolved[$module] = true;
        $module($pdo);
    }
}
