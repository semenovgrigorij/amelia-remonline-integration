<?php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', WP_CONTENT_DIR . '/remonline-logs/php-errors.log');

/**
 * Plugin Name: Amelia Remonline Integration
 * Description: Интеграция плагина бронирования Amelia с API Remonline CRM
 * Version: 1.0.0
 * Author: Григорий Семёнов
 * Text Domain: amelia-remonline
 */

// Защита от прямого доступа к файлу
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Основной класс плагина
 */
class Amelia_Remonline_Integration
{

    /**
     * Конструктор класса, инициализирует хуки
     */
    public function __construct()
    {
        // Добавляем страницу настроек в админке
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Регистрируем хук для создания нового бронирования в Amelia
        add_action('ameliabooking_appointment_added', array($this, 'handle_appointment_created'), 10, 2);

        // Отслеживание всех хуков Amelia
        add_action('all', function ($tag) {
            if (strpos($tag, 'amelia') !== false) {
                $test_dir = WP_CONTENT_DIR . '/remonline-logs';
                if (!file_exists($test_dir)) {
                    wp_mkdir_p($test_dir);
                }
                file_put_contents($test_dir . '/amelia_hooks.log', 'Hook triggered: ' . $tag . ' at ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
            }
        });

        // Альтернативные хуки Amelia
        add_action('wp_ajax_wpamelia_api', array($this, 'intercept_amelia_api'), 10);
        add_action('wp_ajax_nopriv_wpamelia_api', array($this, 'intercept_amelia_api'), 10);

        if (!wp_next_scheduled('remonline_sync_appointments')) {
            wp_schedule_event(time(), 'twicedaily', 'remonline_sync_appointments'); // 2 раза в день вместо hourly
        }
        add_action('remonline_sync_appointments', array($this, 'sync_unsynchronized_appointments'));

        // Добавляем поддержку логов
        $this->init_logs();

        // Проверяем необходимость обновления токена
        $auto_update = get_option('remonline_auto_update_token', 'yes');
        $last_update = intval(get_option('remonline_token_last_update', 0)); // Преобразуем в целое число
        $current_time = time();
        $time_passed = $current_time - $last_update;

        // Если прошло более 11 часов с момента последнего обновления (39600 секунд)
        if ($auto_update === 'yes' && ($time_passed > 39600 || $last_update === 0)) {
            $this->update_token();
        }
    }

    /**
     * Инициализирует систему логирования
     */
    private function init_logs()
    {
        // Создаем директорию для логов, если её нет
        $log_dir = WP_CONTENT_DIR . '/remonline-logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);

            // Добавляем .htaccess для защиты директории логов
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($log_dir . '/.htaccess', $htaccess_content);
        }
        // Создаем пустой лог при инициализации, если его нет
        $log_file = $log_dir . '/integration.log';
        if (!file_exists($log_file)) {
            file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] [info] Инициализация плагина интеграции Amelia с Remonline' . "\n");
        }
    }

    /**
     * Добавляет запись в лог
     */
    public function log($message, $type = 'info')
    {
        $log_file = WP_CONTENT_DIR . '/remonline-logs/integration.log';
        $date = date('[Y-m-d H:i:s]');
        $log_message = "$date [$type] $message\n";
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    /**
     * Добавляет страницу настроек в админ-меню
     */
    public function add_admin_menu()
    {
        add_options_page(
            'Настройки Remonline API',
            'Remonline API',
            'manage_options',
            'amelia-remonline',
            array($this, 'settings_page')
        );
    }

    /**
     * Регистрирует настройки плагина
     */
    public function register_settings()
    {
        register_setting('amelia_remonline_settings', 'remonline_api_token');
        register_setting('amelia_remonline_settings', 'remonline_branch_id');
        register_setting('amelia_remonline_settings', 'remonline_order_type');
        register_setting('amelia_remonline_settings', 'remonline_status_id', array(
            'default' => '1642511' 
        ));
        register_setting('amelia_remonline_settings', 'remonline_enable_integration', array(
            'default' => 'yes'
        ));
        register_setting('amelia_remonline_settings', 'remonline_log_level', array(
            'default' => 'error'
        ));

        // Новые настройки для автоматического обновления токена
        register_setting('amelia_remonline_settings', 'remonline_api_key');
        register_setting('amelia_remonline_settings', 'remonline_token_last_update', array(
            'default' => 0
        ));
        register_setting('amelia_remonline_settings', 'remonline_auto_update_token', array(
            'default' => 'yes'
        ));
    }

    /**
     * Выводит страницу настроек
     */
    public function settings_page()
    {
?>
        <div class="wrap">
            <h1>Налаштування інтеграції Amelia з Remonline</h1>

            <form method="post" action="options.php">
                <?php settings_fields('amelia_remonline_settings'); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Включити інтеграцію</th>
                        <td>
                            <select name="remonline_enable_integration">
                                <option value="yes"
                                    <?php selected(get_option('remonline_enable_integration', 'yes'), 'yes'); ?>>Так</option>
                                <option value="no" <?php selected(get_option('remonline_enable_integration', 'yes'), 'no'); ?>>
                                    Ні</option>
                            </select>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">API токен Remonline</th>
                        <td>
                            <input type="text" name="remonline_api_token"
                                value="<?php echo esc_attr(get_option('remonline_api_token')); ?>" class="regular-text"
                                <?php echo (get_option('remonline_auto_update_token', 'yes') === 'yes') ? 'readonly' : ''; ?> />
                            <?php if (get_option('remonline_token_last_update', 0) > 0): ?>
                                <p class="description">
                                    Токен діє 24 годин. Останнє оновлення:
                                    <?php echo date('Y-m-d H:i:s', get_option('remonline_token_last_update')); ?>
                                    <?php if (get_option('remonline_auto_update_token', 'yes') === 'yes'): ?>
                                        <br>Увімкнено автоматичне оновлення.
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <p class="description">Токен діє 24 години. При увімкненому автоматичному оновленні буде
                                    оновлюватися автоматично.</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Новые поля для автоматического обновления токена: -->

                    <tr valign="top">
                        <th scope="row">API ключ Remonline</th>
                        <td>
                            <input type="text" name="remonline_api_key"
                                value="<?php echo esc_attr(get_option('remonline_api_key')); ?>" class="regular-text" />
                            <p class="description">Постійний API ключ адміністратора Remonline для автоматичного оновлення
                                токена.</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Автоматичне оновлення токена</th>
                        <td>
                            <select name="remonline_auto_update_token">
                                <option value="yes" <?php selected(get_option('remonline_auto_update_token', 'yes'), 'yes'); ?>>
                                    Да</option>
                                <option value="no" <?php selected(get_option('remonline_auto_update_token', 'yes'), 'no'); ?>>
                                    Нет</option>
                            </select>
                            <p class="description">Токен автоматично оновлюватиметься кожні 24 години.</p>
                        </td>
                    </tr>

                    <?php if (get_option('remonline_token_last_update', 0) > 0): ?>
                        <tr valign="top">
                            <th scope="row">Останнє оновлення токена</th>
                            <td>
                                <?php echo date('Y-m-d H:i:s', get_option('remonline_token_last_update', 0)); ?>
                                <button type="button" id="update-token-now" class="button">Оновити зараз</button>
                                <span id="update-token-result"></span>

                                <script>
                                    jQuery(document).ready(function($) {
                                        $('#update-token-now').on('click', function() {
                                            var button = $(this);
                                            button.prop('disabled', true);
                                            $('#update-token-result').html('Обновление...');

                                            $.post(ajaxurl, {
                                                action: 'update_remonline_token',
                                                nonce: '<?php echo wp_create_nonce('update_remonline_token'); ?>'
                                            }, function(response) {
                                                button.prop('disabled', false);
                                                if (response.success) {
                                                    $('#update-token-result').html(
                                                        '<span style="color: green;">Токен успешно обновлен!</span>'
                                                    );
                                                    setTimeout(function() {
                                                        location.reload();
                                                    }, 1500);
                                                } else {
                                                    $('#update-token-result').html(
                                                        '<span style="color: red;">Ошибка: ' + response.data
                                                        .message + '</span>');
                                                }
                                            });
                                        });
                                    });
                                </script>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr valign="top">
                        <th scope="row">ID філії в Remonline<br>
                            <span style="font-size: smaller; color: gray;">(01_G_CAR_CENTRAL)</span>
                        </th>
                        <td>
                            <input type="text" name="remonline_branch_id"
                                value="<?php echo esc_attr(get_option('remonline_branch_id', '134397')); ?>"
                                class="regular-text" />
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Тип замовлення в Remonline<br>
                            <span style="font-size: smaller; color: gray;">(попередній запис)</span>
                        </th>
                        <td>
                            <input type="text" name="remonline_order_type"
                                value="<?php echo esc_attr(get_option('remonline_order_type', '240552')); ?>"
                                class="regular-text" />
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Статус замовлення в Remonline</th>
                        <td>
                            <p>Автозапис</p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Рівень логування</th>
                        <td>
                            <select name="remonline_log_level">
                                <option value="debug" <?php selected(get_option('remonline_log_level', 'error'), 'debug'); ?>>
                                    Налагодження (всі події)</option>
                                <option value="info" <?php selected(get_option('remonline_log_level', 'error'), 'info'); ?>>
                                    Інформація</option>
                                <option value="error" <?php selected(get_option('remonline_log_level', 'error'), 'error'); ?>>
                                    Тільки помилки</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                    	<th scope="row">Секретний ключ для вебхуків</th>
                        <td>
                        	<input type="text" name="remonline_webhook_secret"
                            	value="<?php echo esc_attr(get_option('remonline_webhook_secret')); ?>" class="regular-text" />
                            <p class="description">Цей ключ використовується для безпечної авторизації вебхуків від Remonline</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />

            <h2>Журнал інтеграції</h2>
            <p>Журнал інтеграції знаходиться у файлі: <code><?php echo WP_CONTENT_DIR; ?>/remonline-logs/integration.log</code>
            </p>

            <?php
            // Показываем последние записи из лога
            $log_file = WP_CONTENT_DIR . '/remonline-logs/integration.log';
            if (file_exists($log_file)) {
                echo '<h3>Останні події:</h3>';
                echo '<div style="background: #f0f0f0; padding: 10px; max-height: 300px; overflow: auto; font-family: monospace;">';

                $logs = file($log_file);
                $logs = array_slice($logs, -20); // Последние 20 записей

                foreach ($logs as $log_entry) {
                    if (strpos($log_entry, '[error]') !== false) {
                        echo '<div style="color: #c00;">' . esc_html($log_entry) . '</div>';
                    } else if (strpos($log_entry, '[info]') !== false) {
                        echo '<div style="color: #00c;">' . esc_html($log_entry) . '</div>';
                    } else {
                        echo '<div>' . esc_html($log_entry) . '</div>';
                    }
                }

                echo '</div>';

                // Кнопка для очистки лога
                echo '<form method="post">';
                wp_nonce_field('clear_remonline_logs', 'clear_logs_nonce');
                echo '<p><button type="submit" name="clear_remonline_logs" class="button">Очистить журнал</button></p>';
                echo '</form>';

                // Обработка очистки лога
                if (
                    isset($_POST['clear_remonline_logs']) &&
                    isset($_POST['clear_logs_nonce']) &&
                    wp_verify_nonce($_POST['clear_logs_nonce'], 'clear_remonline_logs')
                ) {
                    file_put_contents($log_file, '');
                    echo '<div class="notice notice-success"><p>Журнал очищен.</p></div>';
                    echo '<script>window.location.reload();</script>';
                }
            } else {
                echo '<p>Журнал поки що порожній.</p>';
            }
            ?>
        </div>
    <?php
    }

    /**
 * Обрабатывает событие создания нового бронирования в Amelia
 */

 private function acquire_lock($appointmentId) {
    $lock_key = 'remonline_lock_' . $appointmentId;
    $lock = get_transient($lock_key);
    
    if ($lock) {
        return false;
    }
    
    set_transient($lock_key, true, 5 * MINUTE_IN_SECONDS); // Блокировка на 5 минут
    return true;
}

private function release_lock($appointmentId) {
    $lock_key = 'remonline_lock_' . $appointmentId;
    delete_transient($lock_key);
}

/**
 * Обновляет externalId с надежной обработкой ошибок
 * @param int $amelia_appointment_id ID записи в Amelia
 * @param string $remonline_order_id ID заказа в Remonline
 * @return bool Результат операции
 */
private function update_appointment_external_id($amelia_appointment_id, $remonline_order_id) {
    global $wpdb;
    
    if (empty($amelia_appointment_id) || empty($remonline_order_id)) {
        $this->log("Ошибка: пустые параметры при обновлении externalId", 'error');
        return false;
    }
    
    // Проверяем, существует ли запись
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $amelia_appointment_id
        )
    );
    
    if (!$exists) {
        $this->log("Ошибка: попытка обновить несуществующую запись ID: $amelia_appointment_id", 'error');
        return false;
    }
    
    // Сначала проверяем текущее значение, чтобы не обновлять без необходимости
    $current_external_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT externalId FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $amelia_appointment_id
        )
    );
    
    // Если значение уже установлено и совпадает - не обновляем
    if ($current_external_id === $remonline_order_id) {
        $this->log("externalId уже установлен в $remonline_order_id для записи $amelia_appointment_id", 'debug');
        return true;
    }
    
    // Выполняем обновление
    $update_result = $wpdb->update(
        $wpdb->prefix . 'amelia_appointments',
        array('externalId' => $remonline_order_id),
        array('id' => $amelia_appointment_id),
        array('%s'),  // Формат данных
        array('%d')   // Формат условия
    );
    
    // Проверяем результат обновления
    if ($update_result === false) {
        $db_error = $wpdb->last_error;
        $this->log("Ошибка SQL при обновлении externalId: $db_error", 'error');
        
        // Попытка выяснить причину ошибки
        if (empty($db_error)) {
            // Проверим на возможное повреждение таблицы
            $table_status = $wpdb->get_row("CHECK TABLE {$wpdb->prefix}amelia_appointments");
            if ($table_status && $table_status->Msg_text !== 'OK') {
                $this->log("Возможное повреждение таблицы: {$table_status->Msg_text}", 'error');
            }
            
            // Проверим структуру таблицы
            $has_external_id_column = $wpdb->get_var(
                "SHOW COLUMNS FROM {$wpdb->prefix}amelia_appointments LIKE 'externalId'"
            );
            
            if (!$has_external_id_column) {
                $this->log("Критическая ошибка: поле externalId не существует в таблице", 'error');
                return false;
            }
        }
        
        return false;
    } elseif ($update_result === 0) {
        // Запись не изменилась (возможно, значение уже было таким)
        $this->log("Запись $amelia_appointment_id не изменилась при обновлении externalId", 'info');
    } else {
        $this->log("Успешно обновлен externalId: $remonline_order_id для записи $amelia_appointment_id", 'info');
    }
    
    // После обновления проверяем, что запись действительно обновлена
    $check_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT externalId FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $amelia_appointment_id
        )
    );
    
    if ($check_id != $remonline_order_id) {
        $this->log("Ошибка проверки после обновления: externalId = $check_id, ожидалось $remonline_order_id", 'error');
        return false;
    }
    
    return true;
}

