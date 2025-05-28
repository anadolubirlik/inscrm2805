<?php
/**
 * Frontend Müşteri Yönetim Sayfası
 * @version 2.7.0
 */

// Yetki kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// Değişkenleri tanımla
global $wpdb;
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$teams_table = $wpdb->prefix . 'insurance_crm_teams';
$users_table = $wpdb->users;

// Mevcut kullanıcının temsilci ID'sini ve yetkilerini al
function get_current_user_rep_data() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT id, role, customer_edit, customer_delete, policy_edit, policy_delete 
         FROM {$wpdb->prefix}insurance_crm_representatives 
         WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ));
}

// Erişim seviyesini belirle
function get_user_access_level($user_role) {
    switch ($user_role) {
        case 'patron':
            return 'patron';
        case 'manager':
            return 'mudur';
        case 'assistant_manager':
            return 'mudur_yardimcisi';
        case 'team_leader':
            return 'ekip_lideri';
        case 'representative':
        default:
            return 'temsilci';
    }
}

$current_rep = get_current_user_rep_data();
$current_user_rep_id = $current_rep ? $current_rep->id : 0;
$current_user_id = get_current_user_id();

// NOT: Bu fonksiyon dashboard.php'de zaten tanımlanmış olduğundan burada tekrar tanımlamıyoruz
// Kullanıcı rolünü al
$user_role = get_user_role_in_hierarchy($current_user_id);

// Access level belirle
$access_level = get_user_access_level($user_role);

// Kullanıcının müşteri üzerinde düzenleme yetkisi var mı?
function can_edit_customer($rep_data, $customer = null) {
    if (!$rep_data) return false;
    
    // Patron her zaman düzenleyebilir
    if ($rep_data->role == 1) return true;
    
    // Müdür, yetki verilmişse düzenleyebilir
    if ($rep_data->role == 2 && $rep_data->customer_edit == 1) {
        return true;
    }
    
    // Müdür Yardımcısı, yetki verilmişse düzenleyebilir
    if ($rep_data->role == 3 && $rep_data->customer_edit == 1) {
        return true;
    }
    
    // Ekip lideri, yetki verilmişse düzenleyebilir
    if ($rep_data->role == 4 && $rep_data->customer_edit == 1) {
        // Ekip liderinin kendi ekibindeki müşterileri düzenleme yetkisi
        if ($customer) {
            // Basitleştirmek için sadece yetkiyi kontrol ediyoruz
            return true;
        }
    }
    
    // Müşteri temsilcisi sadece kendi müşterilerini düzenleyebilir
    if ($rep_data->role == 5 && $customer && $customer->representative_id == $rep_data->id) {
        return true;
    }
    
    return false;
}

// Kullanıcının müşteri üzerinde silme yetkisi var mı?
function can_delete_customer($rep_data, $customer = null) {
    if (!$rep_data) return false;
    
    // Patron her zaman silebilir
    if ($rep_data->role == 1) return true;
    
    // Müdür yetki verilmişse silebilir
    if ($rep_data->role == 2 && $rep_data->customer_delete == 1) {
        return true;
    }
    
    // Müdür Yardımcısı yetki verilmişse silebilir
    if ($rep_data->role == 3 && $rep_data->customer_delete == 1) {
        return true;
    }
    
    // Ekip lideri, yetki verilmişse kendi ekibindeki müşterileri silebilir
    if ($rep_data->role == 4 && $rep_data->customer_delete == 1 && $customer) {
        // Ekip liderinin ekip üyesi müşterilerini silme yetkisi
        return true;
    }
    
    return false;
}

// Ekip liderinin ekip ID'sini ve ekip üyelerini al
function get_team_for_leader($leader_rep_id) {
    if (!$leader_rep_id) return array('team_id' => null, 'members' => array());
    
    $settings = get_option('insurance_crm_settings', array());
    $teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();
    
    foreach ($teams as $team_id => $team) {
        if ($team['leader_id'] == $leader_rep_id) {
            $members = isset($team['members']) ? $team['members'] : array();
            // Kendisini de ekle
            if (!in_array($leader_rep_id, $members)) {
                $members[] = $leader_rep_id;
            }
            return array('team_id' => $team_id, 'members' => array_unique($members));
        }
    }
    
    return array('team_id' => null, 'members' => array($leader_rep_id)); // Sadece lider
}

// Ekip liderinin ekip bilgilerini al
$team_info = array('team_id' => null, 'members' => array());
if ($access_level == 'ekip_lideri') {
    $team_info = get_team_for_leader($current_user_rep_id);
}


// Görünüm tipini kontrol etmek için
$view_type = isset($_GET['view']) ? $_GET['view'] : 'customers';

// İstatistik hesaplamaları için filtreler
$stats_where = "";
$stats_join = "";

// Temsilcilere göre filtreleme (istatistiklerde kullanmak için)
if ($access_level == 'temsilci' && $current_user_rep_id) {
    // Müşteri Temsilcisi: Sadece kendi müşterilerini görebilir
    $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
}
elseif ($access_level == 'ekip_lideri') {
    if ($view_type == 'team_customers' && !empty($team_info['members'])) {
        // Ekip Lideri + Ekip Görünümü: Tüm ekibin istatistiklerini göster
        $members = $team_info['members'];
        if (!empty($members)) {
            $placeholders = implode(',', array_fill(0, count($members), '%d'));
            $stats_join .= " LEFT JOIN $representatives_table r ON c.representative_id = r.id";
            
            // Query parametrelerini oluştur
            $stats_query_args = array();
            foreach ($members as $member_id) {
                $stats_query_args[] = $member_id;
            }
            $stats_where .= $wpdb->prepare(" AND c.representative_id IN ($placeholders)", $stats_query_args);
        } else {
            $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Müşteri Görünümü: Sadece kendi istatistiklerini göster
        $stats_where .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
    }
}
// Patron, Müdür ve Müdür Yardımcısı tüm müşterileri görebilir (ek filtreleme yok)


// İşlem Bildirileri için session kontrolü
$notice = '';
if (isset($_SESSION['crm_notice'])) {
    $notice = $_SESSION['crm_notice'];
    unset($_SESSION['crm_notice']);
}

// Müşteri silme işlemi
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $customer_id = intval($_GET['id']);
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_customer_' . $customer_id)) {
        // Silme yetkisi kontrolü
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $customers_table WHERE id = %d", $customer_id
        ));
        
        if (!$customer) {
            $notice = '<div class="ab-notice ab-error">Müşteri bulunamadı.</div>';
        } else {
            $can_delete = can_delete_customer($current_rep, $customer);
            
            if ($can_delete) {
                // Silme işlemi (pasife çekme)
                $wpdb->update(
                    $customers_table,
                    array('status' => 'pasif'),
                    array('id' => $customer_id)
                );
                
                // Log kaydı tutma
                $user_id = get_current_user_id();
                $user_info = get_userdata($user_id);
                $log_message = sprintf(
                    'Müşteri ID: %d, Ad: %s %s, %s (ID: %d) tarafından pasife alındı.',
                    $customer_id,
                    $customer->first_name,
                    $customer->last_name,
                    $user_info->display_name,
                    $user_id
                );
                error_log($log_message);
                
                $notice = '<div class="ab-notice ab-success">Müşteri pasif duruma getirildi.</div>';
            } else {
                $notice = '<div class="ab-notice ab-error">Bu müşteriyi pasife alma yetkiniz yok.</div>';
            }
        }
    }
}

