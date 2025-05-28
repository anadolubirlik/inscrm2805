<?php
/**
 * Tüm Personel Görünümü
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

// Burada daha önce tanımlanmış fonksiyonları tekrar tanımlamıyoruz
// Bu fonksiyonlar dashboard.php'de zaten tanımlanmış olacak

global $wpdb;
$user_role = get_user_role_in_hierarchy($current_user->ID);

// Sadece patron ve müdür tüm personeli görebilir
if (!is_patron($current_user->ID) && !is_manager($current_user->ID)) {
    wp_die('Bu sayfaya erişim yetkiniz bulunmuyor.');
}

// Mevcut temsilcileri al
$table_reps = $wpdb->prefix . 'insurance_crm_representatives';
$representatives = $wpdb->get_results(
    "SELECT r.*, u.display_name, u.user_email 
     FROM {$table_reps} r 
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID 
     ORDER BY r.role ASC, u.display_name ASC"
);

// Mevcut ekipler
$settings = get_option('insurance_crm_settings', array());
$teams = isset($settings['teams_settings']['teams']) ? $settings['teams_settings']['teams'] : array();

// Filtreler
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$role_filter = isset($_GET['role']) ? sanitize_text_field($_GET['role']) : 'all';
$team_filter = isset($_GET['team_id']) ? sanitize_text_field($_GET['team_id']) : 'all';

// Rol adlarını harita
$role_map = array(
    1 => 'Patron',
    2 => 'Müdür',
    3 => 'Müdür Yardımcısı',
    4 => 'Ekip Lideri',
    5 => 'Müşteri Temsilcisi'
);

// Temsilci statüleri
$status_map = array(
    'active' => 'Aktif',
    'inactive' => 'Pasif'
);

?>
<div class="all-personnel-container">
    <div class="page-header">
        <h1>Tüm Personel</h1>
        <p class="description">Sisteme kayıtlı tüm personeli yönetin.</p>
    </div>
    
    <div class="filter-toolbar">
        <form class="filter-form" method="get">
            <input type="hidden" name="view" value="all_personnel">
            
            <div class="filter-group">
                <label for="role">Rol:</label>
                <select name="role" id="role" class="filter-control">
                    <option value="all" <?php selected($role_filter, 'all'); ?>>Tüm Roller</option>
                    <?php foreach ($role_map as $role_id => $role_name): ?>
                    <option value="<?php echo $role_id; ?>" <?php selected($role_filter, (string)$role_id); ?>><?php echo $role_name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="status">Durum:</label>
                <select name="status" id="status" class="filter-control">
                    <option value="all" <?php selected($status_filter, 'all'); ?>>Tüm Durumlar</option>
                    <?php foreach ($status_map as $status_id => $status_name): ?>
                    <option value="<?php echo $status_id; ?>" <?php selected($status_filter, $status_id); ?>><?php echo $status_name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if (!empty($teams)): ?>
            <div class="filter-group">
                <label for="team_id">Ekip:</label>
                <select name="team_id" id="team_id" class="filter-control">
                    <option value="all" <?php selected($team_filter, 'all'); ?>>Tüm Ekipler</option>
                    <?php foreach ($teams as $team_id => $team): ?>
                    <option value="<?php echo $team_id; ?>" <?php selected($team_filter, $team_id); ?>><?php echo $team['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter"></i> Filtrele
                </button>
                <a href="<?php echo generate_panel_url('all_personnel'); ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-times"></i> Sıfırla
                </a>
            </div>
        </form>
        
        <div class="view-toggles">
            <button class="toggle-view-mode" data-view="card">
                <i class="fas fa-list"></i> Liste Görünümü
            </button>
        </div>
    </div>
    
    <div class="card-view">
        <div class="personnel-cards">
            <?php if (!empty($representatives)): 
                foreach ($representatives as $rep): 
                    // Filtreye göre gösterme kontrolü
                    if ($status_filter !== 'all' && $rep->status !== $status_filter) {
                        continue;
                    }
                    
                    if ($role_filter !== 'all' && (string)$rep->role !== $role_filter) {
                        continue;
                    }
                    
                    // Ekip filtresi
                    if ($team_filter !== 'all') {
                        $is_in_filtered_team = false;
                        
                        // Ekip lideri kontrolü
                        if (isset($teams[$team_filter]) && $teams[$team_filter]['leader_id'] == $rep->id) {
                            $is_in_filtered_team = true;
                        }
                        // Ekip üyesi kontrolü
                        elseif (isset($teams[$team_filter]['members']) && in_array($rep->id, $teams[$team_filter]['members'])) {
                            $is_in_filtered_team = true;
                        }
                        
                        if (!$is_in_filtered_team) {
                            continue;
                        }
                    }
                    
                    // Temsilcinin ekip bilgileri
                    $team_info = '';
                    foreach ($teams as $team_id => $team) {
                        if ($team['leader_id'] == $rep->id) {
                            $team_info = '<div class="rep-team leader"><i class="fas fa-crown"></i> ' . $team['name'] . ' Lideri</div>';
                            break;
                        } elseif (in_array($rep->id, $team['members'])) {
                            $team_info = '<div class="rep-team member"><i class="fas fa-users"></i> ' . $team['name'] . ' Üyesi</div>';
                            break;
                        }
                    }
                    
                    // Müşteri ve poliçe sayıları
                    $customer_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers
                         WHERE representative_id = %d",
                        $rep->id
                    )) ?: 0;
                    
                    $policy_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies
                         WHERE representative_id = %d AND cancellation_date IS NULL",
                        $rep->id
                    )) ?: 0;
                    
                    // Rol adını belirle
                    $role_name = isset($role_map[$rep->role]) ? $role_map[$rep->role] : 'Temsilci';
                    
                    // Status sınıfı
                    $status_class = $rep->status === 'active' ? 'status-active' : 'status-inactive';
            ?>
                <div class="personnel-card <?php echo $status_class; ?>">
                    <div class="card-header">
                        <div class="role-badge role-<?php echo $rep->role; ?>"><?php echo $role_name; ?></div>
                        <div class="card-actions">
                            <?php if (is_patron($current_user->ID) || is_manager($current_user->ID)): ?>
                                <?php if ($rep->status === 'active'): ?>
                                    <a href="#" class="btn-outline-warning btn-sm status-toggle" data-id="<?php echo $rep->id; ?>" data-status="inactive">
                                        <i class="fas fa-ban"></i> Pasife Al
                                    </a>
                                <?php else: ?>
                                    <a href="#" class="btn-outline-success btn-sm status-toggle" data-id="<?php echo $rep->id; ?>" data-status="active">
                                        <i class="fas fa-check-circle"></i> Aktif Et
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="rep-info">
                            <div class="avatar">
                                <?php if (!empty($rep->avatar_url)): ?>
                                    <img src="<?php echo esc_url($rep->avatar_url); ?>" alt="<?php echo esc_attr($rep->display_name); ?>">
                                <?php else: ?>
                                    <div class="avatar-placeholder">
                                        <?php echo esc_html(substr($rep->display_name, 0, 2)); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($rep->status !== 'active'): ?>
                                    <div class="status-indicator inactive">
                                        <i class="fas fa-ban"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="status-indicator active">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="rep-details">
                                <h3 class="rep-name"><?php echo esc_html($rep->display_name); ?></h3>
                                <p class="rep-title"><?php echo esc_html($rep->title); ?></p>
                                <?php echo $team_info; ?>
                                <div class="contact-info">
                                    <div><i class="fas fa-envelope"></i> <?php echo esc_html($rep->user_email); ?></div>
                                    <div><i class="fas fa-phone"></i> <?php echo esc_html($rep->phone); ?></div>
                                </div>
                                <div class="performance-stats">
                                    <div class="stat">
                                        <i class="fas fa-users"></i>
                                        <span><?php echo number_format($customer_count); ?> Müşteri</span>
                                    </div>
                                    <div class="stat">
                                        <i class="fas fa-file-invoice"></i>
                                        <span><?php echo number_format($policy_count); ?> Poliçe</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <a href="<?php echo generate_panel_url('representative_detail', '', $rep->id); ?>" class="btn-outline btn-sm">
                            <i class="fas fa-eye"></i> Detay
                        </a>
                        <?php if (is_patron($current_user->ID)): ?>
                        <a href="<?php echo generate_panel_url('edit_representative', '', $rep->id); ?>" class="btn-outline btn-sm">
                            <i class="fas fa-edit"></i> Düzenle
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php 
                endforeach;
            else: 
            ?>
                <div class="empty-message">
                    <i class="fas fa-users-slash"></i>
                    <p>Kayıtlı personel bulunamadı.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="table-view" style="display: none;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>İsim</th>
                        <th>Rol</th>
                        <th>Ünvan</th>
                        <th>E-posta</th>
                        <th>Telefon</th>
                        <th>Ekip</th>
                        <th>Müşteri Sayısı</th>
                        <th>Poliçe Sayısı</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($representatives)): 
                        foreach ($representatives as $rep): 
                            // Filtreye göre gösterme kontrolü
                            if ($status_filter !== 'all' && $rep->status !== $status_filter) {
                                continue;
                            }
                            
                            if ($role_filter !== 'all' && (string)$rep->role !== $role_filter) {
                                continue;
                            }
                            
                            // Ekip filtresi
                            if ($team_filter !== 'all') {
                                $is_in_filtered_team = false;
                                
                                // Ekip lideri kontrolü
                                if (isset($teams[$team_filter]) && $teams[$team_filter]['leader_id'] == $rep->id) {
                                    $is_in_filtered_team = true;
                                }
                                // Ekip üyesi kontrolü
                                elseif (isset($teams[$team_filter]['members']) && in_array($rep->id, $teams[$team_filter]['members'])) {
                                    $is_in_filtered_team = true;
                                }
                                
                                if (!$is_in_filtered_team) {
                                    continue;
                                }
                            }
                            
                            // Temsilcinin ekip bilgileri
                            $team_name = '-';
                            $team_role = '';
                            foreach ($teams as $team_id => $team) {
                                if ($team['leader_id'] == $rep->id) {
                                    $team_name = $team['name'];
                                    $team_role = 'Ekip Lideri';
                                    break;
                                } elseif (in_array($rep->id, $team['members'])) {
                                    $team_name = $team['name'];
                                    $team_role = 'Üye';
                                    break;
                                }
                            }
                            
                            // Müşteri ve poliçe sayıları
                            $customer_count = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_customers
                                WHERE representative_id = %d",
                                $rep->id
                            )) ?: 0;
                            
                            $policy_count = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}insurance_crm_policies
                                WHERE representative_id = %d AND cancellation_date IS NULL",
                                $rep->id
                            )) ?: 0;
                            
                            // Rol adını belirle
                            $role_name = isset($role_map[$rep->role]) ? $role_map[$rep->role] : 'Temsilci';
                            
                            // Status sınıfı
                            $status_class = $rep->status === 'active' ? 'status-badge status-active' : 'status-badge status-inactive';
                            $status_text = $rep->status === 'active' ? 'Aktif' : 'Pasif';
                    ?>
                        <tr data-id="<?php echo $rep->id; ?>">
                            <td>
                                <div class="user-info-cell">
                                    <div class="user-avatar-mini">
                                        <?php if (!empty($rep->avatar_url)): ?>
                                            <img src="<?php echo esc_url($rep->avatar_url); ?>" alt="<?php echo esc_attr($rep->display_name); ?>">
                                        <?php else: ?>
                                            <?php echo esc_html(substr($rep->display_name, 0, 2)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <span>
                                        <a href="<?php echo generate_panel_url('representative_detail', '', $rep->id); ?>">
                                            <?php echo esc_html($rep->display_name); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td><span class="role-badge role-<?php echo $rep->role; ?>"><?php echo $role_name; ?></span></td>
                            <td><?php echo esc_html($rep->title ?: '-'); ?></td>
                            <td><?php echo esc_html($rep->user_email); ?></td>
                            <td><?php echo esc_html($rep->phone ?: '-'); ?></td>
                            <td>
                                <?php if (!empty($team_name)): ?>
                                    <?php echo esc_html($team_name); ?>
                                    <small>(<?php echo $team_role; ?>)</small>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($customer_count); ?></td>
                            <td><?php echo number_format($policy_count); ?></td>
                            <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo generate_panel_url('representative_detail', '', $rep->id); ?>" class="table-action" title="Detay">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (is_patron($current_user->ID)): ?>
                                    <a href="<?php echo generate_panel_url('edit_representative', '', $rep->id); ?>" class="table-action" title="Düzenle">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (is_patron($current_user->ID) || is_manager($current_user->ID)): ?>
                                        <?php if ($rep->status === 'active'): ?>
                                            <a href="#" class="table-action status-toggle" title="Pasife Al" data-id="<?php echo $rep->id; ?>" data-status="inactive">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="#" class="table-action status-toggle" title="Aktif Et" data-id="<?php echo $rep->id; ?>" data-status="active">
                                                <i class="fas fa-check-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php 
                        endforeach;
                    else: 
                    ?>
                        <tr>
                            <td colspan="10" class="empty-row">
                                <div class="empty-message">
                                    <i class="fas fa-users-slash"></i>
                                    <p>Kayıtlı personel bulunamadı.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php if (is_patron($current_user->ID)): ?>
    <div class="action-panel">
        <a href="<?php echo generate_panel_url('representative_add'); ?>" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Yeni Temsilci Ekle
        </a>
        <a href="<?php echo generate_panel_url('team_add'); ?>" class="btn btn-primary">
            <i class="fas fa-users-cog"></i> Yeni Ekip Oluştur
        </a>
        <a href="<?php echo generate_panel_url('boss_settings'); ?>" class="btn btn-secondary">
            <i class="fas fa-cog"></i> Yönetim Ayarları
        </a>
    </div>
    <?php endif; ?>
</div>

<style>
.empty-message i {
    font-size: 32px;
    margin-bottom: 10px;
    display: block;
    opacity: 0.5;
}

.empty-message p {
    font-size: 16px;
    margin: 0;
}

/* Görünüm Değiştirme */
.toggle-view-mode {
    background-color: #fff;
    color: #333;
    border: 1px solid #ddd;
}

