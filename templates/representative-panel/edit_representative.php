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

global $wpdb;
$current_user = wp_get_current_user();

// Kullanıcı yetkisi kontrolü - Sadece patron veya yönetici değişiklik yapabilir
$user_role = get_user_role_in_hierarchy($current_user->ID);
if ($user_role !== 'patron' && $user_role !== 'manager') {
    echo '<div class="notice notice-error"><p>Bu sayfaya erişim yetkiniz bulunmamaktadır.</p></div>';
    return;
}

// Temsilci bilgilerini al
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT r.*, u.display_name, u.user_email, u.user_login, u.user_nicename, u.ID as wp_user_id
     FROM {$wpdb->prefix}insurance_crm_representatives r
     JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.id = %d",
    $rep_id
));

if (!$representative) {
    echo '<div class="notice notice-error"><p>Temsilci bulunamadı.</p></div>';
    return;
}

// Form gönderildi mi kontrol et
$success_message = '';
$errors = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_representative_submit'])) {
    // Nonce kontrolü
    if (!isset($_POST['edit_representative_nonce']) || !wp_verify_nonce($_POST['edit_representative_nonce'], 'edit_representative_nonce')) {
        wp_die('Güvenlik kontrolü başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
    
    // Form verilerini al
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $monthly_target = isset($_POST['monthly_target']) ? floatval(str_replace(',', '.', $_POST['monthly_target'])) : 0;
    $target_policy_count = isset($_POST['target_policy_count']) ? intval($_POST['target_policy_count']) : 0;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role']) ? intval($_POST['role']) : $representative->role;
    
    // Form doğrulaması
    if (empty($first_name)) {
        $errors[] = 'Ad alanı zorunludur.';
    }
    if (empty($last_name)) {
        $errors[] = 'Soyad alanı zorunludur.';
    }
    if (empty($email)) {
        $errors[] = 'E-posta alanı zorunludur.';
    } elseif (!is_email($email)) {
        $errors[] = 'Geçerli bir e-posta adresi girin.';
    }
    if (empty($title)) {
        $errors[] = 'Unvan alanı zorunludur.';
    }
    
    // Şifre kontrolü (doldurulduysa)
    if (!empty($password) && strlen($password) < 8) {
        $errors[] = 'Şifre en az 8 karakter olmalıdır.';
    }
    
    // Avatar Yükleme İşlemi
    $avatar_url = $representative->avatar_url; // Mevcut avatarı koru
    
    if (isset($_FILES['avatar_file']) && !empty($_FILES['avatar_file']['name'])) {
        $file = $_FILES['avatar_file'];
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif');

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = 'Geçersiz dosya türü. Sadece JPG, JPEG, PNG ve GIF dosyalarına izin veriliyor.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Dosya boyutu 5MB\'dan büyük olamaz.';
        } else {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $attachment_id = media_handle_upload('avatar_file', 0);

            if (is_wp_error($attachment_id)) {
                $errors[] = 'Dosya yüklenemedi: ' . $attachment_id->get_error_message();
            } else {
                $avatar_url = wp_get_attachment_url($attachment_id);
            }
        }
    }
    
    // Hata yoksa güncelleme yap
    if (empty($errors)) {
        // WordPress kullanıcı bilgilerini güncelle
        $user_data = array(
            'ID' => $representative->wp_user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name
        );
        
        // E-posta güncellemesi
        if ($email !== $representative->user_email) {
            $user_data['user_email'] = $email;
        }
        
        // Şifre güncellemesi
        if (!empty($password)) {
            $user_data['user_pass'] = $password;
        }
        
        $user_update_result = wp_update_user($user_data);
        
        if (is_wp_error($user_update_result)) {
            $errors[] = 'Kullanıcı bilgileri güncellenirken hata oluştu: ' . $user_update_result->get_error_message();
        } else {
            // Temsilci tablosundaki bilgileri güncelle
            $wpdb->update(
                $wpdb->prefix . 'insurance_crm_representatives',
                array(
                    'title' => $title,
                    'phone' => $phone,
                    'monthly_target' => $monthly_target,
                    'target_policy_count' => $target_policy_count,
                    'status' => $status,
                    'notes' => $notes,
                    'avatar_url' => $avatar_url,
                    'role' => $role,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $rep_id)
            );
            
            if ($wpdb->last_error) {
                $errors[] = 'Temsilci bilgileri güncellenirken bir hata oluştu: ' . $wpdb->last_error;
            } else {
                $success_message = 'Temsilci bilgileri başarıyla güncellendi.';
                
                // Tekrar en güncel verileri al
                $representative = $wpdb->get_row($wpdb->prepare(
                    "SELECT r.*, u.display_name, u.user_email, u.user_login, u.user_nicename, u.ID as wp_user_id
                     FROM {$wpdb->prefix}insurance_crm_representatives r
                     JOIN {$wpdb->users} u ON r.user_id = u.ID
                     WHERE r.id = %d",
                    $rep_id
                ));
            }
        }
    }
}

