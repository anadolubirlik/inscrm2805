<?php
/**
 * Frontend Poliçe Yönetim Sayfası - Enhanced Premium Version
 * @version 5.1.0 - Soft Delete, Cancellation Reasons, UI Harmonization
 * @date 2025-05-29 12:06:03
 * @author anadolubirlik
 * @description Enhanced premium version with soft delete, advanced cancellation, and unified UI
 */

// Güvenlik kontrolü
if (!defined('ABSPATH') || !is_user_logged_in()) {
    wp_die(__('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'insurance-crm'), __('Erişim Engellendi', 'insurance-crm'), array('response' => 403));
}

// Global değişkenler
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

/**
 * BACKWARD COMPATIBILITY FUNCTIONS - Sadece tanımlı değilse oluştur
 */
if (!function_exists('get_current_user_rep_id')) {
    function get_current_user_rep_id() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $rep_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
        return $rep_id ? intval($rep_id) : 0;
    }
}

if (!function_exists('get_user_role_level')) {
    function get_user_role_level() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $rep = $wpdb->get_row($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
        return $rep ? intval($rep->role) : 5;
    }
}

if (!function_exists('get_role_name')) {
    function get_role_name($role_level) {
        $roles = [1 => 'Patron', 2 => 'Müdür', 3 => 'Müdür Yardımcısı', 4 => 'Ekip Lideri', 5 => 'Müşteri Temsilcisi'];
        return $roles[$role_level] ?? 'Bilinmiyor';
    }
}

if (!function_exists('get_rep_permissions')) {
    function get_rep_permissions() {
        global $wpdb;
        $current_user_id = get_current_user_id();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT policy_edit, policy_delete FROM {$wpdb->prefix}insurance_crm_representatives 
            WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ));
    }
}

if (!function_exists('get_team_members_ids')) {
    function get_team_members_ids($team_leader_user_id) {
        $current_user_rep_id = get_current_user_rep_id();
        $settings = get_option('insurance_crm_settings', []);
        $teams = $settings['teams_settings']['teams'] ?? [];
        
        foreach ($teams as $team) {
            if (($team['leader_id'] ?? 0) == $current_user_rep_id) {
                $members = $team['members'] ?? [];
                return array_unique(array_merge($members, [$current_user_rep_id]));
            }
        }
        
        return [$current_user_rep_id];
    }
}

if (!function_exists('can_edit_policy')) {
    function can_edit_policy($policy_id, $role_level, $user_rep_id) {
        global $wpdb;
        $rep_permissions = get_rep_permissions();
        
        if ($role_level === 1) return true; // Patron
        if ($role_level === 5) { // Müşteri Temsilcisi
            if (!$rep_permissions || $rep_permissions->policy_edit != 1) return false;
            $policy_owner = $wpdb->get_var($wpdb->prepare(
                "SELECT representative_id FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d", 
                $policy_id
            ));
            return $policy_owner == $user_rep_id;
        }
        if ($role_level === 4) { // Ekip Lideri
            if (!$rep_permissions || $rep_permissions->policy_edit != 1) return false;
            $team_members = get_team_members_ids(get_current_user_id());
            $policy_owner = $wpdb->get_var($wpdb->prepare(
                "SELECT representative_id FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d", 
                $policy_id
            ));
            return in_array($policy_owner, $team_members);
        }
        if (($role_level === 2 || $role_level === 3) && $rep_permissions && $rep_permissions->policy_edit == 1) return true;
        return false;
    }
}

if (!function_exists('can_delete_policy')) {
    function can_delete_policy($policy_id, $role_level, $user_rep_id) {
        global $wpdb;
        $rep_permissions = get_rep_permissions();
        
        if ($role_level === 1) return true; // Patron
        if ($role_level === 5) return false; // Müşteri temsilcileri silemez
        if ($role_level === 4) { // Ekip Lideri
            if (!$rep_permissions || $rep_permissions->policy_delete != 1) return false;
            $team_members = get_team_members_ids(get_current_user_id());
            $policy_owner = $wpdb->get_var($wpdb->prepare(
                "SELECT representative_id FROM {$wpdb->prefix}insurance_crm_policies WHERE id = %d", 
                $policy_id
            ));
            return in_array($policy_owner, $team_members);
        }
        if (($role_level === 2 || $role_level === 3) && $rep_permissions && $rep_permissions->policy_delete == 1) return true;
        return false;
    }
}

/**
 * CLASS EXISTENCE CHECK - Duplicate class hatası önlenir
 */
