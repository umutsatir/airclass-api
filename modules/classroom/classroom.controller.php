<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class ClassroomController extends BaseController {
    protected function getAllowedMethods() {
        return ['GET', 'POST', 'PUT', 'DELETE'];
    }

    protected function checkTeacherOrAdmin() {
        if (!in_array($this->user['role'], ['teacher', 'admin'])) {
            $this->sendError('Unauthorized: Only teachers and admins can perform this action', 403);
        }
    }

    public function get_classroom() {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $status = isset($_GET['status']) ? (int)$_GET['status'] : null;

        $query = "SELECT c.*, 
                        u.name as teacher_name,
                        (SELECT COUNT(*) FROM attendance a WHERE a.classroom_id = c.id AND DATE(a.created_at) = CURDATE()) as attendance_count
                 FROM classroom c 
                 LEFT JOIN user u ON c.teacher_id = u.id 
                 WHERE 1=1";
        $params = [];
        $types = "";

        if ($id) {
            $query .= " AND c.id = ?";
            $params[] = $id;
            $types .= "i";
        }

        if ($status !== null) {
            $query .= " AND c.status = ?";
            $params[] = $status;
            $types .= "i";
        }

        $query .= " ORDER BY c.created_at DESC";

        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $classrooms = [];
        while ($row = $result->fetch_assoc()) {
            $classrooms[] = $row;
        }

        $this->sendResponse($classrooms, 'Classrooms retrieved successfully');
        $stmt->close();
    }

    public function post_classroom() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['code', 'ip', 'port']);

        $code = $this->sanitizeString($this->input['code']);
        $ip = $this->sanitizeString($this->input['ip']);
        $port = (int)$this->input['port'];
        $status = isset($this->input['status']) ? (int)$this->input['status'] : 1; // Default to active
        $teacher_id = $this->user['id'];

        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->sendError('Invalid IP address');
        }

        // Validate port number
        if ($port < 1 || $port > 65535) {
            $this->sendError('Invalid port number');
        }

        // Check if teacher already has an active classroom
        if ($status === 1) {
            $query = "SELECT id FROM classroom WHERE teacher_id = ? AND status = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $teacher_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $this->sendError('You already have an active classroom. Please close it first.');
            }
            $stmt->close();
        }

        // Create classroom
        $query = "INSERT INTO classroom (code, ip, port, status, teacher_id) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssiii", $code, $ip, $port, $status, $teacher_id);

        if ($stmt->execute()) {
            $this->sendResponse([
                'classroom_id' => $this->conn->insert_id
            ], 'Classroom created successfully', 201);
        } else {
            $this->sendError('Failed to create classroom: ' . $this->conn->error);
        }

        $stmt->close();
    }

    public function put_classroom() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['id', 'status']);

        $id = (int)$this->input['id'];
        $status = (int)$this->input['status'];

        // Verify classroom ownership
        $query = "SELECT teacher_id FROM classroom WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($classroom = $result->fetch_assoc()) {
            if ($classroom['teacher_id'] !== $this->user['id'] && $this->user['role'] !== 'admin') {
                $this->sendError('Unauthorized: You can only modify your own classrooms', 403);
            }
        } else {
            $this->sendError('Classroom not found', 404);
        }
        $stmt->close();

        // Update classroom status
        $query = "UPDATE classroom SET status = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $status, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $this->sendResponse(null, 'Classroom status updated successfully');
            } else {
                $this->sendError('Classroom not found', 404);
            }
        } else {
            $this->sendError('Failed to update classroom: ' . $this->conn->error);
        }

        $stmt->close();
    }

    public function delete_classroom() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['id']);

        $id = (int)$this->input['id'];

        // Delete classroom
        $query = "DELETE FROM classroom WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $this->sendResponse(null, 'Classroom deleted successfully');
            } else {
                $this->sendError('Classroom not found', 404);
            }
        } else {
            $this->sendError('Failed to delete classroom: ' . $this->conn->error);
        }

        $stmt->close();
    }

    public function get_attendance_report() {
        $this->checkTeacherOrAdmin();

        $classroom_id = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : null;
        $date = isset($_GET['date']) ? $this->sanitizeString($_GET['date']) : date('Y-m-d');

        if (!$classroom_id) {
            $this->sendError('Classroom ID is required');
        }

        // Verify classroom ownership
        $query = "SELECT teacher_id FROM classroom WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $classroom_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($classroom = $result->fetch_assoc()) {
            if ($classroom['teacher_id'] !== $this->user['id'] && $this->user['role'] !== 'admin') {
                $this->sendError('Unauthorized: You can only view attendance for your own classrooms', 403);
            }
        } else {
            $this->sendError('Classroom not found', 404);
        }
        $stmt->close();

        // Get attendance report
        $query = "SELECT 
                    u.id as student_id,
                    u.name as student_name,
                    u.email as student_email,
                    a.created_at as attendance_time,
                    c.code as classroom_code
                 FROM attendance a
                 JOIN user u ON a.user_id = u.id
                 JOIN classroom c ON a.classroom_id = c.id
                 WHERE a.classroom_id = ?
                 AND DATE(a.created_at) = ?
                 ORDER BY a.created_at ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("is", $classroom_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();

        $attendance_data = [];
        while ($row = $result->fetch_assoc()) {
            $attendance_data[] = $row;
        }

        $this->sendResponse([
            'classroom_code' => $attendance_data[0]['classroom_code'] ?? null,
            'date' => $date,
            'total_students' => count($attendance_data),
            'attendance_list' => $attendance_data
        ], 'Attendance report generated successfully');
        
        $stmt->close();
    }
} 