<?php
/**
 * Poliçe Ekleme/Düzenleme Formu
 * @version 4.0.0
 * @updated 2025-05-29 16:35
 */

include_once(dirname(__FILE__) . '/template-colors.php');

if (!is_user_logged_in()) {
    return;
}

// Veritabanında gerekli sütunların varlığını kontrol et ve yoksa ekle
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';

// insured_party sütunu kontrolü
$column_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'insured_party'");
if (!$column_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN insured_party VARCHAR(255) DEFAULT NULL AFTER status");
}

// İptal bilgileri için sütunlar
$cancellation_date_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_date'");
if (!$cancellation_date_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_date DATE DEFAULT NULL AFTER status");
}

$refunded_amount_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'refunded_amount'");
if (!$refunded_amount_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_date");
}

// YENİ: İptal nedeni için sütun
$cancellation_reason_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'cancellation_reason'");
if (!$cancellation_reason_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN cancellation_reason VARCHAR(100) DEFAULT NULL AFTER refunded_amount");
}

// YENİ: Silinen poliçeler için sütunlar
$is_deleted_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'is_deleted'");
if (!$is_deleted_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
}

$deleted_by_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'deleted_by'");
if (!$deleted_by_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN deleted_by INT(11) DEFAULT NULL");
}

$deleted_at_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'deleted_at'");
if (!$deleted_at_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

// YENİ: Yeni İş - Yenileme bilgisi için sütun
$policy_category_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'policy_category'");
if (!$policy_category_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN policy_category VARCHAR(50) DEFAULT 'Yeni İş' AFTER policy_type");
}

// YENİ: Network bilgisi için sütun
$network_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'network'");
if (!$network_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN network VARCHAR(255) DEFAULT NULL AFTER premium_amount");
}

// YENİ: Durum bilgisi notu için sütun
$status_note_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'status_note'");
if (!$status_note_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN status_note TEXT DEFAULT NULL AFTER status");
}

// YENİ: Ödeme bilgisi için sütun
$payment_info_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'payment_info'");
if (!$payment_info_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN payment_info VARCHAR(255) DEFAULT NULL AFTER premium_amount");
}

// YENİ: Plaka bilgisi için sütun (Kasko/Trafik için gerekli)
$plate_number_exists = $wpdb->get_row("SHOW COLUMNS FROM $policies_table LIKE 'plate_number'");
if (!$plate_number_exists) {
    $wpdb->query("ALTER TABLE $policies_table ADD COLUMN plate_number VARCHAR(20) DEFAULT NULL AFTER insured_party");
}

$editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0;
$renewing = isset($_GET['action']) && $_GET['action'] === 'renew' && isset($_GET['id']) && intval($_GET['id']) > 0;
$cancelling = isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id']) && intval($_GET['id']) > 0;
$create_from_offer = isset($_GET['action']) && $_GET['action'] === 'create_from_offer' && isset($_GET['customer_id']);
$policy_id = $editing || $renewing || $cancelling ? intval($_GET['id']) : 0;

// YENİ: Tanımlı iptal nedenleri
$cancellation_reasons = ['Araç Satışı', 'İsteğe Bağlı', 'Tahsilattan İptal', 'Diğer Sebepler'];

// Teklif verilerini al
$offer_amount = isset($_GET['offer_amount']) ? floatval($_GET['offer_amount']) : '';
$offer_type = isset($_GET['offer_type']) ? sanitize_text_field(urldecode($_GET['offer_type'])) : '';
$offer_file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
$selected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// Oturum açmış temsilcinin ID'sini al
$current_user_rep_id = function_exists('get_current_user_rep_id') ? get_current_user_rep_id() : 0;

// Kullanıcının patron veya müdür olup olmadığını kontrol et
function is_patron_or_manager() {
    $user = wp_get_current_user();
    $user_roles = $user->roles;

    // Burada tüm muhtemel "yönetici" rollerini kontrol ediyoruz
    $management_roles = ['administrator', 'admin', 'patron', 'müdür', 'mudur', 'manager', 'insurance_manager', 'insurance_manager_admin'];
    
    foreach ($management_roles as $role) {
        if (in_array($role, $user_roles)) {
            return true;
        }
    }
    
    return false;
}

if (isset($_POST['save_policy']) && isset($_POST['policy_nonce']) && wp_verify_nonce($_POST['policy_nonce'], 'save_policy')) {
    $policy_data = array(
        'customer_id' => intval($_POST['customer_id']),
        'policy_number' => sanitize_text_field($_POST['policy_number']),
        'policy_type' => sanitize_text_field($_POST['policy_type']),
        'policy_category' => sanitize_text_field($_POST['policy_category']),
        'insurance_company' => sanitize_text_field($_POST['insurance_company']),
        'start_date' => sanitize_text_field($_POST['start_date']),
        'end_date' => sanitize_text_field($_POST['end_date']),
        'premium_amount' => floatval($_POST['premium_amount']),
        'payment_info' => isset($_POST['payment_info']) ? sanitize_text_field($_POST['payment_info']) : '',
        'network' => isset($_POST['network']) ? sanitize_text_field($_POST['network']) : '',
        'status' => sanitize_text_field($_POST['status']),
        'status_note' => isset($_POST['status_note']) ? sanitize_textarea_field($_POST['status_note']) : '',
        'insured_party' => isset($_POST['same_as_insured']) && $_POST['same_as_insured'] === 'yes' ? '' : sanitize_text_field($_POST['insured_party']),
        'representative_id' => $current_user_rep_id // Otomatik olarak mevcut temsilci
    );
    
    // Plaka bilgisi kontrolü (Kasko/Trafik için)
    if (in_array(strtolower($policy_data['policy_type']), ['kasko', 'trafik']) && isset($_POST['plate_number'])) {
        $policy_data['plate_number'] = sanitize_text_field($_POST['plate_number']);
    }

    // İptal bilgilerini ekle
    if (isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes') {
        $policy_data['cancellation_date'] = sanitize_text_field($_POST['cancellation_date']);
        $policy_data['refunded_amount'] = !empty($_POST['refunded_amount']) ? floatval($_POST['refunded_amount']) : 0;
        $policy_data['cancellation_reason'] = sanitize_text_field($_POST['cancellation_reason']);
        $policy_data['status'] = 'Zeyil'; // İptal edilen poliçeyi Zeyil olarak işaretle
    }

    if (!empty($_FILES['document']['name'])) {
        $upload_dir = wp_upload_dir();
        $policy_upload_dir = $upload_dir['basedir'] . '/insurance-crm-docs';
        
        if (!file_exists($policy_upload_dir)) {
            wp_mkdir_p($policy_upload_dir);
        }
        
        $allowed_file_types = array('pdf', 'doc', 'docx');
        $file_ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed_file_types)) {
            $file_name = 'policy-' . time() . '-' . sanitize_file_name($_FILES['document']['name']);
            $file_path = $policy_upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $policy_data['document_path'] = $upload_dir['baseurl'] . '/insurance-crm-docs/' . $file_name;
            } else {
                $upload_error = true;
            }
        } else {
            $file_type_error = true;
        }
    }
    
    // Teklif dosyası kullanılıyorsa
    if (isset($_POST['use_offer_file']) && $_POST['use_offer_file'] == 'yes' && !empty($_POST['offer_file_path'])) {
        $policy_data['document_path'] = $_POST['offer_file_path'];
    }

    $table_name = $wpdb->prefix . 'insurance_crm_policies';

    if ($editing || $cancelling) {
        // Tüm kullanıcılar için yetki kontrolü - Patron ve Müdür için her zaman izin ver
        $can_edit = true;
        
        // Patron/Müdür DEĞİLSE ve kendi poliçesi değilse yetkisiz
        if (!is_patron_or_manager()) {
            $policy_check = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
            if ($policy_check && $policy_check->representative_id != $current_user_rep_id) {
                $can_edit = false;
                $message = 'Bu poliçeyi düzenleme/iptal etme yetkiniz yok.';
                $message_type = 'error';
            }
        }

        if ($can_edit) {
            $policy_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($table_name, $policy_data, ['id' => $policy_id]);

            if ($result !== false) {
                $action_text = isset($_POST['is_cancelled']) && $_POST['is_cancelled'] === 'yes' ? 'iptal edildi' : 'güncellendi';
                $message = 'Poliçe başarıyla ' . $action_text . '.';
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=policies&updated=true";</script>';
                exit;
            } else {
                $message = 'Poliçe işlenirken bir hata oluştu.';
                $message_type = 'error';
            }
        }
    } elseif ($renewing) {
        // Yenileme işlemi için eski poliçenin bilgilerini çek
        $old_policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $policy_id));
        
        if ($old_policy) {
            // Eski poliçeyi pasif olarak işaretle
            $wpdb->update($table_name, ['status' => 'pasif'], ['id' => $policy_id]);
            
            // Yeni poliçeye policy_category olarak 'Yenileme' ekle
            $policy_data['policy_category'] = 'Yenileme';
            $policy_data['created_at'] = current_time('mysql');
            $policy_data['updated_at'] = current_time('mysql');
            
            // İptal bilgileri varsa temizle
            $policy_data['cancellation_date'] = null;
            $policy_data['refunded_amount'] = null;
            $policy_data['cancellation_reason'] = null;
            
            $result = $wpdb->insert($table_name, $policy_data);
            
            if ($result) {
                $new_policy_id = $wpdb->insert_id;
                $message = 'Poliçe başarıyla yenilendi. Yeni poliçe numarası: ' . $policy_data['policy_number'];
                $message_type = 'success';
                $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
                echo '<script>window.location.href = "?view=policies&action=view&id=' . $new_policy_id . '";</script>';
                exit;
            } else {
                $message = 'Poliçe yenilenirken bir hata oluştu.';
                $message_type = 'error';
            }
        } else {
            $message = 'Yenilenecek poliçe bulunamadı.';
            $message_type = 'error';
        }
    } else {
        // Yeni poliçe ekleme
        $policy_data['created_at'] = current_time('mysql');
        $policy_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->insert($table_name, $policy_data);
        
        if ($result) {
            $new_policy_id = $wpdb->insert_id;
            $message = 'Poliçe başarıyla eklendi.';
            $message_type = 'success';
            $_SESSION['crm_notice'] = '<div class="ab-notice ab-' . $message_type . '">' . $message . '</div>';
            echo '<script>window.location.href = "?view=policies&added=true";</script>';
            exit;
        } else {
            $message = 'Poliçe eklenirken bir hata oluştu.';
            $message_type = 'error';
        }
    }
}

