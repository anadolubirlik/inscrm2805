<?php
/**
 * Insurance CRM License Manager
 *
 * @package Insurance_CRM
 */

class Insurance_CRM_License_Manager {
    private $license_key;
    private $license_status;
    private $license_type; // 'monthly' veya 'lifetime'
    private $expiry_date;
    private $domain;
    private $api_url;
    private $product_id = 'insurance-crm';
    private $version;
    private $debug_mode;
    private $bypass_license;
    private $customer_name;
    
    public function __construct($version = '1.1.3') {
        // Version parametresi olarak aldığımız değeri kullanacağız
        $this->version = $version;
        $this->domain = $this->get_site_domain();
        $this->license_key = get_option('insurance_crm_license_key', '');
        $this->license_status = get_option('insurance_crm_license_status', 'inactive');
        $this->license_type = get_option('insurance_crm_license_type', '');
        $this->expiry_date = get_option('insurance_crm_license_expiry', '');
        $this->api_url = get_option('insurance_crm_license_api_url', 'https://benzersizkod.com/api');
        $this->debug_mode = get_option('insurance_crm_license_debug_mode', false);
        $this->bypass_license = get_option('insurance_crm_bypass_license', true);
        $this->customer_name = get_option('insurance_crm_customer_name', '');
        
        // Lisans aktivasyon ve kontrol hook'ları
        add_action('admin_init', array($this, 'check_license_status'));
        add_action('admin_init', array($this, 'process_license_actions'));
        
        // Plugin etkinleştirme hook'u
        register_activation_hook(INSURANCE_CRM_FILE, array($this, 'activation_check'));
        
        // Düzenli lisans kontrolü için cron işi
        add_action('insurance_crm_license_check', array($this, 'scheduled_license_check'));
        
        // Admin menü ve ayarlar sayfası
        add_action('admin_menu', array($this, 'add_license_menu'), 99);
        
        // Eğer bypass_license false ise bildirimleri göster
        if (!$this->bypass_license && $this->license_status !== 'active') {
            add_action('admin_notices', array($this, 'admin_notices'));
        }
        
        // Günlük lisans kontrolü için cron işi planlama
        if (!wp_next_scheduled('insurance_crm_license_check')) {
            wp_schedule_event(time(), 'daily', 'insurance_crm_license_check');
        }
    }
    
    /**
     * Plugin etkinleştirildiğinde çalışır
     */
    public function activation_check() {
        // Lisans anahtarı kontrol et
        if (empty($this->license_key) || $this->license_status !== 'active') {
            // Lisans geçerli değilse bildirim göndereceğiz
            if (!$this->bypass_license) {
                $this->send_activation_notification();
            }
            
            // Lisans sayfasına yönlendirilecek bayrak ayarla
            add_option('insurance_crm_redirect_to_license', true);
        }
    }
    
    /**
     * Admin başlatıldığında lisans durumunu kontrol et
     */
    public function check_license_status() {
        // Bypass modu aktifse lisans kontrolü yapma
        if ($this->bypass_license) {
            return;
        }
        
        // Eğer redirect bayrağı varsa ve admin ise
        if (get_option('insurance_crm_redirect_to_license') && current_user_can('manage_options')) {
            // Bayrağı kaldır
            delete_option('insurance_crm_redirect_to_license');
            
            // Lisans sayfasına yönlendir
            wp_redirect(admin_url('admin.php?page=insurance-crm-license'));
            exit;
        }
        
        // Lisans süresi geçmiş mi kontrol et (aylık abonelik için)
        if ($this->license_status === 'active' && $this->license_type === 'monthly') {
            if (!empty($this->expiry_date) && strtotime($this->expiry_date) < time()) {
                $this->license_status = 'expired';
                update_option('insurance_crm_license_status', 'expired');
                $this->send_expiry_notification();
            }
        }
    }
    
