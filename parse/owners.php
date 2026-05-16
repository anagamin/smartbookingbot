<?php

/**
 * Получение ID владельца (создателя) группы ВК по ID группы
 * Без требования прав администратора
 * 
 * Использование:
 * 1. Подготовьте файл groups.txt с ID групп (по одному на строку)
 * 2. Запустите скрипт: php get_group_owners.php
 * 3. Получите файл group_owners.csv (полная информация)
 */

// ================== КОНФИГУРАЦИЯ ==================

// VK API токен (базовые права, не требует админки групп)
$accessToken = 'vk1.a.5M8ival1fk-TVzjK9Wi27Rs8UHWJY-hLKOAxTnHhnXKIsdvQT6Dn0TqDXjmPP1PLMCNbehlYnbQIgfXrUUvZIwlr7_58fuGSQzPKCz5a-ChnVudcI3GlXT_0rLoTZdtMr5VglLNda55pmWc4v0MsW4ewYFsxLvbbuASIBpWvTPvVZyn4QARvhpZrIsb0LlX1MXj7lHjzqisKlD7fUIdMwA';  // замените на ваш токен
$apiVersion = '5.199';

// Параметры
$maxRequestsPerSecond = 3;
$saveProgressEvery = 50;

// Файлы
$inputFile = 'groups.txt';
$outputFile = 'group_owners.csv';
$progressFile = 'owners_progress.json';
$logFile = 'owners_log.txt';

// ================== ФУНКЦИИ ==================

function logMessage($msg) {
    global $logFile;
    echo $msg . "\n";
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

function vkApiRequest($method, $params, $token, $version) {
    static $lastRequestTime = 0;
    global $maxRequestsPerSecond;
    
    $minInterval = 1 / $maxRequestsPerSecond;
    $now = microtime(true);
    $sleepTime = $lastRequestTime + $minInterval - $now;
    if ($sleepTime > 0) {
        usleep($sleepTime * 1000000);
    }
    
    $params['access_token'] = $token;
    $params['v'] = $version;
    $url = 'https://api.vk.com/method/' . $method . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: VKGroupsParser/1.0']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $lastRequestTime = microtime(true);
    
    if ($httpCode !== 200) {
        logMessage("❌ HTTP ошибка: $httpCode");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['error'])) {
        $errorCode = $data['error']['error_code'];
        $errorMsg = $data['error']['error_msg'];
        
        if ($errorCode == 6) {
            logMessage("⚠️ Лимит запросов, ждём 2 секунды...");
            sleep(2);
            return vkApiRequest($method, $params, $token, $version);
        }
        
        if ($errorCode == 29) {
            logMessage("⚠️ Лимит метода, ждём 60 секунд...");
            sleep(60);
            return vkApiRequest($method, $params, $token, $version);
        }
        
        logMessage("❌ Ошибка API $errorCode: $errorMsg");
        return null;
    }
    
    return $data['response'] ?? null;
}

/**
 * Получение информации о группе
 */
function getGroupInfo($groupId, $token, $version) {
    $params = [
        'group_id' => $groupId,
        'fields' => 'name,type,description,site,status,members_count'
    ];
    
    $result = vkApiRequest('groups.getById', $params, $token, $version);
    
    if ($result && isset($result[0])) {
        return $result[0];
    }
    
    return null;
}

/**
 * Получение владельца группы через creator_id (работает для публичных страниц)
 */
function getOwnerByCreatorId($groupId, $token, $version) {
    $params = [
        'group_id' => $groupId,
        'fields' => 'creator_id'
    ];
    
    // Пробуем другой метод для публичных страниц
    $result = vkApiRequest('groups.getById', $params, $token, $version);
    
    if ($result && isset($result[0]['creator_id'])) {
        return $result[0]['creator_id'];
    }
    
    return null;
}

/**
 * Получение первого поста со стены группы (автор часто является создателем)
 */
function getFirstPostAuthor($groupId, $token, $version) {
    // Получаем первый пост на стене (сортировка по дате, самый старый)
    $params = [
        'owner_id' => -$groupId,
        'count' => 1,
        'offset' => 0,
        'filter' => 'owner'
    ];
    
    // Пробуем получить последний пост (обычно от создателя)
    $result = vkApiRequest('wall.get', $params, $token, $version);
    
    if ($result && isset($result['items'][0]['from_id'])) {
        return $result['items'][0]['from_id'];
    }
    
    return null;
}

/**
 * Получение владельца через парсинг HTML страницы группы
 * (как fallback, если API не помогает)
 */
