<?php
// includes/response.php - Consistent JSON API response helper
// ============================================================
// Every endpoint returns: { "success": bool, "message": string, "data": mixed }

if (!function_exists('respond')) {
    function respond($data, $httpCode = 200) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('respondSuccess')) {
    function respondSuccess($message = 'OK', $data = null, $httpCode = 200) {
        respond(['success' => true, 'message' => $message, 'data' => $data], $httpCode);
    }
}

if (!function_exists('respondError')) {
    function respondError($message = 'Error', $data = null, $httpCode = 400) {
        respond(['success' => false, 'message' => $message, 'data' => $data], $httpCode);
    }
}
