<?php
/**
 * Yönetim Ayarları Sayfası
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

// Yetki kontrolü - sadece patron ayarlara erişebilir
if (!is_patron($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Mevcut ayarları al
$settings = get_option('insurance_crm_settings', array());

// Varsayılan değerler
if (!isset($settings['company_name'])) {
    $settings['company_name'] = get_bloginfo('name');
}
if (!isset($settings['company_email'])) {
    $settings['company_email'] = get_bloginfo('admin_email');
}
if (!isset($settings['renewal_reminder_days'])) {
    $settings['renewal_reminder_days'] = 30;
}
if (!isset($settings['task_reminder_days'])) {
    $settings['task_reminder_days'] = 7;
}
if (!isset($settings['default_policy_types'])) {
    $settings['default_policy_types'] = array('Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık');
}
if (!isset($settings['insurance_companies'])) {
    $settings['insurance_companies'] = array('Allianz', 'Anadolu Sigorta', 'AXA', 'Axa Sigorta', 'Acıbadem', 'Ankara Sigorta', 'Groupama', 'Güneş Sigorta', 'HDI', 'Mapfre', 'Sompo Japan', 'Türkiye Sigorta', 'Unico Sigorta');
}
if (!isset($settings['default_task_types'])) {
    $settings['default_task_types'] = array('Telefon Görüşmesi', 'Yüz Yüze Görüşme', 'Teklif Hazırlama', 'Evrak İmza', 'Dosya Takibi');
}
if (!isset($settings['occupation_settings']['default_occupations'])) {
    $settings['occupation_settings']['default_occupations'] = array('Doktor', 'Mühendis', 'Öğretmen', 'Avukat', 'Muhasebeci', 'İşçi', 'Memur', 'Emekli');
}

// Form gönderildiğinde
if (isset($_POST['submit_settings']) && isset($_POST['settings_nonce']) && 
    wp_verify_nonce($_POST['settings_nonce'], 'save_settings')) {
    
    $tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'general';
    $error_messages = array();
    $success_message = '';
    
    // Genel ayarlar
    if ($tab === 'general') {
        $settings['company_name'] = sanitize_text_field($_POST['company_name']);
        $settings['company_email'] = sanitize_email($_POST['company_email']);
        $settings['renewal_reminder_days'] = intval($_POST['renewal_reminder_days']);
        $settings['task_reminder_days'] = intval($_POST['task_reminder_days']);
    }
    // Poliçe türleri
    elseif ($tab === 'policy_types') {
        $settings['default_policy_types'] = array_map('sanitize_text_field', explode("\n", trim($_POST['default_policy_types'])));
    }
    // Sigorta şirketleri
    elseif ($tab === 'insurance_companies') {
        $settings['insurance_companies'] = array_map('sanitize_text_field', explode("\n", trim($_POST['insurance_companies'])));
    }
    // Görev türleri
    elseif ($tab === 'task_types') {
        $settings['default_task_types'] = array_map('sanitize_text_field', explode("\n", trim($_POST['default_task_types'])));
    }
    // Bildirim ayarları
    elseif ($tab === 'notifications') {
        $settings['notification_settings']['email_notifications'] = isset($_POST['email_notifications']);
        $settings['notification_settings']['renewal_notifications'] = isset($_POST['renewal_notifications']);
        $settings['notification_settings']['task_notifications'] = isset($_POST['task_notifications']);
    }
    // E-posta şablonları
    elseif ($tab === 'email_templates') {
        $settings['email_templates']['renewal_reminder'] = wp_kses_post($_POST['renewal_reminder_template']);
        $settings['email_templates']['task_reminder'] = wp_kses_post($_POST['task_reminder_template']);
        $settings['email_templates']['new_policy'] = wp_kses_post($_POST['new_policy_template']);
    }
    // Site görünümü
    elseif ($tab === 'site_appearance') {
        $settings['site_appearance']['login_logo'] = esc_url_raw($_POST['login_logo']);
        $settings['site_appearance']['font_family'] = sanitize_text_field($_POST['font_family']);
        $settings['site_appearance']['primary_color'] = sanitize_hex_color($_POST['primary_color']);
    }
    // Dosya yükleme ayarları
    elseif ($tab === 'file_upload') {
        $settings['file_upload_settings']['allowed_file_types'] = isset($_POST['allowed_file_types']) ? array_map('sanitize_text_field', $_POST['allowed_file_types']) : array();
    }
    // Meslekler
    elseif ($tab === 'occupations') {
        $settings['occupation_settings']['default_occupations'] = array_map('sanitize_text_field', explode("\n", trim($_POST['default_occupations'])));
    }
    
    // Ayarları kaydet
    update_option('insurance_crm_settings', $settings);
    $success_message = 'Ayarlar başarıyla kaydedildi.';
}

// Aktif sekme
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'general';
?>

<div class="boss-settings-container">
    <div class="page-header">
        <h1><i class="fas fa-cog"></i> Yönetim Ayarları</h1>
        <p class="description">Sistem ayarlarını yapılandırın ve özelleştirin.</p>
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
            </div>
        </div>
    <?php endif; ?>
    
    <div class="settings-container">
        <div class="settings-sidebar">
            <div class="sidebar-header">
                <h3><i class="fas fa-sliders-h"></i> Ayar Kategorileri</h3>
            </div>
            <ul class="settings-menu">
                <li class="<?php echo $active_tab === 'general' ? 'active' : ''; ?>" data-tab="general">
                    <i class="fas fa-home"></i> Genel Ayarlar
                </li>
                <li class="<?php echo $active_tab === 'policy_types' ? 'active' : ''; ?>" data-tab="policy_types">
                    <i class="fas fa-file-invoice"></i> Poliçe Türleri
                </li>
                <li class="<?php echo $active_tab === 'insurance_companies' ? 'active' : ''; ?>" data-tab="insurance_companies">
                    <i class="fas fa-building"></i> Sigorta Şirketleri
                </li>
                <li class="<?php echo $active_tab === 'task_types' ? 'active' : ''; ?>" data-tab="task_types">
                    <i class="fas fa-tasks"></i> Görev Türleri
                </li>
                <li class="<?php echo $active_tab === 'notifications' ? 'active' : ''; ?>" data-tab="notifications">
                    <i class="fas fa-bell"></i> Bildirim Ayarları
                </li>
                <li class="<?php echo $active_tab === 'email_templates' ? 'active' : ''; ?>" data-tab="email_templates">
                    <i class="fas fa-envelope"></i> E-posta Şablonları
                </li>
                <li class="<?php echo $active_tab === 'site_appearance' ? 'active' : ''; ?>" data-tab="site_appearance">
                    <i class="fas fa-paint-brush"></i> Site Görünümü
                </li>
                <li class="<?php echo $active_tab === 'file_upload' ? 'active' : ''; ?>" data-tab="file_upload">
                    <i class="fas fa-cloud-upload-alt"></i> Dosya Yükleme
                </li>
                <li class="<?php echo $active_tab === 'occupations' ? 'active' : ''; ?>" data-tab="occupations">
                    <i class="fas fa-briefcase"></i> Meslekler
                </li>
            </ul>
        </div>
        
        <div class="settings-content">
            <form method="post" action="" class="settings-form">
                <?php wp_nonce_field('save_settings', 'settings_nonce'); ?>
                <input type="hidden" name="active_tab" id="active_tab" value="<?php echo esc_attr($active_tab); ?>">
                
                <!-- Genel Ayarlar -->
                <div class="settings-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>" id="general-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-home"></i> Genel Ayarlar</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="company_name">Şirket Adı</label>
                            <input type="text" name="company_name" id="company_name" class="form-control" 
                                  value="<?php echo esc_attr($settings['company_name']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="company_email">Şirket E-posta</label>
                            <input type="email" name="company_email" id="company_email" class="form-control" 
                                  value="<?php echo esc_attr($settings['company_email']); ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="renewal_reminder_days">Yenileme Hatırlatma (Gün)</label>
                                <input type="number" name="renewal_reminder_days" id="renewal_reminder_days" class="form-control" 
                                      value="<?php echo esc_attr($settings['renewal_reminder_days']); ?>" min="1" max="90">
                                <div class="form-hint">Poliçe yenileme hatırlatması için kaç gün önceden bildirim gönderilsin?</div>
                            </div>
                            
                            <div class="form-group col-md-6">
                                <label for="task_reminder_days">Görev Hatırlatma (Gün)</label>
                                <input type="number" name="task_reminder_days" id="task_reminder_days" class="form-control" 
                                      value="<?php echo esc_attr($settings['task_reminder_days']); ?>" min="1" max="30">
                                <div class="form-hint">Görev hatırlatması için kaç gün önceden bildirim gönderilsin?</div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Poliçe Türleri -->
                <div class="settings-tab <?php echo $active_tab === 'policy_types' ? 'active' : ''; ?>" id="policy_types-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-file-invoice"></i> Poliçe Türleri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="default_policy_types">Varsayılan Poliçe Türleri</label>
                            <textarea name="default_policy_types" id="default_policy_types" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['default_policy_types'])); ?></textarea>
                            <div class="form-hint">Her satıra bir poliçe türü yazın. Bu liste poliçe formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Sigorta Şirketleri -->
                <div class="settings-tab <?php echo $active_tab === 'insurance_companies' ? 'active' : ''; ?>" id="insurance_companies-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-building"></i> Sigorta Şirketleri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="insurance_companies">Sigorta Firmaları Listesi</label>
                            <textarea name="insurance_companies" id="insurance_companies" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['insurance_companies'])); ?></textarea>
                            <div class="form-hint">Her satıra bir sigorta firması adı yazın. Bu liste poliçe formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Görev Türleri -->
                <div class="settings-tab <?php echo $active_tab === 'task_types' ? 'active' : ''; ?>" id="task_types-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-tasks"></i> Görev Türleri</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="default_task_types">Varsayılan Görev Türleri</label>
                            <textarea name="default_task_types" id="default_task_types" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['default_task_types'])); ?></textarea>
                            <div class="form-hint">Her satıra bir görev türü yazın. Bu liste görev formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bildirim Ayarları -->
                <div class="settings-tab <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>" id="notifications-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-bell"></i> Bildirim Ayarları</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="email_notifications" id="email_notifications" 
                                       <?php checked(isset($settings['notification_settings']['email_notifications']) ? $settings['notification_settings']['email_notifications'] : false); ?>>
                                <span class="checkbox-text">E-posta bildirimlerini etkinleştir</span>
                            </label>
                            <div class="form-hint">Sistem bildirimleri e-posta yoluyla da gönderilir.</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="renewal_notifications" id="renewal_notifications"
                                       <?php checked(isset($settings['notification_settings']['renewal_notifications']) ? $settings['notification_settings']['renewal_notifications'] : false); ?>>
                                <span class="checkbox-text">Poliçe yenileme bildirimlerini etkinleştir</span>
                            </label>
                            <div class="form-hint">Poliçe yenilemeleri için bildirim gönderilir.</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="task_notifications" id="task_notifications"
                                       <?php checked(isset($settings['notification_settings']['task_notifications']) ? $settings['notification_settings']['task_notifications'] : false); ?>>
                                <span class="checkbox-text">Görev bildirimlerini etkinleştir</span>
                            </label>
                            <div class="form-hint">Görevler için bildirim gönderilir.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- E-posta Şablonları -->
                <div class="settings-tab <?php echo $active_tab === 'email_templates' ? 'active' : ''; ?>" id="email_templates-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-envelope"></i> E-posta Şablonları</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="renewal_reminder_template">Yenileme Hatırlatma Şablonu</label>
                            <textarea name="renewal_reminder_template" id="renewal_reminder_template" class="form-control" rows="8"><?php 
                                echo isset($settings['email_templates']['renewal_reminder']) ? esc_textarea($settings['email_templates']['renewal_reminder']) : ''; 
                            ?></textarea>
                            <div class="form-hint">
                                Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {end_date}, {premium_amount}
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="task_reminder_template">Görev Hatırlatma Şablonu</label>
                            <textarea name="task_reminder_template" id="task_reminder_template" class="form-control" rows="8"><?php 
                                echo isset($settings['email_templates']['task_reminder']) ? esc_textarea($settings['email_templates']['task_reminder']) : ''; 
                            ?></textarea>
                            <div class="form-hint">
                                Kullanılabilir değişkenler: {customer_name}, {task_description}, {due_date}, {priority}
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_policy_template">Yeni Poliçe Bildirimi</label>
                            <textarea name="new_policy_template" id="new_policy_template" class="form-control" rows="8"><?php 
                                echo isset($settings['email_templates']['new_policy']) ? esc_textarea($settings['email_templates']['new_policy']) : ''; 
                            ?></textarea>
                            <div class="form-hint">
                                Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {start_date}, {end_date}, {premium_amount}
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Site Görünümü -->
                <div class="settings-tab <?php echo $active_tab === 'site_appearance' ? 'active' : ''; ?>" id="site_appearance-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-paint-brush"></i> Site Görünümü</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="login_logo">Giriş Paneli Logo URL</label>
                            <input type="text" name="login_logo" id="login_logo" class="form-control" 
                                  value="<?php echo esc_attr(isset($settings['site_appearance']['login_logo']) ? $settings['site_appearance']['login_logo'] : ''); ?>">
                            <div class="form-hint">Giriş sayfasında görüntülenecek logo URL'si. Boş bırakırsanız varsayılan logo kullanılır.</div>
                            
                            <?php if (!empty($settings['site_appearance']['login_logo'])): ?>
                                <div class="logo-preview" style="margin-top: 10px;">
                                    <img src="<?php echo esc_url($settings['site_appearance']['login_logo']); ?>" alt="Logo Önizleme" style="max-height: 100px; border: 1px solid #ddd; padding: 5px;">
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="font_family">Font Ailesi</label>
                                <input type="text" name="font_family" id="font_family" class="form-control" 
                                       value="<?php echo esc_attr(isset($settings['site_appearance']['font_family']) ? $settings['site_appearance']['font_family'] : 'Arial, sans-serif'); ?>">
                                <div class="form-hint">Örnek: "Arial, sans-serif" veya "Open Sans, sans-serif"</div>
                            </div>
                            
                            <div class="form-group col-md-6">
                                <label for="primary_color">Ana Renk</label>
                                <div class="color-picker-container">
                                    <input type="color" name="primary_color" id="primary_color" class="color-picker" 
                                           value="<?php echo esc_attr(isset($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#3498db'); ?>">
                                    <span class="color-value"><?php echo esc_attr(isset($settings['site_appearance']['primary_color']) ? $settings['site_appearance']['primary_color'] : '#3498db'); ?></span>
                                </div>
                                <div class="form-hint">Giriş paneli, butonlar ve firma adı için ana renk.</div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Dosya Yükleme Ayarları -->
                <div class="settings-tab <?php echo $active_tab === 'file_upload' ? 'active' : ''; ?>" id="file_upload-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-cloud-upload-alt"></i> Dosya Yükleme Ayarları</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label>İzin Verilen Dosya Formatları</label>
                            <div class="file-types-grid">
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="jpg" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('jpg', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">JPEG Resim (.jpg)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="jpeg" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('jpeg', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">JPEG Resim (.jpeg)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="png" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('png', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">PNG Resim (.png)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="pdf" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('pdf', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">PDF Dokümanı (.pdf)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="doc" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('doc', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Word Dokümanı (.doc)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="docx" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('docx', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Word Dokümanı (.docx)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="xls" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('xls', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Excel Tablosu (.xls)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="xlsx" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('xlsx', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Excel Tablosu (.xlsx)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="txt" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('txt', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Metin Dosyası (.txt)</span>
                                    </label>
                                </div>
                                
                                <div class="file-type-item">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="allowed_file_types[]" value="zip" 
                                               <?php checked(isset($settings['file_upload_settings']['allowed_file_types']) && in_array('zip', $settings['file_upload_settings']['allowed_file_types'])); ?>>
                                        <span class="checkbox-text">Arşiv Dosyası (.zip)</span>
                                    </label>
                                </div>
                            </div>
                            <div class="form-hint">Sistem içinde yüklenebilecek dosya türlerini seçin. Seçili olmayan dosya türleri yüklenemez.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Meslekler -->
                <div class="settings-tab <?php echo $active_tab === 'occupations' ? 'active' : ''; ?>" id="occupations-tab">
                    <div class="tab-header">
                        <h2><i class="fas fa-briefcase"></i> Meslekler</h2>
                    </div>
                    <div class="tab-content">
                        <div class="form-group">
                            <label for="default_occupations">Varsayılan Meslekler</label>
                            <textarea name="default_occupations" id="default_occupations" class="form-control" rows="10"><?php echo esc_textarea(implode("\n", $settings['occupation_settings']['default_occupations'])); ?></textarea>
                            <div class="form-hint">Her satıra bir meslek adı yazın. Bu liste müşteri formlarında seçenek olarak sunulacaktır.</div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="submit_settings" class="btn btn-primary">
                                <i class="fas fa-save"></i> Ayarları Kaydet
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.boss-settings-container {
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
    display: flex;
    align-items: center;
    justify-content: center;
}

.page-header h1 i {
    margin-right: 10px;
    color: #3498db;
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

/* Ayarlar Konteyneri */
.settings-container {
    display: flex;
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    overflow: hidden;
}

