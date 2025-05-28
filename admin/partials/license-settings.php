<?php
// Doğrudan erişime izin verme
if (!defined('ABSPATH')) {
    exit;
}

// Lisans durumu ve bilgilerini al
$license_key = get_option('insurance_crm_license_key', '');
$license_status = get_option('insurance_crm_license_status', 'inactive');
$license_type = get_option('insurance_crm_license_type', '');
$license_expiry = get_option('insurance_crm_license_expiry', '');

// İnsan tarafından okunabilir durumu belirle
$status_text = 'Etkin Değil';
$status_class = 'invalid';

if ($license_status === 'active') {
    $status_text = 'Etkin';
    $status_class = 'valid';
} elseif ($license_status === 'expired') {
    $status_text = 'Süresi Dolmuş';
    $status_class = 'expired';
} elseif ($license_status === 'invalid') {
    $status_text = 'Geçersiz';
    $status_class = 'invalid';
}

// Tarih formatını düzenle
$expiry_date = '';
if (!empty($license_expiry)) {
    $expiry_date = date_i18n(get_option('date_format'), strtotime($license_expiry));
}

// Kalan gün sayısını hesapla
$days_left = '';
if ($license_status === 'active' && $license_type === 'monthly' && !empty($license_expiry)) {
    $days_left = ceil((strtotime($license_expiry) - time()) / 86400);
    if ($days_left < 0) {
        $days_left = 0;
    }
}
?>

