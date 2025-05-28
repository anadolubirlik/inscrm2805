<?php
/**
 * Insurance CRM License API Client
 *
 * @package Insurance_CRM
 */

class Insurance_CRM_License_API {
    private $api_url;
    private $api_key;
    private $product_id;
    
    public function __construct($api_url = null) {
        // API URL'sini ve anahtarını ayarlardan al veya varsayılanları kullan
        $this->api_url = $api_url ?: get_option('insurance_crm_license_api_url', 'https://benzersizkod.com/api');
        $this->api_key = get_option('insurance_crm_license_api_key', 'your_secret_api_key');
        $this->product_id = 'insurance-crm';
    }
    
    /**
     * API'ye lisans etkinleştirme isteği gönder
     */
    public function activate_license($params) {
        $instance = $this->get_instance_id();
        
        $api_params = array_merge([
            'product_id' => $this->product_id,
            'instance' => $instance
        ], $params);
        
        return $this->make_request('activate', $api_params);
    }
    
    /**
     * API'ye lisans devre dışı bırakma isteği gönder
     */
    public function deactivate_license($params) {
        $instance = $this->get_instance_id();
        
        $api_params = array_merge([
            'product_id' => $this->product_id,
            'instance' => $instance
        ], $params);
        
        return $this->make_request('deactivate', $api_params);
    }
    
    /**
     * API'ye lisans doğrulama isteği gönder
     */
    public function verify_license($params) {
        $instance = $this->get_instance_id();
        
        $api_params = array_merge([
            'product_id' => $this->product_id,
            'instance' => $instance
        ], $params);
        
        return $this->make_request('verify', $api_params);
    }
    
    /**
     * API'ye bir istek gönderir
     */
    private function make_request($endpoint, $params) {
        $url = trailingslashit($this->api_url) . $endpoint;
        
        $response = wp_remote_post($url, [
            'timeout' => 15,
            'body' => $params,
            'headers' => [
                'X-Api-Key' => $this->api_key
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('License API Error: ' . $response->get_error_message());
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            error_log('License API Error: Response code ' . $response_code);
            return [
                'success' => false,
                'message' => 'API yanıt kodu: ' . $response_code
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['success'])) {
            error_log('License API Error: Invalid response format');
            return [
                'success' => false,
                'message' => 'Geçersiz API yanıtı'
            ];
        }
        
        return $data;
    }
    
    /**
     * Site için benzersiz bir instance ID oluştur
     */
    private function get_instance_id() {
        $instance = get_option('insurance_crm_instance_id');
        
        if (!$instance) {
            $instance = md5(site_url() . time());
            update_option('insurance_crm_instance_id', $instance);
        }
        
        return $instance;
    }
    
    /**
     * Simülasyon modu için test yanıtları oluştur (Geliştirme sırasında kullanılır)
     */
    public function simulate_response($endpoint, $success = true) {
        if ($endpoint === 'activate') {
            return [
                'success' => $success,
                'message' => $success ? 'Lisans başarıyla etkinleştirildi (simülasyon).' : 'Etkinleştirme hatası (simülasyon).',
                'license_type' => 'monthly',
                'expiry_date' => date('Y-m-d', strtotime('+30 days')),
                'customer_name' => 'Test Customer'
            ];
        } elseif ($endpoint === 'verify') {
            return [
                'success' => $success,
                'message' => $success ? 'Lisans geçerli (simülasyon).' : 'Lisans geçersiz (simülasyon).',
                'license_type' => 'monthly',
                'expiry_date' => date('Y-m-d', strtotime('+30 days'))
            ];
        } elseif ($endpoint === 'deactivate') {
            return [
                'success' => $success,
                'message' => $success ? 'Lisans devre dışı bırakıldı (simülasyon).' : 'Devre dışı bırakma hatası (simülasyon).'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Geçersiz endpoint (simülasyon).'
        ];
    }
}