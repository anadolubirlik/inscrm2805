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
 * Ekip Detay sayfası için fonksiyon
 */
function generate_team_detail_url($team_id) {
    return generate_panel_url('team_detail', '', '', array('team_id' => $team_id));
}

// Kullanıcının rolünü belirle
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Dashboard görünümü ve menülere göre yetkili temsilci ID'lerini al
$rep_ids = get_dashboard_representatives($current_user->ID, $current_view);

// Ekip hedefi hesaplama
$team_target = 0;
$team_policy_target = 0;
if ($current_view === 'team' || strpos($current_view, 'team_') === 0 || $user_role == 'patron' || $user_role == 'manager') {
    $targets = $wpdb->get_results($wpdb->prepare(
        "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
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

// Ekip performans verilerini sıralama
if (!empty($member_performance)) {
    // Premium'a göre sıralama (en yüksekten en düşüğe)
    usort($member_performance, function($a, $b) {
        return $b['premium'] <=> $a['premium'];
    });
}

// Mevcut sorguları ekip için uyarlama
$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));

$this_month_start = date('Y-m-01 00:00:00');
$this_month_end = date('Y-m-t 23:59:59');
$new_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND created_at BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
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
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$new_policies = $new_policies ?: 0;

$this_month_cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($rep_ids, [$this_month_start, $this_month_end])
));
$this_month_cancelled_policies = $this_month_cancelled_policies ?: 0;

$policy_increase_rate = $total_policies > 0 ? ($new_policies / $total_policies) * 100 : 0;

$total_refunded_amount = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) 
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")",
    ...$rep_ids
));
$total_refunded_amount = $total_refunded_amount ?: 0;

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
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
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

$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND p.cancellation_date IS NULL
     ORDER BY p.created_at DESC
     LIMIT 5",
    ...$rep_ids
));

$monthly_production_data = array();
$monthly_refunded_data = array();
for ($i = 5; $i >= 0; $i--) {
    $month_year = date('Y-m', strtotime("-$i months"));
    $monthly_production_data[$month_year] = 0;
    $monthly_refunded_data[$month_year] = 0;
}

try {
    $actual_data = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(start_date, '%%Y-%%m') as month_year, 
                COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0) as total,
                COALESCE(SUM(refunded_amount), 0) as refunded
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
         AND start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
         GROUP BY month_year
         ORDER BY month_year ASC",
        ...$rep_ids
    ));
    
    foreach ($actual_data as $data) {
        if (isset($monthly_production_data[$data->month_year])) {
            $monthly_production_data[$data->month_year] = (float)$data->total;
            $monthly_refunded_data[$data->month_year] = (float)$data->refunded;
        }
    }
} catch (Exception $e) {
    error_log('Üretim verileri çekilirken hata: ' . $e->getMessage());
}

$monthly_production = array();
foreach ($monthly_production_data as $month_year => $total) {
    $monthly_production[] = array(
        'month' => $month_year,
        'total' => $total
    );
}

if ($wpdb->last_error) {
    error_log('SQL Hatası: ' . $wpdb->last_error);
}

$upcoming_renewals = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date ASC
     LIMIT 5",
    ...$rep_ids
));

$expired_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND p.end_date < CURDATE()
     AND p.status != 'iptal'
     AND p.cancellation_date IS NULL
     ORDER BY p.end_date DESC
     LIMIT 5",
    ...$rep_ids
));

$notification_count = 0;
$notifications_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}insurance_crm_notifications'") === $wpdb->prefix . 'insurance_crm_notifications';

if ($notifications_table_exists) {
    $notification_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_notifications
         WHERE user_id = %d AND is_read = 0",
        $current_user->ID
    ));
    if ($notification_count === null) $notification_count = 0;
}

$upcoming_tasks_count = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND status = 'pending'
     AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
    ...$rep_ids
));
if ($upcoming_tasks_count === null) $upcoming_tasks_count = 0;

$total_notification_count = $notification_count + $upcoming_tasks_count;

$upcoming_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND t.status = 'pending'
     AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY t.due_date ASC
     LIMIT 5",
    ...$rep_ids
));

$current_month_start = date('Y-m-01');
$next_month_end = date('Y-m-t', strtotime('+1 month'));

$calendar_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE_FORMAT(DATE(due_date), '%Y-%m-%d') as task_date, COUNT(*) as task_count
     FROM {$wpdb->prefix}insurance_crm_tasks
     WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
     AND status IN ('pending', 'in_progress')
     AND due_date BETWEEN %s AND %s
     GROUP BY DATE(due_date)",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $next_month_end . ' 23:59:59'])
));

if ($wpdb->last_error) {
    error_log('Takvim Görev Sorgusu Hatası: ' . $wpdb->last_error);
}

$upcoming_tasks_list = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
     AND t.status IN ('pending', 'in_progress')
     AND t.due_date BETWEEN %s AND %s
     ORDER BY t.due_date ASC
     LIMIT 5",
    ...array_merge($rep_ids, [$current_month_start . ' 00:00:00', $next_month_end . ' 23:59:59'])
));

if ($wpdb->last_error) {
    error_log('Yaklaşan Görevler Sorgusu Hatası: ' . $wpdb->last_error);
}

// Patron ve müdür için özel veri - tüm ekipler
$all_teams = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $settings = get_option('insurance_crm_settings', []);
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
    
    foreach ($teams as $team_id => $team) {
        $leader_data = $wpdb->get_row($wpdb->prepare(
            "SELECT r.id, u.display_name, r.title, r.monthly_target, r.target_policy_count 
             FROM {$wpdb->prefix}insurance_crm_representatives r 
             JOIN {$wpdb->users} u ON r.user_id = u.ID 
             WHERE r.id = %d",
            $team['leader_id']
        ));
        
        if ($leader_data) {
            // Ekip üyelerinin sayısı
            $member_count = count($team['members']);
            
            // Ekip toplam primi hesaplama
            $team_ids = array_merge([$team['leader_id']], $team['members']);
            $team_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                 FROM {$wpdb->prefix}insurance_crm_policies 
                 WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")",
                ...$team_ids
            )) ?: 0;
            
            // Ekip üyelerinin toplam hedefi
            $team_monthly_target = 0;
            $team_policy_target = 0;
            foreach ($team_ids as $id) {
                $member_target = $wpdb->get_row($wpdb->prepare(
                    "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
                    $id
                ));
                $team_monthly_target += $member_target ? floatval($member_target->monthly_target) : 0;
                $team_policy_target += $member_target ? intval($member_target->target_policy_count) : 0;
            }
            
            // Bu ay üretilen poliçe sayısı
            $month_start = date('Y-m-01 00:00:00');
            $month_end = date('Y-m-t 23:59:59');
            $team_month_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND start_date BETWEEN %s AND %s
                AND cancellation_date IS NULL",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            // Bu ay üretilen prim
            $team_month_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0)
                FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND start_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            // İptal poliçe sayısı ve tutarını al
            $cancelled_policies = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND cancellation_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            $cancelled_premium = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
                WHERE representative_id IN (" . implode(',', array_fill(0, count($team_ids), '%d')) . ")
                AND cancellation_date BETWEEN %s AND %s",
                ...array_merge($team_ids, [$month_start, $month_end])
            )) ?: 0;
            
            // Net primi hesapla
            $team_month_net_premium = $team_month_premium - $cancelled_premium;
            
            // Hedef gerçekleşme oranı
            $premium_achievement = $team_monthly_target > 0 ? ($team_month_net_premium / $team_monthly_target) * 100 : 0;
            $policy_achievement = $team_policy_target > 0 ? ($team_month_policies / $team_policy_target) * 100 : 0;
            
            $all_teams[] = [
                'id' => $team_id,
                'name' => $team['name'],
                'leader_id' => $team['leader_id'],
                'leader_name' => $leader_data->display_name,
                'leader_title' => $leader_data->title,
                'member_count' => $member_count,
                'total_premium' => $team_premium,
                'monthly_target' => $team_monthly_target,
                'policy_target' => $team_policy_target,
                'month_policies' => $team_month_policies,
                'month_premium' => $team_month_premium,
                'cancelled_policies' => $cancelled_policies,
                'cancelled_premium' => $cancelled_premium,
                'month_net_premium' => $team_month_net_premium,
                'premium_achievement' => $premium_achievement,
                'policy_achievement' => $policy_achievement,
                'members' => $team['members']
            ];
        }
    }
    
    // Ekipleri toplam prim miktarına göre sırala (en yüksekten en düşüğe)
    usort($all_teams, function($a, $b) {
        return $b['total_premium'] <=> $a['total_premium'];
    });
}

// Tüm temsilcilerin hedef ve performans verileri (yalnızca patron için)
$all_representatives_performance = [];
if ($user_role == 'patron' || $user_role == 'manager') {
    $all_representatives = $wpdb->get_results(
        "SELECT r.id, r.monthly_target, r.target_policy_count, u.display_name, r.title
         FROM {$wpdb->prefix}insurance_crm_representatives r 
         JOIN {$wpdb->users} u ON r.user_id = u.ID 
         WHERE r.status = 'active'
         ORDER BY u.display_name ASC"
    );
    
    foreach ($all_representatives as $rep) {
        $performance = get_representative_performance($rep->id);
        
        // İptal poliçe sayısı ve tutarını al
        $cancelled_policies = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE representative_id = %d 
             AND cancellation_date BETWEEN %s AND %s",
            $rep->id, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')
        )) ?: 0;
        
        $cancelled_premium = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
             WHERE representative_id = %d 
             AND cancellation_date BETWEEN %s AND %s",
            $rep->id, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')
        )) ?: 0;
        
        // Net primi hesapla
        $net_premium = $performance['current_month_premium'] - $cancelled_premium;
        
        // Hedef gerçekleşme oranları (net prim üzerinden)
        $premium_achievement = $rep->monthly_target > 0 ? 
            ($net_premium / $rep->monthly_target) * 100 : 0;
        $policy_achievement = $rep->target_policy_count > 0 ? 
            ($performance['current_month_policy_count'] / $rep->target_policy_count) * 100 : 0;
        
        $all_representatives_performance[] = [
            'id' => $rep->id,
            'name' => $rep->display_name,
            'title' => $rep->title,
            'monthly_target' => $rep->monthly_target,
            'target_policy_count' => $rep->target_policy_count,
            'total_premium' => $performance['total_premium'],
            'current_month_premium' => $performance['current_month_premium'],
            'cancelled_policies' => $cancelled_policies,
            'cancelled_premium' => $cancelled_premium,
            'net_premium' => $net_premium, 
            'total_policy_count' => $performance['total_policy_count'],
            'current_month_policy_count' => $performance['current_month_policy_count'],
            'premium_achievement' => $premium_achievement,
            'policy_achievement' => $policy_achievement
        ];
    }
    
    // Performans verilerine göre sırala (en yüksek premium)
    usort($all_representatives_performance, function($a, $b) {
        return $b['total_premium'] <=> $a['total_premium'];
    });
}