public function handle_appointment_created($appointmentId, $appointmentData)
{
    if (!$this->acquire_lock($appointmentId)) {
        $this->log("Запись $appointmentId уже обрабатывается", 'info');
        return;
    }
    
        // Проверяем, не был ли уже создан заказ для этой записи
        global $wpdb;
        $existing_external_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT externalId FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
                $appointmentId
            )
        );
        
        if (!empty($existing_external_id)) {
            $this->log("Заказ для записи $appointmentId уже существует в Remonline (ID: $existing_external_id)", 'info');
            return true;
        }

    // Проверяем, включена ли интеграция
    if (get_option('remonline_enable_integration', 'yes') !== 'yes') {
        return;
    }

    try {
        $this->log("Отримано нове бронювання: ID = $appointmentId", 'debug');

        // Получаем данные о клиенте и записи
        $appointment_details = $this->get_appointment_details($appointmentId, $appointmentData);

        if (!$appointment_details) {
            $this->log("Неможливо отримати деталі запису ID $appointmentId", 'error');
            return;
        }

        // Получаем API токен
        $api_token = get_option('remonline_api_token');
        if (empty($api_token)) {
            $this->log("API токен Remonline не налаштований", 'error');
            return;
        }

        // Получаем ID клиента из Remonline (существующего или создаем нового)
        $this->log("Пошук або створення клієнта у Remonline: {$appointment_details['customer']['firstName']} {$appointment_details['customer']['lastName']}", 'debug');
        $client_id = $this->get_remonline_client($appointment_details['customer'], $api_token);

        if (!$client_id) {
            $this->log("Не вдалося знайти або створити клієнта у Remonline", 'error');
            return;
        }

        // Создаем заказ в Remonline
        $this->log("Створюємо замовлення у Remonline для клієнта ID $client_id", 'debug');
        $order_id = $this->create_remonline_order(
            $client_id,
            $appointment_details,
            $api_token
        );

        if (!$order_id) {
            $this->log("Не вдалося створити замовлення у Remonline", 'error');
            return;
        }

        $this->log("Успішно створено замовлення (ID: $order_id) у Remonline для клієнта (ID: $client_id)", 'info');
        $this->log("Результат создания заказа в Remonline: " . ($order_id ? "успешно, ID: $order_id" : "ошибка"), $order_id ? 'info' : 'error');

        // Сохраняем ID созданного заказа в Remonline в метаданных записи Amelia
        $update_result = $this->update_appointment_external_id($appointmentId, $order_id);
        
        if (!$update_result) {
            $this->log("Не удалось обновить externalId для записи $appointmentId", 'error');
            // Но продолжаем выполнение, так как заказ в Remonline уже создан
        }

        $update_result = $wpdb->update(
            $wpdb->prefix . 'amelia_appointments',
            array('externalId' => $order_id),
            array('id' => $appointmentId)
        );
        
        if ($update_result === false) {
            $this->log("Ошибка при обновлении externalId в базе данных: " . $wpdb->last_error, 'error');
        } else {
            $this->log("externalId в базе данных успешно обновлен: $order_id для записи $appointmentId", 'info');
        }

        // Перед обновлением базы данных
        $this->log("Пытаемся обновить externalId: $order_id для записи: $appointmentId", 'debug');

        // После обновления проверьте, что запись действительно обновлена
        $check_id = $wpdb->get_var(
            $wpdb->prepare(
            "SELECT externalId FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $appointmentId
            )
        );

        $this->log("После обновления базы данных, значение externalId: $check_id", 'debug');

        return true;
    } catch (Exception $e) {
        $this->log("Помилка при обробці бронювання: " . $e->getMessage(), 'error');
        return false;
    } finally {
        $this->release_lock($appointmentId); // Освобождаем блокировку в любом случае
    }
}

    /**
     * Перехватывает API-запросы Amelia
     */
    public function intercept_amelia_api()
    {
        // Проверяем, что это вызов API для создания бронирования
        if (isset($_REQUEST['call']) && strpos($_REQUEST['call'], '/bookings') !== false) {
            $this->log("Перехвачен API-запрос Amelia: " . $_REQUEST['call'], 'debug');

            // Для отладки сохраним данные запроса
            $input = file_get_contents('php://input');
            $this->log("Данные запроса: " . $input, 'debug');

            // Регистрируем обработчик, который выполнится после завершения запроса
            register_shutdown_function(function () {
                // Ждем немного, чтобы бронирование точно создалось
                sleep(2);

                global $wpdb;

                // Ищем последнее созданное бронирование
                $latest_booking = $wpdb->get_row(
                    "SELECT a.id 
                 FROM {$wpdb->prefix}amelia_appointments a
                 LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
                 WHERE cb.id IS NOT NULL
                 ORDER BY a.id DESC 
                 LIMIT 1",
                    ARRAY_A
                );

                if ($latest_booking) {
                    // Сохраняем ID для дальнейшей обработки
                    file_put_contents(
                        WP_CONTENT_DIR . '/remonline-logs/pending_booking.txt',
                        $latest_booking['id']
                    );

                    // Запускаем процесс синхронизации через WP-CLI
                    $cmd = 'wp eval "global \$amelia_remonline_integration; \$amelia_remonline_integration->handle_appointment_created(' . $latest_booking['id'] . ', []);" > /dev/null 2>&1 &';
                    exec($cmd);
                }
            });
        }
    }

    /**
     * Синхронизирует несинхронизированные бронирования
     */
    public function sync_unsynchronized_appointments()
    {
        global $wpdb;

        $this->log("Запущена регулярная синхронизация бронирований", 'info');

        $appointments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.* 
                 FROM {$wpdb->prefix}amelia_appointments a
                 LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
                 WHERE (a.externalId IS NULL OR a.externalId = '')
                 AND cb.id IS NOT NULL
                 AND a.created < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                 ORDER BY a.bookingStart DESC
                 LIMIT %d",
                10
            ),
            ARRAY_A
        );

        $success_count = 0;
        foreach ($appointments as $appointment) {
            $this->log("Синхронизация бронирования ID: {$appointment['id']}", 'debug');
            $result = $this->handle_appointment_created($appointment['id'], []);
            if ($result) {
                $success_count++;
            }
        }

        $this->log("Завершена регулярная синхронизация. Успешно: $success_count из " . count($appointments), 'info');
    }

    /**
     * Обновляет токен Remonline API
     */
    public function update_token()
    {
        $api_key = get_option('remonline_api_key');

        if (empty($api_key)) {
            $this->log("API ключ не настроен, невозможно обновить токен", 'error');
            return false;
        }

        $this->log("Запрос на обновление токена Remonline", 'debug');

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.remonline.app/token/new",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode([
                'api_key' => $api_key
            ]),
            CURLOPT_HTTPHEADER => [
                "accept: application/json",
                "content-type: application/json"
            ],
        ]);

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            $this->log("Ошибка cURL при обновлении токена: " . $err, 'error');
            return false;
        }

        if ($http_code != 200) {
            $this->log("Ошибка при обновлении токена: HTTP код $http_code, Ответ: $response", 'error');
            return false;
        }

        $response_data = json_decode($response, true);

        if (!isset($response_data['token'])) {
            $this->log("Неправильный формат ответа при обновлении токена: " . $response, 'error');
            return false;
        }

        // Сохраняем новый токен
        update_option('remonline_api_token', $response_data['token']);
        update_option('remonline_token_last_update', time());

        $this->log("Токен Remonline успешно обновлен", 'info');

        return $response_data['token'];
    }

    /**
     * Получает подробную информацию о записи и клиенте
     */
    private function get_appointment_details($appointmentId, $appointmentData)
    {
        global $wpdb;

        $this->log("Начинаем получение деталей для записи ID $appointmentId", 'debug');

        // Выводим информацию о таблицах для отладки
        $this->log("Таблица appointments: {$wpdb->prefix}amelia_appointments", 'debug');
        $this->log("Таблица users: {$wpdb->prefix}amelia_users", 'debug');
        $this->log("Таблица services: {$wpdb->prefix}amelia_services", 'debug');

        // Проверяем существование таблиц
        $appointments_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}amelia_appointments'");
        $users_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}amelia_users'");
        $services_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}amelia_services'");
        $bookings_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}amelia_customer_bookings'");

        if (!$appointments_table || !$users_table || !$services_table || !$bookings_table) {
            $this->log("Не все необходимые таблицы существуют: appointments=$appointments_table, users=$users_table, services=$services_table, bookings=$bookings_table", 'error');
            return null;
        }

        // Получение информации о записи
        $appointment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
                $appointmentId
            ),
            ARRAY_A
        );

        if (!$appointment) {
            $this->log("Запись с ID $appointmentId не найдена в таблице", 'error');
            return null;
        }

        $this->log("Найдена запись: " . print_r($appointment, true), 'debug');

        // Получение booking ID через таблицу amelia_customer_bookings
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId = %d",
                $appointmentId
            ),
            ARRAY_A
        );

        if (!$booking) {
            $this->log("Не найдена информация о бронировании для записи ID $appointmentId", 'error');
            return null;
        }

        $this->log("Найдено бронирование: " . print_r($booking, true), 'debug');

        // Получение информации о клиенте
        $customer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amelia_users WHERE id = %d",
                $booking['customerId']
            ),
            ARRAY_A
        );

        if (!$customer) {
            $this->log("Клиент с ID {$booking['customerId']} не найден", 'error');

            // Пробуем найти клиента по ID из appointment
            if (!empty($appointment['customerId'])) {
                $customer = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}amelia_users WHERE id = %d",
                        $appointment['customerId']
                    ),
                    ARRAY_A
                );

                if (!$customer) {
                    $this->log("Клиент с ID {$appointment['customerId']} также не найден", 'error');
                    return null;
                }
            } else {
                return null;
            }
        }

        $this->log("Найден клиент: " . print_r($customer, true), 'debug');

        // Получение информации об услуге
        $service = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}amelia_services WHERE id = %d",
                $appointment['serviceId']
            ),
            ARRAY_A
        );

        if (!$service) {
            $this->log("Услуга с ID {$appointment['serviceId']} не найдена", 'error');

            // Создаем базовую информацию об услуге
            $service = [
                'id' => $appointment['serviceId'],
                'name' => 'Услуга #' . $appointment['serviceId'],
                'duration' => 60
            ];
        } else {
            $this->log("Найдена услуга: " . print_r($service, true), 'debug');
        }

        // Получение информации о сотруднике/специалисте
        $employee = null;
        if (!empty($appointment['providerId'])) {
            $employee = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}amelia_users WHERE id = %d",
                    $appointment['providerId']
                ),
                ARRAY_A
            );

            if ($employee) {
                $this->log("Найден сотрудник: " . print_r($employee, true), 'debug');
            }
        }

        // Собираем все данные
        return [
            'appointment' => $appointment,
            'customer' => $customer,
            'service' => $service,
            'employee' => $employee
        ];
    }

    /**
 * Проверяет существование клиента в Remonline и возвращает его ID
 */

 private function get_remonline_client($customer, $api_token) {
    // 1. Нормализация данных
    $email = strtolower(trim($customer['email'] ?? ''));
    $phone = $this->normalize_phone($customer['phone'] ?? '');
    
    // 2. Логирование входящих данных
    $this->log("Поиск клиента. Email: '$email', Phone: '$phone'", 'debug');
    
    // 3. Поиск существующего клиента с полной проверкой
    $client_id = $this->find_client_with_full_check($email, $phone, $api_token);
    
    if ($client_id) {
        $this->log("Найден существующий клиент ID: $client_id", 'info');
        return $client_id;
    }
    
    // 4. Попытка создания нового клиента с обработкой ошибок
    $this->log("Попытка создать нового клиента", 'info');
    $new_client_id = $this->create_remonline_client($customer, $api_token);
    
    if ($new_client_id) {
        return $new_client_id;
    }
    
    // 5. Последняя попытка найти клиента с полным перебором
    $this->log("Финальная попытка найти клиента", 'info');
    return $this->ultimate_client_search($email, $phone, $api_token);
}