// Müşterileri getir
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customers = $wpdb->get_results("SELECT * FROM $customers_table ORDER BY first_name ASC");

// Düzenlenen veya iptal edilen poliçenin bilgilerini getir
$policy = null;
if ($policy_id > 0) {
    $policies_table = $wpdb->prefix . 'insurance_crm_policies';
    $policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $policies_table WHERE id = %d", $policy_id));
    
    if (!$policy) {
        echo '<div class="ab-notice ab-error">Poliçe bulunamadı.</div>';
        return;
    }
    
    if ($renewing) {
        // Yenileme işleminde yeni poliçe için bilgileri varsayılan olarak ayarla
        $policy->policy_number = '';  // Yeni poliçe numarası boş olmalı
        $policy->status = 'aktif';    // Yeni poliçe aktif olmalı
        $policy->start_date = date('Y-m-d', strtotime($policy->end_date . ' +1 day')); // Bitişten sonraki gün
        $policy->end_date = date('Y-m-d', strtotime($policy->end_date . ' +1 year')); // Bir yıl sonrası
        $policy->cancellation_date = null;
        $policy->refunded_amount = null;
        $policy->cancellation_reason = null;
    }
    
    // Poliçe sahibi müşteriyi getir
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $policy->customer_id));
}

// Varsayılan poliçe türleri
$settings = get_option('insurance_crm_settings', []);
$policy_types = $settings['default_policy_types'] ?? ['Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer'];