.toggle-view-mode:hover {
    background-color: #f8f9fa;
}

.toggle-view-mode.active {
    background-color: #e3f2fd;
    color: #0d6efd;
    border-color: #b8daff;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (max-width: 992px) {
    .personnel-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .personnel-cards {
        grid-template-columns: 1fr;
    }
    
    .filter-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-form {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }
    
    .filter-group {
        margin-bottom: 10px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .filter-group label {
        margin-bottom: 5px;
    }
    
    .filter-control {
        width: 100%;
    }
    
    .action-buttons {
        width: 100%;
        justify-content: center;
    }
    
    .btn {
        flex: 1;
        justify-content: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Görünüm değiştirici
    const toggleViewBtn = document.querySelector('.toggle-view-mode');
    const cardView = document.querySelector('.card-view');
    const tableView = document.querySelector('.table-view');
    
    if (toggleViewBtn) {
        toggleViewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const currentView = this.getAttribute('data-view');
            
            if (currentView === 'table') {
                // Liste görünümüne geç
                cardView.style.display = 'none';
                tableView.style.display = 'block';
                
                this.innerHTML = '<i class="fas fa-th-large"></i> Kart Görünümü';
                this.setAttribute('data-view', 'card');
                toggleViewBtn.classList.add('active');
                
                // Tercihi local storage'a kaydet
                localStorage.setItem('personnel_view_mode', 'table');
            } else {
                // Kart görünümüne geç
                cardView.style.display = 'block';
                tableView.style.display = 'none';
                
                this.innerHTML = '<i class="fas fa-list"></i> Liste Görünümü';
                this.setAttribute('data-view', 'table');
                toggleViewBtn.classList.remove('active');
                
                // Tercihi local storage'a kaydet
                localStorage.setItem('personnel_view_mode', 'card');
            }
        });
        
        // Sayfa yüklendiğinde önceki tercihi kullan
        const savedViewMode = localStorage.getItem('personnel_view_mode');
        if (savedViewMode === 'table') {
            // Tablo görünümünü aktif et
            cardView.style.display = 'none';
            tableView.style.display = 'block';
            
            toggleViewBtn.innerHTML = '<i class="fas fa-th-large"></i> Kart Görünümü';
            toggleViewBtn.setAttribute('data-view', 'card');
            toggleViewBtn.classList.add('active');
        }
    }
    
    // Filtre butonlarının tıklanması
    const filters = document.querySelectorAll('.filter-control');
    filters.forEach(filter => {
        filter.addEventListener('change', function() {
            // Form submit'i burada yapılabilir veya otomatik gönderme için
            // document.querySelector('.filter-form').submit();
        });
    });
    
    // Durum değiştirme butonlarına tıklama onayı
    const statusButtons = document.querySelectorAll('.btn-outline-warning, .btn-outline-success');
    statusButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const isActive = this.classList.contains('btn-outline-warning');
            const confirmMessage = isActive ? 
                'Bu temsilciyi pasife almak istediğinizden emin misiniz?' : 
                'Bu temsilciyi aktif etmek istediğinizden emin misiniz?';
                
            if (!confirm(confirmMessage)) {
                e.preventDefault();
            }
        });
    });
});
</script>