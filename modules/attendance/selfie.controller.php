<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class SelfieController extends BaseController {
    protected function getAllowedMethods() {
        return ['POST'];
    }

    public function post_upload_selfie() {
        // Check if user is a student
        if ($this->user['role'] != 'student') {
            $this->sendError('Unauthorized: Only students can upload selfies', 403);
        }

        // Validate required fields
        if (!isset($_FILES['selfie']) || !isset($_POST['classroom_id'])) {
            $this->sendError('Missing required fields: selfie and classroom_id');
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
        $file = $_FILES['selfie'];
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
        $upload_dir = __DIR__ . '/../../uploads/selfies/' . $classroom_id;
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                $this->sendError('Failed to create upload directory');
            }
        }

        // Generate unique filename
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $this->user['id'] . '_' . $timestamp . '_' . $random . '.' . $extension;
        $filepath = $upload_dir . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $this->sendError('Failed to save uploaded file');
        }

        // Return success response with file path
        $relative_path = 'uploads/selfies/' . $classroom_id . '/' . $filename;
        $this->sendResponse([
            'path' => $relative_path
        ], 'Selfie uploaded successfully', 201);
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