// Sigorta şirketleri
$insurance_companies = array_unique($settings['insurance_companies'] ?? ['Sompo']);
sort($insurance_companies);

// Poliçe kategorileri
$policy_categories = ['Yeni İş', 'Yenileme', 'Zeyil', 'Diğer'];

// Form action URL'sini hazırla
$form_action = sanitize_url($_SERVER['REQUEST_URI']);

// Başlık ve açıklama
$title = '';
$description = '';

if ($editing) {
    $title = 'Poliçe Düzenle';
    $description = 'Mevcut poliçe bilgilerini düzenleyebilirsiniz.';
} elseif ($renewing) {
    $title = 'Poliçe Yenile';
    $description = 'Mevcut poliçenin yeni versiyonunu oluşturabilirsiniz.';
} elseif ($cancelling) {
    $title = 'Poliçe İptal';
    $description = 'Poliçeyi iptal etmek için aşağıdaki bilgileri doldurunuz.';
} elseif ($create_from_offer) {
    $title = 'Tekliften Poliçe Oluştur';
    $description = 'Teklif bilgilerinden yeni bir poliçe oluşturabilirsiniz.';
} else {
    $title = 'Yeni Poliçe Ekle';
    $description = 'Yeni bir poliçe kaydı oluşturmak için aşağıdaki formu doldurunuz.';
}

// Müşteri bilgilerini getir (tekliften oluşturma durumunda)
if ($create_from_offer && $selected_customer_id > 0) {
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $selected_customer_id));
}

// Temsilcinin müşteri olup olmadığını kontrol et
$is_customer = false;
if ($current_user_rep_id) {
    // Temsilcinin bağlı olduğu kullanıcı bilgisini getir
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $rep = $wpdb->get_row($wpdb->prepare("SELECT * FROM $representatives_table WHERE id = %d", $current_user_rep_id));
    
    if ($rep) {
        $is_customer = true;
    }
}
?>

