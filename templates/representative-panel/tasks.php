<?php
/**
 * Frontend Görev Yönetim Sayfası
 * @version 3.0.0 - Kişisel ve Ekip Görevleri ayrımı ve gelişmiş istatistikler
 */

// Renk ayarlarını dahil et
//include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Değişkenleri tanımla
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// Mevcut kullanıcının temsilci ID'sini al
if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
    }
}
$current_user_rep_id = get_current_user_rep_id();
$current_user_id = get_current_user_id();

// Yönetici yetkisini kontrol et (WordPress admin veya insurance_manager)
$is_wp_admin_or_manager = current_user_can('administrator') || current_user_can('insurance_manager');

// --- Role Helper Functions (Adapted from dashboard4365satir.php) ---
if (!function_exists('is_patron')) {
    function is_patron($user_id) {
        global $wpdb;
        $settings = get_option('insurance_crm_settings', []);
        $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
        if (empty($management_hierarchy['patron_id'])) return false;
        $rep = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d", $user_id));
        if (!$rep) return false;
        return ($management_hierarchy['patron_id'] == $rep->id);
    }
}

if (!function_exists('is_manager')) {
    function is_manager($user_id) {
        global $wpdb;
        $settings = get_option('insurance_crm_settings', []);
        $management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array();
        if (empty($management_hierarchy['manager_id'])) return false;
        $rep = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d", $user_id));
        if (!$rep) return false;
        return ($management_hierarchy['manager_id'] == $rep->id);
    }
}

if (!function_exists('is_team_leader')) {
    function is_team_leader($user_id) {
        global $wpdb;
        $settings = get_option('insurance_crm_settings', []);
        $teams = $settings['teams_settings']['teams'] ?? [];
        $rep = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d", $user_id));
        if (!$rep) return false;
        foreach ($teams as $team) {
            if ($team['leader_id'] == $rep->id) return true;
        }
        return false;
    }
}

if (!function_exists('get_team_members_ids')) {
    function get_team_members_ids($team_leader_user_id) {
        global $wpdb;
        $leader_rep_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $team_leader_user_id
        ));
        
        if (!$leader_rep_id) {
            return array();
        }
        
        $settings = get_option('insurance_crm_settings', array());
        $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
        
        foreach ($teams as $team) {
            if (isset($team['leader_id']) && $team['leader_id'] == $leader_rep_id) {
                $members = isset($team['members']) ? $team['members'] : array();
                // Kendisini de ekle
                if (!in_array($leader_rep_id, $members)) {
                    $members[] = $leader_rep_id;
                }
                return array_unique($members);
            }
        }
        
        // Eğer ekip lideri bulunamazsa, sadece kendisini içeren bir dizi döndür
        return array($leader_rep_id);
    }
}
// --- End Role Helper Functions ---

// İşlem Bildirileri için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Kullanıcının rolüne göre erişim düzeyi belirlenmesi
function get_user_role_level() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $current_user_id
    ));
    
    if (!$rep) return 5; // Varsayılan olarak en düşük yetki
    
    return intval($rep->role); // 1: Patron, 2: Müdür, 3: Müdür Yard., 4: Ekip Lideri, 5: Müş. Temsilcisi
}

$user_role_level = get_user_role_level();

// Aktif görünüm belirleme (kişisel veya ekip görevleri)
$current_view = isset($_GET['view_type']) ? sanitize_text_field($_GET['view_type']) : 'personal';
$is_team_view = ($current_view === 'team');

// Görev silme işlemi (Sadece WP Admin/Insurance Manager, Patron, Müdür silebilir)
$can_delete_tasks = $is_wp_admin_or_manager || is_patron($current_user_id) || is_manager($current_user_id);
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_task_' . $task_id)) {
        if (!$can_delete_tasks) {
            $notice = '<div class="ab-notice ab-error">Görev silme yetkisine sahip değilsiniz.</div>';
        } else {
            $delete_result = $wpdb->delete($tasks_table, array('id' => $task_id), array('%d'));
            if ($delete_result !== false) {
                $notice = '<div class="ab-notice ab-success">Görev başarıyla silindi.</div>';
            } else {
                $notice = '<div class="ab-notice ab-error">Görev silinirken bir hata oluştu.</div>';
            }
        }
    }
}

// Görev tamamlama işlemi
if (isset($_GET['action']) && $_GET['action'] === 'complete' && isset($_GET['id'])) {
    $task_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'complete_task_' . $task_id)) {
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tasks_table WHERE id = %d", $task_id));
        
        $can_complete = false;
        if ($is_wp_admin_or_manager || is_patron($current_user_id) || is_manager($current_user_id)) {
            $can_complete = true;
        } elseif ($current_user_rep_id && $task->representative_id == $current_user_rep_id) {
            $can_complete = true;
        } elseif (is_team_leader($current_user_id)) {
            $team_members = get_team_members_ids($current_user_id);
            if (in_array($task->representative_id, $team_members)) {
                $can_complete = true;
            }
        }

        if ($can_complete) {
            $update_result = $wpdb->update(
                $tasks_table,
                array('status' => 'completed', 'updated_at' => current_time('mysql')),
                array('id' => $task_id)
            );
            if ($update_result !== false) {
                $notice = '<div class="ab-notice ab-success">Görev başarıyla tamamlandı olarak işaretlendi.</div>';
            } else {
                $notice = '<div class="ab-notice ab-error">Görev güncellenirken bir hata oluştu.</div>';
            }
        } else {
            $notice = '<div class="ab-notice ab-error">Bu görevi tamamlama yetkiniz yok.</div>';
        }
    }
}

// Filtreler ve Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// Filtre parametrelerini al ve sanitize et
$filters = array(
    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0,
    'priority' => isset($_GET['priority']) ? sanitize_text_field($_GET['priority']) : '',
    'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
    'task_description' => isset($_GET['task_description']) ? sanitize_text_field($_GET['task_description']) : '',
    'task_title' => isset($_GET['task_title']) ? sanitize_text_field($_GET['task_title']) : '',
    'due_date' => isset($_GET['due_date']) ? sanitize_text_field($_GET['due_date']) : '',
    'time_filter' => isset($_GET['time_filter']) ? sanitize_text_field($_GET['time_filter']) : '',
);