if (!class_exists('ModernPolicyManager')) {
    
    /**
     * Modern Poliçe Yönetim Sınıfı - Enhanced Premium Version
     */
    class ModernPolicyManager {
        private $wpdb;
        private $user_id;
        private $user_rep_id;
        private $user_role_level;
        public $is_team_view;
        private $tables;
        private $show_deleted = false;

        public function __construct() {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->user_id = get_current_user_id();
            
            $this->tables = [
                'policies' => $wpdb->prefix . 'insurance_crm_policies',
                'customers' => $wpdb->prefix . 'insurance_crm_customers',
                'representatives' => $wpdb->prefix . 'insurance_crm_representatives',
                'users' => $wpdb->users
            ];
            
            $this->user_rep_id = $this->getCurrentUserRepId();
            $this->user_role_level = $this->getUserRoleLevel();
            $this->is_team_view = (isset($_GET['view_type']) && $_GET['view_type'] === 'team');
            $this->show_deleted = (isset($_GET['show_deleted']) && $_GET['show_deleted'] === '1');

            $this->initializeDatabase();
            $this->performAutoPassivation();
        }

        /**
         * Silinmiş poliçelerin gösterilme modunu kontrol etmek için public getter
         * Önceki hata: Cannot access private property ModernPolicyManager::$show_deleted
         */
        public function isShowDeletedMode(): bool {
            return $this->show_deleted;
        }

        public function getUserRoleLevel(): int {
            if (empty($this->tables['representatives'])) {
                return 5;
            }
            
            $rep = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT role FROM {$this->tables['representatives']} WHERE user_id = %d AND status = 'active'",
                $this->user_id
            ));
            return $rep ? intval($rep->role) : 5;
        }

        public function getCurrentUserRepId(): int {
            if (empty($this->tables['representatives'])) {
                return 0;
            }
            
            $rep_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->tables['representatives']} WHERE user_id = %d AND status = 'active'",
                $this->user_id
            ));
            return $rep_id ? intval($rep_id) : 0;
        }

        public function getRoleName(int $role_level): string {
            $roles = [1 => 'Patron', 2 => 'Müdür', 3 => 'Müdür Yardımcısı', 4 => 'Ekip Lideri', 5 => 'Müşteri Temsilcisi'];
            return $roles[$role_level] ?? 'Bilinmiyor';
        }

        private function initializeDatabase(): void {
            $columns = [
                // Existing columns
                ['table' => 'policies', 'column' => 'policy_category', 'definition' => "VARCHAR(50) DEFAULT 'Yeni İş'"],
                ['table' => 'policies', 'column' => 'network', 'definition' => 'VARCHAR(255) DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'status_note', 'definition' => 'TEXT DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'payment_info', 'definition' => 'VARCHAR(255) DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'insured_party', 'definition' => 'VARCHAR(255) DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'cancellation_date', 'definition' => 'DATE DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'refunded_amount', 'definition' => 'DECIMAL(10,2) DEFAULT NULL'],
                ['table' => 'customers', 'column' => 'tc_identity', 'definition' => 'VARCHAR(20) DEFAULT NULL'],
                ['table' => 'customers', 'column' => 'birth_date', 'definition' => 'DATE DEFAULT NULL'],
                
                // NEW: Soft Delete and Cancellation Reason columns
                ['table' => 'policies', 'column' => 'is_deleted', 'definition' => 'TINYINT(1) DEFAULT 0'],
                ['table' => 'policies', 'column' => 'deleted_by', 'definition' => 'INT(11) DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'deleted_at', 'definition' => 'DATETIME DEFAULT NULL'],
                ['table' => 'policies', 'column' => 'cancellation_reason', 'definition' => 'VARCHAR(100) DEFAULT NULL']
            ];

            foreach ($columns as $col) {
                if (!isset($this->tables[$col['table']])) continue;
                
                $table_name = $this->tables[$col['table']];
                $exists = $this->wpdb->get_row($this->wpdb->prepare(
                    "SHOW COLUMNS FROM `{$table_name}` LIKE %s", 
                    $col['column']
                ));
                
                if (!$exists) {
                    $this->wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `{$col['column']}` {$col['definition']}");
                }
            }
        }

        private function performAutoPassivation(): void {
            if ($this->user_rep_id === 0) {
                return;
            }
            
            $cache_key = 'auto_passive_check_' . $this->user_id . '_' . date('Y-m-d');
            
            if (get_transient($cache_key)) {
                return;
            }

            $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
            $conditions = ["status = 'aktif'", "cancellation_date IS NULL", "end_date < %s"];
            $params = [$thirty_days_ago];

            if ($this->user_role_level >= 4) {
                $team_ids = $this->getTeamMemberIds();
                if (!empty($team_ids)) {
                    $placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
                    $conditions[] = "representative_id IN ({$placeholders})";
                    $params = array_merge($params, $team_ids);
                } else {
                    $conditions[] = "representative_id = %d";
                    $params[] = $this->user_rep_id;
                }
            }

            $sql = "UPDATE {$this->tables['policies']} SET status = 'pasif' WHERE " . implode(' AND ', $conditions);
            $this->wpdb->query($this->wpdb->prepare($sql, ...$params));
            
            set_transient($cache_key, true, DAY_IN_SECONDS);
        }

        private function getTeamMemberIds(): array {
            if ($this->user_role_level > 4 || $this->user_rep_id === 0) {
                return [$this->user_rep_id];
            }

            $settings = get_option('insurance_crm_settings', []);
            $teams = $settings['teams_settings']['teams'] ?? [];
            
            foreach ($teams as $team) {
                if (($team['leader_id'] ?? 0) == $this->user_rep_id) {
                    $members = $team['members'] ?? [];
                    return array_unique(array_merge($members, [$this->user_rep_id]));
                }
            }
            
            return [$this->user_rep_id];
        }

        public function getResetFiltersUrl(): string {
            $current_url = $_SERVER['REQUEST_URI'];
            $url_parts = parse_url($current_url);
            $base_path = $url_parts['path'] ?? '';
            
            $clean_params = [];
            $clean_params['view'] = 'policies';
            
            if ($this->is_team_view) {
                $clean_params['view_type'] = 'team';
            }
            
            if ($this->isShowDeletedMode()) {
                $clean_params['show_deleted'] = '1';
            }
            
            $query_string = http_build_query($clean_params);
            return $base_path . ($query_string ? '?' . $query_string : '');
        }

        /**
         * ACTION URL GENERATION METHODS
         */
        public function getViewUrl($policy_id): string {
            $params = [
                'view' => 'policies',
                'action' => 'view',
                'id' => $policy_id
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }

        public function getEditUrl($policy_id): string {
            $params = [
                'view' => 'policies',
                'action' => 'edit', 
                'id' => $policy_id
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }

        public function getCancelUrl($policy_id): string {
            $params = [
                'view' => 'policies',
                'action' => 'cancel',
                'id' => $policy_id
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return wp_nonce_url(add_query_arg($params, $_SERVER['REQUEST_URI']), 'cancel_policy_' . $policy_id);
        }

        public function getDeleteUrl($policy_id): string {
            $params = [
                'view' => 'policies',
                'action' => 'delete',
                'id' => $policy_id
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return wp_nonce_url(add_query_arg($params, $_SERVER['REQUEST_URI']), 'delete_policy_' . $policy_id);
        }

        public function getRenewUrl($policy_id): string {
            $params = [
                'view' => 'policies',
                'action' => 'renew',
                'id' => $policy_id
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }

        public function getCustomerViewUrl($customer_id): string {
            $params = [
                'view' => 'customers',
                'action' => 'view',
                'id' => $customer_id
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }

        public function getNewPolicyUrl(): string {
            $params = [
                'view' => 'policies',
                'action' => 'new'
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }
        
        // NEW: Show Deleted Policies URL
        public function getShowDeletedUrl(): string {
            $params = [
                'view' => 'policies',
                'show_deleted' => '1'
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }
        
        // NEW: Show Active Policies URL
        public function getShowActiveUrl(): string {
            $params = [
                'view' => 'policies'
            ];
            if ($this->is_team_view) {
                $params['view_type'] = 'team';
            }
            return add_query_arg($params, $_SERVER['REQUEST_URI']);
        }

        /**
         * NEW: Soft Delete Policy Method
         */
        public function softDeletePolicy($policy_id): bool {
            if (!$this->canDeletePolicy($policy_id)) {
                return false;
            }
            
            $update_data = [
                'is_deleted' => 1,
                'deleted_by' => $this->user_id,
                'deleted_at' => current_time('mysql')
            ];
            
            $result = $this->wpdb->update(
                $this->tables['policies'],
                $update_data,
                ['id' => $policy_id]
            );
            
            return $result !== false;
        }
        
        /**
         * NEW: Restore Deleted Policy Method 
         */
        public function restoreDeletedPolicy($policy_id): bool {
            if ($this->user_role_level > 2) {
                // Only Patron or Müdür can restore
                return false;
            }
            
            $update_data = [
                'is_deleted' => 0,
                'deleted_by' => NULL,
                'deleted_at' => NULL
            ];
            
            $result = $this->wpdb->update(
                $this->tables['policies'],
                $update_data,
                ['id' => $policy_id]
            );
            
            return $result !== false;
        }
        
        /**
         * NEW: Method to check if user can view a specific policy
         */
        public function canViewPolicy($policy_id): bool {
            $policy = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT representative_id, is_deleted FROM {$this->tables['policies']} WHERE id = %d",
                $policy_id
            ));
            
            if (!$policy) return false;
            
            // Check if policy is deleted and user has permission
            if ($policy->is_deleted && $this->user_role_level > 2) return false;
            
            // Patron and Müdür can view all
            if ($this->user_role_level <= 2) return true;
            
            // For team leaders check if policy belongs to team
            if ($this->user_role_level === 4) {
                $team_members = $this->getTeamMemberIds();
                return in_array($policy->representative_id, $team_members);
            }
            
            // Regular representatives can only view their own policies
            return $policy->representative_id == $this->user_rep_id;
        }

        /**
         * SQL sorgu placeholder sayısını kontrol eden yardımcı metod
         * wpdb prepare hatasını önlemek için kullanılır
         */
        private function countPlaceholders(string $sql): int {
            return substr_count($sql, '%s') + substr_count($sql, '%d') + substr_count($sql, '%f');
        }

        /**
         * ENHANCED SQL WITH IMPROVED FILTERING
         */
        public function getPolicies(array $filters, int $page = 1, int $per_page = 15): array {
            $offset = ($page - 1) * $per_page;
            $where_conditions = ['1=1'];
            $params = [];

            // NEW: Handle deleted policies
            if ($this->isShowDeletedMode() && $this->user_role_level <= 2) {
                $where_conditions[] = 'p.is_deleted = 1';
            } else {
                $where_conditions[] = 'p.is_deleted = 0';
            }
            
            $this->applyAuthorizationFilter($where_conditions, $params, $filters);
            $this->applySearchFilters($where_conditions, $params, $filters);

            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            
            // DEFAULT SORTING BY CREATION DATE (NEWEST FIRST)
            $orderby = 'p.created_at';
            $order = 'DESC';
            
            if (!empty($_GET['orderby'])) {
                $allowed_orderby = [
                    'p.policy_number' => 'p.policy_number',
                    'p.created_at' => 'p.created_at',
                    'p.start_date' => 'p.start_date',
                    'p.end_date' => 'p.end_date',
                    'p.premium_amount' => 'p.premium_amount',
                    'customer_name' => 'c.first_name'
                ];
                
                $requested_orderby = sanitize_key($_GET['orderby']);
                if (isset($allowed_orderby[$requested_orderby])) {
                    $orderby = $allowed_orderby[$requested_orderby];
                }
            }
            
            if (!empty($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC'])) {
                $order = strtoupper($_GET['order']);
            }

            // Extended query for deleted policies info
            $sql = "
                SELECT p.*, c.first_name, c.last_name, c.tc_identity, 
                       u.display_name as representative_name,
                       du.display_name as deleted_by_name
                FROM {$this->tables['policies']} p 
                LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
                LEFT JOIN {$this->tables['representatives']} r ON p.representative_id = r.id
                LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
                LEFT JOIN {$this->tables['users']} du ON p.deleted_by = du.ID
                {$where_clause}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d
            ";

            // SAFE QUERY EXECUTION WITH FALLBACK
            $final_params = array_merge($params, [$per_page, $offset]);
            
            // Placeholder sayısını kontrol et - bu hataları önler
            $placeholder_count = $this->countPlaceholders($sql);
            $param_count = count($final_params);
            
            if ($placeholder_count > 0 && $param_count === $placeholder_count) {
                $policies = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$final_params));
            } else {
                // Hata logu ve fallback
                error_log("SQL placeholder mismatch in getPolicies: {$placeholder_count} placeholders but {$param_count} parameters");
                
                // Fallback: Manuel escape ve query
                $escaped_params = array_map(function($param) {
                    if (is_int($param)) return $param;
                    return "'" . esc_sql($param) . "'";
                }, $params);
                
                $safe_where = $where_clause;
                foreach ($escaped_params as $i => $escaped_param) {
                    $safe_where = preg_replace('/(%[sdf])/', $escaped_param, $safe_where, 1);
                }
                
                $fallback_sql = "
                    SELECT p.*, c.first_name, c.last_name, c.tc_identity, 
                           u.display_name as representative_name,
                           du.display_name as deleted_by_name 
                    FROM {$this->tables['policies']} p 
                    LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
                    LEFT JOIN {$this->tables['representatives']} r ON p.representative_id = r.id
                    LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
                    LEFT JOIN {$this->tables['users']} du ON p.deleted_by = du.ID
                    {$safe_where}
                    ORDER BY {$orderby} {$order}
                    LIMIT {$per_page} OFFSET {$offset}
                ";
                
                $policies = $this->wpdb->get_results($fallback_sql);
            }
            
            // Toplam sayı
            $count_sql = "
                SELECT COUNT(DISTINCT p.id) 
                FROM {$this->tables['policies']} p 
                LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
                LEFT JOIN {$this->tables['representatives']} r ON p.representative_id = r.id
                LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
                {$where_clause}
            ";
            
            $count_placeholder_count = $this->countPlaceholders($count_sql);
            $count_param_count = count($params);
            
            if ($count_placeholder_count > 0 && $count_param_count === $count_placeholder_count) {
                $total = $this->wpdb->get_var($this->wpdb->prepare($count_sql, ...$params));
            } else {
                // Hata logu ve fallback
                error_log("SQL placeholder mismatch in count query: {$count_placeholder_count} placeholders but {$count_param_count} parameters");
                
                // Fallback count
                if (!empty($params)) {
                    $escaped_params = array_map(function($param) {
                        if (is_int($param)) return $param;
                        return "'" . esc_sql($param) . "'";
                    }, $params);
                    
                    $safe_where = $where_clause;
                    foreach ($escaped_params as $i => $escaped_param) {
                        $safe_where = preg_replace('/(%[sdf])/', $escaped_param, $safe_where, 1);
                    }
                    
                    $count_fallback = "
                        SELECT COUNT(DISTINCT p.id) 
                        FROM {$this->tables['policies']} p 
                        LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
                        LEFT JOIN {$this->tables['representatives']} r ON p.representative_id = r.id
                        LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
                        {$safe_where}
                    ";
                    $total = $this->wpdb->get_var($count_fallback);
                } else {
                    $total = $this->wpdb->get_var($count_sql);
                }
            }

            return ['policies' => $policies ?: [], 'total' => (int) $total];
        }

        private function applyAuthorizationFilter(array &$conditions, array &$params, array $filters): void {
            if (!empty($filters['representative_id_filter'])) {
                $conditions[] = 'p.representative_id = %d';
                $params[] = (int) $filters['representative_id_filter'];
                return;
            }

            if ($this->user_rep_id === 0) {
                $conditions[] = 'p.representative_id = %d';
                $params[] = 0;
                return;
            }

            if ($this->user_role_level <= 3) {
                // Patron, Müdür, Müdür Yrd. - hepsini görebilir
            } elseif ($this->user_role_level === 4) {
                $team_ids = $this->getTeamMemberIds();
                if ($this->is_team_view && !empty($team_ids)) {
                    $placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
                    $conditions[] = "p.representative_id IN ({$placeholders})";
                    $params = array_merge($params, $team_ids);
                } elseif (!$this->is_team_view) {
                    $conditions[] = 'p.representative_id = %d';
                    $params[] = $this->user_rep_id;
                }
            } else {
                $conditions[] = 'p.representative_id = %d';
                $params[] = $this->user_rep_id;
            }
        }

        private function applySearchFilters(array &$conditions, array &$params, array $filters): void {
            $filter_mappings = [
                'policy_number' => ['p.policy_number', 'LIKE'],
                'customer_id' => ['p.customer_id', '='],
                'policy_type' => ['p.policy_type', '='],
                'insurance_company' => ['p.insurance_company', '='],
                'status' => ['p.status', '='],
                'insured_party' => ['p.insured_party', 'LIKE'],
                'policy_category' => ['p.policy_category', '='],
                'network' => ['p.network', 'LIKE'],
                'payment_info' => ['p.payment_info', 'LIKE'],
                'status_note' => ['p.status_note', 'LIKE'],
                'cancellation_reason' => ['p.cancellation_reason', '='] // NEW: Cancellation reason filter
            ];

            foreach ($filter_mappings as $key => [$column, $operator]) {
                if (empty($filters[$key])) continue;
                if ($key === 'customer_id' && $filters[$key] == 0) continue;

                if ($operator === 'LIKE') {
                    $conditions[] = "{$column} LIKE %s";
                    $params[] = '%' . $this->wpdb->esc_like($filters[$key]) . '%';
                } else {
                    $conditions[] = "{$column} = %s";
                    $params[] = $filters[$key];
                }
            }

            // PASSIVE POLICIES FILTER - Default exclude passive
            if (empty($filters['show_passive'])) {
                $conditions[] = "(p.status != 'pasif' OR p.cancellation_date IS NOT NULL)";
            }
            
            // NEW: Only show cancelled policies
            if (!empty($filters['show_cancelled'])) {
                $conditions[] = "p.cancellation_date IS NOT NULL";
            }

            if (!empty($filters['expiring_soon'])) {
                $today = date('Y-m-d');
                $future_date = date('Y-m-d', strtotime('+30 days'));
                $conditions[] = "p.status = 'aktif' AND p.cancellation_date IS NULL AND p.end_date BETWEEN %s AND %s";
                $params[] = $today;
                $params[] = $future_date;
            }

            // IMPROVED DATE RANGE FILTER
            if (!empty($filters['date_range'])) {
                $dates = explode(' - ', $filters['date_range']);
                if (count($dates) === 2) {
                    // Handle Turkish date format (DD/MM/YYYY)
                    $start_parts = explode('/', trim($dates[0]));
                    $end_parts = explode('/', trim($dates[1]));
                    
                    if (count($start_parts) === 3 && count($end_parts) === 3) {
                        $start = $start_parts[2] . '-' . $start_parts[1] . '-' . $start_parts[0];
                        $end = $end_parts[2] . '-' . $end_parts[1] . '-' . $end_parts[0];
                        
                        if (strtotime($start) && strtotime($end)) {
                            $conditions[] = "p.start_date <= %s AND p.end_date >= %s";
                            $params[] = $end;
                            $params[] = $start;
                        }
                    }
                }
            }
        }

        public function getStatistics(array $filters): array {
            $where_conditions = ['1=1'];
            $params = [];
            
            // Exclude deleted policies for statistics
            $where_conditions[] = 'p.is_deleted = 0';
            
            $this->applyAuthorizationFilter($where_conditions, $params, $filters);
            
            // For statistics, we include passive policies in totals
            $original_show_passive = $filters['show_passive'] ?? '';
            $filters['show_passive'] = '1'; // Temporarily include passive for full stats
            $this->applySearchFilters($where_conditions, $params, $filters);
            $filters['show_passive'] = $original_show_passive; // Restore original
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            $stats = [];
            
            $base_query = "FROM {$this->tables['policies']} p 
                          LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
                          LEFT JOIN {$this->tables['representatives']} r ON p.representative_id = r.id
                          LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
                          {$where_clause}";

            // GÜVENLİ STATISTICS QUERIES
            try {
                $stats['total'] = (int) $this->executeStatQuery("SELECT COUNT(DISTINCT p.id) {$base_query}", $params);
                $stats['active'] = (int) $this->executeStatQuery("SELECT COUNT(DISTINCT p.id) {$base_query} AND p.status = 'aktif' AND p.cancellation_date IS NULL", $params);
                $stats['passive'] = (int) $this->executeStatQuery("SELECT COUNT(DISTINCT p.id) {$base_query} AND p.status = 'pasif' AND p.cancellation_date IS NULL", $params);
                $stats['cancelled'] = (int) $this->executeStatQuery("SELECT COUNT(DISTINCT p.id) {$base_query} AND p.cancellation_date IS NOT NULL", $params);
                $stats['total_premium'] = (float) $this->executeStatQuery("SELECT COALESCE(SUM(p.premium_amount), 0) {$base_query}", $params);
                
                // NEW: Count soft deleted policies
                if ($this->user_role_level <= 2) {
                    $deleted_where = str_replace('p.is_deleted = 0', 'p.is_deleted = 1', $where_clause);
                    $stats['deleted'] = (int) $this->executeStatQuery("SELECT COUNT(DISTINCT p.id) FROM {$this->tables['policies']} p 
                                        LEFT JOIN {$this->tables['customers']} c ON p.customer_id = c.id
                                        LEFT JOIN {$this->tables['representatives']} r ON p.representative_id = r.id
                                        LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
                                        {$deleted_where}", $params);
                } else {
                    $stats['deleted'] = 0;
                }
            } catch (Exception $e) {
                error_log("Statistics query error: " . $e->getMessage());
                $stats = ['total' => 0, 'active' => 0, 'passive' => 0, 'cancelled' => 0, 'total_premium' => 0, 'deleted' => 0];
            }
            
            $stats['avg_premium'] = $stats['total'] > 0 ? $stats['total_premium'] / $stats['total'] : 0;
            return $stats;
        }

        private function executeStatQuery($query, $params) {
            // Placeholder sayısını kontrol et
            $placeholder_count = $this->countPlaceholders($query);
            $param_count = count($params);
            
            if ($placeholder_count > 0 && $param_count === $placeholder_count) {
                return $this->wpdb->get_var($this->wpdb->prepare($query, ...$params));
            } else {
                // Hata logu ve fallback
                if ($placeholder_count != $param_count) {
                    error_log("SQL placeholder mismatch in stat query: {$placeholder_count} placeholders but {$param_count} parameters");
                }
                
                // Fallback with manual escaping
                if (!empty($params)) {
                    $escaped_params = array_map(function($param) {
                        if (is_int($param)) return $param;
                        return "'" . esc_sql($param) . "'";
                    }, $params);
                    
                    $safe_query = $query;
                    foreach ($escaped_params as $i => $escaped_param) {
                        $safe_query = preg_replace('/(%[sdf])/', $escaped_param, $safe_query, 1);
                    }
                    
                    return $this->wpdb->get_var($safe_query);
                } else {
                    return $this->wpdb->get_var($query);
                }
            }
        }

        public function canEditPolicy($policy_id): bool {
            return can_edit_policy($policy_id, $this->user_role_level, $this->user_rep_id);
        }

        public function canDeletePolicy($policy_id): bool {
            return can_delete_policy($policy_id, $this->user_role_level, $this->user_rep_id);
        }
        
        // NEW: Method to handle the policy deletion
        public function handlePolicyDeletion($policy_id): array {
            if (!$this->canDeletePolicy($policy_id)) {
                return [
                    'success' => false,
                    'message' => 'Bu poliçeyi silme yetkiniz yok.',
                    'type' => 'error'
                ];
            }
            
            // Check if policy exists and is not already deleted
            $policy = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id, policy_number, is_deleted FROM {$this->tables['policies']} WHERE id = %d",
                $policy_id
            ));
            
            if (!$policy) {
                return [
                    'success' => false,
                    'message' => 'Poliçe bulunamadı.',
                    'type' => 'error'
                ];
            }
            
            if ($policy->is_deleted) {
                return [
                    'success' => false,
                    'message' => 'Bu poliçe zaten silinmiş durumda.',
                    'type' => 'warning'
                ];
            }
            
            $result = $this->softDeletePolicy($policy_id);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Poliçe başarıyla silindi: ' . $policy->policy_number,
                    'type' => 'success'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Poliçe silinirken bir hata oluştu.',
                    'type' => 'error'
                ];
            }
        }
        
        // NEW: Method to restore deleted policy
        public function handlePolicyRestore($policy_id): array {
            // Check if policy exists and is deleted
            $policy = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id, policy_number, is_deleted FROM {$this->tables['policies']} WHERE id = %d",
                $policy_id
            ));
            
            if (!$policy) {
                return [
                    'success' => false,
                    'message' => 'Poliçe bulunamadı.',
                    'type' => 'error'
                ];
            }
            
            if (!$policy->is_deleted) {
                return [
                    'success' => false,
                    'message' => 'Bu poliçe zaten aktif durumda.',
                    'type' => 'warning'
                ];
            }
            
            if ($this->user_role_level > 2) {
                return [
                    'success' => false,
                    'message' => 'Sadece Patron veya Müdür silinmiş poliçeleri geri getirebilir.',
                    'type' => 'error'
                ];
            }
            
            $result = $this->restoreDeletedPolicy($policy_id);
            
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Poliçe başarıyla geri getirildi: ' . $policy->policy_number,
                    'type' => 'success'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Poliçe geri getirilirken bir hata oluştu.',
                    'type' => 'error'
                ];
            }
        }
    }
} // End of class existence check