<style>
    .policy-form-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 20px 30px;
        max-width: 1200px;
        margin: 20px auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    
    .policy-form-header {
        margin-bottom: 30px;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 15px;
    }
    
    .policy-form-header h2 {
        font-size: 24px;
        color: #333;
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .policy-form-header p {
        font-size: 14px;
        color: #666;
        margin: 0;
    }
    
    .policy-form-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
    
    .policy-form-section {
        background: #f9f9f9;
        border-radius: 6px;
        padding: 20px;
        border: 1px solid #eee;
    }
    
    .policy-form-section h3 {
        margin-top: 0;
        font-size: 18px;
        color: #333;
        margin-bottom: 15px;
        font-weight: 600;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
    }
    
    .form-row {
        margin-bottom: 15px;
    }
    
    .form-row label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        font-size: 14px;
        color: #444;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    
    .form-textarea {
        min-height: 100px;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        border-color: #1976d2;
        outline: none;
        box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.2);
    }
    
    .form-actions {
        margin-top: 30px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }
    
    .btn-primary {
        background-color: #1976d2;
        color: white;
    }
    
    .btn-primary:hover {
        background-color: #1565c0;
    }
    
    .btn-secondary {
        background-color: #f5f5f5;
        color: #333;
        border: 1px solid #ddd;
    }
    
    .btn-secondary:hover {
        background-color: #e0e0e0;
    }
    
    .btn-danger {
        background-color: #f44336;
        color: white;
    }
    
    .btn-danger:hover {
        background-color: #d32f2f;
    }
    
    .checkbox-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
    }
    
    .checkbox-row input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
    
    .notification {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    
    .notification.success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
        color: #2e7d32;
    }
    
    .notification.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
        color: #c62828;
    }
    
    .notification.warning {
        background-color: #fff3e0;
        border-left: 4px solid #ff9800;
        color: #e65100;
    }
    
    /* Cancellation Section Styles */
    .cancellation-section {
        background-color: #ffebee;
        border: 1px solid #ffcdd2;
    }
    
    .cancellation-section h3 {
        color: #c62828;
    }
    
    .cancellation-section .form-row label {
        color: #c62828;
    }
    
    /* New Status Note Section */
    .status-note-row {
        margin-top: 15px;
    }

    .status-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
    }
    
    .status-aktif {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .status-pasif {
        background-color: #f5f5f5;
        color: #757575;
    }
    
    .status-iptal {
        background-color: #ffebee;
        color: #c62828;
    }
    
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
    }
    
    .badge-primary {
        background-color: #e3f2fd;
        color: #1976d2;
    }
    
    .badge-success {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .badge-warning {
        background-color: #fff3e0;
        color: #f57c00;
    }

    /* Network Field */
    .network-field {
        margin-top: 15px;
    }
    
    /* Payment Info Field */
    .payment-info-field {
        margin-top: 15px;
    }
    
    /* Document Upload */
    .file-upload-wrapper {
        position: relative;
    }
    
    .file-upload-wrapper input[type=file] {
        opacity: 0;
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 99;
        height: 40px;
        cursor: pointer;
    }
    
    .file-upload-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        background-color: white;
    }
    
    .file-upload-wrapper:hover .file-upload-input {
        border-color: #1976d2;
    }
    
    /* Responsive designs */
    @media (max-width: 768px) {
        .policy-form-container {
            padding: 15px;
        }
        
        .policy-form-content {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .btn {
            width: 100%;
        }
    }

    /* Tam genişlik bölümü için CSS */
    .full-width-section {
        grid-column: 1 / -1; /* Tüm grid sütunlarını kapla */
        margin-top: 20px;
    }

    /* Dosya upload alanını daha kullanışlı hale getir */
    .full-width-section .file-upload-wrapper {
        max-width: 600px; /* Dosya seçme alanını kontrollü bir genişlikte tutar */
    }

    /* Müşteri Bilgileri Yükleniyor Spinner */
    .customer-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(0,0,0,.1);
        border-radius: 50%;
        border-top-color: #1976d2;
        animation: spin 1s ease-in-out infinite;
        margin-left: 10px;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Plaka alanı için özel stil */
    .plate-input {
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 1px;
    }

    /* Seçilmemiş alanlar için belirginlik */
    select:invalid, .form-select option:first-child {
        color: #757575;
    }
</style>

<?php if (isset($message)): ?>
<div class="notification <?php echo $message_type; ?>">
    <?php echo $message; ?>
</div>
<?php endif; ?>

<div class="policy-form-container" id="policy-form">
    <div class="policy-form-header">
        <h2><?php echo $title; ?></h2>
        <p><?php echo $description; ?></p>
    </div>
    
    <form action="<?php echo $form_action; ?>" method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('save_policy', 'policy_nonce'); ?>
        <input type="hidden" name="save_policy" value="1">
        
        <div class="policy-form-content">
            
            <?php if ($cancelling || (isset($policy) && $policy->cancellation_date)): ?>
            <!-- İPTAL BİLGİLERİ - EN ÜSTTE TAM GENİŞLİKTE -->
            <div class="policy-form-section cancellation-section full-width-section">
                <h3>İptal Bilgileri</h3>
                <input type="hidden" name="is_cancelled" value="yes">
                
                <div class="form-row">
                    <label for="cancellation_date">İptal Tarihi <span style="color: red;">*</span></label>
                    <input type="date" name="cancellation_date" id="cancellation_date" class="form-input" 
                           value="<?php echo isset($policy) && $policy->cancellation_date ? esc_attr($policy->cancellation_date) : date('Y-m-d'); ?>" 
                           required>
                </div>
                
                <div class="form-row">
                    <label for="refunded_amount">İade Tutarı (₺)</label>
                    <input type="number" name="refunded_amount" id="refunded_amount" class="form-input" 
                           value="<?php echo isset($policy) && $policy->refunded_amount ? esc_attr($policy->refunded_amount) : ''; ?>" 
                           step="0.01" min="0" placeholder="Varsa iade tutarı">
                </div>
                
                <!-- İptal nedeni seçimi -->
                <div class="form-row">
                    <label for="cancellation_reason">İptal Nedeni <span style="color: red;">*</span></label>
                    <select name="cancellation_reason" id="cancellation_reason" class="form-select" required>
                        <option value="">Seçiniz...</option>
                        <?php foreach ($cancellation_reasons as $reason): ?>
                        <option value="<?php echo esc_attr($reason); ?>" <?php if (isset($policy) && $policy->cancellation_reason === $reason) echo 'selected'; ?>>
                            <?php echo esc_html($reason); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <p style="color: #c62828; font-weight: 500; font-size: 14px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Dikkat: İptal işlemi geri alınamaz. İptal edilen poliçeler sistemde kalacak ancak Zeyil olarak işaretlenecektir.
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Müşteri Bilgileri -->
            <div class="policy-form-section">
                <h3>Müşteri Bilgileri</h3>
                <div class="form-row">
                    <label for="customer_id">Müşteri Seçin <span style="color: red;">*</span></label>
                    <select name="customer_id" id="customer_id" class="form-select" required <?php echo ($editing || $cancelling || $renewing || $create_from_offer) ? 'disabled' : ''; ?>>
                        <option value="">Müşteri Seçin</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c->id; ?>" <?php selected(isset($policy) ? $policy->customer_id : ($selected_customer_id ?: 0), $c->id); ?>>
                            <?php echo esc_html($c->first_name . ' ' . $c->last_name); ?>
                            <?php if (!empty($c->tc_identity)): ?>
                                (<?php echo esc_html($c->tc_identity); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($editing || $cancelling || $renewing || $create_from_offer): ?>
                    <input type="hidden" name="customer_id" value="<?php echo isset($policy) ? $policy->customer_id : $selected_customer_id; ?>">
                    <?php endif; ?>
                </div>
                
                <div id="customer_details">
                    <?php if (isset($customer) && $customer): ?>
                    <div class="form-row">
                        <label>TC Kimlik No</label>
                        <input type="text" id="customer_tc" class="form-input" value="<?php echo esc_attr($customer->tc_identity); ?>" readonly>
                    </div>
                    
                    <div class="form-row">
                        <label>Telefon</label>
                        <input type="text" id="customer_phone" class="form-input" value="<?php echo esc_attr($customer->phone); ?>" readonly>
                    </div>
                    
                    <div class="form-row">
                        <label>E-posta</label>
                        <input type="text" id="customer_email" class="form-input" value="<?php echo esc_attr($customer->email); ?>" readonly>
                    </div>
                    
                    <div class="form-row">
                        <label for="insured_party">Sigortalayan</label>
                        <input type="text" name="insured_party" id="insured_party" class="form-input" 
                               value="<?php echo isset($policy) && !empty($policy->insured_party) ? esc_attr($policy->insured_party) : ''; ?>" 
                               placeholder="Sigortalayan farklıysa lütfen isim soyisim girin">
                        
                        <div class="checkbox-row">
                            <input type="checkbox" name="same_as_insured" id="same_as_insured" value="yes" 
                               <?php echo isset($policy) && empty($policy->insured_party) ? 'checked' : ''; ?>>
                            <label for="same_as_insured">Sigortalı ile Sigortalayan Aynı Kişi mi?</label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Poliçe Bilgileri -->
            <div class="policy-form-section">
                <h3>Poliçe Bilgileri</h3>
                <div class="form-row">
                    <label for="policy_number">Poliçe Numarası <span style="color: red;">*</span></label>
                    <input type="text" name="policy_number" id="policy_number" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->policy_number) : ''; ?>" 
                           required>
                </div>
                
                <div class="form-row">
                    <label for="policy_type">Poliçe Türü <span style="color: red;">*</span></label>
                    <select name="policy_type" id="policy_type" class="form-select" required>
                        <option value="">Seçiniz...</option>
                        <?php foreach ($policy_types as $type): ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php if (isset($policy)) selected($policy->policy_type, $type); else if ($offer_type === $type) echo 'selected'; ?>>
                            <?php echo esc_html($type); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="Diğer">Diğer</option>
                    </select>
                </div>
                
                <!-- Plaka alanı (Kasko/Trafik için) -->
                <div class="form-row" id="plate_field" style="display: none;">
                    <label for="plate_number">Araç Plakası <span style="color: red;">*</span></label>
                    <input type="text" name="plate_number" id="plate_number" class="form-input plate-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->plate_number) : ''; ?>"
                           placeholder="34ABC123" maxlength="10">
                </div>
                
                <!-- Poliçe Kategorisi seçimi -->
                <div class="form-row">
                    <label for="policy_category">Poliçe Kategorisi</label>
                    <select name="policy_category" id="policy_category" class="form-select">
                        <option value="">Seçiniz...</option>
                        <?php foreach ($policy_categories as $category): ?>
                        <option value="<?php echo esc_attr($category); ?>" <?php if (isset($policy)) selected($policy->policy_category, $category); else if ($renewing && $category === 'Yenileme') echo 'selected'; ?>>
                            <?php echo esc_html($category); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="insurance_company">Sigorta Şirketi <span style="color: red;">*</span></label>
                    <select name="insurance_company" id="insurance_company" class="form-select" required>
                        <option value="">Seçiniz...</option>
                        <?php foreach ($insurance_companies as $company): ?>
                        <option value="<?php echo esc_attr($company); ?>" <?php if (isset($policy)) selected($policy->insurance_company, $company); ?>>
                            <?php echo esc_html($company); ?>
                        </option>
                        <?php endforeach; ?>
                        <option value="Diğer">Diğer</option>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="start_date">Başlangıç Tarihi <span style="color: red;">*</span></label>
                    <input type="date" name="start_date" id="start_date" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->start_date) : date('Y-m-d'); ?>" 
                           required>
                </div>
                
                <div class="form-row">
                    <label for="end_date">Bitiş Tarihi <span style="color: red;">*</span></label>
                    <input type="date" name="end_date" id="end_date" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->end_date) : date('Y-m-d', strtotime('+1 year')); ?>" 
                           required>
                </div>
            </div>
            
            <!-- Ödeme Bilgileri -->
            <div class="policy-form-section">
                <h3>Ödeme ve Durum Bilgileri</h3>
                <div class="form-row">
                    <label for="premium_amount">Prim Tutarı (₺) <span style="color: red;">*</span></label>
                    <input type="number" name="premium_amount" id="premium_amount" class="form-input" 
                           value="<?php echo isset($policy) && $policy->premium_amount > 0 ? esc_attr($policy->premium_amount) : ''; ?>" 
                           step="0.01" min="0" required placeholder="Prim tutarı giriniz">
                </div>
                
                <!-- Ödeme Bilgisi alanı -->
                <div class="form-row payment-info-field">
                    <label for="payment_info">Ödeme Bilgisi</label>
                    <select name="payment_info" id="payment_info" class="form-select">
                        <option value="">Seçiniz...</option>
                        <option value="Peşin" <?php if (isset($policy) && $policy->payment_info === 'Peşin') echo 'selected'; ?>>Peşin</option>
                        <option value="3 Taksit" <?php if (isset($policy) && $policy->payment_info === '3 Taksit') echo 'selected'; ?>>3 Taksit</option>
                        <option value="6 Taksit" <?php if (isset($policy) && $policy->payment_info === '6 Taksit') echo 'selected'; ?>>6 Taksit</option>
                        <option value="9 Taksit" <?php if (isset($policy) && $policy->payment_info === '9 Taksit') echo 'selected'; ?>>9 Taksit</option>
                        <option value="12 Taksit" <?php if (isset($policy) && $policy->payment_info === '12 Taksit') echo 'selected'; ?>>12 Taksit</option>
                        <option value="Ödenmedi" <?php if (isset($policy) && $policy->payment_info === 'Ödenmedi') echo 'selected'; ?>>Ödenmedi</option>
                        <option value="Nakit" <?php if (isset($policy) && $policy->payment_info === 'Nakit') echo 'selected'; ?>>Nakit</option>
                        <option value="Kredi Kartı" <?php if (isset($policy) && $policy->payment_info === 'Kredi Kartı') echo 'selected'; ?>>Kredi Kartı</option>
                        <option value="Havale" <?php if (isset($policy) && $policy->payment_info === 'Havale') echo 'selected'; ?>>Havale</option>
                        <option value="Diğer" <?php if (isset($policy) && $policy->payment_info === 'Diğer') echo 'selected'; ?>>Diğer</option>
                    </select>
                </div>
                
                <!-- Network bilgisi alanı -->
                <div class="form-row network-field">
                    <label for="network">Network/Anlaşmalı Kurum</label>
                    <input type="text" name="network" id="network" class="form-input" 
                           value="<?php echo isset($policy) ? esc_attr($policy->network) : ''; ?>" 
                           placeholder="Varsa anlaşmalı kurum bilgisi">
                </div>
                
                <div class="form-row">
                    <label for="status">Durum</label>
                    <select name="status" id="status" class="form-select" <?php if ($cancelling) echo 'disabled'; ?>>
                        <option value="">Seçiniz...</option>
                        <option value="aktif" <?php if (isset($policy) && $policy->status === 'aktif') echo 'selected'; ?>>Aktif</option>
                        <option value="pasif" <?php if (isset($policy) && $policy->status === 'pasif') echo 'selected'; ?>>Pasif</option>
                        <option value="Zeyil" <?php if (isset($policy) && $policy->status === 'Zeyil') echo 'selected'; ?>>Zeyil</option>
                    </select>
                    <?php if ($cancelling): ?>
                    <input type="hidden" name="status" value="Zeyil">
                    <?php endif; ?>
                </div>
                
                <!-- Durum notu alanı -->
                <div class="form-row status-note-row">
                    <label for="status_note">Durum Notu</label>
                    <textarea name="status_note" id="status_note" class="form-textarea" 
                           placeholder="Poliçe durumu hakkında ekstra bilgi"><?php echo isset($policy) ? esc_textarea($policy->status_note) : ''; ?></textarea>
                </div>
            </div>
            
            <!-- Döküman Yükleme - Tam Genişlik -->
            <div class="policy-form-section full-width-section">
                <h3>Dökümanlar</h3>
                <div class="form-row">
                    <label>Poliçe Dökümantasyonu</label>
                    <div class="file-upload-wrapper">
                        <div class="file-upload-input">
                            <?php if (isset($policy) && $policy->document_path): ?>
                                Mevcut Döküman: 
                                <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank">
                                    <?php echo basename($policy->document_path); ?>
                                </a>
                            <?php else: ?>
                                Döküman seçmek için tıklayın (PDF, DOC, DOCX)
                            <?php endif; ?>
                        </div>
                        <input type="file" name="document" accept=".pdf,.doc,.docx">
                    </div>
                </div>
                
                <?php if (isset($offer_file_id) && $offer_file_id > 0): ?>
                    <?php 
                    $offer_file_path = get_attached_file($offer_file_id);
                    $offer_file_url = wp_get_attachment_url($offer_file_id);
                    if ($offer_file_path && $offer_file_url):
                    ?>
                    <div class="form-row">
                        <div class="checkbox-row">
                            <input type="checkbox" name="use_offer_file" id="use_offer_file" value="yes">
                            <label for="use_offer_file">Teklif dökümantasyonunu kullan</label>
                        </div>
                        <input type="hidden" name="offer_file_path" value="<?php echo esc_url($offer_file_url); ?>">
                        <div style="margin-top: 5px;">
                            <a href="<?php echo esc_url($offer_file_url); ?>" target="_blank">
                                <?php echo basename($offer_file_path); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="?view=policies<?php echo isset($policy_manager) && $policy_manager->is_team_view ? '&view_type=team' : ''; ?>" class="btn btn-secondary">
                İptal
            </a>
            
            <?php if ($cancelling): ?>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Poliçeyi iptal etmek istediğinizden emin misiniz?');">
                    Poliçeyi İptal Et
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-primary">
                    <?php echo $editing ? 'Güncelle' : ($renewing ? 'Yenile' : 'Kaydet'); ?>
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
// Sayfa yüklendiğinde hemen çalışacak fonksiyonlar
document.addEventListener('DOMContentLoaded', function() {
    // Kasko/Trafik seçiminde plaka alanını göster/gizle
    updatePlateField();
    
    // Sigorta şirketi ve poliçe kategorisi önceden seçili ise kontrol et
    const policyCategory = document.getElementById('policy_category');
    if (policyCategory && policyCategory.value === '') {
        const firstOption = policyCategory.querySelector('option:not([value=""])');
        if (firstOption) {
            policyCategory.value = firstOption.value;
        }
    }
    
    // Checkbox durumunu kontrol et
    setupInsuredCheckbox();
    
    // Dosya seçildiğinde etiketi güncelle
    const fileUpload = document.querySelector('input[type="file"]');
    const fileUploadLabel = document.querySelector('.file-upload-input');
    
    if (fileUpload && fileUploadLabel) {
        fileUpload.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                fileUploadLabel.textContent = this.files[0].name;
            }
        });
    }
    
    // İptal durumu seçildiğinde uyarı
    const statusSelect = document.getElementById('status');
    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            if (this.value === 'Zeyil') {
                alert('Dikkat: Zeyil durumu seçtiniz. Bu işlem genellikle İptal Et butonunu kullanarak yapılmalıdır. Buradan yapılan değişiklikler iptal bilgilerini içermeyecektir.');
            }
        });
    }
    
    // Tarih aralığı kontrolleri
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if (startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            if (endDateInput.value && new Date(endDateInput.value) <= new Date(this.value)) {
                // Bitiş tarihi başlangıç tarihinden önce veya aynı olamaz
                const newEndDate = new Date(this.value);
                newEndDate.setFullYear(newEndDate.getFullYear() + 1);
                endDateInput.valueAsDate = newEndDate;
            }
        });
    }
    
    // İptal tarihi kontrolü
    const cancellationDateInput = document.getElementById('cancellation_date');
    
    if (cancellationDateInput && startDateInput && endDateInput) {
        cancellationDateInput.addEventListener('change', function() {
            const cancellationDate = new Date(this.value);
            const startDate = new Date(startDateInput.value);
            const endDate = new Date(endDateInput.value);
            
            if (cancellationDate < startDate || cancellationDate > endDate) {
                alert('İptal tarihi, poliçe başlangıç ve bitiş tarihleri arasında olmalıdır.');
                this.valueAsDate = new Date();
            }
        });
    }
    
    // İade tutarı kontrolleri
    const premiumAmountInput = document.getElementById('premium_amount');
    const refundedAmountInput = document.getElementById('refunded_amount');
    
    if (premiumAmountInput && refundedAmountInput) {
        refundedAmountInput.addEventListener('change', function() {
            const premiumAmount = parseFloat(premiumAmountInput.value);
            const refundedAmount = parseFloat(this.value);
            
            if (refundedAmount > premiumAmount) {
                alert('İade tutarı, prim tutarından büyük olamaz.');
                this.value = premiumAmount;
            }
        });
    }
    
    // Plaka formatı kontrolü
    const plateInput = document.getElementById('plate_number');
    if (plateInput) {
        plateInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    
    // Poliçe türü değişince plaka alanını güncelle
    const policyType = document.getElementById('policy_type');
    if (policyType) {
        policyType.addEventListener('change', updatePlateField);
    }
    
    // Form validasyonu
    const form = document.querySelector('form');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#f44336';
                    
                    // Form alanının üstüne kaydır
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Lütfen tüm zorunlu alanları doldurun.');
            }
        });
    }
    
    // Müşteri bilgilerini al (eğer müşteri seçilmişse)
    const customerSelect = document.getElementById('customer_id');
    if (customerSelect && customerSelect.value) {
        fetchCustomerDetails(customerSelect.value);
    }
    
    // Müşteri değiştiğinde bilgileri yenile
    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            fetchCustomerDetails(this.value);
        });
    }
});

