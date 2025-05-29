<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class SlideControlController extends BaseController {
    protected function getAllowedMethods() {
        return ['GET', 'POST', 'PUT', 'DELETE'];
    }

    // Create a new slide control request
    public function post_slide_control() {
        // Only teachers can control slides
        if ($this->user['role'] !== 'teacher') {
            $this->sendError('Unauthorized', 403);
        }

        $this->validateRequiredFields(['slide_id', 'classroom_id', 'action']);

        $slide_id = (int)$this->input['slide_id'];
        $classroom_id = (int)$this->input['classroom_id'];
        $action = $this->input['action'];
        $slide_number = isset($this->input['slide_number']) ? (int)$this->input['slide_number'] : null;
        $user_id = $this->user['id'];

        // Validate action
        if (!in_array($action, ['next', 'prev', 'goto'])) {
            $this->sendError('Invalid action. Must be one of: next, prev, goto');
        }

        // Validate slide_number is provided for 'goto' action
        if ($action === 'goto' && $slide_number === null) {
            $this->sendError('slide_number is required for goto action');
        }

        // Validate slide exists and belongs to classroom
        $query = "SELECT id FROM slide WHERE id = ? AND classroom_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $slide_id, $classroom_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $this->sendError('Slide not found or does not belong to this classroom');
        }
        $stmt->close();

        // Insert control request
        $query = "INSERT INTO slide_control (slide_id, classroom_id, user_id, action, slide_number) 
                 VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiisi", $slide_id, $classroom_id, $user_id, $action, $slide_number);

        if ($stmt->execute()) {
            $this->sendResponse([
                'control_id' => $this->conn->insert_id,
                'status' => 'pending'
            ], 'Slide control request created successfully', 201);
        } else {
            $this->sendError('Failed to create slide control request');
        }
        $stmt->close();
    }

    // Get slide control requests
    public function get_slide_control() {
        $classroom_id = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : null;
        $slide_id = isset($_GET['slide_id']) ? (int)$_GET['slide_id'] : null;
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$classroom_id && !$slide_id && !$id) {
            $this->sendError('Either classroom_id, slide_id, or id is required');
        }

        $query = "SELECT sc.*, u.name as user_name 
                 FROM slide_control sc 
                 JOIN user u ON sc.user_id = u.id 
                 WHERE 1=1";
        $params = [];
        $types = "";

        if ($id) {
            $query .= " AND sc.id = ?";
            $params[] = $id;
            $types .= "i";
        }

        if ($classroom_id) {
            $query .= " AND sc.classroom_id = ?";
            $params[] = $classroom_id;
            $types .= "i";
        }

        if ($slide_id) {
            $query .= " AND sc.slide_id = ?";
            $params[] = $slide_id;
            $types .= "i";
        }

        if ($status) {
            $query .= " AND sc.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        $query .= " ORDER BY sc.created_at DESC";

        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $controls = [];
        while ($row = $result->fetch_assoc()) {
            $controls[] = $row;
        }

        $this->sendResponse($controls, 'Slide controls retrieved successfully');
        $stmt->close();
    }

    // Update slide control status
    public function put_slide_control() {
        $this->validateRequiredFields(['id', 'status']);

        $id = (int)$this->input['id'];
        $status = $this->input['status'];

        // Validate status
        if (!in_array($status, ['pending', 'completed', 'cancelled'])) {
            $this->sendError('Invalid status. Must be one of: pending, completed, cancelled');
        }

        // Check if control exists and user has permission
        $query = "SELECT * FROM slide_control WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $this->sendError('Slide control not found');
        }

        $control = $result->fetch_assoc();
        
        // Only teacher who created the control can update
        if ($this->user['role'] !== 'teacher' || 
            ($this->user['id'] !== $control['user_id'])) {
            $this->sendError('Unauthorized', 403);
        }

        // Update status
        $query = "UPDATE slide_control SET status = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $status, $id);

        if ($stmt->execute()) {
            $this->sendResponse([
                'id' => $id,
                'status' => $status
            ], 'Slide control status updated successfully');
        } else {
            $this->sendError('Failed to update slide control status');
        }
        $stmt->close();
    }

    // Delete slide control
    public function delete_slide_control() {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$id) {
            $this->sendError('Control ID is required');
        }

        // Check if control exists and user has permission
        $query = "SELECT * FROM slide_control WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $this->sendError('Slide control not found');
        }

        $control = $result->fetch_assoc();
        
        // Only teacher who created the control can delete
        if ($this->user['role'] !== 'teacher' || 
            $this->user['id'] !== $control['user_id']) {
            $this->sendError('Unauthorized', 403);
        }

        // Delete control
        $query = "DELETE FROM slide_control WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $this->sendResponse(null, 'Slide control deleted successfully');
        } else {
            $this->sendError('Failed to delete slide control');
        }
        $stmt->close();
    }
} 