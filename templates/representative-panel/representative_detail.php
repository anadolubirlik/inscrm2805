<?php
if (!defined('ABSPATH')) {
    exit;
}

// Temsilci ID'sini al
$rep_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$rep_id) {
    echo '<div class="notice notice-error"><p>Temsilci ID bulunamadı.</p></div>';
    return;
}

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

global $wpdb;

// Temsilci bilgilerini al
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$wpdb->prefix}insurance_crm_representatives r
     JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.id = %d",
    $rep_id
));

if (!$representative) {
    echo '<div class="notice notice-error"><p>Temsilci bulunamadı.</p></div>';
    return;
}

// Temel istatistikleri al
$total_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d AND cancellation_date IS NULL",
    $rep_id
));

$total_customers = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers 
     WHERE representative_id = %d",
    $rep_id
));

$filtered_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    $rep_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
));

$filtered_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL",
    $rep_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$cancelled_policies = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND cancellation_date BETWEEN %s AND %s",
    $rep_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$cancelled_premium = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(refunded_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND cancellation_date BETWEEN %s AND %s",
    $rep_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
)) ?: 0;

$net_premium = $filtered_premium - $cancelled_premium;

// Temsilcinin aylık hedefleri
$monthly_target = $representative->monthly_target ?: 0;
$target_policy_count = $representative->target_policy_count ?: 0;

// Seçilen dönem için orantılı hedef hesaplaması
$days_in_period = (strtotime($filter_end_date) - strtotime($filter_start_date)) / (60 * 60 * 24) + 1;
$days_in_month = date('t', strtotime($filter_start_date));

if ($filter_period == 'this_month' || $filter_period == 'last_month') {
    // Tam ay için hedef kullan
    $period_target = $monthly_target;
    $period_policy_target = $target_policy_count;
} else {
    // Dönem uzunluğuna göre hedefi orantıla
    $months_in_period = $days_in_period / 30;
    $period_target = $monthly_target * $months_in_period;
    $period_policy_target = ceil($target_policy_count * $months_in_period);
}

// Gerçekleşme oranları
$premium_achievement = $period_target > 0 ? ($net_premium / $period_target) * 100 : 0;
$policy_achievement = $period_policy_target > 0 ? ($filtered_policies / $period_policy_target) * 100 : 0;

// Prim hesaplaması - Yeni eklenen kod
$policy_threshold = 10; // Minimum poliçe sayısı
$premium_threshold = 300000; // Minimum prim tutarı (TL)
$commission_rate = 0.07; // Yüzde 7 komisyon

// Prim hak edişi için kontroller
$is_policy_threshold_met = $filtered_policies >= $policy_threshold;
$is_premium_threshold_met = $net_premium >= $premium_threshold;
$bonus_qualified = $is_policy_threshold_met && $is_premium_threshold_met;

// Hak edilen prim tutarı
$bonus_amount = 0;
$bonus_message = '';

if ($bonus_qualified) {
    $bonus_amount = ($net_premium - $premium_threshold) * $commission_rate;
    $bonus_message = '<strong class="bonus-earned">Tebrikler!</strong> ' . number_format($filtered_policies, 0, ',', '.') . ' poliçe ve ₺' . number_format($net_premium, 2, ',', '.') . ' üretim ile prim hak ettiniz.';
} else {
    // Prim hak edilmemişse, neden hak edilmediğini açıklayan mesaj
    if (!$is_policy_threshold_met) {
        $remaining_policies = $policy_threshold - $filtered_policies;
        $bonus_message = 'Henüz ' . number_format($filtered_policies, 0, ',', '.') . ' poliçe sattınız, ' . $policy_threshold . ' poliçe sınırına ' . $remaining_policies . ' poliçe kaldı.';
    } elseif (!$is_premium_threshold_met) {
        $remaining_premium = $premium_threshold - $net_premium;
        $bonus_message = $policy_threshold . ' poliçe sınırını geçtiniz, tebrikler! Ancak henüz ₺' . number_format($premium_threshold, 2, ',', '.') . ' üretim sınırına ulaşamadınız. Kalan: ₺' . number_format($remaining_premium, 2, ',', '.');
    }
}