// Sigortalı ile Sigortalayan Aynı Kişi mi? seçeneği için
function setupInsuredCheckbox() {
    const sameAsInsuredCheckbox = document.getElementById('same_as_insured');
    const insuredPartyInput = document.getElementById('insured_party');
    
    if (sameAsInsuredCheckbox && insuredPartyInput) {
        sameAsInsuredCheckbox.addEventListener('change', function() {
            insuredPartyInput.disabled = this.checked;
            if (this.checked) {
                insuredPartyInput.value = '';
            }
        });
        
        // Sayfa yüklendiğinde kontrol
        if (sameAsInsuredCheckbox.checked) {
            insuredPartyInput.disabled = true;
        }
    }
}

// Müşteri detaylarını getir
function fetchCustomerDetails(customerId) {
    if (!customerId) return;
    
    // AJAX isteği için endpoint
    const endpoint = ajaxurl || (window.location.href.split('?')[0] + '?action=get_customer_info');
    
    // Form verisi oluştur
    const formData = new FormData();
    formData.append('action', 'get_customer_info');
    formData.append('customer_id', customerId);
    
    // AJAX isteği başlat
    fetch(endpoint, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCustomerDetails(data.data);
        } else {
            console.error('Müşteri bilgileri alınamadı:', data.message);
        }
    })
    .catch(error => {
        console.error('AJAX isteği başarısız oldu:', error);
    });
}

