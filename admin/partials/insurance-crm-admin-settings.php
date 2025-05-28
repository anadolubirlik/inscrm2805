<?php
/**
 * Ayarlar Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.0 (2025-05-02)
 * @version    1.1.14 (2025-05-23)
 */

if (!defined('WPINC')) {
    die;
}

// Logo yükleme işlemi için ayrı form gönderimi
$logo_upload_error = '';
$logo_upload_success = '';
$logo_uploaded_url = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo_nonce']) && wp_verify_nonce($_POST['upload_logo_nonce'], 'upload_logo_action')) {
    if (!current_user_can('manage_options')) {
        $logo_upload_error = 'Yetkisiz işlem: Yönetici izni gerekiyor.';
    } else {
        // Yükleme dizininin yazılabilirliğini kontrol et
        $upload_dir = wp_upload_dir();
        if (!is_writable($upload_dir['basedir'])) {
            error_log('Insurance CRM Logo Upload Error: Upload directory is not writable - ' . $upload_dir['basedir']);
            $logo_upload_error = 'Yükleme dizinine yazma izni yok: ' . $upload_dir['basedir'];
        } else {
            // PHP yapılandırmasını kontrol et
            $upload_max_filesize = ini_get('upload_max_filesize');
            $post_max_size = ini_get('post_max_size');
            if (wp_convert_hr_to_bytes($upload_max_filesize) < 5 * 1024 * 1024 || wp_convert_hr_to_bytes($post_max_size) < 5 * 1024 * 1024) {
                error_log('Insurance CRM Logo Upload Error: PHP upload limits too low - upload_max_filesize: ' . $upload_max_filesize . ', post_max_size: ' . $post_max_size);
                $logo_upload_error = 'PHP dosya yükleme limitleri çok düşük. upload_max_filesize: ' . $upload_max_filesize . ', post_max_size: ' . $post_max_size . '. Minimum 5MB olmalı.';
            } else {
                if (isset($_FILES['logo_file']) && !empty($_FILES['logo_file']['name'])) {
                    $file = $_FILES['logo_file'];

                    // Dosya türünü kontrol et
                    $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');
                    if (!in_array($file['type'], $allowed_types)) {
                        error_log('Insurance CRM Logo Upload Error: Invalid file type - ' . $file['type']);
                        $logo_upload_error = 'Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.';
                    } else {
                        // Dosya boyutunu kontrol et (5MB sınırı)
                        if ($file['size'] > 5 * 1024 * 1024) {
                            error_log('Insurance CRM Logo Upload Error: File size exceeds 5MB - ' . $file['size']);
                            $logo_upload_error = 'Dosya boyutu 5MB\'dan büyük olamaz.';
                        } else {
                            // WordPress’in medya yükleme fonksiyonunu kullan
                            require_once(ABSPATH . 'wp-admin/includes/file.php');
                            require_once(ABSPATH . 'wp-admin/includes/media.php');
                            require_once(ABSPATH . 'wp-admin/includes/image.php');

                            $attachment_id = media_handle_upload('logo_file', 0);

                            if (is_wp_error($attachment_id)) {
                                error_log('Insurance CRM Logo Upload Error: ' . $attachment_id->get_error_message());
                                $logo_upload_error = 'Dosya yüklenemedi: ' . $attachment_id->get_error_message();
                            } else {
                                // Yüklenen dosyanın URL’sini al
                                $url = wp_get_attachment_url($attachment_id);
                                if (!$url) {
                                    error_log('Insurance CRM Logo Upload Error: Could not retrieve attachment URL for ID ' . $attachment_id);
                                    $logo_upload_error = 'Yüklenen dosyanın URL’si alınamadı.';
                                } else {
                                    // Mevcut ayarları al ve güncelle
                                    $settings = get_option('insurance_crm_settings', array());
                                    $settings['site_appearance']['login_logo'] = $url;
                                    update_option('insurance_crm_settings', $settings);
                                    $logo_upload_success = 'Logo başarıyla yüklendi! Değişiklikleri kaydetmek için lütfen "Ayarları Kaydet" butonuna tıklayın.';
                                    $logo_uploaded_url = $url;
                                }
                            }
                        }
                    }
                } else {
                    $logo_upload_error = 'Dosya seçilmedi.';
                }
            }
        }
    }
}

