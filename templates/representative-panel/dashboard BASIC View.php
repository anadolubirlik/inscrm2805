<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$user = wp_get_current_user();
if (!in_array('insurance_representative', (array)$user->roles)) {
    wp_safe_redirect(home_url());
    exit;
}

$current_user = wp_get_current_user();
$current_view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';

global $wpdb;
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}insurance_crm_representatives 
     WHERE user_id = %d AND status = 'active'",
    $current_user->ID
));

/**
 * Kullanıcının rolünü döndüren fonksiyon - doğrudan veritabanı tablosundan rol değerini kullanır
 */
function get_user_role_in_hierarchy($user_id) {
    global $wpdb;
    
    // Temsilcinin role değerini al
    $role_value = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $user_id
    ));
    
    // Role değeri yoksa varsayılan olarak temsilci döndür
    if ($role_value === null) {
        return 'representative';
    }
    
    // Role değerine göre rol adını belirle
    switch (intval($role_value)) {
        case 1:
            return 'patron';
        case 2:
            return 'manager';
        case 3:
            return 'assistant_manager';
        case 4:
            return 'team_leader';
        case 5:
        default:
            return 'representative';
    }
}

/**
 * Patron kontrolü
 */
function is_patron($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'patron';
}

/**
 * Müdür kontrolü
 */
function is_manager($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'manager';
}

/**
 * Müdür Yardımcısı kontrolü
 */
function is_assistant_manager($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'assistant_manager';
}

/**
 * Ekip lideri kontrolü
 */
function is_team_leader($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'team_leader';
}

/**
 * Ekip üyeleri listesi
 */
function get_team_members($user_id) {
    global $wpdb;
    $settings = get_option('insurance_crm_settings', []);
    $teams = $settings['teams_settings']['teams'] ?? [];
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    if (!$rep) return [];
    foreach ($teams as $team) {
        if ($team['leader_id'] == $rep->id) {
            $members = array_merge([$team['leader_id']], $team['members']);
            return array_unique($members);
        }
    }
    return [];
}

/**
 * Dashboard görünümü ve alt menüler için temsilci ID'lerini döndüren fonksiyon
 * Görünüm parametresine göre farklı davranış gösterir
 */
function get_dashboard_representatives($user_id, $current_view = 'dashboard') {
    global $wpdb;
    
    // Temsilci ID'sini al
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $user_id
    ));
    
    if (!$rep) return [];
    
    $rep_id = $rep->id;
    
    // Patron için:
    if (is_patron($user_id)) {
        // Dashboard ve ana menülerde (iletişimden anladığım bu) tüm verileri görsün
        if ($current_view == 'dashboard' || 
            $current_view == 'customers' || 
            $current_view == 'policies' ||
            $current_view == 'tasks' ||
            $current_view == 'reports') {
            return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
        } 
        // Alt menülerde belirli bir ekip gösteriliyorsa sadece o ekibi göster
        else if (strpos($current_view, 'team_') === 0 && isset($_GET['team_id'])) {
            $selected_team_id = sanitize_text_field($_GET['team_id']);
            $settings = get_option('insurance_crm_settings', []);
            $teams = $settings['teams_settings']['teams'] ?? [];
            if (isset($teams[$selected_team_id])) {
                $team_members = array_merge([$teams[$selected_team_id]['leader_id']], $teams[$selected_team_id]['members']);
                return $team_members;
            }
        }
        // Diğer tüm görünümlerde tüm temsilcileri göster
        return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
    }
    
    // Müdür için:
    if (is_manager($user_id)) {
        // Ana dashboard'da kendi verilerini görsün
        if ($current_view == 'dashboard') {
            return [$rep_id];
        }
        // Müdüre özel alt menülerde (müdür paneli, tüm ekipler vs) tüm verileri görsün
        else if ($current_view == 'manager_dashboard' || 
                $current_view == 'all_teams' || 
                $current_view == 'team_leaders' ||
                strpos($current_view, 'manager_') === 0) {
            
            return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
        }
        // Ana menülerde de tüm verileri görsün (istenildiği gibi)
        else if ($current_view == 'customers' || 
                $current_view == 'policies' ||
                $current_view == 'tasks' ||
                $current_view == 'reports') {
            
            return $wpdb->get_col("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE status = 'active'");
        }
        // Belirli ekip görünümü seçilmişse o ekibin verilerini göster
        else if (strpos($current_view, 'team_') === 0 && isset($_GET['team_id'])) {
            $selected_team_id = sanitize_text_field($_GET['team_id']);
            $settings = get_option('insurance_crm_settings', []);
            $teams = $settings['teams_settings']['teams'] ?? [];
            if (isset($teams[$selected_team_id])) {
                $team_members = array_merge([$teams[$selected_team_id]['leader_id']], $teams[$selected_team_id]['members']);
                return $team_members;
            }
        }
        // Diğer tüm durumlarda sadece kendi verilerini göster
        return [$rep_id];
    }
    
    // Ekip lideri için
    if (is_team_leader($user_id)) {
        // Dashboard ve ana menülerde sadece kendi verilerini görür
        if ($current_view == 'dashboard') {
            return [$rep_id];
        }
        // Ekip lideri menülerinde ekibinin tüm verilerini görür
        else if ($current_view == 'team' || 
                strpos($current_view, 'team_') === 0) {
            
            $team_members = get_team_members($user_id);
            return !empty($team_members) ? $team_members : [$rep_id];
        }
        // Ana menülerde (customers, policies, tasks) tüm ekip üyelerinin verilerini görsün (istenildiği gibi)
        else if ($current_view == 'customers' || 
                $current_view == 'policies' ||
                $current_view == 'tasks' ||
                $current_view == 'reports') {
                
            $team_members = get_team_members($user_id);
            return !empty($team_members) ? $team_members : [$rep_id];
        }
        // Diğer tüm durumlarda sadece kendi verilerini göster
        return [$rep_id];
    }
    
    // Normal müşteri temsilcisi sadece kendi verilerini görür
    return [$rep_id];
}

/**
 * Kullanıcının silme yetkisi olup olmadığını kontrol eder
 */
function can_delete_items($user_id) {
    return is_patron($user_id) || is_manager($user_id);
}

/**
 * Kullanıcının pasife çekme yetkisi olup olmadığını kontrol eder
 * Patron ve müdür hem silme hem pasife çekme yetkisine, 
 * Ekip lideri ve temsilciler sadece pasife çekme yetkisine sahiptir
 */
function can_deactivate_items($user_id) {
    return true; // Tüm kullanıcılar pasife çekebilir
}

/**
 * Silme işlemini loglar
 */
function log_delete_action($user_id, $item_id, $item_type) {
    global $wpdb;
    
    // Kullanıcı bilgilerini al
    $user = get_userdata($user_id);
    $user_name = $user ? $user->display_name : 'Bilinmeyen Kullanıcı';
    
    // Item türüne göre detayları al
    $item_details = '';
    if ($item_type == 'customer') {
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}insurance_crm_customers WHERE id = %d",
            $item_id
        ));
        if ($customer) {
            $item_details = $customer->first_name . ' ' . $customer->last_name;
        }
    } elseif ($item_type == 'policy') {
        $policy = $wpdb->get_row($wpdb->prepare(
            "SELECT policy_number FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d",
            $item_id
        ));
        if ($policy) {
            $item_details = $policy->policy_number;
        }
    }
    
    // Log mesajını hazırla
    $log_message = sprintf(
        'Kullanıcı %s (#%d) tarafından %s ID: %d, Detay: %s silindi.',
        $user_name,
        $user_id,
        $item_type == 'customer' ? 'müşteri' : 'poliçe',
        $item_id,
        $item_details
    );
    
    // Log tablosuna kaydet
    $wpdb->insert(
        $wpdb->prefix . 'insurance_crm_activity_logs',
        [
            'user_id' => $user_id,
            'action_type' => 'delete',
            'item_type' => $item_type,
            'item_id' => $item_id,
            'details' => $log_message,
            'created_at' => current_time('mysql')
        ]
    );
    
    // WordPress log dosyasına da yazdır
    error_log($log_message);
    
    return true;
}

/**
 * Müşteri veya poliçe için silme butonu oluşturur 
 */
function get_delete_button($item_id, $item_type, $user_id) {
    if (can_delete_items($user_id)) {
        $confirm_message = $item_type == 'customers' ? 'Bu müşteriyi silmek istediğinizden emin misiniz?' : 'Bu poliçeyi silmek istediğinizden emin misiniz?';
        
        return '<a href="' . generate_panel_url($item_type, 'delete', $item_id) . '" class="table-action" title="Sil" onclick="return confirm(\'' . $confirm_message . '\');">
                <i class="dashicons dashicons-trash"></i>
            </a>';
    }
    return '';
}

/**
 * Müşteri veya poliçe için pasife çekme butonu oluşturur 
 */
function get_deactivate_button($item_id, $item_type, $user_id, $current_status = 'active') {
    if (can_deactivate_items($user_id)) {
        $action = $current_status == 'active' ? 'deactivate' : 'activate';
        $icon = $current_status == 'active' ? 'dashicons-hidden' : 'dashicons-visibility';
        $title = $current_status == 'active' ? 'Pasife Al' : 'Aktif Et';
        $confirm_message = $current_status == 'active' ? 
            ($item_type == 'customers' ? 'Bu müşteriyi pasife almak istediğinizden emin misiniz?' : 'Bu poliçeyi pasife almak istediğinizden emin misiniz?') :
            ($item_type == 'customers' ? 'Bu müşteriyi aktif etmek istediğinizden emin misiniz?' : 'Bu poliçeyi aktif etmek istediğinizden emin misiniz?');
        
        return '<a href="' . generate_panel_url($item_type, $action, $item_id) . '" class="table-action" title="' . $title . '" onclick="return confirm(\'' . $confirm_message . '\');">
                <i class="dashicons ' . $icon . '"></i>
            </a>';
    }
    return '';
}

/**
 * Hedef performans bilgilerini getirir
 */
function get_representative_targets($rep_ids) {
    global $wpdb;
    if (empty($rep_ids)) return [];
    
    $placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));
    $targets = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.monthly_target, r.target_policy_count, u.display_name, r.title
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         WHERE r.id IN ($placeholders)",
        ...$rep_ids
    ), ARRAY_A);
    
    return $targets;
}

/**
 * Temsilcinin bu ayki performans verilerini getirir
 */
function get_representative_performance($rep_id) {
    global $wpdb;
    
    $this_month_start = date('Y-m-01 00:00:00');
    $this_month_end = date('Y-m-t 23:59:59');
    
    $total_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d",
        $rep_id
    )) ?: 0;
    
    $current_month_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
         FROM {$wpdb->prefix}insurance_crm_policies
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s",
        $rep_id, $this_month_start, $this_month_end
    )) ?: 0;
    
    $total_policy_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d AND cancellation_date IS NULL",
        $rep_id
    )) ?: 0;
    
    $current_month_policy_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        $rep_id, $this_month_start, $this_month_end
    )) ?: 0;
    
    return [
        'total_premium' => $total_premium,
        'current_month_premium' => $current_month_premium,
        'total_policy_count' => $total_policy_count,
        'current_month_policy_count' => $current_month_policy_count
    ];
}

