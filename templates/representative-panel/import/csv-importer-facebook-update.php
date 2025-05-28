<?php
/**
 * Facebook ADS CSV İçe Aktarma Ek Fonksiyonları
 * @version 1.4.3
 * @date 2025-05-27 12:06:04
 * @user anadolubirlikLütfen
 */

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed
}

// Hata günlüğü fonksiyonunu yükle (mevcut değilse)
if (!function_exists('facebook_ads_log')) {
    function facebook_ads_log($message, $data = null) {
        error_log('[Facebook ADS] ' . $message);
    }
}

/**
 * Tarih formatlar - eğer tanımlanmamışsa tanımla
 */
if (!function_exists('format_date_for_db')) {
    function format_date_for_db($date_str) {
        // Boşsa boş dön
        if (empty($date_str)) {
            return '';
        }
        
        // Tarih zaten Y-m-d formatında mı?
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            return $date_str;
        }
        
        // Tarih/saat ise sadece tarih kısmını al
        if (strpos($date_str, ' ') !== false) {
            $date_parts = explode(' ', $date_str);
            $date_str = $date_parts[0];
        }
        
        // Nokta veya eğik çizgi ayrılmış formatlarda çalış (dd.mm.yyyy veya dd/mm/yyyy)
        if (strpos($date_str, '.') !== false) {
            $date_parts = explode('.', $date_str);
        } elseif (strpos($date_str, '/') !== false) {
            $date_parts = explode('/', $date_str);
        } elseif (strpos($date_str, '-') !== false) {
            $date_parts = explode('-', $date_str);
        } else {
            return ''; // Anlaşılamayan format
        }
        
        // Parçaların geçerli olup olmadığını kontrol et
        if (count($date_parts) != 3) {
            return '';
        }
        
        // Gün/Ay/Yıl formatı olarak kabul et
        $day = intval($date_parts[0]);
        $month = intval($date_parts[1]);
        $year = intval($date_parts[2]);
        
        // Yıl 2 haneli ise 2000'li yıllar olarak kabul et
        if ($year < 100) {
            $year += 2000;
        }
        
        // Geçerli bir tarih olup olmadığını kontrol et
        if (!checkdate($month, $day, $year)) {
            return '';
        }
        
        // MySQL formatında dön
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

/**
 * İsim ayırma fonksiyonu - eğer tanımlanmamışsa
 */
if (!function_exists('parse_name')) {
    function parse_name($full_name) {
        $full_name = trim($full_name);
        
        if (empty($full_name)) {
            return ['', ''];
        }
        
        // İsim parçalarını boşluk karakterine göre ayır
        $name_parts = explode(' ', $full_name);
        
        // Tek kelime varsa, bu isimdir
        if (count($name_parts) == 1) {
            return [$name_parts[0], ''];
        } 
        // İki kelime varsa, ilk kelime isim, ikinci kelime soyisimdir
        else if (count($name_parts) == 2) {
            return [$name_parts[0], $name_parts[1]];
        }
        // İkiden fazla kelime varsa, son kelime soyisim, diğerleri isimdir
        else {
            $last_name = array_pop($name_parts);
            $first_name = implode(' ', $name_parts);
            return [$first_name, $last_name];
        }
    }
}

/**
 * Facebook ADS CSV başlıklarını temizler ve düzenler
 * 
 * @param array $headers Orijinal başlıklar
 * @return array Temizlenmiş başlıklar
 */
function clean_facebook_ads_headers($headers) {
    $cleaned_headers = [];
    
    foreach ($headers as $index => $header) {
        // BOM karakterleri ve diğer özel karakterleri temizle
        $header = trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header));
        
        // Boş alanları düzeltme
        if (empty($header)) {
            $header = 'Sütun ' . ($index + 1);
        }
        
        // Varsa başlık içindeki boşlukları düzeltme (e-mail_verified gibi BOM ile beraber olan başlıklar)
        $header = trim(preg_replace('/\s+/', ' ', $header));
        
        $cleaned_headers[$index] = $header;
    }
    
    return $cleaned_headers;
}

