<?php
/**
 * Basit ve Kullanıcı Dostu Raporlar Sayfası
 * @version 8.0.0 - Clean, Simple, User-Friendly Design
 * @created 2025-05-28
 * @author Anadolu Birlik CRM Team
 */

// Yetki kontrolü
if (!is_user_logged_in()) {
    echo '<div class="simple-alert alert-error">
        <i class="fas fa-lock"></i>
        <span>Bu sayfayı görüntülemek için giriş yapmalısınız.</span>
    </div>';
    return;
}

// Kullanıcı rolü ve yetki belirleme
function get_user_role_and_permissions() {
    global $wpdb;
    
    $user_id = get_current_user_id();
    $user = wp_get_current_user();
    
    $role = 'representative';
    
    if (in_array('administrator', $user->roles) || user_can($user_id, 'manage_options')) {
        $role = 'patron';
    } elseif (in_array('manager', $user->roles) || user_can($user_id, 'edit_others_posts')) {
        $role = 'manager';
    } elseif (in_array('assistant_manager', $user->roles)) {
        $role = 'assistant_manager';
    } elseif (in_array('team_leader', $user->roles)) {
        $role = 'team_leader';
    }
    
    $representative_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
        $user_id
    ));
    
    $team_id = null;
    $team_members = [];
    
    if ($representative_id) {
        $team_id = $wpdb->get_var($wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
            $representative_id
        ));
        
        if ($team_id && in_array($role, ['team_leader', 'assistant_manager'])) {
            $team_members = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE team_id = %d AND status = 'active'",
                $team_id
            ));
        }
    }
    
    return [
        'role' => $role,
        'representative_id' => $representative_id,
        'team_id' => $team_id,
        'team_members' => $team_members,
        'can_see_all' => in_array($role, ['patron', 'manager']),
        'can_see_team' => in_array($role, ['assistant_manager', 'team_leader']),
        'can_see_own' => true
    ];
}

// Patron için müşteri temsilcisi listesi
function get_representatives_list() {
    global $wpdb;
    
    $representatives = $wpdb->get_results(
        "SELECT r.id, r.first_name, r.last_name, u.display_name 
         FROM {$wpdb->prefix}insurance_crm_representatives r
         LEFT JOIN {$wpdb->prefix}users u ON r.user_id = u.ID
         WHERE r.status = 'active'
         ORDER BY r.first_name ASC"
    );
    
    return $representatives;
}

// Yetki bazında WHERE koşulu oluşturma
function build_permission_where_clause($permissions, $selected_rep = null, $table_alias = '') {
    $prefix = $table_alias ? $table_alias . '.' : '';
    
    if ($permissions['can_see_all'] && $selected_rep) {
        return "{$prefix}representative_id = " . intval($selected_rep);
    }
    
    if ($permissions['can_see_all']) {
        return '1=1';
    } elseif ($permissions['can_see_team'] && !empty($permissions['team_members'])) {
        $team_ids = implode(',', array_map('intval', $permissions['team_members']));
        return "{$prefix}representative_id IN ({$team_ids})";
    } else {
        return "{$prefix}representative_id = " . intval($permissions['representative_id']);
    }
}

// Müşteri portföy raporu
function get_customer_portfolio_data($permissions, $selected_rep = null, $start_date = '', $end_date = '', $status = '', $category = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    $where_permission = build_permission_where_clause($permissions, $selected_rep, 'c');
    
    $query = "SELECT c.id, c.first_name, c.last_name, c.category, c.status, c.created_at, c.representative_id,
                     r.first_name as rep_first_name, r.last_name as rep_last_name,
                     (SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies p WHERE p.customer_id = c.id) as policy_count,
                     (SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies p WHERE p.customer_id = c.id AND p.cancellation_date IS NULL) as total_premium
              FROM $table_name c 
              LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON c.representative_id = r.id
              WHERE $where_permission";
    
    $params = [];
    if ($start_date) { $query .= " AND c.created_at >= %s"; $params[] = $start_date; }
    if ($end_date) { $query .= " AND c.created_at <= %s"; $params[] = $end_date; }
    if ($status) { $query .= " AND c.status = %s"; $params[] = $status; }
    if ($category) { $query .= " AND c.category = %s"; $params[] = $category; }
    
    $query .= " ORDER BY c.created_at DESC LIMIT 100";
    
    if (!empty($params)) {
        return $wpdb->get_results($wpdb->prepare($query, ...$params));
    }
    return $wpdb->get_results($query);
}

// Müşteri portföy özet verileri
function get_customer_portfolio_summary($permissions, $selected_rep = null, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_customers';
    
    $where_permission = build_permission_where_clause($permissions, $selected_rep, '');
    
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $last_month_start = date('Y-m-01', strtotime('-1 month'));
    $last_month_end = date('Y-m-t', strtotime('-1 month'));
    
    $summary_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'pasif' THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN status = 'belirsiz' THEN 1 ELSE 0 END) as uncertain,
            SUM(CASE WHEN created_at >= %s AND created_at <= %s THEN 1 ELSE 0 END) as new_this_month,
            SUM(CASE WHEN created_at >= %s AND created_at <= %s THEN 1 ELSE 0 END) as new_last_month,
            SUM(CASE WHEN category = 'kurumsal' THEN 1 ELSE 0 END) as corporate,
            SUM(CASE WHEN category = 'bireysel' THEN 1 ELSE 0 END) as individual
         FROM $table_name 
         WHERE $where_permission";
    
    $params = [$month_start, $month_end, $last_month_start, $last_month_end];
    
    if ($start_date) { $summary_query .= " AND created_at >= %s"; $params[] = $start_date; }
    if ($end_date) { $summary_query .= " AND created_at <= %s"; $params[] = $end_date; }
    
    $summary = $wpdb->get_row($wpdb->prepare($summary_query, ...$params));
    
    if (!$summary) {
        $summary = (object)[
            'total' => 0, 'active' => 0, 'inactive' => 0, 'uncertain' => 0,
            'new_this_month' => 0, 'new_last_month' => 0, 'corporate' => 0, 'individual' => 0
        ];
    }
    
    $new_change = 0;
    if ($summary->new_last_month > 0) {
        $new_change = round((($summary->new_this_month - $summary->new_last_month) / $summary->new_last_month) * 100, 1);
    }
    
    return [
        'total' => (int)$summary->total,
        'active' => (int)$summary->active,
        'inactive' => (int)$summary->inactive,
        'uncertain' => (int)$summary->uncertain,
        'new_this_month' => (int)$summary->new_this_month,
        'new_last_month' => (int)$summary->new_last_month,
        'new_change_percent' => $new_change,
        'corporate' => (int)$summary->corporate,
        'individual' => (int)$summary->individual,
        'activity_rate' => $summary->total > 0 ? round(($summary->active / $summary->total) * 100, 1) : 0
    ];
}

