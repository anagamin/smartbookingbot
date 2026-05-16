<?php

/**
 * Получение access_token VK с нужными scope через OAuth 2.0 + PKCE
 * Запуск: php get_vk_token.php
 */

// ================== КОНФИГУРАЦИЯ ==================
$clientId = 54591054;        // ID вашего приложения (замените!)
$clientSecret = 'd1a0565ed1a0565ed1a0565e7cd2e0a810dd1a0d1a0565ebba430def3ddce2eeede55a6'; // Секретный ключ (замените!)
$redirectUri = 'https://oauth.vk.com/blank.html'; // Должен совпадать с настройками приложения

// Запрашиваемые права (scope) — здесь указываем те, которые нужны вашему парсеру
$scopes = [
    'groups',      // доступ к группам (нужен для groups.search)
    'wall',        // доступ к стене
    'offline',     // для получения бессрочного токена (необязательно)
    'photos',      // доступ к фото (если нужно)
    'friends'      // доступ к друзьям
];

// ================== ФУНКЦИИ PKCE ==================

/**
 * Генерация code_verifier (случайная строка 43-128 символов)
 */
function generateCodeVerifier($length = 64) {
    if ($length < 43) $length = 43;
    if ($length > 128) $length = 128;
    
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-._~';
    $verifier = '';
    $maxIndex = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $verifier .= $chars[random_int(0, $maxIndex)];
    }
    
    return $verifier;
}

/**
 * Генерация code_challenge из code_verifier (метод S256)
 */
function generateCodeChallenge($codeVerifier) {
    $hash = hash('sha256', $codeVerifier, true);
    return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
}

// ================== ШАГ 1: ГЕНЕРАЦИЯ ССЫЛКИ ДЛЯ АВТОРИЗАЦИИ ==================

// Сохраняем verifier и state для последующего обмена
$codeVerifier = generateCodeVerifier(64);
$codeChallenge = generateCodeChallenge($codeVerifier);
$state = bin2hex(random_bytes(16));

// Сохраняем во временный файл (или можно в сессию, но для CLI проще так)
file_put_contents('vk_auth_state.json', json_encode([
    'code_verifier' => $codeVerifier,
    'state' => $state,
    'timestamp' => time()
]));

// Формируем URL для авторизации [citation:5]
$scopeString = implode(',', $scopes);
$authUrl = 'https://id.vk.com/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scopeString,
    'state' => $state,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256'
]);

echo "\n========================================\n";
echo "🚀 Для получения токена перейдите по ссылке:\n";
echo "========================================\n\n";
echo $authUrl . "\n\n";
echo "========================================\n";
echo "1. Откройте ссылку в браузере\n";
echo "2. Авторизуйтесь в VK (если ещё не авторизованы)\n";
echo "3. Разрешите приложению запрашиваемые доступы\n";
echo "4. После подтверждения вы попадёте на страницу с сообщением:\n";
echo "   'Пожалуйста, не копируйте данные из адресной строки...'\n";
echo "5. Скопируйте ИЗ АДРЕСНОЙ СТРОКИ параметры code и device_id\n";
echo "========================================\n\n";

// Ожидаем ввода от пользователя
echo "Введите code из адресной строки: ";
$code = trim(fgets(STDIN));

echo "Введите device_id из адресной строки: ";
$deviceId = trim(fgets(STDIN));

if (empty($code) || empty($deviceId)) {
    die("❌ Ошибка: code и device_id обязательны\n");
}

// Загружаем сохранённый verifier
$savedData = json_decode(file_get_contents('vk_auth_state.json'), true);
if (!$savedData || !isset($savedData['code_verifier'])) {
    die("❌ Ошибка: не найден сохранённый code_verifier\n");
}
$codeVerifier = $savedData['code_verifier'];

// ================== ШАГ 2: ОБМЕН КОДА НА ТОКЕН ==================

echo "\n🔄 Обмениваем код на токен...\n";

$tokenUrl = 'https://id.vk.com/oauth2/auth';

$postData = http_build_query([
    'grant_type' => 'authorization_code',
    'code_verifier' => $codeVerifier,
    'redirect_uri' => $redirectUri,
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'device_id' => $deviceId,
    'state' => $savedData['state']
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("❌ HTTP ошибка: $httpCode\nОтвет: $response\n");
}

$tokens = json_decode($response, true);

if (isset($tokens['error'])) {
    die("❌ Ошибка VK: " . ($tokens['error_description'] ?? $tokens['error']) . "\n");
}

if (isset($tokens['access_token'])) {
    echo "\n========================================\n";
    echo "✅ ТОКЕН УСПЕШНО ПОЛУЧЕН!\n";
    echo "========================================\n";
    echo "ACCESS_TOKEN: " . $tokens['access_token'] . "\n";
    echo "REFRESH_TOKEN: " . ($tokens['refresh_token'] ?? 'нет') . "\n";
    echo "Срок действия: " . ($tokens['expires_in'] ?? 0) . " секунд\n";
    echo "USER_ID: " . ($tokens['user_id'] ?? 'неизвестно') . "\n";
    echo "========================================\n\n";
    
    // Сохраняем токен в файл
    file_put_contents('vk_access_token.json', json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "💾 Токен сохранён в файл: vk_access_token.json\n";
    echo "🔑 Скопируйте ACCESS_TOKEN и используйте его в вашем парсере\n";
} else {
    echo "❌ Неожиданный ответ:\n";
    print_r($tokens);
}

// Удаляем временный файл
@unlink('vk_auth_state.json');