// Sınıfı başlat - CLASS INSTANTIATION CHECK
if (!isset($policy_manager) || !($policy_manager instanceof ModernPolicyManager)) {
    try {
        $policy_manager = new ModernPolicyManager();
    } catch (Exception $e) {
        echo '<div class="error-notice">Sistem başlatılamadı: ' . esc_html($e->getMessage()) . '</div>';
        return;
    }
}

// Handle policy deletion request
$delete_response = null;
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $policy_id = intval($_GET['id']);
    
    // Verify nonce
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_policy_' . $policy_id)) {
        $delete_response = $policy_manager->handlePolicyDeletion($policy_id);
    } else {
        $delete_response = [
            'success' => false,
            'message' => 'Güvenlik doğrulaması başarısız oldu.',
            'type' => 'error'
        ];
    }
}

// Handle policy restoration
$restore_response = null;
if (isset($_GET['action']) && $_GET['action'] === 'restore' && isset($_GET['id'])) {
    $policy_id = intval($_GET['id']);
    
    // Verify nonce
    if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'restore_policy_' . $policy_id)) {
        $restore_response = $policy_manager->handlePolicyRestore($policy_id);
    } else {
        $restore_response = [
            'success' => false,
            'message' => 'Güvenlik doğrulaması başarısız oldu.',
            'type' => 'error'
        ];
    }
}

// ENHANCED FILTERS WITH NEW OPTIONS
$filters = [
    'policy_number' => sanitize_text_field($_GET['policy_number'] ?? ''),
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'representative_id_filter' => (int) ($_GET['representative_id_filter'] ?? 0),
    'policy_type' => sanitize_text_field($_GET['policy_type'] ?? ''),
    'insurance_company' => sanitize_text_field($_GET['insurance_company'] ?? ''),
    'status' => sanitize_text_field($_GET['status'] ?? ''),
    'insured_party' => sanitize_text_field($_GET['insured_party'] ?? ''),
    'date_range' => sanitize_text_field($_GET['date_range'] ?? ''),
    'policy_category' => sanitize_text_field($_GET['policy_category'] ?? ''),
    'network' => sanitize_text_field($_GET['network'] ?? ''),
    'payment_info' => sanitize_text_field($_GET['payment_info'] ?? ''),
    'status_note' => sanitize_text_field($_GET['status_note'] ?? ''),
    'expiring_soon' => isset($_GET['expiring_soon']) ? '1' : '',
    'show_passive' => isset($_GET['show_passive']) ? '1' : '',
    'show_cancelled' => isset($_GET['show_cancelled']) ? '1' : '', // NEW: Show only cancelled policies
    'cancellation_reason' => sanitize_text_field($_GET['cancellation_reason'] ?? ''), // NEW: Filter by cancellation reason
];

$current_page = max(1, (int) ($_GET['paged'] ?? 1));

// PER PAGE SELECTION
$per_page_options = [15, 25, 50, 100];
$per_page = (int) ($_GET['per_page'] ?? 15);
if (!in_array($per_page, $per_page_options)) {
    $per_page = 15;
}

// Veri çekme
try {
    $policy_data = $policy_manager->getPolicies($filters, $current_page, $per_page);
    $policies = $policy_data['policies'];
    $total_items = $policy_data['total'];
    $total_pages = ceil($total_items / $per_page);

    $statistics = $policy_manager->getStatistics($filters);
} catch (Exception $e) {
    $policies = [];
    $total_items = 0;
    $total_pages = 0;
    $statistics = ['total' => 0, 'active' => 0, 'passive' => 0, 'cancelled' => 0, 'total_premium' => 0, 'avg_premium' => 0, 'deleted' => 0];
    echo '<div class="error-notice">Veri alınırken hata oluştu: ' . esc_html($e->getMessage()) . '</div>';
}

$active_filter_count = count(array_filter($filters, fn($v) => $v !== '' && $v !== 0));

// Dropdown verileri
$settings = get_option('insurance_crm_settings', []);
$insurance_companies = array_unique($settings['insurance_companies'] ?? ['Sompo']);
sort($insurance_companies);

$policy_types = $settings['default_policy_types'] ?? ['Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat', 'Seyahat', 'Diğer'];
$policy_categories = ['Yeni İş', 'Yenileme', 'Zeyil', 'Diğer'];

// NEW: Cancellation reasons for filter
$cancellation_reasons = ['Araç Satışı', 'İsteğe Bağlı', 'Tahsilattan İptal', 'Diğer Sebepler'];

try {
    $customers = $wpdb->get_results("SELECT id, first_name, last_name FROM {$customers_table} ORDER BY first_name, last_name");
} catch (Exception $e) {
    $customers = [];
}

$representatives = [];
if ($policy_manager->getUserRoleLevel() <= 4) {
    try {
        $rep_query = "SELECT r.id, u.display_name FROM {$representatives_table} r 
                      JOIN {$users_table} u ON r.user_id = u.ID 
                      WHERE r.status = 'active' ORDER BY u.display_name";
        $representatives = $wpdb->get_results($rep_query);
    } catch (Exception $e) {
        $representatives = [];
    }
}

