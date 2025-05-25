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
        // Check if user is a student
        if ($this->user['role'] != 'student') {
            $this->sendError('Unauthorized: Only students can upload selfies', 403);
        }

        // Validate required fields
        if (!isset($_FILES['image']) || !isset($_POST['classroom_id'])) {
            $this->sendError('Missing required fields: image and classroom_id');
        }

        $classroom_id = (int)$_POST['classroom_id'];

        // Verify classroom exists and is active
        $query = "SELECT id FROM classroom WHERE id = ? AND status = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $classroom_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $this->sendError('Invalid or inactive classroom');
        }
        $stmt->close();

        // Verify student is in this classroom
        $query = "SELECT id FROM classroom_student 
                 WHERE classroom_id = ? AND student_id = ? AND status = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom_id, $this->user['id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $this->sendError('You are not a member of this classroom');
        }
        $stmt->close();

        // Validate file
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->sendError('File upload failed: ' . $this->getUploadErrorMessage($file['error']));
        }

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($file['type'], $allowed_types)) {
            $this->sendError('Invalid file type. Only JPG and PNG images are allowed.');
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            $this->sendError('File too large. Maximum size is 5MB.');
        }

        // Create directory if it doesn't exist
        $upload_dir = rtrim(UPLOAD_DIR, '/') . '/selfies/' . $classroom_id . '/';
        if (!file_exists($upload_dir)) {
            if (!@mkdir($upload_dir, 0777, true)) {
                error_log("Failed to create directory: " . $upload_dir . " - Error: " . error_get_last()['message']);
                $this->sendError('Failed to create upload directory. Please contact support.');
            }
            // Ensure directory is writable
            if (!@chmod($upload_dir, 0777)) {
                error_log("Failed to set permissions on directory: " . $upload_dir . " - Error: " . error_get_last()['message']);
                $this->sendError('Failed to set directory permissions. Please contact support.');
            }
        } else {
            // Ensure existing directory is writable
            if (!is_writable($upload_dir)) {
                if (!@chmod($upload_dir, 0777)) {
                    error_log("Failed to set permissions on existing directory: " . $upload_dir . " - Error: " . error_get_last()['message']);
                    $this->sendError('Upload directory is not writable. Please contact support.');
                }
            }
        }

        // Generate unique filename
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $this->user['id'] . '_' . $timestamp . '_' . $random . '.' . $extension;
        $full_path = $upload_dir . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $full_path)) {
            $this->sendError('Failed to save uploaded file');
        }

        // Save to database
        $relative_path = 'selfies/' . $classroom_id . '/' . $filename;
        $query = "INSERT INTO image (classroom_id, full_path, type) VALUES (?, ?, 'selfie')";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("is", $classroom_id, $relative_path);

        if ($stmt->execute()) {
            $this->sendResponse([
                'image_id' => $this->conn->insert_id,
                'path' => $relative_path
            ], 'Selfie uploaded successfully', 201);
        } else {
            // Delete uploaded file if database insert fails
            unlink($full_path);
            $this->sendError('Failed to save selfie record: ' . $this->conn->error);
        }
        $stmt->close();
    }

    private function getUploadErrorMessage($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }
} 