// Sorgu oluştur
$base_query = "FROM $tasks_table t 
               LEFT JOIN $customers_table c ON t.customer_id = c.id
               LEFT JOIN $policies_table p ON t.policy_id = p.id
               LEFT JOIN $representatives_table r ON t.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Yetkilere göre sorguyu düzenle
if ($user_role_level === 1 || $user_role_level === 2 || $user_role_level === 3) {
    if (!$is_team_view) {
        // Kişisel görünüm: Sadece kendi görevlerini göster
        $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
    }
    // Ekip görünümünde tüm görevleri görebilir (Patron, Müdür, Müdür Yardımcısı)
} else if ($user_role_level === 4) {
    // Ekip lideri ise
    if ($is_team_view) {
        // Ekip görünümü: Ekipteki tüm temsilcilerin görevlerini göster
        $team_member_ids = get_team_members_ids(get_current_user_id());
        if (!empty($team_member_ids)) {
            $member_placeholders = implode(',', array_fill(0, count($team_member_ids), '%d'));
            $base_query .= $wpdb->prepare(" AND t.representative_id IN ($member_placeholders)", ...$team_member_ids);
        } else {
            // Ekip yoksa sadece kendi görevlerini görsün
            $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Kişisel görünüm: Sadece kendi görevlerini göster
        $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
    }
} else {
    // Normal müşteri temsilcisi sadece kendi görevlerini görebilir
    $base_query .= $wpdb->prepare(" AND t.representative_id = %d", $current_user_rep_id);
}

// Filtreleri ekle
if (!empty($filters['customer_id'])) {
    $base_query .= $wpdb->prepare(" AND t.customer_id = %d", $filters['customer_id']);
}
if (!empty($filters['priority'])) {
    $base_query .= $wpdb->prepare(" AND t.priority = %s", $filters['priority']);
}
if (!empty($filters['status'])) {
    $base_query .= $wpdb->prepare(" AND t.status = %s", $filters['status']);
}
if (!empty($filters['task_title'])) {
    $base_query .= $wpdb->prepare(
        " AND (t.task_title LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR p.policy_number LIKE %s)",
        '%' . $wpdb->esc_like($filters['task_title']) . '%',
        '%' . $wpdb->esc_like($filters['task_title']) . '%',
        '%' . $wpdb->esc_like($filters['task_title']) . '%',
        '%' . $wpdb->esc_like($filters['task_title']) . '%'
    );
}
if (!empty($filters['due_date'])) {
    $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $filters['due_date']);
}

// Zaman filtresi (bugün, bu hafta, bu ay)
if (!empty($filters['time_filter'])) {
    $today_date = date('Y-m-d');
    switch ($filters['time_filter']) {
        case 'today':
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $today_date);
            break;
        case 'tomorrow':
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) = %s", $tomorrow);
            break;
        case 'this_week':
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $week_start, $week_end);
            break;
        case 'next_week':
            $week_start = date('Y-m-d', strtotime('monday next week'));
            $week_end = date('Y-m-d', strtotime('sunday next week'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $week_start, $week_end);
            break;
        case 'this_month':
            $month_start = date('Y-m-01');
            $month_end = date('Y-m-t');
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $month_start, $month_end);
            break;
        case 'next_month':
            $next_month_start = date('Y-m-01', strtotime('first day of next month'));
            $next_month_end = date('Y-m-t', strtotime('first day of next month'));
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $next_month_start, $next_month_end);
            break;
        case 'overdue':
            $base_query .= $wpdb->prepare(" AND DATE(t.due_date) < %s AND t.status NOT IN ('completed', 'cancelled')", $today_date);
            break;
    }
}

// İSTATİSTİK HESAPLAMALARI
// Toplam görev sayısı
$total_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query);

// Bugünkü görevler
$today_date = date('Y-m-d');
$today_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) = %s", $today_date));

// Yarınki görevler
$tomorrow_date = date('Y-m-d', strtotime('+1 day'));
$tomorrow_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) = %s", $tomorrow_date));

// Bu haftaki görevler
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$this_week_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $week_start, $week_end));

// Gelecek haftaki görevler
$next_week_start = date('Y-m-d', strtotime('monday next week'));
$next_week_end = date('Y-m-d', strtotime('sunday next week'));
$next_week_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $next_week_start, $next_week_end));

// Bu ayki görevler
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$this_month_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $month_start, $month_end));

// Gelecek ayki görevler
$next_month_start = date('Y-m-01', strtotime('first day of next month'));
$next_month_end = date('Y-m-t', strtotime('first day of next month'));
$next_month_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) BETWEEN %s AND %s", $next_month_start, $next_month_end));

// Gecikmiş görevler
$overdue_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND DATE(t.due_date) < %s AND t.status NOT IN ('completed', 'cancelled')", $today_date));

// Bu ay eklenen görevler
$this_month_start_date = date('Y-m-01 00:00:00');
$created_this_month = $wpdb->get_var("SELECT COUNT(*) " . $base_query . $wpdb->prepare(" AND t.created_at >= %s", $this_month_start_date));

// Durum bazlı görev sayıları
$completed_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'completed'");
$pending_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'pending'");
$in_progress_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'in_progress'");
$cancelled_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.status = 'cancelled'");

// Öncelik bazlı görev sayıları
$high_priority_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.priority = 'high'");
$medium_priority_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.priority = 'medium'");
$low_priority_tasks = $wpdb->get_var("SELECT COUNT(*) " . $base_query . " AND t.priority = 'low'");

// Grafik verileri
$status_chart_data = array(
    'Beklemede' => (int)$pending_tasks,
    'İşlemde' => (int)$in_progress_tasks,
    'Tamamlandı' => (int)$completed_tasks,
    'İptal Edildi' => (int)$cancelled_tasks
);

$priority_chart_data = array(
    'Yüksek' => (int)$high_priority_tasks,
    'Orta' => (int)$medium_priority_tasks,
    'Düşük' => (int)$low_priority_tasks
);

