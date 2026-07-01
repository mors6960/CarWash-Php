<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sonnys.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$client  = getSonnysClient();
$pdo     = getDB();
$siteId  = $_ENV['SONNYS_SITE_ID'];

echo "[fetch_employees] Starting\n";

// ── Step 1: Fetch employee list ──────────────────────────────────────────────
try {
    sonnysThrottle();
    $response  = $client->get('employee', ['query' => ['site_id' => $siteId]]);
    $employees = json_decode((string) $response->getBody(), true)['data']['employees'] ?? [];
} catch (\Exception $e) {
    echo "[fetch_employees] ERROR fetching employee list: " . $e->getMessage() . "\n";
    exit(1);
}

$stmt = $pdo->prepare("
    INSERT INTO employees_sonnys (employee_id, first_name, last_name, active, start_date, phone, email, fetched_at)
    VALUES (:employee_id, :first_name, :last_name, :active, :start_date, :phone, :email, NOW())
    ON DUPLICATE KEY UPDATE
        first_name = VALUES(first_name),
        last_name  = VALUES(last_name),
        active     = VALUES(active),
        fetched_at = NOW()
");

foreach ($employees as $emp) {
    $stmt->execute([
        ':employee_id' => $emp['employeeId'] ?? null,
        ':first_name'  => $emp['firstName']  ?? null,
        ':last_name'   => $emp['lastName']   ?? null,
        ':active'      => isset($emp['active']) ? (int) $emp['active'] : 1,
        ':start_date'  => !empty($emp['startDate']) ? date('Y-m-d', strtotime($emp['startDate'])) : null,
        ':phone'       => $emp['phone']      ?? null,
        ':email'       => $emp['email']      ?? null,
    ]);
}

echo "[fetch_employees] " . count($employees) . " employees stored\n";

// ── Step 2: Fetch clock entries per employee (14-day range limit) ────────────
$endDate   = new DateTime();
$startDate = (clone $endDate)->modify('-30 days');

$clockStmt = $pdo->prepare("
    INSERT INTO clock_entries
        (employee_id, site_code, clock_in, clock_out,
         regular_rate, regular_hours,
         overtime_eligible, overtime_rate, overtime_hours,
         was_modified, modification_timestamp, was_created_in_backoffice,
         fetched_at)
    VALUES
        (:employee_id, :site_code, :clock_in, :clock_out,
         :regular_rate, :regular_hours,
         :overtime_eligible, :overtime_rate, :overtime_hours,
         :was_modified, :modification_timestamp, :was_created_in_backoffice,
         NOW())
");

foreach ($employees as $emp) {
    $empId   = $emp['employeeId'];
    $current = clone $startDate;

    // Loop in 14-day chunks (API limit)
    while ($current < $endDate) {
        $chunkEnd = (clone $current)->modify('+13 days');
        if ($chunkEnd > $endDate) {
            $chunkEnd = clone $endDate;
        }

        $fromStr = $current->format('Y-m-d');
        $toStr   = $chunkEnd->format('Y-m-d');

        // Delete existing entries for this employee + window to avoid duplicates on re-sync
        $pdo->prepare("
            DELETE FROM clock_entries
            WHERE employee_id = ? AND clock_in >= ? AND clock_in <= ?
        ")->execute([$empId, $fromStr . ' 00:00:00', $toStr . ' 23:59:59']);

        try {
            sonnysThrottle();
            $response = $client->get("employee/{$empId}/clock-entries", [
                'query' => [
                    'startDate' => $current->format('Y-m-d'),
                    'endDate'   => $chunkEnd->format('Y-m-d'),
                ],
            ]);
            $data    = json_decode((string) $response->getBody(), true)['data'] ?? [];
            $weeks   = $data['weeks'] ?? [];
            $entries = [];
            foreach ($weeks as $week) {
                $entries = array_merge($entries, $week['clockEntries'] ?? []);
            }

            foreach ($entries as $entry) {
                $clockStmt->execute([
                    ':employee_id'             => $empId,
                    ':site_code'               => $entry['siteCode']              ?? $siteId,
                    ':clock_in'                => !empty($entry['clockIn'])
                                                  ? date('Y-m-d H:i:s', strtotime($entry['clockIn'])) : null,
                    ':clock_out'               => !empty($entry['clockOut'])
                                                  ? date('Y-m-d H:i:s', strtotime($entry['clockOut'])) : null,
                    ':regular_rate'            => $entry['regularRate']            ?? null,
                    ':regular_hours'           => $entry['regularHours']           ?? null,
                    ':overtime_eligible'       => isset($entry['overtimeEligible']) ? (int) $entry['overtimeEligible'] : 0,
                    ':overtime_rate'           => $entry['overtimeRate']           ?? null,
                    ':overtime_hours'          => $entry['overtimeHours']          ?? null,
                    ':was_modified'            => isset($entry['wasModified']) ? (int) $entry['wasModified'] : 0,
                    ':modification_timestamp'  => !empty($entry['modificationTimestamp'])
                                                  ? date('Y-m-d H:i:s', strtotime($entry['modificationTimestamp'])) : null,
                    ':was_created_in_backoffice' => isset($entry['wasCreatedInBackOffice']) ? (int) $entry['wasCreatedInBackOffice'] : 0,
                ]);
            }

            echo "[fetch_employees] Emp #{$empId} {$fromStr} → {$toStr}: " . count($entries) . " clock entries\n";

        } catch (\Exception $e) {
            echo "[fetch_employees] ERROR clock entries emp #{$empId}: " . $e->getMessage() . "\n";
        }

        $current->modify('+14 days');
    }
}

echo "[fetch_employees] Done\n";
