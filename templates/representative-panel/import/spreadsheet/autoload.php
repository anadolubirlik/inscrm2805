<?php
/**
 * PhpSpreadsheet Otomatik Yükleme
 * @version 1.0.0
 * @date 2025-05-27 12:53:50
 */

// PhpSpreadsheet'i composer ile yüklediyseniz bu dosya yeterli
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
} else {
    // PhpSpreadsheet'i manuel olarak indirdiyseniz bu kısmı kullanın
    spl_autoload_register(function ($class) {
        // PhpOffice\PhpSpreadsheet alanı
        $prefix = 'PhpOffice\\PhpSpreadsheet\\';
        $base_dir = dirname(__FILE__) . '/PhpSpreadsheet/src/PhpSpreadsheet/';
        $len = strlen($prefix);
        
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });
}

// PhpSpreadsheet'in yüklü olup olmadığını kontrol et
function is_spreadsheet_available() {
    return class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet');
}

// Manuel bir kontrol fonksiyonu - hataları göster
function check_spreadsheet_installation() {
    if (is_spreadsheet_available()) {
        return true;
    } else {
        echo '<div class="notice notice-error">
            <p><strong>Hata:</strong> PhpSpreadsheet kütüphanesi bulunamadı. XLS/XLSX dosyalarını içe aktarmak için PhpSpreadsheet kütüphanesinin kurulu olması gerekiyor.</p>
            <p>Kurulum talimatları: <a href="https://github.com/PHPOffice/PhpSpreadsheet#installation" target="_blank">https://github.com/PHPOffice/PhpSpreadsheet</a></p>
            <p>Alternatif olarak şu komutu çalıştırın: <code>composer require phpoffice/phpspreadsheet</code></p>
        </div>';
        return false;
    }
}