<?php

/**
 * OpenAPI/Swagger Auto-Generator for OpenCart Dynamic API
 * PHP 5.6+ Compatible - Lightweight Static Analysis Version
 */

if (!class_exists('ControllerApiBaseCatalog')) {
    require_once(__DIR__ . '/base_catalog.php');
}

class ControllerApiSwagger extends ControllerApiBaseCatalog {
    
    private $spec = array();
    private $discoveredModules = array();
    private $apiBaseUrl;
    
    /**
     * Main endpoint - returns OpenAPI spec
     */
    public function index() {
        $format = isset($this->request->get['format']) ? $this->request->get['format'] : 'json';
        
        // Build complete OpenAPI spec
        $this->buildSpec();
        
        if ($format === 'yaml') {
            $this->sendYaml();
        } else {
            $this->sendJson();
        }
    }
    
    /**
     * Build complete OpenAPI specification with auto-discovery
     */
    private function buildSpec() {
        $this->apiBaseUrl = $this->getBaseUrl();
        
        // Auto-discover all modules
        $this->discoverAllModules();
        
        $this->spec = array(
            'openapi' => '3.0.0',
            'info' => array(
                'title' => 'OpenCart Dynamic API - Auto-Generated',
                'description' => $this->buildDescription(),
                'version' => '2.0.0',
                'contact' => array(
                    'name' => 'OpenCart Dynamic API',
                    'url' => $this->apiBaseUrl
                )
            ),
            'servers' => array(
                array(
                    'url' => $this->apiBaseUrl,
                    'description' => 'Current Server'
                )
            ),
            'security' => array(
                array('ApiKeyAuth' => array())
            ),
            'components' => array(
                'securitySchemes' => array(
                    'ApiKeyAuth' => array(
                        'type' => 'apiKey',
                        'in' => 'query',
                        'name' => 'api_key',
                        'description' => 'API Key from OpenCart admin panel'
                    )
                ),
                'schemas' => $this->buildSchemas()
            ),
            'paths' => $this->buildAllPaths(),
            'tags' => $this->buildTags()
        );
    }
    
    /**
     * Auto-discover all modules from admin and catalog
     */
    private function discoverAllModules() {
        $this->discoveredModules = array();
        
        // Discover catalog models
        $this->discoveredModules['catalog']['models'] = $this->scanModules('catalog', 'model');
        
        // Discover catalog controllers
        $this->discoveredModules['catalog']['controllers'] = $this->scanModules('catalog', 'controller');
        
        // Discover admin models (if base_admin exists)
        if (file_exists(DIR_APPLICATION . 'controller/api/base_admin.php')) {
            $this->discoveredModules['admin']['models'] = $this->scanModules('admin', 'model');
            $this->discoveredModules['admin']['controllers'] = $this->scanModules('admin', 'controller');
        }
    }
    
    /**
     * Scan modules from a specific side and type
     */
    private function scanModules($side, $type) {
        $modules = array();
        
        // Determine base path
        if ($side === 'catalog') {
            $basePath = $this->catalogPath . $type . DIRECTORY_SEPARATOR;
        } else {
            $adminPath = $this->detectAdminPath();
            $basePath = $adminPath . $type . DIRECTORY_SEPARATOR;
        }
        
        // Normalize base path
        $basePath = $this->normalizePath($basePath);
        
        if (!is_dir($basePath)) {
            return $modules;
        }
        
        // Scan directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            
            // Get full path and normalize
            $fullPath = $this->normalizePath($file->getPathname());
            
            // Extract relative path (remove base path)
            $relativePath = str_replace($basePath, '', $fullPath);
            
            // Remove .php extension
            $module = str_replace('.php', '', $relativePath);
            
            // Convert backslashes to forward slashes (OpenCart standard)
            $module = str_replace(DIRECTORY_SEPARATOR, '/', $module);
            
            // Remove leading/trailing slashes
            $module = trim($module, '/');
            
            // Skip if module name is empty or invalid
            if (empty($module) || strpos($module, ':') !== false) {
                continue;
            }
            
            // Extract methods using static analysis (no loading required)
            $methods = $this->extractMethodsFromFile($fullPath);
            
            if (!empty($methods)) {
                $modules[] = array(
                    'module' => $module,
                    'methods' => $methods,
                    'path' => $fullPath
                );
            }
        }
        
