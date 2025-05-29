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

if (!$representative) {
    wp_die('Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.');
}

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
 * Tam yetkili kullanıcı kontrolü (Patron ve Müdür)
 */
function has_full_admin_access($user_id) {
    // Patron ve Müdür aynı haklara sahip olmalı
    return is_patron($user_id) || is_manager($user_id);
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
    
    // Patron ve Müdür için (tam yetkili kullanıcılar):
    if (has_full_admin_access($user_id)) {
        // Dashboard, ana menüler ve yönetim ekranlarında tüm verileri görsün
        if ($current_view == 'dashboard' || 
            $current_view == 'customers' || 
            $current_view == 'policies' ||
            $current_view == 'tasks' ||
            $current_view == 'reports' ||
            $current_view == 'organization' ||
            $current_view == 'all_personnel' ||
            $current_view == 'manager_dashboard' || 
            $current_view == 'team_leaders') {
            
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
    
    // Ekip lideri için
    if (is_team_leader($user_id)) {
        // Dashboard ve ana menülerde sadece kendi verilerini görür
        if ($current_view == 'dashboard') {
            return [$rep_id];
        }
        // Ekip lideri menülerinde ekibinin tüm verilerini görür
        else if ($current_view == 'team' || 
                strpos($current_view, 'team_') === 0 ||
                $current_view == 'organization' || // Organizasyon yönetimine erişim
                $current_view == 'all_personnel') { // Personel yönetimine erişim
            
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
    return has_full_admin_access($user_id);
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
 * Ekip Detay sayfası için fonksiyon
 */
function generate_team_detail_url($team_id) {
    return generate_panel_url('team_detail', '', '', array('team_id' => $team_id));
}

// Kullanıcının rolünü belirle
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Dashboard görünümü ve menülere göre yetkili temsilci ID'lerini al
$rep_ids = get_dashboard_representatives($current_user->ID, $current_view);

// Rol isimlerini tanımla
function get_role_name($role) {
    $roles = [
        'patron' => 'Patron',
        'manager' => 'Müdür', 
        'assistant_manager' => 'Müdür Yardımcısı',
        'team_leader' => 'Ekip Lideri',
        'representative' => 'Müşteri Temsilcisi'
    ];
    return $roles[$role] ?? 'Bilinmiyor';
}

// Filtre tarihi belirleme
$date_filter_period = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : 'this_month';
$custom_start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$custom_end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

// Seçilen tarih aralığına göre başlangıç ve bitiş tarihlerini belirle
switch ($date_filter_period) {
    case 'last_3_months':
        $filter_start_date = date('Y-m-d', strtotime('-3 months'));
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Son 3 Ay';
        break;
    case 'last_6_months':
        $filter_start_date = date('Y-m-d', strtotime('-6 months'));
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Son 6 Ay';
        break;
    case 'this_year':
        $filter_start_date = date('Y-01-01');
        $filter_end_date = date('Y-m-d');
        $filter_title = 'Bu Yıl';
        break;
    case 'custom':
        $filter_start_date = !empty($custom_start_date) ? $custom_start_date : date('Y-m-01');
        $filter_end_date = !empty($custom_end_date) ? $custom_end_date : date('Y-m-d');
        $filter_title = date('d.m.Y', strtotime($filter_start_date)) . ' - ' . date('d.m.Y', strtotime($filter_end_date));
        break;
    case 'this_month':
    default:
        $filter_start_date = date('Y-m-01');
        $filter_end_date = date('Y-m-t');
        $filter_title = 'Bu Ay';
        break;
}

// Filtre parametrelerini URL'e eklemek için yardımcı fonksiyon
function add_date_filter_to_url($url, $period, $start_date = '', $end_date = '') {
    $url = add_query_arg('date_filter', $period, $url);
    
    if ($period === 'custom') {
        if (!empty($start_date)) {
            $url = add_query_arg('start_date', $start_date, $url);
        }
        if (!empty($end_date)) {
            $url = add_query_arg('end_date', $end_date, $url);
        }
    }
    
    return $url;
}

// Dashboard veri sorguları
$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));

$new_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND created_at BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$new_customers = $new_customers ?: 0;
$customer_increase_rate = $total_customers > 0 ? ($new_customers / $total_customers) * 100 : 0;

$total_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND cancellation_date IS NULL",
    ...$rep_ids
));

$new_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$new_policies = $new_policies ?: 0;

$total_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));
if ($total_premium === null) $total_premium = 0;

$new_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));
$new_premium = $new_premium ?: 0;
$premium_increase_rate = $total_premium > 0 ? ($new_premium / $total_premium) * 100 : 0;

$current_month = date('Y-m');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');

$current_month_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
     FROM {$wpdb->prefix}insurance_crm_policies
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND start_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $current_month_end . ' 23:59:59'])
));

if ($current_month_premium === null) $current_month_premium = 0;

// Performans Metrikleri - En çok üretim yapan, en çok yeni iş, en çok yeni müşteri, en çok iptali olan
function get_performance_metrics($rep_ids, $period = 'this_month', $start_date = null, $end_date = null) {
    global $wpdb;
    $table_policies = $wpdb->prefix . 'insurance_crm_policies';
    $table_customers = $wpdb->prefix . 'insurance_crm_customers';
    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
    
    if (empty($rep_ids)) {
        return [
            'top_producer' => null,
            'most_new_business' => null,
            'most_new_customers' => null,
            'most_cancellations' => null
        ];
    }
    
    $rep_ids_str = implode(',', array_map('intval', $rep_ids));
    
    // Varsayılan tarih değerlerini ayarla
    if (!$start_date) {
        switch ($period) {
            case 'last_3_months':
                $start_date = date('Y-m-d', strtotime('-3 months'));
                break;
            case 'last_6_months':
                $start_date = date('Y-m-d', strtotime('-6 months'));
                break;
            case 'this_year':
                $start_date = date('Y-01-01');
                break;
            case 'this_month':
            default:
                $start_date = date('Y-m-01');
                break;
        }
    }
    
    if (!$end_date) {
        $end_date = date('Y-m-d');
    }
    
    $start_datetime = $start_date . ' 00:00:00';
    $end_datetime = $end_date . ' 23:59:59';
    
    // En çok üretim yapan (toplam prim)
    $top_producer = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            p.representative_id,
            SUM(p.premium_amount) - COALESCE(SUM(p.refunded_amount), 0) as total_premium,
            COUNT(p.id) as policy_count,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_policies} p
        LEFT JOIN 
            {$table_reps} r ON p.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            p.representative_id IN ({$rep_ids_str})
            AND p.status = 'active'
            AND p.start_date BETWEEN %s AND %s
        GROUP BY 
            p.representative_id
        ORDER BY 
            total_premium DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    // En çok yeni iş (poliçe sayısı)
    $most_new_business = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            p.representative_id,
            COUNT(p.id) as policy_count,
            SUM(p.premium_amount) - COALESCE(SUM(p.refunded_amount), 0) as total_premium,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_policies} p
        LEFT JOIN 
            {$table_reps} r ON p.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            p.representative_id IN ({$rep_ids_str})
            AND p.status = 'active'
            AND p.start_date BETWEEN %s AND %s
        GROUP BY 
            p.representative_id
        ORDER BY 
            policy_count DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    // En çok yeni müşteri
    $most_new_customers = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            c.representative_id,
            COUNT(c.id) as customer_count,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_customers} c
        LEFT JOIN
            {$table_reps} r ON c.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            c.representative_id IN ({$rep_ids_str})
            AND c.created_at BETWEEN %s AND %s
        GROUP BY 
            c.representative_id
        ORDER BY 
            customer_count DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    // En çok iptal eden
    $most_cancellations = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            p.representative_id,
            COUNT(p.id) as cancellation_count,
            COALESCE(SUM(p.refunded_amount), 0) as refunded_amount,
            r.user_id,
            u.display_name,
            r.title
        FROM 
            {$table_policies} p
        LEFT JOIN 
            {$table_reps} r ON p.representative_id = r.id
        LEFT JOIN 
            {$wpdb->users} u ON r.user_id = u.ID
        WHERE 
            p.representative_id IN ({$rep_ids_str})
            AND p.cancellation_date BETWEEN %s AND %s
        GROUP BY 
            p.representative_id
        ORDER BY 
            cancellation_count DESC
        LIMIT 1",
        $start_datetime, $end_datetime
    ));
    
    return [
        'top_producer' => $top_producer,
        'most_new_business' => $most_new_business,
        'most_new_customers' => $most_new_customers,
        'most_cancellations' => $most_cancellations
    ];
}

