<?php
/**
 * Görev Ekleme/Düzenleme Formu
 * @version 7.2.2
 */

// Veritabanı kontrolü ve task_title alanı ekleme
function insurance_crm_check_tasks_db_structure() {
    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_tasks';
        
        // Tablonun varlığını kontrol et
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            // task_title sütununun varlığını kontrol et
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'task_title'",
                DB_NAME, 
                $table_name
            ));
            
            // Eğer task_title alanı yoksa ekle
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN task_title VARCHAR(255) AFTER id");
            }
        }
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DB Structure Check Error: ' . $e->getMessage());
        }
    }
}

// Poliçe tablosuna insurance_company sütunu ekleme
function insurance_crm_check_policies_db_structure() {
    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_policies';
        
        // Tablonun varlığını kontrol et
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        if ($table_exists) {
            // insurance_company sütununun varlığını kontrol et
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'insurance_company'",
                DB_NAME, 
                $table_name
            ));
            
            // Eğer insurance_company alanı yoksa ekle
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN insurance_company VARCHAR(100) NULL AFTER policy_type");
            }
        }
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Policies DB Structure Check Error: ' . $e->getMessage());
        }
    }
}

// Veritabanı yapılarını kontrol et
insurance_crm_check_tasks_db_structure();
insurance_crm_check_policies_db_structure();

// Renk ayarlarını dahil et
include_once(dirname(__FILE__) . '/template-colors.php');

// Yetki kontrolü
if (!is_user_logged_in()) {
    return;
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$task_id = $editing ? intval($_GET['id']) : 0;

// Müşteri ID'si veya Poliçe ID'si varsa form açılışında seçili gelsin
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$selected_policy_id = isset($_GET['policy_id']) ? intval($_GET['policy_id']) : 0;

// Yenileme görevi ise parametreleri al
$task_type = isset($_GET['task_type']) ? sanitize_text_field($_GET['task_type']) : '';

// Mevcut kullanıcı bilgilerini ve rolünü al
$current_user_id = get_current_user_id();
$current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;

// Kullanıcının rolünü belirle (patron, müdür, ekip lideri)
$is_patron = function_exists('is_patron') ? is_patron($current_user_id) : false;
$is_manager = function_exists('is_manager') ? is_manager($current_user_id) : false;
$is_team_leader = function_exists('is_team_leader') ? is_team_leader($current_user_id) : false;

// Form gönderildiğinde işlem yap
if (isset($_POST['save_task']) && isset($_POST['task_nonce']) && wp_verify_nonce($_POST['task_nonce'], 'save_task')) {
    
    // Görevi düzenleyecek kişinin yetkisi var mı?
    $can_edit = true;
    
    // Görev verileri
    $task_data = array(
        'task_title' => sanitize_text_field($_POST['task_title']),
        'customer_id' => intval($_POST['customer_id']),
        'policy_id' => !empty($_POST['policy_id']) ? intval($_POST['policy_id']) : null,
        'task_description' => sanitize_textarea_field($_POST['task_description']),
        'due_date' => sanitize_text_field($_POST['due_date']),
        'priority' => sanitize_text_field($_POST['priority']),
        'status' => sanitize_text_field($_POST['status']),
        'representative_id' => !empty($_POST['representative_id']) ? intval($_POST['representative_id']) : null
    );
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_tasks';
    
    // Temsilci kontrolü - temsilciyse ve temsilci seçilmediyse kendi ID'sini ekle
    if (!$is_patron && !$is_manager && !$is_team_leader && empty($task_data['representative_id']) && $current_user_rep_id) {
        $task_data['representative_id'] = $current_user_rep_id;
    }
    
    if ($editing) {
        // Yetki kontrolü
        $is_admin = current_user_can('administrator') || current_user_can('insurance_manager');
        
        if (!$is_admin && !$is_patron && !$is_manager) {
            $task_check = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d", $task_id
            ));
            
            if ($task_check && $task_check->representative_id != $current_user_rep_id && 
                (!$is_team_leader || !in_array($task_check->representative_id, get_team_members($current_user_id)))) {
                $can_edit = false;
                $message = 'Bu görevi düzenleme yetkiniz yok.';
                $message_type = 'error';
            }
        }
        
        if ($can_edit) {
            $task_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $task_data, ['id' => $task_id]);
            
            if ($result !== false) {
                $message = 'Görev başarıyla güncellendi.';
                $message_type = 'success';
                
                // Başarılı işlemden sonra yönlendirme
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=tasks&updated=true";</script>';
                exit;
            } else {
                $message = 'Görev güncellenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } else {
        // Yeni görev ekle
        $task_data['created_at'] = current_time('mysql');
        $task_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $task_data);
        
        if ($result !== false) {
            $new_task_id = $wpdb->insert_id;
            $message = 'Görev başarıyla eklendi.';
            $message_type = 'success';
            
            // Başarılı işlemden sonra yönlendirme
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            echo '<script>window.location.href = "?view=tasks&added=true";</script>';
            exit;
        } else {
            $message = 'Görev eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    }
}

// Görevi düzenlenecek verilerini al
$task = null;
if ($editing) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $task_id));
    
    if (!$task) {
        echo '<div class="ab-notice ab-error">Görev bulunamadı.</div>';
        return;
    }
    
    // Yetki kontrolü (temsilci sadece kendi görevlerini düzenleyebilir)
    if (!current_user_can('administrator') && !current_user_can('insurance_manager') && !$is_patron && !$is_manager) {
        if ($task->representative_id != $current_user_rep_id && 
            (!$is_team_leader || !in_array($task->representative_id, get_team_members($current_user_id)))) {
            echo '<div class="ab-notice ab-error">Bu görevi düzenleme yetkiniz yok.</div>';
            return;
        }
    }
    
    // Eğer düzenleme modundaysa müşteri ID'sini alalım
    $selected_customer_id = $task->customer_id;
    $selected_policy_id = $task->policy_id;
}