// Rolleri al
$roles = array(
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
);

// Ekip bilgilerini al
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

$rep_team = null;
foreach ($teams as $team_id => $team) {
    if ($team['leader_id'] == $rep_id || in_array($rep_id, $team['members'])) {
        $rep_team = $team;
        $rep_team['id'] = $team_id;
        break;
    }
}

// Ekip lideri mi kontrol et
$is_team_leader = false;
foreach ($teams as $team) {
    if ($team['leader_id'] == $rep_id) {
        $is_team_leader = true;
        break;
    }
}
?>

<div class="edit-representative-container">
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
                <h1><?php echo esc_html($representative->display_name); ?> <small>(Düzenleme)</small></h1>
                <div class="rep-meta">
                    <span class="rep-email"><i class="dashicons dashicons-email"></i> <?php echo esc_html($representative->user_email); ?></span>
                    <span class="rep-username"><i class="dashicons dashicons-admin-users"></i> <?php echo esc_html($representative->user_login); ?></span>
                    <?php if ($rep_team): ?>
                        <span class="rep-team"><i class="dashicons dashicons-groups"></i> <?php echo esc_html($rep_team['name']); ?> Ekibi</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="rep-header-right">
            <a href="<?php echo generate_panel_url('representative_detail', '', $rep_id); ?>" class="button">
                <i class="dashicons dashicons-visibility"></i> Temsilci Detayına Dön
            </a>
            <a href="<?php echo generate_panel_url('all_representatives'); ?>" class="button">
                <i class="dashicons dashicons-list-view"></i> Tüm Temsilciler
            </a>
            <a href="<?php echo generate_panel_url('dashboard'); ?>" class="button">
                <i class="dashicons dashicons-dashboard"></i> Dashboard'a Dön
            </a>
        </div>
    </div>
    
    <?php if (!empty($success_message)): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html($success_message); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
    <div class="notice notice-error is-dismissible">
        <?php foreach($errors as $error): ?>
        <p><?php echo esc_html($error); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <form method="post" action="" enctype="multipart/form-data" class="edit-representative-form">
        <?php wp_nonce_field('edit_representative_nonce', 'edit_representative_nonce'); ?>
        
        <div class="form-tabs">
            <a href="#basic" class="tab-link active" data-tab="basic">
                <i class="dashicons dashicons-admin-users"></i> Temel Bilgiler
            </a>
            <a href="#targets" class="tab-link" data-tab="targets">
                <i class="dashicons dashicons-chart-bar"></i> Hedefler
            </a>
            <a href="#role" class="tab-link" data-tab="role">
                <i class="dashicons dashicons-businessperson"></i> Rol ve Yetki
            </a>
            <a href="#security" class="tab-link" data-tab="security">
                <i class="dashicons dashicons-shield"></i> Güvenlik
            </a>
        </div>
        
        <div class="form-content">
            <div class="tab-content active" id="basic">
                <div class="form-section">
                    <h2>Temel Bilgiler</h2>
                    <p>Temsilcinin kişisel ve iletişim bilgilerini güncelleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="avatar_file">Profil Fotoğrafı</label>
                            <input type="file" name="avatar_file" id="avatar_file" accept="image/jpeg,image/png,image/gif">
                            <p class="form-tip">Maksimum dosya boyutu: 5MB. İzin verilen türler: JPG, PNG, GIF</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="first_name">Ad <span class="required">*</span></label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr(get_user_meta($representative->wp_user_id, 'first_name', true)); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Soyad <span class="required">*</span></label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr(get_user_meta($representative->wp_user_id, 'last_name', true)); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-posta <span class="required">*</span></label>
                            <input type="email" name="email" id="email" value="<?php echo esc_attr($representative->user_email); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Telefon</label>
                            <input type="text" name="phone" id="phone" value="<?php echo esc_attr($representative->phone); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="title">Unvan <span class="required">*</span></label>
                            <input type="text" name="title" id="title" value="<?php echo esc_attr($representative->title); ?>" required>
                            <p class="form-tip">Örnek: Müşteri Temsilcisi, Uzman, Yönetici vs.</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Durum</label>
                            <select name="status" id="status">
                                <option value="active" <?php selected($representative->status, 'active'); ?>>Aktif</option>
                                <option value="passive" <?php selected($representative->status, 'passive'); ?>>Pasif</option>
                            </select>
                        </div>
                        
                        <div class="form-group col-span-2">
                            <label for="notes">Notlar</label>
                            <textarea name="notes" id="notes" rows="4"><?php echo esc_textarea($representative->notes); ?></textarea>
                            <p class="form-tip">Temsilci ile ilgili özel notlarınız (sadece yöneticiler görebilir)</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="targets">
                <div class="form-section">
                    <h2>Hedef Bilgileri</h2>
                    <p>Temsilcinin aylık satış hedeflerini belirleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="monthly_target">Aylık Prim Hedefi (₺) <span class="required">*</span></label>
                            <input type="number" step="0.01" min="0" name="monthly_target" id="monthly_target" value="<?php echo esc_attr($representative->monthly_target); ?>" required>
                            <p class="form-tip">Temsilcinin aylık ulaşması gereken prim tutarını girin</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="target_policy_count">Aylık Poliçe Hedefi <span class="required">*</span></label>
                            <input type="number" step="1" min="0" name="target_policy_count" id="target_policy_count" value="<?php echo esc_attr($representative->target_policy_count); ?>" required>
                            <p class="form-tip">Temsilcinin aylık satması gereken poliçe sayısı</p>
                        </div>
                    </div>
                    
                    <div class="performance-summary">
                        <h3>Mevcut Performans Özeti</h3>
                        <?php
                        // Bu ay için üretilen prim ve poliçe sayısı
                        $this_month_start = date('Y-m-01 00:00:00');
                        $this_month_end = date('Y-m-t 23:59:59');
                        
                        $this_month_premium = $wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(SUM(premium_amount), 0) FROM {$wpdb->prefix}insurance_crm_policies 
                             WHERE representative_id = %d 
                             AND start_date BETWEEN %s AND %s
                             AND cancellation_date IS NULL",
                            $rep_id, $this_month_start, $this_month_end
                        )) ?: 0;
                        
                        $this_month_policies = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies 
                             WHERE representative_id = %d 
                             AND start_date BETWEEN %s AND %s
                             AND cancellation_date IS NULL",
                            $rep_id, $this_month_start, $this_month_end
                        )) ?: 0;
                        
                        $premium_achievement = $representative->monthly_target > 0 ? ($this_month_premium / $representative->monthly_target) * 100 : 0;
                        $policy_achievement = $representative->target_policy_count > 0 ? ($this_month_policies / $representative->target_policy_count) * 100 : 0;
                        ?>
                        
                        <div class="performance-grid">
                            <div class="performance-item">
                                <div class="performance-label">Aylık Prim Hedefi:</div>
                                <div class="performance-value">₺<?php echo number_format($representative->monthly_target, 2, ',', '.'); ?></div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Bu Ay Üretilen:</div>
                                <div class="performance-value">₺<?php echo number_format($this_month_premium, 2, ',', '.'); ?></div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Gerçekleşme Oranı:</div>
                                <div class="performance-value"><?php echo number_format($premium_achievement, 2); ?>%</div>
                            </div>
                        </div>
                        
                        <div class="performance-grid">
                            <div class="performance-item">
                                <div class="performance-label">Aylık Poliçe Hedefi:</div>
                                <div class="performance-value"><?php echo $representative->target_policy_count; ?> adet</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Bu Ay Satılan:</div>
                                <div class="performance-value"><?php echo $this_month_policies; ?> adet</div>
                            </div>
                            <div class="performance-item">
                                <div class="performance-label">Gerçekleşme Oranı:</div>
                                <div class="performance-value"><?php echo number_format($policy_achievement, 2); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="role">
                <div class="form-section">
                    <h2>Rol ve Yetki Bilgileri</h2>
                    <p>Temsilcinin sistem içindeki rolünü ve yetkilerini belirleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="role">Sistem Rolü <span class="required">*</span></label>
                            <select name="role" id="role" class="role-select">
                                <?php foreach ($roles as $role_id => $role_name): ?>
                                    <option value="<?php echo $role_id; ?>" <?php selected($representative->role, $role_id); ?> <?php echo ($role_id == 1 && $user_role !== 'patron') ? 'disabled' : ''; ?>>
                                        <?php echo esc_html($role_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-tip">Temsilcinin sistemdeki rolünü belirler</p>
                        </div>
                    </div>
                    
                    <div class="team-info">
                        <h3>Ekip Bilgileri</h3>
                        <?php if ($rep_team): ?>
                            <div class="team-detail">
                                <p><strong>Ekip:</strong> <?php echo esc_html($rep_team['name']); ?></p>
                                <p><strong>Rol:</strong> <?php echo $rep_team['leader_id'] == $rep_id ? 'Ekip Lideri' : 'Ekip Üyesi'; ?></p>
                                <p>Ekip bilgilerini güncellemek için <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams&action=edit_team&team_id=' . $rep_team['id']); ?>" target="_blank">Ekip Yönetimi</a> sayfasını kullanın.</p>
                            </div>
                        <?php else: ?>
                            <div class="empty-team">
                                <p>Bu temsilci henüz bir ekibe atanmamış.</p>
                                <a href="<?php echo admin_url('admin.php?page=insurance-crm-representatives&tab=teams'); ?>" target="_blank" class="button button-secondary">Ekip Yönetimine Git</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="security">
                <div class="form-section">
                    <h2>Güvenlik Ayarları</h2>
                    <p>Temsilcinin giriş bilgilerini ve güvenlik ayarlarını değiştirin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Kullanıcı Adı</label>
                            <input type="text" name="username" id="username" value="<?php echo esc_attr($representative->user_login); ?>" readonly>
                            <p class="form-tip">Kullanıcı adı değiştirilemez</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Şifre</label>
                            <input type="password" name="password" id="password" placeholder="Yeni şifre belirlemek için doldurun">
                            <p class="form-tip">Şifreyi değiştirmek istemiyorsanız boş bırakın</p>
                        </div>
                    </div>
                    
                    <div class="last-login-info">
                        <h3>Son Aktivite Bilgileri</h3>
                        <?php
                        $last_login = get_user_meta($representative->wp_user_id, 'last_login', true);
                        $last_login_time = $last_login ? date_i18n('d.m.Y H:i:s', intval($last_login)) : 'Henüz giriş yapmamış';
                        ?>
                        <p><strong>Son Giriş:</strong> <?php echo $last_login_time; ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="edit_representative_submit" class="button button-primary">
                <i class="dashicons dashicons-saved"></i> Değişiklikleri Kaydet
            </button>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Sekme değiştirme
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        
        // Aktif sekme linkini değiştir
        $('.tab-link').removeClass('active');
        $(this).addClass('active');
        
        // İçeriği değiştir
        var tabId = $(this).data('tab');
        $('.tab-content').removeClass('active');
        $('#' + tabId).addClass('active');
    });
    
    // Avatar önizleme
    $('#avatar_file').on('change', function(e) {
        if (this.files && this.files[0]) {
            var file = this.files[0];
            
            // Dosya türü ve boyut kontrolü
            var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            var maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                alert('Geçersiz dosya türü! Sadece JPG, JPEG, PNG ve GIF formatları kabul edilir.');
                $(this).val('');
                return;
            }
            
            if (file.size > maxSize) {
                alert('Dosya boyutu çok büyük! Maksimum 5MB yükleyebilirsiniz.');
                $(this).val('');
                return;
            }
            
            // Dosya uygunsa önizlemeyi göster
            var reader = new FileReader();
            reader.onload = function(e) {
                $('.rep-avatar img').attr('src', e.target.result);
                if ($('.rep-avatar .default-avatar').length) {
                    $('.rep-avatar .default-avatar').hide();
                    $('.rep-avatar').append('<img src="' + e.target.result + '" alt="Profil Fotoğrafı">');
                }
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Rol değiştirme uyarısı
    var originalRole = $('.role-select').val();
    $('.role-select').on('change', function() {
        var newRole = $(this).val();
        if (originalRole != newRole) {
            if (confirm('Dikkat: Rol değiştirmek, temsilcinin sistem içindeki yetkilerini değiştirecektir. Devam etmek istiyor musunuz?')) {
                // Devam et
            } else {
                $(this).val(originalRole);
            }
        }
    });
});
</script>

<style>
.edit-representative-container {
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
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
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
    font-size: 32px;
}

.rep-info h1 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #333;
}