/**
 * Facebook ADS CSV dosya formatını algılar
 * 
 * @param array $headers CSV başlıkları
 * @return bool CSV'nin Facebook ADS formatında olup olmadığı
 */
function is_facebook_ads_csv_format($headers) {
    // Facebook ADS CSV için gereken alanlar
    $fb_required_fields = ['id', 'created_time', 'ad_id', 'ad_name', 'campaign_id', 'campaign_name'];
    $match_count = 0;
    
    // Eğer tek bir başlık varsa ve içinde birden fazla alan birleştirilmişse
    if (count($headers) === 1) {
        $header = implode(' ', $headers);
        // Birleştirilmiş başlık içinde gereken alanları ara
        foreach ($fb_required_fields as $field) {
            if (stripos($header, $field) !== false) {
                $match_count++;
            }
        }
        // En az 3 eşleşme varsa Facebook formatı olarak kabul et
        return ($match_count >= 3);
    }
    
    // Normal sütun başlıkları için kontrol
    foreach ($fb_required_fields as $field) {
        foreach ($headers as $header) {
            // BOM karakterleri ve boşlukları temizle
            $header = trim(preg_replace('/[\x00-\x1F\x80-\xFF\s]/', '', $header));
            if (stripos($header, $field) !== false) {
                $match_count++;
                break;
            }
        }
    }
    
    // En az 3 eşleşme varsa Facebook formatı olarak kabul et
    return ($match_count >= 3);
}

/**
 * Facebook ADS CSV dosyasını yeniden düzenle ve başlıkları normalize et
 * 
 * @param string $file_path CSV dosyasının yolu
 * @return array Düzenlenmiş başlıklar ve içerik
 */
