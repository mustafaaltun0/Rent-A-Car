<?php

function car_photo_storage_dir(): string
{
    return auth_project_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'car-photos';
}

function car_photo_public_url(?array $car = null): ?string
{
    if (!$car || empty($car['photo_path']) || empty($car['id'])) {
        return null;
    }

    return app_base_url() . '/car_photo.php?id=' . (int) $car['id'];
}

function car_photo_filesystem_path(?array $car = null): ?string
{
    if (!$car) {
        return null;
    }

    $relativePath = trim((string) ($car['photo_path'] ?? ''));
    return auth_storage_relative_filesystem_path($relativePath, 'storage/car-photos');
}

function car_photo_axis_value(?string $value, array $allowed, string $fallback = 'center'): string
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, $allowed, true) ? $normalized : $fallback;
}

function car_photo_focus_value($value, int $fallback = 50): int
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    $numeric = (int) $value;
    return max(0, min(100, $numeric));
}

function car_photo_axis_from_focus(int $focus, string $orientation = 'x'): string
{
    if ($focus <= 33) {
        return $orientation === 'x' ? 'left' : 'top';
    }

    if ($focus >= 67) {
        return $orientation === 'x' ? 'right' : 'bottom';
    }

    return 'center';
}

function car_photo_object_position(?array $car = null, string $xKey = 'photo_position_x', string $yKey = 'photo_position_y'): string
{
    if ($car && (array_key_exists('photo_focus_x', $car) || array_key_exists('photo_focus_y', $car))) {
        $focusX = car_photo_focus_value($car['photo_focus_x'] ?? null, 50);
        $focusY = car_photo_focus_value($car['photo_focus_y'] ?? null, 50);
        return $focusX . '% ' . $focusY . '%';
    }

    if ($car && (array_key_exists('car_photo_focus_x', $car) || array_key_exists('car_photo_focus_y', $car))) {
        $focusX = car_photo_focus_value($car['car_photo_focus_x'] ?? null, 50);
        $focusY = car_photo_focus_value($car['car_photo_focus_y'] ?? null, 50);
        return $focusX . '% ' . $focusY . '%';
    }

    if ($car && (array_key_exists('linked_car_photo_focus_x', $car) || array_key_exists('linked_car_photo_focus_y', $car))) {
        $focusX = car_photo_focus_value($car['linked_car_photo_focus_x'] ?? null, 50);
        $focusY = car_photo_focus_value($car['linked_car_photo_focus_y'] ?? null, 50);
        return $focusX . '% ' . $focusY . '%';
    }

    $x = car_photo_axis_value($car[$xKey] ?? 'center', ['left', 'center', 'right']);
    $y = car_photo_axis_value($car[$yKey] ?? 'center', ['top', 'center', 'bottom']);

    return $x . ' ' . $y;
}

function car_photo_position_style(?array $car = null, string $xKey = 'photo_position_x', string $yKey = 'photo_position_y'): string
{
    return '--car-photo-position: ' . car_photo_object_position($car, $xKey, $yKey) . ';';
}

function car_store_photo_upload(array $photoFile, int $companyId, int $carId): array
{
    $relativeDir = 'storage/car-photos/' . $companyId . '/' . $carId;
    $storageDir = car_photo_storage_dir() . DIRECTORY_SEPARATOR . $companyId . DIRECTORY_SEPARATOR . $carId;

    $result = auth_store_standard_image_upload(
        $photoFile,
        $storageDir,
        $relativeDir,
        'car',
        5 * 1024 * 1024,
        1600,
        900,
        'cover'
    );

    if (!$result['ok']) {
        $statusMap = [
            'too_large' => 'car_photo_too_large',
            'invalid_type' => 'car_photo_invalid',
            'upload_failed' => 'car_photo_upload_failed',
        ];
        $result['status'] = $statusMap[$result['status'] ?? 'upload_failed'] ?? 'car_photo_upload_failed';
    }

    return $result;
}

function telematics_car_has_live_data(array $car): bool
{
    return !empty($car['telematics_enabled']) && (
        !empty($car['telematics_last_sync_at'])
        || $car['telematics_last_odometer_km'] !== null
        || ($car['telematics_last_latitude'] !== null && $car['telematics_last_longitude'] !== null)
    );
}

function rental_km_metrics(array $rental, ?array $car = null): array
{
    $departureKm = isset($rental['departure_km']) && $rental['departure_km'] !== '' && $rental['departure_km'] !== null ? (int) $rental['departure_km'] : null;
    $returnKm = isset($rental['return_km']) && $rental['return_km'] !== '' && $rental['return_km'] !== null ? (int) $rental['return_km'] : null;
    $telematicsKm = ($car && isset($car['telematics_last_odometer_km']) && $car['telematics_last_odometer_km'] !== null && $car['telematics_last_odometer_km'] !== '') ? (int) $car['telematics_last_odometer_km'] : null;
    $startAt = !empty($rental['start_date']) ? new DateTimeImmutable($rental['start_date']) : null;

    $endReference = null;
    if ($returnKm !== null && !empty($rental['end_date'])) {
        $endReference = new DateTimeImmutable($rental['end_date']);
    } elseif ((int) ($rental['completed'] ?? 0) === 1 && !empty($rental['end_date'])) {
        $endReference = new DateTimeImmutable($rental['end_date']);
    } elseif ($telematicsKm !== null) {
        $endReference = new DateTimeImmutable(date('Y-m-d H:i:s'));
    } elseif (!empty($rental['end_date'])) {
        $endReference = new DateTimeImmutable($rental['end_date']);
    }

    $distanceKm = null;
    $distanceSource = 'Veri yok';
    $effectiveEndKm = null;

    if ($departureKm !== null && $returnKm !== null) {
        $effectiveEndKm = $returnKm;
        $distanceKm = max(0, $returnKm - $departureKm);
        $distanceSource = 'Manuel teslim KM';
    } elseif ($departureKm !== null && $telematicsKm !== null && $telematicsKm >= $departureKm) {
        $effectiveEndKm = $telematicsKm;
        $distanceKm = max(0, $telematicsKm - $departureKm);
        $distanceSource = 'Telematik anlik KM';
    }

    $durationDays = null;
    if ($startAt && $endReference) {
        $durationSeconds = max(0, $endReference->getTimestamp() - $startAt->getTimestamp());
        $durationDays = max(1, (int) ceil($durationSeconds / 86400));
    }

    $averageDailyKm = ($distanceKm !== null && $durationDays && $durationDays > 0)
        ? round($distanceKm / $durationDays, 1)
        : null;

    return [
        'departure_km' => $departureKm,
        'effective_end_km' => $effectiveEndKm,
        'distance_km' => $distanceKm,
        'duration_days' => $durationDays,
        'average_daily_km' => $averageDailyKm,
        'distance_source' => $distanceSource,
        'telematics_last_odometer_km' => $telematicsKm,
        'is_live' => $returnKm === null && $telematicsKm !== null,
    ];
}