$current_action = sanitize_key($_GET['action'] ?? '');
$show_list = !in_array($current_action, ['view', 'edit', 'new', 'renew', 'cancel']);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poliçe Yönetimi - Modern CRM v5.1.0</title>
    
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    
    <!-- Load jQuery BEFORE daterangepicker -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<div class="modern-crm-container" id="policies-container" <?php echo !$show_list ? 'style="display:none;"' : ''; ?>>
    
    <!-- Header Section -->
    <header class="crm-header">
        <div class="header-content">
            <div class="title-section">
                <div class="page-title">
                    <i class="fas fa-file-contract"></i>
                    <h1>Poliçe Yönetimi</h1>
                    <span class="version-badge">v5.1.0</span>
                </div>
                <div class="user-badge">
                    <span class="role-badge">
                        <i class="fas fa-user-shield"></i>
                        <?php echo esc_html($policy_manager->getRoleName($policy_manager->getUserRoleLevel())); ?>
                    </span>
                </div>
            </div>
            
            <div class="header-actions">
                <?php if ($policy_manager->getUserRoleLevel() <= 4): ?>
                <div class="view-toggle">
                    <a href="<?php echo esc_url(add_query_arg(['view' => 'policies', 'view_type' => 'personal'], remove_query_arg(array_keys($filters)))); ?>" 
                       class="view-btn <?php echo !$policy_manager->is_team_view ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i>
                        <span>Kişisel</span>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(['view' => 'policies', 'view_type' => 'team'], remove_query_arg(array_keys($filters)))); ?>" 
                       class="view-btn <?php echo $policy_manager->is_team_view ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Ekip</span>
                    </a>
                </div>
                <?php endif; ?>

                <div class="filter-controls">
                    <button type="button" id="filterToggle" class="btn btn-outline filter-toggle">
                        <i class="fas fa-filter"></i>
                        <span>Filtrele</span>
                        <?php if ($active_filter_count > 0): ?>
                        <span class="filter-count"><?php echo $active_filter_count; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-down chevron"></i>
                    </button>
                    
                    <?php if ($active_filter_count > 0): ?>
                    <a href="<?php echo esc_url($policy_manager->getResetFiltersUrl()); ?>" class="btn btn-ghost clear-filters">
                        <i class="fas fa-times"></i>
                        <span>Temizle</span>
                    </a>
                    <?php endif; ?>
                </div>

                <?php if ($policy_manager->getUserRoleLevel() <= 2 && !$policy_manager->isShowDeletedMode()): ?>
                <a href="<?php echo esc_url($policy_manager->getShowDeletedUrl()); ?>" class="btn btn-warning">
                    <i class="fas fa-trash-alt"></i>
                    <span>Silinen Poliçeler</span>
                </a>
                <?php elseif ($policy_manager->getUserRoleLevel() <= 2 && $policy_manager->isShowDeletedMode()): ?>
                <a href="<?php echo esc_url($policy_manager->getShowActiveUrl()); ?>" class="btn btn-info">
                    <i class="fas fa-check-circle"></i>
                    <span>Aktif Poliçeler</span>
                </a>
                <?php endif; ?>

                <a href="<?php echo esc_url($policy_manager->getNewPolicyUrl()); ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    <span>Yeni Poliçe</span>
                </a>
            </div>
        </div>
    </header>

    <?php if ($delete_response): ?>
    <div class="notification-banner notification-<?php echo $delete_response['type']; ?>">
        <div class="notification-icon">
            <i class="fas fa-<?php echo $delete_response['type'] === 'success' ? 'check-circle' : ($delete_response['type'] === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
        </div>
        <div class="notification-content">
            <?php echo esc_html($delete_response['message']); ?>
        </div>
        <button class="notification-close">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>
    
    <?php if ($restore_response): ?>
    <div class="notification-banner notification-<?php echo $restore_response['type']; ?>">
        <div class="notification-icon">
            <i class="fas fa-<?php echo $restore_response['type'] === 'success' ? 'check-circle' : ($restore_response['type'] === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
        </div>
        <div class="notification-content">
            <?php echo esc_html($restore_response['message']); ?>
        </div>
        <button class="notification-close">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Filters Section -->
    <section class="filters-section <?php echo $active_filter_count === 0 ? 'hidden' : ''; ?>" id="filtersSection">
        <div class="filters-container">
            <form method="get" class="filters-form" action="">
                <input type="hidden" name="view" value="policies">
                <?php if ($policy_manager->is_team_view): ?>
                <input type="hidden" name="view_type" value="team">
                <?php endif; ?>
                <?php if ($policy_manager->isShowDeletedMode()): ?>
                <input type="hidden" name="show_deleted" value="1">
                <?php endif; ?>
                
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="policy_number">Poliçe Numarası</label>
                        <input type="text" id="policy_number" name="policy_number" 
                               value="<?php echo esc_attr($filters['policy_number']); ?>" 
                               placeholder="Poliçe numarası ara..." class="form-input">
                    </div>

                    <div class="filter-group">
                        <label for="customer_id">Müşteri</label>
                        <select id="customer_id" name="customer_id" class="form-select">
                            <option value="0">Tüm Müşteriler</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer->id; ?>" <?php selected($filters['customer_id'], $customer->id); ?>>
                                <?php echo esc_html($customer->first_name . ' ' . $customer->last_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($representatives)): ?>
                    <div class="filter-group">
                        <label for="representative_id_filter">Temsilci</label>
                        <select id="representative_id_filter" name="representative_id_filter" class="form-select">
                            <option value="0">Tüm Temsilciler</option>
                            <?php foreach ($representatives as $rep): ?>
                            <option value="<?php echo $rep->id; ?>" <?php selected($filters['representative_id_filter'], $rep->id); ?>>
                                <?php echo esc_html($rep->display_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label for="policy_type">Poliçe Türü</label>
                        <select id="policy_type" name="policy_type" class="form-select">
                            <option value="">Tüm Türler</option>
                            <?php foreach ($policy_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php selected($filters['policy_type'], $type); ?>>
                                <?php echo esc_html($type); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="insurance_company">Sigorta Şirketi</label>
                        <select id="insurance_company" name="insurance_company" class="form-select">
                            <option value="">Tüm Şirketler</option>
                            <?php foreach ($insurance_companies as $company): ?>
                            <option value="<?php echo $company; ?>" <?php selected($filters['insurance_company'], $company); ?>>
                                <?php echo esc_html($company); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Durum</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Tüm Durumlar</option>
                            <option value="aktif" <?php selected($filters['status'], 'aktif'); ?>>Aktif</option>
                            <option value="pasif" <?php selected($filters['status'], 'pasif'); ?>>Pasif</option>
                            <option value="iptal" <?php selected($filters['status'], 'iptal'); ?>>İptal</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="policy_category">Kategori</label>
                        <select id="policy_category" name="policy_category" class="form-select">
                            <option value="">Tüm Kategoriler</option>
                            <?php foreach ($policy_categories as $category): ?>
                            <option value="<?php echo $category; ?>" <?php selected($filters['policy_category'], $category); ?>>
                                <?php echo esc_html($category); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- YENİ: Cancellation Reason Filter -->
                    <div class="filter-group">
                        <label for="cancellation_reason">İptal Nedeni</label>
                        <select id="cancellation_reason" name="cancellation_reason" class="form-select">
                            <option value="">Tüm İptal Nedenleri</option>
                            <?php foreach ($cancellation_reasons as $reason): ?>
                            <option value="<?php echo $reason; ?>" <?php selected($filters['cancellation_reason'], $reason); ?>>
                                <?php echo esc_html($reason); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="date_range">Tarih Aralığı</label>
                        <input type="text" id="date_range" name="date_range" 
                               value="<?php echo esc_attr($filters['date_range']); ?>" 
                               placeholder="Tarih aralığı seçin" class="form-input" readonly>
                    </div>

                    <div class="filter-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="expiring_soon" value="1" <?php checked($filters['expiring_soon'], '1'); ?>>
                            <span class="checkmark"></span>
                            Yakında Sona Erecekler (30 gün)
                        </label>
                    </div>
                    
                    <div class="filter-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="show_passive" value="1" <?php checked($filters['show_passive'], '1'); ?>>
                            <span class="checkmark"></span>
                            Pasif Poliçeleri de Göster
                        </label>
                    </div>
                    
                    <div class="filter-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="show_cancelled" value="1" <?php checked($filters['show_cancelled'], '1'); ?>>
                            <span class="checkmark"></span>
                            Sadece İptal Edilen Poliçeleri Göster
                        </label>
                    </div>
                </div>

                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <span>Filtrele</span>
                    </button>
                    <a href="<?php echo esc_url($policy_manager->getResetFiltersUrl()); ?>" class="btn btn-outline">
                        <i class="fas fa-undo"></i>
                        <span>Sıfırla</span>
                    </a>
                </div>
            </form>
        </div>
    </section>

    <!-- Statistics Dashboard -->
    <section class="dashboard-section" id="dashboardSection" <?php echo $active_filter_count > 0 || $policy_manager->isShowDeletedMode() ? 'style="display:none;"' : ''; ?>>
        <div class="stats-cards">
            <div class="stat-card primary">
                <div class="stat-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-content">
                    <h3>Toplam Poliçe</h3>
                    <div class="stat-value"><?php echo number_format($statistics['total']); ?></div>
                    <div class="stat-subtitle">
                        <?php 
                        if ($policy_manager->is_team_view) echo 'Ekip Toplamı';
                        else echo 'Kişisel Toplam';
                        ?>
                    </div>
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3>Aktif Poliçeler</h3>
                    <div class="stat-value"><?php echo number_format($statistics['active']); ?></div>
                    <div class="stat-subtitle">
                        <?php echo $statistics['total'] > 0 ? number_format(($statistics['active'] / $statistics['total']) * 100, 1) : 0; ?>% Toplam
                    </div>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                    <h3>Toplam Prim</h3>
                    <div class="stat-value">₺<?php echo number_format($statistics['total_premium'], 0, ',', '.'); ?></div>
                    <div class="stat-subtitle">
                        Ort: ₺<?php echo number_format($statistics['avg_premium'], 0, ',', '.'); ?>
                    </div>
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="stat-content">
                    <h3>İptal Edilen Poliçeler</h3>
                    <div class="stat-value"><?php echo number_format($statistics['cancelled']); ?></div>
                    <div class="stat-subtitle">
                        <?php echo $statistics['total'] > 0 ? number_format(($statistics['cancelled'] / $statistics['total']) * 100, 1) : 0; ?>% Toplam
                    </div>
                </div>
            </div>
            
            <?php if ($policy_manager->getUserRoleLevel() <= 2): ?>
            <div class="stat-card dark">
                <div class="stat-icon">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <div class="stat-content">
                    <h3>Silinen Poliçeler</h3>
                    <div class="stat-value"><?php echo number_format($statistics['deleted']); ?></div>
                    <div class="stat-subtitle">
                        <a href="<?php echo esc_url($policy_manager->getShowDeletedUrl()); ?>" class="view-deleted-link">Silinenleri Göster</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="charts-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-chart-pie"></i>
                    Detaylı İstatistikler
                </h2>
                <button type="button" id="chartsToggle" class="btn btn-ghost">
                    <i class="fas fa-chevron-up"></i>
                </button>
            </div>
            
            <div class="charts-container" id="chartsContainer">
                <div class="chart-grid">
                    <!-- Chart canvases will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </section>

    <!-- Special Banner for Deleted Policies View -->
    <?php if ($policy_manager->isShowDeletedMode()): ?>
    <div class="deleted-policies-banner">
        <div class="banner-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="banner-content">
            <h3>Silinen Poliçeleri Görüntülüyorsunuz</h3>
            <p>Bu bölümde sadece silinen poliçeler gösterilmektedir. Silinen poliçeleri geri getirebilir veya silinme detaylarını görebilirsiniz.</p>
        </div>
        <div class="banner-actions">
            <a href="<?php echo esc_url($policy_manager->getShowActiveUrl()); ?>" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Aktif Poliçelere Dön
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Policies Table -->
    <section class="table-section">
        <?php if (!empty($policies)): ?>
        <div class="table-wrapper">
            <div class="table-header">
                <div class="table-info">
                    <div class="table-meta">
                        <span>Toplam: <strong><?php echo number_format($total_items); ?></strong> poliçe</span>
                        <?php if ($policy_manager->is_team_view): ?>
                        <span class="view-badge team">
                            <i class="fas fa-users"></i>
                            Ekip Görünümü
                        </span>
                        <?php else: ?>
                        <span class="view-badge personal">
                            <i class="fas fa-user"></i>
                            Kişisel Görünüm
                        </span>
                        <?php endif; ?>
                        
                        <?php if ($policy_manager->isShowDeletedMode()): ?>
                        <span class="view-badge deleted">
                            <i class="fas fa-trash-alt"></i>
                            Silinen Poliçeler
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- PER PAGE SELECTOR -->
                    <div class="table-controls">
                        <div class="per-page-selector">
                            <label for="per_page">Sayfa başına:</label>
                            <select id="per_page" name="per_page" class="form-select" onchange="updatePerPage(this.value)">
                                <?php foreach ($per_page_options as $option): ?>
                                <option value="<?php echo $option; ?>" <?php selected($per_page, $option); ?>>
                                    <?php echo $option; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

<div class="table-header-scroll">
    <div style="width: 1200px;"></div>
</div>
            <div class="table-container">
                <table class="policies-table">
                    <thead>
                        <tr>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(array_merge($_GET, ['orderby' => 'p.policy_number', 'order' => 'ASC']))); ?>">
                                    Poliçe No <i class="fas fa-sort"></i>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(array_merge($_GET, ['orderby' => 'customer_name', 'order' => 'ASC']))); ?>">
                                    Müşteri <i class="fas fa-sort"></i>
                                </a>
                            </th>
                            <th>Tür</th>
                            <th>Şirket</th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(array_merge($_GET, ['orderby' => 'p.end_date', 'order' => 'ASC']))); ?>">
                                    Bitiş Tarihi <i class="fas fa-sort"></i>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url(add_query_arg(array_merge($_GET, ['orderby' => 'p.premium_amount', 'order' => 'DESC']))); ?>">
                                    Prim <i class="fas fa-sort"></i>
                                </a>
                            </th>
                            <th>Durum</th>
                            <th>Temsilci</th>
                            <th>Kategori</th>
                            <?php if ($policy_manager->isShowDeletedMode()): ?>
                            <th>Silinme Tarihi</th>
                            <th>Silen Kullanıcı</th>
                            <?php else: ?>
                            <th>Ödeme</th>
                            <th>Döküman</th>
                            <?php endif; ?>
                            <th class="actions-column">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($policies as $policy): 
                            // Policy status logic
                            $is_cancelled = !empty($policy->cancellation_date);
                            $is_passive = ($policy->status === 'pasif' && empty($policy->cancellation_date));
                            $is_expired = (strtotime($policy->end_date) < time() && $policy->status === 'aktif' && !$is_cancelled);
                            $is_expiring = (!$is_expired && !$is_passive && !$is_cancelled && 
                                          strtotime($policy->end_date) >= time() && 
                                          (strtotime($policy->end_date) - time()) < (30 * 24 * 60 * 60));
                            $is_deleted = !empty($policy->is_deleted);

                            $row_class = '';
                            if ($is_deleted) $row_class = 'deleted';
                            elseif ($is_cancelled) $row_class = 'cancelled';
                            elseif ($is_passive) $row_class = 'passive';
                            elseif ($is_expired) $row_class = 'expired';
                            elseif ($is_expiring) $row_class = 'expiring';
                        ?>
                        <tr class="<?php echo $row_class; ?>" data-policy-id="<?php echo $policy->id; ?>">
                            <td class="policy-number">
                                <a href="<?php echo esc_url($policy_manager->getViewUrl($policy->id)); ?>" class="policy-link">
                                    <?php echo esc_html($policy->policy_number); ?>
                                </a>
                                <div class="policy-badges">
                                    <?php if ($is_deleted): ?>
                                    <span class="badge deleted">Silinmiş</span>
                                    <?php endif; ?>
                                    <?php if ($is_cancelled): ?>
                                    <span class="badge cancelled">İptal</span>
                                    <?php endif; ?>
                                    <?php if ($is_passive): ?>
                                    <span class="badge passive">Pasif</span>
                                    <?php endif; ?>
                                    <?php if ($is_expired): ?>
                                    <span class="badge expired">Süresi Doldu</span>
                                    <?php endif; ?>
                                    <?php if ($is_expiring): ?>
                                    <span class="badge expiring">Yakında Bitiyor</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="customer">
                                <a href="<?php echo esc_url($policy_manager->getCustomerViewUrl($policy->customer_id)); ?>" class="customer-link">
                                    <?php echo esc_html($policy->first_name . ' ' . $policy->last_name); ?>
                                </a>
                                <?php if (!empty($policy->tc_identity)): ?>
                                <small class="tc-identity"><?php echo esc_html($policy->tc_identity); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="policy-type"><?php echo esc_html($policy->policy_type); ?></td>
                            <td class="insurance-company"><?php echo esc_html($policy->insurance_company); ?></td>
                            <td class="end-date"><?php echo date('d.m.Y', strtotime($policy->end_date)); ?></td>
                            <td class="premium"><?php echo number_format($policy->premium_amount, 2, ',', '.') . ' ₺'; ?></td>
                            <td class="status">
                                <span class="status-badge <?php echo $policy->status; ?>">
                                    <?php echo ucfirst($policy->status); ?>
                                </span>
                                <?php if ($is_cancelled): ?>
                                <small class="cancellation-date">İptal: <?php echo date('d.m.Y', strtotime($policy->cancellation_date)); ?></small>
                                <?php if (!empty($policy->cancellation_reason)): ?>
                                <small class="cancellation-reason"><?php echo esc_html($policy->cancellation_reason); ?></small>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="representative"><?php echo !empty($policy->representative_name) ? esc_html($policy->representative_name) : '—'; ?></td>
                            <td class="category"><?php echo !empty($policy->policy_category) ? esc_html($policy->policy_category) : 'Yeni İş'; ?></td>
                            
                            <?php if ($policy_manager->isShowDeletedMode()): ?>
                            <td class="deleted-at">
                                <?php echo !empty($policy->deleted_at) ? date('d.m.Y H:i', strtotime($policy->deleted_at)) : '—'; ?>
                            </td>
                            <td class="deleted-by">
                                <?php echo !empty($policy->deleted_by_name) ? esc_html($policy->deleted_by_name) : '—'; ?>
                            </td>
                            <?php else: ?>
                            <td class="payment"><?php echo !empty($policy->payment_info) ? esc_html($policy->payment_info) : '—'; ?></td>
                            <td class="document">
                                <?php if (!empty($policy->document_path)): ?>
                                <a href="<?php echo esc_url($policy->document_path); ?>" target="_blank" class="btn btn-xs btn-outline" title="Döküman Görüntüle">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                                <?php else: ?>
                                <span class="no-document">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            
                            <td class="actions">
    <div class="action-buttons-group">
        <!-- Tüm İşlem Butonları Tek Satırda -->
        <div class="all-actions">
            <?php if ($policy_manager->isShowDeletedMode()): ?>
                <?php if ($policy_manager->getUserRoleLevel() <= 2): ?>
                <a href="<?php echo wp_nonce_url('?view=policies&action=restore&id=' . $policy->id, 'restore_policy_' . $policy->id); ?>" 
                   class="btn btn-xs btn-success" title="Geri Getir" onclick="return confirm('Bu poliçeyi geri getirmek istediğinizden emin misiniz?');">
                    <i class="fas fa-trash-restore"></i>
                </a>
                <?php endif; ?>
                <button class="btn btn-xs btn-outline view-delete-details" 
                       data-id="<?php echo $policy->id; ?>"
                       data-policy="<?php echo esc_attr($policy->policy_number); ?>"
                       data-date="<?php echo !empty($policy->deleted_at) ? date('d.m.Y H:i', strtotime($policy->deleted_at)) : '-'; ?>"
                       data-user="<?php echo esc_attr($policy->deleted_by_name); ?>"
                       title="Detaylar">
                    <i class="fas fa-info-circle"></i>
                </button>
            <?php else: ?>
                <!-- Temel Butonlar -->
                <a href="<?php echo esc_url($policy_manager->getViewUrl($policy->id)); ?>" 
                   class="btn btn-xs btn-primary" title="Görüntüle">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($policy_manager->canEditPolicy($policy->id)): ?>
                <a href="<?php echo esc_url($policy_manager->getEditUrl($policy->id)); ?>" 
                   class="btn btn-xs btn-outline" title="Düzenle">
                    <i class="fas fa-edit"></i>
                </a>
                
                <!-- İptal Et/Yenile Butonları - Dropdown Olmadan Direkt Göster -->
                <?php if ($policy->status === 'aktif' && empty($policy->cancellation_date)): ?>
                <a href="<?php echo esc_url($policy_manager->getCancelUrl($policy->id)); ?>" 
                   class="btn btn-xs btn-warning" title="İptal Et"
                   onclick="return confirm('Bu poliçeyi iptal etmek istediğinizden emin misiniz?');">
                    <i class="fas fa-ban"></i>
                </a>
                <a href="<?php echo esc_url($policy_manager->getRenewUrl($policy->id)); ?>" 
                   class="btn btn-xs btn-success" title="Yenile">
                    <i class="fas fa-redo"></i>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                
                <!-- Silme Butonu - Direkt Göster -->
                <?php if ($policy_manager->canDeletePolicy($policy->id)): ?>
                <a href="<?php echo esc_url($policy_manager->getDeleteUrl($policy->id)); ?>" 
                   class="btn btn-xs btn-danger" title="Sil"
                   onclick="return confirm('Bu poliçeyi silmek istediğinizden emin misiniz? Bu işlem geri alınabilir ancak poliçe listede görünmez olacak.');">
                    <i class="fas fa-trash"></i>
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <nav class="pagination">
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '<i class="fas fa-chevron-left"></i>',
                        'next_text' => '<i class="fas fa-chevron-right"></i>',
                        'total' => $total_pages,
                        'current' => $current_page,
                        'add_args' => array_filter($_GET, fn($key) => $key !== 'paged', ARRAY_FILTER_USE_KEY)
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <?php if ($policy_manager->isShowDeletedMode()): ?>
                <i class="fas fa-trash-alt"></i>
                <?php else: ?>
                <i class="fas fa-file-contract"></i>
                <?php endif; ?>
            </div>
            <h3>
                <?php if ($policy_manager->isShowDeletedMode()): ?>
                    Silinen poliçe bulunamadı
                <?php else: ?>
                    Poliçe bulunamadı
                <?php endif; ?>
            </h3>
            <p>
                <?php 
                if ($policy_manager->isShowDeletedMode()) {
                    echo 'Silinen poliçe kaydı bulunamadı.';
                } elseif ($policy_manager->is_team_view) {
                    echo 'Ekibinize ait poliçe bulunamadı.';
                } else {
                    echo 'Arama kriterlerinize uygun poliçe bulunamadı.';
                }
                ?>
            </p>
            <a href="<?php echo esc_url($policy_manager->getResetFiltersUrl()); ?>" class="btn btn-primary">
                <i class="fas fa-refresh"></i>
                Tüm Poliçeleri Göster
            </a>
        </div>
        <?php endif; ?>
    </section>
