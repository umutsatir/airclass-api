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
        $code = isset($_GET['code']) ? $this->sanitizeString($_GET['code']) : null;
        
        if (!$classroom_id && !$code) {
            $this->sendError('Either classroom_id or code is required');
        }

        $query = "SELECT a.*, 
                        u.name as student_name, 
                        u.email as student_email,
                        c.code as classroom_code,
                        c.teacher_id,
                        t.name as teacher_name,
                        DATE(a.created_at) as attendance_date,
                        ac.code as attendance_code
                 FROM attendance a 
                 JOIN user u ON a.user_id = u.id 
                 JOIN classroom c ON a.classroom_id = c.id 
                 LEFT JOIN user t ON c.teacher_id = t.id 
                 LEFT JOIN attendance_code ac ON a.attendance_session_id = ac.id
                 WHERE 1=1";
        $params = [];
        $types = "";
        
        if ($classroom_id) {
            // If teacher, verify classroom ownership
            if ($this->user['role'] === 'teacher') {
                $query .= " AND c.teacher_id = ?";
                $params[] = $this->user['id'];
                $types .= "i";
            }
            $query .= " AND a.classroom_id = ?";
            $params[] = $classroom_id;
            $types .= "i";
        }
        
        if ($code) {
            $query .= " AND c.code = ?";
            $params[] = $code;
            $types .= "s";
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
        
        $this->sendResponse([
            'total_students' => count($attendances),
            'attendance_list' => $attendances
        ], 'Attendance records retrieved successfully');
        $stmt->close();
    }

    public function post_attendance() {
        $this->validateRequiredFields(['classroom_id', 'code']);

        // Check if user is a student
        if ($this->user['role'] !== 'student') {
            $this->sendError('Only students can mark attendance', 403);
        }

        $classroom_id = (int)$this->input['classroom_id'];
        $code = $this->sanitizeString($this->input['code']);
        $user_id = $this->user['id'];

        // Start transaction
        $this->conn->begin_transaction();

        try {
            // Verify attendance code
            $query = "SELECT ac.* 
                     FROM attendance_code ac 
                     WHERE ac.code = ? 
                     AND ac.classroom_id = ? 
                     AND ac.status = 1 
                     AND ac.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     FOR UPDATE"; // Lock the row
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("si", $code, $classroom_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$attendance_code = $result->fetch_assoc()) {
                throw new Exception('Invalid or expired attendance code');
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
                throw new Exception('You have already marked attendance for this classroom today');
            }
            $stmt->close();

            // Mark attendance
            $query = "INSERT INTO attendance (classroom_id, user_id, attendance_session_id) 
                     VALUES (?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $classroom_id, $user_id, $attendance_code['id']);

            if (!$stmt->execute()) {
                throw new Exception('Failed to mark attendance: ' . $this->conn->error);
            }

            $attendance_id = $this->conn->insert_id;
            if (!$attendance_id) {
                throw new Exception('Failed to get attendance ID');
            }

            // Check if all students have marked attendance
            $query = "SELECT COUNT(*) as total_students, 
                            (SELECT COUNT(*) FROM attendance 
                             WHERE classroom_id = ? 
                             AND attendance_session_id = ?) as marked_count
                     FROM user 
                     WHERE role = 'student'";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $classroom_id, $attendance_code['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $counts = $result->fetch_assoc();
            $stmt->close();

            if ($counts['total_students'] === $counts['marked_count']) {
                $query = "UPDATE attendance_code SET status = 0 WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("i", $attendance_code['id']);
                $stmt->execute();
                $stmt->close();
            }

            // Commit transaction
            $this->conn->commit();

            $this->sendResponse([
                'attendance_id' => $attendance_id,
                'attendance_code' => $code
            ], 'Attendance marked successfully', 201);

        } catch (Exception $e) {
            // Rollback transaction on error
            $this->conn->rollback();
            $this->sendError($e->getMessage());
        }
    }

    public function post_attendance_code() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['classroom_id']);

        $classroom_id = (int)$this->input['classroom_id'];

        // Verify classroom ownership and active status
        $query = "SELECT id FROM classroom WHERE id = ? AND teacher_id = ? AND status = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom_id, $this->user['id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $this->sendError('Invalid classroom or classroom is not active');
        }
        $stmt->close();

        // Check if there's already an active code for this classroom
        $query = "SELECT id FROM attendance_code 
                 WHERE classroom_id = ? 
                 AND status = 1 
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $classroom_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $this->sendError('There is already an active attendance code for this classroom');
        }
        $stmt->close();

        $code = strtoupper(substr(md5(uniqid()), 0, 6)); // Generate 6-character code

        // Create attendance code
        $query = "INSERT INTO attendance_code (code, classroom_id, status) VALUES (?, ?, 1)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $code, $classroom_id);

        if ($stmt->execute()) {
            $this->sendResponse([
                'code' => $code,
                'classroom_id' => $classroom_id,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                'expires_in' => '5 minutes'
            ], 'Attendance code generated successfully', 201);
        } else {
            $this->sendError('Failed to generate attendance code');
        }

        $stmt->close();
    }
} 