// Görev türüne göre varsayılan değerleri ayarla
$default_task_title = '';
$default_task_description = '';

// Son tarih varsayılan olarak 3 gün sonrası
$default_due_date = date('Y-m-d\TH:i', strtotime('+3 days'));
$default_priority = 'medium';

// Eğer poliçe yenileme görevi ise
if ($task_type === 'renewal' && !empty($selected_policy_id)) {
    global $wpdb;
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    
    $policy = $wpdb->get_row($wpdb->prepare("
        SELECT p.*, c.first_name, c.last_name 
        FROM $policies_table p
        LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON p.customer_id = c.id
        WHERE p.id = %d
    ", $selected_policy_id));
    
    if ($policy) {
        $selected_customer_id = $policy->customer_id;
        $default_task_title = "Poliçe Yenileme: {$policy->policy_number}";
        $default_task_description = "Poliçe yenileme hatırlatması: {$policy->policy_number}\n\nMüşteri: {$policy->first_name} {$policy->last_name}\nPoliçe No: {$policy->policy_number}\nPoliçe Türü: {$policy->policy_type}";
        
        // Son tarih, poliçe bitiş tarihinden 1 hafta önce olsun
        $due_date = new DateTime($policy->end_date);
        $due_date->modify('-1 week');
        $default_due_date = $due_date->format('Y-m-d\TH:i');
        
        // Öncelik "yüksek" olsun
        $default_priority = 'high';
    }
}

// Seçili müşteri bilgilerini getir
$selected_customer_name = '';
if ($selected_customer_id > 0) {
    global $wpdb;
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    
    // tc_kimlik_no sütunu var mı kontrol et
    $has_tc_column = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'tc_kimlik_no'",
        DB_NAME, $customers_table
    ));
    
    $customer = $wpdb->get_row($wpdb->prepare("
        SELECT first_name, last_name, phone" . ($has_tc_column ? ", tc_kimlik_no" : "") . "
        FROM $customers_table 
        WHERE id = %d
    ", $selected_customer_id));
    
    if ($customer) {
        $selected_customer_name = $customer->first_name . ' ' . $customer->last_name . 
                                (!empty($customer->phone) ? ' (' . $customer->phone . ')' : '') .
                                (!empty($customer->tc_kimlik_no) ? ' [TC: ' . $customer->tc_kimlik_no . ']' : '');
    }
}

// Tüm müşterileri al (dropdown ve filtreleme için)
$all_customers = [];
$all_customers_data = [];
$customers_table = $wpdb->prefix . 'insurance_crm_customers';

// tc_kimlik_no ve status sütunlarını kontrol et
$has_tc_column = $wpdb->get_results($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'tc_kimlik_no'",
    DB_NAME, $customers_table
));
$has_status_column = $wpdb->get_results($wpdb->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
    DB_NAME, $customers_table
));

$status_condition = $has_status_column ? " WHERE status = 'aktif'" : "";
$customers = $wpdb->get_results(
    "SELECT id, first_name, last_name, phone" . ($has_tc_column ? ", tc_kimlik_no" : "") . " 
     FROM $customers_table" . $status_condition . "
     ORDER BY first_name, last_name
     LIMIT 100"
);

if ($customers) {
    foreach ($customers as $customer) {
        $customer_name = $customer->first_name . ' ' . $customer->last_name;
        $customer_phone = !empty($customer->phone) ? ' (' . $customer->phone . ')' : '';
        $customer_tc = !empty($customer->tc_kimlik_no) ? ' [TC: ' . $customer->tc_kimlik_no . ']' : '';
        $display_name = $customer_name . $customer_phone . $customer_tc;
        
        $all_customers[$customer->id] = $display_name;
        $all_customers_data[$customer->id] = [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'phone' => $customer->phone,
            'tc_kimlik_no' => !empty($customer->tc_kimlik_no) ? $customer->tc_kimlik_no : ''
        ];
    }
}

