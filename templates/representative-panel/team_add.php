<?php
/**
 * Yeni Ekip Ekleme Sayfası
 * 
 * @package    Insurance_CRM
 * @subpackage Insurance_CRM/templates/representative-panel
 * @author     Anadolu Birlik
 * @since      1.0.0
 * @version    1.0.1 (2025-05-28)
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

// Yetki kontrolü - sadece patron ve müdür yeni ekip ekleyebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Mevcut temsilcileri ve ekipleri al
$table_reps = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$table_reps} r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     WHERE r.status = 'active'
     ORDER BY r.role ASC, u.display_name ASC"
);

// Mevcut ekipler
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

// Form gönderildiğinde ekip oluştur
$error_messages = array();
$success_message = '';

if (isset($_POST['submit_team']) && isset($_POST['team_nonce']) && 
    wp_verify_nonce($_POST['team_nonce'], 'add_team')) {
    
    // Form verilerini al
    $team_name = sanitize_text_field($_POST['team_name']);
    $team_leader_id = intval($_POST['team_leader_id']);
    $team_members = isset($_POST['team_members']) ? array_map('intval', $_POST['team_members']) : array();
    
    // Validasyon
    if (empty($team_name)) {
        $error_messages[] = 'Ekip adı gereklidir.';
    }
    
    if ($team_leader_id <= 0) {
        $error_messages[] = 'Ekip lideri seçilmelidir.';
    }
    
    // Aynı isimde başka bir ekip var mı kontrol et
    foreach ($teams as $team) {
        if (strtolower($team['name']) === strtolower($team_name)) {
            $error_messages[] = 'Bu isimde bir ekip zaten mevcut.';
            break;
        }
    }
    
    // Seçilen ekip liderinin başka bir ekipte lider olup olmadığını kontrol et
    foreach ($teams as $team) {
        if ($team['leader_id'] == $team_leader_id) {
            $error_messages[] = 'Seçilen kişi zaten başka bir ekibin lideri.';
            break;
        }
    }
    
    // Hata yoksa ekibi kaydet
    if (empty($error_messages)) {
        // Benzersiz ekip ID'si oluştur
        $team_id = 'team_' . uniqid();
        
        // Ekibi ayarlara ekle
        if (!isset($settings['teams_settings'])) {
            $settings['teams_settings'] = array();
        }
        
        if (!isset($settings['teams_settings']['teams'])) {
            $settings['teams_settings']['teams'] = array();
        }
        
        $settings['teams_settings']['teams'][$team_id] = array(
            'name' => $team_name,
            'leader_id' => $team_leader_id,
            'members' => $team_members
        );
        
        update_option('insurance_crm_settings', $settings);
        
        $success_message = 'Ekip başarıyla oluşturuldu.';
        
        // Aktivite logu ekle
        $table_logs = $wpdb->prefix . 'insurance_crm_activity_log';
        $wpdb->insert(
            $table_logs,
            array(
                'user_id' => $current_user->ID,
                'username' => $current_user->display_name,
                'action_type' => 'create',
                'action_details' => json_encode(array(
                    'item_type' => 'team',
                    'item_id' => $team_id,
                    'name' => $team_name,
                    'created_by' => $current_user->display_name
                )),
                'created_at' => current_time('mysql')
            )
        );
        
        // Seçilen temsilcinin rolünü ekip lideri olarak güncelle
        if ($team_leader_id > 0) {
            $wpdb->update(
                $table_reps,
                array(
                    'role' => 4, // Ekip Lideri rolü
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $team_leader_id)
            );
        }
    }
}

?>

<!-- Sayfa İçeriği -->
<div class="team-add-container">
    <div class="page-header">
        <h1>Yeni Ekip Oluştur</h1>
        <p class="description">Yeni bir çalışma ekibi oluşturun ve ekip üyelerini belirleyin.</p>
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
                    <a href="<?php echo home_url('/representative-panel/?view=team_add'); ?>" class="btn btn-secondary">
                        <i class="fas fa-plus"></i> Başka Ekip Ekle
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Mevcut ekiplerin görselleştirilmesi -->
        <?php if (!empty($teams)): ?>
            <div class="existing-teams-container">
                <h2><i class="fas fa-project-diagram"></i> Mevcut Ekipler</h2>
                <div class="team-cards">
                    <?php foreach ($teams as $team_id => $team): 
                        // Ekip lideri bilgilerini al
                        $leader_name = '(Lider tanımlanmamış)';
                        $leader_role = '';
                        $member_count = count($team['members']);
                        
                        foreach ($representatives as $rep) {
                            if ($rep->id == $team['leader_id']) {
                                $leader_name = $rep->display_name;
                                $role_map = array(
                                    1 => 'Patron',
                                    2 => 'Müdür',
                                    3 => 'Müdür Yardımcısı',
                                    4 => 'Ekip Lideri',
                                    5 => 'Müşteri Temsilcisi'
                                );
                                $leader_role = isset($role_map[$rep->role]) ? $role_map[$rep->role] : $rep->title;
                                break;
                            }
                        }
                    ?>
                        <div class="team-card">
                            <div class="team-card-header">
                                <h3><?php echo esc_html($team['name']); ?></h3>
                                <div class="team-actions">
                                    <a href="<?php echo home_url('/representative-panel/?view=team_detail&team_id=' . $team_id); ?>" class="btn-sm btn-outline">
                                        <i class="fas fa-eye"></i> Detay
                                    </a>
                                    <a href="<?php echo home_url('/representative-panel/?view=edit_team&team_id=' . $team_id); ?>" class="btn-sm btn-outline">
                                        <i class="fas fa-edit"></i> Düzenle
                                    </a>
                                </div>
                            </div>
                            <div class="team-card-body">
                                <div class="team-leader">
                                    <div class="leader-avatar">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div class="leader-info">
                                        <span class="leader-title">Ekip Lideri</span>
                                        <span class="leader-name"><?php echo esc_html($leader_name); ?></span>
                                        <span class="leader-role"><?php echo esc_html($leader_role); ?></span>
                                    </div>
                                </div>
                                <div class="team-stats">
                                    <div class="team-stat">
                                        <i class="fas fa-users"></i>
                                        <span><?php echo $member_count; ?> Üye</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="post" action="" class="team-form">
            <?php wp_nonce_field('add_team', 'team_nonce'); ?>
            
            <div class="form-card">
                <div class="card-header">
                    <h2><i class="fas fa-users"></i> Yeni Ekip Bilgileri</h2>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="team_name">Ekip Adı <span class="required">*</span></label>
                            <input type="text" name="team_name" id="team_name" class="form-control" required>
                            <div class="form-hint">Örnek: Satış Ekibi, Müşteri İlişkileri Ekibi</div>
                        </div>
                        
                        <div class="form-group col-md-6">
                            <label for="team_leader_id">Ekip Lideri <span class="required">*</span></label>
                            <select name="team_leader_id" id="team_leader_id" class="form-control" required>
                                <option value="">-- Ekip Lideri Seçin --</option>
                                <?php
                                $used_leaders = array();
                                foreach ($teams as $team) {
                                    $used_leaders[] = $team['leader_id'];
                                }
                                
                                foreach ($representatives as $rep):
                                    // Hali hazırda ekip lideri olanları listeden çıkar
                                    if (in_array($rep->id, $used_leaders)) {
                                        continue;
                                    }
                                    
                                    // Patron ve Müdür rolleri ekip lideri olamaz
                                    if ($rep->role == 1 || $rep->role == 2) {
                                        continue;
                                    }
                                ?>
                                    <option value="<?php echo $rep->id; ?>">
                                        <?php 
                                            echo esc_html($rep->display_name);
                                            if (!empty($rep->title)) {
                                                echo ' (' . esc_html($rep->title) . ')';
                                            }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group col-md-12">
                            <label>Ekip Üyeleri</label>
                            
                            <div class="member-selection">
                                <div class="available-members">
                                    <div class="section-header">
                                        <h3>Mevcut Temsilciler</h3>
                                        <input type="text" id="search-available" placeholder="Temsilci ara..." class="search-input">
                                    </div>
                                    <div class="member-list" id="available-list">
                                        <?php
                                        foreach ($representatives as $rep):
                                            // Patron ve Müdür rolleri ekip üyesi olamaz
                                            if ($rep->role == 1 || $rep->role == 2) {
                                                continue;
                                            }
                                            
                                            // Ekip lideri olanları ve zaten bir ekipte olanları atla
                                            $is_leader = false;
                                            $is_member = false;
                                            
                                            foreach ($teams as $team) {
                                                if ($team['leader_id'] == $rep->id) {
                                                    $is_leader = true;
                                                    break;
                                                }
                                                
                                                if (in_array($rep->id, $team['members'])) {
                                                    $is_member = true;
                                                    break;
                                                }
                                            }
                                            
                                            if ($is_leader || $is_member) {
                                                continue;
                                            }
                                        ?>
                                            <div class="member-item" data-id="<?php echo $rep->id; ?>">
                                                <div class="member-info">
                                                    <div class="member-avatar">
                                                        <?php if (!empty($rep->avatar_url)): ?>
                                                            <img src="<?php echo esc_url($rep->avatar_url); ?>" alt="<?php echo esc_attr($rep->display_name); ?>">
                                                        <?php else: ?>
                                                            <i class="fas fa-user"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="member-details">
                                                        <span class="member-name"><?php echo esc_html($rep->display_name); ?></span>
                                                        <span class="member-title"><?php echo esc_html($rep->title); ?></span>
                                                    </div>
                                                </div>
                                                <button type="button" class="btn-add-member" title="Ekibe Ekle">
                                                    <i class="fas fa-plus-circle"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if ($wpdb->num_rows == 0): ?>
                                            <div class="empty-message">
                                                <p>Eklenecek temsilci bulunmuyor.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="team-members">
                                    <div class="section-header">
                                        <h3>Ekip Üyeleri</h3>
                                    </div>
                                    <div class="member-list" id="team-list">
                                        <div class="empty-message" id="empty-team">
                                            <p>Henüz ekibe üye eklenmedi.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-hint">
                                Ekip üyeleri listesine eklemek için temsilcilere tıklayın. Ekip lideri otomatik olarak ekibe dahil edilecektir.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="submit_team" class="btn btn-primary">
                    <i class="fas fa-save"></i> Ekibi Kaydet
                </button>
                <a href="<?php echo home_url('/representative-panel/?view=all_personnel'); ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> İptal
                </a>
            </div>
        </form>
    <?php endif; ?>
</div>

<style>
.team-add-container {
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

/* Mevcut Ekipler Kartları */
.existing-teams-container {
    margin-bottom: 30px;
}

