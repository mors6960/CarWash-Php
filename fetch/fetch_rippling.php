<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';


use GuzzleHttp\Client;

$pdo   = getDB();
$token = $_ENV['RIPPLING_API_TOKEN'] ?? '';

if (empty($token)) {
    echo "[fetch_rippling] ERROR: RIPPLING_API_TOKEN not set\n";
    exit(1);
}

$client = new Client([
    'base_uri' => 'https://rest.ripplingapis.com/',
    'timeout'  => 30,
    'headers'  => [
        'Authorization' => 'Bearer ' . $token,
        'Accept'        => 'application/json',
    ],
]);

echo "[fetch_rippling] Starting\n";

// All endpoints return { results: [...], next_link: ... }
function rGet(Client $c, string $path, array $q = []): array
{
    $all  = [];
    $opts = $q ? ['query' => $q] : [];
    do {
        $body = json_decode((string) $c->get($path, $opts)->getBody(), true);
        $page = $body['results'] ?? [];
        $all  = array_merge($all, $page);
        $next = $body['next_link'] ?? null;
        if ($next) {
            // next_link is a full URL — extract path + query
            $parsed = parse_url($next);
            $path   = ltrim($parsed['path'], '/');
            parse_str($parsed['query'] ?? '', $opts['query']);
        }
    } while ($next);
    return $all;
}

// Extract name from carwashoasis email prefix (e.g. "angeldiaz" → first="angeldiaz", last=null)
// Rippling V2 does not expose first/last name without user:read scope
function nameFromEmail(string $email): array
{
    $prefix = explode('@', $email)[0];
    return ['first' => ucfirst($prefix), 'last' => null];
}

// ── 1. Departments ────────────────────────────────────────────────────────────
$deptMap = [];
try {
    foreach (rGet($client, 'departments') as $d) {
        if (!empty($d['id'])) {
            $deptMap[$d['id']] = $d['name'] ?? null;
        }
    }
    echo "[fetch_rippling] " . count($deptMap) . " departments loaded\n";
} catch (Exception $ex) {
    echo "[fetch_rippling] WARN departments: " . $ex->getMessage() . "\n";
}

// ── 2. Compensations ──────────────────────────────────────────────────────────
$compMap = [];
try {
    foreach (rGet($client, 'compensations') as $c) {
        $wId  = $c['worker_id'] ?? null;
        $rate = $c['hourly_wage']['value'] ?? null;
        if ($wId && $rate !== null) {
            $compMap[$wId] = (float) $rate;
        }
    }
    echo "[fetch_rippling] " . count($compMap) . " compensations loaded\n";
} catch (Exception $ex) {
    echo "[fetch_rippling] WARN compensations: " . $ex->getMessage() . "\n";
}