function buildCarMileageSummary(array $rentals, ?array $car = null): array
{
    $totalDistanceKm = 0.0;
    $totalDays = 0;
    $countedRentals = 0;
    $liveDistanceKm = 0.0;

    foreach ($rentals as $rental) {
        $metrics = rental_km_metrics($rental, $car);
        if ($metrics['distance_km'] === null || $metrics['duration_days'] === null) {
            continue;
        }

        $totalDistanceKm += (float) $metrics['distance_km'];
        $totalDays += (int) $metrics['duration_days'];
        $countedRentals++;

        if (!empty($metrics['is_live'])) {
            $liveDistanceKm += (float) $metrics['distance_km'];
        }
    }

    return [
        'total_distance_km' => $totalDistanceKm,
        'total_days' => $totalDays,
        'counted_rentals' => $countedRentals,
        'average_daily_km' => $totalDays > 0 ? round($totalDistanceKm / $totalDays, 1) : null,
        'live_distance_km' => $liveDistanceKm,
    ];
}
function car_is_archived(array $car): bool
{
    return !empty($car['archived_at']);
}

function car_is_sold(array $car): bool
{
    return !empty($car['sold_at']);
}

function getCarSalesByCarId(PDO $pdo, int $companyId, array $carIds): array
{
    ensureCarSaleSchema($pdo);

    $carIds = array_values(array_unique(array_map('intval', array_filter($carIds, static fn ($id): bool => (int) $id > 0))));
    if (empty($carIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($carIds), '?'));
    $params = array_merge([$companyId], $carIds);
    $st = $pdo->prepare("
        SELECT *
        FROM car_sales
        WHERE company_id = ?
          AND sale_status = 'active'
          AND car_id IN ($placeholders)
        ORDER BY id DESC
    ");
    $st->execute($params);

    $salesByCarId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sale) {
        $carId = (int) ($sale['car_id'] ?? 0);
        if ($carId <= 0 || isset($salesByCarId[$carId])) {
            continue;
        }

        $salesByCarId[$carId] = $sale;
    }

    return $salesByCarId;
}

function getCarSaleCollectionsBySaleId(PDO $pdo, int $companyId, array $saleIds): array
{
    ensureCarSaleSchema($pdo);

    $saleIds = array_values(array_unique(array_map('intval', array_filter($saleIds, static fn ($id): bool => (int) $id > 0))));
    if (empty($saleIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $params = array_merge([$companyId], $saleIds);
    $st = $pdo->prepare("
        SELECT *
        FROM car_sale_collections
        WHERE company_id = ?
          AND car_sale_id IN ($placeholders)
        ORDER BY collected_at ASC, id ASC
    ");
    $st->execute($params);

    $grouped = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $collection) {
        $saleId = (int) ($collection['car_sale_id'] ?? 0);
        if ($saleId <= 0) {
            continue;
        }

        $grouped[$saleId][] = $collection;
    }

    return $grouped;
}

function car_sale_collection_is_active(array $collection): bool
{
    return ($collection['collection_status'] ?? 'active') === 'active';
}

function car_sale_latest_active_collection(array $collections): ?array
{
    for ($index = count($collections) - 1; $index >= 0; $index--) {
        if (car_sale_collection_is_active($collections[$index])) {
            return $collections[$index];
        }
    }

    return null;
}

function car_sale_collected_amount(array $sale, array $collectionsBySaleId): float
{
    $saleId = (int) ($sale['id'] ?? 0);
    $total = 0.0;

    foreach ($collectionsBySaleId[$saleId] ?? [] as $collection) {
        if (!car_sale_collection_is_active($collection)) {
            continue;
        }

        $total += max(0.0, (float) ($collection['amount'] ?? 0));
    }

    return $total;
}

function car_sale_pending_amount(array $sale, array $collectionsBySaleId): float
{
    $totalAmount = max(0.0, (float) ($sale['total_amount'] ?? 0));
    return max(0.0, $totalAmount - car_sale_collected_amount($sale, $collectionsBySaleId));
}

function car_sale_effective_payment_status(array $sale, array $collectionsBySaleId): string
{
    $totalAmount = max(0.0, (float) ($sale['total_amount'] ?? 0));
    $collectedAmount = car_sale_collected_amount($sale, $collectionsBySaleId);

    if ($totalAmount <= 0.0 || $collectedAmount + 0.0001 >= $totalAmount) {
        return 'collected';
    }

    if ($collectedAmount <= 0.0001) {
        return 'pending';
    }

    return 'partial';
}

