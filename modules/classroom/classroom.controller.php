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
        $query = "SELECT c.*, 
                        u.name as teacher_name,
                        (SELECT COUNT(*) FROM attendance a 
                         JOIN attendance_code ac ON a.attendance_session_id = ac.id 
                         WHERE ac.classroom_id = c.id AND DATE(a.created_at) = CURDATE()) as attendance_count
                 FROM classroom c 
                 LEFT JOIN user u ON c.teacher_id = u.id 
                 WHERE c.teacher_id = ? AND c.status = 1
                 ORDER BY c.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->user['id']);
        $stmt->execute();
        $result = $stmt->get_result();

        $classrooms = [];
        while ($row = $result->fetch_assoc()) {
            $classrooms[] = $row;
        }

        $this->sendResponse($classrooms, 'Active classrooms retrieved successfully');
        $stmt->close();
    }

    public function post_classroom() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['ip', 'port']);

        // Generate unique 6-digit classroom code using timestamp and random number
        $timestamp = time();
        $random = mt_rand(0, 999); // 0-999 random number
        $code = substr(($timestamp . str_pad($random, 3, '0', STR_PAD_LEFT)), -6);

        // Verify code uniqueness
        $query = "SELECT id FROM classroom WHERE code = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $code);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $random = mt_rand(0, 999);
            $code = substr(($timestamp . str_pad($random, 3, '0', STR_PAD_LEFT)), -6);
        }
        $stmt->close();

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
        $query = "INSERT INTO classroom (code, ip, port, teacher_id) VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssii", $code, $ip, $port, $teacher_id);

        if ($stmt->execute()) {
            $this->sendResponse([
                'classroom_id' => $this->conn->insert_id,
                'code' => $code
            ], 'Classroom created successfully', 201);
        } else {
            $this->sendError('Failed to create classroom: ' . $this->conn->error);
        }

        $stmt->close();
    }

    public function put_classroom() {
        $this->checkTeacherOrAdmin();

        $this->validateRequiredFields(['id']);

        $id = (int)$this->input['id'];
        
        // Handle both boolean and numeric status values
        if (!isset($this->input['status'])) {
            $this->sendError('Missing required field: status');
        }
        
        // Convert boolean or numeric status to integer
        $status = is_bool($this->input['status']) ? 
            ($this->input['status'] ? 1 : 0) : 
            (int)$this->input['status'];

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
                // Get the updated classroom data
                $query = "SELECT c.*, 
                                u.name as teacher_name,
                                (SELECT COUNT(*) FROM attendance a WHERE a.classroom_id = c.id AND DATE(a.created_at) = CURDATE()) as attendance_count
                         FROM classroom c 
                         LEFT JOIN user u ON c.teacher_id = u.id 
                         WHERE c.id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $classroom = $result->fetch_assoc();

                $this->sendResponse($classroom, 'Classroom status updated successfully');
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
                 JOIN attendance_code ac ON a.attendance_session_id = ac.id
                 JOIN classroom c ON ac.classroom_id = c.id
                 WHERE ac.classroom_id = ?
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

    public function post_classroom_join() {
        // Check if user is a student
        if ($this->user['role'] != 'student') {
            $this->sendError('Unauthorized: Only students can join classrooms', 403);
        }

        $this->validateRequiredFields(['code']);

        $code = $this->sanitizeString($this->input['code']);

        // Validate code format (6 alphanumeric characters)
        if (!preg_match('/^[A-Za-z0-9]{6}$/', $code)) {
            $this->sendError('Invalid classroom code format. Code must be 6 alphanumeric characters.');
        }

        // Check if classroom exists and is active
        $query = "SELECT c.*, u.name as teacher_name
                 FROM classroom c 
                 LEFT JOIN user u ON c.teacher_id = u.id 
                 WHERE c.code = ? AND c.status = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $classroom = $result->fetch_assoc();
        $stmt->close();

        if (!$classroom) {
            $this->sendError('Invalid or inactive classroom code', 404);
        }

        // Check if user is already in another active classroom
        $query = "SELECT c.id, c.code 
                 FROM classroom_student cs 
                 JOIN classroom c ON cs.classroom_id = c.id 
                 WHERE cs.student_id = ? AND cs.status = 1 AND c.status = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($active_classroom = $result->fetch_assoc()) {
            $stmt->close();
            $this->sendError('You are already in classroom ' . $active_classroom['code'] . '. Please leave it first.');
        }
        $stmt->close();

        // Add student to classroom
        $query = "INSERT INTO classroom_student (classroom_id, student_id, status) VALUES (?, ?, 1)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $classroom['id'], $this->user['id']);

        if (!$stmt->execute()) {
            $stmt->close();
            $this->sendError('Failed to join classroom: ' . $this->conn->error);
        }
        $stmt->close();

        // Get updated classroom data
        $query = "SELECT c.*, 
                        u.name as teacher_name,
                        (SELECT COUNT(*) FROM classroom_student cs 
                         WHERE cs.classroom_id = c.id AND cs.status = 1) as student_count
                 FROM classroom c 
                 LEFT JOIN user u ON c.teacher_id = u.id 
                 WHERE c.id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $classroom['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $updated_classroom = $result->fetch_assoc();
        $stmt->close();

        $this->sendResponse([
            'classroom' => [
                'id' => $updated_classroom['id'],
                'code' => $updated_classroom['code'],
                'teacher_name' => $updated_classroom['teacher_name'],
                'ip' => $updated_classroom['ip'],
                'port' => $updated_classroom['port'],
                'student_count' => $updated_classroom['student_count'],
                'created_at' => $updated_classroom['created_at']
            ]
        ], 'Successfully joined classroom', 201);
    }

    public function post_classroom_leave() {
        // Check if user is a student
        if ($this->user['role'] != 'student') {
            $this->sendError('Unauthorized: Only students can leave classrooms', 403);
        }

        // Find the student's active classroom
        $query = "SELECT cs.id, cs.classroom_id, c.code 
                 FROM classroom_student cs 
                 JOIN classroom c ON cs.classroom_id = c.id 
                 WHERE cs.student_id = ? AND cs.status = 1 AND c.status = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($active_classroom = $result->fetch_assoc()) {
            // Update classroom_student status to inactive
            $query = "UPDATE classroom_student SET status = 0 WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $active_classroom['id']);

            if ($stmt->execute()) {
                $this->sendResponse(null, 'Successfully left classroom ' . $active_classroom['code']);
            } else {
                $this->sendError('Failed to leave classroom: ' . $this->conn->error);
            }
        } else {
            $this->sendError('No active classroom found', 404);
        }

        $stmt->close();
    }
} 