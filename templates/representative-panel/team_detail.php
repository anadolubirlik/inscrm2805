<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ekip ID'sini al
$team_id = isset($_GET['team_id']) ? sanitize_text_field($_GET['team_id']) : '';

if (empty($team_id)) {
    echo '<div class="notice notice-error"><p>Ekip ID bulunamadı.</p></div>';
    return;
}

global $wpdb;
$current_user = wp_get_current_user();

// Tarih filtrelerini al
$filter_start_date = isset($_GET['filter_start_date']) ? sanitize_text_field($_GET['filter_start_date']) : date('Y-m-01'); // Bu ayın başlangıcı
$filter_end_date = isset($_GET['filter_end_date']) ? sanitize_text_field($_GET['filter_end_date']) : date('Y-m-t'); // Bu ayın sonu
$filter_period = isset($_GET['filter_period']) ? sanitize_text_field($_GET['filter_period']) : 'this_month';

// Tarih filtrelerine göre başlangıç ve bitiş tarihlerini ayarla
if ($filter_period == 'this_month') {
    $filter_start_date = date('Y-m-01');
    $filter_end_date = date('Y-m-t');
    $period_title = "Bu Ay (".date('F Y').")";
} elseif ($filter_period == 'last_month') {
    $filter_start_date = date('Y-m-01', strtotime('first day of last month'));
    $filter_end_date = date('Y-m-t', strtotime('last day of last month'));
    $period_title = "Geçen Ay (".date('F Y', strtotime('last month')).")";
} elseif ($filter_period == 'last_3_months') {
    $filter_start_date = date('Y-m-01', strtotime('-2 months'));
    $filter_end_date = date('Y-m-t');
    $period_title = "Son 3 Ay";
} elseif ($filter_period == 'last_6_months') {
    $filter_start_date = date('Y-m-01', strtotime('-5 months'));
    $filter_end_date = date('Y-m-t');
    $period_title = "Son 6 Ay";
} elseif ($filter_period == 'this_year') {
    $filter_start_date = date('Y-01-01');
    $filter_end_date = date('Y-12-31');
    $period_title = "Bu Yıl (".date('Y').")";
} elseif ($filter_period == 'custom') {
    $period_title = "Özel Tarih: " . date('d.m.Y', strtotime($filter_start_date)) . " - " . date('d.m.Y', strtotime($filter_end_date));
}

// Ekip bilgilerini al
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

if (!isset($teams[$team_id])) {
    echo '<div class="notice notice-error"><p>Ekip bulunamadı.</p></div>';
    return;
}

$team = $teams[$team_id];
$team_leader_id = $team['leader_id'];
$team_members_ids = $team['members'];

// Tüm ekip üyeleri (lider dahil)
$all_team_members = array_merge([$team_leader_id], $team_members_ids);

// Ekip liderini al
$team_leader = $wpdb->get_row($wpdb->prepare(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$wpdb->prefix}insurance_crm_representatives r
     JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.id = %d",
    $team_leader_id
));

if (!$team_leader) {
    echo '<div class="notice notice-error"><p>Ekip lideri bulunamadı.</p></div>';
    return;
}

// Ekip üyelerini al
$team_members = array();
if (!empty($team_members_ids)) {
    $placeholders = implode(',', array_fill(0, count($team_members_ids), '%d'));
    $team_members = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, u.display_name, u.user_email 
         FROM {$wpdb->prefix}insurance_crm_representatives r
         JOIN {$wpdb->users} u ON r.user_id = u.ID
         WHERE r.id IN ($placeholders)",
        ...$team_members_ids
    ));
}

// Ekip hedefi ve performansı
$team_total_target = 0;
$team_total_policy_target = 0;

// Ekibin toplam hedeflerini hesapla
foreach ($all_team_members as $member_id) {
    $member_data = $wpdb->get_row($wpdb->prepare(
        "SELECT monthly_target, target_policy_count FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
        $member_id
    ));
    
    if ($member_data) {
        $team_total_target += floatval($member_data->monthly_target);
        $team_total_policy_target += intval($member_data->target_policy_count);
    }
}

// Seçilen dönem için üretilen poliçe ve primler
$team_total_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ")
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));

$team_filtered_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ") 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));

$team_filtered_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ") 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
)) ?: 0;

$team_cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ") 
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
)) ?: 0;

$team_cancelled_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ") 
     AND cancellation_date BETWEEN %s AND %s",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
)) ?: 0;

$team_net_premium = $team_filtered_premium - $team_cancelled_premium;

// Seçilen dönem için müşteri sayısı
$team_filtered_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ")
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));

// Seçilen dönem için orantılı hedef hesaplaması
$days_in_period = (strtotime($filter_end_date) - strtotime($filter_start_date)) / (60 * 60 * 24) + 1;

if ($filter_period == 'this_month' || $filter_period == 'last_month') {
    // Tam ay için hedef kullan
    $period_target = $team_total_target;
    $period_policy_target = $team_total_policy_target;
} else {
    // Dönem uzunluğuna göre hedefi orantıla
    $months_in_period = $days_in_period / 30;
    $period_target = $team_total_target * $months_in_period;
    $period_policy_target = ceil($team_total_policy_target * $months_in_period);
}

