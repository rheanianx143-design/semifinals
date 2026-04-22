<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

$action = $_GET['action'] ?? '';

switch($action) {
    case 'stats':
        getStats($conn);
        break;
    case 'logs':
        getLogs($conn, $_GET['limit'] ?? 100);
        break;
    case 'inside':
        getInside($conn);
        break;
    case 'users':
        getUsers($conn);
        break;
    case 'delete_all_logs':
        deleteAllLogs($conn);
        break;
    case 'delete_log':
        deleteLog($conn, $_GET['id'] ?? 0);
        break;
    case 'reset_inside_status':
        resetInsideStatus($conn);
        break;
    case 'manual_exit':
        manualExit($conn);
        break;
    case 'export_logs':
        exportLogs($conn);
        break;
    case 'reset_user_state':
        resetUserState($conn);
        break;
    case 'reset_all_states':
        resetAllStates($conn);
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function getStats($conn) {
    $stats = [];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    $stats['total_users'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM current_status WHERE is_inside = TRUE");
    $stats['inside_count'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM access_logs WHERE DATE(timestamp) = CURDATE()");
    $stats['total_access_today'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM access_logs WHERE status = 'DENIED' AND DATE(timestamp) = CURDATE()");
    $stats['denied_today'] = $result->fetch_assoc()['count'];
    
    echo json_encode($stats);
}

function getLogs($conn, $limit) {
    $sql = "SELECT id, uid, name, status, timestamp FROM access_logs ORDER BY timestamp DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $logs = [];
    while($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    
    echo json_encode($logs);
}

function getInside($conn) {
    $sql = "SELECT cs.uid, cs.name, cs.last_access 
            FROM current_status cs 
            WHERE cs.is_inside = TRUE 
            ORDER BY cs.last_access DESC";
    $result = $conn->query($sql);
    
    $inside = [];
    while($row = $result->fetch_assoc()) {
        $inside[] = $row;
    }
    
    echo json_encode($inside);
}

function getUsers($conn) {
    $result = $conn->query("SELECT id, name, uid, role, created_at FROM users ORDER BY id");
    
    $users = [];
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    echo json_encode($users);
}

function deleteAllLogs($conn) {
    $conn->query("START TRANSACTION");
    
    try {
        // Delete all access logs
        $conn->query("DELETE FROM access_logs");
        
        // IMPORTANT: Reset current status to FALSE for everyone
        $conn->query("UPDATE current_status SET is_inside = FALSE");
        
        $conn->query("COMMIT");
        echo json_encode(['success' => true, 'message' => 'All logs deleted. All users reset to OUTSIDE state. Next scan will be ENTER.']);
    } catch (Exception $e) {
        $conn->query("ROLLBACK");
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function deleteLog($conn, $id) {
    // First, get the log details before deleting
    $stmt = $conn->prepare("SELECT uid, status FROM access_logs WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $log = $result->fetch_assoc();
    
    if ($log) {
        $uid = $log['uid'];
        $deleted_status = $log['status'];
        
        // Delete the log
        $stmt = $conn->prepare("DELETE FROM access_logs WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            // After deleting, check if we need to reset the user's state
            // Get the most recent log for this user
            $stmt = $conn->prepare("SELECT status FROM access_logs WHERE uid = ? ORDER BY timestamp DESC LIMIT 1");
            $stmt->bind_param("s", $uid);
            $stmt->execute();
            $result = $stmt->get_result();
            $last_log = $result->fetch_assoc();
            
            if ($last_log) {
                // Update current status based on last log
                $new_status = ($last_log['status'] == 'ENTER') ? 1 : 0;
                $stmt = $conn->prepare("UPDATE current_status SET is_inside = ? WHERE uid = ?");
                $stmt->bind_param("is", $new_status, $uid);
                $stmt->execute();
            } else {
                // No logs left for this user - reset to OUTSIDE
                $stmt = $conn->prepare("UPDATE current_status SET is_inside = FALSE WHERE uid = ?");
                $stmt->bind_param("s", $uid);
                $stmt->execute();
            }
            
            echo json_encode(['success' => true, 'message' => 'Log deleted. User state reset.']);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Log not found']);
    }
}

function resetInsideStatus($conn) {
    // Reset all users to OUTSIDE
    $conn->query("UPDATE current_status SET is_inside = FALSE");
    echo json_encode(['success' => true, 'message' => 'All users reset to OUTSIDE. Next scan will be ENTER.']);
}

function manualExit($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    $uid = $data['uid'] ?? '';
    $name = $data['name'] ?? '';
    
    if ($uid) {
        // Update status to OUTSIDE
        $stmt = $conn->prepare("UPDATE current_status SET is_inside = FALSE WHERE uid = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        
        // Add exit log
        $stmt = $conn->prepare("INSERT INTO access_logs (uid, name, status) VALUES (?, ?, 'EXIT')");
        $stmt->bind_param("ss", $uid, $name);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => "$name marked as EXITED. Next scan will be ENTER."]);
    } else {
        echo json_encode(['success' => false]);
    }
}

function exportLogs($conn) {
    $result = $conn->query("SELECT id, uid, name, status, timestamp FROM access_logs ORDER BY timestamp DESC");
    $logs = [];
    while($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    echo json_encode($logs);
}

function resetUserState($conn) {
    // Reset a specific user's state
    $data = json_decode(file_get_contents('php://input'), true);
    $uid = $data['uid'] ?? '';
    
    if ($uid) {
        $stmt = $conn->prepare("UPDATE current_status SET is_inside = FALSE WHERE uid = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'User state reset. Next scan will be ENTER.']);
    } else {
        echo json_encode(['success' => false]);
    }
}

function resetAllStates($conn) {
    // Reset ALL users to OUTSIDE regardless of logs
    $conn->query("UPDATE current_status SET is_inside = FALSE");
    echo json_encode(['success' => true, 'message' => 'ALL users reset to OUTSIDE. Every next scan will be ENTER.']);
}

$conn->close();
?>