.settings-sidebar {
    width: 250px;
    background-color: #f8f9fa;
    border-right: 1px solid #eaeaea;
    flex-shrink: 0;
}

.sidebar-header {
    padding: 15px;
    border-bottom: 1px solid #eaeaea;
}

.sidebar-header h3 {
    margin: 0;
    font-size: 16px;
    color: #333;
    display: flex;
    align-items: center;
}

.sidebar-header h3 i {
    margin-right: 8px;
    color: #3498db;
}

.settings-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.settings-menu li {
    padding: 12px 15px;
    font-size: 14px;
    cursor: pointer;
    border-bottom: 1px solid #eaeaea;
    display: flex;
    align-items: center;
    transition: all 0.2s ease;
}

.settings-menu li i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
    color: #6c757d;
}

.settings-menu li:hover {
    background-color: #e9ecef;
}

.settings-menu li.active {
    background-color: #e3f2fd;
    color: #0d6efd;
    font-weight: 500;
}

.settings-menu li.active i {
    color: #0d6efd;
}

.settings-content {
    flex: 1;
    padding: 20px;
    max-height: 800px;
    overflow-y: auto;
}

.settings-tab {
    display: none;
}

.settings-tab.active {
    display: block;
}

.tab-header {
    margin-bottom: 20px;
    border-bottom: 1px solid #eaeaea;
    padding-bottom: 10px;
}

