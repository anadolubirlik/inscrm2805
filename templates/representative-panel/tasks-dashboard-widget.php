<div class="dashboard-card tasks-summary-card">
    <div class="card-header">
        <h3><?php echo $current_view == 'team' ? 'Ekip Görev Özeti' : 'Görev Özeti'; ?></h3>
        <div class="card-actions">
            <a href="?view=tasks" class="text-button">Tüm Görevler</a>
            <a href="?view=tasks&action=new" class="card-option" title="Yeni Görev">
                <i class="dashicons dashicons-plus-alt"></i>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="tasks-summary-grid">
            <?php
            // Bugün için görevler
            $today = date('Y-m-d');
            $today_tasks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
                WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                AND status IN ('pending', 'in_progress')
                AND DATE(due_date) = %s",
                ...array_merge($rep_ids, [$today])
            ));
            
            // Yarın için görevler
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $tomorrow_tasks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
                WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                AND status IN ('pending', 'in_progress')
                AND DATE(due_date) = %s",
                ...array_merge($rep_ids, [$tomorrow])
            ));
            
            // Bu hafta için görevler (bugün ve yarın hariç)
            $week_start = date('Y-m-d', strtotime('+2 day'));
            $week_end = date('Y-m-d', strtotime('Sunday this week'));
            if ($week_start > $week_end) {
                $week_end = date('Y-m-d', strtotime('Sunday next week'));
            }
            
            $week_tasks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
                WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                AND status IN ('pending', 'in_progress')
                AND DATE(due_date) BETWEEN %s AND %s",
                ...array_merge($rep_ids, [$week_start, $week_end])
            ));
            
            // Bu ay için görevler (bu hafta hariç)
            $month_start = date('Y-m-d', strtotime('Monday next week'));
            $month_end = date('Y-m-t');
            $month_tasks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_tasks
                WHERE representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ")
                AND status IN ('pending', 'in_progress')
                AND DATE(due_date) BETWEEN %s AND %s",
                ...array_merge($rep_ids, [$month_start, $month_end])
            ));
            ?>
            
            <div class="task-summary-card today">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar-alt"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $today_tasks; ?></h3>
                    <p>Bugünkü Görev</p>
                </div>
                <a href="?view=tasks&due_date=<?php echo $today; ?>" class="task-summary-link">Görüntüle</a>
            </div>
            
            <div class="task-summary-card tomorrow">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $tomorrow_tasks; ?></h3>
                    <p>Yarınki Görev</p>
                </div>
                <a href="?view=tasks&due_date=<?php echo $tomorrow; ?>" class="task-summary-link">Görüntüle</a>
            </div>
            
            <div class="task-summary-card this-week">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-calendar-alt"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $week_tasks; ?></h3>
                    <p>Bu Hafta Görev</p>
                </div>
                <a href="?view=tasks" class="task-summary-link">Görüntüle</a>
            </div>
            
            <div class="task-summary-card this-month">
                <div class="task-summary-icon">
                    <i class="dashicons dashicons-analytics"></i>
                </div>
                <div class="task-summary-content">
                    <h3><?php echo $month_tasks; ?></h3>
                    <p>Bu Ay Görev</p>
                </div>
                <a href="?view=tasks" class="task-summary-link">Görüntüle</a>
            </div>
        </div>
        
        <?php 
        // En acil 3 görevi listele
        $urgent_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, c.first_name, c.last_name 
            FROM {$wpdb->prefix}insurance_crm_tasks t
            LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
            WHERE t.representative_id IN (" . implode(',', array_fill(0, count($rep_ids), '%d')) . ") 
            AND t.status IN ('pending', 'in_progress')
            AND t.due_date >= CURDATE()
            ORDER BY t.due_date ASC
            LIMIT 3",
            ...$rep_ids
        ));
        
        if (!empty($urgent_tasks)): ?>
        <div class="urgent-tasks">
            <h4>Acil Görevler</h4>
            <?php foreach ($urgent_tasks as $task): 
                $days_remaining = ceil((strtotime($task->due_date) - time()) / 86400);
                $urgency_class = $days_remaining <= 1 ? 'very-urgent' : ($days_remaining <= 3 ? 'urgent' : 'normal');
            ?>
                <div class="urgent-task-item <?php echo $urgency_class; ?>">
                    <div class="task-date">
                        <div class="date-number"><?php echo date('d', strtotime($task->due_date)); ?></div>
                        <div class="date-month"><?php echo date_i18n('M', strtotime($task->due_date)); ?></div>
                    </div>
                    <div class="task-details">
                        <h5><?php echo esc_html($task->task_description); ?></h5>
                        <?php if ($task->first_name || $task->last_name): ?>
                            <p>Müşteri: <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="task-action">
                        <a href="?view=tasks&action=edit&id=<?php echo $task->id; ?>" class="view-task-btn">Görüntüle</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty-tasks-message">
                <div class="empty-icon">
                    <i class="dashicons dashicons-yes-alt"></i>
                </div>
                <p>Yaklaşan göreviniz bulunmuyor.</p>
                <a href="?view=tasks&action=new" class="button button-primary">Yeni Görev Ekle</a>
            </div>
        <?php endif; ?>
    </div>
</div>