// Görünüm kontrolü - Takım poliçeleri mi, bireysel poliçeler mi?
$view_type = isset($_GET['view']) ? $_GET['view'] : 'customers';

// Filtreler ve Sayfalama
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 15;
$offset = ($current_page - 1) * $per_page;

// FİLTRELEME PARAMETRELERİ
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
$representative_filter = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;

// GELİŞMİŞ FİLTRELER
$gender_filter = isset($_GET['gender']) ? sanitize_text_field($_GET['gender']) : '';
$is_pregnant_filter = isset($_GET['is_pregnant']) ? '1' : '';
$has_children_filter = isset($_GET['has_children']) ? '1' : '';
$has_spouse_filter = isset($_GET['has_spouse']) ? '1' : '';
$has_vehicle_filter = isset($_GET['has_vehicle']) ? '1' : '';
$owns_home_filter = isset($_GET['owns_home']) ? '1' : '';
$has_pet_filter = isset($_GET['has_pet']) ? '1' : '';
$child_tc_filter = isset($_GET['child_tc']) ? sanitize_text_field($_GET['child_tc']) : '';
$spouse_tc_filter = isset($_GET['spouse_tc']) ? sanitize_text_field($_GET['spouse_tc']) : '';

// Sorgu oluştur
$base_query = "FROM $customers_table c 
               LEFT JOIN $representatives_table r ON c.representative_id = r.id
               LEFT JOIN $users_table u ON r.user_id = u.ID
               WHERE 1=1";

// Rol tabanlı erişim kontrolü
if ($access_level == 'temsilci' && $current_user_rep_id) {
    // Müşteri Temsilcisi: Sadece kendi müşterilerini görebilir
    $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
}
elseif ($access_level == 'ekip_lideri') {
    if ($view_type == 'team_customers' && !empty($team_info['members'])) {
        // Ekip Görünümü: Ekip Lideri ekibindeki tüm temsilcilerin müşterilerini görebilir
        $members = $team_info['members'];
        if (!empty($members)) {
            $placeholders = implode(',', array_fill(0, count($members), '%d'));
            
            // Query parametrelerini oluştur
            $query_args = array();
            foreach ($members as $member_id) {
                $query_args[] = $member_id;
            }
            $base_query .= $wpdb->prepare(" AND c.representative_id IN ($placeholders)", $query_args);
        } else {
            // Ekipte üye yoksa sadece kendi müşterilerini göster
            $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
        }
    } else {
        // Normal Görünüm: Ekip Lideri sadece kendi müşterilerini görür
        $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $current_user_rep_id);
    }
}

// Patron, Müdür ve Müdür Yardımcısı tüm müşterileri görebilir (ek filtreleme yok)

// Arama filtresi
if (!empty($search)) {
    $base_query .= $wpdb->prepare(
        " AND (
            c.first_name LIKE %s 
            OR c.last_name LIKE %s 
            OR CONCAT(c.first_name, ' ', c.last_name) LIKE %s
            OR c.tc_identity LIKE %s 
            OR c.email LIKE %s 
            OR c.phone LIKE %s
            OR c.spouse_name LIKE %s
            OR c.spouse_tc_identity LIKE %s
            OR c.children_names LIKE %s
            OR c.children_tc_identities LIKE %s
            OR c.company_name LIKE %s
            OR c.tax_number LIKE %s
        )",
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
    );
}

// Durum ve kategori filtreleri
if (!empty($status_filter)) {
    $base_query .= $wpdb->prepare(" AND c.status = %s", $status_filter);
}

if (!empty($category_filter)) {
    $base_query .= $wpdb->prepare(" AND c.category = %s", $category_filter);
}

if ($representative_filter > 0) {
    // Temsilci filtreleme yetkisi kontrol et
    $can_filter_by_rep = true;
    
    if ($access_level == 'temsilci') {
        // Temsilci sadece kendini filtreleyebilir
        if ($representative_filter != $current_user_rep_id) {
            $can_filter_by_rep = false;
            $notice .= '<div class="ab-notice ab-warning">Sadece kendi müşterilerinizi görebilirsiniz. Filtreleme göz ardı edildi.</div>';
        }
    } 
    else if ($access_level == 'ekip_lideri') {
        // Ekip lideri sadece kendi ekibindeki temsilcileri filtreleyebilir
        if (!in_array($representative_filter, $team_info['members'])) {
            $can_filter_by_rep = false;
            $notice .= '<div class="ab-notice ab-warning">Seçtiğiniz temsilci sizin ekibinize ait değil. Filtreleme göz ardı edildi.</div>';
        }
    }
    
    if ($can_filter_by_rep) {
        $base_query .= $wpdb->prepare(" AND c.representative_id = %d", $representative_filter);
    }
}

// GELİŞMİŞ FİLTRELER UYGULAMASI
if (!empty($gender_filter)) {
    $base_query .= $wpdb->prepare(" AND c.gender = %s", $gender_filter);
}

// Gebe müşteriler filtresi
if (!empty($is_pregnant_filter)) {
    $base_query .= " AND c.is_pregnant = 1 AND c.gender = 'female'";
}

// Çocuklu müşteriler filtresi
if (!empty($has_children_filter)) {
    $base_query .= " AND (c.children_count > 0 OR c.children_names IS NOT NULL)";
}

// Eşi olan müşteriler filtresi
if (!empty($has_spouse_filter)) {
    $base_query .= " AND c.spouse_name IS NOT NULL AND c.spouse_name != ''";
}

// Aracı olan müşteriler filtresi
if (!empty($has_vehicle_filter)) {
    $base_query .= " AND c.has_vehicle = 1";
}

// Ev sahibi olan müşteriler filtresi
if (!empty($owns_home_filter)) {
    $base_query .= " AND c.owns_home = 1";
}

// Evcil hayvan sahibi olan müşteriler filtresi
if (!empty($has_pet_filter)) {
    $base_query .= " AND c.has_pet = 1";
}