$performance_metrics = get_performance_metrics($rep_ids, $date_filter_period, $filter_start_date, $filter_end_date);

function generate_panel_url($view, $action = '', $id = '', $additional_params = array()) {
    $base_url = get_permalink();
    $query_args = array();
    
    if ($view !== 'dashboard') {
        $query_args['view'] = $view;
    }
    
    if (!empty($action)) {
        $query_args['action'] = $action;
    }
    
    if (!empty($id)) {
        $query_args['id'] = $id;
    }
    
    if (!empty($additional_params) && is_array($additional_params)) {
        $query_args = array_merge($query_args, $additional_params);
    }
    
    if (empty($query_args)) {
        return $base_url;
    }
    
    return add_query_arg($query_args, $base_url);
}

// Activity Log tablosunu oluştur (eğer yoksa)
function create_activity_log_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'insurance_crm_activity_logs';
    
    // Tablo var mı kontrol et
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        // Tablo oluştur
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action_type varchar(50) NOT NULL,
            item_type varchar(50) NOT NULL,
            item_id bigint(20) NOT NULL,
            details text NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log('Activity log tablosu oluşturuldu.');
    }
}

// Activity Log tablosunu oluştur
create_activity_log_table();

add_action('wp_enqueue_scripts', 'insurance_crm_rep_panel_scripts');
function insurance_crm_rep_panel_scripts() {
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1', true);
}

// CRM version
$crm_version = '2.8.05';
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?php wp_head(); ?>
</head>

