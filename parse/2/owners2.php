<?php

/**
 * Usage:
 * php vk_group_contacts.php groups.txt YOUR_VK_ACCESS_TOKEN
 *
 * groups.txt:
 * club123456
 * public123456
 * 123456
 * nail_pskov
 * 
 * Output:
 * group_id;contact_id
 */

if ($argc < 3) {
    echo "Usage: php vk_group_contacts.php groups.txt VK_ACCESS_TOKEN\n";
    exit(1);
}

$inputFile = $argv[1];
$token = $argv[2];

$apiVersion = '5.199';
$outFile = __DIR__ . '/contacts.txt';
$logFile = __DIR__ . '/contacts_log.txt';

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
        CURLOPT_SSL_VERIFYPEER => false,
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

// Очищаем выходной файл
file_put_contents($outFile, '');

$totalContacts = 0;
$processedGroups = 0;

foreach ($groups as $rawGroup) {
    $groupId = normalizeGroupId($rawGroup);

    if ($groupId === '') {
        continue;
    }

    $processedGroups++;
    logLine("Processing group: {$rawGroup} -> {$groupId}");

    try {
        // Получаем контакты сообщества
        $groupInfoResponse = vkApi('groups.getById', [
            'group_ids' => $groupId,
            'fields' => 'contacts',
        ]);

        $groupInfo = $groupInfoResponse['groups'][0] ?? $groupInfoResponse[0] ?? null;

        if ($groupInfo && !empty($groupInfo['contacts'])) {
            $contactsCount = 0;
            
            foreach ($groupInfo['contacts'] as $contact) {
                $contactId = $contact['user_id'] ?? null;
                
                if (!$contactId) {
                    continue;
                }
                
                // Записываем в файл: id_группы;id_контакта
                $line = $groupId . ';' . $contactId . "\n";
                file_put_contents($outFile, $line, FILE_APPEND);
                
                $contactsCount++;
                $totalContacts++;
                
                logLine("  Found contact: {$groupId};{$contactId}");
            }
            
            logLine("  Total contacts found: {$contactsCount}");
        } else {
            logLine("  No contacts found");
        }
        
        usleep(350000); // Задержка между запросами
        
    } catch (Throwable $e) {
        logLine("  Error for {$groupId}: " . $e->getMessage());
    }
}

echo "\n========== RESULTS ==========\n";
echo "Processed groups: {$processedGroups}\n";
echo "Total contacts found: {$totalContacts}\n";
echo "Output file: {$outFile}\n";
echo "Log file: {$logFile}\n";
echo "\nSample output:\n";

// Показываем первые 5 строк результата
$sample = file($outFile, FILE_IGNORE_NEW_LINES);
if ($sample) {
    for ($i = 0; $i < min(5, count($sample)); $i++) {
        echo "  " . $sample[$i] . "\n";
    }
    if (count($sample) > 5) {
        echo "  ...\n";
    }
} else {
    echo "  No contacts found\n";
}