$time_chart_data = array(
    'Bugün' => (int)$today_tasks,
    'Yarın' => (int)$tomorrow_tasks,
    'Bu Hafta' => (int)$this_week_tasks,
    'Gelecek Hafta' => (int)$next_week_tasks,
    'Bu Ay' => (int)$this_month_tasks,
    'Gelecek Ay' => (int)$next_month_tasks,
    'Gecikmiş' => (int)$overdue_tasks
);

// 0 değerlerini filtrele
$status_chart_data = array_filter($status_chart_data);
$priority_chart_data = array_filter($priority_chart_data);
$time_chart_data = array_filter($time_chart_data);

// Listede gösterilecek görevleri getir
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 't.due_date';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'ASC';

$tasks = $wpdb->get_results("
    SELECT t.*, 
           c.first_name, c.last_name, 
           p.policy_number, 
           u.display_name as rep_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Toplam kayıt sayısını al (for pagination, uses the same $base_query)
$total_items = $total_tasks;

// Müşterileri al (for filter dropdown)
$customers_query = "";
if ($user_role_level === 5 || ($user_role_level === 4 && !$is_team_view)) {
    // Müşteri Temsilcisi veya Ekip Lideri (kendi görünümü) - sadece kendi müşterilerini görsün
    $customers_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
} elseif ($user_role_level === 4 && $is_team_view) {
    // Ekip Lideri (ekip görünümü) - ekip üyelerinin müşterilerini görsün
    $team_member_ids = get_team_members_ids(get_current_user_id());
    if (!empty($team_member_ids)) {
        $placeholders = implode(',', array_fill(0, count($team_member_ids), '%d'));
        $customers_query .= $wpdb->prepare(" AND c.representative_id IN ($placeholders)", ...$team_member_ids);
    } else {
        $customers_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
    }
}

$customers = $wpdb->get_results("
    SELECT id, first_name, last_name 
    FROM $customers_table c
    WHERE status = 'aktif' $customers_query
    ORDER BY first_name, last_name
");

// Sayfalama
$total_pages = ceil($total_items / $per_page);

// Aktif action belirle
$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new');

// Belirli bir gün için görev var mı kontrolü
$has_tasks_for_date = false;
$selected_date_formatted = '';
$no_tasks_message = '';

if (!empty($filters['due_date'])) {
    try {
        $selected_date = new DateTime($filters['due_date']);
        $selected_date_formatted = $selected_date->format('d.m.Y');
    } catch (Exception $e) {
        $selected_date_formatted = $filters['due_date']; // fallback to raw date
    }
    $has_tasks_for_date = !empty($tasks);
    
    if (!$has_tasks_for_date && empty($filters['customer_id']) && empty($filters['priority']) && empty($filters['status']) && empty($filters['task_title'])) {
        $no_tasks_message = '<div class="ab-empty-state">
            <i class="fas fa-calendar-day"></i>
            <h3>' . $selected_date_formatted . ' tarihi için görev bulunamadı</h3>
            <p>Bu tarih için henüz görev ataması yapılmamış veya filtrelerinize uyan görev yok.</p>
            <a href="?view=tasks&action=new&due_date=' . esc_attr($filters['due_date']) . '" class="ab-btn ab-btn-primary">
                <i class="fas fa-plus"></i> Yeni Görev Ekle
            </a>
        </div>';
    }
}

// Aktif filtre sayısı
$active_filter_count = count(array_filter($filters));
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="ab-crm-container" id="tasks-list-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    <?php echo $notice; ?>

    <!-- Görev Listesi Başlığı -->
    <div class="ab-crm-header">
        <div class="ab-crm-title-area">
            <h1><i class="fas fa-tasks"></i> Görevler<?php echo !empty($filters['due_date']) ? ' - <span class="selected-date">' . esc_html($selected_date_formatted) . '</span>' : ''; ?></h1>
        </div>
        
        <div class="ab-crm-header-actions">
            <?php if ($user_role_level <= 4): // Ekip lideri ve üstü için görünüm seçimi ?>
            <div class="ab-view-toggle">
                <a href="?view=tasks&view_type=personal" class="ab-view-btn <?php echo !$is_team_view ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> Görevlerim
                </a>
                <a href="?view=tasks&view_type=team" class="ab-view-btn <?php echo $is_team_view ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Ekip Görevleri
                </a>
            </div>
            <?php endif; ?>
            
            <a href="?view=tasks&action=new<?php echo !empty($filters['due_date']) ? '&due_date=' . esc_attr($filters['due_date']) : ''; ?>" class="ab-btn ab-btn-primary">
                <i class="fas fa-plus"></i> Yeni Görev
            </a>
        </div>
    </div>

    <!-- Özet İstatistikler -->
    <div class="ab-task-summary-cards">
        <div class="ab-summary-card">
            <div class="ab-summary-card-icon ab-icon-blue">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="ab-summary-card-body">
                <h3>Toplam Görev</h3>
                <div class="ab-summary-card-value"><?php echo number_format($total_tasks); ?></div>
            </div>
        </div>
        
        <div class="ab-summary-card">
            <div class="ab-summary-card-icon ab-icon-green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="ab-summary-card-body">
                <h3>Tamamlanan</h3>
                <div class="ab-summary-card-value"><?php echo number_format($completed_tasks); ?></div>
                <div class="ab-summary-card-info">
                    <?php echo number_format($total_tasks > 0 ? ($completed_tasks / $total_tasks) * 100 : 0, 1); ?>% Tamamlama
                </div>
            </div>
        </div>
        
        <div class="ab-summary-card">
            <div class="ab-summary-card-icon ab-icon-orange">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="ab-summary-card-body">
                <h3>Gecikmiş Görev</h3>
                <div class="ab-summary-card-value"><?php echo number_format($overdue_tasks); ?></div>
                <div class="ab-summary-card-info">
                    Acil ilgilenilmeli
                </div>
            </div>
        </div>
        
        <div class="ab-summary-card">
            <div class="ab-summary-card-icon ab-icon-red">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="ab-summary-card-body">
                <h3>Bugünkü Görevler</h3>
                <div class="ab-summary-card-value"><?php echo number_format($today_tasks); ?></div>
                <div class="ab-summary-card-info">
                    <?php echo date('d.m.Y'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Zaman Bazlı Görev Filtreleri -->
    <div class="ab-time-filter-tabs">
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=today" class="ab-time-filter-tab <?php echo $filters['time_filter'] === 'today' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-day"></i> Bugün
            <span class="ab-badge"><?php echo $today_tasks; ?></span>
        </a>
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=tomorrow" class="ab-time-filter-tab <?php echo $filters['time_filter'] === 'tomorrow' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i> Yarın
            <span class="ab-badge"><?php echo $tomorrow_tasks; ?></span>
        </a>
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=this_week" class="ab-time-filter-tab <?php echo $filters['time_filter'] === 'this_week' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-week"></i> Bu Hafta
            <span class="ab-badge"><?php echo $this_week_tasks; ?></span>
        </a>
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=next_week" class="ab-time-filter-tab <?php echo $filters['time_filter'] === 'next_week' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-plus"></i> Gelecek Hafta
            <span class="ab-badge"><?php echo $next_week_tasks; ?></span>
        </a>
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=this_month" class="ab-time-filter-tab <?php echo $filters['time_filter'] === 'this_month' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-check"></i> Bu Ay
            <span class="ab-badge"><?php echo $this_month_tasks; ?></span>
        </a>
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>&time_filter=overdue" class="ab-time-filter-tab <?php echo $filters['time_filter'] === 'overdue' ? 'active warning-tab' : ''; ?>">
            <i class="fas fa-exclamation-circle"></i> Gecikmiş
            <span class="ab-badge ab-badge-warning"><?php echo $overdue_tasks; ?></span>
        </a>
    </div>

    <!-- Gelişmiş İstatistikler -->
    <div class="ab-task-stats-dashboard">
        <div class="ab-stats-dashboard-header">
            <h2>
                <i class="fas fa-chart-pie"></i> 
                <?php if ($is_team_view && $user_role_level <= 4): ?>
                    Ekip Görevi İstatistikleri
                <?php else: ?>
                    <?php echo $user_role_level <= 3 && !$is_team_view ? 'Kişisel Görev İstatistikleri' : 'Görev İstatistikleri'; ?>
                <?php endif; ?>
            </h2>
            <div class="ab-stats-actions">
                <button id="toggle-task-stats" class="ab-btn ab-btn-sm">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
        </div>
        
        <div class="ab-stats-content">
            <div class="ab-stats-grid">
                <!-- Durum dağılımı grafiği -->
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Durum Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="taskStatusChart"></canvas>
                    </div>
                </div>
                
                <!-- Öncelik dağılımı grafiği -->
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Öncelik Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="taskPriorityChart"></canvas>
                    </div>
                </div>
                
                <!-- Zaman bazlı dağılım grafiği -->
                <div class="ab-stats-chart-container ab-chart-wide">
                    <div class="ab-stats-chart-header">
                        <h3>Zaman Bazlı Görev Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="taskTimeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtreleme Butonu ve Form -->
    <div class="ab-filter-toggle-container">
        <button type="button" id="toggle-tasks-filters-btn" class="ab-btn ab-toggle-filters">
            <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
        </button>
        
        <?php if ($active_filter_count > 0): ?>
        <div class="ab-active-filters">
            <span><?php echo $active_filter_count; ?> aktif filtre</span>
            <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="ab-clear-filters">
                <i class="fas fa-times"></i> Temizle
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Filtreleme formu - Varsayılan olarak gizli -->
    <div id="tasks-filters-container" class="ab-crm-filters ab-filters-hidden">
        <form method="get" id="tasks-filter" class="ab-filter-form">
            <input type="hidden" name="view" value="tasks">
            <?php if ($is_team_view): ?>
            <input type="hidden" name="view_type" value="team">
            <?php endif; ?>
            
            <div class="ab-form-row">
                <div class="ab-filter-col">
                    <label for="customer_id">Müşteri</label>
                    <select name="customer_id" id="customer_id" class="ab-select">
                        <option value="">Tüm Müşteriler</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>" <?php selected($filters['customer_id'], $customer->id); ?>>
                                <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="priority">Öncelik</label>
                    <select name="priority" id="priority" class="ab-select">
                        <option value="">Tüm Öncelikler</option>
                        <option value="low" <?php selected($filters['priority'], 'low'); ?>>Düşük</option>
                        <option value="medium" <?php selected($filters['priority'], 'medium'); ?>>Orta</option>
                        <option value="high" <?php selected($filters['priority'], 'high'); ?>>Yüksek</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="ab-select">
                        <option value="">Tüm Durumlar</option>
                        <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Beklemede</option>
                        <option value="in_progress" <?php selected($filters['status'], 'in_progress'); ?>>İşlemde</option>
                        <option value="completed" <?php selected($filters['status'], 'completed'); ?>>Tamamlandı</option>
                        <option value="cancelled" <?php selected($filters['status'], 'cancelled'); ?>>İptal Edildi</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="due_date">Son Tarih</label>
                    <input type="date" name="due_date" id="due_date" class="ab-input" value="<?php echo esc_attr($filters['due_date']); ?>">
                </div>
                
                <div class="ab-filter-col">
                    <label for="task_title">Görev Tanımı</label>
                    <input type="text" name="task_title" id="task_title" class="ab-input" value="<?php echo esc_attr($filters['task_title']); ?>" placeholder="Görev Ara...">
                </div>
                
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" class="ab-btn ab-btn-filter">Filtrele</button>
                    <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="ab-btn ab-btn-reset">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Görev Listesi -->
    <?php if (!empty($no_tasks_message) && empty($tasks)): // Show only if no tasks and specific message exists ?>
        <?php echo $no_tasks_message; ?>
    <?php elseif (!empty($tasks)): ?>
    <div class="ab-crm-table-wrapper">
        <div class="ab-crm-table-info">
            <span>Toplam: <?php echo number_format($total_items); ?> görev</span>
            <?php if ($is_team_view && $user_role_level <= 4): ?>
                <span class="ab-view-badge">
                    <i class="fas fa-users"></i> Ekip Görünümü
                </span>
            <?php elseif ($user_role_level <= 3 && !$is_team_view): ?>
                <span class="ab-view-badge">
                    <i class="fas fa-user"></i> Kişisel Görünüm
                </span>
            <?php endif; ?>
        </div>
        
        <table class="ab-crm-table">
            <thead>
                <tr>
                  <th>
        Görev Tanımı <i class="fas fa-sort"></i>
</th>
                 
                    </th>
                    <th>Müşteri</th>
                    <th>Poliçe</th>
                    <th>
                            Son Tarih <i class="fas fa-sort"></i>
                                      </th>
                    <th>
                       Öncelik <i class="fas fa-sort"></i>
                     
                    </th>
                    <th>
                            Durum <i class="fas fa-sort"></i>
                 
                    </th>
                    <?php if ($is_team_view || $user_role_level <= 3): ?>
                    <th>Temsilci</th>
                    <?php endif; ?>
                    <th class="ab-actions-column">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task): 
                    $task_due_time = strtotime($task->due_date);
                    $is_overdue = $task_due_time < time() && $task->status !== 'completed' && $task->status !== 'cancelled';
                    $is_today = date('Y-m-d') == date('Y-m-d', $task_due_time);
                    
                    $row_class = '';
                    if ($is_overdue) {
                        $row_class = 'overdue';
                    } elseif ($is_today) {
                        $row_class = 'today';
                    }
                    
                    switch ($task->status) {
                        case 'completed': $row_class .= ' task-completed'; break;
                        case 'in_progress': $row_class .= ' task-in-progress'; break;
                        case 'cancelled': $row_class .= ' task-cancelled'; break;
                        default: $row_class .= ' task-pending';
                    }
                    
                    $row_class .= ' priority-' . $task->priority;
                ?>
                    <tr class="<?php echo trim($row_class); ?>">
                        <td>
                            <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" class="ab-task-title">
                                <?php echo esc_html($task->task_title); ?>
                                <?php if ($is_overdue): ?>
                                    <span class="ab-badge ab-badge-danger">Gecikmiş!</span>
                                <?php elseif ($is_today): ?>
                                    <span class="ab-badge ab-badge-today">Bugün</span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <?php if (!empty($task->customer_id)): ?>
                            <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="ab-customer-link">
                                <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                            </a>
                            <?php else: ?> <span class="ab-no-value">—</span> <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                                <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="ab-policy-link">
                                    <?php echo esc_html($task->policy_number); ?>
                                </a>
                            <?php else: ?> <span class="ab-no-value">—</span> <?php endif; ?>
                        </td>
                        <td class="ab-date-cell">
                            <span class="ab-due-date <?php echo $is_overdue ? 'overdue' : ($is_today ? 'today' : ''); ?>">
                                <?php echo date('d.m.Y H:i', $task_due_time); ?>
                            </span>
                        </td>
                        <td>
                            <span class="ab-badge ab-badge-priority-<?php echo esc_attr($task->priority); ?>">
                                <?php 
                                switch ($task->priority) {
                                    case 'low': echo 'Düşük'; break;
                                    case 'medium': echo 'Orta'; break;
                                    case 'high': echo 'Yüksek'; break;
                                    default: echo ucfirst($task->priority); break;
                                }
                                ?>
                            </span>
                        </td>
                        <td>
                            <span class="ab-badge ab-badge-task-<?php echo esc_attr($task->status); ?>">
                                <?php 
                                switch ($task->status) {
                                    case 'pending': echo 'Beklemede'; break;
                                    case 'in_progress': echo 'İşlemde'; break;
                                    case 'completed': echo 'Tamamlandı'; break;
                                    case 'cancelled': echo 'İptal Edildi'; break;
                                    default: echo ucfirst($task->status); break;
                                }
                                ?>
                            </span>
                        </td>
                        <?php if ($is_team_view || $user_role_level <= 3): ?>
                        <td><?php echo !empty($task->rep_name) ? esc_html($task->rep_name) : '<span class="ab-no-value">—</span>'; ?></td>
                        <?php endif; ?>
                        <td class="ab-actions-cell">
                            <div class="ab-actions">
                                <a href="?view=tasks&action=view&id=<?php echo $task->id; ?>" title="Görüntüle" class="ab-action-btn">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <?php 
                                // Düzenleme izni kontrolü
                                $can_edit = $is_wp_admin_or_manager || is_patron($current_user_id) || is_manager($current_user_id);
                                
                                if (!$can_edit && $current_user_rep_id && $task->representative_id == $current_user_rep_id) {
                                    $can_edit = true; // Kendi görevlerini düzenleyebilir
                                }
                                
                                if (!$can_edit && is_team_leader($current_user_id)) {
                                    $team_members = get_team_members_ids($current_user_id);
                                    if (in_array($task->representative_id, $team_members)) {
                                        $can_edit = true; // Ekip üyelerinin görevlerini düzenleyebilir
                                    }
                                }
                                
                                // Tamamlama izni kontrolü
                                $can_complete = $can_edit && $task->status !== 'completed';
                                ?>
                                
                                <?php if ($can_edit): ?>
                                <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" title="Düzenle" class="ab-action-btn">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                
                                <?php if ($can_complete): ?>
                                <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task->id . ($is_team_view ? '&view_type=team' : ''), 'complete_task_' . $task->id); ?>" 
                                   title="Tamamla" class="ab-action-btn ab-action-complete"
                                   onclick="return confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?');">
                                    <i class="fas fa-check"></i>
                                </a>
                                <?php endif; ?>
                                
                                <?php if ($can_delete_tasks): ?>
                                <a href="<?php echo wp_nonce_url('?view=tasks&action=delete&id=' . $task->id . ($is_team_view ? '&view_type=team' : ''), 'delete_task_' . $task->id); ?>" 
                                   class="ab-action-btn ab-action-danger" title="Sil"
                                   onclick="return confirm('Bu görevi silmek istediğinizden emin misiniz?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
        <div class="ab-pagination">
            <?php
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '', 'prev_text' => '&laquo;', 'next_text' => '&raquo;',
                'total' => $total_pages, 'current' => $current_page
            ));
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif (empty($no_tasks_message)): // Görev yoksa ve özel mesaj yoksa ?>
    <div class="ab-empty-state">
        <i class="fas fa-tasks"></i>
        <h3>Görev bulunamadı</h3>
        <p>Arama kriterlerinize uygun görev bulunamadı veya bu görünüm için atanmış görev yok.</p>
        <a href="?view=tasks<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="ab-btn">Tüm Görevleri Göster</a>
    </div>
    <?php endif; ?>
