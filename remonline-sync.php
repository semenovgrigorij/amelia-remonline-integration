<?php
// Проверка безопасности
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once(ABSPATH . 'wp-load.php');
}

// Убедимся, что плагин активирован
if (!class_exists('Amelia_Remonline_Integration')) {
    die('Плагин интеграции Amelia с Remonline не активирован.');
}

// Получаем несинхронизированные бронирования
global $wpdb;

$appointments = $wpdb->get_results(
    "SELECT a.* FROM {$wpdb->prefix}amelia_appointments a
     LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
     WHERE (a.externalId IS NULL OR a.externalId = '')
     AND cb.id IS NOT NULL
     ORDER BY a.bookingStart DESC
     LIMIT 10",
    ARRAY_A
);

if (empty($appointments)) {
    echo "Нет бронирований для синхронизации.\n";
    exit;
}

echo "Найдено " . count($appointments) . " бронирований для синхронизации.\n";

global $amelia_remonline_integration;

$success_count = 0;
foreach ($appointments as $appointment) {
    echo "Синхронизация бронирования ID: " . $appointment['id'] . "... ";
    $result = $amelia_remonline_integration->handle_appointment_created($appointment['id'], []);
    if ($result) {
        echo "Успешно.\n";
        $success_count++;
    } else {
        echo "Ошибка.\n";
    }
}

echo "Завершено. Успешно синхронизировано: $success_count из " . count($appointments) . ".\n";