<div class="wrap insurance-crm-license-page">
    <h1>Insurance CRM Lisans Yönetimi</h1>
    
    <?php settings_errors('insurance_crm_license'); ?>
    
    <div class="license-info-box">
        <h2>Lisans Durumu</h2>
        
        <?php if ($license_status === 'active'): ?>
            <div class="license-status-card active">
                <span class="dashicons dashicons-yes-alt"></span>
                <div class="license-details">
                    <h3>Lisans Aktif</h3>
                    <p class="license-type">
                        <?php echo $license_type === 'monthly' ? 'Aylık Abonelik' : 'Ömür Boyu Lisans'; ?>
                    </p>
                    <?php if ($license_type === 'monthly' && !empty($expiry_date)): ?>
                        <p class="expiry-info">
                            Bitiş Tarihi: <?php echo $expiry_date; ?> 
                            (<?php echo $days_left; ?> gün kaldı)
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="license-status-card inactive">
                <span class="dashicons dashicons-warning"></span>
                <div class="license-details">
                    <h3>Lisans Aktif Değil</h3>
                    <p>Tüm özellikleri kullanmak için lisansınızı etkinleştirin.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="license-management-box">
        <h2><?php echo empty($license_key) ? 'Lisans Etkinleştir' : 'Lisans Yönetimi'; ?></h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Lisans Anahtarı</th>
                    <td>
                        <input type="text" name="insurance_crm_license_key" class="regular-text" 
                               value="<?php echo esc_attr($license_key); ?>" 
                               <?php echo $license_status === 'active' ? 'readonly' : ''; ?> />
                        
                        <?php if ($license_status === 'active'): ?>
                            <p class="description">
                                Lisans anahtarınız etkin. Bu alan güvenlik nedeniyle salt okunurdur.
                            </p>
                        <?php else: ?>
                            <p class="description">
                                Lisans anahtarınızı buraya girin. Eğer bir anahtarınız yoksa, 
                                <a href="https://yoursite.com/buy-license" target="_blank">satın almak için tıklayın</a>.
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if (!empty($license_key)): ?>
                <tr>
                    <th scope="row">Durum</th>
                    <td>
                        <span class="license-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if ($license_status === 'active'): ?>
                <tr>
                    <th scope="row">Lisans Tipi</th>
                    <td>
                        <?php echo $license_type === 'monthly' ? 'Aylık Abonelik' : 'Ömür Boyu Lisans'; ?>
                    </td>
                </tr>
                
                <?php if ($license_type === 'monthly' && !empty($expiry_date)): ?>
                <tr>
                    <th scope="row">Bitiş Tarihi</th>
                    <td>
                        <?php echo $expiry_date; ?> (<?php echo $days_left; ?> gün kaldı)
                    </td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
            </table>
            
            <p class="submit">
                <?php if ($license_status !== 'active'): ?>
                    <input type="hidden" name="insurance_crm_license_action" value="activate" />
                    <input type="submit" class="button button-primary" value="Lisansı Etkinleştir" />
                <?php else: ?>
                    <input type="hidden" name="insurance_crm_license_action" value="deactivate" />
                    <input type="submit" class="button" value="Lisansı Devre Dışı Bırak" />
                    
                    <?php if ($license_type === 'monthly'): ?>
                        <a href="https://yoursite.com/renew-license" target="_blank" class="button button-primary">
                            Lisansı Yenile
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </form>
    </div>
    
    <div class="license-info-box">
        <h2>Lisans Bilgileri</h2>
        <p>Insurance CRM plugini için iki tür lisanslama modeli sunulmaktadır:</p>
        
        <div class="license-types">
            <div class="license-type-card">
                <h3>Aylık Abonelik</h3>
                <ul>
                    <li>Tüm özelliklere erişim</li>
                    <li>Aylık otomatik yenileme</li>
                    <li>Öncelikli teknik destek</li>
                    <li>Aylık güncellemeler</li>
                </ul>
                <p class="price">399₺<span>/ay</span></p>
                <a href="https://yoursite.com/buy-monthly" target="_blank" class="button button-primary">
                    Satın Al
                </a>
            </div>
            
            <div class="license-type-card featured">
                <h3>Ömür Boyu Lisans</h3>
                <div class="badge">En İyi Değer</div>
                <ul>
                    <li>Tüm özelliklere sınırsız erişim</li>
                    <li>Tek seferlik ödeme</li>
                    <li>Öncelikli teknik destek</li>
                    <li>1 yıl ücretsiz güncellemeler</li>
                    <li>Sınırsız alan adı değişimi</li>
                </ul>
                <p class="price">3,999₺<span>/tek seferlik</span></p>
                <a href="https://yoursite.com/buy-lifetime" target="_blank" class="button button-primary">
                    Satın Al
                </a>
            </div>
        </div>
        
        <p class="license-note">
            * Lisans bir domain için geçerlidir. Farklı domainlerde kullanım için her domain için ayrı lisans satın alınmalıdır.
            <br>
            * Aylık abonelikler iptal edilebilir, ancak ücret iadesi yapılmamaktadır.
        </p>
    </div>
    
    <style>
        .insurance-crm-license-page .license-status-card {
            display: flex;
            align-items: center;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .insurance-crm-license-page .license-status-card.active {
            background-color: #f0f9eb;
            border: 1px solid #67c23a;
        }
        
        .insurance-crm-license-page .license-status-card.inactive {
            background-color: #fef0f0;
            border: 1px solid #f56c6c;
        }
        
        .insurance-crm-license-page .license-status-card .dashicons {
            font-size: 30px;
            width: 30px;
            height: 30px;
            margin-right: 15px;
        }
        
        .insurance-crm-license-page .license-status-card.active .dashicons {
            color: #67c23a;
        }
        
        .insurance-crm-license-page .license-status-card.inactive .dashicons {
            color: #f56c6c;
        }
        
        .insurance-crm-license-page .license-details h3 {
            margin: 0 0 5px;
        }
        
        .insurance-crm-license-page .license-details p {
            margin: 0;
        }
        
        .insurance-crm-license-page .license-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .insurance-crm-license-page .license-status.valid {
            background-color: #67c23a;
            color: white;
        }
        
        .insurance-crm-license-page .license-status.invalid,
        .insurance-crm-license-page .license-status.expired {
            background-color: #f56c6c;
            color: white;
        }
        
        .insurance-crm-license-page .license-info-box,
        .insurance-crm-license-page .license-management-box {
            background: white;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .insurance-crm-license-page .license-types {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin: 20px 0;
        }
        
        .insurance-crm-license-page .license-type-card {
            flex: 0 0 48%;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 5px;
            position: relative;
            margin-bottom: 15px;
        }
        
        .insurance-crm-license-page .license-type-card.featured {
            border-color: #007cba;
            box-shadow: 0 0 10px rgba(0,124,186,0.15);
        }
        
        .insurance-crm-license-page .license-type-card .badge {
            position: absolute;
            top: -10px;
            right: 20px;
            background: #007cba;
            color: white;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .insurance-crm-license-page .license-type-card h3 {
            margin-top: 0;
        }
        
        .insurance-crm-license-page .license-type-card ul {
            list-style-type: none;
            padding-left: 0;
        }
        
        .insurance-crm-license-page .license-type-card ul li:before {
            content: "✓ ";
            color: #67c23a;
            font-weight: bold;
        }
        
        .insurance-crm-license-page .license-type-card .price {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin: 15px 0;
        }
        
        .insurance-crm-license-page .license-type-card .price span {
            font-size: 14px;
            color: #999;
            font-weight: normal;
        }
        
        @media screen and (max-width: 782px) {
            .insurance-crm-license-page .license-type-card {
                flex: 0 0 100%;
            }
        }
    </style>
</div>