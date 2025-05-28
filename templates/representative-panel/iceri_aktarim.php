<?php
/**
 * CSV ve XML İçeri Aktarım Sayfası
 * @version 1.2.1
 * @date 2025-05-27 10:15:36
 * @user anadolubirlik
 */

// Kullanıcı oturum kontrolü
if (!is_user_logged_in()) {
    echo '<div class="ab-notice ab-error">Bu sayfayı görüntülemek için giriş yapmalısınız.</div>';
    return;
}

// ÖNEMLİ: Önce Facebook ADS fonksiyonlarını dahil ediyoruz
require_once(dirname(__FILE__) . '/import/csv-importer-facebook-update.php');

// CSV-importer içinde zaten tanımlı olan fonksiyonları çağırmak için wrapper
if (!function_exists('call_read_csv_headers')) {
    function call_read_csv_headers($file_path, $is_facebook = false) {
        // Facebook için özel işleme
        if ($is_facebook && function_exists('read_csv_headers_facebook')) {
            return read_csv_headers_facebook($file_path, $is_facebook);
        } 
        
        // Normal CSV işleme için mevcut fonksiyonu çağır
        if (function_exists('read_csv_headers')) {
            return read_csv_headers($file_path);
        }
        
        // Fallback - fonksiyon bulunamazsa
        throw new Exception('read_csv_headers fonksiyonu yüklenemedi.');
    }
}

// Sonra orijinal CSV importer'ı yükle
require_once(dirname(__FILE__) . '/import/csv-importer.php');

// Veritabanı tablolarını tanımlama
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
$users_table = $wpdb->users;

// Mevcut kullanıcı temsilcisi ID'sini alma
function get_current_user_rep_id() {
    global $wpdb;
    $current_user_id = get_current_user_id();
    return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'", $current_user_id));
}

$current_user_rep_id = get_current_user_rep_id();

// İşlem türünü belirleme (XML, CSV veya Facebook)
$import_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'csv';
$is_xml = ($import_type === 'xml');
$is_facebook = ($import_type === 'facebook');

// Bildiriler için değişken
$notice = '';

/**
 * Adresten kredi kartı ve diğer finansal bilgileri temizleyen fonksiyon
 * 
 * @param string $address Temizlenecek adres
 * @return string Temizlenmiş adres
 */
function clean_address($address) {
    if (empty($address)) {
        return '';
    }
    
    $patterns = [
        '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
        '/\b\d{4}[\s\-]?\d{4}[\s\-]?[\*]{4}[\s\-]?\d{4}\b/i',
        '/\b\d{6}[\*]{6}\d{4}\b/i',
        '/\b5\d{3}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
        '/\b4\d{3}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/i',
        '/Kart[ _]?No[:\s]*[\d\*\s\-]+/i',
        '/Kart[ _]?Sahibi[:\s]*[^<>\/\n\r]+/i',
        '/Kart[_\s]Tahsil[_\s]Tarihi[:\s]*[^<>\/\n\r]+/i',
        '/BrutTutar[:\s]*[\-\d\.,]+/i',
        '/Tahsilat[_\s]Doviz[:\s]*[A-Z]+/i',
        '/Police[_\s]Doviz[:\s]*[A-Z]+/i',
        '/Police[_\s]Tutar[:\s]*[\-\d\.,]+/i',
        '/Tahsil[_\s]Tarihi[:\s]*[\d\.\-\/]+/i',
        '/TahsilatTutari[:\s]*[\d\.\,]+/i',
        '/\d{5}[\s\-]?\d{5}[\s\-]?\d{5}[\s\-]?\d{5}/i',
        '/\b\d{5}\s+\d{5}\s+\d{5}\b/i',
        '/[\d\s]{15,30}/',
        '/[\d]{5,6}\s[\d]{5,6}\s[\d]{5,6}/',
    ];
    
    foreach ($patterns as $pattern) {
        $address = preg_replace($pattern, '[KART BİLGİSİ ÇIKARILDI]', $address);
    }
    
    $address = preg_replace('/\s{3,}/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

/**
 * Tüm müşteri temsilcilerini getiren fonksiyon
 * 
 * @return array Temsilcilerin ID ve isimleriyle birlikte bir dizi
 */
function get_all_representatives() {
    global $wpdb;
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    
    $representatives = $wpdb->get_results("
        SELECT r.id, r.user_id, u.display_name 
        FROM {$representatives_table} r
        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
        WHERE r.status = 'active'
        ORDER BY u.display_name
    ");
    
    return $representatives;
}

// XML veri işleme debug bilgilerini tutan array
$debug_info = array(
    'total_policies' => 0,
    'processed_policies' => 0,
    'matched_customers' => 0,
    'failed_matches' => 0,
    'last_error' => ''
);

// Müşteri temsilcileri listesini al
$all_representatives = get_all_representatives();

// XML Yükleme ve Ön İzleme
$preview_data = null;
if ($is_xml && isset($_POST['preview_xml']) && isset($_POST['xml_import_nonce']) && wp_verify_nonce($_POST['xml_import_nonce'], 'xml_import_action')) {
    if (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['xml_file']['tmp_name'];
        $file_type = mime_content_type($file_tmp);

        // Sadece XML dosyalarına izin ver
        if ($file_type !== 'text/xml' && $file_type !== 'application/xml') {
            $notice = '<div class="ab-notice ab-error">Lütfen geçerli bir XML dosyası yükleyin.</div>';
        } else {
            $xml_content = file_get_contents($file_tmp);
            // BOM karakterini temizleme (UTF-8 BOM sorunlarını önler)
            $xml_content = preg_replace('/^\xEF\xBB\xBF/', '', $xml_content);
            
            // Hata işleme modunu etkinleştir
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml_content);
            $libxml_errors = libxml_get_errors();
            libxml_clear_errors();

            if ($xml === false || !empty($libxml_errors)) {
                $error_msg = "XML dosyası ayrıştırılamadı. ";
                if (!empty($libxml_errors)) {
                    $error_msg .= "Hata: " . $libxml_errors[0]->message;
                }
                $notice = '<div class="ab-notice ab-error">' . $error_msg . '</div>';
            } else {
                $preview_data = array(
                    'policies' => array(),
                    'customers' => array(),
                );

                $processed_policies = 0;
                
                // Debug işlem başlangıç saati
                $debug_info['process_start'] = date('Y-m-d H:i:s'); 
                $debug_info['xml_structure'] = '';
                
                // XML yapısını belirle
                if (isset($xml->ACENTEDATATRANSFERI) && isset($xml->ACENTEDATATRANSFERI->POLICE)) {
                    // ACENTEDATATRANSFERI altındaki POLICE elementleri
                    $debug_info['xml_structure'] = 'ACENTEDATATRANSFERI->POLICE';
                    $policies = $xml->ACENTEDATATRANSFERI->POLICE;
                    
                    // SimpleXML'in foreach'inde sorunu önlemek için önce dizi olarak element sayısını belirle
                    $policy_count = $policies->count();
                    $debug_info['total_policies'] = $policy_count;
                    
                    // Her bir poliçeyi tek tek işle
                    for ($i = 0; $i < $policy_count; $i++) {
                        $policy_xml = $policies[$i];
                        process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                        $processed_policies++;
                    }
                    
                } elseif (isset($xml->POLICE)) {
                    // Doğrudan POLICE elementleri
                    $debug_info['xml_structure'] = 'direct POLICE';
                    $policies = $xml->POLICE;
                    
                    // SimpleXML'in foreach'inde sorunu önlemek için önce dizi olarak element sayısını belirle
                    $policy_count = $policies->count();
                    $debug_info['total_policies'] = $policy_count;
                    
                    // Her bir poliçeyi tek tek işle
                    for ($i = 0; $i < $policy_count; $i++) {
                        $policy_xml = $policies[$i];
                        process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                        $processed_policies++;
                    }
                    
                } else {
                    // Hiçbir bilinen yapı bulamazsak, farklı bir XML ağacı olabilir, tüm yapıyı tara
                    $debug_info['xml_structure'] = 'unknown structure, scanning';
                    $found_policies = false;
                    
                    // Bilinen tüm yapıları dene
                    foreach ($xml->children() as $tag_name => $node) {
                        if (strtoupper($tag_name) == 'POLICE' || $tag_name == 'POLICE') {
                            // İlk seviyede POLICE elementi
                            $debug_info['xml_structure'] = 'first level as ' . $tag_name;
                            $policies = $xml->{$tag_name};
                            $policy_count = $policies->count();
                            $debug_info['total_policies'] = $policy_count;
                            
                            for ($i = 0; $i < $policy_count; $i++) {
                                $policy_xml = $policies[$i];
                                process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                                $processed_policies++;
                            }
                            
                            $found_policies = true;
                            break;
                        }
                        
                        // İkinci seviyede POLICE elementi
                        foreach ($node->children() as $child_name => $child) {
                            if (strtoupper($child_name) == 'POLICE' || $child_name == 'POLICE') {
                                $debug_info['xml_structure'] = $tag_name . '->' . $child_name;
                                $policies = $xml->{$tag_name}->{$child_name};
                                $policy_count = $policies->count();
                                $debug_info['total_policies'] = $policy_count;
                                
                                for ($i = 0; $i < $policy_count; $i++) {
                                    $policy_xml = $policies[$i];
                                    process_policy_xml($policy_xml, $preview_data, $current_user_rep_id, $wpdb, $customers_table, $debug_info);
                                    $processed_policies++;
                                }
                                
                                $found_policies = true;
                                break 2; // İç ve dış döngüden çık
                            }
                        }
                    }
                    
                    if (!$found_policies) {
                        $notice = '<div class="ab-notice ab-error">XML dosyası beklenen formatta değil. Poliçe bilgileri bulunamadı.</div>';
                        $preview_data = null;
                    }
                }
                
                // Hiç poliçe işlenmemişse hata ver
                if ($processed_policies === 0 && $preview_data !== null) {
                    $notice = '<div class="ab-notice ab-error">XML dosyası okundu, ancak hiçbir poliçe bilgisi bulunamadı. Sebep: ' . $debug_info['last_error'] . '</div>';
                    $preview_data = null;
                } else {
                    // İşlem başarılı - Debug bilgisini ekle
                    $debug_info['processed_policies'] = $processed_policies;
                    $preview_data['debug'] = $debug_info;
                }
            }
        }
    } else {
        $notice = '<div class="ab-notice ab-error">Lütfen bir XML dosyası seçin.</div>';
    }
}

// CSV Yükleme ve Eşleştirme Adımı
if ((!$is_xml && !$is_facebook && isset($_POST['preview_csv']) && isset($_POST['csv_import_nonce']) && wp_verify_nonce($_POST['csv_import_nonce'], 'csv_import_action')) ||
    ($is_facebook && isset($_POST['preview_csv']) && isset($_POST['facebook_import_nonce']) && wp_verify_nonce($_POST['facebook_import_nonce'], 'facebook_import_action'))) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_type = mime_content_type($file_tmp);

        // CSV dosyası kontrolü
        $allowed_types = array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel');
        if (!in_array($file_type, $allowed_types) && !in_array(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION), array('csv'))) {
            $notice = '<div class="ab-notice ab-error">Lütfen geçerli bir CSV dosyası yükleyin. (Yüklenen dosya tipi: ' . $file_type . ')</div>';
        } else {
            try {
                // CSV dosyasını oku ve sütunları çıkar
                $csv_data = call_read_csv_headers($file_tmp, $is_facebook);
                
                // Facebook CSV formatını kontrol et
                $suggested_mapping = [];
                if ($is_facebook) {
                    $is_facebook_format = is_facebook_ads_csv_format($csv_data['headers']);
                    if ($is_facebook_format) {
                        // Facebook ADS CSV başlıklarını temizle
                        $csv_data['headers'] = clean_facebook_ads_headers($csv_data['headers']);
                        
                        // Otomatik eşleştirme önerilerini oluştur
                        $suggested_mapping = suggest_facebook_ads_mapping($csv_data['headers']);
                    } else {
                        $notice = '<div class="ab-notice ab-warning">Bu dosya Facebook ADS CSV formatına benzemiyor. Standart CSV eşleştirmeye devam ediliyor.</div>';
                    }
                }
                
                // Yüklenen dosyayı geçici dizine kopyala
                $upload_dir = wp_upload_dir();
                $temp_dir = $upload_dir['basedir'] . '/temp_csv';
                if (!file_exists($temp_dir)) {
                    mkdir($temp_dir, 0755, true);
                }
                
                // Güvenli bir dosya adı oluştur
                $temp_file = $temp_dir . '/' . uniqid('csv_') . '.csv';
                move_uploaded_file($file_tmp, $temp_file);
                
                // Eşleştirme adımını göster
                $matching_step = true;
                
            } catch (Exception $e) {
                $notice = '<div class="ab-notice ab-error">CSV dosyası işlenirken hata oluştu: ' . $e->getMessage() . '</div>';
                $preview_data = null;
            }
        }
    } else {
        $notice = '<div class="ab-notice ab-error">Lütfen bir CSV dosyası seçin. Hata kodu: ' . $_FILES['csv_file']['error'] . '</div>';
    }
}

