<?php
require_once(DIR_APPLICATION . 'controller/api/base_catalog.php');

class ControllerApiDynamicCatalog extends ControllerApiBaseCatalog {
    
    // Cache for inspection results
    private $inspectionCache = [];
    
    // Model dependency list (Catalog side)
    private $modelDependencies = [
        'catalog/product' => [
            'catalog/category',
            'catalog/manufacturer',
            'catalog/review',
            'tool/image'
        ],
        'catalog/category' => [
            'catalog/product',
            'tool/image'
        ],
        'checkout/order' => [
            'account/customer',
            'account/address',
            'catalog/product',
            'localisation/currency',
            'localisation/country',
            'localisation/zone'
        ],
        'account/customer' => [
            'account/address',
            'account/customer_group'
        ],
        'account/wishlist' => [
            'catalog/product',
            'tool/image'
        ],
        'account/order' => [
            'catalog/product',
            'account/address'
        ]
    ];

    /**
     * 🎯 MODEL ENDPOINT (Catalog)
     * Execute model methods dynamically
     * 
     * Usage:
     * GET  /index.php?route=api/dynamic_catalog/model&module=catalog/product&api_key=XXX
     * GET  /index.php?route=api/dynamic_catalog/model&module=catalog/product&method=getProduct&api_key=XXX
     * GET  /index.php?route=api/dynamic_catalog/model&module=catalog/product&method=getProduct&product_id=123&api_key=XXX
     * POST /index.php?route=api/dynamic_catalog/model&module=catalog/product&method=getProducts&api_key=XXX
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
                        'list_methods' => 'GET /index.php?route=api/dynamic_catalog/model&module=catalog/product&api_key=XXX',
                        'inspect_method' => 'GET /index.php?route=api/dynamic_catalog/model&module=catalog/product&method=getProduct&api_key=XXX',
                        'call_method' => 'POST /index.php?route=api/dynamic_catalog/model&module=catalog/product&method=getProduct&product_id=123&api_key=XXX'
                    ]
                ], 400);
                return;
            }

            // Load model dependencies
            $dependenciesLoaded = $this->loadModelDependencies($module);

            // Load the main model
            $filePath = $this->getCorrectFilePath($module, 'model');
            
            if (!$this->loadCatalogModel($module)) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Catalog Model not found: ' . $module,
                    'file_searched' => $filePath,
                    'file_exists' => file_exists($filePath),
                    'modification_type' => $this->modificationType,
                    'vqmod_active' => $this->isVQModActive(),
                    'ocmod_active' => $this->isOCModActive(),
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

            // Get actual loaded file
            $reflection = new ReflectionClass($modelObject);
            $actualLoadedFile = $reflection->getFileName();

            // If no method specified, list all methods
            if (empty($method)) {
                $this->listModelMethods($module, $modelObject, $actualLoadedFile, $dependenciesLoaded);
                return;
            }

            // Check if method exists
            if (!method_exists($modelObject, $method)) {
                $availableMethods = $this->getPublicMethods($modelObject);

                $this->sendResponse([
                    'success' => false,
                    'error' => 'Method not found: ' . $method,
                    'file_loaded' => $actualLoadedFile,
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
                $this->inspectMethod($module, $method, $modelObject, $actualLoadedFile, $dependenciesLoaded);
                return;
            }

            // Execute the method
            $this->executeModelMethod($module, $method, $modelObject, $actualLoadedFile, $dependenciesLoaded);

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
     * 🎮 CONTROLLER ENDPOINT (Catalog)
     * Execute controller methods dynamically
     * 
     * Usage:
     * GET  /index.php?route=api/dynamic_catalog/controller&module=product/product&api_key=XXX
     * GET  /index.php?route=api/dynamic_catalog/controller&module=product/product&method=index&api_key=XXX
     * POST /index.php?route=api/dynamic_catalog/controller&module=product/product&method=index&product_id=123&api_key=XXX
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
                        'list_methods' => 'GET /index.php?route=api/dynamic_catalog/controller&module=product/product&api_key=XXX',
                        'inspect_method' => 'GET /index.php?route=api/dynamic_catalog/controller&module=product/product&method=index&api_key=XXX',
                        'call_method' => 'POST /index.php?route=api/dynamic_catalog/controller&module=product/product&method=index&api_key=XXX'
                    ]
                ], 400);
                return;
            }

            // Load controller
            $filePath = $this->getCorrectFilePath($module, 'controller');
            $controller = $this->loadCatalogController($module);

            if (!$controller) {
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Catalog Controller not found: ' . $module,
                    'file_searched' => $filePath,
                    'file_exists' => file_exists($filePath),
                    'modification_type' => $this->modificationType,
                    'hint' => 'Check if the controller file exists at the path above'
                ], 404);
                return;
            }

            // Get actual loaded file
            $reflection = new ReflectionClass($controller);
            $actualLoadedFile = $reflection->getFileName();

            // If no method specified, list all methods
            if (empty($method)) {
                $this->listControllerMethods($module, $controller, $actualLoadedFile);
                return;
            }

            // Check if method exists
            if (!method_exists($controller, $method)) {
                $availableMethods = $this->getPublicMethods($controller);

                $this->sendResponse([
                    'success' => false,
                    'error' => 'Method not found: ' . $method,
                    'file_loaded' => $actualLoadedFile,
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
                $this->inspectMethod($module, $method, $controller, $actualLoadedFile, []);
                return;
            }

            // Execute the method
            $this->executeControllerMethod($module, $method, $controller, $actualLoadedFile);

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
     * GET /index.php?route=api/dynamic_catalog/discover&type=model&api_key=XXX
     * GET /index.php?route=api/dynamic_catalog/discover&type=controller&api_key=XXX
     * GET /index.php?route=api/dynamic_catalog/discover&type=all&api_key=XXX
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
                'api_type' => 'CATALOG (Frontend)',
                'discovered' => $result,
                'total_models' => count($result['models']),
                'total_controllers' => count($result['controllers']),
                'catalog_path' => $this->catalogPath,
                'modification_status' => [
                    'type' => $this->modificationType,
                    'vqmod_active' => $this->isVQModActive(),
                    'ocmod_active' => $this->isOCModActive()
                ]
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ============ 📋 LIST METHODS ============
    
    /**
     * 📋 List model methods with file info
     */
    private function listModelMethods($module, $modelObject, $filePath, $dependenciesLoaded) {
        $publicMethods = $this->getPublicMethodsDetailed($modelObject);
        
        // 🆕 Get actual loaded file using reflection
        $reflection = new ReflectionClass($modelObject);
        $actualLoadedFile = $reflection->getFileName();
        
        // 🆕 Calculate default (unmodified) path
        $defaultPath = $this->normalizePath($this->catalogPath . 'model/' . $module . '.php');
        $actualPath = $this->normalizePath($actualLoadedFile);
        
        // 🆕 Check if file is modified
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
            'api_type' => 'CATALOG (Frontend)',
            'type' => 'catalog_model',
            'module' => $module,
            'file_info' => [
                'actual_loaded_file' => $actualLoadedFile,  // ✅ Actual file from reflection
                'file_exists' => file_exists($actualLoadedFile),
                'is_modified' => $isModified,  // ✅ Corrected detection
                'modification_type' => $this->modificationType,
                'default_path' => $defaultPath,  // 🆕 Show what the default would be
                'file_size' => file_exists($actualLoadedFile) ? filesize($actualLoadedFile) : 0,
                'last_modified' => file_exists($actualLoadedFile) ? date('Y-m-d H:i:s', filemtime($actualLoadedFile)) : null
            ],
            'methods' => $publicMethods,
            'grouped_methods' => $grouped,
            'total_methods' => count($publicMethods),
            'dependencies_loaded' => $dependenciesLoaded,
            'modification_status' => [
                'vqmod_active' => $this->isVQModActive(),
                'ocmod_active' => $this->isOCModActive(),
                'detected_type' => $this->modificationType
            ],
            'hints' => [
                'inspect_method' => 'Add &method=METHOD_NAME to see detailed analysis',
                'call_method' => 'Use POST with &method=METHOD_NAME and parameters to execute'
            ]
        ]);
    }
    

    /**
     * 📋 List controller methods with file info
     */
    private function listControllerMethods($module, $controller, $filePath) {
        $publicMethods = $this->getPublicMethodsDetailed($controller);
        
        // 🆕 Get actual loaded file using reflection
        $reflection = new ReflectionClass($controller);
        $actualLoadedFile = $reflection->getFileName();
        
        // 🆕 Calculate default path
        $defaultPath = $this->normalizePath($this->catalogPath . 'controller/' . $module . '.php');
        $actualPath = $this->normalizePath($actualLoadedFile);
        
        // 🆕 Check if file is modified
        $isModified = !$this->pathsEqual($actualPath, $defaultPath);
        
        $this->sendResponse([
            'success' => true,
            'api_type' => 'CATALOG (Frontend)',
            'type' => 'catalog_controller',
            'module' => $module,
            'file_info' => [
                'actual_loaded_file' => $actualLoadedFile,
                'file_exists' => file_exists($actualLoadedFile),
                'is_modified' => $isModified,
                'modification_type' => $this->modificationType,
                'default_path' => $defaultPath,
                'file_size' => file_exists($actualLoadedFile) ? filesize($actualLoadedFile) : 0,
                'last_modified' => file_exists($actualLoadedFile) ? date('Y-m-d H:i:s', filemtime($actualLoadedFile)) : null
            ],
            'methods' => $publicMethods,
            'total_methods' => count($publicMethods),
            'modification_status' => [
                'vqmod_active' => $this->isVQModActive(),
                'ocmod_active' => $this->isOCModActive(),
                'detected_type' => $this->modificationType
            ],
            'hints' => [
                'inspect_method' => 'Add &method=METHOD_NAME to see detailed analysis',
                'call_method' => 'Use POST with &method=METHOD_NAME and parameters to execute'
            ]
        ]);
    }   


    // ============ 🔬 INSPECTION METHODS ============
    
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
    
        // Extract information from code
        $fields = $this->extractFieldsFromCode($methodCode);
        $calledMethods = $this->extractCalledMethods($methodCode);
        $validations = $this->extractValidationRules($methodCode);
        
        // 🆕 SMART INSPECTION: If controller has no fields, follow to model
        $followUpData = null;
        $isController = stripos(get_class($object), 'Controller') !== false;
        
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
            'api_type' => 'CATALOG (Frontend)',
            'type' => stripos(get_class($object), 'Model') !== false ? 'catalog_model' : 'catalog_controller',
            'module' => $module,
            'method' => $method,
            'file_info' => array(
                'loaded_from' => $filePath,
                'actual_file' => $filename,
                'is_modified' => !$this->pathsEqual($filePath, $filename),
                'modification_type' => $this->modificationType,
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
            'usage_examples' => $this->generateUsageExamples($module, $method, $exampleData, stripos(get_class($object), 'Model') !== false),
            'dependencies_loaded' => $dependenciesLoaded,
            'raw_code' => (defined('DEBUG') && DEBUG) ? $methodCode : 'Enable DEBUG to see code',
            'from_cache' => false,
            'modification_status' => [
                'vqmod_active' => $this->isVQModActive(),
                'ocmod_active' => $this->isOCModActive()
            ],
            'hints' => [
                'execute' => 'Use POST with JSON body or URL parameters to execute this method',
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
            
            // Check if method names match
            if (stripos($modelMethod, $controllerMethod) === false && 
                stripos($controllerMethod, $modelMethod) === false) {
                continue;
            }
            
            // Try to load and inspect the model
            try {
                if (!$this->loadCatalogModel($modelModule)) {
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

    // ============ ⚡ EXECUTION METHODS ============

    /**
     * ⚡ Execute model method
     */
    private function executeModelMethod($module, $method, $modelObject, $filePath, $dependenciesLoaded) {
        // Get method reflection for inspection
        $reflection = new ReflectionMethod($modelObject, $method);

        // 🆕 Extract method code and detect dependencies
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = file($filename);
        $methodCode = implode("", array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        // 🆕 Auto-load detected model dependencies
        $calledMethods = $this->extractCalledMethods($methodCode);
        foreach ($calledMethods as $call) {
            if ($call['type'] === 'model_call') {
                $depModel = $call['model'];
                if (!in_array($depModel, $dependenciesLoaded)) {
                    try {
                        if ($this->loadCatalogModel($depModel)) {
                            $dependenciesLoaded[] = $depModel;
                            if (defined('DEBUG') && DEBUG) {
                                error_log("🔄 Auto-loaded dependency: $depModel");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Failed to auto-load $depModel: " . $e->getMessage());
                    }
                }
            }
        }

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
                'api_type' => 'CATALOG (Frontend)',
                'type' => 'catalog_model',
                'module' => $module,
                'method' => $method,
                'file_loaded' => $filePath,
                'is_modified' => ($filePath != $this->catalogPath . 'model/' . $module . '.php'),
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
        $autoMerged = false;
        
        // Get method parameters
        $params = $this->getMethodParams($method, null, null, $autoMerged);
    
        // 🆕 Start output buffering (for echo/print statements)
        ob_start();
        
        try {
            // 🔑 KEY FIX: Clear any existing response output before execution
            if (is_object($controller->response) && method_exists($controller->response, 'setOutput')) {
                $controller->response->setOutput('');
            }
            
            // Execute method
            $result = $this->executeMethod($controller, $method, $params);
            
            // 🆕 Get output buffer (for direct echo/print)
            $bufferOutput = ob_get_clean();
            
            // 🔑 KEY FIX: Get response output (from $this->response->setOutput)
            $responseOutput = '';
            if (is_object($controller->response) && method_exists($controller->response, 'getOutput')) {
                $responseOutput = $controller->response->getOutput();
                
                // 🧹 Clean response so it doesn't get sent automatically
                if (method_exists($controller->response, 'setOutput')) {
                    $controller->response->setOutput('');
                }
            }
            
            // 🆕 Combine all outputs
            $totalOutput = trim($responseOutput . $bufferOutput);
            
            // 🆕 Build final result
            if (!empty($totalOutput)) {
                // ✅ We captured HTML output
                $finalResult = [
                    'type' => 'html',
                    'content' => $totalOutput,
                    'content_length' => strlen($totalOutput),
                    'content_preview' => substr($totalOutput, 0, 200) . '...',
                    'returned_value' => $result,
                    'sources' => [
                        'response_object' => !empty($responseOutput),
                        'output_buffer' => !empty($bufferOutput),
                        'response_length' => strlen($responseOutput),
                        'buffer_length' => strlen($bufferOutput)
                    ]
                ];
            } elseif ($result !== null) {
                // Direct return value (rare in controllers)
                $finalResult = $result;
            } else {
                // No output and no return
                $finalResult = null;
            }
            
        } catch (Exception $e) {
            ob_end_clean();
            throw $e;
        }
    
        $this->sendResponse([
            'success' => true,
            'result' => $finalResult,
            'meta' => [
                'api_type' => 'CATALOG (Frontend)',
                'type' => 'catalog_controller',
                'module' => $module,
                'method' => $method,
                'file_loaded' => $filePath,
                'is_modified' => ($filePath != $this->catalogPath . 'controller/' . $module . '.php'),
                'modification_type' => $this->modificationType,
                'params_count' => count($params),
                'output_captured' => !empty($totalOutput),
                'output_method' => !empty($responseOutput) ? 'response_object' : (!empty($bufferOutput) ? 'output_buffer' : 'none'),
                'response_methods_available' => [
                    'has_getOutput' => is_object($controller->response) && method_exists($controller->response, 'getOutput'),
                    'has_setOutput' => is_object($controller->response) && method_exists($controller->response, 'setOutput')
                ],
                'execution_time' => $this->getExecutionTime()
            ]
        ]);
    }

    // ============ 🔧 HELPER METHODS ============

    /**
     * 📁 Scan directory for PHP files
     */
    private function scanDirectory($type) {
        $basePath = $this->catalogPath . $type . '/';
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
     * 🔄 Load model dependencies (with auto-detection)
     */
    private function loadModelDependencies($module) {
        $loaded = array();

        // 1️⃣ Load pre-defined dependencies
        if (isset($this->modelDependencies[$module])) {
            foreach ($this->modelDependencies[$module] as $depModel) {
                try {
                    if ($this->loadCatalogModel($depModel)) {
                        $loaded[] = $depModel;
                    }
                } catch (Exception $e) {
                    error_log("Dependency load failed: $depModel - " . $e->getMessage());
                }
            }
        }

        // 2️⃣ NEW: Auto-load dependencies from method inspection
        // This will load any model that's called via $this->model_xxx->method()

        return $loaded;
    }


    /**
     * 🎯 Get method parameters (with auto-merge for partial updates)
     */
    private function getMethodParams($method, $module = null, $modelObject = null, &$autoMerged = false) {
        $params = array();

        // Get JSON body
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonInput = null;
        }

        // Get direct URL parameters
        $urlParams = $this->extractDirectParams();

        // Detect method type
        $isEditMethod = preg_match('/(edit|update)/i', $method);
        $isAddMethod = preg_match('/(add|insert|create)/i', $method);
        $isGetMethod = preg_match('/(get|fetch|load|list)/i', $method);

        // EDIT method with auto-merge
        if ($isEditMethod) {
            if ($jsonInput && !empty($jsonInput)) {
                $isPartialData = $this->isPartialEditData($jsonInput, $method, $module);

                if ($isPartialData) {
                    // Partial data - need auto-merge
                    if (defined('DEBUG') && DEBUG) {
                        error_log("🔄 PARTIAL DATA DETECTED - Starting Auto-Merge");
                        error_log("Method: $method | Data: " . json_encode($jsonInput));
                    }

                    if (empty($urlParams)) {
                        throw new Exception(
                            "Auto-Merge Error: ID parameter is missing. " .
                            "For partial updates, you MUST provide the record ID in URL. " .
                            "Example: &product_id=61"
                        );
                    }

                    if (!$modelObject) {
                        throw new Exception("Auto-Merge Error: Model object not available");
                    }

                    $recordId = $urlParams[0];
                    $existingData = $this->getExistingData($modelObject, $method, $recordId);

                    if ($existingData && is_array($existingData) && !empty($existingData)) {
                        $mergedData = $this->smartMerge($existingData, $jsonInput);
                        $autoMerged = true;

                        if (defined('DEBUG') && DEBUG) {
                            error_log("✅ AUTO-MERGE SUCCESSFUL! Changed fields: " . implode(', ', array_keys($jsonInput)));
                        }

                        $params = array_merge($urlParams, array($mergedData));
                    } else {
                        throw new Exception(
                            "Auto-Merge Failed: Cannot retrieve existing data for ID: $recordId. " .
                            "Possible causes: Record doesn't exist, Getter method not working, or Database issue."
                        );
                    }
                } else {
                    // Complete data
                    if (!empty($urlParams)) {
                        $params = array_merge($urlParams, array($jsonInput));
                    } else {
                        $params = array($jsonInput);
                    }
                }
            } else {
                $params = $urlParams;
            }
        }
        // ADD method
        elseif ($isAddMethod) {
            if ($jsonInput && !empty($jsonInput)) {
                $params = array($jsonInput);
            } else {
                $params = $urlParams;
            }
        }
        // GET method
        elseif ($isGetMethod) {
            if ($jsonInput && isset($jsonInput['params']) && is_array($jsonInput['params'])) {
                $params = $jsonInput['params'];
            } elseif (!empty($urlParams)) {
                $params = $urlParams;
            } elseif ($jsonInput && is_array($jsonInput)) {
                $params = array($jsonInput);
            }
        }
        // Other methods
        else {
            if ($jsonInput) {
                if (isset($jsonInput['params'])) {
                    $params = is_array($jsonInput['params']) ? $jsonInput['params'] : array($jsonInput['params']);
                } else {
                    $params = array($jsonInput);
                }
            } elseif (!empty($urlParams)) {
                $params = $urlParams;
            }
        }

        return $params;
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
     * 🔍 Get existing data (auto-detect getter method)
     */
    private function getExistingData($modelObject, $editMethod, $id) {
        // Possible getter methods
        $possibleGetters = array(
            preg_replace('/(edit|update)/i', 'get', $editMethod, 1),
            preg_replace('/(edit|update)([A-Z]\w+)/i', 'get$2', $editMethod),
            'get' . ucfirst($editMethod)
        );

        foreach ($possibleGetters as $getMethod) {
            if (!method_exists($modelObject, $getMethod)) {
                continue;
            }

            try {
                $data = $modelObject->$getMethod($id);

                if ($data === null || $data === false || (is_array($data) && empty($data))) {
                    continue;
                }

                if (defined('DEBUG') && DEBUG) {
                    error_log("✅ $getMethod returned valid data with " . count($data) . " fields");
                }

                return $data;

            } catch (Exception $e) {
                continue;
            }
        }

        return null;
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
     * ⚙️ Execute method with error handling
     */
    private function executeMethod($object, $method, $params) {
        try {
            set_error_handler(array($this, 'errorHandler'));
            
            if (is_array($params) && !empty($params)) {
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
     * 📋 Get detailed method list
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
        $baseUrl = 'https://yourdomain.com/index.php?route=api/dynamic_catalog/' . $type;
        $examples = array();

        if (preg_match('/^get/i', $method)) {
            $examples['get_single'] = array(
                'description' => 'Get single record by ID',
                'method' => 'GET',
                'url' => $baseUrl . '&module=' . $module . '&method=' . $method . '&id=123&api_key=YOUR_API_KEY'
            );
        }

        if (preg_match('/^add/i', $method)) {
            $examples['add'] = array(
                'description' => 'Add new record',
                'method' => 'POST',
                'url' => $baseUrl . '&module=' . $module . '&method=' . $method . '&api_key=YOUR_API_KEY',
                'body' => $exampleData
            );
        }

        if (preg_match('/^edit/i', $method)) {
            $examples['edit_full'] = array(
                'description' => 'Edit with complete data',
                'method' => 'POST',
                'url' => $baseUrl . '&module=' . $module . '&method=' . $method . '&id=123&api_key=YOUR_API_KEY',
                'body' => $exampleData
            );
            
            $examples['edit_partial'] = array(
                'description' => 'Edit with partial data (auto-merge)',
                'method' => 'POST',
                'url' => $baseUrl . '&module=' . $module . '&method=' . $method . '&id=123&api_key=YOUR_API_KEY',
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

}
