<?php
if (!defined('ABSPATH')) {
    exit;
}

class WRR_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Reward Roulette',
            'Roulette',
            'manage_options',
            'reward-roulette',
            array($this, 'render_dashboard'),
            'dashicons-superhero',
            58
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_reward-roulette' !== $hook) {
            return;
        }
        
        wp_enqueue_script('wrr-admin-preview', WRR_PLUGIN_URL . 'admin/js/wrr-admin-preview.js', array('jquery'), WRR_VERSION, true);
        
        // Pass initial sector data for preview
        $sectors = WRR_Database::get_sectors(false);
        wp_localize_script('wrr-admin-preview', 'wrr_admin_data', array(
            'sectors' => $sectors
        ));
    }

    public function render_dashboard() {
        // Handle Delete
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && check_admin_referer('wrr_delete_sector')) {
            WRR_Database::delete_sector(intval($_GET['id']));
            echo '<div class="notice notice-success"><p>Сектор видалено!</p></div>';
        }
    
        // Handle Save
        if (isset($_POST['wrr_save_sectors']) && check_admin_referer('wrr_save_sectors_nonce')) {
            $this->save_sectors($_POST['sectors']);
            
            // Handle Add New
            if (!empty($_POST['new_sector']['name'])) {
                WRR_Database::add_sector(array(
                    'name' => sanitize_text_field($_POST['new_sector']['name']),
                    'type' => sanitize_text_field($_POST['new_sector']['type']),
                    'value' => sanitize_text_field($_POST['new_sector']['value']),
                    'probability' => intval($_POST['new_sector']['probability']),
                    'color' => sanitize_hex_color($_POST['new_sector']['color']),
                    'is_active' => 1
                ));
            }
            
            echo '<div class="notice notice-success"><p>Налаштування оновлено!</p></div>';
        }
        
        if (isset($_POST['wrr_save_settings']) && check_admin_referer('wrr_save_settings_nonce')) {
            $settings = array(
                'min_spent' => floatval($_POST['min_spent']),
                'min_orders' => intval($_POST['min_orders']),
                'allowed_roles' => isset($_POST['allowed_roles']) ? array_map('sanitize_text_field', $_POST['allowed_roles']) : array()
            );

            // Registration fields visibility
            $reg_fields = array(
                'first_name' => isset($_POST['reg_field_first_name']) ? 1 : 0,
                'last_name'  => isset($_POST['reg_field_last_name']) ? 1 : 0,
                'date_of_birth' => isset($_POST['reg_field_dob']) ? 1 : 0
            );
            update_option('wrr_registration_fields', $reg_fields);
            update_option('wrr_targeting_settings', $settings);
            echo '<div class="notice notice-success"><p>Налаштування збережено!</p></div>';
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sectors';
        $sectors = WRR_Database::get_sectors(false); // Get ALL sectors, including inactive
        
        // Settings get ... (omitted for brevity, same as before)
        $settings = get_option('wrr_targeting_settings', array('min_spent' => 0, 'min_orders' => 0, 'allowed_roles' => array()));
        global $wp_roles;
        $all_roles = $wp_roles->roles;
        
        ?>
        <div class="wrap">
            <h1>🎰 Налаштування Reward Roulette</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=reward-roulette&tab=sectors" class="nav-tab <?php echo $active_tab == 'sectors' ? 'nav-tab-active' : ''; ?>">Сектори Призів</a>
                <a href="?page=reward-roulette&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Правила Показу</a>
                <a href="?page=reward-roulette&tab=birthday" class="nav-tab <?php echo $active_tab == 'birthday' ? 'nav-tab-active' : ''; ?>">🎉 Подарунок на ДН</a>
            </nav>
            
            <br>

            <?php if ($active_tab == 'sectors'): ?>
                <div style="display: flex; gap: 20px;">
                    <div style="flex: 2;">
                        <form method="post" action="">
                            <?php wp_nonce_field('wrr_save_sectors_nonce'); ?>
                            
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>Назва</th>
                                        <th>Тип</th>
                                        <th>Значення</th>
                                        <th>Ймовірність (%)</th>
                                        <th>Колір</th>
                                        <th style="width: 50px;">Вкл</th>
                                        <th style="width: 50px;">Дії</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($sectors)): foreach($sectors as $index => $sector): ?>
                                        <tr>
                                            <td>
                                                <input type="hidden" name="sectors[<?php echo $sector->id; ?>][id]" value="<?php echo $sector->id; ?>">
                                                <input type="text" name="sectors[<?php echo $sector->id; ?>][name]" value="<?php echo esc_attr($sector->name); ?>" style="width:100%" class="wrr-input-name">
                                            </td>
                                            <td>
                                                <select name="sectors[<?php echo $sector->id; ?>][type]">
                                                    <option value="coupon" <?php selected($sector->type, 'coupon'); ?>>Купон</option>
                                                    <option value="cashback" <?php selected($sector->type, 'cashback'); ?>>Кешбек</option>
                                                    <option value="shipping" <?php selected($sector->type, 'shipping'); ?>>Безкоштовна Доставка</option>
                                                    <option value="product" <?php selected($sector->type, 'product'); ?>>Товар</option>
                                                    <option value="no_win" <?php selected($sector->type, 'no_win'); ?>>Нічого</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="sectors[<?php echo $sector->id; ?>][value]" value="<?php echo esc_attr($sector->value); ?>" placeholder="10 або ID">
                                            </td>
                                            <td>
                                                <input type="number" name="sectors[<?php echo $sector->id; ?>][probability]" value="<?php echo esc_attr($sector->probability); ?>" min="0" max="100">
                                            </td>
                                            <td>
                                                <input type="color" name="sectors[<?php echo $sector->id; ?>][color]" value="<?php echo esc_attr($sector->color); ?>" class="wrr-input-color">
                                            </td>
                                            <td>
                                                <input type="checkbox" name="sectors[<?php echo $sector->id; ?>][is_active]" value="1" <?php checked($sector->is_active, 1); ?>>
                                            </td>
                                            <td>
                                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=reward-roulette&tab=sectors&action=delete&id=' . $sector->id), 'wrr_delete_sector'); ?>" onclick="return confirm('Видалити цей сектор?');" class="button button-small notice-dismiss" style="position:relative; right:auto; text-decoration:none;">❌</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    
                                    <!-- New Sector Row -->
                                    <tr style="background: #e6f7ff; border-top: 2px solid #2271b1;">
                                        <td>
                                            <strong>Новий:</strong>
                                            <input type="text" name="new_sector[name]" placeholder="Назва сектора" style="width:100%">
                                        </td>
                                        <td>
                                            <select name="new_sector[type]">
                                                <option value="coupon">Купон</option>
                                                <option value="cashback">Кешбек</option>
                                                <option value="shipping">Безкоштовна Доставка</option>
                                                <option value="product">Товар</option>
                                                <option value="no_win">Нічого</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="new_sector[value]" placeholder="Значення">
                                        </td>
                                        <td>
                                            <input type="number" name="new_sector[probability]" value="10" min="0" max="100">
                                        </td>
                                        <td>
                                            <input type="color" name="new_sector[color]" value="#2271b1">
                                        </td>
                                        <td colspan="2">
                                            <em>Додасться при збереженні</em>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p>Переконайтесь, що сума ймовірностей дорівнює 100 для найкращого досвіду.</p>
                            
                            <p class="submit">
                                <input type="submit" name="wrr_save_sectors" id="submit" class="button button-primary" value="Зберегти Зміни">
                            </p>
                        </form>
                    </div>
                    
                    <!-- Live Preview Side -->
                    <div style="flex: 1; min-width: 320px;">
                        <div class="card" style="position: sticky; top: 150px; text-align: center;">
                            <h3>🎨 Live Preview</h3>
                            <div id="wrr-admin-preview-container" style="position: relative; width: 300px; height: 300px; margin: 0 auto;">
                                <div class="wrr-pointer" style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 15px solid transparent; border-right: 15px solid transparent; border-top: 25px solid #333; z-index: 10;"></div>
                                <canvas id="wrr-admin-canvas" width="300" height="300" style="width: 100%; height: 100%; border-radius: 50%; box-shadow: 0 10px 20px rgba(0,0,0,0.15);"></canvas>
                            </div>
                            <p class="description" style="margin-top: 15px;">Рулетка оновлюється при зміні кольорів чи назв.</p>
                        </div>
                    </div>
                </div>
                
                <div class="card" style="margin-top:20px;">
                    <h3>📜 Історія Останніх 50 Спінів</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Користувач</th>
                                <th>Нагорода</th>
                                <th>Значення</th>
                                <th>Дата</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $logs = WRR_Database::get_logs(50);
                            if ($logs): 
                                foreach($logs as $log): 
                                    $user_display = $log->user_login ? $log->user_login : 'Guest';
                                    ?>
                                    <tr>
                                        <td><?php echo $log->id; ?></td>
                                        <td><?php echo esc_html($user_display); ?></td>
                                        <td><?php echo esc_html($log->reward_type); ?></td>
                                        <td><?php echo esc_html($log->reward_value); ?></td>
                                        <td><?php echo esc_html($log->created_at); ?></td>
                                    </tr>
                                    <?php 
                                endforeach; 
                            else: 
                                ?>
                                <tr>
                                    <td colspan="5">Історія порожня.</td>
                                </tr>
                                <?php 
                            endif; 
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($active_tab == 'settings'): ?>
                 <form method="post" action="">
                    <?php wp_nonce_field('wrr_save_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Мінімальні Витрати</th>
                            <td>
                                <input type="number" step="0.01" name="min_spent" value="<?php echo esc_attr($settings['min_spent']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Мінімальна Кількість Замовлень</th>
                            <td>
                                <input type="number" name="min_orders" value="<?php echo esc_attr($settings['min_orders']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Поля реєстрації (WooCommerce)</th>
                            <td>
                                <?php $reg_fields = get_option('wrr_registration_fields', array('first_name'=>1,'last_name'=>1,'date_of_birth'=>0)); ?>
                                <label style="display:block; margin-bottom:5px;"><input type="checkbox" name="reg_field_first_name" value="1" <?php checked(!empty($reg_fields['first_name']),1); ?>> Показувати поле "Ім'я"</label>
                                <label style="display:block; margin-bottom:5px;"><input type="checkbox" name="reg_field_last_name" value="1" <?php checked(!empty($reg_fields['last_name']),1); ?>> Показувати поле "Прізвище"</label>
                                <label style="display:block; margin-bottom:5px;"><input type="checkbox" name="reg_field_dob" value="1" <?php checked(!empty($reg_fields['date_of_birth']),1); ?>> Показувати поле "Дата народження"</label>
                                <p class="description">Виберіть, які додаткові поля будуть додаватися у форму реєстрації WooCommerce.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Дозволені Ролі</th>
                            <td>
                                <?php 
                                $allowed = isset($settings['allowed_roles']) ? $settings['allowed_roles'] : array();
                                foreach($all_roles as $role_key => $role_data): 
                                ?>
                                    <label style="display:block; margin-bottom:5px;">
                                        <input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, $allowed)); ?>>
                                        <?php echo $role_data['name']; ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="wrr_save_settings" class="button button-primary" value="Зберегти Налаштування">
                    </p>
                </form>
            <?php elseif ($active_tab == 'birthday'): ?>
                <?php
                if (isset($_POST['wrr_save_birthday_settings']) && check_admin_referer('wrr_save_birthday_nonce')) {
                    $bday_settings = array(
                        'enabled' => isset($_POST['bday_enabled']) ? 'yes' : 'no',
                        'email_subject' => sanitize_text_field($_POST['bday_subject']),
                        'email_content' => wp_kses_post($_POST['bday_content'])
                    );
                    update_option('wrr_birthday_settings', $bday_settings);
                    echo '<div class="notice notice-success"><p>Налаштування ДН збережено!</p></div>';
                }
                $bday_settings = get_option('wrr_birthday_settings', array(
                    'enabled' => 'no',
                    'email_subject' => 'З Днем Народження! 🎂 Отримайте ваш подарунок!',
                    'email_content' => '<p>З Днем Народження!</p><p>Сьогодні ваш особливий день, і ми підготували для вас можливість виграти чудовий подарунок. Прокрутіть наше Колесо Фортуни прямо зараз!</p><p><a href="{site_url}" style="padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 5px;">Прокрутити Колесо</a></p>'
                ));
                ?>
                <form method="post" action="">
                    <?php wp_nonce_field('wrr_save_birthday_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Увімкнути привітання?</th>
                            <td>
                                <input type="checkbox" name="bday_enabled" value="1" <?php checked($bday_settings['enabled'], 'yes'); ?>>
                                <p class="description">Якщо увімкнено, система щодня перевірятиме іменинників та надсилатиме їм запрошення.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Тема Email</th>
                            <td>
                                <input type="text" name="bday_subject" value="<?php echo esc_attr($bday_settings['email_subject']); ?>" class="large-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Зміст Email</th>
                            <td>
                                <?php 
                                wp_editor($bday_settings['email_content'], 'bday_content', array('textarea_name' => 'bday_content', 'textarea_rows' => 10)); 
                                ?>
                                <p class="description">Доступні шорткоди: <code>{user_name}</code>, <code>{site_url}</code>.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="wrr_save_birthday_settings" class="button button-primary" value="Зберегти Налаштування ДН">
                    </p>
                </form>

                <hr>

                <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                    <h3>🧪 Тестова Відправка</h3>
                    <?php
                    if (isset($_POST['wrr_send_test_email']) && !empty($_POST['test_email_address'])) {
                        check_admin_referer('wrr_test_email_nonce');
                        $test_email = sanitize_email($_POST['test_email_address']);
                        $automation = WRR_Birthday_Automation::get_instance();
                        if ($automation->send_test_email($test_email)) {
                            echo '<div class="notice notice-success inline"><p>Тестовий лист надіслано на <strong>' . esc_html($test_email) . '</strong>!</p></div>';
                        } else {
                            echo '<div class="notice notice-error inline"><p>Помилка при відправці листа.</p></div>';
                        }
                    }
                    ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('wrr_test_email_nonce'); ?>
                        <p>Введіть Email для отримання тестового привітання:</p>
                        <input type="email" name="test_email_address" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required>
                        <input type="submit" name="wrr_send_test_email" class="button button-secondary" value="Надіслати Тестовий Email">
                        <p class="description">Лист міститиме посилання з активованим тестовим режимом рулетки.</p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function save_sectors($sectors_data) {
        global $wpdb;
        foreach ($sectors_data as $id => $data) {
            $wpdb->update(
                "{$wpdb->prefix}wrr_sectors",
                array(
                    'name' => sanitize_text_field($data['name']),
                    'type' => sanitize_text_field($data['type']),
                    'value' => sanitize_text_field($data['value']),
                    'probability' => intval($data['probability']),
                    'color' => sanitize_hex_color($data['color']),
                    'is_active' => isset($data['is_active']) ? 1 : 0
                ),
                array('id' => intval($data['id']))
            );
        }
    }
}
