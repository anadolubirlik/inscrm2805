<?php
/**
 * Frontend Poliçe Yönetim Sayfası
 * @version 4.0.0 - İçeri Aktarım İşlemleri Ayrı Dosyaya Taşındı
 * @date 2025-05-26 19:13:02
 * @user anadolubirlik
 */

// Kullanıcı oturum kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Veritabanı tablolarını tanımlama
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

// Kullanıcının rolüne göre erişim düzeyi belirlenmesi
function get_user_role_level() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    $rep = $wpdb->get_row($wpdb->prepare(
        "SELECT id, role, customer_edit, customer_delete, policy_edit, policy_delete 
        FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d",
        $current_user_id
    ));
    
    if (!$rep) return 5; // Varsayılan olarak en düşük yetki
    
    return intval($rep->role); // 1: Patron, 2: Müdür, 3: Müdür Yard., 4: Ekip Lideri, 5: Müş. Temsilcisi
}

// Rol adı alma fonksiyonu
function get_role_name($role_level) {
    $role_names = array(
        1 => 'Patron',
        2 => 'Müdür',
        3 => 'Müdür Yardımcısı',
        4 => 'Ekip Lideri',
        5 => 'Müşteri Temsilcisi'
    );
    
    return isset($role_names[$role_level]) ? $role_names[$role_level] : 'Bilinmiyor';
}

// Patron veya müdür kontrolü
if (!function_exists('is_patron_or_manager')) {
    function is_patron_or_manager($user_id) {
        return is_patron($user_id) || is_manager($user_id);
    }
}

// Patron kontrolü
if (!function_exists('is_patron')) {
    function is_patron($user_id) {
        global $wpdb;
        $role_value = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
        
        return intval($role_value) === 1;
    }
}

// Müdür kontrolü
if (!function_exists('is_manager')) {
    function is_manager($user_id) {
        global $wpdb;
        $role_value = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
        
        return intval($role_value) === 2;
    }
}

// Ekip lideri kontrolü
if (!function_exists('is_team_leader')) {
    function is_team_leader($user_id) {
        global $wpdb;
        $role_value = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
        
        return intval($role_value) === 4;
    }
}

// Temsilcinin yetki bilgilerini alma
function get_rep_permissions() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT policy_edit, policy_delete FROM {$wpdb->prefix}insurance_crm_representatives 
        WHERE user_id = %d AND status = 'active'", 
        $current_user_id
    ));
}

// Kullanıcının düzenleme yetkisi var mı?
function can_edit_policy($policy_id, $role_level, $user_rep_id) {
    global $wpdb;
    
    // Temsilcinin özel yetkilerini al
    $rep_permissions = get_rep_permissions();
    
    // Patron her zaman düzenleyebilir
    if ($role_level === 1) return true;
    
    // Müşteri temsilcisi kendi poliçelerini düzenleyebilir (yetki varsa)
    if ($role_level === 5) {
        if (!$rep_permissions || $rep_permissions->policy_edit != 1) {
            return false;
        }
        
        $policy_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT representative_id FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d",
            $policy_id
        ));
        
        return $policy_owner == $user_rep_id;
    }
    
    // Ekip lideri ekibindeki poliçeleri düzenleyebilir mi?
    if ($role_level === 4) {
        // Yetki kontrolü
        if (!$rep_permissions || $rep_permissions->policy_edit != 1) {
            return false;
        }
        
        // Ekip üyesi mi kontrolü yap
        $team_members = get_team_members_ids(get_current_user_id());
        $policy_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT representative_id FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d",
            $policy_id
        ));
        
        return in_array($policy_owner, $team_members);
    }
    
    // Müdür ve Müdür Yardımcıları için yetki kontrolü
    if (($role_level === 2 || $role_level === 3) && $rep_permissions && $rep_permissions->policy_edit == 1) {
        return true;
    }
    
    return false;
}

// Kullanıcının silme yetkisi var mı?
function can_delete_policy($policy_id, $role_level, $user_rep_id) {
    global $wpdb;
    
    // Temsilcinin özel yetkilerini al
    $rep_permissions = get_rep_permissions();
    
    // Patron her zaman silebilir
    if ($role_level === 1) return true;
    
    // Müşteri temsilcileri poliçe silemez, sadece iptal edebilir
    if ($role_level === 5) {
        return false;
    }
    
    // Ekip lideri yetkisi (policy_delete) ve ekip üyelerinin poliçelerini silme kontrolü
    if ($role_level === 4) {
        if (!$rep_permissions || $rep_permissions->policy_delete != 1) {
            return false;
        }
        
        // Ekip üyesi mi kontrolü yap
        $team_members = get_team_members_ids(get_current_user_id());
        $policy_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT representative_id FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d",
            $policy_id
        ));
        
        return in_array($policy_owner, $team_members);
    }
    
    // Müdür ve Müdür Yardımcıları yetkisi
    if (($role_level === 2 || $role_level === 3) && $rep_permissions && $rep_permissions->policy_delete == 1) {
        return true;
    }
    
    return false;
}