private function find_exact_client_match($email, $phone, $api_token) {
    // Получаем всех клиентов, соответствующих запросу
    $search_params = [];
    if (!empty($email)) $search_params['email'] = $email;
    if (!empty($phone)) $search_params['phone'] = $phone;
    
    if (empty($search_params)) {
        return null;
    }
    
    $clients = $this->search_client_api($search_params, $api_token);
    $this->log("Найдено клиентов: " . count($clients), 'debug');
    
    foreach ($clients as $client) {
        // Проверка точного совпадения email
        $client_email = strtolower(trim($client['email'] ?? ''));
        $email_match = !empty($email) && $client_email === $email;
        
        // Проверка точного совпадения телефона
        $phone_match = false;
        if (!empty($phone)) {
            foreach ($client['phone'] ?? [] as $client_phone) {
                if ($this->normalize_phone($client_phone) === $phone) {
                    $phone_match = true;
                    break;
                }
            }
        }
        
        // Если есть хотя бы одно точное совпадение
        if ($email_match || $phone_match) {
            $this->log("Точное совпадение клиента ID: {$client['id']}", 'debug');
            return $client['id'];
        }
    }
    
    return null;
}

private function deep_client_search($email, $phone, $api_token) {
    // 1. Полный список клиентов (без фильтрации)
    $all_clients = $this->search_client_api([], $api_token);
    $this->log("Всего клиентов в системе: " . count($all_clients), 'debug');
    
    // 2. Поиск по всем вариантам телефона
    if (!empty($phone)) {
        $phone_variants = $this->generate_phone_variants($phone);
        
        foreach ($all_clients as $client) {
            foreach ($client['phone'] ?? [] as $client_phone) {
                $normalized_client_phone = $this->normalize_phone($client_phone);
                
                if (in_array($normalized_client_phone, $phone_variants)) {
                    $this->log("Найден клиент по варианту телефона: {$client['id']}", 'debug');
                    return $client['id'];
                }
            }
        }
    }
    
    // 3. Поиск по email (если есть)
    if (!empty($email)) {
        foreach ($all_clients as $client) {
            $client_email = strtolower(trim($client['email'] ?? ''));
            if ($client_email === $email) {
                $this->log("Найден клиент по email: {$client['id']}", 'debug');
                return $client['id'];
            }
        }
    }
    
    $this->log("Клиент не найден после расширенного поиска", 'error');
    return null;
}