// Gerçekleşme oranları
$premium_achievement = $period_target > 0 ? ($team_net_premium / $period_target) * 100 : 0;
$policy_achievement = $period_policy_target > 0 ? ($team_filtered_policies / $period_policy_target) * 100 : 0;

// Ekip üyelerinin performans verilerini hesapla
$team_members_performance = array();

// Önce ekip liderini ekle
$leader_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    $team_leader_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$leader_cancelled = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND cancellation_date BETWEEN %s AND %s",
    $team_leader_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$leader_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    $team_leader_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$leader_cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND cancellation_date BETWEEN %s AND %s",
    $team_leader_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$leader_net_premium = $leader_premium - $leader_cancelled;

$leader_achievement = $team_leader->monthly_target > 0 ? ($leader_net_premium / $team_leader->monthly_target) * 100 : 0;
$leader_policy_achievement = $team_leader->target_policy_count > 0 ? ($leader_policies / $team_leader->target_policy_count) * 100 : 0;

$team_members_performance[] = array(
    'id' => $team_leader->id,
    'name' => $team_leader->display_name,
    'role' => 'Ekip Lideri',
    'avatar_url' => $team_leader->avatar_url,
    'monthly_target' => $team_leader->monthly_target,
    'target_policy_count' => $team_leader->target_policy_count,
    'premium' => $leader_premium,
    'cancelled' => $leader_cancelled,
    'net_premium' => $leader_net_premium,
    'policies' => $leader_policies,
    'cancelled_policies' => $leader_cancelled_policies,
    'premium_achievement' => $leader_achievement,
    'policy_achievement' => $leader_policy_achievement,
    'is_leader' => true
);

// Şimdi ekip üyelerini ekle
foreach ($team_members as $member) {
    $member_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        $member->id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
    )) ?: 0;
    
    $member_cancelled = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND cancellation_date BETWEEN %s AND %s",
        $member->id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
    )) ?: 0;
    
    $member_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        $member->id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
    )) ?: 0;
    
    $member_cancelled_policies = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND cancellation_date BETWEEN %s AND %s",
        $member->id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
    )) ?: 0;
    
    $member_net_premium = $member_premium - $member_cancelled;
    
    $member_achievement = $member->monthly_target > 0 ? ($member_net_premium / $member->monthly_target) * 100 : 0;
    $member_policy_achievement = $member->target_policy_count > 0 ? ($member_policies / $member->target_policy_count) * 100 : 0;
    
    $team_members_performance[] = array(
        'id' => $member->id,
        'name' => $member->display_name,
        'role' => 'Üye',
        'avatar_url' => $member->avatar_url,
        'monthly_target' => $member->monthly_target,
        'target_policy_count' => $member->target_policy_count,
        'premium' => $member_premium,
        'cancelled' => $member_cancelled,
        'net_premium' => $member_net_premium,
        'policies' => $member_policies,
        'cancelled_policies' => $member_cancelled_policies,
        'premium_achievement' => $member_achievement,
        'policy_achievement' => $member_policy_achievement,
        'is_leader' => false
    );
}

// Üye performanslarını sırala - en iyi performans gösterenden en düşüğe
usort($team_members_performance, function($a, $b) {
    if ($a['is_leader'] && !$b['is_leader']) return -1;
    if (!$a['is_leader'] && $b['is_leader']) return 1;
    return $b['net_premium'] <=> $a['net_premium'];
});

// En iyi performans gösteren üyeyi bul (lider hariç)
$best_performer = null;
foreach ($team_members_performance as $member) {
    if (!$member['is_leader'] && $member['net_premium'] > 0) {
        $best_performer = $member;
        break;
    }
}

// Tüm ekip üyelerini net prime göre sırala (lider dahil)
$all_members_ranked = $team_members_performance;
usort($all_members_ranked, function($a, $b) {
    return $b['net_premium'] <=> $a['net_premium'];
});

// Son eklenen poliçeler
$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name, r.id as rep_id, u.display_name as rep_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON p.representative_id = r.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE p.representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ")
     AND p.cancellation_date IS NULL
     ORDER BY p.created_at DESC
     LIMIT 5",
    ...$all_team_members
));

// Poliçe türlerine göre dağılım
$policy_types = $wpdb->get_results($wpdb->prepare(
    "SELECT policy_type, COUNT(*) as count, COALESCE(SUM(premium_amount), 0) as total_premium
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ")
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL
     GROUP BY policy_type",
    ...array_merge($all_team_members, [$filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'])
));

// Aylık performans trendleri
$monthly_data = array();
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end = date('Y-m-t', strtotime("-$i months"));
    
    $month_premium = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(premium_amount), 0) - COALESCE(SUM(refunded_amount), 0) 
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ")
         AND start_date BETWEEN %s AND %s",
        ...array_merge($all_team_members, [$month_start . ' 00:00:00', $month_end . ' 23:59:59'])
    )) ?: 0;
    
    $month_policy_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) 
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id IN (" . implode(',', array_fill(0, count($all_team_members), '%d')) . ")
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        ...array_merge($all_team_members, [$month_start . ' 00:00:00', $month_end . ' 23:59:59'])
    )) ?: 0;
    
    $monthly_data[] = array(
        'month' => $month,
        'month_name' => date_i18n('F Y', strtotime($month)),
        'premium' => $month_premium,
        'policy_count' => $month_policy_count
    );
}