// Eşleştirme yapılıp önizleme görüntüleme adımı
if ((!$is_xml && !$is_facebook && isset($_POST['match_csv_columns']) && isset($_POST['csv_match_nonce']) && wp_verify_nonce($_POST['csv_match_nonce'], 'csv_match_action')) ||
    ($is_facebook && isset($_POST['match_csv_columns']) && isset($_POST['facebook_match_nonce']) && wp_verify_nonce($_POST['facebook_match_nonce'], 'facebook_match_action'))) {
    try {
        // Geçici dosyayı al
        $temp_file = sanitize_text_field($_POST['temp_csv_file']);
        
        if (!file_exists($temp_file)) {
            throw new Exception('Geçici CSV dosyası bulunamadı.');
        }
        
        // Kullanıcının seçtiği eşleştirmeleri al
        $column_mapping = array();
        $db_columns = array(
            'police_no', 'ad', 'soyad', 'tc_kimlik', 'telefon', 'adres', 'dogum_tarih',
            'police_turu', 'sigorta_sirketi', 'baslangic_tarih', 'bitis_tarih', 
            'prim_tutari', 'sigorta_ettiren', 'network', 'status',
            // YENİ: Poliçe kategorisi, Network, Durum notu, Ödeme bilgisi ve Temsilci ID alanları
            'policy_category', 'status_note', 'payment_info', 'representative_id'
        );
        
        // Facebook ADS için ek alanlar
        if ($is_facebook) {
            $db_columns[] = 'email';
            $db_columns[] = 'campaign_name';
            $db_columns[] = 'created_time';
            $db_columns[] = 'yorum';
        }
        
        foreach ($db_columns as $column) {
            if (isset($_POST['mapping_' . $column]) && $_POST['mapping_' . $column] !== '') {
                $column_mapping[$column] = (int) $_POST['mapping_' . $column];
            }
        }
        
        // Ayırıcı bilgisini ekle
        $column_mapping['delimiter'] = sanitize_text_field($_POST['csv_delimiter']);
        
        // Müşteri temsilcisi atamalarını al (Facebook için)
        $customer_representatives = isset($_POST['customer_representative']) ? array_map('intval', $_POST['customer_representative']) : array();
        
        // Eşleştirme bilgileriyle CSV dosyasını işle
        if ($is_facebook) {
            // Facebook ADS formatı için özel işleme
            $preview_data = process_facebook_ads_csv($temp_file, $column_mapping, $current_user_rep_id, $wpdb, $customer_representatives);
        } else {
            // Normal CSV işleme
            $preview_data = process_csv_file_with_mapping($temp_file, $column_mapping, $current_user_rep_id, $wpdb);
        }
        
    } catch (Exception $e) {
        $notice = '<div class="ab-notice ab-error">Eşleştirme işlemi sırasında hata oluştu: ' . $e->getMessage() . '</div>';
        $preview_data = null;
    }
}

// XML'den poliçe işleme fonksiyonu
function process_policy_xml($policy_xml, &$preview_data, $current_user_rep_id, $wpdb, $customers_table, &$debug_info) {
    // Poliçe türü belirleme
    $policy_type_raw = (string)$policy_xml->Urun_Adi;
    $policy_type_map = array(
        'ZORUNLU MALİ SORUMLULUK' => 'Trafik',
        'TICARI GENİŞLETİLMİŞ KASKO' => 'Kasko',
    );
    $policy_type = isset($policy_type_map[$policy_type_raw]) ? $policy_type_map[$policy_type_raw] : 'Diğer';

    // Müşteri bilgilerini al - Sigortali_AdiSoyadi veya Musteri_Adi kullan
    $customer_name = (string)$policy_xml->Musteri_Adi;
    if (empty($customer_name)) {
        $customer_name = (string)$policy_xml->Sigortali_AdiSoyadi;
    }

    // Kullanılabilir müşteri adı yoksa, bu poliçeyi atlayalım
    if (empty($customer_name)) {
        $debug_info['failed_matches']++;
        $debug_info['last_error'] = 'Müşteri adı bulunamadı';
        return;
    }

    $customer_name = trim($customer_name);
    $customer_parts = preg_split('/\s+/', $customer_name, 2);
    $first_name = !empty($customer_parts[0]) ? $customer_parts[0] : 'Bilinmeyen';
    $last_name = !empty($customer_parts[1]) ? $customer_parts[1] : '';
    
    // Telefon numarası
    $phone = (string)$policy_xml->Sigortali_MobilePhone;
    if (empty($phone)) {
        $phone = (string)$policy_xml->Telefon;
    }
    
    // Adres bilgisini temizle (kredi kartı ve diğer finansal bilgileri kaldır)
    $address_raw = (string)$policy_xml->Musteri_Adresi;
    $address = clean_address($address_raw);
    
    // TC Kimlik numarasını al
    $tc_kimlik = (string)$policy_xml->TCKimlikNo;
    if (empty($tc_kimlik)) {
        $tc_kimlik = (string)$policy_xml->Musteri_TCKimlikNo;
    }
    if (empty($tc_kimlik)) {
        $tc_kimlik = (string)$policy_xml->Sigortali_TCKimlikNo;
    }
    
    // Doğum tarihi bilgisini al ve formatla (MySQL date format: YYYY-MM-DD)
    $birth_date = null;
    if (!empty($policy_xml->Musteri_Dogum_Tarihi)) {
        $birth_date_raw = (string)$policy_xml->Musteri_Dogum_Tarihi;
        $birth_date = date('Y-m-d', strtotime(str_replace('.', '-', $birth_date_raw)));
    }

    // Müşteriyi TC kimlik numarasına göre kontrol et
    $customer_id = null;
    if (!empty($tc_kimlik)) {
        $customer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $customers_table WHERE tc_identity = %s",
            $tc_kimlik
        ));
    }

    if (!$customer_id) {
        // TC kimliğe göre bulunamadıysa, isim ve telefona göre kontrol et
        $customer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $customers_table WHERE first_name = %s AND last_name = %s AND phone = %s",
            $first_name,
            $last_name,
            $phone
        ));
    }

    $customer_status = $customer_id ? 'Mevcut' : 'Yeni';
    if ($customer_id) {
        $debug_info['matched_customers']++;
    }

    // Müşteri verisini ön izlemeye ekle
    $customer_key = md5($tc_kimlik . $first_name . $last_name . $phone);
    if (!isset($preview_data['customers'][$customer_key])) {
        $preview_data['customers'][$customer_key] = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'address' => $address, 
            'address_raw' => $address_raw,  // Debug için ham adresi de kaydediyoruz
            'tc_kimlik' => $tc_kimlik,
            'birth_date' => $birth_date,
            'status' => $customer_status,
            'customer_id' => $customer_id,
            'representative_id' => $current_user_rep_id
        );
    }

    // Poliçe verilerini hazırla
    $policy_number = (string)$policy_xml->Police_NO;
    $zeyl_no = (string)$policy_xml->Zeyl_NO;
    if (!empty($zeyl_no) && $zeyl_no != '0') {
        $policy_number .= '-' . $zeyl_no;
    }

    // Tarih formatlarını kontrol et ve düzelt
    $start_date_raw = (string)$policy_xml->PoliceBaslangicTarihi;
    $end_date_raw = (string)$policy_xml->PoliceBitisTarihi;
    
    // Tarih formatlarını düzenli hale getir (gün-ay-yıl formatından yıl-ay-gün'e)
    $start_date = date('Y-m-d', strtotime(str_replace('.', '-', $start_date_raw)));
    $end_date = date('Y-m-d', strtotime(str_replace('.', '-', $end_date_raw)));
    
    // Tüm poliçeleri aktif olarak işaretle
    $status = 'aktif';

    // Brut primi hesapla
    $premium_amount = 0;
    if (isset($policy_xml->BrutPrim)) {
        $premium_amount = floatval((string)$policy_xml->BrutPrim);
    }

    // Poliçe verisini ön izlemeye ekle
    $preview_data['policies'][] = array(
        'policy_number' => $policy_number,
        'customer_key' => $customer_key,
        'policy_type' => $policy_type,
        'policy_category' => 'Yeni İş', // Varsayılan olarak Yeni İş
        'insurance_company' => 'Sompo',
        'start_date' => $start_date,
        'end_date' => $end_date,
        'premium_amount' => $premium_amount,
        'insured_party' => '', // Sigorta ettiren boş bırakıldı
        'status' => $status,
        'network' => '',
        'status_note' => '',
        'payment_info' => '',
        'representative_id' => $current_user_rep_id,
        'xml_fields' => array(
            'Urun_Adi' => (string)$policy_xml->Urun_Adi,
            'Police_NO' => (string)$policy_xml->Police_NO,
            'Zeyl_NO' => (string)$policy_xml->Zeyl_NO
        )
    );
}

