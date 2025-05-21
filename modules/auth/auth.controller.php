<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class AuthController extends BaseController {
    protected function getAllowedMethods() {
        return ['POST'];
    }

    protected function shouldSkipAuth() {
        // Skip auth validation for login and register endpoints
        return true;
    }

    public function post_login() {
        $this->validateRequiredFields(['email', 'password']);

        $email = $this->sanitizeString($this->input['email']);
        $password = $this->input['password'];

        $query = "SELECT * FROM user WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                // Generate JWT token
                $token = encodeJWT([
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'exp' => time() + (60 * 60 * 24) // 24 hours
                ]);

                // Remove password from user data
                unset($user['password']);

                $this->sendResponse([
                    'token' => $token,
                    'user' => $user
                ], 'Login successful');
            }
        }

        $this->sendError('Invalid credentials', 401);
        $stmt->close();
    }

    public function post_register() {
        $this->validateRequiredFields(['name', 'email', 'password', 'role']);

        $name = $this->sanitizeString($this->input['name']);
        $email = $this->sanitizeString($this->input['email']);
        $password = $this->input['password'];
        $role = $this->sanitizeString($this->input['role']);

        // Validate role
        if (!in_array($role, ['student', 'teacher', 'admin'])) {
            $this->sendError('Invalid role');
        }

        // Check if email already exists
        $query = "SELECT id FROM user WHERE email = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $this->sendError('Email already registered');
        }
        $stmt->close();

        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Create user
        $query = "INSERT INTO user (name, email, password, role) VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssss", $name, $email, $hashed_password, $role);

        if ($stmt->execute()) {
            $user_id = $this->conn->insert_id;

            // Generate JWT token
            $token = encodeJWT([
                'id' => $user_id,
                'email' => $email,
                'role' => $role,
                'exp' => time() + (60 * 60 * 24) // 24 hours
            ]);

            $this->sendResponse([
                'token' => $token,
                'user' => [
                    'id' => $user_id,
                    'name' => $name,
                    'email' => $email,
                    'role' => $role
                ]
            ], 'Registration successful', 201);
        } else {
            $this->sendError('Failed to create user: ' . $this->conn->error);
        }

        $stmt->close();
    }
} 