// Müşteri bilgilerini güncelle
function updateCustomerDetails(customer) {
    const customerDetails = document.getElementById('customer_details');
    if (!customerDetails) return;
    
    customerDetails.innerHTML = `
        <div class="form-row">
            <label>TC Kimlik No</label>
            <input type="text" id="customer_tc" class="form-input" value="${customer.tc_identity || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label>Telefon</label>
            <input type="text" id="customer_phone" class="form-input" value="${customer.phone || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label>E-posta</label>
            <input type="text" id="customer_email" class="form-input" value="${customer.email || ''}" readonly>
        </div>
        
        <div class="form-row">
            <label for="insured_party">Sigortalayan</label>
            <input type="text" name="insured_party" id="insured_party" class="form-input" 
                   value="" 
                   placeholder="Sigortalayan farklıysa lütfen isim soyisim girin">
            
            <div class="checkbox-row">
                <input type="checkbox" name="same_as_insured" id="same_as_insured" value="yes" checked>
                <label for="same_as_insured">Sigortalı ile Sigortalayan Aynı Kişi mi?</label>
            </div>
        </div>
    `;
    
    // Checkbox işlevini tekrar tanımla
    setupInsuredCheckbox();
}

// Kasko/Trafik seçiminde plaka alanını göster/gizle
function updatePlateField() {
    const policyTypeSelect = document.getElementById('policy_type');
    const plateField = document.getElementById('plate_field');
    
    if (!policyTypeSelect || !plateField) return;
    
    const policyType = policyTypeSelect.value.toLowerCase();
    const plateInput = document.getElementById('plate_number');
    
    // Kasko veya Trafik seçiliyse plaka alanını göster ve zorunlu yap
    if (policyType === 'kasko' || policyType === 'trafik') {
        plateField.style.display = 'block';
        if (plateInput) {
            plateInput.setAttribute('required', 'required');
        }
    } else {
        // Diğer poliçe türleri için plaka alanını gizle ve zorunlu olma özelliğini kaldır
        plateField.style.display = 'none';
        if (plateInput) {
            plateInput.removeAttribute('required');
            plateInput.value = ''; // Plaka değerini temizle
        }
    }
}