// Hata ayıklama için müşteri listesini log’la
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Dropdown müşteri sayısı: ' . count($all_customers));
    error_log('Son SQL sorgusu (dropdown): ' . $wpdb->last_query);
    if ($wpdb->last_error) {
        error_log('SQL Hatası (dropdown): ' . $wpdb->last_error);
    }
}

// Tüm poliçeleri al (önyüze gömmek için)
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$all_policies = [];
if (!empty($all_customers)) {
    $customer_ids = array_keys($all_customers);
    $placeholder = implode(',', array_fill(0, count($customer_ids), '%d'));
    
    $policies = $wpdb->get_results($wpdb->prepare(
        "SELECT id, customer_id, policy_number, COALESCE(policy_type, '') AS policy_type, 
                COALESCE(insurance_company, '') AS insurance_company, start_date, end_date
         FROM $policies_table 
         WHERE customer_id IN ($placeholder) AND status != 'iptal'
         ORDER BY id DESC",
        $customer_ids
    ));
    
    // Poliçeleri müşteri ID’sine göre gruplandır
    foreach ($policies as $policy) {
        $all_policies[$policy->customer_id][] = [
            'id' => $policy->id,
            'policy_number' => $policy->policy_number,
            'policy_type' => $policy->policy_type,
            'insurance_company' => $policy->insurance_company,
            'start_date' => date('d.m.Y', strtotime($policy->start_date)),
            'end_date' => date('d.m.Y', strtotime($policy->end_date))
        ];
    }
    
    // Hata ayıklama için poliçe log’ları
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Toplam poliçe sayısı: ' . count($policies));
        error_log('Son poliçe SQL sorgusu: ' . $wpdb->last_query);
        if ($wpdb->last_error) {
            error_log('Poliçe SQL Hatası: ' . $wpdb->last_error);
        }
    }
}

// Temsilcileri rolüne göre filtrele
$representatives = [];
$reps_table = $wpdb->prefix . 'insurance_crm_representatives';

if ($is_patron || $is_manager || current_user_can('administrator')) {
    // Patron ve müdürler tüm temsilcileri görebilir
    $representatives = $wpdb->get_results("
        SELECT r.id, u.display_name 
        FROM $reps_table r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name ASC
    ");
} elseif ($is_team_leader) {
    // Ekip lideri sadece kendi ekibindeki üyeleri görebilir
    $team_members = get_team_members($current_user_id);
    
    if (!empty($team_members) && is_array($team_members)) {
        // Ekip üyesi temsilcileri al
        $placeholder = implode(',', array_fill(0, count($team_members), '%d'));
        
        // PHP versiyon uyumluluğu
        $query = $wpdb->prepare(
            "SELECT r.id, u.display_name 
            FROM $reps_table r
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE r.status = 'active' AND r.id IN ($placeholder)
            ORDER BY u.display_name ASC",
            $team_members
        );
        
        $representatives = $wpdb->get_results($query);
        
        // Ekip liderini de ekle
        if ($current_user_rep_id) {
            $leader = $wpdb->get_row($wpdb->prepare(
                "SELECT r.id, u.display_name 
                FROM $reps_table r
                LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                WHERE r.status = 'active' AND r.id = %d",
                $current_user_rep_id
            ));
            
            if ($leader) {
                array_unshift($representatives, $leader);
            }
        }
    }
} else {
    // Normal temsilciler sadece kendilerini görebilir
    if ($current_user_rep_id) {
        $representatives = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, u.display_name 
            FROM $reps_table r
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            WHERE r.status = 'active' AND r.id = %d
            ORDER BY u.display_name ASC",
            $current_user_rep_id
        ));
    }
}

// Varsayılan görevli temsilci belirle
$default_representative_id = 0;

// Eğer düzenlemede mevcut temsilci varsa onu kullan
if ($editing && !empty($task->representative_id)) {
    $default_representative_id = $task->representative_id;
}
// Yeni görev ise ve normal temsilci ise kendisini otomatik seç
elseif (!$editing && !$is_patron && !$is_manager && !$is_team_leader && $current_user_rep_id) {
    $default_representative_id = $current_user_rep_id;
}

// Öncelik renkleri
$priority_colors = [
    'low' => ['bg' => '#e6ffed', 'text' => '#22863a', 'border' => '#c8e1cb'],
    'medium' => ['bg' => '#fff8e5', 'text' => '#bf8700', 'border' => '#f4d8a0'],
    'high' => ['bg' => '#ffeef0', 'text' => '#cb2431', 'border' => '#f4b7bc'],
    'urgent' => ['bg' => '#800020', 'text' => '#ffffff', 'border' => '#660018']
];

