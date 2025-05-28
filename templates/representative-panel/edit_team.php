<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ekip ID'sini al
$team_id = isset($_GET['team_id']) ? sanitize_text_field($_GET['team_id']) : '';

if (empty($team_id)) {
    echo '<div class="notice notice-error"><p>Ekip ID bulunamadı.</p></div>';
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

// Ekip bilgilerini al
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

if (!isset($teams[$team_id])) {
    echo '<div class="notice notice-error"><p>Ekip bulunamadı.</p></div>';
    return;
}

$team = $teams[$team_id];

// Form gönderildi mi kontrol et
$success_message = '';
$errors = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_team_submit'])) {
    // Nonce kontrolü
    if (!isset($_POST['edit_team_nonce']) || !wp_verify_nonce($_POST['edit_team_nonce'], 'edit_team_nonce')) {
        wp_die('Güvenlik kontrolü başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
    
    // Form verilerini al
    $team_name = isset($_POST['team_name']) ? sanitize_text_field($_POST['team_name']) : '';
    $team_leader_id = isset($_POST['team_leader_id']) ? intval($_POST['team_leader_id']) : 0;
    $team_members = isset($_POST['team_members']) ? array_map('intval', $_POST['team_members']) : array();
    $team_color = isset($_POST['team_color']) ? sanitize_hex_color($_POST['team_color']) : '#4caf50';
    $team_description = isset($_POST['team_description']) ? sanitize_textarea_field($_POST['team_description']) : '';
    
    // Form doğrulaması
    if (empty($team_name)) {
        $errors[] = 'Ekip adı zorunludur.';
    }
    if (empty($team_leader_id)) {
        $errors[] = 'Lütfen bir ekip lideri seçin.';
    }
    
    // Ekip lideri değişti ise yetki seviyesini güncelle
    $original_leader_id = $team['leader_id'];
    if ($team_leader_id != $original_leader_id) {
        // Eski liderin yetki seviyesini kontrol et (eğer başka ekiplerde lider değilse düşür)
        $is_leader_elsewhere = false;
        foreach ($teams as $existing_team_id => $existing_team) {
            if ($existing_team_id != $team_id && $existing_team['leader_id'] == $original_leader_id) {
                $is_leader_elsewhere = true;
                break;
            }
        }
        
        if (!$is_leader_elsewhere) {
            // Eski liderin yetkisini müşteri temsilcisi seviyesine düşür
            $wpdb->update(
                $wpdb->prefix . 'insurance_crm_representatives',
                array('role' => 5), // Müşteri temsilcisi
                array('id' => $original_leader_id)
            );
        }
        
        // Yeni liderin yetkisini ekip lideri seviyesine yükselt
        $wpdb->update(
            $wpdb->prefix . 'insurance_crm_representatives',
            array('role' => 4), // Ekip lideri
            array('id' => $team_leader_id)
        );
    }
    
    // Hata yoksa güncelleme yap
    if (empty($errors)) {
        // Ekibi güncelle
        $teams[$team_id] = array(
            'name' => $team_name,
            'leader_id' => $team_leader_id,
            'members' => $team_members,
            'color' => $team_color,
            'description' => $team_description
        );
        
        $settings['teams_settings']['teams'] = $teams;
        update_option('insurance_crm_settings', $settings);
        
        $success_message = 'Ekip bilgileri başarıyla güncellendi.';
        
        // Güncel ekip bilgilerini al
        $team = $teams[$team_id];
    }
}

// Tüm temsilcileri al
$all_representatives = $wpdb->get_results(
    "SELECT r.id, r.title, r.role, u.display_name, u.user_email
     FROM {$wpdb->prefix}insurance_crm_representatives r
     JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.status = 'active'
     ORDER BY u.display_name ASC"
);

// Ekip liderini ve üyeleri ayrı ayrı al
$team_leader = null;
$team_members = array();
$available_representatives = array();

foreach ($all_representatives as $rep) {
    if ($rep->id == $team['leader_id']) {
        $team_leader = $rep;
    } elseif (in_array($rep->id, $team['members'])) {
        $team_members[] = $rep;
    } else {
        $available_representatives[] = $rep;
    }
}
?>

<div class="edit-team-container">
    <div class="team-header">
        <div class="team-header-left">
            <div class="team-icon" <?php if (!empty($team['color'])) echo 'style="background: ' . esc_attr($team['color']) . ';"'; ?>>
                <i class="dashicons dashicons-groups"></i>
            </div>
            <div class="team-info">
                <h1><?php echo esc_html($team['name']); ?> <small>(Düzenleme)</small></h1>
                <div class="team-meta">
                    <?php if ($team_leader): ?>
                        <span class="team-leader">Ekip Lideri: <strong><?php echo esc_html($team_leader->display_name); ?></strong></span>
                    <?php endif; ?>
                    <span class="team-members">Üye Sayısı: <strong><?php echo count($team['members']); ?></strong> kişi</span>
                </div>
            </div>
        </div>
        <div class="team-header-right">
            <a href="<?php echo generate_panel_url('team_detail', '', '', array('team_id' => $team_id)); ?>" class="button">
                <i class="dashicons dashicons-visibility"></i> Ekip Detayına Dön
            </a>
            <a href="<?php echo generate_panel_url('all_teams'); ?>" class="button">
                <i class="dashicons dashicons-list-view"></i> Tüm Ekipler
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
    
    <form method="post" action="" class="edit-team-form">
        <?php wp_nonce_field('edit_team_nonce', 'edit_team_nonce'); ?>
        
        <div class="form-tabs">
            <a href="#basic" class="tab-link active" data-tab="basic">
                <i class="dashicons dashicons-groups"></i> Temel Bilgiler
            </a>
            <a href="#members" class="tab-link" data-tab="members">
                <i class="dashicons dashicons-businessperson"></i> Ekip Üyeleri
            </a>
        </div>
        
        <div class="form-content">
            <div class="tab-content active" id="basic">
                <div class="form-section">
                    <h2>Ekip Temel Bilgileri</h2>
                    <p>Ekibin adı, lideri ve diğer temel bilgilerini düzenleyin.</p>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="team_name">Ekip Adı <span class="required">*</span></label>
                            <input type="text" name="team_name" id="team_name" value="<?php echo esc_attr($team['name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="team_color">Ekip Rengi</label>
                            <input type="color" name="team_color" id="team_color" value="<?php echo esc_attr($team['color'] ?? '#4caf50'); ?>">
                            <p class="form-tip">Ekibi temsil eden renk (grafik ve raporlarda kullanılır)</p>
                        </div>
                        
                        <div class="form-group col-span-2">
                            <label for="team_description">Ekip Açıklaması</label>
                            <textarea name="team_description" id="team_description" rows="3"><?php echo esc_textarea($team['description'] ?? ''); ?></textarea>
                            <p class="form-tip">Ekip hakkında kısa bir açıklama (isteğe bağlı)</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="members">
                <div class="form-section">
                    <h2>Ekip Lideri ve Üyeleri</h2>
                    <p>Ekip için bir lider seçin ve ekibe üye atayın.</p>
                    
                    <div class="form-grid">
                        <div class="form-group col-span-2">
                            <label for="team_leader_id">Ekip Lideri <span class="required">*</span></label>
                            <select name="team_leader_id" id="team_leader_id" required>
                                <option value="">-- Lider Seçin --</option>
                                <?php if ($team_leader): ?>
                                    <option value="<?php echo $team_leader->id; ?>" selected>
                                        <?php echo esc_html($team_leader->display_name . ' (' . $team_leader->title . ')'); ?>
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($available_representatives as $rep): ?>
                                    <option value="<?php echo $rep->id; ?>">
                                        <?php echo esc_html($rep->display_name . ' (' . $rep->title . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-tip"><strong>Dikkat:</strong> Ekip lideri değiştirilirse, eski lider müşteri temsilcisi statüsüne düşecektir!</p>
                        </div>
                    </div>
                    
                    <div class="members-selection">
                        <h3>Ekip Üyeleri</h3>
                        <p class="form-tip">Ekibe üye olarak eklemek istediğiniz temsilcileri seçin.</p>
                        
                        <div class="members-container">
                            <div class="available-members">
                                <h4>Kullanılabilir Temsilciler</h4>
                                <div class="search-box">
                                    <input type="text" id="search-available" placeholder="Temsilci ara...">
                                </div>
                                <select multiple id="available-representatives" size="10">
                                    <?php foreach ($available_representatives as $rep): ?>
                                        <option value="<?php echo $rep->id; ?>">
                                            <?php echo esc_html($rep->display_name . ' (' . $rep->title . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="members-actions">
                                    <button type="button" id="add-member" class="button button-secondary">
                                        <i class="dashicons dashicons-arrow-right-alt"></i> Ekle
                                    </button>
                                </div>
                            </div>
                            
                            <div class="selected-members">
                                <h4>Seçili Ekip Üyeleri</h4>
                                <div class="search-box">
                                    <input type="text" id="search-selected" placeholder="Üyelerde ara...">
                                </div>
                                <select multiple id="selected-members" size="10">
                                    <?php foreach ($team_members as $member): ?>
                                        <option value="<?php echo $member->id; ?>">
                                            <?php echo esc_html($member->display_name . ' (' . $member->title . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="members-actions">
                                    <button type="button" id="remove-member" class="button button-secondary">
                                        <i class="dashicons dashicons-arrow-left-alt"></i> Çıkar
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Seçili üyelerin formda gönderilmesi için gizli alan -->
                        <div id="selected-members-container">
                            <?php foreach ($team['members'] as $member_id): ?>
                                <input type="hidden" name="team_members[]" value="<?php echo $member_id; ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="edit_team_submit" class="button button-primary">
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
    
    // Üye ekleme
    $('#add-member').on('click', function() {
        var selected = $('#available-representatives option:selected');
        if (selected.length > 0) {
            $('#selected-members').append(selected.clone());
            selected.remove();
            updateHiddenFields();
        }
    });
    
    // Üye çıkarma
    $('#remove-member').on('click', function() {
        var selected = $('#selected-members option:selected');
        if (selected.length > 0) {
            $('#available-representatives').append(selected.clone());
            selected.remove();
            updateHiddenFields();
        }
    });
    
    // Seçili üyeler için hidden input alanlarını güncelle
    function updateHiddenFields() {
        $('#selected-members-container').empty();
        $('#selected-members option').each(function() {
            var memberId = $(this).val();
            $('#selected-members-container').append('<input type="hidden" name="team_members[]" value="' + memberId + '">');
        });
    }
    
    // Kullanılabilir temsilcilerde arama
    $('#search-available').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('#available-representatives option').each(function() {
            var text = $(this).text().toLowerCase();
            if (text.indexOf(searchTerm) > -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Seçili üyelerde arama
    $('#search-selected').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('#selected-members option').each(function() {
            var text = $(this).text().toLowerCase();
            if (text.indexOf(searchTerm) > -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Form gönderildiğinde tüm seçili üyelerin seçili olmasını sağla (güvenlik için)
    $('form').on('submit', function() {
        $('#selected-members option').prop('selected', true);
    });
    
    // Ekip rengi değiştiğinde icon rengini güncelle
    $('#team_color').on('input', function() {
        var color = $(this).val();
        $('.team-icon').css('background', color);
    });
});
</script>

<style>
.edit-team-container {
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.team-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.team-header-left {
    display: flex;
    align-items: center;
}

.team-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 20px;
    background: #4caf50;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.team-icon .dashicons {
    font-size: 40px;
}

.team-info h1 {
    margin: 0 0 10px;
    font-size: 24px;
    color: #333;
}

.team-info h1 small {
    font-size: 16px;
    color: #666;
    font-weight: normal;
}

.team-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    color: #666;
    font-size: 14px;
}

.team-meta span {
    display: flex;
    align-items: center;
}

.team-meta span strong {
    color: #333;
    margin-left: 5px;
}

.team-header-right {
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

.edit-team-form {
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
.form-group input[type="color"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input[type="color"] {
    height: 40px;
    padding: 5px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #0073aa;
    box-shadow: 0 0 0 1px #0073aa;
    outline: none;
}

.required {
    color: #dc3232;
}

.form-tip {
    color: #666;
    font-size: 12px;
    margin: 5px 0 0;
}

.members-selection {
    margin-top: 30px;
}

.members-selection h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 10px;
    color: #333;
}

.members-selection h4 {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 10px;
    color: #333;
}

.members-container {
    display: flex;
    gap: 20px;
    margin: 20px 0;
}

.available-members,
.selected-members {
    flex: 1;
    background: #f9f9f9;
    border-radius: 6px;
    padding: 15px;
}

.search-box {
    margin-bottom: 10px;
}

.search-box input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

select[multiple] {
    width: 100%;
    height: 250px;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 5px;
}

select[multiple] option {
    padding: 8px;
    margin-bottom: 2px;
    border-radius: 3px;
    cursor: pointer;
}

select[multiple] option:hover {
    background: #f0f0f0;
}

.members-actions {
    margin-top: 15px;
    display: flex;
    justify-content: center;
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
    
    .members-container {
        flex-direction: column;
    }
}

@media screen and (max-width: 768px) {
    .team-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .team-header-right {
        width: 100%;
    }
}
</style>