// Poliçe tipine göre dağılım
$policy_types = $wpdb->get_results($wpdb->prepare(
    "SELECT policy_type, COUNT(*) as count, COALESCE(SUM(premium_amount), 0) as total_premium
     FROM {$wpdb->prefix}insurance_crm_policies 
     WHERE representative_id = %d 
     AND start_date BETWEEN %s AND %s
     AND cancellation_date IS NULL
     GROUP BY policy_type",
    $rep_id, $filter_start_date . ' 00:00:00', $filter_end_date . ' 23:59:59'
));

// Son eklenen poliçeler
$recent_policies = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, c.first_name, c.last_name
     FROM {$wpdb->prefix}insurance_crm_policies p
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
     WHERE p.representative_id = %d
     AND p.cancellation_date IS NULL
     ORDER BY p.created_at DESC
     LIMIT 10",
    $rep_id
));

// Son eklenen müşteriler
$recent_customers = $wpdb->get_results($wpdb->prepare(
    "SELECT c.*, 
            (SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies WHERE customer_id = c.id AND cancellation_date IS NULL) as policy_count
     FROM {$wpdb->prefix}insurance_crm_customers c
     WHERE c.representative_id = %d
     ORDER BY c.created_at DESC
     LIMIT 5",
    $rep_id
));

// Son görevler
$recent_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id = %d
     ORDER BY t.due_date ASC
     LIMIT 10",
    $rep_id
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
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s",
        $rep_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59'
    )) ?: 0;
    
    $month_policy_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) 
         FROM {$wpdb->prefix}insurance_crm_policies 
         WHERE representative_id = %d 
         AND start_date BETWEEN %s AND %s
         AND cancellation_date IS NULL",
        $rep_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59'
    )) ?: 0;
    
    $monthly_data[] = array(
        'month' => $month,
        'month_name' => date_i18n('F Y', strtotime($month)),
        'premium' => $month_premium,
        'policy_count' => $month_policy_count
    );
}

?>

<div class="rep-detail-container">
    <div class="rep-header">
        <div class="rep-header-left">
            <div class="rep-avatar">
                <?php if (!empty($representative->avatar_url)): ?>
                    <img src="<?php echo esc_url($representative->avatar_url); ?>" alt="<?php echo esc_attr($representative->display_name); ?>">
                <?php else: ?>
                    <div class="default-avatar">
                        <?php echo substr($representative->display_name, 0, 1); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="rep-info">
                <h1><?php echo esc_html($representative->display_name); ?></h1>
                <div class="rep-meta">
                    <span class="rep-title"><?php echo esc_html($representative->title); ?></span>
                    <span class="rep-email"><i class="dashicons dashicons-email"></i> <?php echo esc_html($representative->user_email); ?></span>
                    <span class="rep-phone"><i class="dashicons dashicons-phone"></i> <?php echo esc_html($representative->phone); ?></span>
                </div>
            </div>
        </div>
        <div class="rep-header-right">
            <div class="action-buttons">
                <a href="<?php echo generate_panel_url('edit_representative', '', $rep_id); ?>" class="action-button edit-btn">
                    <i class="dashicons dashicons-edit"></i>
                    <span>Düzenle</span>
                </a>
                <a href="<?php echo generate_panel_url('representatives'); ?>" class="action-button list-btn">
                    <i class="dashicons dashicons-list-view"></i>
                    <span>Temsilciler</span>
                </a>
                <a href="<?php echo generate_panel_url('dashboard'); ?>" class="action-button dashboard-btn">
                    <i class="dashicons dashicons-dashboard"></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>
    </div>

    <div class="rep-period-filter">
        <form action="" method="get" class="filter-form">
            <input type="hidden" name="view" value="representative_detail">
            <input type="hidden" name="id" value="<?php echo $rep_id; ?>">
            
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


