<?php
require_once __DIR__ . '/../../inc/BaseController.php';

class AttendanceController extends BaseController {
    protected function getAllowedMethods() {
        return ['GET', 'POST', 'PUT'];
    }

    protected function checkTeacherOrAdmin() {
        if (!in_array($this->user['role'], ['teacher', 'admin'])) {
            $this->sendError('Unauthorized: Only teachers and admins can perform this action', 403);
        }
    }

    public function get_attendance() {
        $classroom_id = isset($_GET['classroom_id']) ? (int)$_GET['classroom_id'] : null;
        $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        
        $query = "SELECT a.*, u.name as user_name, c.code as classroom_code 
                 FROM attendance a 
                 JOIN user u ON a.user_id = u.id 
                 JOIN classroom c ON a.classroom_id = c.id 
                 WHERE 1=1";
        $params = [];
        $types = "";
        
        if ($classroom_id) {
            $query .= " AND a.classroom_id = ?";
            $params[] = $classroom_id;
            $types .= "i";
        }
        
        if ($user_id) {
            $query .= " AND a.user_id = ?";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $query .= " ORDER BY a.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $attendances = [];
        while ($row = $result->fetch_assoc()) {
            $attendances[] = $row;
        }
        
        $this->sendResponse($attendances, 'Attendance records retrieved successfully');
        $stmt->close();
    }

    public function post_attendance() {
        $this->validateRequiredFields(['classroom_id', 'code']);

        $classroom_id = (int)$this->input['classroom_id'];
        $code = $this->sanitizeString($this->input['code']);
        $user_id = $this->user['id'];

        // Verify attendance code
        $query = "SELECT ac.*, a.classroom_id 
                 FROM attendance_code ac 
                 JOIN attendance a ON ac.attendance_id = a.id 
                 WHERE ac.code = ? AND ac.status = 1 
                 AND a.classroom_id = ? 
                 AND ac.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $code, $classroom_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$attendance_code = $result->fetch_assoc()) {
            $this->sendError('Invalid or expired attendance code');
        }
        $stmt->close();

        // Check if already marked attendance for this classroom today
        $query = "SELECT id FROM attendance 
                 WHERE classroom_id = ? 
                 AND user_id = ? 
                 AND DATE(created_at) = CURDATE()";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom_id, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $this->sendError('You have already marked attendance for this classroom today');
        }
        $stmt->close();

        // Mark attendance
        $query = "INSERT INTO attendance (classroom_id, user_id, status) VALUES (?, ?, 1)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom_id, $user_id);

        if ($stmt->execute()) {
            // Invalidate the attendance code
            $query = "UPDATE attendance_code SET status = 0 WHERE id = ?";
            $stmt2 = $this->conn->prepare($query);
            $stmt2->bind_param("i", $attendance_code['id']);
            $stmt2->execute();
            $stmt2->close();

            $this->sendResponse([
                'attendance_id' => $this->conn->insert_id
            ], 'Attendance marked successfully', 201);
        } else {
            $this->sendError('Failed to mark attendance: ' . $this->conn->error);
        }

        $stmt->close();
    }

    public function post_attendance_code() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['classroom_id']);

        $classroom_id = (int)$this->input['classroom_id'];
        $code = strtoupper(substr(md5(uniqid()), 0, 6)); // Generate 6-character code

        // Create attendance record
        $query = "INSERT INTO attendance (classroom_id, user_id, status) VALUES (?, ?, 0)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom_id, $this->user['id']);

        if ($stmt->execute()) {
            $attendance_id = $this->conn->insert_id;

            // Create attendance code
            $query = "INSERT INTO attendance_code (code, attendance_id, status) VALUES (?, ?, 1)";
            $stmt2 = $this->conn->prepare($query);
            $stmt2->bind_param("si", $code, $attendance_id);

            if ($stmt2->execute()) {
                $this->sendResponse([
                    'code' => $code,
                    'expires_in' => '5 minutes'
                ], 'Attendance code generated successfully', 201);
            } else {
                $this->sendError('Failed to generate attendance code');
            }
            $stmt2->close();
        } else {
            $this->sendError('Failed to create attendance record');
        }

        $stmt->close();
    }
} 