<body class="insurance-crm-page">
    <div class="insurance-crm-sidenav">
        <div class="sidenav-header">
            <?php
            $settings = get_option('insurance_crm_settings', array());
            $company_name = !empty($settings['company_name']) ? $settings['company_name'] : get_bloginfo('name');
            $logo_url = !empty($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : plugins_url('/assets/images/insurance-logo.png', dirname(__FILE__));
            ?>
            <div class="sidenav-logo">
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name); ?> Logo">
            </div>
            <h3><?php echo esc_html($company_name); ?></h3>
        </div>

        <div class="sidenav-user">
            <div class="user-avatar">
                <?php 
                // Temsilci bilgilerini al
                global $wpdb;
                $rep = $wpdb->get_row($wpdb->prepare(
                    "SELECT avatar_url FROM {$wpdb->prefix}insurance_crm_representatives 
                     WHERE user_id = %d AND status = 'active'",
                    $current_user->ID
                ));
                
                if ($rep && !empty($rep->avatar_url)): 
                ?>
                    <img src="<?php echo esc_url($rep->avatar_url); ?>" alt="<?php echo esc_attr($current_user->display_name); ?>">
                <?php else: ?>
                    <?php echo get_avatar($current_user->ID, 64); ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <h4><?php echo esc_html($current_user->display_name); ?></h4>
                <span><?php echo esc_html($representative->title); ?></span>
                <span class="user-role <?php echo esc_attr($user_role); ?>-role"><?php echo esc_html(get_role_name($user_role)); ?></span>
            </div>
        </div>
        
        <nav class="sidenav-menu">
            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="<?php echo $current_view == 'dashboard' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?php echo generate_panel_url('customers'); ?>" class="<?php echo $current_view == 'customers' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-groups"></i>
                <span>Müşterilerim</span>
            </a>
            <a href="<?php echo generate_panel_url('policies'); ?>" class="<?php echo $current_view == 'policies' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-portfolio"></i>
                <span>Poliçelerim</span>
            </a>
            <a href="<?php echo generate_panel_url('tasks'); ?>" class="<?php echo $current_view == 'tasks' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-calendar-alt"></i>
                <span>Görevlerim</span>
            </a>
            <a href="<?php echo generate_panel_url('reports'); ?>" class="<?php echo $current_view == 'reports' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-chart-area"></i>
                <span>Raporlar</span>
            </a>
            <a href="<?php echo generate_panel_url('iceri_aktarim'); ?>" class="<?php echo $current_view == 'iceri_aktarim' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-upload"></i>
                <span>İçeri Aktarım</span>
            </a>
            
            <?php if (has_full_admin_access($current_user->ID) || is_team_leader($current_user->ID)): ?>
            <!-- Organizasyon Yönetimi Menüsü -->
            <div class="sidenav-submenu">
                <a href="javascript:void(0);" class="submenu-toggle <?php echo in_array($current_view, ['organization', 'all_personnel', 'team_add', 'representative_add', 'boss_settings']) ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-networking"></i>
                    <span>Organizasyon Yönetimi</span>
                    <i class="submenu-arrow fas fa-chevron-right"></i>
                </a>
                <div class="submenu-items" style="display: none;">
                    <?php if (has_full_admin_access($current_user->ID)): ?>
                    <a href="<?php echo generate_panel_url('organization'); ?>" class="<?php echo $current_view == 'organization' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-networking"></i>
                        <span>Organizasyon Yapısı</span>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="<?php echo $current_view == 'all_personnel' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Tüm Personel</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_add'); ?>" class="<?php echo $current_view == 'team_add' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Yeni Ekip Oluştur</span>
                    </a>
                    <a href="<?php echo generate_panel_url('representative_add'); ?>" class="<?php echo $current_view == 'representative_add' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-users"></i>
                        <span>Yeni Temsilci Ekle</span>
                    </a>
                    <?php if (has_full_admin_access($current_user->ID)): ?>
                    <a href="<?php echo generate_panel_url('boss_settings'); ?>" class="<?php echo $current_view == 'boss_settings' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-generic"></i>
                        <span>Yönetim Ayarları</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (is_team_leader($current_user->ID)): ?>
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('team'); ?>" class="<?php echo $current_view == 'team' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-groups"></i>
                    <span>Ekip Performansı</span>
                </a>
                <div class="submenu-items" style="display: none;">
                    <a href="<?php echo generate_panel_url('team_policies'); ?>" class="<?php echo $current_view == 'team_policies' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-portfolio"></i>
                        <span>Ekip Poliçeleri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_customers'); ?>" class="<?php echo $current_view == 'team_customers' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Ekip Müşterileri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_tasks'); ?>" class="<?php echo $current_view == 'team_tasks' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-calendar-alt"></i>
                        <span>Ekip Görevleri</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_reports'); ?>" class="<?php echo $current_view == 'team_reports' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-chart-area"></i>
                        <span>Ekip Raporları</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <a href="<?php echo generate_panel_url('settings'); ?>" class="<?php echo $current_view == 'settings' ? 'active' : ''; ?>">
                <i class="dashicons dashicons-admin-generic"></i>
                <span>Ayarlar</span>
            </a>
        </nav>
        
        <div class="sidenav-footer">
            <a href="<?php echo wp_logout_url(home_url('/temsilci-girisi')); ?>" class="logout-button">
                <i class="dashicons dashicons-exit"></i>
                <span>Çıkış Yap</span>
            </a>
        </div>
    </div>

    <div class="insurance-crm-main">
        <header class="main-header">
            <div class="header-left">
                <button id="sidenav-toggle">
                    <i class="dashicons dashicons-menu"></i>
                </button>
                <h2>Dashboard</h2>
            </div>
            
            <div class="header-right">
                <div class="search-box">
                    <form action="<?php echo generate_panel_url('search'); ?>" method="get">
                        <i class="dashicons dashicons-search"></i>
                        <input type="text" name="keyword" placeholder="Ad, TC No, Çocuk Tc No.." value="<?php echo isset($_GET['keyword']) ? esc_attr($_GET['keyword']) : ''; ?>">
                        <input type="hidden" name="view" value="search">
                    </form>
                </div>
                
                <div class="quick-actions">
                    <button class="quick-add-btn" id="quick-add-toggle">
                        <i class="dashicons dashicons-plus-alt"></i>
                        <span>Hızlı Ekle</span>
                    </button>
                    
                    <div class="quick-add-dropdown">
                        <a href="<?php echo generate_panel_url('customers', 'new'); ?>" class="add-customer">
                            <i class="dashicons dashicons-groups"></i>
                            <span>Yeni Müşteri</span>
                        </a>
                        <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="add-policy">
                            <i class="dashicons dashicons-portfolio"></i>
                            <span>Yeni Poliçe</span>
                        </a>
                        <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="add-task">
                            <i class="dashicons dashicons-calendar-alt"></i>
                            <span>Yeni Görev</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="main-content">
            <!-- Modern Header Information Card -->
            <div class="dashboard-info-header">
                <div class="info-card main-info">
                    <div class="info-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="info-content">
                        <h2>Dashboard Yönetimi</h2>
                        <p>Performans göstergeleri ve sistem durumu</p>
                    </div>
                    <div class="info-details">
                        <div class="detail-item">
                            <span class="detail-label">Versiyon:</span>
                            <span class="detail-value"><?php echo esc_html($crm_version); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Kullanıcı Yetkisi:</span>
                            <span class="detail-value role-badge <?php echo esc_attr($user_role); ?>-role">
                                <?php echo esc_html(get_role_name($user_role)); ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Son Güncelleme:</span>
                            <span class="detail-value"><?php echo date('d.m.Y H:i'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enhanced Date Filter -->
            <div class="date-filter-container modern-filter">
                <div class="filter-header">
                    <div class="filter-title">
                        <i class="fas fa-calendar-alt"></i>
                        <h3>Zaman Aralığı Filtresi</h3>
                    </div>
                    <div class="current-period">
                        <span class="period-label">Aktif Dönem:</span>
                        <span class="period-value"><?php echo esc_html($filter_title); ?></span>
                    </div>
                </div>
                
                <form method="get" action="<?php echo generate_panel_url('dashboard'); ?>" class="modern-filter-form">
                    <div class="filter-buttons-grid">
                        <a href="<?php echo add_date_filter_to_url(generate_panel_url('dashboard'), 'this_month'); ?>" 
                           class="filter-button <?php echo $date_filter_period == 'this_month' ? 'active' : ''; ?>">
                            <i class="far fa-calendar-check"></i>
                            <span>Bu Ay</span>
                        </a>
                        <a href="<?php echo add_date_filter_to_url(generate_panel_url('dashboard'), 'last_3_months'); ?>" 
                           class="filter-button <?php echo $date_filter_period == 'last_3_months' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-minus"></i>
                            <span>Son 3 Ay</span>
                        </a>
                        <a href="<?php echo add_date_filter_to_url(generate_panel_url('dashboard'), 'last_6_months'); ?>" 
                           class="filter-button <?php echo $date_filter_period == 'last_6_months' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Son 6 Ay</span>
                        </a>
                        <a href="<?php echo add_date_filter_to_url(generate_panel_url('dashboard'), 'this_year'); ?>" 
                           class="filter-button <?php echo $date_filter_period == 'this_year' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-day"></i>
                            <span>Bu Yıl</span>
                        </a>
                        <button type="button" id="custom-date-toggle" 
                                class="filter-button custom-button <?php echo $date_filter_period == 'custom' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-plus"></i>
                            <span>Özel Tarih</span>
                        </button>
                    </div>
                    
                    <div id="custom-date-panel" class="custom-date-panel" 
                         style="<?php echo $date_filter_period == 'custom' ? 'display: block;' : 'display: none;'; ?>">
                        <div class="custom-date-inputs">
                            <div class="date-input-group">
                                <label><i class="fas fa-calendar-minus"></i> Başlangıç Tarihi</label>
                                <input type="date" name="start_date" value="<?php echo esc_attr($custom_start_date); ?>" required>
                            </div>
                            <div class="date-input-group">
                                <label><i class="fas fa-calendar-plus"></i> Bitiş Tarihi</label>
                                <input type="date" name="end_date" value="<?php echo esc_attr($custom_end_date); ?>" required>
                            </div>
                            <input type="hidden" name="date_filter" value="custom">
                            <button type="submit" class="apply-filter-btn">
                                <i class="fas fa-filter"></i>
                                Filtrele
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Main Statistics Grid -->
            <div class="modern-stats-grid">
                <div class="stat-card customers-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i>
                            <span><?php echo number_format($customer_increase_rate, 1); ?>%</span>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($total_customers); ?></h3>
                        <p>Toplam Müşteri</p>
                        <div class="stat-details">
                            <span class="detail-text"><?php echo $filter_title; ?> eklenen: +<?php echo $new_customers; ?></span>
                        </div>
                    </div>
                </div>

                <div class="stat-card policies-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <div class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i>
                            <span><?php echo number_format(($new_policies / max($total_policies, 1)) * 100, 1); ?>%</span>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($total_policies); ?></h3>
                        <p>Toplam Poliçe</p>
                        <div class="stat-details">
                            <span class="detail-text"><?php echo $filter_title; ?> eklenen: +<?php echo $new_policies; ?></span>
                        </div>
                    </div>
                </div>

                <div class="stat-card production-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i>
                            <span><?php echo number_format($premium_increase_rate, 1); ?>%</span>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3>₺<?php echo number_format($total_premium, 0, ',', '.'); ?></h3>
                        <p>Toplam Üretim</p>
                        <div class="stat-details">
                            <span class="detail-text"><?php echo $filter_title; ?> eklenen: +₺<?php echo number_format($new_premium, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="stat-card target-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-bullseye"></i>
                        </div>
                        <div class="stat-trend neutral">
                            <i class="fas fa-clock"></i>
                            <span>Bu Ay</span>
                        </div>
                    </div>
                    <div class="stat-content">
                        <h3>₺<?php echo number_format($current_month_premium, 0, ',', '.'); ?></h3>
                        <p>Bu Ay Üretim</p>
                        <div class="stat-details">
                            <span class="detail-text">Aylık hedef performansı</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collapsible Performance Metrics - Only for Patron -->
            <?php if (is_patron($current_user->ID)): ?>
            <div class="collapsible-section performance-metrics-section">
                <div class="section-header" id="performance-metrics-toggle">
                    <div class="section-title">
                        <i class="fas fa-trophy"></i>
                        <h3>Performans Analizi</h3>
                    </div>
                    <div class="section-toggle">
                        <i class="fas fa-chevron-down"></i>
                    </div>
                </div>
                
                <div class="section-content" id="performance-metrics-content" style="display: none;">
                    <div class="performance-cards-grid">
                        <div class="performance-card top-producer">
                            <div class="card-icon">
                                <i class="fas fa-crown"></i>
                            </div>
                            <div class="card-content">
                                <h4>En Çok Üretim Yapan</h4>
                                <?php if (!empty($performance_metrics['top_producer'])): ?>
                                    <div class="performer-info">
                                        <span class="performer-name"><?php echo esc_html($performance_metrics['top_producer']->display_name); ?></span>
                                        <span class="performer-value">₺<?php echo number_format($performance_metrics['top_producer']->total_premium, 0, ',', '.'); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="no-data">Veri bulunamadı</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="performance-card new-business">
                            <div class="card-icon">
                                <i class="fas fa-rocket"></i>
                            </div>
                            <div class="card-content">
                                <h4>En Çok Yeni İş</h4>
                                <?php if (!empty($performance_metrics['most_new_business'])): ?>
                                    <div class="performer-info">
                                        <span class="performer-name"><?php echo esc_html($performance_metrics['most_new_business']->display_name); ?></span>
                                        <span class="performer-value"><?php echo $performance_metrics['most_new_business']->policy_count; ?> poliçe</span>
                                    </div>
                                <?php else: ?>
                                    <span class="no-data">Veri bulunamadı</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="performance-card new-customers">
                            <div class="card-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="card-content">
                                <h4>En Çok Yeni Müşteri</h4>
                                <?php if (!empty($performance_metrics['most_new_customers'])): ?>
                                    <div class="performer-info">
                                        <span class="performer-name"><?php echo esc_html($performance_metrics['most_new_customers']->display_name); ?></span>
                                        <span class="performer-value"><?php echo $performance_metrics['most_new_customers']->customer_count; ?> müşteri</span>
                                    </div>
                                <?php else: ?>
                                    <span class="no-data">Veri bulunamadı</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="performance-card cancellations">
                            <div class="card-icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="card-content">
                                <h4>En Çok İptali Olan</h4>
                                <?php if (!empty($performance_metrics['most_cancellations'])): ?>
                                    <div class="performer-info">
                                        <span class="performer-name"><?php echo esc_html($performance_metrics['most_cancellations']->display_name); ?></span>
                                        <span class="performer-value"><?php echo $performance_metrics['most_cancellations']->cancellation_count; ?> iptal</span>
                                    </div>
                                <?php else: ?>
                                    <span class="no-data">Veri bulunamadı</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Organization Management Collapsible Section -->
            <?php if (has_full_admin_access($current_user->ID) || is_team_leader($current_user->ID)): ?>
            <div class="collapsible-section org-management-section">
                <div class="section-header" id="org-management-toggle">
                    <div class="section-title">
                        <i class="fas fa-sitemap"></i>
                        <h3>Organizasyon Yönetimi</h3>
                    </div>
                    <div class="section-toggle">
                        <i class="fas fa-chevron-down"></i>
                    </div>
                </div>
                
                <div class="section-content" id="org-management-content" style="display: none;">
                    <div class="quick-actions-grid">
                        <?php if (has_full_admin_access($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('organization'); ?>" class="quick-action-card">
                            <div class="action-icon">
                                <i class="fas fa-sitemap"></i>
                            </div>
                            <div class="action-content">
                                <h4>Organizasyon Yapısı</h4>
                                <p>Hiyerarşi ve rol tanımlamaları</p>
                            </div>
                        </a>
                        <?php endif;                        ?>
                        <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="quick-action-card">
                            <div class="action-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="action-content">
                                <h4>Tüm Personel</h4>
                                <p>Personel listesi ve yönetimi</p>
                            </div>
                        </a>
                        
                        <a href="<?php echo generate_panel_url('team_add'); ?>" class="quick-action-card">
                            <div class="action-icon">
                                <i class="fas fa-users-cog"></i>
                            </div>
                            <div class="action-content">
                                <h4>Yeni Ekip Oluştur</h4>
                                <p>Ekip tanımlama ve üye atama</p>
                            </div>
                        </a>
                        
                        <a href="<?php echo generate_panel_url('representative_add'); ?>" class="quick-action-card">
                            <div class="action-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="action-content">
                                <h4>Yeni Temsilci Ekle</h4>
                                <p>Temsilci kaydı ve rol atama</p>
                            </div>
                        </a>
                        
                        <?php if (has_full_admin_access($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('boss_settings'); ?>" class="quick-action-card">
                            <div class="action-icon">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div class="action-content">
                                <h4>Yönetim Ayarları</h4>
                                <p>Sistem ve güvenlik ayarları</p>
                            </div>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Charts and Analytics Section -->
            <div class="analytics-section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-chart-bar"></i>
                        <h3>Analitik Göstergeleri</h3>
                    </div>
                    <div class="chart-controls">
                        <button type="button" id="charts-toggle" class="btn btn-outline">
                            <i class="fas fa-chevron-up"></i>
                            <span>Grafikleri Gizle</span>
                        </button>
                    </div>
                </div>
                
                <div class="charts-container" id="charts-container">
                    <div class="charts-grid">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h4><i class="fas fa-chart-line"></i> Aylık Üretim Trendi</h4>
                            </div>
                            <div class="chart-body">
                                <canvas id="productionChart" width="400" height="300"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h4><i class="fas fa-pie-chart"></i> Poliçe Dağılımı</h4>
                            </div>
                            <div class="chart-body">
                                <canvas id="policyDistributionChart" width="400" height="300"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h4><i class="fas fa-chart-bar"></i> Müşteri Artışı</h4>
                            </div>
                            <div class="chart-body">
                                <canvas id="customerGrowthChart" width="400" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats Tables -->
            <div class="quick-stats-section">
                <div class="stats-tabs">
                    <div class="tab-buttons">
                        <button class="tab-button active" data-tab="renewals">
                            <i class="fas fa-sync-alt"></i>
                            <span>Yaklaşan Yenilemeler</span>
                        </button>
                        <button class="tab-button" data-tab="expired">
                            <i class="fas fa-clock"></i>
                            <span>Süresi Geçmiş Poliçeler</span>
                        </button>
                        <button class="tab-button" data-tab="recent">
                            <i class="fas fa-plus-circle"></i>
                            <span>Son Eklenen Poliçeler</span>
                        </button>
                    </div>
                    
                    <div class="tab-content">
                        <!-- Yaklaşan Yenilemeler -->
                        <div class="tab-panel active" id="renewals-panel">
                            <div class="table-container">
                                <table class="modern-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th>Tür</th>
                                            <th>Bitiş Tarihi</th>
                                            <th>Prim</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $upcoming_renewals = $wpdb->get_results($wpdb->prepare(
                                            "SELECT p.*, c.first_name, c.last_name 
                                             FROM {$wpdb->prefix}insurance_crm_policies p
                                             LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
                                             WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                                             AND p.end_date BETWEEN %s AND %s
                                             AND p.status = 'aktif'
                                             AND p.cancellation_date IS NULL
                                             ORDER BY p.end_date ASC
                                             LIMIT 10",
                                            ...array_merge($rep_ids, [date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))])
                                        ));
                                        
                                        if (!empty($upcoming_renewals)):
                                            foreach ($upcoming_renewals as $policy):
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="policy-number"><?php echo esc_html($policy->policy_number); ?></span>
                                            </td>
                                            <td>
                                                <span class="customer-name"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></span>
                                            </td>
                                            <td>
                                                <span class="policy-type"><?php echo esc_html($policy->policy_type); ?></span>
                                            </td>
                                            <td>
                                                <span class="end-date"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></span>
                                                <span class="days-remaining">
                                                    <?php 
                                                    $days_left = ceil((strtotime($policy->end_date) - time()) / (60*60*24));
                                                    echo $days_left . ' gün kaldı';
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="premium">₺<?php echo number_format($policy->premium_amount, 2); ?></span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="action-btn view-btn" title="Görüntüle">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-btn renew-btn" title="Yenile">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="6" class="no-data">Yaklaşan yenileme bulunamadı</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Süresi Geçmiş Poliçeler -->
                        <div class="tab-panel" id="expired-panel">
                            <div class="table-container">
                                <table class="modern-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th>Tür</th>
                                            <th>Bitiş Tarihi</th>
                                            <th>Geciken Gün</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $expired_policies = $wpdb->get_results($wpdb->prepare(
                                            "SELECT p.*, c.first_name, c.last_name 
                                             FROM {$wpdb->prefix}insurance_crm_policies p
                                             LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
                                             WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                                             AND p.end_date < %s
                                             AND p.status = 'aktif'
                                             AND p.cancellation_date IS NULL
                                             ORDER BY p.end_date DESC
                                             LIMIT 10",
                                            ...array_merge($rep_ids, [date('Y-m-d')])
                                        ));
                                        
                                        if (!empty($expired_policies)):
                                            foreach ($expired_policies as $policy):
                                        ?>
                                        <tr class="expired-row">
                                            <td>
                                                <span class="policy-number"><?php echo esc_html($policy->policy_number); ?></span>
                                            </td>
                                            <td>
                                                <span class="customer-name"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></span>
                                            </td>
                                            <td>
                                                <span class="policy-type"><?php echo esc_html($policy->policy_type); ?></span>
                                            </td>
                                            <td>
                                                <span class="end-date expired"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></span>
                                            </td>
                                            <td>
                                                <span class="overdue-days">
                                                    <?php 
                                                    $overdue_days = floor((time() - strtotime($policy->end_date)) / (60*60*24));
                                                    echo $overdue_days . ' gün';
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="action-btn view-btn" title="Görüntüle">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-btn renew-btn" title="Yenile">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="6" class="no-data">Süresi geçmiş poliçe bulunamadı</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Son Eklenen Poliçeler -->
                        <div class="tab-panel" id="recent-panel">
                            <div class="table-container">
                                <table class="modern-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th>Tür</th>
                                            <th>Başlangıç Tarihi</th>
                                            <th>Prim</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $recent_policies = $wpdb->get_results($wpdb->prepare(
                                            "SELECT p.*, c.first_name, c.last_name 
                                             FROM {$wpdb->prefix}insurance_crm_policies p
                                             LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
                                             WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                                             AND p.cancellation_date IS NULL
                                             ORDER BY p.created_at DESC
                                             LIMIT 10",
                                            ...$rep_ids
                                        ));
                                        
                                        if (!empty($recent_policies)):
                                            foreach ($recent_policies as $policy):
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="policy-number"><?php echo esc_html($policy->policy_number); ?></span>
                                            </td>
                                            <td>
                                                <span class="customer-name"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></span>
                                            </td>
                                            <td>
                                                <span class="policy-type"><?php echo esc_html($policy->policy_type); ?></span>
                                            </td>
                                            <td>
                                                <span class="start-date"><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></span>
                                            </td>
                                            <td>
                                                <span class="premium">₺<?php echo number_format($policy->premium_amount, 2); ?></span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="action-btn view-btn" title="Görüntüle">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="action-btn edit-btn" title="Düzenle">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="6" class="no-data">Son eklenen poliçe bulunamadı</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modern CSS Styles -->
        <style>
            :root {
                --primary-color: #0073aa;
                --primary-dark: #005a87;
                --secondary-color: #6c757d;
                --success-color: #28a745;
                --warning-color: #ffc107;
                --danger-color: #dc3545;
                --info-color: #17a2b8;
                --light-color: #f8f9fa;
                --dark-color: #343a40;
                --border-radius: 8px;
                --box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                --transition: all 0.3s ease;
            }

            /* Modern Info Header */
            .dashboard-info-header {
                margin-bottom: 24px;
            }

            .info-card {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 16px;
                padding: 24px;
                color: white;
                box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
                position: relative;
                overflow: hidden;
            }

            .info-card::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 50%;
                transform: scale(0);
                transition: transform 0.6s ease;
            }

            .info-card:hover::before {
                transform: scale(1);
            }

            .main-info {
                display: flex;
                align-items: center;
                gap: 20px;
                position: relative;
                z-index: 1;
            }

            .info-icon {
                width: 64px;
                height: 64px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 28px;
                flex-shrink: 0;
            }

            .info-content h2 {
                margin: 0 0 8px 0;
                font-size: 24px;
                font-weight: 600;
            }

            .info-content p {
                margin: 0;
                opacity: 0.9;
                font-size: 16px;
            }

            .info-details {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-left: auto;
            }

            .detail-item {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
            }

            .detail-label {
                opacity: 0.8;
            }

            .detail-value {
                font-weight: 600;
            }

            .role-badge {
                background: rgba(255, 255, 255, 0.2);
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            /* Enhanced Date Filter */
            .date-filter-container {
                background: white;
                border-radius: 16px;
                padding: 24px;
                margin-bottom: 24px;
                box-shadow: var(--box-shadow);
                border: 1px solid #e9ecef;
            }

            .filter-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 2px solid #f8f9fa;
            }

            .filter-title {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .filter-title h3 {
                margin: 0;
                font-size: 20px;
                font-weight: 600;
                color: var(--dark-color);
            }

            .filter-title i {
                color: var(--primary-color);
                font-size: 20px;
            }

            .current-period {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                background: linear-gradient(135deg, #0073aa, #005a87);
                color: white;
                border-radius: 25px;
                font-size: 14px;
            }

            .period-label {
                opacity: 0.9;
            }

            .period-value {
                font-weight: 600;
            }

            .filter-buttons-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .filter-button {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                padding: 16px 12px;
                background: white;
                border: 2px solid #e9ecef;
                border-radius: 12px;
                text-decoration: none;
                color: var(--secondary-color);
                transition: var(--transition);
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
            }

            .filter-button:hover {
                border-color: var(--primary-color);
                color: var(--primary-color);
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(0, 115, 170, 0.2);
            }

            .filter-button.active {
                background: var(--primary-color);
                border-color: var(--primary-color);
                color: white;
                box-shadow: 0 4px 15px rgba(0, 115, 170, 0.3);
            }

            .filter-button i {
                font-size: 18px;
            }

            .custom-date-panel {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 20px;
                margin-top: 16px;
            }

            .custom-date-inputs {
                display: grid;
                grid-template-columns: 1fr 1fr auto;
                gap: 16px;
                align-items: end;
            }

            .date-input-group label {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                font-weight: 500;
                color: var(--dark-color);
            }

            .date-input-group input {
                width: 100%;
                padding: 12px 16px;
                border: 2px solid #e9ecef;
                border-radius: 8px;
                font-size: 14px;
                transition: var(--transition);
            }

            .date-input-group input:focus {
                border-color: var(--primary-color);
                outline: none;
                box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.1);
            }

            .apply-filter-btn {
                padding: 12px 24px;
                background: var(--primary-color);
                color: white;
                border: none;
                border-radius: 8px;
                font-weight: 500;
                cursor: pointer;
                transition: var(--transition);
                display: flex;
                align-items: center;
                gap: 8px;
                white-space: nowrap;
            }

            .apply-filter-btn:hover {
                background: var(--primary-dark);
                transform: translateY(-1px);
            }

            /* Modern Stats Grid */
            .modern-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 24px;
                margin-bottom: 32px;
            }

            .stat-card {
                background: white;
                border-radius: 16px;
                padding: 24px;
                box-shadow: var(--box-shadow);
                border: 1px solid #e9ecef;
                transition: var(--transition);
                position: relative;
                overflow: hidden;
            }

            .stat-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            }

            .stat-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            }

            .customers-card::before {
                background: linear-gradient(90deg, var(--success-color), #20c997);
            }

            .policies-card::before {
                background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            }

            .production-card::before {
                background: linear-gradient(90deg, var(--warning-color), #fd7e14);
            }

            .target-card::before {
                background: linear-gradient(90deg, var(--danger-color), #e83e8c);
            }

            .stat-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 16px;
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                color: white;
            }

            .customers-card .stat-icon {
                background: linear-gradient(135deg, var(--success-color), #20c997);
            }

            .policies-card .stat-icon {
                background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            }

            .production-card .stat-icon {
                background: linear-gradient(135deg, var(--warning-color), #fd7e14);
            }

            .target-card .stat-icon {
                background: linear-gradient(135deg, var(--danger-color), #e83e8c);
            }

            .stat-trend {
                display: flex;
                align-items: center;
                gap: 4px;
                padding: 4px 8px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }

            .stat-trend.positive {
                background: rgba(40, 167, 69, 0.1);
                color: var(--success-color);
            }

            .stat-trend.neutral {
                background: rgba(108, 117, 125, 0.1);
                color: var(--secondary-color);
            }

            .stat-content h3 {
                margin: 0 0 8px 0;
                font-size: 32px;
                font-weight: 700;
                color: var(--dark-color);
            }

            .stat-content p {
                margin: 0 0 12px 0;
                font-size: 16px;
                font-weight: 500;
                color: var(--secondary-color);
            }

            .stat-details {
                font-size: 14px;
                color: var(--secondary-color);
            }

            .detail-text {
                font-style: italic;
            }

            /* Collapsible Sections */
            .collapsible-section {
                background: white;
                border-radius: 16px;
                margin-bottom: 24px;
                box-shadow: var(--box-shadow);
                border: 1px solid #e9ecef;
                overflow: hidden;
            }

            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 20px 24px;
                background: linear-gradient(135deg, #f8f9fa, #e9ecef);
                cursor: pointer;
                transition: var(--transition);
                border-bottom: 1px solid #e9ecef;
            }

            .section-header:hover {
                background: linear-gradient(135deg, #e9ecef, #dee2e6);
            }

            .section-title {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .section-title h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: var(--dark-color);
            }

            .section-title i {
                color: var(--primary-color);
                font-size: 18px;
            }

            .section-toggle {
                transition: var(--transition);
            }

            .section-toggle.rotated {
                transform: rotate(180deg);
            }

            .section-content {
                padding: 24px;
            }

            /* Performance Cards Grid */
            .performance-cards-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
            }

            .performance-card {
                background: linear-gradient(135deg, #f8f9fa, white);
                border: 1px solid #e9ecef;
                border-radius: 12px;
                padding: 20px;
                transition: var(--transition);
                position: relative;
                overflow: hidden;
            }

            .performance-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 3px;
            }

            .performance-card.top-producer::before {
                background: linear-gradient(90deg, #ffd700, #ffb347);
            }

            .performance-card.new-business::before {
                background: linear-gradient(90deg, var(--primary-color), var(--info-color));
            }

            .performance-card.new-customers::before {
                background: linear-gradient(90deg, var(--success-color), #20c997);
            }

            .performance-card.cancellations::before {
                background: linear-gradient(90deg, var(--danger-color), #e83e8c);
            }

            .performance-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }

            .performance-card .card-icon {
                width: 40px;
                height: 40px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                color: white;
                margin-bottom: 16px;
            }

            .top-producer .card-icon {
                background: linear-gradient(135deg, #ffd700, #ffb347);
            }

            .new-business .card-icon {
                background: linear-gradient(135deg, var(--primary-color), var(--info-color));
            }

            .new-customers .card-icon {
                background: linear-gradient(135deg, var(--success-color), #20c997);
            }

            .cancellations .card-icon {
                background: linear-gradient(135deg, var(--danger-color), #e83e8c);
            }

            .card-content h4 {
                margin: 0 0 12px 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--dark-color);
            }

            .performer-info {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .performer-name {
                font-weight: 600;
                color: var(--dark-color);
            }

            .performer-value {
                font-size: 18px;
                font-weight: 700;
                color: var(--primary-color);
            }

            .no-data {
                color: var(--secondary-color);
                font-style: italic;
            }

            /* Quick Actions Grid */
            .quick-actions-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 20px;
            }

            .quick-action-card {
                background: white;
                border: 2px solid #e9ecef;
                border-radius: 12px;
                padding: 20px;
                text-decoration: none;
                color: inherit;
                transition: var(--transition);
                display: flex;
                align-items: center;
                gap: 16px;
            }

            .quick-action-card:hover {
                border-color: var(--primary-color);
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(0, 115, 170, 0.15);
            }

            .action-icon {
                width: 48px;
                height: 48px;
                background: linear-gradient(135deg, var(--primary-color), var(--info-color));
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                color: white;
                flex-shrink: 0;
            }

            .action-content h4 {
                margin: 0 0 8px 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--dark-color);
            }

            .action-content p {
                margin: 0;
                font-size: 14px;
                color: var(--secondary-color);
            }

            /* Analytics Section */
            .analytics-section {
                background: white;
                border-radius: 16px;
                margin-bottom: 24px;
                box-shadow: var(--box-shadow);
                border: 1px solid #e9ecef;
                overflow: hidden;
            }

            .charts-container {
                padding: 24px;
            }

            .charts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 24px;
            }

            .chart-card {
                background: #f8f9fa;
                border-radius: 12px;
                overflow: hidden;
                border: 1px solid #e9ecef;
            }

            .chart-header {
                padding: 16px 20px;
                background: white;
                border-bottom: 1px solid #e9ecef;
            }

            .chart-header h4 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--dark-color);
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .chart-body {
                padding: 20px;
                background: white;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                border: 2px solid transparent;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: var(--transition);
                background: none;
            }

            .btn-outline {
                border-color: #e9ecef;
                color: var(--secondary-color);
            }

            .btn-outline:hover {
                border-color: var(--primary-color);
                color: var(--primary-color);
            }

            /* Quick Stats Tables */
            .quick-stats-section {
                background: white;
                border-radius: 16px;
                box-shadow: var(--box-shadow);
                border: 1px solid #e9ecef;
                overflow: hidden;
            }

            .stats-tabs {
                display: flex;
                flex-direction: column;
            }

            .tab-buttons {
                display: flex;
                background: #f8f9fa;
                border-bottom: 1px solid #e9ecef;
            }

            .tab-button {
                flex: 1;
                padding: 16px 20px;
                background: none;
                border: none;
                border-bottom: 3px solid transparent;
                cursor: pointer;
                transition: var(--transition);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                font-size: 14px;
                font-weight: 500;
                color: var(--secondary-color);
            }

            .tab-button:hover {
                background: rgba(0, 115, 170, 0.05);
                color: var(--primary-color);
            }

            .tab-button.active {
                background: white;
                border-bottom-color: var(--primary-color);
                color: var(--primary-color);
            }

            .tab-content {
                position: relative;
            }

            .tab-panel {
                display: none;
                padding: 24px;
            }

            .tab-panel.active {
                display: block;
            }

            /* Modern Table Styles */
            .table-container {
                overflow-x: auto;
            }

            .modern-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
            }

            .modern-table th {
                background: #f8f9fa;
                padding: 16px 12px;
                text-align: left;
                font-weight: 600;
                color: var(--dark-color);
                border-bottom: 2px solid #e9ecef;
                white-space: nowrap;
            }

            .modern-table td {
                padding: 16px 12px;
                border-bottom: 1px solid #f1f3f4;
                vertical-align: middle;
            }

            .modern-table tbody tr {
                transition: var(--transition);
            }

            .modern-table tbody tr:hover {
                background: rgba(0, 115, 170, 0.02);
            }

            .modern-table tbody tr.expired-row {
                background: rgba(220, 53, 69, 0.05);
            }

            .modern-table tbody tr.expired-row:hover {
                background: rgba(220, 53, 69, 0.1);
            }

            /* Table Cell Styles */
            .policy-number {
                font-weight: 600;
                color: var(--primary-color);
            }

            .customer-name {
                font-weight: 500;
                color: var(--dark-color);
            }

            .policy-type {
                padding: 4px 8px;
                background: rgba(0, 115, 170, 0.1);
                color: var(--primary-color);
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }

            .end-date {
                font-weight: 500;
            }

            .end-date.expired {
                color: var(--danger-color);
                font-weight: 600;
            }

            .days-remaining,
            .overdue-days {
                display: block;
                font-size: 12px;
                margin-top: 4px;
                padding: 2px 6px;
                border-radius: 8px;
                font-weight: 500;
            }

            .days-remaining {
                background: rgba(255, 193, 7, 0.1);
                color: #e6a500;
            }

            .overdue-days {
                background: rgba(220, 53, 69, 0.1);
                color: var(--danger-color);
            }

            .premium {
                font-weight: 600;
                color: var(--success-color);
            }

            .start-date {
                font-weight: 500;
                color: var(--dark-color);
            }

            .no-data {
                text-align: center;
                color: var(--secondary-color);
                font-style: italic;
                padding: 40px 20px;
            }

            /* Table Actions */
            .table-actions {
                display: flex;
                gap: 8px;
            }

            .action-btn {
                width: 32px;
                height: 32px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
                font-size: 14px;
                transition: var(--transition);
                border: 1px solid transparent;
            }

            .view-btn {
                background: rgba(0, 115, 170, 0.1);
                color: var(--primary-color);
                border-color: rgba(0, 115, 170, 0.2);
            }

            .view-btn:hover {
                background: var(--primary-color);
                color: white;
            }

            .edit-btn {
                background: rgba(255, 193, 7, 0.1);
                color: #e6a500;
                border-color: rgba(255, 193, 7, 0.2);
            }

            .edit-btn:hover {
                background: #e6a500;
                color: white;
            }

            .renew-btn {
                background: rgba(40, 167, 69, 0.1);
                color: var(--success-color);
                border-color: rgba(40, 167, 69, 0.2);
            }

            .renew-btn:hover {
                background: var(--success-color);
                color: white;
            }

            /* Responsive Design */
            @media (max-width: 1200px) {
                .modern-stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .performance-cards-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .charts-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 768px) {
                .insurance-crm-sidenav {
                    width: 0;
                    overflow: hidden;
                }
                
                .insurance-crm-sidenav.show {
                    width: 260px;
                }
                
                .insurance-crm-main {
                    margin-left: 0;
                }
                
                .modern-stats-grid,
                .performance-cards-grid {
                    grid-template-columns: 1fr;
                }
                
                .filter-buttons-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .custom-date-inputs {
                    grid-template-columns: 1fr;
                }
                
                .tab-buttons {
                    flex-direction: column;
                }
                
                .info-card.main-info {
                    flex-direction: column;
                    text-align: center;
                }
                
                .info-details {
                    margin-left: 0;
                    margin-top: 16px;
                }
                
                .quick-actions-grid {
                    grid-template-columns: 1fr;
                }
                
                .quick-action-card {
                    flex-direction: column;
                    text-align: center;
                }
            }

            @media (min-width: 769px) {
                .insurance-crm-sidenav {
                    width: 260px !important;
                }
                
                .insurance-crm-main {
                    margin-left: 260px !important;
                }
            }
        </style>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Toggle sidenav on mobile
                const sidenavToggle = document.getElementById('sidenav-toggle');
                const sidenav = document.querySelector('.insurance-crm-sidenav');
                
                if (sidenavToggle) {
                    sidenavToggle.addEventListener('click', function() {
                        sidenav.classList.toggle('show');
                    });
                }
                
                // Toggle submenu
                const submenuToggles = document.querySelectorAll('.submenu-toggle');
                
                submenuToggles.forEach(toggle => {
                    toggle.addEventListener('click', function() {
                        const parent = this.closest('.sidenav-submenu');
                        const submenuItems = this.nextElementSibling;
                        const arrow = this.querySelector('.submenu-arrow');
                        
                        if (submenuItems.style.display === 'none' || !submenuItems.style.display) {
                            submenuItems.style.display = 'block';
                            parent.classList.add('open');
                            if (arrow) arrow.style.transform = 'rotate(90deg)';
                        } else {
                            submenuItems.style.display = 'none';
                            parent.classList.remove('open');
                            if (arrow) arrow.style.transform = 'rotate(0deg)';
                        }
                    });
                });
                
                // Custom date filter toggle
                const customDateToggle = document.getElementById('custom-date-toggle');
                const customDatePanel = document.getElementById('custom-date-panel');
                
                if (customDateToggle && customDatePanel) {
                    customDateToggle.addEventListener('click', function() {
                        if (customDatePanel.style.display === 'none' || customDatePanel.style.display === '') {
                            customDatePanel.style.display = 'block';
                            this.classList.add('active');
                        } else {
                            customDatePanel.style.display = 'none';
                            this.classList.remove('active');
                        }
                    });
                }
                
                // Performance metrics toggle (only for patron)
                const performanceToggle = document.getElementById('performance-metrics-toggle');
                const performanceContent = document.getElementById('performance-metrics-content');
                
                if (performanceToggle && performanceContent) {
                    performanceToggle.addEventListener('click', function() {
                        const isVisible = performanceContent.style.display === 'block';
                        const toggle = this.querySelector('.section-toggle');
                        
                        if (isVisible) {
                            performanceContent.style.display = 'none';
                            toggle.classList.remove('rotated');
                        } else {
                            performanceContent.style.display = 'block';
                            toggle.classList.add('rotated');
                        }
                    });
                }
                
                // Organization management toggle
                const orgToggle = document.getElementById('org-management-toggle');
                const orgContent = document.getElementById('org-management-content');
                
                if (orgToggle && orgContent) {
                    orgToggle.addEventListener('click', function() {
                        const isVisible = orgContent.style.display === 'block';
                        const toggle = this.querySelector('.section-toggle');
                        
                        if (isVisible) {
                            orgContent.style.display = 'none';
                            toggle.classList.remove('rotated');
                        } else {
                            orgContent.style.display = 'block';
                            toggle.classList.add('rotated');
                        }
                    });
                }
                
                // Charts toggle
                const chartsToggle = document.getElementById('charts-toggle');
                const chartsContainer = document.getElementById('charts-container');
                
                if (chartsToggle && chartsContainer) {
                    chartsToggle.addEventListener('click', function() {
                        const isVisible = chartsContainer.style.display !== 'none';
                        const icon = this.querySelector('i');
                        const text = this.querySelector('span');
                        
                        if (isVisible) {
                            chartsContainer.style.display = 'none';
                            icon.className = 'fas fa-chevron-down';
                            text.textContent = 'Grafikleri Göster';
                        } else {
                            chartsContainer.style.display = 'block';
                            icon.className = 'fas fa-chevron-up';
                            text.textContent = 'Grafikleri Gizle';
                        }
                    });
                }
                
                // Tab switching functionality
                const tabButtons = document.querySelectorAll('.tab-button');
                const tabPanels = document.querySelectorAll('.tab-panel');
                
                tabButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const targetTab = this.getAttribute('data-tab');
                        
                        // Remove active class from all buttons and panels
                        tabButtons.forEach(btn => btn.classList.remove('active'));
                        tabPanels.forEach(panel => panel.classList.remove('active'));
                        
                        // Add active class to clicked button and corresponding panel
                        this.classList.add('active');
                        document.getElementById(targetTab + '-panel').classList.add('active');
                    });
                });
                
                // Initialize charts
                initializeCharts();
            });
            
            function initializeCharts() {
                // Production Chart
                const productionChart = document.getElementById('productionChart');
                if (productionChart) {
                    new Chart(productionChart, {
                        type: 'line',
                        data: {
                            labels: ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran'],
                            datasets: [{
                                label: 'Aylık Üretim (₺)',
                                data: [120000, 150000, 180000, 160000, 200000, 220000],
                                borderColor: '#0073aa',
                                backgroundColor: 'rgba(0, 115, 170, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '₺' + value.toLocaleString('tr-TR');
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                
                // Policy Distribution Chart
                const policyChart = document.getElementById('policyDistributionChart');
                if (policyChart) {
                    new Chart(policyChart, {
                        type: 'doughnut',
                        data: {
                            labels: ['Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık'],
                            datasets: [{
                                data: [35, 25, 20, 12, 8],
                                backgroundColor: [
                                    '#0073aa',
                                    '#28a745',
                                    '#ffc107',
                                    '#dc3545',
                                    '#17a2b8'
                                ],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }
                
                // Customer Growth Chart
                const customerChart = document.getElementById('customerGrowthChart');
                if (customerChart) {
                    new Chart(customerChart, {
                        type: 'bar',
                        data: {
                            labels: ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran'],
                            datasets: [{
                                label: 'Yeni Müşteriler',
                                data: [12, 18, 15, 22, 28, 25],
                                backgroundColor: 'rgba(40, 167, 69, 0.8)',
                                borderColor: '#28a745',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
            }
        </script>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