.tab-header h2 {
    margin: 0;
    font-size: 20px;
    color: #333;
    display: flex;
    align-items: center;
}

.tab-header h2 i {
    margin-right: 10px;
    color: #3498db;
}

.tab-content {
    padding: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -10px;
    margin-left: -10px;
    margin-bottom: 15px;
}

.col-md-6 {
    padding-right: 10px;
    padding-left: 10px;
    flex: 0 0 50%;
    max-width: 50%;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #333;
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

textarea.form-control {
    resize: vertical;
    min-height: 100px;
}

.form-hint {
    margin-top: 5px;
    font-size: 12px;
    color: #6c757d;
}

.checkbox-label {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    margin-right: 8px;
}

.checkbox-text {
    font-weight: normal;
}

/* Dosya Türleri Izgara */
.file-types-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    background-color: #f9f9f9;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
}

.file-type-item {
    padding: 5px;
}

/* Renk Seçici */
.color-picker-container {
    display: flex;
    align-items: center;
}

.color-picker {
    width: 50px;
    height: 30px;
    padding: 0;
    border: none;
    border-radius: 4px;
    margin-right: 10px;
}

.color-value {
    font-family: monospace;
    font-size: 14px;
    color: #495057;
}

.form-actions {
    margin-top: 30px;
    padding-top: 15px;
    border-top: 1px solid #eaeaea;
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
    padding: 10px 16px;
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

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (max-width: 768px) {
    .settings-container {
        flex-direction: column;
    }
    
    .settings-sidebar {
        width: 100%;
        margin-bottom: 20px;
    }
    
    .settings-menu {
        display: flex;
        flex-wrap: wrap;
    }
    
    .settings-menu li {
        flex: 0 0 50%;
        box-sizing: border-box;
    }
    
    .col-md-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .file-types-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sekme değiştirme fonksiyonu
    const menuItems = document.querySelectorAll('.settings-menu li');
    const tabs = document.querySelectorAll('.settings-tab');
    const activeTabInput = document.getElementById('active_tab');
    
    menuItems.forEach(item => {
        item.addEventListener('click', () => {
            const tabId = item.dataset.tab;
            
            // Aktif menü öğesini değiştir
            menuItems.forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            
            // Aktif sekmeyi değiştir
            tabs.forEach(tab => {
                tab.classList.remove('active');
                if (tab.id === tabId + '-tab') {
                    tab.classList.add('active');
                    activeTabInput.value = tabId;
                }
            });
        });
    });
    
    // Renk seçici değiştiğinde değeri güncelle
    const colorPicker = document.getElementById('primary_color');
    const colorValue = document.querySelector('.color-value');
    
    if (colorPicker && colorValue) {
        colorPicker.addEventListener('input', function() {
            colorValue.textContent = this.value;
        });
    }
});
</script>
                            