<?php
declare(strict_types=1);

final class ClientImportService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const BATCH_SIZE = 200;

    public const FIELD_MAP = [
        'full_name' => ['ФИО', 'fio', 'full_name', 'имя'],
        'line' => ['Сегмент линейки (Премиум/Стандарт)', 'Сегмент линейки', 'линейка', 'line'],
        'section' => ['Раздел в таблице', 'Раздел', 'section'],
        'tariff_days' => ['Дней доставки в неделю', 'тариф', 'tariff_days'],
        'pack_days' => ['Пакет (дни)', 'Пакет', 'pack_days'],
        'kcal' => ['Калории (ккал)', 'Калории', 'ккал', 'kcal'],
        'ration_no' => ['Рацион №', 'Рацион', 'ration_no'],
        'diet' => ['Диета/особенности', 'Диета', 'diet'],
        'delivery_time' => ['Время доставки (утро/вечер)', 'Время доставки', 'delivery_time'],
        'period_start' => ['Последний период: с', 'period_start'],
        'period_end' => ['Последний период: по', 'period_end'],
        'end_date' => ['Дата окончания', 'end_date'],
        'pauses' => ['Пауза (даты/нет)', 'Пауза', 'pauses'],
        'flag_mark' => ['Метка (!,?)', 'Метка', 'flag_mark'],
        'notes' => ['Примечание', 'notes'],
        'source_text' => ['Исходная строка Excel', 'source_text'],
        'source_row' => ['Строка в источнике', 'source_row'],
        'status' => ['Статус клиента', 'Статус', 'status'],
        'tariff_days_ref' => ['Дней в неделю (справочно…)', 'Дней в неделю (справочно', 'tariff_days_ref'],
        'needs_review' => ['Требует проверки', 'needs_review'],
        'review_reasons' => ['Причины проверки', 'review_reasons'],
    ];

    public static function upload(Db $db, array $actor, array $file): array
    {
        actor_require($actor, 'customers.import');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError(422, 'Не удалось загрузить файл');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new HttpError(422, 'Размер файла не больше 5 МБ');
        }
        $name = (string) ($file['name'] ?? 'clients.csv');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            throw new HttpError(422, 'Допустимы только CSV и XLSX');
        }
        if (in_array($ext, ['xlsx', 'xls'], true) && !class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new HttpError(422, 'PhpSpreadsheet не установлен. Сохраните файл как CSV (UTF-8).');
        }
        $dir = dirname(__DIR__) . '/data/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $token = bin2hex(random_bytes(16));
        $stored = $token . '.' . $ext;
        $path = $dir . '/' . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
            throw new HttpError(500, 'Не удалось сохранить файл');
        }
        [$headers, $rows] = self::readFile($path, $ext);
        $mapping = self::autoMap($headers);
        return [
            'token' => $token,
            'filename' => $name,
            'ext' => $ext,
            'headers' => $headers,
            'mapping' => $mapping,
            'row_count' => count($rows),
            'fields' => array_keys(self::FIELD_MAP),
        ];
    }

    public static function preview(Db $db, array $actor, array $body): array
    {
        actor_require($actor, 'customers.import');
        [$headers, $rows, $path] = self::loadByToken($body);
        $mapping = is_array($body['mapping'] ?? null) ? $body['mapping'] : self::autoMap($headers);
        $duplicateMode = is_array($body['duplicate_mode'] ?? null) ? $body['duplicate_mode'] : [];
        $existingMode = (string) ($body['existing_mode'] ?? 'auto');
        $parsed = self::parseRows($db, $rows, $headers, $mapping, $duplicateMode, $existingMode, true);
        return [
            'mapping' => $mapping,
            'preview' => array_slice($parsed['preview'], 0, 50),
            'counts' => $parsed['counts'],
            'errors' => $parsed['errors'],
            'duplicate_groups' => $parsed['duplicate_groups'],
            'row_count' => count($rows),
            'can_import' => count($parsed['errors']) === 0,
        ];
    }

    public static function commit(Db $db, array $actor, array $body): array
    {
        actor_require($actor, 'customers.import');
        [$headers, $rows, $path] = self::loadByToken($body);
        $mapping = is_array($body['mapping'] ?? null) ? $body['mapping'] : self::autoMap($headers);
        $duplicateMode = is_array($body['duplicate_mode'] ?? null) ? $body['duplicate_mode'] : [];
        $existingMode = (string) ($body['existing_mode'] ?? 'auto');
        $parsed = self::parseRows($db, $rows, $headers, $mapping, $duplicateMode, $existingMode, false);
        if ($parsed['errors']) {
            throw new HttpError(422, 'Исправьте ошибки валидации перед импортом');
        }
        $filename = (string) ($body['filename'] ?? basename($path));
        $batchId = $db->insert(
            'INSERT INTO import_batches (
                user_id, filename, stored_path, status, total_rows, created_customers, created_subscriptions,
                updated_rows, skipped_rows, error_rows, report_json, created_at
             ) VALUES (
                :user_id, :filename, :stored_path, :status, :total_rows, 0, 0, 0, 0, 0, NULL, :created_at
             )',
            [
                'user_id' => $actor['id'],
                'filename' => $filename,
                'stored_path' => $path,
                'status' => 'running',
                'total_rows' => count($rows),
                'created_at' => app_now(),
            ],
        );

        $counts = [
            'created_customers' => 0,
            'created_subscriptions' => 0,
            'updated_rows' => 0,
            'skipped_rows' => 0,
            'error_rows' => 0,
        ];
        $errors = [];
        $actions = $parsed['actions'];
        $keyCustomerIds = [];
        $chunks = array_chunk($actions, self::BATCH_SIZE, true);
        try {
            foreach ($chunks as $chunk) {
                $db->tx(function (Db $db) use ($chunk, $actor, $batchId, &$counts, &$errors, &$keyCustomerIds): void {
                    foreach ($chunk as $rowNum => $action) {
                        try {
                            self::applyAction($db, $actor, $batchId, $action, $counts, $keyCustomerIds);
                        } catch (Throwable $e) {
                            $counts['error_rows']++;
                            $errors[] = ['row' => $rowNum, 'message' => $e->getMessage()];
                        }
                    }
                });
            }
        } catch (Throwable $e) {
            $db->exec(
                'UPDATE import_batches SET status = :status, report_json = :report WHERE id = :id',
                [
                    'status' => 'failed',
                    'report' => json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                    'id' => $batchId,
                ],
            );
            throw $e;
        }

        $report = [
            'counts' => $counts,
            'errors' => $errors,
        ];
        $db->exec(
            'UPDATE import_batches SET status = :status, created_customers = :cc, created_subscriptions = :cs,
             updated_rows = :ur, skipped_rows = :sr, error_rows = :er, report_json = :report WHERE id = :id',
            [
                'status' => 'completed',
                'cc' => $counts['created_customers'],
                'cs' => $counts['created_subscriptions'],
                'ur' => $counts['updated_rows'],
                'sr' => $counts['skipped_rows'],
                'er' => $counts['error_rows'],
                'report' => json_encode($report, JSON_UNESCAPED_UNICODE),
                'id' => $batchId,
            ],
        );
        audit_change(
            $db,
            $actor['id'],
            'import_batches',
            $batchId,
            'import',
            'total_rows',
            null,
            (string) count($rows),
        );
        $errorCsv = self::buildErrorCsv($errors);
        return [
            'batch_id' => $batchId,
            'counts' => $counts,
            'errors' => $errors,
            'error_csv' => $errorCsv,
        ];
    }

    public static function rollback(Db $db, array $actor, int $batchId): array
    {
        actor_require($actor, 'customers.import');
        $batch = $db->one('SELECT * FROM import_batches WHERE id = :id', ['id' => $batchId]);
        if (!$batch) {
            throw new HttpError(404, 'Партия импорта не найдена');
        }
        if ($batch['status'] === 'rolled_back') {
            throw new HttpError(422, 'Импорт уже откатан');
        }
        $db->tx(function (Db $db) use ($batchId): void {
            $changes = $db->all(
                'SELECT * FROM import_changes WHERE batch_id = :id ORDER BY id DESC',
                ['id' => $batchId],
            );
            foreach ($changes as $change) {
                $type = $change['entity_type'];
                $id = (int) $change['entity_id'];
                $action = $change['action'];
                if ($action === 'create') {
                    if ($type === 'client_subscriptions') {
                        $db->exec('DELETE FROM client_subscriptions WHERE id = :id', ['id' => $id]);
                    } elseif ($type === 'customers') {
                        $db->exec('DELETE FROM customers WHERE id = :id', ['id' => $id]);
                    }
                } elseif ($action === 'update' && $change['before_json']) {
                    $before = json_decode((string) $change['before_json'], true);
                    if (is_array($before) && $type === 'client_subscriptions') {
                        self::restoreSubscription($db, $id, $before);
                    } elseif (is_array($before) && $type === 'customers') {
                        self::restoreCustomer($db, $id, $before);
                    }
                }
            }
            $db->exec(
                'UPDATE import_batches SET status = :status, rolled_back_at = :at WHERE id = :id',
                ['status' => 'rolled_back', 'at' => app_now(), 'id' => $batchId],
            );
        });
        audit_change($db, $actor['id'], 'import_batches', $batchId, 'rollback', 'status', $batch['status'], 'rolled_back');
        return ['ok' => true, 'batch_id' => $batchId];
    }

    public static function getBatch(Db $db, array $actor, int $batchId): array
    {
        actor_require($actor, 'customers.import');
        $batch = $db->one('SELECT * FROM import_batches WHERE id = :id', ['id' => $batchId]);
        if (!$batch) {
            throw new HttpError(404, 'Партия импорта не найдена');
        }
        $batch['report'] = $batch['report_json'] ? json_decode((string) $batch['report_json'], true) : null;
        return $batch;
    }

    private static function loadByToken(array $body): array
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string) ($body['token'] ?? '')) ?? '';
        if (strlen($token) !== 32) {
            throw new HttpError(422, 'Сначала загрузите файл');
        }
        $dir = dirname(__DIR__) . '/data/imports';
        $found = null;
        $ext = null;
        foreach (['csv', 'xlsx', 'xls'] as $try) {
            $path = $dir . '/' . $token . '.' . $try;
            if (is_file($path)) {
                $found = $path;
                $ext = $try;
                break;
            }
        }
        if (!$found) {
            throw new HttpError(404, 'Файл импорта не найден, загрузите снова');
        }
        [$headers, $rows] = self::readFile($found, $ext);
        return [$headers, $rows, $found];
    }

    private static function readFile(string $path, string $ext): array
    {
        if ($ext === 'csv') {
            return self::readCsv($path);
        }
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new HttpError(422, 'PhpSpreadsheet не установлен. Сохраните файл как CSV (UTF-8).');
        }
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);
        if (!$matrix) {
            return [[], []];
        }
        $headers = array_map(static fn ($v) => trim((string) $v), array_shift($matrix) ?? []);
        $rows = [];
        foreach ($matrix as $row) {
            if (self::rowEmpty($row)) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $h) {
                $assoc[$h] = isset($row[$i]) ? (string) $row[$i] : '';
            }
            $rows[] = $assoc;
        }
        return [$headers, $rows];
    }

    private static function readCsv(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new HttpError(500, 'Не удалось прочитать CSV');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $tmp = fopen('php://temp', 'r+');
        fwrite($tmp, $raw);
        rewind($tmp);
        $headers = fgetcsv($tmp, 0, ',', '"', '\\');
        if ($headers === false) {
            fclose($tmp);
            return [[], []];
        }
        $headers = array_map(static fn ($v) => trim((string) $v), $headers);
        $rows = [];
        while (($cols = fgetcsv($tmp, 0, ',', '"', '\\')) !== false) {
            if (self::rowEmpty($cols)) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $h) {
                $assoc[$h] = isset($cols[$i]) ? (string) $cols[$i] : '';
            }
            $rows[] = $assoc;
        }
        fclose($tmp);
        return [$headers, $rows];
    }

    private static function rowEmpty(array $row): bool
    {
        foreach ($row as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function autoMap(array $headers): array
    {
        $map = [];
        foreach (self::FIELD_MAP as $field => $aliases) {
            $map[$field] = null;
            foreach ($headers as $header) {
                $h = mb_strtolower(trim($header), 'UTF-8');
                foreach ($aliases as $alias) {
                    $a = mb_strtolower($alias, 'UTF-8');
                    if ($h === $a || str_starts_with($h, $a) || str_contains($h, $a)) {
                        $map[$field] = $header;
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    private static function parseRows(
        Db $db,
        array $rows,
        array $headers,
        array $mapping,
        array $duplicateMode,
        string $existingMode,
        bool $forPreview,
    ): array {
        $errors = [];
        $preview = [];
        $actions = [];
        $counts = [
            'will_create_customers' => 0,
            'will_create_subscriptions' => 0,
            'will_update' => 0,
            'will_skip' => 0,
        ];
        $byKey = [];
        $normalized = [];
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $mapped = self::mapRow($row, $mapping);
            $item = self::validateMapped($mapped, $rowNum);
            if ($item['errors']) {
                foreach ($item['errors'] as $msg) {
                    $errors[] = ['row' => $rowNum, 'message' => $msg];
                }
            }
            $key = name_key((string) ($item['data']['full_name'] ?? ''));
            $item['row_num'] = $rowNum;
            $item['name_key'] = $key;
            $item['raw'] = $row;
            $normalized[] = $item;
            if ($key !== '') {
                $byKey[$key][] = $rowNum;
            }
        }
        $duplicateGroups = [];
        foreach ($byKey as $key => $nums) {
            if (count($nums) > 1) {
                $mode = $duplicateMode[$key] ?? 'merge';
                $duplicateGroups[] = [
                    'name_key' => $key,
                    'rows' => $nums,
                    'full_name' => $normalized[$nums[0] - 2]['data']['full_name'] ?? '',
                    'mode' => $mode,
                ];
            }
        }
        $keyToCustomer = [];
        $seenNewInFile = [];
        foreach ($normalized as $item) {
            if ($item['errors']) {
                continue;
            }
            $key = $item['name_key'];
            $rowNum = $item['row_num'];
            $mode = $duplicateMode[$key] ?? 'merge';
            $existing = null;
            if ($mode === 'merge' && isset($keyToCustomer[$key]) && $keyToCustomer[$key] > 0) {
                $existing = ['id' => $keyToCustomer[$key]];
            } elseif ($mode === 'merge') {
                $existing = $db->one('SELECT id, full_name, phone FROM customers WHERE name_key = :key ORDER BY id ASC LIMIT 1', ['key' => $key]);
                if ($existing) {
                    $keyToCustomer[$key] = (int) $existing['id'];
                }
            }
            $hasRations = false;
            if ($existing) {
                $hasRations = (bool) $db->one(
                    'SELECT id FROM client_subscriptions WHERE customer_id = :id LIMIT 1',
                    ['id' => $existing['id']],
                );
            }
            $resolved = $existingMode;
            if ($resolved === 'auto') {
                $resolved = (!$existing || !$hasRations) ? 'create_subscription' : 'skip';
            }
            if ($mode === 'merge' && isset($seenNewInFile[$key])) {
                $action = $seenNewInFile[$key] === 'skip' ? 'skip' : 'create_subscription_same';
            } elseif (!$existing) {
                $action = 'create_customer_and_subscription';
                $seenNewInFile[$key] = 'create';
            } elseif ($resolved === 'skip') {
                $action = 'skip';
                $seenNewInFile[$key] = 'skip';
            } elseif ($resolved === 'update_empty') {
                $action = 'update_empty';
                $seenNewInFile[$key] = 'update';
            } else {
                $action = 'create_subscription';
                $keyToCustomer[$key] = (int) $existing['id'];
                $seenNewInFile[$key] = 'create';
            }
            $preview[] = array_merge($item['data'], [
                'row' => $rowNum,
                'action' => $action,
                'existing_customer_id' => $existing['id'] ?? null,
                'name_key' => $key,
            ]);
            $actions[$rowNum] = [
                'action' => $action,
                'data' => $item['data'],
                'name_key' => $key,
                'existing_id' => isset($existing['id']) ? (int) $existing['id'] : null,
                'duplicate_mode' => $mode,
            ];
        }
        $reCust = 0;
        $reSub = 0;
        $reUpd = 0;
        $reSkip = 0;
        $seenNew = [];
        foreach ($actions as $act) {
            if ($act['action'] === 'create_customer_and_subscription') {
                $k = $act['name_key'];
                if ($act['duplicate_mode'] === 'merge' && isset($seenNew[$k])) {
                    $reSub++;
                } else {
                    $reCust++;
                    $reSub++;
                    $seenNew[$k] = true;
                }
            } elseif (in_array($act['action'], ['create_subscription', 'create_subscription_same'], true)) {
                $reSub++;
            } elseif ($act['action'] === 'update_empty') {
                $reUpd++;
            } elseif ($act['action'] === 'skip') {
                $reSkip++;
            }
        }
        $counts = [
            'will_create_customers' => $reCust,
            'will_create_subscriptions' => $reSub,
            'will_update' => $reUpd,
            'will_skip' => $reSkip,
        ];
        return [
            'preview' => $preview,
            'counts' => $counts,
            'errors' => $errors,
            'duplicate_groups' => $duplicateGroups,
            'actions' => $actions,
        ];
    }

    private static function mapRow(array $row, array $mapping): array
    {
        $out = [];
        foreach (self::FIELD_MAP as $field => $_) {
            $header = $mapping[$field] ?? null;
            if ($header === null || $header === '' || $header === '__skip') {
                $out[$field] = null;
                continue;
            }
            $out[$field] = $row[$header] ?? null;
        }
        return $out;
    }

    private static function validateMapped(array $mapped, int $rowNum): array
    {
        $errors = [];
        $fullName = trim((string) ($mapped['full_name'] ?? ''));
        if ($fullName === '') {
            $errors[] = 'Пустое ФИО';
        }
        $line = trim((string) ($mapped['line'] ?? ''));
        if ($line !== '' && !in_array($line, NutritionService::LINES, true)) {
            $errors[] = 'Линейка не из списка (Премиум/Стандарт)';
        }
        if ($line === '') {
            $errors[] = 'Не указана линейка';
        }
        $statusRaw = trim((string) ($mapped['status'] ?? ''));
        $status = self::mapStatus($statusRaw);
        if ($statusRaw !== '' && $status === null) {
            $errors[] = 'Неизвестный статус';
        }
        foreach (['period_start', 'period_end', 'end_date'] as $dateField) {
            $v = trim((string) ($mapped[$dateField] ?? ''));
            if ($v !== '' && !self::validDate($v)) {
                $errors[] = 'Неверная дата: ' . $dateField;
            }
        }
        $tariff = trim((string) ($mapped['tariff_days'] ?? ''));
        $tariffDays = null;
        if ($tariff !== '') {
            if (!is_numeric($tariff) || !in_array((int) $tariff, NutritionService::TARIFF_DAYS, true)) {
                // allow empty / unknown as null? Plan says 7,6,5,4 — invalid should error
                if (is_numeric($tariff)) {
                    $errors[] = 'Тариф дней должен быть 7, 6, 5 или 4';
                } else {
                    $errors[] = 'Неверное значение дней доставки';
                }
            } else {
                $tariffDays = (int) $tariff;
            }
        }
        $delivery = trim((string) ($mapped['delivery_time'] ?? ''));
        if ($delivery !== '' && !in_array($delivery, NutritionService::DELIVERY_TIMES, true)) {
            $errors[] = 'Время доставки: утро или вечер';
        }
        $pauses = trim((string) ($mapped['pauses'] ?? ''));
        if (mb_strtolower($pauses, 'UTF-8') === 'нет') {
            $pauses = '';
        }
        $needs = trim((string) ($mapped['needs_review'] ?? ''));
        $needsReview = in_array(mb_strtolower($needs, 'UTF-8'), ['да', '1', 'yes', 'true'], true) ? 1 : 0;
        $ref = trim((string) ($mapped['tariff_days_ref'] ?? ''));
        $data = [
            'full_name' => $fullName,
            'line' => $line,
            'section' => self::nullIfEmpty($mapped['section'] ?? null),
            'tariff_days' => $tariffDays,
            'pack_days' => self::nullIfEmpty($mapped['pack_days'] ?? null),
            'kcal' => self::nullIfEmpty($mapped['kcal'] ?? null),
            'ration_no' => self::nullIfEmpty($mapped['ration_no'] ?? null),
            'diet' => self::nullIfEmpty($mapped['diet'] ?? null),
            'delivery_time' => $delivery !== '' ? $delivery : null,
            'period_start' => self::nullIfEmpty($mapped['period_start'] ?? null),
            'period_end' => self::nullIfEmpty($mapped['period_end'] ?? null),
            'end_date' => self::nullIfEmpty($mapped['end_date'] ?? null),
            'pauses' => $pauses !== '' ? $pauses : null,
            'status' => $status ?? 'active',
            'tariff_days_ref' => $ref !== '' && is_numeric($ref) ? (int) $ref : null,
            'flag_mark' => self::nullIfEmpty($mapped['flag_mark'] ?? null),
            'notes' => self::nullIfEmpty($mapped['notes'] ?? null),
            'source_row' => isset($mapped['source_row']) && is_numeric($mapped['source_row']) ? (int) $mapped['source_row'] : $rowNum,
            'source_text' => self::nullIfEmpty($mapped['source_text'] ?? null),
            'needs_review' => $needsReview,
            'review_reasons' => self::nullIfEmpty($mapped['review_reasons'] ?? null),
        ];
        return ['data' => $data, 'errors' => $errors];
    }

    private static function mapStatus(string $raw): ?string
    {
        if ($raw === '') {
            return 'active';
        }
        $map = [
            'активный' => 'active',
            'active' => 'active',
            'подписка закончилась' => 'ended',
            'закончилась' => 'ended',
            'ended' => 'ended',
            'пауза' => 'paused',
            'paused' => 'paused',
        ];
        $key = mb_strtolower(trim($raw), 'UTF-8');
        return $map[$key] ?? null;
    }

    private static function validDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $dt && $dt->format('Y-m-d') === $v;
    }

    private static function nullIfEmpty(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function applyAction(Db $db, array $actor, int $batchId, array $action, array &$counts, array &$keyCustomerIds): void
    {
        $type = $action['action'];
        $data = $action['data'];
        $key = $action['name_key'];
        $now = app_now();

        if ($type === 'skip') {
            $counts['skipped_rows']++;
            return;
        }

        $customerId = $action['existing_id'];
        if ($type === 'create_customer_and_subscription') {
            if (($action['duplicate_mode'] ?? 'merge') === 'merge' && isset($keyCustomerIds[$key])) {
                $customerId = $keyCustomerIds[$key];
                $type = 'create_subscription_same';
            } else {
                $customerId = self::createCustomer($db, $actor, $batchId, $data, $key, $now);
                $counts['created_customers']++;
                if (($action['duplicate_mode'] ?? 'merge') === 'merge') {
                    $keyCustomerIds[$key] = $customerId;
                }
            }
        } elseif ($type === 'create_subscription_same') {
            $customerId = $keyCustomerIds[$key] ?? $action['existing_id'];
            if (!$customerId) {
                throw new RuntimeException('Нет клиента для дубля ФИО');
            }
        } elseif ($type === 'create_subscription') {
            $customerId = $action['existing_id'];
            if (($action['duplicate_mode'] ?? 'merge') === 'merge' && isset($keyCustomerIds[$key])) {
                $customerId = $keyCustomerIds[$key];
            }
            if (!$customerId) {
                throw new RuntimeException('Клиент не найден');
            }
            $keyCustomerIds[$key] = $customerId;
        } elseif ($type === 'update_empty') {
            $customerId = $action['existing_id'];
            if (!$customerId) {
                throw new RuntimeException('Клиент не найден');
            }
            $sub = $db->one(
                'SELECT * FROM client_subscriptions WHERE customer_id = :id ORDER BY id DESC LIMIT 1',
                ['id' => $customerId],
            );
            if (!$sub) {
                $subId = self::insertSubscription($db, $batchId, $customerId, $data, $now);
                self::logChange($db, $batchId, 'client_subscriptions', $subId, 'create', null);
                $counts['created_subscriptions']++;
                return;
            }
            $before = $sub;
            $updates = [];
            foreach ($data as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $cur = $sub[$field] ?? null;
                if ($cur === null || $cur === '') {
                    $updates[$field] = $value;
                }
            }
            if ($updates) {
                $sets = [];
                $params = ['id' => $sub['id'], 'updated_at' => $now];
                foreach ($updates as $k => $v) {
                    $sets[] = $k . ' = :' . $k;
                    $params[$k] = $v;
                }
                $sets[] = 'updated_at = :updated_at';
                $db->exec('UPDATE client_subscriptions SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
                self::logChange($db, $batchId, 'client_subscriptions', (int) $sub['id'], 'update', $before);
                $counts['updated_rows']++;
            } else {
                $counts['skipped_rows']++;
            }
            return;
        }

        if (in_array($type, ['create_customer_and_subscription', 'create_subscription', 'create_subscription_same'], true)) {
            $subId = self::insertSubscription($db, $batchId, (int) $customerId, $data, $now);
            self::logChange($db, $batchId, 'client_subscriptions', $subId, 'create', null);
            $counts['created_subscriptions']++;
        }
    }

    private static function createCustomer(Db $db, array $actor, int $batchId, array $data, string $key, string $now): int
    {
        $id = $db->insert(
            'INSERT INTO customers (full_name, phone, phone_digits, whatsapp, status, vip, manager_id, source, city, district, comment, first_contact_at, registered_at, created_at, name_key)
             VALUES (:name, :phone, :digits, :whatsapp, :status, 0, :manager, :source, :city, :district, NULL, :contact, :registered, :created, :name_key)',
            [
                'name' => $data['full_name'],
                'phone' => '',
                'digits' => '',
                'whatsapp' => '',
                'status' => 'active',
                'manager' => $actor['employee_id'] ?? null,
                'source' => 'import',
                'city' => 'Алматы',
                'district' => '',
                'contact' => app_today(),
                'registered' => app_today(),
                'created' => $now,
                'name_key' => $key,
            ],
        );
        self::logChange($db, $batchId, 'customers', $id, 'create', null);
        return $id;
    }

    private static function insertSubscription(Db $db, int $batchId, int $customerId, array $data, string $now): int
    {
        return $db->insert(
            'INSERT INTO client_subscriptions (
                customer_id, full_name, line, section, tariff_days, pack_days, kcal, ration_no, diet,
                delivery_time, period_start, period_end, end_date, pauses, status, tariff_days_ref,
                flag_mark, notes, source_row, source_text, needs_review, review_reasons,
                import_batch_id, created_at, updated_at
             ) VALUES (
                :customer_id, :full_name, :line, :section, :tariff_days, :pack_days, :kcal, :ration_no, :diet,
                :delivery_time, :period_start, :period_end, :end_date, :pauses, :status, :tariff_days_ref,
                :flag_mark, :notes, :source_row, :source_text, :needs_review, :review_reasons,
                :import_batch_id, :created_at, :updated_at
             )',
            [
                'customer_id' => $customerId,
                'full_name' => $data['full_name'],
                'line' => $data['line'],
                'section' => $data['section'],
                'tariff_days' => $data['tariff_days'],
                'pack_days' => $data['pack_days'],
                'kcal' => $data['kcal'],
                'ration_no' => $data['ration_no'],
                'diet' => $data['diet'],
                'delivery_time' => $data['delivery_time'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'end_date' => $data['end_date'],
                'pauses' => $data['pauses'],
                'status' => $data['status'],
                'tariff_days_ref' => $data['tariff_days_ref'],
                'flag_mark' => $data['flag_mark'] !== null ? mb_substr((string) $data['flag_mark'], 0, 5) : null,
                'notes' => $data['notes'],
                'source_row' => $data['source_row'],
                'source_text' => $data['source_text'],
                'needs_review' => $data['needs_review'] ? 1 : 0,
                'review_reasons' => $data['review_reasons'],
                'import_batch_id' => $batchId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private static function logChange(Db $db, int $batchId, string $type, int $id, string $action, ?array $before): void
    {
        $db->insert(
            'INSERT INTO import_changes (batch_id, entity_type, entity_id, action, before_json, created_at)
             VALUES (:batch, :type, :id, :action, :before, :created)',
            [
                'batch' => $batchId,
                'type' => $type,
                'id' => $id,
                'action' => $action,
                'before' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'created' => app_now(),
            ],
        );
    }

    private static function restoreSubscription(Db $db, int $id, array $before): void
    {
        $cols = [
            'full_name', 'line', 'section', 'tariff_days', 'pack_days', 'kcal', 'ration_no', 'diet',
            'delivery_time', 'period_start', 'period_end', 'end_date', 'pauses', 'status', 'tariff_days_ref',
            'flag_mark', 'notes', 'source_row', 'source_text', 'needs_review', 'review_reasons',
        ];
        $sets = [];
        $params = ['id' => $id, 'updated_at' => app_now()];
        foreach ($cols as $col) {
            if (array_key_exists($col, $before)) {
                $sets[] = $col . ' = :' . $col;
                $params[$col] = $before[$col];
            }
        }
        $sets[] = 'updated_at = :updated_at';
        if ($sets) {
            $db->exec('UPDATE client_subscriptions SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        }
    }

    private static function restoreCustomer(Db $db, int $id, array $before): void
    {
        $cols = ['full_name', 'phone', 'phone_digits', 'status', 'name_key', 'comment', 'source'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($cols as $col) {
            if (array_key_exists($col, $before)) {
                $sets[] = $col . ' = :' . $col;
                $params[$col] = $before[$col];
            }
        }
        if ($sets) {
            $db->exec('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        }
    }

    private static function buildErrorCsv(array $errors): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['row', 'message'], ',', '"', '\\');
        foreach ($errors as $err) {
            fputcsv($fh, [$err['row'] ?? '', $err['message'] ?? ''], ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }
}