// Ayarları kaydet (diğer ayarlar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insurance_crm_settings_nonce'])) {
    if (!wp_verify_nonce($_POST['insurance_crm_settings_nonce'], 'insurance_crm_save_settings')) {
        wp_die(__('Güvenlik doğrulaması başarısız', 'insurance-crm'));
    }

    // Mevcut ayarları al
    $existing_settings = get_option('insurance_crm_settings', array());

    // Yeni ayarları hazırla
    $new_settings = array(
        'company_name' => sanitize_text_field($_POST['company_name']),
        'company_email' => sanitize_email($_POST['company_email']),
        'renewal_reminder_days' => intval($_POST['renewal_reminder_days']),
        'task_reminder_days' => intval($_POST['task_reminder_days']),
        'default_policy_types' => array_map('sanitize_text_field', explode("\n", trim($_POST['default_policy_types']))),
        'insurance_companies' => array_map('sanitize_text_field', explode("\n", trim($_POST['insurance_companies']))),
        'default_task_types' => array_map('sanitize_text_field', explode("\n", trim($_POST['default_task_types']))),
        'notification_settings' => array(
            'email_notifications' => isset($_POST['email_notifications']),
            'renewal_notifications' => isset($_POST['renewal_notifications']),
            'task_notifications' => isset($_POST['task_notifications'])
        ),
        'email_templates' => array(
            'renewal_reminder' => wp_kses_post($_POST['renewal_reminder_template']),
            'task_reminder' => wp_kses_post($_POST['task_reminder_template']),
            'new_policy' => wp_kses_post($_POST['new_policy_template'])
        ),
        'site_appearance' => array(
            'login_logo' => sanitize_text_field($_POST['login_logo']),
            'font_family' => sanitize_text_field($_POST['font_family']),
            'primary_color' => sanitize_hex_color($_POST['primary_color'])
        ),
        'file_upload_settings' => array(
            'allowed_file_types' => isset($_POST['allowed_file_types']) ? array_map('sanitize_text_field', $_POST['allowed_file_types']) : array()
        ),
        'occupation_settings' => array(
            'default_occupations' => array_map('sanitize_text_field', explode("\n", trim($_POST['default_occupations'])))
        )
    );

    // Mevcut ayarlarla yeni ayarları birleştir
    $settings = array_replace_recursive($existing_settings, $new_settings);

    // Ayarları kaydet
    update_option('insurance_crm_settings', $settings);
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Ayarlar başarıyla kaydedildi.', 'insurance-crm') . '</p></div>';
}

// Mevcut ayarları al (yeniden al, çünkü yükleme sonrası güncellenmiş olabilir)
$settings = get_option('insurance_crm_settings', array());

// Varsayılan değerler
if (!isset($settings['insurance_companies'])) {
    $settings['insurance_companies'] = array();
}
if (!isset($settings['site_appearance'])) {
    $settings['site_appearance'] = array(
        'login_logo' => '',
        'font_family' => 'Arial, sans-serif',
        'primary_color' => '#2980b9'
    );
}
if (!isset($settings['file_upload_settings'])) {
    $settings['file_upload_settings'] = array(
        'allowed_file_types' => array('jpg', 'jpeg', 'pdf', 'docx')
    );
}
if (!isset($settings['occupation_settings'])) {
    $settings['occupation_settings'] = array(
        'default_occupations' => array('Doktor', 'Mühendis', 'Öğretmen', 'Avukat')
    );
}

// Mevcut dosya türlerini al
$allowed_file_types = $settings['file_upload_settings']['allowed_file_types'];
// Mevcut meslekleri al
$default_occupations = $settings['occupation_settings']['default_occupations'];

// Aktif sekme bilgisini al (varsa)
$active_tab = isset($_POST['active_tab']) ? sanitize_text_field($_POST['active_tab']) : '#general';
?>