// XML/CSV Onaylama ve Aktarma
if (isset($_POST['confirm_xml']) || isset($_POST['confirm_csv']) || isset($_POST['confirm_facebook'])) {
    $nonce_action = $is_xml ? 'xml_confirm_action' : ($is_facebook ? 'facebook_confirm_action' : 'csv_confirm_action');
    $nonce_field = $is_xml ? 'xml_confirm_nonce' : ($is_facebook ? 'facebook_confirm_nonce' : 'csv_confirm_nonce');
    
    if (isset($_POST[$nonce_field]) && wp_verify_nonce($_POST[$nonce_field], $nonce_action)) {
        $selected_policies = isset($_POST['selected_policies']) ? array_map('sanitize_text_field', $_POST['selected_policies']) : array();
        
        // Base64 kodlanmış serileştirilmiş veriyi çöz
        $preview_data = isset($_POST['preview_data']) ? @unserialize(base64_decode($_POST['preview_data'])) : null;
        
        // HATA AYIKLAMA KODU
        if ($is_facebook) {
            error_log('Facebook ADS İçe Aktarma: İşlem başladı');
            error_log('Seçilen Müşteriler: ' . print_r($selected_policies, true));
            error_log('Preview data boş mu?: ' . (empty($preview_data) ? 'Evet' : 'Hayır'));
        }

        if (!$preview_data || empty($selected_policies)) {
            $notice = '<div class="ab-notice ab-error">Aktarılacak veri bulunamadı veya hiçbir poliçe seçilmedi.</div>';
            // HATA AYIKLAMA KODU
            if ($is_facebook) {
                error_log('Hata: Aktarılacak veri bulunamadı veya hiçbir müşteri seçilmedi.');
            }
        } else {
            $success_count = 0;
            $error_count = 0;
            $customer_success = 0;
            $task_success = 0;

            // Müşteri temsilcisi atamalarını al (Facebook ADS için)
            $customer_representatives = [];
            if ($is_facebook && isset($_POST['customer_representative'])) {
                $customer_representatives = array_map('intval', $_POST['customer_representative']);
                // HATA AYIKLAMA KODU
                error_log('Müşteri Temsilcileri: ' . print_r($customer_representatives, true));
            }

            // İçe aktarım zamanı
            $import_time = current_time('mysql');

            // Önce tüm müşterileri oluştur veya güncelle
            $customer_ids = array();
            foreach ($preview_data['customers'] as $customer_key => $customer) {
                // HATA AYIKLAMA KODU
                if ($is_facebook) {
                    error_log('İşlenen Müşteri: ' . $customer['first_name'] . ' ' . $customer['last_name']);
                }
                
                // Müşteriyi kontrol et veya oluştur
                $existing_customer_id = $customer['customer_id'];
                if (!$existing_customer_id) {
                    $customer_insert_data = array(
                        'first_name' => $customer['first_name'],
                        'last_name' => $customer['last_name'],
                        'phone' => $customer['phone'],
                        'address' => $customer['address'],
                        'representative_id' => $customer['representative_id'] ?? $current_user_rep_id,
                        'created_at' => $customer['created_at'] ?? $import_time, // İçe aktarım zamanını kullan veya CSV'den gelen tarihi
                        'updated_at' => current_time('mysql'),
                    );
                    
                    // TC kimlik numarasını tc_identity sütununa ekle
                    if (!empty($customer['tc_kimlik'])) {
                        $customer_insert_data['tc_identity'] = $customer['tc_kimlik'];
                    }
                    
                    // Doğum tarihini ekle
                    if (!empty($customer['birth_date'])) {
                        $customer_insert_data['birth_date'] = $customer['birth_date'];
                    }
                    
                    // Facebook ADS için e-posta ve not alanlarını ekle
                    if ($is_facebook) {
                        if (!empty($customer['email'])) {
                            $customer_insert_data['email'] = $customer['email'];
                        }
                        if (!empty($customer['note'])) {
                            $customer_insert_data['notes'] = $customer['note'];
                        }
                        $customer_insert_data['source'] = 'facebook_ads';
                        
                        // HATA AYIKLAMA KODU
                        error_log('Yeni Müşteri Verisi: ' . print_r($customer_insert_data, true));
                    }
                    
                    $result = $wpdb->insert($customers_table, $customer_insert_data);
                    
                    // HATA AYIKLAMA KODU
                    if ($is_facebook) {
                        error_log('Müşteri ekleme sonucu: ' . ($result !== false ? 'Başarılı' : 'Başarısız'));
                        if ($result === false) {
                            error_log('SQL Hatası: ' . $wpdb->last_error);
                        }
                    }
                    
                    if ($result !== false) {
                        $customer_ids[$customer_key] = $wpdb->insert_id;
                        $customer_success++;
                        
                        // Facebook ADS müşterisi için görev oluştur
                        if ($is_facebook) {
                            $new_customer_id = $wpdb->insert_id;
                            $assigned_rep_id = $customer['representative_id'] ?? $current_user_rep_id;
                            $task_id = create_facebook_lead_task($new_customer_id, $assigned_rep_id, $import_time);
                            
                            // HATA AYIKLAMA KODU
                            error_log('Görev oluşturma sonucu: ' . ($task_id ? "ID: $task_id" : 'Başarısız'));
                            
                            if ($task_id) {
                                $task_success++;
                            }
                        }
                    } else {
                        $customer_ids[$customer_key] = null;
                        $error_count++;
                    }
                } else {
                    // Mevcut müşteriyi güncelle
                    $customer_update_data = array(
                        'updated_at' => current_time('mysql'),
                    );
                    
                    // Boş olmayan alanları güncelle
                    if (!empty($customer['phone'])) {
                        $customer_update_data['phone'] = $customer['phone'];
                    }
                    if (!empty($customer['address'])) {
                        $customer_update_data['address'] = $customer['address'];
                    }
                    if (!empty($customer['tc_kimlik'])) {
                        $customer_update_data['tc_identity'] = $customer['tc_kimlik'];
                    }
                    if (!empty($customer['birth_date'])) {
                        $customer_update_data['birth_date'] = $customer['birth_date'];
                    }
                    
                    // Facebook ADS için ek alanlar
                    if ($is_facebook) {
                        if (!empty($customer['email'])) {
                            $customer_update_data['email'] = $customer['email'];
                        }
                        if (!empty($customer['note'])) {
                            $customer_update_data['notes'] = !empty($customer['existing_notes']) ? 
                                $customer['existing_notes'] . "\n\n" . $customer['note'] : $customer['note'];
                        }
                        
                        // Temsilci atama
                        if (!empty($customer['representative_id'])) {
                            $customer_update_data['representative_id'] = $customer['representative_id'];
                        }
                        
                        // Facebook müşterisi olduğunu belirt
                        $customer_update_data['source'] = 'facebook_ads';
                        
                        // Facebook ADS güncellemesi için görev oluştur (mevcut müşteriler için de)
                        $assigned_rep_id = $customer['representative_id'] ?? $current_user_rep_id;
                        $task_id = create_facebook_lead_task($existing_customer_id, $assigned_rep_id, $import_time);
                        
                        if ($task_id) {
                            $task_success++;
                        }
                    }
                    
                    // Müşteri temsilcisi atanmamışsa ata
                    $has_representative = $wpdb->get_var($wpdb->prepare(
                        "SELECT representative_id FROM $customers_table WHERE id = %d",
                        $existing_customer_id
                    ));
                    
                    if (empty($has_representative)) {
                        $customer_update_data['representative_id'] = $customer['representative_id'] ?? $current_user_rep_id;
                    }
                    
                    if (!empty($customer_update_data)) {
                        $wpdb->update(
                            $customers_table,
                            $customer_update_data,
                            array('id' => $existing_customer_id)
                        );
                    }
                    
                    $customer_ids[$customer_key] = $existing_customer_id;
                }
            }

            // Şimdi poliçeleri oluştur
            foreach ($preview_data['policies'] as $index => $policy_data) {
                // String karşılaştırması yerine integer karşılaştırması
                $index_int = intval($index);
                if (!in_array((string)$index, $selected_policies) && !in_array($index_int, $selected_policies)) {
                    continue; // Seçilmemiş poliçeleri atla
                }

                // Müşteri ID'sini al
                $customer_key = $policy_data['customer_key'];
                $customer_id = isset($customer_ids[$customer_key]) ? $customer_ids[$customer_key] : null;
                
                if (!$customer_id) {
                    $error_count++;
                    continue; // Müşteri oluşturulamadıysa poliçeyi atla
                }

                // Poliçeyi kontrol et (aynı poliçe numarası varsa güncelle)
                $existing_policy = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM $policies_table WHERE policy_number = %s",
                    $policy_data['policy_number']
                ));

                // Poliçe verilerini hazırla
                $policy_insert_data = array(
                    'policy_number' => $policy_data['policy_number'],
                    'customer_id' => $customer_id,
                    'representative_id' => $policy_data['representative_id'] ?? $current_user_rep_id, // CSV'den gelen temsilci ID'si
                    'policy_type' => $policy_data['policy_type'],
                    'policy_category' => $policy_data['policy_category'] ?? 'Yeni İş', // YENİ: Poliçe kategorisi
                    'insurance_company' => $policy_data['insurance_company'],
                    'start_date' => $policy_data['start_date'],
                    'end_date' => $policy_data['end_date'],
                    'premium_amount' => $policy_data['premium_amount'],
                    'payment_info' => $policy_data['payment_info'] ?? '', // YENİ: Ödeme bilgisi
                    'network' => $policy_data['network'] ?? '', // YENİ: Network bilgisi
                    'insured_party' => $policy_data['insured_party'],
                    'status' => $policy_data['status'],
                    'status_note' => $policy_data['status_note'] ?? '', // YENİ: Durum notu
                    'updated_at' => current_time('mysql'),
                );

                if ($existing_policy) {
                    // Poliçeyi güncelle
                    $result = $wpdb->update(
                        $policies_table, 
                        $policy_insert_data,
                        array('id' => $existing_policy->id)
                    );
                } else {
                    // Yeni poliçe ekle
                    $policy_insert_data['created_at'] = current_time('mysql');
                    $result = $wpdb->insert($policies_table, $policy_insert_data);
                }

                if ($result !== false) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }

            $tasks_message = $is_facebook && $task_success > 0 ? " {$task_success} otomatik görev oluşturuldu." : "";
            $notice = '<div class="ab-notice ab-success">' . $success_count . ' poliçe başarıyla aktarıldı. ' . $customer_success . ' yeni müşteri eklendi.' . $tasks_message;
            if ($error_count > 0) {
                $notice .= ' ' . $error_count . ' işlemde hata oluştu.';
            }
            $notice .= '</div>';

            // Ön izleme verisini sıfırla
            $preview_data = null;
            $matching_step = false;

            // policies sayfasına yönlendir
            $_SESSION['crm_notice'] = $notice;
             echo '<script>window.location.href = "' . esc_url('?view=policies') . '";</script>';
            exit;
        }
    }
}