private function search_client_api($params, $api_token) {
    $curl = curl_init();
    $query = http_build_query($params);
    $url = "https://api.remonline.app/clients/?token=$api_token&$query";
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["accept: application/json"],
    ]);
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($http_code != 200) {
        $this->log("Ошибка API: $http_code, URL: $url", 'error');
        return [];
    }
    
    $data = json_decode($response, true);
    return $data['data'] ?? [];
}
private function normalize_phone($phone) {
    // Удаляем все нецифровые символы
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    // Приводим к формату 380XXXXXXXXX
    if (strlen($digits) === 10 && $digits[0] === '0') {
        return '38' . substr($digits, 1);
    }
    
    if (strlen($digits) === 9) {
        return '380' . $digits;
    }
    
    return $digits;
}

private function find_client_with_full_check($email, $phone, $api_token) {
    // Получаем всех клиентов
    $all_clients = $this->get_all_clients($api_token);
    
    foreach ($all_clients as $client) {
        // Проверка email
        $client_email = strtolower(trim($client['email'] ?? ''));
        $email_match = !empty($email) && $client_email === $email;
        
        // Проверка телефона
        $phone_match = false;
        $client_phones = $client['phone'] ?? [];
        
        foreach ($client_phones as $client_phone) {
            if ($this->normalize_phone($client_phone) === $phone) {
                $phone_match = true;
                break;
            }
        }
        
        // Если найдено совпадение
        if ($email_match || $phone_match) {
            $this->log("Точное совпадение с клиентом ID: {$client['id']}", 'debug');
            $this->log("Данные клиента: " . json_encode([
                'id' => $client['id'],
                'name' => $client['name'] ?? '',
                'email' => $client_email,
                'phones' => $client_phones
            ]), 'debug');
            
            return $client['id'];
        }
    }
    
    return null;
}

private function ultimate_client_search($email, $phone, $api_token) {
    $all_clients = $this->get_all_clients($api_token);
    
    foreach ($all_clients as $client) {
        // Проверка email (без учета пустых значений)
        if (!empty($email)) {
            $client_email = strtolower(trim($client['email'] ?? ''));
            if ($client_email === $email) {
                $this->log("Найден по email: {$client['id']}", 'info');
                return $client['id'];
            }
        }
        
        // Проверка всех вариантов телефона
        foreach ($client['phone'] ?? [] as $client_phone) {
            $normalized_client_phone = $this->normalize_phone($client_phone);
            $normalized_phone = $this->normalize_phone($phone);
            
            // Сравниваем последние 10 цифр (на случай разных префиксов)
            if (substr($normalized_client_phone, -10) === substr($normalized_phone, -10)) {
                $this->log("Найден по телефону: {$client['id']}", 'info');
                return $client['id'];
            }
        }
    }
    
    $this->log("Клиент не найден после полной проверки", 'error');
    return null;
}
private function get_all_clients($api_token) {
    $all_clients = [];
    $page = 1;
    
    do {
        $clients = $this->search_client_api(['page' => $page], $api_token);
        $all_clients = array_merge($all_clients, $clients);
        $page++;
    } while (!empty($clients));
    
    $this->log("Получено клиентов: " . count($all_clients), 'debug');
    return $all_clients;
}
private function search_client_by_exact_phone($phone, $api_token) {
    $clients = $this->search_client_api(['query' => $phone], $api_token);
    $normalized_phone = $this->normalize_phone($phone);
    
    foreach ($clients as $client) {
        if (isset($client['phone']) && is_array($client['phone'])) {
            foreach ($client['phone'] as $client_phone) {
                if ($this->normalize_phone($client_phone) === $normalized_phone) {
                    return $client;
                }
            }
        }
    }
    
    return null;
}



private function generate_phone_variants($phone) {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    $variants = [
        $digits,
        '38' . $digits,
        '+38' . $digits,
        '380' . substr($digits, 2),
        '0' . substr($digits, 2)
    ];
    
    return array_unique($variants);
}


    /**
     * Создает клиента в Remonline API
     */
    private function create_remonline_client($customer, $api_token)
    {
        $curl = curl_init();

        $data = array(
            'first_name' => $customer['firstName'],
            'last_name' => $customer['lastName'],
            'email' => $customer['email'],
            'custom_fields' => array(
                'f5370833' => 'Потенційний клієнт'
            ),
            'phone' => array(
                $customer['phone']
            )
        );

        $json_data = json_encode($data);
        $api_url = 'https://api.remonline.app/clients/?token=' . $api_token;

        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_data,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Accept: application/json"
            )
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($http_code == 401) {
            $this->log("Ошибка авторизации (401) при создании клиента, пробуем обновить токен", 'info');
            $new_token = $this->update_token();

            if ($new_token) {
                // Повторяем запрос с новым токеном
                $api_url = 'https://api.remonline.app/clients/?token=' . $new_token;

                curl_setopt($curl, CURLOPT_URL, $api_url);

                $response = curl_exec($curl);
                $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $error = curl_error($curl);
            }
        }

        curl_close($curl);

        // Логируем ответ API
        $log_level = get_option('remonline_log_level', 'error');
        if ($log_level == 'debug') {
            $this->log("API ответ (создание клиента): HTTP код: $http_code, Ответ: $response", 'debug');
        }

        if ($http_code != 200) {
            $this->log("Ошибка при создании клиента: HTTP код $http_code, Ошибка: $error, Ответ: $response", 'error');
            return null;
        }

        $response_data = json_decode($response, true);
        if (isset($response_data['data']['id'])) {
            return $response_data['data']['id'];
        }

        return null;
    }
    
    /**
     * Создает заказ в Remonline API
     */
    private function create_remonline_order($client_id, $appointment_details, $api_token)
    {
        $curl = curl_init();

        $customer = $appointment_details['customer'];
        $appointment = $appointment_details['appointment'];
        $service = $appointment_details['service'];

        // Формируем описание заказа
        $malfunction = $service['name'];
        if (!empty($appointment_details['employee'])) {
            $malfunction .= ' - ' . $appointment_details['employee']['firstName'] . ' ' . $appointment_details['employee']['lastName'];
        }
        $malfunction .= ' - ' . date('Y-m-d H:i', intval($appointment['bookingStart']));

        // Убедимся, что bookingStart - это число и преобразуем его в миллисекунды
        $bookingStart = strtotime($appointment['bookingStart']) * 1000;
        $this->log("Время записи: строка={$appointment['bookingStart']}, timestamp=" . strtotime($appointment['bookingStart']) . ", в миллисекундах={$bookingStart}", 'debug');

        $data = array(
            'branch_id' => intval(get_option('remonline_branch_id', '134397')),
            'order_type' => intval(get_option('remonline_order_type', '240552')),
            'status' => 1642511,
            'email' => $customer['email'],
            'client_id' => $client_id,
            'manager' => 268918,
            // 'asset_id' => 6083062,
            // 'malfunction' => $malfunction,
            'duration' => $service['duration'] ? intval($service['duration']) : 60,
            'scheduled_for' => $bookingStart, // Метка времени в миллисекундах
            'custom_fields' => array(
                'f5370833' => 'Потенційний клієнт',
            //     'f5294177' => "01_Зовнішній Клієнт СТО G CAR (Київ)",
                'f5294178' => $appointment['id'], // Используем ID записи в Amelia как референс
            ),
            'ad_campaign_id' => 301120
        );

        $json_data = json_encode($data);
        $api_url = 'https://api.remonline.app/order/?token=' . $api_token;

        // Логируем данные запроса
        $this->log("Отправляем запрос на создание заказа: " . $json_data, 'debug');

        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_data,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Accept: application/json"
            )
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($http_code == 401) {
            $this->log("Ошибка авторизации (401) при создании заказа, пробуем обновить токен", 'info');
            $new_token = $this->update_token();

            if ($new_token) {
                // Повторяем запрос с новым токеном
                $api_url = 'https://api.remonline.app/order/?token=' . $new_token;

                curl_setopt($curl, CURLOPT_URL, $api_url);

                $response = curl_exec($curl);
                $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $error = curl_error($curl);
            }
        }

        curl_close($curl);

        // Логируем ответ API
        $log_level = get_option('remonline_log_level', 'error');
        if ($log_level == 'debug') {
            $this->log("API ответ (создание заказа): HTTP код: $http_code, Ответ: $response", 'debug');
        }

        if ($http_code != 200) {
            $this->log("Ошибка при создании заказа: HTTP код $http_code, Ошибка: $error, Ответ: $response", 'error');
            return null;
        }

        $response_data = json_decode($response, true);
        if (isset($response_data['data']['id'])) {
            $order_id = $response_data['data']['id'];
        
            return $order_id;
        }
        return null;
    }
}

