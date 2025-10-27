<?php
/**
 * Plugin Name: Didar CRM Complete User Sync
 * Plugin URI: https://didar.me
 * Description: پلاگین کامل برای سینک دوطرفه کاربران وردپرس و ووکامرس با CRM دیدار. شامل تنظیمات پیشرفته، cron job، و مدیریت خطاها.
 * Version: 2.2
 * Author: Rick Sanchez
 * Author URI: https://ricksanchez.ir
 * License: GPL v2 or later
 * Text Domain: didar-crm
 * Tested up to: 6.8
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// تعریف ثابت‌ها
define('DIDAR_SYNC_VERSION', '2.2');
define('DIDAR_SYNC_API_BASE', 'https://app.didar.me/api/');
define('DIDAR_SYNC_OPTION_KEY', 'didar_sync_options');
define('DIDAR_SYNC_LOG_FILE', WP_CONTENT_DIR . '/didar-sync.log');

// کلاس اصلی پلاگین
class DidarCRM_Complete_Sync {
    private $options;
    private $api_key;
    private $field_mapping = array(
        'first_name' => 'FirstName',
        'last_name' => 'LastName',
        'email' => 'Email',
        'phone' => 'MobilePhone',
        'username' => 'Code'
    );

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // هوک‌های وردپرس
        add_action('user_register', array($this, 'sync_to_didar'), 10, 1);
        add_action('profile_update', array($this, 'sync_to_didar'), 10, 1);

        // هوک‌های ووکامرس اگر فعال باشد
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_customer_save_address', array($this, 'sync_to_didar'), 10, 2);
            add_action('woocommerce_new_customer', array($this, 'sync_to_didar'), 10, 1);
            add_action('woocommerce_update_customer', array($this, 'sync_to_didar'), 10, 1);
        }
        
        // Cron برای سینک از دیدار به WP
        add_action('didar_cron_sync_from_didar', array($this, 'sync_from_didar'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // AJAX برای سینک دستی
        add_action('wp_ajax_didar_manual_sync', array($this, 'manual_sync_handler'));
        add_action('wp_ajax_didar_sync_single_user', array($this, 'sync_single_user_handler'));

        // هوک‌های لیست کاربران
        add_filter('manage_users_columns', array($this, 'add_sync_column'));
        add_action('manage_users_custom_column', array($this, 'render_sync_column'), 10, 3);
        add_action('admin_footer-users.php', array($this, 'add_sync_scripts'));

        // هوک‌های ویرایش کاربر
        add_action('show_user_profile', array($this, 'add_sync_optout_field'));
        add_action('edit_user_profile', array($this, 'add_sync_optout_field'));
        add_action('personal_options_update', array($this, 'save_sync_optout'));
        add_action('edit_user_profile_update', array($this, 'save_sync_optout'));
    }

    public function init() {
        $this->options = get_option(DIDAR_SYNC_OPTION_KEY, array());
        $this->api_key = isset($this->options['api_key']) ? sanitize_text_field($this->options['api_key']) : '';
        $this->field_mapping = apply_filters('didar_field_mapping', $this->field_mapping);
        
        load_plugin_textdomain('didar-crm', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_didar-sync' && $screen->id !== 'didar-sync_page_didar-logs' && $screen->id !== 'users' && $screen->id !== 'profile' && $screen->id !== 'user-edit') {
            return;
        }

        // CSS سفارشی برای UI زیبا
        wp_add_inline_style('wp-admin', '
            #didar-sync-wrap .didar-card { 
                background: #fff; 
                border: 1px solid #c3c4c7; 
                border-radius: 4px; 
                padding: 20px; 
                margin: 20px 0; 
                box-shadow: 0 1px 1px rgba(0,0,0,.04); 
            }
            #didar-sync-wrap .didar-tab-nav { 
                display: flex; 
                background: #f1f1f1; 
                border-bottom: 1px solid #c3c4c7; 
                margin: -20px -20px 20px; 
                padding: 10px 20px; 
            }
            #didar-sync-wrap .didar-tab-nav a { 
                display: block; 
                padding: 10px 20px; 
                text-decoration: none; 
                color: #0073aa; 
                border-bottom: 2px solid transparent; 
                transition: all .3s; 
            }
            #didar-sync-wrap .didar-tab-nav a.active { 
                color: #1d2327; 
                border-bottom-color: #0073aa; 
                font-weight: bold; 
            }
            #didar-sync-wrap .didar-tab-content { display: none; }
            #didar-sync-wrap .didar-tab-content.active { display: block; }
            #didar-sync-wrap .form-table th { font-weight: bold; color: #1d2327; }
            #didar-sync-wrap .mapping-table { width: 100%; border-collapse: collapse; }
            #didar-sync-wrap .mapping-table th, #didar-sync-wrap .mapping-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
            #didar-sync-wrap .mapping-table input { width: 100%; }
            #didar-sync-wrap .sync-buttons { display: flex; gap: 10px; margin: 20px 0; }
            #didar-sync-wrap .sync-btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; transition: background .3s; }
            #didar-sync-wrap .sync-btn-primary { background: #0073aa; color: white; }
            #didar-sync-wrap .sync-btn-primary:hover { background: #005a87; }
            #didar-sync-wrap .sync-btn-secondary { background: #f1f1f1; color: #0073aa; }
            #didar-sync-wrap .sync-btn-secondary:hover { background: #ddd; }
            #didar-sync-wrap .loading { display: none; text-align: center; padding: 20px; }
            #didar-sync-wrap .loading.active { display: block; }
            #didar-sync-wrap .success-notice { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
            .didar-sync-button { 
                padding: 5px 10px; 
                border: 1px solid #0073aa; 
                background: #0073aa; 
                color: white; 
                border-radius: 3px; 
                cursor: pointer; 
                font-size: 12px; 
                text-decoration: none; 
            }
            .didar-sync-button:hover { background: #005a87; }
            .didar-sync-button.disabled { background: #ccc; cursor: not-allowed; }
            .didar-sync-status { font-size: 11px; color: #666; }
        ');

        // JS برای تب‌ها و AJAX
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Tab Navigation
                $(".didar-tab-nav a").click(function(e) {
                    e.preventDefault();
                    var tab = $(this).attr("href");
                    $(".didar-tab-nav a").removeClass("active");
                    $(this).addClass("active");
                    $(".didar-tab-content").removeClass("active");
                    $(tab).addClass("active");
                });

                // AJAX Sync for main settings
                $(".sync-btn").click(function(e) {
                    e.preventDefault();
                    var btn = $(this);
                    var direction = btn.data("direction");
                    var loading = $(".loading");
                    var notice = $(".success-notice");
                    
                    if (!confirm("آیا مطمئن هستید؟ این عملیات ممکن است زمان‌بر باشد.") ) return;
                    
                    btn.prop("disabled", true).text("در حال انجام...");
                    loading.addClass("active").html("<p>در حال سینک... لطفاً صبر کنید.</p><div class=\'spinner\'></div>");
                    notice.removeClass("active");
                    
                    $.post(ajaxurl, {
                        action: "didar_manual_sync",
                        nonce: didar_ajax.nonce,
                        direction: direction
                    }).done(function(response) {
                        if (response.success) {
                            notice.html("سینک با موفقیت انجام شد: " + response.data.message).addClass("active");
                        } else {
                            alert("خطا: " + response.data);
                        }
                        btn.prop("disabled", false).text(btn.data("original-text"));
                        loading.removeClass("active");
                    }).fail(function() {
                        alert("خطای ارتباطی");
                        btn.prop("disabled", false).text(btn.data("original-text"));
                        loading.removeClass("active");
                    });
                });
                
                // Single user sync
                $(".didar-sync-button").click(function(e) {
                    e.preventDefault();
                    var btn = $(this);
                    var userId = btn.data("user-id");
                    var status = btn.next(".didar-sync-status");
                    
                    if (btn.hasClass("disabled")) return;
                    
                    btn.addClass("disabled").text("در حال سینک...");
                    status.html("در حال سینک...");
                    
                    $.post(ajaxurl, {
                        action: "didar_sync_single_user",
                        nonce: didar_ajax.nonce,
                        user_id: userId
                    }).done(function(response) {
                        if (response.success) {
                            status.html("سینک شد: " + response.data.message).css("color", "green");
                        } else {
                            status.html("خطا: " + response.data).css("color", "red");
                        }
                        btn.removeClass("disabled").text("سینک");
                    }).fail(function() {
                        status.html("خطای ارتباطی").css("color", "red");
                        btn.removeClass("disabled").text("سینک");
                    });
                });
                
                // ذخیره original text برای دکمه‌ها
                $(".sync-btn").each(function() {
                    $(this).data("original-text", $(this).text());
                });
            });
        ');

        wp_localize_script('jquery', 'didar_ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('didar_sync_actions')
        ));
    }

    public function add_sync_column($columns) {
        $columns['didar_sync'] = __('سینک دیدار', 'didar-crm');
        return $columns;
    }

    public function render_sync_column($value, $column_name, $user_id) {
        if ($column_name !== 'didar_sync') {
            return $value;
        }

        $optout = get_user_meta($user_id, 'didar_optout', true);
        if ($optout) {
            return '<span class="didar-sync-status" style="color: orange;">غیرفعال (opt-out)</span>';
        }

        $didar_id = get_user_meta($user_id, 'didar_contact_id', true);
        $status = $didar_id ? 'همگام: ID ' . $didar_id : 'نامحسوب';

        echo '<button class="didar-sync-button" data-user-id="' . esc_attr($user_id) . '">سینک</button>';
        echo '<br><span class="didar-sync-status">' . esc_html($status) . '</span>';
    }

    public function add_sync_scripts() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // کد JS قبلاً در enqueue اضافه شده
        });
        </script>
        <?php
    }

    public function add_sync_optout_field($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        $optout = get_user_meta($user->ID, 'didar_optout', true);
        ?>
        <h3><?php esc_html_e('تنظیمات سینک دیدار', 'didar-crm'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="didar_optout"><?php esc_html_e('عدم سینک با دیدار', 'didar-crm'); ?></label></th>
                <td>
                    <input type="checkbox" id="didar_optout" name="didar_optout" <?php checked($optout); ?> />
                    <p class="description"><?php esc_html_e('این کاربر را از سینک خودکار و دستی با CRM دیدار خارج کند.', 'didar-crm'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_sync_optout($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (isset($_POST['didar_optout'])) {
            update_user_meta($user_id, 'didar_optout', true);
        } else {
            delete_user_meta($user_id, 'didar_optout');
        }
    }

    public function activate() {
        // تنظیمات پیش‌فرض
        $default_options = array(
            'api_key' => '',
            'sync_direction' => 'both',
            'cron_frequency' => 'daily',
            'field_mapping' => $this->field_mapping,
            'log_enabled' => true,
            'auto_sync_woocommerce' => true
        );
        if (!get_option(DIDAR_SYNC_OPTION_KEY)) {
            add_option(DIDAR_SYNC_OPTION_KEY, $default_options);
        } else {
            // به‌روزرسانی mapping اگر وجود نداشته باشد
            $options = get_option(DIDAR_SYNC_OPTION_KEY);
            if (!isset($options['field_mapping'])) {
                $options['field_mapping'] = $default_options['field_mapping'];
                update_option(DIDAR_SYNC_OPTION_KEY, $options);
            }
        }
        
        // راه‌اندازی cron اگر قبلاً نباشد
        if (!wp_next_scheduled('didar_cron_sync_from_didar')) {
            wp_schedule_event(time(), $this->options['cron_frequency'] ?? 'daily', 'didar_cron_sync_from_didar');
        }
        
        // ایجاد فایل لاگ
        if (!file_exists(DIDAR_SYNC_LOG_FILE)) {
            touch(DIDAR_SYNC_LOG_FILE);
            chmod(DIDAR_SYNC_LOG_FILE, 0644);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('didar_cron_sync_from_didar');
    }

    public function admin_menu() {
        add_options_page(
            __('تنظیمات سینک دیدار', 'didar-crm'),
            __('دیدار CRM', 'didar-crm'),
            'manage_options',
            'didar-sync',
            array($this, 'settings_page')
        );
        
        // زیرمنو برای لاگ‌ها
        add_submenu_page(
            'didar-sync',
            __('لاگ‌ها', 'didar-crm'),
            __('لاگ‌ها', 'didar-crm'),
            'manage_options',
            'didar-logs',
            array($this, 'logs_page')
        );
    }

    public function admin_init() {
        // ثبت تنظیمات
        register_setting('didar_sync_group', DIDAR_SYNC_OPTION_KEY, array($this, 'sanitize_options'));
        
        // به‌روزرسانی cron frequency بعد از ذخیره
        if (isset($_POST[DIDAR_SYNC_OPTION_KEY])) {
            $new_freq = $_POST[DIDAR_SYNC_OPTION_KEY]['cron_frequency'] ?? 'daily';
            $timestamp = wp_next_scheduled('didar_cron_sync_from_didar');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'didar_cron_sync_from_didar');
                wp_schedule_event(time(), $new_freq, 'didar_cron_sync_from_didar');
            }
        }
    }

    public function sanitize_options($input) {
        $sanitized = array();
        $sanitized['api_key'] = sanitize_text_field($input['api_key'] ?? '');
        $sanitized['sync_direction'] = in_array($input['sync_direction'] ?? '', array('one_to_didar', 'one_from_didar', 'both'), true) ? $input['sync_direction'] : 'both';
        $sanitized['cron_frequency'] = in_array($input['cron_frequency'] ?? '', array('hourly', 'twicedaily', 'daily'), true) ? $input['cron_frequency'] : 'daily';
        $sanitized['log_enabled'] = isset($input['log_enabled']);
        $sanitized['auto_sync_woocommerce'] = isset($input['auto_sync_woocommerce']);
        $sanitized['field_mapping'] = array_map('sanitize_text_field', $input['field_mapping'] ?? array());
        return $sanitized;
    }

    public function settings_page() {
        // نمایش notice اگر sync شده (حالا از AJAX)
        if (isset($_GET['synced'])) {
            $message = $_GET['synced'] === 'to_didar' ? __('سینک به دیدار با موفقیت انجام شد.', 'didar-crm') : __('سینک از دیدار با موفقیت انجام شد.', 'didar-crm');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        ?>
        <div class="wrap" id="didar-sync-wrap">
            <h1><?php esc_html_e('تنظیمات کامل سینک با CRM دیدار', 'didar-crm'); ?></h1>
            
            <div class="didar-card">
                <div class="didar-tab-nav">
                    <a href="#tab-general" class="active"><?php esc_html_e('عمومی', 'didar-crm'); ?></a>
                    <a href="#tab-mapping"><?php esc_html_e('نقشه‌برداری فیلدها', 'didar-crm'); ?></a>
                    <a href="#tab-sync"><?php esc_html_e('سینک', 'didar-crm'); ?></a>
                </div>
                
                <div id="tab-general" class="didar-tab-content active">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('didar_sync_group');
                        do_settings_sections('didar_sync_group');
                        settings_errors('didar_sync_messages');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('API Key دیدار', 'didar-crm'); ?></th>
                                <td>
                                    <input type="text" name="<?php echo esc_attr(DIDAR_SYNC_OPTION_KEY); ?>[api_key]" value="<?php echo esc_attr($this->options['api_key']); ?>" class="regular-text" />
                                    <p class="description"><?php esc_html_e('کلید API را از پنل دیدار دریافت کنید: تنظیمات > اتصال به سرورهای دیگر > API Key', 'didar-crm'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('جهت سینک', 'didar-crm'); ?></th>
                                <td>
                                    <select name="<?php echo esc_attr(DIDAR_SYNC_OPTION_KEY); ?>[sync_direction]">
                                        <option value="both" <?php selected($this->options['sync_direction'], 'both'); ?>><?php esc_html_e('دوطرفه', 'didar-crm'); ?></option>
                                        <option value="one_to_didar" <?php selected($this->options['sync_direction'], 'one_to_didar'); ?>><?php esc_html_e('از WP به دیدار', 'didar-crm'); ?></option>
                                        <option value="one_from_didar" <?php selected($this->options['sync_direction'], 'one_from_didar'); ?>><?php esc_html_e('از دیدار به WP', 'didar-crm'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('فرکانس Cron', 'didar-crm'); ?></th>
                                <td>
                                    <select name="<?php echo esc_attr(DIDAR_SYNC_OPTION_KEY); ?>[cron_frequency]">
                                        <option value="hourly" <?php selected($this->options['cron_frequency'], 'hourly'); ?>><?php esc_html_e('ساعتی', 'didar-crm'); ?></option>
                                        <option value="twicedaily" <?php selected($this->options['cron_frequency'], 'twicedaily'); ?>><?php esc_html_e('دو بار در روز', 'didar-crm'); ?></option>
                                        <option value="daily" <?php selected($this->options['cron_frequency'], 'daily'); ?>><?php esc_html_e('روزانه', 'didar-crm'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('برای سینک از دیدار به وردپرس', 'didar-crm'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('فعال‌سازی لاگ', 'didar-crm'); ?></th>
                                <td>
                                    <input type="checkbox" id="log_enabled" name="<?php echo esc_attr(DIDAR_SYNC_OPTION_KEY); ?>[log_enabled]" <?php checked($this->options['log_enabled']); ?> />
                                    <label for="log_enabled"><?php esc_html_e('لاگ عملیات را در فایل ذخیره کند', 'didar-crm'); ?></label>
                                    <p class="description"><?php esc_html_e('فایل لاگ در wp-content/didar-sync.log', 'didar-crm'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('سینک خودکار ووکامرس', 'didar-crm'); ?></th>
                                <td>
                                    <input type="checkbox" id="auto_sync_woocommerce" name="<?php echo esc_attr(DIDAR_SYNC_OPTION_KEY); ?>[auto_sync_woocommerce]" <?php checked($this->options['auto_sync_woocommerce']); ?> />
                                    <label for="auto_sync_woocommerce"><?php esc_html_e('مشتریان ووکامرس را نیز سینک کند', 'didar-crm'); ?></label>
                                    <?php if (class_exists('WooCommerce')): ?>
                                        <p class="description"><?php esc_html_e('ووکامرس فعال است و هوک‌ها اضافه شده‌اند.', 'didar-crm'); ?></p>
                                    <?php else: ?>
                                        <p class="description"><?php esc_html_e('ووکامرس نصب نیست؛ این گزینه نادیده گرفته می‌شود.', 'didar-crm'); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                </div>
                
                <div id="tab-mapping" class="didar-tab-content">
                    <table class="mapping-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('فیلد WP/ووکامرس', 'didar-crm'); ?></th>
                                <th><?php esc_html_e('فیلد دیدار', 'didar-crm'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->field_mapping as $wp_key => $didar_key): ?>
                            <tr>
                                <td><?php echo esc_html($wp_key); ?></td>
                                <td><input type="text" name="<?php echo esc_attr(DIDAR_SYNC_OPTION_KEY); ?>[field_mapping][<?php echo esc_attr($wp_key); ?>]" value="<?php echo esc_attr($this->options['field_mapping'][$wp_key] ?? $didar_key); ?>" class="regular-text" /></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('فیلدهای سفارشی را بر اساس داکیومنت API تنظیم کنید. پس از تغییر، تنظیمات را ذخیره کنید.', 'didar-crm'); ?></p>
                </div>
                
                <div id="tab-sync" class="didar-tab-content">
                    <h3><?php esc_html_e('عملیات دستی سینک', 'didar-crm'); ?></h3>
                    <div class="sync-buttons">
                        <button class="sync-btn sync-btn-primary" data-direction="to_didar"><?php esc_html_e('سینک به دیدار (همه کاربران)', 'didar-crm'); ?></button>
                        <button class="sync-btn sync-btn-secondary" data-direction="from_didar"><?php esc_html_e('سینک از دیدار (همه مخاطبین)', 'didar-crm'); ?></button>
                    </div>
                    <div class="loading"></div>
                    <div class="success-notice"></div>
                    <p class="description"><?php esc_html_e('این عملیات‌ها از طریق AJAX بدون reload صفحه انجام می‌شوند.', 'didar-crm'); ?></p>
                    <p class="description"><?php esc_html_e('برای سینک تک‌تک کاربران، به لیست کاربران مراجعه کنید.', 'didar-crm'); ?></p>
                </div>
            </div>
            
            <div class="didar-card">
                <h2><?php esc_html_e('راهنما', 'didar-crm'); ?></h2>
                <p><?php esc_html_e('این پلاگین کاربران را به صورت خودکار سینک می‌کند. پنل مدیریت با تب‌ها و AJAX برای تجربه کاربری بهتر طراحی شده. برای production، WP-Cron را با server cron جایگزین کنید.', 'didar-crm'); ?></p>
            </div>
        </div>
        <?php
    }

    public function logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما مجوز لازم را ندارید.', 'didar-crm'));
        }

        if (isset($_GET['clear_logs']) && wp_verify_nonce($_GET['_wpnonce'], 'clear_didar_logs')) {
            file_put_contents(DIDAR_SYNC_LOG_FILE, '');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('لاگ‌ها پاک شد.', 'didar-crm') . '</p></div>';
        }

        if (file_exists(DIDAR_SYNC_LOG_FILE)) {
            $logs = esc_textarea(file_get_contents(DIDAR_SYNC_LOG_FILE));
        } else {
            $logs = esc_html__('فایل لاگ وجود ندارد.', 'didar-crm');
        }
        ?>
        <div class="wrap" id="didar-sync-wrap">
            <h1><?php esc_html_e('لاگ‌های سینک دیدار', 'didar-crm'); ?></h1>
            <div class="didar-card">
                <textarea style="width:100%; height:400px; font-family: monospace;" readonly><?php echo $logs; ?></textarea>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=didar-logs&clear_logs=1'), 'clear_didar_logs')); ?>" class="button button-secondary" onclick="return confirm('<?php esc_js(__('مطمئن هستید؟ لاگ‌ها پاک خواهند شد.', 'didar-crm')); ?>');"><?php esc_html_e('پاک کردن لاگ‌ها', 'didar-crm'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    private function log_message($message) {
        if (!($this->options['log_enabled'] ?? false)) {
            return;
        }
        $log_entry = sprintf(
            '[%s] %s' . PHP_EOL,
            current_time('mysql'),
            wp_strip_all_tags($message)
        );
        file_put_contents(DIDAR_SYNC_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
    }

    private function is_user_optout($user_id) {
        return get_user_meta($user_id, 'didar_optout', true);
    }

    public function sync_to_didar($user_id, $customer_id = null) {
        if (empty($this->api_key) || $this->options['sync_direction'] === 'one_from_didar' || $this->is_user_optout($user_id)) {
            if ($this->is_user_optout($user_id)) {
                $this->log_message('کاربر opt-out است: ID ' . intval($user_id));
            }
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            $this->log_message('کاربر یافت نشد: ID ' . intval($user_id));
            return;
        }

        // جمع‌آوری داده‌ها با mapping
        $contact_data = array();
        foreach ($this->options['field_mapping'] as $wp_field => $didar_field) {
            $value = '';
            switch ($wp_field) {
                case 'first_name':
                    $value = get_user_meta($user_id, 'first_name', true);
                    if (($customer_id || $this->options['auto_sync_woocommerce']) && class_exists('WooCommerce')) {
                        $value = get_user_meta($user_id, 'billing_first_name', true) ?: $value;
                    }
                    break;
                case 'last_name':
                    $value = get_user_meta($user_id, 'last_name', true);
                    if (($customer_id || $this->options['auto_sync_woocommerce']) && class_exists('WooCommerce')) {
                        $value = get_user_meta($user_id, 'billing_last_name', true) ?: $value;
                    }
                    break;
                case 'email':
                    $value = $user->user_email;
                    if (($customer_id || $this->options['auto_sync_woocommerce']) && class_exists('WooCommerce')) {
                        $value = get_user_meta($user_id, 'billing_email', true) ?: $value;
                    }
                    break;
                case 'phone':
                    $value = get_user_meta($user_id, 'billing_phone', true);
                    if (empty($value)) {
                        $value = get_user_meta($user_id, $wp_field, true);
                    }
                    break;
                case 'username':
                    $value = $user->user_login;
                    break;
                default:
                    $value = get_user_meta($user_id, $wp_field, true);
            }
            if (!empty($value)) {
                $contact_data[$didar_field] = sanitize_text_field($value);
            }
        }

        if (empty($contact_data['Email'])) { // حداقل ایمیل لازم
            $this->log_message('ایمیل موجود نیست برای سینک: کاربر ' . intval($user_id));
            return;
        }

        // ارسال به API
        $url = DIDAR_SYNC_API_BASE . 'contact/save?apikey=' . urlencode($this->api_key);
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode($contact_data),
            'timeout' => 30,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            $this->log_message('خطا در درخواست API به دیدار: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['success']) && $result['success']) {
            update_user_meta($user_id, 'didar_contact_id', absint($result['data']['Id'] ?? 0));
            $this->log_message('سینک موفق به دیدار: کاربر ' . intval($user_id) . ' -> ID ' . absint($result['data']['Id'] ?? 0));
        } else {
            $this->log_message('خطا در سینک به دیدار: ' . esc_html($body) . ' برای کاربر ' . intval($user_id));
        }
    }

    public function sync_from_didar() {
        if (empty($this->api_key) || $this->options['sync_direction'] === 'one_to_didar') {
            return;
        }

        // جستجوی همه مخاطبین با pagination
        $offset = 0;
        $limit = 100;
        $max_offset = 10000; // جلوگیری از loop بی‌نهایت
        $processed = 0;

        while ($offset < $max_offset) {
            $search_data = array(
                'Criteria' => array(),
                'From' => $offset,
                'Limit' => $limit
            );

            $url = DIDAR_SYNC_API_BASE . 'contact/search?apikey=' . urlencode($this->api_key);
            $response = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($search_data),
                'timeout' => 30,
                'sslverify' => true
            ));

            if (is_wp_error($response)) {
                $this->log_message('خطا در جستجوی مخاطبین دیدار: ' . $response->get_error_message());
                break;
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!isset($result['List']) || empty($result['List'])) {
                break;
            }

            foreach ($result['List'] as $contact) {
                $email = sanitize_email($contact['Email'] ?? '');
                if (empty($email)) {
                    continue;
                }

                $didar_id = absint($contact['Id'] ?? 0);
                $user = get_user_by('email', $email);

                if (!$user) {
                    // ایجاد کاربر جدید
                    $base_username = sanitize_user(($contact['FirstName'] ?? '') . '.' . ($contact['LastName'] ?? ''));
                    $username = $base_username;
                    $counter = 1;
                    while (username_exists($username)) {
                        $username = $base_username . $counter;
                        $counter++;
                        if ($counter > 100) {
                            $this->log_message('نمی‌توان username unique ساخت برای ایمیل: ' . $email);
                            continue 2;
                        }
                    }
                    $password = wp_generate_password(12, true);
                    $user_id = wp_create_user($username, $password, $email);
                    if (is_wp_error($user_id)) {
                        $this->log_message('خطا در ایجاد کاربر از دیدار: ' . $user_id->get_error_message() . ' برای ایمیل ' . $email);
                        continue;
                    }
                    // set role
                    $user = get_user_by('id', $user_id);
                    if (class_exists('WooCommerce') && $this->options['auto_sync_woocommerce']) {
                        $user->set_role('customer');
                    } else {
                        $user->set_role('subscriber');
                    }
                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => sanitize_text_field($contact['FirstName'] ?? ''),
                        'last_name' => sanitize_text_field($contact['LastName'] ?? '')
                    ));
                    update_user_meta($user_id, 'didar_contact_id', $didar_id);
                    update_user_meta($user_id, 'billing_phone', sanitize_text_field($contact['MobilePhone'] ?? ''));
                    // ارسال ایمیل
                    wp_new_user_notification($user_id, null, 'both');
                    $this->log_message('کاربر جدید ایجاد شد از دیدار: ID ' . $user_id . ' (ایمیل: ' . $email . ')');
                } else {
                    // به‌روزرسانی کاربر موجود - چک optout
                    if ($this->is_user_optout($user->ID)) {
                        $this->log_message('کاربر opt-out است؛ skip update از دیدار برای ID ' . $user->ID . ' (ایمیل: ' . $email . ')');
                        continue;
                    }

                    $current_didar_id = get_user_meta($user->ID, 'didar_contact_id', true);
                    if ($current_didar_id && $current_didar_id != $didar_id) {
                        $this->log_message('ID دیدار متفاوت؛ skip update برای کاربر ' . $user->ID . ' (ایمیل: ' . $email . ')');
                        continue;
                    }
                    wp_update_user(array(
                        'ID' => $user->ID,
                        'first_name' => sanitize_text_field($contact['FirstName'] ?? $user->first_name),
                        'last_name' => sanitize_text_field($contact['LastName'] ?? $user->last_name)
                    ));
                    update_user_meta($user->ID, 'billing_phone', sanitize_text_field($contact['MobilePhone'] ?? ''));
                    update_user_meta($user->ID, 'didar_contact_id', $didar_id);
                    $this->log_message('کاربر به‌روزرسانی شد از دیدار: ID ' . $user->ID . ' (ایمیل: ' . $email . ')');
                }
                $processed++;
            }

            $offset += $limit;
            if (count($result['List']) < $limit) {
                break;
            }
        }

        $this->log_message('سینک از دیدار کامل شد. تعداد پردازش‌شده: ' . $processed);
    }

    public function manual_sync_handler() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'didar_manual_sync')) {
            wp_send_json_error('Unauthorized');
        }

        $direction = sanitize_text_field($_POST['direction'] ?? '');
        if ($direction === 'to_didar') {
            $this->manual_sync_to_didar();
            wp_send_json_success(array('message' => __('تعداد کاربران سینک‌شده: ' . $this->get_user_count(), 'didar-crm')));
        } elseif ($direction === 'from_didar') {
            $this->manual_sync_from_didar();
            wp_send_json_success(array('message' => __('سینک از دیدار کامل شد.', 'didar-crm')));
        }
        wp_send_json_error('Invalid direction');
    }

    public function sync_single_user_handler() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'didar_sync_actions')) {
            wp_send_json_error('Unauthorized');
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        if ($this->is_user_optout($user_id)) {
            wp_send_json_error('کاربر opt-out است');
        }

        $this->sync_to_didar($user_id);
        $didar_id = get_user_meta($user_id, 'didar_contact_id', true);
        wp_send_json_success(array('message' => 'ID دیدار: ' . $didar_id));
    }

    private function manual_sync_to_didar() {
        $users = get_users(array('number' => 0)); // همه کاربران
        $count = 0;
        foreach ($users as $user) {
            if (!$this->is_user_optout($user->ID)) {
                $this->sync_to_didar($user->ID);
                $count++;
            }
        }
        $this->log_message('سینک دستی به دیدار: ' . $count . ' کاربر پردازش شد');
    }

    private function manual_sync_from_didar() {
        $this->sync_from_didar();
    }

    private function get_user_count() {
        global $wpdb;
        return intval($wpdb->get_var("SELECT COUNT(ID) FROM $wpdb->users"));
    }
}

// راه‌اندازی پلاگین
new DidarCRM_Complete_Sync();