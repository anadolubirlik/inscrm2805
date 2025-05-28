<?php
/**
 * CSV İçe Aktarma İşlevleri
 * @version 1.3.0
 * @date 2025-05-27 10:15:36
 */

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed
}

/**
 * CSV dosyasını okur ve manuel eşleştirme için sütunları getirir
 *
 * @param string $file_path CSV dosyasının geçici yolu
 * @return array CSV başlıkları ve örnek veriler
 */
function read_csv_headers($file_path) {
    $content = file_get_contents($file_path);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    
    $delimiter = ',';
    
    // Hangi ayırıcının kullanıldığını tespit et
    if (strpos($content, ';') !== false) {
        $delimiter = ';';
    } elseif (strpos($content, "\t") !== false) {
        $delimiter = "\t";
    }
    
    $file = fopen($file_path, 'r');
    if (!$file) {
        throw new Exception('CSV dosyası açılamadı.');
    }
    
    $headers = fgetcsv($file, 0, $delimiter);
    if (!$headers) {
        throw new Exception('CSV başlıklarını okuma hatası.');
    }
    
    $sample_data = [];
    $rows_read = 0;
    
    while (($row = fgetcsv($file, 0, $delimiter)) !== false && $rows_read < 5) {
        $row = array_pad($row, count($headers), null);
        $sample_data[] = $row;
        $rows_read++;
    }
    
    fclose($file);
    
    return [
        'headers' => $headers,
        'sample_data' => $sample_data,
        'delimiter' => $delimiter
    ];
}

/**
 * Eşleştirme bilgileriyle CSV dosyasını işler
 *
 * @param string $file_path CSV dosyasının geçici yolu
 * @param array $column_mapping Sütun eşleştirmeleri
 * @param int $current_user_rep_id Mevcut kullanıcının temsilci ID'si
 * @param wpdb $wpdb WordPress database object
 * @return array İşlenmiş CSV verileri
 */