</div>

<?php
// Eğer action=view, action=new veya action=edit ise ilgili dosyayı dahil et
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view': if (isset($_GET['id'])) { include_once('task-view.php'); } break;
        case 'new': case 'edit': include_once('task-form.php'); break;
    }
}
?>

<style>
/* Ana Container */
.ab-crm-container {
    max-width: 96%;
    margin: 0 auto 30px;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    color: #333;
}

/* Başlık Alanı */
.ab-crm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
    flex-wrap: wrap;
}

.ab-crm-title-area {
    display: flex;
    align-items: center;
}

.ab-crm-header h1 {
    font-size: 24px;
    margin: 0;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-crm-header-actions {
    display: flex;
    gap: 10px;
}

.ab-view-toggle {
    display: flex;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
}

.ab-view-btn {
    padding: 8px 12px;
    background: #f5f5f5;
    color: #555;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s;
}

.ab-view-btn.active {
    background: #4caf50;
    color: white;
}

.ab-view-btn:hover:not(.active) {
    background: #eee;
}

.ab-view-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background-color: #e6f7ff;
    color: #1890ff;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
}

.selected-date {
    font-weight: 500;
    color: #4caf50;
    padding: 3px 8px;
    background-color: #f0fff4;
    border-radius: 4px;
}

/* Özet İstatistik Kartları */
.ab-task-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.ab-summary-card {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
    padding: 20px;
    display: flex;
    align-items: center;
    transition: transform 0.2s;
    border: 1px solid #eee;
}