?>

<div class="team-detail-container">
    <div class="team-header">
        <div class="team-header-left">
            <div class="team-icon">
                <i class="dashicons dashicons-groups"></i>
            </div>
            <div class="team-info">
                <h1><?php echo esc_html($team['name']); ?> Ekibi</h1>
                <div class="team-meta">
                    <span class="team-leader">Ekip Lideri: <strong><?php echo esc_html($team_leader->display_name); ?></strong> (<?php echo esc_html($team_leader->title); ?>)</span>
                    <span class="team-members">Ekip Üyesi: <strong><?php echo count($team_members); ?></strong> kişi</span>
                    <span class="team-total-target">Toplam Hedef: <strong>₺<?php echo number_format($team_total_target, 2, ',', '.'); ?></strong></span>
                </div>
            </div>
        </div>
        <div class="team-header-right">
            <a href="?view=edit_team&team_id=team_682c7733e49d6" class="modern-btn modern-btn-primary">
                <i class="dashicons dashicons-edit"></i>
                <span>Ekibi Düzenle</span>
            </a>
            <a href="<?php echo generate_panel_url('all_teams'); ?>" class="modern-btn modern-btn-secondary">
                <i class="dashicons dashicons-list-view"></i>
                <span>Tüm Ekipler</span>
            </a>
            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="modern-btn modern-btn-outline">
                <i class="dashicons dashicons-dashboard"></i>
                <span>Dashboard</span>
            </a>
        </div>
    </div>

    <div class="team-period-filter">
        <form action="" method="get" class="filter-form">
            <input type="hidden" name="view" value="team_detail">
            <input type="hidden" name="team_id" value="<?php echo $team_id; ?>">
            
            <div class="filter-section">
                <label for="filter_period">Tarih Aralığı:</label>
                <select name="filter_period" id="filter_period" onchange="toggleCustomDateFields(this.value)">
                    <option value="this_month" <?php selected($filter_period, 'this_month'); ?>>Bu Ay</option>
                    <option value="last_month" <?php selected($filter_period, 'last_month'); ?>>Geçen Ay</option>
                    <option value="last_3_months" <?php selected($filter_period, 'last_3_months'); ?>>Son 3 Ay</option>
                    <option value="last_6_months" <?php selected($filter_period, 'last_6_months'); ?>>Son 6 Ay</option>
                    <option value="this_year" <?php selected($filter_period, 'this_year'); ?>>Bu Yıl</option>
                    <option value="custom" <?php selected($filter_period, 'custom'); ?>>Özel Tarih Aralığı</option>
                </select>
            </div>
            
            <div class="custom-date-fields" style="display: <?php echo $filter_period == 'custom' ? 'flex' : 'none'; ?>;">
                <div class="filter-section">
                    <label for="filter_start_date">Başlangıç:</label>
                    <input type="date" name="filter_start_date" id="filter_start_date" value="<?php echo esc_attr($filter_start_date); ?>">
                </div>
                <div class="filter-section">
                    <label for="filter_end_date">Bitiş:</label>
                    <input type="date" name="filter_end_date" id="filter_end_date" value="<?php echo esc_attr($filter_end_date); ?>">
                </div>
            </div>
            
            <div class="filter-section">
                <button type="submit" class="button button-primary">Uygula</button>
            </div>
        </form>
        <div class="current-period">
            <strong>Seçili Dönem:</strong> <?php echo $period_title; ?>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-box">
            <div class="stat-icon">
                <i class="dashicons dashicons-groups"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo number_format($team_filtered_customers); ?></div>
                <div class="stat-label">Dönem Müşteri</div>
            </div>
        </div>
        
        <div class="stat-box">
            <div class="stat-icon">
                <i class="dashicons dashicons-portfolio"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo number_format($team_filtered_policies); ?></div>
                <div class="stat-label">Dönem Poliçe</div>
            </div>
        </div>
        
        <div class="stat-box">
            <div class="stat-icon">
                <i class="dashicons dashicons-chart-area"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value">₺<?php echo number_format($team_filtered_premium, 2, ',', '.'); ?></div>
                <div class="stat-label">Dönem Üretim</div>
            </div>
        </div>
        
        <div class="stat-box">
            <div class="stat-icon negative-icon">
                <i class="dashicons dashicons-dismiss"></i>
            </div>
            <div class="stat-details">
                <div class="stat-value negative-value">₺<?php echo number_format($team_cancelled_premium, 2, ',', '.'); ?></div>
                <div class="stat-label">Dönem İptaller</div>
                <div class="stat-sublabel"><?php echo $team_cancelled_policies; ?> poliçe iptal edildi</div>
            </div>
        </div>
    </div>

    <?php if ($best_performer): ?>
    <div class="best-performer-section">
        <div class="best-performer-card">
            <div class="trophy-icon">
                <i class="dashicons dashicons-awards"></i>
            </div>
            <div class="best-performer-content">
                <h3>🏆 Bu Ayın En İyisi</h3>
                <div class="performer-info">
                    <div class="performer-avatar">
                        <?php if (!empty($best_performer['avatar_url'])): ?>
                            <img src="<?php echo esc_url($best_performer['avatar_url']); ?>" alt="<?php echo esc_attr($best_performer['name']); ?>">
                        <?php else: ?>
                            <?php echo substr($best_performer['name'], 0, 1); ?>
                        <?php endif; ?>
                    </div>
                    <div class="performer-details">
                        <div class="performer-name"><?php echo esc_html($best_performer['name']); ?></div>
                        <div class="performer-title">Müşteri Temsilcisi</div>
                        <div class="performer-achievement">₺<?php echo number_format($best_performer['net_premium'], 2, ',', '.'); ?> net prim üretimi</div>
                    </div>
                </div>
            </div>
            <div class="celebration-animation">
                <div class="confetti"></div>
                <div class="confetti"></div>
                <div class="confetti"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="team-detail-grid">
        <div class="team-detail-card performance-summary">
            <div class="card-header">
                <h3>Ekip Hedef Gerçekleşme</h3>
            </div>
            <div class="card-body">
                <div class="target-performance">
                    <div class="target-item">
                        <div class="target-label">Prim Hedefi</div>
                        <div class="target-data">
                            <div class="target-numbers">
                                <div class="target-actual">₺<?php echo number_format($team_net_premium, 2, ',', '.'); ?></div>
                                <div class="target-expected">/ ₺<?php echo number_format($period_target, 2, ',', '.'); ?></div>
                            </div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo min(100, $premium_achievement); ?>%"></div>
                            </div>
                            <div class="target-percentage"><?php echo number_format($premium_achievement, 2); ?>%</div>
                        </div>
                    </div>
                    <div class="target-item">
                        <div class="target-label">Poliçe Hedefi</div>
                        <div class="target-data">
                            <div class="target-numbers">
                                <div class="target-actual"><?php echo number_format($team_filtered_policies); ?> adet</div>
                                <div class="target-expected">/ <?php echo number_format($period_policy_target); ?> adet</div>
                            </div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo min(100, $policy_achievement); ?>%"></div>
                            </div>
                            <div class="target-percentage"><?php echo number_format($policy_achievement, 2); ?>%</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="team-detail-card policy-types">
            <div class="card-header">
                <h3>Poliçe Tür Dağılımı</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="policyTypeChart"></canvas>
                </div>
                <div class="chart-legend">
                    <?php if (!empty($policy_types)): ?>
                        <?php foreach($policy_types as $type): ?>
                        <div class="legend-item">
                            <div class="legend-color" data-type="<?php echo esc_attr($type->policy_type); ?>"></div>
                            <div class="legend-text">
                                <div class="legend-label"><?php echo esc_html(ucfirst($type->policy_type)); ?></div>
                                <div class="legend-value"><?php echo esc_html($type->count); ?> poliçe (₺<?php echo number_format($type->total_premium, 2, ',', '.'); ?>)</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>Seçilen dönemde poliçe verisi bulunamadı.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="team-detail-card monthly-trend">
            <div class="card-header">
                <h3>Aylık Performans Trendi</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="team-detail-card member-performance">
            <div class="card-header">
                <h3>Ekip Üyeleri Performansı</h3>
            </div>
            <div class="card-body">
                <div class="member-performance-table">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Üye</th>
                                    <th class="hide-mobile">Rol</th>
                                    <th class="hide-mobile">Hedef (₺)</th>
                                    <th>Üretim (₺)</th>
                                    <th class="hide-mobile">İptaller (₺)</th>
                                    <th>Net (₺)</th>
                                    <th class="hide-mobile">Gerçekleşme</th>
                                    <th>Poliçe</th>
                                    <th class="hide-mobile">İptal</th>
                                    <th class="mobile-actions">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($team_members_performance as $member): ?>
                                <tr class="<?php echo $member['is_leader'] ? 'leader-row' : ''; ?>">
                                    <td>
                                        <div class="member-info">
                                            <div class="member-avatar">
                                                <?php if (!empty($member['avatar_url'])): ?>
                                                    <img src="<?php echo esc_url($member['avatar_url']); ?>" alt="<?php echo esc_attr($member['name']); ?>">
                                                <?php else: ?>
                                                    <?php echo substr($member['name'], 0, 1); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="member-details">
                                                <div class="member-name"><?php echo esc_html($member['name']); ?></div>
                                                <div class="member-role-mobile show-mobile">
                                                    <span class="role-badge <?php echo $member['is_leader'] ? 'leader-badge' : 'member-badge'; ?>"><?php echo esc_html($member['role']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hide-mobile"><span class="role-badge <?php echo $member['is_leader'] ? 'leader-badge' : 'member-badge'; ?>"><?php echo esc_html($member['role']); ?></span></td>
                                    <td class="hide-mobile">₺<?php echo number_format($member['monthly_target'], 2, ',', '.'); ?></td>
                                    <td class="positive-value">₺<?php echo number_format($member['premium'], 2, ',', '.'); ?></td>
                                    <td class="negative-value hide-mobile">₺<?php echo number_format($member['cancelled'], 2, ',', '.'); ?></td>
                                    <td class="<?php echo $member['net_premium'] >= 0 ? 'positive-value' : 'negative-value'; ?>">
                                        ₺<?php echo number_format($member['net_premium'], 2, ',', '.'); ?>
                                    </td>
                                    <td class="hide-mobile">
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $member['premium_achievement']); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($member['premium_achievement'], 2); ?>%</div>
                                    </td>
                                    <td><?php echo $member['policies']; ?> adet</td>
                                    <td class="hide-mobile"><?php echo $member['cancelled_policies']; ?> adet</td>
                                    <td class="mobile-actions">
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="action-btn" title="Detay Görüntüle">
                                            <i class="dashicons dashicons-visibility"></i>
                                            <span class="btn-text">Gör</span>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2" class="hide-mobile">Toplam</th>
                                    <th class="show-mobile">Toplam</th>
                                    <th class="hide-mobile">₺<?php echo number_format($team_total_target, 2, ',', '.'); ?></th>
                                    <th>₺<?php echo number_format($team_filtered_premium, 2, ',', '.'); ?></th>
                                    <th class="hide-mobile">₺<?php echo number_format($team_cancelled_premium, 2, ',', '.'); ?></th>
                                    <th>₺<?php echo number_format($team_net_premium, 2, ',', '.'); ?></th>
                                    <th class="hide-mobile">
                                        <div class="progress-mini">
                                            <div class="progress-bar" style="width: <?php echo min(100, $premium_achievement); ?>%"></div>
                                        </div>
                                        <div class="progress-text"><?php echo number_format($premium_achievement, 2); ?>%</div>
                                    </th>
                                    <th><?php echo $team_filtered_policies; ?> adet</th>
                                    <th class="hide-mobile"><?php echo $team_cancelled_policies; ?> adet</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="team-detail-card team-ranking">
            <div class="card-header">
                <h3>Ekip Üyeleri Sıralaması</h3>
                <div class="ranking-subtitle">Net prim üretimine göre sıralama</div>
            </div>
            <div class="card-body">
                <div class="ranking-list">
                    <?php foreach($all_members_ranked as $index => $member): ?>
                    <div class="ranking-item <?php echo $member['is_leader'] ? 'leader-item' : ''; ?>">
                        <div class="ranking-position">
                            <?php if ($index == 0): ?>
                                <div class="medal gold">🥇</div>
                            <?php elseif ($index == 1): ?>
                                <div class="medal silver">🥈</div>
                            <?php elseif ($index == 2): ?>
                                <div class="medal bronze">🥉</div>
                            <?php else: ?>
                                <div class="position-number"><?php echo $index + 1; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="ranking-member">
                            <div class="member-avatar">
                                <?php if (!empty($member['avatar_url'])): ?>
                                    <img src="<?php echo esc_url($member['avatar_url']); ?>" alt="<?php echo esc_attr($member['name']); ?>">
                                <?php else: ?>
                                    <?php echo substr($member['name'], 0, 1); ?>
                                <?php endif; ?>
                            </div>
                            <div class="member-info-rank">
                                <div class="member-name"><?php echo esc_html($member['name']); ?></div>
                                <div class="member-role">
                                    <span class="role-badge <?php echo $member['is_leader'] ? 'leader-badge' : 'member-badge'; ?>">
                                        <?php echo esc_html($member['role']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="ranking-performance">
                            <div class="performance-value">₺<?php echo number_format($member['net_premium'], 2, ',', '.'); ?></div>
                            <div class="performance-details">
                                <?php echo $member['policies']; ?> poliçe | %<?php echo number_format($member['premium_achievement'], 1); ?> hedef
                            </div>
                        </div>
                        <div class="ranking-actions">
                            <a href="<?php echo generate_panel_url('representative_detail', '', $member['id']); ?>" class="rank-action-btn">
                                <i class="dashicons dashicons-visibility"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="team-detail-card recent-policies">
            <div class="card-header">
                <h3>Son Eklenen Poliçeler</h3>
                <a href="<?php echo generate_panel_url('policies', '', '', array('team_id' => $team_id)); ?>" class="card-action">Tümünü Gör</a>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_policies)): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Poliçe No</th>
                                <th class="hide-mobile">Müşteri</th>
                                <th class="hide-mobile">Temsilci</th>
                                <th>Tür</th>
                                <th class="hide-mobile">Başlangıç</th>
                                <th>Tutar</th>
                                <th class="mobile-actions">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_policies as $policy): ?>
                            <tr>
                                <td><?php echo esc_html($policy->policy_number); ?></td>
                                <td class="hide-mobile"><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></td>
                                <td class="hide-mobile"><?php echo esc_html($policy->rep_name); ?></td>
                                <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                                <td class="hide-mobile"><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                                <td>₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                                <td class="mobile-actions">
                                    <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="action-btn" title="Poliçe Detayı">
                                        <i class="dashicons dashicons-visibility"></i>
                                        <span class="btn-text">Gör</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>Poliçe verisi bulunamadı.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tarih filtre alanları görünürlük kontrolü
    window.toggleCustomDateFields = function(value) {
        const customDateFields = document.querySelector('.custom-date-fields');
        if (value === 'custom') {
            customDateFields.style.display = 'flex';
        } else {
            customDateFields.style.display = 'none';
        }
    };

    // Poliçe Tür Dağılımı Grafiği
    const policyTypeData = <?php echo json_encode(array_map(function($type) {
        return [
            'type' => ucfirst($type->policy_type),
            'count' => $type->count,
            'premium' => $type->total_premium
        ];
    }, $policy_types)); ?>;

    if (policyTypeData.length > 0) {
        const policyTypeCtx = document.getElementById('policyTypeChart').getContext('2d');
        
        const policyTypeNames = policyTypeData.map(item => item.type);
        const policyTypeCounts = policyTypeData.map(item => item.count);
        const policyColors = generateColors(policyTypeData.length);
        
        // Efsane renkleri güncelle
        policyTypeData.forEach((item, index) => {
            const legendColor = document.querySelector(`.legend-color[data-type="${item.type.toLowerCase()}"]`);
            if (legendColor) {
                legendColor.style.backgroundColor = policyColors[index];
            }
        });
        
        new Chart(policyTypeCtx, {
            type: 'doughnut',
            data: {
                labels: policyTypeNames,
                datasets: [{
                    data: policyTypeCounts,
                    backgroundColor: policyColors,
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const item = policyTypeData[context.dataIndex];
                                return `${item.type}: ${item.count} poliçe (₺${item.premium.toLocaleString('tr-TR', {minimumFractionDigits: 2})})`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Aylık Trend Grafiği
    const monthlyData = <?php echo json_encode($monthly_data); ?>;
    
    if (monthlyData.length > 0) {
        const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        
        const months = monthlyData.map(item => item.month_name);
        const premiums = monthlyData.map(item => item.premium);
        const policyCounts = monthlyData.map(item => item.policy_count);
        
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Prim Üretimi (₺)',
                        data: premiums,
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Poliçe Adedi',
                        data: policyCounts,
                        backgroundColor: 'rgba(255, 159, 64, 0.5)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1,
                        yAxisID: 'y1',
                        type: 'line',
                        fill: false,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Prim Üretimi (₺)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₺' + value.toLocaleString('tr-TR');
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        },
                        title: {
                            display: true,
                            text: 'Poliçe Adedi'
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const datasetLabel = context.dataset.label;
                                const value = context.parsed.y;
                                if (datasetLabel === 'Prim Üretimi (₺)') {
                                    return `${datasetLabel}: ₺${value.toLocaleString('tr-TR', {minimumFractionDigits: 2})}`;
                                } else {
                                    return `${datasetLabel}: ${value} adet`;
                                }
                            }
                        }
                    }
                }
            }
        });
    }

    // Renk üretme fonksiyonu
    function generateColors(count) {
        const baseColors = [
            '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
            '#5a5c69', '#6610f2', '#20c9a6', '#fd7e14', '#6f42c1'
        ];
        
        // Eğer daha fazla renge ihtiyaç varsa, renkleri tekrarla
        const colors = [];
        for (let i = 0; i < count; i++) {
            colors.push(baseColors[i % baseColors.length]);
        }
        
        return colors;
    }
});
</script>

