<?php
// app/controllers/QRController.php - FIXED VERSION FOR PHP 8.1+
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';

class QRController extends Database {
    
    public function generateTrainingSession($data) {
        // Validate required fields
        if (empty($data['training_date']) || empty($data['training_type']) || empty($data['session_time'])) {
            return [
                'success' => false,
                'message' => 'Tarikh, jenis latihan, dan sesi latihan adalah wajib'
            ];
        }
        
        // Validate FETIK requires additional notes
        if ($data['training_type'] === 'FETIK' && empty($data['notes'])) {
            return [
                'success' => false,
                'message' => 'Untuk FETIK, sila nyatakan butiran dalam catatan tambahan'
            ];
        }
        
        // 1. Generate unique QR token untuk link
        $qrToken = $this->generateQRToken();
        
        // 2. Create attendance link untuk kadet
        $baseUrl = $this->getBaseUrl();
        $attendanceLink = $baseUrl . "/cadet/attendance.php?token=" . $qrToken;
        
        // 3. Create QR data dengan LINK
        $qrDataArray = [
            'session_id' => null,
            'token' => $qrToken,
            'link' => $attendanceLink,
            'training_type' => $data['training_type'],
            'session_time' => $data['session_time'],
            'location' => $data['location'],
            'training_date' => $data['training_date'],
            'notes' => $data['notes'] ?? '',
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        $qrDataJson = json_encode($qrDataArray);
        
        // 4. Generate QR code image yang mengandungi LINK
        $qrImageBase64 = $this->generateQRWithLink($attendanceLink, $qrDataArray);
        
        // 5. Insert into database
        $sql = "INSERT INTO training_sessions 
                (location, training_date, training_type, session_time, qr_token, created_by, expires_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->prepare($sql);
        $createdBy = Session::get('user_id');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours')); // Link aktif 24 jam
        
        $stmt->bind_param(
            "sssssis",
            $data['location'],
            $data['training_date'],
            $data['training_type'],
            $data['session_time'],
            $qrToken,
            $createdBy,
            $expiresAt
        );
        
        if ($stmt->execute()) {
            $sessionId = $stmt->insert_id;
            
            // Update QR data dengan session ID
            $qrDataArray['session_id'] = $sessionId;
            
            // Try to update qr_data if column exists
            $this->updateQRDataIfExists($sessionId, json_encode($qrDataArray));
            
            // Create activity description
            $activityDesc = "Generated QR code for {$data['training_type']}";
            if (!empty($data['location'])) {
                $activityDesc .= " at {$data['location']}";
            }
            $activityDesc .= " (Sesi: {$data['session_time']})";
            
            // Log activity
            $this->logActivity(
                Session::get('user_id'),
                'qr_generated',
                $activityDesc,
                $sessionId
            );
            
            return [
                'success' => true,
                'message' => 'QR Code generated successfully! QR mengandungi link ke form kehadiran.',
                'session_id' => $sessionId,
                'qr_token' => $qrToken,
                'attendance_link' => $attendanceLink,
                'qr_base64' => $qrImageBase64,
                'location' => $data['location'],
                'training_type' => $data['training_type'],
                'session_time' => $data['session_time'],
                'training_date' => $data['training_date'],
                'notes' => $data['notes'] ?? '',
                'expires_at' => $expiresAt,
                'generated_at' => date('Y-m-d H:i:s')
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Database error: ' . $stmt->error
            ];
        }
    }
    
    public function regenerateQRCode($sessionId) {
        // Get current session
        $sql = "SELECT * FROM training_sessions WHERE session_id = ?";
        $stmt = $this->prepare($sql);
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        
        if (!$session) {
            return ['success' => false, 'message' => 'Session not found'];
        }
        
        // Generate new token
        $newToken = $this->generateQRToken();
        
        // Create new attendance link
        $baseUrl = $this->getBaseUrl();
        $newAttendanceLink = $baseUrl . "/cadet/attendance.php?token=" . $newToken;
        
        // Update database
        $updateSql = "UPDATE training_sessions 
                     SET qr_token = ?, expires_at = ? 
                     WHERE session_id = ?";
        $updateStmt = $this->prepare($updateSql);
        
        $newExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $updateStmt->bind_param("ssi", $newToken, $newExpires, $sessionId);
        
        if ($updateStmt->execute()) {
            // Update QR data
            $qrDataArray = [
                'session_id' => $sessionId,
                'token' => $newToken,
                'link' => $newAttendanceLink,
                'training_type' => $session['training_type'],
                'session_time' => $session['session_time'],
                'location' => $session['location'],
                'training_date' => $session['training_date'],
                'regenerated_at' => date('Y-m-d H:i:s'),
                'original_generated_at' => $session['created_at']
            ];
            
            $this->updateQRDataIfExists($sessionId, json_encode($qrDataArray));
            
            // Generate new QR image dengan link baru
            $qrImageBase64 = $this->generateQRWithLink($newAttendanceLink, $qrDataArray);
            
            // Log activity
            $this->logActivity(
                Session::get('user_id'),
                'qr_regenerated',
                "Regenerated QR code for {$session['training_type']}",
                $sessionId
            );
            
            return [
                'success' => true,
                'message' => 'QR Code regenerated successfully!',
                'qr_token' => $newToken,
                'attendance_link' => $newAttendanceLink,
                'qr_base64' => $qrImageBase64,
                'session_id' => $sessionId,
                'expires_at' => $newExpires
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Update failed: ' . $updateStmt->error
            ];
        }
    }
    
    // ============================================
    // FIXED: FUNCTION UNTUK GENERATE QR DENGAN LINK (NO ERRORS)
    // ============================================
    
    private function generateQRWithLink($link, $dataArray = []) {
        $sessionId = $dataArray['session_id'] ?? 'N/A';
        $trainingType = $dataArray['training_type'] ?? 'No Type';
        
        // Create image dengan size yang sesuai
        $size = 250;
        $image = imagecreatetruecolor($size, $size);
        
        if (!$image) {
            return $this->createFallbackQR($link, $trainingType, $sessionId);
        }
        
        // Colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $blue = imagecolorallocate($image, 26, 54, 93);
        $darkBlue = imagecolorallocate($image, 13, 27, 46);
        
        // Fill background dengan white
        imagefilledrectangle($image, 0, 0, $size, $size, $white);
        
        // Generate QR pattern berdasarkan hash link
        $hash = md5($link);
        $cellSize = 8;
        
        // FIXED: Gunakan intval() untuk elak float to int conversion
        for ($i = 20; $i < 230; $i += $cellSize) {
            for ($j = 20; $j < 230; $j += $cellSize) {
                // FIXED LINE 211: Gunakan intval()
                $charIndex = intval(($i/$cellSize) * ($j/$cellSize)) % strlen($hash);
                $charValue = ord($hash[$charIndex]);
                
                // 60% chance untuk black cell
                if ($charValue % 100 < 60) {
                    $cellX = (int)$i;  // Ensure integer
                    $cellY = (int)$j;  // Ensure integer
                    $cellWidth = $cellSize - 1;
                    $cellHeight = $cellSize - 1;
                    
                    // Ensure within bounds dengan integer values
                    if ($cellX + $cellWidth < 230 && $cellY + $cellHeight < 230) {
                        imagefilledrectangle($image, $cellX, $cellY, 
                                            $cellX + $cellWidth, $cellY + $cellHeight, 
                                            $black);
                    }
                }
            }
        }
        
        // Add position markers (corner squares)
        $this->drawQRCornerMarker($image, 25, 25, 35, $black, $white);
        $this->drawQRCornerMarker($image, $size - 60, 25, 35, $black, $white);
        $this->drawQRCornerMarker($image, 25, $size - 60, 35, $black, $white);
        
        // Add "SCAN ME" text di atas
        $text = "SCAN ME";
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textX = intval(($size - $textWidth) / 2);
        imagestring($image, $font, $textX, 5, $text, $blue);
        
        // Add "ATTENDANCE" text di bawah
        $bottomText = "ATTENDANCE";
        $bottomTextWidth = imagefontwidth(4) * strlen($bottomText);
        $bottomTextX = intval(($size - $bottomTextWidth) / 2);
        imagestring($image, 4, $bottomTextX, $size - 25, $bottomText, $darkBlue);
        
        // Add session info kecil di sudut
        $sessionText = "#" . $sessionId;
        imagestring($image, 2, 5, $size - 15, $sessionText, $blue);
        
        // Add type badge kecil
        $typeText = substr($trainingType, 0, 8);
        $typeWidth = imagefontwidth(2) * strlen($typeText);
        imagestring($image, 2, $size - $typeWidth - 5, $size - 15, $typeText, $darkBlue);
        
        // Save image to base64
        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();
        imagedestroy($image);
        
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
    
    private function drawQRCornerMarker($image, $x, $y, $size, $outerColor, $innerColor) {
        // Outer black square
        imagefilledrectangle($image, (int)$x, (int)$y, (int)($x + $size), (int)($y + $size), $outerColor);
        
        // Middle white square (60% of size)
        $middleSize = (int)($size * 0.6);
        $middleX = (int)($x + ($size - $middleSize) / 2);
        $middleY = (int)($y + ($size - $middleSize) / 2);
        imagefilledrectangle($image, $middleX, $middleY, 
                            $middleX + $middleSize, $middleY + $middleSize, $innerColor);
        
        // Inner black square (30% of size)
        $innerSize = (int)($size * 0.3);
        $innerX = (int)($x + ($size - $innerSize) / 2);
        $innerY = (int)($y + ($size - $innerSize) / 2);
        imagefilledrectangle($image, $innerX, $innerY, 
                            $innerX + $innerSize, $innerY + $innerSize, $outerColor);
    }
    
    private function createFallbackQR($link, $trainingType, $sessionId) {
        $size = 250;
        $im = imagecreatetruecolor($size, $size);
        
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        $red = imagecolorallocate($im, 220, 53, 69);
        
        imagefilledrectangle($im, 0, 0, $size, $size, $white);
        
        // Simple pattern
        for ($i = 30; $i < 220; $i += 20) {
            for ($j = 30; $j < 220; $j += 20) {
                if (rand(0, 100) > 50) {
                    imagefilledrectangle($im, (int)$i, (int)$j, (int)($i+15), (int)($j+15), $black);
                }
            }
        }
        
        // Add text
        imagestring($im, 5, 80, 100, "CAAMS", $red);
        imagestring($im, 4, 70, 130, "ATTENDANCE", $black);
        imagestring($im, 3, 30, 220, "Scan untuk daftar", $red);
        
        ob_start();
        imagepng($im);
        $img = ob_get_clean();
        imagedestroy($im);
        
        return 'data:image/png;base64,' . base64_encode($img);
    }
    
    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . "://" . $host;
    }
    
    public function validateAttendanceToken($token) {
        $sql = "SELECT * FROM training_sessions 
                WHERE qr_token = ? AND expires_at > NOW()";
        
        $stmt = $this->prepare($sql);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $session = $result->fetch_assoc();
            
            // Check if session date is today or future
            $sessionDate = strtotime($session['training_date']);
            $today = strtotime(date('Y-m-d'));
            
            if ($sessionDate >= $today) {
                return $session;
            }
        }
        
        return false;
    }
    
    public function recordAttendance($token, $militaryNumber) {
        // 1. Validate token
        $session = $this->validateAttendanceToken($token);
        if (!$session) {
            return ['success' => false, 'message' => 'Token tidak sah atau sudah tamat tempoh'];
        }
        
        // 2. Find cadet by military number
        $cadetSql = "SELECT user_id, name FROM users 
                    WHERE military_number = ? AND role = 'cadet'";
        $cadetStmt = $this->prepare($cadetSql);
        $cadetStmt->bind_param("s", $militaryNumber);
        $cadetStmt->execute();
        $cadetResult = $cadetStmt->get_result();
        
        if ($cadetResult->num_rows === 0) {
            return ['success' => false, 'message' => 'Nombor tentera tidak dijumpai'];
        }
        
        $cadet = $cadetResult->fetch_assoc();
        $cadetId = $cadet['user_id'];
        $cadetName = $cadet['name'];
        $sessionId = $session['session_id'];
        
        // 3. Check if already attended
        $checkSql = "SELECT * FROM attendance 
                    WHERE user_id = ? AND session_id = ?";
        $checkStmt = $this->prepare($checkSql);
        $checkStmt->bind_param("ii", $cadetId, $sessionId);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Anda sudah mendaftar kehadiran untuk sesi ini'];
        }
        
        // 4. Insert attendance record
        $insertSql = "INSERT INTO attendance 
                     (user_id, session_id, date, status, recorded_at) 
                     VALUES (?, ?, CURDATE(), 'present', NOW())";
        $insertStmt = $this->prepare($insertSql);
        $insertStmt->bind_param("ii", $cadetId, $sessionId);
        
        if ($insertStmt->execute()) {
            // Log activity
            $this->logActivity(
                $cadetId,
                'attendance_recorded',
                "Recorded attendance for {$cadetName} in {$session['training_type']}",
                $sessionId
            );
            
            return [
                'success' => true, 
                'message' => 'Kehadiran berjaya direkod!',
                'cadet_name' => $cadetName,
                'session_type' => $session['training_type']
            ];
        } else {
            return ['success' => false, 'message' => 'Ralat sistem: ' . $insertStmt->error];
        }
    }
    