// Aktif öncelik rengini seç
$current_priority = $editing && isset($task->priority) ? $task->priority : $default_priority;
$active_priority_bg = isset($priority_colors[$current_priority]) ? $priority_colors[$current_priority]['bg'] : '#ffffff';
$active_priority_text = isset($priority_colors[$current_priority]) ? $priority_colors[$current_priority]['text'] : '#333333';
$active_priority_border = isset($priority_colors[$current_priority]) ? $priority_colors[$current_priority]['border'] : '#ddd';
?>

<div class="ab-task-form-container">
    <div class="ab-form-header">
        <h2>
            <?php if ($editing): ?>
                <i class="fas fa-edit"></i> Görev Düzenle
            <?php else: ?>
                <i class="fas fa-plus-circle"></i> Yeni Görev Ekle
            <?php endif; ?>
        </h2>
        <a href="?view=tasks" class="ab-btn ab-btn-secondary">
            <i class="fas fa-arrow-left"></i> Listeye Dön
        </a>
    </div>

    <?php if (isset($message)): ?>
    <div class="ab-notice ab-<?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" class="ab-task-form">
        <?php wp_nonce_field('save_task', 'task_nonce'); ?>
        
        <div class="ab-form-card panel-family <?php echo 'priority-' . esc_attr($current_priority); ?>">
            <div class="ab-form-section">
                <h3><i class="fas fa-tasks"></i> Görev Bilgileri</h3>
                
                <div class="ab-form-row">
                    <div class="ab-form-group ab-full-width">
                        <label for="task_title">Görev Tanımı <span class="required">*</span></label>
                        <input type="text" name="task_title" id="task_title" class="ab-input" 
                               value="<?php echo $editing && isset($task->task_title) ? esc_attr($task->task_title) : esc_attr($default_task_title); ?>" 
                               placeholder="Görev için kısa başlık girin" required>
                    </div>
                </div>
                
                <div class="ab-form-row">
                    <div class="ab-form-group ab-full-width">
                        <label for="task_description">Görev Açıklaması <span class="required">*</span></label>
                        <textarea name="task_description" id="task_description" class="ab-textarea" rows="4" 
                                  placeholder="Görevin detaylarını buraya yazın" required><?php echo $editing ? esc_textarea($task->task_description) : esc_textarea($default_task_description); ?></textarea>
                    </div>
                </div>
                
                <div class="ab-form-row ab-equal-columns">
                    <div class="ab-form-group ab-full-width">
                        <label for="customer_id">Müşteri <span class="required">*</span></label>
                        
                        <!-- Müşteri Dropdown ve Filtre Alanı -->
                        <div class="customer-select-wrapper">
                            <input type="text" id="customer_filter" class="ab-input" 
                                   placeholder="Müşteri ara (İsim, Soyisim, Telefon, TC Kimlik No)" autocomplete="off">
                            <select name="customer_id" id="customer_dropdown" class="ab-select" required>
                                <option value="">Müşteri Seçin</option>
                                <?php foreach ($all_customers as $id => $name): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($selected_customer_id, $id); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Müşteri Poliçeleri (Radio Buttons) -->
                <div id="customer-policies-container" class="ab-form-row" style="display: <?php echo $selected_customer_id ? 'block' : 'none'; ?>;">
                    <div class="ab-loading">Poliçeler yükleniyor...</div>
                </div>
                
                <div class="ab-form-row ab-equal-columns">
                    <div class="ab-form-group">
                        <label for="due_date">Son Tarih <span class="required">*</span></label>
                        <input type="datetime-local" name="due_date" id="due_date" class="ab-input" 
                               value="<?php echo $editing ? date('Y-m-d\TH:i', strtotime($task->due_date)) : esc_attr($default_due_date); ?>" required>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="priority">Öncelik <span class="required">*</span></label>
                        <select name="priority" id="priority" class="ab-select" 
                               style="background-color: <?php echo esc_attr($active_priority_bg); ?>; color: <?php echo esc_attr($active_priority_text); ?>; border-color: <?php echo esc_attr($active_priority_border); ?>;" required>
                            <option value="low" <?php selected($current_priority, 'low'); ?>>Düşük</option>
                            <option value="medium" <?php selected($current_priority, 'medium'); ?>>Orta</option>
                            <option value="high" <?php selected($current_priority, 'high'); ?>>Yüksek</option>
                            <option value="urgent" <?php selected($current_priority, 'urgent'); ?>>Çok Acil</option>
                        </select>
                    </div>
                </div>
                
                <div class="ab-form-row ab-equal-columns">
                    <div class="ab-form-group">
                        <label for="status">Durum <span class="required">*</span></label>
                        <select name="status" id="status" class="ab-select" required>
                            <option value="pending" <?php echo $editing && $task->status === 'pending' ? 'selected' : (!$editing ? 'selected' : ''); ?>>Beklemede</option>
                            <option value="in_progress" <?php echo $editing && $task->status === 'in_progress' ? 'selected' : ''; ?>>İşlemde</option>
                            <option value="completed" <?php echo $editing && $task->status === 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                            <option value="cancelled" <?php echo $editing && $task->status === 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                        </select>
                    </div>
                    
                    <div class="ab-form-group">
                        <label for="representative_id">Sorumlu Temsilci<?php echo (!$is_patron && !$is_manager && !$is_team_leader) ? ' (Otomatik)' : ''; ?></label>
                        <select name="representative_id" id="representative_id" class="ab-select" <?php echo (!$is_patron && !$is_manager && !$is_team_leader) ? 'disabled' : ''; ?>>
                            <option value="">Sorumlu Temsilci Seçin<?php echo (!$is_patron && !$is_manager && !$is_team_leader) ? ' (Otomatik)' : ' (Opsiyonel)'; ?></option>
                            <?php foreach ($representatives as $rep): ?>
                                <option value="<?php echo esc_attr($rep->id); ?>" <?php selected($default_representative_id, $rep->id); ?>>
                                    <?php echo esc_html($rep->display_name); ?>
                                    <?php echo ($rep->id == $current_user_rep_id) ? ' (Ben)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$is_patron && !$is_manager && !$is_team_leader): ?>
                            <input type="hidden" name="representative_id" value="<?php echo esc_attr($current_user_rep_id); ?>">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="ab-form-actions">
                <a href="?view=tasks" class="ab-btn ab-btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
                <button type="submit" name="save_task" class="ab-btn ab-btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editing ? 'Güncelle' : 'Kaydet'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Müşteri ve poliçe verilerini önyüze göm -->
