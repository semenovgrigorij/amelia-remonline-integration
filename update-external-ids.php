<?php
// Проверка безопасности
if (!defined('ABSPATH')) {
    require_once(ABSPATH . 'wp-load.php');
}

// Устанавливаем externalId для тестирования
global $wpdb;

// Выберите конкретную запись в Amelia и указанный ID заказа из Remonline
$amelia_id = isset($_GET['amelia_id']) ? intval($_GET['amelia_id']) : 0;
$remonline_id = isset($_GET['remonline_id']) ? $_GET['remonline_id'] : '';

if (!$amelia_id || !$remonline_id) {
    echo "Необходимо указать amelia_id и remonline_id в URL";
    exit;
}

// Обновляем запись
$result = $wpdb->update(
    $wpdb->prefix . 'amelia_appointments',
    ['externalId' => $remonline_id],
    ['id' => $amelia_id],
    ['%s'],
    ['%d']
);

if ($result === false) {
    echo "Ошибка: " . $wpdb->last_error;
} else {
    echo "Успешно обновлено записей: " . $result;
    
    // Выводим текущее значение для проверки
    $current = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT externalId FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $amelia_id
        )
    );
    
    echo "<br>Текущее значение externalId: " . $current;
}