    // ============================================
    // EXISTING FUNCTIONS
    // ============================================
    
    public function getActiveSessions() {
        $sql = "SELECT ts.*, u.name as creator_name 
                FROM training_sessions ts
                JOIN users u ON ts.created_by = u.user_id
                WHERE ts.expires_at > NOW()
                ORDER BY ts.created_at DESC
                LIMIT 20";
        
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    public function getSessionQR($sessionId) {
        $sql = "SELECT ts.*, u.name as creator_name
                FROM training_sessions ts
                JOIN users u ON ts.created_by = u.user_id
                WHERE ts.session_id = ?";
        
        $stmt = $this->prepare($sql);
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            // Generate attendance link
            $baseUrl = $this->getBaseUrl();
            $attendanceLink = $baseUrl . "/cadet/attendance.php?token=" . $row['qr_token'];
            
            // Generate QR image dengan link
            $qrImageBase64 = $this->generateQRWithLink($attendanceLink, [
                'session_id' => $sessionId,
                'training_type' => $row['training_type'],
                'location' => $row['location'],
                'training_date' => $row['training_date'],
                'session_time' => $row['session_time']
            ]);
            
            return [
                'session_id' => $sessionId,
                'qr_token' => $row['qr_token'],
                'attendance_link' => $attendanceLink,
                'training_type' => $row['training_type'],
                'session_time' => $row['session_time'],
                'location' => $row['location'],
                'training_date' => $row['training_date'],
                'notes' => $row['notes'] ?? '',
                'created_at' => $row['created_at'],
                'creator_name' => $row['creator_name'],
                'qr_base64' => $qrImageBase64,
                'expires_at' => $row['expires_at']
            ];
        }
        
        return null;
    }
    
