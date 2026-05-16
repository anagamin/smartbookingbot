<?php

/**
 * Фильтрация групп ВК по активности (дате последнего поста)
 * 
 * Использование:
 * 1. Подготовьте файл groups.txt с ID групп (по одному на строку)
 * 2. Запустите скрипт: php filter_active_groups.php
 * 3. Получите файлы active_groups.txt и inactive_groups.txt
 */

// ================== КОНФИГУРАЦИЯ ==================

// VK API токен (должен иметь доступ к wall.get)
$accessToken = 'vk1.a.5M8ival1fk-TVzjK9Wi27Rs8UHWJY-hLKOAxTnHhnXKIsdvQT6Dn0TqDXjmPP1PLMCNbehlYnbQIgfXrUUvZIwlr7_58fuGSQzPKCz5a-ChnVudcI3GlXT_0rLoTZdtMr5VglLNda55pmWc4v0MsW4ewYFsxLvbbuASIBpWvTPvVZyn4QARvhpZrIsb0LlX1MXj7lHjzqisKlD7fUIdMwA';  // замените на ваш токен
$apiVersion = '5.199';

// Параметры фильтрации
$maxDaysInactive = 60;        // сколько дней без поста считается "неактивным"
$maxRequestsPerSecond = 3;    // лимит VK API
$saveProgressEvery = 50;       // сохранять прогресс каждые N групп

// Файлы
$inputFile = 'groups.csv';             // исходный файл с ID групп (по одному на строку)
$outputFile = 'active_groups.txt';     // результат (только активные ID)
$inactiveFile = 'inactive_groups.txt'; // неактивные ID (опционально)
$progressFile = 'filter_progress.json'; // прогресс для докачки
$logFile = 'filter_log.txt';            // лог ошибок

// ================== ФУНКЦИИ ==================

/**
 * Логирование
 */
function logMessage($msg) {
    global $logFile;
    echo $msg . "\n";
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

/**
 * Запрос к API VK с соблюдением лимитов
 */
function vkApiRequest($method, $params, $token, $version) {
    static $lastRequestTime = 0;
    global $maxRequestsPerSecond;
    
    // Соблюдаем интервал между запросами
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: VKActivityFilter/1.0']);
    
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
        
        // Ошибка 100: группа не существует или заблокирована
        if ($errorCode == 100) {
            logMessage("⚠️ Группа не найдена или заблокирована");
            return null;
        }
        
        logMessage("❌ Ошибка API $errorCode: $errorMsg");
        return null;
    }
    
    return $data['response'] ?? null;
}

/**
 * Получение даты последнего поста в группе
 */
function getLastPostDate($groupId, $token, $version) {
    $params = [
        'owner_id' => -$groupId,
        'count' => 1,
        'filter' => 'owner'
    ];
    
    $result = vkApiRequest('wall.get', $params, $token, $version);
    
    if ($result && isset($result['items'][0]['date'])) {
        return $result['items'][0]['date'];
    }
    
    return null;
}

/**
 * Загрузка ID групп из текстового файла (по одному на строку)
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
        if (preg_match('/^\d+$/', $line)) {
            $ids[] = (int)$line;
        } elseif (preg_match('/club(\d+)/', $line, $matches)) {
            // Если вдруг попали ссылки вида https://vk.com/club123456
            $ids[] = (int)$matches[1];
        }
    }
    
    return array_unique($ids);
}

/**
 * Сохранение прогресса
 */
function saveProgress($progress, $file) {
    file_put_contents($file, json_encode($progress, JSON_PRETTY_PRINT));
}

/**
 * Загрузка прогресса
 */
function loadProgress($file) {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['lastIndex'])) {
            return $data;
        }
    }
    return ['lastIndex' => 0, 'processedIds' => [], 'activeIds' => [], 'inactiveIds' => []];
}

/**
 * Сохранение результатов в текстовые файлы
 */