// Çocuk TC'si ile arama
if (!empty($child_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.children_tc_identities LIKE %s", '%' . $wpdb->esc_like($child_tc_filter) . '%');
}

// Eş TC'si ile arama
if (!empty($spouse_tc_filter)) {
    $base_query .= $wpdb->prepare(" AND c.spouse_tc_identity = %s", $spouse_tc_filter);
}

// -----------------------------------------------------
// İSTATİSTİK VERİLERİ İÇİN SORGULAR
// -----------------------------------------------------

// 1. Toplam müşteri sayısı
$total_customers_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE 1=1 $stats_where";
$total_customers = $wpdb->get_var($total_customers_query);

// 2. Bu ay eklenen müşteriler
$this_month_start = date('Y-m-01 00:00:00');
$new_customers_query = $wpdb->prepare(
    "SELECT COUNT(*) FROM $customers_table c $stats_join 
    WHERE c.created_at >= %s $stats_where",
    $this_month_start
);
$new_customers_this_month = $wpdb->get_var($new_customers_query);

// 3. Aktif/pasif/belirsiz müşteri dağılımı
$status_aktif_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.status = 'aktif' $stats_where";
$status_aktif = $wpdb->get_var($status_aktif_query);

$status_pasif_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.status = 'pasif' $stats_where";
$status_pasif = $wpdb->get_var($status_pasif_query);

$status_belirsiz_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.status = 'belirsiz' $stats_where";
$status_belirsiz = $wpdb->get_var($status_belirsiz_query);

// 4. Bireysel/kurumsal müşteri dağılımı
$category_bireysel_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.category = 'bireysel' $stats_where";
$category_bireysel = $wpdb->get_var($category_bireysel_query);

$category_kurumsal_query = "SELECT COUNT(*) FROM $customers_table c $stats_join WHERE c.category = 'kurumsal' $stats_where";
$category_kurumsal = $wpdb->get_var($category_kurumsal_query);

// 5. Son 6 aydaki müşteri artış trendi
$months = array();
$trend_data = array();

for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01 00:00:00', strtotime("-$i month"));
    $month_end = date('Y-m-t 23:59:59', strtotime("-$i month"));
    $month_label = date('M', strtotime("-$i month"));
    
    $trend_query = $wpdb->prepare(
        "SELECT COUNT(*) FROM $customers_table c $stats_join
        WHERE c.created_at BETWEEN %s AND %s $stats_where",
        $month_start, $month_end
    );
    
    $customer_count = $wpdb->get_var($trend_query);
    
    $months[] = $month_label;
    $trend_data[] = intval($customer_count);
}

// Toplam müşteri sayısını al (filtreli sayfa için)
$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT c.id) " . $base_query);

// Sıralama
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'c.created_at';
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), array('ASC', 'DESC')) ? strtoupper($_GET['order']) : 'DESC';

