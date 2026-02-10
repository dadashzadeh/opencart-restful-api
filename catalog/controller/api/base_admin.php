<?php
/**
 * API Base Controller and model for admin side of OpenCart 
 * 
 * Provides core functionality for OpenCart Admin API:
 * - Multi-version OpenCart support (OC2, OC3, OC4)
 * - VQMod and OCMOD detection
 * - Automatic file path resolution
 * - API authentication
 * - Admin model/controller loading
 * 
 * @package    OpenCart Dynamic API
 */

class ControllerApiBaseAdmin extends Controller {
    protected $apiKey;
    protected $apiUser;
    protected $adminPath;
    protected $modificationType = null;
    protected $ocVersion;
    protected $modificationPath;
    protected $vqmodPath;
    
    /**
     * Constructor - Initialize and authenticate
     */
    public function __construct($registry) {
        parent::__construct($registry);
        
        // Detect OpenCart version
        $this->detectOCVersion();
        
        // Detect all paths
        $this->detectPaths();
        
        // Detect modification type
        $this->detectModificationType();
        
        // Authenticate API request
        if (!$this->authenticate()) {
            $this->sendResponse([
                'success' => false,
                'error' => 'Authentication failed',
                'hint' => 'Provide api_key parameter or X-API-Key header'
            ], 401);
            exit;
        }
    }
    
    /**
     * Detect OpenCart version
     */
    private function detectOCVersion() {
        if (defined('VERSION')) {
            $this->ocVersion = VERSION;
        } else {
            $this->ocVersion = '2.0.0.0';
        }
    }
    
    /**
     * Check if OpenCart 2.x
     */
    private function isOC2() {
        return version_compare($this->ocVersion, '2.0.0.0', '>=') && 
               version_compare($this->ocVersion, '3.0.0.0', '<');
    }
    
    /**
     * Check if OpenCart 3.x
     */
    private function isOC3() {
        return version_compare($this->ocVersion, '3.0.0.0', '>=') && 
               version_compare($this->ocVersion, '4.0.0.0', '<');
    }
    
    /**
     * Check if OpenCart 4.x
     */
    private function isOC4() {
        return version_compare($this->ocVersion, '4.0.0.0', '>=');
    }
    
    /**
     * Detect all system paths
     */
    private function detectPaths() {
        // Step 1: Detect Admin directory
        $this->detectAdminPath();
        
        // Step 2: Detect OCMOD modification path
        $this->detectModificationPath();
        
        // Step 3: Detect VQMod path
        $this->detectVQModPath();
    }
    