<style>
.team-detail-container {
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.team-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.team-header-left {
    display: flex;
    align-items: center;
}

.team-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 20px;
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.team-icon .dashicons {
    font-size: 40px;
}

.team-info h1 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #333;
}

.team-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    color: #666;
    font-size: 14px;
}

.team-meta span {
    display: flex;
    align-items: center;
}

.team-meta span strong {
    color: #333;
    margin-left: 5px;
}

.team-header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* Modern Button Styles */
.modern-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
}

.modern-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.modern-btn:hover::before {
    left: 100%;
}

.modern-btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.modern-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102,126,234,0.4);
    color: white;
}

.modern-btn-secondary {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
}

.modern-btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(240,147,251,0.4);
    color: white;
}

.modern-btn-outline {
    background: white;
    color: #667eea;
    border: 2px solid #667eea;
}

.modern-btn-outline:hover {
    background: #667eea;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102,126,234,0.3);
}

.modern-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.modern-btn span {
    font-weight: 500;
}

/* Best Performer Section */
.best-performer-section {
    margin-bottom: 20px;
}

.best-performer-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    padding: 25px;
    color: white;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(102,126,234,0.3);
}

.best-performer-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    pointer-events: none;
}

.trophy-icon {
    position: absolute;
    top: 20px;
    right: 20px;
    font-size: 30px;
    color: #ffd700;
    animation: bounce 2s infinite;
}

