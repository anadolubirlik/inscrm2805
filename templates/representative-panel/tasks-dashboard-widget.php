<?php
/**
 * Görev Özeti Widget Modülü
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel/modules/task-management
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ekip veya temsilci görevlerini getir
global $wpdb;
global $current_user;

// Bugünkü, yarınki, bu haftaki ve bu ayki görevleri al
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');
$tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));
$tomorrow_end = date('Y-m-d 23:59:59', strtotime('+1 day'));
$week_start = date('Y-m-d 00:00:00', strtotime('this week'));
$week_end = date('Y-m-d 23:59:59', strtotime('this week +6 days'));
$month_start = date('Y-m-01 00:00:00');
$month_end = date('Y-m-t 23:59:59');

// Temsilci ID'leri (ekip lideri için tüm takım, normal temsilci için sadece kendi)
$rep_ids = get_dashboard_representatives($current_user->ID, $current_view);

// Yaklaşan görevler
$placeholders = implode(',', array_fill(0, count($rep_ids), '%d'));

$today_tasks = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) 
     FROM {$wpdb->prefix}insurance_crm_tasks 
     WHERE representative_id IN ($placeholders) 
     AND due_date BETWEEN %s AND %s 
     AND status != 'completed'",
    array_merge($rep_ids, [$today_start, $today_end])
));

$tomorrow_tasks = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) 
     FROM {$wpdb->prefix}insurance_crm_tasks 
     WHERE representative_id IN ($placeholders) 
     AND due_date BETWEEN %s AND %s 
     AND status != 'completed'",
    array_merge($rep_ids, [$tomorrow_start, $tomorrow_end])
));

$week_tasks = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) 
     FROM {$wpdb->prefix}insurance_crm_tasks 
     WHERE representative_id IN ($placeholders) 
     AND due_date BETWEEN %s AND %s 
     AND due_date NOT BETWEEN %s AND %s 
     AND due_date NOT BETWEEN %s AND %s 
     AND status != 'completed'",
    array_merge($rep_ids, [$week_start, $week_end, $today_start, $today_end, $tomorrow_start, $tomorrow_end])
));

$month_tasks = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) 
     FROM {$wpdb->prefix}insurance_crm_tasks 
     WHERE representative_id IN ($placeholders) 
     AND due_date BETWEEN %s AND %s 
     AND due_date NOT BETWEEN %s AND %s 
     AND status != 'completed'",
    array_merge($rep_ids, [$month_start, $month_end, $week_start, $week_end])
));

// Yaklaşan acil görevler (önümüzdeki 3 gün)
$urgent_date = date('Y-m-d 23:59:59', strtotime('+3 days'));
$urgent_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, c.first_name, c.last_name 
     FROM {$wpdb->prefix}insurance_crm_tasks t
     LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
     WHERE t.representative_id IN ($placeholders) 
     AND t.due_date <= %s 
     AND t.status != 'completed'
     ORDER BY t.priority DESC, t.due_date ASC
     LIMIT 5",
    array_merge($rep_ids, [$urgent_date])
));

?>

<div class="dashboard-card task-summary-card">
    <div class="card-header">
        <h3><i class="fas fa-tasks"></i> Görev Özeti</h3>
        <div class="card-actions">
            <a href="<?php echo generate_panel_url('tasks'); ?>" class="text-button">Tümünü Gör</a>
            <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="card-option" title="Yeni Görev">
                <i class="dashicons dashicons-plus-alt"></i>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="tasks-summary-grid">
            <div class="task-summary-card today">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar-alt"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $today_tasks; ?></h3>
                    <p>Bugünkü Görev</p>
                </div>
                <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'today')); ?>" class="task-summary-link">Görüntüle</a>
            </div>
            
            <div class="task-summary-card tomorrow">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $tomorrow_tasks; ?></h3>
                    <p>Yarınki Görev</p>
                </div>
                <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'tomorrow')); ?>" class="task-summary-link">Görüntüle</a>
            </div>
            
            <div class="task-summary-card this-week">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar-alt"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $week_tasks; ?></h3>
                    <p>Bu Hafta Görev</p>
                </div>
                <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'this_week')); ?>" class="task-summary-link">Görüntüle</a>
            </div>
            
            <div class="task-summary-card this-month">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $month_tasks; ?></h3>
                    <p>Bu Ay Görev</p>
                </div>
                <a href="<?php echo generate_panel_url('tasks', '', '', array('filter' => 'this_month')); ?>" class="task-summary-link">Görüntüle</a>
            </div>
        </div>
        
        <?php if (!empty($urgent_tasks)): ?>
        <div class="urgent-tasks">
            <h4>Yaklaşan Acil Görevler</h4>
            <?php foreach ($urgent_tasks as $task): 
                $priority_class = '';
                switch ($task->priority) {
                    case 'high':
                        $priority_class = 'very-urgent';
                        break;
                    case 'medium':
                        $priority_class = 'urgent';
                        break;
                    default:
                        $priority_class = 'normal';
                }
                
                $due_date = new DateTime($task->due_date);
            ?>
                <div class="urgent-task-item <?php echo $priority_class; ?>">
                    <div class="task-date">
                        <div class="date-number"><?php echo $due_date->format('d'); ?></div>
                        <div class="date-month"><?php echo $due_date->format('M'); ?></div>
                    </div>
                    <div class="task-details">
                        <h5><?php echo esc_html($task->title); ?></h5>
                        <p>
                            <?php if (!empty($task->customer_id)): ?>
                                <strong><?php echo esc_html($task->first_name . ' ' . $task->last_name); ?></strong> - 
                            <?php endif; ?>
                            <?php echo wp_trim_words(esc_html($task->description), 10, '...'); ?>
                        </p>
                    </div>
                    <div class="task-action">
                        <a href="<?php echo generate_panel_url('tasks', 'view', $task->id); ?>" class="view-task-btn">
                            Görüntüle
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-tasks-message">
            <div class="empty-icon"><i class="dashicons dashicons-yes"></i></div>
            <p>Yaklaşan göreviniz bulunmuyor.</p>
            <a href="<?php echo generate_panel_url('tasks', 'new'); ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Yeni Görev Ekle
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>