function getOwnerByHtmlParsing($groupId) {
    $url = "https://vk.com/club{$groupId}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        return null;
    }
    
    // Ищем блок с создателем: обычно "Создатель", "Основатель", "Администратор"
    // Вариант 1: ищем ссылку на профиль в блоке управление
    if (preg_match('/group_admin_list.*?href="\/(id\d+)"/is', $html, $matches)) {
        return $matches[1];
    }
    
    // Вариант 2: ищем по тексту "Создатель" или "Основатель"
    if (preg_match('/(?:Создатель|Основатель|Creator).*?href="\/(id\d+)"/is', $html, $matches)) {
        return $matches[1];
    }
    
    // Вариант 3: ищем owner_id в JavaScript данных
    if (preg_match('/"owner_id":(\d+)/', $html, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Определение владельца группы комбинированным методом
 */
function getGroupOwner($groupId, $token, $version) {
    $methods = [
        'creator_id' => function($id, $t, $v) { return getOwnerByCreatorId($id, $t, $v); },
        'first_post' => function($id, $t, $v) { return getFirstPostAuthor($id, $t, $v); },
        'html_parse' => function($id, $t, $v) { return getOwnerByHtmlParsing($id); }
    ];
    
    foreach ($methods as $name => $method) {
        $ownerId = $method($groupId, $token, $version);
        if ($ownerId) {
            logMessage("   Метод '$name' нашёл владельца: $ownerId");
            return $ownerId;
        }
    }
    
    return null;
}

/**
 * Загрузка ID групп из текстового файла
 */
function loadGroupIds($file) {
    if (!file_exists($file)) {
        die("❌ Файл $file не найден!\n");
    }
    
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    $ids = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (preg_match('/club(\d+)/', $line, $matches)) {
            $ids[] = (int)$matches[1];
        } elseif (preg_match('/^\d+$/', $line)) {
            $ids[] = (int)$line;
        }
    }
    
    return array_unique($ids);
}

function saveProgress($progress, $file) {
    file_put_contents($file, json_encode($progress, JSON_PRETTY_PRINT));
}

function loadProgress($file) {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['lastIndex'])) {
            return $data;
        }
    }
    return ['lastIndex' => 0, 'processedIds' => [], 'results' => []];
}

function saveResults($results, $file) {
    $fp = fopen($file, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['group_id', 'group_name', 'owner_id', 'method', 'error'], ';');
    
    foreach ($results as $result) {
        fputcsv($fp, [
            $result['group_id'],
            $result['group_name'] ?? '',
            $result['owner_id'] ?? '',
            $result['method'] ?? '',
            $result['error'] ?? ''
        ], ';');
    }
    
    fclose($fp);
}

// ================== ОСНОВНАЯ ЛОГИКА ==================

echo "\n👤 ===== ПОИСК ВЛАДЕЛЬЦЕВ ГРУПП ВК (без прав админа) =====\n";
echo "🔑 Токен: " . substr($accessToken, 0, 15) . "...\n\n";

// Проверка токена
echo "🔍 Проверка токена...\n";
$testResult = vkApiRequest('groups.getById', ['group_id' => 1], $accessToken, $apiVersion);
if (!$testResult) {
    die("❌ Токен не работает! Проверьте access_token\n");
}
echo "✅ Токен валидный\n\n";

// Загружаем ID групп
echo "📂 Загрузка ID из $inputFile...\n";
$groupIds = loadGroupIds($inputFile);
$total = count($groupIds);
echo "✅ Загружено ID: $total\n\n";

if ($total == 0) {
    die("❌ В файле не найдено ни одного ID группы\n");
}

// Загружаем прогресс
$progress = loadProgress($progressFile);
$lastIndex = $progress['lastIndex'];
$processedIds = $progress['processedIds'] ?? [];
$results = $progress['results'] ?? [];

$resultsMap = [];
foreach ($results as $r) {
    $resultsMap[$r['group_id']] = $r;
}

echo "🔄 Начинаем поиск владельцев...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$startTime = time();
$processedCount = count($processedIds);

for ($i = $lastIndex; $i < $total; $i++) {
    $groupId = $groupIds[$i];
    
    if (in_array($groupId, $processedIds) || isset($resultsMap[$groupId])) {
        continue;
    }
    
    $percent = round(($i / $total) * 100, 1);
    echo "[$i/$total] ($percent%) Группа: $groupId... ";
    
    // Получаем информацию о группе
    $groupInfo = getGroupInfo($groupId, $accessToken, $apiVersion);
    $groupName = $groupInfo['name'] ?? 'Неизвестно';
    
    // Получаем владельца
    $ownerData = getGroupOwner($groupId, $accessToken, $apiVersion);
    
    if ($ownerData) {
        echo "✅ владелец: $ownerData\n";
        $results[] = [
            'group_id' => $groupId,
            'group_name' => $groupName,
            'owner_id' => $ownerData,
            'method' => 'detected',
            'error' => ''
        ];
    } else {
        echo "❌ владелец не найден\n";
        $results[] = [
            'group_id' => $groupId,
            'group_name' => $groupName,
            'owner_id' => '',
            'method' => '',
            'error' => 'Владелец не найден (нет публичных данных)'
        ];
    }
    
    $processedIds[] = $groupId;
    $processedCount++;
    
    if ($processedCount % $saveProgressEvery == 0) {
        $progress['lastIndex'] = $i + 1;
        $progress['processedIds'] = $processedIds;
        $progress['results'] = $results;
        saveProgress($progress, $progressFile);
        saveResults($results, $outputFile . '.tmp');
        echo "   💾 Прогресс сохранён (обработано $processedCount из $total)\n";
    }
    
    if ($processedCount % 100 == 0) {
        $elapsed = time() - $startTime;
        $rate = ($elapsed > 0) ? round($processedCount / ($elapsed / 60), 1) : 0;
        $foundCount = count(array_filter($results, function($r) { return !empty($r['owner_id']); }));
        echo "   📊 Скорость: $rate групп/мин | Найдено: $foundCount из " . count($results) . "\n";
    }
}

saveResults($results, $outputFile);

if (file_exists($outputFile . '.tmp')) {
    unlink($outputFile . '.tmp');
}

@unlink($progressFile);

$elapsed = time() - $startTime;
$minutes = round($elapsed / 60, 1);
$foundCount = count(array_filter($results, function($r) { return !empty($r['owner_id']); }));

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🎉 ГОТОВО!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ Всего групп: " . count($results) . "\n";
echo "✅ Найдено владельцев: $foundCount\n";
echo "❌ Не найдено: " . (count($results) - $foundCount) . "\n";
echo "📁 Результат: $outputFile\n";
echo "⏱️ Время: $minutes минут\n";