function process_csv_file_with_mapping($file_path, $column_mapping, $current_user_rep_id, $wpdb) {
    $customers_table = $wpdb->prefix . 'insurance_crm_customers';
    $representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
    $users_table = $wpdb->users;
    
    $file = fopen($file_path, 'r');
    if (!$file) {
        throw new Exception('CSV dosyası açılamadı.');
    }
    
    $delimiter = isset($column_mapping['delimiter']) ? $column_mapping['delimiter'] : ',';
    
    // İlk satırı başlık olarak al ve atla
    fgetcsv($file, 0, $delimiter);
    
    $data = [];
    $processed_policies = [];
    $customers = [];
    
    // Debug bilgisi
    $debug_info = array(
        'total_policies' => 0,
        'processed_policies' => 0,
        'matched_customers' => 0,
        'failed_matches' => 0,
        'process_start' => date('Y-m-d H:i:s'),
        'last_error' => ''
    );
    
    while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
        try {
            // Eksik sütunları boş değerlerle doldur
            $max_index = max(array_values($column_mapping));
            $row = array_pad($row, $max_index + 1, '');
            
            // Temel müşteri bilgilerini al
            $first_name = isset($column_mapping['ad']) ? $row[$column_mapping['ad']] : '';
            $last_name = isset($column_mapping['soyad']) ? $row[$column_mapping['soyad']] : '';
            
            // Eğer müşteri soyadı alanı eşleştirilmemişse, isim alanını ad ve soyad olarak böl
            if (empty($last_name) && !empty($first_name)) {
                $name_parts = explode(' ', $first_name, 2);
                if (count($name_parts) > 1) {
                    $first_name = $name_parts[0];
                    $last_name = $name_parts[1];
                }
            }
            
            // Diğer müşteri verilerini al
            $phone = isset($column_mapping['telefon']) ? sanitize_text_field($row[$column_mapping['telefon']]) : '';
            $tc_identity = isset($column_mapping['tc_kimlik']) ? sanitize_text_field($row[$column_mapping['tc_kimlik']]) : '';
            $address = isset($column_mapping['adres']) ? sanitize_text_field($row[$column_mapping['adres']]) : '';
            $birth_date = isset($column_mapping['dogum_tarih']) ? sanitize_text_field($row[$column_mapping['dogum_tarih']]) : '';
            
            // Doğum tarihini düzenle
            if (!empty($birth_date)) {
                if (function_exists('format_date_for_db')) {
                    $birth_date = format_date_for_db($birth_date);
                } else {
                    // Nokta veya eğik çizgi ile ayrılmış formatı MySQL formatına çevir
                    if (preg_match('/^\d{2}[.\/]\d{2}[.\/]\d{4}$/', $birth_date)) {
                        $date_parts = preg_split('/[.\/]/', $birth_date);
                        $birth_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
                    }
                }
            }
            
            // Poliçe verilerini al
            $policy_number = isset($column_mapping['police_no']) ? sanitize_text_field($row[$column_mapping['police_no']]) : '';
            $policy_type = isset($column_mapping['police_turu']) ? sanitize_text_field($row[$column_mapping['police_turu']]) : '';
            $insurance_company = isset($column_mapping['sigorta_sirketi']) ? sanitize_text_field($row[$column_mapping['sigorta_sirketi']]) : '';
            
            // YENİ: Poliçe kategorisi (Yeni İş/Yenileme)
            $policy_category = isset($column_mapping['policy_category']) ? sanitize_text_field($row[$column_mapping['policy_category']]) : 'Yeni İş';
            
            // YENİ: Network bilgisi
            $network = isset($column_mapping['network']) ? sanitize_text_field($row[$column_mapping['network']]) : '';
            
            // YENİ: Durum bilgisi notu
            $status_note = isset($column_mapping['status_note']) ? sanitize_text_field($row[$column_mapping['status_note']]) : '';
            
            // YENİ: Ödeme bilgisi
            $payment_info = isset($column_mapping['payment_info']) ? sanitize_text_field($row[$column_mapping['payment_info']]) : '';
            
            // YENİ: Temsilci bilgisi - CSV'den gelen veriyi kullan
            $representative_id = isset($column_mapping['representative_id']) ? sanitize_text_field($row[$column_mapping['representative_id']]) : '';
            
            // Eğer temsilci boşsa veya hatalıysa mevcut kullanıcıyı kullan
            if (empty($representative_id)) {
                $representative_id = $current_user_rep_id;
            } else {
                // Temsilci bilgisi varsa, bu ID veya adı veritabanında ara
                if (is_numeric($representative_id)) {
                    // Direkt ID ise kontrol et
                    $rep_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $representatives_table WHERE id = %d", 
                        intval($representative_id)
                    ));
                    
                    if (!$rep_exists) {
                        $representative_id = $current_user_rep_id;
                    }
                } else {
                    // Ad/soyad olabilir, isimden ID'yi bul
                    $rep = $wpdb->get_row($wpdb->prepare(
                        "SELECT r.id FROM $representatives_table r 
                        LEFT JOIN $users_table u ON r.user_id = u.ID 
                        WHERE u.display_name LIKE %s OR u.user_login LIKE %s LIMIT 1", 
                        '%' . $wpdb->esc_like($representative_id) . '%',
                        '%' . $wpdb->esc_like($representative_id) . '%'
                    ));
                    
                    if ($rep) {
                        $representative_id = $rep->id;
                    } else {
                        $representative_id = $current_user_rep_id;
                    }
                }
            }
            
            // Tarihleri al
            $start_date = isset($column_mapping['baslangic_tarih']) ? sanitize_text_field($row[$column_mapping['baslangic_tarih']]) : '';
            $end_date = isset($column_mapping['bitis_tarih']) ? sanitize_text_field($row[$column_mapping['bitis_tarih']]) : '';
            
            // Tarihleri düzenle
            if (!empty($start_date)) {
                if (function_exists('format_date_for_db')) {
                    $start_date = format_date_for_db($start_date);
                } else {
                    if (preg_match('/^\d{2}[.\/]\d{2}[.\/]\d{4}$/', $start_date)) {
                        $date_parts = preg_split('/[.\/]/', $start_date);
                        $start_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
                    }
                }
            } else {
                $start_date = date('Y-m-d');
            }
            
            if (!empty($end_date)) {
                if (function_exists('format_date_for_db')) {
                    $end_date = format_date_for_db($end_date);
                } else {
                    if (preg_match('/^\d{2}[.\/]\d{2}[.\/]\d{4}$/', $end_date)) {
                        $date_parts = preg_split('/[.\/]/', $end_date);
                        $end_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
                    }
                }
            } else {
                $end_date_obj = new DateTime($start_date);
                $end_date_obj->modify('+1 year');
                $end_date = $end_date_obj->format('Y-m-d');
            }
            
            // Diğer poliçe bilgilerini al
            $premium_amount = isset($column_mapping['prim_tutari']) ? str_replace(['.', ','], ['', '.'], $row[$column_mapping['prim_tutari']]) : 0;
            $insured_party = isset($column_mapping['sigorta_ettiren']) ? sanitize_text_field($row[$column_mapping['sigorta_ettiren']]) : '';
            $status = isset($column_mapping['status']) ? strtolower(sanitize_text_field($row[$column_mapping['status']])) : 'aktif';
            
            // Durum değerini standardize et
            if ($status != 'pasif') {
                $status = 'aktif';
            }
            
            // Müşteri ve poliçe anahtarlarını oluştur
            $customer_key = md5($tc_identity . $first_name . $last_name . $phone);
            $policy_key = md5($policy_number . $insurance_company);
            
            // Mevcut müşteriyi kontrol et
            $customer_id = null;
            $customer_status = 'Yeni';
            
            // TC Kimlik, telefon numarası veya ad ve soyad ile müşteriyi ara
            if (!empty($tc_identity) || !empty($phone) || (!empty($first_name) && !empty($last_name))) {
                $query_parts = [];
                $query_values = [];
                
                if (!empty($tc_identity)) {
                    $query_parts[] = "tc_identity = %s";
                    $query_values[] = $tc_identity;
                }
                
                if (!empty($phone)) {
                    $query_parts[] = "phone = %s";
                    $query_values[] = $phone;
                }
                
                if (!empty($first_name) && !empty($last_name)) {
                    $query_parts[] = "(first_name = %s AND last_name = %s)";
                    $query_values[] = $first_name;
                    $query_values[] = $last_name;
                }
                
                if (!empty($query_parts)) {
                    $query = "SELECT id FROM $customers_table WHERE " . implode(' OR ', $query_parts);
                    
                    // Parametreleri doğru şekilde geç
                    $prepared_query = call_user_func_array(
                        array($wpdb, 'prepare'),
                        array_merge(array($query), $query_values)
                    );
                    
                    $customer_id = $wpdb->get_var($prepared_query);
                    
                    if ($customer_id) {
                        $customer_status = 'Mevcut';
                        $debug_info['matched_customers']++;
                    }
                }
            }
            
            // Müşteriyi kaydet
            $customers[$customer_key] = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'tc_kimlik' => $tc_identity,
                'address' => $address,
                'birth_date' => $birth_date,
                'status' => $customer_status,
                'customer_id' => $customer_id,
                'representative_id' => $representative_id,
                'created_at' => $start_date // YENİ: Müşteri kayıt tarihi = Poliçe başlangıç tarihi
            ];
            
            // Poliçeyi kaydet
            $processed_policies[] = [
                'policy_number' => $policy_number,
                'customer_key' => $customer_key,
                'policy_type' => $policy_type,
                'policy_category' => $policy_category, // YENİ: Poliçe kategorisi
                'insurance_company' => $insurance_company,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'premium_amount' => floatval($premium_amount),
                'payment_info' => $payment_info, // YENİ: Ödeme bilgisi
                'network' => $network, // YENİ: Network bilgisi
                'insured_party' => $insured_party,
                'status' => $status,
                'status_note' => $status_note, // YENİ: Durum bilgisi notu
                'representative_id' => $representative_id // YENİ: Temsilci ID'si CSV'den
            ];
            
            $debug_info['processed_policies']++;
        } catch (Exception $e) {
            $debug_info['failed_matches']++;
            $debug_info['last_error'] = $e->getMessage();
            continue;
        }
    }
    
    fclose($file);
    
    // Debug bilgisini güncelle
    $debug_info['total_policies'] = count($processed_policies);
    
    return [
        'policies' => $processed_policies, 
        'customers' => $customers,
        'debug' => $debug_info,
        'column_mapping' => $column_mapping
    ];
}

/**
 * Türkçe karakterleri normalleştirir
 * 
 * @param string $str Normalleştirilecek metin
 * @return string Normalleştirilmiş metin
 */
function normalize_turkish_chars($str) {
    $str = trim($str);
    
    $replacements = array(
        'ı' => 'i', 'İ' => 'I',
        'ğ' => 'g', 'Ğ' => 'G',
        'ü' => 'u', 'Ü' => 'U',
        'ş' => 's', 'Ş' => 'S',
        'ö' => 'o', 'Ö' => 'O',
        'ç' => 'c', 'Ç' => 'C',
        'â' => 'a', 'Â' => 'A',
        'î' => 'i', 'Î' => 'I',
        'û' => 'u', 'Û' => 'U'
    );
    
    return strtr($str, $replacements);
}