// insured_party sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// Müşteri tablosunda tc_identity sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $customers_table LIKE 'tc_identity'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $customers_table ADD COLUMN tc_identity VARCHAR(20) DEFAULT NULL AFTER last_name");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// Müşteri tablosunda birth_date sütunu kontrolü ve ekleme
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $customers_table LIKE 'birth_date'");
if (!$column_exists) {
    $result = $wpdb->query("ALTER TABLE $customers_table ADD COLUMN birth_date DATE DEFAULT NULL AFTER tc_identity");
    if ($result === false) {
        echo '<div class="ab-notice ab-error">Veritabanı güncellenirken bir hata oluştu: ' . $wpdb->last_error . '</div>';
        return;
    }
}

// İptal işlemleri için yeni sütunlar kontrolü ve ekleme
$cancellation_date_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_date'");
if (!$cancellation_date_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status");
}

$refunded_amount_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'refunded_amount'");
if (!$refunded_amount_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date");
}

// Mevcut kullanıcı temsilcisi ID'sini alma
function get_current_user_rep_id() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'", $current_user_id));
}

// Ekip liderinin ekip üyelerini alma
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

$current_user_rep_id = get_current_user_rep_id();
$user_role_level = get_user_role_level();
$role_name = get_role_name($user_role_level);

// Aktif görünüm belirleme (kişisel veya ekip poliçeleri)
$current_view = isset($_GET['view_type']) ? sanitize_text_field($_GET['view_type']) : 'personal';
$is_team_view = ($current_view === 'team');

// Bildiriler için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Filtreleme için GET parametrelerini al ve sanitize et
$filters = array(
    'policy_number' => isset($_GET['policy_number']) ? sanitize_text_field($_GET['policy_number']) : '',
    'customer_id' => isset($_GET['customer_id']) ? intval($_GET['customer_id']) : '',
    'policy_type' => isset($_GET['policy_type']) ? sanitize_text_field($_GET['policy_type']) : '',
    'insurance_company' => isset($_GET['insurance_company']) ? sanitize_text_field($_GET['insurance_company']) : '',
    'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
    'insured_party' => isset($_GET['insured_party']) ? sanitize_text_field($_GET['insured_party']) : '',
    'date_range' => isset($_GET['date_range']) ? sanitize_text_field($_GET['date_range']) : '',
);

// Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// Temel sorguyu oluştur
$base_query = "FROM $policies_table p 
               LEFT JOIN $customers_table c ON p.customer_id = c.id
               LEFT JOIN $representatives_table r ON p.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Yetkilere göre sorguyu düzenle
if ($user_role_level === 1 || $user_role_level === 2 || $user_role_level === 3) {
    if (!$is_team_view) {
        // Kişisel görünüm: Sadece kendi yaptığı poliçeleri göster
        $base_query .= $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
    }
    // Ekip görünümünde tüm poliçeleri görebilir (Patron, Müdür, Müdür Yardımcısı)
} else if ($user_role_level === 4) {
    // Ekip lideri ise
    if ($is_team_view) {
        // Ekip görünümü: Ekipteki tüm temsilcilerin poliçelerini göster
        $team_member_ids = get_team_members_ids(get_current_user_id());
        if (!empty($team_member_ids)) {
            $member_placeholders = implode(',', array_fill(0, count($team_member_ids), '%d'));
            $base_query .= $wpdb->prepare(" AND p.representative_id IN ($member_placeholders)", ...$team_member_ids);
        } else {
            // Ekip yoksa sadece kendi poliçelerini görsün
            $base_query .= $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Kişisel görünüm: Sadece kendi poliçelerini göster
        $base_query .= $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
    }
} else {
    // Normal müşteri temsilcisi sadece kendi poliçelerini görebilir
    $base_query .= $wpdb->prepare(" AND p.representative_id = %d", $current_user_rep_id);
}

// Filtreleme kriterlerini ekle
if (!empty($filters['policy_number'])) {
    $base_query .= $wpdb->prepare(" AND p.policy_number LIKE %s", '%' . $wpdb->esc_like($filters['policy_number']) . '%');
}
if (!empty($filters['customer_id'])) {
    $base_query .= $wpdb->prepare(" AND p.customer_id = %d", $filters['customer_id']);
}
if (!empty($filters['policy_type'])) {
    $base_query .= $wpdb->prepare(" AND p.policy_type = %s", $filters['policy_type']);
}
if (!empty($filters['insurance_company'])) {
    $base_query .= $wpdb->prepare(" AND p.insurance_company = %s", $filters['insurance_company']);
}
if (!empty($filters['status'])) {
    $base_query .= $wpdb->prepare(" AND p.status = %s", $filters['status']);
}
if (!empty($filters['insured_party'])) {
    $base_query .= $wpdb->prepare(" AND p.insured_party LIKE %s", '%' . $wpdb->esc_like($filters['insured_party']) . '%');
}

// Tarih aralığı filtresi
if (!empty($filters['date_range'])) {
    $dates = explode(' - ', $filters['date_range']);
    if (count($dates) === 2) {
        $start_date = date('Y-m-d', strtotime($dates[0]));
        $end_date = date('Y-m-d', strtotime($dates[1]));
        $base_query .= $wpdb->prepare(" AND p.start_date >= %s AND p.end_date <= %s", $start_date, $end_date);
    }
}

// Toplam kayıt sayısını al
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT p.id) " . $base_query);

// İSTATİSTİK SORGULARI - RAPORLAMA İÇİN

