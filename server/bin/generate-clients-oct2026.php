<?php
declare(strict_types=1);

/**
 * Generates clients_oct2026.csv with expected import stats:
 * 183 subscriptions, 175 clients, 120 active / 54 ended / 9 paused, Premium 87 / Standard 96
 */

$headers = [
    '№',
    'ФИО',
    'Сегмент линейки (Премиум/Стандарт)',
    'Раздел в таблице',
    'Дней доставки в неделю',
    'Пакет (дни)',
    'Калории (ккал)',
    'Рацион №',
    'Диета/особенности',
    'Время доставки (утро/вечер)',
    'Последний период: с',
    'Последний период: по',
    'Дата окончания',
    'Пауза (даты/нет)',
    'Метка (!,?)',
    'Примечание',
    'Исходная строка Excel',
    'Строка в источнике',
    'Статус клиента',
    'Дней в неделю (справочно…)',
    'Требует проверки',
    'Причины проверки',
    'Пауза действует…',
    'Дата окончания уже прошла',
    'Строк с таким ФИО',
    'Цвет ФИО (hex)',
];

$first = ['Амиреев', 'Бекова', 'Касымов', 'Нурланова', 'Сейтов', 'Омарова', 'Тлеуберген', 'Жумагали', 'Ахметов', 'Ким', 'Садыков', 'Ибрагимов', 'Мусина', 'Рахимов', 'Есенова'];
$second = ['Ерлан', 'Алия', 'Данияр', 'Айгерим', 'Алихан', 'Мадина', 'Диана', 'Асель', 'Нурлан', 'Сая', 'Арман', 'Айнур', 'Бауржан', 'Гаухар', 'Ермек'];

$rows = [];
$clientIndex = 0;
$statusPlan = array_merge(
    array_fill(0, 120, 'Активный'),
    array_fill(0, 54, 'Подписка закончилась'),
    array_fill(0, 9, 'Пауза'),
);
$linePlan = array_merge(
    array_fill(0, 87, 'Премиум'),
    array_fill(0, 96, 'Стандарт'),
);
shuffle($statusPlan);
// Keep deterministic
mt_srand(20261017);
$statusPlan = [];
for ($i = 0; $i < 120; $i++) $statusPlan[] = 'Активный';
for ($i = 0; $i < 54; $i++) $statusPlan[] = 'Подписка закончилась';
for ($i = 0; $i < 9; $i++) $statusPlan[] = 'Пауза';
$linePlan = [];
for ($i = 0; $i < 87; $i++) $linePlan[] = 'Премиум';
for ($i = 0; $i < 96; $i++) $linePlan[] = 'Стандарт';

$duplicateNames = [];
for ($i = 0; $i < 8; $i++) {
    $duplicateNames[] = $first[$i] . ' ' . $second[$i];
}

$names = [];
for ($i = 0; $i < 175; $i++) {
    if ($i < 8) {
        $names[] = $duplicateNames[$i];
    } else {
        $names[] = $first[$i % count($first)] . ' ' . $second[($i * 3) % count($second)] . ' ' . ($i + 1);
    }
}

// 183 rows: first 8 names appear twice, remaining 167 once = 8*2 + 167 = 183, clients = 8 + 167 = 175
$nameSequence = [];
foreach ($duplicateNames as $n) {
    $nameSequence[] = $n;
    $nameSequence[] = $n;
}
for ($i = 8; $i < 175; $i++) {
    $nameSequence[] = $names[$i];
}

$tariffs = [7, 6, 5, 4, null];
$diets = ['баланс', 'веган', 'без глютена', 'детокс', 'Стандарт+', 'Изи', ''];
$times = ['утро', 'вечер', ''];
$packs = ['21', '3; 21', '14', '7'];
$kcals = ['1200', '1500', '1800', '1500; 1800', ''];

for ($i = 0; $i < 183; $i++) {
    $status = $statusPlan[$i];
    $line = $linePlan[$i];
    $tariff = $tariffs[$i % count($tariffs)];
    if ($status === 'Пауза' && $tariff === null) {
        $tariff = 5;
    }
    $end = $status === 'Подписка закончилась'
        ? sprintf('2026-09-%02d', ($i % 28) + 1)
        : sprintf('2026-11-%02d', ($i % 28) + 1);
    $row = [
        (string) ($i + 1),
        $nameSequence[$i],
        $line,
        $line === 'Премиум' ? ($i % 2 ? 'Премиум Слим/Детокс' : 'Премиум') : ($i % 3 ? 'Диеты' : 'Стандарт'),
        $tariff === null ? '' : (string) $tariff,
        $packs[$i % count($packs)],
        $kcals[$i % count($kcals)],
        (string) (($i % 9) + 1),
        $diets[$i % count($diets)],
        $times[$i % count($times)],
        '2026-10-01',
        '2026-10-21',
        $end,
        $status === 'Пауза' ? '2026-10-10; 2026-10-12' : 'нет',
        $i % 17 === 0 ? '!' : ($i % 23 === 0 ? '?' : ''),
        $i % 11 === 0 ? 'звонок' : '',
        'excel row ' . ($i + 2),
        (string) ($i + 2),
        $status,
        $tariff === null ? '' : (string) $tariff,
        $i % 19 === 0 ? 'Да' : '',
        $i % 19 === 0 ? 'Проверить ФИО и тариф' : '',
        '',
        $status === 'Подписка закончилась' ? 'Да' : '',
        in_array($nameSequence[$i], $duplicateNames, true) ? '2' : '1',
        '',
    ];
    $rows[] = $row;
}

$path = dirname(__DIR__, 2) . '/clients_oct2026.csv';
$fh = fopen($path, 'wb');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, $headers, ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($fh, $row, ',', '"', '\\');
}
fclose($fh);
echo "Wrote {$path} with " . count($rows) . " rows\n";
