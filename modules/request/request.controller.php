<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class RequestController extends BaseController {
    protected function getAllowedMethods() {
        return ['GET', 'POST', 'PUT', 'DELETE'];
    }

    public function get_request_check() {
        // Only students can check their request status
        if ($this->user['role'] !== 'student') {
            $this->sendError('Unauthorized: Only students can check request status', 403);
        }

        $this->validateRequiredFields(['classroom_id']);

        $classroom_id = (int)$this->input['classroom_id'];

        // First check if any request exists for this student in this classroom
        $query = "SELECT id, status FROM request 
                 WHERE classroom_id = ? AND user_id = ? 
                 ORDER BY created_at DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom_id, $this->user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $this->sendResponse([
                'hasActiveRequest' => false
            ], 'No request found for this classroom', 200, false);
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        // If we have a request, check if it's approved
        $has_active_request = $row['status'] === 'approved';
        
        $this->sendResponse([
            'hasActiveRequest' => $has_active_request,
            'request_id' => (int)$row['id']
        ], $has_active_request ? 'Request is approved' : 'Request is pending or rejected', 200, $has_active_request);
    }

    public function get_request() {
        $classroom_id = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : null;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$classroom_id && !$id) {
            $this->sendError('Either classroom_id or id is required');
        }

        $query = "SELECT r.*, u.name as user_name, u.email as user_email 
                 FROM request r 
                 JOIN user u ON r.user_id = u.id 
                 WHERE 1=1";
        $params = [];
        $types = "";

        if ($id) {
            $query .= " AND r.id = ?";
            $params[] = $id;
            $types .= "i";
        }

        if ($classroom_id) {
            $query .= " AND r.classroom_id = ?";
            $params[] = $classroom_id;
            $types .= "i";
        }

        // Students can only see their own requests
        if ($this->user['role'] === 'student') {
            $query .= " AND r.user_id = ?";
            $params[] = $this->user['id'];
            $types .= "i";
        }

        $query .= " ORDER BY r.created_at DESC";

        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }

        $this->sendResponse($requests, 'Requests retrieved successfully');
        $stmt->close();
    }

    public function post_request() {
        // Only students can create requests
        if ($this->user['role'] !== 'student') {
            $this->sendError('Unauthorized', 403);
        }

        $this->validateRequiredFields(['classroom_id']);

        $classroom_id = (int)$this->input['classroom_id'];

        // Check if classroom exists
        $stmt = $this->conn->prepare("SELECT id FROM classroom WHERE id = ?");
        $stmt->bind_param("i", $classroom_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $this->sendError('Classroom not found');
        }
        $stmt->close();

        // Create request
        $query = "INSERT INTO request (user_id, classroom_id, status) 
                 VALUES (?, ?, 'pending')";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user['id'], $classroom_id);

        if ($stmt->execute()) {
            $this->sendResponse([
                'request_id' => $this->conn->insert_id,
                'status' => 'pending'
            ], 'Request created successfully', 201);
        } else {
            $this->sendError('Failed to create request: ' . $this->conn->error);
        }
        $stmt->close();
    }

    public function put_request() {
        // Only teachers can update requests
        if ($this->user['role'] !== 'teacher') {
            $this->sendError('Unauthorized', 403);
        }

        $this->validateRequiredFields(['id', 'status']);

        $id = (int)$this->input['id'];
        $status = $this->sanitizeString($this->input['status']);

        // Validate status
        $allowed_statuses = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $allowed_statuses)) {
            $this->sendError('Invalid status');
        }

        // Update request
        $query = "UPDATE request SET status = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $status, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $this->sendResponse([
                    'request_id' => $id,
                    'status' => $status
                ], 'Request updated successfully');
            } else {
                $this->sendError('Request not found');
            }
        } else {
            $this->sendError('Failed to update request: ' . $this->conn->error);
        }
        $stmt->close();
    }

    public function delete_request() {
        $this->validateRequiredFields(['id']);

        $id = (int)$this->input['id'];

        // Delete request
        $query = "DELETE FROM request WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $this->sendResponse(null, 'Request deleted successfully');
            } else {
                $this->sendError('Request not found');
            }
        } else {
            $this->sendError('Failed to delete request: ' . $this->conn->error);
        }
        $stmt->close();
    }
} 