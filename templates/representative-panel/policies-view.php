<?php
/**
 * Poliçe Detay Görüntüleme Sayfası
 * @version 2.0.0
 * @updated 2025-05-29
 */

if (!defined('ABSPATH') || !is_user_logged_in()) {
    wp_die(__('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'insurance-crm'), __('Erişim Engellendi', 'insurance-crm'), array('response' => 403));
}

global $wpdb;
$policy_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($policy_id <= 0) {
    echo '<div class="error-notice">Geçersiz poliçe kimliği.</div>';
    return;
}

// Poliçe verilerini getir
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$policy = $wpdb->get_row($wpdb->prepare("SELECT * FROM $policies_table WHERE id = %d", $policy_id));

if (!$policy) {
    echo '<div class="error-notice">Poliçe bulunamadı. Silinmiş olabilir.</div>';
    return;
}

// Müşteri verilerini getir
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customers_table WHERE id = %d", $policy->customer_id));

if (!$customer) {
    echo '<div class="error-notice">Müşteri bulunamadı. Silinmiş olabilir.</div>';
    return;
}

// Temsilci verilerini getir
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;
$representative = $wpdb->get_row($wpdb->prepare(
    "SELECT r.*, u.display_name FROM $representatives_table r 
     JOIN $users_table u ON r.user_id = u.ID
     WHERE r.id = %d",
    $policy->representative_id
));

// Silme bilgisi için kullanıcı adını çek
$deleted_by_user = null;
if (!empty($policy->deleted_by)) {
    $deleted_by_user = $wpdb->get_var($wpdb->prepare(
        "SELECT display_name FROM $users_table WHERE ID = %d",
        $policy->deleted_by
    ));
}

// Erişim kontrolü
$can_edit = can_edit_policy($policy_id, get_user_role_level(), get_current_user_rep_id());
$can_delete = can_delete_policy($policy_id, get_user_role_level(), get_current_user_rep_id());

// Poliçe durumu hesaplama
$is_cancelled = !empty($policy->cancellation_date);
$is_passive = ($policy->status === 'pasif' && empty($policy->cancellation_date));
$is_expired = (strtotime($policy->end_date) < time() && $policy->status === 'aktif' && !$is_cancelled);
$is_expiring = (!$is_expired && !$is_passive && !$is_cancelled && 
              strtotime($policy->end_date) >= time() && 
              (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60));
$is_deleted = !empty($policy->is_deleted);
?>

<style>
    .policy-view-container {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        max-width: 1200px;
        margin: 20px auto;
    }
    
    .policy-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .policy-title {
        display: flex;
        flex-direction: column;
    }
    
    .policy-title h2 {
        margin: 0 0 5px 0;
        font-size: 24px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .policy-title p {
        margin: 0;
        color: #666;
        font-size: 14px;
    }
    
    .policy-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .policy-details {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
    }
    
    .policy-section {
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    
    .policy-section h3 {
        margin: 0 0 15px 0;
        font-size: 18px;
        color: #333;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .policy-section h3 i {
        color: #1976d2;
    }
    
    .info-row {
        display: flex;
        margin-bottom: 12px;
    }
    
    .info-label {
        width: 40%;
        font-weight: 500;
        color: #555;
        font-size: 14px;
    }
    
    .info-value {
        width: 60%;
        color: #333;
        font-size: 14px;
    }
    
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 12px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
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
    
    .status-deleted {
        background-color: #424242;
        color: #fff;
    }
    
    .policy-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 500;
        margin-left: 8px;
    }
    
    .badge-expired {
        background-color: #ffecb3;
        color: #ff6f00;
    }
    
    .badge-expiring {
        background-color: #e3f2fd;
        color: #1976d2;
    }
    
    .badge-renewal {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .badge-new {
        background-color: #e1f5fe;
        color: #0288d1;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
        text-decoration: none;
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
    
    .btn-warning {
        background-color: #ff9800;
        color: white;
    }
    
    .btn-warning:hover {
        background-color: #f57c00;
    }
    
    .btn-danger {
        background-color: #f44336;
        color: white;
    }
    
    .btn-danger:hover {
        background-color: #d32f2f;
    }
    
    .btn-success {
        background-color: #4caf50;
        color: white;
    }
    
    .btn-success:hover {
        background-color: #388e3c;
    }
    
    .file-link {
        display: flex;
        align-items: center;
        color: #1976d2;
        text-decoration: none;
        gap: 5px;
        font-weight: 500;
    }
    
    .file-link:hover {
        text-decoration: underline;
    }
    
    /* Cancellation Section */
    .cancellation-section h3 {
        color: #c62828;
    }
    
    .cancellation-section h3 i {
        color: #c62828;
    }
    
    .cancellation-section {
        background-color: #fff8f8;
    }
    
    .deletion-info {
        background-color: #f5f5f5;
        border-left: 4px solid #616161;
        padding: 15px;
        margin-top: 15px;
        border-radius: 4px;
    }
    
    .deletion-info h4 {
        margin: 0 0 10px 0;
        color: #616161;
        font-size: 15px;
    }
    
    .deletion-info p {
        margin: 0 0 5px 0;
        color: #616161;
        font-size: 13px;
    }
    
    /* Responsive styles */
    @media (max-width: 768px) {
        .policy-header {
            flex-direction: column;
        }
        
        .policy-actions {
            justify-content: flex-start;
        }
        
        .policy-details {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="policy-view-container">
    <div class="policy-header">
        <div class="policy-title">
            <h2>
                <i class="fas fa-file-contract"></i>
                Poliçe: <?php echo esc_html($policy->policy_number); ?>
                
                <?php if ($is_deleted): ?>
                <span class="status-badge status-deleted">
                    <i class="fas fa-trash-alt"></i> Silinmiş
                </span>
                <?php elseif ($is_cancelled): ?>
                <span class="status-badge status-iptal">
                    <i class="fas fa-ban"></i> İptal Edilmiş
                </span>
                <?php elseif ($is_passive): ?>
                <span class="status-badge status-pasif">
                    <i class="fas fa-power-off"></i> Pasif
                </span>
                <?php else: ?>
                <span class="status-badge status-aktif">
                    <i class="fas fa-check-circle"></i> Aktif
                </span>
                <?php endif; ?>
                
                <?php if ($is_expired): ?>
                <span class="policy-badge badge-expired">
                    <i class="fas fa-exclamation-circle"></i> Süresi Dolmuş
                </span>
                <?php elseif ($is_expiring): ?>
                <span class="policy-badge badge-expiring">
                    <i class="fas fa-clock"></i> Yakında Bitiyor
                </span>
                <?php endif; ?>
                
                <?php if ($policy->policy_category === 'Yenileme'): ?>
                <span class="policy-badge badge-renewal">
                    <i class="fas fa-sync-alt"></i> Yenileme
                </span>
                <?php elseif ($policy->policy_category === 'Yeni İş'): ?>
                <span class="policy-badge badge-new">
                    <i class="fas fa-plus"></i> Yeni İş
                </span>
                <?php endif; ?>
            </h2>
            <p>
                <?php echo esc_html($policy->policy_type); ?> - 
                <?php echo esc_html($policy->insurance_company); ?> 
                <?php if (!empty($policy->network)): ?>
                (<?php echo esc_html($policy->network); ?>)
                <?php endif; ?>
            </p>
        </div>
        
        <div class="policy-actions">
            <a href="?view=policies<?php echo isset($_GET['view_type']) && $_GET['view_type'] === 'team' ? '&view_type=team' : ''; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Listeye Dön
            </a>
            
            <?php if (!$is_deleted): ?>
                <?php if ($can_edit): ?>
                <a href="?view=policies&action=edit&id=<?php echo $policy_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Düzenle
                </a>
                <?php endif; ?>
                
                <?php if ($policy->status === 'aktif' && empty($policy->cancellation_date) && $can_edit): ?>
                <a href="?view=policies&action=renew&id=<?php echo $policy_id; ?>" class="btn btn-success">
                    <i class="fas fa-redo"></i> Yenile
                </a>
                
                <a href="?view=policies&action=cancel&id=<?php echo $policy_id; ?>" class="btn btn-warning" 
                   onclick="return confirm('Bu poliçeyi iptal etmek istediğinizden emin misiniz?');">
                    <i class="fas fa-ban"></i> İptal Et
                </a>
                <?php endif; ?>
                
                <?php if ($can_delete): ?>
                <a href="<?php echo wp_nonce_url('?view=policies&action=delete&id=' . $policy_id, 'delete_policy_' . $policy_id); ?>" class="btn btn-danger"
                   onclick="return confirm('Bu poliçeyi silmek istediğinizden emin misiniz? Bu işlem geri alınabilir ancak poliçe listede görünmez olacak.');">
                    <i class="fas fa-trash"></i> Sil
                </a>
                <?php endif; ?>
            <?php else: ?>
                <?php if (get_user_role_level() <= 2): ?>
                <a href="<?php echo wp_nonce_url('?view=policies&action=restore&id=' . $policy_id, 'restore_policy_' . $policy_id); ?>" class="btn btn-success"
                   onclick="return confirm('Bu poliçeyi geri getirmek istediğinizden emin misiniz?');">
                    <i class="fas fa-trash-restore"></i> Geri Getir
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="policy-details">
        <!-- Müşteri Bilgileri -->
        <div class="policy-section">
            <h3>
                <i class="fas fa-user"></i>
                Müşteri Bilgileri
            </h3>
            
            <div class="info-row">
                <div class="info-label">Müşteri Adı:</div>
                <div class="info-value">
                    <a href="?view=customers&action=view&id=<?php echo $customer->id; ?>">
                        <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                    </a>
                </div>
            </div>
            
            <?php if (!empty($customer->tc_identity)): ?>
            <div class="info-row">
                <div class="info-label">TC Kimlik No:</div>
                <div class="info-value"><?php echo esc_html($customer->tc_identity); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($customer->phone)): ?>
            <div class="info-row">
                <div class="info-label">Telefon:</div>
                <div class="info-value"><?php echo esc_html($customer->phone); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($customer->email)): ?>
            <div class="info-row">
                <div class="info-label">E-posta:</div>
                <div class="info-value"><?php echo esc_html($customer->email); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($customer->address)): ?>
            <div class="info-row">
                <div class="info-label">Adres:</div>
                <div class="info-value"><?php echo esc_html($customer->address); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($policy->insured_party)): ?>
            <div class="info-row">
                <div class="info-label">Sigortalı:</div>
                <div class="info-value"><?php echo esc_html($policy->insured_party); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Poliçe Bilgileri -->
        <div class="policy-section">
            <h3>
                <i class="fas fa-file-alt"></i>
                Poliçe Detayları
            </h3>
            
            <div class="info-row">
                <div class="info-label">Poliçe Numarası:</div>
                <div class="info-value"><?php echo esc_html($policy->policy_number); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Poliçe Türü:</div>
                <div class="info-value"><?php echo esc_html($policy->policy_type); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Kategori:</div>
                <div class="info-value"><?php echo esc_html($policy->policy_category); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Sigorta Şirketi:</div>
                <div class="info-value"><?php echo esc_html($policy->insurance_company); ?></div>
            </div>
            
            <?php if (!empty($policy->network)): ?>
            <div class="info-row">
                <div class="info-label">Network/Anlaşmalı:</div>
                <div class="info-value"><?php echo esc_html($policy->network); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="info-row">
                <div class="info-label">Başlangıç Tarihi:</div>
                <div class="info-value"><?php echo date('d.m.Y', strtotime($policy->start_date)); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Bitiş Tarihi:</div>
                <div class="info-value"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Durum:</div>
                <div class="info-value">
                    <span class="status-badge status-<?php echo esc_attr($policy->status); ?>">
                        <?php 
                        if ($policy->status === 'aktif') echo 'Aktif';
                        elseif ($policy->status === 'pasif') echo 'Pasif';
                        elseif ($policy->status === 'iptal') echo 'İptal';
                        else echo esc_html($policy->status);
                        ?>
                    </span>
                </div>
            </div>
            
            <?php if (!empty($policy->status_note)): ?>
            <div class="info-row">
                <div class="info-label">Durum Notu:</div>
                <div class="info-value"><?php echo esc_html($policy->status_note); ?></div>
            </div>
            <?php endif; ?>
            
            <div class="info-row">
                <div class="info-label">Oluşturulma Tarihi:</div>
                <div class="info-value"><?php echo date('d.m.Y H:i', strtotime($policy->created_at)); ?></div>
            </div>
            
            <?php if ($policy->updated_at !== $policy->created_at): ?>
            <div class="info-row">
                <div class="info-label">Son Güncelleme:</div>
                <div class="info-value"><?php echo date('d.m.Y H:i', strtotime($policy->updated_at)); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Ödeme Bilgileri -->
        <div class="policy-section">
            <h3>
                <i class="fas fa-money-bill-wave"></i>
                Ödeme Bilgileri
            </h3>
            
            <div class="info-row">
                <div class="info-label">Prim Tutarı:</div>
                <div class="info-value"><?php echo number_format($policy->premium_amount, 2, ',', '.') . ' ₺'; ?></div>
            </div>
            
            <?php if (!empty($policy->payment_info)): ?>
            <div class="info-row">
                <div class="info-label">Ödeme Bilgisi:</div>
                <div class="info-value"><?php echo esc_html($policy->payment_info); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($policy->document_path)): ?>
            <div class="info-row">
                <div class="info-label">Poliçe Dökümantasyonu:</div>
                <div class="info-value">
                    <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" class="file-link">
                        <i class="fas fa-file-pdf"></i>
                        Poliçe Dosyasını Görüntüle
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($representative)): ?>
            <div class="info-row">
                <div class="info-label">Sorumlu Temsilci:</div>
                <div class="info-value"><?php echo esc_html($representative->display_name); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- İptal Bilgileri (varsa) -->
        <?php if (!empty($policy->cancellation_date)): ?>
        <div class="policy-section cancellation-section">
            <h3>
                <i class="fas fa-ban"></i>
                İptal Bilgileri
            </h3>
            
            <div class="info-row">
                <div class="info-label">İptal Tarihi:</div>
                <div class="info-value"><?php echo date('d.m.Y', strtotime($policy->cancellation_date)); ?></div>
            </div>
            
            <?php if (!empty($policy->cancellation_reason)): ?>
            <div class="info-row">
                <div class="info-label">İptal Nedeni:</div>
                <div class="info-value"><?php echo esc_html($policy->cancellation_reason); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($policy->refunded_amount > 0): ?>
            <div class="info-row">
                <div class="info-label">İade Edilen Tutar:</div>
                <div class="info-value"><?php echo number_format($policy->refunded_amount, 2, ',', '.') . ' ₺'; ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Silinen Poliçe Bilgileri (varsa) -->
        <?php if ($is_deleted): ?>
        <div class="policy-section">
            <h3>
                <i class="fas fa-trash-alt"></i>
                Silinme Bilgileri
            </h3>
            
            <div class="deletion-info">
                <h4>Bu poliçe silinmiş durumda</h4>
                <?php if (!empty($policy->deleted_at)): ?>
                <p><strong>Silinme Tarihi:</strong> <?php echo date('d.m.Y H:i', strtotime($policy->deleted_at)); ?></p>
                <?php endif; ?>
                
                <?php if (!empty($deleted_by_user)): ?>
                <p><strong>Silen Kullanıcı:</strong> <?php echo esc_html($deleted_by_user); ?></p>
                <?php endif; ?>
                
                <p><em>Patron veya Müdür seviyesindeki kullanıcılar silinmiş poliçeleri geri getirebilir.</em></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dinamik tarih ve saat formatlamaları
    const dateElements = document.querySelectorAll('.format-date');
    dateElements.forEach(function(element) {
        const dateStr = element.getAttribute('data-date');
        if (dateStr) {
            const date = new Date(dateStr);
            element.textContent = date.toLocaleDateString('tr-TR');
        }
    });
    
    // Poliçe durumuna göre ekstra bilgilendirme
    const policyStatus = "<?php echo $policy->status; ?>";
    const isPolicyExpired = <?php echo $is_expired ? 'true' : 'false'; ?>;
    const isPolicyExpiring = <?php echo $is_expiring ? 'true' : 'false'; ?>;
    
    if (policyStatus === 'aktif' && isPolicyExpired) {
        const policyHeader = document.querySelector('.policy-title');
        const expirationWarning = document.createElement('div');
        expirationWarning.innerHTML = `
            <div style="margin-top: 10px; padding: 10px; background-color: #fff3e0; border-left: 4px solid #ff9800; border-radius: 4px;">
                <p style="margin: 0; font-size: 14px; color: #e65100;">
                    <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                    Bu poliçenin süresi dolmuş. Müşteriye yenileme teklifi göndermek isteyebilirsiniz.
                </p>
            </div>
        `;
        policyHeader.appendChild(expirationWarning);
    } else if (policyStatus === 'aktif' && isPolicyExpiring) {
        const policyHeader = document.querySelector('.policy-title');
        const expirationWarning = document.createElement('div');
        expirationWarning.innerHTML = `
            <div style="margin-top: 10px; padding: 10px; background-color: #e1f5fe; border-left: 4px solid #03a9f4; border-radius: 4px;">
                <p style="margin: 0; font-size: 14px; color: #01579b;">
                    <i class="fas fa-info-circle" style="margin-right: 8px;"></i>
                    Bu poliçe 30 gün içinde sona erecek. Müşteriye yenileme hatırlatması yapabilirsiniz.
                </p>
            </div>
        `;
        policyHeader.appendChild(expirationWarning);
    }
    
    // Poliçe dökümantasyonu için PDF önizleme (varsa)
    const documentPath = "<?php echo isset($policy->document_path) ? esc_url($policy->document_path) : ''; ?>";
    if (documentPath && documentPath.toLowerCase().endsWith('.pdf')) {
        const documentLink = document.querySelector('.file-link');
        if (documentLink) {
            documentLink.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Modern PDF önizleme
                const modal = document.createElement('div');
                modal.innerHTML = `
                    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 9999; display: flex; align-items: center; justify-content: center;">
                        <div style="background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column;">
                            <div style="display: flex; justify-content: space-between; padding: 15px; border-bottom: 1px solid #eee;">
                                <h3 style="margin: 0; font-size: 18px;">Poliçe Dökümantasyonu</h3>
                                <button style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                            </div>
                            <div style="flex: 1; padding: 0; overflow: hidden;">
                                <iframe src="${documentPath}" style="width: 100%; height: 70vh; border: none;"></iframe>
                            </div>
                            <div style="padding: 15px; text-align: right; border-top: 1px solid #eee;">
                                <a href="${documentPath}" target="_blank" style="display: inline-block; padding: 8px 16px; background: #1976d2; color: white; border-radius: 4px; text-decoration: none;">Yeni Pencerede Aç</a>
                                <button style="margin-left: 10px; padding: 8px 16px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Kapat</button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
                
                // Kapatma olayları
                const closeBtn = modal.querySelector('button');
                const closeBtn2 = modal.querySelectorAll('button')[1];
                const modalBg = modal.querySelector('div');
                
                closeBtn.addEventListener('click', function() {
                    document.body.removeChild(modal);
                });
                
                closeBtn2.addEventListener('click', function() {
                    document.body.removeChild(modal);
                });
                
                modalBg.addEventListener('click', function(e) {
                    if (e.target === modalBg) {
                        document.body.removeChild(modal);
                    }
                });
            });
        }
    }
    
    // Sayfa bildirimlerini göster
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('updated') === 'true') {
        showNotification('Poliçe başarıyla güncellendi', 'success');
    }
    
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = 'notification-banner notification-' + type;
        notification.innerHTML = `
            <div class="notification-icon">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            </div>
            <div class="notification-content">
                ${message}
            </div>
            <button class="notification-close">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.querySelector('.policy-view-container').prepend(notification);
        
        const closeBtn = notification.querySelector('.notification-close');
        closeBtn.addEventListener('click', function() {
            notification.style.opacity = '0';
            setTimeout(() => {
                notification.remove();
            }, 300);
        });
        
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 5000);
    }
});
</script>