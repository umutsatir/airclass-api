<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class ImageController extends BaseController {
    protected function getAllowedMethods() {
        return ['GET', 'POST'];
    }

    public function get_image() {
        $classroom_id = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : null;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$classroom_id && !$id) {
            $this->sendError('Either classroom_id or id is required');
        }

        $query = "SELECT * FROM image WHERE 1=1";
        $params = [];
        $types = "";

        if ($id) {
            $query .= " AND id = ?";
            $params[] = $id;
            $types .= "i";
        }

        if ($classroom_id) {
            $query .= " AND classroom_id = ?";
            $params[] = $classroom_id;
            $types .= "i";
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $images = [];
        while ($row = $result->fetch_assoc()) {
            $images[] = $row;
        }

        $this->sendResponse($images, 'Images retrieved successfully');
        $stmt->close();
    }

    public function post_image() {
        // Only teachers can upload images
        if ($this->user['role'] !== 'teacher') {
            $this->sendError('Unauthorized', 403);
        }

        $this->validateRequiredFields(['classroom_id']);

        if (!isset($_FILES['image'])) {
            $this->sendError('No image file uploaded');
        }

        $classroom_id = (int)$this->input['classroom_id'];
        $file = $_FILES['image'];

        // Validate file
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed_types)) {
            $this->sendError('Invalid file type. Only JPG, PNG and GIF are allowed');
        }

        if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            $this->sendError('File too large. Maximum size is 5MB');
        }

        // Create upload directory if it doesn't exist
        $upload_dir = UPLOAD_DIR . 'images/' . $classroom_id . '/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $full_path = $upload_dir . $filename;

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $full_path)) {
            // Save to database
            $relative_path = 'images/' . $classroom_id . '/' . $filename;
            $query = "INSERT INTO image (classroom_id, full_path) VALUES (?, ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("is", $classroom_id, $relative_path);

            if ($stmt->execute()) {
                $this->sendResponse([
                    'image_id' => $this->conn->insert_id,
                    'path' => $relative_path
                ], 'Image uploaded successfully', 201);
            } else {
                // Delete uploaded file if database insert fails
                unlink($full_path);
                $this->sendError('Failed to save image record: ' . $this->conn->error);
            }
            $stmt->close();
        } else {
            $this->sendError('Failed to upload image');
        }
    }
} 