.existing-teams-container h2 {
    font-size: 20px;
    color: #333;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
}

.existing-teams-container h2 i {
    margin-right: 10px;
    color: #3498db;
}

.team-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.team-card {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.team-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.team-card-header {
    background-color: #f8f9fa;
    padding: 15px;
    border-bottom: 1px solid #eaeaea;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.team-card-header h3 {
    margin: 0;
    font-size: 16px;
    color: #333;
}

.team-actions {
    display: flex;
    gap: 5px;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
}

.btn-outline {
    background-color: transparent;
    border: 1px solid #ced4da;
    color: #6c757d;
    transition: all 0.2s ease;
}

.btn-outline:hover {
    background-color: #f8f9fa;
    color: #333;
}

.btn-outline i {
    margin-right: 5px;
    font-size: 12px;
}

.team-card-body {
    padding: 15px;
}

.team-leader {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.leader-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: #e3f2fd;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    color: #0d47a1;
}

.leader-info {
    display: flex;
    flex-direction: column;
}

.leader-title {
    font-size: 12px;
    font-weight: 600;
    color: #3498db;
}

.leader-name {
    font-size: 15px;
    font-weight: 600;
    color: #333;
}

.leader-role {
    font-size: 12px;
    color: #6c757d;
}

.team-stats {
    display: flex;
    justify-content: space-between;
}

.team-stat {
    display: flex;
    align-items: center;
    font-size: 14px;
    color: #6c757d;
}

.team-stat i {
    margin-right: 5px;
    color: #3498db;
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

.col-md-12 {
    flex: 0 0 100%;
    max-width: 100%;
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

/* Üye Seçim Alanı */
.member-selection {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 10px;
}

.available-members, .team-members {
    background-color: #f9f9f9;
    border-radius: 8px;
    border: 1px solid #eaeaea;
    overflow: hidden;
}

.section-header {
    background-color: #f0f0f0;
    padding: 10px 15px;
    border-bottom: 1px solid #eaeaea;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-header h3 {
    margin: 0;
    font-size: 16px;
    color: #333;
}

.search-input {
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
    width: 150px;
}

.member-list {
    max-height: 300px;
    overflow-y: auto;
    padding: 10px;
}

.member-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 5px;
    background-color: #fff;
    border: 1px solid #eaeaea;
    transition: all 0.2s ease;
}

.member-item:hover {
    background-color: #f0f7ff;
    border-color: #d0e1fd;
}

.member-info {
    display: flex;
    align-items: center;
    flex: 1;
}

.member-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: #e3f2fd;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    color: #0d47a1;
    overflow: hidden;
}

.member-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.member-details {
    display: flex;
    flex-direction: column;
}

.member-name {
    font-weight: 500;
    font-size: 14px;
    color: #333;
}

.member-title {
    font-size: 12px;
    color: #6c757d;
}

.btn-add-member, .btn-remove-member {
    background: none;
    border: none;
    font-size: 18px;
    cursor: pointer;
    padding: 5px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.btn-add-member {
    color: #2ecc71;
}

.btn-add-member:hover {
    background-color: rgba(46, 204, 113, 0.1);
}

.btn-remove-member {
    color: #e74c3c;
}

.btn-remove-member:hover {
    background-color: rgba(231, 76, 60, 0.1);
}

.empty-message {
    padding: 15px;
    text-align: center;
    color: #6c757d;
    font-style: italic;
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
    
    .member-selection {
        grid-template-columns: 1fr;
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
    // DOM elementlerini al
    const teamLeaderSelect = document.getElementById('team_leader_id');
    const availableList = document.getElementById('available-list');
    const teamList = document.getElementById('team-list');
    const emptyTeam = document.getElementById('empty-team');
    const searchInput = document.getElementById('search-available');
    
    // Üye ekle/çıkar fonksiyonları
    function addMemberToTeam(memberId, memberHTML) {
        // Gizli input ekle
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'team_members[]';
        input.value = memberId;
        
        // HTML'i düzenle - Ekle butonunu Çıkar butonuyla değiştir
        const newMemberHTML = memberHTML.replace(
            '<button type="button" class="btn-add-member" title="Ekibe Ekle"><i class="fas fa-plus-circle"></i></button>',
            '<button type="button" class="btn-remove-member" title="Ekipten Çıkar"><i class="fas fa-times-circle"></i></button>'
        );
        
        // Yeni üye elementini oluştur ve ekle
        const memberDiv = document.createElement('div');
        memberDiv.className = 'member-item';
        memberDiv.dataset.id = memberId;
        memberDiv.innerHTML = newMemberHTML;
        memberDiv.appendChild(input);
        
        // Ekipten çıkarma butonuna olay dinleyicisi ekle
        memberDiv.querySelector('.btn-remove-member').addEventListener('click', function() {
            removeMemberFromTeam(memberId, memberDiv);
        });
        
        teamList.appendChild(memberDiv);
        
        // Boş mesajı gizle
        if (teamList.children.length > 1) {
            emptyTeam.style.display = 'none';
        }
    }
    
    function removeMemberFromTeam(memberId, element) {
        // Ekipten üyeyi kaldır
        teamList.removeChild(element);
        
        // Ekip boşsa boş mesajını göster
        if (teamList.children.length <= 1) {
            emptyTeam.style.display = 'block';
        }
        
        // Mevcut temsilciler listesindeki elementin görünürlüğünü kontrol et
        const availableMember = availableList.querySelector(`.member-item[data-id="${memberId}"]`);
        if (availableMember) {
            // Zaten görünürse işlem yapma
        } else {
            // Temsilciyi orijinal listede bul ve görünür yap
            const originalItems = document.querySelectorAll(`#available-list .member-item[data-id="${memberId}"]`);
            if (originalItems.length > 0) {
                originalItems.forEach(item => {
                    item.style.display = 'flex';
                });
            }
        }
    }
    
    // Üye ekleme butonlarına olay dinleyicisi ekle
    document.querySelectorAll('#available-list .btn-add-member').forEach(button => {
        button.addEventListener('click', function() {
            const memberItem = this.closest('.member-item');
            const memberId = memberItem.dataset.id;
            const memberHTML = memberItem.innerHTML;
            
            // Üyeyi ekibe ekle
            addMemberToTeam(memberId, memberHTML);
            
            // Mevcut listeden gizle
            memberItem.style.display = 'none';
        });
    });
    
    // Arama fonksiyonu
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const availableMembers = availableList.querySelectorAll('.member-item');
            
            availableMembers.forEach(item => {
                if (item.style.display === 'none') return; // Ekibe eklenmiş üyeleri atla
                
                const memberName = item.querySelector('.member-name').textContent.toLowerCase();
                const memberTitle = item.querySelector('.member-title').textContent.toLowerCase();
                
                if (memberName.includes(searchTerm) || memberTitle.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
    
    // Ekip lideri değiştiğinde altta bir not göster
    if (teamLeaderSelect) {
        teamLeaderSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const leaderName = selectedOption.textContent.trim();
                const infoElement = document.createElement('div');
                infoElement.className = 'form-hint';
                infoElement.innerHTML = `<i class="fas fa-info-circle"></i> <strong>${leaderName}</strong> ekip lideri olarak seçildi ve otomatik olarak rol değişikliği yapılacak.`;
                
                // Mevcut bilgi notunu temizle ve yenisini ekle
                const existingInfo = this.parentNode.querySelector('.form-hint.leader-info');
                if (existingInfo) {
                    existingInfo.remove();
                }
                
                infoElement.classList.add('leader-info');
                this.parentNode.appendChild(infoElement);
            }
        });
    }
});
</script>