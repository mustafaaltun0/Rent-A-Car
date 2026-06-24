<?php

function rental_document_type_catalog(): array {
    return [
        'rental_summary' => ['label' => 'Kiralama Ozeti', 'prefix' => 'KRL'],
        'collection_receipt' => ['label' => 'Tahsilat Makbuzu', 'prefix' => 'MKB'],
    ];
}

function rental_document_title(string $documentType): string {
    $catalog = rental_document_type_catalog();
    return $catalog[$documentType]['label'] ?? $documentType;
}

function rental_document_prefix(string $documentType): string {
    $catalog = rental_document_type_catalog();
    return $catalog[$documentType]['prefix'] ?? 'DOC';
}

function ensureDocumentSequenceRow(PDO $pdo, int $companyId, string $documentType): void {
    $prefix = rental_document_prefix($documentType);
    $insert = $pdo->prepare('INSERT IGNORE INTO document_sequences (company_id, document_type, prefix, next_number) VALUES (?, ?, ?, 1)');
    $insert->execute([$companyId, $documentType, $prefix]);
}

function rental_ensure_document(PDO $pdo, int $companyId, int $rentalId, string $documentType, int $createdByUserId = 0): array {
    ensureRentalDocumentSchema($pdo);

    $existing = $pdo->prepare('SELECT * FROM rental_documents WHERE company_id = ? AND rental_id = ? AND document_type = ? LIMIT 1');
    $existing->execute([$companyId, $rentalId, $documentType]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    ensureDocumentSequenceRow($pdo, $companyId, $documentType);

    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT * FROM rental_documents WHERE company_id = ? AND rental_id = ? AND document_type = ? LIMIT 1');
        $existing->execute([$companyId, $rentalId, $documentType]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->commit();
            return $row;
        }

        $sequenceSt = $pdo->prepare('SELECT id, prefix, next_number FROM document_sequences WHERE company_id = ? AND document_type = ? LIMIT 1 FOR UPDATE');
        $sequenceSt->execute([$companyId, $documentType]);
        $sequence = $sequenceSt->fetch(PDO::FETCH_ASSOC);
        if (!$sequence) {
            throw new RuntimeException('Belge sirasi bulunamadi.');
        }

        $sequenceNumber = max(1, (int) ($sequence['next_number'] ?? 1));
        $documentNumber = sprintf('%s-%s-%06d', $sequence['prefix'], date('Y'), $sequenceNumber);

        $insert = $pdo->prepare('INSERT INTO rental_documents (company_id, rental_id, document_type, document_number, sequence_number, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([
            $companyId,
            $rentalId,
            $documentType,
            $documentNumber,
            $sequenceNumber,
            $createdByUserId > 0 ? $createdByUserId : null,
        ]);

        $updateSequence = $pdo->prepare('UPDATE document_sequences SET next_number = ? WHERE id = ?');
        $updateSequence->execute([$sequenceNumber + 1, (int) $sequence['id']]);

        $documentId = (int) $pdo->lastInsertId();
        $pdo->commit();

        $fetchInserted = $pdo->prepare('SELECT * FROM rental_documents WHERE id = ? LIMIT 1');
        $fetchInserted->execute([$documentId]);
        return $fetchInserted->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function rental_extension_payment_status(array $extension): string {
    $status = strtolower(trim((string) ($extension['payment_status'] ?? 'collected')));
    return in_array($status, ['pending', 'partial', 'collected'], true) ? $status : 'collected';
}

function rental_extension_lifecycle_status(array $extension): string {
    $status = strtolower(trim((string) ($extension['extension_status'] ?? 'active')));
    return in_array($status, ['active', 'cancelled'], true) ? $status : 'active';
}

function rental_extension_is_active(array $extension): bool {
    return rental_extension_lifecycle_status($extension) === 'active';
}

function rental_extension_is_collected(array $extension): bool {
    return rental_extension_payment_status($extension) === 'collected';
}

function rental_extension_status_label(array $extension): string {
    if (!rental_extension_is_active($extension)) {
        return 'Iptal Edildi';
    }
    if (rental_extension_payment_status($extension) === 'partial') {
        return 'Parcali Tahsil Edildi';
    }
    return rental_extension_is_collected($extension) ? 'Tahsil Edildi' : 'Tahsilat Bekliyor';
}

function rental_extension_status_badge_class(array $extension): string {
    if (!rental_extension_is_active($extension)) {
        return 'bg-secondary';
    }
    if (rental_extension_payment_status($extension) === 'partial') {
        return 'bg-info text-dark';
    }
    return rental_extension_is_collected($extension) ? 'bg-success' : 'bg-warning text-dark';
}

function rental_extension_collection_lifecycle_status(array $collection): string {
    $status = strtolower(trim((string) ($collection['collection_status'] ?? 'active')));
    return in_array($status, ['active', 'cancelled'], true) ? $status : 'active';
}

function rental_extension_collection_is_active(array $collection): bool {
    return rental_extension_collection_lifecycle_status($collection) === 'active';
}

function rental_extension_active_collections(array $collections): array {
    return array_values(array_filter($collections, static function (array $collection): bool {
        return rental_extension_collection_is_active($collection);
    }));
}

function rental_extension_latest_active_collection(array $collections): ?array {
    $activeCollections = rental_extension_active_collections($collections);
    if (empty($activeCollections)) {
        return null;
    }

    usort($activeCollections, static function (array $left, array $right): int {
        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    return end($activeCollections) ?: null;
}

function rental_active_extensions(array $extensions): array {
    return array_values(array_filter($extensions, static function (array $extension): bool {
        return rental_extension_is_active($extension);
    }));
}

function rental_latest_active_extension(array $extensions): ?array {
    $activeExtensions = rental_active_extensions($extensions);
    if (empty($activeExtensions)) {
        return null;
    }

    usort($activeExtensions, static function (array $left, array $right): int {
        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    return end($activeExtensions) ?: null;
}

function rental_effective_end_date(array $rental, array $extensionsByRentalId): ?string {
    $rentalId = (int) ($rental['id'] ?? 0);
    $latestActiveExtension = rental_latest_active_extension($extensionsByRentalId[$rentalId] ?? []);
    if ($latestActiveExtension && !empty($latestActiveExtension['new_end_date'])) {
        return (string) $latestActiveExtension['new_end_date'];
    }

    return $rental['initial_end_date'] ?? $rental['end_date'] ?? null;
}

function rental_refresh_end_date(PDO $pdo, array $rental, array $extensionsByRentalId): void {
    $rentalId = (int) ($rental['id'] ?? 0);
    $companyId = (int) ($rental['company_id'] ?? 0);
    if ($rentalId <= 0 || $companyId <= 0) {
        return;
    }

    $effectiveEndDate = rental_effective_end_date($rental, $extensionsByRentalId);
    $update = $pdo->prepare('UPDATE rentals SET end_date = ? WHERE id = ? AND company_id = ?');
    $update->execute([$effectiveEndDate, $rentalId, $companyId]);
}

function getRentalExtensionCollectionsByExtensionId(PDO $pdo, ?int $companyId = null): array {
    ensureRentalExtensionSchema($pdo);
    $companyId = $companyId ?? (function_exists('auth_current_company_id') ? (int) auth_current_company_id() : 0);

    if ($companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM rental_extension_collections WHERE company_id = ? ORDER BY rental_extension_id ASC, collected_at ASC, id ASC');
        $st->execute([$companyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM rental_extension_collections ORDER BY rental_extension_id ASC, collected_at ASC, id ASC')->fetchAll();
    }

    $grouped = [];
    foreach ($rows as $row) {
        $extensionId = (int) ($row['rental_extension_id'] ?? 0);
        if (!isset($grouped[$extensionId])) {
            $grouped[$extensionId] = [];
        }
        $grouped[$extensionId][] = $row;
    }

    return $grouped;
}

function getRentalExtensionRevisionsByExtensionId(PDO $pdo, ?int $companyId = null): array {
    ensureRentalExtensionSchema($pdo);
    $companyId = $companyId ?? (function_exists('auth_current_company_id') ? (int) auth_current_company_id() : 0);

    if ($companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM rental_extension_revisions WHERE company_id = ? ORDER BY rental_extension_id ASC, created_at ASC, id ASC');
        $st->execute([$companyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM rental_extension_revisions ORDER BY rental_extension_id ASC, created_at ASC, id ASC')->fetchAll();
    }

    $grouped = [];
    foreach ($rows as $row) {
        $extensionId = (int) ($row['rental_extension_id'] ?? 0);
        if (!isset($grouped[$extensionId])) {
            $grouped[$extensionId] = [];
        }
        $grouped[$extensionId][] = $row;
    }

    return $grouped;
}

function rental_extension_original_terms(array $extension, array $revisions = []): array
{
    $fallbackPreviousEndDate = !empty($extension['previous_end_date']) ? (string) $extension['previous_end_date'] : null;
    $fallbackNewEndDate = !empty($extension['new_end_date']) ? (string) $extension['new_end_date'] : null;
    $fallbackIncome = max(0.0, (float) ($extension['income'] ?? 0));

    foreach ($revisions as $revision) {
        $actionType = (string) ($revision['action_type'] ?? '');
        if ($actionType !== 'created') {
            continue;
        }

        $payloadAfter = json_decode((string) ($revision['payload_after'] ?? ''), true);
        if (!is_array($payloadAfter)) {
            continue;
        }

        return [
            'previous_end_date' => !empty($payloadAfter['previous_end_date']) ? (string) $payloadAfter['previous_end_date'] : $fallbackPreviousEndDate,
            'new_end_date' => !empty($payloadAfter['new_end_date']) ? (string) $payloadAfter['new_end_date'] : $fallbackNewEndDate,
            'income' => array_key_exists('income', $payloadAfter) ? max(0.0, (float) $payloadAfter['income']) : $fallbackIncome,
        ];
    }

    return [
        'previous_end_date' => $fallbackPreviousEndDate,
        'new_end_date' => $fallbackNewEndDate,
        'income' => $fallbackIncome,
    ];
}

function rental_duration_days(?string $startDate, ?string $endDate): int
{
    if (empty($startDate) || empty($endDate)) {
        return 0;
    }

    try {
        $start = new DateTimeImmutable((string) $startDate);
        $end = new DateTimeImmutable((string) $endDate);
    } catch (Throwable $e) {
        return 0;
    }

    if ($end <= $start) {
        return 0;
    }

    return (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 86400);
}

function rental_extension_prorated_income(array $originalTerms, ?string $currentNewEndDate): float
{
    $originalPreviousEndDate = (string) ($originalTerms['previous_end_date'] ?? '');
    $originalNewEndDate = (string) ($originalTerms['new_end_date'] ?? '');
    $originalIncome = max(0.0, (float) ($originalTerms['income'] ?? 0));

    $originalDays = rental_duration_days($originalPreviousEndDate, $originalNewEndDate);
    $currentDays = rental_duration_days($originalPreviousEndDate, $currentNewEndDate);
    if ($originalDays <= 0 || $currentDays <= 0 || $originalIncome <= 0.0) {
        return $originalIncome;
    }

    return round(($originalIncome / $originalDays) * $currentDays, 2);
}

function rental_extension_collected_amount(array $extension, array $collectionsByExtensionId = []): float {
    $extensionId = (int) ($extension['id'] ?? 0);
    $income = max(0.0, (float) ($extension['income'] ?? 0));

    if (!rental_extension_is_active($extension)) {
        return 0.0;
    }

    if (isset($collectionsByExtensionId[$extensionId])) {
        $collectedAmount = 0.0;
        foreach ($collectionsByExtensionId[$extensionId] as $collection) {
            if (!rental_extension_collection_is_active($collection)) {
                continue;
            }
            $collectedAmount += max(0.0, (float) ($collection['amount'] ?? 0));
        }
        return min($income, $collectedAmount);
    }

    return rental_extension_is_collected($extension) ? $income : 0.0;
}

function rental_extension_pending_amount(array $extension, array $collectionsByExtensionId = []): float {
    $income = max(0.0, (float) ($extension['income'] ?? 0));
    return max(0.0, $income - rental_extension_collected_amount($extension, $collectionsByExtensionId));
}

function rental_extension_effective_payment_status(array $extension, array $collectionsByExtensionId = []): string {
    if (!rental_extension_is_active($extension)) {
        return 'cancelled';
    }

    $income = max(0.0, (float) ($extension['income'] ?? 0));
    $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
    if ($income <= 0.0) {
        return 'collected';
    }
    if ($collectedAmount <= 0.0) {
        return 'pending';
    }
    if ($collectedAmount + 0.0001 >= $income) {
        return 'collected';
    }
    return 'partial';
}

function rental_extension_record_revision(PDO $pdo, int $companyId, int $rentalId, int $extensionId, string $actionType, ?array $payloadBefore, ?array $payloadAfter, int $createdByUserId = 0): void {
    ensureRentalExtensionSchema($pdo);

    $insert = $pdo->prepare('INSERT INTO rental_extension_revisions (company_id, rental_extension_id, rental_id, action_type, payload_before, payload_after, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $companyId,
        $extensionId,
        $rentalId,
        $actionType,
        $payloadBefore !== null ? json_encode($payloadBefore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $payloadAfter !== null ? json_encode($payloadAfter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $createdByUserId > 0 ? $createdByUserId : null,
    ]);
}

function getRentalExtensionsByRentalId(PDO $pdo, ?int $companyId = null): array {
    ensureRentalExtensionSchema($pdo);
    $companyId = $companyId ?? (function_exists('auth_current_company_id') ? (int) auth_current_company_id() : 0);

    if ($companyId > 0) {
        $st = $pdo->prepare('SELECT * FROM rental_extensions WHERE company_id = ? ORDER BY rental_id ASC, id ASC');
        $st->execute([$companyId]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM rental_extensions ORDER BY rental_id ASC, id ASC')->fetchAll();
    }

    $grouped = [];
    foreach ($rows as $row) {
        $rentalId = (int)$row['rental_id'];
        if (!isset($grouped[$rentalId])) {
            $grouped[$rentalId] = [];
        }
        $grouped[$rentalId][] = $row;
    }
    return $grouped;
}

function rental_contract_income(array $rental): float
{
    return max(0.0, (float) ($rental['income'] ?? 0));
}

function rental_collected_amount(array $rental): float
{
    $income = rental_contract_income($rental);

    if (array_key_exists('collected_amount', $rental) && $rental['collected_amount'] !== null && $rental['collected_amount'] !== '') {
        return min($income, max(0.0, (float) $rental['collected_amount']));
    }

    $legacyStatus = strtolower(trim((string) ($rental['payment_status'] ?? '')));
    if ($legacyStatus === 'pending') {
        return 0.0;
    }

    return $income;
}

function rental_pending_amount(array $rental): float
{
    return max(0.0, rental_contract_income($rental) - rental_collected_amount($rental));
}

function rental_effective_payment_status(array $rental): string
{
    $income = rental_contract_income($rental);
    $collectedAmount = rental_collected_amount($rental);

    if ($income <= 0.0 || $collectedAmount + 0.0001 >= $income) {
        return 'collected';
    }

    if ($collectedAmount <= 0.0001) {
        return 'pending';
    }

    return 'partial';
}

function getRentalTotals(array $rental, array $extensionsByRentalId, array $collectionsByExtensionId = []): array {
    $rentalId = (int)($rental['id'] ?? 0);
    $contractIncome = rental_contract_income($rental);
    $contractExpense = max(0.0, (float)($rental['expense'] ?? 0));
    $baseCollectedAmount = rental_collected_amount($rental);
    $basePendingAmount = max(0.0, $contractIncome - $baseCollectedAmount);
    $baseCollectionRatio = $contractIncome > 0.0 ? min(1.0, $baseCollectedAmount / $contractIncome) : 1.0;
    $income = $baseCollectedAmount;
    $expense = $contractExpense * $baseCollectionRatio;
    $pendingIncome = $basePendingAmount;
    $pendingExpense = max(0.0, $contractExpense - $expense);

    foreach ($extensionsByRentalId[$rentalId] ?? [] as $extension) {
        if (!rental_extension_is_active($extension)) {
            continue;
        }
        $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
        $extensionExpense = max(0.0, (float) ($extension['expense'] ?? 0));
        $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
        $remainingAmount = max(0.0, $extensionIncome - $collectedAmount);
        $collectionRatio = $extensionIncome > 0.0 ? min(1.0, $collectedAmount / $extensionIncome) : 1.0;
        $collectedExpense = $extensionExpense * $collectionRatio;
        $remainingExpense = max(0.0, $extensionExpense - $collectedExpense);

        $income += $collectedAmount;
        $expense += $collectedExpense;
        $pendingIncome += $remainingAmount;
        $pendingExpense += $remainingExpense;
    }

    return [
        'income' => $income,
        'expense' => $expense,
        'net_profit' => $income - $expense,
        'pending_income' => $pendingIncome,
        'pending_expense' => $pendingExpense,
        'pending_net_profit' => $pendingIncome - $pendingExpense,
        'contract_income' => $income + $pendingIncome,
        'contract_expense' => $expense + $pendingExpense,
        'contract_net_profit' => ($income + $pendingIncome) - ($expense + $pendingExpense),
    ];
}

function buildRentalExtensionReceivableWarnings(array $rentals, array $extensionsByRentalId, array $collectionsByExtensionId = [], int $reminderDays = 1): array
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $items = [];
    $byRentalId = [];

    foreach ($rentals as $rental) {
        if ((int) ($rental['completed'] ?? 0) === 1) {
            continue;
        }

        $rentalId = (int) ($rental['id'] ?? 0);
        foreach ($extensionsByRentalId[$rentalId] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }

            $pendingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
            $paymentDueDate = trim((string) ($extension['payment_due_date'] ?? ''));
            if ($pendingAmount <= 0.0 || $paymentDueDate === '') {
                continue;
            }

            try {
                $dueDate = new DateTimeImmutable(date('Y-m-d', strtotime($paymentDueDate)));
            } catch (Throwable $exception) {
                continue;
            }

            $daysLeft = (int) $today->diff($dueDate)->format('%r%a');
            if ($daysLeft > $reminderDays) {
                continue;
            }

            $customerName = trim((string) ($rental['customer_name'] ?? 'Musteri'));
            $level = $daysLeft < 0 ? 'danger' : 'warning';

            if ($daysLeft < 0) {
                $message = $customerName . ' uzatmasinda bekleyen ' . money($pendingAmount) . ' tahsilat ' . abs($daysLeft) . ' gun gecikti.';
                $shortLabel = 'Tahsilat gecikti';
            } elseif ($daysLeft === 0) {
                $message = $customerName . ' uzatmasinda bekleyen ' . money($pendingAmount) . ' tahsilat bugun alinmali.';
                $shortLabel = 'Bugun tahsilat';
            } else {
                $message = $customerName . ' uzatmasinda bekleyen ' . money($pendingAmount) . ' tahsilat yarin alinmali.';
                $shortLabel = 'Yarin tahsilat';
            }

            $item = [
                'rental_id' => $rentalId,
                'extension_id' => (int) ($extension['id'] ?? 0),
                'pending_amount' => $pendingAmount,
                'due_date' => $paymentDueDate,
                'days_left' => $daysLeft,
                'level' => $level,
                'short_label' => $shortLabel,
                'message' => $message,
            ];

            $items[] = $item;
            $byRentalId[$rentalId][] = $item;
        }
    }

    usort($items, static function (array $left, array $right): int {
        $leftPriority = $left['days_left'] < 0 ? 0 : ($left['days_left'] === 0 ? 1 : 2);
        $rightPriority = $right['days_left'] < 0 ? 0 : ($right['days_left'] === 0 ? 1 : 2);
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return strcmp((string) ($left['due_date'] ?? ''), (string) ($right['due_date'] ?? ''));
    });

    foreach ($byRentalId as &$rentalItems) {
        usort($rentalItems, static function (array $left, array $right): int {
            return ($left['days_left'] ?? 0) <=> ($right['days_left'] ?? 0);
        });
    }
    unset($rentalItems);

    return [
        'items' => $items,
        'by_rental_id' => $byRentalId,
    ];
}

function buildRentalCollectionCenterData(array $rentals, array $extensionsByRentalId, array $collectionsByExtensionId = []): array
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $pendingItems = [];
    $recentCollections = [];
    $summary = [
        'overdue_amount' => 0.0,
        'due_today_amount' => 0.0,
        'upcoming_amount' => 0.0,
        'pending_total' => 0.0,
        'active_pending_count' => 0,
        'collected_this_month' => 0.0,
        'collected_this_month_count' => 0,
    ];

    foreach ($rentals as $rental) {
        $rentalId = (int) ($rental['id'] ?? 0);
        $carLabel = trim((string) (($rental['brand'] ?? '') . ' ' . ($rental['model'] ?? '') . ' - ' . ($rental['plate'] ?? '')));
        foreach ($extensionsByRentalId[$rentalId] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }

            $extensionId = (int) ($extension['id'] ?? 0);
            $pendingAmount = rental_extension_pending_amount($extension, $collectionsByExtensionId);
            $paymentDueDate = trim((string) ($extension['payment_due_date'] ?? ''));

            if ($pendingAmount > 0.0) {
                $daysLeft = null;
                $urgency = 'muted';
                if ($paymentDueDate !== '') {
                    try {
                        $dueDate = new DateTimeImmutable(date('Y-m-d', strtotime($paymentDueDate)));
                        $daysLeft = (int) $today->diff($dueDate)->format('%r%a');
                        if ($daysLeft < 0) {
                            $urgency = 'danger';
                            $summary['overdue_amount'] += $pendingAmount;
                        } elseif ($daysLeft === 0) {
                            $urgency = 'warning';
                            $summary['due_today_amount'] += $pendingAmount;
                        } else {
                            $urgency = 'info';
                            $summary['upcoming_amount'] += $pendingAmount;
                        }
                    } catch (Throwable $exception) {
                        $daysLeft = null;
                    }
                }

                $pendingItems[] = [
                    'rental_id' => $rentalId,
                    'extension_id' => $extensionId,
                    'car_id' => (int) ($rental['car_id'] ?? 0),
                    'car_photo_path' => $rental['photo_path'] ?? null,
                    'car_photo_position_x' => $rental['photo_position_x'] ?? 'center',
                    'car_photo_position_y' => $rental['photo_position_y'] ?? 'center',
                    'car_photo_focus_x' => $rental['photo_focus_x'] ?? null,
                    'car_photo_focus_y' => $rental['photo_focus_y'] ?? null,
                    'customer_name' => (string) ($rental['customer_name'] ?? 'Musteri'),
                    'car_label' => $carLabel !== '' ? $carLabel : 'Arac bilgisi yok',
                    'pending_amount' => $pendingAmount,
                    'due_date' => $paymentDueDate !== '' ? $paymentDueDate : null,
                    'days_left' => $daysLeft,
                    'urgency' => $urgency,
                    'collected_amount' => rental_extension_collected_amount($extension, $collectionsByExtensionId),
                    'contract_amount' => max(0.0, (float) ($extension['income'] ?? 0)),
                ];
                $summary['pending_total'] += $pendingAmount;
                $summary['active_pending_count']++;
            }

            foreach ($collectionsByExtensionId[$extensionId] ?? [] as $collection) {
                if (!rental_extension_collection_is_active($collection)) {
                    continue;
                }

                $collectedAt = $collection['collected_at'] ?? null;
                if (!$collectedAt) {
                    continue;
                }

                $collectionAmount = max(0.0, (float) ($collection['amount'] ?? 0));
                if (date('Y-m', strtotime($collectedAt)) === date('Y-m')) {
                    $summary['collected_this_month'] += $collectionAmount;
                    $summary['collected_this_month_count']++;
                }

                $recentCollections[] = [
                    'rental_id' => $rentalId,
                    'extension_id' => $extensionId,
                    'collection_id' => (int) ($collection['id'] ?? 0),
                    'car_id' => (int) ($rental['car_id'] ?? 0),
                    'car_photo_path' => $rental['photo_path'] ?? null,
                    'car_photo_position_x' => $rental['photo_position_x'] ?? 'center',
                    'car_photo_position_y' => $rental['photo_position_y'] ?? 'center',
                    'car_photo_focus_x' => $rental['photo_focus_x'] ?? null,
                    'car_photo_focus_y' => $rental['photo_focus_y'] ?? null,
                    'customer_name' => (string) ($rental['customer_name'] ?? 'Musteri'),
                    'car_label' => $carLabel !== '' ? $carLabel : 'Arac bilgisi yok',
                    'amount' => $collectionAmount,
                    'collected_at' => $collectedAt,
                    'payment_method' => (string) ($collection['payment_method'] ?? ''),
                    'note' => (string) ($collection['note'] ?? ''),
                ];
            }
        }
    }

    usort($pendingItems, static function (array $left, array $right): int {
        $leftPriority = $left['days_left'] === null ? 3 : ($left['days_left'] < 0 ? 0 : ($left['days_left'] === 0 ? 1 : 2));
        $rightPriority = $right['days_left'] === null ? 3 : ($right['days_left'] < 0 ? 0 : ($right['days_left'] === 0 ? 1 : 2));
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }
        return strcmp((string) ($left['due_date'] ?? ''), (string) ($right['due_date'] ?? ''));
    });

    usort($recentCollections, static function (array $left, array $right): int {
        return strcmp((string) ($right['collected_at'] ?? ''), (string) ($left['collected_at'] ?? ''));
    });

    return [
        'summary' => $summary,
        'pending_items' => $pendingItems,
        'recent_collections' => array_slice($recentCollections, 0, 20),
    ];
}

function buildRentalMonthlyData(array $rentals, array $extensionsByRentalId, array $collectionsByExtensionId = []): array {
    $monthly = [];

    foreach ($rentals as $rental) {
        $startDate = $rental['start_date'] ?? null;
        $rentalId = (int) ($rental['id'] ?? 0);
        $hasAnyExtension = !empty($extensionsByRentalId[$rentalId] ?? []);
        $initialEndDate = $hasAnyExtension
            ? ($rental['initial_end_date'] ?? ($rental['end_date'] ?? null))
            : ($rental['end_date'] ?? ($rental['initial_end_date'] ?? null));
        $baseContractIncome = rental_contract_income($rental);
        $baseCollectedIncome = rental_collected_amount($rental);
        $baseExpense = max(0.0, (float) ($rental['expense'] ?? 0));
        $baseCollectionRatio = $baseContractIncome > 0.0 ? min(1.0, $baseCollectedIncome / $baseContractIncome) : 1.0;

        addMonthlyAllocation($monthly, allocateAmountByMonth($startDate, $initialEndDate, $baseCollectedIncome), 0);
        addMonthlyAllocation($monthly, allocateAmountByMonth($startDate, $initialEndDate, $baseExpense * $baseCollectionRatio), 1);

        foreach ($extensionsByRentalId[(int)($rental['id'] ?? 0)] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }
            $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
            if ($collectedAmount <= 0.0) {
                continue;
            }
            $segmentStart = $extension['previous_end_date'] ?? $startDate;
            $segmentEnd = $extension['new_end_date'] ?? null;
            $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
            $extensionExpense = max(0.0, (float) ($extension['expense'] ?? 0));
            $collectionRatio = $extensionIncome > 0.0 ? min(1.0, $collectedAmount / $extensionIncome) : 1.0;
            addMonthlyAllocation($monthly, allocateAmountByMonth($segmentStart, $segmentEnd, $collectedAmount), 0);
            addMonthlyAllocation($monthly, allocateAmountByMonth($segmentStart, $segmentEnd, $extensionExpense * $collectionRatio), 1);
        }
    }

    ksort($monthly);
    foreach ($monthly as &$values) {
        if (!isset($values[2])) $values[2] = 0.0;
        $values[3] = ($values[1] ?? 0) + ($values[2] ?? 0);
        $values[4] = ($values[0] ?? 0) - ($values[3] ?? 0);
    }
    unset($values);

    return $monthly;
}

function calculateOverlapSeconds(DateTimeImmutable $segStart, DateTimeImmutable $segEnd, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): int {
    $startTs = max($segStart->getTimestamp(), $rangeStart->getTimestamp());
    $endTs = min($segEnd->getTimestamp(), $rangeEnd->getTimestamp());
    return max(0, $endTs - $startTs);
}

function buildCarPeriodProfitSummary(array $rentals, array $extensionsByRentalId, array $selectedCarIds, array $periodRanges, array $collectionsByExtensionId = []): array {
    $selectedMap = array_fill_keys(array_map('intval', $selectedCarIds), true);
    $summary = [];
    foreach ($selectedCarIds as $carId) {
        $summary[(int)$carId] = [];
        foreach ($periodRanges as $key => $_range) {
            $summary[(int)$carId][$key] = ['income' => 0.0, 'expense' => 0.0, 'net_profit' => 0.0];
        }
    }

    foreach ($rentals as $rental) {
        $carId = (int)($rental['car_id'] ?? 0);
        if (!isset($selectedMap[$carId])) {
            continue;
        }

        $segments = [];
        $start = $rental['start_date'] ?? null;
        $rentalId = (int) ($rental['id'] ?? 0);
        $hasAnyExtension = !empty($extensionsByRentalId[$rentalId] ?? []);
        $initialEnd = $hasAnyExtension
            ? ($rental['initial_end_date'] ?? ($rental['end_date'] ?? null))
            : ($rental['end_date'] ?? ($rental['initial_end_date'] ?? null));
        if ($start) {
            $baseContractIncome = rental_contract_income($rental);
            $baseCollectedIncome = rental_collected_amount($rental);
            $baseExpense = max(0.0, (float) ($rental['expense'] ?? 0));
            $baseCollectionRatio = $baseContractIncome > 0.0 ? min(1.0, $baseCollectedIncome / $baseContractIncome) : 1.0;
            $segments[] = [
                'start' => $start,
                'end' => $initialEnd,
                'income' => $baseCollectedIncome,
                'expense' => $baseExpense * $baseCollectionRatio,
            ];
        }

        foreach ($extensionsByRentalId[(int)($rental['id'] ?? 0)] ?? [] as $extension) {
            if (!rental_extension_is_active($extension)) {
                continue;
            }
            $extensionIncome = max(0.0, (float) ($extension['income'] ?? 0));
            $extensionExpense = max(0.0, (float) ($extension['expense'] ?? 0));
            $collectedAmount = rental_extension_collected_amount($extension, $collectionsByExtensionId);
            if ($collectedAmount <= 0.0) {
                continue;
            }
            $collectionRatio = $extensionIncome > 0.0 ? min(1.0, $collectedAmount / $extensionIncome) : 1.0;
            $segments[] = [
                'start' => $extension['previous_end_date'] ?? $start,
                'end' => $extension['new_end_date'] ?? null,
                'income' => $collectedAmount,
                'expense' => $extensionExpense * $collectionRatio,
            ];
        }

        foreach ($segments as $segment) {
            if (empty($segment['start'])) {
                continue;
            }
            $segStart = new DateTimeImmutable($segment['start']);
            $segEnd = !empty($segment['end']) ? new DateTimeImmutable($segment['end']) : $segStart;
            if ($segEnd <= $segStart) {
                foreach ($periodRanges as $periodKey => [$rangeStart, $rangeEnd]) {
                    if ($segStart >= $rangeStart && $segStart < $rangeEnd) {
                        $summary[$carId][$periodKey]['income'] += $segment['income'];
                        $summary[$carId][$periodKey]['expense'] += $segment['expense'];
                        $summary[$carId][$periodKey]['net_profit'] += $segment['income'] - $segment['expense'];
                    }
                }
                continue;
            }

            $segmentSeconds = $segEnd->getTimestamp() - $segStart->getTimestamp();
            foreach ($periodRanges as $periodKey => [$rangeStart, $rangeEnd]) {
                $overlap = calculateOverlapSeconds($segStart, $segEnd, $rangeStart, $rangeEnd);
                if ($overlap <= 0 || $segmentSeconds <= 0) {
                    continue;
                }
                $ratio = $overlap / $segmentSeconds;
                $income = $segment['income'] * $ratio;
                $expense = $segment['expense'] * $ratio;
                $summary[$carId][$periodKey]['income'] += $income;
                $summary[$carId][$periodKey]['expense'] += $expense;
                $summary[$carId][$periodKey]['net_profit'] += $income - $expense;
            }
        }
    }

    return $summary;
}
