<?php

/**
 * VK Groups Parser (актуальная версия 2025)
 * Использует OAuth 2.0 / Service Key
 */

// ================== КОНФИГУРАЦИЯ ==================
// Сервисный ключ (получен через dev.vk.com)
//54591054
$serviceToken = 'd1a0565ed1a0565ed1a0565e7cd2e0a810dd1a0d1a0565ebba430def3ddce2eeede55a6';

// Если используете User Token (OAuth 2.0)
$userToken = 'vk1.a.5M8ival1fk-TVzjK9Wi27Rs8UHWJY-hLKOAxTnHhnXKIsdvQT6Dn0TqDXjmPP1PLMCNbehlYnbQIgfXrUUvZIwlr7_58fuGSQzPKCz5a-ChnVudcI3GlXT_0rLoTZdtMr5VglLNda55pmWc4v0MsW4ewYFsxLvbbuASIBpWvTPvVZyn4QARvhpZrIsb0LlX1MXj7lHjzqisKlD7fUIdMwA'; // опционально, для методов с правами пользователя

$apiVersion = '5.199'; // актуальная версия API

// Ключевые слова и минус-слова
$keywords = [
    "Мастер маникюра", "Нейл-мастер", "Маникюр", "Парикмахер", "Брадмейкер", "Бровист", "Лашмейкер", "Наращивание ресниц", "Косметолог", "Чистка лица", "Перманентный макияж", "Татуаж", "Массажист", "Классический массаж", "Спортивный массаж", "Массаж на дому", "Шугаринг", "Депиляция", "Эпиляция", "Мастер тату", "Тату салон", "Логопед"
];

$minusWords = [
    'салон', 'студия', 'центр', 'сеть', 'барбершоп', 'клуб', 'клиника', 'спа',
    'обучение', 'курсы', 'школа', 'академия', 'ищу'
];

// Города (можно добавить ID городов для ускорения)
$cities = ['Москва', 'Санкт-Петербург', 'Новосибирск', 'Екатеринбург', 'Казань', 'Нижний Новгород', 'Красноярск', 'Челябинск', 'Самара', 'Уфа'];

// Параметры фильтрации
$minMembers = 100;
$maxMembers = 20000;
$lastActivityDays = 30;

// Файлы
$outputFile = 'parsed_groups.csv';
$progressFile = 'parse_progress.json';

// ================== ФУНКЦИИ ДЛЯ РАБОТЫ С API ==================

/**
 * Выполняет запрос к VK API (новый стандарт)
 */
function vkApiRequest($method, $params, $token, $version) {
    $params['access_token'] = $token;
    $params['v'] = $version;
    
    $url = 'https://api.vk.com/method/' . $method . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: VKParser/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "❌ HTTP ошибка: $httpCode\n";
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Обработка ошибок API
    if (isset($data['error'])) {
        $errorCode = $data['error']['error_code'];
        $errorMsg = $data['error']['error_msg'];
        
        // Ошибка 5: неверный токен
        if ($errorCode == 5) {
            echo "❌ Неверный токен доступа! Получите новый на dev.vk.com\n";
            return null;
        }
        
        // Ошибка 6: слишком много запросов
        if ($errorCode == 6) {
            echo "⚠️ Лимит запросов, ждём 1 секунду...\n";
            sleep(1);
            return vkApiRequest($method, $params, $token, $version); // retry
        }
        
        echo "❌ Ошибка API $errorCode: $errorMsg\n";
        return null;
    }
    
    return $data['response'] ?? null;
}

/**
 * Поиск групп по ключевому слову и городу
 */
function searchGroups($query, $token, $version, $offset = 0, $count = 200) {
    $params = [
        'q' => $query,
        'type' => 'group',
        'count' => $count,
        'offset' => $offset
    ];
    
    // Важно: groups.search требует Service Token!
    return vkApiRequest('groups.search', $params, $token, $version);
}

/**
 * Получение информации о группе (члены, описание и т.д.)
 */
function getGroupInfo($groupId, $token, $version) {
    $params = [
        'group_id' => $groupId,
        'fields' => 'description,members_count,status,activity'
    ];
    
    $result = vkApiRequest('groups.getById', $params, $token, $version);
    return $result[0] ?? null;
}

/**
 * Получение последнего поста в группе
 */