$search_results = array();
if ($current_view == 'search' && isset($_GET['keyword']) && !empty(trim($_GET['keyword']))) {
    $keyword = sanitize_text_field($_GET['keyword']);
    $search_query = "
        SELECT c.*, p.policy_number, CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) AS customer_name
        FROM {$wpdb->prefix}insurance_crm_customers c
        LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON c.id = p.customer_id
        WHERE c.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
        AND (
            CONCAT(TRIM(c.first_name), ' ', TRIM(c.last_name)) LIKE %s
            OR TRIM(c.tc_identity) LIKE %s
            OR TRIM(c.children_tc_identities) LIKE %s
            OR TRIM(p.policy_number) LIKE %s
        )
        GROUP BY c.id
        ORDER BY c.first_name ASC
        LIMIT 20
    ";
    
    $search_results = $wpdb->get_results($wpdb->prepare(
        $search_query,
        ...array_merge($rep_ids, [
            '%' . $wpdb->esc_like($keyword) . '%',
            '%' . $wpdb->esc_like($keyword) . '%',
            '%' . $wpdb->esc_like($keyword) . '%',
            '%' . $wpdb->esc_like($keyword) . '%'
        ])
    ));

    if ($wpdb->last_error) {
        error_log('Arama Sorgusu Hatası: ' . $wpdb->last_error);
    }
}

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
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
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
                <?php if ($user_role == 'patron'): ?>
                    <span class="user-role patron-role">Patron</span>
                <?php elseif ($user_role == 'manager'): ?>
                    <span class="user-role manager-role">Müdür</span>
                <?php elseif ($user_role == 'team_leader'): ?>
                    <span class="user-role leader-role">Ekip Lideri</span>
                <?php endif; ?>
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
            
            <?php if (is_patron($current_user->ID)): ?>
            <!-- Patron İçin Özel Menü -->
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('organization'); ?>" class="<?php echo $current_view == 'organization' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-networking"></i>
                    <span>Organizasyon Yönetimi</span>
                </a>
                <div class="submenu-items">
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="<?php echo $current_view == 'all_teams' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Tüm Ekipler</span>
                    </a>
                    <a href="<?php echo generate_panel_url('all_representatives'); ?>" class="<?php echo $current_view == 'all_representatives' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-businessperson"></i>
                        <span>Tüm Temsilciler</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives'); ?>" class="<?php echo $current_view == 'admin_panel' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-admin-users"></i>
                        <span>Yönetim Paneli</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (is_manager($current_user->ID)): ?>
            <!-- Müdür İçin Özel Menü -->
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('manager_dashboard'); ?>" class="<?php echo $current_view == 'manager_dashboard' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-businessman"></i>
                    <span>Müdür Paneli</span>
                </a>
                <div class="submenu-items">
                    <a href="<?php echo generate_panel_url('all_teams'); ?>" class="<?php echo $current_view == 'all_teams' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-groups"></i>
                        <span>Tüm Ekipler</span>
                    </a>
                    <a href="<?php echo generate_panel_url('team_leaders'); ?>" class="<?php echo $current_view == 'team_leaders' ? 'active' : ''; ?>">
                        <i class="dashicons dashicons-businessperson"></i>
                        <span>Ekip Liderleri</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (is_team_leader($current_user->ID)): ?>
            <div class="sidenav-submenu">
                <a href="<?php echo generate_panel_url('team'); ?>" class="<?php echo $current_view == 'team' ? 'active' : ''; ?>">
                    <i class="dashicons dashicons-groups"></i>
                    <span>Ekip Performansı</span>
                </a>
                <div class="submenu-items">
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
                <h2>
                    <?php 
                    switch($current_view) {
                        case 'customers':
                            echo 'Müşteriler';
                            break;
                        case 'policies':
                            echo 'Poliçeler';
                            break;
                        case 'tasks':
                            echo 'Görevler';
                            break;
                        case 'reports':
                            echo 'Raporlar';
                            break;
                        case 'settings':
                            echo 'Ayarlar';
                            break;
                        case 'search':
                            echo 'Arama Sonuçları';
                            break;
                        case 'team':
                            echo 'Ekip Performansı';
                            break;
                        case 'team_policies':
                            echo 'Ekip Poliçeleri';
                            break;
                        case 'team_customers':
                            echo 'Ekip Müşterileri';
                            break;
                        case 'team_tasks':
                            echo 'Ekip Görevleri';
                            break;
                        case 'team_reports':
                            echo 'Ekip Raporları';
                            break;
                        case 'organization':
                            echo 'Organizasyon Yönetimi';
                            break;
                        case 'all_teams':
                            echo 'Tüm Ekipler';
                            break;
                        case 'all_representatives':
                            echo 'Tüm Temsilciler';
                            break;
                        case 'manager_dashboard':
                            echo 'Müdür Paneli';
                            break;
                        case 'team_leaders':
                            echo 'Ekip Liderleri';
                            break;
                        case 'team_detail':
                            echo 'Ekip Detayı';
                            break;
                        case 'representative_detail':
                            echo 'Temsilci Detayı';
                            break;
                        case 'edit_representative':
                            echo 'Temsilci Düzenle';
                            break;
                        case 'edit_team':
                            echo 'Ekip Düzenle';
                            break;
                        default:
                            echo ($user_role == 'patron') ? 'Patron Dashboard' : 
                                (($user_role == 'manager') ? 'Müdür Dashboard' : 
                                (($user_role == 'team_leader') ? 'Ekip Lideri Dashboard' : 'Dashboard'));
                    }
                    ?>
                </h2>
            </div>
            
            <div class="header-right">
                <div class="search-box">
                    <form action="<?php echo generate_panel_url('search'); ?>" method="get">
                        <i class="dashicons dashicons-search"></i>
                        <input type="text" name="keyword" placeholder="Ad, TC No, Çocuk Tc No.." value="<?php echo isset($_GET['keyword']) ? esc_attr($_GET['keyword']) : ''; ?>">
                        <input type="hidden" name="view" value="search">
                    </form>
                </div>
                
                <div class="notification-bell">
                    <a href="#" id="notifications-toggle" title="Bildirimler">
                        <i class="dashicons dashicons-bell"></i>
                        <?php if ($total_notification_count > 0): ?>
                        <span class="notification-badge"><?php echo $total_notification_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="notifications-dropdown">
                        <div class="notifications-header">
                            <h3><i class="dashicons dashicons-bell"></i> Bildirimler</h3>
                            <a href="#" class="mark-all-read" title="Tümünü okundu işaretle"><i class="dashicons dashicons-yes-alt"></i> Tümünü okundu işaretle</a>
                        </div>
                        
                        <div class="notifications-list">
                            <?php if ($notifications_table_exists && $notification_count > 0): ?>
                                <?php 
                                $notifications = $wpdb->get_results($wpdb->prepare(
                                    "SELECT * FROM {$wpdb->prefix}insurance_crm_notifications
                                     WHERE user_id = %d AND is_read = 0
                                     ORDER BY created_at DESC
                                     LIMIT 5",
                                    $current_user->ID
                                ));
                                ?>
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item unread" data-id="<?php echo esc_attr($notification->id); ?>">
                                        <div class="notification-icon">
                                            <i class="dashicons dashicons-warning"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p><?php echo esc_html($notification->message); ?></p>
                                            <span class="notification-time">
                                                <i class="dashicons dashicons-clock"></i> <?php echo date_i18n('d.m.Y H:i', strtotime($notification->created_at)); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($upcoming_tasks)): ?>
                                <?php foreach ($upcoming_tasks as $task): ?>
                                    <div class="notification-item unread">
                                        <div class="notification-icon">
                                            <i class="dashicons dashicons-calendar-alt"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p>
                                                <strong>Görev:</strong> <?php echo esc_html($task->task_title); ?>
                                            <span class="notification-time">Son Tarih: <?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?>
                                            </span></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="dashicons dashicons-yes-alt"></i>
                                    </div>
                                    <div class="notification-content">
                                        <p>Yaklaşan görev bulunmuyor.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="notifications-footer">
                            <a href="<?php echo generate_panel_url('notifications'); ?>"><i class="dashicons dashicons-visibility"></i> Tüm bildirimleri gör</a>
                        </div>
                    </div>
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

        <?php if ($current_view == 'dashboard' || $current_view == 'team'): ?>
        <div class="main-content">
            <?php if ($user_role == 'patron'): ?>
            <!-- PATRON DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>Organizasyon Genel Bakış</h3>
                <p class="dashboard-subtitle">Tüm organizasyon için performans metrikleri ve genel bakış</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label">Toplam Müşteri</div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_customers; ?> Müşteri
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box policies-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-portfolio"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_policies); ?></div>
                        <div class="stat-label">Toplam Poliçe</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box production-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-chart-bar"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Toplam Üretim</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box target-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-performance"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Bu Ay Üretim</div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
                        <?php
                        $remaining_amount = max(0, $monthly_target - $current_month_premium);
                        ?>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- PATRON - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_teams'); ?>" class="text-button">Tüm Ekipler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>İptal Poliçe (₺)</th>
                                <th>Net Prim (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_teams as $team): ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['month_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell negative-value">₺<?php echo number_format($team['cancelled_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['month_net_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 2); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_team_detail_url($team['id']); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                        <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button button-primary">Yönetim Paneline Git</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PATRON - Temsilci Hedefleri ve Performansları -->
            <div class="dashboard-card target-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_representatives'); ?>" class="text-button">Tüm Temsilciler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_representatives_performance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci</th>
                                <th>Unvan</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>İptal Poliçe (₺)</th>
                                <th>Net Prim (₺)</th>
                                <th>Gerçekleşme (%)</th>
                                <th>İptal Poliçe</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_representatives_performance as $rep): ?>
                            <tr>
                                <td><?php echo esc_html($rep['name']); ?></td>
                                <td><?php echo esc_html($rep['title']); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($rep['monthly_target'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($rep['current_month_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell negative-value">₺<?php echo number_format($rep['cancelled_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($rep['net_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $rep['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($rep['premium_achievement'], 2); ?>%</div>
                                </td>
                                <td><?php echo $rep['cancelled_policies']; ?> adet</td>
                                <td>
                                    <a href="<?php echo generate_panel_url('representative_detail', '', $rep['id']); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($user_role == 'manager'): ?>
            <!-- MÜDÜR DASHBOARD İÇERİĞİ -->
            <div class="dashboard-header">
                <h3>Ekipler Yönetimi</h3>
                <p class="dashboard-subtitle">Sorumluluğunuz altındaki tüm ekipler ve ekip liderleri için performans metrikleri</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label">Tüm Ekipler Toplam Müşteri</div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_customers; ?> Müşteri
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box policies-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-portfolio"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_policies); ?></div>
                        <div class="stat-label">Tüm Ekipler Toplam Poliçe</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box production-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-chart-bar"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Tüm Ekipler Toplam Üretim</div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box target-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-performance"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label">Tüm Ekipler Bu Ay Üretim</div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Toplam Hedef: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
                        <?php
                        $remaining_amount = max(0, $monthly_target - $current_month_premium);
                        ?>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- MÜDÜR - Ekip Performans Tablosu -->
            <div class="dashboard-card team-performance-card">
                <div class="card-header">
                    <h3>Ekip Performansları</h3>
                    <div class="card-actions">
                        <a href="<?php echo generate_panel_url('all_teams'); ?>" class="text-button">Tüm Ekipler</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($all_teams)): ?>
                    <table class="data-table teams-table">
                        <thead>
                            <tr>
                                <th>Ekip Adı</th>
                                <th>Ekip Lideri</th>
                                <th>Üye Sayısı</th>
                                <th>Toplam Prim (₺)</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>İptal Poliçe (₺)</th>
                                <th>Net Prim (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_teams as $team): ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                <td><?php echo $team['member_count']; ?> üye</td>
                                <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['month_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell negative-value">₺<?php echo number_format($team['cancelled_premium'], 2, ',', '.'); ?></td>
                                <td class="amount-cell">₺<?php echo number_format($team['month_net_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($team['premium_achievement'], 2); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_team_detail_url($team['id']); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="dashicons dashicons-groups"></i>
                        </div>
                        <h4>Henüz ekip tanımlanmamış</h4>
                        <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- MÜDÜR - Temsilci Performans Tablosu -->
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Temsilci Performansları</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($member_performance)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Temsilci Adı</th>
                                <th>Unvan</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo esc_html($member['title']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td>₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>₺<?php echo number_format($member['this_month_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement_rate']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement_rate'], 2); ?>%</div>
                                </td>
                                <td>
                                    <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="action-button view-button">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span>Detay</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($current_view == 'team' && !is_team_leader($current_user->ID)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="dashicons dashicons-groups"></i>
                </div>
                <h4>Yetkisiz Erişim</h4>
                <p>Ekip performansı sayfasını görüntülemek için ekip lideri olmalısınız.</p>
            </div>
           


<?php elseif ($current_view == 'team'): ?>
<div class="dashboard-header">
    <h3>Ekip Performans Özeti</h3>
    <p class="dashboard-subtitle">Ekibinizin performans göstergeleri ve hedef gerçekleşme durumu</p>
</div>

<div class="stats-grid">
    <div class="stat-box customers-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-groups"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($total_customers); ?></div>
            <div class="stat-label">Ekip Toplam Müşteri</div>
        </div>
        <div class="stat-change positive">
            <div class="stat-new">
                Bu ay eklenen: +<?php echo $new_customers; ?> Müşteri
            </div>
            <div class="stat-rate positive">
                <i class="dashicons dashicons-arrow-up-alt"></i>
                <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
            </div>
        </div>
    </div>
    
    <div class="stat-box policies-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-portfolio"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value"><?php echo number_format($total_policies); ?></div>
            <div class="stat-label">Ekip Toplam Poliçe</div>
            <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
        </div>
        <div class="stat-change positive">
            <div class="stat-new">
                Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
            </div>
            <div class="stat-new refund-info">
                Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
            </div>
            <div class="stat-rate positive">
                <i class="dashicons dashicons-arrow-up-alt"></i>
                <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
            </div>
        </div>
    </div>
    
    <div class="stat-box production-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-chart-bar"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
            <div class="stat-label">Ekip Toplam Üretim</div>
            <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
        </div>
        <div class="stat-change positive">
            <div class="stat-new">
                Bu ay eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
            </div>
            <div class="stat-rate positive">
                <i class="dashicons dashicons-arrow-up-alt"></i>
                <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
            </div>
        </div>
    </div>
    
    <div class="stat-box target-box">
        <div class="stat-icon">
            <i class="dashicons dashicons-performance"></i>
        </div>
        <div class="stat-details">
            <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
            <div class="stat-label">Ekip Bu Ay Üretim</div>
        </div>
        <div class="stat-target">
            <div class="target-text">Prim Hedefi: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
            <?php
            $remaining_amount = max(0, $monthly_target - $current_month_premium);
            ?>
            <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
            <div class="target-progress-mini">
                <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
            </div>
            
            <div class="target-text">Poliçe Hedefi: <?php echo $team_policy_target; ?> Adet</div>
            <div class="target-text">Gerçekleşen: <?php echo $new_policies; ?> Adet (<?php echo number_format($policy_achievement_rate, 2); ?>%)</div>
            <div class="target-progress-mini">
                <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="upper-section">
        <div class="dashboard-card chart-card">
            <div class="card-header">
                <h3>Ekip Aylık Üretim Performansı</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="productionChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="performance-section">
        <div class="dashboard-card performance-distribution-card">
            <div class="card-header">
                <h3>Performans Dağılımı</h3>
            </div>
            <div class="card-body">
                <div class="performance-layout">
                    <div class="team-contribution-chart">
                        <div class="pie-chart">
                            <canvas id="teamContributionChart"></canvas>
                        </div>
                        <h4 class="pie-chart-title">Ekip Katkı Dağılımı</h4>
                    </div>
                    
                    <div class="team-performance-table">
                        <?php if (!empty($member_performance)): ?>
                        <h4>Ekip Üyesi Performansı</h4>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Üye Adı</th>
                                    <th>Müşteri</th>
                                    <th>Poliçe</th>
                                    <th>Bu Ay Üretim</th>
                                    <th>Gerçekleşme</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($member_performance as $member): ?>
                                <tr>
                                    <td><?php echo esc_html($member['name']); ?></td>
                                    <td><?php echo number_format($member['customers']); ?></td>
                                    <td><?php echo number_format($member['policies']); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($member['this_month_premium'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement_rate']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($member['premium_achievement_rate'], 2); ?>%</div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <p>Ekipte performans verisi görüntülenecek üye bulunmamaktadır.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Ekip katkı dağılımını hazırla
$team_contribution_labels = [];
$team_contribution_data = [];
$team_contribution_colors = [
    'rgba(54, 162, 235, 0.8)', 
    'rgba(255, 99, 132, 0.8)',
    'rgba(255, 206, 86, 0.8)',
    'rgba(75, 192, 192, 0.8)',
    'rgba(153, 102, 255, 0.8)',
    'rgba(255, 159, 64, 0.8)',
    'rgba(201, 203, 207, 0.8)'
];

if (!empty($member_performance)) {
    $color_index = 0;
    foreach ($member_performance as $member) {
        $team_contribution_labels[] = $member['name'];
        $team_contribution_data[] = $member['this_month_premium'];
        $color_index = ($color_index + 1) % count($team_contribution_colors);
    }
}
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ekip Katkı Dağılımı Grafiği
    const teamContributionChart = document.getElementById('teamContributionChart');
    if (teamContributionChart) {
        new Chart(teamContributionChart, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($team_contribution_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($team_contribution_data); ?>,
                    backgroundColor: <?php echo json_encode($team_contribution_colors); ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ₺${value.toLocaleString('tr-TR', {minimumFractionDigits: 2})} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>


            <?php else: ?>
            <!-- NORMAL DASHBOARD VEYA EKİP LİDERİ DASHBOARD İÇERİĞİ -->
            <div class="stats-grid">
                <div class="stat-box customers-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-groups"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Toplam Müşteri' : 'Toplam Müşteri'; ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_customers; ?> Müşteri
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($customer_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box policies-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-portfolio"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value"><?php echo number_format($total_policies); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Toplam Poliçe' : 'Toplam Poliçe'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +<?php echo $new_policies; ?> Poliçe
                        </div>
                        <div class="stat-new refund-info">
                            Bu ay iptal edilen: <?php echo $this_month_cancelled_policies; ?> Poliçe
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($policy_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box production-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-chart-bar"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($total_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Toplam Üretim' : 'Toplam Üretim'; ?></div>
                        <div class="refund-info">Toplam İade: ₺<?php echo number_format($total_refunded_amount, 2, ',', '.'); ?></div>
                    </div>
                    <div class="stat-change positive">
                        <div class="stat-new">
                            Bu ay eklenen: +₺<?php echo number_format($new_premium, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-rate positive">
                            <i class="dashicons dashicons-arrow-up-alt"></i>
                            <span><?php echo number_format($premium_increase_rate, 2); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box target-box">
                    <div class="stat-icon">
                        <i class="dashicons dashicons-performance"></i>
                    </div>
                    <div class="stat-details">
                        <div class="stat-value">₺<?php echo number_format($current_month_premium, 2, ',', '.'); ?></div>
                        <div class="stat-label"><?php echo $current_view == 'team' ? 'Ekip Bu Ay Üretim' : 'Bu Ay Üretim'; ?></div>
                    </div>
                    <div class="stat-target">
                        <div class="target-text">Prim Hedefi: ₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></div>
                        <?php
                        $remaining_amount = max(0, $monthly_target - $current_month_premium);
                        ?>
                        <div class="target-text">Hedefe Kalan: ₺<?php echo number_format($remaining_amount, 2, ',', '.'); ?></div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $achievement_rate; ?>%"></div>
                        </div>
                        
                        <div class="target-text">Poliçe Hedefi: <?php echo $team_policy_target; ?> Adet</div>
                        <div class="target-text">Gerçekleşen: <?php echo $new_policies; ?> Adet (<?php echo number_format($policy_achievement_rate, 2); ?>%)</div>
                        <div class="target-progress-mini">
                            <div class="target-bar" style="width: <?php echo $policy_achievement_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($current_view == 'team' && !empty($member_performance)): ?>
            <div class="dashboard-card member-performance-card">
                <div class="card-header">
                    <h3>Üye Performansı</h3>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Üye Adı</th>
                                <th>Müşteri Sayısı</th>
                                <th>Poliçe Sayısı</th>
                                <th>Aylık Hedef (₺)</th>
                                <th>Bu Ay Üretim (₺)</th>
                                <th>Gerçekleşme</th>
                                <th>Hedef Poliçe</th>
                                <th>Bu Ay Poliçe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_performance as $member): ?>
                            <tr>
                                <td><?php echo esc_html($member['name']); ?></td>
                                <td><?php echo number_format($member['customers']); ?></td>
                                <td><?php echo number_format($member['policies']); ?></td>
                                <td>₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                <td>₺<?php echo number_format($member['this_month_premium'], 2, ',', '.'); ?></td>
                                <td>
                                    <div class="progress-mini">
                                        <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement_rate']); ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo number_format($member['premium_achievement_rate'], 2); ?>%</div>
                                </td>
                                <td><?php echo $member['target_policy_count']; ?> adet</td>
                                <td><?php echo $member['this_month_policies']; ?> adet</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="dashboard-grid">
                <div class="upper-section">
                    <div class="dashboard-card chart-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Aylık Üretim Performansı' : 'Aylık Üretim Performansı'; ?></h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="productionChart"></canvas>
                            </div>
                            <div class="production-table" style="margin-top: 20px;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Ay-Yıl</th>
                                            <th>Hedef (₺)</th>
                                            <th>Üretilen (₺)</th>
                                            <th>İade Edilen (₺)</th>
                                            <th>Gerçekleşme Oranı (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_production_data as $month_year => $total): ?>
                                            <?php 
                                            $dateParts = explode('-', $month_year);
                                            $year = $dateParts[0];
                                            $month = (int)$dateParts[1];
                                            $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 
                                                       'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                                            $month_name = $months[$month - 1] . ' ' . $year;
                                            $achievement_rate = $monthly_target > 0 ? ($total / $monthly_target) * 100 : 0;
                                            $achievement_rate = min(100, $achievement_rate);
                                            $refunded_amount = $monthly_refunded_data[$month_year];
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html($month_name); ?></td>
                                                <td>₺<?php echo number_format($monthly_target, 2, ',', '.'); ?></td>
                                                <td class="amount-cell">₺<?php echo number_format($total, 2, ',', '.'); ?></td>
                                                <td class="refund-info">₺<?php echo number_format($refunded_amount, 2, ',', '.'); ?></td>
                                                <td><?php echo number_format($achievement_rate, 2, ',', '.'); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>    
                    <?php
                    if (!$representative) {
                        echo '<div class="ab-notice ab-error">Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.</div>';
                    } else {
                    ?>
                      
<?php
// Görev yönetimi fonksiyonlarını dahil et
include_once dirname(__FILE__) . '/modules/task-management/task-functions.php';
?>


<?php
if (!$representative) {
    echo '<div class="ab-notice ab-error">Müşteri temsilcisi kaydınız bulunamadı veya hesabınız pasif durumda.</div>';
} else {
    // Görev özeti widget'ını dahil et
    include_once dirname(__FILE__) . '/modules/task-management/task-summary-widget.php';
}
?>                   

                    <?php } ?>
                </div>
                
                <div class="lower-section">
                    <div class="dashboard-card renewals-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Yaklaşan Yenilemeler' : 'Yaklaşan Yenilemeler'; ?></h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'renewals')); ?>" class="text-button">Tümünü Gör</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($upcoming_renewals)): ?>
                                <table class="data-table renewals-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_renewals as $policy): 
                                            $end_date = new DateTime($policy->end_date);
                                            $now = new DateTime();
                                            $days_remaining = $now->diff($end_date)->days;
                                            $urgency_class = '';
                                            if ($days_remaining <= 5) {
                                                $urgency_class = 'urgent';
                                            } elseif ($days_remaining <= 15) {
                                                $urgency_class = 'soon';
                                            }
                                        ?>
                                        <tr class="<?php echo $urgency_class; ?>">
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                </a>
                                            </td>
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-button renew-button">
                                                    <i class="dashicons dashicons-update"></i>
                                                    <span>Yenile</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-calendar-alt"></i>
                                    </div>
                                    <h4>Yaklaşan yenileme bulunmuyor</h4>
                                    <p>Önümüzdeki 30 gün içinde yenilenecek poliçe yok.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dashboard-card expired-policies-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Süresi Geçmiş Poliçeler' : 'Süresi Geçmiş Poliçeler'; ?></h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies', '', '', array('filter' => 'expired')); ?>" class="text-button">Tümünü Gör</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($expired_policies)): ?>
                                <table class="data-table expired-policies-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th class="hide-mobile">Gecikme</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expired_policies as $policy): 
                                            $end_date = new DateTime($policy->end_date);
                                            $now = new DateTime();
                                            $days_overdue = $end_date->diff($now)->days;
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                </a>
                                            </td>
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td class="days-overdue hide-mobile">
                                                <?php echo $days_overdue; ?> gün
                                            </td>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>" class="action-button renew-button">
                                                    <i class="dashicons dashicons-update"></i>
                                                    <span>Yenile</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-portfolio"></i>
                                    </div>
                                    <h4>Süresi geçmiş poliçe bulunmuyor</h4>
                                    <p>Tüm poliçeleriniz güncel.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dashboard-card recent-policies-card">
                        <div class="card-header">
                            <h3><?php echo $current_view == 'team' ? 'Ekip Son Eklenen Poliçeler' : 'Son Eklenen Poliçeler'; ?></h3>
                            <div class="card-actions">
                                <a href="<?php echo generate_panel_url('policies'); ?>" class="text-button">Tümünü Gör</a>
                                <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="card-option" title="Yeni Poliçe">
                                    <i class="dashicons dashicons-plus-alt"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_policies)): ?>
                                <table class="data-table policies-table">
                                    <thead>
                                        <tr>
                                            <th>Poliçe No</th>
                                            <th>Müşteri</th>
                                            <th class="hide-mobile">Tür</th>
                                            <th class="hide-mobile">Başlangıç</th>
                                            <th class="hide-mobile">Bitiş</th>
                                            <th class="hide-mobile">Tutar</th>
                                            <th class="hide-mobile">Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_policies as $policy): ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="policy-link">
                                                    <?php echo esc_html($policy->policy_number); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="user-info-cell">
                                                    <div class="user-avatar-mini">
                                                        <?php 
                                                        $initial = strtoupper(substr($policy->first_name, 0, 1));
                                                        echo $initial;
                                                        ?>
                                                    </div>
                                                    <span>
                                                        <a href="<?php echo generate_panel_url('customers', 'edit', $policy->customer_id); ?>">
                                                            <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                                        </a>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="hide-mobile"><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                            <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->end_date)); ?></td>
                                            <td class="amount-cell hide-mobile">₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                            <td class="hide-mobile">
                                                <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                                                    <?php echo esc_html(ucfirst($policy->status)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="<?php echo generate_panel_url('policies', 'edit', $policy->id); ?>" class="table-action" title="Düzenle">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                    <div class="table-action-dropdown-wrapper">
                                                        <button class="table-action table-action-more" title="Daha Fazla">
                                                            <i class="dashicons dashicons-ellipsis"></i>
                                                        </button>
                                                        <div class="table-action-dropdown">
                                                            <a href="<?php echo generate_panel_url('policies', 'renew', $policy->id); ?>">Yenile</a>
                                                            <a href="<?php echo generate_panel_url('policies', 'duplicate', $policy->id); ?>">Kopyala</a>
                                                            <?php if (can_delete_items($current_user->ID)): ?>
                                                            <a href="<?php echo generate_panel_url('policies', 'cancel', $policy->id); ?>" class="text-danger">İptal Et</a>
                                                            <?php else: ?>
                                                            <a href="<?php echo generate_panel_url('policies', 'deactivate', $policy->id); ?>" class="text-warning">Pasife Al</a>
                                                            <?php endif; ?>
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
                                    <div class="empty-icon">
                                        <i class="dashicons dashicons-portfolio"></i>
                                    </div>
                                    <h4>Henüz poliçe eklenmemiş</h4>
                                    <p>Sisteme poliçe ekleyerek müşterilerinizi takip edin.</p>
                                    <a href="<?php echo generate_panel_url('policies', 'new'); ?>" class="button button-primary">
                                        Yeni Poliçe Ekle
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php elseif ($current_view == 'search'): ?>
            <div class="main-content">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Arama Sonuçları</h3>
                        <div class="card-actions">
                            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="text-button">Dashboard'a Dön</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($search_results)): ?>
                            <table class="data-table search-results-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Ad Soyad', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('TC Kimlik', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Çocuk Ad Soyad', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Çocuk TC Kimlik', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('Poliçe No', 'insurance-crm'); ?></th>
                                        <th><?php esc_html_e('İşlemler', 'insurance-crm'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($search_results as $customer): ?>
                                        <tr>
                                            <td>
                                                <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" class="ab-customer-name">
                                                    <?php echo esc_html($customer->customer_name); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html($customer->tc_identity); ?></td>
                                            <td><?php echo esc_html($customer->children_names ?: '-'); ?></td>
                                            <td><?php echo esc_html($customer->children_tc_identities ?: '-'); ?></td>
                                            <td><?php echo esc_html($customer->policy_number ?: '-'); ?></td>
                                            <td>
                                                <div class="table-actions">
                                                    <a href="?view=customers&action=view&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="Görüntüle">
                                                        <i class="dashicons dashicons-visibility"></i>
                                                    </a>
                                                    <a href="?view=customers&action=edit&id=<?php echo esc_attr($customer->id); ?>" class="table-action" title="Düzenle">
                                                        <i class="dashicons dashicons-edit"></i>
                                                    </a>
                                                    <div class="table-action-dropdown-wrapper">
                                                        <button class="table-action table-action-more" title="Daha Fazla">
                                                            <i class="dashicons dashicons-ellipsis"></i>
                                                        </button>
                                                        <div class="table-action-dropdown">
                                                            <?php if (can_delete_items($current_user->ID)): ?>
                                                            <a href="?view=customers&action=delete&id=<?php echo esc_attr($customer->id); ?>" 
                                                               onclick="return confirm('Bu müşteriyi silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.')" class="text-danger">
                                                                <?php esc_html_e('Sil', 'insurance-crm'); ?>
                                                            </a>
                                                            <?php else: ?>
                                                            <a href="?view=customers&action=deactivate&id=<?php echo esc_attr($customer->id); ?>" 
                                                               onclick="return confirm('Bu müşteriyi pasife almak istediğinizden emin misiniz?')" class="text-warning">
                                                                <?php esc_html_e('Pasife Al', 'insurance-crm'); ?>
                                                            </a>
                                                            <?php endif; ?>
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
                                <div class="empty-icon"><i class="dashicons dashicons-search"></i></div>
                                <h4><?php esc_html_e('Sonuç Bulunamadı', 'insurance-crm'); ?></h4>
                                <p><?php esc_html_e('Aradığınız kritere uygun bir sonuç bulunamadı.', 'insurance-crm'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif ($current_view == 'all_teams' || $current_view == 'all_representatives' || $current_view == 'organization' || $current_view == 'manager_dashboard' || $current_view == 'team_leaders'): ?>
            <!-- YÖNETİM SEKME İÇERİKLERİ -->
            <div class="main-content">
                <?php if ($current_view == 'organization'): ?>
                <div class="dashboard-card hierarchy-card">
                    <div class="card-header">
                        <h3>Organizasyon Yapısı</h3>
                    </div>
                    <div class="card-body">
                        <?php 
                        // Organizasyon şeması için veri hazırlama
                        $settings = get_option('insurance_crm_settings', []);
                        $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
                        
                        $patron_data = null;
                        $manager_data = null;
                        
                        if (!empty($management_hierarchy['patron_id'])) {
                            $patron_data = $wpdb->get_row($wpdb->prepare(
                                "SELECT r.*, u.display_name 
                                 FROM {$wpdb->prefix}insurance_crm_representatives r 
                                 JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                 WHERE r.id = %d",
                                $management_hierarchy['patron_id']
                            ));
                        }
                        
                        if (!empty($management_hierarchy['manager_id'])) {
                            $manager_data = $wpdb->get_row($wpdb->prepare(
                                "SELECT r.*, u.display_name 
                                 FROM {$wpdb->prefix}insurance_crm_representatives r 
                                 JOIN {$wpdb->users} u ON r.user_id = u.ID 
                                 WHERE r.id = %d",
                                $management_hierarchy['manager_id']
                            ));
                        }
                        ?>
                        
                        <div class="org-chart">
                            <div class="org-level patron-level">
                                <div class="org-box patron-box">
                                    <div class="org-title">Patron</div>
                                    <div class="org-name">
                                        <?php echo $patron_data ? esc_html($patron_data->display_name) : '(Tanımlanmadı)'; ?>
                                    </div>
                                    <?php if ($patron_data): ?>
                                    <div class="org-subtitle"><?php echo esc_html($patron_data->title); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="org-connector"></div>
                            
                            <div class="org-level manager-level">
                                <div class="org-box manager-box">
                                    <div class="org-title">Müdür</div>
                                    <div class="org-name">
                                        <?php echo $manager_data ? esc_html($manager_data->display_name) : '(Tanımlanmadı)'; ?>
                                    </div>
                                    <?php if ($manager_data): ?>
                                    <div class="org-subtitle"><?php echo esc_html($manager_data->title); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="org-connector"></div>
                            
                            <div class="org-level team-leaders-level">
                                <?php if (!empty($all_teams)): ?>
                                    <?php foreach ($all_teams as $team): ?>
                                    <div class="org-box team-leader-box">
                                        <div class="org-title">Ekip Lideri</div>
                                        <div class="org-name"><?php echo esc_html($team['leader_name']); ?></div>
                                        <div class="org-subtitle"><?php echo esc_html($team['leader_title']); ?></div>
                                        <div class="org-team-name"><?php echo esc_html($team['name']); ?> Ekibi</div>
                                        <div class="org-team-count"><?php echo $team['member_count']; ?> Üye</div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <div class="org-box empty-box">
                                    <div class="org-title">Henüz Ekip Tanımlanmamış</div>
                                    <p>Ekip yapılandırması için yönetim paneline gidin.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="org-actions">
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=hierarchy'); ?>" class="button button-primary">
                                Yönetim Hiyerarşisini Düzenle
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button">
                                Ekipleri Düzenle
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($current_view == 'all_teams'): ?>
                <div class="dashboard-card teams-list-card">
                    <div class="card-header">
                        <h3>Tüm Ekipler</h3>
                        <?php if ($user_role == 'patron'): ?>
                        <div class="card-actions">
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams&action=new_team'); ?>" class="button button-primary">
                                Yeni Ekip Oluştur
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_teams)): ?>
                        <table class="data-table teams-table">
                            <thead>
                                <tr>
                                    <th>Ekip Adı</th>
                                    <th>Ekip Lideri</th>
                                    <th>Üye Sayısı</th>
                                    <th>Toplam Prim (₺)</th>
                                    <th>Aylık Hedef (₺)</th>
                                    <th>Bu Ay Üretim</th>
                                    <th>İptal Poliçe (₺)</th>
                                    <th>Net Prim (₺)</th>
                                    <th>Gerçekleşme Oranı</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_teams as $team): ?>
                                <tr>
                                    <td><?php echo esc_html($team['name']); ?></td>
                                    <td><?php echo esc_html($team['leader_name'] . ' (' . $team['leader_title'] . ')'); ?></td>
                                    <td><?php echo $team['member_count']; ?> üye</td>
                                    <td class="amount-cell">₺<?php echo number_format($team['total_premium'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($team['monthly_target'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($team['month_premium'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell negative-value">₺<?php echo number_format($team['cancelled_premium'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($team['month_net_premium'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $team['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($team['premium_achievement'], 2); ?>%</div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="<?php echo generate_team_detail_url($team['id']); ?>" class="table-action" title="Görüntüle">
                                                <i class="dashicons dashicons-visibility"></i>
                                            </a>
                                            <?php if ($user_role == 'patron'): ?>
                                            <a href="<?php echo generate_panel_url('edit_team', '', '', array('team_id' => $team['id'])); ?>" class="table-action" title="Düzenle">
                                                <i class="dashicons dashicons-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="dashicons dashicons-groups"></i>
                            </div>
                            <h4>Henüz ekip tanımlanmamış</h4>
                            <p>Organizasyon yapısını düzenlemek için yönetim paneline gidin.</p>
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" class="button button-primary">Yönetim Paneline Git</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($current_view == 'all_representatives'): ?>
                <div class="dashboard-card all-reps-card">
                    <div class="card-header">
                        <h3>Tüm Temsilciler</h3>
                        <?php if ($user_role == 'patron'): ?>
                        <div class="card-actions">
                            <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=new'); ?>" class="button button-primary">
                                Yeni Temsilci Ekle
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($all_representatives_performance)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Temsilci Adı</th>
                                    <th>Unvan</th>
                                    <th>Rol</th>
                                    <th>Aylık Hedef (₺)</th>
                                    <th>Bu Ay (₺)</th>
                                    <th>İptal Poliçe (₺)</th>
                                    <th>Net Prim (₺)</th>
                                    <th>Gerçekleşme</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Patron ve Müdür bilgilerini al
                                $settings = get_option('insurance_crm_settings', []);
                                $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
                                $patron_id = !empty($management_hierarchy['patron_id']) ? $management_hierarchy['patron_id'] : 0;
                                $manager_id = !empty($management_hierarchy['manager_id']) ? $management_hierarchy['manager_id'] : 0;
                                
                                // Ekip liderleri listesi
                                $team_leader_ids = array();
                                foreach ($all_teams as $team) {
                                    $team_leader_ids[] = $team['leader_id'];
                                }
                                
                                foreach ($all_representatives_performance as $rep): 
                                    // Rol belirleme
                                    $role = '';
                                    $role_class = '';
                                    
                                    if ($rep['id'] == $patron_id) {
                                        $role = 'Patron';
                                        $role_class = 'patron-role';
                                    } elseif ($rep['id'] == $manager_id) {
                                        $role = 'Müdür';
                                        $role_class = 'manager-role';
                                    } elseif (in_array($rep['id'], $team_leader_ids)) {
                                        $role = 'Ekip Lideri';
                                        $role_class = 'leader-role';
                                    } else {
                                        $role = 'Temsilci';
                                        $role_class = 'rep-role';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($rep['name']); ?></td>
                                    <td><?php echo esc_html($rep['title']); ?></td>
                                    <td><span class="role-badge <?php echo $role_class; ?>"><?php echo $role; ?></span></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep['monthly_target'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep['current_month_premium'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell negative-value">₺<?php echo number_format($rep['cancelled_premium'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($rep['net_premium'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $rep['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($rep['premium_achievement'], 2); ?>%</div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="<?php echo generate_panel_url('representative_detail', '', $rep['id']); ?>" class="table-action" title="Görüntüle">
                                                <i class="dashicons dashicons-visibility"></i>
                                            </a>
                                            <?php if ($user_role == 'patron'): ?>
                                            <a href="<?php echo generate_panel_url('edit_representative', '', $rep['id']); ?>" class="table-action" title="Düzenle">
                                                <i class="dashicons dashicons-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <p>Henüz temsilci performans verisi bulunmamaktadır.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($current_view == 'manager_dashboard' || $current_view == 'team_leaders'): ?>
                <!-- Müdür için ekip liderlerini gösterme -->
                <div class="dashboard-card team-leaders-card">
                    <div class="card-header">
                        <h3>Ekip Liderleri</h3>
                    </div>
                    <div class="card-body">
                        <?php
                        // Ekip liderleri listesi ve performansları
                        $team_leaders = array();
                        foreach ($all_teams as $team) {
                            foreach ($member_performance as $member) {
                                if ($member['id'] == $team['leader_id']) {
                                    $team_leaders[] = array(
                                        'id' => $member['id'],
                                        'name' => $member['name'],
                                        'title' => $member['title'],
                                        'team_name' => $team['name'],
                                        'team_size' => $team['member_count'],
                                        'monthly_target' => $member['monthly_target'],
                                        'this_month_premium' => $member['this_month_premium'],
                                        'premium_achievement_rate' => $member['premium_achievement_rate'],
                                        'target_policy_count' => $member['target_policy_count'],
                                        'this_month_policies' => $member['this_month_policies'],
                                        'policy_achievement_rate' => $member['policy_achievement_rate']
                                    );
                                    break;
                                }
                            }
                        }
                        
                        if (!empty($team_leaders)):
                        ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ekip Lideri</th>
                                    <th>Unvan</th>
                                    <th>Ekip Adı</th>
                                    <th>Ekip Boyutu</th>
                                    <th>Aylık Hedef (₺)</th>
                                    <th>Bu Ay (₺)</th>
                                    <th>Gerçekleşme</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($team_leaders as $leader): ?>
                                <tr>
                                    <td><?php echo esc_html($leader['name']); ?></td>
                                    <td><?php echo esc_html($leader['title']); ?></td>
                                    <td><?php echo esc_html($leader['team_name']); ?></td>
                                    <td><?php echo $leader['team_size']; ?> üye</td>
                                    <td class="amount-cell">₺<?php echo number_format($leader['monthly_target'], 2, ',', '.'); ?></td>
                                    <td class="amount-cell">₺<?php echo number_format($leader['this_month_premium'], 2, ',', '.'); ?></td>
                                    <td>
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $leader['premium_achievement_rate']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($leader['premium_achievement_rate'], 2); ?>%</div>
                                    </td>
                                    <td>
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $leader['id']); ?>" class="action-button view-button">
                                            <i class="dashicons dashicons-visibility"></i>
                                            <span>Detay</span>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="dashicons dashicons-businessperson"></i>
                            </div>
                            <h4>Henüz ekip lideri tanımlanmamış</h4>
                            <p>Ekip yapılandırması için yönetim paneline gidin.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php elseif ($current_view == 'representative_detail' || $current_view == 'team_detail'): ?>
            <?php 
            if ($current_view == 'representative_detail') {
                include_once(dirname(__FILE__) . '/representative_detail.php');
            } elseif ($current_view == 'team_detail') {
                include_once(dirname(__FILE__) . '/team_detail.php');
            }
            ?>
        <?php elseif ($current_view == 'edit_representative'): ?>
            <?php include_once(dirname(__FILE__) . '/edit_representative.php'); ?>
        <?php elseif ($current_view == 'edit_team'): ?>
            <?php include_once(dirname(__FILE__) . '/edit_team.php'); ?>
        <?php elseif ($current_view == 'customers' || $current_view == 'team_customers'): ?>
            <?php include_once(dirname(__FILE__) . '/customers.php'); ?>
        <?php elseif ($current_view == 'policies' || $current_view == 'team_policies'): ?>
            <?php include_once(dirname(__FILE__) . '/policies.php'); ?>
        <?php elseif ($current_view == 'tasks' || $current_view == 'team_tasks'): ?>
            <?php include_once(dirname(__FILE__) . '/tasks.php'); ?>
        <?php elseif ($current_view == 'reports' || $current_view == 'team_reports'): ?>
            <?php include_once(dirname(__FILE__) . '/reports.php'); ?>
        <?php elseif ($current_view == 'settings'): ?>
            <?php include_once(dirname(__FILE__) . '/settings.php'); ?>
        	
	<?php elseif ($current_view == 'notifications'): ?>
 <?php include_once(dirname(__FILE__) . '/notifications.php'); ?>
<?php elseif ($current_view == 'iceri_aktarim'): ?>
 <?php include_once(dirname(__FILE__) . '/iceri_aktarim.php'); ?>
<?php endif; ?>

        <style>
            .insurance-crm-page * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body.insurance-crm-page {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                background-color: #f5f7fa;
                color: #333;
                margin: 0;
                padding: 0;
                min-height: 50vh;
            }
            
            .insurance-crm-sidenav {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 260px;
                background: #1e293b;
                color: #fff;
                display: flex;
                flex-direction: column;
                z-index: 1000;
                transition: all 0.3s ease;
            }
            
            .sidenav-header {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .sidenav-logo {
                width: 40px;
                height: 40px;
                margin-right: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidenav-logo img {
                max-width: 100%;
                max-height: 100%;
            }
            
            .sidenav-header h3 {
                font-weight: 600;
                font-size: 18px;
                color: #fff;
            }
            
            .sidenav-user {
                padding: 20px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                overflow: hidden;
                margin-right: 12px;
            }
            
            .user-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .user-info h4 {
                font-size: 14px;
                font-weight: 600;
                color: #fff;
                margin: 0;
            }
            
            .user-info span {
                font-size: 12px;
                color: rgba(255,255,255,0.7);
            }
            
            .user-role {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 600;
                margin-top: 4px;
            }
            
            .patron-role {
                background: #4a148c;
                color: #fff;
            }
            
            .manager-role {
                background: #0d47a1;
                color: #fff;
            }
            
            .leader-role {
                background: #1b5e20;
                color: #fff;
            }
            
            .rep-role {
                background: #424242;
                color: #fff;
            }
            
            .role-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
            }
            
            .sidenav-menu {
                flex: 1;
                padding: 20px 0;
                overflow-y: auto;
            }
            
            .sidenav-menu a {
                display: flex;
                align-items: center;
                padding: 12px 20px;
                color: rgba(255,255,255,0.7);
                text-decoration: none;
                transition: all 0.2s ease;
            }
            
            .sidenav-menu a:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
            }
            
            .sidenav-menu a.active {
                background: rgba(0,115,170,0.8);
                color: #fff;
                border-right: 3px solid #fff;
            }
            
            .sidenav-menu a .dashicons {
                margin-right: 12px;
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
            
            .sidenav-submenu {
                padding: 0;
            }
            
            .sidenav-submenu > a {
                font-weight: 600;
            }
            
            .submenu-items {
                padding-left: 20px;
                background: rgba(0,0,0,0.1);
            }
            
            .submenu-items a {
                padding: 10px 20px;
                font-size: 14px;
            }
            
            .submenu-items a .dashicons {
                margin-right: 10px;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            
            .sidenav-footer {
                padding: 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }
            
            .logout-button {
                display: flex;
                align-items: center;
                color: rgba(255,255,255,0.7);
                padding: 10px;
                border-radius: 4px;
                text-decoration: none;
                transition: all 0.2s ease;
            }
            
            .logout-button:hover {
                background: rgba(255,255,255,0.1);
                color: #fff;
            }
            
            .logout-button .dashicons {
                margin-right: 8px;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            
            .insurance-crm-main {
                margin-left: 260px;
                min-height: 100vh;
                background: #f5f7fa;
                transition: all 0.3s ease;
            }
            
            .main-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 30px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                position: sticky;
                top: 0;
                z-index: 900;
            }
            
            .header-left {
                display: flex;
                align-items: center;
            }
            
            #sidenav-toggle {
                background: none;
                border: none;
                color: #555;
                font-size: 20px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 5px;
                margin-right: 15px;
            }
            
            .header-left h2 {
                font-size: 18px;
                font-weight: 600;
                color: #333;
                margin: 0;
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
                padding: 8px 15px 8px 35px;
                border: 1px solid #e0e0e0;
                border-radius: 20px;
                width: 250px;
                font-size: 14px;
                transition: all 0.3s;
            }
            
            .search-box input:focus {
                width: 300px;
                border-color: #0073aa;
                box-shadow: 0 0 0 2px rgba(0,115,170,0.2);
                outline: none;
            }
            
            .search-box .dashicons {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: #666;
            }
            
            .notification-bell {
                position: relative;
                margin-right: 20px;
            }
            
            .notification-bell a {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 20px;
                color: #555;
                transition: all 0.2s;
            }
            
            .notification-bell a:hover {
                background: #f0f0f0;
                color: #333;
            }
            
            .notification-badge {
                position: absolute;
                top: 5px;
                right: 5px;
                background: #dc3545;
                color: #fff;
                border-radius: 10px;
                min-width: 18px;
                height: 18px;
                font-size: 11px;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0 6px;
            }
            
            .notifications-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 320px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
                margin-top: 10px;
                display: none;
                overflow: hidden;
                z-index: 1000;
            }
            
            .notifications-dropdown.show {
                display: block;
            }
            
            .notifications-header {
                padding: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #eee;
            }
            
            .notifications-header h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
            }
            
            .mark-all-read {
                font-size: 12px;
                color: #0073aa;
                text-decoration: none;
            }
            
            .notifications-list {
                max-height: 300px;
                overflow-y: auto;
            }
            
            .notification-item {
                display: flex;
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
                transition: background 0.2s;
            }
            
            .notification-item:hover {
                background: #f9f9f9;
            }
            
            .notification-item.unread {
                background: #f0f7ff;
            }
            
            .notification-item .dashicons {
                margin-right: 12px;
                font-size: 20px;
                color: #0073aa;
            }
            
            .notification-content {
                flex: 1;
            }
            
            .notification-content p {
                margin: 0 0 5px;
                font-size: 14px;
                color: #333;
            }
            
            .notification-time {
                font-size: 12px;
                color: #777;
            }
            

.notifications-footer {
    padding: 15px 10px;
    text-align: center;
    border-top: 1px solid #eee;
}

.notifications-footer a {
    display: block; /* Alana tam yayılmasını sağlar */
    width: 100%; /* Genişlik %100, alanı kaplar */
    padding: 8px 0; /* Sağ-sol padding'i sıfırlayıp üst-alt 8px */
    margin: 10px 0;
    text-decoration: none;
    color: #333;
    border-radius: 4px;
    font-size: 14px; /* Font boyutunu küçült */
    letter-spacing: 0.5px; /* Harfler arası boşluk artır */
    box-sizing: border-box; /* Padding'in genişliği etkilemesini önler */
}

.notifications-footer a:hover {
    background-color: #f5f5f5;
}

.notifications-list {
    margin-bottom: 15px;
}

.notification-item {
    padding: 10px;
    margin-bottom: 5px;
}

            
            .quick-actions {
                position: relative;
            }
            
            .quick-add-btn {
                display: flex;
                align-items: center;
                background: #0073aa;
                color: #fff;
                border: none;
                padding: 8px 15px;
                border-radius: 4px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .quick-add-btn:hover {
                background: #005a87;
            }
            
            .quick-add-btn .dashicons {
                margin-right: 5px;
            }
            
            .quick-add-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                width: 200px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
                margin-top: 10px;
                display: none;
                overflow: hidden;
                z-index: 1000;
            }
            .quick-add-dropdown.show {
                display: block;
            }
            
            .quick-add-dropdown a {
                display: flex;
                align-items: center;
                padding: 12px 15px;
                color: #333;
                text-decoration: none;
                transition: background 0.2s;
            }
            
            .quick-add-dropdown a:hover {
                background: #f5f5f5;
            }
            
            .quick-add-dropdown a .dashicons {
                margin-right: 10px;
                color: #0073aa;
            }
            
            .main-content {
                padding: 30px;
            }
            
            /* Dashboard Header Styles */
            .dashboard-header {
                margin-bottom: 20px;
            }
            
            .dashboard-header h3 {
                font-size: 24px;
                font-weight: 600;
                color: #333;
                margin-bottom: 5px;
            }
            
            .dashboard-subtitle {
                font-size: 16px;
                color: #666;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .stat-box {
                background: white;
                border-radius: 10px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }
            
            .stat-box:hover {
                transform: translateY(-3px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            }
            
            .stat-icon {
                margin-bottom: 15px;
                width: 50px;
                height: 50px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .stat-icon .dashicons {
                font-size: 24px;
                color: white;
            }
            
            .customers-box .stat-icon {
                background: linear-gradient(135deg, #4e54c8, #8f94fb);
            }
            
            .policies-box .stat-icon {
                background: linear-gradient(135deg, #11998e, #38ef7d);
            }
            
            .production-box .stat-icon {
                background: linear-gradient(135deg, #F37335, #FDC830);
            }
            
            .target-box .stat-icon {
                background: linear-gradient(135deg, #536976, #292E49);
            }
            
            .stat-details {
                margin-bottom: 15px;
            }
            
            .stat-value {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 5px;
                color: #333;
            }
            
            .stat-label {
                font-size: 14px;
                color: #666;
            }
            
            .stat-change {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                font-size: 13px;
                margin-top: auto;
            }
            
            .stat-new {
                margin-bottom: 5px;
                color: #333;
            }
            
            .stat-rate.positive {
                display: flex;
                align-items: center;
                color: #28a745;
            }
            
            .stat-rate .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 2px;
            }
            
            .stat-target {
                margin-top: auto;
            }
            
            .target-text {
                font-size: 13px;
                color: #666;
                margin-bottom: 5px;
            }
            
            .target-progress-mini {
                height: 5px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 6px;
            }
            
            .target-bar {
                height: 100%;
                background: #4e54c8;
                border-radius: 3px;
                transition: width 1s ease-in-out;
            }
            
            .refund-info {
                font-size: 12px;
                color: #dc3545;
                margin-top: 5px;
            }
            
            .dashboard-grid {
                display: flex;
                flex-direction: column;
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .upper-section {
                display: flex;
                flex-direction: row;
                gap: 20px;
                align-items: stretch;
                justify-content: space-between;
            }
            
            .dashboard-grid .upper-section .dashboard-card.chart-card {
                width: 65%;
                flex-shrink: 0;
            }
            
            .dashboard-grid .upper-section .dashboard-card.calendar-card {
                width: 35%;
                flex-shrink: 0;
            }
            
            .lower-section {
                display: flex;
                flex-direction: column;
                gap: 20px;
                width: 100%;
            }
            
            .dashboard-grid .lower-section .dashboard-card {
                width: 100%;
            }
            
            .dashboard-card {
                background: white;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            }
            
            .member-performance-card,
            .team-performance-card,
            .target-performance-card {
                margin-bottom: 20px;
            }
            
            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .card-header h3 {
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin: 0;
            }
            
            .card-actions {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .card-option {
                background: none;
                border: none;
                color: #666;
                width: 28px;
                height: 28px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .card-option:hover {
                background: #f5f5f5;
                color: #333;
            }
            
            .text-button {
                font-size: 13px;
                color: #0073aa;
                text-decoration: none;
                transition: color 0.2s;
            }
            
            .text-button:hover {
                color: #005a87;
                text-decoration: underline;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .chart-container {
                height: 300px;
            }
            
            /* Organization Chart Styles */
            .org-chart {
                display: flex;
                flex-direction: column;
                align-items: center;
                margin: 20px 0;
            }
            
            .org-level {
                display: flex;
                justify-content: center;
                gap: 30px;
                width: 100%;
                margin-bottom: 10px;
            }
            
            .team-leaders-level {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .org-box {
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                width: 200px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            
            .patron-box {
                background-color: #f0f7ff;
                border: 2px solid #4a89dc;
            }
            
            .manager-box {
                background-color: #fff5f0;
                border: 2px solid #e8864a;
            }
            
            .team-leader-box {
                background-color: #f0f8f0;
                border: 2px solid #5cb85c;
                margin-bottom: 15px;
            }
            
            .empty-box {
                background-color: #f9f9f9;
                border: 2px dashed #ccc;
            }
            
            .org-title {
                font-weight: bold;
                font-size: 16px;
                margin-bottom: 5px;
                color: #444;
            }
            
            .org-name {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            
            .org-subtitle {
                font-style: italic;
                color: #666;
                font-size: 14px;
                margin-bottom: 5px;
            }
            
            .org-team-name {
                margin-top: 10px;
                font-weight: bold;
                color: #5cb85c;
            }
            
            .org-team-count {
                font-size: 12px;
                color: #777;
            }
            
            .org-connector {
                width: 2px;
                height: 30px;
                background-color: #999;
                margin: 5px 0;
            }
            
            .org-actions {
                display: flex;
                justify-content: center;
                gap: 15px;
                margin-top: 20px;
            }
            
            /* Progress Bar Styles */
            .progress-mini {
                height: 5px;
                background: #e9ecef;
                border-radius: 3px;
                overflow: hidden;
                margin-bottom: 3px;
            }
            
            .progress-bar {
                height: 100%;
                background: #4e54c8;
                border-radius: 3px;
            }
            
            .progress-text {
                font-size: 12px;
                color: #666;
                text-align: right;
            }
            
            #calendar {
                width: 100%;
                height: 500px;
                margin: 0 auto;
                visibility: visible;
                font-size: 12px;
            }
            
            .fc {
                visibility: visible !important;
            }
            
            .fc-scroller {
                overflow-y: hidden !important;
            }
            
            .fc-daygrid-day {
                position: relative;
                height: 30px;
                width: 30px;
            }
            
            .fc-daygrid-day-frame {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
            }
            
            .fc-daygrid-day-top {
                margin-bottom: 2px;
            }
            
            .fc-daygrid-day-number {
                color: #333;
                text-decoration: none;
                font-size: 10px;
            }
            
            .fc-daygrid-day-events {
                text-align: center;
            }
            
            .fc-task-count {
                background: #0073aa;
                color: #fff;
                border-radius: 10px;
                padding: 1px 4px;
                font-size: 9px;
                display: inline-block;
                text-decoration: none;
            }
            
            .fc-task-count:hover {
                background: #005a87;
            }
            
            .fc-header-toolbar {
                font-size: 12px;
            }
            
            .fc-button {
                padding: 2px 5px;
                font-size: 10px;
            }
            
            .data-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .data-table th {
                color: #666;
                font-weight: 500;
                font-size: 13px;
                text-align: left;
                padding: 12px 15px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .data-table td {
                padding: 12px 15px;
                font-size: 14px;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .data-table tr:last-child td {
                border-bottom: none;
            }
            
            .data-table a {
                color: #0073aa;
                text-decoration: none;
            }
            
            .data-table a:hover {
                text-decoration: underline;
            }
            
            .days-remaining {
                font-weight: 500;
            }
            
            .urgent .days-remaining {
                color: #dc3545;
            }
            
            .soon .days-remaining {
                color: #fd7e14;
            }
            
            .action-button {
                display: inline-flex;
                align-items: center;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 5px 10px;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .action-button:hover {
                background: #e9ecef;
            }
            
            .action-button .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                margin-right: 5px;
            }
            
            .renew-button {
                color: #0073aa;
                border-color: #0073aa;
                background: rgba(0,115,170,0.05);
            }
            
            .renew-button:hover {
                background: rgba(0,115,170,0.1);
            }
            
            .view-button {
                color: #0073aa;
                border-color: #0073aa;
                background: rgba(0,115,170,0.05);
            }
            
            .view-button:hover {
                background: rgba(0,115,170,0.1);
            }
            
            .days-overdue {
                font-weight: 500;
                color: #dc3545;
            }
            
            .user-info-cell {
                display: flex;
                align-items: center;
            }
            
            .user-avatar-mini {
                width: 28px;
                height: 28px;
                border-radius: 50%;
                background: #0073aa;
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 600;
                margin-right: 8px;
                flex-shrink: 0;
            }
            
            .status-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .status-active, .status-aktif {
                background: #d1e7dd;
                color: #198754;
            }
            
            .status-pending, .status-bekliyor {
                background: #fff3cd;
                color: #856404;
            }
            
            .status-cancelled, .status-iptal {
                background: #f8d7da;
                color: #dc3545;
            }
            
            .amount-cell {
                font-weight: 500;
                color: #333;
            }
            
            .negative-value {
                color: #dc3545;
            }
            
            .table-actions {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .table-action {
                width: 28px;
                height: 28px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #666;
                transition: all 0.2s;
                text-decoration: none;
            }
            
            .table-action:hover {
                background: #f0f0f0;
                color: #333;
            }
            
            .table-action-dropdown-wrapper {
                position: relative;
            }
            
            .table-action-more {
                cursor: pointer;
            }
            
            .table-action-dropdown {
                position: absolute;
                top: 100%;
                right: 0;
                background: #fff;
                border-radius: 6px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.15);
                z-index: 1000;
                min-width: 120px;
                display: none;
            }
            
            .table-action-dropdown.show {
                display: block;
            }
            
            .table-action-dropdown a {
                display: block;
                padding: 8px 15px;
                color: #333;
                text-decoration: none;
                font-size: 13px;
            }
            
            .table-action-dropdown a:hover {
                background: #f5f5f5;
            }
            
            .table-action-dropdown a.text-danger {
                color: #dc3545;
            }
            
            .table-action-dropdown a.text-danger:hover {
                background: #f8d7da;
            }
            
            .empty-state {
                text-align: center;
                padding: 30px;
            }
            
            .empty-state .empty-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background: #f0f7ff;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 15px;
            }
            
            .empty-state .empty-icon .dashicons {
                font-size: 30px;
                color: #0073aa;
            }
            
            .empty-state h4 {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 10px;
                color: #333;
            }
            
            .empty-state p {
                font-size: 14px;
                color: #666;
                margin-bottom: 20px;
            }
            
            .task-list {
                list-style-type: none;
                padding: 0;
            }
            
            .task-item {
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 13px;
            }
            
            .task-item:last-child {
                border-bottom: none;
            }
            
            .task-link {
                margin-left: 10px;
                color: #0073aa;
                text-decoration: none;
                font-size: 12px;
            }
            
            .task-link:hover {
                text-decoration: underline;
            }
            
            /* Responsive Adjustments */
            @media (max-width: 992px) {
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                .dashboard-grid .upper-section {
                    flex-direction: column;
                }
                .dashboard-grid .upper-section .dashboard-card.chart-card,
                .dashboard-grid .upper-section .dashboard-card.calendar-card {
                    width: 100%;
                }
                .search-box input {
                    width: 150px;
                }
                .search-box input:focus {
                    width: 200px;
                }
            }
            
            @media (max-width: 768px) {
                .insurance-crm-sidenav {
                    width: 60px;
                    transform: translateX(0);
                }
                .insurance-crm-sidenav.expanded {
                    width: 260px;
                }
                .sidenav-header h3, 
                .user-info h4, 
                .user-info span,
                .sidenav-menu a span, 
                .logout-button span,
                .sidenav-submenu .submenu-items {
                    display: none;
                }
                .insurance-crm-sidenav.expanded .sidenav-header h3, 
                .insurance-crm-sidenav.expanded .user-info h4, 
                .insurance-crm-sidenav.expanded .user-info span, 
                .insurance-crm-sidenav.expanded .sidenav-menu a span,
                .insurance-crm-sidenav.expanded .logout-button span {
                    display: block;
                }
                .insurance-crm-sidenav.expanded .sidenav-submenu .submenu-items {
                    display: block;
                }
                .insurance-crm-main {
                    margin-left: 60px;
                }
                .insurance-crm-sidenav.expanded + .insurance-crm-main {
                    margin-left: 260px;
                }
                .stats-grid {
                    grid-template-columns: repeat(1, 1fr);
                }
                .hide-mobile {
                    display: none;
                }
            }
            
            @media (max-width: 576px) {
                .main-content {
                    padding: 15px;
                }
                .main-header {
                    padding: 10px 15px;
                }
                .header-left h2 {
                    font-size: 16px;
                }
                .quick-add-btn span {
                    display: none;
                }
            }





.tasks-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.task-summary-card {
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.task-summary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.task-summary-card.today {
    border-left: 4px solid #e53935;
}

.task-summary-card.tomorrow {
    border-left: 4px solid #fb8c00;
}

.task-summary-card.this-week {
    border-left: 4px solid #43a047;
}

.task-summary-card.this-month {
    border-left: 4px solid #1e88e5;
}

.task-summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}

.task-summary-card.today .task-summary-icon {
    background: rgba(229, 57, 53, 0.1);
    color: #e53935;
}

.task-summary-card.tomorrow .task-summary-icon {
    background: rgba(251, 140, 0, 0.1);
    color: #fb8c00;
}

.task-summary-card.this-week .task-summary-icon {
    background: rgba(67, 160, 71, 0.1);
    color: #43a047;
}

.task-summary-card.this-month .task-summary-icon {
    background: rgba(30, 136, 229, 0.1);
    color: #1e88e5;
}

.task-summary-icon .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
}

.task-summary-content {
    margin-top: auto;
}

.task-summary-content h3 {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
    color: #333;
}

.task-summary-content p {
    font-size: 14px;
    color: #666;
    margin: 0;
}

.task-summary-link {
    color: #0073aa;
    text-decoration: none;
    font-size: 13px;
    margin-top: 10px;
    display: inline-block;
    font-weight: 500;
    transition: color 0.2s;
}

.task-summary-link:hover {
    color: #005a87;
    text-decoration: underline;
}

.urgent-tasks {
    margin-top: 25px;
}

.urgent-tasks h4 {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
}

.urgent-task-item {
    display: flex;
    align-items: center;
    padding: 15px;
    margin-bottom: 10px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    transition: transform 0.2s ease;
}

.urgent-task-item:hover {
    transform: translateY(-2px);
}

.urgent-task-item.very-urgent {
    border-left: 4px solid #e53935;
}

.urgent-task-item.urgent {
    border-left: 4px solid #fb8c00;
}

.urgent-task-item.normal {
    border-left: 4px solid #43a047;
}

.task-date {
    width: 50px;
    height: 50px;
    background: #f5f7fa;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    flex-shrink: 0;
}

.date-number {
    font-size: 18px;
    font-weight: 700;
    color: #333;
    line-height: 1;
}

.date-month {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
}

.task-details {
    flex: 1;
}

.task-details h5 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 5px;
    color: #333;
}

.task-details p {
    font-size: 13px;
    color: #666;
    margin: 0;
}

.task-action {
    margin-left: 15px;
}

.view-task-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    background: #0073aa;
    color: #fff;
    border-radius: 4px;
    font-size: 12px;
    text-decoration: none;
    transition: background 0.2s;
}

.view-task-btn:hover {
    background: #005a87;
    color: #fff;
}

.empty-tasks-message {
    text-align: center;
    padding: 30px 0;
}

.empty-tasks-message .empty-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(0,115,170,0.1);
    color: #0073aa;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
}

.empty-tasks-message .empty-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.empty-tasks-message p {
    color: #666;
    margin-bottom: 15px;
}

@media screen and (max-width: 992px) {
    .tasks-summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media screen and (max-width: 576px) {
    .tasks-summary-grid {
        grid-template-columns: 1fr;
    }
}



.performance-layout {
    display: flex;
    flex-wrap: wrap;
    gap: 25px;
}

.team-contribution-chart {
    flex: 1;
    min-width: 250px;
}

.team-performance-table {
    flex: 2;
    min-width: 350px;
}

.pie-chart {
    width: 100%;
    height: 300px;
    position: relative;
    margin-bottom: 15px;
}

.pie-chart-title {
    font-size: 14px;
    font-weight: 600;
    margin-top: 5px;
    margin-bottom: 15px;
    text-align: center;
    color: #333;
}

.performance-section {
    margin-top: 20px;
}

@media (max-width: 768px) {
    .performance-layout {
        flex-direction: column;
    }
    
    .team-contribution-chart, 
    .team-performance-table {
        flex: 1 100%;
    }
}


        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidenavToggle = document.getElementById('sidenav-toggle');
            const sidenav = document.querySelector('.insurance-crm-sidenav');
            const main = document.querySelector('.insurance-crm-main');
            
            if (sidenavToggle) {
                sidenavToggle.addEventListener('click', function() {
                    sidenav.classList.toggle('expanded');
                });
            }
            
            // Notifications Dropdown Toggle
            const notificationsToggle = document.getElementById('notifications-toggle');
            const notificationsDropdown = document.querySelector('.notifications-dropdown');
            
            if (notificationsToggle && notificationsDropdown) {
                notificationsToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    notificationsDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!notificationsToggle.contains(e.target) && !notificationsDropdown.contains(e.target)) {
                        notificationsDropdown.classList.remove('show');
                    }
                });
            }
            
            // Quick Add Dropdown Toggle
            const quickAddToggle = document.getElementById('quick-add-toggle');
            const quickAddDropdown = document.querySelector('.quick-add-dropdown');
            
            if (quickAddToggle && quickAddDropdown) {
                quickAddToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    quickAddDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!quickAddToggle.contains(e.target) && !quickAddDropdown.contains(e.target)) {
                        quickAddDropdown.classList.remove('show');
                    }
                });
            }
            
            // Table Action Dropdowns
            const actionMoreButtons = document.querySelectorAll('.table-action-more');
            actionMoreButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = button.parentElement.querySelector('.table-action-dropdown');
                    if (dropdown) {
                        dropdown.classList.toggle('show');
                    }
                });
            });
            
            document.addEventListener('click', function(e) {
                actionMoreButtons.forEach(button => {
                    const dropdown = button.parentElement.querySelector('.table-action-dropdown');
                    if (dropdown && !button.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            });
            
            // Production Chart
            const productionChartCanvas = document.querySelector('#productionChart');
            if (productionChartCanvas) {
                const monthlyProduction = <?php echo json_encode($monthly_production); ?>;
                
                const labels = monthlyProduction.map(item => {
                    const [year, month] = item.month.split('-');
                    const months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 
                                  'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
                    return months[parseInt(month) - 1] + ' ' + year;
                });
                
                const data = monthlyProduction.map(item => item.total);
                
                new Chart(productionChartCanvas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Aylık Üretim (₺)',
                            data: data,
                            backgroundColor: 'rgba(0,115,170,0.6)',
                            borderColor: 'rgba(0,115,170,1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₺' + value.toLocaleString('tr-TR');
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return '₺' + context.parsed.y.toLocaleString('tr-TR');
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Mark All Notifications as Read
            const markAllReadLink = document.querySelector('.mark-all-read');
            if (markAllReadLink) {
                markAllReadLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Tüm bildirimleri okundu olarak işaretlemek istediğinize emin misiniz?')) {
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=mark_all_notifications_read'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.querySelectorAll('.notification-item.unread').forEach(item => {
                                    item.classList.remove('unread');
                                });
                                const badge = document.querySelector('.notification-badge');
                                if (badge) badge.remove();
                                alert('Tüm bildirimler okundu olarak işaretlendi.');
                            } else {
                                alert('Hata: ' + (data.data.message || 'İşlem başarısız.'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Bir hata oluştu.');
                        });
                    }
                });
            }
            
            // Arama Formu Submit Kontrolü
            const searchForm = document.querySelector('.search-box form');
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    const keywordInput = searchForm.querySelector('input[name="keyword"]');
                    if (!keywordInput.value.trim()) {
                        e.preventDefault();
                        alert('Lütfen bir arama kriteri girin.');
                    }
                });
            }
        });
        </script>
        
        <?php wp_footer(); ?>
    </body>
</html>