function update_amelia_with_remonline_id($amelia_appointment_id, $remonline_order_id) {
    global $wpdb;
    $result = $wpdb->update(
        $wpdb->prefix . 'amelia_appointments',
        ['externalId' => $remonline_order_id], // Данные для обновления
        ['id' => $amelia_appointment_id],      // Условие WHERE
        ['%s'],                                // Формат данных
        ['%d']                                 // Формат условия
    );
    
    if ($result === false) {
        // Логирование ошибки
        error_log("Ошибка при обновлении externalId для записи #$amelia_appointment_id: " . $wpdb->last_error);
        return false;
    }
    
    return true;
}

// Инициализация плагина
// Добавление тестовой функции для проверки срабатывания хука
add_action('init', function () {
    // Создаем директорию и файл для проверки хуков
    $test_dir = WP_CONTENT_DIR . '/remonline-logs';
    if (!file_exists($test_dir)) {
        wp_mkdir_p($test_dir);
    }
    file_put_contents($test_dir . '/hook_test.log', 'Plugin initialized at ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

    // Проверяем, существует ли хук для Amelia
    global $wp_filter;
    if (isset($wp_filter['ameliabooking_appointment_added'])) {
        file_put_contents($test_dir . '/hook_test.log', 'Hook ameliabooking_appointment_added exists' . "\n", FILE_APPEND);
    } else {
        file_put_contents($test_dir . '/hook_test.log', 'Hook ameliabooking_appointment_added NOT found' . "\n", FILE_APPEND);
    }
});
$amelia_remonline_integration = new Amelia_Remonline_Integration();

// AJAX-обработчик для обновления токена
add_action('wp_ajax_update_remonline_token', function () {
    // Проверка безопасности
    check_ajax_referer('update_remonline_token', 'nonce');

    // Проверка прав пользователя
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав для выполнения операции']);
        return;
    }

    global $amelia_remonline_integration;
    $token = $amelia_remonline_integration->update_token();

    if ($token) {
        wp_send_json_success(['token' => $token]);
    } else {
        wp_send_json_error(['message' => 'Не удалось обновить токен. Проверьте журнал для подробностей.']);
    }

    wp_die();
});

// Добавление страницы тестирования
add_action('admin_menu', function () {
    add_submenu_page(
        'options-general.php',
        'Тест Remonline',
        'Тест Remonline',
        'manage_options',
        'remonline-test',
        'remonline_test_page'
    );
});

function remonline_test_page()
{
    global $wpdb;

    echo '<div class="wrap">';
    echo '<h1>Тестирование интеграции с Remonline</h1>';

    // Обработка тестового отправления
    if (isset($_POST['test_appointment_id']) && !empty($_POST['test_appointment_id'])) {
        $appointment_id = intval($_POST['test_appointment_id']);

        // Получаем экземпляр класса интеграции
        global $amelia_remonline_integration;

        // Вызываем обработчик бронирования напрямую
        $amelia_remonline_integration->handle_appointment_created($appointment_id, []);

        echo '<div class="notice notice-success"><p>Тестовый запрос отправлен. Проверьте журнал интеграции.</p></div>';
    }

    // Показываем последние бронирования
    $appointments = $wpdb->get_results(
        "SELECT a.*, c.firstName, c.lastName, c.email, c.phone, s.name as serviceName
         FROM {$wpdb->prefix}amelia_appointments a
         JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
         JOIN {$wpdb->prefix}amelia_users c ON cb.customerId = c.id
         JOIN {$wpdb->prefix}amelia_services s ON a.serviceId = s.id
         ORDER BY a.bookingStart DESC
         LIMIT 10",
        ARRAY_A
    );

    echo '<form method="post">';
    echo '<h2>Последние бронирования</h2>';

    if (empty($appointments)) {
        echo '<p>Бронирования не найдены. Убедитесь, что в Amelia есть хотя бы одно бронирование.</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>ID</th><th>Клиент</th><th>Услуга</th><th>Дата</th><th>Действие</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($appointments as $appointment) {
            echo '<tr>';
            echo '<td>' . $appointment['id'] . '</td>';
            echo '<td>' . $appointment['firstName'] . ' ' . $appointment['lastName'] . '<br>' .
                $appointment['email'] . '<br>' . $appointment['phone'] . '</td>';
            echo '<td>' . $appointment['serviceName'] . '</td>';
            echo '<td>';
            if (is_numeric($appointment['bookingStart'])) {
                echo date('Y-m-d H:i', intval($appointment['bookingStart']));
            } else {
                echo esc_html($appointment['bookingStart']);
            }
            echo '</td>';
            echo '<td><button type="submit" name="test_appointment_id" value="' . $appointment['id'] . '" class="button">Тест</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</form>';

    // Отображаем содержимое лога
    $log_file = WP_CONTENT_DIR . '/remonline-logs/integration.log';
    if (file_exists($log_file)) {
        echo '<h2>Последние записи журнала:</h2>';
        echo '<div style="background: #f0f0f0; padding: 10px; max-height: 300px; overflow: auto; font-family: monospace;">';

        $logs = file($log_file);
        $logs = array_slice($logs, -20); // Последние 20 записей

        foreach ($logs as $log_entry) {
            if (strpos($log_entry, '[error]') !== false) {
                echo '<div style="color: #c00;">' . esc_html($log_entry) . '</div>';
            } else if (strpos($log_entry, '[info]') !== false) {
                echo '<div style="color: #00c;">' . esc_html($log_entry) . '</div>';
            } else {
                echo '<div>' . esc_html($log_entry) . '</div>';
            }
        }

        echo '</div>';
    } else {
        echo '<p>Журнал интеграции пока пуст.</p>';
    }

    // Статистика интеграции
    echo '<h2>Статистика интеграции</h2>';

    global $wpdb;
    $appointments_with_external_id = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}amelia_appointments 
     WHERE externalId IS NOT NULL AND externalId != ''"
    );

    echo '<p>Количество записей, отправленных в Remonline: ' . intval($appointments_with_external_id) . '</p>';

    // Добавьте кнопку для ручного запуска синхронизации всех бронирований
    echo '<form method="post">';
    wp_nonce_field('sync_all_appointments', 'sync_all_nonce');
    echo '<button type="submit" name="sync_all_appointments" class="button button-primary">Синхронизировать все бронирования</button>';
    echo '</form>';

    // Обработка массовой синхронизации
    if (
        isset($_POST['sync_all_appointments']) &&
        isset($_POST['sync_all_nonce']) &&
        wp_verify_nonce($_POST['sync_all_nonce'], 'sync_all_appointments')
    ) {

        $appointments = $wpdb->get_results(
            "SELECT a.* FROM {$wpdb->prefix}amelia_appointments a
         LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
         WHERE (a.externalId IS NULL OR a.externalId = '')
         AND cb.id IS NOT NULL
         ORDER BY a.bookingStart DESC
         LIMIT 50",
            ARRAY_A
        );

        $synced_count = 0;

        foreach ($appointments as $appointment) {
            global $amelia_remonline_integration;
            $result = $amelia_remonline_integration->handle_appointment_created($appointment['id'], []);
            if ($result) {
                $synced_count++;
            }
        }

        echo '<div class="notice notice-success"><p>Синхронизировано ' . $synced_count . ' из ' . count($appointments) . ' бронирований.</p></div>';
    }

    // Кнопка для ручной синхронизации последнего бронирования
    echo '<form method="post" style="margin-top: 20px;">';
    wp_nonce_field('sync_latest_appointment', 'sync_latest_nonce');
    echo '<button type="submit" name="sync_latest_appointment" class="button button-secondary">Синхронизировать последнее бронирование</button>';
    echo '</form>';

    // Обработка синхронизации последнего бронирования
    if (
        isset($_POST['sync_latest_appointment']) &&
        isset($_POST['sync_latest_nonce']) &&
        wp_verify_nonce($_POST['sync_latest_nonce'], 'sync_latest_appointment')
    ) {

        global $wpdb, $amelia_remonline_integration;

        // Получаем последнее созданное бронирование
        $latest_appointment = $wpdb->get_row(
            "SELECT a.*, cb.customerId FROM {$wpdb->prefix}amelia_appointments a
         JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
         ORDER BY a.id DESC 
         LIMIT 1",
            ARRAY_A
        );

        if ($latest_appointment) {
            echo '<div class="notice notice-info"><p>Попытка синхронизации бронирования ID: ' . $latest_appointment['id'] . '</p></div>';
            $result = $amelia_remonline_integration->handle_appointment_created($latest_appointment['id'], []);
            if ($result) {
                echo '<div class="notice notice-success"><p>Бронирование успешно синхронизировано с Remonline.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Не удалось синхронизировать бронирование. Проверьте журнал.</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning"><p>Бронирования не найдены.</p></div>';
        }
    }

    echo '</div>';
}