// Sayfa başlığı belirle
$page_title = $is_xml ? 'XML Dosyası İçeri Aktar' : ($is_facebook ? 'Facebook ADS CSV İçeri Aktar' : 'CSV Dosyası İçeri Aktar');
$file_type_name = $is_xml ? 'XML' : ($is_facebook ? 'Facebook ADS CSV' : 'CSV');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<div class="ab-crm-container">
    <!-- Başlık -->
    <div class="ab-crm-header">
        <div class="ab-crm-title-area">
            <h1>
                <i class="fas fa-<?php echo $is_xml ? 'upload' : ($is_facebook ? 'facebook-square' : 'file-csv'); ?>"></i> 
                <?php echo esc_html($page_title); ?>
            </h1>
        </div>
        
        <div class="ab-crm-header-actions">
            <a href="?view=policies" class="ab-btn">
                <i class="fas fa-chevron-left"></i> Poliçelere Dön
            </a>
            <a href="?view=iceri_aktarim&type=<?php echo $is_xml ? 'csv' : ($is_facebook ? 'xml' : 'facebook'); ?>" class="ab-btn">
                <i class="fas fa-exchange-alt"></i> <?php echo $is_xml ? 'CSV' : ($is_facebook ? 'XML' : 'Facebook ADS CSV'); ?> İçeri Aktarıma Geç
            </a>
        </div>
    </div>
    
    <?php echo $notice; ?>
    
    <?php if (!isset($matching_step) && $preview_data === null): ?>
    <div class="ab-crm-section">
        <h2><?php echo $file_type_name; ?> Dosyası Yükle</h2>
        <p>Lütfen içeri aktarmak istediğiniz <?php echo $file_type_name; ?> dosyasını seçin.</p>
        
        <form method="post" enctype="multipart/form-data" id="<?php echo $is_xml ? 'xml-import-form' : ($is_facebook ? 'facebook-import-form' : 'csv-import-form'); ?>" class="ab-filter-form">
            <?php 
            if ($is_xml) {
                wp_nonce_field('xml_import_action', 'xml_import_nonce'); 
            } elseif ($is_facebook) {
                wp_nonce_field('facebook_import_action', 'facebook_import_nonce');
            } else {
                wp_nonce_field('csv_import_action', 'csv_import_nonce');
            }
            ?>
            <div class="ab-filter-row">
                <div class="ab-filter-col">
                    <label for="<?php echo $is_xml ? 'xml_file' : 'csv_file'; ?>"><?php echo $file_type_name; ?> Dosyası Seç</label>
                    <input type="file" name="<?php echo $is_xml ? 'xml_file' : 'csv_file'; ?>" id="<?php echo $is_xml ? 'xml_file' : 'csv_file'; ?>" accept="<?php echo $is_xml ? '.xml' : '.csv'; ?>" required>
                </div>
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" name="<?php echo $is_xml ? 'preview_xml' : 'preview_csv'; ?>" class="ab-btn ab-btn-filter">Ön İzleme</button>
                </div>
            </div>
        </form>
        
        <div class="ab-file-format-info">
            <h3>Format Bilgisi</h3>
            <?php if ($is_xml): ?>
            <p><strong>XML dosyası için:</strong> Dosya formatı, her poliçe için &lt;POLICE&gt; etiketleri içinde olmalıdır.</p>
            <pre class="ab-code-example"><code>&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;ACENTEDATATRANSFERI&gt;
  &lt;POLICE&gt;
    &lt;Police_NO&gt;1234567&lt;/Police_NO&gt;
    &lt;Musteri_Adi&gt;Ahmet Yılmaz&lt;/Musteri_Adi&gt;
    &lt;TCKimlikNo&gt;12345678901&lt;/TCKimlikNo&gt;
    &lt;Urun_Adi&gt;KASKO&lt;/Urun_Adi&gt;
    ...
  &lt;/POLICE&gt;
  ...
&lt;/ACENTEDATATRANSFERI&gt;</code></pre>
            <?php elseif ($is_facebook): ?>
            <p><strong>Facebook ADS CSV dosyası için:</strong> Dosya, Facebook Ads'ten indirilen lead verilerini içermelidir.</p>
            <pre class="ab-code-example"><code>id,created_time,ad_name,campaign_name,form_name,isim,soyisim,email,telefon,il,ilce,...
12345,2025-01-01T12:30:45,Sigorta Reklamı,AXA Kampanyası,Sigorta Başvuru,Ahmet,Yılmaz,ahmet@example.com,05001234567,İstanbul,Kadıköy,...
</code></pre>
            <?php else: ?>
            <p><strong>CSV dosyası için:</strong> Dosyanın ilk satırı başlıkları içermeli ve alanlar virgülle (,) veya noktalı virgülle (;) ayrılmalıdır.</p>
            <pre class="ab-code-example"><code>Police No;Ad;Soyad;TC Kimlik No;Telefon;Poliçe Türü;Başlangıç Tarihi;Bitiş Tarihi;Prim Tutarı;Temsilci;Yeni İş/Yenileme;Network;Ödeme Bilgisi;Durum