function normalize_facebook_ads_csv($file_path) {
    // Dosyayı oku
    $content = file_get_contents($file_path);
    
    // BOM karakterlerini temizle
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    
    // CSV dosyasının içeriğindeki özel karakterleri temizle (ancak düzeni koruyacak şekilde)
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', '', $content);
    
    // Satırları ayır
    $lines = explode("\n", $content);
    $headers = [];
    $data = [];
    
    if (count($lines) > 0) {
        // Başlık satırını al
        $header_line = trim($lines[0]);
        
        // Facebook ADS CSV formatını tespit et (sabit genişlikli veya virgülle ayrılmış)
        $delimiter = ',';
        $is_fixed_width = false;
        
        // Sınırlayıcıları kontrol et (virgül, tab, noktalı virgül)
        if (strpos($header_line, ',') !== false) {
            $delimiter = ',';
        } elseif (strpos($header_line, ';') !== false) {
            $delimiter = ';';
        } elseif (strpos($header_line, "\t") !== false) {
            $delimiter = "\t";
        } else {
            // Hiçbir bilinen sınırlayıcı bulunamadıysa, sabit genişlikli olabilir
            $is_fixed_width = true;
        }
        
        if ($is_fixed_width) {
            // Sabit genişlikli format için başlık satırını işle
            // Başlığı boşluk gruplarına göre ayır
            $headers = preg_split('/\s{2,}/', $header_line, -1, PREG_SPLIT_NO_EMPTY);
            $headers = array_map('trim', $headers);
            
            // Verileri satır satır işle
            for ($i = 1; $i < count($lines); $i++) {
                if (empty(trim($lines[$i]))) continue;
                
                $line = $lines[$i];
                $row = [];
                
                // Her bir satır için, başlık pozisyonlarına göre veri parçalarını çıkar
                $position = 0;
                foreach ($headers as $header) {
                    $field_length = strlen($header) + 2; // Başlık uzunluğu + boşluklar
                    $field_value = trim(substr($line, $position, $field_length));
                    $row[] = $field_value;
                    $position += $field_length;
                }
                
                // Son alanı al (kalan tüm içerik)
                if ($position < strlen($line)) {
                    $row[] = trim(substr($line, $position));
                }
                
                // Eksik alanları doldur
                $row = array_pad($row, count($headers), '');
                $data[] = $row;
            }
        } else {
            // Normal sınırlayıcı ile ayrılmış format
            // Başlıkları al
            $headers = str_getcsv($header_line, $delimiter);
            $headers = array_map('trim', $headers);
            
            // Veri satırlarını işle
            for ($i = 1; $i < count($lines); $i++) {
                if (empty(trim($lines[$i]))) continue;
                
                $row = str_getcsv($lines[$i], $delimiter);
                // Eksik alanları doldur
                $row = array_pad($row, count($headers), '');
                $data[] = $row;
            }
        }
        
        // Eğer herhangi bir yöntemle başlık alanları elde edilemezse, manuel olarak oluştur
        if (empty($headers)) {
            // Facebook ADS için temel başlıklar
            $headers = array(
                'id', 'created_time', 'ad_id', 'ad_name', 'adset_id', 'adset_name', 
                'campaign_id', 'campaign_name', 'form_id', 'form_name', 'is_organic',
                'platform', 'phone', 'email', 'full_name'
            );
            
            // Satırları boşluklara göre parçalayarak yeniden işle
            for ($i = 0; $i < count($lines); $i++) {
                if (empty(trim($lines[$i]))) continue;
                
                $row_text = $lines[$i];
                
                // İlk boşluk gruplarına göre ayır
                $row = preg_split('/\s{2,}/', $row_text, -1, PREG_SPLIT_NO_EMPTY);
                $row = array_map('trim', $row);
                
                // Eksik alanları doldur
                $row = array_pad($row, count($headers), '');
                $data[] = $row;
            }
        }
    }
    
    // Geçici bir CSV dosyası oluştur (standart virgülle ayrılmış)
    $temp_file = tempnam(sys_get_temp_dir(), 'fb_csv');
    $handle = fopen($temp_file, 'w');
    
    // Başlıkları yaz
    fputcsv($handle, $headers, ',');
    
    // Verileri yaz
    foreach ($data as $row) {
        // Üç kontrol:
        // 1. Başlık sayısına göre satır uzunluğunu kontrol et
        $row = array_slice($row, 0, count($headers));
        
        // 2. Eksik alanları doldur
        $row = array_pad($row, count($headers), '');
        
        // 3. Boş satırları atla
        if (empty(array_filter($row))) {
            continue;
        }
        
        fputcsv($handle, $row, ',');
    }
    
    fclose($handle);
    
    // Sonuç olarak başlıkları, geçici dosya yolunu ve yeni ayırıcıyı dön
    return [
        'headers' => $headers,
        'temp_file' => $temp_file,
        'delimiter' => ','
    ];
}

/**
 * CSV dosyasını okur ve manuel eşleştirme için sütunları getirir (Facebook desteği)
 *
 * @param string $file_path CSV dosyasının geçici yolu
 * @param bool $is_facebook Facebook ADS CSV formatı mı
 * @return array CSV başlıkları ve örnek veriler
 */
function read_csv_headers_facebook($file_path, $is_facebook = false) {
    if ($is_facebook) {
        // Facebook ADS CSV için özel normalleştirme yap
        $normalized_csv = normalize_facebook_ads_csv($file_path);
        $temp_file = $normalized_csv['temp_file'];
        $headers = $normalized_csv['headers'];
        $delimiter = $normalized_csv['delimiter'];
        
        // Dosyayı aç
        $file = fopen($temp_file, 'r');
        if (!$file) {
            throw new Exception('Normalleştirilmiş CSV dosyası açılamadı.');
        }
        
        // Başlık satırını atla (zaten aldık)
        fgetcsv($file, 0, $delimiter);
        
        // Örnek veri satırları için ilk 5 satırı oku
        $sample_data = [];
        $rows_read = 0;
        
        while (($row = fgetcsv($file, 0, $delimiter)) !== false && $rows_read < 5) {
            // Eksik alanları null ile doldur
            $row = array_pad($row, count($headers), null);
            $sample_data[] = $row;
            $rows_read++;
        }
        
        fclose($file);
        
        // Geçici dosyayı temizleme (işlem tamamlandığında temizlenecek)
        
        return [
            'headers' => $headers,
            'sample_data' => $sample_data,
            'delimiter' => $delimiter,
            'temp_file' => $temp_file
        ];
    }
    
    // Normal CSV işleme - separate function allows original to run
    return [
        'headers' => [],
        'sample_data' => [],
        'delimiter' => ','
    ];
}