add_action('admin_init', function () {
    global $amelia_remonline_integration;

    // Проверяем наличие файла с необработанным бронированием
    $pending_file = WP_CONTENT_DIR . '/remonline-logs/pending_booking.txt';
    if (file_exists($pending_file)) {
        $booking_id = trim(file_get_contents($pending_file));

        if (!empty($booking_id) && is_numeric($booking_id)) {
            // Удаляем файл, чтобы избежать повторной обработки
            unlink($pending_file);

            // Обрабатываем бронирование
            $amelia_remonline_integration->handle_appointment_created($booking_id, []);
        }
    }
});

// Добавление JavaScript для автоматической синхронизации на фронтенде

add_action('wp_footer', function () {
    if (isset($_GET['ameliaCache']) || strpos($_SERVER['REQUEST_URI'], 'appointment-success') !== false) {
    ?>
        <script>
            // Отправляем запрос на синхронизацию последнего бронирования
            setTimeout(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('action=sync_latest_booking');
            }, 3000); // Задержка в 3 секунды
        </script>
<?php
    }
});

// Обработчик AJAX-запроса для синхронизации последнего бронирования
add_action('wp_ajax_sync_latest_booking', 'sync_latest_booking_ajax');
add_action('wp_ajax_nopriv_sync_latest_booking', 'sync_latest_booking_ajax');

function sync_latest_booking_ajax()
{
    global $wpdb, $amelia_remonline_integration;

    // Находим последнее бронирование
    $latest_booking = $wpdb->get_row(
        "SELECT a.id 
         FROM {$wpdb->prefix}amelia_appointments a
         LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
         WHERE cb.id IS NOT NULL
         ORDER BY a.id DESC 
         LIMIT 1",
        ARRAY_A
    );

    if ($latest_booking) {
        $amelia_remonline_integration->handle_appointment_created($latest_booking['id'], []);
        wp_send_json_success(['message' => 'Бронирование синхронизировано']);
    } else {
        wp_send_json_error(['message' => 'Бронирование не найдено']);
    }

    wp_die();
    
}


/**
 * Обработчик запросов на обновление статуса заказа из Remonline
 */
function handle_remonline_status_update($request) {
	// Включаем вывод ошибок PHP для отладки
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    
    global $amelia_remonline_integration;
    
    $params = $request->get_params();
    $amelia_remonline_integration->log("Получен запрос на обновление статуса: " . 		print_r($params, true), 'debug');
    
    // Дополнительные проверки перед обработкой
    if (!isset($params['secret'])) {
        $amelia_remonline_integration->log("Отсутствует параметр secret", 'error');
        return new WP_Error('missing_secret', 'Отсутствует параметр secret', 			array('status' => 400));
    }
    
    // Получаем логгер из глобального объекта плагина
    global $amelia_remonline_integration;
    
	    // Проверка секретного ключа
    $webhook_secret = get_option('remonline_webhook_secret', '');
    $amelia_remonline_integration->log("Сравнение ключей: получено [{$params['secret']}], ожидалось [$webhook_secret]", 'debug');
    
    if (empty($webhook_secret) || $params['secret'] !== $webhook_secret) {
        $amelia_remonline_integration->log("Неверный секретный ключ при попытке обновления статуса", 'error');
        return new WP_Error('invalid_secret', 'Неверный секретный ключ', array('status' => 403));
    }
    
    // Проверяем наличие необходимых параметров
    if (empty($params['orderId']) || empty($params['newStatusId'])) {
        $amelia_remonline_integration->log("Отсутствуют обязательные параметры: orderId или newStatusId", 'error');
        return new WP_Error('missing_params', 'Отсутствуют обязательные параметры', array('status' => 400));
    }
    
    $remonline_order_id = $params['orderId'];
    $new_status_id = $params['newStatusId'];
    
    $amelia_remonline_integration->log("Получен запрос на обновление статуса для заказа Remonline #$remonline_order_id", 'info');
    
    // Ищем соответствующую запись в Amelia по внешнему ID (externalId)
    global $wpdb;
    $appointment_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE externalId = %s",
            $remonline_order_id
        )
    );
    
    if (!$appointment_id) {
        $amelia_remonline_integration->log("Не найдена запись Amelia для заказа Remonline #$remonline_order_id", 'error');
        return new WP_Error('appointment_not_found', 'Не найдена запись для данного заказа', array('status' => 404));
    }
    
    $amelia_remonline_integration->log("Найдена запись Amelia ID: $appointment_id для заказа Remonline #$remonline_order_id", 'debug');
    
    // Определяем статус Amelia на основе статуса Remonline
    $amelia_status = map_remonline_status_to_amelia($new_status_id);
    
    // Обновляем статус бронирования в Amelia
    $result = update_amelia_appointment_status($appointment_id, $amelia_status);
    
    if ($result === false) {
        $amelia_remonline_integration->log("Ошибка при обновлении статуса для записи Amelia ID: $appointment_id", 'error');
        return new WP_Error('update_failed', 'Ошибка при обновлении статуса', array('status' => 500));
    }
    
    $amelia_remonline_integration->log("Успешно обновлен статус для записи Amelia ID: $appointment_id на '$amelia_status'", 'info');
    
    return array(
        'success' => true,
        'message' => "Статус записи #$appointment_id успешно обновлен на '$amelia_status'",
        'appointment_id' => $appointment_id,
        'remonline_order_id' => $remonline_order_id,
    );
}

/**
 * Обработчик запросов на обновление даты и времени заказа из Remonline
 */
function handle_remonline_datetime_update($request) {
    global $amelia_remonline_integration;
    
    $params = $request->get_params();
    $amelia_remonline_integration->log("Получен запрос на обновление даты/времени: " . print_r($params, true), 'debug');
    
    // Проверка секретного ключа
    if (empty($params['secret']) || $params['secret'] !== get_option('remonline_webhook_secret', '')) {
        $amelia_remonline_integration->log("Неверный секретный ключ при попытке обновления даты", 'error');
        return new WP_Error('invalid_secret', 'Неверный секретный ключ', array('status' => 403));
    }
    
    // Проверяем наличие необходимых параметров
    if (empty($params['orderId']) || empty($params['scheduledFor'])) {
        $amelia_remonline_integration->log("Отсутствуют обязательные параметры: orderId или scheduledFor", 'error');
        return new WP_Error('missing_params', 'Отсутствуют обязательные параметры', array('status' => 400));
    }
    
    $remonline_order_id = $params['orderId'];
    $scheduled_for = $params['scheduledFor']; // timestamp в миллисекундах
    
    $amelia_remonline_integration->log("Получен запрос на обновление даты для заказа Remonline #$remonline_order_id на $scheduled_for", 'info');
    
    // Ищем соответствующую запись в Amelia по внешнему ID (externalId)
    global $wpdb;
    $appointment_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE externalId = %s",
            $remonline_order_id
        )
    );
    
    if (!$appointment_id) {
        $amelia_remonline_integration->log("Не найдена запись Amelia для заказа Remonline #$remonline_order_id", 'error');
        return new WP_Error('appointment_not_found', 'Не найдена запись для данного заказа', array('status' => 404));
    }
    
    // Обновляем дату и время бронирования в Amelia
    $result = update_amelia_appointment_datetime($appointment_id, $scheduled_for);
    
    if ($result === false) {
        $amelia_remonline_integration->log("Ошибка при обновлении даты для записи Amelia ID: $appointment_id", 'error');
        return new WP_Error('update_failed', 'Ошибка при обновлении даты', array('status' => 500));
    }
    
    $human_date = date('Y-m-d H:i:s', intval($scheduled_for) / 1000);
    $amelia_remonline_integration->log("Успешно обновлена дата для записи Amelia ID: $appointment_id на '$human_date'", 'info');
    
    return array(
        'success' => true,
        'message' => "Дата записи #$appointment_id успешно обновлена на '$human_date'",
        'appointment_id' => $appointment_id,
        'remonline_order_id' => $remonline_order_id,
    );
}