1234567;Ahmet;Yılmaz;12345678901;05001234567;Kasko;01.01.2025;01.01.2026;1500,00;Ahmet Temsilci;Yeni İş;A Network;Peşin;aktif
2345678;Ayşe;Kaya;98765432109;05009876543;Trafik;15.01.2025;15.01.2026;750,00;Ayşe Temsilci;Yenileme;B Network;3 Taksit;aktif
...</code></pre>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- CSV Sütun Eşleştirme Adımı -->
    <?php if (isset($matching_step) && $matching_step): ?>
    <div class="ab-crm-section">
        <h2><?php echo $is_facebook ? 'Facebook ADS' : ''; ?> CSV Sütunlarını Eşleştirme</h2>
        <p>Aşağıdaki tablodan, her bir veritabanı alanı için karşılık gelen CSV sütununu seçiniz.</p>
        
        <form method="post" id="<?php echo $is_facebook ? 'facebook-mapping-form' : 'csv-mapping-form'; ?>" class="ab-filter-form">
            <?php 
            if ($is_facebook) {
                wp_nonce_field('facebook_match_action', 'facebook_match_nonce');
            } else {
                wp_nonce_field('csv_match_action', 'csv_match_nonce'); 
            }
            ?>
            <input type="hidden" name="temp_csv_file" value="<?php echo esc_attr($temp_file); ?>">
            <input type="hidden" name="csv_delimiter" value="<?php echo esc_attr($csv_data['delimiter']); ?>">
            
            <!-- Örnek Veriler Tablosu -->
            <div class="ab-mapping-preview">
                <h3>CSV Dosyasının İçeriği</h3>
                <div class="ab-csv-table-container">
                    <table class="ab-csv-preview-table">
                        <thead>
                            <tr>
                                <th>Sütun No</th>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <th><?php echo esc_html($header); ?> <span class="ab-column-number">(<?php echo $index; ?>)</span></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($csv_data['sample_data'] as $row_index => $row): ?>
                                <tr>
                                    <td class="ab-row-number">Satır <?php echo $row_index + 1; ?></td>
                                    <?php foreach ($row as $cell): ?>
                                        <td><?php echo esc_html($cell); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Eşleştirme Alanları -->
            <div class="ab-mapping-fields">
                <h3>Alanları Eşleştir</h3>
                <p><strong>Talimatlar:</strong> Her veritabanı alanı için, karşılık gelen CSV sütununu seçin. Zorunlu alanlar <span class="ab-required">*</span> ile işaretlenmiştir.</p>
                
                <div class="ab-mapping-grid">
                    <!-- Müşteri Bilgileri -->
                    <div class="ab-mapping-section">
                        <h4>Müşteri Bilgileri</h4>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_ad">Müşteri Adı <span class="ab-required">*</span></label>
                            <select name="mapping_ad" id="mapping_ad" class="ab-select" required>
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['ad']) && $suggested_mapping['ad'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_soyad">Müşteri Soyadı</label>
                            <select name="mapping_soyad" id="mapping_soyad" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['soyad']) && $suggested_mapping['soyad'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Eğer ad ve soyad tek sütunda ise sadece "Müşteri Adı" alanını seçin.</small>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_tc_kimlik">T.C. Kimlik No</label>
                            <select name="mapping_tc_kimlik" id="mapping_tc_kimlik" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['tc_kimlik']) && $suggested_mapping['tc_kimlik'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_telefon">Telefon</label>
                            <select name="mapping_telefon" id="mapping_telefon" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['telefon']) && $suggested_mapping['telefon'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_adres">Adres</label>
                            <select name="mapping_adres" id="mapping_adres" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['adres']) && $suggested_mapping['adres'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_dogum_tarih">Doğum Tarihi</label>
                            <select name="mapping_dogum_tarih" id="mapping_dogum_tarih" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['dogum_tarih']) && $suggested_mapping['dogum_tarih'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- YENİ: Temsilci ID Alanı -->
                        <div class="ab-mapping-field">
                            <label for="mapping_representative_id">Müşteri Temsilcisi</label>
                            <select name="mapping_representative_id" id="mapping_representative_id" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Temsilci ID veya ismi içeren sütunu seçin (boş ise mevcut kullanıcı kullanılacak).</small>
                        </div>
                        
                        <?php if ($is_facebook): ?>
                        <div class="ab-mapping-field">
                            <label for="mapping_email">E-posta</label>
                            <select name="mapping_email" id="mapping_email" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['email']) && $suggested_mapping['email'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_yorum">Yorum / Not</label>
                            <select name="mapping_yorum" id="mapping_yorum" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['yorum']) && $suggested_mapping['yorum'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Müşteri ile ilgili notları içeren sütunu seçin.</small>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_campaign_name">Kampanya Adı</label>
                            <select name="mapping_campaign_name" id="mapping_campaign_name" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['campaign_name']) && $suggested_mapping['campaign_name'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Facebook kampanya bilgisini içeren sütunu seçin.</small>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_created_time">Oluşturulma Tarihi</label>
                            <select name="mapping_created_time" id="mapping_created_time" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>" <?php if(isset($suggested_mapping['created_time']) && $suggested_mapping['created_time'] == $index): ?> selected <?php endif; ?>>
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Kaydın oluşturulma tarihini içeren sütunu seçin.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!$is_facebook): ?>
                    <!-- Poliçe Bilgileri - Facebook ADS için gösterilmez -->
                    <div class="ab-mapping-section">
                        <h4>Poliçe Bilgileri</h4>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_police_no">Poliçe No <span class="ab-required">*</span></label>
                            <select name="mapping_police_no" id="mapping_police_no" class="ab-select" required>
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_police_turu">Poliçe Türü</label>
                            <select name="mapping_police_turu" id="mapping_police_turu" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- YENİ: Poliçe Kategorisi Alanı -->
                        <div class="ab-mapping-field">
                            <label for="mapping_policy_category">Yeni İş / Yenileme</label>
                            <select name="mapping_policy_category" id="mapping_policy_category" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Yeni İş veya Yenileme bilgisini içeren sütunu seçin.</small>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_sigorta_sirketi">Sigorta Şirketi</label>
                            <select name="mapping_sigorta_sirketi" id="mapping_sigorta_sirketi" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_baslangic_tarih">Başlangıç Tarihi</label>
                            <select name="mapping_baslangic_tarih" id="mapping_baslangic_tarih" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_bitis_tarih">Bitiş Tarihi</label>
                            <select name="mapping_bitis_tarih" id="mapping_bitis_tarih" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_prim_tutari">Prim Tutarı</label>
                            <select name="mapping_prim_tutari" id="mapping_prim_tutari" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- YENİ: Ödeme Bilgisi Alanı -->
                        <div class="ab-mapping-field">
                            <label for="mapping_payment_info">Ödeme Bilgisi</label>
                            <select name="mapping_payment_info" id="mapping_payment_info" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Ör: Peşin, 3 Taksit gibi ödeme bilgilerini içeren sütunu seçin.</small>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_sigorta_ettiren">Sigorta Ettiren</label>
                            <select name="mapping_sigorta_ettiren" id="mapping_sigorta_ettiren" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- YENİ: Network Bilgisi Alanı -->
                        <div class="ab-mapping-field">
                            <label for="mapping_network">Network</label>
                            <select name="mapping_network" id="mapping_network" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Ör: A Network, B Network gibi bilgiler.</small>
                        </div>
                        
                        <div class="ab-mapping-field">
                            <label for="mapping_status">Durum</label>
                            <select name="mapping_status" id="mapping_status" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- YENİ: Durum Bilgisi Notu Alanı -->
                        <div class="ab-mapping-field">
                            <label for="mapping_status_note">Durum Bilgisi Notu</label>
                            <select name="mapping_status_note" id="mapping_status_note" class="ab-select">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($csv_data['headers'] as $index => $header): ?>
                                    <option value="<?php echo $index; ?>">
                                        <?php echo esc_html($header); ?> (<?php echo $index; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="ab-hint">Poliçe durumu ile ilgili not ve açıklamalar.</small>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Facebook ADS için açıklama -->
                    <div class="ab-mapping-section">
                        <h4>Facebook ADS İçe Aktarım Bilgisi</h4>
                        <div class="ab-facebook-info">
                            <p>Facebook ADS içe aktarımı özelliği ile:</p>
                            <ul>
                                <li>Müşteri bilgileri ile potansiyel müşteri oluşturulacaktır</li>
                                <li>Poliçe kaydı olmayacaktır - sadece müşteri adayı kaydı oluşturulacaktır</li>
                                <li>Ön izlemede her müşteri için müşteri temsilcisi ataması yapabileceksiniz</li>
                                <li>Facebook kampanyası bilgisi müşteri notları alanına eklenecektir</li>
                                <li>Her müşteri için otomatik görev oluşturulacaktır</li>
                            </ul>
                            
                            <div class="ab-facebook-settings">
                                <h5>Varsayılan Ayarlar</h5>
                                <table class="ab-facebook-settings-table">
                                    <tr>
                                        <th>Kaynak:</th>
                                        <td>Facebook ADS</td>
                                    </tr>
                                    <tr>
                                        <th>Durum:</th>
                                        <td>Aktif</td>
                                    </tr>
                                    <tr>
                                        <th>Temsilci:</th>
                                        <td>Ön izleme ekranında seçilebilir</td>
                                    </tr>
                                    <tr>
                                        <th>Otomatik Görev:</th>
                                        <td>Müşteri araması için 1 gün sonra saat 18:00'e kadar</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="ab-mapping-actions">
                    <button type="submit" name="match_csv_columns" class="ab-btn ab-btn-filter">Eşleştir ve Önizle</button>
                    <a href="?view=iceri_aktarim&type=<?php echo $is_facebook ? 'facebook' : 'csv'; ?>" class="ab-btn ab-btn-reset">İptal</a>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Önizleme ve Aktarım İşlemleri -->
    <?php if (isset($preview_data) && !isset($matching_step)): ?>
    <div class="ab-crm-section">
        <h2><?php echo $is_xml ? "XML" : ($is_facebook ? "Facebook ADS CSV" : "CSV"); ?> Ön İzleme</h2>
        <p>Yüklenen dosyadaki veriler aşağıda listelenmiştir. Aktarmak istediğiniz <?php echo $is_facebook ? "müşterileri" : "poliçeleri"; ?> seçin ve onaylayın.</p>
        
        <?php if (isset($preview_data['debug'])): ?>
        <div class="ab-debug-info">
            <p><strong>İşlem Bilgileri:</strong> 
            <?php if ($is_facebook): ?>
            Dosyada <?php echo $preview_data['debug']['total_records'] ?? 0; ?> kayıt bulundu, 
            <?php echo $preview_data['debug']['processed_records'] ?? 0; ?> kayıt işlendi, 
            <?php echo $preview_data['debug']['matched_customers'] ?? 0; ?> mevcut müşteri eşleştirildi.
            <?php else: ?>
            Dosyada <?php echo $preview_data['debug']['total_policies']; ?> poliçe bulundu, 
            <?php echo $preview_data['debug']['processed_policies']; ?> poliçe işlendi, 
            <?php echo $preview_data['debug']['matched_customers']; ?> mevcut müşteri eşleştirildi.
            <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if ((!$is_xml && !$is_facebook) && isset($preview_data['column_mapping'])): ?>
        <div class="ab-mapping-info">
            <h4>Alan Eşleştirmeleri</h4>
            <p>Aşağıdaki tabloda CSV dosyanızdaki alanlar ve eşleşen veritabanı alanları gösterilmektedir:</p>
            <table class="ab-csv-mapping-table">
                <thead>
                    <tr>
                        <th>CSV Başlığı</th>
                        <th>Sistem Alanı</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $field_labels = [
                        'police_no' => 'Poliçe No',
                        'ad' => 'Müşteri Adı',
                        'soyad' => 'Müşteri Soyadı',
                        'tc_kimlik' => 'TC Kimlik No',
                        'telefon' => 'Telefon',
                        'adres' => 'Adres',
                        'dogum_tarih' => 'Doğum Tarihi',
                        'police_turu' => 'Poliçe Türü',
                        'policy_category' => 'Yeni İş/Yenileme',
                        'sigorta_sirketi' => 'Sigorta Şirketi',
                        'baslangic_tarih' => 'Başlangıç Tarihi',
                        'bitis_tarih' => 'Bitiş Tarihi',
                        'prim_tutari' => 'Prim Tutarı',
                        'payment_info' => 'Ödeme Bilgisi',
                        'sigorta_ettiren' => 'Sigorta Ettiren',
                        'network' => 'Network',
                        'status' => 'Durum',
                        'status_note' => 'Durum Bilgisi Notu',
                        'representative_id' => 'Müşteri Temsilcisi',
                        'email' => 'E-Posta',
                        'campaign_name' => 'Kampanya Adı',
                        'created_time' => 'Oluşturulma Tarihi',
                        'yorum' => 'Yorum/Not'
                    ];
                    
                    foreach ($preview_data['column_mapping'] as $db_field => $csv_index):
                        if (strpos($db_field, 'extra_') === 0 || $db_field === 'delimiter') continue; // Ekstra alanları atla
                        $original_header = isset($preview_data['original_headers'][$csv_index]) ? 
                            $preview_data['original_headers'][$csv_index] : 'Boş';
                        $field_label = isset($field_labels[$db_field]) ? $field_labels[$db_field] : ucfirst($db_field);
                        ?>
                        <tr>
                            <td><?php echo esc_html($original_header); ?></td>
                            <td><?php echo esc_html($field_label); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <form method="post" id="preview-confirm-form" class="ab-filter-form">
            <?php echo $is_xml ? 
                wp_nonce_field('xml_confirm_action', 'xml_confirm_nonce', true, false) : 
                ($is_facebook ? 
                    wp_nonce_field('facebook_confirm_action', 'facebook_confirm_nonce', true, false) :
                    wp_nonce_field('csv_confirm_action', 'csv_confirm_nonce', true, false)); 
            ?>
            <!-- Preview data'yı JSON yerine seri hale getir -->
            <input type="hidden" name="preview_data" value="<?php echo esc_attr(base64_encode(serialize($preview_data))); ?>">
            
            <?php if ($is_facebook && isset($preview_data['customers']) && !empty($preview_data['customers'])): ?>
                <!-- "Tüm satırlar için müşteri temsilcisi seçin" dropdown'ı -->
                <div class="ab-global-rep-selector">
                    <label for="global_representative">Tüm müşteriler için temsilci seçin: </label>
                    <select id="global_representative" class="global-rep-select">
                        <option value="">-- Temsilci Seçin --</option>
                        
                        <?php foreach ($all_representatives as $rep): ?>
                            <option value="<?php echo esc_attr($rep->id); ?>"
                                <?php if ($rep->id == $current_user_rep_id) echo ' selected'; ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="ab-hint">(Bu seçim tüm müşterilere uygulanır)</span>
                </div>
            
                <h4>Facebook Müşteri Adayları (<?php echo count($preview_data['customers']); ?>)</h4>
                <div class="ab-crm-table-wrapper">
                    <table class="ab-crm-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-customers" checked></th>
                                <th>Ad Soyad</th>
                                <th>E-posta</th>
                                <th>Telefon</th>
                                <th>Kampanya</th>
                                <th>Kayıt Tarihi</th>
                                <th>Durum</th>
                                <th>Müşteri Temsilcisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data['customers'] as $index => $customer): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_policies[]" value="<?php echo $index; ?>" checked>
                                        
                                        <!-- Müşteri temsilcisi seçimi ekle -->
                                        <div class="representative-selection">
                                            <select name="customer_representative[<?php echo $index; ?>]" class="rep-select">
                                                <option value="">-- Temsilci Seç --</option>
                                                <?php foreach ($all_representatives as $rep): ?>
                                                    <option value="<?php echo esc_attr($rep->id); ?>"
                                                        <?php selected($rep->id == $current_user_rep_id); ?>>
                                                        <?php echo esc_html($rep->display_name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($customer['first_name'] . ' ' . $customer['last_name']); ?></td>
                                    <td><?php echo esc_html($customer['email'] ?? ''); ?></td>
                                    <td><?php echo esc_html($customer['phone'] ?? ''); ?></td>
                                    <td><?php echo esc_html($customer['campaign_name'] ?? ''); ?></td>
                                    <td>
                                        <?php 
                                        $created_time = isset($customer['created_time']) ? $customer['created_time'] : '';
                                        if (!empty($created_time)) {
                                            // Facebook timestamp dönüşümü (2025-05-24T23:34:51+03:00 formatından)
                                            $date = new DateTime($created_time);
                                            echo $date->format('d.m.Y H:i');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($customer['status'] === 'Yeni'): ?>
                                            <span class="ab-badge ab-badge-warning">Yeni</span>
                                        <?php else: ?>
                                            <span class="ab-badge ab-badge-info">Mevcut</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Bu hücre boş bırakılacak, çünkü sütun başlığında zaten müşteri temsilcisi belirtiliyor -->
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <h4>Yeni Poliçeler (<?php echo count($preview_data['policies']); ?>)</h4>
                <div class="ab-crm-table-wrapper">
                    <table class="ab-crm-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-policies" checked></th>
                                <th>Poliçe No</th>
                                <th>Müşteri</th>
                                <th>TC Kimlik No</th>
                                <th>Poliçe Türü</th>
                                <th>Yeni İş/Yenileme</th>
                                <th>Sigorta Firması</th>
                                <th>Network</th>
                                <th>Başlangıç</th>
                                <th>Bitiş</th>
                                <th>Prim (₺)</th>
                                <th>Ödeme</th>
                                <th>Durum</th>
                                <th>Temsilci</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data['policies'] as $index => $policy): ?>
                                <?php 
                                $customer = $preview_data['customers'][$policy['customer_key']];
                                
                                // Temsilci adını bul
                                $rep_name = '';
                                $rep_id = $policy['representative_id'] ?? $current_user_rep_id;
                                foreach ($all_representatives as $rep) {
                                    if ($rep->id == $rep_id) {
                                        $rep_name = $rep->display_name;
                                        break;
                                    }
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_policies[]" value="<?php echo $index; ?>" checked></td>
                                    <td><?php echo esc_html($policy['policy_number']); ?></td>
                                    <td>
                                        <?php echo esc_html($customer['first_name'] . ' ' . $customer['last_name']);
                                        if ($customer['status'] === 'Yeni') {
                                            echo ' <span class="ab-badge ab-badge-warning">Yeni</span>';
                                        } ?>
                                    </td>
                                    <td><?php echo esc_html($customer['tc_kimlik']); ?></td>
                                    <td><?php echo esc_html($policy['policy_type']); ?></td>
                                    <td><?php echo esc_html($policy['policy_category'] ?? 'Yeni İş'); ?></td>
                                    <td><?php echo esc_html($policy['insurance_company']); ?></td>
                                    <td><?php echo isset($policy['network']) && !empty($policy['network']) ? esc_html($policy['network']) : '—'; ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy['start_date'])); ?></td>
                                    <td><?php echo date('d.m.Y', strtotime($policy['end_date'])); ?></td>
                                    <td><?php echo number_format($policy['premium_amount'], 2, ',', '.'); ?></td>
                                    <td><?php echo isset($policy['payment_info']) && !empty($policy['payment_info']) ? esc_html($policy['payment_info']) : '—'; ?></td>
                                    <td><span class="ab-badge ab-badge-status-<?php echo esc_attr($policy['status']); ?>"><?php echo $policy['status'] === 'aktif' ? 'Aktif' : 'Pasif'; ?></span></td>
                                    <td><?php echo !empty($rep_name) ? esc_html($rep_name) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Yeni Müşteriler (Facebook ADS gelmiyorsa) -->
            <?php if (!$is_facebook): ?>
            <h4>Yeni Müşteriler (<?php echo count(array_filter($preview_data['customers'], function($c) { return $c['status'] === 'Yeni'; })); ?>)</h4>
            <div class="ab-crm-table-wrapper">
                <table class="ab-crm-table">
                    <thead>
                        <tr>
                            <th>Ad</th>
                            <th>Soyad</th>
                            <th>TC Kimlik No</th>
                            <th>Doğum Tarihi</th>
                            <th>Telefon</th>
                            <th>Adres</th>
                            <th>Durum</th>
                            <th>Temsilci</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $new_customers = array_filter($preview_data['customers'], function($c) { return $c['status'] === 'Yeni'; });
                        
                        if (!empty($new_customers)):
                            foreach ($new_customers as $customer):
                                // Temsilci adını bul
                                $rep_name = '';
                                $rep_id = $customer['representative_id'] ?? $current_user_rep_id;
                                foreach ($all_representatives as $rep) {
                                    if ($rep->id == $rep_id) {
                                        $rep_name = $rep->display_name;
                                        break;
                                    }
                                }
                        ?>
                            <tr>
                                <td><?php echo esc_html($customer['first_name']); ?></td>
                                <td><?php echo esc_html($customer['last_name']); ?></td>
                                <td><?php echo esc_html($customer['tc_kimlik']); ?></td>
                                <td><?php echo !empty($customer['birth_date']) ? date('d.m.Y', strtotime($customer['birth_date'])) : '-'; ?></td>
                                <td><?php echo esc_html($customer['phone']); ?></td>
                                <td><?php echo esc_html($customer['address']); ?></td>
                                <td><span class="ab-badge ab-badge-warning">Yeni</span></td>
                                <td><?php echo !empty($rep_name) ? esc_html($rep_name) : '—'; ?></td>
                            </tr>
                        <?php 
                            endforeach;
                        else: 
                        ?>
                            <tr>
                                <td colspan="8">Yeni müşteri bulunamadı.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="ab-filter-row">
                <div class="ab-filter-col ab-button-col">
                    <button type="submit" name="<?php echo $is_xml ? "confirm_xml" : ($is_facebook ? "confirm_facebook" : "confirm_csv"); ?>" class="ab-btn ab-btn-filter">
                        Onayla ve <?php echo $is_facebook ? "Müşterileri" : "Poliçeleri"; ?> Aktar
                    </button>
                    <a href="?view=iceri_aktarim&type=<?php echo $is_xml ? 'xml' : ($is_facebook ? 'facebook' : 'csv'); ?>" class="ab-btn ab-btn-reset">İptal</a>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>