/**
 * Organizasyon bağlantıları için URL oluşturur
 */
function get_organization_link($type, $params = []) {
    $url = home_url('/representative-panel/');
    if ($type === 'hierarchy') {
        $url = add_query_arg(['view' => 'boss_settings'], $url);
    } elseif ($type === 'teams') {
        $url = add_query_arg(['view' => 'team_add'], $url);
    } elseif ($type === 'personnel') {
        $url = add_query_arg(['view' => 'all_personnel'], $url);
    }
    
    if (!empty($params)) {
        $url = add_query_arg($params, $url);
    }
    
    return $url;
}

/**
 * Ekip Detay sayfası için fonksiyon
 */
function generate_team_detail_url($team_id) {
    return generate_panel_url('team_detail', '', '', array('team_id' => $team_id));
}

/**
 * Panel URL'si oluşturma
 */
function generate_panel_url($view, $action = '', $id = '', $additional_params = array()) {
    $url = home_url('/representative-panel/');
    $params = array('view' => $view);
    
    if (!empty($action)) {
        $params['action'] = $action;
    }
    
    if (!empty($id)) {
        $params['id'] = $id;
    }
    
    if (!empty($additional_params) && is_array($additional_params)) {
        $params = array_merge($params, $additional_params);
    }
    
    $url = add_query_arg($params, $url);
    
    return $url;
}

// Activity Log tablosunu oluştur (eğer yoksa)
function create_activity_log_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_activity_log';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            username varchar(100) NOT NULL,
            action_type varchar(50) NOT NULL,
            action_details text NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Gerekli script'leri ekle
function insurance_crm_rep_panel_scripts() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1', true);
}

// Kullanıcının rolünü belirle
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Dashboard görünümü ve menülere göre yetkili temsilci ID'lerini al
$rep_ids = get_dashboard_representatives($current_user->ID, $current_view);

// Ekip hedefi hesaplama
$team_target = 0;
$team_policy_target = 0;

if ($current_view === 'team' || strpos($current_view, 'team_') === 0 || $user_role == 'patron' || $user_role == 'manager') {
    $placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));
    $targets = $wpdb->get_results($wpdb->prepare(
        "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE id IN ($placeholders)",
        ...$rep_ids
    ));
    
    foreach ($targets as $target) {
        $team_target += floatval($target->monthly_target);
        $team_policy_target += intval($target->target_policy_count);
    }
} else {
    $team_target = $representative->monthly_target;
    $team_policy_target = $representative->target_policy_count;
}

// Üye performans verileri
$member_performance = [];

if ($current_view === 'team' || $user_role == 'patron' || $user_role == 'manager') {
    foreach ($rep_ids as $rep_id) {
        $member_data = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id, u.display_name, r.title, r.monthly_target, r.target_policy_count 
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id = %d",
            $rep_id
        ));
        
        if ($member_data) {
            $customers = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
                 WHERE representative_id = %d",
                $rep_id
            ));
            
            $policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d AND cancellation_date IS NULL",
                $rep_id
            ));
            
            $premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d",
                $rep_id
            ));
            
            // Bu ay eklenen poliçe ve prim
            $this_month_start = date('Y-m-01 00:00:00');
            $this_month_end = date('Y-m-t 23:59:59');
            
            $this_month_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d 
                 AND start_date BETWEEN %s AND %s
                 AND cancellation_date IS NULL",
                $rep_id, $this_month_start, $this_month_end
            ));
            
            $this_month_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id = %d 
                 AND start_date BETWEEN %s AND %s",
                $rep_id, $this_month_start, $this_month_end
            ));
            
            // Hedefe uzaklık hesaplama
            $premium_achievement_rate = $member_data->monthly_target > 0 ? ($this_month_premium / $member_data->monthly_target) * 100 : 0;
            $policy_achievement_rate = $member_data->target_policy_count > 0 ? ($this_month_policies / $member_data->target_policy_count) * 100 : 0;
            
            $member_performance[] = [
                'id' => $member_data->id,
                'name' => $member_data->display_name,
                'title' => $member_data->title,
                'customers' => $customers,
                'policies' => $policies,
                'premium' => $premium,
                'this_month_policies' => $this_month_policies,
                'this_month_premium' => $this_month_premium,
                'monthly_target' => $member_data->monthly_target,
                'target_policy_count' => $member_data->target_policy_count,
                'premium_achievement_rate' => $premium_achievement_rate,
                'policy_achievement_rate' => $policy_achievement_rate
            ];
        }
    }
}

// Performans verilerini sıralama
if (!empty($member_performance)) {
    // Premium'a göre sıralama (en yüksekten en düşüğe)
    usort($member_performance, function($a, $b) {
        return $b['premium'] <=> $a['premium'];
    });
}

// Mevcut sorguları ekip için uyarla
$placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));

$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN ($placeholders)",
    ...$rep_ids
));

$this_month_start = date('Y-m-01 00:00:00');
$this_month_end = date('Y-m-t 23:59:59');

$new_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN ($placeholders) 
     AND created_at BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));

$new_customers = $new_customers ?: 0;
$customer_increase_rate = $total_customers > 0 ? ($new_customers / $total_customers) * 100 : 0;

$total_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN ($placeholders)
     AND cancellation_date IS NULL",
    ...$rep_ids
));

$new_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN ($placeholders) 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$new_policies = $new_policies ?: 0;

$this_month_cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN ($placeholders) 
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$this_month_cancelled_policies = $this_month_cancelled_policies ?: 0;

$policy_increase_rate = $total_policies > 0 ? ($new_policies / $total_policies) * 100 : 0;

$total_refunded_amount = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) 
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN ($placeholders)",
    ...$rep_ids
));
$total_refunded_amount = $total_refunded_amount ?: 0;

$total_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN ($placeholders)",
    ...$rep_ids
));
if ($total_premium === null) $total_premium = 0;

$new_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN ($placeholders) 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$new_premium = $new_premium ?: 0;
$premium_increase_rate = $total_premium > 0 ? ($new_premium / $total_premium) * 100 : 0;

$current_month = date('Y-m');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$current_month_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies
     WHERE representative_id IN ($placeholders) 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $current_month_end . ' 23:59:59'])
));

if ($current_month_premium === null) $current_month_premium = 0;

$monthly_target = $team_target > 0 ? $team_target : 1;
$achievement_rate = ($current_month_premium / $monthly_target) * 100;
$achievement_rate = min(100, $achievement_rate);

// Poliçe hedef gerçekleşme oranı
$policy_achievement_rate = ($team_policy_target > 0 && $new_policies > 0) ? 
    ($new_policies / $team_policy_target) * 100 : 0;
$policy_achievement_rate = min(100, $policy_achievement_rate);

// Son eklenen poliçeleri al
$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, c.gender
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN ($placeholders)
     AND p.cancellation_date IS NULL
     ORDER BY p.created_at DESC
     LIMIT 5",
    ...$rep_ids
));

// Aylık üretim verilerini al
$monthly_production_data = [];
$monthly_refunded_data = [];

// Son 6 ay için veriler
for ($i = 5; $i >= 0; $i--) {
    $month_year = date('Y-m', strtotime("-$i months"));
    $month_start = date('Y-m-01 00:00:00', strtotime("-$i months"));
    $month_end = date('Y-m-t 23:59:59', strtotime("-$i months"));
    
    // Bu ay eklenen poliçelerin toplam primi
    $month_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0)
         FROM {$wpdb->prefix}insurance_crm_policies
         WHERE representative_id IN ($placeholders) 
         AND start_date BETWEEN %s AND %s",
        ...array_merge($rep_ids, [$month_start, $month_end])
    ));
    $monthly_production_data[$month_year] = $month_premium ?: 0;
    
    // Bu ay iade edilen primler
    $month_refunded = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(refunded_amount), 0)
         FROM {$wpdb->prefix}insurance_crm_policies
         WHERE representative_id IN ($placeholders) 
         AND start_date BETWEEN %s AND %s",
        ...array_merge($rep_ids, [$month_start, $month_end])
    ));
    $monthly_refunded_data[$month_year] = $month_refunded ?: 0;
}

// Yaklaşan yenilemeleri al
$today = date('Y-m-d');
$days30 = date('Y-m-d', strtotime('+30 days'));

$upcoming_renewals = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, c.gender
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN ($placeholders)
     AND p.end_date BETWEEN %s AND %s
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date ASC
     LIMIT 5",
    ...array_merge($rep_ids, [$today, $days30])
));

// Süresi geçmiş poliçeleri al
$expired_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, c.gender
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN ($placeholders)
     AND p.end_date < %s
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date DESC
     LIMIT 5",
    ...array_merge($rep_ids, [$today])
));

// Aylık üretimi grafiğe uygun formata dönüştür
$monthly_production = [];
foreach ($monthly_production_data as $month_year => $total) {
    $monthly_production[] = [
        'month' => $month_year,
        'total' => $total
    ];
}

// En çok üretim yapanlar (bu ay)
$top_producers = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $top_producers = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, u.display_name, r.title, 
                COALESCE(SUM(p.premium_amount), 0) - COALESCE(SUM(p.refunded_amount), 0) as total_premium
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id 
            AND p.start_date BETWEEN %s AND %s
         WHERE r.status = 'active'
         GROUP BY r.id
         ORDER BY total_premium DESC
         LIMIT 3",
        $this_month_start, $this_month_end
    ));
}

// En çok yeni iş ekleyenler (bu ay)
$top_new_business = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $top_new_business = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, u.display_name, r.title, 
                COUNT(p.id) as policy_count
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id 
            AND p.start_date BETWEEN %s AND %s
            AND p.cancellation_date IS NULL
         WHERE r.status = 'active'
         GROUP BY r.id
         ORDER BY policy_count DESC
         LIMIT 3",
        $this_month_start, $this_month_end
    ));
}

// En çok yeni müşteri ekleyenler (bu ay)
$top_new_customers = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $top_new_customers = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, u.display_name, r.title, 
                COUNT(c.id) as customer_count
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON r.id = c.representative_id 
            AND c.created_at BETWEEN %s AND %s
         WHERE r.status = 'active'
         GROUP BY r.id
         ORDER BY customer_count DESC
         LIMIT 3",
        $this_month_start, $this_month_end
    ));
}

