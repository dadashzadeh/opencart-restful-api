<?php
require_once(DIR_APPLICATION . 'controller/api/base_admin.php');

class ControllerApiDynamicAdmin extends ControllerApiBaseAdmin {
    
    // Cache for inspection results
    private $inspectionCache = [];
    
    // Model dependency list
    private $modelDependencies = [
        'sale/order' => [
            'marketing/affiliate',
            'marketing/marketing',
            'customer/customer',
            'customer/customer_group',
            'localisation/order_status',
            'localisation/country',
            'localisation/zone',
            'localisation/currency',
            'localisation/language',
            'setting/setting',
            'sale/order_status'
        ],
        'catalog/product' => [
            'catalog/category',
            'catalog/manufacturer',
            'catalog/option',
            'catalog/filter',
            'catalog/download',
            'catalog/recurring',
            'localisation/stock_status',
            'localisation/tax_class',
            'localisation/weight_class',
            'localisation/length_class',
            'setting/store',
            'tool/image'
        ],
        'customer/customer' => [
            'customer/customer_group',
            'customer/customer_approval',
            'marketing/affiliate',
            'localisation/country',
            'localisation/zone',
            'setting/store'
        ],
        'catalog/category' => [
            'catalog/filter',
            'setting/store',
            'localisation/language',
            'tool/image'
        ],
        'catalog/manufacturer' => [
            'setting/store',
            'tool/image'
        ],
        'catalog/information' => [
            'setting/store',
            'localisation/language'
        ],
        'sale/voucher' => [
            'sale/voucher_theme',
            'localisation/order_status'
        ],
        'marketing/coupon' => [
            'catalog/category',
            'catalog/product',
            'customer/customer_group'
        ]
    ];

    /**
     * 🎯 MODEL ENDPOINT
     * Execute model methods dynamically
     * 
     * Usage:
     * GET  /api/dynamic_admin/model&module=catalog/product&api_key=XXX
     * GET  /api/dynamic_admin/model&module=catalog/product&method=add&api_key=XXX
     * GET  /api/dynamic_admin/model&module=catalog/product&method=getProduct&product_id=123&api_key=XXX
     * POST /api/dynamic_admin/model&module=catalog/product&method=addProduct&api_key=XXX
     */
    public function model() {
        try {
            $module = $this->getParam('module');
            $method = $this->getParam('method');

            if (empty($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Module parameter is required',
                    'usage' => [
                        'list_methods' => 'GET /api/dynamic_admin/model&module=catalog/product&api_key=XXX',
                        'inspect_method' => 'GET /api/dynamic_admin/model&module=catalog/product&method=addProduct&api_key=XXX',
                        'call_method' => 'POST /api/dynamic_admin/model&module=catalog/product&method=addProduct&api_key=XXX'
                    ]
                ], 400);
                return;
            }

            // Load model dependencies
            $dependenciesLoaded = $this->loadModelDependencies($module);

            // Load the main model
            $filePath = $this->getCorrectFilePath($module, 'model');
            
            if (!$this->loadAdminModel($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not found: ' . $module,
                    'file_searched' => $filePath,
                    'file_exists' => file_exists($filePath),
                    'modification_type' => $this->modificationType,
                    'hint' => 'Check if the model file exists at the path above'
                ], 404);
                return;
            }

            // Get model instance
            $modelKey = 'model_' . str_replace('/', '_', $module);
            $modelObject = $this->registry->get($modelKey);