/* Ana Container */
.ab-crm-container {
    max-width: 96%;
    margin: 0 auto 30px;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    color: #333;
}

/* Başlık Alanı */
.ab-crm-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eaeaea;
    flex-wrap: wrap;
}

.ab-crm-header h1 {
    font-size: 24px;
    margin: 0;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.ab-crm-title-area {
    display: flex;
    align-items: center;
}

.ab-crm-header-actions {
    display: flex;
    gap: 10px;
}

.ab-crm-section {
    margin-bottom: 30px;
    background-color: #fff;
}

.ab-crm-section h2 {
    font-size: 20px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    color: #333;
}

.ab-file-format-info {
    margin-top: 25px;
    padding: 15px;
    border-radius: 6px;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
}

.ab-file-format-info h3 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 16px;
    color: #495057;
}

.ab-code-example {
    background-color: #f5f5f5;
    padding: 15px;
    border-radius: 5px;
    border: 1px solid #e1e1e1;
    font-family: monospace;
    font-size: 13px;
    overflow-x: auto;
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
    color: #333;
}

/* Butonlar */
.ab-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    background-color: #f7f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 13px;
    transition: all 0.2s;
    font-weight: 500;
}

.ab-btn:hover {
    background-color: #eaeaea;
    text-decoration: none;
    color: #333;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-filter {
    background-color: #4caf50;
    border-color: #43a047;
    color: white;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-filter:hover {
    background-color: #3d9140;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.ab-btn-reset {
    background-color: #f8f9fa;
    border-color: #d1d5db;
    color: #666;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s;
}

.ab-btn-reset:hover {
    background-color: #e5e7eb;
    color: #444;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

/* Form Elemanları */
.ab-filter-form {
    width: 100%;
}

.ab-filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    align-items: end;
}

.ab-filter-col {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.ab-filter-col label {
    font-size: 14px;
    font-weight: 500;
    color: #444;
    margin-bottom: 8px;
    line-height: 1.4;
}

.ab-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-select:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-filter-col input[type="text"],
.ab-filter-col input[type="file"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    height: 40px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.ab-filter-col input[type="text"]:focus,
.ab-filter-col input[type="file"]:focus {
    outline: none;
    border-color: #4caf50;
    box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
}

.ab-button-col {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

/* CSV Eşleştirme Ekranı Stilleri */
.ab-mapping-preview {
    margin-bottom: 25px;
    background-color: #f5f9ff;
    border: 1px solid #d0e1fd;
    border-radius: 6px;
    padding: 15px;
}

.ab-csv-table-container {
    width: 100%;
    overflow-x: auto;
    margin-top: 10px;
}

.ab-csv-preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 100%;
}

.ab-csv-preview-table th,
.ab-csv-preview-table td {
    padding: 8px 10px;
    text-align: left;
    border: 1px solid #ddd;
    white-space: nowrap;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ab-csv-preview-table th {
    background-color: #eef4ff;
    font-weight: 600;
    position: relative;
    padding-right: 25px;
}

.ab-csv-preview-table thead tr {
    position: sticky;
    top: 0;
    z-index: 1;
}

.ab-column-number {
    display: inline-block;
    background: #0066cc;
    color: white;
    border-radius: 3px;
    padding: 0px 5px;
    font-size: 11px;
    margin-left: 4px;
}

.ab-row-number {
    background-color: #eef4ff;
    font-weight: 600;
}

.ab-mapping-fields {
    margin-top: 30px;
}

.ab-mapping-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 25px;
}

.ab-mapping-section {
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    padding: 15px;
    border-radius: 6px;
}

.ab-mapping-section h4 {
    margin-top: 0;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e0e0e0;
}

.ab-mapping-field {
    margin-bottom: 15px;
}

.ab-mapping-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
}

.ab-mapping-field small {
    display: block;
    color: #777;
    margin-top: 4px;
    font-size: 12px;
}

.ab-hint {
    display: block;
    color: #666;
    font-size: 12px;
    margin-top: 4px;
}

.ab-required {
    color: #e53935;
    font-weight: bold;
}

.ab-mapping-actions {
    margin-top: 25px;
    display: flex;
    gap: 12px;
}

/* CSV Eşleştirme Tablosu Stilleri */
.ab-mapping-info {
    background-color: #f0f8ff;
    border: 1px solid #cce5ff;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 5px;
}

.ab-mapping-info h4 {
    margin-top: 0;
    margin-bottom: 10px;
    color: #0366d6;
    font-size: 16px;
}

.ab-mapping-info p {
    margin-bottom: 12px;
    font-size: 14px;
}

.ab-csv-mapping-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 12px;
    font-size: 13px;
}

.ab-csv-mapping-table th,
.ab-csv-mapping-table td {
    padding: 8px 12px;
    border: 1px solid #ddd;
    text-align: left;
}

.ab-csv-mapping-table th {
    background-color: #f1f8ff;
    font-weight: 600;
}

.ab-csv-mapping-table tr:nth-child(even) {
    background-color: #f9f9f9;
}

.ab-csv-mapping-table tr:hover {
    background-color: #f0f0f0;
}

/* Ön İzleme Ekranı Stilleri */
.ab-debug-info {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 12px 15px;
    margin-bottom: 20px;
    font-size: 13px;
    color: #666;
}

.ab-crm-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
    background-color: #fff;
    border-radius: 8px;
    border: 1px solid #eee;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}

.ab-crm-table {
    width: 100%;
    border-collapse: collapse;
}

.ab-crm-table th,
.ab-crm-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

.ab-crm-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #444;
}

