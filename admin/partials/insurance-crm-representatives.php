<?php
/**
 * Müşteri Temsilcileri Sayfası
 *
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/admin/partials
 * @author     Anadolu Birlik
 * @since      1.0.3
 * @version    1.1.0
 */

if (!defined('WPINC')) {
    die;
}

// Veritabanı güncelleme işlemi - Role alanı ve yetkilendirme alanlarını ekleme
function insurance_crm_update_representative_fields() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'insurance_crm_representatives';
    
    // Role sütunu var mı kontrol et
    $role_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'role'");
    
    if(empty($role_column_exists)) {
        // Önce title sütununun varlığını kontrol et
        $title_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'title'");
        
        if(!empty($title_column_exists)) {
            // Title sütunu varsa, role sütununu ekle ve title değerlerini kopyala
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `role` INT NOT NULL DEFAULT 5 AFTER `user_id`;");
            error_log('Role alanı veritabanına eklendi.');
            
            // Title alanını silme işlemi bir sonraki güncellemeye bırakılabilir
            // $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN `title`;");
            // error_log('Title alanı veritabanından kaldırıldı.');
        } else {
            // Title sütunu yoksa, sadece role sütununu ekle
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `role` INT NOT NULL DEFAULT 5 AFTER `user_id`;");
            error_log('Role alanı veritabanına eklendi.');
        }
    }

    // Hedef Poliçe Adedi sütunu var mı kontrol et
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'target_policy_count'");
    
    if(empty($column_exists)) {
        // Sütun yoksa ekle
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `target_policy_count` INT NOT NULL DEFAULT 0 AFTER `monthly_target`;");
        error_log('Hedef Poliçe Adedi alanı veritabanına eklendi.');
    }

    // Avatar URL sütunu için kontrol
    $avatar_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'avatar_url'");
    if (empty($avatar_column_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `avatar_url` VARCHAR(255) DEFAULT '' AFTER `target_policy_count`;");
        error_log('Avatar URL alanı veritabanına eklendi.');
    }
    
    // YETKİLENDİRME SÜTUNLARI İÇİN KONTROL VE EKLEME İŞLEMİ
    // Müşteri Düzenleme Yetkisi
    $customer_edit_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'customer_edit'");
    if (empty($customer_edit_column_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `customer_edit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `avatar_url`;");
        error_log('Müşteri Düzenleme Yetkisi alanı veritabanına eklendi.');
    }
    
    // Müşteri Silme Yetkisi
    $customer_delete_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'customer_delete'");
    if (empty($customer_delete_column_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `customer_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_edit`;");
        error_log('Müşteri Silme Yetkisi alanı veritabanına eklendi.');
    }
    
    // Poliçe Düzenleme Yetkisi
    $policy_edit_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'policy_edit'");
    if (empty($policy_edit_column_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `policy_edit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `customer_delete`;");
        error_log('Poliçe Düzenleme Yetkisi alanı veritabanına eklendi.');
    }
    
    // Poliçe Silme Yetkisi
    $policy_delete_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'policy_delete'");
    if (empty($policy_delete_column_exists)) {
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN `policy_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `policy_edit`;");
        error_log('Poliçe Silme Yetkisi alanı veritabanına eklendi.');
    }
}

// Veritabanını güncelle
insurance_crm_update_representative_fields();

// Sekme yönetimi
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'representatives';

// Aksiyon kontrolü
$action = isset($_GET['action']) ? $_GET['action'] : '';
$rep_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = ($action === 'edit' && $rep_id > 0);
$adding = ($action === 'new');
$edit_rep = null;

// Rol tanımları
$role_definitions = array(
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
);

// Düzenleme için temsilci bilgilerini al
if ($editing) {
    global $wpdb;
    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
    
    // Debug için tablo adını görelim
    error_log('Tablo adı: ' . $table_reps);
    
    // Önce sadece temsilci kaydını al
    $rep_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_reps} WHERE id = %d",
        $rep_id
    ));
    
    if ($rep_record) {
        // Temsilci kaydı varsa, kullanıcı bilgilerini al
        $user_data = get_userdata($rep_record->user_id);
        
        if ($user_data) {
            // Kullanıcı verilerini ve temsilci verilerini birleştir
            $edit_rep = (object) array_merge(
                (array) $rep_record,
                array(
                    'email' => $user_data->user_email,
                    'display_name' => $user_data->display_name,
                    'username' => $user_data->user_login,
                    'first_name' => $user_data->first_name,
                    'last_name' => $user_data->last_name
                )
            );
        } else {
            // Kullanıcı kaydı yok ama temsilci kaydı var
            $edit_rep = $rep_record;
            $edit_rep->email = '';
            $edit_rep->display_name = 'Kullanıcı Kaydı Yok';
            $edit_rep->username = '';
            $edit_rep->first_name = '';
            $edit_rep->last_name = '';
            
            echo '<div class="notice notice-warning"><p>Bu temsilcinin WordPress kullanıcı kaydı bulunamadı (User ID: ' . $rep_record->user_id . ').</p></div>';
        }
    } else {
        // Temsilci kaydı bulunamadı
        echo '<div class="notice notice-error"><p>Temsilci kaydı bulunamadı. (ID: ' . $rep_id . ')</p>';
        
        // Mevcut temsilcileri listeleyelim - debug için
        $all_reps = $wpdb->get_results("SELECT id, user_id FROM {$table_reps}");
        echo '<p>Mevcut temsilci kayıtları:</p><ul>';
        if ($all_reps) {
            foreach ($all_reps as $rep) {
                echo '<li>ID: ' . $rep->id . ', User ID: ' . $rep->user_id . '</li>';
            }
        } else {
            echo '<li>Hiç temsilci kaydı bulunamadı.</li>';
        }
        echo '</ul></div>';
        
        $editing = false;
    }
}

// Form gönderildiğinde işlem yap
if (isset($_POST['submit_representative']) && isset($_POST['representative_nonce']) && 
    wp_verify_nonce($_POST['representative_nonce'], 'add_edit_representative')) {
    
    global $wpdb;
    $table_reps = $wpdb->prefix . 'insurance_crm_representatives';
    $avatar_url = '';

    // Avatar Yükleme İşlemi
    if (isset($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');

        if (!in_array($file['type'], $allowed_types)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.', 'insurance-crm') . '</p></div>';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Dosya boyutu 5MB\'dan büyük olamaz.', 'insurance-crm') . '</p></div>';
        } else {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $attachment_id = media_handle_upload('avatar_file', 0);

            if (is_wp_error($attachment_id)) {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Dosya yüklenemedi: ', 'insurance-crm') . $attachment_id->get_error_message() . '</p></div>';
            } else {
                $avatar_url = wp_get_attachment_url($attachment_id);
            }
        }
    }

    // Yetkilendirme değerlerini kontrol et
    $customer_edit = isset($_POST['customer_edit']) ? 1 : 0;
    $customer_delete = isset($_POST['customer_delete']) ? 1 : 0;
    $policy_edit = isset($_POST['policy_edit']) ? 1 : 0;
    $policy_delete = isset($_POST['policy_delete']) ? 1 : 0;

    if ($editing && isset($_POST['rep_id'])) {
        // Mevcut temsilciyi güncelle
        $rep_data = array(
            'role' => intval($_POST['role']),
            'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '',  // Geçiş dönemi için title alanını da güncelle
            'phone' => sanitize_text_field($_POST['phone']),
            'department' => sanitize_text_field($_POST['department']),
            'monthly_target' => floatval($_POST['monthly_target']),
            'target_policy_count' => intval($_POST['target_policy_count']),
            'customer_edit' => $customer_edit,
            'customer_delete' => $customer_delete,
            'policy_edit' => $policy_edit,
            'policy_delete' => $policy_delete,
            'updated_at' => current_time('mysql')
        );

        // Avatar URL'i güncelle (yeni dosya yüklendiyse)
        if (!empty($avatar_url)) {
            $rep_data['avatar_url'] = $avatar_url;
        } else {
            // Mevcut avatar_url'i koru
            $existing_rep = $wpdb->get_row($wpdb->prepare("SELECT avatar_url FROM $table_reps WHERE id = %d", intval($_POST['rep_id'])));
            $rep_data['avatar_url'] = $existing_rep->avatar_url;
        }
        
        $update_result = $wpdb->update(
            $table_reps,
            $rep_data,
            array('id' => intval($_POST['rep_id']))
        );
        
        if ($update_result === false) {
            echo '<div class="notice notice-error"><p>Güncelleme sırasında bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
        } else {
            // Kullanıcı bilgilerini güncelle
            if (isset($_POST['first_name']) && isset($_POST['last_name']) && isset($_POST['email']) && isset($edit_rep->user_id)) {
                $user_id = $edit_rep->user_id;
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => sanitize_text_field($_POST['first_name']),
                    'last_name' => sanitize_text_field($_POST['last_name']),
                    'display_name' => sanitize_text_field($_POST['first_name']) . ' ' . sanitize_text_field($_POST['last_name']),
                    'user_email' => sanitize_email($_POST['email'])
                ));
                
                // Kullanıcının rolünü de güncelle
                $user = new WP_User($user_id);
                $user->set_role('insurance_representative');
            }
            
            // Şifre değiştirme kontrolü
            if (!empty($_POST['password']) && !empty($_POST['confirm_password']) && $_POST['password'] === $_POST['confirm_password'] && isset($edit_rep->user_id)) {
                wp_set_password($_POST['password'], $edit_rep->user_id);
            }
            
            echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
            
            // Şifre değiştirilmişse, güncellemeden sonra sayfayı yenile 
            if (!empty($_POST['password'])) {
                echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-representatives') . '";</script>';
            } else {
                // Düzenleme bitince listeye dön
                echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-representatives&updated=1') . '";</script>';
            }
        }
    } elseif ($adding || !isset($_POST['rep_id'])) {
        // Yeni temsilci oluştur
        if (isset($_POST['username']) && isset($_POST['password']) && isset($_POST['confirm_password'])) {
            $username = sanitize_user($_POST['username']);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            
            if (empty($username) || empty($password) || empty($confirm_password)) {
                echo '<div class="notice notice-error"><p>Kullanıcı adı ve şifre alanlarını doldurunuz.</p></div>';
            } else if ($password !== $confirm_password) {
                echo '<div class="notice notice-error"><p>Şifreler eşleşmiyor.</p></div>';
            } else if (username_exists($username)) {
                echo '<div class="notice notice-error"><p>Bu kullanıcı adı zaten kullanımda.</p></div>';
            } else if (email_exists($_POST['email'])) {
                echo '<div class="notice notice-error"><p>Bu e-posta adresi zaten kullanımda.</p></div>';
            } else {
                // Kullanıcı oluştur
                $user_id = wp_create_user($username, $password, sanitize_email($_POST['email']));
                
                if (!is_wp_error($user_id)) {
                    // Kullanıcı detaylarını güncelle
                    wp_update_user(
                        array(
                            'ID' => $user_id,
                            'first_name' => sanitize_text_field($_POST['first_name']),
                            'last_name' => sanitize_text_field($_POST['last_name']),
                            'display_name' => sanitize_text_field($_POST['first_name']) . ' ' . sanitize_text_field($_POST['last_name'])
                        )
                    );
                    
                    // Kullanıcıya rol ata
                    $user = new WP_User($user_id);
                    $user->set_role('insurance_representative');
                    
                    // Seçilen rolü al
                    $selected_role = isset($_POST['role']) ? intval($_POST['role']) : 5;
                    
                    // Müşteri temsilcisi kaydı oluştur
                    $insert_result = $wpdb->insert(
                        $table_reps,
                        array(
                            'user_id' => $user_id,
                            'role' => $selected_role,
                            'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : $role_definitions[$selected_role], // Geçiş dönemi için title alanını da doldur
                            'phone' => sanitize_text_field($_POST['phone']),
                            'department' => sanitize_text_field($_POST['department']),
                            'monthly_target' => floatval($_POST['monthly_target']),
                            'target_policy_count' => intval($_POST['target_policy_count']),
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
                    
                    if ($insert_result !== false) {
                        echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla eklendi.</p></div>';
                        
                        // Ekleme işlemi bitince listeye dön
                        echo '<script>window.location.href = "' . admin_url('admin.php?page=insurance-crm-representatives&added=1') . '";</script>';
                    } else {
                        echo '<div class="notice notice-error"><p>Temsilci kaydı oluşturulurken bir hata oluştu: ' . $wpdb->last_error . '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Kullanıcı oluşturulurken bir hata oluştu: ' . $user_id->get_error_message() . '</p></div>';
                }
            }
        } else {
            echo '<div class="notice notice-error"><p>Gerekli alanlar doldurulmadı.</p></div>';
        }
    }
}

// Ekip Ekleme/Düzenleme İşlemleri
if (isset($_POST['submit_team']) && isset($_POST['team_nonce']) && 
    wp_verify_nonce($_POST['team_nonce'], 'add_edit_team')) {
    
    $team_name = sanitize_text_field($_POST['team_name']);
    $team_leader_id = intval($_POST['team_leader_id']);
    $team_members = isset($_POST['team_members']) ? array_map('intval', $_POST['team_members']) : array();
    $team_id = isset($_POST['team_id']) ? sanitize_text_field($_POST['team_id']) : 'team_' . uniqid();
    
    $settings = get_option('insurance_crm_settings', array());
    if (!isset($settings['teams_settings'])) {
        $settings['teams_settings'] = array('teams' => array());
    }
    
    // Ekip bilgilerini kaydet
    $settings['teams_settings']['teams'][$team_id] = array(
        'name' => $team_name,
        'leader_id' => $team_leader_id,
        'members' => $team_members
    );
    
    update_option('insurance_crm_settings', $settings);
    
    echo '<div class="notice notice-success"><p>Ekip bilgileri başarıyla kaydedildi.</p></div>';
}

// Yönetim Hiyerarşisi Kaydetme İşlemi
if (isset($_POST['submit_hierarchy']) && isset($_POST['hierarchy_nonce']) && 
    wp_verify_nonce($_POST['hierarchy_nonce'], 'update_management_hierarchy')) {
    
    $patron_id = isset($_POST['patron_id']) ? intval($_POST['patron_id']) : 0;
    $manager_id = isset($_POST['manager_id']) ? intval($_POST['manager_id']) : 0;
    $assistant_manager_ids = isset($_POST['assistant_manager_ids']) ? array_map('intval', $_POST['assistant_manager_ids']) : array();
    
    $settings = get_option('insurance_crm_settings', array());
    if (!isset($settings['management_hierarchy'])) {
        $settings['management_hierarchy'] = array();
    }
    
    // Yönetim hiyerarşisini kaydet
    $settings['management_hierarchy'] = array(
        'patron_id' => $patron_id,
        'manager_id' => $manager_id,
        'assistant_manager_ids' => $assistant_manager_ids,
        'updated_at' => current_time('mysql')
    );
    
    update_option('insurance_crm_settings', $settings);
    
    echo '<div class="notice notice-success"><p>Yönetim hiyerarşisi başarıyla güncellendi.</p></div>';
}

// Ekip Silme İşlemi
if ($active_tab === 'teams' && isset($_GET['delete_team']) && isset($_GET['_wpnonce'])) {
    $team_id = sanitize_text_field($_GET['delete_team']);
    
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_team_' . $team_id)) {
        $settings = get_option('insurance_crm_settings', array());
        
        if (isset($settings['teams_settings']['teams'][$team_id])) {
            unset($settings['teams_settings']['teams'][$team_id]);
            update_option('insurance_crm_settings', $settings);
            echo '<div class="notice notice-success"><p>Ekip başarıyla silindi.</p></div>';
        }
    }
}

// Silme işlemi
if ($action === 'delete' && $rep_id > 0) {
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_representative_' . $rep_id)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'insurance_crm_representatives';
        
        // Önce kullanıcı ID'sini al
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table_name} WHERE id = %d",
            $rep_id
        ));
        
        if ($user_id) {
            // WordPress kullanıcısını sil
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            
            // Kullanıcıyı silme işlemi
            if (wp_delete_user($user_id)) {
                // Temsilci kaydını sil
                $wpdb->delete($table_name, array('id' => $rep_id));
                echo '<div class="notice notice-success"><p>Müşteri temsilcisi başarıyla silindi.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Kullanıcı silinirken bir hata oluştu.</p></div>';
            }
        } else {
            // Temsilci kaydını sil
            $wpdb->delete($table_name, array('id' => $rep_id));
            echo '<div class="notice notice-success"><p>Müşteri temsilcisi kaydı silindi.</p></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p>Geçersiz silme işlemi.</p></div>';
    }
}

// İşlem mesajları
if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    echo '<div class="notice notice-success"><p>Müşteri temsilcisi güncellendi.</p></div>';
}

if (isset($_GET['added']) && $_GET['added'] === '1') {
    echo '<div class="notice notice-success"><p>Yeni müşteri temsilcisi eklendi.</p></div>';
}

// Mevcut temsilcileri listele
global $wpdb;
$table_name = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results(
    "SELECT r.*, u.user_email as email, u.display_name 
     FROM {$table_name} r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active' 
     ORDER BY r.created_at DESC"
);

// Ekipleri al
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

// Yönetim hiyerarşisini al
$management_hierarchy = isset($settings['management_hierarchy']) ? $settings['management_hierarchy'] : array(
    'patron_id' => 0, 
    'manager_id' => 0,
    'assistant_manager_ids' => array()
);

// Müdür yardımcıları için array oluştur, eğer yoksa boş array kullan
if (!isset($management_hierarchy['assistant_manager_ids'])) {
    $management_hierarchy['assistant_manager_ids'] = array();
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Müşteri Temsilcileri Yönetimi</h1>
    
    <?php if (!$editing && !$adding): ?>
        <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&action=new'); ?>" class="page-title-action">Yeni Temsilci Ekle</a>
    <?php endif; ?>
    
    <hr class="wp-header-end">
    
    <?php if (!$editing && !$adding): ?>
    <!-- Sekme Menüsü -->
    <h2 class="nav-tab-wrapper">
        <a href="?page=insurance-crm-representatives&tab=representatives" class="nav-tab <?php echo $active_tab === 'representatives' ? 'nav-tab-active' : ''; ?>">Müşteri Temsilcileri</a>
        <a href="?page=insurance-crm-representatives&tab=teams" class="nav-tab <?php echo $active_tab === 'teams' ? 'nav-tab-active' : ''; ?>">Ekipler</a>
        <a href="?page=insurance-crm-representatives&tab=hierarchy" class="nav-tab <?php echo $active_tab === 'hierarchy' ? 'nav-tab-active' : ''; ?>">Yönetim Hiyerarşisi</a>
    </h2>
    
    <?php if ($active_tab === 'representatives'): ?>
    <!-- TEMSİLCİLER LİSTESİ -->
    <div class="insurance-crm-table-container">
        <table class="wp-list-table widefat fixed striped representatives">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Ad Soyad</th>
                    <th>E-posta</th>
                    <th>Rol</th>
                    <th>Telefon</th>
                    <th>Departman</th>
                    <th>Aylık Hedef</th>
                    <th>Hedef Poliçe Adedi</th>
                    <th>Yetkiler</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($representatives)): ?>
                    <tr>
                        <td colspan="10">Hiç müşteri temsilcisi bulunamadı.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($representatives as $rep): ?>
                    <tr>
                        <td><?php echo esc_html($rep->id); ?></td>
                        <td><?php echo esc_html($rep->display_name); ?></td>
                        <td><?php echo esc_html($rep->email); ?></td>
                        <td>
                            <?php 
                            // Role değeri varsa onu göster, yoksa title'ı göster
                            if(isset($rep->role)) {
                                echo isset($role_definitions[$rep->role]) ? esc_html($role_definitions[$rep->role]) : esc_html("Müşteri Temsilcisi");
                            } else {
                                echo esc_html($rep->title);
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($rep->phone); ?></td>
                        <td><?php echo esc_html($rep->department); ?></td>
                        <td>₺<?php echo number_format($rep->monthly_target, 2); ?></td>
                        <td><?php echo isset($rep->target_policy_count) ? intval($rep->target_policy_count) : 0; ?> Adet</td>
                        <td>
                            <?php 
                            $permissions = array();
                            if (isset($rep->customer_edit) && $rep->customer_edit) $permissions[] = "MüşteriD";
                            if (isset($rep->customer_delete) && $rep->customer_delete) $permissions[] = "MüşteriS";
                            if (isset($rep->policy_edit) && $rep->policy_edit) $permissions[] = "PoliçeD";
                            if (isset($rep->policy_delete) && $rep->policy_delete) $permissions[] = "PoliçeS";
                            
                            if (!empty($permissions)) {
                                echo implode(", ", $permissions);
                            } else {
                                echo "-";
                            }
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=insurance-crm-representatives&action=edit&id=' . $rep->id)); ?>" 
                               class="button button-small">
                                Düzenle
                            </a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=insurance-crm-representatives&action=delete&id=' . $rep->id), 'delete_representative_' . $rep->id)); ?>" 
                               class="button button-small delete-representative" 
                               onclick="return confirm('Bu müşteri temsilcisini silmek istediğinizden emin misiniz?');">
                                Sil
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php elseif ($active_tab === 'teams'): ?>
    <!-- EKİPLER SEKMESİ İÇERİĞİ -->
    <div class="insurance-crm-table-container teams-container">
        <div class="teams-header">
            <h3>Ekip Yönetimi</h3>
            <p class="description">Müşteri Temsilcilerini ekiplere ayırın ve ekip liderlerini belirleyin.</p>
            <a href="?page=insurance-crm-representatives&tab=teams&action=new_team" class="button button-primary">Yeni Ekip Oluştur</a>
        </div>

        <?php if (isset($_GET['action']) && $_GET['action'] === 'new_team' || isset($_GET['action']) && $_GET['action'] === 'edit_team'): ?>
            <?php
            $edit_team = null;
            $edit_team_id = '';
            
            if (isset($_GET['action']) && $_GET['action'] === 'edit_team' && isset($_GET['team_id'])) {
                $edit_team_id = sanitize_text_field($_GET['team_id']);
                if (isset($teams[$edit_team_id])) {
                    $edit_team = $teams[$edit_team_id];
                }
            }
            ?>
            <!-- Ekip Ekleme/Düzenleme Formu -->
            <div class="team-form-container">
                <h3><?php echo $edit_team ? 'Ekibi Düzenle' : 'Yeni Ekip Oluştur'; ?></h3>
                <form method="post" action="?page=insurance-crm-representatives&tab=teams">
                    <?php wp_nonce_field('add_edit_team', 'team_nonce'); ?>
                    
                    <?php if ($edit_team): ?>
                        <input type="hidden" name="team_id" value="<?php echo esc_attr($edit_team_id); ?>">
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="team_name">Ekip Adı <span class="required">*</span></label></th>
                            <td>
                                <input type="text" name="team_name" id="team_name" class="regular-text" required
                                       value="<?php echo $edit_team ? esc_attr($edit_team['name']) : ''; ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="team_leader_id">Ekip Lideri <span class="required">*</span></label></th>
                            <td>
                                <select name="team_leader_id" id="team_leader_id" class="regular-text" required>
                                    <option value="">-- Ekip Lideri Seçin --</option>
                                    <?php foreach ($representatives as $rep): ?>
                                        <option value="<?php echo esc_attr($rep->id); ?>" 
                                                <?php selected($edit_team && $edit_team['leader_id'] == $rep->id); ?>>
                                            <?php echo esc_html($rep->display_name . ' (' . (isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title) . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="team_members">Ekip Üyeleri</label></th>
                            <td>
                                <div class="team-members-selection">
                                    <?php 
                                    $selected_members = $edit_team ? $edit_team['members'] : array();
                                    foreach ($representatives as $rep):
                                        // Lider aynı zamanda üye olamaz
                                        if ($edit_team && $edit_team['leader_id'] == $rep->id) continue;
                                    ?>
                                        <label class="team-member-checkbox">
                                            <input type="checkbox" name="team_members[]" value="<?php echo esc_attr($rep->id); ?>"
                                                   <?php checked(in_array($rep->id, $selected_members)); ?>>
                                            <?php echo esc_html($rep->display_name . ' (' . (isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title) . ')'); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description">Bu ekibe dahil olacak müşteri temsilcilerini seçin.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit_team" class="button button-primary" 
                               value="<?php echo $edit_team ? 'Ekibi Güncelle' : 'Ekip Oluştur'; ?>">
                        <a href="?page=insurance-crm-representatives&tab=teams" class="button">İptal</a>
                    </p>
                </form>
            </div>
        <?php else: ?>
            <!-- Ekipler Listesi -->
            <?php if (empty($teams)): ?>
                <div class="notice notice-info">
                    <p>Henüz hiç ekip oluşturulmamış. İlk ekibinizi oluşturmak için "Yeni Ekip Oluştur" butonunu kullanın.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped teams">
                    <thead>
                        <tr>
                            <th>Ekip Adı</th>
                            <th>Ekip Lideri</th>
                            <th>Üye Sayısı</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $team_id => $team): 
                            // Ekip lideri bilgilerini al
                            $leader_info = '---';
                            foreach ($representatives as $rep) {
                                if ($rep->id == $team['leader_id']) {
                                    $role_display = isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title;
                                    $leader_info = $rep->display_name . ' (' . $role_display . ')';
                                    break;
                                }
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo esc_html($leader_info); ?></td>
                                <td><?php echo count($team['members']); ?> Üye</td>
                                <td>
                                    <a href="?page=insurance-crm-representatives&tab=teams&action=edit_team&team_id=<?php echo esc_attr($team_id); ?>" 
                                       class="button button-small">
                                        Düzenle
                                    </a>
                                    <a href="<?php echo esc_url(wp_nonce_url('?page=insurance-crm-representatives&tab=teams&delete_team=' . $team_id, 'delete_team_' . $team_id)); ?>" 
                                       class="button button-small" 
                                       onclick="return confirm('Bu ekibi silmek istediğinizden emin misiniz?');">
                                        Sil
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <?php elseif ($active_tab === 'hierarchy'): ?>
    <!-- YÖNETİM HİYERARŞİSİ SEKMESİ -->
    <div class="insurance-crm-table-container hierarchy-container">
        <div class="hierarchy-header">
            <h3>Yönetim Hiyerarşisi</h3>
            <p class="description">Patron, Müdür ve Müdür Yardımcılarını belirleyin. Müdür ve yardımcıları tüm ekip liderlerini yönetirler.</p>
        </div>

        <!-- Yönetim Hiyerarşisi Düzenleme Formu -->
        <form method="post" action="?page=insurance-crm-representatives&tab=hierarchy">
            <?php wp_nonce_field('update_management_hierarchy', 'hierarchy_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="patron_id">Patron (En Üst Yönetici)</label></th>
                    <td>
                        <select name="patron_id" id="patron_id" class="regular-text">
                            <option value="0">-- Patron Seçin --</option>
                            <?php foreach ($representatives as $rep): ?>
                                <option value="<?php echo esc_attr($rep->id); ?>" 
                                        <?php selected($management_hierarchy['patron_id'] == $rep->id); ?>>
                                    <?php echo esc_html($rep->display_name . ' (' . (isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Organizasyonun en üst yöneticisini seçin.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="manager_id">Müdür (Ekip Liderleri Yöneticisi)</label></th>
                    <td>
                        <select name="manager_id" id="manager_id" class="regular-text">
                            <option value="0">-- Müdür Seçin --</option>
                            <?php foreach ($representatives as $rep): 
                                // Patron zaten seçildiyse, onu müdür seçeneklerinden çıkar
                                if ($management_hierarchy['patron_id'] == $rep->id) continue;
                            ?>
                                <option value="<?php echo esc_attr($rep->id); ?>" 
                                        <?php selected($management_hierarchy['manager_id'] == $rep->id); ?>>
                                    <?php echo esc_html($rep->display_name . ' (' . (isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Tüm ekipleri ve ekip liderlerini yönetecek müdürü seçin.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="assistant_managers">Müdür Yardımcıları</label></th>
                    <td>
                        <div class="assistant-manager-selection">
                            <?php foreach ($representatives as $rep): 
                                // Patron veya Müdür zaten seçildiyse, onları müdür yardımcısı seçeneklerinden çıkar
                                if ($management_hierarchy['patron_id'] == $rep->id || $management_hierarchy['manager_id'] == $rep->id) continue;
                            ?>
                                <label class="assistant-manager-checkbox">
                                    <input type="checkbox" name="assistant_manager_ids[]" value="<?php echo esc_attr($rep->id); ?>"
                                           <?php checked(in_array($rep->id, $management_hierarchy['assistant_manager_ids'])); ?>>
                                    <?php echo esc_html($rep->display_name . ' (' . (isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title) . ')'); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">Müdüre yardımcı olacak kişileri seçin. Birden fazla seçebilirsiniz.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_hierarchy" class="button button-primary" value="Yönetim Hiyerarşisini Kaydet">
            </p>
        </form>

        <!-- Görsel Hiyerarşi Şeması -->
        <div class="hierarchy-visualization">
            <h3>Organizasyon Şeması</h3>
            
            <div class="hierarchy-chart">
                <?php 
                $patron_name = '(Seçilmemiş)';
                $patron_title = '';
                $manager_name = '(Seçilmemiş)';
                $manager_title = '';
                
                // Patron bilgilerini al
                if ($management_hierarchy['patron_id'] > 0) {
                    foreach ($representatives as $rep) {
                        if ($rep->id == $management_hierarchy['patron_id']) {
                            $patron_name = $rep->display_name;
                            $patron_title = isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title;
                            break;
                        }
                    }
                }
                
                // Müdür bilgilerini al
                if ($management_hierarchy['manager_id'] > 0) {
                    foreach ($representatives as $rep) {
                        if ($rep->id == $management_hierarchy['manager_id']) {
                            $manager_name = $rep->display_name;
                            $manager_title = isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title;
                            break;
                        }
                    }
                }
                ?>
                
                <!-- Hiyerarşi Şeması -->
                <div class="org-chart">
                    <div class="org-level patron-level">
                        <div class="org-box patron-box">
                            <div class="org-title">Patron</div>
                            <div class="org-name"><?php echo esc_html($patron_name); ?></div>
                            <?php if (!empty($patron_title)): ?>
                                <div class="org-subtitle"><?php echo esc_html($patron_title); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="org-connector"></div>
                    
                    <div class="org-level manager-level">
                        <div class="org-box manager-box">
                            <div class="org-title">Müdür</div>
                            <div class="org-name"><?php echo esc_html($manager_name); ?></div>
                            <?php if (!empty($manager_title)): ?>
                                <div class="org-subtitle"><?php echo esc_html($manager_title); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($management_hierarchy['assistant_manager_ids'])): ?>
                    <div class="org-connector"></div>
                    
                    <div class="org-level assistant-managers-level">
                        <?php foreach ($management_hierarchy['assistant_manager_ids'] as $assistant_id): 
                            $assistant_name = '---';
                            $assistant_title = '';
                            
                            foreach ($representatives as $rep) {
                                if ($rep->id == $assistant_id) {
                                    $assistant_name = $rep->display_name;
                                    $assistant_title = isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title;
                                    break;
                                }
                            }
                        ?>
                            <div class="org-box assistant-manager-box">
                                <div class="org-title">Müdür Yardımcısı</div>
                                <div class="org-name"><?php echo esc_html($assistant_name); ?></div>
                                <?php if (!empty($assistant_title)): ?>
                                    <div class="org-subtitle"><?php echo esc_html($assistant_title); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="org-connector"></div>
                    
                    <?php if (!empty($teams)): ?>
                    <div class="org-level team-leaders-level">
                        <?php foreach ($teams as $team_id => $team): 
                            $leader_name = '---';
                            $leader_title = '';
                            
                            foreach ($representatives as $rep) {
                                if ($rep->id == $team['leader_id']) {
                                    $leader_name = $rep->display_name;
                                    $leader_title = isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title;
                                    break;
                                }
                            }
                        ?>
                            <div class="org-box team-leader-box">
                                <div class="org-title">Ekip Lideri</div>
                                <div class="org-name"><?php echo esc_html($leader_name); ?></div>
                                <?php if (!empty($leader_title)): ?>
                                    <div class="org-subtitle"><?php echo esc_html($leader_title); ?></div>
                                <?php endif; ?>
                                <div class="org-team-name"><?php echo esc_html($team['name']); ?> Ekibi</div>
                                <div class="org-team-count"><?php echo count($team['members']); ?> Üye</div>
                                
                                <?php if (!empty($team['members'])): ?>
                                <div class="org-connector team-member-connector"></div>
                                <div class="team-members-container">
                                    <?php foreach ($team['members'] as $member_id): 
                                        $member_name = '';
                                        $member_title = '';
                                        
                                        foreach ($representatives as $rep) {
                                            if ($rep->id == $member_id) {
                                                $member_name = $rep->display_name;
                                                $member_title = isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title;
                                                break;
                                            }
                                        }
                                        
                                        if (!empty($member_name)):
                                    ?>
                                        <div class="org-box team-member-box">
                                            <div class="org-name"><?php echo esc_html($member_name); ?></div>
                                            <?php if (!empty($member_title)): ?>
                                                <div class="org-subtitle"><?php echo esc_html($member_title); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="org-level team-leaders-level empty-level">
                        <div class="org-box empty-box">
                            <div class="org-title">Henüz Ekip Yok</div>
                            <p>Ekip oluşturmak için "Ekipler" sekmesini kullanın.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <!-- TEMSİLCİ DÜZENLEME / EKLEME FORMU -->
    <div class="insurance-crm-form-container">
        <h2><?php echo $editing ? 'Müşteri Temsilcisini Düzenle' : 'Yeni Müşteri Temsilcisi Ekle'; ?></h2>
        <form method="post" action="" class="insurance-crm-form" enctype="multipart/form-data">
            <?php wp_nonce_field('add_edit_representative', 'representative_nonce'); ?>
            
            <?php if ($editing): ?>
                <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">
            <?php endif; ?>
            
            <div class="insurance-crm-form-section">
                <h3>Kullanıcı Bilgileri</h3>
                
                <table class="form-table">
                    <?php if (!$editing): ?>
                        <tr>
                            <th><label for="username">Kullanıcı Adı <span class="required">*</span></label></th>
                            <td><input type="text" name="username" id="username" class="regular-text" required></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>Kullanıcı Adı</th>
                            <td><strong><?php echo esc_html($edit_rep->username); ?></strong> (Kullanıcı adı değiştirilemez)</td>
                        </tr>
                    <?php endif; ?>
                        
                    <tr>
                        <th><label for="password">Şifre <?php echo $editing ? '' : '<span class="required">*</span>'; ?></label></th>
                        <td>
                            <input type="password" name="password" id="password" class="regular-text" <?php echo !$editing ? 'required' : ''; ?>>
                            <?php if ($editing): ?>
                                <p class="description">Şifreyi değiştirmek için yeni şifre girin veya mevcut şifreyi korumak için boş bırakın.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="confirm_password">Şifre (Tekrar) <?php echo $editing ? '' : '<span class="required">*</span>'; ?></label></th>
                        <td><input type="password" name="confirm_password" id="confirm_password" class="regular-text" <?php echo !$editing ? 'required' : ''; ?>></td>
                    </tr>
                    <tr>
                        <th><label for="first_name">Ad <span class="required">*</span></label></th>
                        <td>
                            <input type="text" name="first_name" id="first_name" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->first_name) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="last_name">Soyad <span class="required">*</span></label></th>
                        <td>
                            <input type="text" name="last_name" id="last_name" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->last_name) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email">E-posta <span class="required">*</span></label></th>
                        <td>
                            <input type="email" name="email" id="email" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->email) : ''; ?>">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="insurance-crm-form-section">
                <h3>Temsilci Bilgileri</h3>
                
                <table class="form-table">
                    <tr>
                        <th><label for="role">Rol <span class="required">*</span></label></th>
                        <td>
                            <select name="role" id="role" class="regular-text" required>
                                <?php foreach ($role_definitions as $role_id => $role_name): ?>
                                    <option value="<?php echo $role_id; ?>" 
                                            <?php selected($editing && isset($edit_rep->role) && $edit_rep->role == $role_id); ?>>
                                        <?php echo esc_html($role_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Temsilcinin sistemdeki rolü</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="phone">Telefon <span class="required">*</span></label></th>
                        <td>
                            <input type="tel" name="phone" id="phone" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->phone) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="department">Departman</label></th>
                        <td>
                            <input type="text" name="department" id="department" class="regular-text"
                                   value="<?php echo $editing ? esc_attr($edit_rep->department) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="monthly_target">Aylık Hedef (₺) <span class="required">*</span></label></th>
                        <td>
                            <input type="number" step="0.01" min="0" name="monthly_target" id="monthly_target" class="regular-text" required
                                   value="<?php echo $editing ? esc_attr($edit_rep->monthly_target) : ''; ?>">
                            <p class="description">Temsilcinin aylık satış hedefi (₺)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="target_policy_count">Hedef Poliçe Adedi <span class="required">*</span></label></th>
                        <td>
                            <input type="number" step="1" min="0" name="target_policy_count" id="target_policy_count" class="regular-text" required
                                   value="<?php echo $editing && isset($edit_rep->target_policy_count) ? intval($edit_rep->target_policy_count) : '0'; ?>">
                            <p class="description">Temsilcinin aylık hedef poliçe adedi</p>
                        </td>
                    </tr>
                    
                    <tr id="permissions_section">
                        <th><label>Yetkiler</label></th>
                        <td>
                            <div class="permissions-container">
                                <h4>Müşteri ve Poliçe İşlem Yetkileri</h4>
                                <p class="description">Temsilciye verilecek özellikleri seçin. Patron rolü tüm yetkilere sahiptir.</p>
                                
                                <div class="permissions-grid">
                                    <div class="permission-row">
                                        <label>
                                            <input type="checkbox" name="customer_edit" value="1" 
                                                <?php checked($editing && isset($edit_rep->customer_edit) && $edit_rep->customer_edit == 1); ?>>
                                            <span>Müşteri Düzenleme</span>
                                        </label>
                                        <p class="description">Müşteri bilgilerini düzenleyebilir</p>
                                    </div>
                                    
                                    <div class="permission-row">
                                        <label>
                                            <input type="checkbox" name="customer_delete" value="1" 
                                                <?php checked($editing && isset($edit_rep->customer_delete) && $edit_rep->customer_delete == 1); ?>>
                                            <span>Müşteri Silme</span>
                                        </label>
                                        <p class="description">Müşteri kaydını pasife alabilir/silebilir</p>
                                    </div>
                                    
                                    <div class="permission-row">
                                        <label>
                                            <input type="checkbox" name="policy_edit" value="1" 
                                                <?php checked($editing && isset($edit_rep->policy_edit) && $edit_rep->policy_edit == 1); ?>>
                                            <span>Poliçe Düzenleme</span>
                                        </label>
                                        <p class="description">Poliçe bilgilerini düzenleyebilir</p>
                                    </div>
                                    
                                    <div class="permission-row">
                                        <label>
                                            <input type="checkbox" name="policy_delete" value="1" 
                                                <?php checked($editing && isset($edit_rep->policy_delete) && $edit_rep->policy_delete == 1); ?>>
                                            <span>Poliçe Silme</span>
                                        </label>
                                        <p class="description">Poliçe kaydını pasife alabilir/silebilir</p>
                                    </div>
                                </div>
                                
                                <div id="role_permission_message" class="permission-message"></div>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="avatar_file">Avatar (Profil Fotoğrafı)</label></th>
                        <td>
                            <input type="file" name="avatar_file" id="avatar_file" accept="image/*">
                            <?php if ($editing && !empty($edit_rep->avatar_url)): ?>
                                <div class="avatar-preview" style="margin-top: 10px;">
                                    <img src="<?php echo esc_url($edit_rep->avatar_url); ?>" alt="Avatar Önizleme" style="max-width: 100px; max-height: 100px; object-fit: cover; border-radius: 50%;">
                                </div>
                            <?php endif; ?>
                            <p class="description"><?php _e('Temsilcinin profil fotoğrafı. Önerilen boyut: 100x100 piksel. (JPG, JPEG, PNG, GIF desteklenir, maksimum 5MB)', 'insurance-crm'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="submit">
                <input type="submit" name="submit_representative" class="button button-primary" 
                       value="<?php echo $editing ? 'Temsilciyi Güncelle' : 'Müşteri Temsilcisi Ekle'; ?>">
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives'); ?>" class="button">İptal</a>
            </p>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>
.insurance-crm-form-container {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-top: 20px;
}
.insurance-crm-form-section {
    margin-bottom: 20px;
}
.insurance-crm-form-section h3 {
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    margin-bottom: 15px;
}
.insurance-crm-table-container {
    background: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.required {
    color: #dc3232;
}

/* Teams Tab Styles */
.teams-container .teams-header,
.hierarchy-container .hierarchy-header {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.teams-container .teams-header h3,
.hierarchy-container .hierarchy-header h3 {
    margin-bottom: 5px;
}

.teams-container .teams-header .description,
.hierarchy-container .hierarchy-header .description {
    margin-bottom: 15px;
}

.team-form-container {
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    margin-bottom: 20px;
}

.team-members-selection, 
.assistant-manager-selection {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
}

.team-member-checkbox,
.assistant-manager-checkbox {
    display: block;
    margin-bottom: 5px;
}

.team-member-checkbox input,
.assistant-manager-checkbox input {
    margin-right: 5px;
}

table.teams th:first-child,
table.teams td:first-child {
    width: 30%;
}

/* Hierarchy Visualization */
.hierarchy-visualization {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.hierarchy-chart {
    margin-top: 20px;
}

.org-chart {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.org-level {
    display: flex;
    justify-content: center;
    width: 100%;
    margin-bottom: 10px;
    flex-wrap: wrap;
    gap: 15px;
}

.team-leaders-level, .assistant-managers-level {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 15px;
}

.org-box {
    padding: 15px;
    border-radius: 8px;
    text-align: center;
    width: 200px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.patron-box {
    background-color: #f0f7ff;
    border: 2px solid #4a89dc;
}

.manager-box {
    background-color: #fff5f0;
    border: 2px solid #e8864a;
}

.assistant-manager-box {
    background-color: #fff0e8;
    border: 2px solid #ffa566;
}

.team-leader-box {
    background-color: #f0f8f0;
    border: 2px solid #5cb85c;
    position: relative;
    margin-bottom: 30px;
}

.team-member-box {
    background-color: #f8f8f8;
    border: 1px solid #ddd;
    padding: 10px;
    margin: 5px;
    width: 150px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.empty-box {
    background-color: #f9f9f9;
    border: 2px dashed #ccc;
}

.org-title {
    font-weight: bold;
    font-size: 16px;
    margin-bottom: 5px;
    color: #444;
}

.org-name {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 2px;
}

.org-subtitle {
    font-style: italic;
    color: #666;
    font-size: 14px;
    margin-bottom: 5px;
}

.org-team-name {
    margin-top: 10px;
    font-weight: bold;
    color: #5cb85c;
}

.org-team-count {
    font-size: 12px;
    color: #777;
}

.org-connector {
    width: 2px;
    height: 20px;
    background-color: #999;
    margin: 5px 0;
}

.team-member-connector {
    height: 15px;
    margin-bottom: 5px;
}

.team-members-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 5px;
    margin-top: 5px;
}

.avatar-preview img {
    display: block;
    border: 1px solid #ddd;
    padding: 5px;
    background: #f9f9f9;
}

/* İzin Ayarları Stili */
.permissions-container {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 5px;
    border: 1px solid #e0e0e0;
}

.permissions-container h4 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 14px;
    color: #333;
}

.permissions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-top: 15px;
}

.permission-row {
    background-color: #fff;
    padding: 12px;
    border-radius: 4px;
    border: 1px solid #eee;
}

.permission-row label {
    display: flex;
    align-items: center;
    margin-bottom: 5px;
    font-weight: 500;
}

.permission-row label input {
    margin-right: 8px;
}

.permission-row .description {
    margin: 0;
    padding-left: 22px;
    color: #666;
    font-size: 12px;
}

.permission-message {
    margin-top: 15px;
    padding: 10px;
    border-radius: 4px;
    display: none;
}

.permission-message.patron {
    display: block;
    background-color: #f0f7ff;
    border: 1px solid #cce5ff;
    color: #0c5460;
}

.permission-message.manager {
    display: block;
    background-color: #fff5f0;
    border: 1px solid #ffccbc;
    color: #c53929;
}

@media (max-width: 768px) {
    .team-leaders-level, 
    .assistant-managers-level {
        flex-direction: column;
        align-items: center;
    }
    
    .org-box {
        width: 100%;
        max-width: 220px;
    }
    
    .team-members-container {
        flex-direction: column;
    }
    
    .team-member-box {
        width: 180px;
    }
    
    .permissions-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Şifre eşleşme kontrolü
    $('#confirm_password').on('blur', function() {
        var password = $('#password').val();
        var confirmPassword = $(this).val();
        
        if (password && confirmPassword && password !== confirmPassword) {
            alert('Şifreler eşleşmiyor!');
            $(this).val('').focus();
        }
    });
    
    // Form gönderildiğinde doğrulama
    $('form.insurance-crm-form').on('submit', function(e) {
        var password = $('#password').val();
        var confirmPassword = $('#confirm_password').val();
        
        if (password && password !== confirmPassword) {
            e.preventDefault();
            alert('Şifreler eşleşmiyor!');
            $('#confirm_password').focus();
            return false;
        }
        
        // Diğer doğrulamalar...
        return true;
    });

    // Ekip Lideri seçildiğinde, o kişiyi üyelerden çıkar
    $('#team_leader_id').on('change', function() {
        var leaderId = $(this).val();
        
        // Tüm checkboxları aktifleştir
        $('.team-member-checkbox input').prop('disabled', false);
        
        if (leaderId) {
            // Lideri üye listesinden çıkar
            $('.team-member-checkbox input[value="' + leaderId + '"]')
                .prop('checked', false)
                .prop('disabled', true);
        }
    });

    // Sayfa yüklendiğinde lideri kontrol et
    if ($('#team_leader_id').length) {
        $('#team_leader_id').trigger('change');
    }
    
    // Patron veya Müdür seçildiğinde, onları müdür yardımcısı seçeneklerinden çıkar
    function updateAssistantManagerOptions() {
        var patronId = $('#patron_id').val();
        var managerId = $('#manager_id').val();
        
        // Tüm müdür yardımcısı seçeneklerini aktifleştir
        $('.assistant-manager-checkbox input').prop('disabled', false);
        
        // Patron veya müdür olanları devre dışı bırak
        if (patronId) {
            $('.assistant-manager-checkbox input[value="' + patronId + '"]')
                .prop('checked', false)
                .prop('disabled', true);
        }
        
        if (managerId) {
            $('.assistant-manager-checkbox input[value="' + managerId + '"]')
                .prop('checked', false)
                .prop('disabled', true);
        }
    }
    
    // Patron seçildiğinde, o kişiyi müdür seçeneklerinden çıkar
    $('#patron_id').on('change', function() {
        var patronId = $(this).val();
        
        // Müdür select elementini yeniden oluştur
        var $managerSelect = $('#manager_id');
        var currentManagerId = $managerSelect.val();
        
        $managerSelect.find('option').remove().end()
            .append('<option value="0">-- Müdür Seçin --</option>');
        
        // Tüm temsilcileri döngüye al ve patron olmayanları ekle
        <?php foreach ($representatives as $rep): ?>
        if (patronId != <?php echo $rep->id; ?>) {
            $managerSelect.append('<option value="<?php echo $rep->id; ?>"><?php echo esc_js($rep->display_name . ' (' . (isset($rep->role) && isset($role_definitions[$rep->role]) ? $role_definitions[$rep->role] : $rep->title) . ')'); ?></option>');
        }
        <?php endforeach; ?>
        
        // Eğer önceki müdür seçimi hala geçerliyse (patron değilse), onu seç
        if (currentManagerId != patronId) {
            $managerSelect.val(currentManagerId);
        }
        
        // Müdür yardımcısı seçeneklerini güncelle
        updateAssistantManagerOptions();
    });
    
    // Müdür seçildiğinde, müdür yardımcısı seçeneklerini güncelle
    $('#manager_id').on('change', function() {
        updateAssistantManagerOptions();
    });
    
    // Sayfa yüklendiğinde kontrolleri başlat
    if ($('#patron_id').length) {
        $('#patron_id').trigger('change');
    }

    // Avatar dosya türü ve boyut kontrolü
    $('#avatar_file').on('change', function() {
        var file = this.files[0];
        if (file) {
            var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            var maxSize = 5 * 1024 * 1024; // 5MB

            if (!allowedTypes.includes(file.type)) {
                alert('Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.');
                $(this).val('');
                return;
            }

            if (file.size > maxSize) {
                alert('Dosya boyutu 5MB\'dan büyük olamaz.');
                $(this).val('');
                return;
            }
        }
    });

    // Rol Seçimine Göre İzin Mesajları ve Ayarları
    function updatePermissionsByRole() {
        var roleId = $('#role').val();
        var $permissionMessage = $('#role_permission_message');
        var $permissionCheckboxes = $('.permission-row input[type="checkbox"]');
        
        // Tüm mesaj stillerini temizle
        $permissionMessage.removeClass('patron manager');
        
        if (roleId == 1) { // Patron
            // Patron için tüm yetkiler otomatik seçilir
            $permissionCheckboxes.prop('checked', true);
            $permissionCheckboxes.prop('disabled', true);
            $permissionMessage.html('<strong>Patron rolü tüm yetkilere sahiptir.</strong> Bu ayarlar otomatik olarak seçilmiş ve kilitlenmiştir.');
            $permissionMessage.addClass('patron');
            $permissionMessage.show();
        } 
        else if (roleId == 2) { // Müdür
            // Müdür için yetkilendirme seçilebilir
            $permissionCheckboxes.prop('disabled', false);
            $permissionMessage.html('<strong>Müdür rolü için yetkileri özelleştirebilirsiniz.</strong> Patron gerekirse yetkiyi verebilir veya kaldırabilir.');
            $permissionMessage.addClass('manager');
            $permissionMessage.show();
        }
        else {
            // Diğer roller için yetkiler serbest seçilebilir
            $permissionCheckboxes.prop('disabled', false);
            $permissionMessage.hide();
        }
    }
    
    // Rol değiştiğinde yetkiler de değişsin
    $('#role').on('change', function() {
        updatePermissionsByRole();
    });
    
    // Sayfa yüklendiğinde rol ayarlarını kontrol et
    updatePermissionsByRole();
});
</script>