<div class="wrap insurance-crm-wrap">
    <h1><?php _e('Insurance CRM Ayarları', 'insurance-crm'); ?></h1>

    <?php if (!empty($logo_upload_error)): ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html($logo_upload_error); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($logo_upload_success)): ?>
        <div class="notice notice-success is-dismissible" id="logo-upload-success" data-url="<?php echo esc_attr($logo_uploaded_url); ?>">
            <p><?php echo esc_html($logo_upload_success); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" class="insurance-crm-settings-form" enctype="multipart/form-data" id="settings-form">
        <?php wp_nonce_field('insurance_crm_save_settings', 'insurance_crm_settings_nonce'); ?>
        <input type="hidden" name="active_tab" id="active_tab" value="<?php echo esc_attr($active_tab); ?>">

        <div class="insurance-crm-settings-tabs">
            <nav class="nav-tab-wrapper">
                <a href="#general" class="nav-tab <?php echo $active_tab === '#general' ? 'nav-tab-active' : ''; ?>"><?php _e('Genel', 'insurance-crm'); ?></a>
                <a href="#notifications" class="nav-tab <?php echo $active_tab === '#notifications' ? 'nav-tab-active' : ''; ?>"><?php _e('Bildirimler', 'insurance-crm'); ?></a>
                <a href="#templates" class="nav-tab <?php echo $active_tab === '#templates' ? 'nav-tab-active' : ''; ?>"><?php _e('E-posta Şablonları', 'insurance-crm'); ?></a>
                <a href="#site-appearance" class="nav-tab <?php echo $active_tab === '#site-appearance' ? 'nav-tab-active' : ''; ?>"><?php _e('Site Görünümü', 'insurance-crm'); ?></a>
                <a href="#file-upload-settings" class="nav-tab <?php echo $active_tab === '#file-upload-settings' ? 'nav-tab-active' : ''; ?>"><?php _e('Dosya Yükleme Ayarları', 'insurance-crm'); ?></a>
                <a href="#occupations" class="nav-tab <?php echo $active_tab === '#occupations' ? 'nav-tab-active' : ''; ?>"><?php _e('Meslekler', 'insurance-crm'); ?></a>
            </nav>

            <!-- Genel Ayarlar -->
            <div id="general" class="insurance-crm-settings-tab <?php echo $active_tab === '#general' ? 'active' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="company_name"><?php _e('Şirket Adı', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="company_name" id="company_name" class="regular-text" 
                                   value="<?php echo esc_attr($settings['company_name']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="company_email"><?php _e('Şirket E-posta', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="email" name="company_email" id="company_email" class="regular-text" 
                                   value="<?php echo esc_attr($settings['company_email']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="renewal_reminder_days"><?php _e('Yenileme Hatırlatma (Gün)', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="renewal_reminder_days" id="renewal_reminder_days" class="small-text" 
                                   value="<?php echo esc_attr($settings['renewal_reminder_days']); ?>" min="1" max="90">
                            <p class="description"><?php _e('Poliçe yenileme hatırlatması için kaç gün önceden bildirim gönderilsin?', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="task_reminder_days"><?php _e('Görev Hatırlatma (Gün)', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="task_reminder_days" id="task_reminder_days" class="small-text" 
                                   value="<?php echo esc_attr($settings['task_reminder_days']); ?>" min="1" max="30">
                            <p class="description"><?php _e('Görev hatırlatması için kaç gün önceden bildirim gönderilsin?', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_policy_types"><?php _e('Varsayılan Poliçe Türleri', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_policy_types" id="default_policy_types" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['default_policy_types'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir poliçe türü yazın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="insurance_companies"><?php _e('Sigorta Firmaları', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="insurance_companies" id="insurance_companies" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['insurance_companies'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir sigorta firması yazın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="default_task_types"><?php _e('Varsayılan Görev Türleri', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_task_types" id="default_task_types" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['default_task_types'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir görev türü yazın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Bildirim Ayarları -->
            <div id="notifications" class="insurance-crm-settings-tab <?php echo $active_tab === '#notifications' ? 'active' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('E-posta Bildirimleri', 'insurance-crm'); ?></th>
                        <td>
                            <fieldset>
                                <label for="email_notifications">
                                    <input type="checkbox" name="email_notifications" id="email_notifications" 
                                           <?php checked($settings['notification_settings']['email_notifications']); ?>>
                                    <?php _e('E-posta bildirimlerini etkinleştir', 'insurance-crm'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Yenileme Bildirimleri', 'insurance-crm'); ?></th>
                        <td>
                            <fieldset>
                                <label for="renewal_notifications">
                                    <input type="checkbox" name="renewal_notifications" id="renewal_notifications" 
                                           <?php checked($settings['notification_settings']['renewal_notifications']); ?>>
                                    <?php _e('Poliçe yenileme bildirimlerini etkinleştir', 'insurance-crm'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Görev Bildirimleri', 'insurance-crm'); ?></th>
                        <td>
                            <fieldset>
                                <label for="task_notifications">
                                    <input type="checkbox" name="task_notifications" id="task_notifications" 
                                           <?php checked($settings['notification_settings']['task_notifications']); ?>>
                                    <?php _e('Görev bildirimlerini etkinleştir', 'insurance-crm'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- E-posta Şablonları -->
            <div id="templates" class="insurance-crm-settings-tab <?php echo $active_tab === '#templates' ? 'active' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="renewal_reminder_template"><?php _e('Yenileme Hatırlatma Şablonu', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['email_templates']['renewal_reminder'],
                                'renewal_reminder_template',
                                array(
                                    'textarea_name' => 'renewal_reminder_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false
                                )
                            );
                            ?>
                            <p class="description">
                                <?php _e('Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {end_date}, {premium_amount}', 'insurance-crm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="task_reminder_template"><?php _e('Görev Hatırlatma Şablonu', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['email_templates']['task_reminder'],
                                'task_reminder_template',
                                array(
                                    'textarea_name' => 'task_reminder_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false
                                )
                            );
                            ?>
                            <p class="description">
                                <?php _e('Kullanılabilir değişkenler: {customer_name}, {task_description}, {due_date}, {priority}', 'insurance-crm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="new_policy_template"><?php _e('Yeni Poliçe Bildirimi', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_editor(
                                $settings['email_templates']['new_policy'],
                                'new_policy_template',
                                array(
                                    'textarea_name' => 'new_policy_template',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false
                                )
                            );
                            ?>
                            <p class="description">
                                <?php _e('Kullanılabilir değişkenler: {customer_name}, {policy_number}, {policy_type}, {start_date}, {end_date}, {premium_amount}', 'insurance-crm'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Site Görünümü Ayarları -->
            <div id="site-appearance" class="insurance-crm-settings-tab <?php echo $active_tab === '#site-appearance' ? 'active' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="login_logo"><?php _e('Giriş Paneli Logo', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <!-- Ayrı form ile logo yükleme -->
                            <form method="post" enctype="multipart/form-data" class="logo-upload-form" id="logo-upload-form">
                                <?php wp_nonce_field('upload_logo_action', 'upload_logo_nonce'); ?>
                                <input type="hidden" name="active_tab" value="#site-appearance">
                                <div class="logo-upload-wrapper">
                                    <input type="text" name="login_logo" id="login_logo" class="regular-text" 
                                           value="<?php echo esc_attr($settings['site_appearance']['login_logo']); ?>">
                                    <input type="file" name="logo_file" id="logo_file" accept="image/*">
                                    <button type="submit" class="button button-secondary upload-logo-btn">
                                        <?php _e('Logo Yükle', 'insurance-crm'); ?>
                                    </button>
                                    <button type="button" class="button button-secondary remove-logo-btn" style="display: <?php echo !empty($settings['site_appearance']['login_logo']) ? 'inline-block' : 'none'; ?>;">
                                        <?php _e('Logoyu Kaldır', 'insurance-crm'); ?>
                                    </button>
                                </div>
                            </form>
                            <p class="description"><?php _e('Giriş panelinde görünecek logo. Önerilen boyut: 180x60 piksel.', 'insurance-crm'); ?></p>
                            <?php if (!empty($settings['site_appearance']['login_logo'])): ?>
                                <div class="logo-preview" style="margin-top: 10px;">
                                    <img src="<?php echo esc_url($settings['site_appearance']['login_logo']); ?>" alt="Logo Önizleme" style="max-width: 300px; max-height: 100px; object-fit: contain;">
                                </div>
                            <?php else: ?>
                                <div class="logo-preview" style="margin-top: 10px; display: none;">
                                    <img src="" alt="Logo Önizleme" style="max-width: 300px; max-height: 100px; object-fit: contain;">
                                </div>
                            <?php endif; ?>
                            <!-- Manuel URL girişi için alternatif -->
                            <div class="manual-logo-url" style="margin-top: 10px;">
                                <label for="manual_logo_url"><?php _e('Alternatif: Logo URL’sini manuel girin', 'insurance-crm'); ?></label>
                                <input type="text" name="manual_logo_url" id="manual_logo_url" class="regular-text" 
                                       placeholder="https://example.com/logo.png" value="<?php echo esc_attr($logo_uploaded_url); ?>">
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="font_family"><?php _e('Font Ailesi', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="font_family" id="font_family" class="regular-text" 
                                   value="<?php echo esc_attr($settings['site_appearance']['font_family']); ?>">
                            <p class="description"><?php _e('Örnek: "Arial, sans-serif" veya "Open Sans, sans-serif".', 'insurance-crm'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="primary_color"><?php _e('Ana Renk', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <input type="color" name="primary_color" id="primary_color" 
                                   value="<?php echo esc_attr($settings['site_appearance']['primary_color']); ?>">
                            <p class="description"><?php _e('Giriş paneli, butonlar ve firma adı için ana renk.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Dosya Yükleme Ayarları -->
            <div id="file-upload-settings" class="insurance-crm-settings-tab <?php echo $active_tab === '#file-upload-settings' ? 'active' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php _e('İzin Verilen Dosya Formatları', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span><?php _e('İzin Verilen Dosya Formatları', 'insurance-crm'); ?></span></legend>
                                <div class="insurance-crm-checkbox-group">
                                    <label for="file_type_jpg">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_jpg" value="jpg" 
                                               <?php checked(in_array('jpg', $allowed_file_types)); ?>>
                                        <?php _e('JPEG Resim Dosyaları (.jpg)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_jpeg">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_jpeg" value="jpeg" 
                                               <?php checked(in_array('jpeg', $allowed_file_types)); ?>>
                                        <?php _e('JPEG Resim Dosyaları (.jpeg)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_png">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_png" value="png" 
                                               <?php checked(in_array('png', $allowed_file_types)); ?>>
                                        <?php _e('PNG Resim Dosyaları (.png)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_pdf">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_pdf" value="pdf" 
                                               <?php checked(in_array('pdf', $allowed_file_types)); ?>>
                                        <?php _e('PDF Dokümanları (.pdf)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_doc">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_doc" value="doc" 
                                               <?php checked(in_array('doc', $allowed_file_types)); ?>>
                                        <?php _e('Word Dokümanları (.doc)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_docx">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_docx" value="docx" 
                                               <?php checked(in_array('docx', $allowed_file_types)); ?>>
                                        <?php _e('Word Dokümanları (.docx)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_xls">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_xls" value="xls" 
                                               <?php checked(in_array('xls', $allowed_file_types)); ?>>
                                        <?php _e('Excel Tabloları (.xls)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_xlsx">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_xlsx" value="xlsx" 
                                               <?php checked(in_array('xlsx', $allowed_file_types)); ?>>
                                        <?php _e('Excel Tabloları (.xlsx)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_txt">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_txt" value="txt" 
                                               <?php checked(in_array('txt', $allowed_file_types)); ?>>
                                        <?php _e('Metin Dosyaları (.txt)', 'insurance-crm'); ?>
                                    </label>
                                    <label for="file_type_zip">
                                        <input type="checkbox" name="allowed_file_types[]" id="file_type_zip" value="zip" 
                                               <?php checked(in_array('zip', $allowed_file_types)); ?>>
                                        <?php _e('Arşiv Dosyaları (.zip)', 'insurance-crm'); ?>
                                    </label>
                                </div>
                            </fieldset>
                            <p class="description"><?php _e('Seçili formatlar dışındaki dosyaların sistem tarafından reddedileceğini unutmayın.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Meslekler Ayarları -->
            <div id="occupations" class="insurance-crm-settings-tab <?php echo $active_tab === '#occupations' ? 'active' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="default_occupations"><?php _e('Varsayılan Meslekler', 'insurance-crm'); ?></label>
                        </th>
                        <td>
                            <textarea name="default_occupations" id="default_occupations" class="large-text code" rows="6"><?php 
                                echo esc_textarea(implode("\n", $settings['occupation_settings']['default_occupations'])); 
                            ?></textarea>
                            <p class="description"><?php _e('Her satıra bir meslek yazın. Bu meslekler, müşteri formunda dropdown menüde listelenecektir.', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button(__('Ayarları Kaydet', 'insurance-crm'), 'primary', 'submit', true, ['id' => 'submit-settings']); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab yönetimi
    $('.insurance-crm-settings-tabs nav a').click(function(e) {
        e.preventDefault();
        var tab = $(this).attr('href').substring(1);
        
        // Tab butonlarını güncelle
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Tab içeriklerini güncelle
        $('.insurance-crm-settings-tab').removeClass('active');
        $('#' + tab).addClass('active');

        // Aktif sekme bilgisini gizli input’a yaz
        $('#active_tab').val('#' + tab);
    });

    // Logoyu kaldırma
    $('.remove-logo-btn').on('click', function(e) {
        e.preventDefault();

        var inputField = $('#login_logo');
        var previewContainer = $('.logo-preview');
        var button = $(this);
        var manualUrlField = $('#manual_logo_url');

        // Input alanlarını temizle
        inputField.val('');
        manualUrlField.val('');

        // Önizlemeyi gizle
        previewContainer.hide();

        // Kaldır butonunu gizle
        button.hide();

        // Dosya input’unu sıfırla
        $('#logo_file').val('');
    });

    // Manuel logo URL’si girildiğinde önizlemeyi güncelle
    $('#manual_logo_url').on('input', function() {
        var url = $(this).val();
        var previewContainer = $('.logo-preview');
        var previewImage = previewContainer.find('img');
        var inputField = $('#login_logo');
        var removeButton = $('.remove-logo-btn');

        if (url) {
            inputField.val(url);
            previewImage.attr('src', url);
            previewContainer.show();
            removeButton.show();
        } else {
            inputField.val('');
            previewContainer.hide();
            removeButton.hide();
        }
    });

    // Logo yükleme başarılı olduğunda URL’yi manual_logo_url alanına yaz ve uyarı göster
    if ($('#logo-upload-success').length) {
        var uploadedUrl = $('#logo-upload-success').data('url');
        if (uploadedUrl) {
            $('#manual_logo_url').val(uploadedUrl);
            $('#login_logo').val(uploadedUrl);

            var previewContainer = $('.logo-preview');
            var previewImage = previewContainer.find('img');
            var removeButton = $('.remove-logo-btn');

            previewImage.attr('src', uploadedUrl);
            previewContainer.show();
            removeButton.show();

            // Ayarları Kaydet butonuna tıklama uyarısı göster
            var saveButton = $('#submit-settings');
            saveButton.css('border', '2px solid #ff0000').css('background-color', '#ffe6e6');
            saveButton.after('<p class="description save-warning" style="color: #ff0000; margin-top: 5px;">Değişiklikleri kaydetmek için lütfen "Ayarları Kaydet" butonuna tıklayın.</p>');

            // 3 saniye sonra otomatik tıklama (isteğe bağlı)
            setTimeout(function() {
                saveButton.click();
            }, 3000);
        }
    }

    // Sayfa yüklendiğinde aktif sekme ve login_logo değerini kontrol et
    var activeTab = $('#active_tab').val();
    if (activeTab) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('.insurance-crm-settings-tab').removeClass('active');
        $('a[href="' + activeTab + '"]').addClass('nav-tab-active');
        $(activeTab).addClass('active');
    }

    var loginLogo = $('#login_logo').val();
    if (loginLogo) {
        var previewContainer = $('.logo-preview');
        var previewImage = previewContainer.find('img');
        var removeButton = $('.remove-logo-btn');

        previewImage.attr('src', loginLogo);
        previewContainer.show();
        removeButton.show();
    }
});
</script>

<style>
.insurance-crm-settings-tabs {
    margin-top: 20px;
}

.insurance-crm-settings-tab {
    display: none;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-top: none;
}

.insurance-crm-settings-tab.active {
    display: block;
}

.insurance-crm-settings-form .form-table th {
    width: 300px;
}

.insurance-crm-settings-form .description {
    margin-top: 5px;
    color: #666;
}

.insurance-crm-checkbox-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
    padding: 10px 0;
}

.insurance-crm-checkbox-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    padding: 8px 12px;
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    transition: background 0.2s ease;
}

.insurance-crm-checkbox-group label:hover {
    background: #f0f0f0;
}

.insurance-crm-checkbox-group input[type="checkbox"] {
    margin: 0;
    width: 16px;
    height: 16px;
}

.logo-upload-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.logo-upload-wrapper .regular-text {
    padding: 6px 8px;
    font-size: 13px;
}

.logo-upload-wrapper .button {
    padding: 4px 12px;
    font-size: 13px;
    line-height: 1.5;
}

.logo-preview img {
    display: block;
    border: 1px solid #ddd;
    padding: 5px;
    background: #f9f9f9;
}

.manual-logo-url {
    margin-top: 10px;
}

.manual-logo-url label {
    display: block;
    margin-bottom: 5px;
}

.manual-logo-url input {
    width: 100%;
}

.save-warning {
    font-weight: bold;
}
</style>