.ab-summary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.ab-summary-card-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: white;
    font-size: 20px;
}

.ab-icon-blue {
    background: linear-gradient(135deg, #2196F3, #1976D2);
}

.ab-icon-green {
    background: linear-gradient(135deg, #4CAF50, #388E3C);
}

.ab-icon-orange {
    background: linear-gradient(135deg, #FF9800, #F57C00);
}

.ab-icon-red {
    background: linear-gradient(135deg, #F44336, #D32F2F);
}

.ab-summary-card-body {
    flex: 1;
}

.ab-summary-card-body h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    font-weight: 500;
    color: #666;
}

.ab-summary-card-value {
    font-size: 24px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.ab-summary-card-info {
    font-size: 12px;
    color: #888;
}

/* Zaman Filtre Sekmeleri */
.ab-time-filter-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.ab-time-filter-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 20px;
    color: #555;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.2s;
}

.ab-time-filter-tab:hover {
    background: #efefef;
    color: #333;
    text-decoration: none;
}

.ab-time-filter-tab.active {
    background: #4caf50;
    color: white;
    border-color: #43a047;
}

.ab-time-filter-tab.warning-tab {
    background-color: #fff1f0;
    color: #cf1322;
    border-color: #ffccc7;
}

.ab-time-filter-tab .ab-badge {
    background: rgba(255, 255, 255, 0.3);
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.ab-time-filter-tab.active .ab-badge {
    background: rgba(255, 255, 255, 0.8);
    color: #555;
}

.ab-time-filter-tab.warning-tab.active {
    background: #f5222d;
    color: white;
    border-color: #cf1322;
}

.ab-time-filter-tab.warning-tab.active .ab-badge {
    background: rgba(255, 255, 255, 0.8);
    color: #f5222d;
}

.ab-badge-warning {
    background-color: #fff8e5;
    color: #bf8700;
}

/* İstatistik Dashboard Paneli */
.ab-task-stats-dashboard {
    margin: 20px 0;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    border: 1px solid #eee;
}

.ab-stats-dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #eee;
}

.ab-stats-dashboard-header h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-stats-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-stats-content {
    padding: 20px;
}

.ab-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.ab-stats-chart-container {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    border: 1px solid #f0f0f0;
}

.ab-chart-wide {
    grid-column: span 2;
}

.ab-stats-chart-header {
    padding: 12px 15px;
    border-bottom: 1px solid #f0f0f0;
    background: #f9f9f9;
}

.ab-stats-chart-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
}

.ab-stats-chart-body {
    padding: 15px;
    height: 220px;
    position: relative;
}

/* Filtreleme Alanları */
.ab-filter-toggle-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.ab-toggle-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    background-color: #f8f9fa;
    border: 1px solid #ddd;
    font-weight: 500;
    transition: all 0.2s;
}

.ab-toggle-filters:hover, .ab-toggle-filters.active {
    background-color: #e9ecef;
    border-color: #ccc;
}

.ab-toggle-filters.active i.fa-chevron-down {
    transform: rotate(180deg);
}

.ab-active-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #555;
    padding: 5px 10px;
    background-color: #f0f8ff;
    border-radius: 4px;
    border: 1px solid #cce5ff;
}