/**
 * Facebook ADS CSV için otomatik sütun eşleştirme önerileri
 * 
 * @param array $headers CSV başlıkları
 * @return array Eşleştirme önerileri
 */
function suggest_facebook_ads_mapping($headers) {
    $mapping = [];
    $mapping_rules = [
        'ad' => ['first_name', 'firstname', 'ad', 'isim', 'full_name', 'fullname', 'name', 'adı', 'müşteri_adı'],
        'soyad' => ['last_name', 'lastname', 'soyad', 'soyisim', 'soyadı'],
        'telefon' => ['phone', 'phone_number', 'phonenumber', 'telefon', 'tel', 'gsm', 'mobile', 'cep', 'ceptelefonu'],
        'email' => ['email', 'mail', 'e-mail', 'e_mail', 'eposta', 'e-posta'],
        'tc_kimlik' => ['tc_kimlik', 'tckimlik', 'tcno', 'tc_no', 'identity', 'kimlik'],
        'adres' => ['address', 'adres', 'street', 'city', 'sehir', 'il', 'ilce'],
        'dogum_tarih' => ['birth_date', 'birthdate', 'dogum', 'dogumtarihi', 'doğumtarihi', 'dateofbirth', 'birthday'],
        'yorum' => ['note', 'notes', 'comment', 'comments', 'yorum', 'açıklama', 'aciklama', 'mesaj', 'message'],
        'created_time' => ['created_time', 'createdtime', 'created_at', 'createdat', 'creation_date', 'creationdate', 'tarih', 'date'],
        'campaign_name' => ['campaign_name', 'campaignname', 'kampanya_adı', 'kampanyaadi', 'campaign'],
    ];
    
    // Her başlık için eşleştirebileceğimiz bir alan bulma
    foreach ($headers as $index => $header) {
        $header = strtolower(trim(preg_replace('/\s+/', '', $header)));
        
        foreach ($mapping_rules as $db_field => $possible_matches) {
            foreach ($possible_matches as $match) {
                if ($header === $match || strpos($header, $match) !== false) {
                    $mapping[$db_field] = $index;
                    break 2;
                }
            }
        }
    }
    
    // Tam isim (full name) alanını özellikle kontrol et
    foreach ($headers as $index => $header) {
        $header = strtolower(trim(preg_replace('/\s+/', '', $header)));
        if (strpos($header, 'full') !== false && strpos($header, 'name') !== false) {
            // Tam isim alanı varsa, ad alanına atayalım
            $mapping['ad'] = $index;
            // Soyad alanını temizleyelim çünkü tam isim zaten ad alanında olacak
            if (isset($mapping['soyad'])) {
                unset($mapping['soyad']);
            }
        }
    }
    
    return $mapping;
}

/**
 * Facebook ADS verilerini müşteriler için işler ve temsilci ataması yapar
 *
 * @param array $row CSV satırı
 * @param array $column_mapping Sütun eşleştirme
 * @param int $representative_id Atanacak temsilci ID'si
 * @return array İşlenmiş müşteri verisi
 */