</div>

<!-- Delete Details Modal Template -->
<div id="deleteDetailsModal" class="policy-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-trash-alt"></i> Silinen Poliçe Detayları</h3>
            <button class="close-modal">×</button>
        </div>
        <div class="modal-body">
            <div class="info-row">
                <div class="info-label">Poliçe Numarası:</div>
                <div class="info-value" id="modal-policy-number"></div>
            </div>
            <div class="info-row">
                <div class="info-label">Silinme Tarihi:</div>
                <div class="info-value" id="modal-delete-date"></div>
            </div>
            <div class="info-row">
                <div class="info-label">Silen Kullanıcı:</div>
                <div class="info-value" id="modal-delete-user"></div>
            </div>
            <div class="restore-info">
                <i class="fas fa-info-circle"></i>
                <p>Silinmiş poliçeleri yalnızca Patron veya Müdür seviyesindeki kullanıcılar geri getirebilir.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary close-modal-btn">Kapat</button>
            <?php if ($policy_manager->getUserRoleLevel() <= 2): ?>
            <button class="btn btn-success" id="restore-policy-btn">Poliçeyi Geri Getir</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>

/* Butonların yan yana düzgün görünmesi için ek stil */
.all-actions {
    display: flex;
    gap: 4px;
    flex-wrap: nowrap;
    justify-content: flex-start;
    align-items: center;
}

.all-actions .btn {
    margin: 2px;
    min-width: 28px;
    height: 28px;
    padding: 4px;
}

/* Küçük ekranlarda butonların hizalanması */
@media (max-width: 768px) {
    .all-actions {
        flex-wrap: wrap;
    }
}



/* Modern CSS Styles with Material Design 3 Principles - Enhanced v5.1.0 */
:root {
    /* Colors */
    --primary: #1976d2;
    --primary-dark: #1565c0;
    --primary-light: #42a5f5;
    --secondary: #9c27b0;
    --success: #2e7d32;
    --warning: #f57c00;
    --danger: #d32f2f;
    --info: #0288d1;
    
    /* Neutral Colors */
    --surface: #ffffff;
    --surface-variant: #f5f5f5;
    --surface-container: #fafafa;
    --on-surface: #1c1b1f;
    --on-surface-variant: #49454f;
    --outline: #79747e;
    --outline-variant: #cac4d0;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-base: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --font-size-2xl: 1.5rem;
    
    /* Spacing */
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
    
    /* Border Radius */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    
    /* Transitions */
    --transition-fast: 150ms ease;
    --transition-base: 250ms ease;
    --transition-slow: 350ms ease;
}

/* Reset & Base Styles */
* {
    box-sizing: border-box;
}

.modern-crm-container {
    font-family: var(--font-family);
    color: var(--on-surface);
    background-color: var(--surface-container);
    min-height: 100vh;
    padding: var(--spacing-lg);
    margin: 0;
}

.error-notice {
    background: #ffebee;
    border: 1px solid #e57373;
    color: #c62828;
    padding: var(--spacing-md);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-lg);
    font-weight: 500;
}

/* Notification Banner */
.notification-banner {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md) var(--spacing-lg);
    border-radius: var(--radius-lg);
    margin-bottom: var(--spacing-lg);
    animation: slideInDown 0.3s ease;
    box-shadow: var(--shadow-md);
}

.notification-success {
    background-color: #e8f5e9;
    border-left: 4px solid var(--success);
}

.notification-error {
    background-color: #ffebee;
    border-left: 4px solid var(--danger);
}

.notification-warning {
    background-color: #fff3e0;
    border-left: 4px solid var(--warning);
}

.notification-info {
    background-color: #e1f5fe;
    border-left: 4px solid var(--info);
}

.notification-icon {
    font-size: var(--font-size-xl);
}

.notification-success .notification-icon {
    color: var(--success);
}

.notification-error .notification-icon {
    color: var(--danger);
}

.notification-warning .notification-icon {
    color: var(--warning);
}

.notification-info .notification-icon {
    color: var(--info);
}

.notification-content {
    flex-grow: 1;
    font-size: var(--font-size-base);
}

.notification-close {
    background: none;
    border: none;
    color: var(--on-surface-variant);
    cursor: pointer;
    font-size: var(--font-size-lg);
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    transition: background-color var(--transition-fast);
}

.notification-close:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

@keyframes slideInDown {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Special Banner for Deleted Policies */
.deleted-policies-banner {
    background-color: #ffebee;
    border: 2px solid #e53935;
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
    box-shadow: var(--shadow-md);
    position: relative;
    overflow: hidden;
}

.deleted-policies-banner::before {
    content: '';
    position: absolute;
    top: -15px;
    right: -15px;
    width: 150px;
    height: 50px;
    background-color: #e53935;
    transform: rotate(45deg);
    z-index: 0;
}

.banner-icon {
    font-size: 2.5rem;
    color: #e53935;
    z-index: 1;
}

.banner-content {
    flex: 1;
    z-index: 1;
}

.banner-content h3 {
    margin: 0 0 var(--spacing-sm);
    font-size: var(--font-size-xl);
    color: #c62828;
}

.banner-content p {
    margin: 0;
    font-size: var(--font-size-base);
    color: #333;
}

.banner-actions {
    z-index: 1;
}

/* Header Styles */
.crm-header {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--spacing-lg);
    flex-wrap: wrap;
}

.title-section {
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
}

.page-title {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.page-title i {
    font-size: var(--font-size-xl);
    color: var(--primary);
}

.page-title h1 {
    margin: 0;
    font-size: var(--font-size-2xl);
    font-weight: 600;
    color: var(--on-surface);
}

.version-badge {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
    color: white;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.role-badge {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-xl);
    font-size: var(--font-size-sm);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

/* Header Actions */
.header-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.view-toggle {
    display: flex;
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-xs);
}

.view-btn {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-md);
    text-decoration: none;
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface-variant);
    transition: all var(--transition-fast);
}

.view-btn:hover {
    background: var(--surface);
    color: var(--on-surface);
}

.view-btn.active {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
}

.filter-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

/* Enhanced Button Styles */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border: 1px solid transparent;
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    position: relative;
    overflow: hidden;
    background: none;
    white-space: nowrap;
}

.btn:before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.btn:hover:before {
    left: 100%;
}

.btn-primary {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
}

.btn-primary:hover {
    background: var(--primary-dark);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.btn-secondary {
    background: #757575;
    color: white;
}

.btn-secondary:hover {
    background: #616161;
    transform: translateY(-1px);
}

.btn-outline {
    background: transparent;
    color: var(--primary);
    border-color: var(--outline-variant);
}

.btn-outline:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-ghost {
    background: transparent;
    color: var(--on-surface-variant);
}

.btn-ghost:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #2e7d32;
    transform: translateY(-1px);
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    background: #ef6c00;
    transform: translateY(-1px);
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #c62828;
    transform: translateY(-1px);
}

.btn-info {
    background: var(--info);
    color: white;
}

.btn-info:hover {
    background: #0277bd;
    transform: translateY(-1px);
}

.btn-xs {
    padding: 4px 8px;
    font-size: var(--font-size-xs);
    gap: 4px;
}

.btn-sm {
    padding: var(--spacing-xs) var(--spacing-sm);
    font-size: 0.75rem;
}

/* Filter Section */
.filters-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
    transition: all var(--transition-base);
}

.filters-section.hidden {
    display: none;
}

.filters-container {
    padding: var(--spacing-xl);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.filter-group label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface);
}

.form-input,
.form-select {
    width: 100%;
    padding: var(--spacing-md);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-base);
    background: var(--surface);
    color: var(--on-surface);
    transition: all var(--transition-fast);
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

.checkbox-group {
    display: flex;
    align-items: center;
    padding-top: var(--spacing-md);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
    font-size: var(--font-size-sm);
    user-select: none;
}

.checkbox-label input[type="checkbox"] {
    margin: 0;
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

.filter-count {
    background: var(--danger);
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-xl);
    min-width: 20px;
    text-align: center;
}

.filters-actions {
    display: flex;
    gap: var(--spacing-md);
    justify-content: flex-end;
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
}

/* Dashboard Section */
.dashboard-section {
    margin-bottom: var(--spacing-xl);
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    transition: all var(--transition-base);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--primary), var(--primary-light));
}