function saveResults($activeIds, $inactiveIds, $activeFile, $inactiveFile) {
    if (!empty($activeIds)) {
        file_put_contents($activeFile, implode("\n", $activeIds));
        logMessage("✅ Активные ID сохранены: " . count($activeIds) . " → $activeFile");
    }
    
    if (!empty($inactiveIds) && $inactiveFile) {
        file_put_contents($inactiveFile, implode("\n", $inactiveIds));
        logMessage("📁 Неактивные ID сохранены: " . count($inactiveIds) . " → $inactiveFile");
    }
}

// ================== ОСНОВНАЯ ЛОГИКА ==================

echo "\n🔍 ===== ФИЛЬТРАЦИЯ ГРУПП ПО АКТИВНОСТИ =====\n";
echo "📊 Последний пост не старше: $maxDaysInactive дней\n";
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
$activeIds = $progress['activeIds'] ?? [];
$inactiveIds = $progress['inactiveIds'] ?? [];

// Восстанавливаем прогресс (если уже что-то обработано)
$startIndex = $lastIndex;

echo "🔄 Начинаем проверку активности...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$startTime = time();
$cache = [];
$processedCount = count($processedIds);

for ($i = $startIndex; $i < $total; $i++) {
    $groupId = $groupIds[$i];
    
    // Проверяем, не обрабатывали ли уже эту группу
    if (in_array($groupId, $processedIds)) {
        continue;
    }
    
    // Прогресс-бар
    $percent = round(($i / $total) * 100, 1);
    echo "[$i/$total] ($percent%) Проверяем ID: $groupId... ";
    
    // Получаем дату последнего поста (с кэшем)
    if (isset($cache[$groupId])) {
        $lastPostDate = $cache[$groupId];
    } else {
        $lastPostDate = getLastPostDate($groupId, $accessToken, $apiVersion);
        $cache[$groupId] = $lastPostDate;
    }
    
    if ($lastPostDate === null) {
        echo "❌ нет постов (или ошибка)\n";
        $inactiveIds[] = $groupId;
    } else {
        $daysAgo = floor((time() - $lastPostDate) / 86400);
        
        if ($daysAgo <= $maxDaysInactive) {
            echo "✅ активна (пост $daysAgo дней назад)\n";
            $activeIds[] = $groupId;
        } else {
            echo "❌ неактивна (пост $daysAgo дней назад)\n";
            $inactiveIds[] = $groupId;
        }
    }
    
    $processedIds[] = $groupId;
    $processedCount++;
    
    // Сохраняем прогресс каждые N групп
    if ($processedCount % $saveProgressEvery == 0) {
        $progress['lastIndex'] = $i + 1;
        $progress['processedIds'] = $processedIds;
        $progress['activeIds'] = $activeIds;
        $progress['inactiveIds'] = $inactiveIds;
        saveProgress($progress, $progressFile);
        echo "   💾 Прогресс сохранён (обработано $processedCount из $total)\n";
    }
    
    // Статистика каждые 100 групп
    if ($processedCount % 100 == 0) {
        $elapsed = time() - $startTime;
        $rate = ($elapsed > 0) ? round($processedCount / ($elapsed / 60), 1) : 0;
        echo "   📊 Скорость: $rate групп/мин | Активных: " . count($activeIds) . " | Неактивных: " . count($inactiveIds) . "\n";
    }
}

// ================== СОХРАНЕНИЕ РЕЗУЛЬТАТОВ ==================

saveResults($activeIds, $inactiveIds, $outputFile, $inactiveFile);

// Очистка прогресса после успешного выполнения
@unlink($progressFile);

// Финальная статистика
$elapsed = time() - $startTime;
$minutes = round($elapsed / 60, 1);

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🎉 ГОТОВО!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ Активных групп (пост ≤ $maxDaysInactive дней): " . count($activeIds) . "\n";
echo "❌ Неактивных групп: " . count($inactiveIds) . "\n";
echo "📁 Результат активных: $outputFile\n";
if ($inactiveFile) {
    echo "📁 Результат неактивных: $inactiveFile\n";
}
echo "⏱️ Время выполнения: $minutes минут\n";
echo "📊 Скорость: " . round($total / ($elapsed / 60), 1) . " групп/мин\n";