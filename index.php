<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering to catch any errors
ob_start();

try {
    require_once __DIR__ . '/inc/config.php';

    // Debug PHP environment
    error_log("PHP include path: " . get_include_path());
    error_log("PHP version: " . PHP_VERSION);
    error_log("PHP SAPI: " . php_sapi_name());
    error_log("Document root: " . $_SERVER['DOCUMENT_ROOT']);

    // CORS headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    // Get request path and method
    $request_uri = $_SERVER['REQUEST_URI'];
    $base_path = '/airclass-api';
    $path = parse_url($request_uri, PHP_URL_PATH);
    $path = str_replace($base_path, '', $path);
    $method = $_SERVER['REQUEST_METHOD'];

    // Debug routing information
    error_log("=== Routing Debug ===");
    error_log("Raw REQUEST_URI: " . $request_uri);
    error_log("Base path: " . $base_path);
    error_log("Path before processing: " . $path);
    error_log("Method: " . $method);

    // Remove trailing slash and ensure path starts with /
    $path = '/' . ltrim(rtrim($path, '/'), '/');
    error_log("Final path: " . $path);

    // Route mapping to controllers
    $routes = [
        // Auth routes
        'POST /auth/login' => ['AuthController', 'post_login'],
        'POST /auth/register' => ['AuthController', 'post_register'],
        
        // Classroom routes
        'GET /classroom' => ['ClassroomController', 'get_classroom'],
        'POST /classroom' => ['ClassroomController', 'post_classroom'],
        'PUT /classroom' => ['ClassroomController', 'put_classroom'],
        'DELETE /classroom' => ['ClassroomController', 'delete_classroom'],
        
        // Attendance routes
        'GET /attendance' => ['AttendanceController', 'get_attendance'],
        'POST /attendance' => ['AttendanceController', 'post_attendance'],
        'POST /attendance/code' => ['AttendanceController', 'post_attendance_code'],
        
        // Image routes
        'GET /image' => ['ImageController', 'get_image'],
        'POST /image' => ['ImageController', 'post_image'],
        
        // Slide routes
        'GET /slide' => ['SlideController', 'get_slide'],
        'POST /slide' => ['SlideController', 'post_slide'],
        
        // Request routes
        'GET /request' => ['RequestController', 'get_request'],
        'POST /request' => ['RequestController', 'post_request'],
        'PUT /request' => ['RequestController', 'put_request'],
        'DELETE /request' => ['RequestController', 'delete_request']
    ];

    // Find matching route
    $route_key = "$method $path";
    error_log("Constructed route key: " . $route_key);
    error_log("Available routes: " . print_r(array_keys($routes), true));

    if (isset($routes[$route_key])) {
        list($controller, $action) = $routes[$route_key];
        
        // Try a different approach to constructing the path
        $module_name = strtolower(str_replace('Controller', '', $controller));
        $controller_file = realpath(__DIR__ . '/modules/' . $module_name . '/' . $module_name . '.controller.php');
        
        error_log("Module name: " . $module_name);
        error_log("Controller file path: " . $controller_file);
        
        if ($controller_file && file_exists($controller_file)) {
            require_once $controller_file;
            if (class_exists($controller)) {
                $controller_class = new $controller();
                if (method_exists($controller_class, $action)) {
                    $controller_class->$action();
                } else {
                    throw new Exception("Action '$action' not found in controller '$controller'");
                }
            } else {
                throw new Exception("Controller class '$controller' not found in file: " . $controller_file);
            }
        } else {
            throw new Exception("Controller file not found at: " . $controller_file);
        }
    } else {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'status' => false,
            'message' => 'Endpoint not found',
            'debug' => [
                'route_key' => $route_key,
                'available_routes' => array_keys($routes)
            ]
        ]);
    }
} catch (Exception $e) {
    // Clear any output
    ob_clean();
    
    // Send error response
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'Internal Server Error',
        'error' => $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    
    // Log the error
    error_log("Error in index.php: " . $e->getMessage());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// End output buffering and send
ob_end_flush(); 