.stat-card.success:before {
    background: linear-gradient(90deg, var(--success), #4caf50);
}

.stat-card.warning:before {
    background: linear-gradient(90deg, var(--warning), #ff9800);
}

.stat-card.danger:before {
    background: linear-gradient(90deg, var(--danger), #f44336);
}

.stat-card.dark:before {
    background: linear-gradient(90deg, #424242, #757575);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-xl);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
    color: white;
    flex-shrink: 0;
}

.stat-card.primary .stat-icon {
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
}

.stat-card.success .stat-icon {
    background: linear-gradient(135deg, var(--success), #4caf50);
}

.stat-card.warning .stat-icon {
    background: linear-gradient(135deg, var(--warning), #ff9800);
}

.stat-card.danger .stat-icon {
    background: linear-gradient(135deg, var(--danger), #f44336);
}

.stat-card.dark .stat-icon {
    background: linear-gradient(135deg, #424242, #757575);
}

.stat-content h3 {
    margin: 0 0 var(--spacing-sm) 0;
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface-variant);
}

.stat-value {
    font-size: var(--font-size-2xl);
    font-weight: 700;
    color: var(--on-surface);
    margin-bottom: var(--spacing-xs);
}

.stat-subtitle {
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.view-deleted-link {
    color: #607d8b;
    text-decoration: underline;
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.view-deleted-link:hover {
    color: #455a64;
}

/* Charts Section */
.charts-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
}

.section-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.charts-container {
    padding: var(--spacing-xl);
}

.chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: var(--spacing-lg);
}

.chart-item {
    background: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
}

.chart-item h4 {
    margin: 0 0 var(--spacing-md) 0;
    font-size: var(--font-size-base);
    font-weight: 500;
    color: var(--on-surface);
    text-align: center;
}

.chart-canvas {
    position: relative;
    height: 250px;
    width: 100%;
}

/* Enhanced Table Section */
.table-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    overflow: hidden;
}

.table-header {
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-bottom: 1px solid var(--outline-variant);
}

.table-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.table-meta {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.table-controls {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.per-page-selector {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.per-page-selector label {
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--on-surface);
    white-space: nowrap;
}

.per-page-selector select {
    padding: 4px 8px;
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    background: var(--surface);
    color: var(--on-surface);
    min-width: 60px;
}

.view-badge {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-xs) var(--spacing-md);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.view-badge.team {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.view-badge.personal {
    background: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.view-badge.deleted {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.table-container {
    overflow-x: auto;
}

.policies-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1400px;
}

.policies-table th,
.policies-table td {
    padding: var(--spacing-md);
    text-align: left;
    border-bottom: 1px solid var(--outline-variant);
    font-size: var(--font-size-sm);
    vertical-align: middle;
}

.policies-table th {
    background: var(--surface-variant);
    font-weight: 600;
    color: var(--on-surface);
    position: sticky;
    top: 0;
    z-index: 1;
}

.policies-table th a {
    color: inherit;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.policies-table th a:hover {
    color: var(--primary);
}

.policies-table tbody tr {
    transition: all var(--transition-fast);
}

.policies-table tbody tr:hover {
    background: var(--surface-variant);
}

/* Row Status Colors */
.policies-table tr.deleted td {
    background: rgba(97, 97, 97, 0.05) !important;
    border-left: 3px solid #616161;
}

.policies-table tr.cancelled td {
    background: rgba(211, 47, 47, 0.05) !important;
    border-left: 3px solid var(--danger);
}

.policies-table tr.passive td {
    background: rgba(117, 117, 117, 0.05) !important;
    border-left: 3px solid #757575;
}

.policies-table tr.expired td {
    background: rgba(245, 124, 0, 0.05) !important;
    border-left: 3px solid var(--warning);
}

.policies-table tr.expiring td {
    background: rgba(25, 118, 210, 0.05) !important;
    border-left: 3px solid var(--primary);
}

/* Table Cell Specific Styles */
.policy-number {
    min-width: 150px;
}

.policy-link {
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
    display: block;
    margin-bottom: var(--spacing-xs);
}

.policy-link:hover {
    text-decoration: underline;
}

.policy-badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-xs);
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 2px var(--spacing-xs);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge.deleted {
    background: rgba(97, 97, 97, 0.1);
    color: #616161;
    border: 1px solid rgba(97, 97, 97, 0.2);
}

.badge.cancelled {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
    border: 1px solid rgba(211, 47, 47, 0.2);
}

.badge.passive {
    background: rgba(117, 117, 117, 0.1);
    color: #757575;
    border: 1px solid rgba(117, 117, 117, 0.2);
}

.badge.expired {
    background: rgba(245, 124, 0, 0.1);
    color: var(--warning);
    border: 1px solid rgba(245, 124, 0, 0.2);
}

.badge.expiring {
    background: rgba(25, 118, 210, 0.1);
    color: var(--primary);
    border: 1px solid rgba(25, 118, 210, 0.2);
}

.customer-link {
    color: var(--on-surface);
    text-decoration: none;
    font-weight: 500;
}

.customer-link:hover {
    color: var(--primary);
    text-decoration: underline;
}

.tc-identity {
    display: block;
    color: var(--on-surface-variant);
    font-size: 0.75rem;
    margin-top: var(--spacing-xs);
}

.premium {
    font-weight: 600;
    color: var(--success);
    text-align: right;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-transform: capitalize;
}

.status-badge.aktif {
    background: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.pasif {
    background: rgba(117, 117, 117, 0.1);
    color: #757575;
}

.status-badge.iptal {
    background: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.cancellation-date {
    display: block;
    color: var(--on-surface-variant);
    font-size: 0.75rem;
    margin-top: var(--spacing-xs);
}

.cancellation-reason {
    display: block;
    color: var(--danger);
    font-size: 0.75rem;
    margin-top: 2px;
    font-style: italic;
}

/* Enhanced Action Buttons */
.actions-column {
    width: 140px;
    text-align: center;
}

.action-buttons-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: center;
}

.primary-actions {
    display: flex;
    gap: 4px;
    justify-content: center;
}

.secondary-actions {
    position: relative;
}

/* Dropdown Styles */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle {
    cursor: pointer;
}

.dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: var(--surface);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    min-width: 140px;
    z-index: 1000;
    padding: var(--spacing-xs);
}

.dropdown:hover .dropdown-menu {
    display: block;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    color: var(--on-surface);
    text-decoration: none;
    border-radius: var(--radius-md);
    font-size: var(--font-size-sm);
    transition: all var(--transition-fast);
}

.dropdown-item:hover {
    background: var(--surface-variant);
}

.dropdown-item.btn-warning {
    color: var(--warning);
}

.dropdown-item.btn-success {
    color: var(--success);
}

.dropdown-item.btn-danger {
    color: var(--danger);
}

.dropdown-item.btn-warning:hover {
    background: rgba(245, 124, 0, 0.1);
}

.dropdown-item.btn-success:hover {
    background: rgba(46, 125, 50, 0.1);
}

.dropdown-item.btn-danger:hover {
    background: rgba(211, 47, 47, 0.1);
}

/* Modal Styles */
.policy-modal {
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: white;
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 600px;
    box-shadow: var(--shadow-xl);
    animation: modalFadeIn 0.3s ease;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    padding: var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: #f5f5f5;
    border-bottom: 1px solid #e0e0e0;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
}

.modal-header h3 {
    margin: 0;
    font-size: var(--font-size-lg);
    color: #333;
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.modal-header h3 i {
    color: var(--danger);
}

.close-modal {
    background: none;
    border: none;
    font-size: 24px;
    color: #666;
    cursor: pointer;
    line-height: 1;
}

.modal-body {
    padding: var(--spacing-lg);
}

.info-row {
    display: flex;
    margin-bottom: 15px;
}

.info-label {
    width: 40%;
    font-weight: 600;
    color: #555;
}

.info-value {
    width: 60%;
    color: #333;
}

.restore-info {
    margin-top: var(--spacing-lg);
    padding: var(--spacing-md);
    background-color: #f5f5f5;
    border-radius: var(--radius-md);
    display: flex;
    gap: var(--spacing-sm);
}

.restore-info i {
    color: var(--info);
    flex-shrink: 0;
    margin-top: 3px;
}

.restore-info p {
    margin: 0;
    font-size: var(--font-size-sm);
    color: #555;
}

.modal-footer {
    padding: var(--spacing-md) var(--spacing-lg);
    display: flex;
    justify-content: flex-end;
    gap: var(--spacing-sm);
    border-top: 1px solid #e0e0e0;
    background-color: #f5f5f5;
    border-radius: 0 0 var(--radius-lg) var(--radius-lg);
}

/* Pagination */
.pagination-wrapper {
    padding: var(--spacing-lg) var(--spacing-xl);
    background: var(--surface-variant);
    border-top: 1px solid var(--outline-variant);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: var(--spacing-xs);
}

.pagination .page-numbers {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: var(--spacing-sm);
    border: 1px solid var(--outline-variant);
    border-radius: var(--radius-md);
    color: var(--on-surface);
    text-decoration: none;
    font-size: var(--font-size-sm);
    font-weight: 500;
    transition: all var(--transition-fast);
}

.pagination .page-numbers:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.pagination .page-numbers.current {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: var(--spacing-2xl);
    color: var(--on-surface-variant);
}

.empty-icon {
    font-size: 4rem;
    color: var(--outline);
    margin-bottom: var(--spacing-lg);
}

.empty-state h3 {
    margin: 0 0 var(--spacing-md) 0;
    font-size: var(--font-size-xl);
    color: var(--on-surface);
}

.empty-state p {
    margin: 0 0 var(--spacing-xl) 0;
    font-size: var(--font-size-base);
}

/* Responsive Design */
@media (max-width: 1024px) {
    .action-buttons-group {
        flex-direction: row;
        gap: 2px;
    }
    
    .primary-actions {
        flex-direction: column;
        gap: 2px;
    }
    
    .actions-column {
        width: 120px;
    }
}

@media (max-width: 768px) {
    .modern-crm-container {
        padding: var(--spacing-md);
    }

    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .header-actions {
        justify-content: space-between;
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }

    .stats-cards {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }

    .chart-grid {
        grid-template-columns: 1fr;
    }

    .table-container {
        margin: 0 calc(-1 * var(--spacing-md));
    }

    .table-info {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-sm);
    }

    .policies-table th:nth-child(n+6),
    .policies-table td:nth-child(n+6) {
        display: none;
    }

    .policies-table th:nth-child(4),
    .policies-table td:nth-child(4),
    .policies-table th:nth-child(9),
    .policies-table td:nth-child(9),
    .policies-table th:nth-child(10),
    .policies-table td:nth-child(10) {
        display: none;
    }
    
    .action-buttons-group {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 2px;
    }
    
    .btn-xs {
        padding: 2px 4px;
        font-size: 0.65rem;
    }
    
    .deleted-policies-banner {
        flex-direction: column;
        text-align: center;
        padding: var(--spacing-md);
    }
    
    .banner-actions {
        margin-top: var(--spacing-md);
    }
}

@media (max-width: 480px) {
    .stat-card {
        flex-direction: column;
        text-align: center;
    }

    .action-buttons-group {
        justify-content: flex-start;
    }
    
    .actions-column {
        width: 100px;
    }
}

/* Loading States */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid var(--outline-variant);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Animations */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.animate-slide-in {
    animation: slideIn var(--transition-base);
}

.animate-fade-in {
    animation: fadeIn var(--transition-base);
}

/* Print Styles */
@media print {
    .modern-crm-container {
        padding: 0;
        background: white;
    }
    
    .crm-header,
    .filters-section,
    .dashboard-section,
    .action-buttons-group,
    .deleted-policies-banner {
        display: none;
    }
    
    .table-section {
        box-shadow: none;
        border: none;
    }
    
    .policies-table {
        width: 100%;
    }
    
    .policies-table th:last-child,
    .policies-table td:last-child {
        display: none;
    }
}



/* Çift Yönlü Kaydırma Çubuğu Çözümü */
@media (max-width: 1400px) {
    /* Ana tablo konteyner düzenlemeleri */
    .table-container {
        position: relative;
        overflow-x: auto;
        margin-bottom: 0 !important;
    }

    /* Üst kaydırma çubuğu için yeni bir konteyner */
    .table-container::before {
        content: '';
        display: block;
        height: 15px;
        width: 100%;
        background: linear-gradient(to bottom, rgba(248, 249, 250, 1) 50%, rgba(248, 249, 250, 0) 100%);
        position: sticky;
        top: 0;
        z-index: 2;
    }

    /* Üst kaydırma çubuğu */
    .table-header-scroll {
        overflow-x: auto;
        overflow-y: hidden;
        position: sticky;
        top: 0;
        z-index: 3;
        margin-bottom: -15px;
        height: 15px;
    }

    /* "Görünmez" içerik - kaydırma çubuğunun genişliğini tablo ile eşleştirir */
    .table-header-scroll div {
        height: 1px;
        width: 100%;
    }

    /* Kaydırma çubuğu stillemesi */
    .table-container::-webkit-scrollbar,
    .table-header-scroll::-webkit-scrollbar {
        height: 8px;
    }

    .table-container::-webkit-scrollbar-thumb,
    .table-header-scroll::-webkit-scrollbar-thumb {
        background-color: #b0b0b0;
        border-radius: 4px;
    }

    .table-container::-webkit-scrollbar-track,
    .table-header-scroll::-webkit-scrollbar-track {
        background-color: #f0f0f0;
    }

    /* Politik tablo genişliği */
    .policies-table {
        min-width: 1200px; /* Daha tutarlı davranış için tabloyu sabit genişliğe ayarladım */
    }
}



</style>

<script>
/**
 * Modern Policies Management JavaScript v5.1.0 - PREMIUM EDITION
 * @author anadolubirlik
 * @date 2025-05-29 12:21:38
 * @description Enhanced with soft delete, cancellation reasons, and policy restoration
 */

class ModernPoliciesApp {
    constructor() {
        this.activeFilterCount = <?php echo $active_filter_count; ?>;
        this.statisticsData = <?php echo json_encode($statistics); ?>;
        this.isInitialized = false;
        this.showDeletedMode = <?php echo $policy_manager->isShowDeletedMode() ? 'true' : 'false'; ?>;
        this.version = '5.1.0';
        
        this.init();
    }

    async init() {
        try {
            this.initializeEventListeners();
            this.initializeDateRangePicker();
            this.initializeFilters();
            this.initializeTableFeatures();
            this.initializeModals();
            
            if (typeof Chart !== 'undefined') {
                await this.initializeCharts();
            }
            
            this.isInitialized = true;
            this.logInitialization();
            
            // Check for notification banners
            this.handleNotificationBanners();
            
        } catch (error) {
            console.error('❌ Initialization failed:', error);
            this.showNotification('Uygulama başlatılamadı. Sayfayı yenileyin.', 'error');
        }
    }

    initializeEventListeners() {
        const filterToggle = document.getElementById('filterToggle');
        const filtersSection = document.getElementById('filtersSection');
        
        if (filterToggle && filtersSection) {
            filterToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleFilters(filtersSection, filterToggle);
            });
        }

        const chartsToggle = document.getElementById('chartsToggle');
        const chartsContainer = document.getElementById('chartsContainer');
        
        if (chartsToggle && chartsContainer) {
            chartsToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleCharts(chartsContainer, chartsToggle);
            });
        }

        this.handleDashboardVisibility();
        this.enhanceFormInputs();
        this.enhanceTable();
        this.initKeyboardShortcuts();
        
        // Close notification buttons
        document.querySelectorAll('.notification-close').forEach(button => {
            button.addEventListener('click', () => {
                const notification = button.closest('.notification-banner');
                if (notification) {
                    notification.style.opacity = '0';
                    setTimeout(() => {
                        notification.style.display = 'none';
                    }, 300);
                }
            });
        });
        
        // View delete details buttons
        document.querySelectorAll('.view-delete-details').forEach(button => {
            button.addEventListener('click', () => {
                this.showDeleteDetailsModal(button);
            });
        });
    }
    
    initializeModals() {
        // Close modals on background click
        const modals = document.querySelectorAll('.policy-modal');
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeModal(modal);
                }
            });
            
            const closeBtn = modal.querySelector('.close-modal');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    this.closeModal(modal);
                });
            }
            
            const closeBtns = modal.querySelectorAll('.close-modal-btn');
            closeBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    this.closeModal(modal);
                });
            });
            
            // Restore button functionality
            const restoreBtn = modal.querySelector('#restore-policy-btn');
            if (restoreBtn) {
                restoreBtn.addEventListener('click', () => {
                    const policyId = modal.getAttribute('data-policy-id');
                    if (policyId) {
                        if (confirm('Bu poliçeyi geri getirmek istediğinizden emin misiniz?')) {
                            window.location.href = '?view=policies&action=restore&id=' + policyId + '&_wpnonce=<?php echo wp_create_nonce('restore_policy_'); ?>' + policyId;
                        }
                    }
                });
            }
        });
    }
    
    showDeleteDetailsModal(button) {
        const modal = document.getElementById('deleteDetailsModal');
        if (!modal) return;
        
        const policyId = button.getAttribute('data-id');
        const policyNumber = button.getAttribute('data-policy');
        const deleteDate = button.getAttribute('data-date');
        const deleteUser = button.getAttribute('data-user');
        
        modal.setAttribute('data-policy-id', policyId);
        
        document.getElementById('modal-policy-number').textContent = policyNumber;
        document.getElementById('modal-delete-date').textContent = deleteDate;
        document.getElementById('modal-delete-user').textContent = deleteUser || 'Bilinmiyor';
        
        modal.style.display = 'flex';
    }
    
    closeModal(modal) {
        modal.style.display = 'none';
    }

    handleNotificationBanners() {
        const notifications = document.querySelectorAll('.notification-banner');
        if (notifications.length > 0) {
            setTimeout(() => {
                notifications.forEach(notification => {
                    notification.style.opacity = '0';
                    setTimeout(() => {
                        notification.style.display = 'none';
                    }, 300);
                });
            }, 5000);
        }
    }

    toggleFilters(filtersSection, filterToggle) {
        const isHidden = filtersSection.classList.contains('hidden');
        const chevron = filterToggle.querySelector('.chevron');
        const dashboardSection = document.getElementById('dashboardSection');
        
        if (isHidden) {
            filtersSection.classList.remove('hidden');
            filtersSection.classList.add('animate-slide-in');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
            if (dashboardSection && !this.showDeletedMode) dashboardSection.style.display = 'none';
        } else {
            filtersSection.classList.add('hidden');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
            if (dashboardSection && this.activeFilterCount === 0 && !this.showDeletedMode) {
                dashboardSection.style.display = 'block';
            }
        }
    }

    toggleCharts(chartsContainer, chartsToggle) {
        const isHidden = chartsContainer.style.display === 'none';
        const icon = chartsToggle.querySelector('i');
        
        if (isHidden) {
            chartsContainer.style.display = 'block';
            chartsContainer.classList.add('animate-slide-in');
            if (icon) icon.className = 'fas fa-chevron-up';
        } else {
            chartsContainer.style.display = 'none';
            if (icon) icon.className = 'fas fa-chevron-down';
        }
    }

    handleDashboardVisibility() {
        const dashboardSection = document.getElementById('dashboardSection');
        const filtersSection = document.getElementById('filtersSection');
        
        if ((this.activeFilterCount > 0 || this.showDeletedMode) && dashboardSection) {
            dashboardSection.style.display = 'none';
            
            if (filtersSection && this.activeFilterCount > 0) {
                filtersSection.classList.remove('hidden');
            }
        }
    }

    initializeDateRangePicker() {
        // jQuery dependency check
        if (typeof $ === 'undefined') {
            console.warn('jQuery not available for DateRangePicker');
            return;
        }
        
        if (typeof moment === 'undefined') {
            console.warn('Moment.js not available for DateRangePicker');
            return;
        }
        
        if (!$.fn.daterangepicker) {
            console.warn('DateRangePicker plugin not available');
            return;
        }

        try {
            // Turkish moment localization
            moment.locale('tr', {
                months: [
                    'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
                    'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'
                ],
                monthsShort: [
                    'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz',
                    'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'
                ],
                weekdays: [
                    'Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'
                ],
                weekdaysShort: ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'],
                weekdaysMin: ['Pz', 'Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct'],
                week: {
                    dow: 1 // Monday is the first day of the week
                }
            });

            const $dateRange = $('#date_range');
            if ($dateRange.length === 0) {
                console.warn('Date range input not found');
                return;
            }

            $dateRange.daterangepicker({
                autoUpdateInput: false,
                opens: 'left',
                showDropdowns: true,
                showWeekNumbers: true,
                timePicker: false,
                locale: {
                    format: 'DD/MM/YYYY',
                    separator: ' - ',
                    applyLabel: 'Uygula',
                    cancelLabel: 'Temizle',
                    fromLabel: 'Başlangıç',
                    toLabel: 'Bitiş',
                    customRangeLabel: 'Özel Aralık',
                    weekLabel: 'H',
                    daysOfWeek: ['Pz', 'Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct'],
                    monthNames: [
                        'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
                        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'
                    ],
                    firstDay: 1
                },
                ranges: {
                    'Bugün': [moment(), moment()],
                    'Dün': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Son 7 Gün': [moment().subtract(6, 'days'), moment()],
                    'Son 30 Gün': [moment().subtract(29, 'days'), moment()],
                    'Bu Ay': [moment().startOf('month'), moment().endOf('month')],
                    'Geçen Ay': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                    'Bu Yıl': [moment().startOf('year'), moment().endOf('year')],
                    'Geçen Yıl': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
                }
            });

            $dateRange.on('apply.daterangepicker', function(ev, picker) {
                const startDate = picker.startDate.format('DD/MM/YYYY');
                const endDate = picker.endDate.format('DD/MM/YYYY');
                $(this).val(startDate + ' - ' + endDate);
                $(this).trigger('change');
                console.log('Date range applied:', startDate, '-', endDate);
            });

            $dateRange.on('cancel.daterangepicker', function() {
                $(this).val('');
                $(this).trigger('change');
                console.log('Date range cleared');
            });

            // Set initial value if exists
            const initialValue = $dateRange.val();
            if (initialValue && initialValue.includes(' - ')) {
                const dates = initialValue.split(' - ');
                if (dates.length === 2) {
                    const startDate = moment(dates[0], 'DD/MM/YYYY');
                    const endDate = moment(dates[1], 'DD/MM/YYYY');
                    if (startDate.isValid() && endDate.isValid()) {
                        $dateRange.data('daterangepicker').setStartDate(startDate);
                        $dateRange.data('daterangepicker').setEndDate(endDate);
                        console.log('Initial date range set:', startDate.format('DD/MM/YYYY'), '-', endDate.format('DD/MM/YYYY'));
                    }
                }
            }

            console.log('✅ DateRangePicker initialized successfully');
        } catch (error) {
            console.error('❌ DateRangePicker initialization failed:', error);
        }
    }

    initializeFilters() {
        this.enhanceSelectBoxes();
        this.addFilterCounting();
        
        // Toggle filters and checkbox interactions
        const showCancelled = document.querySelector('input[name="show_cancelled"]');
        const showPassive = document.querySelector('input[name="show_passive"]');
        
        if (showCancelled && showPassive) {
            showCancelled.addEventListener('change', function() {
                if (this.checked) {
                    showPassive.checked = true;
                }
            });
        }
    }

    enhanceSelectBoxes() {
        const selects = document.querySelectorAll('.form-select');
        selects.forEach(select => {
            if (select.options.length > 10) {
                select.setAttribute('data-live-search', 'true');
                select.setAttribute('data-size', '8');
            }
        });
    }

    addFilterCounting() {
        const filterInputs = document.querySelectorAll('.filters-form input, .filters-form select');
        const filterToggle = document.getElementById('filterToggle');
        
        filterInputs.forEach(input => {
            input.addEventListener('change', this.debounce(() => {
                const count = this.countActiveFilters();
                this.updateFilterCount(filterToggle, count);
            }, 300));
        });
    }

    countActiveFilters() {
        const filterInputs = document.querySelectorAll('.filters-form input, .filters-form select');
        let count = 0;
        
        filterInputs.forEach(input => {
            if (input.type === 'hidden') return;
            if (input.type === 'checkbox' && input.checked) count++;
            else if (input.value && input.value !== '0' && input.value !== '') count++;
        });
        
        return count;
    }

    updateFilterCount(filterToggle, count) {
        if (!filterToggle) return;
        
        let countElement = filterToggle.querySelector('.filter-count');
        
        if (count > 0) {
            if (!countElement) {
                countElement = document.createElement('span');
                countElement.className = 'filter-count';
                filterToggle.insertBefore(countElement, filterToggle.querySelector('.chevron'));
            }
            countElement.textContent = count;
        } else if (countElement) {
            countElement.remove();
        }
    }

    async initializeCharts() {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded, skipping charts initialization');
            return;
        }
        try {
            Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#49454f';
            Chart.defaults.plugins.legend.position = 'bottom';
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
            Chart.defaults.plugins.legend.labels.padding = 20;
            Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            Chart.defaults.plugins.tooltip.titleColor = '#ffffff';
            Chart.defaults.plugins.tooltip.bodyColor = '#ffffff';
            Chart.defaults.plugins.tooltip.cornerRadius = 8;

            await this.createChartsContainer();
            this.renderCharts();
        } catch (error) {
            console.error('Charts initialization failed:', error);
        }
    }

    async createChartsContainer() {
        const chartsContainer = document.getElementById('chartsContainer');
        if (!chartsContainer) return;

        const chartGrid = chartsContainer.querySelector('.chart-grid');
        if (chartGrid) chartGrid.remove();

        const newChartGrid = document.createElement('div');
        newChartGrid.className = 'chart-grid';
        
        const charts = [
            { id: 'policyStatusChart', title: 'Poliçe Durumları', type: 'doughnut' },
            { id: 'policyTypeChart', title: 'Poliçe Türleri', type: 'pie' },
            { id: 'insuranceCompanyChart', title: 'Sigorta Şirketleri', type: 'bar' },
            { id: 'monthlyTrendChart', title: 'Aylık Trend', type: 'line' }
        ];

        charts.forEach(chart => {
            const chartItem = document.createElement('div');
            chartItem.className = 'chart-item';
            chartItem.innerHTML = `
                <h4>${chart.title}</h4>
                <div class="chart-canvas">
                    <canvas id="${chart.id}"></canvas>
                </div>
            `;
            newChartGrid.appendChild(chartItem);
        });

        chartsContainer.appendChild(newChartGrid);
        newChartGrid.classList.add('animate-fade-in');
    }

    renderCharts() {
        this.renderPolicyStatusChart();
        this.renderPolicyTypeChart();
        this.renderInsuranceCompanyChart();
        this.renderMonthlyTrendChart();
    }

    renderPolicyStatusChart() {
        const ctx = document.getElementById('policyStatusChart');
        if (!ctx) return;

        const data = {
            labels: ['Aktif', 'Pasif', 'İptal', 'Silinmiş'],
            datasets: [{
                data: [
                    this.statisticsData.active || 0,
                    this.statisticsData.passive || 0,
                    this.statisticsData.cancelled || 0,
                    this.statisticsData.deleted || 0
                ],
                backgroundColor: [
                    '#2e7d32',
                    '#757575',
                    '#d32f2f',
                    '#424242'
                ],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverBorderWidth: 4,
                hoverOffset: 8
            }]
        };

        new Chart(ctx, {
            type: 'doughnut',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value * 100) / total) : 0;
                                return `${context.label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    animateRotate: true,
                    duration: 1000
                }
            }
        });
    }

    renderPolicyTypeChart() {
        const ctx = document.getElementById('policyTypeChart');
        if (!ctx) return;

        const data = {
            labels: ['Kasko', 'Trafik', 'Konut', 'DASK', 'Sağlık', 'Hayat'],
            datasets: [{
                data: [30, 25, 20, 15, 10, 8],
                backgroundColor: [
                    '#1976d2',
                    '#388e3c',
                    '#f57c00',
                    '#7b1fa2',
                    '#c2185b',
                    '#00796b'
                ],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 6
            }]
        };

        new Chart(ctx, {
            type: 'pie',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value * 100) / total) : 0;
                                return `${context.label}: ${value} adet (${percentage}%)`;
                            }
                        }
                    }
                },
                animation: {
                    duration: 1200
                }
            }
        });
    }

    renderInsuranceCompanyChart() {
        const ctx = document.getElementById('insuranceCompanyChart');
        if (!ctx) return;

        const data = {
            labels: ['Sompo', 'Axa', 'Allianz', 'Zurich', 'HDI', 'Diğer'],
            datasets: [{
                label: 'Poliçe Sayısı',
                data: [40, 30, 25, 20, 15, 12],
                backgroundColor: [
                    'rgba(25, 118, 210, 0.8)',
                    'rgba(56, 142, 60, 0.8)',
                    'rgba(245, 124, 0, 0.8)',
                    'rgba(123, 31, 162, 0.8)',
                    'rgba(194, 24, 91, 0.8)',
                    'rgba(158, 158, 158, 0.8)'
                ],
                borderColor: [
                    '#1976d2',
                    '#388e3c',
                    '#f57c00',
                    '#7b1fa2',
                    '#c2185b',
                    '#9e9e9e'
                ],
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            }]
        };

        new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.label}: ${context.raw} poliçe`;
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });
    }

    renderMonthlyTrendChart() {
        const ctx = document.getElementById('monthlyTrendChart');
        if (!ctx) return;

        const data = {
            labels: ['Ara 2024', 'Oca 2025', 'Şub 2025', 'Mar 2025', 'Nis 2025', 'May 2025'],
            datasets: [{
                label: 'Yeni Poliçeler',
                data: [12, 19, 15, 25, 22, 30],
                fill: true,
                backgroundColor: 'rgba(25, 118, 210, 0.1)',
                borderColor: '#1976d2',
                borderWidth: 3,
                tension: 0.4,
                pointBackgroundColor: '#1976d2',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointHoverBackgroundColor: '#1976d2',
                pointHoverBorderColor: '#ffffff',
                pointHoverBorderWidth: 3
            }]
        };

        new Chart(ctx, {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.label}: ${context.raw} yeni poliçe`;
                            }
                        }
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeOutQuart'
                }
            }
        });
    }

    initializeTableFeatures() {
        this.addTableRowHoverEffects();
        this.addTableSorting();
        this.addTableQuickActions();
    }

    addTableRowHoverEffects() {
        const tableRows = document.querySelectorAll('.policies-table tbody tr');
        
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.transform = 'scale(1.002)';
                row.style.zIndex = '1';
                row.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
            });
            
            row.addEventListener('mouseleave', () => {
                row.style.transform = 'scale(1)';
                row.style.zIndex = 'auto';
                row.style.boxShadow = 'none';
            });
        });
    }

    addTableSorting() {
        const sortableHeaders = document.querySelectorAll('.policies-table th a');
        
        sortableHeaders.forEach(header => {
            header.addEventListener('click', (e) => {
                const table = header.closest('table');
                if (table) {
                    table.classList.add('loading');
                    setTimeout(() => {
                        table.classList.remove('loading');
                    }, 1000);
                }
            });
        });
    }

    addTableQuickActions() {
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'k':
                        e.preventDefault();
                        this.focusTableSearch();
                        break;
                    case 'n':
                        e.preventDefault();
                        window.location.href = '?view=policies&action=new';
                        break;
                }
            }
        });
    }

    enhanceFormInputs() {
        const inputs = document.querySelectorAll('.form-input, .form-select');
        
        inputs.forEach(input => {
            this.addFloatingLabelEffect(input);
            this.addValidationStyling(input);
        });
    }

    addFloatingLabelEffect(input) {
        input.addEventListener('focus', () => {
            input.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', () => {
            if (!input.value) {
                input.parentElement.classList.remove('focused');
            }
        });
        
        if (input.value) {
            input.parentElement.classList.add('focused');
        }
    }

    addValidationStyling(input) {
        input.addEventListener('blur', () => {
            if (input.hasAttribute('required') && !input.value) {
                input.classList.add('error');
            } else {
                input.classList.remove('error');
            }
        });
    }

    enhanceTable() {
        this.addTableExport();
    }

    addTableExport() {
        const tableHeader = document.querySelector('.table-header');
        if (!tableHeader || tableHeader.querySelector('.export-btn')) return;

        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-sm btn-outline export-btn';
        exportBtn.innerHTML = '<i class="fas fa-download"></i> Dışa Aktar';
        exportBtn.addEventListener('click', () => this.exportTableToCSV());
        
        const tableControls = tableHeader.querySelector('.table-controls');
        if (tableControls) {
            tableControls.appendChild(exportBtn);
        } else {
            tableHeader.appendChild(exportBtn);
        }
    }

    exportTableToCSV() {
        const table = document.querySelector('.policies-table');
        if (!table) return;

        const rows = table.querySelectorAll('tr');
        const csvContent = [];
        
        rows.forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const rowData = [];
            cols.forEach(col => {
                if (!col.classList.contains('actions-column') && !col.classList.contains('actions')) {
                    rowData.push('"' + col.textContent.trim().replace(/"/g, '""') + '"');
                }
            });
            csvContent.push(rowData.join(','));
        });

        const csvString = csvContent.join('\n');
        const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            const fileName = this.showDeletedMode ? 'deleted_policies_' : 'policies_';
            link.setAttribute('href', url);
            link.setAttribute('download', `${fileName}${new Date().getTime()}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }

            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'f':
                        e.preventDefault();
                        this.toggleFiltersShortcut();
                        break;
                    case 'r':
                        e.preventDefault();
                        this.refreshPage();
                        break;
                }
            }

            if (e.key === 'Escape') {
                const openModal = document.querySelector('.policy-modal[style*="display: flex"]');
                if (openModal) {
                    this.closeModal(openModal);
                } else {
                    this.closeFilters();
                }
            }
        });
    }

    toggleFiltersShortcut() {
        const filterToggle = document.getElementById('filterToggle');
        if (filterToggle) {
            filterToggle.click();
        }
    }

    refreshPage() {
        window.location.reload();
    }

    focusTableSearch() {
        const policyNumberFilter = document.getElementById('policy_number');
        if (policyNumberFilter) {
            policyNumberFilter.focus();
            // If the filter section is hidden, show it
            const filtersSection = document.getElementById('filtersSection');
            if (filtersSection && filtersSection.classList.contains('hidden')) {
                const filterToggle = document.getElementById('filterToggle');
                if (filterToggle) filterToggle.click();
            }
        }
    }

    closeFilters() {
        const filtersSection = document.getElementById('filtersSection');
        if (filtersSection && !filtersSection.classList.contains('hidden')) {
            const filterToggle = document.getElementById('filterToggle');
            if (filterToggle) {
                filterToggle.click();
            }
        }
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    showNotification(message, type = 'info', duration = 5000) {
        // Use SweetAlert2 if available
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: type === 'error' ? 'error' : type === 'warning' ? 'warning' : type === 'success' ? 'success' : 'info',
                title: type === 'error' ? 'Hata' : type === 'warning' ? 'Uyarı' : type === 'success' ? 'Başarılı' : 'Bilgi',
                text: message,
                timer: duration,
                timerProgressBar: true,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
            return;
        }
        
        // Fallback to custom notification
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'}-circle"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            background: ${type === 'success' ? '#4caf50' : type === 'error' ? '#f44336' : '#2196f3'};
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            animation: slideInRight 0.3s ease;
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        }, duration);
        
        notification.querySelector('.notification-close').addEventListener('click', () => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        });
    }

    logInitialization() {
        console.log(`🚀 Modern Policies App v${this.version} - PREMIUM EDITION`);
        console.log('👤 User: anadolubirlik');
        console.log('⏰ Current Time: 2025-05-29 12:25:48 UTC');
        console.log('📊 Statistics:', this.statisticsData);
        console.log('🔍 Active Filters:', this.activeFilterCount);
        console.log('✅ All enhancements completed:');
        console.log('  ✓ Per-page selection implemented');
        console.log('  ✓ Soft delete implemented');
        console.log('  ✓ Cancellation reasons added');
        console.log('  ✓ Deleted policies management');
        console.log('  ✓ Action buttons redesigned');
        console.log('  ✓ Default sorting by creation date');
        console.log('🎯 System is production-ready and enhanced');
    }
}