// Satış performans raporu
function get_sales_performance_data($permissions, $selected_rep = null, $start_date = '', $end_date = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_policies';
    
    $where_permission = build_permission_where_clause($permissions, $selected_rep, 'p');
    
    if (!$start_date) $start_date = date('Y-m-01', strtotime('-6 months'));
    if (!$end_date) $end_date = date('Y-m-d');
    
    $query = "SELECT 
        DATE_FORMAT(p.start_date, '%Y-%m') as period,
        COUNT(*) as sales_count,
        SUM(p.premium_amount) as total_premium,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM $table_name p_check 
            WHERE p_check.customer_id = p.customer_id 
            AND p_check.start_date < p.start_date 
            AND ($where_permission)
        ) THEN 0 ELSE 1 END) as new_customers,
        SUM(CASE WHEN p.premium_amount > 300000 THEN (p.premium_amount - 300000) * 0.07 ELSE 0 END) as bonus_commission
     FROM $table_name p
     WHERE ($where_permission)
     AND p.start_date >= %s
     AND p.start_date <= %s
     GROUP BY DATE_FORMAT(p.start_date, '%Y-%m')
     ORDER BY period";
    
    $results = $wpdb->get_results($wpdb->prepare($query, $start_date, $end_date));
    
    // İptal edilen poliçeler
    $cancelled_query = "SELECT 
        DATE_FORMAT(p.cancellation_date, '%Y-%m') as period,
        COUNT(*) as cancelled,
        COALESCE(SUM(p.refunded_amount), 0) as refunded_amount
     FROM $table_name p
     WHERE ($where_permission)
     AND p.cancellation_date >= %s
     AND p.cancellation_date <= %s
     AND p.cancellation_date IS NOT NULL
     GROUP BY DATE_FORMAT(p.cancellation_date, '%Y-%m')";
    
    $cancelled_results = $wpdb->get_results($wpdb->prepare($cancelled_query, $start_date, $end_date));
    
    $cancelled_data = [];
    foreach ($cancelled_results as $cancelled) {
        $cancelled_data[$cancelled->period] = [
            'cancelled' => (int)$cancelled->cancelled,
            'refunded_amount' => (float)$cancelled->refunded_amount
        ];
    }
    
    if (!$results) $results = [];
    
    $tr_months = [
        '01' => 'Ocak', '02' => 'Şubat', '03' => 'Mart', '04' => 'Nisan', 
        '05' => 'Mayıs', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Ağustos',
        '09' => 'Eylül', '10' => 'Ekim', '11' => 'Kasım', '12' => 'Aralık'
    ];
    
    foreach($results as $key => $result) {
        $year_month = explode('-', $result->period);
        $year = $year_month[0];
        $month = $year_month[1];
        $results[$key]->period_tr = $tr_months[$month] . ' ' . $year;
        
        $period_cancelled = $cancelled_data[$result->period] ?? ['cancelled' => 0, 'refunded_amount' => 0];
        $results[$key]->cancelled = $period_cancelled['cancelled'];
        $results[$key]->refunded_amount = $period_cancelled['refunded_amount'];
        
        $results[$key]->net_sales = $result->sales_count - $results[$key]->cancelled;
        $results[$key]->net_premium = $result->total_premium - $results[$key]->refunded_amount;
        
        $results[$key]->monthly_target = 10;
        $results[$key]->over_target = max(0, $result->sales_count - 10);
        $results[$key]->target_achievement = round(($result->sales_count / 10) * 100, 1);
    }
    
    $total_sales = array_sum(array_column($results, 'sales_count'));
    $total_cancelled = array_sum(array_column($results, 'cancelled'));
    $total_premium = array_sum(array_column($results, 'total_premium'));
    $total_refunded = array_sum(array_column($results, 'refunded_amount'));
    $total_bonus = array_sum(array_column($results, 'bonus_commission'));
    $total_new_customers = array_sum(array_column($results, 'new_customers'));
    
    $net_sales = $total_sales - $total_cancelled;
    $net_premium = $total_premium - $total_refunded;
    
    $month_count = max(1, count($results));
    $total_target = 10 * $month_count;
    $achievement_rate = $total_target > 0 ? round(($net_sales / $total_target) * 100, 1) : 0;
    
    return [
        'data' => $results,
        'total_sales' => $total_sales,
        'total_cancelled' => $total_cancelled,
        'net_sales' => $net_sales,
        'total_premium' => $total_premium,
        'total_refunded' => $total_refunded,
        'net_premium' => $net_premium,
        'total_bonus_commission' => $total_bonus,
        'total_new_customers' => $total_new_customers,
        'target_sales' => $total_target,
        'achievement_rate' => $achievement_rate,
        'average_premium' => $total_sales > 0 ? round($total_premium / $total_sales, 2) : 0
    ];
}

// Ana işlem başlangıcı
$user_permissions = get_user_role_and_permissions();

if (!$user_permissions['representative_id'] && !$user_permissions['can_see_all']) {
    echo '<div class="simple-alert alert-error">
        <i class="fas fa-user-times"></i>
        <span>Müşteri temsilcisi bulunamadı. Lütfen yöneticinize başvurun.</span>
    </div>';
    return;
}

global $wpdb;

// Patron için seçilen temsilci
$selected_representative = '';
if ($user_permissions['can_see_all'] && isset($_GET['representative_filter'])) {
    $selected_representative = sanitize_text_field($_GET['representative_filter']);
}

// Temsilci listesi (patron için)
$representatives_list = [];
if ($user_permissions['can_see_all']) {
    $representatives_list = get_representatives_list();
}

// Aktif sekme
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

// Veri hazırlama
$dashboard_data = get_customer_portfolio_summary($user_permissions, $selected_representative);
$sales_data = get_sales_performance_data($user_permissions, $selected_representative);

