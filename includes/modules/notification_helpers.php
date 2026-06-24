<?php

function notifications_open_count(PDO $pdo, int $companyId): int
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE company_id = ? AND status = 'open'");
    $st->execute([$companyId]);
    return (int) $st->fetchColumn();
}

function notifications_summary(PDO $pdo, int $companyId): array
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_count,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN status = 'open' AND severity = 'danger' THEN 1 ELSE 0 END) AS critical_open_count
        FROM notifications
        WHERE company_id = ?
    ");
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'open_count' => (int) ($row['open_count'] ?? 0),
        'read_count' => (int) ($row['read_count'] ?? 0),
        'resolved_count' => (int) ($row['resolved_count'] ?? 0),
        'critical_open_count' => (int) ($row['critical_open_count'] ?? 0),
    ];
}

function notifications_fetch(PDO $pdo, int $companyId, string $status = 'open', int $limit = 100): array
{
    ensureNotificationSchema($pdo);
    $limit = max(1, min(250, $limit));
    $allowedStatuses = ['open', 'read', 'resolved', 'active'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'open';
    }

    if ($status === 'active') {
        $st = $pdo->prepare("
            SELECT *
            FROM notifications
            WHERE company_id = ? AND status <> 'resolved'
            ORDER BY
                CASE status
                    WHEN 'open' THEN 0
                    WHEN 'read' THEN 1
                    ELSE 2
                END,
                CASE severity
                    WHEN 'danger' THEN 0
                    WHEN 'warning' THEN 1
                    ELSE 2
                END,
                COALESCE(due_at, created_at) ASC,
                id DESC
            LIMIT {$limit}
        ");
        $st->execute([$companyId]);
    } else {
        $st = $pdo->prepare("
            SELECT *
            FROM notifications
            WHERE company_id = ? AND status = ?
            ORDER BY
                CASE severity
                    WHEN 'danger' THEN 0
                    WHEN 'warning' THEN 1
                    ELSE 2
                END,
                COALESCE(due_at, created_at) ASC,
                id DESC
            LIMIT {$limit}
        ");
        $st->execute([$companyId, $status]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['metadata'] = [];
        if (!empty($row['metadata_json'])) {
            try {
                $decoded = json_decode((string) $row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $row['metadata'] = $decoded;
                }
            } catch (Throwable $exception) {
                $row['metadata'] = [];
            }
        }
    }
    unset($row);

    return $rows;
}

function notification_target_url(array $notification): ?string
{
    $entityType = (string) ($notification['entity_type'] ?? '');
    $entityId = (int) ($notification['entity_id'] ?? 0);
    $metadata = $notification['metadata'] ?? [];

    if ($entityType === 'rental' && $entityId > 0) {
        return 'rental_detail.php?id=' . $entityId;
    }

    if ($entityType === 'rental_extension' && !empty($metadata['rental_id'])) {
        return 'rental_detail.php?id=' . (int) $metadata['rental_id'] . '#extension-' . $entityId;
    }

    if ($entityType === 'car' && $entityId > 0) {
        return 'car_detail.php?id=' . $entityId;
    }

    return null;
}

function notifications_mark_read(PDO $pdo, int $companyId, int $notificationId): bool
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = CASE WHEN status = 'open' THEN 'read' ELSE status END,
            read_at = CASE WHEN status = 'open' THEN NOW() ELSE read_at END,
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status = 'open'
    ");
    $st->execute([$notificationId, $companyId]);
    return $st->rowCount() > 0;
}

function notifications_mark_all_read(PDO $pdo, int $companyId): int
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = 'read',
            read_at = COALESCE(read_at, NOW()),
            updated_at = NOW()
        WHERE company_id = ? AND status = 'open'
    ");
    $st->execute([$companyId]);
    return $st->rowCount();
}

function notifications_resolve(PDO $pdo, int $companyId, int $notificationId): bool
{
    ensureNotificationSchema($pdo);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = 'resolved',
            read_at = COALESCE(read_at, NOW()),
            resolved_at = NOW(),
            updated_at = NOW()
        WHERE id = ? AND company_id = ? AND status <> 'resolved'
    ");
    $st->execute([$notificationId, $companyId]);
    return $st->rowCount() > 0;
}

function notifications_upsert_system(PDO $pdo, int $companyId, array $payload): void
{
    ensureNotificationSchema($pdo);

    $notificationKey = trim((string) ($payload['notification_key'] ?? ''));
    if ($notificationKey === '') {
        return;
    }

    $metadataJson = null;
    if (isset($payload['metadata']) && is_array($payload['metadata'])) {
        try {
            $metadataJson = json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $metadataJson = null;
        }
    }

    $st = $pdo->prepare("
        INSERT INTO notifications (
            company_id, notification_key, source_type, event_type, entity_type, entity_id,
            severity, status, title, message, due_at, first_seen_at, last_seen_at,
            metadata_json, created_at, updated_at
        ) VALUES (
            ?, ?, 'system', ?, ?, ?, ?, 'open', ?, ?, ?, NOW(), NOW(), ?, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            event_type = VALUES(event_type),
            entity_type = VALUES(entity_type),
            entity_id = VALUES(entity_id),
            severity = VALUES(severity),
            title = VALUES(title),
            message = VALUES(message),
            due_at = VALUES(due_at),
            last_seen_at = NOW(),
            metadata_json = VALUES(metadata_json),
            updated_at = NOW(),
            status = CASE WHEN notifications.status = 'resolved' THEN 'open' ELSE notifications.status END,
            resolved_at = CASE WHEN notifications.status = 'resolved' THEN NULL ELSE notifications.resolved_at END
    ");

    $st->execute([
        $companyId,
        $notificationKey,
        (string) ($payload['event_type'] ?? 'system.notice'),
        $payload['entity_type'] ?? null,
        isset($payload['entity_id']) ? (int) $payload['entity_id'] : null,
        (string) ($payload['severity'] ?? 'info'),
        (string) ($payload['title'] ?? 'Bildirim'),
        (string) ($payload['message'] ?? ''),
        $payload['due_at'] ?? null,
        $metadataJson,
    ]);
}

function notifications_resolve_missing_system(PDO $pdo, int $companyId, array $activeKeys): void
{
    ensureNotificationSchema($pdo);

    if (empty($activeKeys)) {
        $st = $pdo->prepare("
            UPDATE notifications
            SET status = 'resolved',
                resolved_at = COALESCE(resolved_at, NOW()),
                updated_at = NOW()
            WHERE company_id = ? AND source_type = 'system' AND status <> 'resolved'
        ");
        $st->execute([$companyId]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($activeKeys), '?'));
    $params = array_merge([$companyId], $activeKeys);
    $st = $pdo->prepare("
        UPDATE notifications
        SET status = 'resolved',
            resolved_at = COALESCE(resolved_at, NOW()),
            updated_at = NOW()
        WHERE company_id = ?
          AND source_type = 'system'
          AND status <> 'resolved'
          AND notification_key NOT IN ($placeholders)
    ");
    $st->execute($params);
}

function notifications_sync_operational(PDO $pdo, int $companyId): array
{
    app_ensure_schema($pdo, 'notifications', 'rental_core', 'car_core');

    $activeKeys = [];
    $today = new DateTimeImmutable(date('Y-m-d'));
    $now = new DateTimeImmutable(date('Y-m-d H:i:s'));

    $carsSt = $pdo->prepare("
        SELECT id, brand, model, plate, inspection_date, insurance_date, maintenance_date
        FROM cars
        WHERE company_id = ? AND archived_at IS NULL
    ");
    $carsSt->execute([$companyId]);
    $cars = $carsSt->fetchAll(PDO::FETCH_ASSOC);

    $carDateFields = [
        'inspection_date' => ['event' => 'car.inspection_due', 'title' => 'Muayene takibi', 'label' => 'muayenesi'],
        'insurance_date' => ['event' => 'car.insurance_due', 'title' => 'Sigorta takibi', 'label' => 'sigortasi'],
        'maintenance_date' => ['event' => 'car.maintenance_due', 'title' => 'Bakim takibi', 'label' => 'bakimi'],
    ];

    foreach ($cars as $car) {
        $carId = (int) ($car['id'] ?? 0);
        $carLabel = trim(($car['brand'] ?? '') . ' ' . ($car['model'] ?? '') . ' - ' . ($car['plate'] ?? ''));

        foreach ($carDateFields as $field => $meta) {
            $rawDate = trim((string) ($car[$field] ?? ''));
            if ($rawDate === '') {
                continue;
            }

            try {
                $targetDate = new DateTimeImmutable(date('Y-m-d', strtotime($rawDate)));
            } catch (Throwable $exception) {
                continue;
            }

            $daysLeft = (int) $today->diff($targetDate)->format('%r%a');
            if ($daysLeft > 30) {
                continue;
            }

            $severity = $daysLeft < 0 ? 'danger' : 'warning';
            if ($daysLeft < 0) {
                $message = $carLabel . ' aracinin ' . $meta['label'] . ' ' . abs($daysLeft) . ' gun gecmis durumda.';
            } elseif ($daysLeft === 0) {
                $message = $carLabel . ' aracinin ' . $meta['label'] . ' bugun doluyor.';
            } else {
                $message = $carLabel . ' aracinin ' . $meta['label'] . ' ' . $daysLeft . ' gun icinde dolacak.';
            }

            $key = 'car:' . $field . ':' . $carId;
            $activeKeys[] = $key;
            notifications_upsert_system($pdo, $companyId, [
                'notification_key' => $key,
                'event_type' => $meta['event'],
                'entity_type' => 'car',
                'entity_id' => $carId,
                'severity' => $severity,
                'title' => $meta['title'],
                'message' => $message,
                'due_at' => $targetDate->format('Y-m-d 00:00:00'),
                'metadata' => ['car_id' => $carId, 'days_left' => $daysLeft],
            ]);
        }
    }

    $rentalsSt = $pdo->prepare("
      SELECT
        r.id,
        r.customer_name,
        r.completed,
        r.end_date,
        c.brand,
        c.model,
        c.plate,
        COALESCE(MAX(re.new_end_date), r.end_date) AS effective_end_date
      FROM rentals r
      LEFT JOIN cars c ON c.id = r.car_id AND c.company_id = r.company_id
      LEFT JOIN rental_extensions re ON re.rental_id = r.id AND re.extension_status = 'active'
      WHERE r.company_id = ? AND r.archived_at IS NULL AND r.completed = 0
      GROUP BY r.id, r.customer_name, r.completed, r.end_date, c.brand, c.model, c.plate
      HAVING effective_end_date IS NOT NULL
    ");
    $rentalsSt->execute([$companyId]);
    $rentals = $rentalsSt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rentals as $rental) {
        $effectiveEndDate = $rental['effective_end_date'] ?? null;
        if (!$effectiveEndDate) {
            continue;
        }

        try {
            $endDateTime = new DateTimeImmutable(date('Y-m-d H:i:s', strtotime($effectiveEndDate)));
            $endDate = new DateTimeImmutable($endDateTime->format('Y-m-d'));
        } catch (Throwable $exception) {
            continue;
        }

        $daysLeft = (int) $today->diff($endDate)->format('%r%a');
        if ($daysLeft > 1) {
            continue;
        }

        $rentalId = (int) ($rental['id'] ?? 0);
        $carLabel = trim(($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? ''));
        $isOverdue = $endDateTime < $now;
        $severity = $isOverdue ? 'danger' : 'warning';
        if ($isOverdue) {
            if ($daysLeft < 0) {
                $message = $carLabel . ' icin kiralama suresi gecti. ' . abs($daysLeft) . ' gun gecmis durumda.';
            } else {
                $message = $carLabel . ' icin kiralama suresi doldu, arac henuz teslim alinmadi.';
            }
        } elseif ($daysLeft === 0) {
            $message = $carLabel . ' icin kiralama suresi bugun doluyor.';
        } else {
            $message = $carLabel . ' icin kiralama suresi yarin doluyor.';
        }

        $key = 'rental:deadline:' . $rentalId;
        $activeKeys[] = $key;
        notifications_upsert_system($pdo, $companyId, [
            'notification_key' => $key,
            'event_type' => 'rental.deadline',
            'entity_type' => 'rental',
            'entity_id' => $rentalId,
            'severity' => $severity,
            'title' => 'Kiralama suresi takibi',
            'message' => $message,
            'due_at' => $endDateTime->format('Y-m-d H:i:s'),
            'metadata' => ['rental_id' => $rentalId, 'days_left' => $daysLeft],
        ]);
    }

    $baseRentalsSt = $pdo->prepare("
        SELECT id, customer_name, completed
        FROM rentals
        WHERE company_id = ? AND archived_at IS NULL AND completed = 0
        ORDER BY start_date
    ");
    $baseRentalsSt->execute([$companyId]);
    $baseRentals = $baseRentalsSt->fetchAll(PDO::FETCH_ASSOC);
    $extensionsByRentalId = getRentalExtensionsByRentalId($pdo, $companyId);
    $collectionsByExtensionId = getRentalExtensionCollectionsByExtensionId($pdo, $companyId);
    $receivableWarnings = buildRentalExtensionReceivableWarnings($baseRentals, $extensionsByRentalId, $collectionsByExtensionId, 1);

    foreach ($receivableWarnings['items'] as $warning) {
        $extensionId = (int) ($warning['extension_id'] ?? 0);
        $rentalId = (int) ($warning['rental_id'] ?? 0);
        $key = 'extension:receivable:' . $extensionId;
        $activeKeys[] = $key;
        notifications_upsert_system($pdo, $companyId, [
            'notification_key' => $key,
            'event_type' => 'rental_extension.receivable',
            'entity_type' => 'rental_extension',
            'entity_id' => $extensionId,
            'severity' => ($warning['level'] ?? '') === 'danger' ? 'danger' : 'warning',
            'title' => 'Bekleyen tahsilat',
            'message' => (string) ($warning['message'] ?? ''),
            'due_at' => !empty($warning['due_date']) ? date('Y-m-d 00:00:00', strtotime((string) $warning['due_date'])) : null,
            'metadata' => [
                'rental_id' => $rentalId,
                'extension_id' => $extensionId,
                'pending_amount' => (float) ($warning['pending_amount'] ?? 0),
                'days_left' => (int) ($warning['days_left'] ?? 0),
            ],
        ]);
    }

    notifications_resolve_missing_system($pdo, $companyId, array_values(array_unique($activeKeys)));
    return notifications_summary($pdo, $companyId);
}