// 1. Bu ay ve geçen ay eklenen poliçeler
$this_month_start = date('Y-m-01 00:00:00');
$this_month_end = date('Y-m-t 23:59:59');
$this_month_policies_count = $wpdb->get_var(
    "SELECT COUNT(*) FROM $policies_table p
    WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . "
    AND p.created_at BETWEEN '$this_month_start' AND '$this_month_end'"
);

$last_month_start = date('Y-m-01 00:00:00', strtotime('-1 month'));
$last_month_end = date('Y-m-t 23:59:59', strtotime('-1 month'));
$last_month_policies_count = $wpdb->get_var(
    "SELECT COUNT(*) FROM $policies_table p
    WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
    AND p.created_at BETWEEN '$last_month_start' AND '$last_month_end'"
);

// 2. İade bilgileri
$total_refund_amount = $wpdb->get_var(
    "SELECT COALESCE(SUM(refunded_amount), 0) FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     AND refunded_amount > 0"
);

$refunded_policies_count = $wpdb->get_var(
    "SELECT COUNT(*) FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     AND refunded_amount > 0"
);

// 3. Poliçe türlerine göre dağılım (pasta grafiği için)
$policy_type_distribution = $wpdb->get_results(
    "SELECT policy_type, COUNT(*) as count 
     FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     GROUP BY policy_type 
     ORDER BY count DESC"
);

// 4. Sigorta şirketlerine göre dağılım
$insurance_company_distribution = $wpdb->get_results(
    "SELECT insurance_company, COUNT(*) as count 
     FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     GROUP BY insurance_company 
     ORDER BY count DESC"
);

// 5. Aylık poliçe trendi (son 6 ay)
$monthly_trend_data = array();
$monthly_trend_labels = array();

for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01 00:00:00', strtotime("-$i month"));
    $month_end = date('Y-m-t 23:59:59', strtotime("-$i month"));
    $month_label = date('M Y', strtotime("-$i month"));
    
    $monthly_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $policies_table p 
         WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
         AND p.created_at BETWEEN %s AND %s",
        $month_start, $month_end
    ));
    
    $monthly_trend_data[] = intval($monthly_count);
    $monthly_trend_labels[] = $month_label;
}

// 6. Aylık prim tutarları (son 6 ay)
$monthly_premium_data = array();

for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01 00:00:00', strtotime("-$i month"));
    $month_end = date('Y-m-t 23:59:59', strtotime("-$i month"));
    
    $monthly_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) FROM $policies_table p 
         WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
         AND p.created_at BETWEEN %s AND %s",
        $month_start, $month_end
    ));
    
    $monthly_premium_data[] = floatval($monthly_premium);
}

// 7. Aktif/Pasif/İptal edilen poliçe sayıları
$active_policies = $wpdb->get_var(
    "SELECT COUNT(*) FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     AND p.status = 'aktif' AND p.cancellation_date IS NULL"
);

$passive_policies = $wpdb->get_var(
    "SELECT COUNT(*) FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     AND p.status = 'pasif' AND p.cancellation_date IS NULL"
);

$cancelled_policies = $wpdb->get_var(
    "SELECT COUNT(*) FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
     AND p.cancellation_date IS NOT NULL"
);

// 8. Yakında sona erecek poliçeler (ay bazında gelecek 6 ay)
$expiring_policies = array();
$expiring_labels = array();
$current_date = date('Y-m-d');

for ($i = 0; $i < 6; $i++) {
    $month_start = date('Y-m-01', strtotime("+$i month"));
    $month_end = date('Y-m-t', strtotime("+$i month"));
    $month_label = date('M Y', strtotime("+$i month"));
    
    $expiring_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $policies_table p 
         WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5) . " 
         AND p.end_date BETWEEN %s AND %s
         AND p.status = 'aktif' AND p.cancellation_date IS NULL",
        $month_start, $month_end
    ));
    
    $expiring_policies[] = intval($expiring_count);
    $expiring_labels[] = $month_label;
}

// Toplam prim tutarı ve ortalaması
$total_premium = $wpdb->get_var(
    "SELECT COALESCE(SUM(premium_amount), 0) FROM $policies_table p 
     WHERE " . substr($base_query, strpos($base_query, "WHERE") + 5)
);
$avg_premium = $total_items > 0 ? $total_premium / $total_items : 0;

// Listede gösterilecek poliçeleri getir
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'p.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