/**
 * Сопоставляет статусы Remonline и Amelia
 */
function map_remonline_status_to_amelia($remonline_status_id) {
    // Здесь нужно описать соответствие статусов Remonline статусам Amelia
    // ID статусов Remonline должны быть корректными для вашей системы
    $status_map = array(
        '1642511' => 'pending',    // Автозапис -> Ожидание в Amelia
        '1342663' => 'approved',   // В работе -> Подтверждено в Amelia
        // '1642513' => 'canceled',   // Отменено в Remonline -> Отменено в Amelia
        '1342652' => 'rejected',   // Отклонено в Remonline -> Отклонено в Amelia
        // '1642515' => 'completed'   // Выполнено в Remonline -> Завершено в Amelia
    );
    
    return isset($status_map[$remonline_status_id]) ? $status_map[$remonline_status_id] : 'pending';
}

/**
 * Обновляет статус записи в Amelia
 */
function update_amelia_appointment_status($appointment_id, $status) {
    global $wpdb;
    
    // Проверяем, что статус допустимый
    $valid_statuses = array('pending', 'approved', 'canceled', 'rejected', 'completed');
    if (!in_array($status, $valid_statuses)) {
        return false;
    }
    
    // Обновляем статус записи в таблице amelia_appointments
    $result = $wpdb->update(
        $wpdb->prefix . 'amelia_appointments',
        array('status' => $status),
        array('id' => $appointment_id)
    );
    
    // Если обновление прошло успешно, обновляем также статус в таблице бронирований
    if ($result !== false) {
        $wpdb->update(
            $wpdb->prefix . 'amelia_customer_bookings',
            array('status' => $status),
            array('appointmentId' => $appointment_id)
        );
        
        // Вызываем хук Amelia для обработки изменения статуса
        do_action('amelia_appointment_status_changed', $appointment_id, $status);
        
        return true;
    }
    
    return false;
}

/**
 * Обновляет дату и время записи в Amelia
 */
function update_amelia_appointment_datetime($appointment_id, $timestamp_ms) {
    global $wpdb, $amelia_remonline_integration;
    
    // Проверки входных данных
    if (empty($appointment_id) || empty($timestamp_ms) || !is_numeric($timestamp_ms)) {
        $amelia_remonline_integration->log("Неверные параметры для обновления даты записи: ID=$appointment_id, timestamp=$timestamp_ms", 'error');
        return false;
    }
    
    // Конвертируем метку времени из миллисекунд в секунды
    $timestamp_sec = intval($timestamp_ms) / 1000;
    
    // Форматируем дату в MySQL формат в UTC (без коррекции часового пояса)
    $mysql_datetime = gmdate('Y-m-d H:i:s', $timestamp_sec);
    
    $amelia_remonline_integration->log("Обновление времени записи ID: $appointment_id на $mysql_datetime (UTC) из timestamp: $timestamp_ms", 'debug');
    
    // Получаем текущие данные записи
    $appointment = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}amelia_appointments WHERE id = %d", $appointment_id),
        ARRAY_A
    );
    
    if (!$appointment) {
        $amelia_remonline_integration->log("Не найдена запись с ID: $appointment_id для обновления времени", 'error');
        return false;
    }
    
    // Рассчитываем продолжительность записи
    $duration = isset($appointment['duration']) ? intval($appointment['duration']) : 0;
    if ($duration <= 0) {
        // Если длительность не указана, попробуем получить её из связанной услуги
        if (isset($appointment['serviceId'])) {
            $service_duration = $wpdb->get_var(
                $wpdb->prepare("SELECT duration FROM {$wpdb->prefix}amelia_services WHERE id = %d", $appointment['serviceId'])
            );
            $duration = $service_duration ? intval($service_duration) : 60; // По умолчанию 60 минут
        } else {
            $duration = 60; // По умолчанию 60 минут
        }
    }
    
    // Рассчитываем время окончания (тоже в UTC)
    $end_timestamp = $timestamp_sec + ($duration * 60);
    $end_datetime = gmdate('Y-m-d H:i:s', $end_timestamp);
    
    // Добавим дебаг-информацию
    $timezone_string = get_option('timezone_string');
    $amelia_remonline_integration->log(
        "Информация о времени: timestamp_ms=$timestamp_ms, UTC_start=$mysql_datetime, UTC_end=$end_datetime, " . 
        "WP_timezone=$timezone_string, Local_time=" . date('Y-m-d H:i:s', $timestamp_sec),
        'debug'
    );
    
    // Обновляем запись в базе данных (сохраняем в UTC)
    $result = $wpdb->update(
        $wpdb->prefix . 'amelia_appointments',
        array(
            'bookingStart' => $mysql_datetime,
            'bookingEnd' => $end_datetime
        ),
        array('id' => $appointment_id)
    );
    
    if ($result === false) {
        $amelia_remonline_integration->log("Ошибка при обновлении времени записи: " . $wpdb->last_error, 'error');
        return false;
    }
    
    $amelia_remonline_integration->log("Успешно обновлено время записи ID: $appointment_id на $mysql_datetime (UTC) с окончанием: $end_datetime (UTC)", 'info');
    
    // Вызываем хук Amelia для уведомления об изменении расписания
    do_action('amelia_appointment_time_updated', $appointment_id, $mysql_datetime, $end_datetime);
    
    return true;
}

// Добавляем новое поле в настройки плагина для секретного ключа вебхука
add_action('admin_init', function() {
    register_setting('amelia_remonline_settings', 'remonline_webhook_secret', array(
        'default' => wp_generate_password(20, false)
    ));
});

// Добавляем регистрацию REST API эндпоинта для проверки
add_action('rest_api_init', function () {
    // Endpoint для проверки наличия записи в Amelia
    register_rest_route('amelia-remonline/v1', '/check-appointment', array(
        'methods' => 'GET',
        'callback' => 'check_appointment_exists',
        'permission_callback' => '__return_true'
    ));

    // Endpoint для обновления статуса
    register_rest_route('amelia-remonline/v1', '/update-status', array(
        'methods' => 'POST',
        'callback' => 'update_appointment_status',
        'permission_callback' => '__return_true'
    ));

    // Endpoint для обновления даты/времени
    register_rest_route('amelia-remonline/v1', '/update-datetime', array(
        'methods' => 'POST',
        'callback' => 'update_appointment_datetime',
        'permission_callback' => '__return_true'
    ));

    // Endpoint для получения токена
    register_rest_route('amelia-remonline/v1', '/get-token', array(
        'methods' => 'GET',
        'callback' => 'get_remonline_token',
        'permission_callback' => '__return_true'
    ));
});

/**
 * Обработчик запроса на получение актуального токена Remonline
 */
function handle_get_token_request($request) {
    global $amelia_remonline_integration;
    
    $params = $request->get_params();
    
    // Проверка секретного ключа
    if (empty($params['secret']) || $params['secret'] !== get_option('remonline_webhook_secret', '')) {
        $amelia_remonline_integration->log("Неверный секретный ключ при запросе токена", 'error');
        return new WP_Error('invalid_secret', 'Неверный секретный ключ', array('status' => 403));
    }
    
    $token = get_option('remonline_api_token', '');
    $expiry = get_option('remonline_token_expiry', 0);
    
    // Если токен просрочен или скоро истечет, обновляем его
    if (time() > $expiry - 3600) { // за 1 час до истечения
        $amelia_remonline_integration->log("Токен просрочен или скоро истечет, обновляем", 'info');
        $token = $amelia_remonline_integration->update_token();
    }
    
    if (empty($token)) {
        $amelia_remonline_integration->log("Не удалось получить или обновить токен", 'error');
        return new WP_Error('token_error', 'Не удалось получить или обновить токен', array('status' => 500));
    }
    
    return array(
        'success' => true,
        'token' => $token,
        'expires' => get_option('remonline_token_expiry', 0)
    );
}

/**
 * Проверяет наличие записи с указанным external_id в базе данных Amelia
 */