.ab-clear-filters {
    color: #007bff;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 3px;
}

.ab-clear-filters:hover {
    text-decoration: underline;
}

.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ab-filters-hidden {
    display: none !important;
}

.ab-filter-form {
    width: 100%;
}

.ab-form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    align-items: end;
}

.ab-filter-col {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.ab-filter-col label {
    font-size: 14px;
    font-weight: 500;
    color: #444;
    margin-bottom: 8px;
    line-height: 1.4;
}

.ab-select, .ab-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-select:focus, .ab-input:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-button-col {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

/* Butonlar */
.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 13px;
    transition: all 0.2s;
    font-weight: 500;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-primary {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
}

.ab-btn-primary:hover {
    background-color: #3d9140;
    color: white;
}

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-btn-filter {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-filter:hover {
    background-color: #3d9140;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-reset {
    background-color: #f8f9fa;
    border-color: #d1d5db;
    color: #666;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-reset:hover {
    background-color: #e5e7eb;
    color: #444;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

/* Tablo Stilleri */
.ab-crm-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
    background-color: #fff;
    border-radius: 8px;
    border: 1px solid #eee;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

.ab-crm-table-info {
    padding: 10px 15px;
    font-size: 13px;
    color: #666;
    border-bottom: 1px solid #eee;
    background-color: #f8f9fa;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ab-crm-table {
    width: 100%;
    border-collapse: collapse;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

.ab-crm-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #444;
}

.ab-crm-table th a {
    color: #444;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.ab-crm-table th a:hover {
    color: #000;
}

.ab-crm-table tr:hover td {
    background-color: #f5f5f5;
}

.ab-crm-table tr:last-child td {
    border-bottom: none;
}

/* Özel satır renklendirme */
tr.task-pending td {
    background-color: #ffffff !important;
}

tr.task-in-progress td {
    background-color: #f8f9ff !important;
}

tr.task-in-progress td:first-child {
    border-left: 3px solid #2196f3;
}

tr.task-completed td {
    background-color: #f0fff0 !important;
}

tr.task-completed td:first-child {
    border-left: 3px solid #4caf50;
}

tr.task-cancelled td {
    background-color: #f5f5f5 !important;
}

tr.task-cancelled td:first-child {
    border-left: 3px solid #9e9e9e;
}

tr.priority-high td:first-child {
    border-left-width: 5px !important;
}

tr.overdue td {
    background-color: #fff2f2 !important;
}

tr.overdue td:first-child {
    border-left: 3px solid #e53935 !important;
}

tr.today td {
    background-color: #fffde7 !important;
}

tr.today td:first-child {
    border-left: 3px solid #ffc107;
}

/* Bağlantı ve veri stilleri */
.ab-task-description {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ab-task-description:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link, .ab-policy-link {
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-link:hover, .ab-policy-link:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-actions-column {
    text-align: center;
    width: 120px;
    min-width: 120px;
}

.ab-actions-cell {
    text-align: center;
}

.ab-date-cell {
    font-size: 12px;
    white-space: nowrap;
}

.ab-due-date {
    white-space: nowrap;
}

.ab-due-date.overdue {
    color: #cb2431;
    font-weight: 500;
}

.ab-due-date.today {
    color: #ff8c00;
    font-weight: 500;
}

.ab-no-value {
    color: #999;
    font-style: italic;
    font-size: 12px;
}

.ab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
}

.ab-badge-priority-low {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-priority-medium {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-priority-high {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-task-pending {
    background-color: #f1f8ff;
    color: #0366d6;
}

.ab-badge-task-in_progress {
    background-color: #fff8e5;  
    color: #bf8700;
}

.ab-badge-task-completed {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-task-cancelled {
    background-color: #f5f5f5;
    color: #666;
}

.ab-badge-danger {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-today {
    background-color: #fffde7;
    color: #ff8c00;
}

.ab-actions {
    display: flex;
    gap: 6px;
    justify-content: center;
}

.ab-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    color: #555;
    background-color: #f8f9fa;
    border: 1px solid #eee;
    transition: all 0.2s;
    text-decoration: none;
}

.ab-action-btn:hover {
    background-color: #eee;
    color: #333;
    text-decoration: none;
}

.ab-action-complete:hover {
    background-color: #e6ffed;
    color: #22863a;
    border-color: #c3e6cb;
}

.ab-action-danger:hover {
    background-color: #ffe5e5;
    color: #d32f2f;
    border-color: #ffcccc;
}

/* Boş Durum Gösterimi */
.ab-empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #666;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-top: 20px;
}

.ab-empty-state i {
    font-size: 48px;
    color: #999;
    margin-bottom: 15px;
}

.ab-empty-state h3 {
    margin: 10px 0;
    font-size: 18px;
    color: #444;
}

.ab-empty-state p {
    margin-bottom: 20px;
    font-size: 14px;
}

.ab-pagination {
    padding: 15px;
    display: flex;
    justify-content: center;
    align-items: center;
    border-top: 1px solid #eee;
}

.ab-pagination .page-numbers {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 6px;
    margin: 0 4px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    color: #333;
    text-decoration: none;
    font-size: 14px;
}

.ab-pagination .page-numbers.current {
    background-color: #4caf50;
    color: white;
    border-color: #43a047;
}

.ab-pagination .page-numbers:hover:not(.current) {
    background-color: #f5f5f5;
    border-color: #ccc;
}

/* Gizleme için stil */
.ab-hidden {
    display: none;
}

/* Duyarlı Tasarım */
@media (max-width: 1200px) {
    .ab-crm-container {
        max-width: 98%;
        padding: 15px;
    }

    .ab-form-row {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    
    .ab-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-chart-wide {
        grid-column: auto;
    }
}

@media (max-width: 992px) {
    .ab-crm-container {
        max-width: 100%;
        margin: 0 10px;
        padding: 15px;
    }
    
    .ab-task-summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .ab-crm-table th:nth-child(3),
    .ab-crm-table td:nth-child(3) {
        display: none;
    }

    .ab-form-row {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
    }
    
    .ab-time-filter-tabs {
        overflow-x: auto;
        padding-bottom: 10px;
    }
    
    .ab-time-filter-tab {
        flex-shrink: 0;
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 10px;
        margin: 0 5px;
    }
    
    .ab-task-summary-cards {
        grid-template-columns: 1fr;
    }
    
    .ab-form-row {
        grid-template-columns: 1fr;
    }
    
    .ab-crm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .ab-crm-header-actions {
        width: 100%;
        flex-direction: column;
        gap: 10px;
    }
    
    .ab-view-toggle {
        width: 100%;
    }
    
    .ab-view-btn {
        flex: 1;
        text-align: center;
    }
    
    .ab-btn-primary {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 576px) {
    .ab-crm-container {
        margin: 0;
        border-radius: 0;
        box-shadow: none;
    }
    
    .ab-crm-table th:nth-child(7),
    .ab-crm-table td:nth-child(7) {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // İstatistikleri kontrol fonksiyonu
    let statsVisible = true;
    const toggleStatsBtn = document.getElementById('toggle-task-stats');
    const statsContent = document.querySelector('.ab-stats-content');
    
    if (toggleStatsBtn && statsContent) {
        toggleStatsBtn.addEventListener('click', function() {
            if (statsVisible) {
                statsContent.style.display = 'none';
                toggleStatsBtn.innerHTML = '<i class="fas fa-chevron-down"></i>';
            } else {
                statsContent.style.display = 'block';
                toggleStatsBtn.innerHTML = '<i class="fas fa-chevron-up"></i>';
            }
            statsVisible = !statsVisible;
        });
    }
    
    // Filtreleme toggle kontrolü
    const toggleFiltersBtn = document.getElementById('toggle-tasks-filters-btn');
    const filtersContainer = document.getElementById('tasks-filters-container');
    
    if (toggleFiltersBtn && filtersContainer) {
        toggleFiltersBtn.addEventListener('click', function() {
            filtersContainer.classList.toggle('ab-filters-hidden');
            toggleFiltersBtn.classList.toggle('active');
        });
    }

    // İlk yükleme durumunda aktif filtre varsa filtreleri göster
    <?php if ($active_filter_count > 0): ?>
    if (filtersContainer) {
        filtersContainer.classList.remove('ab-filters-hidden');
        if (toggleFiltersBtn) toggleFiltersBtn.classList.add('active');
    }
    <?php endif; ?>
    
    // Chart.js grafikleri için renkler
    const chartColors = {
        status: {
            'Beklemede': '#0366d6',
            'İşlemde': '#bf8700',
            'Tamamlandı': '#22863a',
            'İptal Edildi': '#6c757d',
            'Gecikmiş': '#cb2431'
        },
        priority: {
            'Yüksek': '#cb2431',
            'Orta': '#bf8700',
            'Düşük': '#22863a'
        },
        time: {
            'Bugün': '#ff8c00',
            'Yarın': '#2196f3',
            'Bu Hafta': '#4CAF50',
            'Gelecek Hafta': '#9c27b0',
            'Bu Ay': '#607d8b',
            'Gelecek Ay': '#795548',
            'Gecikmiş': '#f44336'
        }
    };
    
    // Durum grafiği
    const statusChartCanvas = document.getElementById('taskStatusChart');
    if (statusChartCanvas && typeof Chart !== 'undefined') {
        const statusData = <?php echo json_encode(array_values($status_chart_data)); ?>;
        const statusLabels = <?php echo json_encode(array_keys($status_chart_data)); ?>;
        const statusColors = statusLabels.map(label => chartColors.status[label] || '#999');
        
        if (statusData.length > 0) {
            new Chart(statusChartCanvas, {
                type: 'pie',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusData,
                        backgroundColor: statusColors,
                        borderWidth: 1,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let value = context.raw;
                                    let sum = context.dataset.data.reduce((a, b) => a + b, 0);
                                    let percentage = Math.round((value * 100) / sum) + '%';
                                    return context.label + ': ' + value + ' görev (' + percentage + ')';
                                }
                            }
                        }
                    }
                }
            });
        } else {
            const ctx = statusChartCanvas.getContext('2d');
            ctx.font = '14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Grafik için yeterli veri bulunmamaktadır.', statusChartCanvas.width / 2, statusChartCanvas.height / 2);
        }
    }
    
    // Öncelik grafiği
    const priorityChartCanvas = document.getElementById('taskPriorityChart');
    if (priorityChartCanvas && typeof Chart !== 'undefined') {
        const priorityData = <?php echo json_encode(array_values($priority_chart_data)); ?>;
        const priorityLabels = <?php echo json_encode(array_keys($priority_chart_data)); ?>;
        const priorityColors = priorityLabels.map(label => chartColors.priority[label] || '#999');
        
        if (priorityData.length > 0) {
            new Chart(priorityChartCanvas, {
                type: 'pie',
                data: {
                    labels: priorityLabels,
                    datasets: [{
                        data: priorityData,
                        backgroundColor: priorityColors,
                        borderWidth: 1,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let value = context.raw;
                                    let sum = context.dataset.data.reduce((a, b) => a + b, 0);
                                    let percentage = Math.round((value * 100) / sum) + '%';
                                    return context.label + ': ' + value + ' görev (' + percentage + ')';
                                }
                            }
                        }
                    }
                }
            });
        } else {
            const ctx = priorityChartCanvas.getContext('2d');
            ctx.font = '14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Grafik için yeterli veri bulunmamaktadır.', priorityChartCanvas.width / 2, priorityChartCanvas.height / 2);
        }
    }
    
    // Zaman dağılımı grafiği
    const timeChartCanvas = document.getElementById('taskTimeChart');
    if (timeChartCanvas && typeof Chart !== 'undefined') {
        const timeData = <?php echo json_encode(array_values($time_chart_data)); ?>;
        const timeLabels = <?php echo json_encode(array_keys($time_chart_data)); ?>;
        const timeColors = timeLabels.map(label => chartColors.time[label] || '#999');
        
        if (timeData.length > 0) {
            new Chart(timeChartCanvas, {
                type: 'bar',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Görev Sayısı',
                        data: timeData,
                        backgroundColor: timeColors,
                        borderColor: timeColors.map(color => color),
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
                                precision: 0
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
                                    return context.dataset.label + ': ' + context.raw;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            const ctx = timeChartCanvas.getContext('2d');
            ctx.font = '14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Grafik için yeterli veri bulunmamaktadır.', timeChartCanvas.width / 2, timeChartCanvas.height / 2);
        }
    }
    
    // Görev düğmelerinin olay dinleyicileri
    const completeButtons = document.querySelectorAll('.ab-action-complete');
    completeButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            console.log('Görev tamamlama işlemi başlatıldı - Kullanıcı: <?php echo esc_js(wp_get_current_user()->user_login); ?> - Tarih: <?php echo date("Y-m-d H:i:s"); ?>');
        });
    });
    
    const deleteButtons = document.querySelectorAll('.ab-action-danger');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            console.log('Görev silme işlemi başlatıldı - Kullanıcı: <?php echo esc_js(wp_get_current_user()->user_login); ?> - Tarih: <?php echo date("Y-m-d H:i:s"); ?>');
        });
    });
});
</script>

<?php
// İlgili formlara yönlendirme
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view':
            if (isset($_GET['id'])) {
                include_once('task-view.php');
            }
            break;
        case 'new':
        case 'edit':
            include_once('task-form.php');
            break;
    }
}
?>