            if (!$modelObject) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Model not loaded properly',
                    'model_key' => $modelKey
                ], 500);
                return;
            }

            // If no method specified, list all methods
            if (empty($method)) {
                $this->listModelMethods($module, $modelObject, $filePath, $dependenciesLoaded);
                return;
            }

            // Check if method exists
            if (!method_exists($modelObject, $method)) {
                $availableMethods = $this->getPublicMethods($modelObject);

                $this->sendResponse([
                    'success' => false,
                    'error' => 'Method not found: ' . $method,
                    'file_loaded' => $filePath,
                    'available_methods' => $availableMethods,
                    'suggestion' => $this->findSimilarMethod($method, $availableMethods),
                    'hint' => 'Remove &method parameter to see all available methods'
                ], 404);
                return;
            }

            // Check if this is an inspection request (GET with method)
            $isInspection = ($_SERVER['REQUEST_METHOD'] === 'GET' && !$this->hasExecutionParams());

            if ($isInspection) {
                // Show detailed method inspection
                $this->inspectMethod($module, $method, $modelObject, $filePath, $dependenciesLoaded);
                return;
            }

            // Execute the method
            $this->executeModelMethod($module, $method, $modelObject, $filePath, $dependenciesLoaded);

        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => (defined('DEBUG') && DEBUG) ? $e->getTraceAsString() : null,
                'hint' => 'Enable DEBUG mode in config.php for detailed error trace'
            ], 500);
        }
    }

    /**
     * 🎮 CONTROLLER ENDPOINT
     * Execute controller methods dynamically
     * 
     * Usage:
     * GET  /api/dynamic_admin/controller&module=catalog/product&api_key=XXX
     * GET  /api/dynamic_admin/controller&module=catalog/product&method=add&api_key=XXX
     * POST /api/dynamic_admin/controller&module=catalog/product&method=add&api_key=XXX
     */
    public function controller() {
        try {
            $module = $this->getParam('module');
            $method = $this->getParam('method');

            if (empty($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Module parameter is required',
                    'usage' => [
                        'list_methods' => 'GET /api/dynamic_admin/controller&module=catalog/product&api_key=XXX',
                        'inspect_method' => 'GET /api/dynamic_admin/controller&module=catalog/product&method=add&api_key=XXX',
                        'call_method' => 'POST /api/dynamic_admin/controller&module=catalog/product&method=add&api_key=XXX'
                    ]
                ], 400);
                return;
            }

            // Load controller
            $filePath = $this->getCorrectFilePath($module, 'controller');
            $controller = $this->loadAdminController($module);

            if (!$controller) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Controller not found: ' . $module,
                    'file_searched' => $filePath,
                    'file_exists' => file_exists($filePath),
                    'modification_type' => $this->modificationType,
                    'hint' => 'Check if the controller file exists at the path above'
                ], 404);
                return;
            }

            // If no method specified, list all methods
            if (empty($method)) {
                $this->listControllerMethods($module, $controller, $filePath);
                return;
            }

            // Check if method exists
            if (!method_exists($controller, $method)) {
                $availableMethods = $this->getPublicMethods($controller);

                $this->sendResponse([
                    'success' => false,
                    'error' => 'Method not found: ' . $method,
                    'file_loaded' => $filePath,
                    'available_methods' => $availableMethods,
                    'suggestion' => $this->findSimilarMethod($method, $availableMethods),
                    'hint' => 'Remove &method parameter to see all available methods'
                ], 404);
                return;
            }

            // Check if this is an inspection request (GET with method)
            $isInspection = ($_SERVER['REQUEST_METHOD'] === 'GET' && !$this->hasExecutionParams());

            if ($isInspection) {
                // Show detailed method inspection
                $this->inspectMethod($module, $method, $controller, $filePath, []);
                return;
            }

            // Execute the method
            $this->executeControllerMethod($module, $method, $controller, $filePath);

        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => (defined('DEBUG') && DEBUG) ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * 🔍 DISCOVER ENDPOINT
     * List all available modules (models/controllers)
     * 
     * Usage:
     * GET /api/dynamic_admin/discover&type=model&api_key=XXX
     * GET /api/dynamic_admin/discover&type=controller&api_key=XXX
     * GET /api/dynamic_admin/discover&type=all&api_key=XXX
     */
    public function discover() {
        try {
            $type = $this->getParam('type', 'all');
            
            $result = array(
                'models' => array(),
                'controllers' => array()
            );
            
            if ($type === 'model' || $type === 'all') {
                $result['models'] = $this->scanDirectory('model');
            }
            
            if ($type === 'controller' || $type === 'all') {
                $result['controllers'] = $this->scanDirectory('controller');
            }
            
            $this->sendResponse([
                'success' => true,
                'discovered' => $result,
                'total_models' => count($result['models']),
                'total_controllers' => count($result['controllers']),
                'admin_path' => $this->adminPath,
                'modification_type' => $this->modificationType
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📋 List model methods with file info
     */
    private function listModelMethods($module, $modelObject, $filePath, $dependenciesLoaded) {
        $publicMethods = $this->getPublicMethodsDetailed($modelObject);

        $reflection = new ReflectionClass($modelObject);
        $actualLoadedFile = $reflection->getFileName();

        $defaultPath = $this->normalizePath(
            $this->adminPath . 'model/' . $module . '.php'
        );
        $actualPath = $this->normalizePath($actualLoadedFile);

        $isModified = !$this->pathsEqual($actualPath, $defaultPath);

        // Group methods by category
        $grouped = array(
            'get' => array(),
            'add' => array(),
            'edit' => array(),
            'delete' => array(),
            'other' => array()
        );
        
        foreach ($publicMethods as $methodInfo) {
            $name = $methodInfo['name'];
            if (preg_match('/^get/i', $name)) {
                $grouped['get'][] = $methodInfo;
            } elseif (preg_match('/^(add|insert|create)/i', $name)) {
                $grouped['add'][] = $methodInfo;
            } elseif (preg_match('/^(edit|update)/i', $name)) {
                $grouped['edit'][] = $methodInfo;
            } elseif (preg_match('/^(delete|remove)/i', $name)) {
                $grouped['delete'][] = $methodInfo;
            } else {
                $grouped['other'][] = $methodInfo;
            }
        }
        
        $this->sendResponse([
            'success' => true,
            'type' => 'model',
            'module' => $module,
            'file_info' => [
                'loaded_from' => $filePath,
                'actual_file' => $actualLoadedFile,
                'is_modified' => $isModified,
                'modification_type' => $this->modificationType,
                'default_path' => $defaultPath,
                'file_exists' => file_exists($actualLoadedFile),
                'file_size' => file_exists($actualLoadedFile) ? filesize($actualLoadedFile) : 0,
                'last_modified' => file_exists($actualLoadedFile) ? date('Y-m-d H:i:s', filemtime($actualLoadedFile)) : null
            ],
            'methods' => $publicMethods,
            'grouped_methods' => $grouped,
            'total_methods' => count($publicMethods),
            'dependencies_loaded' => $dependenciesLoaded,
            'hints' => [
                'inspect_method' => 'Add &method=METHOD_NAME to see detailed analysis',
                'call_method' => 'Use POST with &method=METHOD_NAME to execute'
            ]
        ]);
    }

    /**
     * 📋 List controller methods with file info
     */
    private function listControllerMethods($module, $controller, $filePath) {
        $publicMethods = $this->getPublicMethodsDetailed($controller);

        $reflection = new ReflectionClass($controller);
        $actualLoadedFile = $reflection->getFileName();

        $defaultPath = $this->normalizePath(
            $this->adminPath . 'controller/' . $module . '.php'
        );
        $actualPath = $this->normalizePath($actualLoadedFile);

        $isModified = !$this->pathsEqual($actualPath, $defaultPath);

        $this->sendResponse([
            'success' => true,
            'type' => 'controller',
            'module' => $module,
            'file_info' => [
                'loaded_from' => $filePath,
                'actual_file' => $actualLoadedFile,
                'is_modified' => $isModified,
                'modification_type' => $this->modificationType,
                'default_path' => $defaultPath,
                'file_exists' => file_exists($actualLoadedFile),
                'file_size' => file_exists($actualLoadedFile) ? filesize($actualLoadedFile) : 0,
                'last_modified' => file_exists($actualLoadedFile) ? date('Y-m-d H:i:s', filemtime($actualLoadedFile)) : null
            ],
            'methods' => $publicMethods,
            'total_methods' => count($publicMethods),
            'hints' => [
                'inspect_method' => 'Add &method=METHOD_NAME to see detailed analysis',
                'call_method' => 'Use POST with &method=METHOD_NAME to execute'
            ]
        ]);
    }


    /**
     * 🔬 Inspect method (detailed analysis with smart model follow-up)
     */
    private function inspectMethod($module, $method, $object, $filePath, $dependenciesLoaded) {
        // Check cache
        $cacheKey = get_class($object) . '_' . $method;
        if (isset($this->inspectionCache[$cacheKey])) {
            $cached = $this->inspectionCache[$cacheKey];
            $cached['from_cache'] = true;
            $this->sendResponse($cached);
            return;
        }

        // Get method reflection
        $reflection = new ReflectionMethod($object, $method);

        // Get method source code
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $length = $endLine - $startLine;

        $source = file($filename);
        $methodCode = implode("", array_slice($source, $startLine - 1, $length + 1));

        // 🆕 محاسبه مسیر پیش‌فرض (unmodified)
        $defaultPath = $this->normalizePath(
            $this->adminPath . 'model/' . $module . '.php'
        );
        $actualPath = $this->normalizePath($filename);

        // ✅ مقایسه صحیح با مسیر پیش‌فرض
        $isModified = !$this->pathsEqual($actualPath, $defaultPath);

        // Extract information from code
        $fields = $this->extractFieldsFromCode($methodCode);
        $calledMethods = $this->extractCalledMethods($methodCode);
        $validations = $this->extractValidationRules($methodCode);
        
        // 🆕 SMART INSPECTION: If controller has no fields, follow to model
        $followUpData = null;
        $isController = !is_a($object, 'Model', true);
        
        if ($isController && empty($fields['required']) && empty($fields['optional']) && !empty($calledMethods)) {
            $followUpData = $this->followToModelMethod($calledMethods, $method);
            
            // If we found model data, merge it
            if ($followUpData) {
                $fields = $followUpData['fields'];
                $validations = array_merge($validations, $followUpData['validations']);
            }
        }
        
        $exampleData = $this->buildExampleFromFields($fields);
        
        $response = array(
            'success' => true,
            'type' => is_a($object, 'Model', true) ? 'model' : 'controller',
            'module' => $module,
            'method' => $method,
            'file_info' => array(
                'loaded_from' => $filePath,
                'actual_file' => $filename,
                'is_modified' => $isModified,
                'modification_type' => $this->modificationType,
                'default_path' => $defaultPath,
                'start_line' => $startLine,
                'end_line' => $endLine,
                'total_lines' => $length + 1,
                'file_size' => filesize($filename),
                'last_modified' => date('Y-m-d H:i:s', filemtime($filename))
            ),
            'parameters' => $this->getMethodParametersInfo($reflection),
            'detected_fields' => $fields,
            'validation_rules' => $validations,
            'called_methods' => $calledMethods,
            'example_json' => $exampleData,
            'usage_examples' => $this->generateUsageExamples($module, $method, $exampleData, is_a($object, 'Model', true)),
            'dependencies_loaded' => $dependenciesLoaded,
            'raw_code' => (defined('DEBUG') && DEBUG) ? $methodCode : 'Enable DEBUG to see code',
            'from_cache' => false,
            'hints' => [
                'execute' => 'Use POST with JSON body to execute this method',
                'partial_update' => 'For edit methods, you can send partial data - missing fields will be auto-merged'
            ]
        );
        
        // 🆕 Add follow-up information if available
        if ($followUpData) {
            $response['smart_inspection'] = array(
                'followed_to_model' => true,
                'model_module' => $followUpData['model_module'],
                'model_method' => $followUpData['model_method'],
                'reason' => 'Controller method has no direct fields - automatically inspected underlying model method',
                'model_file' => $followUpData['model_file']
            );
        }
    
        // Cache the result
        $this->inspectionCache[$cacheKey] = $response;
    
        $this->sendResponse($response);
    }

    /**
     * Normalize path (convert slashes to system format)
     * 
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    protected function normalizePath($path) {
        // Replace all slashes with DIRECTORY_SEPARATOR
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        
        // Remove duplicate separators
        $normalized = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR) . '+#', DIRECTORY_SEPARATOR, $normalized);
        
        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }
    
    /**
     * Compare two paths (normalized)
     */
    private function pathsEqual($path1, $path2) {
        $normalized1 = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path1);
        $normalized2 = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path2);

        return rtrim($normalized1, DIRECTORY_SEPARATOR) === rtrim($normalized2, DIRECTORY_SEPARATOR);
    }
    
    /**
     * 🔍 Follow controller method to underlying model method
     */
    private function followToModelMethod($calledMethods, $controllerMethod) {
        // Find first model_call that matches method name
        foreach ($calledMethods as $call) {
            if ($call['type'] !== 'model_call') {
                continue;
            }
            
            $modelMethod = $call['method'];
            $modelModule = $call['model'];
            
            // Check if method names match (e.g., controller's "edit" -> model's "editProduct")
            if (stripos($modelMethod, $controllerMethod) === false && 
                stripos($controllerMethod, $modelMethod) === false) {
                continue;
            }
            
            // Try to load and inspect the model
            try {
                if (!$this->loadAdminModel($modelModule)) {
                    continue;
                }
                
                $modelKey = 'model_' . str_replace('/', '_', $modelModule);
                $modelObject = $this->registry->get($modelKey);
                
                if (!$modelObject || !method_exists($modelObject, $modelMethod)) {
                    continue;
                }
                
                // Get model method code
                $modelReflection = new ReflectionMethod($modelObject, $modelMethod);
                $modelFilename = $modelReflection->getFileName();
                $modelStartLine = $modelReflection->getStartLine();
                $modelEndLine = $modelReflection->getEndLine();
                
                $modelSource = file($modelFilename);
                $modelCode = implode("", array_slice($modelSource, $modelStartLine - 1, $modelEndLine - $modelStartLine + 1));
                
                // Extract fields from model
                $modelFields = $this->extractFieldsFromCode($modelCode);
                $modelValidations = $this->extractValidationRules($modelCode);
                
                // Only return if we found fields
                if (!empty($modelFields['required']) || !empty($modelFields['optional']) || !empty($modelFields['arrays'])) {
                    return array(
                        'fields' => $modelFields,
                        'validations' => $modelValidations,
                        'model_module' => $modelModule,
                        'model_method' => $modelMethod,
                        'model_file' => $modelFilename
                    );
                }
                
            } catch (Exception $e) {
                // Continue to next method
                continue;
            }
        }
        
        return null;
    }
    

    /**
     * ⚡ Execute model method
     */
    private function executeModelMethod($module, $method, $modelObject, $filePath, $dependenciesLoaded) {
        // Detect method type
        $isEditMethod = preg_match('/(edit|update)/i', $method);
        $autoMerged = false;

        // Get method parameters
        $params = $this->getMethodParams($method, $module, $modelObject, $autoMerged);

        // Execute method
        $result = $this->executeMethod($modelObject, $method, $params);

        $this->sendResponse([
            'success' => true,
            'result' => $result,
            'meta' => [
                'type' => 'model',
                'module' => $module,
                'method' => $method,
                'file_loaded' => $filePath,
                'modification_type' => $this->modificationType,
                'params_count' => count($params),
                'auto_merged' => $autoMerged,
                'dependencies_loaded' => count($dependenciesLoaded),
                'execution_time' => $this->getExecutionTime()
            ]
        ]);
    }

    /**
     * ⚡ Execute controller method
     */
    private function executeControllerMethod($module, $method, $controller, $filePath) {
        // ✅ تعریف متغیر $autoMerged
        $autoMerged = false;
        
        // Get method parameters
        $params = $this->getMethodParams($method, null, null, $autoMerged);
    
        // Execute method
        $result = $this->executeMethod($controller, $method, $params);
    
        $this->sendResponse([
            'success' => true,
            'result' => $result,
            'meta' => [
                'type' => 'controller',
                'module' => $module,
                'method' => $method,
                'file_loaded' => $filePath,
                'modification_type' => $this->modificationType,
                'params_count' => count($params),
                'auto_merged' => $autoMerged,  // ✅ اضافه شد
                'execution_time' => $this->getExecutionTime()
            ]
        ]);
    }
    

    /**
     * 📁 Scan directory for PHP files
     */
    private function scanDirectory($type) {
        $basePath = $this->adminPath . $type . '/';
        $modules = array();
        
        if (!is_dir($basePath)) {
            return $modules;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = str_replace($basePath, '', $file->getPathname());
                $module = str_replace('.php', '', $relativePath);
                $module = str_replace('\\', '/', $module);
                
                $modules[] = array(
                    'module' => $module,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                );
            }
        }
        
        return $modules;
    }

    /**
     * 🔧 Check if request has execution parameters
     */
    private function hasExecutionParams() {
        // Check if there are any parameters besides module, method, api_key, route
        $reserved = array('route', 'module', 'method', 'api_key', 'cache');
        
        foreach ($this->request->get as $key => $value) {
            if (!in_array($key, $reserved)) {
                return true;
            }
        }
        
        // Check if there's POST data
        if (!empty($this->request->post)) {
            return true;
        }
        
        // Check if there's JSON body
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($jsonInput)) {
            return true;
        }
        
        return false;
    }

    /**
     * 🔄 Load model dependencies
     */
    private function loadModelDependencies($module) {
        $loaded = array();
        
        if (!isset($this->modelDependencies[$module])) {
            return $loaded;
        }
        
        foreach ($this->modelDependencies[$module] as $depModel) {
            try {
                if ($this->loadAdminModel($depModel)) {
                    $loaded[] = $depModel;
                }
            } catch (Exception $e) {
                error_log("Dependency load failed: $depModel - " . $e->getMessage());
            }
        }
        
        return $loaded;
    }
    /**
     * 🎯 Get method parameters (Enhanced with Smart Defaults)
     */
    private function getMethodParams($method, $module = null, $modelObject = null, &$autoMerged = false) {
        $params = array();

        // Get JSON body
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonInput = null;
        }

        // 🆕 PRIORITY: Check for form data or URL params FIRST
        $formData = $this->getAllFormData();
        $urlParams = $this->extractDirectParams();

        if (defined('DEBUG') && DEBUG) {
            error_log("=== getMethodParams ===");
            error_log("Form Data: " . json_encode($formData));
            error_log("URL Params: " . json_encode($urlParams));
            error_log("JSON Input: " . json_encode($jsonInput));
        }

        // Detect method type
        $isEditMethod = preg_match('/(edit|update)/i', $method);
        $isAddMethod = preg_match('/(add|insert|create)/i', $method);
        $isGetMethod = preg_match('/(get|fetch|load|list)/i', $method);
        $isDeleteMethod = preg_match('/(delete|remove)/i', $method);

        // ============ PRIORITY 1: Form Data ============
        if (!empty($formData) && ($isEditMethod || $isAddMethod)) {
            if (defined('DEBUG') && DEBUG) {
                error_log("🎯 Using FORM DATA");
            }

            // For EDIT methods
            if ($isEditMethod && !empty($urlParams)) {
                $recordId = $urlParams[0];

                // Check if partial
                if ($this->isPartialEditData($formData, $method, $module)) {
                    if ($modelObject) {
                        $existingData = $this->getExistingData($modelObject, $method, $recordId);
                        if ($existingData && is_array($existingData)) {
                            // ✅ FIX: Restructure BOTH before merge
                            $existingData = $this->ensureNestedStructure($existingData, $module);
                            $formData = $this->ensureNestedStructure($formData, $module);  // ✅ اضافه شد

                            $mergedData = $this->smartMerge($existingData, $formData);
                            $autoMerged = true;

                            if (defined('DEBUG') && DEBUG) {
                                error_log("✅ FORM AUTO-MERGE successful");
                                error_log("Final merged data keys: " . implode(', ', array_keys($mergedData)));
                            }

                            $params = array_merge($urlParams, array($mergedData));
                            return $params;
                        }
                    }
                }

                $params = array_merge($urlParams, array($formData));
                return $params;
            }

            // For ADD methods
            $params = array($formData);
            return $params;
        }

        // ============ PRIORITY 2: JSON Body ============
        if (!empty($jsonInput) && ($isEditMethod || $isAddMethod)) {
            if ($isEditMethod) {
                $isPartialData = $this->isPartialEditData($jsonInput, $method, $module);

                if ($isPartialData) {
                    if (empty($urlParams)) {
                        throw new Exception("Auto-Merge Error: ID parameter missing. Example: &attribute_id=15");
                    }

                    if (!$modelObject) {
                        throw new Exception("Auto-Merge Error: Model object not available");
                    }

                    $recordId = $urlParams[0];
                    $existingData = $this->getExistingData($modelObject, $method, $recordId);

                    if ($existingData && is_array($existingData) && !empty($existingData)) {
                        // ✅ FIX: Restructure BOTH
                        $existingData = $this->ensureNestedStructure($existingData, $module);
                        $jsonInput = $this->ensureNestedStructure($jsonInput, $module);  // ✅ اضافه شد

                        $mergedData = $this->smartMerge($existingData, $jsonInput);
                        $autoMerged = true;

                        if (defined('DEBUG') && DEBUG) {
                            error_log("✅ JSON AUTO-MERGE successful");
                            error_log("Final merged data keys: " . implode(', ', array_keys($mergedData)));
                        }

                        $params = array_merge($urlParams, array($mergedData));
                    } else {
                        throw new Exception("Auto-Merge Failed: Cannot retrieve data for ID: $recordId");
                    }
                } else {
                    // Complete data
                    if (!empty($urlParams)) {
                        $params = array_merge($urlParams, array($jsonInput));
                    } else {
                        $params = array($jsonInput);
                    }
                }
            } elseif ($isAddMethod) {
                $params = array($jsonInput);
            }
        }


        // ============ PRIORITY 3: URL Params Only ============
        elseif (!empty($urlParams)) {
            $params = $urlParams;
        }
        // ============ GET/DELETE Methods ============
        elseif ($isGetMethod || $isDeleteMethod) {
            if ($jsonInput && isset($jsonInput['params']) && is_array($jsonInput['params'])) {
                $params = $jsonInput['params'];
            } elseif (!empty($urlParams)) {
                $params = $urlParams;
            } elseif ($jsonInput && is_array($jsonInput)) {
                $params = array($jsonInput);
            }
        }

        return $params;
    }

    /**
     * 🆕 Get ALL form data (POST/GET combined)
     */
    private function getAllFormData() {
        $data = array();

        // Get POST data
        if (!empty($this->request->post)) {
            $data = $this->request->post;
        } elseif (!empty($_POST)) {
            $data = $_POST;
        }

        // Merge with GET params (excluding reserved)
        $reserved = array('route', 'module', 'method', 'api_key', 'user_token', 'token');
        foreach ($this->request->get as $key => $value) {
            if (!in_array($key, $reserved) && !isset($data[$key])) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * 🔄 Ensure nested structure (با auto-detect از دیتابیس)
     */
    private function ensureNestedStructure($data, $module) {
        // Map of modules to their nested key
        $nestedMap = array(
            'catalog/product' => 'product_description',
            'catalog/category' => 'category_description',
            'catalog/manufacturer' => 'manufacturer_description',
            'catalog/information' => 'information_description',
            'catalog/attribute' => 'attribute_description',
            'catalog/attribute_group' => 'attribute_group_description',
            'catalog/option' => 'option_description',
            'catalog/option_value' => 'option_value_description',
            'catalog/filter' => 'filter_description',
            'catalog/filter_group' => 'filter_group_description',
        );

        if (!isset($nestedMap[$module])) {
            return $data; // Unknown module
        }

        $nestedKey = $nestedMap[$module];

        // Check if already nested
        if (isset($data[$nestedKey]) && is_array($data[$nestedKey]) && !empty($data[$nestedKey])) {
            return $this->verifyNestedFields($data, $module, $nestedKey);
        }

        // 🆕 Get language fields from DATABASE
        $languageFields = $this->getLanguageFieldsFromDatabase($module);

        // Check if data has flat language fields
        $hasLangFields = false;
        $foundFields = array();

        foreach ($languageFields as $field) {
            if (isset($data[$field])) {
                $hasLangFields = true;
                $foundFields[] = $field;
            }
        }

        if (!$hasLangFields) {
            return $data;
        }

        // Get language ID
        $languageId = isset($data['language_id']) ? $data['language_id'] : $this->config->get('config_language_id');
        if (empty($languageId)) {
            $languageId = 2; // Default
        }

        // Build nested structure
        $data[$nestedKey] = array();
        $data[$nestedKey][$languageId] = array();

        // Move fields to nested
        foreach ($foundFields as $field) {
            $data[$nestedKey][$languageId][$field] = $data[$field];
        }

        // Add missing fields with empty values
        foreach ($languageFields as $field) {
            if (!isset($data[$nestedKey][$languageId][$field])) {
                $data[$nestedKey][$languageId][$field] = '';
            }
        }

        if (defined('DEBUG') && DEBUG) {
            error_log("✅ Restructured flat to nested: $nestedKey");
            error_log("Moved fields: " . implode(', ', $foundFields));
        }

        return $data;
    }


    /**
     * 🔍 Verify nested fields (با auto-detect از دیتابیس)
     */
    private function verifyNestedFields($data, $module, $nestedKey) {
        // 🆕 Get required fields from DATABASE
        $requiredFields = $this->getLanguageFieldsFromDatabase($module);

        // Loop through all language IDs
        foreach ($data[$nestedKey] as $languageId => &$fields) {
            // Check for missing fields
            foreach ($requiredFields as $field) {
                if (!isset($fields[$field])) {
                    // Try to get from top-level
                    if (isset($data[$field])) {
                        $fields[$field] = $data[$field];

                        if (defined('DEBUG') && DEBUG) {
                            error_log("✅ Moved $field from top-level to nested[$languageId]");
                        }
                    } else {
                        // Add with empty value
                        $fields[$field] = '';

                        if (defined('DEBUG') && DEBUG) {
                            error_log("⚠️ Added missing field $field to nested[$languageId] (empty)");
                        }
                    }
                }
            }
        }
        unset($fields); // Break reference

        return $data;
    }

    /**
     * 🗄️ Get language fields from database table structure
     * 
     * Automatically detects language-specific fields from database tables
     * like oc_product_description, oc_category_description, etc.
     * 
     * @param string $module Module name (e.g., 'catalog/product')
     * @return array List of language-specific field names
     */
    private function getLanguageFieldsFromDatabase($module) {
        // Cache to avoid repeated queries
        static $cache = array();

        if (isset($cache[$module])) {
            return $cache[$module];
        }

        // Map module to description table
        $tableMap = array(
            'catalog/product' => 'product_description',
            'catalog/category' => 'category_description',
            'catalog/manufacturer' => 'manufacturer_description',
            'catalog/information' => 'information_description',
            'catalog/attribute' => 'attribute_description',
            'catalog/attribute_group' => 'attribute_group_description',
            'catalog/option' => 'option_description',
            'catalog/option_value' => 'option_value_description',
            'catalog/filter' => 'filter_description',
            'catalog/filter_group' => 'filter_group_description',
            'catalog/download' => 'download_description',
            'catalog/recurring' => 'recurring_description',
            'sale/voucher_theme' => 'voucher_theme_description',
        );

        // Get table name
        if (isset($tableMap[$module])) {
            $tableName = $tableMap[$module];
        } else {
            // Auto-detect: catalog/product → product_description
            $parts = explode('/', $module);
            $tableName = end($parts) . '_description';
        }

        if (empty($tableName)) {
            $cache[$module] = array();
            return array();
        }

        $fullTableName = DB_PREFIX . $tableName;

        try {
            // Check if table exists
            $checkQuery = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($fullTableName) . "'");

            if ($checkQuery->num_rows == 0) {
                if (defined('DEBUG') && DEBUG) {
                    error_log("⚠️ Table $fullTableName does not exist");
                }
                $cache[$module] = array();
                return array();
            }

            // Get table columns
            $columnsQuery = $this->db->query("SHOW COLUMNS FROM `" . $fullTableName . "`");

            $fields = array();

            // ✅ FIXED: Only exclude actual primary key fields (not indexes)
            $primaryKeys = array();
            $excludeFields = array('language_id', 'store_id'); // Reserved fields

            // First pass: Find PRIMARY keys
            foreach ($columnsQuery->rows as $column) {
                if ($column['Key'] === 'PRI') {
                    $primaryKeys[] = $column['Field'];
                }
            }

            if (defined('DEBUG') && DEBUG) {
                error_log("Primary Keys: " . implode(', ', $primaryKeys));
            }

            // Second pass: Get all fields EXCEPT primary keys and reserved fields
            foreach ($columnsQuery->rows as $column) {
                $fieldName = $column['Field'];

                // ✅ Skip ONLY primary keys and reserved fields
                // ✅ DO NOT skip indexes (like 'name' which has Key='MUL')
                if (in_array($fieldName, $primaryKeys) || in_array($fieldName, $excludeFields)) {
                    continue;
                }

                $fields[] = $fieldName;
            }

            if (defined('DEBUG') && DEBUG) {
                error_log("✅ Auto-detected " . count($fields) . " language fields from $fullTableName");
                error_log("Fields: " . implode(', ', $fields));
            }

            $cache[$module] = $fields;
            return $fields;

        } catch (Exception $e) {
            if (defined('DEBUG') && DEBUG) {
                error_log("❌ Error detecting fields from $fullTableName: " . $e->getMessage());
            }

            // Fallback to common fields
            $fallbackFields = array('name', 'description', 'meta_title', 'meta_description', 'meta_keyword');
            $cache[$module] = $fallbackFields;
            return $fallbackFields;
        }
    }


    /**
     * 🧠 Detect if data is partial (incomplete)
     */
    private function isPartialEditData($data, $method, $module) {
        // If only 1-3 fields, likely partial
        if (count($data) <= 3) {
            return true;
        }

        // Check for key OpenCart fields
        $requiredFields = array('model', 'quantity', 'status');
        $hasAllRequired = true;

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $hasAllRequired = false;
                break;
            }
        }

        if (!$hasAllRequired) {
            return true;
        }

        // Check for nested arrays (like product_description)
        if (!isset($data['product_description']) || empty($data['product_description'])) {
            return true;
        }

        return false;
    }

    /**
     * 🔍 Get existing data (با استفاده خودکار از متدهای موجود در Model)
     * 
     * این متد:
     * - خودکار متدهای get* را پیدا می‌کند
     * - آن‌ها را فراخوانی می‌کند
     * - داده‌ها را با کلیدهای صحیح (product_description, product_category, ...) ترکیب می‌کند
     * 
     * @param object $modelObject Model instance
     * @param string $editMethod Edit method name (e.g., 'editProduct')
     * @param int $id Record ID
     * @return array Complete data with all related tables
     */
    private function getExistingData($modelObject, $editMethod, $id) {
        // Step 1: Try to find main getter method
        $possibleGetters = array(
            preg_replace('/(edit|update)/i', 'get', $editMethod, 1),  // editProduct → getProduct
            preg_replace('/(edit|update)([A-Z]\w+)/i', 'get$2', $editMethod),
            'get' . ucfirst(preg_replace('/(edit|update)/i', '', $editMethod))
        );

        if (defined('DEBUG') && DEBUG) {
            error_log("🔎 Auto-detecting getter methods for $editMethod with ID: $id");
        }

        $mainData = null;
        $mainGetterMethod = null;

        // Find main getter
        foreach ($possibleGetters as $getMethod) {
            if (method_exists($modelObject, $getMethod)) {
                try {
                    $mainData = $modelObject->$getMethod($id);

                    if ($mainData && is_array($mainData) && !empty($mainData)) {
                        $mainGetterMethod = $getMethod;

                        if (defined('DEBUG') && DEBUG) {
                            error_log("✅ Main getter: $getMethod returned " . count($mainData) . " fields");
                        }
                        break;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        if (!$mainData || !is_array($mainData)) {
            if (defined('DEBUG') && DEBUG) {
                error_log("❌ No main getter method found or empty data");
            }
            return null;
        }

        // Step 2: Auto-detect and call related getter methods
        // Extract entity name from main getter (e.g., "getProduct" → "Product")
        $entityName = preg_replace('/^get/', '', $mainGetterMethod);

        if (defined('DEBUG') && DEBUG) {
            error_log("🔍 Entity name: $entityName");
            error_log("🔍 Looking for related methods like get{$entityName}* ...");
        }

        // Get all public methods from model
        $reflection = new ReflectionClass($modelObject);
        $allMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $relatedMethodsFound = 0;

        foreach ($allMethods as $methodReflection) {
            $methodName = $methodReflection->getName();

            // Skip constructor, destructor, and main getter
            if ($methodName === '__construct' || 
                $methodName === '__destruct' || 
                $methodName === $mainGetterMethod) {
                continue;
            }

            // ✅ Pattern 1: get{Entity}{Something} (e.g., getProductCategories, getProductImages)
            // ✅ Pattern 2: get{Entity}s (e.g., getProducts - but we skip this for list methods)
            if (preg_match('/^get' . $entityName . '([A-Z]\w+)$/i', $methodName, $matches)) {
                $suffix = $matches[1]; // e.g., "Categories", "Images", "Descriptions"

                // Check method parameters
                $params = $methodReflection->getParameters();

                // Skip if method requires more than 1 parameter
                if (count($params) > 1) {
                    continue;
                }

                // Skip if method requires 0 parameters (likely a list method)
                if (count($params) === 0) {
                    continue;
                }

                // Check if first parameter name suggests it's for this entity
                $firstParam = $params[0];
                $paramName = $firstParam->getName();

                // Expected parameter names
                $expectedParamNames = [
                    strtolower($entityName) . '_id',  // e.g., product_id
                    $paramName === 'id',
                    $paramName === strtolower($entityName) . '_id'
                ];

                if (!in_array(true, $expectedParamNames) && 
                    !in_array($paramName, [strtolower($entityName) . '_id', 'id'])) {
                    continue; // Skip if parameter name doesn't match
                }

                // Try to call the method
                try {
                    $relatedData = $modelObject->$methodName($id);

                    if ($relatedData !== null && $relatedData !== false) {
                        // ✅ PRIORITY 1: Auto-detect from add/edit code
                        $keyName = $this->detectActualFieldName($modelObject, $methodName, $entityName);

                        // ✅ FALLBACK: Use deriveKeyName if detection failed
                        if (empty($keyName)) {
                            $keyName = $this->deriveKeyName($entityName, $suffix);

                            if (defined('DEBUG') && DEBUG) {
                                error_log("⚠️ Auto-detection failed for $methodName, using fallback: $keyName");
                            }
                        }

                        $mainData[$keyName] = $relatedData;
                        $relatedMethodsFound++;

                        if (defined('DEBUG') && DEBUG) {
                            $count = is_array($relatedData) ? count($relatedData) : 'N/A';
                            error_log("✅ Called $methodName → stored as '$keyName' ($count items)");
                        }
                    }

                } catch (Exception $e) {
                    if (defined('DEBUG') && DEBUG) {
                        error_log("⚠️ $methodName failed: " . $e->getMessage());
                    }
                    continue;
                }

            }
        }

        if (defined('DEBUG') && DEBUG) {
            error_log("✅ Auto-detection complete: Found $relatedMethodsFound related methods");
            error_log("Total keys in data: " . count($mainData));
        }

        return $mainData;
    }

    /**
     * 🔍 Detect actual field name from add/edit method code (Fully Automatic)
     * 
     * Scans addProduct/editProduct to find EXACT field name used in code
     * Example: getProductImages() → finds 'product_image' (NOT 'image')
     * 
     * @param object $modelObject Model instance
     * @param string $methodName Getter method name (e.g., 'getProductImages')
     * @param string $entityName Entity name (e.g., 'Product')
     * @return string|null Actual field name or null
     */
    private function detectActualFieldName($modelObject, $methodName, $entityName) {
        // Cache to avoid repeated scans
        static $cache = [];
        $cacheKey = get_class($modelObject) . '_' . $methodName;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Target methods to scan
        $targetMethods = [
            'add' . $entityName,
            'edit' . $entityName
        ];

        // Extract suffix from getter method name
        // getProductImages → Images
        // getProductTags → Tags
        $suffix = preg_replace('/^get' . $entityName . '/i', '', $methodName);
        $suffixLower = strtolower($suffix);

        foreach ($targetMethods as $targetMethod) {
            if (!method_exists($modelObject, $targetMethod)) {
                continue;
            }

            try {
                $reflection = new ReflectionMethod($modelObject, $targetMethod);
                $filename = $reflection->getFileName();
                $startLine = $reflection->getStartLine();
                $endLine = $reflection->getEndLine();

                $source = file($filename);
                $code = implode("", array_slice($source, $startLine - 1, $endLine - $startLine + 1));

                // ✅ Find all $data['xxx'] patterns
                if (preg_match_all("/\\\$data\['([^']+)'\]/", $code, $matches)) {
                    $candidateFields = array_unique($matches[1]);

                    // ✅ PRIORITY 1: Find field that contains suffix
                    // and starts with entity name
                    foreach ($candidateFields as $fieldName) {
                        $fieldLower = strtolower($fieldName);

                        // Match criteria:
                        // 1. Field starts with entity name (product_xxx)
                        // 2. Field contains the suffix (getProductImages → image)
                        $entityPrefix = strtolower($entityName) . '_';

                        if (strpos($fieldLower, $entityPrefix) === 0) {
                            $fieldSuffix = substr($fieldLower, strlen($entityPrefix));

                            // Check if suffix matches (with or without 's')
                            // getProductImages → product_image (match!)
                            // getProductTags → product_tagn (match!)
                            if ($this->suffixMatches($suffixLower, $fieldSuffix)) {
                                if (defined('DEBUG') && DEBUG) {
                                    error_log("🎯 Auto-detected field: $methodName → $fieldName");
                                }
                                $cache[$cacheKey] = $fieldName;
                                return $fieldName;
                            }
                        }
                    }
                }

            } catch (Exception $e) {
                if (defined('DEBUG') && DEBUG) {
                    error_log("Failed to scan $targetMethod: " . $e->getMessage());
                }
                continue;
            }
        }

        // ✅ FALLBACK: Use simple rule
        $fallbackName = strtolower($entityName) . '_' . $this->applySimpleSingularRules($suffixLower);

        if (defined('DEBUG') && DEBUG) {
            error_log("⚠️ Auto-detection failed for $methodName, using fallback: $fallbackName");
        }

        $cache[$cacheKey] = $fallbackName;
        return $fallbackName;
    }

    /**
     * ✅ Check if suffix matches field suffix (با normalization)
     * 
     * Examples:
     * - suffixMatches('images', 'image') → true
     * - suffixMatches('seourls', 'seo_url') → true ✅ FIXED!
     * - suffixMatches('tags', 'tagn') → true
     * - suffixMatches('categories', 'category') → true
     * 
     * @param string $methodSuffix Suffix from method name (e.g., 'seourls')
     * @param string $fieldSuffix Suffix from field name (e.g., 'seo_url')
     * @return bool
     */
    private function suffixMatches($methodSuffix, $fieldSuffix) {
        // ✅ Normalize: Remove underscores for comparison
        // This handles cases like:
        // - seourls vs seo_url
        // - customtext vs custom_text
        $normalizedMethod = str_replace('_', '', strtolower($methodSuffix));
        $normalizedField = str_replace('_', '', strtolower($fieldSuffix));
        
        // Exact match (normalized)
        if ($normalizedMethod === $normalizedField) {
            return true;
        }
        
        // Method suffix without 's' matches field
        // seourls → seourl === seourl (from seo_url) ✅
        if (rtrim($normalizedMethod, 's') === $normalizedField) {
            return true;
        }
        
        // Method suffix contains field suffix
        // tags → tagn
        if (strpos($normalizedMethod, $normalizedField) !== false) {
            return true;
        }
        
        // Field suffix contains method suffix (without 's')
        // images → image
        if (strpos($normalizedField, rtrim($normalizedMethod, 's')) !== false) {
            return true;
        }
        
        // Handle 'ies' → 'y'
        // categories → category
        if (substr($normalizedMethod, -3) === 'ies') {
            $singularForm = substr($normalizedMethod, 0, -3) . 'y';
            if ($singularForm === $normalizedField) {
                return true;
            }
        }
        
        return false;
    }   

    /**
     * 🔤 Apply simple singular rules (Fallback only)
     * 
     * این فقط برای fallback استفاده می‌شود - معمولاً از code detection استفاده می‌کنیم
     * 
     * @param string $word Plural word
     * @return string Singular form
     */
    private function applySimpleSingularRules($word) {
        // Handle 'ies' → 'y'
        if (substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';  // categories → category
        }

        // Handle 'es' → '' (for some words)
        if (substr($word, -2) === 'es' && in_array(substr($word, -3, 1), ['s', 'x', 'z', 'h'])) {
            return substr($word, 0, -2);  // boxes → box, wishes → wish
        }

        // Handle 's' → ''
        if (substr($word, -1) === 's') {
            return substr($word, 0, -1);  // images → image
        }

        return $word;
    }


    /**
     * 🔤 Derive key name from entity and suffix (با singular mapping)
     * 
     * Examples:
     * - deriveKeyName('Product', 'Categories') → 'product_category'
     * - deriveKeyName('Product', 'Descriptions') → 'product_description'
     * - deriveKeyName('Product', 'Images') → 'product_image'
     * 
     * @param string $entity Entity name (e.g., 'Product')
     * @param string $suffix Method suffix (e.g., 'Categories')
     * @return string Derived key name
     */
    private function deriveKeyName($entity, $suffix) {
        // Convert to lowercase
        $entity = strtolower($entity);
        $suffix = strtolower($suffix);

        // ✅ Singular mapping for common plurals (OpenCart standard)
        $pluralToSingular = array(
            'categories' => 'category',
            'images' => 'image',
            'descriptions' => 'description',
            'attributes' => 'attribute',
            'options' => 'option',
            'values' => 'value',
            'discounts' => 'discount',
            'specials' => 'special',
            'filters' => 'filter',
            'rewards' => 'reward',
            'downloads' => 'download',
            'stores' => 'store',
            'seourls' => 'seo_url',
            'layouts' => 'layout',
            'affiliates' => 'affiliate',
            'customtext' => 'customtext',
            'customtexts' => 'customtext',
            'tags' => 'tag',
            'tagn' => 'tagn',
            'tagns' => 'tagn',
            'recurrings' => 'recurring',
            'transactions' => 'transaction',
            'histories' => 'history',
            'addresses' => 'address',
            'activities' => 'activity',
            'approvals' => 'approval',
            'searches' => 'search',
            'wishlists' => 'wishlist',
            'ips' => 'ip',
            'logins' => 'login',
            'sessions' => 'session',
            'totals' => 'total',
            'vouchers' => 'voucher',
            'returns' => 'return',
            'statuses' => 'status',
            'reasons' => 'reason',
            'actions' => 'action'
        );

        // Check if suffix has direct mapping
        if (isset($pluralToSingular[$suffix])) {
            return $entity . '_' . $pluralToSingular[$suffix];
        }

        // ✅ Fallback: Simple 's' removal for regular plurals
        // (e.g., 'Products' → 'Product')
        $singularSuffix = preg_replace('/s$/', '', $suffix);

        // ✅ Handle 'ies' → 'y' (e.g., 'Accessories' → 'Accessory')
        $singularSuffix = preg_replace('/ies$/', 'y', $singularSuffix);

        // Special case mappings for compound names
        $specialMappings = array(
            'seo_url' => 'seo_url',
            'seourl' => 'seo_url',
            'customtext' => 'customtext',
            'affiliate' => 'affiliate'
        );

        if (isset($specialMappings[$singularSuffix])) {
            return $entity . '_' . $specialMappings[$singularSuffix];
        }

        // Default: entity_suffix
        return $entity . '_' . $singularSuffix;
    }




    /**
     * 🧩 Smart merge arrays (recursive)
     */
    private function smartMerge($existing, $new) {
        if (!is_array($existing) || !is_array($new)) {
            return $new;
        }

        foreach ($new as $key => $value) {
            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                $existing[$key] = $this->smartMerge($existing[$key], $value);
            } else {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    /**
     * 📤 Extract direct parameters from URL
     */
    private function extractDirectParams() {
        $params = array();
        $reserved = array('route', 'module', 'method', 'api_key', 'cache');
        
        foreach ($this->request->get as $key => $value) {
            if (!in_array($key, $reserved)) {
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                $params[] = $value;
            }
        }
        
        return $params;
    }


    /**
     * ⚙️ Execute method with enhanced error handling
     */
    private function executeMethod($object, $method, $params) {
        try {
            set_error_handler(array($this, 'errorHandler'));
            
            // ✅ Check if method exists
            if (!method_exists($object, $method)) {
                throw new Exception("Method '$method' does not exist");
            }
    
            // ✅ Get method reflection to check parameters
            $reflection = new ReflectionMethod($object, $method);
            $requiredParamsCount = $reflection->getNumberOfRequiredParameters();
            $totalParamsCount = $reflection->getNumberOfParameters();
    
            // ✅ Validate parameter count
            if (count($params) < $requiredParamsCount) {
                throw new Exception(
                    "Method '$method' requires at least $requiredParamsCount parameter(s), " .
                    "but " . count($params) . " provided. " .
                    "Add missing parameters or use GET to see method details."
                );
            }
    
            if (count($params) > $totalParamsCount) {
                // ⚠️ Trim extra parameters (but don't throw error)
                if (defined('DEBUG') && DEBUG) {
                    error_log("Warning: Extra parameters provided to $method - trimming to $totalParamsCount");
                }
                $params = array_slice($params, 0, $totalParamsCount);
            }
            
            // Execute method
            if (!empty($params)) {
                $result = call_user_func_array(array($object, $method), $params);
            } else {
                $result = $object->$method();
            }
            
            restore_error_handler();
            
            return $result;
            
        } catch (Exception $e) {
            restore_error_handler();
            throw $e;
        }
    }
    

    /**
     * 🚨 Error handler - convert warnings to exceptions
     */
    public function errorHandler($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * 📋 Get detailed method list (PHP 5.6 compatible)
     */
    private function getPublicMethodsDetailed($object, $filter = '') {
        $methods = array();
        $reflection = new ReflectionClass($object);
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            
            $methodName = $method->getName();
            
            if (!empty($filter) && stripos($methodName, $filter) === false) {
                continue;
            }
            
            $params = array();
            foreach ($method->getParameters() as $param) {
                $paramInfo = array(
                    'name' => $param->getName(),
                    'required' => !$param->isOptional(),
                    'position' => $param->getPosition(),
                    'type' => 'mixed'
                );
                
                if ($param->isOptional()) {
                    try {
                        $paramInfo['default'] = $param->getDefaultValue();
                    } catch (Exception $e) {
                        $paramInfo['default'] = null;
                    }
                }
                
                $params[] = $paramInfo;
            }
            
            $methods[] = array(
                'name' => $methodName,
                'parameters' => $params,
                'parameter_count' => count($params),
                'category' => $this->categorizeMethod($methodName)
            );
        }
        
        return $methods;
    }

    /**
     * 🏷️ Categorize method by name
     */
    private function categorizeMethod($methodName) {
        if (preg_match('/^get/i', $methodName)) return 'getter';
        if (preg_match('/^(add|insert|create)/i', $methodName)) return 'creator';
        if (preg_match('/^(edit|update)/i', $methodName)) return 'updater';
        if (preg_match('/^(delete|remove)/i', $methodName)) return 'deleter';
        if (preg_match('/^(total|count)/i', $methodName)) return 'counter';
        return 'other';
    }

    /**
     * 📝 Get simple method list
     */
    private function getPublicMethods($object) {
        $methods = get_class_methods($object);
        $reflection = new ReflectionClass($object);
        $publicMethods = array();
        
        foreach ($methods as $method) {
            $methodReflection = $reflection->getMethod($method);
            if ($methodReflection->isPublic() && !$methodReflection->isConstructor()) {
                $publicMethods[] = $method;
            }
        }
        
        return $publicMethods;
    }

    /**
     * 🔬 Extract fields from method code
     */
    private function extractFieldsFromCode($code) {
        $fields = array(
            'required' => array(),
            'optional' => array(),
            'arrays' => array(),
            'nested' => array(),
            'database_fields' => array()
        );

        // Remove comments
        $code = preg_replace('/\/\*.*?\*\//s', '', $code);
        $code = preg_replace('/\/\/.*$/m', '', $code);

        // Extract all $data['field']
        preg_match_all("/\\\$data\['([^']+)'\]/", $code, $allDataFields);
        $allFields = array_unique($allDataFields[1]);

        // Detect optional
        preg_match_all("/(?:isset|!empty)\(\s*\\\$data\['([^']+)'\]\s*\)/", $code, $optionalFields);
        $optionalList = array_unique($optionalFields[1]);

        // Detect ternary optional
        preg_match_all("/\\\$data\['([^']+)'\]\s*\?\?/", $code, $ternaryFields);
        $optionalList = array_merge($optionalList, $ternaryFields[1]);

        // Detect arrays
        preg_match_all("/foreach\s*\(\s*\\\$data\['([^']+)'\]\s+as/", $code, $arrayFields);
        $arrayList = array_unique($arrayFields[1]);

        // Detect nested fields
        preg_match_all(
            "/foreach\s*\(\s*\\\$data\['([^']+)'\]\s+as\s+([^\)]+)\)\s*\{([^\}]*(?:\{[^\}]*\}[^\}]*)*)\}/s", 
            $code, 
            $foreachBlocks, 
            PREG_SET_ORDER
        );

        foreach ($foreachBlocks as $block) {
            $parentField = $block[1];
            $foreachContent = $block[3];

            preg_match_all("/\\\$(?:value|item|row|v)\['([^']+)'\]/", $foreachContent, $nestedMatches);

            if (!empty($nestedMatches[1])) {
                $fields['nested'][$parentField] = array_unique($nestedMatches[1]);
            }
        }

        // Extract database fields
        $this->extractDatabaseFields($code, $fields);

        // Categorize fields
        foreach ($allFields as $field) {
            if (in_array($field, $arrayList)) {
                $fields['arrays'][] = $field;
            } elseif (in_array($field, $optionalList)) {
                $fields['optional'][] = $field;
            } else {
                $fields['required'][] = $field;
            }
        }

        // Remove duplicates
        $fields['required'] = array_values(array_unique($fields['required']));
        $fields['optional'] = array_values(array_unique($fields['optional']));
        $fields['arrays'] = array_values(array_unique($fields['arrays']));

        return $fields;
    }

    /**
     * 🗄️ Extract database fields from SQL queries
     */
    private function extractDatabaseFields($code, &$fields) {
        preg_match_all("/INSERT INTO.*?SET\s+([^\"]*)/s", $code, $insertMatches);
        preg_match_all("/UPDATE.*?SET\s+([^WHERE\"]*)/s", $code, $updateMatches);

        $allQueries = array_merge($insertMatches[1], $updateMatches[1]);

        foreach ($allQueries as $query) {
            preg_match_all("/[`']?(\w+)[`']?\s*=/", $query, $dbFields);
            if (!empty($dbFields[1])) {
                $fields['database_fields'] = array_merge(
                    $fields['database_fields'],
                    $dbFields[1]
                );
            }
        }

        $fields['database_fields'] = array_values(array_unique($fields['database_fields']));
    }

    /**
     * 🔎 Extract called methods from code
     */
    private function extractCalledMethods($code) {
        $methods = array();

        preg_match_all("/\\\$this->model_([a-z_]+)->([a-zA-Z]+)/", $code, $modelCalls);
        
        for ($i = 0; $i < count($modelCalls[0]); $i++) {
            $model = str_replace('_', '/', $modelCalls[1][$i]);
            $method = $modelCalls[2][$i];
            $methods[] = array(
                'model' => $model,
                'method' => $method,
                'type' => 'model_call'
            );
        }

        preg_match_all("/\\\$this->load->model\('([^']+)'\)/", $code, $loadCalls);
        foreach ($loadCalls[1] as $model) {
            $methods[] = array(
                'model' => $model,
                'type' => 'model_load'
            );
        }

        return $methods;
    }

    /**
     * ✅ Extract validation rules
     */
    private function extractValidationRules($code) {
        $rules = array();

        $patterns = array(
            'required' => "/if\s*\(\s*!\\\$data\['([^']+)'\]\s*\)/",
            'email' => "/filter_var\(\\\$data\['([^']+)'\],\s*FILTER_VALIDATE_EMAIL\)/",
            'numeric' => "/is_numeric\(\\\$data\['([^']+)'\]\)/",
            'length' => "/(strlen|mb_strlen)\(\\\$data\['([^']+)'\]\)/",
        );

        foreach ($patterns as $ruleType => $pattern) {
            preg_match_all($pattern, $code, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $field) {
                    if (!isset($rules[$field])) {
                        $rules[$field] = array();
                    }
                    $rules[$field][] = array('type' => $ruleType);
                }
            }
        }

        return $rules;
    }

    /**
     * 🏗️ Build example JSON from fields
     */
    private function buildExampleFromFields($fields) {
        $example = array();

        foreach ($fields['required'] as $field) {
            $example[$field] = $this->guessFieldValue($field, true);
        }

        foreach ($fields['optional'] as $field) {
            $example[$field] = $this->guessFieldValue($field, false);
        }

        foreach ($fields['arrays'] as $field) {
            if (isset($fields['nested'][$field])) {
                $nestedExample = array();
                foreach ($fields['nested'][$field] as $nestedField) {
                    $nestedExample[$nestedField] = $this->guessFieldValue($nestedField);
                }

                if (preg_match('/(description|seo_url|name|title)/i', $field)) {
                    $example[$field] = array(
                        1 => $nestedExample,
                        2 => $nestedExample
                    );
                } else {
                    $example[$field] = array($nestedExample);
                }
            } else {
                $example[$field] = array();
            }
        }

        return $example;
    }

    /**
     * 💡 Guess field value based on name
     */
    private function guessFieldValue($fieldName, $isRequired = true) {
        $fieldLower = strtolower($fieldName);

        // IDs
        if (preg_match('/_id$/', $fieldLower)) {
            if (strpos($fieldLower, 'language') !== false) return 1;
            if (strpos($fieldLower, 'store') !== false) return 0;
            return 0;
        }

        // Status
        if ($fieldLower === 'status') return 1;

        // Numeric
        if (strpos($fieldLower, 'quantity') !== false) return 100;
        if (strpos($fieldLower, 'price') !== false) return '99.99';
        if (strpos($fieldLower, 'sort_order') !== false) return 0;

        // Dates
        if (strpos($fieldLower, 'date') !== false) return date('Y-m-d');

        // Text
        if (strpos($fieldLower, 'name') !== false) return 'Sample ' . ucfirst($fieldName);
        if (strpos($fieldLower, 'description') !== false) return '<p>Description for ' . $fieldName . '</p>';
        if (strpos($fieldLower, 'model') !== false) return 'PROD-' . rand(1000, 9999);

        return $isRequired ? 'Value for ' . $fieldName : '';
    }

    /**
     * 📖 Generate usage examples
     */
    private function generateUsageExamples($module, $method, $exampleData, $isModel) {
        $type = $isModel ? 'model' : 'controller';
        $baseUrl = 'https://yourdomain.com/api/dynamic_admin/' . $type;
        $examples = array();

        if (preg_match('/^get/i', $method)) {
            $examples['get_single'] = array(
                'description' => 'Get single record by ID',
                'method' => 'GET',
                'url' => $baseUrl . '?module=' . $module . '&method=' . $method . '&id=123&api_key=YOUR_API_KEY'
            );
        }

        if (preg_match('/^add/i', $method)) {
            $examples['add'] = array(
                'description' => 'Add new record',
                'method' => 'POST',
                'url' => $baseUrl . '?module=' . $module . '&method=' . $method . '&api_key=YOUR_API_KEY',
                'body' => $exampleData
            );
        }

        if (preg_match('/^edit/i', $method)) {
            $examples['edit_full'] = array(
                'description' => 'Edit with complete data',
                'method' => 'POST',
                'url' => $baseUrl . '?module=' . $module . '&method=' . $method . '&id=123&api_key=YOUR_API_KEY',
                'body' => $exampleData
            );
            
            $examples['edit_partial'] = array(
                'description' => 'Edit with partial data (auto-merge)',
                'method' => 'POST',
                'url' => $baseUrl . '?module=' . $module . '&method=' . $method . '&id=123&api_key=YOUR_API_KEY',
                'body' => array('status' => 0, 'quantity' => 50),
                'note' => 'Only changed fields needed - missing fields will be auto-merged from existing data'
            );
        }

        return $examples;
    }

    /**
     * 📊 Get method parameters info
     */
    private function getMethodParametersInfo($reflection) {
        $params = array();
        foreach ($reflection->getParameters() as $param) {
            $paramInfo = array(
                'name' => $param->getName(),
                'required' => !$param->isOptional(),
                'position' => $param->getPosition(),
                'type' => 'mixed'
            );

            if ($param->isOptional()) {
                try {
                    $paramInfo['default'] = $param->getDefaultValue();
                } catch (Exception $e) {
                    $paramInfo['default'] = null;
                }
            }

            $params[] = $paramInfo;
        }
        return $params;
    }

    /**
     * 🔍 Find similar method name
     */
    private function findSimilarMethod($needle, $haystack) {
        $needle = strtolower($needle);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($haystack as $method) {
            $methodLower = strtolower($method);
            
            if (strpos($methodLower, $needle) !== false || strpos($needle, $methodLower) !== false) {
                return $method;
            }

            $distance = levenshtein($needle, $methodLower);
            $maxLength = max(strlen($needle), strlen($methodLower));
            $similarity = 1 - ($distance / $maxLength);

            if ($similarity > $bestScore) {
                $bestScore = $similarity;
                $bestMatch = $method;
            }
        }

        return $bestScore > 0.6 ? $bestMatch : null;
    }

    /**
     * 📤 Get parameter value from request
     */
    private function getParam($name, $default = null) {
        if (isset($this->request->get[$name])) {
            return $this->request->get[$name];
        }
        if (isset($this->request->post[$name])) {
            return $this->request->post[$name];
        }
        return $default;
    }

    /**
     * ⏱️ Calculate execution time
     */
    private function getExecutionTime() {
        if (defined('MICROTIME_START')) {
            return round((microtime(true) - MICROTIME_START) * 1000, 2) . 'ms';
        }
        return 'N/A';
    }
}