<!-- Temsilci bilgileri ve tarih seçimi sonrasında bu kodu koyun -->
<div style="width: 100%; display: flex; gap: 8px; margin-bottom: 20px;">
    <!-- Toplam Müşteri -->
    <div style="width: 20%; background: #fff; border-radius: 8px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-top: 3px solid #4e73df;">
        <div style="display: flex; align-items: center;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4e73df, #224abe); display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                <i class="dashicons dashicons-groups" style="color: white;"></i>
            </div>
            <div>
                <div style="font-size: 18px; font-weight: 700; margin-bottom: 2px; color: #333;"><?php echo number_format($total_customers, 0, ',', '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-weight: 500;">Toplam Müşteri</div>
            </div>
        </div>
    </div>
    
    <!-- Toplam Poliçe -->
    <div style="width: 20%; background: #fff; border-radius: 8px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-top: 3px solid #4e73df;">
        <div style="display: flex; align-items: center;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4e73df, #224abe); display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                <i class="dashicons dashicons-portfolio" style="color: white;"></i>
            </div>
            <div>
                <div style="font-size: 18px; font-weight: 700; margin-bottom: 2px; color: #333;"><?php echo number_format($total_policies, 0, ',', '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-weight: 500;">Toplam Poliçe</div>
            </div>
        </div>
    </div>
    
    <!-- Dönem Üretim -->
    <div style="width: 20%; background: #fff; border-radius: 8px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-top: 3px solid #4e73df;">
        <div style="display: flex; align-items: center;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #4e73df, #224abe); display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                <i class="dashicons dashicons-chart-area" style="color: white;"></i>
            </div>
            <div>
                <div style="font-size: 18px; font-weight: 700; margin-bottom: 2px; color: #333;">₺<?php echo number_format($filtered_premium, 2, ',', '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-weight: 500;">Dönem Üretim</div>
            </div>
        </div>
    </div>
    
    <!-- Dönem İptaller -->
    <div style="width: 20%; background: #fff; border-radius: 8px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-top: 3px solid #4e73df;">
        <div style="display: flex; align-items: center;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #e74a3b, #c52f1e); display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                <i class="dashicons dashicons-dismiss" style="color: white;"></i>
            </div>
            <div>
                <div style="font-size: 18px; font-weight: 700; margin-bottom: 2px; color: #e74a3b;">₺<?php echo number_format($cancelled_premium, 2, ',', '.'); ?></div>
                <div style="font-size: 12px; color: #666; font-weight: 500;">Dönem İptaller</div>
                <div style="font-size: 10px; color: #999;"><?php echo $cancelled_policies; ?> poliçe iptal</div>
            </div>
        </div>
    </div>
    
    <!-- Dönem Primi -->
    <div style="width: 20%; background: #fff; border-radius: 8px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-top: 3px solid <?php echo $bonus_qualified ? '#1cc88a' : '#f6c23e'; ?>;">
        <div style="display: flex; align-items: center;">
            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, <?php echo $bonus_qualified ? '#1cc88a, #13855c' : '#f6c23e, #dda20a'; ?>); display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                <i class="dashicons <?php echo $bonus_qualified ? 'dashicons-awards' : 'dashicons-clock'; ?>" style="color: white;"></i>
            </div>
            <div>
                <div style="font-size: 18px; font-weight: 700; margin-bottom: 2px; color: <?php echo $bonus_qualified ? '#1cc88a' : '#f6c23e'; ?>;"><?php echo $bonus_qualified ? '₺'.number_format($bonus_amount, 2, ',', '.') : '₺0,00'; ?></div>
                <div style="font-size: 12px; color: #666; font-weight: 500;">Dönem Primi</div>
                <div style="font-size: 10px; color: #999;">
                    <?php echo $bonus_qualified ? '%7 komisyon' : ($is_policy_threshold_met ? $filtered_policies . '/' . $policy_threshold . ' ✓' : $filtered_policies . '/' . $policy_threshold . ' poliçe'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="rep-detail-grid">
        <div class="rep-detail-card performance-summary">
            <div class="card-header">
                <h3>Hedef Gerçekleşme</h3>
            </div>
            <div class="card-body">
                <div class="target-performance">
                    <div class="target-item">
                        <div class="target-label">Prim Hedefi</div>
                        <div class="target-data">
                            <div class="target-numbers">
                                <div class="target-actual">₺<?php echo number_format($net_premium, 2, ',', '.'); ?></div>
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
                                <div class="target-actual"><?php echo number_format($filtered_policies, 0, ',', '.'); ?> adet</div>
                                <div class="target-expected">/ <?php echo number_format($period_policy_target, 0, ',', '.'); ?> adet</div>
                            </div>
                            <div class="target-progress">
                                <div class="target-bar" style="width: <?php echo min(100, $policy_achievement); ?>%"></div>
                            </div>
                            <div class="target-percentage"><?php echo number_format($policy_achievement, 2); ?>%</div>
                        </div>
                    </div>
                    
                    <!-- Prim Bilgilendirme Alanı - Yeni Eklenen Bölüm -->
                    <div class="bonus-information">
                        <div class="bonus-title">Prim Hak Edişi</div>
                        <div class="bonus-criteria">
                            <div class="criteria-item <?php echo $is_policy_threshold_met ? 'met' : 'not-met'; ?>">
                                <i class="dashicons <?php echo $is_policy_threshold_met ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></i>
                                <span>Minimum <?php echo $policy_threshold; ?> Poliçe</span>
                                <div class="criteria-count"><?php echo $filtered_policies; ?>/<?php echo $policy_threshold; ?></div>
                            </div>
                            <div class="criteria-item <?php echo $is_premium_threshold_met ? 'met' : 'not-met'; ?>">
                                <i class="dashicons <?php echo $is_premium_threshold_met ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></i>
                                <span>Minimum ₺<?php echo number_format($premium_threshold, 0, ',', '.'); ?> Üretim</span>
                                <div class="criteria-count">₺<?php echo number_format($net_premium, 0, ',', '.'); ?>/₺<?php echo number_format($premium_threshold, 0, ',', '.'); ?></div>
                            </div>
                        </div>
                        <div class="bonus-message">
                            <?php echo $bonus_message; ?>
                        </div>
                        <?php if ($bonus_qualified): ?>
                        <div class="bonus-calculation">
                            <div class="calculation-title">Prim Hesaplaması</div>
                            <div class="calculation-formula">
                                <div class="formula-item">
                                    <div class="formula-label">Dönem Net Üretim</div>
                                    <div class="formula-value">₺<?php echo number_format($net_premium, 2, ',', '.'); ?></div>
                                </div>
                                <div class="formula-item">
                                    <div class="formula-label">Kesinti Tutarı</div>
                                    <div class="formula-value">- ₺<?php echo number_format($premium_threshold, 2, ',', '.'); ?></div>
                                </div>
                                <div class="formula-item">
                                    <div class="formula-label">Primlendirilecek Tutar</div>
                                    <div class="formula-value">₺<?php echo number_format($net_premium - $premium_threshold, 2, ',', '.'); ?></div>
                                </div>
                                <div class="formula-item formula-result">
                                    <div class="formula-label">Hak Edilen Prim (%7)</div>
                                    <div class="formula-value">₺<?php echo number_format($bonus_amount, 2, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="rep-detail-card policy-types">
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

        <div class="rep-detail-card monthly-trend">
            <div class="card-header">
                <h3>Aylık Performans Trendi</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="rep-detail-card recent-policies">
            <div class="card-header">
                <h3>Temsilcinin Son Eklediği Poliçeler</h3>
                <a href="<?php echo generate_panel_url('policies', '', '', array('rep_id' => $rep_id)); ?>" class="card-action">Tümünü Gör</a>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_policies)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Poliçe No</th>
                            <th>Müşteri</th>
                            <th>Tür</th>
                            <th>Başlangıç</th>
                            <th>Tutar</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_policies as $policy): ?>
                        <tr>
                            <td><?php echo esc_html($policy->policy_number); ?></td>
                            <td><?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?></td>
                            <td><?php echo esc_html(ucfirst($policy->policy_type)); ?></td>
                            <td><?php echo date_i18n('d.m.Y', strtotime($policy->start_date)); ?></td>
                            <td>₺<?php echo number_format($policy->premium_amount, 2, ',', '.'); ?></td>
                            <td>
                                <a href="<?php echo generate_panel_url('policies', 'view', $policy->id); ?>" class="button button-small view-btn">
                                    <i class="dashicons dashicons-visibility"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p>Poliçe verisi bulunamadı.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Son Eklenen Müşteriler ve Görevler - Yan Yana -->
        <div class="rep-detail-card recent-customers">
            <div class="card-header">
                <h3>Temsilcinin Son Eklediği Müşteriler</h3>
                <a href="<?php echo generate_panel_url('customers', '', '', array('rep_id' => $rep_id)); ?>" class="card-action">Tümünü Gör</a>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_customers)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Müşteri</th>
                            <th>Telefon</th>
                            <th>Poliçe Sayısı</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_customers as $customer): ?>
                        <tr>
                            <td><?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?></td>
                            <td><?php echo esc_html($customer->phone); ?></td>
                            <td><?php echo (int)$customer->policy_count; ?></td>
                            <td>
                                <a href="<?php echo generate_panel_url('customers', 'view', $customer->id); ?>" class="button button-small view-btn">
                                    <i class="dashicons dashicons-visibility"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p>Müşteri verisi bulunamadı.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="rep-detail-card recent-tasks">
            <div class="card-header">
                <h3>Temsilcinin Görevleri</h3>
                <a href="<?php echo generate_panel_url('tasks', '', '', array('rep_id' => $rep_id)); ?>" class="card-action">Tümünü Gör</a>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_tasks)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Görev</th>
                            <th>Son Tarih</th>
                            <th>Durum</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_tasks as $task): 
                            $priority_class = '';
                            $status_class = '';
                            
                            if ($task->priority == 'high') {
                                $priority_class = 'priority-high';
                                $priority_text = 'Yüksek';
                            } elseif ($task->priority == 'medium') {
                                $priority_class = 'priority-medium';
                                $priority_text = 'Orta';
                            } else {
                                $priority_class = 'priority-low';
                                $priority_text = 'Düşük';
                            }
                            
                            if ($task->status == 'completed') {
                                $status_class = 'status-completed';
                                $status_text = 'Tamamlandı';
                            } elseif ($task->status == 'in_progress') {
                                $status_class = 'status-inprogress';
                                $status_text = 'Devam Ediyor';
                            } else {
                                $status_class = 'status-pending';
                                $status_text = 'Bekliyor';
                            }
                        ?>
                        <tr>
                            <td><?php echo esc_html($task->title); ?></td>
                            <td><?php echo date_i18n('d.m.Y', strtotime($task->due_date)); ?></td>
                            <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td>
                                <a href="<?php echo generate_panel_url('tasks', 'view', $task->id); ?>" class="button button-small view-btn">
                                    <i class="dashicons dashicons-visibility"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p>Görev verisi bulunamadı.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
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
.rep-detail-container {
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.rep-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.rep-header:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, #4e73df, #36b9cc);
}