.best-performer-content {
    position: relative;
    z-index: 2;
}

.best-performer-content h3 {
    margin: 0 0 20px 0;
    font-size: 20px;
    font-weight: 700;
}

.performer-info {
    display: flex;
    align-items: center;
    gap: 20px;
}

.performer-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    border: 3px solid rgba(255,255,255,0.3);
}

.performer-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.performer-details {
    flex-grow: 1;
}

.performer-name {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 5px;
}

.performer-title {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 8px;
}

.performer-achievement {
    font-size: 16px;
    font-weight: 600;
    background: rgba(255,255,255,0.2);
    padding: 5px 12px;
    border-radius: 20px;
    display: inline-block;
}

.celebration-animation {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
}

.confetti {
    position: absolute;
    width: 10px;
    height: 10px;
    background: #ffd700;
    animation: confetti-fall 3s infinite linear;
}

.confetti:nth-child(1) {
    left: 10%;
    animation-delay: 0s;
    background: #ff6b6b;
}

.confetti:nth-child(2) {
    left: 50%;
    animation-delay: 1s;
    background: #4ecdc4;
}

.confetti:nth-child(3) {
    left: 90%;
    animation-delay: 2s;
    background: #45b7d1;
}

@keyframes bounce {
    0%, 20%, 50%, 80%, 100% {
        transform: translateY(0);
    }
    40% {
        transform: translateY(-10px);
    }
    60% {
        transform: translateY(-5px);
    }
}