.ab-crm-table tr:hover td {
    background-color: #f5f5f5;
}

.ab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
}

.ab-badge-warning {
    background-color: #fff8e5;
    color: #bf8700;
}

.ab-badge-info {
    background-color: #e6f7ff;
    color: #0366d6;
}

.ab-badge-status-aktif {
    background-color: #e6ffed;
    color: #22863a;
}

.ab-badge-status-pasif {
    background-color: #f5f5f5;
    color: #666;
}

.ab-notice {
    padding: 12px 15px;
    margin-bottom: 20px;
    border-radius: 6px;
    font-size: 14px;
}

.ab-error {
    background-color: #ffeaea;
    color: #d32f2f;
    border-left: 4px solid #d32f2f;
}

.ab-success {
    background-color: #e6ffed;
    color: #22863a;
    border-left: 4px solid #22863a;
}

.ab-warning {
    background-color: #fff8e5;
    color: #bf8700;
    border-left: 4px solid #bf8700;
}

/* Facebook Özellikleri */
.ab-facebook-info {
    background-color: #f0f2f5;
    border: 1px solid #dddfe2;
    border-radius: 6px;
    padding: 15px;
}

.ab-facebook-info p {
    font-size: 14px;
    margin-bottom: 10px;
}

.ab-facebook-info ul {
    margin-left: 20px;
    margin-bottom: 15px;
}