// Müşterileri getir
$customers = $wpdb->get_results("
    SELECT c.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, u.display_name as representative_name 
    " . $base_query . " 
    ORDER BY $orderby $order 
    LIMIT $per_page OFFSET $offset
");

// Temsilcileri al (erişim seviyesine göre filtrelenmiş)
$representatives = array();
if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi') {
    // Patron, Müdür ve Müdür Yardımcısı tüm temsilcileri görebilir
    $representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        JOIN $users_table u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
} elseif ($access_level == 'ekip_lideri' && !empty($team_info['members'])) {
    // Ekip lideri sadece kendi ekibindeki temsilcileri görebilir
    $members = $team_info['members'];
    if (!empty($members)) {
        $placeholders = implode(',', array_fill(0, count($members), '%d'));
        
        // Query parametrelerini oluştur
        $query_args = array();
        foreach ($members as $member_id) {
            $query_args[] = $member_id;
        }
        
        $representatives = $wpdb->get_results($wpdb->prepare("
            SELECT r.id, u.display_name 
            FROM $representatives_table r
            JOIN $users_table u ON r.user_id = u.ID
            WHERE r.status = 'active' AND r.id IN ($placeholders)
            ORDER BY u.display_name ASC
        ", $query_args));
    }
} elseif ($access_level == 'temsilci' && $current_user_rep_id) {
    // Temsilci sadece kendisini görebilir
    $representatives = $wpdb->get_results($wpdb->prepare("
        SELECT r.id, u.display_name 
        FROM $representatives_table r
        JOIN $users_table u ON r.user_id = u.ID
        WHERE r.status = 'active' AND r.id = %d
        ORDER BY u.display_name ASC
    ", $current_user_rep_id));
}

// Sayfalama
$total_pages = ceil($total_items / $per_page);

// Aktif action belirle
$current_action = isset($_GET['action']) ? $_GET['action'] : '';
$show_list = ($current_action !== 'view' && $current_action !== 'edit' && $current_action !== 'new');

// Filtreleme yapıldı mı kontrolü
$is_filtered = !empty($search) || 
               !empty($status_filter) || 
               !empty($category_filter) || 
               $representative_filter > 0 || 
               !empty($gender_filter) || 
               !empty($is_pregnant_filter) || 
               !empty($has_children_filter) || 
               !empty($has_spouse_filter) || 
               !empty($has_vehicle_filter) || 
               !empty($owns_home_filter) || 
               !empty($has_pet_filter) || 
               !empty($child_tc_filter) || 
               !empty($spouse_tc_filter);

// SQL sorguları için debug
$debug_mode = false; // Geliştirici modu - aktifleştirirseniz SQL sorgularını gösterir
?>

<!-- Font Awesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<!-- Chart.js kütüphanesi -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="ab-crm-container">
    <?php echo $notice; ?>
    
    <?php if ($debug_mode): ?>
    <div class="ab-debug-info">
        <h3>Debug Bilgileri</h3>
        <pre>
            Total Customers Query: <?php echo $total_customers_query; ?>
            Result: <?php echo $total_customers; ?>
            
            Status Aktif Query: <?php echo $status_aktif_query; ?>
            Result: <?php echo $status_aktif; ?>
            
            Category Bireysel Query: <?php echo $category_bireysel_query; ?>
            Result: <?php echo $category_bireysel; ?>
            
            Category Kurumsal Query: <?php echo $category_kurumsal_query; ?>
            Result: <?php echo $category_kurumsal; ?>
            
            Access Level: <?php echo $access_level; ?>
            User Role: <?php echo $user_role; ?>
            User Rep ID: <?php echo $current_user_rep_id; ?>
        </pre>
    </div>
    <?php endif; ?>
    
    <!-- Müşteri Listesi -->
    <div class="ab-customers-list <?php echo !$show_list ? 'ab-hidden' : ''; ?>">
        <!-- Header -->
        <div class="ab-crm-header">
            <h1>Müşteriler</h1>
            <div class="ab-header-actions">
                <button type="button" id="ab-toggle-advanced-filter" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-filter"></i> Filtreleme
                </button>

 <?php if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi' || $access_level == 'ekip_lideri'): ?>
    <a href="?view=team_customers" class="ab-btn <?php echo $view_type == 'team_customers' ? 'ab-btn-primary' : 'ab-btn-secondary'; ?>">
        <i class="fas fa-users"></i> Ekip Müşterileri
    </a>
    <a href="?view=customers" class="ab-btn <?php echo $view_type == 'customers' ? 'ab-btn-primary' : 'ab-btn-secondary'; ?>">
        <i class="fas fa-user"></i> Müşterilerim
    </a>
    <?php endif; ?>


                <?php if ($current_rep && ($current_rep->role == 1 || $current_rep->role == 2 || $current_rep->role == 3 || $current_rep->role == 4 || $current_rep->role == 5)): ?>
                <a href="?view=customers&action=new" class="ab-btn ab-btn-primary">
                    <i class="fas fa-plus"></i> Yeni Müşteri
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- İSTATİSTİK DASHBOARD PANELI -->
       <div class="ab-statistics-dashboard" id="ab-statistics-dashboard">
    <div class="ab-stats-header">
        <h2><i class="fas fa-chart-line"></i> 
            <?php echo ($view_type == 'team_customers' && $access_level == 'ekip_lideri') 
                ? 'Ekip Müşteri İstatistikleri' 
                : 'Müşteri İstatistikleri'; ?>
        </h2>
        <div class="ab-stats-header-meta">
                    <?php 
                    switch($access_level) {
                        case 'patron':
                            echo '<span class="ab-badge ab-badge-access"><i class="fas fa-crown"></i> Patron Erişimi</span>';
                            break;
                        case 'mudur':
                            echo '<span class="ab-badge ab-badge-access"><i class="fas fa-user-tie"></i> Müdür Erişimi</span>';
                            break;
                        case 'mudur_yardimcisi':
                            echo '<span class="ab-badge ab-badge-access"><i class="fas fa-user-tie"></i> Müdür Yardımcısı Erişimi</span>';
                            break;
                        case 'ekip_lideri':
                            echo '<span class="ab-badge ab-badge-access"><i class="fas fa-users"></i> Ekip Lideri Erişimi</span>';
                            break;
                        case 'temsilci':
                            echo '<span class="ab-badge ab-badge-access"><i class="fas fa-user"></i> Temsilci Erişimi</span>';
                            break;
                    }
                    ?>
                    <span class="ab-stats-date">Son güncelleme: <?php echo date('d.m.Y H:i'); ?></span>
                </div>
            </div>
            
            <div class="ab-stats-grid">
                <!-- Toplam Müşteri -->
                <div class="ab-stats-card">
                    <div class="ab-stats-card-content">
                        <div class="ab-stats-card-title">Toplam Müşteri</div>
                        <div class="ab-stats-card-value"><?php echo number_format($total_customers); ?></div>
                        
<div class="ab-stats-card-description">
    <?php 
    switch($access_level) {
        case 'patron':
        case 'mudur':
        case 'mudur_yardimcisi':
            echo 'Tüm müşteriler';
            break;
        case 'ekip_lideri':
            if ($view_type == 'team_customers') {
                echo 'Ekibinizdeki müşteriler';
            } else {
                echo 'Sizin müşterileriniz';
            }
            break;
        case 'temsilci':
            echo 'Sizin müşterileriniz';
            break;
    }
    ?>
</div>

                    </div>
                    <div class="ab-stats-card-icon ab-stats-icon-blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                
                <!-- Bu ay eklenen -->
                <div class="ab-stats-card">
                    <div class="ab-stats-card-content">
                        <div class="ab-stats-card-title">Bu Ay Eklenen</div>
                        <div class="ab-stats-card-value"><?php echo number_format($new_customers_this_month); ?></div>
                        <div class="ab-stats-card-description">
                            <?php echo date('F Y'); ?> ayında
                        </div>
                    </div>
                    <div class="ab-stats-card-icon ab-stats-icon-green">
                        <i class="fas fa-user-plus"></i>
                    </div>
                </div>
                
                <!-- Aktif Müşteriler -->
                <div class="ab-stats-card">
                    <div class="ab-stats-card-content">
                        <div class="ab-stats-card-title">Aktif Müşteriler</div>
                        <div class="ab-stats-card-value">
                            <?php 
                            echo number_format($status_aktif);
                            $aktif_oran = $total_customers > 0 ? round($status_aktif / $total_customers * 100) : 0;
                            echo ' <span class="ab-stats-card-percent">(' . $aktif_oran . '%)</span>';
                            ?>
                        </div>
                        <div class="ab-stats-card-description">
                            Toplam müşterilerin oranı
                        </div>
                    </div>
                    <div class="ab-stats-card-icon ab-stats-icon-teal">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                
                <!-- Bireysel / Kurumsal Dağılımı -->
                <div class="ab-stats-card">
                    <div class="ab-stats-card-content">
                        <div class="ab-stats-card-title">Bireysel / Kurumsal</div>
                        <div class="ab-stats-card-value">
                            <?php echo number_format($category_bireysel) . ' / ' . number_format($category_kurumsal); ?>
                        </div>
                        <div class="ab-stats-card-description">
                            <?php 
                            $bireysel_oran = $total_customers > 0 ? round($category_bireysel / $total_customers * 100) : 0;
                            echo 'Bireysel: %' . $bireysel_oran . ' - Kurumsal: %' . (100 - $bireysel_oran);
                            ?>
                        </div>
                    </div>
                    <div class="ab-stats-card-icon ab-stats-icon-purple">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
            </div>
            
            <div class="ab-stats-charts">
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Müşteri Türü Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Müşteri Durumu Dağılımı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                
                <div class="ab-stats-chart-container">
                    <div class="ab-stats-chart-header">
                        <h3>Son 6 Ay Müşteri Artışı</h3>
                    </div>
                    <div class="ab-stats-chart-body">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gelişmiş Filtreler - JavaScript ile toggle edilir -->
        <div id="ab-filters-panel" class="ab-crm-filters" style="display: none;">
            <form method="get" action="" id="customers-filter" class="ab-filter-form">
                <input type="hidden" name="view" value="customers">
                
                <div class="ab-filter-top-row">
                    <div class="ab-filter-col">
                        <label for="ab_filter_status">Durum</label>
                        <select name="status" id="ab_filter_status" class="ab-select">
                            <option value="">Tüm Durumlar</option>
                            <option value="aktif" <?php selected($status_filter, 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($status_filter, 'pasif'); ?>>Pasif</option>
                            <option value="belirsiz" <?php selected($status_filter, 'belirsiz'); ?>>Belirsiz</option>
                        </select>
                    </div>
                    
                    <div class="ab-filter-col">
                        <label for="ab_filter_category">Kategori</label>
                        <select name="category" id="ab_filter_category" class="ab-select">
                            <option value="">Tüm Kategoriler</option>
                            <option value="bireysel" <?php selected($category_filter, 'bireysel'); ?>>Bireysel</option>
                            <option value="kurumsal" <?php selected($category_filter, 'kurumsal'); ?>>Kurumsal</option>
                        </select>
                    </div>
                    
                    <?php if (!empty($representatives)): ?>
                    <div class="ab-filter-col">
                        <label for="ab_filter_rep_id">Temsilci</label>
                        <select name="rep_id" id="ab_filter_rep_id" class="ab-select">
                            <option value="">Tüm Temsilciler</option>
                            <?php foreach ($representatives as $rep): ?>
                            <option value="<?php echo $rep->id; ?>" <?php selected($representative_filter, $rep->id); ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="ab-filter-col">
                        <label for="ab_filter_search">Arama</label>
                        <div class="ab-search-box">
                            <input type="text" name="s" id="ab_filter_search" value="<?php echo esc_attr($search); ?>" placeholder="Müşteri Ara...">
                            <button type="submit" class="ab-btn-search">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="ab-advanced-filters">
                    <div class="ab-filter-section">
                        <h4>Kişisel Bilgiler</h4>
                        <div class="ab-filter-grid">
                            <div class="ab-filter-col">
                                <label for="ab_filter_gender">Cinsiyet</label>
                                <select name="gender" id="ab_filter_gender" class="ab-select">
                                    <option value="">Seçiniz</option>
                                    <option value="male" <?php selected($gender_filter, 'male'); ?>>Erkek</option>
                                    <option value="female" <?php selected($gender_filter, 'female'); ?>>Kadın</option>
                                </select>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="is_pregnant" id="ab_filter_is_pregnant" <?php checked($is_pregnant_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Sadece gebe müşteriler</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ab-filter-section">
                        <h4>Aile Bilgileri</h4>
                        <div class="ab-filter-grid">
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_spouse" id="ab_filter_has_spouse" <?php checked($has_spouse_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Eşi olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_children" id="ab_filter_has_children" <?php checked($has_children_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Çocuğu olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label for="ab_filter_spouse_tc">Eş TC Kimlik No</label>
                                <input type="text" name="spouse_tc" id="ab_filter_spouse_tc" class="ab-input" value="<?php echo esc_attr($spouse_tc_filter); ?>" placeholder="Eş TC Kimlik ile ara...">
                            </div>
                            
                            <div class="ab-filter-col">
                                <label for="ab_filter_child_tc">Çocuk TC Kimlik No</label>
                                <input type="text" name="child_tc" id="ab_filter_child_tc" class="ab-input" value="<?php echo esc_attr($child_tc_filter); ?>" placeholder="Çocuk TC Kimlik ile ara...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="ab-filter-section">
                        <h4>Varlık Bilgileri</h4>
                        <div class="ab-filter-grid">
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_vehicle" id="ab_filter_has_vehicle" <?php checked($has_vehicle_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Aracı olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="owns_home" id="ab_filter_owns_home" <?php checked($owns_home_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Ev sahibi olanlar</span>
                                </label>
                            </div>
                            
                            <div class="ab-filter-col">
                                <label class="ab-filter-checkbox-container">
                                    <input type="checkbox" name="has_pet" id="ab_filter_has_pet" <?php checked($has_pet_filter, '1'); ?>>
                                    <span class="ab-filter-checkbox-text">Evcil hayvanı olanlar</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="ab-filter-actions">
                    <button type="submit" class="ab-btn ab-btn-primary">
                        <i class="fas fa-filter"></i> Filtrele
                    </button>
                    <a href="?view=customers" class="ab-btn ab-btn-secondary">
                        <i class="fas fa-times"></i> Sıfırla
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Filtreleme durumunu gösteren bilgi kutusu -->
        <?php if ($is_filtered): ?>
        <div class="ab-filter-info">
            <div class="ab-filter-info-content">
                <i class="fas fa-info-circle"></i> 
                <span>Filtreleme aktif. Toplam <?php echo $total_items; ?> müşteri bulundu.</span>
            </div>
            <a href="?view=customers" class="ab-filter-clear-btn">
                <i class="fas fa-times"></i> Filtreleri Temizle
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Tablo -->
        <?php if (!empty($customers)): ?>
        <div class="ab-crm-table-wrapper">
            <div class="ab-crm-table-info">
                <span>Toplam: <?php echo $total_items; ?> müşteri</span>
            </div>
            
            <table class="ab-crm-table">
                <thead>
                    <tr>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.first_name', 'order' => $order === 'ASC' && $orderby === 'c.first_name' ? 'DESC' : 'ASC')); ?>">
                                Ad Soyad <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th>TC Kimlik</th>
                        <th>İletişim</th>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.category', 'order' => $order === 'ASC' && $orderby === 'c.category' ? 'DESC' : 'ASC')); ?>">
                                Kategori <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.status', 'order' => $order === 'ASC' && $orderby === 'c.status' ? 'DESC' : 'ASC')); ?>">
                                Durum <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <?php if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi' || $access_level == 'ekip_lideri'): ?>
                        <th>Temsilci</th>
                        <?php endif; ?>
                        <th>
                            <a href="<?php echo add_query_arg(array('orderby' => 'c.created_at', 'order' => $order === 'ASC' && $orderby === 'c.created_at' ? 'DESC' : 'ASC')); ?>">
                                Kayıt Tarihi <i class="fas fa-sort"></i>
                            </a>
                        </th>
                        <th class="ab-actions-column">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <?php 
                        $row_class = '';
                        switch ($customer->status) {
                            case 'aktif': $row_class = 'status-active'; break;
                            case 'pasif': $row_class = 'status-inactive'; break;
                            case 'belirsiz': $row_class = 'status-uncertain'; break;
                        }
                        // Kurumsal müşteriler için ek class ekleyelim
                        if ($customer->category === 'kurumsal') {
                            $row_class .= ' customer-corporate';
                        }
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td>
                                <a href="?view=customers&action=view&id=<?php echo $customer->id; ?>" class="ab-customer-name">
                                    <?php echo esc_html($customer->customer_name); ?>
                                </a>
                                <?php if (!empty($customer->company_name)): ?>
                                <div class="ab-company-name"><?php echo esc_html($customer->company_name); ?></div>
                                <?php endif; ?>
                                <?php if ($customer->is_pregnant == 1): ?>
                                <span class="ab-badge ab-badge-pregnancy"><i class="fas fa-baby"></i> Gebe</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($customer->tc_identity); ?></td>
                            <td>
                                <div>
                                    <?php if (!empty($customer->email)): ?>
                                    <div class="ab-contact-info"><i class="fas fa-envelope"></i> <?php echo esc_html($customer->email); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($customer->phone)): ?>
                                    <div class="ab-contact-info"><i class="fas fa-phone"></i> <?php echo esc_html($customer->phone); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="ab-badge ab-badge-category-<?php echo $customer->category; ?>">
                                    <?php echo $customer->category === 'bireysel' ? 'Bireysel' : 'Kurumsal'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="ab-badge ab-badge-status-<?php echo $customer->status; ?>">
                                    <?php 
                                    switch ($customer->status) {
                                        case 'aktif': echo 'Aktif'; break;
                                        case 'pasif': echo 'Pasif'; break;
                                        case 'belirsiz': echo 'Belirsiz'; break;
                                        default: echo ucfirst($customer->status);
                                    }
                                    ?>
                                </span>
                            </td>
                            <?php if ($access_level == 'patron' || $access_level == 'mudur' || $access_level == 'mudur_yardimcisi' || $access_level == 'ekip_lideri'): ?>
                            <td>
                                <?php echo !empty($customer->representative_name) ? esc_html($customer->representative_name) : '—'; ?>
                            </td>
                            <?php endif; ?>
                            <td class="ab-date-cell"><?php echo date('d.m.Y', strtotime($customer->created_at)); ?></td>
                            <td class="ab-actions-cell">
                                <div class="ab-actions">
                                    <a href="?view=customers&action=view&id=<?php echo $customer->id; ?>" title="Görüntüle" class="ab-action-btn">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if (can_edit_customer($current_rep, $customer)): ?>
                                    <a href="?view=customers&action=edit&id=<?php echo $customer->id; ?>" title="Düzenle" class="ab-action-btn">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (can_delete_customer($current_rep, $customer) && $customer->status !== 'pasif'): ?>
                                    <a href="<?php echo wp_nonce_url('?view=customers&action=delete&id=' . $customer->id, 'delete_customer_' . $customer->id); ?>" 
                                       onclick="return confirm('Bu müşteriyi pasif duruma getirmek istediğinizden emin misiniz?');" 
                                       title="Pasif Yap" class="ab-action-btn ab-action-danger">
                                        <i class="fas fa-ban"></i>
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
                // Sayfalama bağlantıları
                $pagination_args = array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
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
            <i class="fas fa-users"></i>
            <h3>Müşteri bulunamadı</h3>
            <p>Arama kriterlerinize uygun müşteri bulunamadı.</p>
            <a href="?view=customers" class="ab-btn">Tüm Müşterileri Göster</a>
        </div>
        <?php endif; ?>
    </div>
    
    <?php
    // Eğer action=view, action=new veya action=edit ise ilgili dosyayı dahil et
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'view':
                if (isset($_GET['id'])) {
                    include_once('customers-view.php');
                }
                break;
            case 'new':
            case 'edit':
                include_once('customers-form.php');
                break;
        }
    }
    ?>
</div>

<style>
/* Temel Stiller - Daha kompakt ve şık tasarım */
.ab-crm-container {
    max-width: 96%;
    width: 100%;
    margin: 0 auto;
    padding: 20px;
    font-family: inherit;
    color: #333;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e5e5;
    box-sizing: border-box;
}

.ab-crm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.ab-crm-header h1 {
    font-size: 22px;
    margin: 0;
    font-weight: 600;
    color: #333;
}

.ab-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Debug info */
.ab-debug-info {
    background-color: #f5f5f5;
    border: 1px solid #ddd;
    padding: 10px;
    margin-bottom: 15px;
    font-family: monospace;
    font-size: 12px;
    overflow-x: auto;
}

.ab-debug-info pre {
    white-space: pre-wrap;
}

/* Bildirimler */
.ab-notice {
    padding: 10px 15px;
    margin-bottom: 15px;
    border-left: 4px solid;
    border-radius: 3px;
    font-size: 14px;
}

.ab-success {
    background-color: #f0fff4;
    border-left-color: #38a169;
}

.ab-error {
    background-color: #fff5f5;
    border-left-color: #e53e3e;
}

.ab-warning {
    background-color: #fffbea;
    border-left-color: #d97706;
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

.ab-btn-secondary {
    background-color: #f8f9fa;
    border-color: #ddd;
}

.ab-btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.ab-btn-search {
    background: none;
    border: none;
    padding: 0 10px;
    color: #666;
    cursor: pointer;
}

/* İstatistik Dashboard Paneli */
.ab-statistics-dashboard {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
}

.ab-stats-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e9ecef;
}

.ab-stats-header h2 {
    font-size: 16px;
    margin: 0;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-stats-header-meta {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ab-stats-date {
    font-size: 12px;
    color: #666;
}

.ab-badge-access {
    background-color: #f3f4f6;
    color: #4b5563;
}

/* İstatistik Kartları Grid */
.ab-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.ab-stats-card {
    background-color: #fff;
    border-radius: 6px;
    padding: 15px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px solid #eee;
}

.ab-stats-card-content {
    flex: 1;
}

.ab-stats-card-title {
    font-size: 13px;
    color: #666;
    font-weight: 500;
    margin-bottom: 5px;
}

.ab-stats-card-value {
    font-size: 22px;
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
}

.ab-stats-card-percent {
    font-size: 14px;
    color: #666;
    font-weight: normal;
}

.ab-stats-card-description {
    font-size: 12px;
    color: #666;
}

.ab-stats-card-icon {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 15px;
    color: white;
    font-size: 18px;
}

.ab-stats-icon-blue {
    background-color: #3b82f6;
}

.ab-stats-icon-green {
    background-color: #10b981;
}

.ab-stats-icon-teal {
    background-color: #14b8a6;
}

.ab-stats-icon-purple {
    background-color: #8b5cf6;
}

.ab-stats-icon-orange {
    background-color: #f59e0b;
}

/* Chart Bölümü - Güncellendi */
.ab-stats-charts {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 15px;
}

.ab-stats-chart-container {
    background-color: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #eee;
    overflow: hidden;
}

.ab-stats-chart-header {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
}

.ab-stats-chart-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.ab-stats-chart-body {
    padding: 15px;
    height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

@media (max-width: 1200px) {
    .ab-stats-charts {
        grid-template-columns: 1fr 1fr;
    }
    
    .ab-stats-chart-container:last-child {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    .ab-stats-charts {
        grid-template-columns: 1fr;
    }
    
    .ab-stats-chart-container:last-child {
        grid-column: span 1;
    }
    
    .ab-stats-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .ab-stats-header-meta {
        width: 100%;
        justify-content: space-between;
    }
}

/* Filtreler */
.ab-crm-filters {
    background-color: #f9f9f9;
    border: 1px solid #eee;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ab-filter-top-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.ab-filter-col {
    flex: 1;
    min-width: 120px;
}

.ab-filter-col label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 12px;
    color: #555;
}

.ab-search-box {
    display: flex;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    background-color: #fff;
}

.ab-search-box input {
    flex: 1;
    padding: 8px 10px;
    border: none;
    outline: none;
    width: 100%;
    font-size: 13px;
}

.ab-select {
    width: 100%;
    padding: 7px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    font-size: 13px;
    height: 32px;
}

.ab-input {
    width: 100%;
    padding: 7px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    font-size: 13px;
    height: 32px;
}

/* Gelişmiş filtreler için stil */
.ab-advanced-filters {
    margin-top: 10px;
    padding-top: 15px;
    border-top: 1px dashed #ddd;
}

.ab-filter-section {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.ab-filter-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.ab-filter-section h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.ab-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

/* ÖZEL OLARAK ÇAKIŞMAYI ÖNLEMEK İÇİN CHECKBOX STIL */
.ab-filter-checkbox-container {
    display: flex;
    align-items: center;
    margin-top: 10px;
    cursor: pointer;
}

.ab-filter-checkbox-container input[type="checkbox"] {
    margin-right: 8px;
}

.ab-filter-checkbox-text {
    font-size: 13px;
    user-select: none;
}

/* Filtre action butonları */
.ab-filter-actions {
    margin-top: 15px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Filtreleme durumu bilgisi */
.ab-filter-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 15px;
    background-color: #e6f7ff;
    border: 1px solid #91d5ff;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 13px;
    color: #1890ff;
}

.ab-filter-info-content {
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-filter-clear-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #1890ff;
    text-decoration: none;
    font-weight: 500;
    font-size: 12px;
    transition: color 0.2s;
}

.ab-filter-clear-btn:hover {
    text-decoration: underline;
    color: #096dd9;
}

/* Tablo */
.ab-crm-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
    background-color: #fff;
    border-radius: 4px;
    border: 1px solid #eee;
}

.ab-crm-table-info {
    padding: 8px 12px;
    font-size: 13px;
    color: #666;
    border-bottom: 1px solid #eee;
    background-color: #f8f9fa;
}

.ab-crm-table {
    width: 100%;
    border-collapse: collapse;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 10px 12px;
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

.ab-customer-name {
    font-weight: 500;
    color: #2271b1;
    text-decoration: none;
}

.ab-customer-name:hover {
    text-decoration: underline;
    color: #135e96;
}

.ab-company-name {
    font-size: 11px;
    color: #666;
    margin-top: 2px;
}

/* Kurumsal müşteri satırı için stil */
tr.customer-corporate td {
    background-color: #f8f4ff !important; /* Açık mor arka plan */
}

tr.customer-corporate td:first-child {
    border-left: 3px solid #8e44ad; /* Sadece ilk hücrede sol kenarda mor çizgi */
}

tr.customer-corporate:hover td {
    background-color: #f0e8ff !important; /* Hover durumunda daha koyu mor */
}

/* İşlemler kolonu */
.ab-actions-column {
    text-align: center;
    width: 100px;
    min-width: 100px;
}

.ab-actions-cell {
    text-align: center;
}

/* İletişim bilgileri */
.ab-contact-info {
    font-size: 12px;
    margin-bottom: 3px;
    color: #666;
}

.ab-contact-info:last-child {
    margin-bottom: 0;
}

.ab-contact-info i {
    width: 14px;
    text-align: center;
    margin-right: 5px;
    color: #888;
}

/* Tarih hücresi */
.ab-date-cell {
    font-size: 12px;
    white-space: nowrap;
}

/* Durum ve Kategori Renklendimeleri */
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

.ab-badge-status-belirsiz {
    background-color: #fff8c5;
    color: #b08800;
}

.ab-badge-category-bireysel {
    background-color: #e1effe;
    color: #1e429f;
}

.ab-badge-category-kurumsal {
    background-color: #e6f6eb;
    color: #166534;
}

.ab-badge-pregnancy {
    background-color: #fce7f3;
    color: #be185d;
    margin-top: 4px;
    font-size: 10px;
    padding: 2px 6px;
}

/* İşlem Butonları */
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

/* İkon stilleri - görünürlük düzeltmesi */
.ab-action-btn i {
    font-size: 14px;
    display: inline-block;
}

/* Boş Durum Gösterimi */
.ab-empty-state {
    text-align: center;
    padding: 30px 20px;
    color: #666;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 4px;
}

.ab-empty-state i {
    font-size: 36px;
    color: #999;
    margin-bottom: 10px;
}

.ab-empty-state h3 {
    margin: 10px 0;
    font-size: 16px;
}

.ab-empty-state p {
    margin-bottom: 20px;
    font-size: 14px;
}

/* Sayfalama */
.ab-pagination {
    padding: 10px;
    display: flex;
    justify-content: center;
    align-items: center;
    border-top: 1px solid #eee;
}

.ab-pagination .page-numbers {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 5px;
    margin: 0 3px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
    color: #333;
    text-decoration: none;
    font-size: 13px;
}

.ab-pagination .page-numbers.current {
    background-color: #4caf50;
    color: white;
    border-color: #43a047;
}

.ab-pagination .page-numbers:hover:not(.current) {
    background-color: #f5f5f5;
}

/* Gizleme için stil */
.ab-hidden {
    display: none;
}

/* Geri dön butonu - customer-view.php ve customer-form.php için */
.ab-back-button {
    margin-bottom: 15px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
    padding: 6px 12px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #444;
    text-decoration: none;
    transition: all 0.2s;
}

.ab-back-button:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
}

/* Mobil Uyumluluk - Geliştirilmiş */
@media (max-width: 1200px) {
    .ab-crm-container {
        max-width: 98%;
        padding: 15px;
    }
    
    .ab-filter-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }
}

@media (max-width: 992px) {
    .ab-crm-container {
        max-width: 100%;
        margin-left: 10px;
        margin-right: 10px;
    }
    
    .ab-crm-table th:nth-child(2),
    .ab-crm-table td:nth-child(2) {
        display: none; /* TC Kimlik kolonunu gizle */
    }
    
    .ab-filter-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
}

@media (max-width: 768px) {
    .ab-crm-container {
        padding: 10px;
    }
    
    .ab-filter-col {
        width: 100%;
    }
    
    .ab-filter-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-crm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .ab-header-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .ab-header-actions .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-crm-table th,
    .ab-crm-table td {
        padding: 8px 6px;
    }
    
    /* Bazı kolonları küçük ekranlarda gizle */
    .ab-crm-table th:nth-child(3),
    .ab-crm-table td:nth-child(3) {
        display: none; /* İletişim kolonunu gizle */
    }
    
    .ab-crm-table th:nth-child(4),
    .ab-crm-table td:nth-child(4) {
        display: none; /* Kategori kolonunu gizle */
    }
    
    .ab-actions {
        flex-direction: column;
        gap: 4px;
    }
    
    .ab-action-btn {
        width: 26px;
        height: 26px;
    }
    
    .ab-filter-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .ab-filter-actions {
        flex-direction: column;
    }

    .ab-filter-actions .ab-btn {
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
    
    .ab-crm-table th:nth-child(6),
    .ab-crm-table td:nth-child(6) {
        display: none; /* Kayıt tarihi kolonunu gizle */
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Konsola bilgi yazdır (debug)
    console.log("Müşteri sayfası yüklendi - " + new Date());
    
    // Gelişmiş filtreleme göster/gizle işlemleri
    $('#ab-toggle-advanced-filter').click(function() {
        $('#ab-statistics-dashboard').slideToggle(300);
        $('#ab-filters-panel').slideToggle(300);
    });

    // Filtreleme aktifse filtreleme panelini otomatik aç ve istatistik panelini gizle
    <?php if ($is_filtered): ?>
    $('#ab-filters-panel').show();
    $('#ab-statistics-dashboard').hide();
    <?php endif; ?>

    // Form gönderildiğinde sadece dolu alanların gönderilmesi
    $('#customers-filter').submit(function() {
        // Gelişmiş filtrelerde, boş veya seçilmemiş alanları kaldır
        $(this).find(':input').each(function() {
            // Checkbox için kontrol
            if (this.type === 'checkbox' && !this.checked) {
                $(this).prop('disabled', true);
            }
            // Select ve text inputlar için kontrol
            else if ((this.type === 'select-one' || this.type === 'text') && !$(this).val()) {
                $(this).prop('disabled', true);
            }
        });
        return true;
    });
    
    // Gebe checkbox kontrolü - Sadece kadın seçildiğinde aktif olsun
    $('#ab_filter_gender').change(function() {
        if ($(this).val() === 'female') {
            $('#ab_filter_is_pregnant').prop('disabled', false);
            $('#ab_filter_is_pregnant').parent().removeClass('disabled');
        } else {
            $('#ab_filter_is_pregnant').prop('checked', false);
            $('#ab_filter_is_pregnant').prop('disabled', true);
            $('#ab_filter_is_pregnant').parent().addClass('disabled');
        }
    });
    
    // Sayfa yüklendiğinde cinsiyet seçimine göre gebe checkbox durumu kontrolü
    if ($('#ab_filter_gender').val() !== 'female') {
        $('#ab_filter_is_pregnant').prop('disabled', true);
        $('#ab_filter_is_pregnant').parent().addClass('disabled');
    }
    
    // Sayfalama ve sıralama linklerinde tüm filtrelerin kalması için
    $('.ab-pagination .page-numbers, .ab-crm-table th a').each(function() {
        var href = $(this).attr('href');
        if (href) {
            // Mevcut URL'deki tüm parametreleri al
            var currentUrlParams = new URLSearchParams(window.location.search);
            var targetUrlParams = new URLSearchParams(href);
            
            // Hedef URL'de paged veya order parametresi varsa koru
            var hasPagedParam = targetUrlParams.has('paged');
            var hasOrderParams = targetUrlParams.has('orderby') || targetUrlParams.has('order');
            
            // Mevcut URL'deki tüm parametreleri yeni URL'ye ekle (paged ve order hariç)
            currentUrlParams.forEach(function(value, key) {
                // paged veya order/orderby parametrelerini hedef URL'den içeriyorsa ekle
                if ((key !== 'paged' || !hasPagedParam) && 
                    ((key !== 'orderby' && key !== 'order') || !hasOrderParams)) {
                    targetUrlParams.set(key, value);
                }
            });
            
            $(this).attr('href', '?' + targetUrlParams.toString());
        }
    });
    
    // Grafikler için canvas kontrolleri
    function checkCanvasExists(id) {
        var canvas = document.getElementById(id);
        console.log("Canvas kontrolü: " + id + " = " + (canvas ? "var" : "yok"));
        return !!canvas;
    }
    
    // Kategori grafiği için veri kontrolü
    var categoryData = [<?php echo intval($category_bireysel); ?>, <?php echo intval($category_kurumsal); ?>];
    console.log("Kategori verileri:", categoryData);
    
    // Durum grafiği için veri kontrolü
    var statusData = [<?php echo intval($status_aktif); ?>, <?php echo intval($status_pasif); ?>, <?php echo intval($status_belirsiz); ?>];
    console.log("Durum verileri:", statusData);
    
    // Trend grafiği için veri kontrolü
    var trendLabels = <?php echo json_encode($months); ?>;
    var trendData = <?php echo json_encode($trend_data); ?>;
    console.log("Trend verileri:", trendData);
    
    // Chart.js ile grafikler oluşturma - hata yakalama ile
    try {
        // Kategori Grafiği - chart.js
        if (checkCanvasExists('categoryChart')) {
            var categoryCtx = document.getElementById('categoryChart').getContext('2d');
            new Chart(categoryCtx, {
                type: 'pie',
                data: {
                    labels: ['Bireysel', 'Kurumsal'],
                    datasets: [{
                        data: categoryData,
                        backgroundColor: [
                            'rgba(30, 66, 159, 0.7)',
                            'rgba(22, 101, 52, 0.7)'
                        ],
                        borderColor: [
                            'rgba(30, 66, 159, 1)',
                            'rgba(22, 101, 52, 1)'
                        ],
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
                                font: { size: 12 }
                            }
                        }
                    }
                }
            });
        }

        // Durum Grafiği - chart.js
        if (checkCanvasExists('statusChart')) {
            var statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'pie',
                data: {
                    labels: ['Aktif', 'Pasif', 'Belirsiz'],
                    datasets: [{
                        data: statusData,
                        backgroundColor: [
                            'rgba(34, 134, 58, 0.7)',
                            'rgba(102, 102, 102, 0.7)',
                            'rgba(176, 136, 0, 0.7)'
                        ],
                        borderColor: [
                            'rgba(34, 134, 58, 1)',
                            'rgba(102, 102, 102, 1)',
                            'rgba(176, 136, 0, 1)'
                        ],
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
                                font: { size: 12 }
                            }
                        }
                    }
                }
            });
        }

        // Son 6 Ay Trend Grafiği - chart.js
        if (checkCanvasExists('trendChart')) {
            var trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Yeni Müşteriler',
                        data: trendData,
                        fill: true,
                        backgroundColor: 'rgba(76, 175, 80, 0.1)',
                        borderColor: 'rgba(76, 175, 80, 1)',
                        tension: 0.3,
                        pointBackgroundColor: 'rgba(76, 175, 80, 1)',
                        pointRadius: 4,
                        pointHoverRadius: 6
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
                                title: function(tooltipItem) {
                                    return tooltipItem[0].label + ' ' + new Date().getFullYear();
                                },
                                label: function(context) {
                                    return context.raw + ' yeni müşteri';
                                }
                            }
                        }
                    }
                }
            });
        }
    } catch (e) {
        console.error("Grafik oluşturma hatası:", e);
        // Hata olursa kullanıcıya bilgi ver
        $('.ab-stats-chart-body').each(function() {
            if ($(this).children().length === 0) {
                $(this).html('<div class="ab-chart-error"><i class="fas fa-exclamation-triangle"></i> Grafik yüklenemedi</div>');
            }
        });
    }
});
</script>