@keyframes confetti-fall {
    0% {
        transform: translateY(-100px) rotate(0deg);
        opacity: 1;
    }
    100% {
        transform: translateY(400px) rotate(720deg);
        opacity: 0;
    }
}

.team-period-filter {
    padding: 15px 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

.filter-form {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.filter-section {
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-section label {
    font-weight: 500;
    color: #333;
}

.filter-section select,
.filter-section input {
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-width: 150px;
}

.custom-date-fields {
    display: flex;
    gap: 15px;
}

.current-period {
    font-size: 14px;
    color: #666;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.stat-box {
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #4e73df, #224abe);
    color: white;
}

.negative-icon {
    background: linear-gradient(135deg, #e74a3b, #c52f1e);
}

.stat-icon .dashicons {
    font-size: 24px;
}

.stat-details {
    flex-grow: 1;
}

.stat-value {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 5px;
    color: #333;
}

.negative-value {
    color: #e74a3b;
}

.positive-value {
    color: #4caf50;
}

.stat-label {
    font-size: 14px;
    color: #666;
}

.stat-sublabel {
    font-size: 12px;
    color: #999;
    margin-top: 5px;
}

.team-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.team-detail-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    overflow: hidden;
}

.performance-summary {
    grid-column: 1;
    grid-row: 1;
}

.policy-types {
    grid-column: 2;
    grid-row: 1;
}

.monthly-trend {
    grid-column: 1 / span 2;
    grid-row: 2;
}

.member-performance {
    grid-column: 1 / span 2;
    grid-row: 3;
}

.team-ranking {
    grid-column: 1 / span 2;
    grid-row: 4;
}

.recent-policies {
    grid-column: 1 / span 2;
    grid-row: 5;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f0;
}

.card-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.ranking-subtitle {
    font-size: 12px;
    color: #666;
    font-weight: normal;
}

.card-action {
    color: #4e73df;
    text-decoration: none;
    font-size: 14px;
}

.card-body {
    padding: 20px;
}

.target-performance {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.target-item {
    padding-bottom: 20px;
    border-bottom: 1px solid #f0f0f0;
}

.target-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.target-label {
    font-weight: 600;
    color: #333;
    margin-bottom: 10px;
}

.target-numbers {
    display: flex;
    align-items: baseline;
    margin-bottom: 10px;
}

.target-actual {
    font-size: 18px;
    font-weight: 700;
    color: #4e73df;
}

.target-expected {
    font-size: 14px;
    color: #666;
    margin-left: 5px;
}

.target-progress {
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 5px;
}

.target-bar {
    height: 100%;
    background: #4e73df;
    border-radius: 4px;
    transition: width 1s ease-in-out;
}

.target-percentage {
    text-align: right;
    font-size: 14px;
    color: #333;
    font-weight: 600;
}

.chart-container {
    height: 300px;
    position: relative;
}

.chart-legend {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 20px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
    background: #ccc;
}

.legend-text {
    display: flex;
    align-items: center;
    gap: 10px;
}

.legend-label {
    font-weight: 600;
    min-width: 80px;
}

.legend-value {
    color: #666;
    font-size: 14px;
}

/* Team Ranking Styles */
.ranking-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.ranking-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 10px;
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.ranking-item:hover {
    background: #e9ecef;
    transform: translateX(5px);
}

.leader-item {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    border-left-color: #f39c12;
}

.ranking-item:first-child {
    background: linear-gradient(135deg, #ffd700, #ffed4e);
    border-left-color: #f39c12;
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
}

.ranking-item:nth-child(2) {
    background: linear-gradient(135deg, #e8e8e8, #c0c0c0);
    border-left-color: #95a5a6;
}

.ranking-item:nth-child(3) {
    background: linear-gradient(135deg, #ffa500, #ff8c00);
    border-left-color: #e67e22;
}

.ranking-position {
    width: 50px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.medal {
    font-size: 24px;
    animation: pulse 2s infinite;
}

.position-number {
    font-size: 18px;
    font-weight: 700;
    color: #666;
    width: 30px;
    height: 30px;
    background: #dee2e6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ranking-member {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-grow: 1;
    margin-left: 15px;
}

.member-info-rank {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.ranking-performance {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    text-align: right;
    margin-right: 15px;
}

.performance-value {
    font-size: 16px;
    font-weight: 700;
    color: #2c3e50;
}

.performance-details {
    font-size: 12px;
    color: #7f8c8d;
}

.ranking-actions {
    display: flex;
    align-items: center;
}

.rank-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    background: #667eea;
    color: white;
    border-radius: 50%;
    text-decoration: none;
    transition: all 0.3s ease;
}

.rank-action-btn:hover {
    background: #5a67d8;
    transform: scale(1.1);
    color: white;
}

.rank-action-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

@keyframes pulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
    100% {
        transform: scale(1);
    }
}

/* Table Responsive Styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.member-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.member-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    overflow: hidden;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: #666;
    flex-shrink: 0;
}

.member-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.member-details {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.member-name {
    font-weight: 500;
    white-space: nowrap;
}

.member-role-mobile {
    display: none;
}

.role-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}

.leader-badge {
    background: #e8f5e9;
    color: #2e7d32;
}

.member-badge {
    background: #e3f2fd;
    color: #1976d2;
}

.leader-row {
    background: #f9fff9;
}

.progress-mini {
    height: 5px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 3px;
}

.progress-bar {
    height: 100%;
    background: #4e73df;
    border-radius: 3px;
}

.progress-text {
    font-size: 12px;
    color: #666;
    text-align: right;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.data-table th {
    text-align: left;
    padding: 12px 10px;
    border-bottom: 2px solid #e0e0e0;
    font-weight: 600;
    color: #333;
    background: #f8f9fa;
    white-space: nowrap;
}

.data-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
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

.data-table tfoot tr {
    background: #f8f9fa;
    font-weight: 600;
}

.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 12px;
    font-size: 12px;
    border-radius: 6px;
    background: #667eea;
    color: white;
    text-decoration: none;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.action-btn:hover {
    background: #5a67d8;
    transform: translateY(-1px);
    color: white;
}

.action-btn .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

.btn-text {
    font-weight: 500;
}

.empty-state {
    padding: 40px 20px;
    text-align: center;
    color: #666;
}

.empty-state p {
    margin: 0;
    font-size: 16px;
}

/* Hide/Show Mobile Elements */
.hide-mobile {
    display: table-cell;
}

.show-mobile {
    display: none;
}

.mobile-actions {
    width: 80px;
    text-align: center;
}

/* Mobile Responsive Styles */
@media screen and (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .team-detail-grid {
        grid-template-columns: 1fr;
    }
    
    .performance-summary, .policy-types, .monthly-trend, .member-performance, .team-ranking, .recent-policies {
        grid-column: 1;
    }
    
    .performance-summary {
        grid-row: 1;
    }
    
    .policy-types {
        grid-row: 2;
    }
    
    .monthly-trend {
        grid-row: 3;
    }
    
    .member-performance {
        grid-row: 4;
    }
    
    .team-ranking {
        grid-row: 5;
    }
    
    .recent-policies {
        grid-row: 6;
    }
}

@media screen and (max-width: 768px) {
    .team-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }
    
    .team-header-right {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
    
    .modern-btn {
        padding: 10px 16px;
        font-size: 13px;
    }
    
    .modern-btn span {
        display: none;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .team-period-filter {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .current-period {
        width: 100%;
    }
    
    /* Mobile Table Styles */
    .hide-mobile {
        display: none;
    }
    
    .show-mobile {
        display: block;
    }
    
    .data-table {
        min-width: 100%;
        font-size: 14px;
    }
    
    .data-table th,
    .data-table td {
        padding: 8px 6px;
    }
    
    .member-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .member-details {
        width: 100%;
    }
    
    .member-name {
        font-size: 14px;
    }
    
    .action-btn .btn-text {
        display: none;
    }
    
    .action-btn {
        padding: 6px 8px;
        min-width: 32px;
        justify-content: center;
    }
    
    /* Best Performer Mobile */
    .best-performer-card {
        padding: 20px;
    }
    
    .performer-info {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .performer-avatar {
        width: 80px;
        height: 80px;
        font-size: 32px;
        margin: 0 auto;
    }
    
    .trophy-icon {
        position: static;
        text-align: center;
        margin-bottom: 10px;
    }
    
    /* Ranking Mobile */
    .ranking-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 15px;
    }
    
    .ranking-member {
        margin-left: 0;
        width: 100%;
    }
    
    .ranking-performance {
        align-items: flex-start;
        text-align: left;
        margin-right: 0;
        width: 100%;
    }
    
    .ranking-actions {
        align-self: flex-end;
    }
}

@media screen and (max-width: 480px) {
    .team-detail-container {
        padding: 10px;
    }
    
    .team-header {
        padding: 15px;
    }
    
    .team-icon {
        width: 60px;
        height: 60px;
        margin-right: 15px;
    }
    
    .team-icon .dashicons {
        font-size: 30px;
    }
    
    .team-info h1 {
        font-size: 20px;
    }
    
    .team-meta {
        flex-direction: column;
        gap: 8px;
    }
    
    .card-body {
        padding: 15px;
    }
    
    .chart-container {
        height: 250px;
    }
    
    .modern-btn {
        padding: 8px 12px;
        font-size: 12px;
        gap: 4px;
    }
    
    .best-performer-card {
        padding: 15px;
    }
    
    .performer-name {
        font-size: 16px;
    }
    
    .performer-achievement {
        font-size: 14px;
        padding: 4px 10px;
    }
}
</style>