function check_appointment_exists($request) {
    global $wpdb, $amelia_remonline_integration;
    
    $params = $request->get_params();
    $secret = isset($params['secret']) ? $params['secret'] : '';
    $external_id = isset($params['external_id']) ? $params['external_id'] : '';
    
    // Проверка секретного ключа
    if ($secret !== get_option('remonline_webhook_secret', '')) {
        $amelia_remonline_integration->log("Неверный секретный ключ при проверке записи: $external_id", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Неверный секретный ключ'], 403);
    }
    
    if (empty($external_id)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Не указан ID заказа'], 400);
    }
    
    $amelia_remonline_integration->log("Проверка наличия записи с externalId: $external_id", 'info');
    
    $appointment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE externalId = %s LIMIT 1",
        $external_id
    ));
    
    $exists = !empty($appointment_id);
    
    $amelia_remonline_integration->log("Результат проверки записи $external_id: " . ($exists ? "Найдена (ID: $appointment_id)" : "Не найдена"), 'info');
    
    return [
        'success' => true,
        'exists' => $exists,
        'appointment_id' => $appointment_id
    ];
}

/**
 * Обновляет статус записи в Amelia
 */
function update_appointment_status($request) {
    global $wpdb, $amelia_remonline_integration;
    
    $params = json_decode($request->get_body(), true);
    
    $secret = isset($params['secret']) ? $params['secret'] : '';
    $order_id = isset($params['orderId']) ? $params['orderId'] : '';
    $new_status_id = isset($params['newStatusId']) ? $params['newStatusId'] : '';
    
    // Проверка секретного ключа
    if ($secret !== get_option('remonline_webhook_secret', '')) {
        $amelia_remonline_integration->log("Неверный секретный ключ при обновлении статуса", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Неверный секретный ключ'], 403);
    }
    
    if (empty($order_id) || empty($new_status_id)) {
        $amelia_remonline_integration->log("Недостаточно данных для обновления статуса", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Недостаточно данных'], 400);
    }
    
    $amelia_remonline_integration->log("Запрос на обновление статуса для заказа $order_id на статус $new_status_id", 'info');
    
    // Получаем ID записи по внешнему ID
    $appointment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE externalId = %s LIMIT 1",
        $order_id
    ));
    
    if (!$appointment_id) {
        $amelia_remonline_integration->log("Запись для заказа $order_id не найдена в Amelia", 'error');
        return new WP_REST_Response([
            'success' => false, 
            'message' => "Запись для заказа $order_id не найдена в Amelia"
        ], 404);
    }
    
    // Определяем статус Amelia на основе статуса Remonline
    $amelia_status = 'pending'; // По умолчанию
    
    // Статусы Remonline
    $IN_PROGRESS_STATUS_ID = '1342663'; // "Новий"
    $AUTO_APPOINTMENT_STATUS_ID = '1642511'; // "Автозапис"
    $COMPLETED_STATUS_ID = '1342658'; // "Выполнен"
    $CANCELED_STATUS_ID = '1342652'; // "Отменен"
    
    // Маппинг статусов Remonline -> Amelia
    switch ($new_status_id) {
        case $IN_PROGRESS_STATUS_ID: // "Новий"
            $amelia_status = 'approved'; // Подтвержден
            break;
        case $COMPLETED_STATUS_ID: // "Выполнен"
            $amelia_status = 'approved'; // или другой подходящий статус
            break;
        case $CANCELED_STATUS_ID: // "Отменен"
            $amelia_status = 'canceled'; // Отменен
            break;
        case $AUTO_APPOINTMENT_STATUS_ID: // "Автозапис"
            $amelia_status = 'pending'; // Ожидает
            break;
        default:
            $amelia_status = 'pending';
    }
    
    $amelia_remonline_integration->log("Преобразование статуса Remonline $new_status_id в статус Amelia: $amelia_status", 'info');
    
    // Обновляем статус в БД
    $result = $wpdb->update(
        $wpdb->prefix . 'amelia_appointments',
        ['status' => $amelia_status],
        ['id' => $appointment_id]
    );
    
    if ($result === false) {
        $amelia_remonline_integration->log("Ошибка при обновлении статуса: " . $wpdb->last_error, 'error');
        return new WP_REST_Response([
            'success' => false, 
            'message' => "Ошибка при обновлении статуса: " . $wpdb->last_error
        ], 500);
    }
    
    $amelia_remonline_integration->log("Статус записи #$appointment_id успешно обновлен на $amelia_status", 'info');
    
    // Вызываем хук Amelia для уведомления о смене статуса
    do_action('amelia_appointment_status_changed', $appointment_id, $amelia_status);
    
    return [
        'success' => true,
        'message' => "Статус успешно обновлен на $amelia_status",
        'appointment_id' => $appointment_id,
        'status' => $amelia_status
    ];
}

/**
 * Возвращает текущий токен Remonline API
 */
function get_remonline_token($request) {
    global $amelia_remonline_integration;
    
    $params = $request->get_params();
    $secret = isset($params['secret']) ? $params['secret'] : '';
    
    // Проверка секретного ключа
    if ($secret !== get_option('remonline_webhook_secret', '')) {
        $amelia_remonline_integration->log("Неверный секретный ключ при запросе токена", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Неверный секретный ключ'], 403);
    }
    
    $token = get_option('remonline_api_token', '');
    $expiry = intval(get_option('remonline_token_expiry', 0));
    
    // Если токен устарел или скоро истечет, обновляем его
    if (empty($token) || time() > $expiry - 1800) { // Обновляем, если осталось менее 30 минут
        $amelia_remonline_integration->log("Обновление истекающего токена", 'info');
        $token = $amelia_remonline_integration->update_token();
        $expiry = intval(get_option('remonline_token_expiry', 0));
    }
    
    if (empty($token)) {
        $amelia_remonline_integration->log("Не удалось получить токен API", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Не удалось получить токен API'], 500);
    }
    
    return [
        'success' => true,
        'token' => $token,
        'expires' => $expiry
    ];
}

/**
 * Обновляет дату и время записи в Amelia
 */
function update_appointment_datetime($request) {
    global $wpdb, $amelia_remonline_integration;
    
    $params = json_decode($request->get_body(), true);
    
    $secret = isset($params['secret']) ? $params['secret'] : '';
    $order_id = isset($params['orderId']) ? $params['orderId'] : '';
    $scheduled_for = isset($params['scheduledFor']) ? $params['scheduledFor'] : 0;
    
    // Проверка секретного ключа
    if ($secret !== get_option('remonline_webhook_secret', '')) {
        $amelia_remonline_integration->log("Неверный секретный ключ при обновлении времени", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Неверный секретный ключ'], 403);
    }
    
    if (empty($order_id) || empty($scheduled_for)) {
        $amelia_remonline_integration->log("Недостаточно данных для обновления времени", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Недостаточно данных'], 400);
    }
    
    $scheduled_time = intval($scheduled_for);
    if ($scheduled_time <= 0) {
        $amelia_remonline_integration->log("Некорректное время для обновления: $scheduled_time", 'error');
        return new WP_REST_Response(['success' => false, 'message' => 'Некорректное время'], 400);
    }
    
    $amelia_remonline_integration->log("Запрос на обновление времени для заказа $order_id на " . date('Y-m-d H:i:s', $scheduled_time/1000), 'info');
    
    // Получаем ID записи по внешнему ID
    $appointment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE externalId = %s LIMIT 1",
        $order_id
    ));
    
    if (!$appointment_id) {
        $amelia_remonline_integration->log("Запись для заказа $order_id не найдена в Amelia", 'error');
        return new WP_REST_Response([
            'success' => false, 
            'message' => "Запись для заказа $order_id не найдена в Amelia"
        ], 404);
    }
    
    // Получаем информацию о записи
    $appointment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
        $appointment_id
    ));
    
    if (!$appointment) {
        $amelia_remonline_integration->log("Ошибка при получении данных записи #$appointment_id", 'error');
        return new WP_REST_Response([
            'success' => false, 
            'message' => "Ошибка при получении данных записи"
        ], 500);
    }
    
    // Преобразуем время из миллисекунд в MySQL datetime формат (UTC)
    $mysql_datetime = date('Y-m-d H:i:s', $scheduled_time/1000);
    
    // Рассчитываем новое время окончания (сохраняем исходную длительность)
    $duration = strtotime($appointment->bookingEnd) - strtotime($appointment->bookingStart);
    $new_end_time = date('Y-m-d H:i:s', $scheduled_time/1000 + $duration);
    
    $amelia_remonline_integration->log("Обновление времени записи #$appointment_id: $mysql_datetime - $new_end_time", 'info');
    
    // Обновляем время в БД
    $result = $wpdb->update(
        $wpdb->prefix . 'amelia_appointments',
        [
            'bookingStart' => $mysql_datetime,
            'bookingEnd' => $new_end_time
        ],
        ['id' => $appointment_id]
    );
    
    if ($result === false) {
        $amelia_remonline_integration->log("Ошибка при обновлении времени: " . $wpdb->last_error, 'error');
        return new WP_REST_Response([
            'success' => false, 
            'message' => "Ошибка при обновлении времени: " . $wpdb->last_error
        ], 500);
    }
    
    $amelia_remonline_integration->log("Время записи #$appointment_id успешно обновлено", 'info');
    
    // Вызываем хук Amelia для уведомления о изменении времени
    do_action('amelia_appointment_time_updated', $appointment_id);
    
    return [
        'success' => true,
        'message' => "Время успешно обновлено",
        'appointment_id' => $appointment_id,
        'new_start' => $mysql_datetime,
        'new_end' => $new_end_time
    ];
}