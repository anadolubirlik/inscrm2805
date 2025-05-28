<?php
/**
 * Panel renklerini ayarlayan ortak kod parçası
 * @version 1.1.0
 */

// Kullanıcının renk ayarlarını al
$current_user = wp_get_current_user();
$personal_color = get_user_meta($current_user->ID, 'crm_personal_color', true) ?: '#3498db';
$corporate_color = get_user_meta($current_user->ID, 'crm_corporate_color', true) ?: '#4caf50';
$family_color = get_user_meta($current_user->ID, 'crm_family_color', true) ?: '#ff9800';
$vehicle_color = get_user_meta($current_user->ID, 'crm_vehicle_color', true) ?: '#e74c3c';
$home_color = get_user_meta($current_user->ID, 'crm_home_color', true) ?: '#9c27b0';

// Renk opaklığını ayarla (panel arka planları için)
function adjust_color_opacity($hex, $opacity) {
    // Hex kodunu RGB'ye dönüştür
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    
    // RGBA renk kodu döndür
    return "rgba($r, $g, $b, $opacity)";
}

// Paneller için CSS oluştur
$panel_css = "
<style>
/* Panel Renkleri - Kullanıcı Ayarları */
.panel-personal {
    border-left: 3px solid {$personal_color} !important;
    background-color: " . adjust_color_opacity($personal_color, 0.05) . " !important;
}
.panel-personal .ab-panel-header {
    background-color: " . adjust_color_opacity($personal_color, 0.1) . " !important;
}

.panel-corporate {
    border-left: 3px solid {$corporate_color} !important;
    background-color: " . adjust_color_opacity($corporate_color, 0.05) . " !important;
}
.panel-corporate .ab-panel-header {
    background-color: " . adjust_color_opacity($corporate_color, 0.1) . " !important;
}

.panel-family {
    border-left: 3px solid {$family_color} !important;
    background-color: " . adjust_color_opacity($family_color, 0.05) . " !important;
}
.panel-family .ab-panel-header {
    background-color: " . adjust_color_opacity($family_color, 0.1) . " !important;
}

.panel-vehicle {
    border-left: 3px solid {$vehicle_color} !important;
    background-color: " . adjust_color_opacity($vehicle_color, 0.05) . " !important;
}
.panel-vehicle .ab-panel-header {
    background-color: " . adjust_color_opacity($vehicle_color, 0.1) . " !important;
}

.panel-home {
    border-left: 3px solid {$home_color} !important;
    background-color: " . adjust_color_opacity($home_color, 0.05) . " !important;
}
.panel-home .ab-panel-header {
    background-color: " . adjust_color_opacity($home_color, 0.1) . " !important;
}

/* Butonlar için renkler */
.ab-btn-primary {
    background-color: {$corporate_color} !important;
    border-color: " . adjust_color_opacity($corporate_color, 0.8) . " !important;
}
.ab-btn-primary:hover {
    background-color: " . adjust_color_opacity($corporate_color, 0.9) . " !important;
}

.ab-btn-success {
    background-color: {$family_color} !important;
    border-color: " . adjust_color_opacity($family_color, 0.8) . " !important;
    color: white !important;
}
.ab-btn-success:hover {
    background-color: " . adjust_color_opacity($family_color, 0.9) . " !important;
}

.ab-pagination .page-numbers.current {
    background-color: {$corporate_color};
    border-color: " . adjust_color_opacity($corporate_color, 0.8) . ";
}

/* Badge stilleri */
.ab-badge-status-aktif {
    background-color: " . adjust_color_opacity($corporate_color, 0.2) . ";
    color: " . adjust_color_opacity($corporate_color, 0.9) . ";
}

/* Tablo başlık stilleri */
.ab-crm-table th {
    border-bottom: 2px solid " . adjust_color_opacity($corporate_color, 0.2) . ";
}

/* Hover stilleri */
.ab-policy-number:hover,
.ab-task-description:hover,
.ab-info-value a:hover {
    color: {$corporate_color};
}

/* Panel stilleri iyileştirmeleri */
.ab-panel {
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    transition: box-shadow 0.3s ease;
}
.ab-panel:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

/* Form Kartı Stilleri */
.ab-form-card.panel-corporate {
    border-left: 3px solid {$corporate_color};
}
.ab-form-card.panel-corporate .ab-form-section h3 {
    color: " . adjust_color_opacity($corporate_color, 0.9) . ";
}

.ab-form-card.panel-family {
    border-left: 3px solid {$family_color};
}
.ab-form-card.panel-family .ab-form-section h3 {
    color: " . adjust_color_opacity($family_color, 0.9) . ";
}

/* Input odak stilleri */
.ab-input:focus, .ab-select:focus, .ab-textarea:focus {
    border-color: {$corporate_color};
    box-shadow: 0 0 0 3px " . adjust_color_opacity($corporate_color, 0.2) . ";
    outline: none;
}

/* İyileştirilmiş görsel stillemeler */
.ab-actions a:hover {
    background-color: " . adjust_color_opacity($corporate_color, 0.1) . ";
}

.ab-related-link {
    transition: all 0.3s ease;
}
.ab-related-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* Özel ikon renkleri */
.customer-icon {
    background-color: " . adjust_color_opacity($personal_color, 0.2) . ";
    color: {$personal_color};
}

.policy-icon {
    background-color: " . adjust_color_opacity($corporate_color, 0.2) . ";
    color: {$corporate_color};
}

.task-icon {
    background-color: " . adjust_color_opacity($family_color, 0.2) . ";
    color: {$family_color};
}
</style>
";

// CSS'i sayfanın başına ekle
echo $panel_css;
?>