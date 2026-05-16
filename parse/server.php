<?php
/**
 * Локальный сервер для получения OAuth callback от VK
 * Запуск: php -S localhost:8080 auth_server.php
 */

// Сохраняем code и device_id в файл при редиректе
if (isset($_GET['code']) && isset($_GET['device_id'])) {
    $data = [
        'code' => $_GET['code'],
        'device_id' => $_GET['device_id'],
        'state' => $_GET['state'] ?? '',
        'timestamp' => time()
    ];
    file_put_contents(__DIR__ . '/vk_callback_data.json', json_encode($data));
    
    // Показываем пользователю сообщение об успехе
    echo "<html><body><h2>✅ Код авторизации получен!</h2>";
    echo "<p>Теперь вернитесь в консоль и нажмите Enter для продолжения.</p>";
    echo "</body></html>";
    exit;
}

// Если просто зашли на сервер без параметров
echo "<html><body><h2>🔄 OAuth сервер запущен</h2>";
echo "<p>Ожидаем callback от VK...</p></body></html>";