<?php
/**
 * API Base Controller and model for catalog side of OpenCart 
 * 
 * Provides core functionality for OpenCart Catalog API:
 * - Multi-version OpenCart support (OC2, OC3, OC4)
 * - VQMod and OCMOD detection
 * - Automatic file path resolution
 * - API authentication
 * - Catalog model/controller loading
 * 
 * @package    OpenCart Dynamic API
 */

class ControllerApiBaseCatalog extends Controller {
    protected $apiKey;
    protected $apiUser;
    protected $catalogPath;
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
    protected function detectOCVersion() {  // Changed from private to protected
        if (defined('VERSION')) {
            $this->ocVersion = VERSION;
        } else {
            $this->ocVersion = '2.0.0.0';
        }
    }
    
    /**
     * Check if OpenCart 2.x
     */
    protected function isOC2() {  // Changed from private to protected
        return version_compare($this->ocVersion, '2.0.0.0', '>=') && 
               version_compare($this->ocVersion, '3.0.0.0', '<');
    }
    
    /**
     * Check if OpenCart 3.x
     */
    protected function isOC3() {  // Changed from private to protected
        return version_compare($this->ocVersion, '3.0.0.0', '>=') && 
               version_compare($this->ocVersion, '4.0.0.0', '<');
    }
    
    /**
     * Check if OpenCart 4.x
     */
    protected function isOC4() {  // Changed from private to protected
        return version_compare($this->ocVersion, '4.0.0.0', '>=');
    }
    
    /**
     * Detect all system paths
     */
    protected function detectPaths() {  // Changed from private to protected
        // Step 1: Detect Catalog directory (always DIR_APPLICATION)
        $this->detectCatalogPath();
        
        // Step 2: Detect OCMOD modification path
        $this->detectModificationPath();
        
        // Step 3: Detect VQMod path
        $this->detectVQModPath();
    }
    
    /**
     * Detect Catalog directory path
     * 
     * Catalog path is always DIR_APPLICATION
     */
    protected function detectCatalogPath() {  // Changed from private to protected
        $this->catalogPath = DIR_APPLICATION;
    }
    