.ab-facebook-info li {
    margin-bottom: 5px;
    font-size: 13px;
}

.ab-facebook-settings {
    background-color: #ffffff;
    border: 1px solid #e9ebee;
    border-radius: 4px;
    padding: 12px;
    margin-top: 15px;
}

.ab-facebook-settings h5 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 14px;
    color: #4b4f56;
}

.ab-facebook-settings-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.ab-facebook-settings-table th,
.ab-facebook-settings-table td {
    padding: 6px;
    border-bottom: 1px solid #e9ebee;
    text-align: left;
}

.ab-facebook-settings-table th {
    width: 120px;
    color: #606770;
    font-weight: 500;
}

.representative-selection {
    margin-top: 5px;
}

.rep-select {
    width: 100%;
    padding: 4px 6px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 12px;
}

.ab-global-rep-selector {
    margin: 15px 0;
    padding: 10px;
    background-color: #f0f8ff;
    border: 1px solid #cce5ff;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.global-rep-select {
    min-width: 200px;
    padding: 6px 8px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 13px;
}

/* Duyarlı Tasarım */
@media (max-width: 992px) {
    .ab-mapping-grid {
        grid-template-columns: 1fr;
    }
    
    .ab-filter-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .ab-crm-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .ab-crm-header-actions {
        width: 100%;
        flex-direction: column;
        gap: 10px;
    }
    
    .ab-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form submit için onay isteği
    const confirmForm = document.getElementById('preview-confirm-form');
    if (confirmForm) {
        confirmForm.addEventListener('submit', function(e) {
            // Seçili poliçe var mı kontrol et
            const selectedPolicies = document.querySelectorAll('input[name="selected_policies[]"]:checked');
            if (selectedPolicies.length === 0) {
                e.preventDefault();
                alert('Lütfen en az bir <?php echo $is_facebook ? "müşteri" : "poliçe"; ?> seçin.');
                return false;
            }
            
            return confirm('Seçili <?php echo $is_facebook ? "müşterileri" : "poliçeleri"; ?> aktarmak istediğinize emin misiniz?');
        });
    }
    
    // Tüm poliçeleri seçme/kaldırma
    const selectAllCheckbox = document.getElementById('select-all-policies');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            document.querySelectorAll('input[name="selected_policies[]"]').forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        });
    }
    
    // Tüm müşterileri seçme/kaldırma (Facebook ADS için)
    const selectAllCustomers = document.getElementById('select-all-customers');
    if (selectAllCustomers) {
        selectAllCustomers.addEventListener('change', function() {
            const isChecked = this.checked;
            document.querySelectorAll('input[name="selected_policies[]"]').forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        });
    }

    // Toplu müşteri temsilcisi atama
    if (typeof jQuery !== 'undefined') {
        jQuery('.global-rep-select').on('change', function() {
            var repId = jQuery(this).val();
            if (repId) {
                jQuery('.rep-select').val(repId);
            }
        });
    } else {
        // jQuery yoksa vanilla JS ile yap
        const globalRepSelect = document.getElementById('global_representative');
        if (globalRepSelect) {
            globalRepSelect.addEventListener('change', function() {
                const repId = this.value;
                if (repId) {
                    document.querySelectorAll('.rep-select').forEach(select => {
                        select.value = repId;
                    });
                }
            });
        }
    }

    <?php if (!$is_xml && !$is_facebook): ?>
    // CSV başlıklarını veritabanı alanlarıyla otomatik eşleştirme
    if (document.getElementById('csv-mapping-form')) {
        // Eşleştirme kuralları - anahtar: veritabanı alanı, değer: CSV başlığında aranacak kelimeler
        const mappingRules = {
            'police_no': ['police', 'poliçe', 'policy', 'no', 'numara', 'number'],
            'ad': ['ad', 'isim', 'first', 'name', 'müşteri', 'sigort'],
            'soyad': ['soyad', 'last', 'surname'],
            'tc_kimlik': ['tc', 'kimlik', 'identity', 'tckn', 'vergi'],
            'telefon': ['tel', 'phone', 'cep', 'gsm', 'iletişim', 'contact'],
            'adres': ['adres', 'address', 'lokasyon', 'location', 'il', 'ilçe'],
            'dogum_tarih': ['doğum', 'dogum', 'birth', 'date'],
            'police_turu': ['tür', 'tur', 'type', 'branş', 'brans'],
            'sigorta_sirketi': ['şirket', 'sirket', 'company', 'firma'],
            'baslangic_tarih': ['başlangıç', 'baslangic', 'start', 'başlar'],
            'bitis_tarih': ['bitiş', 'bitis', 'end', 'son', 'sona'],
            'prim_tutari': ['prim', 'tutar', 'premium', 'ücret', 'fiyat', 'price', 'net'],
            'sigorta_ettiren': ['ettiren', 'insured', 'sigortalı', 'sigortali'],
            'network': ['network', 'ağ', 'paket'],
            'status': ['durum', 'status', 'aktif', 'pasif'],
            'policy_category': ['kategori', 'category', 'yeni', 'yenileme', 'renewal', 'new'],
            'payment_info': ['ödeme', 'payment', 'taksit', 'peşin', 'installment'],
            'status_note': ['not', 'note', 'açıklama', 'description'],
            'representative_id': ['temsilci', 'representative', 'yetkili', 'danışman']
        };
        
        // CSV başlıklarını al
        const headers = [];
        const headerElements = document.querySelectorAll('.ab-csv-preview-table th');
        headerElements.forEach((el, index) => {
            if (index > 0) { // İlk "Sütun No" başlığını atla
                const headerText = el.textContent.trim();
                const columnMatch = headerText.match(/\((\d+)\)/);
                if (columnMatch) {
                    const columnIndex = columnMatch[1];
                    headers[columnIndex] = headerText.replace(/\(\d+\)/, '').trim().toLowerCase();
                }
            }
        });
        
        // Her veritabanı alanı için olası eşleşmeleri bul
        Object.keys(mappingRules).forEach(dbField => {
            const selectElement = document.getElementById('mapping_' + dbField);
            if (!selectElement) return;
            
            let bestMatchIndex = null;
            let bestMatchScore = 0;
            
            headers.forEach((header, index) => {
                if (!header) return;
                
                let score = 0;
                mappingRules[dbField].forEach(keyword => {
                    if (header.includes(keyword.toLowerCase())) {
                        score += 1;
                    }
                });
                
                if (score > bestMatchScore) {
                    bestMatchScore = score;
                    bestMatchIndex = index;
                }
            });
            
            // En iyi eşleşme varsa seç
            if (bestMatchIndex !== null && bestMatchScore > 0) {
                selectElement.value = bestMatchIndex;
            }
        });
    }
    <?php endif; ?>
    
    // Form validasyonları
    const xmlImportForm = document.getElementById('xml-import-form');
    if (xmlImportForm) {
        xmlImportForm.addEventListener('submit', function(e) {
            const fileInput = this.querySelector('input[type="file"]');
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Lütfen bir XML dosyası seçin.');
                return false;
            }
        });
    }
    
    const csvImportForm = document.getElementById('csv-import-form');
    if (csvImportForm) {
        csvImportForm.addEventListener('submit', function(e) {
            const fileInput = this.querySelector('input[type="file"]');
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Lütfen bir CSV dosyası seçin.');
                return false;
            }
        });
    }
    
    // Facebook ADS CSV İmport formu
    const facebookImportForm = document.getElementById('facebook-import-form');
    if (facebookImportForm) {
        facebookImportForm.addEventListener('submit', function(e) {
            const fileInput = this.querySelector('input[type="file"]');
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Lütfen bir CSV dosyası seçin.');
                return false;
            }
        });
    }
    
    const csvMappingForm = document.getElementById('csv-mapping-form');
    if (csvMappingForm) {
        csvMappingForm.addEventListener('submit', function(e) {
            // CSV için Ad ve Poliçe No zorunlu
            const requiredFields = ['mapping_ad', 'mapping_police_no'];
            let isValid = true;
            
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field || !field.value) {
                    isValid = false;
                    alert('Lütfen zorunlu alanları doldurun: ' + fieldId.replace('mapping_', ''));
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Facebook Mapping formu
    const facebookMappingForm = document.getElementById('facebook-mapping-form');
    if (facebookMappingForm) {
        facebookMappingForm.addEventListener('submit', function(e) {
            // Facebook için sadece Ad zorunlu
            const requiredFields = ['mapping_ad'];
            let isValid = true;
            
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field || !field.value) {
                    isValid = false;
                    alert('Lütfen zorunlu alanları doldurun: ' + fieldId.replace('mapping_', ''));
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Son kullanıcı etkileşimi kaydı
    console.log('İçeri aktarım sayfası yüklendi - Kullanıcı: anadolubirlikdevam - Tarih: 2025-05-27 10:52:39 UTC');
});
</script>
