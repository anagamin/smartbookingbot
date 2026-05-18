<?php

/**
 * Usage:
 * php vk_group_owners.php groups.txt YOUR_VK_ACCESS_TOKEN
 *
 * groups.txt:
 * club123456
 * public123456
 * 123456
 * nail_pskov
 */

if ($argc < 3) {
    echo "Usage: php vk_group_owners.php groups.txt VK_ACCESS_TOKEN\n";
    exit(1);
}

$inputFile = $argv[1];
$token = $argv[2];

$apiVersion = '5.199';
$outCsv = __DIR__ . '/owners.csv';
$outJson = __DIR__ . '/owners.json';
$logFile = __DIR__ . '/owners_log.txt';

function logLine(string $text): void
{
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL, FILE_APPEND);
}

function vkApi(string $method, array $params): array
{
    global $token, $apiVersion;

    $params['access_token'] = $token;
    $params['v'] = $apiVersion;

    $url = 'https://api.vk.com/method/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Curl error: {$error}");
    }

    curl_close($ch);

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON response: {$raw}");
    }

    if (isset($data['error'])) {
        $msg = $data['error']['error_msg'] ?? 'Unknown VK error';
        $code = $data['error']['error_code'] ?? 'unknown';
        throw new RuntimeException("VK error {$code}: {$msg}");
    }

    return $data['response'] ?? [];
}

function normalizeGroupId(string $group): string
{
    $group = trim($group);
    $group = preg_replace('~^https?://vk\.com/~i', '', $group);
    $group = preg_replace('~^(club|public)~i', '', $group);
    return trim($group);
}

$groups = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (!$groups) {
    echo "Input file is empty\n";
    exit(1);
}

$results = [];
$seen = [];

foreach ($groups as $rawGroup) {
    $groupId = normalizeGroupId($rawGroup);

    if ($groupId === '') {
        continue;
    }

    logLine("Processing group: {$rawGroup} -> {$groupId}");

    try {
        // 1. Получаем руководителей сообщества.
        try {
            $managersResponse = vkApi('groups.getMembers', [
                'group_id' => $groupId,
                'filter' => 'managers',
                'fields' => 'screen_name,first_name,last_name',
            ]);

            $managers = $managersResponse['items'] ?? [];

            foreach ($managers as $manager) {
                $userId = $manager['id'] ?? null;

                if (!$userId) {
                    continue;
                }

                $key = $groupId . ':' . $userId . ':' . ($manager['role'] ?? 'manager');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $results[] = [
                    'group_input' => $rawGroup,
                    'group_id' => $groupId,
                    'user_id' => $userId,
                    'role' => $manager['role'] ?? 'manager',
                    'source' => 'groups.getMembers:managers',
                    'first_name' => $manager['first_name'] ?? '',
                    'last_name' => $manager['last_name'] ?? '',
                    'screen_name' => $manager['screen_name'] ?? '',
                    'profile_url' => 'https://vk.com/id' . $userId,
                ];
            }

            logLine("Managers found: " . count($managers));
        } catch (Throwable $e) {
            logLine("Managers error for {$groupId}: " . $e->getMessage());
        }

        usleep(350000);

        // 2. Получаем контакты сообщества, если они открыты.
        try {
            $groupInfoResponse = vkApi('groups.getById', [
                'group_ids' => $groupId,
                'fields' => 'contacts',
            ]);

            $groupInfo = $groupInfoResponse['groups'][0] ?? $groupInfoResponse[0] ?? null;

            if ($groupInfo && !empty($groupInfo['contacts'])) {
                foreach ($groupInfo['contacts'] as $contact) {
                    $userId = $contact['user_id'] ?? null;

                    if (!$userId) {
                        continue;
                    }

                    $key = $groupId . ':' . $userId . ':contact';

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    $results[] = [
                        'group_input' => $rawGroup,
                        'group_id' => $groupId,
                        'user_id' => $userId,
                        'role' => 'contact',
                        'source' => 'groups.getById:contacts',
                        'first_name' => $contact['desc'] ?? '',
                        'last_name' => '',
                        'screen_name' => '',
                        'profile_url' => 'https://vk.com/id' . $userId,
                    ];
                }

                logLine("Contacts found: " . count($groupInfo['contacts']));
            } else {
                logLine("No contacts found");
            }
        } catch (Throwable $e) {
            logLine("Contacts error for {$groupId}: " . $e->getMessage());
        }

        usleep(350000);
    } catch (Throwable $e) {
        logLine("Fatal group error {$groupId}: " . $e->getMessage());
    }
}

// JSON
file_put_contents(
    $outJson,
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// CSV
$fp = fopen($outCsv, 'w');

fputcsv($fp, [
    'group_input',
    'group_id',
    'user_id',
    'role',
    'source',
    'first_name',
    'last_name',
    'screen_name',
    'profile_url',
]);

foreach ($results as $row) {
    fputcsv($fp, [
        $row['group_input'],
        $row['group_id'],
        $row['user_id'],
        $row['role'],
        $row['source'],
        $row['first_name'],
        $row['last_name'],
        $row['screen_name'],
        $row['profile_url'],
    ]);
}

fclose($fp);

echo "Done.\n";
echo "Found records: " . count($results) . "\n";
echo "CSV: {$outCsv}\n";
echo "JSON: {$outJson}\n";
echo "Log: {$logFile}\n";