function getLastPost($groupId, $token, $version) {
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
 * Проверка наличия ключевых слов в тексте
 */
function matchesKeywords($text, $keywords) {
    $text = mb_strtolower($text, 'UTF-8');
    foreach ($keywords as $kw) {
        if (mb_strpos($text, mb_strtolower($kw, 'UTF-8')) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Проверка минус-слов
 */
function hasMinusWord($text, $minusWords) {
    $text = mb_strtolower($text, 'UTF-8');
    foreach ($minusWords as $mw) {
        if (mb_strpos($text, mb_strtolower($mw, 'UTF-8')) !== false) {
            return true;
        }
    }
    return false;
}

// ================== ОСНОВНАЯ ЛОГИКА ==================

echo "\n🚀 VK Groups Parser (актуальная версия 2025)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📌 API версия: $apiVersion\n";
echo "📌 Токен: " . substr($serviceToken, 0, 10) . "...\n\n";

// Проверка токена
echo "🔍 Проверяем токен...\n";
$testResult = vkApiRequest('groups.getById', ['group_id' => 1], $serviceToken, $apiVersion);
if (!$testResult) {
    echo "❌ Токен не работает! Проверьте:\n";
    echo "   1. Получен ли токен на dev.vk.com (не vkhost)\n";
    echo "   2. Не истёк ли срок действия\n";
    echo "   3. Есть ли у приложения права на groups.search\n";
    exit(1);
}
echo "✅ Токен валидный!\n\n";

$foundGroups = [];
$groupsCache = []; // для избежания дубликатов
$processedQueries = 0;

foreach ($cities as $city) {
    foreach ($keywords as $keyword) {
        $query = $keyword . ' ' . $city;
        echo "🔍 [$processedQueries] Поиск: $query\n";
        
        $offset = 0;
        $step = 200;
        $queryFound = 0;
        
        while ($offset < 1000) { // максимум 1000 групп на запрос
            $result = searchGroups($query, $serviceToken, $apiVersion, $offset, $step);
            
            if (!$result || empty($result['items'])) {
                break;
            }
            
            foreach ($result['items'] as $group) {
                $groupId = $group['id'];
                
                // Проверка дубликатов
                if (isset($groupsCache[$groupId])) {
                    continue;
                }
                
                // Фильтр по количеству участников
                $membersCount = $group['members_count'] ?? 0;
                if ($membersCount < $minMembers || $membersCount > $maxMembers) {
                    continue;
                }
                
                $groupName = $group['name'];
                $description = $group['description'] ?? '';
                
                // Минус-слова
                if (hasMinusWord($groupName, $minusWords) || hasMinusWord($description, $minusWords)) {
                    continue;
                }
                
                // Проверка на самозанятого мастера
                if (!matchesKeywords($groupName . ' ' . $description, $keywords)) {
                    continue;
                }
                
                // Проверка активности (последний пост)
                $lastPostDate = getLastPost($groupId, $serviceToken, $apiVersion);
                if (!$lastPostDate) {
                    continue;
                }
                
                $daysSinceLastPost = (time() - $lastPostDate) / 86400;
                if ($daysSinceLastPost > $lastActivityDays) {
                    continue;
                }
                
                // Всё ок, добавляем
                $groupsCache[$groupId] = true;
                $foundGroups[] = [
                    'id' => $groupId,
                    'name' => $groupName,
                    'members' => $membersCount,
                    'url' => "https://vk.com/club{$groupId}",
                    'last_post' => date('Y-m-d', $lastPostDate),
                    'description' => mb_substr($description, 0, 200)
                ];
                $queryFound++;
                
                echo "   ✅ {$groupName} (участников: {$membersCount})\n";
            }
            
            if (count($result['items']) < $step) {
                break;
            }
            $offset += $step;
            
            // Соблюдаем лимиты API (3 запроса/сек)
            usleep(350000);
        }
        
        echo "   📊 Найдено в запросе: $queryFound | Всего: " . count($foundGroups) . "\n";
        $processedQueries++;
        
        // Сохраняем прогресс каждые 10 запросов
        if ($processedQueries % 10 == 0) {
            saveProgress($foundGroups, $progressFile);
        }
    }
}

// ================== СОХРАНЕНИЕ РЕЗУЛЬТАТОВ ==================

saveToCsv($foundGroups, $outputFile);
echo "\n🎉 Готово! Найдено групп: " . count($foundGroups) . "\n";
echo "📁 Результат: $outputFile\n";

// ================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ==================

function saveProgress($groups, $file) {
    $data = [
        'timestamp' => time(),
        'count' => count($groups),
        'groups' => $groups
    ];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function saveToCsv($groups, $file) {
    $fp = fopen($file, 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($fp, ['ID', 'Название', 'Участников', 'Ссылка', 'Последний пост', 'Описание'], ';');
    
    foreach ($groups as $g) {
        fputcsv($fp, $g, ';');
    }
    fclose($fp);
}