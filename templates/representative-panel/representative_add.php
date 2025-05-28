<?php
/**
 * Yeni Temsilci Ekleme Sayfası
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel
 * @author     Anadolu Birlik
 * @since      1.0.0
 * @version    1.0.0 (2025-05-28)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/temsilci-girisi/'));
    exit;
}

$current_user = wp_get_current_user();
global $wpdb;

// Kullanıcı rolünü kontrol et
function get_user_role_in_hierarchy($user_id) {
    global $wpdb;
    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
    
    $role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$table_reps} WHERE user_id = %d",
        $user_id
    ));
    
    if ($role === null) {
        return '';
    }
    
    $role_map = array(
        1 => 'patron',
        2 => 'manager',
        3 => 'assistant_manager',
        4 => 'team_leader',
        5 => 'representative'
    );
    
    return isset($role_map[$role]) ? $role_map[$role] : 'representative';
}

function is_patron($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'patron';
}

function is_manager($user_id) {
    return get_user_role_in_hierarchy($user_id) === 'manager';
}

// Yetki kontrolü - sadece patron ve müdür yeni temsilci ekleyebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Form gönderildiğinde yeni temsilci oluştur
if (isset($_POST['submit_representative']) && isset($_POST['representative_nonce']) && 
    wp_verify_nonce($_POST['representative_nonce'], 'add_representative')) {
    
    $error_messages = array();
    $success_message = '';
    
    // Form verilerini doğrula
    $username = sanitize_user($_POST['username']);
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $email = sanitize_email($_POST['email']);
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    $phone = sanitize_text_field($_POST['phone']);
    $department = sanitize_text_field($_POST['department']);
    $monthly_target = floatval($_POST['monthly_target']);
    $target_policy_count = intval($_POST['target_policy_count']);
    $role = intval($_POST['role']);
    
    // Yetkilendirme değerlerini kontrol et
    $customer_edit = isset($_POST['customer_edit']) ? 1 : 0;
    $customer_delete = isset($_POST['customer_delete']) ? 1 : 0;
    $policy_edit = isset($_POST['policy_edit']) ? 1 : 0;
    $policy_delete = isset($_POST['policy_delete']) ? 1 : 0;
    
    // Zorunlu alanları kontrol et
    if (empty($username)) {
        $error_messages[] = 'Kullanıcı adı gereklidir.';
    }
    
    if (empty($password)) {
        $error_messages[] = 'Şifre gereklidir.';
    }
    
    if ($password !== $confirm_password) {
        $error_messages[] = 'Şifreler eşleşmiyor.';
    }
    
    if (empty($email)) {
        $error_messages[] = 'E-posta adresi gereklidir.';
    }
    
    if (empty($first_name) || empty($last_name)) {
        $error_messages[] = 'Ad ve soyad gereklidir.';
    }
    
    // Kullanıcı adı ve e-posta kontrolü
    if (username_exists($username)) {
        $error_messages[] = 'Bu kullanıcı adı zaten kullanımda.';
    }
    
    if (email_exists($email)) {
        $error_messages[] = 'Bu e-posta adresi zaten kullanımda.';
    }
    
    // Avatar dosya yükleme işlemi
    $avatar_url = '';
    if (isset($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');

        if (!in_array($file['type'], $allowed_types)) {
            $error_messages[] = 'Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error_messages[] = 'Dosya boyutu 5MB\'dan büyük olamaz.';
        } else {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $attachment_id = media_handle_upload('avatar_file', 0);

            if (is_wp_error($attachment_id)) {
                $error_messages[] = 'Dosya yüklenemedi: ' . $attachment_id->get_error_message();
            } else {
                $avatar_url = wp_get_attachment_url($attachment_id);
            }
        }
    }
    
    // Hata yoksa yeni temsilci oluştur
    if (empty($error_messages)) {
        // WordPress kullanıcısı oluştur
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $error_messages[] = 'Kullanıcı oluşturulurken hata: ' . $user_id->get_error_message();
        } else {
            // Kullanıcı detaylarını güncelle
            wp_update_user(
                array(
                    'ID' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'display_name' => $first_name . ' ' . $last_name
                )
            );
            
            // Kullanıcıya rol ata
            $user = new WP_User($user_id);
            $user->set_role('insurance_representative');
            
            // Müşteri temsilcisi kaydı oluştur
            $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
            $insert_result = $wpdb->insert(
                $table_reps,
                array(
                    'user_id' => $user_id,
                    'role' => $role,
                    'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '',
                    'phone' => $phone,
                    'department' => $department,
                    'monthly_target' => $monthly_target,
                    'target_policy_count' => $target_policy_count,
                    'avatar_url' => $avatar_url,
                    'customer_edit' => $customer_edit,
                    'customer_delete' => $customer_delete,
                    'policy_edit' => $policy_edit,
                    'policy_delete' => $policy_delete,
                    'status' => 'active',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                )
            );
            
            if ($insert_result === false) {
                $error_messages[] = 'Temsilci kaydı oluşturulurken hata: ' . $wpdb->last_error;
                
                // Kullanıcı kaydını sil
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($user_id);
            } else {
                $success_message = 'Müşteri temsilcisi başarıyla eklendi.';
                
                // Aktivite logu ekle
                $table_logs = $wpdb->prefix . 'insurance_crm_activity_log';
                $wpdb->insert(
                    $table_logs,
                    array(
                        'user_id' => $current_user->ID,
                        'username' => $current_user->display_name,
                        'action_type' => 'create',
                        'action_details' => json_encode(array(
                            'item_type' => 'representative',
                            'item_id' => $wpdb->insert_id,
                            'name' => $first_name . ' ' . $last_name,
                            'created_by' => $current_user->display_name
                        )),
                        'created_at' => current_time('mysql')
                    )
                );
            }
        }
    }
}
?>

<!-- Sayfa İçeriği -->
<div class="representative-add-container">
    <div class="page-header">
        <h1>Yeni Müşteri Temsilcisi Ekle</h1>
        <p class="description">Sisteme yeni bir müşteri temsilcisi kaydı oluşturun.</p>
    </div>
    
    <?php if (!empty($error_messages)): ?>
        <div class="message-box error-box">
            <i class="fas fa-exclamation-circle"></i>
            <div class="message-content">
                <h4>Hata</h4>
                <ul>
                    <?php foreach ($error_messages as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
        <div class="message-box success-box">
            <i class="fas fa-check-circle"></i>
            <div class="message-content">
                <h4>Başarılı</h4>
                <p><?php echo esc_html($success_message); ?></p>
                <div class="action-buttons">
                    <a href="<?php echo home_url('/representative-panel/?view=all_personnel'); ?>" class="btn btn-primary">
                        <i class="fas fa-users"></i> Tüm Personeli Görüntüle
                    </a>
                    <a href="<?php echo home_url('/representative-panel/?view=representative_add'); ?>" class="btn btn-secondary">
                        <i class="fas fa-plus"></i> Başka Temsilci Ekle
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <form method="post" action="" class="representative-form" enctype="multipart/form-data">
            <?php wp_nonce_field('add_representative', 'representative_nonce'); ?>
            
            <div class="form-card">
                <div class="card-header">
                    <h2><i class="fas fa-user"></i> Kullanıcı Bilgileri</h2>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="username">Kullanıcı Adı <span class="required">*</span></label>
                            <input type="text" name="username" id="username" class="form-control" required>
                            <div class="form-hint">Giriş için kullanılacak kullanıcı adı. Bu sonradan değiştirilemez.</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="email">E-posta Adresi <span class="required">*</span></label>
                            <input type="email" name="email" id="email" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="password">Şifre <span class="required">*</span></label>
                            <div class="password-field">
                                <input type="password" name="password" id="password" class="form-control" required>
                                <button type="button" class="password-toggle" tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-hint">En az 8 karakter olmalıdır.</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="confirm_password">Şifre (Tekrar) <span class="required">*</span></label>
                            <div class="password-field">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                <button type="button" class="password-toggle" tabindex="-1">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="first_name">Ad <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="last_name">Soyad <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-card">
                <div class="card-header">
                    <h2><i class="fas fa-id-card"></i> Temsilci Bilgileri</h2>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="role">Rol <span class="required">*</span></label>
                            <select name="role" id="role" class="form-control" required>
                                <option value="5">Müşteri Temsilcisi</option>
                                <option value="4">Ekip Lideri</option>
                                <option value="3">Müdür Yardımcısı</option>
                                <option value="2">Müdür</option>
                                <?php if (is_patron($current_user->ID)): ?>
                                <option value="1">Patron</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="title">Ünvan</label>
                            <input type="text" name="title" id="title" class="form-control" placeholder="Örn: Satış Uzmanı">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="phone">Telefon <span class="required">*</span></label>
                            <input type="tel" name="phone" id="phone" class="form-control" required placeholder="5XX XXX XXXX">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="department">Departman</label>
                            <input type="text" name="department" id="department" class="form-control" placeholder="Örn: Satış, Müşteri İlişkileri">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="monthly_target">Aylık Hedef (₺) <span class="required">*</span></label>
                            <input type="number" step="0.01" min="0" name="monthly_target" id="monthly_target" class="form-control" required>
                            <div class="form-hint">Temsilcinin aylık satış hedefi (₺)</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="target_policy_count">Hedef Poliçe Adedi <span class="required">*</span></label>
                            <input type="number" step="1" min="0" name="target_policy_count" id="target_policy_count" class="form-control" required>
                            <div class="form-hint">Temsilcinin aylık hedef poliçe adedi</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-card">
                <div class="card-header">
                    <h2><i class="fas fa-key"></i> Yetkiler ve Profil Fotoğrafı</h2>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <div class="permissions-grid">
                                <h4>Müşteri ve Poliçe İşlem Yetkileri</h4>
                                
                                <div class="permissions-row">
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="customer_edit" value="1">
                                        <span class="checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Müşteri Düzenleme</span>
                                            <span class="label-desc">Müşteri bilgilerini düzenleyebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="permissions-row">
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="customer_delete" value="1">
                                        <span class="checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Müşteri Silme</span>
                                            <span class="label-desc">Müşteri kaydını pasife alabilir/silebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="permissions-row">
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="policy_edit" value="1">
                                        <span class="checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Poliçe Düzenleme</span>
                                            <span class="label-desc">Poliçe bilgilerini düzenleyebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="permissions-row">
                                    <label class="checkbox-container">
                                        <input type="checkbox" name="policy_delete" value="1">
                                        <span class="checkmark"></span>
                                        <div class="label-text">
                                            <span class="label-title">Poliçe Silme</span>
                                            <span class="label-desc">Poliçe kaydını pasife alabilir/silebilir</span>
                                        </div>
                                    </label>
                                </div>
                                
                                <div id="role_permission_message" class="permission-message"></div>
                            </div>
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label for="avatar_file">Profil Fotoğrafı</label>
                            <div class="file-upload-container">
                                <div class="file-upload-preview">
                                    <img src="<?php echo plugins_url('assets/images/default-avatar.png', dirname(dirname(__FILE__))); ?>" alt="Avatar Önizleme" id="avatar-preview">
                                </div>
                                <div class="file-upload-controls">
                                    <input type="file" name="avatar_file" id="avatar_file" accept="image/*" class="inputfile">
                                    <label for="avatar_file">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Fotoğraf Seç</span>
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-avatar" style="display:none">
                                        <i class="fas fa-times"></i> Kaldır
                                    </button>
                                </div>
                            </div>
                            <div class="form-hint">Önerilen boyut: 100x100 piksel. (JPG, PNG, GIF. Maks 5MB)</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="submit_representative" class="btn btn-primary">
                    <i class="fas fa-save"></i> Temsilciyi Kaydet
                </button>
                <a href="<?php echo home_url('/representative-panel/?view=all_personnel'); ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
            </div>
        </form>
    <?php endif; ?>
</div>

<style>
.representative-add-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.page-header {
    margin-bottom: 25px;
    text-align: center;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
}

.page-header h1 {
    font-size: 28px;
    color: #333;
    margin-bottom: 10px;
}

.page-header .description {
    color: #666;
    font-size: 16px;
}

.message-box {
    display: flex;
    align-items: flex-start;
    padding: 20px;
    margin-bottom: 25px;
    border-radius: 8px;
    animation: fadeIn 0.3s ease;
}

.error-box {
    background-color: #fff2f2;
    border-left: 4px solid #e74c3c;
}

.success-box {
    background-color: #eafaf1;
    border-left: 4px solid #2ecc71;
}

.message-box i {
    font-size: 24px;
    margin-right: 15px;
}

.error-box i {
    color: #e74c3c;
}

.success-box i {
    color: #2ecc71;
}

.message-content {
    flex: 1;
}

.message-content h4 {
    font-size: 18px;
    margin: 0 0 10px;
    color: #333;
}

.message-content ul {
    margin: 0;
    padding-left: 20px;
}

.message-content ul li {
    margin-bottom: 5px;
}

.action-buttons {
    margin-top: 15px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.form-card {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    margin-bottom: 25px;
    overflow: hidden;
}

.card-header {
    background-color: #f8f9fa;
    padding: 15px 20px;
    border-bottom: 1px solid #eaeaea;
}

.card-header h2 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    color: #333;
}

.card-header h2 i {
    margin-right: 10px;
    color: #3498db;
}

.card-body {
    padding: 20px;
}

.form-row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -10px;
    margin-left: -10px;
    margin-bottom: 15px;
}

.form-group {
    padding-right: 10px;
    padding-left: 10px;
    margin-bottom: 15px;
    flex: 0 0 100%;
}

.col-md-6 {
    flex: 0 0 50%;
    max-width: 50%;
}

.form-control {
    display: block;
    width: 100%;
    padding: 10px 12px;
    font-size: 14px;
    line-height: 1.5;
    color: #495057;
    background-color: #fff;
    border: 1px solid #ced4da;
    border-radius: 4px;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
    outline: 0;
}

.form-hint {
    margin-top: 5px;
    font-size: 12px;
    color: #6c757d;
}

label {
    display: inline-block;
    margin-bottom: 5px;
    font-weight: 500;
}

.required {
    color: #e74c3c;
}

.password-field {
    position: relative;
}

.password-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: #6c757d;
}

.permissions-grid {
    background-color: #f9f9f9;
    border-radius: 6px;
    padding: 15px;
}

.permissions-grid h4 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 15px;
    color: #333;
    border-bottom: 1px solid #eaeaea;
    padding-bottom: 8px;
}

.permissions-row {
    margin-bottom: 12px;
}

.checkbox-container {
    display: flex;
    position: relative;
    padding-left: 35px;
    cursor: pointer;
    user-select: none;
}

.checkbox-container input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}

.checkmark {
    position: absolute;
    top: 0;
    left: 0;
    height: 20px;
    width: 20px;
    background-color: #fff;
    border: 2px solid #ced4da;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.checkbox-container:hover input ~ .checkmark {
    border-color: #3498db;
}

.checkbox-container input:checked ~ .checkmark {
    background-color: #3498db;
    border-color: #3498db;
}

.checkmark:after {
    content: "";
    position: absolute;
    display: none;
}

.checkbox-container input:checked ~ .checkmark:after {
    display: block;
}

.checkbox-container .checkmark:after {
    left: 6px;
    top: 2px;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.label-text {
    display: flex;
    flex-direction: column;
}

.label-title {
    font-weight: 500;
    font-size: 14px;
}

.label-desc {
    font-size: 12px;
    color: #6c757d;
}

.permission-message {
    margin-top: 15px;
    padding: 10px;
    border-radius: 4px;
    display: none;
    font-size: 13px;
}

.file-upload-container {
    text-align: center;
    background-color: #f8f9fa;
    border-radius: 6px;
    padding: 15px;
}

.file-upload-preview {
    width: 120px;
    height: 120px;
    margin: 0 auto 10px;
    border-radius: 50%;
    overflow: hidden;
    background-color: #ffffff;
    border: 2px solid #ced4da;
    display: flex;
    align-items: center;
    justify-content: center;
}

.file-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.file-upload-controls {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

.inputfile {
    width: 0.1px;
    height: 0.1px;
    opacity: 0;
    overflow: hidden;
    position: absolute;
    z-index: -1;
}

.inputfile + label {
    color: #ffffff;
    background-color: #3498db;
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    display: inline-block;
    transition: background-color 0.2s ease;
}

.inputfile + label:hover {
    background-color: #2980b9;
}

.inputfile + label i {
    margin-right: 5px;
}

.form-actions {
    margin-top: 30px;
    display: flex;
    gap: 10px;
}

.btn {
    display: inline-flex;
    align-items: center;
    font-weight: 500;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    user-select: none;
    border: 1px solid transparent;
    padding: 12px 20px;
    font-size: 14px;
    line-height: 1.5;
    border-radius: 4px;
    transition: all 0.15s ease-in-out;
    cursor: pointer;
}

.btn i {
    margin-right: 8px;
}

.btn-primary {
    color: #fff;
    background-color: #3498db;
    border-color: #3498db;
}

.btn-primary:hover, .btn-primary:focus {
    background-color: #2980b9;
    border-color: #2980b9;
}

.btn-secondary {
    color: #6c757d;
    background-color: #f8f9fa;
    border-color: #ced4da;
}

.btn-secondary:hover, .btn-secondary:focus {
    color: #333;
    background-color: #e9ecef;
    border-color: #ced4da;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.btn-outline-danger {
    color: #e74c3c;
    background-color: transparent;
    border-color: #e74c3c;
}

.btn-outline-danger:hover {
    color: #fff;
    background-color: #e74c3c;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .col-md-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Şifre göster/gizle
    const toggleButtons = document.querySelectorAll('.password-toggle');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
    
    // Şifre eşleşme kontrolü
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    
    if (confirmPassword) {
        confirmPassword.addEventListener('blur', function() {
            if (password.value && this.value && this.value !== password.value) {
                alert('Şifreler eşleşmiyor!');
                this.value = '';
            }
        });
    }
    
    // Avatar yükleme önizleme
    const avatarInput = document.getElementById('avatar_file');
    const avatarPreview = document.getElementById('avatar-preview');
    const removeAvatarBtn = document.querySelector('.remove-avatar');
    
    if (avatarInput) {
        avatarInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    avatarPreview.src = e.target.result;
                    removeAvatarBtn.style.display = 'inline-block';
                };
                
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    if (removeAvatarBtn) {
        removeAvatarBtn.addEventListener('click', function() {
            const defaultAvatar = plugins_url + '/assets/images/default-avatar.png';
            avatarPreview.src = defaultAvatar;
            avatarInput.value = '';
            this.style.display = 'none';
        });
    }
    
    // Rol seçimine göre yetkilendirmeler
    const roleSelect = document.getElementById('role');
    const permissionMessage = document.getElementById('role_permission_message');
    const permissionCheckboxes = document.querySelectorAll('.permissions-row input[type="checkbox"]');
    
    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            const role = parseInt(this.value);
            
            // Tüm mesaj stillerini temizle
            permissionMessage.classList.remove('patron', 'manager');
            permissionMessage.style.display = 'none';
            
            // Rol özelliklerine göre yetkiler
            if (role === 1) { // Patron
                permissionCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                    checkbox.disabled = true;
                });
                
                permissionMessage.textContent = 'Patron rolü tüm yetkilere sahiptir. Bu ayarlar otomatik olarak seçilmiş ve kilitlenmiştir.';
                permissionMessage.classList.add('patron');
                permissionMessage.style.display = 'block';
                permissionMessage.style.backgroundColor = '#e3f2fd';
                permissionMessage.style.color = '#0d47a1';
                permissionMessage.style.border = '1px solid #bbdefb';
            } 
            else if (role === 2) { // Müdür
                permissionCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                    checkbox.disabled = false;
                });
                
                permissionMessage.textContent = 'Müdür rolü için yetkileri özelleştirebilirsiniz.';
                permissionMessage.classList.add('manager');
                permissionMessage.style.display = 'block';
                permissionMessage.style.backgroundColor = '#fff3e0';
                permissionMessage.style.color = '#e65100';
                permissionMessage.style.border = '1px solid #ffe0b2';
            }
            else {
                permissionCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                });
            }
        });
        
        // Sayfa yüklendiğinde rol kontrolü
        roleSelect.dispatchEvent(new Event('change'));
    }
});
</script>