$policies = $wpdb->get_results("
    SELECT p.*, 
           c.first_name, c.last_name, c.tc_identity,
           u.display_name as representative_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Ayarlar ve diğer veriler
$settings = get_option('insurance_crm_settings');
$insurance_companies = isset($settings['insurance_companies']) ? $settings['insurance_companies'] : array();

// Sompo şirketini ekle
if (!in_array('Sompo', $insurance_companies)) {
    $key = array_search('SOMPO', $insurance_companies);
    if ($key !== false) {
        unset($insurance_companies[$key]);
    }
    $insurance_companies[] = 'Sompo';
    $settings['insurance_companies'] = array_values($insurance_companies);
    update_option('insurance_crm_settings', $settings);
}

$policy_types = isset($settings['default_policy_types']) ? $settings['default_policy_types'] : array('Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer');
$customers = $wpdb->get_results("SELECT id, first_name, last_name FROM $customers_table ORDER BY first_name, last_name");
$total_pages = ceil($total_items / $per_page);

$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new' && $current_action !== 'renew' && $current_action !== 'cancel');

// Aktif filtre sayısı
$active_filter_count = count(array_filter($filters));

// Filtreleri sıfırlamak için yardımcı fonksiyon
function reset_all_filters() {
    global $current_view;
    return '?view=policies' . ($current_view === 'team' ? '&view_type=team' : '');
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

<div class="ab-crm-container" id="policies-list-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    <?php echo $notice; ?>

    <!-- Normal Poliçe Listesi -->
    <div class="ab-crm-header">
        <div class="ab-crm-title-area">
            <h1><i class="fas fa-file-contract"></i> Poliçeler</h1>
            <div class="ab-user-role-badge">
                <span class="ab-badge ab-badge-role">
                    <i class="fas fa-user-shield"></i> <?php echo esc_html($role_name); ?>
                </span>
            </div>
        </div>
        
        <div class="ab-crm-header-actions">
            <?php if ($user_role_level <= 4): // Ekip lideri ve üstü için görünüm seçimi ?>
            <div class="ab-view-toggle">
                <a href="?view=policies&view_type=personal" class="ab-view-btn <?php echo !$is_team_view ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> Poliçelerim
                </a>
                <a href="?view=policies&view_type=team" class="ab-view-btn <?php echo $is_team_view ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Ekip Poliçeleri
                </a>
            </div>
            <?php endif; ?>
            
            <a href="?view=policies&action=new" class="ab-btn ab-btn-primary">
                <i class="fas fa-plus"></i> Yeni Poliçe
            </a>
            <a href="?view=iceri_aktarim&type=xml" class="ab-btn ab-btn-primary">
                <i class="fas fa-upload"></i> XML Aktar
            </a>
            <a href="?view=iceri_aktarim&type=csv" class="ab-btn ab-btn-primary">
                <i class="fas fa-file-csv"></i> CSV Aktar
            </a>
        </div>
    </div>
    
    <!-- Özet İstatistikler -->
    <div class="ab-stats-cards">
        <div class="ab-stats-card">
            <div class="ab-stats-card-icon ab-icon-blue">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="ab-stats-card-body">
                <h3>Toplam Poliçe</h3>
                <div class="ab-stats-card-value"><?php echo number_format($total_items); ?></div>
                <div class="ab-stats-card-info">
                    <?php if ($is_team_view && $user_role_level <= 4): ?>
                        Ekip Toplamı
                    <?php elseif ($user_role_level <= 3 && !$is_team_view): ?>
                        Kişisel Poliçeler
                    <?php else: ?>
                        Kişisel Toplam
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="ab-stats-card">
            <div class="ab-stats-card-icon ab-icon-green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="ab-stats-card-body">
                <h3>Aktif Poliçeler</h3>
                <div class="ab-stats-card-value"><?php echo number_format($active_policies); ?></div>
                <div class="ab-stats-card-info">
                    <?php echo number_format($total_items > 0 ? ($active_policies / $total_items) * 100 : 0, 1); ?>% Toplam
                </div>
            </div>
        </div>
        
        <div class="ab-stats-card">
            <div class="ab-stats-card-icon ab-icon-orange">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="ab-stats-card-body">
                <h3>Toplam Prim</h3>
                <div class="ab-stats-card-value">₺<?php echo number_format($total_premium, 0, ',', '.'); ?></div>
                <div class="ab-stats-card-info">
                    Ort: ₺<?php echo number_format($avg_premium, 0, ',', '.'); ?>/poliçe
                </div>
            </div>
        </div>
        
        <div class="ab-stats-card">
            <div class="ab-stats-card-icon ab-icon-red">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <div class="ab-stats-card-body">
                <h3>Bu Ay Sona Erecek</h3>
                <div class="ab-stats-card-value"><?php echo number_format($expiring_policies[0]); ?></div>
                <div class="ab-stats-card-info">
                    <?php echo $is_team_view ? 'Ekip Yenilemeleri' : 'Kişisel Yenilemeler'; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Gelişmiş İstatistik Grafikleri -->
    <div class="ab-stats-dashboard">
        <div class="ab-stats-dashboard-header">
            <h2>
                <i class="fas fa-chart-pie"></i> 
                <?php if ($is_team_view && $user_role_level <= 4): ?>
                    Ekip Poliçe İstatistikleri
                <?php else: ?>
                    <?php echo $user_role_level <= 3 && !$is_team_view ? 'Kişisel Poliçe İstatistikleri' : 'Poliçe İstatistikleri'; ?>
                <?php endif; ?>
            </h2>
            <div class="ab-stats-actions">
                <button id="toggle-stats-view" class="ab-btn ab-btn-sm">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
        </div>
        
        <div class="ab-stats-content">
            <div class="ab-stats-grid">
                <!-- İlk satır: Poliçe türü ve şirket grafikleri -->
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Poliçe Türü Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="policyTypeChart"></canvas>
                    </div>
                </div>
                
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Sigorta Şirketi Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="insuranceCompanyChart"></canvas>
                    </div>
                </div>
                
                <!-- İkinci satır: Durum ve sona erecek poliçeler -->
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Poliçe Durumları</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="policyStatusChart"></canvas>
                    </div>
                </div>
                
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Yakında Sona Erecek Poliçeler</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="expiringPoliciesChart"></canvas>
                    </div>
                </div>
                
                <!-- Üçüncü satır: Aylık poliçe ve prim trendleri -->
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Aylık Poliçe Trendi (Son 6 Ay)</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>
                
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Aylık Prim Trendi (Son 6 Ay)</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="monthlyPremiumChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtreleme Butonu ve Form -->
    <div class="ab-filter-toggle-container">
        <button type="button" id="toggle-filters-btn" class="ab-btn ab-toggle-filters">
            <i class="fas fa-filter"></i> Filtreleme Seçenekleri <i class="fas fa-chevron-down"></i>
        </button>
        
        <?php if ($active_filter_count > 0): ?>
        <div class="ab-active-filters">
            <span><?php echo $active_filter_count; ?> aktif filtre</span>
            <a href="?view=policies<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="ab-clear-filters">
                <i class="fas fa-times"></i> Temizle
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Filtreleme formu - Varsayılan olarak gizli -->
    <div id="policies-filters-container" class="ab-crm-filters ab-filters-hidden">
        <form method="get" id="policies-filter" class="ab-filter-form">
            <input type="hidden" name="view" value="policies">
            <?php if ($is_team_view): ?>
            <input type="hidden" name="view_type" value="team">
            <?php endif; ?>
            
            <div class="ab-filter-row">
                <div class="ab-filter-col">
                    <label for="policy_number">Poliçe No</label>
                    <input type="text" name="policy_number" id="policy_number" value="<?php echo esc_attr($filters['policy_number']); ?>" placeholder="Poliçe No Ara..." class="ab-select">
                </div>
                
                <div class="ab-filter-col">
                    <label for="customer_id">Müşteri</label>
                    <select name="customer_id" id="customer_id" class="ab-select">
                        <option value="">Tüm Müşteriler</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c->id; ?>" <?php selected($filters['customer_id'], $c->id); ?>>
                                <?php echo esc_html($c->first_name . ' ' . $c->last_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="policy_type">Poliçe Türü</label>
                    <select name="policy_type" id="policy_type" class="ab-select">
                        <option value="">Tüm Poliçe Türleri</option>
                        <?php foreach ($policy_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php selected($filters['policy_type'], $type); ?>>
                                <?php echo esc_html($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="insurance_company">Sigorta Firması</label>
                    <select name="insurance_company" id="insurance_company" class="ab-select">
                        <option value="">Tüm Sigorta Firmaları</option>
                        <?php foreach ($insurance_companies as $company): ?>
                            <option value="<?php echo $company; ?>" <?php selected($filters['insurance_company'], $company); ?>>
                                <?php echo esc_html($company); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="ab-select">
                        <option value="">Tüm Durumlar</option>
                        <option value="aktif" <?php selected($filters['status'], 'aktif'); ?>>Aktif</option>
                        <option value="pasif" <?php selected($filters['status'], 'pasif'); ?>>Pasif</option>
                    </select>
                </div>
                
                <div class="ab-filter-col">
                    <label for="insured_party">Sigorta Ettiren</label>
                    <input type="text" name="insured_party" id="insured_party" value="<?php echo esc_attr($filters['insured_party']); ?>" placeholder="Sigorta Ettiren Ara..." class="ab-select">
                </div>
                
                <div class="ab-filter-col">
                    <label for="date_range">Tarih Aralığı</label>
                    <input type="text" name="date_range" id="date_range" value="<?php echo esc_attr($filters['date_range']); ?>" placeholder="Tarih aralığı seçin" class="ab-select" readonly>
                </div>
                
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" class="ab-btn ab-btn-filter">Filtrele</button>
                    <a href="?view=policies<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="ab-btn ab-btn-reset">Sıfırla</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Poliçe Listesi -->
    <?php if (!empty($policies)): ?>
    <div class="ab-crm-table-wrapper">
        <div class="ab-crm-table-info">
            <span>Toplam: <?php echo number_format($total_items); ?> poliçe</span>
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
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.policy_number', 'order' => $order === 'ASC' && $orderby === 'p.policy_number' ? 'DESC' : 'ASC')); ?>">
                            Poliçe No <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Müşteri</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.policy_type', 'order' => $order === 'ASC' && $orderby === 'p.policy_type' ? 'DESC' : 'ASC')); ?>">
                            Poliçe Türü <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Sigorta Firması</th>
                    <th>
                        <a href="<?php echo add_query_arg(array('orderby' => 'p.end_date', 'order' => $order === 'ASC' && $orderby === 'p.end_date' ? 'DESC' : 'ASC')); ?>">
                            Bitiş Tarihi <i class="fas fa-sort"></i>
                        </a>
                    </th>
                    <th>Prim</th>
                    <th>Durum</th>
                    <?php if ($is_team_view || $user_role_level <= 3): ?>
                    <th>Temsilci</th>
                    <?php endif; ?>
                    <th>Döküman</th>
                    <th class="ab-actions-column">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($policies as $policy): 
                    $is_expired = strtotime($policy->end_date) < time();
                    $is_expiring_soon = !$is_expired && (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60);
                    $is_cancelled = !empty($policy->cancellation_date);
                    
                    $row_class = '';
                    if ($is_cancelled) {
                        $row_class = 'cancelled';
                    } elseif ($is_expired) {
                        $row_class = 'expired';
                    } elseif ($is_expiring_soon) {
                        $row_class = 'expiring-soon';
                    }
                    
                    if ($policy->policy_type === 'Kasko' || $policy->policy_type === 'Trafik') {
                        $row_class .= ' policy-vehicle';
                    } elseif ($policy->policy_type === 'Konut' || $policy->policy_type === 'DASK') {
                        $row_class .= ' policy-property';
                    } elseif ($policy->policy_type === 'Sağlık' || $policy->policy_type === 'Hayat') {
                        $row_class .= ' policy-health';
                    }
                    
                    $can_edit = can_edit_policy($policy->id, $user_role_level, $current_user_rep_id);
                    $can_delete = can_delete_policy($policy->id, $user_role_level, $current_user_rep_id);
                ?>
                    <tr class="<?php echo trim($row_class); ?>">
                        <td>
                            <a href="?view=policies&action=view&id=<?php echo $policy->id; ?>" class="ab-policy-number">
                                <?php echo esc_html($policy->policy_number); ?>
                                <?php if ($is_cancelled): ?>
                                    <span class="ab-badge ab-badge-cancelled">İptal Edilmiş</span>
                                <?php elseif ($is_expired): ?>
                                    <span class="ab-badge ab-badge-danger">Süresi Dolmuş</span>
                                <?php elseif ($is_expiring_soon): ?>
                                    <span class="ab-badge ab-badge-warning">Yakında Bitiyor</span>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td>
                            <a href="?view=customers&action=view&id=<?php echo $policy->customer_id; ?>" class="ab-customer-link">
                                <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                <?php if (!empty($policy->tc_identity)): ?>
                                    <small>(<?php echo esc_html($policy->tc_identity); ?>)</small>
                                <?php endif; ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($policy->policy_type); ?></td>
                        <td><?php echo esc_html($policy->insurance_company); ?></td>
                        <td class="ab-date-cell"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                        <td class="ab-amount"><?php echo number_format($policy->premium_amount, 2, ',', '.') . ' ₺'; ?></td>
                        <td>
                            <span class="ab-badge ab-badge-status-<?php echo esc_attr($policy->status); ?>">
                                <?php echo $policy->status === 'aktif' ? 'Aktif' : 'Pasif'; ?>
                            </span>
                            <?php if (!empty($policy->cancellation_date)): ?>
                                <br><small class="ab-cancelled-date">İptal: <?php echo date('d.m.Y', strtotime($policy->cancellation_date)); ?></small>
                            <?php endif; ?>
                        </td>
                        <?php if ($is_team_view || $user_role_level <= 3): ?>
                        <td><?php echo !empty($policy->representative_name) ? esc_html($policy->representative_name) : '—'; ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if (!empty($policy->document_path)): ?>
                                <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" title="Dökümanı Görüntüle" class="ab-btn ab-btn-sm">
                                    <i class="fas fa-file-pdf"></i> Görüntüle
                                </a>
                            <?php else: ?>
                                <span class="ab-no-document">Döküman yok</span>
                            <?php endif; ?>
                        </td>
                        <td class="ab-actions-cell">
                            <div class="ab-actions">
                                <a href="?view=policies&action=view&id=<?php echo $policy->id; ?>" title="Görüntüle" class="ab-action-btn">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <?php if ($can_edit): ?>
                                <a href="?view=policies&action=edit&id=<?php echo $policy->id; ?>" title="Düzenle" class="ab-action-btn">
                                    <i class="fas fa-edit"></i>
                                </a>
                                
                                <?php if ($policy->status === 'aktif' && empty($policy->cancellation_date)): ?>
                                <a href="<?php echo wp_nonce_url('?view=policies&action=cancel&id=' . $policy->id, 'delete_policy_' . $policy->id); ?>" 
                                   title="İptal Et" class="ab-action-btn ab-action-danger"
                                   onclick="return confirm('Bu poliçeyi iptal etmek istediğinizden emin misiniz?');">
                                    <i class="fas fa-ban"></i>
                                </a>
                                <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($can_delete): ?>
                                <a href="<?php echo wp_nonce_url('?view=policies&action=delete&id=' . $policy->id, 'permanently_delete_policy_' . $policy->id); ?>" 
                                   title="Kalıcı Olarak Sil" class="ab-action-btn ab-action-permanent-delete"
                                   onclick="return confirm('DİKKAT! Bu poliçeyi kalıcı olarak silmek üzeresiniz. Bu işlem geri alınamaz. Devam etmek istiyor musunuz?');">
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
            $pagination_args = array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '«',
                'next_text' => '»',
                'total' => $total_pages,
                'current' => $current_page
            );
            echo paginate_links($pagination_args);
            ?>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="ab-empty-state">
        <i class="fas fa-file-contract"></i>
        <h3>Poliçe bulunamadı</h3>
        <p>
            <?php if ($is_team_view): ?>
                Ekibinize ait poliçe bulunamadı.
            <?php else: ?>
                Arama kriterlerinize uygun poliçe bulunamadı.
            <?php endif; ?>
        </p>
        <a href="?view=policies<?php echo $is_team_view ? '&view_type=team' : ''; ?>" class="ab-btn">Tüm Poliçeleri Göster</a>
    </div>
    <?php endif; ?>
</div>

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

.ab-crm-header h1 {
    font-size: 24px;
    margin: 0;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-crm-title-area {
    display: flex;
    align-items: center;
}

.ab-user-role-badge {
    margin-left: 12px;
}

.ab-badge-role {
    background-color: #6200ea;
    color: white;
    border-radius: 20px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 500;
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

/* İstatistik Kutucukları */
.ab-stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.ab-stats-card {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
    padding: 20px;
    display: flex;
    align-items: center;
    transition: transform 0.2s;
    border: 1px solid #eee;
}

.ab-stats-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.ab-stats-card-icon {
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

.ab-stats-card-body {
    flex: 1;
}

.ab-stats-card-body h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    font-weight: 500;
    color: #666;
}

.ab-stats-card-value {
    font-size: 24px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.ab-stats-card-info {
    font-size: 12px;
    color: #888;
}

/* Detaylı İstatistikler Bölümü */
.ab-stats-dashboard {
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

.ab-filter-row {
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

.ab-select {
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

.ab-select:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-filter-col input[type="text"],
.ab-filter-col input[type="file"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-filter-col input[type="text"]:focus,
.ab-filter-col input[type="file"]:focus {
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
tr.policy-vehicle td {
    background-color: #f0f8ff !important;
}

tr.policy-vehicle td:first-child {
    border-left: 3px solid #2271b1;
}

tr.policy-vehicle:hover td {
    background-color: #e6f3ff !important;
}

tr.policy-property td {
    background-color: #f0fff0 !important;
}

tr.policy-property td:first-child {
    border-left: 3px solid #4caf50;
}

tr.policy-property:hover td {
    background-color: #e6ffe6 !important;
}

tr.policy-health td {
    background-color: #fff0f5 !important;
}

tr.policy-health td:first-child {
    border-left: 3px solid #e91e63;
}

tr.policy-health:hover td {
    background-color: #ffe6f0 !important;
}

tr.expired td {
    background-color: #fff2f2 !important;
}

tr.expired td:first-child {
    border-left: 3px solid #e53935;
}

tr.expiring-soon td {
    background-color: #fffaeb !important;
}

tr.expiring-soon td:first-child {
    border-left: 3px solid #ffc107;
}

tr.cancelled td {
    background-color: #f9f0ff !important;
}

tr.cancelled td:first-child {
    border-left: 3px solid #9c27b0;
}

.ab-cancelled-date {
    color: #9c27b0;
    font-style: italic;
    font-size: 11px;
}

/* Bağlantı ve veri stilleri */
.ab-policy-number {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ab-policy-number:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link {
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-link:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-customer-link small {
    display: block;
    color: #666;
    font-size: 11px;
    margin-top: 2px;
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

.ab-badge-status-aktif {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-status-pasif {
    background-color: #f5f5f5;
    color: #666;
}

.ab-badge-danger {
    background-color: #ffeef0;
    color: #cb2431;
}

.ab-badge-warning {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-cancelled {
    background-color: #f3e5f5;
    color: #9c27b0;
}

.ab-no-document {
    color: #999;
    font-style: italic;
    font-size: 12px;
}

.ab-amount {
    font-weight: 600;
    color: #0366d6;
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

.ab-action-danger:hover {
    background-color: #ffe5e5;
    color: #d32f2f;
    border-color: #ffcccc;
}

.ab-action-permanent-delete {
    background-color: #fff0f0;
    border-color: #ffd0d0;
    color: #c00;
}

.ab-action-permanent-delete:hover {
    background-color: #ffe0e0;
    border-color: #ffb0b0;
    color: #a00;
}

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

/* Duyarlı Tasarım */
@media (max-width: 1200px) {
    .ab-crm-container {
        max-width: 98%;
        padding: 15px;
    }

    .ab-filter-row {
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
    
    .ab-stats-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .ab-crm-table th:nth-child(4),
    .ab-crm-table td:nth-child(4) {
        display: none;
    }

    .ab-filter-row {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 10px;
        margin: 0 5px;
    }
    
    .ab-stats-cards {
        grid-template-columns: 1fr;
    }
    
    .ab-filter-row {
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
    
    .ab-crm-table th:nth-child(8),
    .ab-crm-table td:nth-child(8) {
        display: none;
    }
    
    .ab-crm-table th:nth-child(9),
    .ab-crm-table td:nth-child(9) {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // İstatistikleri kontrol fonksiyonu
    let statsVisible = true;
    const toggleStatsBtn = document.getElementById('toggle-stats-view');
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
    
    // DateRangePicker Ayarları
    if (typeof $ !== 'undefined' && $.fn.daterangepicker) {
        $('#date_range').daterangepicker({
            autoUpdateInput: false,
            locale: {
                format: 'DD/MM/YYYY',
                applyLabel: 'Uygula',
                cancelLabel: 'Temizle',
                fromLabel: 'Başlangıç',
                toLabel: 'Bitiş',
                daysOfWeek: ['Pz', 'Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct'],
                monthNames: ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']
            }
        });

        $('#date_range').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
        });

        $('#date_range').on('cancel.daterangepicker', function() {
            $(this).val('');
        });
    }
    
    // Filtreleme toggle kontrolü
    const toggleFiltersBtn = document.getElementById('toggle-filters-btn');
    const filtersContainer = document.getElementById('policies-filters-container');
    
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
    
    // POLİÇE TÜRÜ PASTA GRAFİĞİ
    const policyTypeChart = document.getElementById('policyTypeChart');
    if (policyTypeChart) {
        const policyTypeData = <?php echo json_encode($policy_type_distribution); ?>;
        const labels = policyTypeData.map(item => item.policy_type);
        const data = policyTypeData.map(item => parseInt(item.count));
        
        new Chart(policyTypeChart, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#4caf50', '#2196f3', '#ff9800', '#9c27b0', '#f44336', 
                        '#009688', '#3f51b5', '#e91e63', '#ffc107', '#795548'
                    ],
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
                                return context.label + ': ' + value + ' poliçe (' + percentage + ')';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // SİGORTA ŞİRKETİ PASTA GRAFİĞİ
    const insuranceCompanyChart = document.getElementById('insuranceCompanyChart');
    if (insuranceCompanyChart) {
        const companyData = <?php echo json_encode($insurance_company_distribution); ?>;
        const labels = companyData.map(item => item.insurance_company);
        const data = companyData.map(item => parseInt(item.count));
        
        new Chart(insuranceCompanyChart, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#3f51b5', '#2196f3', '#03a9f4', '#00bcd4', '#009688', 
                        '#4caf50', '#8bc34a', '#cddc39', '#ffc107', '#ff9800'
                    ],
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
                                return context.label + ': ' + value + ' poliçe (' + percentage + ')';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // POLİÇE DURUM DAĞILIMI PASTA GRAFİĞİ
    const policyStatusChart = document.getElementById('policyStatusChart');
    if (policyStatusChart) {
        const statusData = [
            <?php echo $active_policies; ?>, 
            <?php echo $passive_policies; ?>, 
            <?php echo $cancelled_policies; ?>
        ];
        
        new Chart(policyStatusChart, {
            type: 'pie',
            data: {
                labels: ['Aktif', 'Pasif', 'İptal Edilmiş'],
                datasets: [{
                    data: statusData,
                    backgroundColor: [
                        'rgba(76, 175, 80, 0.7)',  // Yeşil
                        'rgba(158, 158, 158, 0.7)', // Gri
                        'rgba(244, 67, 54, 0.7)'   // Kırmızı
                    ],
                    borderColor: [
                        'rgba(76, 175, 80, 1)',
                        'rgba(158, 158, 158, 1)',
                        'rgba(244, 67, 54, 1)'
                    ],
                    borderWidth: 1
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
                                return context.label + ': ' + value + ' poliçe (' + percentage + ')';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // YAKINDA SONA ERECEK POLİÇELER ÇUBUK GRAFİĞİ
    const expiringPoliciesChart = document.getElementById('expiringPoliciesChart');
    if (expiringPoliciesChart) {
        const expiringData = <?php echo json_encode($expiring_policies); ?>;
        const expiringLabels = <?php echo json_encode($expiring_labels); ?>;
        
        new Chart(expiringPoliciesChart, {
            type: 'bar',
            data: {
                labels: expiringLabels,
                datasets: [{
                    label: 'Sona Erecek Poliçeler',
                    data: expiringData,
                    backgroundColor: 'rgba(255, 152, 0, 0.7)',
                    borderColor: 'rgba(255, 152, 0, 1)',
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
                            title: function(context) {
                                return context[0].label;
                            },
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw + ' poliçe';
                            }
                        }
                    }
                }
            }
        });
    }
    
    // AYLIK POLİÇE TRENDİ ÇİZGİ GRAFİĞİ
    const monthlyTrendChart = document.getElementById('monthlyTrendChart');
    if (monthlyTrendChart) {
        const trendData = <?php echo json_encode($monthly_trend_data); ?>;
        const trendLabels = <?php echo json_encode($monthly_trend_labels); ?>;
        
        new Chart(monthlyTrendChart, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Aylık Poliçe Sayısı',
                    data: trendData,
                    fill: true,
                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                    borderColor: 'rgba(33, 150, 243, 1)',
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(33, 150, 243, 1)',
                    pointBorderColor: '#fff',
                    pointRadius: 4
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
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Poliçe Sayısı: ' + context.raw;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // AYLIK PRİM TRENDİ ÇİZGİ GRAFİĞİ
    const monthlyPremiumChart = document.getElementById('monthlyPremiumChart');
    if (monthlyPremiumChart) {
        const premiumData = <?php echo json_encode($monthly_premium_data); ?>;
        const premiumLabels = <?php echo json_encode($monthly_trend_labels); ?>;
        
        new Chart(monthlyPremiumChart, {
            type: 'line',
            data: {
                labels: premiumLabels,
                datasets: [{
                    label: 'Aylık Toplam Prim (₺)',
                    data: premiumData,
                    fill: true,
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    borderColor: 'rgba(76, 175, 80, 1)',
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(76, 175, 80, 1)',
                    pointBorderColor: '#fff',
                    pointRadius: 4
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
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let value = context.raw;
                                return 'Toplam Prim: ₺' + value.toLocaleString('tr-TR');
                            }
                        }
                    }
                }
            }
        });
    }

    // Son kullanıcı etkileşim ve zaman bilgisini log
    console.log('Policies sayfası yüklendi - Kullanıcı: anadolubirlik - Tarih: 2025-05-26 19:21:27 UTC');
});
</script>

<?php
// İlgili formlara yönlendirme
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'view':
            if (isset($_GET['id'])) {
                include_once('policies-view.php');
            }
            break;
        case 'new':
        case 'edit':
        case 'renew':
        case 'cancel':
            include_once('policies-form.php');
            break;
    }
}
?>