// AJAX endpoint'i için destek
var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";

// Sayfa yüklendiğinde ve DOM hazır olduğunda yeniden çalıştır
window.addEventListener('load', function() {
    setTimeout(function() {
        updatePlateField(); // Poliçe türüne göre plaka alanını kontrol et
        
        // Sigorta şirketi ve poliçe kategorisi için varsayılan değerler
        const insuranceCompany = document.getElementById('insurance_company');
        const policyCategory = document.getElementById('policy_category');
        
        // Sigorta şirketi seçili değilse ve DB'den gelen değer varsa otomatik doldur
        <?php if (isset($policy) && !empty($policy->insurance_company)): ?>
        if (insuranceCompany && !insuranceCompany.value) {
            // Şirket adının tam eşleşmesini kontrol et
            const companyOptions = Array.from(insuranceCompany.options);
            for (let i = 0; i < companyOptions.length; i++) {
                if (companyOptions[i].value.toLowerCase() === '<?php echo strtolower($policy->insurance_company); ?>') {
                    insuranceCompany.selectedIndex = i;
                    break;
                }
            }
        }
        <?php endif; ?>
        
        // Poliçe kategorisi seçili değilse ve DB'den gelen değer varsa otomatik doldur
        <?php if (isset($policy) && !empty($policy->policy_category)): ?>
        if (policyCategory && !policyCategory.value) {
            // Kategori adının tam eşleşmesini kontrol et
            const categoryOptions = Array.from(policyCategory.options);
            for (let i = 0; i < categoryOptions.length; i++) {
                if (categoryOptions[i].value.toLowerCase() === '<?php echo strtolower($policy->policy_category); ?>') {
                    policyCategory.selectedIndex = i;
                    break;
                }
            }
        }
        <?php endif; ?>
    }, 100);
});
</script>

<!-- WordPress AJAX handler için gerekli kod -->
<?php
add_action('wp_ajax_get_customer_info', 'get_customer_info_callback');
function get_customer_info_callback() {
    global $wpdb;
    
    // Güvenlik kontrolü (isteğe bağlı)
    // check_ajax_referer('customer_nonce', 'nonce');
    
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    
    if (!$customer_id) {
        wp_send_json_error(['message' => 'Geçersiz müşteri ID']);
        return;
    }
    
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $customer_id));
    
    if (!$customer) {
        wp_send_json_error(['message' => 'Müşteri bulunamadı']);
        return;
    }
    
    // Müşteri verilerini döndür
    wp_send_json_success([
        'id' => $customer->id,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'tc_identity' => $customer->tc_identity,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'address' => $customer->address
    ]);
}
?>