<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class SlideController extends BaseController {
    protected function getAllowedMethods() {
        return ['GET', 'POST'];
    }

    public function get_slide() {
        $classroom_id = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : null;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$classroom_id && !$id) {
            $this->sendError('Either classroom_id or id is required');
        }

        $query = "SELECT * FROM slide WHERE 1=1";
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

        $slides = [];
        while ($row = $result->fetch_assoc()) {
            $slides[] = $row;
        }

        $this->sendResponse($slides, 'Slides retrieved successfully');
        $stmt->close();
    }

    private function convertToPdf($inputFile, $outputFile) {
        try {
            // Use LibreOffice for conversion
            $command = "soffice --headless --convert-to pdf --outdir " . dirname($outputFile) . " " . escapeshellarg($inputFile);
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                error_log("LibreOffice conversion failed: " . implode("\n", $output));
                return false;
            }
            
            // Get the converted file path (LibreOffice adds .pdf extension)
            $convertedFile = dirname($outputFile) . '/' . pathinfo($inputFile, PATHINFO_FILENAME) . '.pdf';
            
            // Rename to our desired output filename
            if (file_exists($convertedFile)) {
                rename($convertedFile, $outputFile);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error converting to PDF: " . $e->getMessage());
            return false;
        }
    }

    public function post_slide() {
        // Only teachers can upload slides
        if ($this->user['role'] !== 'teacher') {
            $this->sendError('Unauthorized', 403);
        }

        $this->validateRequiredFields(['classroom_id']);

        if (!isset($_FILES['slide'])) {
            $this->sendError('No slide file uploaded');
        }

        $classroom_id = (int)$this->input['classroom_id'];
        $file = $_FILES['slide'];

        // Validate file
        $allowed_types = ['application/pdf', 'application/vnd.ms-powerpoint', 
                         'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        if (!in_array($file['type'], $allowed_types)) {
            $this->sendError('Invalid file type. Only PDF and PowerPoint files are allowed');
        }

        if ($file['size'] > 20 * 1024 * 1024) { // 20MB limit
            $this->sendError('File too large. Maximum size is 20MB');
        }

        // Create upload directory if it doesn't exist
        $upload_dir = UPLOAD_DIR . 'slides/' . $classroom_id . '/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $temp_filename = uniqid() . '.' . $extension;
        $temp_path = $upload_dir . $temp_filename;

        // Move uploaded file to temp location
        if (!move_uploaded_file($file['tmp_name'], $temp_path)) {
            $this->sendError('Failed to upload slide');
        }

        // If it's a PowerPoint file, convert to PDF
        if ($file['type'] !== 'application/pdf') {
            $pdf_filename = uniqid() . '.pdf';
            $pdf_path = $upload_dir . $pdf_filename;
            
            if (!$this->convertToPdf($temp_path, $pdf_path)) {
                unlink($temp_path); // Clean up temp file
                $this->sendError('Failed to convert PowerPoint to PDF. Please ensure LibreOffice is installed.');
            }
            
            // Clean up the original PowerPoint file
            unlink($temp_path);
            
            // Update variables to use the PDF file
            $filename = $pdf_filename;
            $full_path = $pdf_path;
        } else {
            // If it's already a PDF, just use the temp file
            $filename = $temp_filename;
            $full_path = $temp_path;
        }

        // Save to database
        $relative_path = 'slides/' . $classroom_id . '/' . $filename;
        $query = "INSERT INTO slide (classroom_id, full_path) VALUES (?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("is", $classroom_id, $relative_path);

        if ($stmt->execute()) {
            $this->sendResponse([
                'slide_id' => $this->conn->insert_id,
                'path' => $relative_path
            ], 'Slide uploaded successfully', 201);
        } else {
            // Delete uploaded file if database insert fails
            unlink($full_path);
            $this->sendError('Failed to save slide record: ' . $this->conn->error);
        }
        $stmt->close();
    }
} 