function prepare_facebook_leads_customer_data($row, $column_mapping, $representative_id) {
    try {
        // Temel müşteri bilgilerini çıkar
        $first_name = isset($column_mapping['ad']) && isset($row[$column_mapping['ad']]) ? trim($row[$column_mapping['ad']]) : '';
        $last_name = isset($column_mapping['soyad']) && isset($row[$column_mapping['soyad']]) ? trim($row[$column_mapping['soyad']]) : '';
        $phone = isset($column_mapping['telefon']) && isset($row[$column_mapping['telefon']]) ? trim($row[$column_mapping['telefon']]) : '';
        $email = isset($column_mapping['email']) && isset($row[$column_mapping['email']]) ? trim($row[$column_mapping['email']]) : '';
        $tc_identity = isset($column_mapping['tc_kimlik']) && isset($row[$column_mapping['tc_kimlik']]) ? trim($row[$column_mapping['tc_kimlik']]) : '';
        $address = isset($column_mapping['adres']) && isset($row[$column_mapping['adres']]) ? trim($row[$column_mapping['adres']]) : '';
        $birth_date = isset($column_mapping['dogum_tarih']) && isset($row[$column_mapping['dogum_tarih']]) ? trim($row[$column_mapping['dogum_tarih']]) : '';
        $note = isset($column_mapping['yorum']) && isset($row[$column_mapping['yorum']]) ? trim($row[$column_mapping['yorum']]) : '';
        
        // Campaign name bilgisi
        $campaign_name = '';
        if (isset($column_mapping['campaign_name']) && isset($row[$column_mapping['campaign_name']])) {
            $campaign_name = trim($row[$column_mapping['campaign_name']]);
        } elseif (isset($column_mapping['ad_name']) && isset($row[$column_mapping['ad_name']])) {
            // Eğer kampanya adı yoksa, reklam adını kullan
            $campaign_name = trim($row[$column_mapping['ad_name']]);
        }
        
        // Oluşturulma tarihi
        $created_time = isset($column_mapping['created_time']) && isset($row[$column_mapping['created_time']]) ? 
            trim($row[$column_mapping['created_time']]) : current_time('mysql');
        
        // Facebook bilgilerini nota ekle
        $facebook_note = "Facebook ADS kaydı\n";
        if (!empty($campaign_name)) {
            $facebook_note .= "Kampanya: " . $campaign_name . "\n";
        }
        $facebook_note .= "Tarih: " . $created_time;
        
        if (!empty($note)) {
            $note .= "\n\n" . $facebook_note;
        } else {
            $note = $facebook_note;
        }
        
        // Ad ve soyadı ayırma veya birleştirme işlemi
        if (!empty($first_name) && empty($last_name)) {
            // İsim alanı dolu, soyisim alanı boş ise ad/soyad ayır
            $name_parts = parse_name($first_name);
            $first_name = $name_parts[0];
            $last_name = $name_parts[1];
        }
        
        // Telefon numarası temizleme
        if (!empty($phone)) {
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            
            // Türkiye telefon numarası formatı kontrolü ve düzeltme
            if (strlen($phone) == 10 && $phone[0] == '5') {
                $phone = '+90' . $phone;
            } elseif (strlen($phone) == 11 && $phone[0] == '0' && $phone[1] == '5') {
                $phone = '+9' . $phone;
            }
        }
        
        // Doğum tarihi düzenleme
        if (!empty($birth_date) && function_exists('format_date_for_db')) {
            $birth_date = format_date_for_db($birth_date);
        }
        
        // Benzersiz müşteri anahtarı oluştur (e-posta veya telefon bazlı)
        $customer_key = md5(($email ?: '') . ($phone ?: '') . $first_name . $last_name);
        
        return [
            'first_name' => $first_name ?: 'İsimsiz',
            'last_name' => $last_name ?: 'Belirsiz',
            'phone' => $phone ?: '',
            'email' => $email ?: '',
            'tc_kimlik' => $tc_identity ?: '',
            'address' => $address ?: '',
            'birth_date' => $birth_date,
            'note' => $note,
            'campaign_name' => $campaign_name,
            'representative_id' => $representative_id,
            'customer_key' => $customer_key,
            'source' => 'facebook_ads',
            'created_time' => $created_time
        ];
    } catch (Exception $e) {
        if (function_exists('facebook_ads_log')) {
            facebook_ads_log("Müşteri verisi hazırlanırken hata oluştu: " . $e->getMessage());
        }
        throw $e;
    }
}

/**
 * Facebook ADS CSV verilerini işler
 *
 * @param string $file_path CSV dosyasının geçici yolu
 * @param array $column_mapping Manuel sütun eşleştirme bilgileri
 * @param int $current_user_rep_id Mevcut kullanıcının temsilci ID'si
 * @param wpdb $wpdb WordPress veritabanı nesnesi
 * @param array $customer_representatives Müşteri temsilcisi atamaları
 * @return array İşlenmiş veriler
 */
