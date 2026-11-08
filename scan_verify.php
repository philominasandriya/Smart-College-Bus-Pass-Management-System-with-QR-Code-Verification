<?php
session_start();
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$qr_data = trim($_GET['qr_data'] ?? '');
$scanner_bus_no = trim($_GET['bus_no'] ?? ''); 

$error_msg = "";
$user_details = null;
$pass_type = "DIGITAL PASS";
$pass_info = [];
$target_user_id = 0;
$is_urgent_pass = false;
$is_emergency_pass = false;
$validity_date = "";

// Helper function to format bus numbers nicely (e.g., convert 8+-+KL-40-6311 to 8 - KL-40-6311)
function formatBusDisplayName($bus_str) {
    $bus_str = trim($bus_str);
    if (empty($bus_str)) return 'N/A';
    $cleaned = preg_replace('/[\+\-]+/', ' - ', $bus_str);
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    return trim($cleaned, ' -');
}

if (!empty($qr_data)) {
    // 1. Check for manual/short structure like SNGCE-PASS-37-20260701
    if (preg_match('/SNGCE-PASS-(\d+)-(\d{8})/i', $qr_data, $matches)) {
        $target_user_id = intval($matches[1]);
        $raw_date = $matches[2]; 
        $validity_date = substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2);
        $pass_type = "STANDARD DIGITAL PASS";
    } 
    elseif (preg_match('/SNGCE-PASS-(\d+)/i', $qr_data, $matches)) {
        $target_user_id = intval($matches[1]);
        $pass_type = "STANDARD DIGITAL PASS";
    }
    // 2. Parse pipe-delimited format (Handles Urgent Passes, Emergency Passes, etc.)
    elseif (strpos($qr_data, '|') !== false) {
        $parts = explode('|', $qr_data);
        $pass_type = trim($parts[0] ?? 'SNGCE PASS');
        
        foreach ($parts as $part) {
            if (strpos($part, ':') !== false) {
                list($key, $val) = explode(':', $part, 2);
                $pass_info[strtolower(trim($key))] = trim($val);
            }
        }
        
        $validity_date = $pass_info['expires'] ?? $pass_info['validity'] ?? $pass_info['expiry'] ?? '';

        if (stripos($pass_type, 'URGENT') !== false) {
            $is_urgent_pass = true;
        }
        
        if ((stripos($pass_type, 'EMERGENCY') !== false || isset($pass_info['altbus']) || isset($pass_info['alternative bus'])) && stripos($pass_type, 'REGULAR') === false && stripos($pass_type, 'DIGITAL') === false) {
            $is_emergency_pass = true;
            if (!isset($pass_info['altbus'])) {
                $pass_info['altbus'] = $pass_info['alternative bus'] ?? $pass_info['bus'] ?? '';
            }
        }
    } else {
        if (is_numeric($qr_data)) {
            $target_user_id = intval($qr_data);
        }
    }

    // 3. Handle Urgent Pass Lookup
    if ($is_urgent_pass) {
        $urgent_req_id = intval($pass_info['id'] ?? 0);
        if ($urgent_req_id > 0) {
            $stmt_urg = $conn->prepare("SELECT r.*, r.boarding_point AS req_boarding, r.applicant_name AS req_name, u.* FROM urgent_pass_requests r LEFT JOIN users u ON r.user_id = u.id WHERE r.id = ?");
            if ($stmt_urg) {
                $stmt_urg->bind_param("i", $urgent_req_id);
                $stmt_urg->execute();
                $urg_res = $stmt_urg->get_result();
                if ($urg_res && $urg_res->num_rows > 0) {
                    $urg_data = $urg_res->fetch_assoc();
                    $raw_name = $urg_data['req_name'] ?? $urg_data['applicant_name'] ?? $pass_info['name'] ?? 'Day Scholar';
                    $clean_name = str_replace('+', ' ', $raw_name);

                    $user_details = [
                        'full_name' => $clean_name,
                        'role' => $urg_data['role'] ?? 'Student (Day Scholar)',
                        'course' => $urg_data['course'] ?? '',
                        'department' => $urg_data['department'] ?? '',
                        'bus_number' => $urg_data['requested_route'] ?? $pass_info['route'] ?? 'N/A',
                        'boarding_point' => $urg_data['req_boarding'] ?? $urg_data['boarding_point'] ?? 'N/A',
                        'phone' => '', 
                        'image' => $urg_data['image'] ?? ''  
                    ];
                }
                $stmt_urg->close();
            }
        }

        // Fallback if request ID lookup didn't fetch user details but QR has user ID or name
        if (!$user_details) {
            $fallback_uid = intval($pass_info['user'] ?? $pass_info['id'] ?? 0);
            if ($fallback_uid > 0) {
                $stmt_fb = $conn->prepare("SELECT * FROM users WHERE id = ?");
                if ($stmt_fb) {
                    $stmt_fb->bind_param("i", $fallback_uid);
                    $stmt_fb->execute();
                    $fb_res = $stmt_fb->get_result();
                    if ($fb_res && $fb_res->num_rows > 0) {
                        $user_details = $fb_res->fetch_assoc();
                    }
                    $stmt_fb->close();
                }
            }
        }

        if (!$user_details && isset($pass_info['name'])) {
            $clean_name = str_replace('+', ' ', $pass_info['name']);
            $user_details = [
                'full_name' => $clean_name,
                'role' => 'Student (Day Scholar)',
                'course' => '',
                'department' => '',
                'bus_number' => $pass_info['route'] ?? 'N/A',
                'boarding_point' => $pass_info['boarding'] ?? 'N/A',
                'phone' => '',
                'image' => ''
            ];
        }
    } else {
        if ($target_user_id == 0) {
            $target_user_id = intval($pass_info['user'] ?? $pass_info['id'] ?? 0);
        }

        if ($target_user_id > 0) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $target_user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $user_details = $res->fetch_assoc();
                    if (empty($validity_date) && isset($user_details['validity'])) {
                        $validity_date = $user_details['validity'];
                    }
                }
                $stmt->close();
            }
        }
    }

    // 4. Validate Bus Permission & Route Matching
    if ($user_details) {
        $assigned_bus = trim($user_details['bus_number'] ?? '');
        $scanned_route = $pass_info['route'] ?? $pass_info['altbus'] ?? $pass_info['bus'] ?? '';

        if (!empty($scanner_bus_no)) {
            $assigned_bus_digits = preg_replace('/[^0-9]/', '', $assigned_bus);
            $scanned_route_digits = preg_replace('/[^0-9]/', '', $scanned_route);
            $scanner_bus_digits = preg_replace('/[^0-9]/', '', $scanner_bus_no);

            $is_route_matched = false;

            if ($is_emergency_pass) {
                $alt_bus = trim(urldecode($pass_info['altbus'] ?? ''));
                $alt_bus = str_replace('+', ' ', $alt_bus);
                $alt_bus_digits = preg_replace('/[^0-9]/', '', $alt_bus);

                $is_own_assigned_bus = (
                    ($assigned_bus_digits !== '' && $scanner_bus_digits !== '' && $assigned_bus_digits === $scanner_bus_digits) ||
                    strcasecmp(trim($assigned_bus), trim($scanner_bus_no)) === 0
                );

                if ($is_own_assigned_bus) {
                    $error_msg = "ERROR: Access Denied! You cannot use an emergency pass on your own assigned bus.";
                } else {
                    $is_alt_matched = (
                        (!empty($alt_bus_digits) && !empty($scanner_bus_digits) && $alt_bus_digits === $scanner_bus_digits) ||
                        strcasecmp(trim($alt_bus), trim($scanner_bus_no)) === 0 ||
                        stripos($scanner_bus_no, $alt_bus) !== false ||
                        stripos($alt_bus, $scanner_bus_no) !== false
                    );
                    
                    if ($is_alt_matched) {
                        $is_route_matched = true;
                    } else {
                        $error_msg = "ERROR: Access Denied! This emergency pass is assigned for alternative bus " . formatBusDisplayName($alt_bus) . ", not for this bus.";
                    }
                }
            } else {
                $is_route_matched = (
                    ($assigned_bus_digits !== '' && $scanner_bus_digits !== '' && $assigned_bus_digits === $scanner_bus_digits) ||
                    ($scanned_route_digits !== '' && $scanner_bus_digits !== '' && $scanned_route_digits === $scanner_bus_digits) ||
                    strcasecmp(trim($assigned_bus), trim($scanner_bus_no)) === 0 || 
                    strcasecmp(trim($scanned_route), trim($scanner_bus_no)) === 0
                );

                if ($is_urgent_pass && !$is_route_matched) {
                    $urg_id = intval($pass_info['id'] ?? 0);
                    if ($urg_id > 0) {
                        $stmt_urg_chk = $conn->prepare("SELECT * FROM urgent_pass_requests WHERE id = ? AND (status = 'Paid & Issued' OR status = 'Approved') AND (requested_route LIKE ? OR requested_route LIKE ?) LIMIT 1");
                        if ($stmt_urg_chk) {
                            $bus_like_1 = "%" . $scanner_bus_no . "%";
                            $bus_like_2 = "%Bus " . $scanner_bus_no . "%";
                            $stmt_urg_chk->bind_param("iss", $urg_id, $bus_like_1, $bus_like_2);
                            $stmt_urg_chk->execute();
                            $urg_res = $stmt_urg_chk->get_result();
                            if ($urg_res && $urg_res->num_rows > 0) {
                                $is_route_matched = true; 
                            }
                            $stmt_urg_chk->close();
                        }
                    }
                }

                if (!$is_route_matched && empty($error_msg)) {
                    $display_bus = formatBusDisplayName($assigned_bus ?: $scanned_route);
                    $display_scanner = formatBusDisplayName($scanner_bus_no);
                    $error_msg = "ERROR: Access Denied! This pass belongs to Bus No. " . htmlspecialchars($display_bus) . ". You are scanning from Driver Bus No. " . htmlspecialchars($display_scanner) . ". Boarding denied.";
                }
            }
        }
    } else {
        if (empty($error_msg)) {
            $error_msg = "ERROR: Unrecognized QR Code structure or user not found in the database (" . htmlspecialchars($qr_data) . ").";
        }
    }
} else {
    $error_msg = "Please provide QR code data to scan.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pass Verification Result</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-6">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-200 p-8">
        <?php if (!empty($error_msg)): ?>
            <div class="text-center space-y-4">
                <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto text-3xl shadow-inner">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h3 class="text-xl font-bold text-red-700">Access Denied</h3>
                <p class="text-sm text-gray-700 bg-red-50 p-4 rounded-xl border border-red-200 font-semibold">
                    <?php echo htmlspecialchars($error_msg); ?>
                </p>
                <a href="driver_dashboard.php" class="inline-block mt-4 bg-[#1A2E6B] text-white px-6 py-2 rounded-lg text-sm font-semibold hover:bg-[#142353] transition">
                    Scan Another Pass
                </a>
            </div>
        <?php elseif ($user_details && empty($error_msg)): ?>
            <div class="text-center space-y-4">
                <div class="w-16 h-16 bg-green-100 text-green-700 rounded-full flex items-center justify-center mx-auto text-3xl shadow-inner">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-[#1A2E6B]">Valid Pass Verified</h3>
                    <p class="text-xs text-gray-500 uppercase font-semibold mt-1"><?php echo htmlspecialchars($pass_type); ?></p>
                </div>
                
                <?php 
                $is_day_scholar = (stripos($pass_type, 'URGENT') !== false || stripos(($user_details['role'] ?? ''), 'Day Scholar') !== false);
                if (!$is_day_scholar):
                ?>
                    <?php if (!empty($user_details['image']) && file_exists("uploads/" . $user_details['image'])): ?>
                        <img src="uploads/<?php echo htmlspecialchars($user_details['image']); ?>" class="w-24 h-24 rounded-full object-cover mx-auto border-4 border-[#1A2E6B] shadow-md">
                    <?php else: ?>
                        <div class="w-24 h-24 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold text-xl mx-auto border-4 border-[#1A2E6B] shadow-md">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="w-full text-left bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-2 text-sm">
                    <div class="flex justify-between border-b pb-1">
                        <span class="text-gray-500 font-medium">Full Name:</span>
                        <span class="font-bold text-gray-900"><?php echo htmlspecialchars($user_details['full_name']); ?></span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span class="text-gray-500 font-medium">Role / Course:</span>
                        <span class="font-bold text-gray-800">
                            <?php 
                            $role_val = ucfirst(htmlspecialchars($user_details['role'] ?? 'Student'));
                            $course_val = trim($user_details['course'] ?? $user_details['department'] ?? '');
                            if (!empty($course_val) && strcasecmp($course_val, 'N/A') !== 0) {
                                echo $role_val . ' (' . htmlspecialchars($course_val) . ')';
                            } else {
                                echo $role_val;
                            }
                            ?>
                        </span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span class="text-gray-500 font-medium">Assigned Bus:</span>
                        <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars(formatBusDisplayName($user_details['bus_number'])); ?></span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span class="text-gray-500 font-medium">Boarding Point:</span>
                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_details['boarding_point']); ?></span>
                    </div>
                    <div class="flex justify-between border-b pb-1">
                        <span class="text-gray-500 font-medium">Validity / Expires:</span>
                        <span class="font-bold text-emerald-700"><?php echo !empty($validity_date) ? htmlspecialchars($validity_date) : 'Active / Standard'; ?></span>
                    </div>
                </div>
                <a href="driver_dashboard.php" class="inline-block w-full bg-[#1A2E6B] text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-[#142353] transition shadow-sm">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Scan Next Pass
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>