        return $modules;
    }
    
    /**
     * Normalize path (convert slashes to system format)
     */
    private function normalizePath($path) {
        // Replace all slashes with DIRECTORY_SEPARATOR
        $normalized = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        
        // Remove duplicate separators
        $normalized = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR) . '+#', DIRECTORY_SEPARATOR, $normalized);
        
        return $normalized;
    }
    
    /**
     * Extract methods from PHP file (static analysis - no require/include)
     */
    private function extractMethodsFromFile($filePath) {
        $methods = array();
        
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $methods;
        }
        
        // Read file content
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return $methods;
        }
        
        // Extract public function declarations
        preg_match_all(
            '/public\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*?)\)/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );
        
        foreach ($matches as $match) {
            $methodName = $match[1];
            
            // Skip constructor/destructor
            if ($methodName === '__construct' || $methodName === '__destruct') {
                continue;
            }
            
            $paramsString = $match[2];
            $parameters = $this->parseParameters($paramsString);
            
            // Categorize method
            $category = $this->categorizeMethod($methodName);
            
            $methods[$methodName] = array(
                'name' => $methodName,
                'parameters' => $parameters,
                'category' => $category,
                'http_method' => $this->getHttpMethod($category)
            );
        }
        
        return $methods;
    }
    
    /**
     * Parse function parameters from string
     */
    private function parseParameters($paramsString) {
        $parameters = array();
        
        if (empty(trim($paramsString))) {
            return $parameters;
        }
        
        // Split by comma (but not inside arrays/parentheses)
        $params = preg_split('/,(?![^(]*\))/', $paramsString);
        
        foreach ($params as $param) {
            $param = trim($param);
            
            if (empty($param)) {
                continue;
            }
            
            // Check for default value
            $hasDefault = strpos($param, '=') !== false;
            
            // Extract parameter name
            preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $param, $nameMatch);
            
            if (!isset($nameMatch[1])) {
                continue;
            }
            
            $paramName = $nameMatch[1];
            
            $paramInfo = array(
                'name' => $paramName,
                'required' => !$hasDefault,
                'type' => 'mixed'
            );
            
            // Try to extract default value
            if ($hasDefault) {
                preg_match('/=\s*(.+)$/', $param, $defaultMatch);
                if (isset($defaultMatch[1])) {
                    $defaultValue = trim($defaultMatch[1]);
                    $defaultValue = str_replace(array("'", '"'), '', $defaultValue);
                    $paramInfo['default'] = $defaultValue;
                }
            }
            
            $parameters[] = $paramInfo;
        }
        
        return $parameters;
    }
    
    /**
     * Build all API paths dynamically
     */
    private function buildAllPaths() {
        $paths = array();
        
        // System endpoints
        $paths['/index.php'] = $this->buildSystemEndpoints();
        
        // Add discovered modules
        $this->addDiscoveredPaths($paths);
        
        return $paths;
    }
    
    /**
     * Add discovered modules to paths
     */
    private function addDiscoveredPaths(&$paths) {
        foreach (array('catalog', 'admin') as $side) {
            if (!isset($this->discoveredModules[$side])) {
                continue;
            }
            
            foreach (array('models', 'controllers') as $moduleType) {
                if (!isset($this->discoveredModules[$side][$moduleType])) {
                    continue;
                }
                
                foreach ($this->discoveredModules[$side][$moduleType] as $moduleData) {
                    $this->addModulePaths($paths, $side, $moduleType, $moduleData);
                }
            }
        }
    }
    
    /**
     * Add module paths to OpenAPI spec
     */
    private function addModulePaths(&$paths, $side, $type, $moduleData) {
        $module = $moduleData['module'];
        $methods = $moduleData['methods'];
        
        $typeKey = $type === 'models' ? 'model' : 'controller';
        
        foreach ($methods as $methodName => $methodInfo) {
            $operationId = "{$side}_{$typeKey}_" . str_replace('/', '_', $module) . "_{$methodName}";
            $pathKey = "/index.php?route=api/dynamic_{$side}/{$typeKey}&module={$module}&method={$methodName}";
            
            $httpMethod = strtolower($methodInfo['http_method']);
            
            if (!isset($paths[$pathKey])) {
                $paths[$pathKey] = array();
            }
            
            $paths[$pathKey][$httpMethod] = array(
                'tags' => array($this->getTagName($side, $module)),
                'summary' => ucfirst($methodName) . " - {$module}",
                'description' => $this->generateMethodDescription($side, $typeKey, $module, $methodName, $methodInfo),
                'operationId' => $operationId,
                'parameters' => $this->buildMethodParameters($side, $typeKey, $module, $methodName, $methodInfo),
                'responses' => $this->buildMethodResponses()
            );
            
            // Add request body for POST methods
            if ($httpMethod === 'post') {
                $paths[$pathKey][$httpMethod]['requestBody'] = $this->buildRequestBody($methodInfo);
            }
        }
    }
    
    /**
     * Generate method description
     */
    private function generateMethodDescription($side, $type, $module, $methodName, $methodInfo) {
        $desc = "**Module:** {$module}\n\n";
        $desc .= "**Method:** {$methodName}\n\n";
        $desc .= "**Type:** " . ucfirst($type) . "\n\n";
        $desc .= "**Side:** " . ucfirst($side) . "\n\n";
        
        if (!empty($methodInfo['parameters'])) {
            $desc .= "**Parameters:**\n\n";
            foreach ($methodInfo['parameters'] as $param) {
                $required = $param['required'] ? '(required)' : '(optional)';
                $desc .= "- `{$param['name']}` {$required}\n";
            }
        }
        
        return $desc;
    }
    
    /**
     * Build method parameters
     */
    private function buildMethodParameters($side, $type, $module, $methodName, $methodInfo) {
        $params = array(
            array(
                'name' => 'route',
                'in' => 'query',
                'required' => true,
                'schema' => array('type' => 'string'),
                'example' => "api/dynamic_{$side}/{$type}"
            ),
            array(
                'name' => 'module',
                'in' => 'query',
                'required' => true,
                'schema' => array('type' => 'string'),
                'example' => $module
            ),
            array(
                'name' => 'method',
                'in' => 'query',
                'required' => true,
                'schema' => array('type' => 'string'),
                'example' => $methodName
            ),
            array(
                'name' => 'api_key',
                'in' => 'query',
                'required' => true,
                'schema' => array('type' => 'string'),
                'description' => 'Your API key'
            )
        );
        
        // Add method-specific parameters for GET requests
        if ($methodInfo['http_method'] === 'GET' && !empty($methodInfo['parameters'])) {
            foreach ($methodInfo['parameters'] as $param) {
                $params[] = array(
                    'name' => $param['name'],
                    'in' => 'query',
                    'required' => $param['required'],
                    'schema' => array('type' => 'string'),
                    'description' => ucfirst($param['name']) . ' parameter'
                );
            }
        }
        
        return $params;
    }
    
    /**
     * Build request body for POST methods
     */
    private function buildRequestBody($methodInfo) {
        $properties = array();
        
        if (!empty($methodInfo['parameters'])) {
            foreach ($methodInfo['parameters'] as $param) {
                $properties[$param['name']] = array(
                    'type' => 'string',
                    'description' => ucfirst($param['name'])
                );
            }
        }
        
        if (empty($properties)) {
            $properties['data'] = array(
                'type' => 'object',
                'description' => 'Method-specific data'
            );
        }
        
        return array(
            'required' => true,
            'content' => array(
                'application/json' => array(
                    'schema' => array(
                        'type' => 'object',
                        'properties' => $properties
                    )
                )
            )
        );
    }
    
    /**
     * Build method responses
     */
    private function buildMethodResponses() {
        return array(
            '200' => array(
                'description' => 'Successful response',
                'content' => array(
                    'application/json' => array(
                        'schema' => array('$ref' => '#/components/schemas/SuccessResponse')
                    )
                )
            ),
            '400' => array(
                'description' => 'Bad request'
            ),
            '401' => array(
                'description' => 'Authentication failed'
            ),
            '404' => array(
                'description' => 'Module or method not found'
            )
        );
    }
    
    /**
     * Build system endpoints
     */
    private function buildSystemEndpoints() {
        return array(
            'get' => array(
                'tags' => array('System'),
                'summary' => 'System Endpoints',
                'parameters' => array(
                    array(
                        'name' => 'route',
                        'in' => 'query',
                        'required' => true,
                        'schema' => array(
                            'type' => 'string',
                            'enum' => array(
                                'api/swagger',
                                'api/base_catalog/info',
                                'api/base_admin/info',
                                'api/dynamic_catalog/discover',
                                'api/dynamic_admin/discover'
                            )
                        )
                    ),
                    array(
                        'name' => 'api_key',
                        'in' => 'query',
                        'required' => true,
                        'schema' => array('type' => 'string')
                    )
                ),
                'responses' => array(
                    '200' => array(
                        'description' => 'Successful response'
                    )
                )
            )
        );
    }
    
    /**
     * Build common schemas
     */
    private function buildSchemas() {
        return array(
            'SuccessResponse' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'result' => array('type' => 'object'),
                    'meta' => array('type' => 'object')
                )
            ),
            'Error' => array(
                'type' => 'object',
                'properties' => array(
                    'success' => array('type' => 'boolean'),
                    'error' => array('type' => 'string')
                )
            )
        );
    }
    
    /**
     * Build tags for grouping
     */
    private function buildTags() {
        $tags = array(
            array('name' => 'System', 'description' => 'System endpoints')
        );
        
        // Add catalog tags
        if (isset($this->discoveredModules['catalog'])) {
            $categories = array();
            
            foreach (array('models', 'controllers') as $type) {
                if (!isset($this->discoveredModules['catalog'][$type])) {
                    continue;
                }
                
                foreach ($this->discoveredModules['catalog'][$type] as $moduleData) {
                    $module = $moduleData['module'];
                    $parts = explode('/', $module);
                    $category = ucfirst($parts[0]);
                    
                    if (!in_array($category, $categories)) {
                        $categories[] = $category;
                        $tags[] = array(
                            'name' => 'Catalog - ' . $category,
                            'description' => 'Catalog ' . strtolower($category) . ' operations'
                        );
                    }
                }
            }
        }
        
        // Add admin tags
        if (isset($this->discoveredModules['admin'])) {
            $categories = array();
            
            foreach (array('models', 'controllers') as $type) {
                if (!isset($this->discoveredModules['admin'][$type])) {
                    continue;
                }
                
                foreach ($this->discoveredModules['admin'][$type] as $moduleData) {
                    $module = $moduleData['module'];
                    $parts = explode('/', $module);
                    $category = ucfirst($parts[0]);
                    
                    if (!in_array($category, $categories)) {
                        $categories[] = $category;
                        $tags[] = array(
                            'name' => 'Admin - ' . $category,
                            'description' => 'Admin ' . strtolower($category) . ' operations'
                        );
                    }
                }
            }
        }
        
        return $tags;
    }
    
    /**
     * Get tag name for a module
     */
    private function getTagName($side, $module) {
        $parts = explode('/', $module);
        $category = ucfirst($parts[0]);
        return ucfirst($side) . ' - ' . $category;
    }
    
    /**
     * Categorize method by name
     */
    private function categorizeMethod($methodName) {
        if (preg_match('/^get/i', $methodName)) return 'getter';
        if (preg_match('/^(add|insert|create)/i', $methodName)) return 'creator';
        if (preg_match('/^(edit|update)/i', $methodName)) return 'updater';
        if (preg_match('/^(delete|remove)/i', $methodName)) return 'deleter';
        return 'other';
    }
    
    /**
     * Get HTTP method based on category
     */
    private function getHttpMethod($category) {
        $postCategories = array('creator', 'updater', 'deleter');
        if (in_array($category, $postCategories)) {
            return 'POST';
        }
        return 'GET';
    }
    
    /**
     * Build API description
     */
    private function buildDescription() {
        $totalModules = 0;
        $totalMethods = 0;
        
        foreach ($this->discoveredModules as $side => $types) {
            foreach ($types as $type => $modules) {
                $totalModules += count($modules);
                foreach ($modules as $moduleData) {
                    $totalMethods += count($moduleData['methods']);
                }
            }
        }
        
        return "**OpenCart Dynamic API** - Auto-Generated Documentation\n\n" .
               "This API provides dynamic access to all OpenCart models and controllers.\n\n" .
               "**Auto-Discovered:**\n" .
               "- {$totalModules} modules\n" .
               "- {$totalMethods} methods\n\n" .
               "**Features:**\n" .
               "- ✅ Automatic module discovery\n" .
               "- ✅ Static analysis (no loading required)\n" .
               "- ✅ Smart method inspection\n" .
               "- ✅ VQMod & OCMOD support\n\n" .
               "**Authentication:** All endpoints require `api_key` parameter\n\n" .
               "**Base Path:** {$this->catalogPath}";
    }
    
    /**
     * Detect admin path
     */
    private function detectAdminPath() {
        if (defined('DIR_ADMIN')) {
            return DIR_ADMIN;
        }
        
        $catalogPath = DIR_APPLICATION;
        
        if ($this->isOC4()) {
            return defined('DIR_OPENCART') ? 
                   DIR_OPENCART . 'admin' . DIRECTORY_SEPARATOR : 
                   dirname(dirname($catalogPath)) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR;
        }
        
        return dirname($catalogPath) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR;
    }
    
    /**
     * Get base URL
     */
    private function getBaseUrl() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        
        return $protocol . '://' . $host . $scriptPath;
    }
    
    /**
     * Send JSON response
     */
    private function sendJson() {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($this->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Send YAML response
     */
    private function sendYaml() {
        header('Content-Type: application/x-yaml; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo $this->convertToYaml($this->spec);
        exit;
    }
    
    /**
     * Simple YAML converter
     */
    private function convertToYaml($data, $indent = 0) {
        $yaml = '';
        $spaces = str_repeat('  ', $indent);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (array_keys($value) === range(0, count($value) - 1)) {
                    $yaml .= $spaces . $key . ":\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $yaml .= $spaces . "  -\n";
                            $yaml .= $this->convertToYaml($item, $indent + 2);
                        } else {
                            $yaml .= $spaces . '  - ' . $this->yamlValue($item) . "\n";
                        }
                    }
                } else {
                    $yaml .= $spaces . $key . ":\n";
                    $yaml .= $this->convertToYaml($value, $indent + 1);
                }
            } else {
                $yaml .= $spaces . $key . ': ' . $this->yamlValue($value) . "\n";
            }
        }
        
        return $yaml;
    }
    
    /**
     * Format YAML value
     */
    private function yamlValue($value) {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_numeric($value)) {
            return $value;
        }
        if (preg_match('/[:\{\}\[\],&*#?|\-<>=!%@`\n]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }
}