// PER PAGE SELECTION FUNCTION
function updatePerPage(newPerPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', newPerPage);
    url.searchParams.delete('paged'); // Reset to first page
    window.location.href = url.toString();
}

// Çift yönlü kaydırma çubuğu senkronizasyonu
document.addEventListener('DOMContentLoaded', function() {
    const tableContainer = document.querySelector('.table-container');
    const headerScroll = document.querySelector('.table-header-scroll');
    
    if (tableContainer && headerScroll) {
        // Tablo genişliğini header scrollbar içeriğine uygula
        const tableWidth = document.querySelector('.policies-table').scrollWidth;
        headerScroll.querySelector('div').style.width = tableWidth + 'px';
        
        // Scroll senkronizasyonu
        tableContainer.addEventListener('scroll', function() {
            headerScroll.scrollLeft = tableContainer.scrollLeft;
        });
        
        headerScroll.addEventListener('scroll', function() {
            tableContainer.scrollLeft = headerScroll.scrollLeft;
        });
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        .notification-content {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }
        .notification-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            opacity: 0.8;
        }
        .notification-close:hover {
            opacity: 1;
            background: rgba(255,255,255,0.1);
        }
        .export-btn {
            margin-left: 12px;
        }
        
        /* Enhanced Action Buttons Tooltip */
        .btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 4px;
        }
        
        /* Enhanced Dropdown Animation */
        .dropdown-menu {
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.15s ease;
        }
        
        .dropdown:hover .dropdown-menu {
            opacity: 1;
            transform: translateY(0);
        }
    `;
    document.head.appendChild(style);

    // Initialize the app
    window.modernPoliciesApp = new ModernPoliciesApp();
    
    // Global utility functions
    window.PoliciesUtils = {
        formatCurrency: (amount) => {
            return new Intl.NumberFormat('tr-TR', {
                style: 'currency',
                currency: 'TRY'
            }).format(amount);
        },
        
        formatDate: (date) => {
            return new Intl.DateTimeFormat('tr-TR').format(new Date(date));
        },
        
        confirmAction: (message) => {
            if (typeof Swal !== 'undefined') {
                return new Promise((resolve) => {
                    Swal.fire({
                        title: 'Emin misiniz?',
                        text: message,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Evet',
                        cancelButtonText: 'İptal',
                        confirmButtonColor: '#d33',
                        reverseButtons: true
                    }).then((result) => {
                        resolve(result.isConfirmed);
                    });
                });
            } else {
                return confirm(message);
            }
        },
        
        showNotification: (message, type = 'info') => {
            if (window.modernPoliciesApp) {
                window.modernPoliciesApp.showNotification(message, type);
            }
        },
        
        updatePerPage: updatePerPage
    };
    
    // Add enhanced keyboard shortcuts help
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F1') {
            e.preventDefault();
            window.modernPoliciesApp.showNotification(
                'Klavye Kısayolları: Ctrl+F (Filtre), Ctrl+N (Yeni), Ctrl+R (Yenile), ESC (Kapat)', 
                'info', 
                8000
            );
        }
    });

    console.log('📋 Enhanced Premium Policies Management System Ready!');
    console.log('🔧 All requested improvements implemented:');
    console.log('  1. ✅ Soft delete implementation');
    console.log('  2. ✅ Cancellation reasons');
    console.log('  3. ✅ UI harmonization across modules');
    console.log('  4. ✅ Deleted policies management');
    console.log('  5. ✅ Policy restoration functionality');
    console.log('💡 Press F1 for keyboard shortcuts help');
});
</script>

<?php
// Form include'ları (Güvenli include)
if (isset($_GET['action'])) {
    $action_param = sanitize_key($_GET['action']);
    $policy_id_param = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (in_array($action_param, array('view', 'new', 'edit', 'renew', 'cancel'))) {
        $include_file = '';
        if ($action_param === 'view' && $policy_id_param > 0) {
            $include_file = 'policies-view.php';
        } elseif (in_array($action_param, array('new', 'edit', 'renew', 'cancel'))) {
            $include_file = 'policies-form.php';
        }

        if ($include_file && file_exists(plugin_dir_path(__FILE__) . $include_file)) {
            try {
                include_once(plugin_dir_path(__FILE__) . $include_file);
            } catch (Exception $e) {
                echo '<div class="error-notice">Form yüklenirken hata oluştu: ' . esc_html($e->getMessage()) . '</div>';
            }
        }
    }
}
?>

</body>
</html>