    /**
     * Detect Admin directory path
     * 
     * Tries multiple methods:
     * 1. DIR_ADMIN constant (if defined)
     * 2. Relative to catalog path
     * 3. Manual search for renamed admin folders
     */
    private function detectAdminPath() {
        // Priority 1: Use defined constant
        if (defined('DIR_ADMIN')) {
            $this->adminPath = DIR_ADMIN;
            return;
        }
        
        // Priority 2: Calculate relative to catalog
        $catalogPath = DIR_APPLICATION;
        
        if ($this->isOC4()) {
            // OpenCart 4: admin is in admin/
            $this->adminPath = defined('DIR_OPENCART') ? 
                              DIR_OPENCART . 'admin/' : 
                              dirname(dirname($catalogPath)) . '/admin/';
        } else {
            // OpenCart 2 & 3
            $this->adminPath = dirname($catalogPath) . '/admin/';
        }
        
        // Priority 3: Manual search if not found
        if (!is_dir($this->adminPath)) {
            $rootPath = $this->isOC4() ? 
                       (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname($catalogPath))) :
                       dirname($catalogPath);
            
            // Common admin folder names
            $possibleNames = ['admin', 'administrator', 'backend', 'control', 'panel'];
            
            foreach ($possibleNames as $name) {
                $testPath = $rootPath . '/' . $name . '/';
                if (is_dir($testPath) && file_exists($testPath . 'index.php')) {
                    $this->adminPath = $testPath;
                    return;
                }
            }
        }
    }
    
    /**
     * Detect OCMOD modification path
     * 
     * Searches in different locations based on OC version:
     * - OC4: DIR_STORAGE/modification/
     * - OC3: DIR_STORAGE/modification/ or system/storage/modification/
     * - OC2: system/modification/
     */
    private function detectModificationPath() {
        $paths = [];
        
        if ($this->isOC4()) {
            // OpenCart 4
            $paths[] = DIR_STORAGE . 'modification/';
            $paths[] = DIR_SYSTEM . 'storage/modification/';
        } elseif ($this->isOC3()) {
            // OpenCart 3
            if (defined('DIR_STORAGE')) {
                $paths[] = DIR_STORAGE . 'modification/';
            }
            $paths[] = DIR_SYSTEM . 'storage/modification/';
            $paths[] = dirname(DIR_APPLICATION) . '/system/storage/modification/';
        } else {
            // OpenCart 2
            if (defined('DIR_MODIFICATION')) {
                $paths[] = DIR_MODIFICATION;
            }
            $paths[] = DIR_SYSTEM . 'modification/';
            $paths[] = DIR_SYSTEM . 'storage/modification/';
        }
        
        // Find first existing path
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $this->modificationPath = $path;
                return;
            }
        }
        
        // Default to first path if none found
        $this->modificationPath = $paths[0];
    }
    
    /**
     * Detect VQMod cache path
     * 
     * VQMod stores modified files in vqmod/vqcache/
     */
    private function detectVQModPath() {
        $rootPath = $this->isOC4() ? 
                   (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname(DIR_APPLICATION))) :
                   dirname(DIR_APPLICATION);
        
        $possiblePaths = [
            $rootPath . '/vqmod/vqcache/',
            $rootPath . '/vqmod/vqcache/admin/',
            dirname($rootPath) . '/vqmod/vqcache/',
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                $this->vqmodPath = $path;
                return;
            }
        }
        
        // Default to first path
        $this->vqmodPath = $possiblePaths[0];
    }
    
    /**
     * Detect active modification system type
     * 
     * Priority:
     * 1. VQMod (if active)
     * 2. OCMOD (if active)
     * 3. Default (no modifications)
     */
    private function detectModificationType() {
        // Priority 1: VQMod
        if ($this->isVQModActive()) {
            $this->modificationType = 'vqmod';
            return;
        }
        
        // Priority 2: OCMOD
        if ($this->isOCModActive()) {
            $this->modificationType = 'ocmod';
            return;
        }
        
        // Priority 3: Default
        $this->modificationType = 'default';
    }
    
    /**
     * Check if VQMod is active
     * 
     * Methods:
     * 1. Check for VQMod class
     * 2. Check for vqmod.php file
     * 3. Check for cached files in vqcache
     */
    private function isVQModActive() {
        // Method 1: VQMod class exists
        if (class_exists('VQMod')) {
            return true;  // ✅ YOU HAVE VQMod INSTALLED
        }
        
        // Method 2: vqmod.php file exists
        $vqmodFile = $rootPath . '/vqmod/vqmod.php';
        if (file_exists($vqmodFile)) {
            return true;  // ✅ VQMod file found
        }
        
        // Method 3: vqcache folder has cached files
        if (is_dir($this->vqmodPath)) {
            $files = glob($this->vqmodPath . 'vq2-*.php');
            if (!empty($files)) {
                return true;  // ✅ VQMod cache files exist
            }
        }
        
        return false;
    }
    
    /**
     * Check if OCMOD is active
     * 
     * Methods:
     * 1. Check modification table in database
     * 2. Check for modified files in modification path
     */
    private function isOCModActive() {
        // Method 1: Check modification table
        try {
            $query = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "modification'");
            if ($query->num_rows) {
                $checkQuery = $this->db->query("SELECT COUNT(*) as total FROM " . DB_PREFIX . "modification WHERE status = 1");
                if ($checkQuery->row['total'] > 0) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // Table doesn't exist or error occurred
        }
        
        // Method 2: Check for modified files
        if (is_dir($this->modificationPath)) {
            // Check admin modifications
            $adminModPath = $this->modificationPath . 'admin/';
            if (is_dir($adminModPath)) {
                $files = array_merge(
                    glob($adminModPath . 'controller/*/*.php'),
                    glob($adminModPath . 'model/*/*.php')
                );
                if (!empty($files)) {
                    return true;
                }
            }
            
            // Check catalog modifications
            $catalogModPath = $this->modificationPath . 'catalog/';
            if (is_dir($catalogModPath)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get correct file path with modification support
     * 
     * Search priority:
     * 1. VQMod cached file (if VQMod active)
     * 2. OCMOD modified file (if OCMOD active)
     * 3. Original file in admin directory
     * 
     * @param string $route Module route (e.g., 'catalog/product')
     * @param string $type File type ('controller' or 'model')
     * @return string Full path to file
     */
    protected function getCorrectFilePath($route, $type = 'controller') {
        $foundPaths = [];
        $searchedPaths = [];
        
        // Build relative path
        if ($type == 'controller') {
            $relativePath = 'controller/' . $route . '.php';
        } elseif ($type == 'model') {
            $relativePath = 'model/' . $route . '.php';
        } else {
            $relativePath = $route;
        }
        
        // ==== Priority 1: VQMod ====
        if ($this->modificationType == 'vqmod' || $this->isVQModActive()) {
            $vqmodFiles = $this->getVQModPaths($relativePath);
            foreach ($vqmodFiles as $vqFile) {
                $searchedPaths[] = $vqFile;
                if (file_exists($vqFile)) {
                    $foundPaths[] = $vqFile;
                }
            }
        }
        
        // ==== Priority 2: OCMOD ====
        if (($this->modificationType == 'ocmod' || $this->isOCModActive()) && empty($foundPaths)) {
            $ocmodFiles = $this->getOCModPaths($relativePath);
            foreach ($ocmodFiles as $ocFile) {
                $searchedPaths[] = $ocFile;
                if (file_exists($ocFile)) {
                    $foundPaths[] = $ocFile;
                }
            }
        }
        
        // ==== Priority 3: Default file ====
        $defaultPath = $this->adminPath . $relativePath;
        $searchedPaths[] = $defaultPath;
        if (file_exists($defaultPath)) {
            $foundPaths[] = $defaultPath;
        }
        
        // Debug logging
        if (empty($foundPaths) && defined('DEBUG') && DEBUG) {
            error_log("API Base: No file found for $route ($type)");
            error_log("Searched paths: " . print_r($searchedPaths, true));
        }
        
        // Return first found path or default
        return !empty($foundPaths) ? $foundPaths[0] : $defaultPath;
    }
    
    /**
     * Get possible VQMod file paths
     * 
     * VQMod uses different naming patterns:
     * - vq2-admin_controller_catalog_product.php
     * - vq2-admin_model_catalog_product.php
     * - admin_controller_catalog_product.php
     * 
     * @param string $relativePath Relative path (e.g., 'controller/catalog/product.php')
     * @return array List of possible VQMod paths
     */
    private function getVQModPaths($relativePath) {
        $paths = [];
        
        // VQMod naming formats
        $formats = [
            'vq2-admin_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
            'vq2-admin_' . str_replace('/', '_', $relativePath),
            'vq2_admin_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
            'admin_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
        ];
        
        foreach ($formats as $format) {
            $paths[] = $this->vqmodPath . $format;
        }
        
        // Direct VQMod path
        $paths[] = $this->vqmodPath . 'admin/' . $relativePath;
        
        return $paths;
    }
    
    /**
     * Get possible OCMOD file paths
     * 
     * OCMOD stores modified files in:
     * - modification/admin/controller/...
     * - modification/admin/model/...
     * 
     * Location varies by OC version
     * 
     * @param string $relativePath Relative path (e.g., 'controller/catalog/product.php')
     * @return array List of possible OCMOD paths
     */
    private function getOCModPaths($relativePath) {
        $paths = [];
        
        // OCMOD in modification path
        $paths[] = $this->modificationPath . 'admin/' . $relativePath;
        
        // OpenCart 2 - system/modification
        if ($this->isOC2()) {
            $paths[] = DIR_SYSTEM . 'admin/' . $relativePath;
        }
        
        // OpenCart 3 - system/storage/modification
        if ($this->isOC3()) {
            $paths[] = DIR_SYSTEM . 'storage/modification/admin/' . $relativePath;
            if (defined('DIR_STORAGE')) {
                $paths[] = DIR_STORAGE . 'modification/admin/' . $relativePath;
            }
        }
        
        // OpenCart 4 - system/storage/modification
        if ($this->isOC4()) {
            $paths[] = DIR_STORAGE . 'modification/admin/' . $relativePath;
            $paths[] = DIR_SYSTEM . 'storage/modification/admin/' . $relativePath;
        }
        
        return $paths;
    }
    
    /**
     * Authenticate API request
     * 
     * Checks API key from:
     * 1. X-API-Key header
     * 2. Authorization header (Bearer token)
     * 3. Query string parameter (api_key)
     * 4. POST parameter (api_key)
     * 
     * @return bool Authentication success
     */
    protected function authenticate() {
        // Get API key from various sources
        $apiKey = $this->getApiKey();
        
        if (empty($apiKey)) {
            return false;
        }
        
        // Check in database
        try {
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "api` 
                WHERE `key` = '" . $this->db->escape($apiKey) . "' 
                AND status = '1'");
            
            if ($query->num_rows) {
                $this->apiUser = $query->row;
                
                // Log API usage
                $this->logApiUsage($query->row['api_id']);
                
                return true;
            }
        } catch (Exception $e) {
            error_log("API Authentication Error: " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Get API key from multiple sources
     * 
     * @return string API key or empty string
     */
    private function getApiKey() {
        // 1. From Headers
        $headers = $this->getAllHeaders();
        
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }
        
        if (isset($headers['X-Api-Key'])) {
            return $headers['X-Api-Key'];
        }
        
        if (isset($headers['Authorization'])) {
            // Bearer token format
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        
        // 2. From Query String
        if (isset($this->request->get['api_key'])) {
            return $this->request->get['api_key'];
        }
        
        // 3. From POST
        if (isset($this->request->post['api_key'])) {
            return $this->request->post['api_key'];
        }
        
        return '';
    }
    
    /**
     * Get all HTTP headers (cross-server compatible)
     * 
     * Works on servers without getallheaders() function
     * 
     * @return array Associative array of headers
     */
    private function getAllHeaders() {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        // Manual extraction from $_SERVER
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                // Convert HTTP_X_API_KEY to X-Api-Key
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        
        return $headers;
    }
    
    /**
     * Log API usage to database
     * 
     * Stores API call information in api_session table
     * 
     * @param int $api_id API ID from api table
     */
    private function logApiUsage($api_id) {
        try {
            // Check if api_session table exists
            $checkTable = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "api_session'");
            
            if ($checkTable->num_rows) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "api_session` SET 
                    api_id = '" . (int)$api_id . "', 
                    session_id = '" . $this->db->escape(session_id()) . "',
                    ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "', 
                    date_added = NOW(), 
                    date_modified = NOW()");
            }
        } catch (Exception $e) {
            // Ignore if table doesn't exist or error
        }
    }
    
    /**
     * Send JSON response
     * 
     * Sets appropriate headers and outputs JSON
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code (default: 200)
     */
    protected function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Load admin model
     * 
     * Loads model from admin directory with modification support
     * 
     * @param string $route Model route (e.g., 'catalog/product')
     * @return bool Load success
     */
    public function loadAdminModel($route) {
        $key = 'model_' . str_replace('/', '_', $route);
        
        // Check if already loaded
        try {
            $existing = $this->registry->get($key);
            if ($existing) {
                return true;
            }
        } catch (Exception $e) {
            // Model not loaded yet
        }
        
        // Get file path with modification support
        $file = $this->getCorrectFilePath($route, 'model');
        
        if (file_exists($file)) {
            // 🆕 FIXED: Convert route to proper PascalCase class name
            // Example: simple_blog/category → ModelSimpleBlogCategory
            $parts = explode('/', $route);
            $className = 'Model';

            foreach ($parts as $part) {
                // Convert snake_case to PascalCase
                // simple_blog → SimpleBlog
                // category → Category
                $words = explode('_', $part);
                foreach ($words as $word) {
                    $className .= ucfirst($word);
                }
            }

            // Require file if class not defined
            if (!class_exists($className, false)) {
                require_once($file);
            }

            // Instantiate and register
            if (class_exists($className)) {
                $modelInstance = new $className($this->registry);
                $this->registry->set($key, $modelInstance);
                return true;
            } else {
                // 🆕 Fallback: Try to auto-detect actual class name from file
                $detectedClass = $this->detectClassNameFromFile($file);
                if ($detectedClass && class_exists($detectedClass)) {
                    $modelInstance = new $detectedClass($this->registry);
                    $this->registry->set($key, $modelInstance);
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * 🔍 Auto-detect class name from PHP file (fallback safety)
     */
    private function detectClassNameFromFile($file) {
        if (!file_exists($file)) {
            return false;
        }
        
        $content = file_get_contents($file);
        
        // Match: class ModelSomething extends ...
        if (preg_match('/class\s+(Model[A-Za-z0-9_]+)/i', $content, $matches)) {
            return $matches[1];
        }
        
        return false;
    }

        
    /**
     * Load admin controller
     * 
     * Loads controller from admin directory with modification support
     * 
     * @param string $route Controller route (e.g., 'catalog/product')
     * @return object|bool Controller instance or false
     */
    public function loadAdminController($route) {  // ✅ تغییر از protected به public
        // Get file path with modification support
        $file = $this->getCorrectFilePath($route, 'controller');
        
        if (file_exists($file)) {
            require_once($file);
            
            $class = 'Controller' . str_replace('/', '', $route);
            
            if (class_exists($class)) {
                return new $class($this->registry);
            }
        }
        
        return false;
    }
        
    
    /**
     * System information endpoint
     * 
     * Provides debugging information about:
     * - OpenCart version
     * - Modification system status
     * - Path detection results
     * - Sample modified files
     * 
     * Usage: GET /api/base_admin/info?api_key=XXX
     */
    public function info() {
        $vqmodFiles = [];
        if ($this->isVQModActive() && is_dir($this->vqmodPath)) {
            $vqmodFiles = array_slice(glob($this->vqmodPath . 'vq2-*.php'), 0, 5);
        }
        
        $ocmodFiles = [];
        if ($this->isOCModActive() && is_dir($this->modificationPath . 'admin/')) {
            $ocmodFiles = array_slice(glob($this->modificationPath . 'admin/*/*.php'), 0, 5);
        }
        
        $this->sendResponse([
            'success' => true,
            'system_info' => [
                'opencart_version' => $this->ocVersion,
                'opencart_type' => $this->isOC4() ? 'OC4' : ($this->isOC3() ? 'OC3' : 'OC2'),
                'modification_type' => $this->modificationType,
                'admin_path' => $this->adminPath,
                'admin_path_exists' => is_dir($this->adminPath),
                'modification_path' => $this->modificationPath,
                'modification_path_exists' => is_dir($this->modificationPath),
                'vqmod_path' => $this->vqmodPath,
                'vqmod_path_exists' => is_dir($this->vqmodPath),
                'vqmod_active' => $this->isVQModActive(),
                'ocmod_active' => $this->isOCModActive(),
                'dir_application' => DIR_APPLICATION,
                'dir_system' => DIR_SYSTEM,
                'dir_storage' => defined('DIR_STORAGE') ? DIR_STORAGE : 'Not defined',
                'php_version' => phpversion(),
                'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown'
            ],
            'sample_files' => [
                'vqmod_files' => array_map('basename', $vqmodFiles),
                'ocmod_files' => array_map(function($f) { 
                    return str_replace($this->modificationPath, '', $f); 
                }, $ocmodFiles)
            ],
            'paths_priority' => [
                '1_vqmod' => $this->vqmodPath,
                '2_ocmod' => $this->modificationPath . 'admin/',
                '3_default' => $this->adminPath
            ]
        ]);
    }
    
    /**
     * Test file path resolution
     * 
     * Tests how a specific file path is resolved
     * Shows all possible paths and which one was selected
     * 
     * Usage: GET /api/base_admin/testPath?route_test=catalog/product&type=model&api_key=XXX
     */
    public function testPath() {
        $route = isset($this->request->get['route_test']) ? $this->request->get['route_test'] : 'catalog/product';
        $type = isset($this->request->get['type']) ? $this->request->get['type'] : 'model';
        
        // Get selected file path
        $filePath = $this->getCorrectFilePath($route, $type);
        $fileExists = file_exists($filePath);
        
        // Collect all possible paths
        $relativePath = $type . '/' . $route . '.php';
        $allPaths = array_merge(
            $this->getVQModPaths($relativePath),
            $this->getOCModPaths($relativePath),
            [$this->adminPath . $relativePath]
        );
        
        // Build detailed path information
        $pathsInfo = [];
        foreach ($allPaths as $path) {
            $pathsInfo[] = [
                'path' => $path,
                'exists' => file_exists($path),
                'readable' => file_exists($path) && is_readable($path),
                'size' => file_exists($path) ? filesize($path) : 0,
                'modified' => file_exists($path) ? date('Y-m-d H:i:s', filemtime($path)) : null
            ];
        }
        
        $this->sendResponse([
            'success' => true,
            'test_route' => $route,
            'test_type' => $type,
            'selected_file' => $filePath,
            'file_exists' => $fileExists,
            'file_readable' => $fileExists && is_readable($filePath),
            'file_size' => $fileExists ? filesize($filePath) : 0,
            'file_modified' => $fileExists ? date('Y-m-d H:i:s', filemtime($filePath)) : null,
            'all_possible_paths' => $pathsInfo,
            'modification_type' => $this->modificationType,
            'search_priority' => [
                '1' => 'VQMod cache',
                '2' => 'OCMOD modifications',
                '3' => 'Original admin files'
            ]
        ]);
    }
}