<script id="customers-data" type="application/json">
<?php echo json_encode($all_customers_data); ?>
</script>
<script id="policies-data" type="application/json">
<?php echo json_encode($all_policies); ?>
</script>

<style>
/* Form Stilleri */
.ab-task-form-container {
    max-width: 1000px;
    margin: 20px auto;
    padding: 25px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    color: #333;
    background-color: #ffffff;
    border-radius: 10px;
    box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e5e5;
}

/* Form başlık alanı */
.ab-form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.ab-form-header h2 {
    margin: 0;
    font-size: 24px;
    color: #333;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Form kartı */
.ab-form-card {
    background-color: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

/* Öncelik sınıfları */
.ab-form-card.priority-low {
    border-color: #c8e1cb;
    box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1), 0 0 0 2px #c8e1cb;
}

.ab-form-card.priority-medium {
    border-color: #f4d8a0;
    box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1), 0 0 0 2px #f4d8a0;
}

.ab-form-card.priority-high {
    border-color: #f4b7bc;
    box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1), 0 0 0 2px #f4b7bc;
}

.ab-form-card.priority-urgent {
    border-color: #660018;
    box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1), 0 0 0 2px #660018;
}

/* Form Bölümleri */
.ab-form-section {
    margin: 0;
    padding: 25px;
    border-bottom: 1px solid #f0f0f0;
}

.ab-form-section h3 {
    margin: 5px 0 20px 0;
    font-size: 18px;
    color: #444;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Form Satırları */
.ab-form-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 22px;
    gap: 20px; 
    align-items: flex-start;
}

.ab-form-row:last-child {
    margin-bottom: 0;
}

/* Eşit genişlikli sütunlar */
.ab-equal-columns {
    justify-content: space-between;
}

.ab-equal-columns .ab-form-group {
    flex: 1;
    min-width: 240px;
    max-width: calc(50% - 10px);
}

.ab-form-group {
    flex: 1;
    min-width: 240px;
    position: relative;
    margin-bottom: 10px;
}

.ab-form-group.ab-full-width {
    flex-basis: 100%;
    width: 100%;
    max-width: 100%;
}

/* Form Etiketleri */
.ab-form-group label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
    color: #444;
    font-size: 14px;
}

.required {
    color: #e53935;
    margin-left: 3px;
}

/* Input Stilleri */
.ab-input, .ab-select, .ab-textarea {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    color: #333;
    background-color: #fff;
    transition: all 0.3s ease;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
    box-sizing: border-box;
}

.ab-input:focus, .ab-select:focus, .ab-textarea:focus {
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.15);
    outline: none;
}

.ab-textarea {
    resize: vertical;
    min-height: 120px;
    line-height: 1.5;
}

/* Placeholder stili */
.ab-input::placeholder, .ab-textarea::placeholder {
    color: #aaa;
    font-style: italic;
}

/* Seçim kutuları için düzeltmeler */
select.ab-select {
    padding-right: 30px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-size: 15px;
    background-color: inherit;
    color: inherit;
    border-color: inherit;
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
}