// JavaScript için veri hazırlama
$js_chart_data = [
    'dashboard_summary' => [
        'labels' => ['Aktif', 'Pasif', 'Belirsiz'],
        'data' => [$dashboard_data['active'], $dashboard_data['inactive'], $dashboard_data['uncertain']]
    ],
    'sales_performance' => [
        'labels' => array_column($sales_data['data'], 'period_tr'),
        'sales_count' => array_column($sales_data['data'], 'sales_count'),
        'net_sales' => array_column($sales_data['data'], 'net_sales'),
        'total_premium' => array_column($sales_data['data'], 'total_premium'),
        'net_premium' => array_column($sales_data['data'], 'net_premium'),
        'bonus_commission' => array_column($sales_data['data'], 'bonus_commission')
    ]
];

?>

<!-- External Libraries -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<div class="simple-reports">
    <!-- Header -->
    <div class="reports-header">
        <div class="header-content">
            <div class="header-left">
                <h1>
                    <i class="fas fa-chart-bar"></i>
                    Raporlar
                </h1>
                <p class="user-info">
                    <span class="role-badge role-<?php echo $user_permissions['role']; ?>">
                        <?php echo ucfirst($user_permissions['role']); ?>
                    </span>
                    <?php if ($user_permissions['can_see_all']): ?>
                        | Tüm Veriler
                    <?php elseif ($user_permissions['can_see_team']): ?>
                        | Ekip Verileri
                    <?php else: ?>
                        | Kişisel Veriler
                    <?php endif; ?>
                    <span class="date">• <?php echo date('d.m.Y H:i'); ?></span>
                </p>
            </div>

            <?php if ($user_permissions['can_see_all']): ?>
            <div class="header-filter">
                <label for="representativeFilter">
                    <i class="fas fa-user-tie"></i>
                    Temsilci Seç:
                </label>
                <select id="representativeFilter" class="simple-select">
                    <option value="">Tüm Temsilciler</option>
                    <?php foreach ($representatives_list as $rep): ?>
                        <option value="<?php echo $rep->id; ?>" <?php echo ($selected_representative == $rep->id) ? 'selected' : ''; ?>>
                            <?php echo $rep->first_name . ' ' . $rep->last_name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-box">
                <div class="stat-icon stat-sales">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-number"><?php echo $sales_data['net_sales']; ?></span>
                    <span class="stat-label">Net Satış</span>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon stat-revenue">
                    <i class="fas fa-lira-sign"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-number">₺<?php echo number_format($sales_data['net_premium'], 0, ',', '.'); ?></span>
                    <span class="stat-label">Net Prim</span>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon stat-customers">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-number"><?php echo $dashboard_data['total']; ?></span>
                    <span class="stat-label">Müşteri</span>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-icon stat-target">
                    <i class="fas fa-bullseye"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-number"><?php echo $sales_data['achievement_rate']; ?>%</span>
                    <span class="stat-label">Hedef</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <div class="reports-nav">
        <nav class="nav-tabs">
            <button class="nav-tab active" data-tab="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </button>
            <button class="nav-tab" data-tab="customers">
                <i class="fas fa-users"></i>
                <span>Müşteriler</span>
            </button>
            <button class="nav-tab" data-tab="sales">
                <i class="fas fa-chart-line"></i>
                <span>Satış Performansı</span>
            </button>
            <button class="nav-tab" data-tab="commissions">
                <i class="fas fa-coins"></i>
                <span>Komisyonlar</span>
            </button>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="reports-content">
        <!-- Dashboard Tab -->
        <div id="dashboard-content" class="tab-content active">
            <div class="content-row">
                <!-- Customer Overview -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-users"></i>
                            Müşteri Portföyü
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="metrics-row">
                            <div class="metric">
                                <span class="metric-value"><?php echo $dashboard_data['total']; ?></span>
                                <span class="metric-label">Toplam</span>
                            </div>
                            <div class="metric success">
                                <span class="metric-value"><?php echo $dashboard_data['active']; ?></span>
                                <span class="metric-label">Aktif</span>
                            </div>
                            <div class="metric danger">
                                <span class="metric-value"><?php echo $dashboard_data['inactive']; ?></span>
                                <span class="metric-label">Pasif</span>
                            </div>
                            <div class="metric warning">
                                <span class="metric-value"><?php echo $dashboard_data['uncertain']; ?></span>
                                <span class="metric-label">Belirsiz</span>
                            </div>
                        </div>
                        
                        <div class="progress-section">
                            <div class="progress-header">
                                <span>Aktivite Oranı</span>
                                <span><?php echo $dashboard_data['activity_rate']; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $dashboard_data['activity_rate']; ?>%"></div>
                            </div>
                        </div>

                        <div class="chart-container">
                            <canvas id="customerChart" height="200"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Sales Performance -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-line"></i>
                            Satış Performansı
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="metrics-row">
                            <div class="metric">
                                <span class="metric-value"><?php echo $sales_data['total_sales']; ?></span>
                                <span class="metric-label">Toplam</span>
                            </div>
                            <div class="metric success">
                                <span class="metric-value"><?php echo $sales_data['net_sales']; ?></span>
                                <span class="metric-label">Net</span>
                            </div>
                            <div class="metric warning">
                                <span class="metric-value"><?php echo $sales_data['target_sales']; ?></span>
                                <span class="metric-label">Hedef</span>
                            </div>
                            <div class="metric <?php echo $sales_data['achievement_rate'] >= 100 ? 'success' : 'danger'; ?>">
                                <span class="metric-value"><?php echo $sales_data['achievement_rate']; ?>%</span>
                                <span class="metric-label">Başarı</span>
                            </div>
                        </div>

                        <div class="achievement-circle">
                            <div class="circle">
                                <div class="circle-fill" data-percent="<?php echo min(100, $sales_data['achievement_rate']); ?>"></div>
                                <div class="circle-text">
                                    <span class="circle-number"><?php echo $sales_data['achievement_rate']; ?>%</span>
                                    <span class="circle-label">Hedef Başarı</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <!-- Revenue Card -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-lira-sign"></i>
                            Gelir Analizi
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="revenue-stats">
                            <div class="revenue-item">
                                <span class="revenue-label">Net Prim</span>
                                <span class="revenue-value">₺<?php echo number_format($sales_data['net_premium'], 0, ',', '.'); ?></span>
                            </div>
                            <div class="revenue-item">
                                <span class="revenue-label">Bonus Komisyon</span>
                                <span class="revenue-value bonus">₺<?php echo number_format($sales_data['total_bonus_commission'], 0, ',', '.'); ?></span>
                            </div>
                            <div class="revenue-item">
                                <span class="revenue-label">Ortalama Prim</span>
                                <span class="revenue-value">₺<?php echo number_format($sales_data['average_premium'], 0, ',', '.'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Growth Card -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-area"></i>
                            Büyüme Trendi
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="growth-comparison">
                            <div class="growth-item current">
                                <span class="growth-period">Bu Ay</span>
                                <span class="growth-number"><?php echo $dashboard_data['new_this_month']; ?></span>
                                <span class="growth-label">Yeni Müşteri</span>
                            </div>
                            <div class="growth-arrow <?php echo $dashboard_data['new_change_percent'] >= 0 ? 'up' : 'down'; ?>">
                                <i class="fas fa-arrow-<?php echo $dashboard_data['new_change_percent'] >= 0 ? 'up' : 'down'; ?>"></i>
                                <span><?php echo abs($dashboard_data['new_change_percent']); ?>%</span>
                            </div>
                            <div class="growth-item previous">
                                <span class="growth-period">Geçen Ay</span>
                                <span class="growth-number"><?php echo $dashboard_data['new_last_month']; ?></span>
                                <span class="growth-label">Yeni Müşteri</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="exportToPNG('dashboard-content')">
                    <i class="fas fa-download"></i>
                    PNG İndir
                </button>
                <button class="btn btn-secondary" onclick="exportToCSV('dashboard')">
                    <i class="fas fa-file-csv"></i>
                    CSV İndir
                </button>
                <button class="btn btn-info" onclick="printReport()">
                    <i class="fas fa-print"></i>
                    Yazdır
                </button>
            </div>
        </div>

        <!-- Customers Tab -->
        <div id="customers-content" class="tab-content">
            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-users"></i>
                            Müşteri Listesi
                        </h3>
                        <div class="card-actions">
                            <button class="btn btn-sm btn-secondary" onclick="exportToCSV('customers')">
                                <i class="fas fa-download"></i>
                                İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Summary -->
                        <div class="summary-stats">
                            <div class="summary-item">
                                <span class="summary-number"><?php echo $dashboard_data['corporate']; ?></span>
                                <span class="summary-label">Kurumsal</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-number"><?php echo $dashboard_data['individual']; ?></span>
                                <span class="summary-label">Bireysel</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-number"><?php echo $dashboard_data['activity_rate']; ?>%</span>
                                <span class="summary-label">Aktivite</span>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="table-wrapper">
                            <table class="simple-table">
                                <thead>
                                    <tr>
                                        <th>Müşteri</th>
                                        <?php if ($user_permissions['can_see_all']): ?>
                                        <th>Temsilci</th>
                                        <?php endif; ?>
                                        <th>Kategori</th>
                                        <th>Durum</th>
                                        <th>Poliçe</th>
                                        <th>Prim</th>
                                        <th>Tarih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $portfolio_data = get_customer_portfolio_data($user_permissions, $selected_representative);
                                    
                                    if ($portfolio_data) {
                                        foreach ($portfolio_data as $customer) {
                                            echo '<tr>';
                                            echo '<td>' . esc_html($customer->first_name . ' ' . $customer->last_name) . '</td>';
                                            
                                            if ($user_permissions['can_see_all']) {
                                                echo '<td class="rep-cell">' . esc_html($customer->rep_first_name . ' ' . $customer->rep_last_name) . '</td>';
                                            }
                                            
                                            echo '<td><span class="badge badge-' . $customer->category . '">' . ucfirst($customer->category) . '</span></td>';
                                            echo '<td><span class="status status-' . $customer->status . '">' . ucfirst($customer->status) . '</span></td>';
                                            echo '<td>' . intval($customer->policy_count) . '</td>';
                                            echo '<td>₺' . number_format(floatval($customer->total_premium), 0, ',', '.') . '</td>';
                                            echo '<td>' . date('d.m.Y', strtotime($customer->created_at)) . '</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="' . ($user_permissions['can_see_all'] ? '7' : '6') . '" class="no-data">Müşteri bulunamadı.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Tab -->
        <div id="sales-content" class="tab-content">
            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-line"></i>
                            Aylık Satış Performansı
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container large">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-table"></i>
                            Detaylı Performans Tablosu
                        </h3>
                        <div class="card-actions">
                            <button class="btn btn-sm btn-secondary" onclick="exportToCSV('sales')">
                                <i class="fas fa-download"></i>
                                İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table class="simple-table">
                                <thead>
                                    <tr>
                                        <th>Dönem</th>
                                        <th>Satış</th>
                                        <th>İptal</th>
                                        <th>Net</th>
                                        <th>Prim (₺)</th>
                                        <th>Bonus (₺)</th>
                                        <th>Hedef %</th>
                                        <th>Yeni Müşteri</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($sales_data['data'])): ?>
                                        <?php foreach($sales_data['data'] as $period): ?>
                                            <tr>
                                                <td><strong><?php echo $period->period_tr; ?></strong></td>
                                                <td><?php echo $period->sales_count; ?></td>
                                                <td class="text-danger"><?php echo $period->cancelled; ?></td>
                                                <td class="text-success"><strong><?php echo $period->net_sales; ?></strong></td>
                                                <td>₺<?php echo number_format($period->net_premium, 0, ',', '.'); ?></td>
                                                <td class="text-warning">₺<?php echo number_format($period->bonus_commission, 0, ',', '.'); ?></td>
                                                <td>
                                                    <span class="achievement <?php echo $period->target_achievement >= 100 ? 'success' : ($period->target_achievement >= 70 ? 'warning' : 'danger'); ?>">
                                                        <?php echo $period->target_achievement; ?>%
                                                    </span>
                                                </td>
                                                <td><?php echo $period->new_customers; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="total-row">
                                            <td><strong>TOPLAM</strong></td>
                                            <td><strong><?php echo $sales_data['total_sales']; ?></strong></td>
                                            <td class="text-danger"><strong><?php echo $sales_data['total_cancelled']; ?></strong></td>
                                            <td class="text-success"><strong><?php echo $sales_data['net_sales']; ?></strong></td>
                                            <td><strong>₺<?php echo number_format($sales_data['total_premium'], 0, ',', '.'); ?></strong></td>
                                            <td class="text-warning"><strong>₺<?php echo number_format($sales_data['total_bonus_commission'], 0, ',', '.'); ?></strong></td>
                                            <td><strong><?php echo $sales_data['achievement_rate']; ?>%</strong></td>
                                            <td><strong><?php echo $sales_data['total_new_customers']; ?></strong></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="no-data">Satış verisi bulunamadı.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Commissions Tab -->
        <div id="commissions-content" class="tab-content">
            <div class="content-row">
                <!-- Commission Summary -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-trophy"></i>
                            Komisyon Özeti
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="commission-summary">
                            <div class="commission-item">
                                <span class="commission-label">Toplam Bonus</span>
                                <span class="commission-value">₺<?php echo number_format($sales_data['total_bonus_commission'], 0, ',', '.'); ?></span>
                                <span class="commission-detail">300.000 TL üstü %7</span>
                            </div>
                            <div class="commission-item">
                                <span class="commission-label">Hedef Üstü</span>
                                <span class="commission-value"><?php echo !empty($sales_data['data']) ? array_sum(array_column($sales_data['data'], 'over_target')) : 0; ?></span>
                                <span class="commission-detail">10 poliçe üzeri</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bonus Chart -->
                <div class="report-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-chart-bar"></i>
                            Aylık Bonus Trendi
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="bonusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <div class="report-card full-width">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-coins"></i>
                            Detaylı Komisyon Tablosu
                        </h3>
                        <div class="card-actions">
                            <button class="btn btn-sm btn-secondary" onclick="exportToCSV('commissions')">
                                <i class="fas fa-download"></i>
                                İndir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-wrapper">
                            <table class="simple-table">
                                <thead>
                                    <tr>
                                        <th>Dönem</th>
                                        <th>Satış</th>
                                        <th>Hedef</th>
                                        <th>Üstü</th>
                                        <th>Bonus (₺)</th>
                                        <th>Toplam Prim (₺)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($sales_data['data'])): ?>
                                        <?php foreach($sales_data['data'] as $period): ?>
                                            <tr>
                                                <td><strong><?php echo $period->period_tr; ?></strong></td>
                                                <td><?php echo $period->sales_count; ?></td>
                                                <td>10</td>
                                                <td class="<?php echo $period->over_target > 0 ? 'text-success' : ''; ?>">
                                                    <?php echo $period->over_target; ?>
                                                </td>
                                                <td class="text-warning">
                                                    <strong>₺<?php echo number_format($period->bonus_commission, 0, ',', '.'); ?></strong>
                                                </td>
                                                <td>₺<?php echo number_format($period->total_premium, 0, ',', '.'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="total-row">
                                            <td><strong>TOPLAM</strong></td>
                                            <td><strong><?php echo $sales_data['total_sales']; ?></strong></td>
                                            <td><strong>-</strong></td>
                                            <td class="text-success"><strong><?php echo !empty($sales_data['data']) ? array_sum(array_column($sales_data['data'], 'over_target')) : 0; ?></strong></td>
                                            <td class="text-warning">
                                                <strong>₺<?php echo number_format($sales_data['total_bonus_commission'], 0, ',', '.'); ?></strong>
                                            </td>
                                            <td><strong>₺<?php echo number_format($sales_data['total_premium'], 0, ',', '.'); ?></strong></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="no-data">Komisyon verisi bulunamadı.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Simple Reports CSS - Clean & User-Friendly */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

.simple-reports {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f8fafc;
    min-height: 100vh;
    padding: 20px;
}

/* Alert */
.simple-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px 20px;
    margin: 20px;
    border-radius: 8px;
    font-weight: 500;
}

.simple-alert.alert-error {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

/* Header */
.reports-header {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 20px;
}

.header-left h1 {
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
}

.header-left h1 i {
    color: #3b82f6;
    margin-right: 10px;
}

.user-info {
    font-size: 14px;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.role-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.role-badge.role-patron {
    background: #fef3c7;
    color: #d97706;
}

.role-badge.role-manager {
    background: #dbeafe;
    color: #2563eb;
}

.role-badge.role-representative {
    background: #d1fae5;
    color: #059669;
}

.header-filter {
    display: flex;
    align-items: center;
    gap: 10px;
}

.header-filter label {
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 5px;
}

.simple-select {
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: white;
    font-size: 14px;
    color: #374151;
    min-width: 200px;
    cursor: pointer;
}

.simple-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Quick Stats */
.quick-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-box {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.2s ease;
}

.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
}

.stat-icon.stat-sales {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
}

.stat-icon.stat-revenue {
    background: linear-gradient(135deg, #10b981, #059669);
}

.stat-icon.stat-customers {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}

.stat-icon.stat-target {
    background: linear-gradient(135deg, #ef4444, #dc2626);
}

.stat-info {
    flex: 1;
}

.stat-number {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1;
}

.stat-label {
    font-size: 14px;
    color: #6b7280;
    margin-top: 4px;
}

/* Navigation */
.reports-nav {
    margin-bottom: 20px;
}

.nav-tabs {
    display: flex;
    background: white;
    border-radius: 8px;
    padding: 4px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    overflow-x: auto;
}

.nav-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border: none;
    background: none;
    color: #6b7280;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.nav-tab:hover {
    background: #f3f4f6;
    color: #374151;
}

.nav-tab.active {
    background: #3b82f6;
    color: white;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.nav-tab i {
    font-size: 16px;
}

/* Content */
.reports-content {
    min-height: 500px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.content-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.content-row .full-width {
    grid-column: 1 / -1;
}

/* Cards */
.report-card {
    background: white;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
    background: #fafafa;
}

.card-header h3 {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-header h3 i {
    color: #3b82f6;
}

.card-actions {
    display: flex;
    gap: 8px;
}

.card-body {
    padding: 20px;
}

/* Metrics */
.metrics-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.metric {
    text-align: center;
    padding: 15px 10px;
    border-radius: 6px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
}

.metric.success {
    background: #ecfdf5;
    border-color: #d1fae5;
}

.metric.danger {
    background: #fef2f2;
    border-color: #fecaca;
}

.metric.warning {
    background: #fffbeb;
    border-color: #fed7aa;
}

.metric-value {
    display: block;
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1;
}

.metric-label {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Progress */
.progress-section {
    margin: 20px 0;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
}

.progress-bar {
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #1d4ed8);
    border-radius: 4px;
    transition: width 0.8s ease;
}

/* Achievement Circle */
.achievement-circle {
    display: flex;
    justify-content: center;
    margin: 20px 0;
}

.circle {
    position: relative;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: conic-gradient(from 0deg, #3b82f6 0%, #3b82f6 0%, #e5e7eb 0%);
    display: flex;
    align-items: center;
    justify-content: center;
}

.circle::before {
    content: '';
    position: absolute;
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: white;
}

.circle-text {
    position: relative;
    text-align: center;
    z-index: 1;
}

.circle-number {
    display: block;
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1;
}

.circle-label {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

/* Revenue Stats */
.revenue-stats {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.revenue-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}

.revenue-item:last-child {
    border-bottom: none;
}

.revenue-label {
    font-size: 14px;
    color: #6b7280;
}

.revenue-value {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
}

.revenue-value.bonus {
    color: #f59e0b;
}

/* Growth Comparison */
.growth-comparison {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}

.growth-item {
    text-align: center;
    flex: 1;
}

.growth-period {
    display: block;
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 4px;
}

.growth-number {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1;
}

.growth-label {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
}

.growth-arrow {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    font-weight: 600;
}

.growth-arrow.up {
    color: #059669;
}

.growth-arrow.down {
    color: #dc2626;
}

.growth-arrow i {
    font-size: 20px;
}

/* Summary Stats */
.summary-stats {
    display: flex;
    justify-content: space-around;
    gap: 20px;
    margin-bottom: 25px;
    padding: 20px;
    background: #f9fafb;
    border-radius: 6px;
}

.summary-item {
    text-align: center;
}

.summary-number {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1;
}

.summary-label {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
}

/* Commission Summary */
.commission-summary {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.commission-item {
    text-align: center;
    padding: 20px;
    background: #f9fafb;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.commission-label {
    display: block;
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 8px;
}

.commission-value {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    line-height: 1;
}

.commission-detail {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
}

/* Tables */
.table-wrapper {
    overflow-x: auto;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}

.simple-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

.simple-table th,
.simple-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.simple-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.simple-table tbody tr:hover {
    background: #f9fafb;
}

.simple-table .total-row {
    background: #f3f4f6;
    font-weight: 600;
}

.simple-table .total-row td {
    border-top: 2px solid #d1d5db;
    font-weight: 600;
}

/* Table Cell Styles */
.rep-cell {
    color: #6b7280;
    font-size: 13px;
}

.text-success {
    color: #059669;
}

.text-danger {
    color: #dc2626;
}

.text-warning {
    color: #d97706;
}

.no-data {
    text-align: center;
    padding: 40px;
    color: #9ca3af;
    font-style: italic;
}

/* Badges and Status */
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-kurumsal {
    background: #dbeafe;
    color: #1d4ed8;
}

.badge-bireysel {
    background: #fef3c7;
    color: #d97706;
}

.status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-aktif {
    background: #d1fae5;
    color: #065f46;
}

.status-pasif {
    background: #fee2e2;
    color: #991b1b;
}

.status-belirsiz {
    background: #fef3c7;
    color: #92400e;
}

.achievement {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.achievement.success {
    background: #d1fae5;
    color: #065f46;
}

.achievement.warning {
    background: #fef3c7;
    color: #92400e;
}

.achievement.danger {
    background: #fee2e2;
    color: #991b1b;
}

/* Charts */
.chart-container {
    height: 250px;
    margin: 20px 0;
}

.chart-container.large {
    height: 400px;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    white-space: nowrap;
}

.btn.btn-sm {
    padding: 6px 12px;
    font-size: 13px;
}

.btn.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn.btn-primary:hover {
    background: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.btn.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn.btn-secondary:hover {
    background: #4b5563;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.btn.btn-info {
    background: #06b6d4;
    color: white;
}

.btn.btn-info:hover {
    background: #0891b2;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .simple-reports {
        padding: 15px;
    }
    
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }
    
    .quick-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .content-row {
        grid-template-columns: 1fr;
    }
    
    .growth-comparison {
        flex-direction: column;
        gap: 15px;
    }
    
    .summary-stats {
        flex-direction: column;
        gap: 15px;
    }
}

@media (max-width: 768px) {
    .simple-reports {
        padding: 10px;
    }
    
    .reports-header {
        padding: 20px;
    }
    
    .header-left h1 {
        font-size: 24px;
    }
    
    .quick-stats {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .stat-box {
        padding: 15px;
    }
    
    .stat-number {
        font-size: 20px;
    }
    
    .nav-tabs {
        flex-direction: column;
        gap: 2px;
    }
    
    .nav-tab {
        justify-content: center;
    }
    
    .card-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .metrics-row {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .simple-table th,
    .simple-table td {
        padding: 8px 10px;
        font-size: 13px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}

@media (max-width: 480px) {
    .header-filter {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    
    .simple-select {
        min-width: auto;
    }
    
    .metrics-row {
        grid-template-columns: 1fr;
    }
    
    .summary-stats {
        padding: 15px;
    }
    
    .simple-table {
        font-size: 12px;
    }
    
    .simple-table th,
    .simple-table td {
        padding: 6px 8px;
    }
}

/* Print Styles */
@media print {
    .simple-reports {
        background: white;
        padding: 0;
    }
    
    .reports-nav,
    .action-buttons,
    .card-actions {
        display: none !important;
    }
    
    .tab-content {
        display: block !important;
    }
    
    .report-card {
        break-inside: avoid;
        margin-bottom: 20px;
        border: 1px solid #000;
    }
    
    .chart-container {
        height: 300px;
    }
}

/* Loading Overlay */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.loading-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #f3f4f6;
    border-top: 4px solid #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Notification Styles */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 10000;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 300px;
    animation: slideIn 0.3s ease;
}

.notification.success {
    border-left: 4px solid #059669;
    background: #ecfdf5;
}

.notification.error {
    border-left: 4px solid #dc2626;
    background: #fef2f2;
}

.notification.info {
    border-left: 4px solid #3b82f6;
    background: #eff6ff;
}

.notification-close {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 16px;
    cursor: pointer;
    color: #6b7280;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Accessibility */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Focus States */
.nav-tab:focus,
.btn:focus,
.simple-select:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}

/* Custom Scrollbar */
.table-wrapper::-webkit-scrollbar {
    height: 8px;
}

.table-wrapper::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<script>
// Simple Reports JavaScript - Clean & Functional
document.addEventListener('DOMContentLoaded', function() {
    // Chart Data
    const chartData = <?php echo json_encode($js_chart_data); ?>;
    let charts = {};
    let currentTab = 'dashboard';

    // Tab Navigation
    function initTabNavigation() {
        const tabButtons = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetTab = this.dataset.tab;
                
                // Remove active class from all tabs
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                const targetContent = document.getElementById(targetTab + '-content');
                if (targetContent) {
                    targetContent.classList.add('active');
                }
                
                // Update current tab and render charts
                currentTab = targetTab;
                renderCharts(targetTab);
                
                // Update URL
                const url = new URL(window.location);
                url.searchParams.set('tab', targetTab);
                window.history.pushState({}, '', url);
            });
        });
    }

    // Representative Filter
    function initRepresentativeFilter() {
        const repFilter = document.getElementById('representativeFilter');
        if (repFilter) {
            repFilter.addEventListener('change', function() {
                const selectedRep = this.value;
                const url = new URL(window.location);
                
                if (selectedRep) {
                    url.searchParams.set('representative_filter', selectedRep);
                } else {
                    url.searchParams.delete('representative_filter');
                }
                
                window.location.href = url.toString();
            });
        }
    }

    // Chart Creation
    function createChart(canvasId, type, data, options = {}) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        
        if (charts[canvasId]) {
            charts[canvasId].destroy();
        }
        
        const ctx = canvas.getContext('2d');
        
        charts[canvasId] = new Chart(ctx, {
            type: type,
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: '#e5e7eb',
                        borderWidth: 1,
                        cornerRadius: 6
                    }
                },
                ...options
            }
        });
    }

    // Render Charts
    function renderCharts(tabId) {
        setTimeout(() => {
            if (tabId === 'dashboard') {
                // Customer Distribution Chart
                if (chartData.dashboard_summary && chartData.dashboard_summary.data.length > 0) {
                    createChart('customerChart', 'doughnut', {
                        labels: chartData.dashboard_summary.labels,
                        datasets: [{
                            data: chartData.dashboard_summary.data,
                            backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    });
                }
            }
            
            if (tabId === 'sales') {
                // Sales Chart
                if (chartData.sales_performance && chartData.sales_performance.labels.length > 0) {
                    createChart('salesChart', 'line', {
                        labels: chartData.sales_performance.labels,
                        datasets: [
                            {
                                label: 'Satış Adedi',
                                data: chartData.sales_performance.sales_count,
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                tension: 0.3,
                                fill: true
                            },
                            {
                                label: 'Net Satış',
                                data: chartData.sales_performance.net_sales,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                tension: 0.3,
                                fill: true
                            }
                        ]
                    }, {
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: '#f3f4f6'
                                }
                            },
                            x: {
                                grid: {
                                    color: '#f3f4f6'
                                }
                            }
                        }
                    });
                }
            }
            
            if (tabId === 'commissions') {
                // Bonus Chart
                if (chartData.sales_performance && chartData.sales_performance.labels.length > 0) {
                    createChart('bonusChart', 'bar', {
                        labels: chartData.sales_performance.labels,
                        datasets: [{
                            label: 'Bonus Komisyon (₺)',
                            data: chartData.sales_performance.bonus_commission,
                            backgroundColor: '#f59e0b',
                            borderColor: '#d97706',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    }, {
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: '#f3f4f6'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    });
                }
            }
        }, 100);
    }

    // Achievement Circle Animation
    function animateAchievementCircle() {
        const circles = document.querySelectorAll('.circle-fill');
        circles.forEach(circle => {
            const percent = circle.dataset.percent || 0;
            const parentCircle = circle.closest('.circle');
            if (parentCircle) {
                const deg = (percent / 100) * 360;
                parentCircle.style.background = `conic-gradient(from 0deg, #3b82f6 ${deg}deg, #e5e7eb ${deg}deg)`;
            }
        });
    }

    // Export Functions
    window.exportToPNG = function(elementId) {
        const element = document.getElementById(elementId) || document.querySelector('.tab-content.active');
        if (!element) {
            showNotification('Dışa aktarılacak içerik bulunamadı.', 'error');
            return;
        }
        
        const loading = createLoadingOverlay();
        document.body.appendChild(loading);
        
        setTimeout(() => {
            html2canvas(element, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff'
            }).then(canvas => {
                const link = document.createElement('a');
                const timestamp = new Date().toISOString().split('T')[0];
                link.download = `rapor_${currentTab}_${timestamp}.png`;
                link.href = canvas.toDataURL();
                link.click();
                
                document.body.removeChild(loading);
                showNotification('PNG başarıyla indirildi!', 'success');
            }).catch(error => {
                console.error('PNG export error:', error);
                document.body.removeChild(loading);
                showNotification('PNG dışa aktarma sırasında hata oluştu.', 'error');
            });
        }, 500);
    };

    window.exportToCSV = function(type) {
        const loading = createLoadingOverlay();
        document.body.appendChild(loading);
        
        const formData = new FormData();
        formData.append('action', 'export_reports_csv');
        formData.append('type', type);
        formData.append('nonce', '<?php echo wp_create_nonce("export_reports_csv"); ?>');
        
        const repFilter = document.getElementById('representativeFilter');
        if (repFilter && repFilter.value) {
            formData.append('representative_filter', repFilter.value);
        }
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            document.body.removeChild(loading);
            
            if (data.success) {
                const blob = new Blob([data.data], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                const timestamp = new Date().toISOString().split('T')[0];
                link.href = url;
                link.download = `${type}_raporu_${timestamp}.csv`;
                link.click();
                window.URL.revokeObjectURL(url);
                
                showNotification('CSV başarıyla indirildi!', 'success');
            } else {
                showNotification('CSV dışa aktarma hatası: ' + (data.data || 'Bilinmeyen hata'), 'error');
            }
        })
        .catch(error => {
            console.error('CSV export error:', error);
            document.body.removeChild(loading);
            showNotification('CSV dışa aktarma işlemi başarısız oldu.', 'error');
        });
    };

    window.printReport = function() {
        window.print();
    };

    // Helper Functions
    function createLoadingOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.innerHTML = '<div class="loading-spinner"></div>';
        return overlay;
    }

    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existing = document.querySelectorAll('.notification');
        existing.forEach(n => n.remove());
        
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const icon = type === 'success' ? 'fa-check-circle' : 
                    type === 'error' ? 'fa-exclamation-circle' : 
                    'fa-info-circle';
        
        notification.innerHTML = `
            <i class="fas ${icon}"></i>
            <span>${message}</span>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }

    // Window resize handler
    function handleResize() {
        Object.values(charts).forEach(chart => {
            if (chart && chart.resize) {
                chart.resize();
            }
        });
    }

    // Initialize everything
    function init() {
        initTabNavigation();
        initRepresentativeFilter();
        renderCharts(currentTab);
        animateAchievementCircle();
        
        // Set initial tab from URL
        const urlParams = new URLSearchParams(window.location.search);
        const tabFromUrl = urlParams.get('tab');
        if (tabFromUrl) {
            const tabButton = document.querySelector(`[data-tab="${tabFromUrl}"]`);
            if (tabButton) {
                tabButton.click();
            }
        }
    }

    // Event listeners
    window.addEventListener('resize', handleResize);

    // Start initialization
    init();
});
</script>

<?php
// Enhanced CSV Export Handler
add_action('wp_ajax_export_reports_csv', 'handle_export_reports_csv');

function handle_export_reports_csv() {
    // Security check
    if (!wp_verify_nonce($_POST['nonce'], 'export_reports_csv')) {
        wp_send_json_error('Güvenlik kontrolü başarısız');
        return;
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Oturum süresi dolmuş');
        return;
    }
    
    $type = sanitize_text_field($_POST['type']);
    $user_permissions = get_user_role_and_permissions();
    
    // Get selected representative for patron users
    $selected_representative = '';
    if ($user_permissions['can_see_all'] && isset($_POST['representative_filter'])) {
        $selected_representative = sanitize_text_field($_POST['representative_filter']);
    }
    
    $csv_data = '';
    $headers = [];
    $rows = [];
    
    try {
        switch ($type) {
            case 'dashboard':
                $headers = ['Metrik', 'Değer', 'Durum'];
                $dashboard_data = get_customer_portfolio_summary($user_permissions, $selected_representative);
                $sales_data = get_sales_performance_data($user_permissions, $selected_representative);
                
                $rows = [
                    ['Toplam Müşteri', $dashboard_data['total'], 'Aktif'],
                    ['Aktif Müşteri', $dashboard_data['active'], 'Pozitif'],
                    ['Pasif Müşteri', $dashboard_data['inactive'], 'Negatif'],
                    ['Net Satış', $sales_data['net_sales'], 'Aktif'],
                    ['Net Prim', number_format($sales_data['net_premium'], 2), 'Aktif'],
                    ['Hedef Başarı', $sales_data['achievement_rate'] . '%', $sales_data['achievement_rate'] >= 100 ? 'Başarılı' : 'Gelişim'],
                    ['Bonus Komisyon', number_format($sales_data['total_bonus_commission'], 2), 'Aktif']
                ];
                break;
                
            case 'customers':
                $headers = ['Ad Soyad', 'Kategori', 'Durum', 'Poliçe Sayısı', 'Toplam Prim', 'Kayıt Tarihi'];
                if ($user_permissions['can_see_all']) {
                    array_splice($headers, 1, 0, ['Temsilci']);
                }
                
                $data = get_customer_portfolio_data($user_permissions, $selected_representative);
                foreach ($data as $customer) {
                    $row = [
                        $customer->first_name . ' ' . $customer->last_name,
                        ucfirst($customer->category),
                        ucfirst($customer->status),
                        $customer->policy_count,
                        number_format($customer->total_premium, 2),
                        date('d.m.Y', strtotime($customer->created_at))
                    ];
                    
                    if ($user_permissions['can_see_all'] && isset($customer->rep_first_name)) {
                        array_splice($row, 1, 0, [$customer->rep_first_name . ' ' . $customer->rep_last_name]);
                    }
                    
                    $rows[] = $row;
                }
                break;
                
            case 'sales':
                $headers = ['Dönem', 'Satış', 'İptal', 'Net', 'Toplam Prim', 'Bonus', 'Hedef Başarı', 'Yeni Müşteri'];
                $data = get_sales_performance_data($user_permissions, $selected_representative);
                
                foreach ($data['data'] as $period) {
                    $rows[] = [
                        $period->period_tr,
                        $period->sales_count,
                        $period->cancelled,
                        $period->net_sales,
                        number_format($period->total_premium, 2),
                        number_format($period->bonus_commission, 2),
                        $period->target_achievement . '%',
                        $period->new_customers
                    ];
                }
                
                // Add totals row
                $rows[] = [
                    'TOPLAM',
                    $data['total_sales'],
                    $data['total_cancelled'],
                    $data['net_sales'],
                    number_format($data['total_premium'], 2),
                    number_format($data['total_bonus_commission'], 2),
                    $data['achievement_rate'] . '%',
                    $data['total_new_customers']
                ];
                break;
                
            case 'commissions':
                $headers = ['Dönem', 'Satış', 'Hedef', 'Hedef Üstü', 'Bonus (₺)', 'Toplam Prim (₺)'];
                $data = get_sales_performance_data($user_permissions, $selected_representative);
                
                foreach ($data['data'] as $period) {
                    $rows[] = [
                        $period->period_tr,
                        $period->sales_count,
                        10,
                        $period->over_target,
                        number_format($period->bonus_commission, 2),
                        number_format($period->total_premium, 2)
                    ];
                }
                
                // Add totals row
                $rows[] = [
                    'TOPLAM',
                    $data['total_sales'],
                    '-',
                    array_sum(array_column($data['data'], 'over_target')),
                    number_format($data['total_bonus_commission'], 2),
                    number_format($data['total_premium'], 2)
                ];
                break;
                
            default:
                wp_send_json_error('Geçersiz export tipi');
                return;
        }
        
        // Create CSV content
        $csv_content = '';
        
        // Add UTF-8 BOM for Excel compatibility
        $csv_content .= "\xEF\xBB\xBF";
        
        // Add headers
        $csv_content .= implode(',', array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, $headers)) . "\r\n";
        
        // Add data rows
        foreach ($rows as $row) {
            $csv_content .= implode(',', array_map(function($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\r\n";
        }
        
        // Add metadata footer
        $csv_content .= "\r\n";
        $csv_content .= "\"Export Bilgileri:\"\r\n";
        $csv_content .= "\"Tarih:\",\"" . date('d.m.Y H:i:s') . "\"\r\n";
        $csv_content .= "\"Kullanıcı:\",\"" . wp_get_current_user()->display_name . "\"\r\n";
        $csv_content .= "\"Rol:\",\"" . ucfirst($user_permissions['role']) . "\"\r\n";
        
        if ($selected_representative) {
            global $wpdb;
            $rep_name = $wpdb->get_var($wpdb->prepare(
                "SELECT CONCAT(first_name, ' ', last_name) FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
                $selected_representative
            ));
            if ($rep_name) {
                $csv_content .= "\"Seçilen Temsilci:\",\"" . $rep_name . "\"\r\n";
            }
        }
        
        // Log the export activity
        error_log("CSV Export: {$type} by user " . get_current_user_id() . " at " . date('Y-m-d H:i:s'));
        
        wp_send_json_success($csv_content);
        
    } catch (Exception $e) {
        error_log("CSV Export Error: " . $e->getMessage());
        wp_send_json_error('Veri işleme hatası: ' . $e->getMessage());
    }
}
?>