// ── 3. Workers → employees_rippling ──────────────────────────────────────────
$workerCount = 0;
try {
    $workers = rGet($client, 'workers');

    $stmt = $pdo->prepare("
        INSERT INTO employees_rippling
            (rippling_id, first_name, last_name, work_email, department,
             role_name, employment_type, start_date, end_date, is_active,
             hourly_rate, fetched_at)
        VALUES
            (:rippling_id, :first_name, :last_name, :work_email, :department,
             :role_name, :employment_type, :start_date, :end_date, :is_active,
             :hourly_rate, NOW())
        ON DUPLICATE KEY UPDATE
            first_name      = VALUES(first_name),
            last_name       = VALUES(last_name),
            work_email      = VALUES(work_email),
            department      = VALUES(department),
            role_name       = VALUES(role_name),
            employment_type = VALUES(employment_type),
            start_date      = VALUES(start_date),
            end_date        = VALUES(end_date),
            is_active       = VALUES(is_active),
            hourly_rate     = VALUES(hourly_rate),
            fetched_at      = NOW()
    ");

    foreach ($workers as $w) {
        $wId   = $w['id'] ?? null;
        if (!$wId) continue;

        $email = $w['work_email'] ?? $w['personal_email'] ?? null;
        $name  = $email ? nameFromEmail($email) : ['first' => null, 'last' => null];
        $dept  = isset($w['department_id'], $deptMap[$w['department_id']])
                    ? $deptMap[$w['department_id']]
                    : null;

        $stmt->execute([
            ':rippling_id'     => $wId,
            ':first_name'      => $name['first'],
            ':last_name'       => $name['last'],
            ':work_email'      => $email,
            ':department'      => $dept,
            ':role_name'       => $w['title'] ?? null,
            ':employment_type' => $w['employment_type'] ?? 'FULL_TIME',
            ':start_date'      => $w['start_date'] ?? null,
            ':end_date'        => $w['end_date']   ?? null,
            ':is_active'       => ($w['status'] === 'ACTIVE') ? 1 : 0,
            ':hourly_rate'     => $compMap[$wId] ?? null,
        ]);
        $workerCount++;
    }

    echo "[fetch_rippling] {$workerCount} employees stored\n";

} catch (Exception $ex) {
    echo "[fetch_rippling] ERROR workers: " . $ex->getMessage() . "\n";
    exit(1);
}

// ── 4. Time Entries → rippling_time_entries ───────────────────────────────────
$entryCount = 0;
$endDt      = new DateTime();
$startDt    = (clone $endDt)->modify('-30 days');

try {
    $entries = rGet($client, 'time-entries', [
        'startDate' => $startDt->format('Y-m-d'),
        'endDate'   => $endDt->format('Y-m-d'),
    ]);

    // When rippling_entry_id is present use it as the unique key (fast path).
    // When it is NULL, fall back to INSERT IGNORE keyed on (rippling_id, date)
    // so the same day is never double-counted.
    $stmtById = $pdo->prepare("
        INSERT INTO rippling_time_entries
            (rippling_entry_id, rippling_id, date, hours_worked, overtime_hours,
             pay_period_start, pay_period_end, site_code, fetched_at)
        VALUES
            (:rippling_entry_id, :rippling_id, :date, :hours_worked, :overtime_hours,
             :pay_period_start, :pay_period_end, :site_code, NOW())
        ON DUPLICATE KEY UPDATE
            hours_worked   = VALUES(hours_worked),
            overtime_hours = VALUES(overtime_hours),
            fetched_at     = NOW()
    ");

    $stmtByDay = $pdo->prepare("
        INSERT IGNORE INTO rippling_time_entries
            (rippling_id, date, hours_worked, overtime_hours,
             pay_period_start, pay_period_end, site_code, fetched_at)
        SELECT :rippling_id, :date, :hours_worked, :overtime_hours,
               :pay_period_start, :pay_period_end, :site_code, NOW()
        FROM DUAL
        WHERE NOT EXISTS (
            SELECT 1 FROM rippling_time_entries
            WHERE rippling_id = :rippling_id2 AND date = :date2
        )
    ");

    foreach ($entries as $entry) {
        $wId     = $entry['worker_id'] ?? null;
        $entryId = $entry['id']        ?? null;
        if (!$wId) continue;

        $entryDate = null;
        if (!empty($entry['start_time'])) {
            $entryDate = (new DateTime($entry['start_time']))->format('Y-m-d');
        }
        if (!$entryDate) continue;

        $summary = $entry['time_entry_summary'] ?? [];
        $payPer  = $entry['pay_period'] ?? [];

        $regularHours  = (float) ($summary['regular_hours']   ?? 0);
        $overtimeHours = (float) ($summary['over_time_hours'] ?? 0);
        $ppStart       = $payPer['start_date'] ?? null;
        $ppEnd         = $payPer['end_date']   ?? null;

        if ($entryId !== null) {
            // Has a real entry ID — upsert on the UNIQUE rippling_entry_id key
            $stmtById->execute([
                ':rippling_entry_id' => $entryId,
                ':rippling_id'       => $wId,
                ':date'              => $entryDate,
                ':hours_worked'      => $regularHours,
                ':overtime_hours'    => $overtimeHours,
                ':pay_period_start'  => $ppStart,
                ':pay_period_end'    => $ppEnd,
                ':site_code'         => 'STOCK',
            ]);
        } else {
            // No entry ID — insert only if (rippling_id, date) not yet present
            $stmtByDay->execute([
                ':rippling_id'      => $wId,
                ':rippling_id2'     => $wId,
                ':date'             => $entryDate,
                ':date2'            => $entryDate,
                ':hours_worked'     => $regularHours,
                ':overtime_hours'   => $overtimeHours,
                ':pay_period_start' => $ppStart,
                ':pay_period_end'   => $ppEnd,
                ':site_code'        => 'STOCK',
            ]);
        }
        $entryCount++;
    }

    echo "[fetch_rippling] {$entryCount} time entries stored\n";

} catch (Exception $ex) {
    echo "[fetch_rippling] ERROR time-entries: " . $ex->getMessage() . "\n";
}

echo "[fetch_rippling] Done\n";