select.ab-select option {
    background-color: #fff;
    color: #333;
}

/* Disabled input için stil */
.ab-select:disabled {
    background-color: #f9f9f9;
    cursor: not-allowed;
    opacity: 0.8;
    border-color: #ddd;
}

/* Müşteri Seçim Stilleri */
.customer-select-wrapper {
    position: relative;
    width: 100%;
}

#customer_filter {
    margin-bottom: 10px;
}

#customer_dropdown {
    width: 100%;
}

/* Poliçe Listesi Stilleri */
.ab-policies-list {
    border: 1px solid #eee;
    border-radius: 6px;
    overflow: hidden;
    background-color: #fcfcfc;
    margin-top: 5px;
}

.ab-policy-option {
    border-bottom: 1px solid #eee;
}

.ab-policy-option:last-child {
    border-bottom: none;
}

.ab-policy-radio {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    width: 100%;
    cursor: pointer;
    transition: background-color 0.2s;
    font-size: 14px; /* Font boyutunu sabitle */
}

.ab-policy-radio:hover {
    background-color: #f5f7fa;
}

.ab-policy-radio input[type="radio"] {
    vertical-align: middle;
    margin-right: 10px;
}

.ab-policy-none {
    display: flex;
    align-items: center;
    padding: 10px 15px;
    cursor: pointer;
    font-style: italic;
    color: #666;
    transition: background-color 0.2s;
}

.ab-policy-none:hover {
    background-color: #f5f7fa;
}

.ab-policy-none span {
    margin-left: 10px;
}

/* Poliçe bilgisi için div kaldırıldı, direkt span'ler kullanılıyor */
.policy-number {
    font-weight: 600;
    font-size: 14px;
}

/* Sigorta Tipleri Renkleri */
.policy-type {
    padding: 2px 6px;
    border-radius: 3px;
    color: #fff;
    font-size: 14px;
}

.policy-type-trafik {
    background-color: #28A745; /* Yeşil */
}

.policy-type-kasko {
    background-color: #007BFF; /* Mavi */
}

.policy-type-konut {
    background-color: #DC3545; /* Kırmızı */
}

.policy-type-dask {
    background-color: #FD7E14; /* Turuncu */
}

.policy-type-tss {
    background-color: #6F42C1; /* Mor */
}

.policy-type-oss {
    background-color: #17A2B8; /* Turkuaz */
}

.policy-type-seyahat-saglik {
    background-color: #E83E8C; /* Pembe */
}

.policy-type-isyeri-policesi {
    background-color: #f26e10; /* Turuncu */
    color: #000; /* Siyah font */
}

.policy-type-diger {
    background-color: #808080; /* Gri - Varsayılan renk */
    color: #000; /* Siyah font */
}

.policy-type-imm-policesi {
    background-color: #20C997; /* Teal */
}

.policy-type-hayat-sigorta-policesi {
    background-color: #FFC107; /* Sarı */
}

/* Sigorta Firmaları Renkleri */
.policy-insurer {
    padding: 2px 6px;
    border-radius: 3px;
    color: #fff;
    font-size: 14px;
}

.policy-insurer-anadolu-sigorta {
    background-color: #003087; /* Lacivert */
}

.policy-insurer-allianz {
    background-color: #003087; /* Koyu Mavi */
}

.policy-insurer-hepiyi {
    background-color: #00A3E0; /* Açık Mavi */
}

.policy-insurer-axa {
    background-color: #000066; /* Koyu Lacivert */
}

.policy-insurer-sompo {
    background-color: #E30613; /* Kırmızı */
}

.policy-insurer-turkiye {
    background-color: #D81E05; /* Kırmızı */
}

.policy-insurer-mapfre {
    background-color: #E30613; /* Kırmızı */
}

.policy-insurer-acibadem {
    background-color: #00A1D6; /* Turkuaz */
}

.policy-insurer-ray {
    background-color: #F39200; /* Turuncu */
}

.policy-insurer-turk-nippon {
    background-color: #0033A0; /* Mavi */
}

.policy-date {
    white-space: nowrap;
    font-size: 14px;
}

.ab-policy-empty {
    padding: 15px;
    text-align: center;
    font-style: italic;
    color: #999;
}

/* Yükleniyor animasyonu */
.ab-loading {
    padding: 15px;
    text-align: center;
    color: #666;
    font-style: italic;
}

/* Form Actions */
.ab-form-actions {
    padding: 20px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    background-color: #f9f9fa;
}

/* Butonlar */
.ab-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 18px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    line-height: 1.4;
    min-height: 40px;
    white-space: nowrap;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
}

.ab-btn-primary {
    background-color: #0073aa;
    border-color: #006291;
    color: white;
}