// En çok iptali olanlar (bu ay)
$top_cancellations = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $top_cancellations = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, u.display_name, r.title, 
                COUNT(p.id) as cancelled_count,
                COALESCE(SUM(p.refunded_amount), 0) as refunded_amount
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON r.id = p.representative_id 
            AND p.cancellation_date BETWEEN %s AND %s
         WHERE r.status = 'active'
         GROUP BY r.id
         ORDER BY cancelled_count DESC
         LIMIT 3",
        $this_month_start, $this_month_end
    ));
}
?>

<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>
            <?php 
            echo get_bloginfo('name') . ' - ';
            if ($current_view == 'dashboard') {
                echo 'Dashboard';
            } elseif ($current_view == 'customers') {
                echo 'Müşterilerim';
            } elseif ($current_view == 'team_customers') {
                echo 'Ekip Müşterileri';
            } elseif ($current_view == 'policies') {
                echo 'Poliçelerim';
            } elseif ($current_view == 'team_policies') {
                echo 'Ekip Poliçeleri';
            } else {
                echo ucfirst($current_view);
            }
            ?>
        </title>
        <?php wp_head(); ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    </head>
    <body class="insurance-crm-page">
        <div class="insurance-crm-sidenav">
            <div class="sidenav-header">
                <div class="sidenav-logo">
                    <img src="<?php echo plugins_url('/assets/images/logo.png', dirname(dirname(__FILE__))); ?>" alt="Logo">
                </div>
                <h3>Sigorta CRM</h3>
            </div>
            
            <div class="sidenav-user">
                <div class="user-avatar">
                    <?php
                    if ($representative && !empty($representative->avatar_url)) {
                        echo '<img src="' . esc_url($representative->avatar_url) . '" alt="Avatar">';
                    } else {
                        echo '<i class="fas fa-user"></i>';
                    }
                    ?>
                </div>
                <div class="user-info">
                    <h4><?php echo esc_html($current_user->display_name); ?></h4>
                    <span>
                        <?php 
                        if ($representative) {
                            echo !empty($representative->title) ? esc_html($representative->title) : '';
                            
                            $role_class = '';
                            $role_name = '';
                            
                            switch ($user_role) {
                                case 'patron':
                                    $role_class = 'patron-role';
                                    $role_name = 'Patron';
                                    break;
                                case 'manager':
                                    $role_class = 'manager-role';
                                    $role_name = 'Müdür';
                                    break;
                                case 'assistant_manager':
                                    $role_class = 'manager-role';
                                    $role_name = 'Müdür Yrd.';
                                    break;
                                case 'team_leader':
                                    $role_class = 'leader-role';
                                    $role_name = 'Ekip Lideri';
                                    break;
                                default:
                                    $role_class = 'rep-role';
                                    $role_name = 'Temsilci';
                            }
                            
                            echo '<br><span class="user-role ' . $role_class . '">' . $role_name . '</span>';
                        }
                        ?>
                    </span>
                </div>
            </div>
            
            <div class="sidenav-menu">
                <a href="<?php echo home_url('/representative-panel/?view=dashboard'); ?>" class="<?php echo $current_view == 'dashboard' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-dashboard"></i>
                    <span>Dashboard</span>
                </a>
                
                <a href="<?php echo home_url('/representative-panel/?view=customers'); ?>" class="<?php echo $current_view == 'customers' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-groups"></i>
                    <span>Müşterilerim</span>
                </a>
                
                <a href="<?php echo home_url('/representative-panel/?view=policies'); ?>" class="<?php echo $current_view == 'policies' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-portfolio"></i>
                    <span>Poliçelerim</span>
                </a>
                
                <a href="<?php echo home_url('/representative-panel/?view=tasks'); ?>" class="<?php echo $current_view == 'tasks' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-calendar-alt"></i>
                    <span>Görevlerim</span>
                </a>
                
                <a href="<?php echo home_url('/representative-panel/?view=reports'); ?>" class="<?php echo $current_view == 'reports' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-chart-bar"></i>
                    <span>Raporlar</span>
                </a>
                
                <?php if (is_team_leader($current_user->ID)): ?>
                <div class="sidenav-submenu">
                    <a href="#" class="submenu-toggle">
                        <i class="dashicons dashicons-admin-users"></i>
                        <span>Ekip Yönetimi</span>
                    </a>
                    <div class="submenu-items">
                        <a href="<?php echo home_url('/representative-panel/?view=team'); ?>" class="<?php echo $current_view == 'team' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-groups"></i>
                            <span>Ekip Görünümü</span>
                        </a>
                        <a href="<?php echo home_url('/representative-panel/?view=team_customers'); ?>" class="<?php echo $current_view == 'team_customers' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-businessperson"></i>
                            <span>Ekip Müşterileri</span>
                        </a>
                        <a href="<?php echo home_url('/representative-panel/?view=team_policies'); ?>" class="<?php echo $current_view == 'team_policies' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-portfolio"></i>
                            <span>Ekip Poliçeleri</span>
                        </a>
                        <a href="<?php echo home_url('/representative-panel/?view=team_tasks'); ?>" class="<?php echo $current_view == 'team_tasks' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <span>Ekip Görevleri</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (is_patron($current_user->ID)): ?>
                <div class="sidenav-submenu">
                    <a href="#" class="submenu-toggle">
                        <i class="dashicons dashicons-businessman"></i>
                        <span>Organizasyon</span>
                    </a>
                    <div class="submenu-items">
                        <a href="<?php echo home_url('/representative-panel/?view=all_personnel'); ?>" class="<?php echo $current_view == 'all_personnel' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-groups"></i>
                            <span>Tüm Personel</span>
                        </a>
                        <a href="<?php echo home_url('/representative-panel/?view=representative_add'); ?>" class="<?php echo $current_view == 'representative_add' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-admin-users"></i>
                            <span>Yeni Temsilci Ekle</span>
                        </a>
                        <a href="<?php echo home_url('/representative-panel/?view=team_add'); ?>" class="<?php echo $current_view == 'team_add' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-networking"></i>
                            <span>Yeni Ekip Oluştur</span>
                        </a>
                        <a href="<?php echo home_url('/representative-panel/?view=boss_settings'); ?>" class="<?php echo $current_view == 'boss_settings' ? 'active' : ''; ?>">
                            <i class="dashicons dashicons-admin-generic"></i>
                            <span>Yönetim Ayarları</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <a href="<?php echo home_url('/representative-panel/?view=settings'); ?>" class="<?php echo $current_view == 'settings' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-admin-settings"></i>
                    <span>Ayarlarım</span>
                </a>
            </div>
            
            <div class="sidenav-footer">
                <a href="<?php echo wp_logout_url(home_url('/temsilci-girisi/')); ?>" class="logout-button">
                    <i class="dashicons dashicons-exit"></i>
                    <span>Çıkış Yap</span>
                </a>
            </div>
        </div>
        
        <div class="insurance-crm-main">
            <div class="main-header">
                <div class="header-left">
                    <button id="sidenav-toggle">
                        <i class="dashicons dashicons-menu-alt"></i>
                    </button>
                    <h2>
                        <?php 
                        if ($current_view == 'dashboard') {
                            echo 'Dashboard';
                        } elseif ($current_view == 'customers') {
                            echo 'Müşterilerim';
                        } elseif ($current_view == 'team_customers') {
                            echo 'Ekip Müşterileri';
                        } elseif ($current_view == 'policies') {
                            echo 'Poliçelerim';
                        } elseif ($current_view == 'search') {
                            echo 'Arama Sonuçları';
                        } elseif ($current_view == 'representative_add') {
                            echo 'Yeni Temsilci Ekle';
                        } elseif ($current_view == 'team_add') {
                            echo 'Yeni Ekip Oluştur';
                        } elseif ($current_view == 'boss_settings') {
                            echo 'Yönetim Ayarları';
                        } elseif ($current_view == 'all_personnel') {
                            echo 'Tüm Personel';
                        } else {
                            echo ucfirst($current_view);
                        }
                        ?>
                    </h2>
                </div>
                <div class="header-right">
                    <div class="search-box">
                        <form method="get" action="<?php echo home_url('/representative-panel/'); ?>">
                            <input type="hidden" name="view" value="search">
                            <input type="text" name="keyword" placeholder="Müşteri Ara, Poliçe Ara...">
                            <i class="dashicons dashicons-search"></i>
                        </form>
                    </div>
                    <div class="notification-bell">
                        <a href="#" id="notifications-toggle">
                            <i class="dashicons dashicons-bell"></i>
                            <?php 
                            $notification_count = 5; // Örnek sayı, gerçek işlevselliğe göre hesaplanmalı
                            if ($notification_count > 0): 
                            ?>
                            <div class="notification-badge"><?php echo $notification_count; ?></div>
                            <?php endif; ?>
                        </a>
                        
                        <div class="notifications-dropdown">
                            <div class="notifications-header">
                                <h3>Bildirimler</h3>
                                <a href="#" class="mark-all-read">Tümünü Okundu Say</a>
                            </div>
                            <div class="notifications-list">
                                <div class="notification-item unread">
                                    <i class="dashicons dashicons-calendar"></i>
                                    <div class="notification-content">
                                        <p><strong>5 poliçe</strong> yakında sona eriyor.</p>
                                        <span class="notification-time">2 saat önce</span>
                                    </div>
                                </div>
                                <div class="notification-item">
                                    <i class="dashicons dashicons-businessman"></i>
                                    <div class="notification-content">
                                        <p><strong>Ahmet Yılmaz</strong> isimli yeni müşteri eklendi.</p>
                                        <span class="notification-time">1 gün önce</span>
                                    </div>
                                </div>
                                <div class="notification-item unread">
                                    <i class="dashicons dashicons-clipboard"></i>
                                    <div class="notification-content">
                                        <p><strong>3 görev</strong> zamanı geçmiş durumda.</p>
                                        <span class="notification-time">Dün</span>
                                    </div>
                                </div>
                                <div class="notification-item">
                                    <i class="dashicons dashicons-yes-alt"></i>
                                    <div class="notification-content">
                                        <p><strong>Kasko Poliçesi #12458</strong> onaylandı.</p>
                                        <span class="notification-time">2 gün önce</span>
                                    </div>
                                </div>
                                <div class="notification-item">
                                    <i class="dashicons dashicons-warning"></i>
                                    <div class="notification-content">
                                        <p><strong>Sağlık Poliçesi #578</strong> iptal edildi.</p>
                                        <span class="notification-time">1 hafta önce</span>
                                    </div>
                                </div>
                            </div>
                            <div class="notifications-footer">
                                <a href="<?php echo home_url('/representative-panel/?view=notifications'); ?>">Tüm Bildirimleri Gör</a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="quick-add">
                        <a href="#" id="quick-add-toggle" class="quick-add-btn">
                            <i class="dashicons dashicons-plus-alt"></i>
                            <span>Hızlı Ekle</span>
                        </a>
                        
                        <div class="quick-add-dropdown">
                            <a href="<?php echo generate_panel_url('customers', 'new'); ?>">
                                <i class="dashicons dashicons-admin-users"></i>
                                <span>Müşteri Ekle</span>
                            </a>
                            <a href="<?php echo generate_panel_url('policies', 'new'); ?>">
                                <i class="dashicons dashicons-portfolio"></i>
                                <span>Poliçe Ekle</span>
                            </a>
                            <a href="<?php echo generate_panel_url('tasks', 'new'); ?>">
                                <i class="dashicons dashicons-calendar-alt"></i>
                                <span>Görev Ekle</span>
                            </a>
                            <?php if (is_patron($current_user->ID) || is_manager($current_user->ID)): ?>
                            <a href="<?php echo generate_panel_url('representative_add'); ?>">
                                <i class="dashicons dashicons-businessman"></i>
                                <span>Temsilci Ekle</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="user-menu">
                        <div class="user-avatar-small">
                            <?php
                            if ($representative && !empty($representative->avatar_url)) {
                                echo '<img src="' . esc_url($representative->avatar_url) . '" alt="User Avatar">';
                            } else {
                                echo '<i class="dashicons dashicons-admin-users"></i>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="main-content">
                <?php 
                // View dosyasını dahil et
                if ($current_view === 'dashboard') {
                    // Ana dashboard içeriği
                ?>
                <div class="dashboard-grid">
                    <?php if (is_patron($current_user->ID)): ?>
                    <!-- Patron-özel Dashboard -->
                    <div class="upper-section">
                        <div class="dashboard-card chart-card">
                            <div class="card-header">
                                <h3><i class="fas fa-chart-line"></i> Üretim Özeti</h3>
                                <div class="card-actions">
                                    <a href="<?php echo home_url('/representative-panel/?view=reports'); ?>" class="text-button">Detaylı Raporlar</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="productionChart" height="250"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dashboard-card calendar-card">
                            <div class="card-header">
                                <h3><i class="fas fa-calendar-alt"></i> Yaklaşan Görevler</h3>
                                <div class="card-actions">
                                    <a href="<?php echo home_url('/representative-panel/?view=tasks'); ?>" class="text-button">Tüm Görevler</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="task-summary">
                                    <?php 
                                    // Görev özet modülünü dahil et
                                    include_once(dirname(dirname(__FILE__)) . '/modules/task-management/task-summary-widget.php');
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo number_format($total_customers); ?></h3>
                                <p>Toplam Müşteri</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend up">
                                    <i class="fas fa-caret-up"></i> 
                                    <?php echo number_format($new_customers); ?> yeni müşteri bu ay
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo number_format($total_policies); ?></h3>
                                <p>Toplam Poliçe</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend up">
                                    <i class="fas fa-caret-up"></i>
                                    <?php echo number_format($new_policies); ?> yeni poliçe bu ay
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon bg-info">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-content">
                                <h3>₺<?php echo number_format($total_premium, 2, ',', '.'); ?></h3>
                                <p>Toplam Prim</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend up">
                                    <i class="fas fa-caret-up"></i>
                                    ₺<?php echo number_format($new_premium, 2, ',', '.'); ?> bu ay
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div class="stat-content">
                                <h3>%<?php echo number_format($achievement_rate, 1); ?></h3>
                                <p>Hedef Gerçekleşme</p>
                            </div>
                            <div class="stat-footer">
                                <div class="progress-bar">
                                    <div class="progress" style="width: <?php echo min(100, $achievement_rate); ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="org-overview">
                        <div class="section-header">
                            <h3>Organizasyon Yönetimi</h3>
                        </div>
                        <div class="org-buttons">
                            <a href="<?php echo get_organization_link('personnel'); ?>" class="org-button">
                                <div class="org-button-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="org-button-content">
                                    <h4>Tüm Personel</h4>
                                    <p>Personel listesi ve detaylar</p>
                                </div>
                            </a>
                            
                            <a href="<?php echo get_organization_link('teams'); ?>" class="org-button">
                                <div class="org-button-icon">
                                    <i class="fas fa-sitemap"></i>
                                </div>
                                <div class="org-button-content">
                                    <h4>Ekipleri Düzenle</h4>
                                    <p>Ekip yapılandırması</p>
                                </div>
                            </a>
                            
                            <a href="<?php echo get_organization_link('hierarchy'); ?>" class="org-button">
                                <div class="org-button-icon">
                                    <i class="fas fa-project-diagram"></i>
                                </div>
                                <div class="org-button-content">
                                    <h4>Yönetim Hiyerarşisini Düzenle</h4>
                                    <p>Detaylı organizasyon ayarları</p>
                                </div>
                            </a>
                        </div>
                    </div>
                    
                    <div class="two-column-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-trophy"></i> En Çok Üretim Yapan</h3>
                                <div class="card-actions">
                                    <a href="<?php echo home_url('/representative-panel/?view=reports&report_type=performance'); ?>" class="text-button">Tüm Sıralama</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($top_producers)): ?>
                                <div class="leaderboard">
                                    <?php 
                                    foreach ($top_producers as $index => $producer):
                                        $rank_class = $index === 0 ? 'gold-rank' : ($index === 1 ? 'silver-rank' : 'bronze-rank');
                                    ?>
                                    <div class="leaderboard-item">
                                        <div class="rank <?php echo $rank_class; ?>"><?php echo $index + 1; ?></div>
                                        <div class="leaderboard-info">
                                            <h4><?php echo esc_html($producer->display_name); ?></h4>
                                            <p><?php echo esc_html($producer->title ?: 'Müşteri Temsilcisi'); ?></p>
                                        </div>
                                        <div class="leaderboard-value">
                                            ₺<?php echo number_format($producer->total_premium, 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-chart-line"></i></div>
                                    <p>Bu ay henüz üretim yapılmamış.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-file-medical"></i> En Çok Yeni İş</h3>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($top_new_business)): ?>
                                <div class="leaderboard">
                                    <?php 
                                    foreach ($top_new_business as $index => $producer):
                                        $rank_class = $index === 0 ? 'gold-rank' : ($index === 1 ? 'silver-rank' : 'bronze-rank');
                                    ?>
                                    <div class="leaderboard-item">
                                        <div class="rank <?php echo $rank_class; ?>"><?php echo $index + 1; ?></div>
                                        <div class="leaderboard-info">
                                            <h4><?php echo esc_html($producer->display_name); ?></h4>
                                            <p><?php echo esc_html($producer->title ?: 'Müşteri Temsilcisi'); ?></p>
                                        </div>
                                        <div class="leaderboard-value">
                                            <?php echo $producer->policy_count; ?> Poliçe
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-clipboard"></i></div>
                                    <p>Bu ay henüz yeni iş kaydı yapılmamış.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="two-column-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-user-plus"></i> En Çok Yeni Müşteri</h3>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($top_new_customers)): ?>
                                <div class="leaderboard">
                                    <?php 
                                    foreach ($top_new_customers as $index => $producer):
                                        $rank_class = $index === 0 ? 'gold-rank' : ($index === 1 ? 'silver-rank' : 'bronze-rank');
                                    ?>
                                    <div class="leaderboard-item">
                                        <div class="rank <?php echo $rank_class; ?>"><?php echo $index + 1; ?></div>
                                        <div class="leaderboard-info">
                                            <h4><?php echo esc_html($producer->display_name); ?></h4>
                                            <p><?php echo esc_html($producer->title ?: 'Müşteri Temsilcisi'); ?></p>
                                        </div>
                                        <div class="leaderboard-value">
                                            <?php echo $producer->customer_count; ?> Müşteri
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-groups"></i></div>
                                    <p>Bu ay henüz yeni müşteri kaydı yapılmamış.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-ban"></i> En Çok İptali Olan</h3>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($top_cancellations)): ?>
                                <div class="leaderboard">
                                    <?php 
                                    foreach ($top_cancellations as $index => $producer):
                                        $rank_class = $index === 0 ? 'rank-warning' : '';
                                    ?>
                                    <div class="leaderboard-item">
                                        <div class="rank <?php echo $rank_class; ?>"><?php echo $index + 1; ?></div>
                                        <div class="leaderboard-info">
                                            <h4><?php echo esc_html($producer->display_name); ?></h4>
                                            <p><?php echo esc_html($producer->title ?: 'Müşteri Temsilcisi'); ?></p>
                                        </div>
                                        <div class="leaderboard-value">
                                            <?php echo $producer->cancelled_count; ?> İptal (₺<?php echo number_format($producer->refunded_amount, 0, ',', '.'); ?>)
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-yes-alt"></i></div>
                                    <p>Bu ay hiç iptal olan poliçe yok.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="two-column-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-clipboard-list"></i> Son Eklenen Poliçeler</h3>
                                <div class="card-actions">
                                    <a href="<?php echo home_url('/representative-panel/?view=policies'); ?>" class="text-button">Tümünü Gör</a>
                                </div>
                            </div>
                            <div class="card-body table-responsive">
                                <?php if (!empty($recent_policies)): ?>
                                <table class="dashboard-table">
                                    <thead>
                                        <tr>
                                            <th>Müşteri</th>
                                            <th>Poliçe No</th>
                                            <th>Tür</th>
                                            <th>Prim</th>
                                            <th>Durum</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_policies as $policy): ?>
                                        <tr>
                                            <td>
                                                <div class="customer-info">
                                                    <span class="gender-icon <?php echo $policy->gender === 'male' ? 'gender-male' : 'gender-female'; ?>">
                                                        <i class="fas fa-<?php echo $policy->gender === 'male' ? 'male' : 'female'; ?>"></i>
                                                    </span>
                                                    <a href="<?php echo generate_panel_url('customers', 'view', $policy->customer_id); ?>">
                                                        <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html($policy->policy_number); ?></td>
                                            <td><?php echo esc_html($policy->policy_type); ?></td>
                                            <td class="amount-cell">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td>
                                                <span class="status-badge status-active">Aktif</span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="table-action" title="Düzenle">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-clipboard"></i></div>
                                    <h4>Henüz Poliçe Yok</h4>
                                    <p>Yeni bir poliçe kaydı oluşturun</p>
                                    <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="btn btn-primary">
                                        <i class="fas fa-plus"></i> Poliçe Ekle
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-calendar-alt"></i> Yaklaşan Yenilemeler</h3>
                                <div class="card-actions">
                                    <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'renewal')); ?>" class="text-button">Tümünü Gör</a>
                                </div>
                            </div>
                            <div class="card-body table-responsive">
                                <?php if (!empty($upcoming_renewals)): ?>
                                <table class="dashboard-table">
                                    <thead>
                                        <tr>
                                            <th>Müşteri</th>
                                            <th>Poliçe No</th>
                                            <th>Tür</th>
                                            <th>Bitiş Tarihi</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_renewals as $policy): ?>
                                        <tr>
                                            <td>
                                                <div class="customer-info">
                                                    <span class="gender-icon <?php echo $policy->gender === 'male' ? 'gender-male' : 'gender-female'; ?>">
                                                        <i class="fas fa-<?php echo $policy->gender === 'male' ? 'male' : 'female'; ?>"></i>
                                                    </span>
                                                    <a href="<?php echo generate_panel_url('customers', 'view', $policy->customer_id); ?>">
                                                        <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html($policy->policy_number); ?></td>
                                            <td><?php echo esc_html($policy->policy_type); ?></td>
                                            <td>
                                                <?php 
                                                $end_date = new DateTime($policy->end_date);
                                                $now = new DateTime();
                                                $days_left = $now->diff($end_date)->days;
                                                $date_class = $days_left <= 7 ? 'date-urgent' : 'date-normal';
                                                echo '<span class="' . $date_class . '">' . $end_date->format('d.m.Y') . '</span>'; 
                                                echo '<div class="days-left">' . $days_left . ' gün kaldı</div>';
                                                ?>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="table-action" title="Yenile">
                                                        <i class="dashicons dashicons-update"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-yes-alt"></i></div>
                                    <h4>Yaklaşan Yenileme Yok</h4>
                                    <p>Önümüzdeki 30 gün içinde yenilenecek poliçe bulunmuyor.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Müşteri Temsilcisi Dashboard -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo number_format($total_customers); ?></h3>
                                <p>Toplam Müşteri</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend up">
                                    <i class="fas fa-caret-up"></i> 
                                    <?php echo number_format($new_customers); ?> yeni müşteri bu ay
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo number_format($total_policies); ?></h3>
                                <p>Aktif Poliçe</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend <?php echo $this_month_cancelled_policies > 0 ? 'down' : 'neutral'; ?>">
                                    <?php if ($this_month_cancelled_policies > 0): ?>
                                        <i class="fas fa-caret-down"></i> <?php echo number_format($this_month_cancelled_policies); ?> iptal
                                    <?php else: ?>
                                        <i class="fas fa-check"></i> İptal yok
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon bg-info">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-content">
                                <h3>₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></h3>
                                <p>Bu Ay Üretim</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend">
                                    Hedefin <?php echo number_format($achievement_rate, 1); ?>%
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo number_format($new_policies); ?></h3>
                                <p>Bu Ay Yeni Poliçe</p>
                            </div>
                            <div class="stat-footer">
                                <div class="trend">
                                    Hedefin <?php echo number_format($policy_achievement_rate, 1); ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tasks-summary-grid">
                        <div class="task-summary-card today">
                            <div class="task-summary-icon">
                                <i class="dashicons dashicons-calendar-alt"></i>
                            </div>
                            <div class="task-summary-content">
                                <h3><?php echo isset($today_tasks) ? $today_tasks : 0; ?></h3>
                                <p>Bugünkü Görev</p>
                            </div>
                            <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'today')); ?>" class="task-summary-link">Görüntüle</a>
                        </div>
                        
                        <div class="task-summary-card tomorrow">
                            <div class="task-summary-icon">
                                <i class="dashicons dashicons-calendar"></i>
                            </div>
                            <div class="task-summary-content">
                                <h3><?php echo isset($tomorrow_tasks) ? $tomorrow_tasks : 0; ?></h3>
                                <p>Yarınki Görev</p>
                            </div>
                            <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'tomorrow')); ?>" class="task-summary-link">Görüntüle</a>
                        </div>
                        
                        <div class="task-summary-card this-week">
                            <div class="task-summary-icon">
                                <i class="dashicons dashicons-calendar-alt"></i>
                            </div>
                            <div class="task-summary-content">
                                <h3><?php echo isset($week_tasks) ? $week_tasks : 0; ?></h3>
                                <p>Bu Hafta Görev</p>
                            </div>
                            <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'this_week')); ?>" class="task-summary-link">Görüntüle</a>
                        </div>
                        
                        <div class="task-summary-card this-month">
                            <div class="task-summary-icon">
                                <i class="dashicons dashicons-calendar"></i>
                            </div>
                            <div class="task-summary-content">
                                <h3><?php echo isset($month_tasks) ? $month_tasks : 0; ?></h3>
                                <p>Bu Ay Görev</p>
                            </div>
                            <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'this_month')); ?>" class="task-summary-link">Görüntüle</a>
                        </div>
                    </div>
                    
                    <div class="two-column-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-chart-line"></i> Aylık Üretim</h3>
                                <div class="card-actions">
                                    <a href="<?php echo home_url('/representative-panel/?view=reports'); ?>" class="text-button">Detaylı Raporlar</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="productionChart" height="220"></canvas>
                                </div>
                                <div class="chart-legend">
                                    <div class="chart-legend-item">
                                        <div class="legend-color" style="background-color: rgba(0,115,170,0.6);"></div>
                                        <div class="legend-text">Aylık Üretim</div>
                                    </div>
                                    <div class="chart-legend-item">
                                        <div class="legend-color" style="background-color: rgba(231,76,60,0.4);"></div>
                                        <div class="legend-text">İade Edilen</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-exclamation-triangle"></i> Yaklaşan Yenilemeler</h3>
                                <div class="card-actions">
                                    <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'renewal')); ?>" class="text-button">Tümünü Gör</a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($upcoming_renewals)): ?>
                                <div class="renewal-list">
                                    <?php foreach ($upcoming_renewals as $policy): 
                                        $end_date = new DateTime($policy->end_date);
                                        $now = new DateTime();
                                        $days_left = $now->diff($end_date)->days;
                                        $warning_class = $days_left <= 7 ? 'renewal-urgent' : 'renewal-normal';
                                    ?>
                                    <div class="renewal-item <?php echo $warning_class; ?>">
                                        <div class="renewal-days">
                                            <div class="days-number"><?php echo $days_left; ?></div>
                                            <div class="days-label">gün</div>
                                        </div>
                                        <div class="renewal-details">
                                            <h5><?php echo esc_html($policy->policy_type); ?></h5>
                                            <div class="renewal-customer">
                                                <span class="gender-icon <?php echo $policy->gender === 'male' ? 'gender-male' : 'gender-female'; ?>">
                                                    <i class="fas fa-<?php echo $policy->gender === 'male' ? 'male' : 'female'; ?>"></i>
                                                </span>
                                                <span><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></span>
                                            </div>
                                            <div class="renewal-meta">
                                                <div><?php echo esc_html($policy->policy_number); ?></div>
                                                <div class="end-date"><?php echo $end_date->format('d.m.Y'); ?></div>
                                            </div>
                                        </div>
                                        <div class="renewal-actions">
                                            <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="action-btn view-btn" title="Görüntüle">
                                                <i class="dashicons dashicons-visibility"></i>
                                            </a>
                                            <a href="<?php echo generate_panel_url('tasks', 'new', '', ['policy_id' => $policy->id, 'task_type' => 'renewal']); ?>" class="action-btn" title="Görev Oluştur">
                                                <i class="dashicons dashicons-calendar-alt"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="dashicons dashicons-yes-alt"></i></div>
                                    <h4>Yaklaşan Yenileme Yok</h4>
                                    <p>Önümüzdeki 30 gün içinde yenilenecek poliçe bulunmuyor.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3><i class="fas fa-clipboard-list"></i> Son Poliçeleriniz</h3>
                            <div class="card-actions">
                                <a href="<?php echo home_url('/representative-panel/?view=policies'); ?>" class="text-button">Tüm Poliçeler</a>
                                <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="card-option" title="Yeni Poliçe Ekle">
                                    <i class="dashicons dashicons-plus-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body table-responsive">
                            <?php if (!empty($recent_policies)): ?>
                            <table class="dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Müşteri</th>
                                        <th>Poliçe No</th>
                                        <th>Tür</th>
                                        <th>Başl. Tarihi</th>
                                        <th>Bitiş Tarihi</th>
                                        <th>Prim</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_policies as $policy): 
                                        $start_date = new DateTime($policy->start_date);
                                        $end_date = new DateTime($policy->end_date);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="customer-info">
                                                <span class="gender-icon <?php echo $policy->gender === 'male' ? 'gender-male' : 'gender-female'; ?>">
                                                    <i class="fas fa-<?php echo $policy->gender === 'male' ? 'male' : 'female'; ?>"></i>
                                                </span>
                                                <a href="<?php echo generate_panel_url('customers', 'view', $policy->customer_id); ?>">
                                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($policy->policy_number); ?></td>
                                        <td><?php echo esc_html($policy->policy_type); ?></td>
                                        <td><?php echo $start_date->format('d.m.Y'); ?></td>
                                        <td><?php echo $end_date->format('d.m.Y'); ?></td>
                                        <td class="amount-cell">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="table-action" title="Görüntüle">
                                                    <i class="dashicons dashicons-visibility"></i>
                                                </a>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="table-action" title="Düzenle">
                                                    <i class="dashicons dashicons-edit"></i>
                                                </a>
                                                <div class="table-action-dropdown-wrapper">
                                                    <span class="table-action table-action-more" title="Daha Fazla">
                                                        <i class="dashicons dashicons-ellipsis"></i>
                                                    </span>
                                                    <div class="table-action-dropdown">
                                                        <a href="<?php echo generate_panel_url('tasks', 'new', '', array('policy_id' => $policy->id)); ?>">
                                                            <i class="dashicons dashicons-calendar-alt"></i> Görev Oluştur
                                                        </a>
                                                        <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>">
                                                            <i class="dashicons dashicons-update"></i> Yenile
                                                        </a>
                                                        <a href="<?php echo generate_panel_url('policies', 'deactivate', $policy->id); ?>" class="text-danger">
                                                            <i class="dashicons dashicons-hidden"></i> Pasife Al
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="dashicons dashicons-clipboard"></i></div>
                                <h4>Henüz Poliçe Yok</h4>
                                <p>Yeni bir poliçe kaydı oluşturun</p>
                                <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Poliçe Ekle
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php endif; ?>
                </div>
                <?php 
                } elseif ($current_view === 'all_personnel') {
                    // Tüm Personel Sayfası
                    include_once(dirname(__FILE__) . '/all_personnel.php');
                } elseif ($current_view === 'representative_add') {
                    // Yeni Temsilci Ekleme Sayfası
                    include_once(dirname(__FILE__) . '/representative_add.php');
                } elseif ($current_view === 'team_add') {
                    // Yeni Ekip Ekleme Sayfası
                    include_once(dirname(__FILE__) . '/team_add.php');
                } elseif ($current_view === 'boss_settings') {
                    // Yönetim Ayarları Sayfası
                    include_once(dirname(__FILE__) . '/boss_settings.php');
                } else {
                    // Diğer görünümler, varsayılan olarak dashboard göster
                ?>
                <div class="alert alert-info">
                    <i class="dashicons dashicons-info"></i>
                    <p>Görünüm bulunamadı: <?php echo esc_html($current_view); ?></p>
                    <a href="<?php echo home_url('/representative-panel/?view=dashboard'); ?>" class="btn btn-sm btn-info">Dashboard'a Dön</a>
                </div>
                <?php
                }
                ?>
            </div>
        </div>
        
        <style>
            :root {
                --primary-color: #0073aa;
                --primary-light: #e3f2fd;
                --primary-dark: #005982;
                --secondary-color: #6c757d;
                --success-color: #28a745;
                --danger-color: #dc3545;
                --warning-color: #ffc107;
                --info-color: #17a2b8;
                --light-color: #f8f9fa;
                --dark-color: #343a40;
                --body-bg: #f0f2f5;
                --card-bg: #ffffff;
                --border-color: #e3e8ec;
                --text-color: #333333;
                --text-muted: #6c757d;
                --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
                --shadow: 0 4px 8px rgba(0,0,0,0.1);
                --shadow-lg: 0 6px 12px rgba(0,0,0,0.15);
                --header-height: 64px;
                --sidenav-width: 260px;
                --sidenav-collapsed-width: 60px;
                --sidenav-bg: #1d2327;
                --sidenav-color: #f0f0f1;
            }
            
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body.insurance-crm-page {
                background-color: var(--body-bg);
                color: var(--text-color);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.5;
                margin: 0;
                padding: 0;
                height: 100vh;
                display: flex;
                overflow: hidden;
            }
            
            .insurance-crm-sidenav {
                width: var(--sidenav-width);
                height: 100vh;
                background-color: var(--sidenav-bg);
                color: var(--sidenav-color);
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1000;
                box-shadow: var(--shadow);
                display: flex;
                flex-direction: column;
                transition: all 0.3s ease-in-out;
                transform: translateX(0);
            }
            
            .sidenav-header {
                display: flex;
                align-items: center;
                padding: 15px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .sidenav-logo {
                width: 30px;
                height: 30px;
                margin-right: 10px;
                border-radius: 50%;
                overflow: hidden;
                background-color: #fff;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            .sidenav-logo img {
                width: 100%;
                height: auto;
            }
            
            .sidenav-header h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
            }
            
            .sidenav-user {
                display: flex;
                align-items: center;
                padding: 15px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background-color: #395C6B;
                display: flex;
                justify-content: center;
                align-items: center;
                margin-right: 10px;
                overflow: hidden;
            }
            
            .user-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .user-avatar i {
                font-size: 20px;
            }
            
            .user-info {
                flex: 1;
                overflow: hidden;
            }
            
            .user-info h4 {
                font-size: 14px;
                font-weight: 600;
                margin: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .user-info span {
                font-size: 12px;
                color: rgba(255, 255, 255, 0.7);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                display: block;
            }
            
            .user-role {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                margin-top: 4px;
            }
            
            .patron-role {
                background-color: #4A235A;
                color: #fff;
            }
            
            .manager-role {
                background-color: #1A5276;
                color: #fff;
            }
            
            .leader-role {
                background-color: #1E8449;
                color: #fff;
            }
            
            .rep-role {
                background-color: #D35400;
                color: #fff;
            }
            
            .sidenav-menu {
                flex: 1;
                overflow-y: auto;
                padding-top: 10px;
            }
            
            .sidenav-menu a {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                color: var(--sidenav-color);
                text-decoration: none;
                transition: all 0.2s;
                position: relative;
                overflow: hidden;
            }
            
            .sidenav-menu a i.dashicons {
                font-size: 18px;
                width: 20px;
                height: 20px;
                margin-right: 15px;
                transition: all 0.2s;
            }
            
            .sidenav-menu a span {
                font-size: 14px;
                white-space: nowrap;
            }
            
            .sidenav-menu a:hover {
                background: rgba(255, 255, 255, 0.1);
            }
            
            .sidenav-menu a.active {
                background-color: var(--primary-color);
                color: white;
            }
            
            /* Alt menü sistemi */
            .sidenav-submenu .submenu-toggle {
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            
            .sidenav-submenu .submenu-toggle::after {
                content: "\f347";
                font-family: "dashicons";
                font-size: 16px;
                margin-left: auto;
                transition: transform 0.3s ease;
            }
            
            .sidenav-submenu.open .submenu-toggle::after {
                transform: rotate(180deg);
            }
            
            .submenu-items {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
                background-color: rgba(0, 0, 0, 0.1);
            }
            
            .sidenav-submenu.open .submenu-items {
                max-height: 400px;
            }
            
            .submenu-items a {
                padding-left: 50px;
            }
            
            .sidenav-footer {
                padding: 15px;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .logout-button {
                display: flex;
                align-items: center;
                padding: 10px;
                color: var(--sidenav-color);
                text-decoration: none;
                transition: all 0.2s;
                border-radius: 4px;
            }
            
            .logout-button i {
                font-size: 18px;
                margin-right: 10px;
            }
            
            .logout-button span {
                font-size: 14px;
            }
            
            .logout-button:hover {
                background: rgba(255, 255, 255, 0.1);
            }
            
            .insurance-crm-main {
                flex: 1;
                margin-left: var(--sidenav-width);
                transition: margin-left 0.3s ease;
                position: relative;
                height: 100vh;
                display: flex;
                flex-direction: column;
            }
            
            .main-header {
                height: var(--header-height);
                background-color: #fff;
                box-shadow: var(--shadow-sm);
                padding: 0 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                z-index: 100;
            }
            
            .header-left {
                display: flex;
                align-items: center;
            }
            
            .header-left h2 {
                font-size: 18px;
                font-weight: 600;
                margin-left: 15px;
                color: #333;
            }
            
            #sidenav-toggle {
                background: transparent;
                border: none;
                cursor: pointer;
                color: #666;
                padding: 5px;
                border-radius: 4px;
                transition: all 0.2s;
            }
            
            #sidenav-toggle:hover {
                background-color: #f0f0f0;
                color: #333;
            }
            
            .header-right {
                display: flex;
                align-items: center;
            }
            
            .search-box {
                position: relative;
                margin-right: 20px;
            }
            
            .search-box input {
                padding: 8px 12px;
                padding-right: 30px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                width: 220px;
                font-size: 14px;
                transition: all 0.3s;
            }
            
            .search-box input:focus {
                width: 280px;
                border-color: var(--primary-color);
                box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, .25);
                outline: 0;
            }
            
            .search-box i {
                position: absolute;
                top: 50%;
                right: 10px;
                transform: translateY(-50%);
                color: #6c757d;
            }
            
            .notification-bell {
                position: relative;
                margin-right: 15px;
            }
            
            .notification-bell a {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                color: #666;
                text-decoration: none;
            }
            
            .notification-bell i {
                font-size: 20px;
            }
            
            .notification-bell a:hover {
                background-color: #f0f0f0;
                color: #333;
            }
            
            .notification-badge {
                position: absolute;
                top: 0;
                right: 0;
                width: 18px;
                height: 18px;
                border-radius: 50%;
                background-color: var(--danger-color);
                color: white;
                font-size: 10px;
                font-weight: 600;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .notifications-dropdown {
                position: absolute;
                top: 100%;
                right: -10px;
                width: 320px;
                background-color: white;
                border-radius: 4px;
                box-shadow: var(--shadow-lg);
                z-index: 1000;
                display: none;
                margin-top: 10px;
                max-height: calc(100vh - 120px);
                overflow-y: auto;
            }
            
            .notifications-dropdown.show {
                display: block;
                animation: fadeIn 0.2s ease;
            }
            
            .notifications-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 15px;
                border-bottom: 1px solid #eaeaea;
            }
            
            .notifications-header h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
            }
            
            .mark-all-read {
                font-size: 12px;
                color: var(--primary-color);
                text-decoration: none;
                transition: all 0.2s;
            }
            
            .mark-all-read:hover {
                text-decoration: underline;
            }
            
            .notifications-list {
                max-height: 400px;
                overflow-y: auto;
            }
            
            .notification-item {
                display: flex;
                align-items: flex-start;
                padding: 15px;
                border-bottom: 1px solid #eaeaea;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .notification-item.unread {
                background-color: #f0f7ff;
            }
            
            .notification-item:hover {
                background-color: #f5f5f5;
            }
            
            .notification-item i {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background-color: #e3f2fd;
                color: var(--primary-color);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 15px;
            }
            
            .notification-content {
                flex: 1;
            }
            
            .notification-content p {
                margin: 0;
                font-size: 14px;
                line-height: 1.4;
            }
            
            .notification-content strong {
                font-weight: 600;
            }
            
            .notification-time {
                font-size: 12px;
                color: #6c757d;
                margin-top: 5px;
                display: block;
            }
            
            .notifications-footer {
                padding: 10px 15px;
                text-align: center;
                border-top: 1px solid #eaeaea;
            }
            
            .notifications-footer a {
                font-size: 13px;
                color: var(--primary-color);
                text-decoration: none;
            }
            
            .notifications-footer a:hover {
                text-decoration: underline;
            }
            
            .quick-add {
                position: relative;
                margin-right: 15px;
            }
            
            .quick-add-btn {
                display: flex;
                align-items: center;
                padding: 8px 16px;
                background-color: var(--primary-color);
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                text-decoration: none;
            }
            
            .quick-add-btn:hover {
                background-color: var(--primary-dark);
            }
            
            .quick-add-btn i {
                margin-right: 8px;
            }
            
            .quick-add-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 200px;
                background-color: white;
                border-radius: 4px;
                box-shadow: var(--shadow-lg);
                z-index: 1000;
                display: none;
                margin-top: 10px;
                animation: fadeIn 0.2s ease;
            }
            
            .quick-add-dropdown.show {
                display: block;
            }
            
            .quick-add-dropdown a {
                display: flex;
                align-items: center;
                padding: 10px 15px;
                color: #333;
                text-decoration: none;
                transition: all 0.2s;
            }
            
            .quick-add-dropdown a:hover {
                background-color: #f5f5f5;
            }
            
            .quick-add-dropdown a i {
                margin-right: 10px;
                color: var(--primary-color);
                width: 20px;
                text-align: center;
            }
            
            .user-menu {
                position: relative;
            }
            
            .user-avatar-small {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background-color: #f0f0f0;
                display: flex;
                justify-content: center;
                align-items: center;
                cursor: pointer;
                overflow: hidden;
            }
            
            .user-avatar-small img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .user-avatar-small i {
                font-size: 18px;
                color: #666;
            }
            
            .user-avatar-small:hover {
                background-color: #e9ecef;
            }
            
            .main-content {
                padding: 20px;
                flex: 1;
                overflow-y: auto;
            }
            
            /* Main Content Styling */
            .dashboard-grid {
                display: grid;
                grid-gap: 20px;
                max-width: 1200px;
                margin: 0 auto;
            }
            
            .upper-section {
                display: grid;
                grid-template-columns: 2fr 1fr;
                grid-gap: 20px;
            }

            .dashboard-card {
                background-color: white;
                border-radius: 8px;
                box-shadow: var(--shadow-sm);
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }
            
            .chart-card {
                height: 100%;
            }
            
            .card-header {
                padding: 15px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #eaeaea;
            }
            
            .card-header h3 {
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin: 0;
                display: flex;
                align-items: center;
            }
            
            .card-header h3 i {
                margin-right: 8px;
                color: var(--primary-color);
            }
            
            .card-actions {
                display: flex;
                align-items: center;
            }
            
            .text-button {
                background: none;
                border: none;
                color: var(--primary-color);
                font-size: 13px;
                cursor: pointer;
                margin-right: 10px;
                text-decoration: none;
            }
            
            .text-button:hover {
                text-decoration: underline;
            }
            
            .card-option {
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #666;
                border-radius: 4px;
                transition: all 0.2s;
                cursor: pointer;
                text-decoration: none;
            }
            
            .card-option:hover {
                background-color: #f0f0f0;
                color: #333;
            }
            
            .card-body {
                padding: 20px;
                flex: 1;
            }
            
            .chart-container {
                height: 100%;
                min-height: 250px;
                position: relative;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                background: white;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                box-shadow: var(--shadow-sm);
                position: relative;
                overflow: hidden;
            }
            
            .stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 15px;
            }
            
            .stat-icon i {
                color: white;
                font-size: 20px;
            }
            
            .bg-primary {
                background-color: #e3f2fd;
            }
            
            .bg-primary i {
                color: #0073aa;
            }
            
            .bg-success {
                background-color: #e8f5e9;
            }
            
            .bg-success i {
                color: #28a745;
            }
            
            .bg-info {
                background-color: #e0f7fa;
            }
            
            .bg-info i {
                color: #17a2b8;
            }
            
            .bg-warning {
                background-color: #fff8e1;
            }
            
            .bg-warning i {
                color: #ffc107;
            }
            
            .stat-content h3 {
                font-size: 24px;
                font-weight: 700;
                margin: 0 0 5px;
                color: #333;
            }
            
            .stat-content p {
                font-size: 14px;
                color: #6c757d;
                margin: 0;
            }
            
            .stat-footer {
                margin-top: 15px;
            }
            
            .trend {
                font-size: 12px;
                display: flex;
                align-items: center;
                color: #6c757d;
            }
            
            .trend i {
                margin-right: 5px;
            }
            
            .trend.up {
                color: #28a745;
            }
            
            .trend.down {
                color: #dc3545;
            }
            
            .trend.neutral {
                color: #6c757d;
            }
            
            .progress-bar {
                height: 6px;
                background-color: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-top: 8px;
            }
            
            .progress {
                height: 100%;
                background-color: #0073aa;
                border-radius: 3px;
            }

            /* Task Summary Widget Styling */
            .tasks-summary-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .task-summary-card {
                background: white;
                border-radius: 8px;
                position: relative;
                box-shadow: var(--shadow-sm);
                display: flex;
                flex-direction: column;
                padding: 15px;
                text-align: center;
                border-top: 3px solid transparent;
                transition: transform 0.3s ease;
            }
            
            .task-summary-card:hover {
                transform: translateY(-5px);
            }
            
            .task-summary-card.today {
                border-top-color: #dc3545;
            }
            
            .task-summary-card.tomorrow {
                border-top-color: #fd7e14;
            }
            
            .task-summary-card.this-week {
                border-top-color: #28a745;
            }
            
            .task-summary-card.this-month {
                border-top-color: #0073aa;
            }
            
            .task-summary-icon {
                margin-bottom: 10px;
            }
            
            .task-summary-icon i {
                font-size: 24px;
                color: #6c757d;
            }
            
            .task-summary-content h3 {
                font-size: 28px;
                font-weight: 700;
                margin: 0 0 5px;
                color: #333;
            }
            
            .task-summary-content p {
                font-size: 14px;
                color: #6c757d;
                margin: 0;
            }
            
            .task-summary-link {
                margin-top: 10px;
                display: inline-block;
                font-size: 13px;
                color: var(--primary-color);
                text-decoration: none;
            }
            
            .task-summary-link:hover {
                text-decoration: underline;
            }

            /* Organization Buttons */
            .org-overview {
                margin-bottom: 20px;
            }
            
            .section-header {
                margin-bottom: 15px;
            }
            
            .section-header h3 {
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin: 0;
            }
            
            .org-buttons {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
            }
            
            .org-button {
                display: flex;
                align-items: center;
                background-color: white;
                border-radius: 8px;
                padding: 15px;
                box-shadow: var(--shadow-sm);
                text-decoration: none;
                color: #333;
                transition: all 0.3s ease;
            }
            
            .org-button:hover {
                transform: translateY(-3px);
                box-shadow: var(--shadow);
                background-color: #f8f9fa;
            }
            
            .org-button-icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                background-color: #e3f2fd;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 15px;
            }
            
            .org-button-icon i {
                font-size: 24px;
                color: #0073aa;
            }
            
            .org-button-content h4 {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 5px;
            }
            
            .org-button-content p {
                font-size: 13px;
                color: #6c757d;
                margin: 0;
            }

            /* Two Column Layout */
            .two-column-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 20px;
            }
            
            /* Leaderboard */
            .leaderboard {
                display: flex;
                flex-direction: column;
            }
            
            .leaderboard-item {
                display: flex;
                align-items: center;
                padding: 12px 0;
                border-bottom: 1px solid #eaeaea;
            }
            
            .leaderboard-item:last-child {
                border-bottom: none;
            }
            
            .rank {
                width: 30px;
                height: 30px;
                background-color: #f0f0f0;
                color: #666;
                font-weight: 700;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 15px;
            }
            
            .gold-rank {
                background-color: #fff3cd;
                color: #9e7c16;
            }
            
            .silver-rank {
                background-color: #e9ecef;
                color: #495057;
            }
            
            .bronze-rank {
                background-color: #f8d7da;
                color: #721c24;
            }
            
            .rank-warning {
                background-color: #f8d7da;
                color: #721c24;
            }
            
            .leaderboard-info {
                flex: 1;
            }
            
            .leaderboard-info h4 {
                font-size: 14px;
                font-weight: 600;
                margin: 0 0 5px;
                color: #333;
            }
            
            .leaderboard-info p {
                font-size: 12px;
                color: #6c757d;
                margin: 0;
            }
            
            .leaderboard-value {
                font-size: 16px;
                font-weight: 700;
                color: #0073aa;
            }

            /* Tables */
            .table-responsive {
                overflow-x: auto;
            }
            
            .dashboard-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .dashboard-table thead th {
                background-color: #f9fafb;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                font-size: 13px;
                color: #6c757d;
                border-bottom: 2px solid #eaeaea;
            }
            
            .dashboard-table tbody td {
                padding: 12px;
                border-bottom: 1px solid #eaeaea;
                color: #333;
                font-size: 14px;
            }
            
            .dashboard-table tbody tr:hover {
                background-color: #f8f9fa;
            }
            
            .customer-info {
                display: flex;
                align-items: center;
            }
            
            .gender-icon {
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 8px;
            }
            
            .gender-male {
                background-color: #e3f2fd;
                color: #0d6efd;
            }
            
            .gender-female {
                background-color: #f8d7da;
                color: #dc3545;
            }
            
            .amount-cell {
                font-weight: 600;
                text-align: right;
            }
            
            .status-badge {
                display: inline-flex;
                align-items: center;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
            }
            
            .status-active {
                background-color: #d1e7dd;
                color: #0f5132;
            }
            
            .status-inactive {
                background-color: #f8d7da;
                color: #842029;
            }
            
            .table-actions {
                display: flex;
                gap: 5px;
            }
            
            .table-action {
                width: 28px;
                height: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                color: #6c757d;
                background-color: transparent;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .table-action:hover {
                background-color: #f0f0f0;
                color: #333;
            }
            
            .date-urgent {
                color: #dc3545;
                font-weight: 600;
            }
            
            .date-normal {
                color: #333;
            }
            
            .days-left {
                font-size: 12px;
                color: #6c757d;
            }
            
            .table-action-dropdown-wrapper {
                position: relative;
            }
            
            .table-action-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                min-width: 160px;
                background-color: white;
                border-radius: 4px;
                box-shadow: var(--shadow);
                z-index: 100;
                display: none;
                padding: 5px 0;
            }
            
            .table-action-dropdown a {
                display: block;
                padding: 8px 12px;
                color: #333;
                text-decoration: none;
                font-size: 13px;
                transition: all 0.2s;
            }
            
            .table-action-dropdown a:hover {
                background-color: #f0f0f0;
            }
            
            .table-action-dropdown a i {
                margin-right: 8px;
                width: 16px;
                text-align: center;
            }
            
            .text-danger {
                color: #dc3545 !important;
            }

            /* Empty States */
            .empty-state {
                text-align: center;
                padding: 30px;
                color: #6c757d;
            }
            
            .empty-state .empty-icon {
                font-size: 36px;
                margin-bottom: 15px;
                color: #adb5bd;
            }
            
            .empty-state .empty-icon i {
                font-size: 36px;
            }
            
            .empty-state h4 {
                font-size: 18px;
                margin: 0 0 10px;
                color: #333;
            }
            
            .empty-state p {
                margin: 0 0 15px;
            }
            
            .btn {
                display: inline-flex;
                align-items: center;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 500;
                border-radius: 4px;
                transition: all 0.2s;
                text-decoration: none;
                cursor: pointer;
                border: 1px solid transparent;
            }
            
            .btn-primary {
                background-color: #0073aa;
                color: white;
                border-color: #0073aa;
            }
            
            .btn-primary:hover {
                background-color: #005982;
                border-color: #005982;
            }
            
            .btn-sm {
                padding: 4px 12px;
                font-size: 13px;
            }
            
            .btn i {
                margin-right: 8px;
            }
            
            .btn-info {
                background-color: #17a2b8;
                color: white;
                border-color: #17a2b8;
            }
            
            .btn-info:hover {
                background-color: #138496;
                border-color: #138496;
            }

            /* Chart Legend */
            .chart-legend {
                display: flex;
                justify-content: center;
                margin-top: 15px;
            }
            
            .chart-legend-item {
                display: flex;
                align-items: center;
                margin-right: 20px;
            }
            
            .chart-legend-item:last-child {
                margin-right: 0;
            }
            
            .legend-color {
                width: 16px;
                height: 16px;
                border-radius: 4px;
                margin-right: 8px;
            }
            
            .legend-text {
                font-size: 13px;
                color: #6c757d;
            }

            /* Renewal List */
            .renewal-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            
            .renewal-item {
                display: flex;
                align-items: center;
                background-color: #f8f9fa;
                border-radius: 6px;
                overflow: hidden;
                transition: transform 0.2s ease;
            }
            
            .renewal-item:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-sm);
            }
            
            .renewal-urgent {
                background-color: #fff5f5;
                border-left: 3px solid #dc3545;
            }
            
            .renewal-normal {
                background-color: #f8f9fa;
                border-left: 3px solid #0073aa;
            }
            
            .renewal-days {
                width: 60px;
                height: 60px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background-color: #fff;
                padding: 10px;
            }
            
            .renewal-urgent .renewal-days {
                color: #dc3545;
            }
            
            .renewal-normal .renewal-days {
                color: #0073aa;
            }
            
            .days-number {
                font-size: 20px;
                font-weight: 700;
            }
            
            .days-label {
                font-size: 12px;
            }
            
            .renewal-details {
                flex: 1;
                padding: 10px;
            }
            
            .renewal-details h5 {
                font-size: 14px;
                margin: 0 0 5px;
                color: #333;
            }
            
            .renewal-customer {
                display: flex;
                align-items: center;
                margin-bottom: 5px;
            }
            
            .renewal-meta {
                display: flex;
                justify-content: space-between;
                font-size: 12px;
                color: #6c757d;
            }
            
            .end-date {
                font-weight: 600;
            }
            
            .renewal-actions {
                display: flex;
                flex-direction: column;
                padding: 10px;
                gap: 5px;
            }
            
            .action-btn {
                width: 28px;
                height: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                color: #6c757d;
                background-color: #fff;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .action-btn:hover {
                background-color: #f0f0f0;
                color: #333;
            }
            
            .view-btn {
                color: #0073aa;
            }

            /* Alert */
            .alert {
                display: flex;
                align-items: flex-start;
                padding: 15px;
                margin-bottom: 20px;
                border-radius: 8px;
                border-left: 4px solid;
            }
            
            .alert-info {
                background-color: #e3f2fd;
                border-left-color: #0073aa;
                color: #0c5460;
            }
            
            .alert i {
                font-size: 20px;
                margin-right: 15px;
                margin-top: 2px;
            }
            
            .alert p {
                margin: 0 0 15px;
            }

            /* Urgent Tasks Section */
            .urgent-tasks {
                margin-top: 20px;
            }
            
            .urgent-tasks h4 {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 15px;
                color: #333;
                border-bottom: 1px solid #eaeaea;
                padding-bottom: 8px;
            }
            
            .urgent-task-item {
                display: flex;
                align-items: center;
                padding: 12px;
                border-radius: 6px;
                margin-bottom: 10px;
                background-color: #f8f9fa;
                border-left: 3px solid #6c757d;
            }
            
            .urgent-task-item.normal {
                border-left-color: #0073aa;
                background-color: #f0f7ff;
            }
            
            .urgent-task-item.urgent {
                border-left-color: #fd7e14;
                background-color: #fff3e0;
            }
            
            .urgent-task-item.very-urgent {
                border-left-color: #dc3545;
                background-color: #fff5f5;
            }
            
            .task-date {
                width: 45px;
                height: 45px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background-color: #fff;
                border-radius: 6px;
                margin-right: 12px;
                box-shadow: var(--shadow-sm);
            }
            
            .date-number {
                font-size: 18px;
                font-weight: 700;
            }
            
            .date-month {
                font-size: 11px;
                text-transform: uppercase;
            }
            
            .task-details {
                flex: 1;
            }
            
            .task-details h5 {
                font-size: 14px;
                margin: 0 0 5px;
                color: #333;
            }
            
            .task-details p {
                font-size: 12px;
                margin: 0;
                color: #6c757d;
            }
            
            .task-action {
                margin-left: 10px;
            }
            
            .view-task-btn {
                display: inline-block;
                padding: 6px 12px;
                background-color: #fff;
                border-radius: 4px;
                font-size: 12px;
                color: #0073aa;
                text-decoration: none;
                transition: all 0.2s;
                border: 1px solid #eaeaea;
            }
            
            .view-task-btn:hover {
                background-color: #f0f7ff;
                border-color: #0073aa;
            }
            
            /* Responsive Styling */
            @media (max-width: 1200px) {
                .dashboard-grid {
                    padding: 0 10px;
                }
                
                .upper-section {
                    grid-template-columns: 1fr;
                }
                
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .org-buttons {
                    grid-template-columns: 1fr;
                }
                
                .two-column-grid {
                    grid-template-columns: 1fr;
                }
                
                .tasks-summary-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
            
            @media (max-width: 767px) {
                .main-header {
                    flex-direction: column;
                    height: auto;
                    padding: 15px;
                }
                
                .header-left {
                    width: 100%;
                    margin-bottom: 10px;
                }
                
                .header-right {
                    width: 100%;
                    justify-content: space-between;
                }
                
                .search-box {
                    margin-right: 10px;
                }
                
                .search-box input {
                    width: 160px;
                }
                
                .search-box input:focus {
                    width: 160px;
                }
                
                .stats-grid {
                    grid-template-columns: 1fr;
                }
                
                .tasks-summary-grid {
                    grid-template-columns: 1fr;
                }
                
                .insurance-crm-sidenav {
                    width: var(--sidenav-collapsed-width);
                    transform: translateX(-100%);
                }
                
                .insurance-crm-sidenav.show {
                    transform: translateX(0);
                }
                
                .sidenav-menu a span,
                .sidenav-header h3,
                .user-info,
                .logout-button span {
                    display: none;
                }
                
                .sidenav-logo {
                    margin-right: 0;
                }
                
                .insurance-crm-main {
                    margin-left: 0;
                }
                
                .table-responsive {
                    overflow-x: auto;
                }
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }
        </style>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Yan menü göster/gizle
                const sidenavToggle = document.getElementById('sidenav-toggle');
                const sidenav = document.querySelector('.insurance-crm-sidenav');
                const mainContent = document.querySelector('.insurance-crm-main');
                
                if (sidenavToggle) {
                    sidenavToggle.addEventListener('click', function() {
                        sidenav.classList.toggle('show');
                        
                        if (window.innerWidth > 767) {
                            if (sidenav.style.width === 'var(--sidenav-collapsed-width)') {
                                sidenav.style.width = 'var(--sidenav-width)';
                                mainContent.style.marginLeft = 'var(--sidenav-width)';
                                
                                // Eleman görünürlüklerini geri al
                                document.querySelectorAll('.sidenav-menu a span, .sidenav-header h3, .user-info, .logout-button span').forEach(el => {
                                    el.style.display = 'block';
                                });
                            } else {
                                sidenav.style.width = 'var(--sidenav-collapsed-width)';
                                mainContent.style.marginLeft = 'var(--sidenav-collapsed-width)';
                                
                                // Elementleri gizle
                                document.querySelectorAll('.sidenav-menu a span, .sidenav-header h3, .user-info, .logout-button span').forEach(el => {
                                    el.style.display = 'none';
                                });
                            }
                        }
                    });
                }
                
                // Alt menü göster/gizle
                const submenuToggles = document.querySelectorAll('.submenu-toggle');
                submenuToggles.forEach(toggle => {
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        const submenu = this.closest('.sidenav-submenu');
                        submenu.classList.toggle('open');
                    });
                });
                
                // Bildirim açılır menü
                const notificationsToggle = document.getElementById('notifications-toggle');
                const notificationsDropdown = document.querySelector('.notifications-dropdown');
                
                if (notificationsToggle && notificationsDropdown) {
                    notificationsToggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        notificationsDropdown.classList.toggle('show');
                        e.stopPropagation();
                    });
                    
                    document.addEventListener('click', function(e) {
                        if (!notificationsDropdown.contains(e.target) && e.target !== notificationsToggle) {
                            notificationsDropdown.classList.remove('show');
                        }
                    });
                }
                
                // Hızlı Ekle açılır menü
                const quickAddToggle = document.getElementById('quick-add-toggle');
                const quickAddDropdown = document.querySelector('.quick-add-dropdown');
                
                if (quickAddToggle && quickAddDropdown) {
                    quickAddToggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        quickAddDropdown.classList.toggle('show');
                        e.stopPropagation();
                    });
                    
                    document.addEventListener('click', function(e) {
                        if (!quickAddDropdown.contains(e.target) && e.target !== quickAddToggle) {
                            quickAddDropdown.classList.remove('show');
                        }
                    });
                }
                
                // Tablo işlem açılır menüler
                const tableActionMoreButtons = document.querySelectorAll('.table-action-more');
                tableActionMoreButtons.forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        const dropdown = this.closest('.table-action-dropdown-wrapper').querySelector('.table-action-dropdown');
                        
                        // Diğer tüm açık dropdown'ları kapat
                        document.querySelectorAll('.table-action-dropdown.show').forEach(openDropdown => {
                            if (openDropdown !== dropdown) {
                                openDropdown.classList.remove('show');
                            }
                        });
                        
                        dropdown.classList.toggle('show');
                        e.stopPropagation();
                    });
                });
                
                document.addEventListener('click', function() {
                    document.querySelectorAll('.table-action-dropdown.show').forEach(dropdown => {
                        dropdown.classList.remove('show');
                    });
                });
                
                // Üretim Grafiği
                const productionChartEl = document.getElementById('productionChart');
                if (productionChartEl) {
                    const ctx = productionChartEl.getContext('2d');
                    
                    // Veri setini hazırla - PHP değişkenlerinden al
                    const months = [
                        <?php 
                        foreach ($monthly_production_data as $month => $premium) {
                            echo "'" . date('M Y', strtotime($month)) . "', ";
                        }
                        ?>
                    ];
                    
                    const premiums = [
                        <?php 
                        foreach ($monthly_production_data as $premium) {
                            echo $premium . ", ";
                        }
                        ?>
                    ];
                    
                    const refunds = [
                        <?php 
                        foreach ($monthly_refunded_data as $refund) {
                            echo $refund . ", ";
                        }
                        ?>
                    ];
                    
                    const productionChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: months,
                            datasets: [
                                {
                                    label: 'Üretim',
                                    data: premiums,
                                    backgroundColor: 'rgba(0, 115, 170, 0.6)',
                                    borderColor: 'rgba(0, 115, 170, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'İade',
                                    data: refunds,
                                    backgroundColor: 'rgba(231, 76, 60, 0.4)',
                                    borderColor: 'rgba(231, 76, 60, 1)',
                                    borderWidth: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed.y !== null) {
                                                label += new Intl.NumberFormat('tr-TR', { 
                                                    style: 'currency', 
                                                    currency: 'TRY',
                                                    minimumFractionDigits: 0
                                                }).format(context.parsed.y);
                                            }
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return new Intl.NumberFormat('tr-TR', {
                                                style: 'currency',
                                                currency: 'TRY',
                                                minimumFractionDigits: 0
                                            }).format(value);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
        </script>
        <?php wp_footer(); ?>
    </body>
</html>