.rep-info h1 small {
    font-size: 16px;
    color: #666;
    font-weight: normal;
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

.rep-header-right {
    display: flex;
    gap: 10px;
}

.notice {
    padding: 12px 15px;
    margin: 15px 0;
    border-left: 4px solid;
    border-radius: 3px;
    background: #fff;
}

.notice-success {
    border-color: #46b450;
}

.notice-error {
    border-color: #dc3232;
}

.edit-representative-form {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    overflow: hidden;
}

.form-tabs {
    display: flex;
    border-bottom: 1px solid #eee;
    background: #f8f9fa;
    overflow-x: auto;
}

.form-tabs .tab-link {
    padding: 15px 20px;
    color: #555;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    font-weight: 500;
    display: flex;
    align-items: center;
    transition: all 0.2s;
    white-space: nowrap;
}

.form-tabs .tab-link .dashicons {
    margin-right: 8px;
}

.form-tabs .tab-link:hover {
    background: #f1f1f1;
    color: #333;
}

.form-tabs .tab-link.active {
    color: #0073aa;
    border-bottom-color: #0073aa;
    background: #fff;
}

.form-content {
    padding: 20px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-section {
    margin-bottom: 30px;
}

.form-section h2 {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 10px;
    color: #333;
}

.form-section p {
    color: #666;
    margin: 0 0 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.col-span-2 {
    grid-column: span 2;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 5px;
    color: #333;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="number"],
.form-group input[type="password"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input[type="file"] {
    padding: 10px 0;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #0073aa;
    box-shadow: 0 0 0 1px #0073aa;
    outline: none;
}

.form-group input:read-only {
    background: #f9f9f9;
    cursor: not-allowed;
}

.form-tip {
    color: #666;
    font-size: 12px;
    margin: 5px 0 0;
}

.required {
    color: #dc3232;
}

.performance-summary {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.performance-summary h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 15px;
    color: #333;
}

.performance-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.performance-item {
    background: #fff;
    padding: 15px;
    border-radius: 4px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.performance-label {
    font-size: 13px;
    color: #666;
    margin-bottom: 5px;
}

.performance-value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.team-info {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.team-info h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 15px;
    color: #333;
}

.team-detail p {
    margin: 10px 0;
}

.empty-team {
    text-align: center;
    padding: 20px 0;
}

.last-login-info {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.last-login-info h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 15px;
    color: #333;
}

.form-actions {
    padding: 20px;
    border-top: 1px solid #eee;
    text-align: right;
}

.button {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    text-decoration: none;
    transition: all 0.2s;
}

.button .dashicons {
    margin-right: 8px;
}

.button-primary {
    background: #0073aa;
    color: #fff;
    border: 1px solid #0073aa;
}

.button-primary:hover {
    background: #005d8c;
}

.button-secondary {
    background: #f8f9fa;
    color: #555;
    border: 1px solid #ddd;
}

.button-secondary:hover {
    background: #f1f1f1;
    border-color: #ccc;
    color: #333;
}

@media screen and (max-width: 992px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.col-span-2 {
        grid-column: auto;
    }
    
    .performance-grid {
        grid-template-columns: 1fr;
    }
}

@media screen and (max-width: 768px) {
    .rep-header {
        flex-direction: column;
    }
    
    .rep-header-right {
        margin-top: 15px;
        width: 100%;
    }
    
    .form-tabs {
        flex-wrap: wrap;
    }
}
</style>