    /**
     * Detect OCMOD modification path
     * 
     * Searches in different locations based on OC version:
     * - OC4: DIR_STORAGE/modification/
     * - OC3: DIR_STORAGE/modification/ or system/storage/modification/
     * - OC2: system/modification/
     */
    protected function detectModificationPath() {  // Changed from private to protected
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
    protected function detectVQModPath() {  // Changed from private to protected
        $rootPath = $this->isOC4() ? 
                   (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname(DIR_APPLICATION))) :
                   dirname(DIR_APPLICATION);
        
        $possiblePaths = [
            $rootPath . '/vqmod/vqcache/',
            $rootPath . '/vqmod/vqcache/catalog/',
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
    protected function detectModificationType() {  // Changed from private to protected
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
    protected function isVQModActive() {  // ✅ Changed from private to protected
        // Method 1: VQMod class exists
        if (class_exists('VQMod')) {
            return true;
        }
        
        // Method 2: vqmod.php file exists
        $rootPath = $this->isOC4() ? 
                   (defined('DIR_OPENCART') ? DIR_OPENCART : dirname(dirname(DIR_APPLICATION))) :
                   dirname(DIR_APPLICATION);
        
        $vqmodFile = $rootPath . '/vqmod/vqmod.php';
        if (file_exists($vqmodFile)) {
            return true;
        }
        
        // Method 3: vqcache folder has cached files
        if (is_dir($this->vqmodPath)) {
            $files = glob($this->vqmodPath . 'vq2-*.php');
            if (!empty($files)) {
                return true;
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
    protected function isOCModActive() {  // ✅ Changed from private to protected
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
            // Check catalog modifications
            $catalogModPath = $this->modificationPath . 'catalog/';
            if (is_dir($catalogModPath)) {
                $files = array_merge(
                    glob($catalogModPath . 'controller/*/*.php'),
                    glob($catalogModPath . 'model/*/*.php')
                );
                if (!empty($files)) {
                    return true;
                }
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
     * 3. Original file in catalog directory
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
        $defaultPath = $this->catalogPath . $relativePath;
        $searchedPaths[] = $defaultPath;
        if (file_exists($defaultPath)) {
            $foundPaths[] = $defaultPath;
        }
        
        // Debug logging
        if (empty($foundPaths) && defined('DEBUG') && DEBUG) {
            error_log("API Base Catalog: No file found for $route ($type)");
            error_log("Searched paths: " . print_r($searchedPaths, true));
        }
        
        // Return first found path or default
        return !empty($foundPaths) ? $foundPaths[0] : $defaultPath;
    }
    
    /**
     * Get possible VQMod file paths
     * 
     * VQMod uses different naming patterns:
     * - vq2-catalog_controller_catalog_product.php
     * - vq2-catalog_model_catalog_product.php
     * - catalog_controller_catalog_product.php
     * 
     * @param string $relativePath Relative path (e.g., 'controller/catalog/product.php')
     * @return array List of possible VQMod paths
     */
    protected function getVQModPaths($relativePath) {  // ✅ Changed from private to protected
        $paths = [];
        
        // VQMod naming formats
        $formats = [
            'vq2-catalog_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
            'vq2-catalog_' . str_replace('/', '_', $relativePath),
            'vq2_catalog_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
            'catalog_' . str_replace(['/', '.php'], ['_', ''], $relativePath) . '.php',
        ];
        
        foreach ($formats as $format) {
            $paths[] = $this->vqmodPath . $format;
        }
        
        // Direct VQMod path
        $paths[] = $this->vqmodPath . 'catalog/' . $relativePath;
        
        return $paths;
    }
    
    /**
     * Get possible OCMOD file paths
     * 
     * OCMOD stores modified files in:
     * - modification/catalog/controller/...
     * - modification/catalog/model/...
     * 
     * Location varies by OC version
     * 
     * @param string $relativePath Relative path (e.g., 'controller/catalog/product.php')
     * @return array List of possible OCMOD paths
     */
    protected function getOCModPaths($relativePath) {  // ✅ Changed from private to protected
        $paths = [];
        
        // OCMOD in modification path
        $paths[] = $this->modificationPath . 'catalog/' . $relativePath;
        
        // OpenCart 2 - system/modification
        if ($this->isOC2()) {
            $paths[] = DIR_SYSTEM . 'catalog/' . $relativePath;
        }
        
        // OpenCart 3 - system/storage/modification
        if ($this->isOC3()) {
            $paths[] = DIR_SYSTEM . 'storage/modification/catalog/' . $relativePath;
            if (defined('DIR_STORAGE')) {
                $paths[] = DIR_STORAGE . 'modification/catalog/' . $relativePath;
            }
        }
        
        // OpenCart 4 - system/storage/modification
        if ($this->isOC4()) {
            $paths[] = DIR_STORAGE . 'modification/catalog/' . $relativePath;
            $paths[] = DIR_SYSTEM . 'storage/modification/catalog/' . $relativePath;
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
    protected function getApiKey() {  // Changed from private to protected
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
    protected function getAllHeaders() {  // Changed from private to protected
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
    protected function logApiUsage($api_id) {  // Changed from private to protected
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
     * Load catalog model
     * 
     * Loads model from catalog directory with modification support
     * 
     * @param string $route Model route (e.g., 'catalog/product')
     * @return bool Load success
     */
    protected function loadCatalogModel($route) {
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
            $parts = explode('/', $route);
            $className = 'Model';

            foreach ($parts as $part) {
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
                // Fallback: Try to auto-detect actual class name
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
        
        if (preg_match('/class\s+(Model[A-Za-z0-9_]+)/i', $content, $matches)) {
            return $matches[1];
        }
        
        return false;
    }

    
    /**
     * Load catalog controller
     * 
     * Loads controller from catalog directory with modification support
     * 
     * @param string $route Controller route (e.g., 'product/product')
     * @return object|bool Controller instance or false
     */
    protected function loadCatalogController($route) {
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
     * Usage: GET /index.php?route=api/base_catalog/info&api_key=XXX
     */
    public function info() {
        $vqmodFiles = [];
        if ($this->isVQModActive() && is_dir($this->vqmodPath)) {
            $vqmodFiles = array_slice(glob($this->vqmodPath . 'vq2-catalog*.php'), 0, 5);
        }
        
        $ocmodFiles = [];
        if ($this->isOCModActive() && is_dir($this->modificationPath . 'catalog/')) {
            $ocmodFiles = array_slice(glob($this->modificationPath . 'catalog/*/*.php'), 0, 5);
        }
        
        $this->sendResponse([
            'success' => true,
            'api_type' => 'CATALOG (Frontend)',
            'system_info' => [
                'opencart_version' => $this->ocVersion,
                'opencart_type' => $this->isOC4() ? 'OC4' : ($this->isOC3() ? 'OC3' : 'OC2'),
                'modification_type' => $this->modificationType,
                'catalog_path' => $this->catalogPath,
                'catalog_path_exists' => is_dir($this->catalogPath),
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
                '2_ocmod' => $this->modificationPath . 'catalog/',
                '3_default' => $this->catalogPath
            ]
        ]);
    }
    
    /**
     * Test file path resolution
     * 
     * Tests how a specific file path is resolved
     * Shows all possible paths and which one was selected
     * 
     * Usage: GET /index.php?route=api/base_catalog/testPath&route_test=catalog/product&type=model&api_key=XXX
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
            [$this->catalogPath . $relativePath]
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
            'api_type' => 'CATALOG (Frontend)',
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
                '3' => 'Original catalog files'
            ]
        ]);
    }
}