function process_facebook_ads_csv($file_path, $column_mapping, $current_user_rep_id, $wpdb, $customer_representatives = array()) {
    // Hata günlüğü fonksiyonunu kontrol et ve yükle
    if (!function_exists('facebook_ads_log')) {
        require_once(dirname(__FILE__) . '/error_log.php');
    }
    
    try {
        facebook_ads_log("Facebook ADS CSV işleme başladı: $file_path");
        
        // Debug bilgilerini tutan dizi
        $debug_info = array(
            'total_records' => 0,
            'processed_records' => 0,
            'matched_customers' => 0,
            'failed_matches' => 0,
            'last_error' => '',
            'process_start' => date('Y-m-d H:i:s'),
        );

        $customers_table = $wpdb->prefix . 'insurance_crm_customers';
        
        // Facebook CSV formatını normalleştir
        $normalized_csv = normalize_facebook_ads_csv($file_path);
        $temp_file = $normalized_csv['temp_file'];
        $original_headers = $normalized_csv['headers'];
        $delimiter = $normalized_csv['delimiter'];
        
        // Dosyayı oku
        $file = fopen($temp_file, 'r');
        if (!$file) {
            throw new Exception('CSV dosyası açılamadı.');
        }

        // İlk satırı header olarak al ve atla
        $header = fgetcsv($file, 0, $delimiter);
        
        // Veri toplama yapıları
        $preview_data = array(
            'policies' => array(),
            'customers' => array(),
            'debug' => $debug_info,
            'column_mapping' => $column_mapping,
            'original_headers' => $original_headers
        );
        
        $processed_records = 0;
        $customer_rows = array();
        
        // CSV içeriğini oku
        while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
            // Boş satırları atla
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Her bir satırı diziye sakla
            $customer_rows[] = $row;
        }
        
        fclose($file);
        
        // Her bir müşteri kaydını işle
        foreach ($customer_rows as $index => $row) {
            try {
                // Eksik alanları null ile doldur
                $row = array_pad($row, count($header), null);
                
                // Eğer bu satır için özel temsilci ataması varsa
                $assigned_rep_id = isset($customer_representatives[$index]) && !empty($customer_representatives[$index]) 
                                ? $customer_representatives[$index] 
                                : $current_user_rep_id;
                
                // Müşteri bilgilerini hazırla
                $customer_data = prepare_facebook_leads_customer_data($row, $column_mapping, $assigned_rep_id);
                
                if ($customer_data) {
                    $customer_key = $customer_data['customer_key'];
                    
                    // TC kimlik, telefon veya e-posta ile müşteriyi kontrol et
                    $customer_query_parts = array();
                    $customer_query_values = array();
                    
                    if (!empty($customer_data['tc_kimlik'])) {
                        $customer_query_parts[] = "tc_identity = %s";
                        $customer_query_values[] = $customer_data['tc_kimlik'];
                    }
                    
                    if (!empty($customer_data['phone'])) {
                        $customer_query_parts[] = "phone = %s";
                        $customer_query_values[] = $customer_data['phone'];
                    }
                    
                    if (!empty($customer_data['email'])) {
                        $customer_query_parts[] = "email = %s";
                        $customer_query_values[] = $customer_data['email'];
                    }
                    
                    $customer_id = null;
                    $customer_status = 'Yeni';
                    $existing_notes = '';
                    
                    if (!empty($customer_query_parts)) {
                        $query = "SELECT id, notes FROM $customers_table WHERE " . implode(' OR ', $customer_query_parts);
                        
                        // Parametreleri doğru şekilde geç
                        if (!empty($customer_query_values)) {
                            $prepared_query = $wpdb->prepare($query, ...$customer_query_values);
                            $existing_customer = $wpdb->get_row($prepared_query);
                        } else {
                            $existing_customer = null;
                        }
                        
                        if ($existing_customer) {
                            $customer_id = $existing_customer->id;
                            $customer_status = 'Mevcut';
                            $existing_notes = $existing_customer->notes;
                            $debug_info['matched_customers']++;
                        }
                    }
                    
                    // Müşteri verisini ön izlemeye ekle
                    $preview_data['customers'][$customer_key] = array(
                        'first_name' => $customer_data['first_name'],
                        'last_name' => $customer_data['last_name'],
                        'email' => $customer_data['email'],
                        'phone' => $customer_data['phone'],
                        'address' => $customer_data['address'],
                        'tc_kimlik' => $customer_data['tc_kimlik'],
                        'birth_date' => $customer_data['birth_date'],
                        'note' => $customer_data['note'],
                        'existing_notes' => $existing_notes,
                        'campaign_name' => $customer_data['campaign_name'],
                        'created_time' => $customer_data['created_time'],
                        'status' => $customer_status,
                        'customer_id' => $customer_id,
                        'representative_id' => $assigned_rep_id,
                        'customer_key' => $customer_key
                    );
                    
                    $processed_records++;
                }
            } catch (Exception $e) {
                // Hatalı satırı atla ve devam et
                $debug_info['last_error'] = $e->getMessage();
                $debug_info['failed_matches']++;
                facebook_ads_log("Satır işlenirken hata: " . $e->getMessage(), $row);
                continue;
            }
        }
        
        // Geçici dosyayı temizle
        if (file_exists($temp_file)) {
            @unlink($temp_file);
        }
        
        // Debug bilgilerini güncelle
        $debug_info['total_records'] = count($customer_rows);
        $debug_info['processed_records'] = $processed_records;
        $preview_data['debug'] = $debug_info;
        
        facebook_ads_log("Facebook ADS CSV işleme tamamlandı: {$processed_records} kayıt işlendi");
        return $preview_data;
    } catch (Exception $e) {
        facebook_ads_log("Facebook ADS CSV işleme hatası: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Facebook ADS müşterileri için otomatik görev oluşturma fonksiyonu
 * 
 * @param int $customer_id Müşteri ID'si
 * @param int $representative_id Temsilci ID'si
 * @param string $import_time İçe aktarım zamanı
 * @return int|bool Oluşturulan görev ID'si veya başarısız ise false
 */
function create_facebook_lead_task($customer_id, $representative_id, $import_time = '') {
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'insurance_crm_tasks';
    
    if (function_exists('facebook_ads_log')) {
        facebook_ads_log("Görev oluşturma başladı: Müşteri ID = {$customer_id}, Temsilci ID = {$representative_id}");
    }
    
    // İçe aktarım zamanı belirtilmemişse şu anki zamanı kullan
    if (empty($import_time)) {
        $import_time = current_time('mysql');
    }
    
    // Son tarih - içe aktarım tarihinden 1 gün sonra saat 18:00
    $due_date = new DateTime($import_time);
    $due_date->modify('+1 day');
    $due_date->setTime(18, 0, 0);
    $due_date_formatted = $due_date->format('Y-m-d H:i:s');
    
    // Görev verilerini hazırla
    $task_data = array(
        'task_title' => 'Facebook Müşterisi Aranacak',
        'task_description' => 'Müşteri arama bekliyor.',
        'customer_id' => $customer_id,
        'policy_id' => null,
        'due_date' => $due_date_formatted,
        'priority' => 'medium',
        'status' => 'pending',
        'representative_id' => $representative_id,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    try {
        // Görevi veritabanına ekle
        $result = $wpdb->insert($tasks_table, $task_data);
        
        if ($result) {
            $task_id = $wpdb->insert_id;
            if (function_exists('facebook_ads_log')) {
                facebook_ads_log("Görev başarıyla oluşturuldu: ID = {$task_id}");
            }
            return $task_id;
        } else {
            if (function_exists('facebook_ads_log')) {
                facebook_ads_log("Görev oluşturma hatası: " . $wpdb->last_error);
            }
            return false;
        }
    } catch (Exception $e) {
        if (function_exists('facebook_ads_log')) {
            facebook_ads_log("Görev oluşturma exception: " . $e->getMessage());
        }
        return false;
    }
}