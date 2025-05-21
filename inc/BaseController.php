<?php
require_once __DIR__ . '/config.php';

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;

class BaseController {
    protected $conn;
    protected $user;
    protected $input;

    public function __construct() {
        $this->conn = getConnection();
        if (!$this->shouldSkipAuth()) {
            $this->authenticate();
        }
        $this->validateMethod();
        $this->parseInput();
    }

    protected function shouldSkipAuth() {
        return false;
    }

    protected function authenticate() {
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

        if (!$token) {
            $this->sendError('No token provided', 401);
        }

        try {
            $decoded = FirebaseJWT::decode($token, new Key(JWT_SECRET, 'HS256'));
            $this->user = (array)$decoded;
        } catch (Exception $e) {
            $this->sendError('Invalid token: ' . $e->getMessage(), 401);
        }
    }

    protected function validateMethod() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, $this->getAllowedMethods())) {
            $this->sendError('Method not allowed', 405);
        }
    }

    protected function getAllowedMethods() {
        return ['GET', 'POST', 'PUT', 'DELETE'];
    }

    protected function parseInput() {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET') {
            $this->input = $_GET;
        } else {
            $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
            
            if (strpos($content_type, 'application/json') !== false) {
                $json = file_get_contents('php://input');
                $this->input = json_decode($json, true) ?? [];
            } else if (strpos($content_type, 'multipart/form-data') !== false) {
                $this->input = $_POST;
            } else {
                parse_str(file_get_contents('php://input'), $this->input);
            }
        }
    }

    protected function validateRequiredFields($fields) {
        foreach ($fields as $field) {
            if (!isset($this->input[$field]) || empty($this->input[$field])) {
                $this->sendError("Missing required field: $field");
            }
        }
    }

    protected function sanitizeString($str) {
        return htmlspecialchars(strip_tags($str));
    }

    protected function sendResponse($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    protected function sendError($message, $code = 400) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => false,
            'message' => $message
        ]);
        exit;
    }

    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
} 