.rep-header-left {
    display: flex;
    align-items: center;
}

.rep-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 20px;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: #666;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.rep-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-avatar {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #4e73df, #224abe);
    color: white;
    font-size: 32px;
    font-weight: bold;
}

.rep-info h1 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #333;
    font-weight: 700;
}

.rep-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    color: #666;
    font-size: 14px;
}

.rep-meta span {
    display: flex;
    align-items: center;
}

.rep-meta .dashicons {
    margin-right: 5px;
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* Yeni Eklenen Aksiyon Butonları Stili */
.rep-header-right {
    display: flex;
    gap: 10px;
}

.action-buttons {
    display: flex;
    gap: 10px;
}

.action-button {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-decoration: none;
    color: #333;
    padding: 10px;
    border-radius: 8px;
    transition: all 0.2s ease;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    width: 80px;
    height: 80px;
    justify-content: center;
}

.action-button:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.action-button .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    margin-bottom: 5px;
}

.action-button span {
    font-size: 12px;
    font-weight: 500;
}

.edit-btn {
    background: #ebf7fe;
    border-color: #c9e5f5;
    color: #2271b1;
}

.edit-btn:hover {
    background: #dcf0fd;
    color: #0a58ca;
}

.edit-btn .dashicons {
    color: #0a58ca;
}