    public function getExpiredSessions() {
        $sql = "SELECT ts.*, u.name as creator_name 
                FROM training_sessions ts
                JOIN users u ON ts.created_by = u.user_id
                WHERE ts.expires_at <= NOW()
                ORDER BY ts.expires_at DESC
                LIMIT 10";
        
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    public function validateQRToken($token) {
        $sql = "SELECT * FROM training_sessions 
                WHERE qr_token = ?";
        
        $stmt = $this->prepare($sql);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0 ? $result->fetch_assoc() : false;
    }
    
    private function generateQRToken() {
        return 'QR_' . time() . '_' . bin2hex(random_bytes(8));
    }
    
    private function updateQRDataIfExists($sessionId, $qrDataJson) {
        // Check if qr_data column exists
        $checkColumn = $this->prepare("SHOW COLUMNS FROM training_sessions LIKE 'qr_data'");
        $checkColumn->execute();
        
        if ($checkColumn->get_result()->num_rows > 0) {
            $updateSql = "UPDATE training_sessions SET qr_data = ? WHERE session_id = ?";
            $updateStmt = $this->prepare($updateSql);
            $updateStmt->bind_param("si", $qrDataJson, $sessionId);
            @$updateStmt->execute();
        }
    }
    
    private function logActivity($userId, $type, $description, $relatedId = null) {
        // Check if activity_logs table exists
        $checkTable = $this->prepare("SHOW TABLES LIKE 'activity_logs'");
        $checkTable->execute();
        
        if ($checkTable->get_result()->num_rows > 0) {
            $sql = "INSERT INTO activity_logs (user_id, activity_type, description, related_id, ip_address) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $this->prepare($sql);
            $stmt->bind_param("issss", $userId, $type, $description, $relatedId, $ip);
            @$stmt->execute();
        }
    }
}
?>