    /**
     * Düzenli olarak lisans kontrolü yapar
     */
    public function scheduled_license_check() {
        // Bypass modu aktifse lisans kontrolü yapma
        if ($this->bypass_license) {
            return;
        }
        
        // Sadece aktif lisansları kontrol et
        if ($this->license_status === 'active') {
            $this->verify_license_with_api();
        }
    }
    

/**
 * Lisans işlemlerini yürütür
 */
public function process_license_actions() {
    if (!isset($_POST['insurance_crm_license_action'])) {
        return;
    }
    
    if (!isset($_POST['insurance_crm_license_nonce']) || 
        !wp_verify_nonce($_POST['insurance_crm_license_nonce'], 'insurance_crm_license')) {
        wp_die('Güvenlik doğrulaması başarısız oldu');
    }
    
    $action = sanitize_text_field($_POST['insurance_crm_license_action']);
    
    if ($action === 'activate') {
        $license_key = isset($_POST['insurance_crm_license_key']) ? 
                       sanitize_text_field($_POST['insurance_crm_license_key']) : '';
        
        if (empty($license_key)) {
            add_settings_error('insurance_crm_license', 'empty-license', 
                'Lütfen bir lisans anahtarı girin.');
            return;
        }
        
        $this->license_key = $license_key;
        update_option('insurance_crm_license_key', $license_key);
        
        // Bypass modu aktifse, API isteği yapmadan aktif göster
        if ($this->bypass_license) {
            $result = [
                'success' => true,
                'license_type' => 'lifetime',
                'expiry_date' => '',
                'customer_name' => 'Geliştirici Modu'
            ];
        } else {
            $result = $this->activate_license();
        }
        
        if ($result['success']) {
            $this->license_status = 'active';
            $this->license_type = $result['license_type'];
            $this->expiry_date = $result['expiry_date'];
            $this->customer_name = $result['customer_name'] ?? '';
            
            update_option('insurance_crm_license_status', 'active');
            update_option('insurance_crm_license_type', $result['license_type']);
            update_option('insurance_crm_license_expiry', $result['expiry_date']);
            update_option('insurance_crm_customer_name', $this->customer_name);
            
            // Bypass modu aktif değilse aktivasyon bildirimi gönder
            if (!$this->bypass_license) {
                $this->send_activation_notification(true);
            }
            
            add_settings_error('insurance_crm_license', 'license-activated', 
                'Lisans başarıyla etkinleştirildi.', 'updated');
        } else {
            add_settings_error('insurance_crm_license', 'license-error', 
                'Lisans etkinleştirilemedi: ' . $result['message']);
        }
    } elseif ($action === 'deactivate') {
        // Bypass modu aktifse, API isteği yapmadan deaktif göster
        if ($this->bypass_license) {
            $result = [
                'success' => true
            ];
        } else {
            $result = $this->deactivate_license();
        }
        
        if ($result['success']) {
            $this->license_status = 'inactive';
            update_option('insurance_crm_license_status', 'inactive');
            
            add_settings_error('insurance_crm_license', 'license-deactivated', 
                'Lisans devre dışı bırakıldı.', 'updated');
        } else {
            add_settings_error('insurance_crm_license', 'license-error', 
                'Lisans devre dışı bırakılamadı: ' . $result['message']);
        }
    } elseif ($action === 'toggle_debug') {
        $debug_mode = !$this->debug_mode;
        update_option('insurance_crm_license_debug_mode', $debug_mode);
        $this->debug_mode = $debug_mode;
        
        add_settings_error('insurance_crm_license', 'debug-mode-' . ($debug_mode ? 'enabled' : 'disabled'), 
            'Hata ayıklama modu ' . ($debug_mode ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.', 'updated');
    } elseif ($action === 'toggle_bypass') {
        // Mevcut değeri al - varsayılan değeri null olarak ayarla ki eğer ayar yoksa hata olmasın
        $current_bypass = get_option('insurance_crm_bypass_license', null);
        
        // Değeri tersine çevir (null ise false olacak şekilde)
        $bypass_license = !($current_bypass === true);
        
        // Ayarı kaydet
        update_option('insurance_crm_bypass_license', $bypass_license);
        
        // Sınıf değişkenini güncelle
        $this->bypass_license = $bypass_license;
        
        // Kullanıcıya mesaj göster
        add_settings_error('insurance_crm_license', 'bypass-mode-' . ($bypass_license ? 'enabled' : 'disabled'), 
            'Lisans kontrolünü atlama modu ' . ($bypass_license ? 'etkinleştirildi' : 'devre dışı bırakıldı') . '.', 'updated');
    } elseif ($action === 'save_settings') {
        $api_url = isset($_POST['insurance_crm_license_api_url']) ? 
                   esc_url_raw($_POST['insurance_crm_license_api_url']) : '';
        $api_key = isset($_POST['insurance_crm_license_api_key']) ? 
                   sanitize_text_field($_POST['insurance_crm_license_api_key']) : '';
        
        update_option('insurance_crm_license_api_url', $api_url);
        update_option('insurance_crm_license_api_key', $api_key);
        
        $this->api_url = $api_url;
        
        add_settings_error('insurance_crm_license', 'settings-saved', 
            'API ayarları kaydedildi.', 'updated');
    }
}

    
    /**
     * API'ye lisansı etkinleştirmek için istek gönder
     */
    private function activate_license() {
        $api = new Insurance_CRM_License_API($this->api_url);
        
        if ($this->debug_mode) {
            $response = $api->simulate_response('activate', true);
        } else {
            $response = $api->activate_license([
                'license_key' => $this->license_key,
                'domain' => $this->domain,
                'product_id' => $this->product_id,
                'version' => $this->version
            ]);
        }
        
        return $response;
    }
    
    /**
     * API'ye lisansı devre dışı bırakmak için istek gönder
     */
    private function deactivate_license() {
        $api = new Insurance_CRM_License_API($this->api_url);
        
        if ($this->debug_mode) {
            $response = $api->simulate_response('deactivate', true);
        } else {
            $response = $api->deactivate_license([
                'license_key' => $this->license_key,
                'domain' => $this->domain,
                'product_id' => $this->product_id
            ]);
        }
        
        return $response;
    }
    
    /**
     * API üzerinden lisansın geçerliliğini doğrula
     */
    private function verify_license_with_api() {
        $api = new Insurance_CRM_License_API($this->api_url);
        
        if ($this->debug_mode) {
            $response = $api->simulate_response('verify', true);
        } else {
            $response = $api->verify_license([
                'license_key' => $this->license_key,
                'domain' => $this->domain,
                'product_id' => $this->product_id,
                'version' => $this->version
            ]);
        }
        
        if (!$response['success']) {
            // Lisans artık geçerli değil
            $this->license_status = 'invalid';
            update_option('insurance_crm_license_status', 'invalid');
            $this->send_invalid_license_notification();
        } elseif ($response['license_type'] === 'monthly' && isset($response['expiry_date'])) {
            // Süresi güncellenmiş olabilir, yeni süreyi kaydet
            $this->expiry_date = $response['expiry_date'];
            update_option('insurance_crm_license_expiry', $response['expiry_date']);
        }
        
        return $response;
    }
    
    /**
     * Site alan adını al
     */
    private function get_site_domain() {
        $domain = parse_url(home_url(), PHP_URL_HOST);
        return $domain;
    }
    
    /**
     * Lisans menüsü ekle
     */
    public function add_license_menu() {
        add_submenu_page(
            'insurance-crm',
            'Lisans Yönetimi',
            'Lisans Yönetimi',
            'manage_options',
            'insurance-crm-license',
            array($this, 'render_license_page')
        );
    }
    
    /**
     * Lisans sayfasını render et
     */
    public function render_license_page() {
        ?>
        <div class="wrap">
            <h1>Insurance CRM Lisans Yönetimi</h1>
            
            <?php settings_errors('insurance_crm_license'); ?>
            
            <?php if ($this->bypass_license): ?>
            <div class="notice notice-info">
                <p><strong>Geliştirici Modu Aktif:</strong> Lisans kontrolleri şu anda atlanmaktadır. Üretim ortamında bu değeri devre dışı bırakmanız önerilir.</p>
            </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
                <h2>Lisans Durumu</h2>
                
                <?php if ($this->license_status === 'active'): ?>
                <div style="background: #f0f9eb; padding: 15px; border-left: 4px solid #67c23a; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #67c23a;"><span class="dashicons dashicons-yes-alt"></span> Lisans Aktif</h3>
                    <p>Lisans türü: <?php echo $this->license_type === 'monthly' ? 'Aylık Abonelik' : 'Ömür Boyu'; ?></p>
                    <?php if ($this->license_type === 'monthly' && !empty($this->expiry_date)): ?>
                        <?php 
                            $days_left = ceil((strtotime($this->expiry_date) - time()) / 86400);
                            $expiry_date = date_i18n(get_option('date_format'), strtotime($this->expiry_date));
                        ?>
                        <p>Bitiş tarihi: <?php echo $expiry_date; ?> (<?php echo $days_left; ?> gün kaldı)</p>
                    <?php endif; ?>
                    <?php if (!empty($this->customer_name)): ?>
                        <p>Müşteri: <?php echo $this->customer_name; ?></p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div style="background: #fbeaea; padding: 15px; border-left: 4px solid #dc3232; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #dc3232;"><span class="dashicons dashicons-warning"></span> Lisans Aktif Değil</h3>
                    <p>Insurance CRM'in tüm özelliklerini kullanmak için lütfen lisans satın alın.</p>
                </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Lisans Anahtarı</th>
                            <td>
                                <input type="text" name="insurance_crm_license_key" class="regular-text" 
                                       value="<?php echo esc_attr($this->license_key); ?>" 
                                       <?php echo $this->license_status === 'active' ? 'readonly' : ''; ?> />
                                
                                <?php if ($this->license_status === 'active'): ?>
                                    <p class="description">
                                        Lisans anahtarınız etkin. Bu alan güvenlik nedeniyle salt okunurdur.
                                    </p>
                                <?php else: ?>
                                    <p class="description">
                                        Lisans anahtarınızı buraya girin. Eğer bir anahtarınız yoksa, 
                                        <a href="https://benzersizkod.com/satin-al" target="_blank">satın almak için tıklayın</a>.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <?php if ($this->license_status !== 'active'): ?>
                            <input type="hidden" name="insurance_crm_license_action" value="activate" />
                            <input type="submit" class="button button-primary" value="Lisansı Etkinleştir" />
                        <?php else: ?>
                            <input type="hidden" name="insurance_crm_license_action" value="deactivate" />
                            <input type="submit" class="button" value="Lisansı Devre Dışı Bırak" />
                            
                            <?php if ($this->license_type === 'monthly'): ?>
                                <a href="https://benzersizkod.com/yenile" target="_blank" class="button button-primary">
                                    Lisansı Yenile
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- Ayarlar Kartı -->
            <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
                <h2>Lisans API Ayarları</h2>
                
                <form method="post" action="">
                    <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                    <input type="hidden" name="insurance_crm_license_action" value="save_settings" />
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">API URL</th>
                            <td>
                                <input type="url" name="insurance_crm_license_api_url" class="regular-text" 
                                       value="<?php echo esc_attr($this->api_url); ?>" />
                                <p class="description">
                                    Lisans API sunucusunun URL'si. Varsayılan: https://benzersizkod.com/api
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Anahtarı</th>
                            <td>
                                <input type="text" name="insurance_crm_license_api_key" class="regular-text" 
                                       value="<?php echo esc_attr(get_option('insurance_crm_license_api_key', '')); ?>" />
                                <p class="description">
                                    API isteklerinin güvenliği için kullanılan anahtar.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Ayarları Kaydet" />
                    </p>
                </form>
            </div>
            
            <!-- Geliştirici Seçenekleri Kartı -->
            <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
                <h2>Geliştirici Seçenekleri</h2>
                
                <form method="post" action="">
                    <?php wp_nonce_field('insurance_crm_license', 'insurance_crm_license_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Hata Ayıklama Modu</th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><span>Hata Ayıklama Modu</span></legend>
                                    <label for="insurance_crm_license_debug_mode">
                                        <input type="checkbox" name="insurance_crm_license_debug_mode" id="insurance_crm_license_debug_mode" 
                                               <?php checked($this->debug_mode, true); ?> disabled />
                                        API isteklerini simüle et (gerçek API istekleri gönderme)
                                    </label>
                                </fieldset>
                                <p class="description">
                                    Bu seçenek, API sunucusu hazır olmadığında geliştirme yapmak için kullanılır.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Lisans Kontrolünü Atla</th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><span>Lisans Kontrolünü Atla</span></legend>
                                    <label for="insurance_crm_bypass_license">
                                        <input type="checkbox" name="insurance_crm_bypass_license" id="insurance_crm_bypass_license" 
                                               <?php checked($this->bypass_license, true); ?> />
                                        Lisans kontrollerini atla (geliştirme modu)
                                    </label>
                                </fieldset>
                                <p class="description">
                                    <strong>Dikkat:</strong> Bu seçenek yalnızca geliştirme aşamasında kullanılmalıdır. 
                                    Üretim ortamında bu seçeneği devre dışı bırakın.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="hidden" name="insurance_crm_license_action" value="toggle_bypass" />
                        <input type="submit" class="button" value="<?php echo $this->bypass_license ? 'Lisans Kontrolünü Etkinleştir' : 'Lisans Kontrolünü Devre Dışı Bırak'; ?>" />
                    </p>
                </form>
            </div>
            
            <!-- Lisans Bilgileri Kartı -->
            <?php if (!$this->bypass_license): ?>
            <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
                <h2>Lisans Bilgileri</h2>
                <p>Insurance CRM plugini için iki tür lisanslama modeli sunulmaktadır:</p>
                
                <div style="display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 300px; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                        <h3>Aylık Abonelik</h3>
                        <ul>
                            <li>Tüm özelliklere erişim</li>
                            <li>Aylık otomatik yenileme</li>
                            <li>Öncelikli teknik destek</li>
                            <li>Aylık güncellemeler</li>
                        </ul>
                        <p style="font-size: 24px; font-weight: bold; margin: 15px 0;">399₺<span style="font-size: 14px; color: #999; font-weight: normal;">/ay</span></p>
                        <a href="https://benzersizkod.com/satin-al/aylik" target="_blank" class="button button-primary">
                            Satın Al
                        </a>
                    </div>
                    
                    <div style="flex: 1; min-width: 300px; border: 1px solid #0073aa; padding: 15px; border-radius: 4px; box-shadow: 0 0 10px rgba(0,115,170,0.15); position: relative;">
                        <div style="position: absolute; top: -10px; right: 10px; background: #0073aa; color: white; padding: 2px 10px; border-radius: 3px; font-size: 12px;">En İyi Değer</div>
                        <h3>Ömür Boyu Lisans</h3>
                        <ul>
                            <li>Tüm özelliklere sınırsız erişim</li>
                            <li>Tek seferlik ödeme</li>
                            <li>Öncelikli teknik destek</li>
                            <li>1 yıl ücretsiz güncellemeler</li>
                            <li>Sınırsız alan adı değişimi</li>
                        </ul>
                        <p style="font-size: 24px; font-weight: bold; margin: 15px 0;">3,999₺<span style="font-size: 14px; color: #999; font-weight: normal;">/tek seferlik</span></p>
                        <a href="https://benzersizkod.com/satin-al/omur-boyu" target="_blank" class="button button-primary">
                            Satın Al
                        </a>
                    </div>
                </div>
                
                <p style="margin-top: 20px; font-size: 12px; color: #666;">
                    * Lisans bir domain için geçerlidir. Farklı domainlerde kullanım için her domain için ayrı lisans satın alınmalıdır.
                    <br>
                    * Aylık abonelikler iptal edilebilir, ancak ücret iadesi yapılmamaktadır.
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Admin bildirimleri göster
     */
    public function admin_notices() {
        // Bypass modu aktifse bildirimleri gösterme
        if ($this->bypass_license) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $screen = get_current_screen();
        if (isset($screen->id) && $screen->id === 'insurance-crm_page_insurance-crm-license') {
            return; // Lisans sayfasında bildirimi gösterme
        }
        
        // Lisans yoksa veya geçersizse bildirim göster
        if (empty($this->license_key) || $this->license_status !== 'active') {
            ?>
            <div class="notice license-warning-notice" style="padding: 20px; background-color: #f44336; color: white; margin: 10px 0; border-radius: 4px; border-left: 4px solid #9f0000; position: relative;">
                <h2 style="margin-top: 0; font-size: 24px; color: white; margin-bottom: 15px;">
                    <span class="dashicons dashicons-warning" style="font-size: 30px; height: 30px; width: 30px; margin-right: 10px;"></span>
                    LİSANS UYARISI
                </h2>
                <p style="font-size: 16px; line-height: 1.5; margin-bottom: 15px;">
                    <strong>Insurance CRM</strong> lisansı etkinleştirilmemiş veya süresi dolmuş. 
                    <br>Tüm özelliklere erişim sağlamak ve kullanıma devam etmek için lütfen lisansınızı yenileyin.
                </p>
                <div style="margin-top: 15px;">
                    <a href="<?php echo admin_url('admin.php?page=insurance-crm-license'); ?>" class="button button-primary button-hero" style="background: #ffffff; color: #f44336; border-color: #ffffff; font-weight: bold; text-shadow: none; box-shadow: none;">
                        HEMEN LİSANSI YENİLE
                    </a>
                </div>
            </div>
            
            <!-- Sayfanın üstüne çıkan popup uyarı -->
            <div id="license-popup-warning" style="position: fixed; top: 32px; left: 0; right: 0; background-color: #f44336; color: white; text-align: center; padding: 15px; z-index: 9999; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                <strong>DİKKAT!</strong> Insurance CRM lisansınız aktif değil! 
                <a href="<?php echo admin_url('admin.php?page=insurance-crm-license'); ?>" style="color: white; text-decoration: underline; font-weight: bold; margin-left: 10px;">
                    HEMEN SATIN ALIN
                </a>
                <button id="close-license-warning" style="background: none; border: none; color: white; cursor: pointer; float: right; font-size: 20px;">&times;</button>
            </div>
            
            <script>
                jQuery(document).ready(function($) {
                    // Popup kapatma butonu işlevi
                    $('#close-license-warning').click(function() {
                        $('#license-popup-warning').slideUp();
                        // 5 dakika sonra tekrar göster
                        setTimeout(function() {
                            $('#license-popup-warning').slideDown();
                        }, 5 * 60 * 1000);
                    });
                });
            </script>
            <?php
        }
        // Aylık lisans ve son 7 gün kaldıysa bildirim göster
        elseif ($this->license_type === 'monthly' && !empty($this->expiry_date)) {
            $days_left = ceil((strtotime($this->expiry_date) - time()) / 86400);
            
            if ($days_left <= 7) {
                $class = 'notice notice-warning';
                $message = sprintf(
                    'Insurance CRM lisansınız %d gün içinde sona erecek. Kesintisiz hizmet için lütfen <a href="%s">lisansınızı yenileyin</a>.',
                    $days_left,
                    admin_url('admin.php?page=insurance-crm-license')
                );
                
                printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
            }
        }
    }
    
    /**
     * Aktivasyon bildirimi gönder
     */
    private function send_activation_notification($is_valid = false) {
        // Geliştirici modunda bildirim gönderme
        if ($this->bypass_license || $this->debug_mode) {
            return;
        }
        
        // SMS API Entegrasyonu - NetGSM örneği 
        $to = '905XXXXXXXXX'; // Sizin numaranız
        $site_url = home_url();
        $message = "Insurance CRM Lisans: " . ($is_valid ? "Yeni geçerli aktivasyon " : "Lisanssız aktivasyon girişimi ") . 
                   "Tarih: " . date('Y-m-d H:i:s') . " | Site: {$site_url}";
        
        // SMS Gönderme - NetGSM API örneği
        $this->send_sms($to, $message);
        
        // E-posta bildirimi de gönder
        $this->send_email_notification($is_valid);
    }
    
    /**
     * Lisans geçersiz bildirimi gönder
     */
    private function send_invalid_license_notification() {
        // Geliştirici modunda bildirim gönderme
        if ($this->bypass_license || $this->debug_mode) {
            return;
        }
        
        $to = '905XXXXXXXXX'; // Sizin numaranız
        $site_url = home_url();
        $message = "Insurance CRM Lisans: Geçersiz lisans tespit edildi! Tarih: " . date('Y-m-d H:i:s') . " | Site: {$site_url}";
        
        // SMS Gönderme
        $this->send_sms($to, $message);
        
        // E-posta bildirimi
        $this->send_email_notification(false, 'invalid');
    }
    
    /**
     * Lisans süresi bitimi bildirimi gönder
     */
    private function send_expiry_notification() {
        // Geliştirici modunda bildirim gönderme
        if ($this->bypass_license || $this->debug_mode) {
            return;
        }
        
        $to = '905XXXXXXXXX'; // Sizin numaranız
        $site_url = home_url();
        $message = "Insurance CRM Lisans: Lisans süresi doldu! Tarih: " . date('Y-m-d H:i:s') . " | Site: {$site_url}";
        
        // SMS Gönderme
        $this->send_sms($to, $message);
        
        // E-posta bildirimi
        $this->send_email_notification(false, 'expired');
    }
    
    /**
     * SMS gönderme
     */
    private function send_sms($to, $message) {
        // NetGSM API örneği
        $username = 'NETGSM_USERNAME';
        $password = 'NETGSM_PASSWORD';
        $header = 'HEADER_NAME';
        
        $url = "https://api.netgsm.com.tr/sms/send/get";
        
        $params = array(
            'usercode' => $username,
            'password' => $password,
            'gsmno' => $to,
            'message' => $message,
            'msgheader' => $header
        );
        
        // cURL isteği
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        // Yanıtı loglayalım
        error_log('SMS API Response: ' . $response);
        
        return $response;
    }
    
    /**
     * E-posta bildirimi gönder
     */
    private function send_email_notification($is_valid = false, $status = '') {
        $admin_email = get_option('admin_email');
        $to = 'your-monitoring-email@example.com';
        $subject = "Insurance CRM Lisans Bildirimi - " . home_url();
        
        $site_url = home_url();
        $domain = $this->domain;
        $license_key = $this->license_key;
        
        if ($status === 'invalid') {
            $license_status = "Geçersiz";
        } elseif ($status === 'expired') {
            $license_status = "Süresi Dolmuş";
        } else {
            $license_status = $is_valid ? "Geçerli" : "Geçersiz";
        }
        
        $message = "Insurance CRM Lisans Bildirimi\n\n";
        $message .= "Site: {$site_url}\n";
        $message .= "Domain: {$domain}\n";
        $message .= "Lisans Anahtarı: {$license_key}\n";
        $message .= "Lisans Durumu: {$license_status}\n";
        $message .= "Bildirim Tarihi: " . date('Y-m-d H:i:s') . "\n\n";
        $message .= "Bu mesaj otomatik olarak gönderilmiştir.";
        
        $headers = array(
            'From: Insurance CRM <noreply@' . $domain . '>',
            'Reply-To: ' . $admin_email
        );
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Lisans durumunu kontrol et
     */
    public function is_license_valid() {
        // Bypass modu aktifse her zaman geçerli kabul et
        if ($this->bypass_license) {
            return true;
        }
        
        return $this->license_status === 'active';
    }
    
    /**
     * Plugin devre dışı bırakıldığında temizlik
     */
    public static function deactivation_cleanup() {
        // Cron işini temizle
        wp_clear_scheduled_hook('insurance_crm_license_check');
    }
}