.list-btn {
    background: #f0f7ff;
    border-color: #d0e3ff;
    color: #1a56db;
}

.list-btn:hover {
    background: #e0ecff;
    color: #1a46db;
}

.list-btn .dashicons {
    color: #1a56db;
}

.dashboard-btn {
    background: #f0fff4;
    border-color: #dcffe4;
    color: #0d792d;
}

.dashboard-btn:hover {
    background: #dcffe4;
    color: #0a6724;
}

.dashboard-btn .dashicons {
    color: #0d792d;
}

.rep-period-filter {
    padding: 15px 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    margin-bottom: 20px;
    width: 100%;
}

.stat-box {
    flex: 1;
    min-width: 0;
    background: #fff;
    border-radius: 8px;
    padding: 12px 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.08);
}

.stat-box:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: #4e73df;
}

.stat-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #4e73df, #224abe);
    color: white;
    box-shadow: 0 4px 8px rgba(78, 115, 223, 0.3);
    flex-shrink: 0;
}

.stat-icon .dashicons {
    font-size: 18px;
}

.negative-icon {
    background: linear-gradient(135deg, #e74a3b, #c52f1e);
    box-shadow: 0 4px 8px rgba(231, 74, 59, 0.3);
}

/* Bonus Icon Styling */
.bonus-icon {
    background: linear-gradient(135deg, #1cc88a, #13855c);
    box-shadow: 0 4px 8px rgba(28, 200, 138, 0.3);
}

.waiting-icon {
    background: linear-gradient(135deg, #f6c23e, #dda20a);
    box-shadow: 0 4px 8px rgba(246, 194, 62, 0.3);
}

.bonus-box:before {
    background: #1cc88a;
}

.waiting-box:before {
    background: #f6c23e;
}

.bonus-value {
    color: #1cc88a !important;
}

.waiting-value {
    color: #f6c23e !important;
}

/* Stat Box Styling - Küçültüldü */
.stat-details {
    flex-grow: 1;
    text-align: left;
    min-width: 0;
}

.stat-value {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 2px;
    color: #333;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.negative-value {
    color: #e74a3b;
}

.stat-label {
    font-size: 12px;
    color: #666;
    font-weight: 500;
}

.stat-sublabel {
    font-size: 10px;
    color: #999;
    margin-top: 2px;
}

.rep-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.rep-detail-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.rep-detail-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
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

.recent-policies {
    grid-column: 1 / span 2;
    grid-row: 3;
}

.recent-customers {
    grid-column: 1;
    grid-row: 4;
}

.recent-tasks {
    grid-column: 2;
    grid-row: 4;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f0;
    background: #f8f9fa;
}

.card-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.card-action {
    color: #4e73df;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.2s ease;
}

.card-action:hover {
    color: #224abe;
    text-decoration: underline;
}

.card-body {
    padding: 20px;
}

/* Target Performance Styling */
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

/* Bonus Information Styling - Yeni Eklenen */
.bonus-information {
    background-color: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
    border: 1px solid #eee;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.bonus-title {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin-bottom: 15px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.bonus-criteria {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 15px;
}

.criteria-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    border-radius: 4px;
    font-weight: 500;
}

.criteria-item.met {
    background-color: #ecfdf3;
    color: #0f5132;
}

.criteria-item.not-met {
    background-color: #fff0f0;
    color: #a30000;
}

.criteria-item.met .dashicons {
    color: #22863a;
}

.criteria-item.not-met .dashicons {
    color: #cb2431;
}

.criteria-count {
    margin-left: auto;
    font-weight: 600;
}

.bonus-message {
    padding: 15px;
    background-color: #f0f7ff;
    border-radius: 4px;
    color: #0c5460;
    font-size: 14px;
    margin-bottom: 15px;
    border-left: 4px solid #4e73df;
}

.bonus-earned {
    color: #1cc88a;
}

/* Bonus Calculation Styling */
.bonus-calculation {
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 15px;
    margin-top: 15px;
}

.calculation-title {
    font-weight: 600;
    margin-bottom: 10px;
    color: #333;
    text-align: center;
}

.calculation-formula {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.formula-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px dashed #eee;
}

.formula-item:last-child {
    border-bottom: none;
}

.formula-label {
    font-weight: 500;
    color: #555;
}

.formula-value {
    font-weight: 600;
    color: #333;
}

.formula-result {
    margin-top: 10px;
    border-top: 2px solid #e0e0e0;
    padding-top: 10px;
}

.formula-result .formula-label {
    font-weight: 700;
    color: #1cc88a;
}

.formula-result .formula-value {
    font-weight: 700;
    color: #1cc88a;
    font-size: 18px;
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

/* Data Table Styling */
.data-table {
    width: 100%;
    border-collapse: collapse;
    border-radius: 4px;
    overflow: hidden;
}

.data-table th {
    text-align: left;
    padding: 12px;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    font-weight: 600;
    color: #333;
    white-space: nowrap;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
}

.data-table tr:hover td {
    background-color: #f5f5f5;
}

.data-table tr:last-child td {
    border-bottom: none;
}

.empty-state {
    padding: 30px 20px;
    text-align: center;
    color: #666;
    background-color: #f9f9f9;
    border-radius: 6px;
    border: 1px dashed #ddd;
}

/* View Button Styling - Geliştirilmiş Butonlar */
.view-btn {
    padding: 6px;
    background-color: #f0f7ff;
    border-color: #d0e3ff;
    color: #1a56db;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s ease;
    width: 30px;
    height: 30px;
}

.view-btn:hover {
    background-color: #e0ecff;
    color: #1a46db;
    box-shadow: 0 2px 5px rgba(26, 86, 219, 0.2);
    transform: translateY(-2px);
}

.view-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* Öncelik ve durum rozetleri için stiller */
.priority-badge, .status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    text-align: center;
}

.priority-high {
    background-color: #ffeaea;
    color: #cb2431;
}

.priority-medium {
    background-color: #fff6e0;
    color: #bf8700;
}

.priority-low {
    background-color: #e8f4fd;
    color: #36b9cc;
}

.status-completed {
    background-color: #e5f9f1;
    color: #1cc88a;
}

.status-inprogress {
    background-color: #e8f4fd;
    color: #4e73df;
}

.status-pending {
    background-color: #f8f9fc;
    color: #5a5c69;
}

/* Mobil uyumluluk için medya sorguları */
@media screen and (max-width: 1200px) {
    .stats-grid {
        flex-wrap: wrap;
    }
    
    .stat-box {
        flex: 1 1 30%;
        margin-bottom: 8px;
    }
    
    .rep-detail-grid {
        grid-template-columns: 1fr;
    }
    
    .performance-summary, .policy-types, .monthly-trend, .recent-policies {
        grid-column: 1;
    }
    
    .recent-customers, .recent-tasks {
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
    
    .recent-policies {
        grid-row: 4;
    }
    
    .recent-customers {
        grid-row: 5;
    }
    
    .recent-tasks {
        grid-row: 6;
    }
    
    .bonus-criteria {
        flex-direction: column;
    }
}

@media screen and (max-width: 920px) {
    .stat-box {
        flex: 1 1 47%;
    }
}

@media screen and (max-width: 768px) {
    .rep-header {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 20px;
    }
    
    .rep-header-left {
        flex-direction: column;
        align-items: center;
    }
    
    .rep-avatar {
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .rep-meta {
        justify-content: center;
    }
    
    .action-buttons {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .rep-period-filter {
        flex-direction: column;
        gap: 15px;
    }
    
    .filter-form {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .custom-date-fields {
        flex-direction: column;
        width: 100%;
    }
    
    .filter-section {
        width: 100%;
    }
    
    .filter-section select,
    .filter-section input {
        width: 100%;
    }
    
    .current-period {
        text-align: center;
    }
    
    .target-numbers {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }
    
    .target-actual, .target-expected {
        width: 100%;
    }
    
    .target-expected {
        text-align: right;
    }
    
    .formula-item {
        flex-direction: column;
        gap: 5px;
    }
    
    .formula-value {
        text-align: right;
    }
}

@media screen and (max-width: 576px) {
    .stat-box {
        flex: 1 1 100%;
    }
}
</style>