.ab-btn-primary:hover {
    background-color: #005f8b;
    color: white;
}

.ab-btn-secondary {
    background-color: #f8f9fa;
    border-color: #ddd;
}

/* Hata mesajları ve uyarılar */
.ab-notice {
    padding: 12px 15px;
    margin-bottom: 20px;
    border-left: 4px solid;
    border-radius: 3px;
    font-size: 14px;
    line-height: 1.5;
}

.ab-success {
    background-color: #f0fff4;
    border-left-color: #38a169;
    color: #276749;
}

.ab-error {
    background-color: #fff5f5;
    border-left-color: #e53e3e;
    color: #c53030;
}

.ab-warning {
    background-color: #fff8e5;
    border-left-color: #bf8700;
    color: #856404;
}

/* Mobil Uyumluluk */
@media (max-width: 768px) {
    .ab-task-form-container {
        padding: 15px;
        margin: 10px;
    }
    
    .ab-form-section {
        padding: 15px;
    }
    
    .ab-form-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .ab-equal-columns .ab-form-group {
        max-width: 100%;
    }
    
    .ab-form-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }
    
    .ab-btn {
        width: 100%;
        justify-content: center;
    }
    
    .ab-policy-info {
        flex-wrap: wrap;
        gap: 5px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM elementleri
    const customerFilter = document.getElementById('customer_filter');
    const customerDropdown = document.getElementById('customer_dropdown');
    const policiesContainer = document.getElementById('customer-policies-container');
    const prioritySelect = document.getElementById('priority');
    
    // Müşteri ve poliçe verilerini al
    const customersData = JSON.parse(document.getElementById('customers-data').textContent);
    const policiesData = JSON.parse(document.getElementById('policies-data').textContent);

    // Müşteri dropdown’ı için filtreleme
    if (customerFilter && customerDropdown) {
        customerFilter.addEventListener('input', function() {
            const filter = this.value.trim().toLowerCase();
            console.log('Filtreleme: ' + filter);
            
            customerDropdown.innerHTML = '<option value="">Müşteri Seçin</option>';
            
            Object.values(customersData).forEach(customer => {
                const fullName = (customer.first_name + ' ' + customer.last_name).toLowerCase();
                const phone = customer.phone ? customer.phone.toLowerCase() : '';
                const tcKimlikNo = customer.tc_kimlik_no ? customer.tc_kimlik_no.toLowerCase() : '';
                
                // TC kimlik no için tam veya kısmi eşleşme
                const isTcMatch = tcKimlikNo && (tcKimlikNo === filter || tcKimlikNo.includes(filter));
                const isMatch = isTcMatch || 
                               fullName.includes(filter) || 
                               phone.includes(filter);
                
                if (isMatch) {
                    const displayName = customer.first_name + ' ' + customer.last_name + 
                                       (customer.phone ? ' (' + customer.phone + ')' : '') + 
                                       (customer.tc_kimlik_no ? ' [TC: ' + customer.tc_kimlik_no + ']' : '');
                    const option = document.createElement('option');
                    option.value = customer.id;
                    option.textContent = displayName;
                    if (customer.id == '<?php echo esc_attr($selected_customer_id); ?>') {
                        option.selected = true;
                    }
                    customerDropdown.appendChild(option);
                }
            });
        });
        
        // İlk yüklemede dropdown’ı doldur
        customerFilter.dispatchEvent(new Event('input'));
    } else {
        console.error('Müşteri filtresi veya dropdown bulunamadı');
    }

    // Müşteri seçimi değiştiğinde poliçeleri göster
    if (customerDropdown) {
        customerDropdown.addEventListener('change', function() {
            const customerId = this.value;
            console.log('Müşteri seçildi: ID=' + customerId);
            
            if (customerId) {
                loadCustomerPolicies(customerId);
            } else {
                policiesContainer.style.display = 'none';
                policiesContainer.innerHTML = '';
                console.log('Müşteri seçilmedi, poliçeler gizlendi');
            }
        });
    } else {
        console.error('Customer dropdown bulunamadı');
    }

    // Poliçeleri yükleme fonksiyonu
    function loadCustomerPolicies(customerId) {
        policiesContainer.innerHTML = '<div class="ab-loading">Poliçeler yükleniyor...</div>';
        policiesContainer.style.display = 'block';
        
        const policies = policiesData[customerId] || [];
        console.log('Poliçeler: ', policies);
        
        let html = '';
        
        if (policies.length > 0) {
            html += '<div class="ab-form-group ab-full-width">';
            html += '<label>İlgili Poliçe</label>';
            html += '<div class="ab-policies-list">';
            html += '<div class="ab-policy-option">';
            html += '<label class="ab-policy-none">';
            html += '<input type="radio" name="policy_id" value="" checked>';
            html += '<span>Poliçe İlişkilendirme</span>';
            html += '</label>';
            html += '</div>';
            
            policies.forEach(policy => {
                // Sigorta tipi ve firması için CSS sınıfları oluştur
                const policyTypeClass = 'policy-type-' + policy.policy_type.toLowerCase().replace(/ /g, '-').replace(/ç/g, 'c').replace(/ş/g, 's').replace(/ı/g, 'i').replace(/ğ/g, 'g').replace(/ö/g, 'o').replace(/ü/g, 'u');
                const insuranceCompanyClass = 'policy-insurer-' + policy.insurance_company.toLowerCase().replace(/ /g, '-').replace(/ç/g, 'c').replace(/ş/g, 's').replace(/ı/g, 'i').replace(/ğ/g, 'g').replace(/ö/g, 'o').replace(/ü/g, 'u');
                
                html += '<div class="ab-policy-option">';
                html += '<label class="ab-policy-radio">';
                html += '<input type="radio" name="policy_id" value="' + policy.id + '"' + 
                        (policy.id == '<?php echo esc_attr($selected_policy_id); ?>' ? ' checked' : '') + '>';
                html += '<span class="policy-number">' + policy.policy_number + '</span> | ';
                html += '<span class="policy-type ' + policyTypeClass + '">' + policy.policy_type + '</span> | ';
                html += '<span class="policy-insurer ' + insuranceCompanyClass + '">' + policy.insurance_company + '</span> | ';
                html += '<span class="policy-date">' + policy.start_date + ' - ' + policy.end_date + '</span>';
                html += '</label>';
                html += '</div>';
            });
            
            html += '</div>';
            html += '</div>';
        } else {
            html += '<div class="ab-form-group ab-full-width">';
            html += '<label>İlgili Poliçe</label>';
            html += '<div class="ab-policies-list">';
            html += '<div class="ab-policy-empty">Bu müşteriye ait poliçe bulunamadı.</div>';
            html += '</div>';
            html += '</div>';
        }
        
        policiesContainer.innerHTML = html;
    }

    // Sayfa yüklendiğinde poliçeleri yükle
    if (customerDropdown && customerDropdown.value) {
        console.log('Sayfa yüklendi, poliçeler yükleniyor: Müşteri ID=' + customerDropdown.value);
        loadCustomerPolicies(customerDropdown.value);
    }

    // Öncelik seçimi için stil güncelleme
    if (prioritySelect) {
        console.log('Priority select bulundu, stil güncelleniyor');
        updatePriorityStyle();
        prioritySelect.addEventListener('change', function() {
            console.log('Öncelik değişti: ' + this.value);
            updatePriorityStyle();
        });
    } else {
        console.error('Priority select bulunamadı');
    }

    // Son tarih kontrolü
    const dueDateInput = document.getElementById('due_date');
    if (dueDateInput) {
        dueDateInput.addEventListener('change', () => {
            console.log('Son tarih değişti: ' + dueDateInput.value);
            validateDueDate(dueDateInput);
        });
        validateDueDate(dueDateInput);
    } else {
        console.error('Due date input bulunamadı');
    }

    function validateDueDate(input) {
        const warningElement = document.querySelector('.past-date-warning');
        if (warningElement) warningElement.remove();

        if (input.value) {
            const dueDate = new Date(input.value);
            const now = new Date();
            if (dueDate < now) {
                const warning = document.createElement('div');
                warning.className = 'ab-notice ab-warning past-date-warning';
                warning.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Girdiğiniz son tarih geçmişte kalmış.';
                input.parentElement.appendChild(warning);
                console.log('Uyarı: Son tarih geçmişte');
            }
        }
    }

    // Öncelik stil güncelleme
    function updatePriorityStyle() {
        const value = prioritySelect.value;
        const colors = {
            low: { bg: '#e6ffed', text: '#22863a', border: '#c8e1cb' },
            medium: { bg: '#fff8e5', text: '#bf8700', border: '#f4d8a0' },
            high: { bg: '#ffeef0', text: '#cb2431', border: '#f4b7bc' },
            urgent: { bg: '#800020', text: '#ffffff', border: '#660018' }
        };

        const { bg, text, border } = colors[value] || { bg: '#ffffff', text: '#333333', border: '#ddd' };
        
        // Select stilini güncelle
        prioritySelect.style.backgroundColor = bg;
        prioritySelect.style.color = text;
        prioritySelect.style.borderColor = border;

        // Form çerçevesine sınıf ekle
        const formCard = prioritySelect.closest('.ab-form-card');
        if (formCard) {
            formCard.classList.remove('priority-low', 'priority-medium', 'priority-high', 'priority-urgent');
            formCard.classList.add(`priority-${value}`);
        }
        console.log('Öncelik stili güncellendi: value=' + value + ', bg=' + bg + ', text=' + text);
    }
});
</script>