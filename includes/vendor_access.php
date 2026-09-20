<?php

function verifyVendorOwnership(?string $recordVendorId = null): string
{
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'vendor') {
        http_response_code(403);
        echo json_encode(['error' => 'Vendor access required.']);
        exit;
    }

    $vendorId = (string)$_SESSION['user_id'];
    if ($recordVendorId !== null && (string)$recordVendorId !== $vendorId) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not authorized to access this vendor data.']);
        exit;
    }

    return $vendorId;
}
