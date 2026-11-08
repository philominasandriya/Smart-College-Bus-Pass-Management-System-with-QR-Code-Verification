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

// 1. Security check: Must be logged in as student or faculty
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'student' && $_SESSION['role'] !== 'faculty')) {
    header("Location: Login.html");
    exit();
}

// Ensure semester payment cycles table exists
$conn->query("CREATE TABLE IF NOT EXISTS semester_payment_cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(50) NOT NULL,
    semester_period VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'draft',
    published_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 2. Fetch user profile using the username stored in the session
$logged_in_username = $_SESSION['username'] ?? '';
$success_msg = "";
$error_msg = "";

$stmt_user = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt_user->bind_param("s", $logged_in_username);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$user_data = $result_user->fetch_assoc();
$stmt_user->close();

// Normalize keys to handle different column names gracefully
if ($user_data) {
    if (!isset($user_data['date_of_birth']) && isset($user_data['dob'])) {
        $user_data['date_of_birth'] = $user_data['dob'];
    }
    if (!isset($user_data['bus_number']) && isset($user_data['bus_no'])) {
        $user_data['bus_number'] = $user_data['bus_no'];
    }
}

// Fallback array if user_data is empty
if (!$user_data) {
    $user_data = [
        'id' => 1,
        'full_name' => 'Student User',
        'username' => $logged_in_username,
        'role' => 'student',
        'date_of_birth' => 'N/A',
        'email' => 'N/A',
        'blood_group' => 'N/A',
        'admission_no' => 'N/A',
        'roll_no' => 'N/A',
        'course' => 'N/A',
        'department' => 'N/A',
        'designation' => 'N/A',
        'bus_number' => 'Not Assigned',
        'boarding_point' => 'N/A',
        'phone' => 'N/A',
        'address' => 'N/A',
        'guardian_name' => 'N/A',
        'guardian_relationship' => 'N/A',
        'guardian_phone' => 'N/A',
        'gender' => 'N/A',
        'payment_status' => 'pending',
        'image' => ''
    ];
}

$user_id = $user_data['id'] ?? 1;
$tab = $_GET['tab'] ?? 'overview';

// Define these role flags early so the sidebar can read them
$is_student = ($user_data['role'] ?? $_SESSION['role']) === 'student';
$is_faculty = ($user_data['role'] ?? $_SESSION['role']) === 'faculty';

if ($is_faculty && $tab == 'payments') {
    $tab = 'overview';
}
// -------------------------------------------------------------
// DYNAMIC FEE FETCHING FROM `bus_fares` TABLE (ROBUST & PRECISE MATCHING)
// -------------------------------------------------------------
$consolidated_fee_amount = 0.00; 
$single_installment_amount = 0.00; 

$user_bus_no = trim($user_data['bus_number'] ?? '');
$user_boarding = trim($user_data['boarding_point'] ?? '');

$fee_check =$conn->query("SHOW TABLES LIKE 'bus_fares'");
if ($fee_check && $fee_check->num_rows > 0) {$found_fare = false;

    // 1. Try exact match on `starting_point` (matching the user's boarding point)
    if (!empty($user_boarding)) {
        $stmt_fee =$conn->prepare("SELECT installment_amount, consolidated_fee FROM bus_fares WHERE LOWER(TRIM(starting_point)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt_fee->bind_param("s", $user_boarding);$stmt_fee->execute();
        $fee_res =$stmt_fee->get_result();
        
        if ($fee_res && $fee_res->num_rows > 0) {$fee_data = $fee_res->fetch_assoc();$base_inst = floatval(str_replace(['₹', ','], '', $fee_data['installment_amount'] ?? 0));$consolidated_fee_amount = floatval(str_replace(['₹', ','], '', $fee_data['consolidated_fee'] ?? 0));$single_installment_amount = ($base_inst > 0) ?$base_inst : round($consolidated_fee_amount / 4, 2);$found_fare = true;
        }
        $stmt_fee->close();
    }

    // 2. Fallback: Partial/Like match if exact match fails
    if (!$found_fare && !empty($user_boarding)) {
        $stmt_fee_fb =$conn->prepare("SELECT installment_amount, consolidated_fee FROM bus_fares WHERE LOWER(TRIM(starting_point)) LIKE LOWER(CONCAT('%', ?, '%')) OR LOWER(TRIM(?)) LIKE LOWER(CONCAT('%', starting_point, '%')) LIMIT 1");
        $stmt_fee_fb->bind_param("ss", $user_boarding, $user_boarding);$stmt_fee_fb->execute();
        $fee_res_fb =$stmt_fee_fb->get_result();
        
        if ($fee_res_fb && $fee_res_fb->num_rows > 0) {$fee_data = $fee_res_fb->fetch_assoc();$base_inst = floatval(str_replace(['₹', ','], '', $fee_data['installment_amount'] ?? 0));$consolidated_fee_amount = floatval(str_replace(['₹', ','], '', $fee_data['consolidated_fee'] ?? 0));$single_installment_amount = ($base_inst > 0) ?$base_inst : round($consolidated_fee_amount / 4, 2);$found_fare = true;
        }
        $stmt_fee_fb->close();
    }

    // 3. Final Fallback default amounts only if completely not found in database
    if (!$found_fare) {$consolidated_fee_amount = 13500.00;
        $single_installment_amount = 3564.00;
    }
}

// Ensure payment_transactions and user tracking columns exist
$conn->query("CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    amount DECIMAL(10,2),
    payment_plan VARCHAR(50),
    razorpay_payment_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$user_cols_res = $conn->query("SHOW COLUMNS FROM users");
$user_table_cols = [];
while ($ucol = $user_cols_res->fetch_assoc()) {
    $user_table_cols[] = $ucol['Field'];
}
if (!in_array('installments_paid', $user_table_cols)) {
    $conn->query("ALTER TABLE users ADD COLUMN installments_paid INT DEFAULT 0");
}
if (!in_array('fee_payment_mode', $user_table_cols)) {
    $conn->query("ALTER TABLE users ADD COLUMN fee_payment_mode VARCHAR(50) DEFAULT ''");
}

$stmt_pstatus = $conn->prepare("SELECT payment_status, installments_paid, fee_payment_mode FROM users WHERE id = ?");
$stmt_pstatus->bind_param("i", $user_id);
$stmt_pstatus->execute();
$pstatus_res = $stmt_pstatus->get_result()->fetch_assoc();
$stmt_pstatus->close();

$current_payment_status = $pstatus_res['payment_status'] ?? 'pending';
$installments_paid_count = intval($pstatus_res['installments_paid'] ?? 0);
$fee_payment_mode = $pstatus_res['fee_payment_mode'] ?? '';

// 3. Handle Fee Payment Success Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_razorpay_payment'])) {
    $selected_plan = trim($conn->real_escape_string($_POST['selected_plan']));
    $payment_id = trim($conn->real_escape_string($_POST['razorpay_payment_id']));
    $target_semester = trim($conn->real_escape_string($_POST['selected_semester'] ?? ''));
    
    if ($selected_plan == 'consolidated') {
        $paid_amount = $consolidated_fee_amount;
        $new_installments_count = 4; 
        $new_payment_status = 'paid';
        $new_payment_mode = 'consolidated';
    } else {
        $paid_amount = $single_installment_amount;
        $new_payment_mode = 'installment';
        
        // Count existing installments for this specific semester
        $chk_curr_inst = $conn->prepare("SELECT COUNT(*) as cnt FROM payment_transactions WHERE user_id = ? AND semester_id = ? AND payment_plan = 'installment'");
        $chk_curr_inst->bind_param("is", $user_id, $target_semester);
        $chk_curr_inst->execute();
        $curr_inst_res = $chk_curr_inst->get_result()->fetch_assoc();
        $new_installments_count = intval($curr_inst_res['cnt'] ?? 0) + 1;
        $chk_curr_inst->close();

        if ($new_installments_count >= 4) {
            $new_payment_status = 'paid';
        } else {
            $new_payment_status = 'partial';
        }
    }

   $stmt_txn = $conn->prepare("INSERT INTO payment_transactions (user_id, amount, payment_plan, semester_id, razorpay_payment_id) VALUES (?, ?, ?, ?, ?)");
    $stmt_txn->bind_param("idsss", $user_id, $paid_amount, $selected_plan, $target_semester, $payment_id);
    $stmt_txn->execute();
    $stmt_txn->close();

    // UPDATE users table columns so it reflects immediately in database and UI
    $stmt_upd_user = $conn->prepare("UPDATE users SET payment_status = ?, installments_paid = ?, fee_payment_mode = ? WHERE id = ?");
    $stmt_upd_user->bind_param("sisi", $new_payment_status, $new_installments_count, $new_payment_mode, $user_id);
    $stmt_upd_user->execute();
    $stmt_upd_user->close();

    $success_msg = "Successfully Paid! Your payment for " . str_replace('_', ' ', $target_semester) . " has been recorded successfully (Razorpay ID: " . htmlspecialchars($payment_id) . ").";}
// Fetch all bus routes for alternative urgent pass selection from admin bus_routes table
$bus_routes_data = [];
$routes_chk = $conn->query("SHOW TABLES LIKE 'bus_routes'");
if ($routes_chk && $routes_chk->num_rows > 0) {
    $routes_res = $conn->query("SELECT * FROM bus_routes");
    if ($routes_res && $routes_res->num_rows > 0) {
        while ($r = $routes_res->fetch_assoc()) {
            $bus_key = $r['bus_no_reg'] ?? $r['bus_no'] ?? $r['bus_number'] ?? $r['route_name'] ?? ('Bus ' . $r['id']);
            
            // എല്ലാ സാധ്യതയുള്ള കോളം പേരുകളും ഇവിടെ പരിശോധിക്കുന്നു
            $raw_points = $r['route_detail'] ?? $r['route_details'] ?? $r['boarding_points'] ?? $r['route_name'] ?? $r['stops'] ?? '';
            
            $points_array = [];
            if (!empty($raw_points)) {
                // സാധാരണ ഹൈഫൻ (-), En-dash (–), Em-dash (—), കോമ (,), പുതിയ വരികൾ എന്നിവയെല്ലാം വെച്ച് കൃത്യമായി വേർതിരിക്കുന്നു
                $parts = preg_split('/\s*[-–—]\s*|[\r\n,]+/', $raw_points);
                foreach ($parts as $p) {
                    $p_clean = trim($p);
                    if (!empty($p_clean)) {
                        $points_array[] = $p_clean;
                    }
                }
            }
            if (empty($points_array)) {
                $points_array = ['Starting Point', 'Destination'];
            }
            $bus_routes_data[$bus_key] = [
                'identifier' => $bus_key,
                'points' => $points_array
            ];
        }
    }
}

// 4. Handle Feedback / Answer Submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_all_feedback'])) {
    $submitted_answers = $_POST['answers'] ?? [];

    $q_cols_res = $conn->query("SHOW COLUMNS FROM feedback_questions");
    $q_cols = [];
    while ($col_row = $q_cols_res->fetch_assoc()) {
        $q_cols[] = $col_row['Field'];
    }

    $all_active_questions = [];
    if (in_array('status', $q_cols)) {
        $fetch_q = $conn->query("SELECT id FROM feedback_questions WHERE status = 'published' OR status = 'active'");
    } elseif (in_array('is_published', $q_cols)) {
        $fetch_q = $conn->query("SELECT id FROM feedback_questions WHERE is_published = 1");
    } else {
        $fetch_q = $conn->query("SELECT id FROM feedback_questions");
    }

    if ($fetch_q && $fetch_q->num_rows > 0) {
        while ($row_q = $fetch_q->fetch_assoc()) {
            $all_active_questions[] = intval($row_q['id']);
        }
    }

    $already_submitted_count = 0;
    foreach ($all_active_questions as $q_id) {
        $chk_sub = $conn->prepare("SELECT status FROM user_feedback_assignments WHERE user_id = ? AND question_id = ? AND status = 'submitted' LIMIT 1");
        $chk_sub->bind_param("ii", $user_id, $q_id);
        $chk_sub->execute();
        if ($chk_sub->get_result()->num_rows > 0) {
            $already_submitted_count++;
        }
        $chk_sub->close();
    }

    if ($already_submitted_count > 0 && $already_submitted_count >= count($all_active_questions)) {
        $error_msg = "Error: You have already submitted your feedback. Modifications are not allowed.";
    } else {
        $missing_answers = false;
        foreach ($all_active_questions as $q_id) {
            if (!isset($submitted_answers[$q_id]) || trim($submitted_answers[$q_id]) === '') {
                $missing_answers = true;
                break;
            }
        }

        if ($missing_answers) {
            $error_msg = "Validation Error: You must answer all published feedback questions before submitting.";
        } else {
            $update_count = 0;
            $has_error = false;

            foreach ($submitted_answers as $question_id => $answer) {
                $question_id = intval($question_id);
                $answer = trim($conn->real_escape_string($answer));

                if (!empty($answer)) {
                    $chk_stmt = $conn->prepare("SELECT id, status FROM user_feedback_assignments WHERE user_id = ? AND question_id = ? LIMIT 1");
                    $chk_stmt->bind_param("ii", $user_id, $question_id);
                    $chk_stmt->execute();
                    $chk_res = $chk_stmt->get_result();
                    
                    if ($chk_res && $chk_res->num_rows > 0) {
                        $row_assign = $chk_res->fetch_assoc();
                        if (strtolower($row_assign['status']) === 'submitted') {
                            $chk_stmt->close();
                            continue; 
                        }
                        $assign_id = $row_assign['id'];
                        $chk_stmt->close();

                        $stmt_fb = $conn->prepare("UPDATE user_feedback_assignments SET answer = ?, status = 'submitted' WHERE id = ?");  
                        $stmt_fb->bind_param("si", $answer, $assign_id);
                    } else {
                        $chk_stmt->close();
                        $stmt_fb = $conn->prepare("INSERT INTO user_feedback_assignments (user_id, question_id, answer, status) VALUES (?, ?, ?, 'submitted')");  
                        $stmt_fb->bind_param("iis", $user_id, $question_id, $answer);
                    }

                    if ($stmt_fb->execute()) {
                        $update_count++;
                    } else {
                        $has_error = true;
                    }
                    $stmt_fb->close();
                }
            }

            if (!$has_error && $update_count > 0) {
                $success_msg = "All feedback responses have been submitted successfully and permanently saved.";
            } else {
                $error_msg = "Some responses could not be saved. Please try again.";
            }
        }
    }
}

// 5. Handle Urgent Pass Request Submission (Updated with Boarding Point & Validation)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_urgent_pass'])) {
    $requested_route = trim($conn->real_escape_string($_POST['requested_route']));
    $boarding_point = trim($conn->real_escape_string($_POST['boarding_point']));
    $reason = trim($conn->real_escape_string($_POST['reason']));

    $conn->query("CREATE TABLE IF NOT EXISTS urgent_pass_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        requested_route VARCHAR(255),
        boarding_point VARCHAR(255),
        reason TEXT,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $urg_cols = $conn->query("SHOW COLUMNS FROM urgent_pass_requests");
    $urg_col_list = [];
    if ($urg_cols) {
        while($uc = $urg_cols->fetch_assoc()) { $urg_col_list[] = $uc['Field']; }
    }
    if (!in_array('boarding_point', $urg_col_list)) {
        $conn->query("ALTER TABLE urgent_pass_requests ADD COLUMN boarding_point VARCHAR(255) DEFAULT ''");
    }

    if (!empty($requested_route) && !empty($boarding_point) && !empty($reason)) {
        $stmt_urg = $conn->prepare("INSERT INTO urgent_pass_requests (user_id, requested_route, boarding_point, reason, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt_urg->bind_param("isss", $user_id, $requested_route, $boarding_point, $reason);
        if ($stmt_urg->execute()) {
            $success_msg = "Urgent bus pass request submitted successfully! Awaiting admin approval.";
        } else {
            $error_msg = "Error submitting request: " . $conn->error;
        }
        $stmt_urg->close();
    } else {
        $error_msg = "Validation Error: All three fields (Alternative Bus Route, Boarding Point, and Reason for Urgency) are mandatory.";
    }
}

// Handle Urgent Pass Payment Verification & Activation (Fixed ₹180)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_urgent_pass_payment'])) {
    $request_id = intval($_POST['urgent_request_id']);
    $payment_id = trim($conn->real_escape_string($_POST['razorpay_payment_id']));

    $conn->query("CREATE TABLE IF NOT EXISTS urgent_pass_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        urgent_request_id INT,
        amount DECIMAL(10,2),
        razorpay_payment_id VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $chk_req = $conn->prepare("SELECT * FROM urgent_pass_requests WHERE id = ? AND user_id = ? AND status = 'Approved'");
    $chk_req->bind_param("ii", $request_id, $user_id);
    $chk_req->execute();
    $req_res = $chk_req->get_result();

    if ($req_res && $req_res->num_rows > 0) {
        $urgent_fee_amount = 180.00;
        $stmt_upay = $conn->prepare("INSERT INTO urgent_pass_payments (user_id, urgent_request_id, amount, razorpay_payment_id) VALUES (?, ?, ?, ?)");
        $stmt_upay->bind_param("iids", $user_id, $request_id, $urgent_fee_amount, $payment_id);
        $stmt_upay->execute();
        $stmt_upay->close();

        $stmt_upd = $conn->prepare("UPDATE urgent_pass_requests SET status = 'Paid & Issued' WHERE id = ?");
        $stmt_upd->bind_param("i", $request_id);
        $stmt_upd->execute();
        $stmt_upd->close();

        $success_msg = "Urgent pass payment of ₹180 completed successfully! Your 1-day digital urgent pass is now active.";
    } else {
        $error_msg = "Invalid or unapproved urgent pass request selected for payment.";
    }
    $chk_req->close();
}

// -------------------------------------------------------------
// FETCH BUS & DRIVER DETAILS (Corrected Parsing & Matching)
// -------------------------------------------------------------
$bus_details = null;

if (!empty($user_bus_no) && strtolower($user_bus_no) !== 'not assigned' && strtolower($user_bus_no) !== 'n/a') {
    // Extract registration number inside parentheses if present (e.g., KL40E6327)
    $reg_number = $user_bus_no;
    if (preg_match('/\(([^)]+)\)/', $user_bus_no, $matches)) {
        $reg_number = trim($matches[1]);
    }
    
    // Extract numeric bus number (e.g., '1' from 'Bus 1')
    $bus_num_only = '';
    if (preg_match('/Bus\s*([0-9]+)/i', $user_bus_no, $match_bus)) {
        $bus_num_only = trim($match_bus[1]);
    }

    $route_cols_result = $conn->query("SHOW COLUMNS FROM bus_routes");
    $route_available_cols = [];
    if ($route_cols_result) {
        while ($row = $route_cols_result->fetch_assoc()) {
            $route_available_cols[] = $row['Field'];
        }
    }

    // Dynamically detect column names for starting point and departure time
    $sp_col = 'N/A';
    foreach (['starting_point', 'start_point', 'source', 'boarding_point'] as $col) {
        if (in_array($col, $route_available_cols)) { $sp_col = $col; break; }
    }

    $st_col = 'N/A';
    foreach (['starting_time', 'start_time', 'departure_time', 'time'] as $col) {
        if (in_array($col, $route_available_cols)) { $st_col = $col; break; }
    }

    $rd_col = '';
    foreach (['route_detail', 'route_details', 'boarding_points', 'route_name', 'stops'] as $col) {
        if (in_array($col, $route_available_cols)) { $rd_col = $col; break; }
    }

    $where_clauses = [];
    $params = [];
    $types = "";

    if (in_array('bus_no_reg', $route_available_cols)) {
        $where_clauses[] = "bus_no_reg = ? OR bus_no_reg LIKE ?";
        $params[] = $reg_number;
        $params[] = "%" . $reg_number . "%";
        $types .= "ss";
    }
    if (in_array('bus_no', $route_available_cols)) {
        $where_clauses[] = "bus_no = ? OR bus_no = ?";
        $params[] = $user_bus_no;
        $params[] = $bus_num_only;
        $types .= "ss";
    }
    if (in_array('bus_number', $route_available_cols)) {
        $where_clauses[] = "bus_number = ?";
        $params[] = $user_bus_no;
        $types .= "s";
    }

    if (!empty($where_clauses)) {
        $select_sp = ($sp_col !== 'N/A') ? "COALESCE($sp_col, 'N/A') AS starting_point" : "'N/A' AS starting_point";
        $select_st = ($st_col !== 'N/A') ? "COALESCE($st_col, 'N/A') AS starting_time" : "'N/A' AS starting_time";
        $select_rd = (!empty($rd_col)) ? "$rd_col AS route_text" : "'' AS route_text";

        $sql_query = "SELECT *, 
            $select_sp, 
            $select_st,
            $select_rd
            FROM bus_routes WHERE " . implode(" OR ", $where_clauses) . " LIMIT 1";

        $stmt_bus = $conn->prepare($sql_query);
        if ($stmt_bus) {
            $stmt_bus->bind_param($types, ...$params);
            $stmt_bus->execute();
            $res_bus = $stmt_bus->get_result();
            if ($res_bus && $res_bus->num_rows > 0) {
                $bus_details = $res_bus->fetch_assoc();
                
                // Fallback: If starting point is empty or N/A, extract it from the route text
                if (empty($bus_details['starting_point']) || $bus_details['starting_point'] === 'N/A') {
                    $raw_route = $bus_details['route_text'] ?? '';
                    if (!empty($raw_route)) {
                        $parts = preg_split('/\s*[-–—]\s*|[\r\n,]+/', $raw_route);
                        if (!empty($parts[0])) {
                            $bus_details['starting_point'] = trim($parts[0]);
                        }
                    }
                }
            }
            $stmt_bus->close();
        }
    }

    // Fetch driver details matching the specific bus number
    $users_cols_result = $conn->query("SHOW COLUMNS FROM users");
    $users_available_cols = [];
    if ($users_cols_result) {
        while ($row = $users_cols_result->fetch_assoc()) {
            $users_available_cols[] = $row['Field'];
        }
    }

    $driver_where = ["role = 'driver'"];
    $driver_params = [];
    $driver_types = "";

    $driver_bus_clauses = [];
    if (in_array('bus_number', $users_available_cols)) {
        $driver_bus_clauses[] = "bus_number = ? OR bus_number LIKE ?";
        $driver_params[] = $user_bus_no;
        $driver_params[] = "%" . $reg_number . "%";
        $driver_types .= "ss";
    }
    if (in_array('bus_no', $users_available_cols)) {
        $driver_bus_clauses[] = "bus_no = ? OR bus_no = ?";
        $driver_params[] = $bus_num_only;
        $driver_params[] = $reg_number;
        $driver_types .= "ss";
    }

    if (!empty($driver_bus_clauses)) {
        $driver_where[] = "(" . implode(" OR ", $driver_bus_clauses) . ")";
    }

    $driver_query = "SELECT full_name, phone FROM users WHERE " . implode(" AND ", $driver_where) . " LIMIT 1";
    $stmt_drv = $conn->prepare($driver_query);
    if ($stmt_drv) {
        if (!empty($driver_types)) {
            $stmt_drv->bind_param($driver_types, ...$driver_params);
        }
        $stmt_drv->execute();
        $res_drv = $stmt_drv->get_result();
        
        if ($res_drv && $res_drv->num_rows > 0) {
            $drv_data = $res_drv->fetch_assoc();
            if (!$bus_details) $bus_details = [];
            $bus_details['driver_name'] = $drv_data['full_name'];
            $bus_details['driver_phone'] = $drv_data['phone'];
        } else {
            if (!$bus_details) $bus_details = [];
            $bus_details['driver_name'] = 'No Driver Assigned';
            $bus_details['driver_phone'] = 'N/A';
        }
        $stmt_drv->close();
    }
}
$is_paid = ($current_payment_status == 'paid' || $current_payment_status == 'completed' || $installments_paid_count >= 1);
$is_student = ($user_data['role'] ?? $_SESSION['role']) === 'student';
$is_faculty = ($user_data['role'] ?? $_SESSION['role']) === 'faculty';

// Faculty members do not make fee payments; restrict fee tab access if faculty tries to access via URL
if ($is_faculty && $tab == 'payments') {
    $tab = 'overview';
}

$emergency_passes = null;
$ep_check = $conn->query("SHOW TABLES LIKE 'emergency_passes'");
if ($ep_check && $ep_check->num_rows > 0) {
    $emergency_passes = $conn->query("SELECT * FROM emergency_passes WHERE user_id = $user_id ORDER BY id DESC");
}

// Fetch user's urgent pass requests
$user_urgent_requests = null;
$ur_table_check = $conn->query("SHOW TABLES LIKE 'urgent_pass_requests'");
if ($ur_table_check && $ur_table_check->num_rows > 0) {
    $stmt_ur_fetch = $conn->prepare("SELECT * FROM urgent_pass_requests WHERE user_id = ? ORDER BY id DESC");
    $stmt_ur_fetch->bind_param("i", $user_id);
    $stmt_ur_fetch->execute();
    $user_urgent_requests = $stmt_ur_fetch->get_result();
    $stmt_ur_fetch->close();
}

// -------------------------------------------------------------
// SEMESTER-CYCLE & PER-INSTALLMENT VALIDITY CALCULATION LOGIC
// -------------------------------------------------------------
$current_year = date('Y');
$semester_start_date = $current_year . '-07-01';

$stmt_lpay = $conn->prepare("SELECT created_at FROM payment_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt_lpay->bind_param("i", $user_id);
$stmt_lpay->execute();
$lpay_res = $stmt_lpay->get_result()->fetch_assoc();
$stmt_lpay->close();
$regular_pass_payment_date = $lpay_res['created_at'] ?? date('Y-m-d');

if ($fee_payment_mode === 'consolidated' || $installments_paid_count >= 4) {
    $regular_pass_expiry_date = date('Y-m-d', strtotime('+5 months', strtotime($semester_start_date)));
} elseif ($installments_paid_count == 3) {
    $regular_pass_expiry_date = date('Y-m-d', strtotime('+4 months +25 days', strtotime($semester_start_date)));
} elseif ($installments_paid_count == 2) {
    $regular_pass_expiry_date = date('Y-m-d', strtotime('+3 months +20 days', strtotime($semester_start_date)));
} elseif ($installments_paid_count == 1) {
    $regular_pass_expiry_date = date('Y-m-d', strtotime('+1 month +25 days', strtotime($semester_start_date)));
} else {
    $regular_pass_expiry_date = date('Y-m-d', strtotime('+5 months', strtotime($semester_start_date)));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Smart College Bus Pass</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Razorpay Checkout Script -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <!-- QRCode Generator Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        @media print {
            body * {
                visibility: hidden;
            }
            .printable-bus-pass, .printable-bus-pass *,
            [class*="printable-urgent-pass-"], [class*="printable-urgent-pass-"] * {
                visibility: visible !important;
            }
            .printable-bus-pass, [class*="printable-urgent-pass-"] {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                box-shadow: none !important;
                border: none !important;
                background-color: white !important;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="bg-[#F8F5F0] font-sans flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-[#1A2E6B] text-white flex flex-col justify-between shadow-lg h-screen overflow-y-auto no-print">
        <div>
            <div class="p-6 text-center border-b border-white/10">
                <h1 class="font-bold text-lg tracking-wider">USER PORTAL</h1>
                <p class="text-xs text-gray-300 mt-1">SNGCE Bus Pass System</p>
            </div>
            <nav class="mt-4 space-y-1">
                <a href="users_dashboard.php?tab=overview" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'overview') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-id-card w-5 mr-3"></i> <?php echo $is_faculty ? 'My Profile' : 'My Bus Pass & Profile'; ?>
                </a>
                
                <?php if (!$is_faculty): ?>
                <a href="users_dashboard.php?tab=payments" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'payments') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-credit-card w-5 mr-3"></i> Fee Payment
                </a>
                <?php endif; ?>

                <a href="users_dashboard.php?tab=bus_driver" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'bus_driver') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-bus w-5 mr-3"></i> Assigned Bus & Driver
                </a>
                <a href="users_dashboard.php?tab=urgent_pass" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'urgent_pass') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-bolt w-5 mr-3"></i> Urgent Pass Request
                </a>
                <a href="users_dashboard.php?tab=emergency" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'emergency') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-triangle-exclamation w-5 mr-3"></i> Emergency Pass Alerts
                </a>
                <a href="users_dashboard.php?tab=feedback" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'feedback') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-comments w-5 mr-3"></i> Feedback 
                </a>
                <a href="complaint.php?tab=complaint" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'Complaint') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-comments w-5 mr-3"></i> Complaint
                </a>
            </nav>
        </div>
        <div class="p-4 border-t border-white/10">
            <a href="Login.html" class="flex items-center justify-center w-full bg-red-600/20 text-red-300 py-2 rounded-lg text-sm font-semibold hover:bg-red-600 hover:text-white transition">
                <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <!-- Top Navigation Bar -->
        <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200 no-print">
            <h2 class="text-xl font-bold text-[#1A2E6B] capitalize"><?php echo str_replace('_', ' ', $tab); ?></h2>
            <div class="flex items-center space-x-3">
                <span class="text-sm font-medium text-gray-600">
                    <?php echo htmlspecialchars($user_data['full_name'] ?? 'User'); ?>
                </span>
                <?php if (!empty($user_data['image'])): ?>
                    <img src="uploads/<?php echo htmlspecialchars($user_data['image']); ?>" class="h-9 w-9 rounded-full object-cover border border-gray-300 shadow-sm" alt="Profile">
                <?php else: ?>
                    <div class="h-9 w-9 bg-[#1A2E6B] text-white rounded-full flex items-center justify-center font-bold text-sm">U</div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Content Body -->
        <div class="p-8">

            <!-- Alerts -->
            <?php if(!empty($success_msg)): ?>
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative no-print">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative no-print">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

           <!-- 1. MY BUS PASS & PROFILE (OVERVIEW) -->
            <?php if ($tab == 'overview'): ?>
                <div class="max-w-3xl mx-auto space-y-6">
                    
                    <!-- Digital Pass Card (Shown only for Students) -->
                    <?php if ($is_student): ?>
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden printable-bus-pass">
                        <div class="bg-[#1A2E6B] text-white p-6 text-center relative">
                            <h3 class="font-bold text-lg tracking-wide">SNGCE DIGITAL BUS PASS</h3>
                            <p class="text-xs text-gray-300 mt-1">Smart College Transportation System</p>
                        </div>
                        <div class="p-8 flex flex-col items-center text-center space-y-4">
                            <?php if ($is_paid): ?>
                                <?php if(!empty($user_data['image'])): ?>
                                    <img src="uploads/<?php echo htmlspecialchars($user_data['image']); ?>" class="w-28 h-28 rounded-full object-cover border-4 border-[#1A2E6B] shadow-md">
                                <?php else: ?>
                                    <div class="w-28 h-28 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 text-3xl"><i class="fa-solid fa-user"></i></div>
                                <?php endif; ?>

                                <div>
                                    <h4 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($user_data['full_name'] ?? 'Guest'); ?></h4>
                                    <p class="text-xs text-gray-500 uppercase tracking-wider mt-0.5"><?php echo ucfirst(htmlspecialchars($user_data['role'] ?? 'student')); ?> | <?php echo htmlspecialchars($user_data['course'] ?? $user_data['department'] ?? $user_data['designation'] ?? 'N/A'); ?></p>
                                </div>

                                <div class="w-full grid grid-cols-2 gap-4 bg-gray-50 p-4 rounded-xl text-left border border-gray-200 text-sm">
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Student's Name</span>
                                        <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($user_data['full_name'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Bus Number</span>
                                        <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($user_data['bus_number'] ?? 'Not Assigned'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Boarding Point</span>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['boarding_point'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Semester Start Date</span>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($semester_start_date); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Validity Expiry Date</span>
                                        <span class="font-bold text-emerald-700"><?php echo htmlspecialchars($regular_pass_expiry_date); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Payment Status</span>
                                        <span class="text-green-700 font-bold bg-green-100 px-2 py-0.5 rounded text-xs"><?php echo ($installments_paid_count >= 4 || $fee_payment_mode === 'consolidated') ? 'No Dues Pending (Full 5 Months Validity)' : 'Installment ' . $installments_paid_count . ' of 4 Paid (1 Month & 25 Days Validity per Installment)'; ?></span>
                                    </div>
                                </div>

                                <!-- QR Code Container -->
                                <div class="flex flex-col items-center justify-center p-3 bg-white border rounded-xl shadow-sm">
                                    <div id="regular-bus-pass-qrcode"></div>
                                    <span class="text-[10px] text-gray-500 mt-2 font-mono">ID: SNGCE-PASS-<?php echo $user_id; ?>-<?php echo date('Ymd', strtotime($semester_start_date)); ?></span>
                                </div>
                                <script>
                                    document.addEventListener("DOMContentLoaded", function() {
                                        new QRCode(document.getElementById("regular-bus-pass-qrcode"), {
                                            text: "SNGCE-BUS-PASS|User:<?php echo $user_id; ?>|Bus:<?php echo urlencode($user_data['bus_number'] ?? ''); ?>|Expires:<?php echo $regular_pass_expiry_date; ?>",
                                            width: 110,
                                            height: 110
                                        });
                                    });
                                </script>

                                <div class="pt-2 no-print">
                                    <button onclick="window.print()" class="bg-[#1A2E6B] text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-[#142353] transition shadow-sm">
                                        <i class="fa-solid fa-print mr-2"></i> Print Bus Pass
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="py-8 px-4 text-center space-y-4">
                                    <div class="w-16 h-16 bg-amber-100 text-amber-700 rounded-full flex items-center justify-center mx-auto text-2xl shadow-inner">
                                        <i class="fa-solid fa-lock"></i>
                                    </div>
                                    <h4 class="text-lg font-bold text-gray-800">Bus Pass Locked - Payment Pending</h4>
                                    <p class="text-sm text-gray-600 max-w-md mx-auto">You must complete your semester bus fee payment or first installment before your digital bus pass can be viewed, accessed, or printed.</p>
                                    <div class="pt-2">
                                        <a href="users_dashboard.php?tab=payments" class="inline-flex items-center bg-[#1A2E6B] text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-[#142353] transition shadow-sm">
                                            <i class="fa-solid fa-credit-card mr-2"></i> Proceed to Fee Payment
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Profile Details View -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-8 space-y-6">
                        <div class="flex items-center space-x-4 border-b pb-4">
                            <?php if(!empty($user_data['image'])): ?>
                                <img src="uploads/<?php echo htmlspecialchars($user_data['image']); ?>" class="w-16 h-16 rounded-full object-cover border-2 border-[#1A2E6B] shadow-sm">
                            <?php else: ?>
                                <div class="w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 text-2xl"><i class="fa-solid fa-user"></i></div>
                            <?php endif; ?>
                            <div>
                                <h3 class="text-xl font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($user_data['full_name'] ?? 'N/A'); ?></h3>
                                <p class="text-xs text-gray-500 uppercase font-semibold"><?php echo ucfirst(htmlspecialchars($user_data['role'] ?? 'user')); ?> Profile</p>
                            </div>
                        </div>

                        <div>
                            <h4 class="text-xs font-bold uppercase text-gray-400 tracking-wider mb-3">Personal Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm bg-gray-50 p-6 rounded-xl border border-gray-200">
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Full Name</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['full_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Email Address</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['email'] ?? 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Phone Number</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['phone'] ?? 'N/A'); ?></span>
                                </div>
                                
                                <?php if ($is_student): ?>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Guardian Name</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['guardian_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Guardian Relationship</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['guardian_relationship'] ?? 'N/A'); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Guardian Phone Number</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['guardian_phone'] ?? 'N/A'); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <h4 class="text-xs font-bold uppercase text-gray-400 tracking-wider mb-3">Institutional & Transport Details</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm bg-gray-50 p-6 rounded-xl border border-gray-200">
                                <?php if ($is_student): ?>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Course</span>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['course'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Batch / Year</span>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['batch'] ?? 'N/A'); ?></span>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Designation</span>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['designation'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div>
                                        <span class="text-xs text-gray-500 uppercase block font-semibold">Department</span>
                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['department'] ?? 'N/A'); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Bus Number</span>
                                    <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($user_data['bus_number'] ?? 'Not Assigned'); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase block font-semibold">Boarding Point</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_data['boarding_point'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

       <!-- 2. FEE PAYMENT & RAZORPAY -->
            <?php elseif ($tab == 'payments'): ?>
                <div class="max-w-xl mx-auto bg-white rounded-xl shadow-md border border-gray-200 p-8">
                    <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Bus Fee Payment Portal</h3>
                    
                    <?php
                    // Ensure payment_transactions has semester tracking column
                    $conn->query("CREATE TABLE IF NOT EXISTS payment_transactions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        amount DECIMAL(10,2),
                        payment_plan VARCHAR(50),
                        semester_id VARCHAR(100),
                        razorpay_payment_id VARCHAR(100),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )");
                    
                    $txn_cols = $conn->query("SHOW COLUMNS FROM payment_transactions");
                    $txn_col_list = [];
                    while($tcol = $txn_cols->fetch_assoc()) { $txn_col_list[] = $tcol['Field']; }
                    if (!in_array('semester_id', $txn_col_list)) {
                        $conn->query("ALTER TABLE payment_transactions ADD COLUMN semester_id VARCHAR(100) DEFAULT ''");
                    }

                   // Get student registration/creation date
$student_reg_date = $user_data['created_at'] ?? $user_data['registration_date'] ?? date('Y-m-d');
$student_reg_timestamp = strtotime($student_reg_date);

// Fetch published payment cycles from database dynamically (Admin published ones)
$published_cycles_res = $conn->query("SELECT * FROM semester_payment_cycles WHERE status = 'published' ORDER BY id DESC");
$semesters_list = [];

if ($published_cycles_res && $published_cycles_res->num_rows > 0) {
    while($cyc = $published_cycles_res->fetch_assoc()) {
        $ac_year = $cyc['academic_year']; // e.g., "2025-26"
        $sem_period = $cyc['semester_period']; // e.g., "July to December"
        
        // Extract starting year of the academic year
        $years = explode('-', $ac_year);
        $start_year = intval($years[0]);
        
        // Determine approximate semester start date based on period
        $sem_start_month = "07";
        $target_year = $start_year;
        
        if (stripos($sem_period, 'January') !== false || stripos($sem_period, 'Jan') !== false) {
            $sem_start_month = "01";
            if (isset($years[1])) {
                $target_year = intval(substr($years[0], 0, 2) . $years[1]);
            } else {
                $target_year = $start_year + 1;
            }
        } elseif (stripos($sem_period, 'July') !== false || stripos($sem_period, 'Jul') !== false) {
            $sem_start_month = "07";
            $target_year = $start_year;
        }
        
        $sem_approx_date = $target_year . '-' . $sem_start_month . '-01';
        $sem_timestamp = strtotime($sem_approx_date);
        
        // Calculate approximate semester end date (+6 months from start)
        $sem_end_timestamp = strtotime('+6 months', $sem_timestamp);
        
        // Only include semesters where the semester ends AFTER the student registered
        if ($sem_end_timestamp >= $student_reg_timestamp) {
            $sem_id = $cyc['academic_year'] . '_' . str_replace(' ', '_', strtolower($cyc['semester_period']));
            $sem_name = $cyc['academic_year'] . ' (' . $cyc['semester_period'] . ')';
            
            $semesters_list[] = [
                'id' => $sem_id,
                'name' => $sem_name
            ];
        }
    }
}

                    // Handle selected semester via GET/POST
                    $selected_semester = $_POST['selected_semester'] ?? $_GET['semester'] ?? '';

                    // Check payment status and installments paid specifically for the selected semester
                    $semester_installments_paid = 0;
                    $semester_payment_mode = '';
                    $semester_fully_paid = false;

                    if (!empty($selected_semester)) {
                        $chk_sem_pay = $conn->prepare("SELECT payment_plan, COUNT(*) as cnt FROM payment_transactions WHERE user_id = ? AND semester_id = ? GROUP BY payment_plan");
                        $chk_sem_pay->bind_param("is", $user_id, $selected_semester);
                        $chk_sem_pay->execute();
                        $sem_pay_res = $chk_sem_pay->get_result();
                        
                        $total_paid_amount = 0;
                        while($sp_row = $sem_pay_res->fetch_assoc()) {
                            if ($sp_row['payment_plan'] === 'consolidated') {
                                $semester_fully_paid = true;
                                $semester_installments_paid = 4;
                                $semester_payment_mode = 'consolidated';
                            }
                        }
                        $chk_sem_pay->close();

                        // If not consolidated, count installment transactions
                        if (!$semester_fully_paid) {
                            $chk_inst_count = $conn->prepare("SELECT COUNT(*) as inst_count FROM payment_transactions WHERE user_id = ? AND semester_id = ? AND payment_plan = 'installment'");
                            $chk_inst_count->bind_param("is", $user_id, $selected_semester);
                            $chk_inst_count->execute();
                            $inst_res = $chk_inst_count->get_result()->fetch_assoc();
                            $semester_installments_paid = intval($inst_res['inst_count'] ?? 0);
                            $chk_inst_count->close();

                            if ($semester_installments_paid >= 4) {
                                $semester_fully_paid = true;
                                $semester_payment_mode = 'installment';
                            }
                        }
                    }
                    ?>

                    <!-- Academic Year & Semester Selection Dropdown Form -->
                    <form method="GET" action="users_dashboard.php" class="mb-6">
                        <input type="hidden" name="tab" value="payments">
                        <label class="block text-xs font-bold uppercase text-gray-600 mb-2">Select Published Academic Year & Semester <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <select name="semester" onchange="this.form.submit()" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-lg text-sm font-semibold text-gray-800">
                                <option value="">-- Choose Published Semester --</option>
                                <?php foreach($semesters_list as $sem): ?>
                                    <option value="<?php echo $sem['id']; ?>" <?php echo ($selected_semester == $sem['id']) ? 'selected' : ''; ?>>
                                        <?php echo $sem['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if (empty($selected_semester)): ?>
                        
                    <?php else: ?>
                        <?php if($semester_fully_paid): ?>
                            <div class="bg-green-100 border border-green-400 text-green-700 p-6 rounded-lg text-center space-y-2">
                                <i class="fa-solid fa-circle-check text-3xl mb-1"></i>
                                <p class="font-bold text-lg">Fee Successfully Paid - No Dues Pending</p>
                                <p class="text-xs">All fees for <?php echo htmlspecialchars(str_replace('_', ' ', $selected_semester)); ?> have been fully cleared successfully.</p>
                            </div>
                        <?php else: ?>
                            <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200 text-sm">
                                <?php if ($semester_installments_paid > 0): ?>
                                    <p class="text-blue-800 font-bold mb-1"><i class="fa-solid fa-circle-info mr-1"></i> Installment <?php echo $semester_installments_paid; ?> of 4 Paid Successfully</p>
                                    <p class="text-xs text-gray-600">Please pay the next pending installment amount below: <strong class="text-[#1A2E6B]">₹<?php echo number_format($single_installment_amount, 2); ?></strong></p>
                                <?php else: ?>
                                    <p class="text-gray-700 font-medium">Please select your fee payment plan for this semester:</p>
                                <?php endif; ?>
                            </div>

                            <div class="space-y-4 mb-6">
                                <?php if ($semester_installments_paid == 0 && $consolidated_fee_amount > 0): ?>
                                <label class="block border rounded-xl p-4 cursor-pointer hover:border-[#1A2E6B] transition bg-gray-50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <input type="radio" name="fee_plan" id="plan_consolidated" value="consolidated" checked class="text-[#1A2E6B]">
                                            <div>
                                                <span class="font-bold text-gray-900 block text-sm">Consolidated Semester Fee</span>
                                                <span class="text-xs text-gray-500">Full term clearance for <?php echo htmlspecialchars($user_boarding); ?></span>
                                            </div>
                                        </div>
                                        <span class="font-bold text-[#1A2E6B] text-base">₹<?php echo number_format($consolidated_fee_amount, 2); ?></span>
                                    </div>
                                </label>
                                <?php endif; ?>

                                <label class="block border rounded-xl p-4 cursor-pointer hover:border-[#1A2E6B] transition bg-gray-50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <input type="radio" name="fee_plan" id="plan_installment" value="installment" <?php echo ($semester_installments_paid > 0 || $consolidated_fee_amount <= 0) ? 'checked' : ''; ?> class="text-[#1A2E6B]">
                                            <div>
                                                <span class="font-bold text-gray-900 block text-sm">Installment <?php echo ($semester_installments_paid + 1); ?> of 4 (with Interest Included)</span>
                                                <span class="text-xs text-gray-500">Next pending installment payment</span>
                                            </div>
                                        </div>
                                        <span class="font-bold text-[#1A2E6B] text-base">₹<?php echo number_format($single_installment_amount, 2); ?></span>
                                    </div>
                                </label>
                            </div>

                            <!-- Consolidated / Fee Details Table -->
                            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 mb-6 space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Selected Semester:</span>
                                    <span class="font-bold uppercase text-[#1A2E6B]"><?php echo htmlspecialchars(str_replace('_', ' ', $selected_semester)); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Passenger Name:</span>
                                    <span class="font-bold"><?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Bus Number:</span>
                                    <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($user_data['bus_number'] ?? ''); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Boarding Point (Stage):</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($user_boarding); ?></span>
                                </div>
                            </div>

                            <button type="button" id="pay-btn" class="w-full bg-[#1A2E6B] text-white py-3 rounded-lg font-semibold hover:bg-[#142353] transition shadow-sm text-sm">
                                <i class="fa-solid fa-lock mr-2"></i> Pay Now 
                            </button>

                            <form id="razorpay-success-form" action="users_dashboard.php?tab=payments" method="POST" class="hidden">
                                <input type="hidden" name="verify_razorpay_payment" value="1">
                                <input type="hidden" name="selected_plan" id="form_selected_plan">
                                <input type="hidden" name="razorpay_payment_id" id="form_razorpay_payment_id">
                                <input type="hidden" name="selected_semester" value="<?php echo htmlspecialchars($selected_semester); ?>">
                            </form>

                            <script>
                                document.getElementById('pay-btn').onclick = function(e) {
                                    const consolidatedRadio = document.getElementById('plan_consolidated');
                                    const isConsolidated = consolidatedRadio ? consolidatedRadio.checked : false;
                                    const selectedPlanName = isConsolidated ? 'consolidated' : 'installment';
                                    const amountInRupees = isConsolidated ? <?php echo $consolidated_fee_amount; ?> : <?php echo $single_installment_amount; ?>;
                                    
                                    var options = {
                                        "key": "rzp_test_6dalArTaXA3vfg",
                                        "amount": Math.round(amountInRupees * 100),
                                        "currency": "INR",
                                        "name": "SNGCE Bus Pass Portal",
                                        "description": "Semester Bus Fee (" + selectedPlanName.toUpperCase() + ")",
                                        "handler": function (response){
                                            document.getElementById('form_selected_plan').value = selectedPlanName;
                                            document.getElementById('form_razorpay_payment_id').value = response.razorpay_payment_id;
                                            document.getElementById('razorpay-success-form').submit();
                                        },
                                        "prefill": {
                                            "name": "<?php echo addslashes($user_data['full_name']); ?>",
                                            "email": "<?php echo addslashes($user_data['email'] ?? $logged_in_username); ?>",
                                            "contact": "<?php echo addslashes($user_data['phone'] ?? ''); ?>"
                                        },
                                        "theme": {
                                            "color": "#1A2E6B"
                                        }
                                    };
                                    var rzp1 = new Razorpay(options);
                                    rzp1.open();
                                    e.preventDefault();
                                }
                            </script>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            

           <!-- 3. ASSIGNED BUS & DRIVER -->
            <?php elseif ($tab == 'bus_driver'): ?>
                <div class="max-w-xl mx-auto bg-white rounded-xl shadow-md border border-gray-200 p-8">
                    <h3 class="text-lg font-bold text-[#1A2E6B] mb-2">Assigned Bus & Driver Information</h3>
                    <p class="text-xs text-gray-500 mb-6">Detailed information regarding your assigned college transportation vehicle, starting route, departure time, and driver credentials.</p>
                    
                    <?php if ($bus_details): ?>
                        <div class="space-y-4 text-sm">
                            <div class="bg-blue-50 border border-blue-200 p-4 rounded-xl space-y-3">
                                <div class="flex justify-between items-center border-b border-blue-100 pb-2">
                                    <span class="text-gray-600 font-medium">Assigned Bus No.:</span>
                                    <span class="font-bold text-[#1A2E6B] text-base"><?php echo htmlspecialchars($bus_details['bus_no_reg'] ?? $bus_details['bus_no'] ?? $bus_details['bus_number'] ?? $user_bus_no); ?></span>
                                </div>
                                <div class="flex justify-between items-center border-b border-blue-100 pb-2">
                                    <span class="text-gray-600 font-medium">Starting Point:</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($bus_details['starting_point'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between items-center border-b border-blue-100 pb-2">
                                    <span class="text-gray-600 font-medium">Departure Time:</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($bus_details['starting_time'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between items-center border-b border-blue-100 pb-2">
                                    <span class="text-gray-600 font-medium">Assigned Driver Name:</span>
                                    <span class="font-bold text-gray-900"><?php echo htmlspecialchars($bus_details['driver_name'] ?? 'Not Assigned'); ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600 font-medium">Driver Contact Phone:</span>
                                    <span class="font-bold text-emerald-700"><?php echo htmlspecialchars($bus_details['driver_phone'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                            <?php if (!empty($bus_details['route_text'])): ?>
                            <div class="p-4 bg-gray-50 rounded-xl border border-gray-200">
                                <span class="text-xs font-bold uppercase text-gray-600 block mb-1">Complete Route Path</span>
                                <p class="text-xs text-gray-700"><?php echo htmlspecialchars($bus_details['route_text']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-amber-50 border border-amber-300 text-amber-800 p-4 rounded-lg text-center text-sm">
                            <i class="fa-solid fa-triangle-exclamation text-xl mb-1 block"></i>
                            <p class="font-bold">No specific bus or driver mapping found for your account (Bus Number on record: "<?php echo htmlspecialchars($user_bus_no); ?>").</p>
                            <p class="text-xs mt-1">Please ensure your bus number matches an active record in the admin bus transport table (`bus_routes`).</p>
                        </div>
                    <?php endif; ?>
                </div>

                    <!-- 4. URGENT PASS REQUEST & PAYMENT WORKFLOW -->
            <?php elseif ($tab == 'urgent_pass'): ?>
                <div class="max-w-2xl mx-auto space-y-8">
                    
                    <?php
                    $current_time_val = date('Y-m-d H:i:s');
                    $paid_urgent_q = $conn->prepare("SELECT r.*, p.created_at AS payment_date FROM urgent_pass_requests r JOIN urgent_pass_payments p ON r.id = p.urgent_request_id WHERE r.user_id = ? AND r.status = 'Paid & Issued' ORDER BY r.id DESC");
                    $paid_urgent_q->bind_param("i", $user_id);
                    $paid_urgent_q->execute();
                    $paid_urgent_res = $paid_urgent_q->get_result();
                    $paid_urgent_q->close();

                    $active_urgent_passes = [];
                    $past_urgent_passes = [];

                    if ($paid_urgent_res && $paid_urgent_res->num_rows > 0) {
                        while($pur = $paid_urgent_res->fetch_assoc()) {
                            $urgent_payment_date = $pur['payment_date'] ?? date('Y-m-d H:i:s');
                            // Exactly 24 hours (1 day) validity from payment timestamp
                            $urgent_expiry_date = date('Y-m-d H:i:s', strtotime('+24 hours', strtotime($urgent_payment_date)));
                            $pur['expiry_date'] = $urgent_expiry_date;
                            $pur['payment_date_formatted'] = $urgent_payment_date;
                            
                            if (strtotime($current_time_val) <= strtotime($urgent_expiry_date)) {
                                $active_urgent_passes[] = $pur;
                            } else {
                                $past_urgent_passes[] = $pur;
                            }
                        }
                    }
                    ?>

                    <!-- 1. Active Valid Urgent Pass (Square QR Code + 24hr Validity) -->
                    <?php if (!empty($active_urgent_passes)): ?>
                        <div class="space-y-6">
                            <h4 class="font-bold text-md text-[#1A2E6B]">Current Active Urgent Pass (24 Hours Validity)</h4>
                            <?php foreach($active_urgent_passes as $pur): 
                                $urg_id_div = "urgent-pass-qr-" . $pur['id'];
                            ?>
                                <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden printable-urgent-pass-<?php echo $pur['id']; ?>">
                                    <div class="bg-[#1A2E6B] text-white p-4 text-center">
                                        <h3 class="font-bold text-md tracking-wide">SNGCE DIGITAL 24-HOUR URGENT BUS PASS</h3>
                                        <p class="text-[11px] text-gray-300">Valid Temporary Pass</p>
                                    </div>
                                    <div class="p-6 flex flex-col items-center text-center space-y-4">
                                        <div class="w-full grid grid-cols-2 gap-3 bg-gray-50 p-4 rounded-xl text-left border border-gray-200 text-sm">
                                            <div>
                                                <span class="text-xs text-gray-500 uppercase block font-semibold">Student's Name</span>
                                                <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($user_data['full_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500 uppercase block font-semibold">Alternative Bus Route</span>
                                                <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($pur['requested_route']); ?></span>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500 uppercase block font-semibold">Selected Boarding Point</span>
                                                <span class="font-bold text-gray-800"><?php echo htmlspecialchars($pur['boarding_point'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div>
                                                <span class="text-xs text-gray-500 uppercase block font-semibold">Validity Expiry (24h)</span>
                                                <span class="font-bold text-red-600"><?php echo htmlspecialchars($pur['expiry_date']); ?></span>
                                            </div>
                                        </div>

                                        <!-- Square QR Code Container -->
                                        <div class="flex flex-col items-center justify-center p-3 bg-white border rounded-xl shadow-sm">
                                            <div id="<?php echo $urg_id_div; ?>" class="w-[150px] h-[150px] flex items-center justify-center"></div>
                                            <span class="text-[10px] text-gray-500 mt-2 font-mono">ID: URGENT-PASS-<?php echo $pur['id']; ?></span>
                                        </div>
                                        <script>
                                            document.addEventListener("DOMContentLoaded", function() {
                                                new QRCode(document.getElementById("<?php echo $urg_id_div; ?>"), {
                                                    text: "SNGCE-URGENT-PASS|User:<?php echo $user_id; ?>|Route:<?php echo urlencode($pur['requested_route']); ?>|Boarding:<?php echo urlencode($pur['boarding_point'] ?? ''); ?>|Expires:<?php echo $pur['expiry_date']; ?>",
                                                    width: 140,
                                                    height: 140
                                                });
                                            });
                                        </script>

                                        <div class="pt-2 no-print">
                                            <button onclick="window.print()" class="bg-[#1A2E6B] text-white px-6 py-2.5 rounded-lg text-xs font-semibold hover:bg-[#142353] transition shadow-sm">
                                                <i class="fa-solid fa-print mr-2"></i> Print Urgent Pass
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- 2. New Urgent Pass Request Form -->
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 space-y-4">
                        <h3 class="text-lg font-bold text-[#1A2E6B]">Request New Urgent Pass</h3>
                        <form action="users_dashboard.php?tab=urgent_pass" method="POST" class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Alternative Bus Route <span class="text-red-500">*</span></label>
                                <select name="requested_route" id="requested_route_select" required onchange="updateBoardingPoints()" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm text-gray-700">
                                    <option value="">-- Select Alternative Bus --</option>
                                    <?php 
                                    if (!empty($bus_routes_data)) {
                                        $user_bus_digits = preg_replace('/[^0-9]/', '', $user_bus_no);
                                        foreach ($bus_routes_data as $route_key => $rdata) { 
                                            $bus_identifier = $rdata['identifier'];
                                            $row_bus_digits = preg_replace('/[^0-9]/', '', $bus_identifier);

                                            if (!empty($user_bus_no) && (
                                                strcasecmp(trim($bus_identifier), trim($user_bus_no)) == 0 || 
                                                strcasecmp(trim($route_key), trim($user_bus_no)) == 0 ||
                                                (!empty($user_bus_digits) && !empty($row_bus_digits) && $row_bus_digits === $user_bus_digits)
                                            )) {
                                                continue;
                                            }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($route_key); ?>"><?php echo htmlspecialchars($route_key); ?></option>
                                        <?php 
                                        }
                                    } 
                                    ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Boarding Point <span class="text-red-500">*</span></label>
                                <select name="boarding_point" id="boarding_point_select" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm text-gray-700">
                                    <option value="">-- First Select Alternative Bus Route --</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Reason for Urgency <span class="text-red-500">*</span></label>
                                <textarea name="reason" rows="3" required placeholder="Explain why you need this urgent pass..." class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm"></textarea>
                            </div>

                            <button type="submit" name="request_urgent_pass" class="w-full bg-[#1A2E6B] text-white py-2.5 rounded-lg font-semibold hover:bg-[#142353] transition shadow-sm text-sm">
                                Submit Urgent Request
                            </button>
                        </form>
                    </div>

                    <!-- 3. Request Status & Payment Table (Pay ₹180 via Razorpay) -->
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Request Status & Payments</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">Route</th>
                                        <th class="p-3">Boarding Point</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3 text-center">Action / Payment (₹180)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if ($user_urgent_requests && $user_urgent_requests->num_rows > 0): ?>
                                        <?php while($ur = $user_urgent_requests->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-3 font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($ur['requested_route']); ?></td>
                                                <td class="p-3 text-gray-800"><?php echo htmlspecialchars($ur['boarding_point'] ?? 'N/A'); ?></td>
                                                <td class="p-3">
                                                    <?php 
                                                        $status = trim($ur['status'] ?? 'pending');
                                                        $status_lower = strtolower($status);
                                                        
                                                        if ($status_lower == 'approved') {
                                                            echo '<span class="bg-green-100 text-green-700 font-bold px-2.5 py-1 rounded-full text-xs">Approved</span>';
                                                        } elseif ($status_lower == 'paid & issued') {
                                                            echo '<span class="bg-blue-100 text-blue-700 font-bold px-2.5 py-1 rounded-full text-xs">Paid & Issued</span>';
                                                        } elseif ($status_lower == 'rejected') {
                                                            echo '<span class="bg-red-100 text-red-700 font-bold px-2.5 py-1 rounded-full text-xs">Rejected</span>';
                                                        } else {
                                                            echo '<span class="bg-amber-100 text-amber-700 font-medium px-2.5 py-1 rounded-full text-xs">Pending</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td class="p-3 text-center">
                                                    <?php if ($status_lower == 'approved'): ?>
                                                        <button type="button" onclick="payUrgentPass(<?php echo $ur['id']; ?>, '<?php echo addslashes($ur['requested_route']); ?>')" class="bg-emerald-600 text-white px-4 py-1.5 rounded-lg text-xs font-semibold hover:bg-emerald-700 transition shadow-sm">
                                                            <i class="fa-solid fa-credit-card mr-1"></i> Pay ₹180
                                                        </button>
                                                    <?php elseif ($status_lower == 'paid & issued'): ?>
                                                        <span class="text-xs text-emerald-700 font-bold">Active</span>
                                                    <?php elseif ($status_lower == 'rejected'): ?>
                                                        <span class="text-xs text-red-600 font-medium">Payment not possible</span>
                                                    <?php else: ?>
                                                        <span class="text-xs text-amber-600 font-medium">Review Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="py-4 text-center text-gray-500">No requests found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 4. Urgent Pass Request History (Expired 24h Passes Table with Route, Boarding Point, Payment Date, and Expiry Info) -->
                    <?php if (!empty($past_urgent_passes)): ?>
                        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 space-y-4">
                            <h4 class="font-bold text-md text-[#1A2E6B]">Urgent Pass Request History (Expired)</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-sm">
                                    <thead>
                                        <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                            <th class="p-3">Route</th>
                                            <th class="p-3">Boarding Point</th>
                                            <th class="p-3">Payment Date</th>
                                            <th class="p-3">Validity Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach($past_urgent_passes as $pur): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-3 font-semibold text-gray-800"><?php echo htmlspecialchars($pur['requested_route']); ?></td>
                                                <td class="p-3 text-gray-700"><?php echo htmlspecialchars($pur['boarding_point'] ?? 'N/A'); ?></td>
                                                <td class="p-3 text-gray-600 text-xs"><?php echo htmlspecialchars($pur['payment_date_formatted']); ?></td>
                                                <td class="p-3">
                                                    <span class="bg-red-100 text-red-700 font-semibold px-2 py-1 rounded text-xs">
                                                        Expired (Ended at <?php echo htmlspecialchars($pur['expiry_date']); ?>)
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

          <!-- Razorpay & Dynamic Route Script Support -->
<form id="urgent-razorpay-form" action="users_dashboard.php?tab=urgent_pass" method="POST" class="hidden">
    <input type="hidden" name="verify_urgent_pass_payment" value="1">
    <input type="hidden" name="urgent_request_id" id="form_urgent_request_id">
    <input type="hidden" name="razorpay_payment_id" id="form_urgent_razorpay_payment_id">
</form>

<script>
    const busRoutesData = <?php echo !empty($bus_routes_data) ? json_encode($bus_routes_data) : '{}'; ?>;
    
    function updateBoardingPoints() {
        const selectRoute = document.getElementById('requested_route_select').value;
        const selectBp = document.getElementById('boarding_point_select');
        selectBp.innerHTML = '<option value="">-- Select Boarding Point --</option>';
        
        if (selectRoute && busRoutesData[selectRoute] && busRoutesData[selectRoute].points) {
            busRoutesData[selectRoute].points.forEach(point => {
                const opt = document.createElement('option');
                opt.value = point;
                opt.textContent = point;
                selectBp.appendChild(opt);
            });
        }
    }

    function payUrgentPass(requestId, routeName) {
        var options = {
            "key": "rzp_test_6dalArTaXA3vfg",
            "amount": 18000, // ₹180.00 in paisa
            "currency": "INR",
            "name": "SNGCE Bus Pass Portal",
            "description": "Urgent Bus Pass (Route: " + routeName + ")",
            "handler": function (response){
                var form = document.getElementById('urgent-razorpay-form');
                document.getElementById('form_urgent_request_id').value = requestId;
                document.getElementById('form_urgent_razorpay_payment_id').value = response.razorpay_payment_id;
                form.submit();
            },
            "prefill": {
                "name": "<?php echo addslashes($user_data['full_name']); ?>",
                "email": "<?php echo addslashes($user_data['email'] ?? $logged_in_username); ?>",
                "contact": "<?php echo addslashes($user_data['phone'] ?? ''); ?>"
            },
            "theme": {
                "color": "#1A2E6B"
            }
        };
        var rzp1 = new Razorpay(options);
        rzp1.open();
    }
</script>

          <!-- 5. EMERGENCY BUS PASS ALERTS & QR CODE -->
            <?php elseif ($tab == 'emergency'): ?>
                <div class="max-w-2xl mx-auto space-y-6">
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 space-y-4">
                        <h3 class="text-lg font-bold text-[#1A2E6B]">Emergency Bus Pass & Active Alerts</h3>
                        <p class="text-xs text-gray-500">The emergency bus pass announced by the admin and its validity can be seen below.</p>

                        <?php
                        $current_date_time = date('Y-m-d H:i:s');
                        if ($emergency_passes && $emergency_passes->num_rows > 0) {
                            while($ep = $emergency_passes->fetch_assoc()) {
                                $valid_until = $ep['valid_until'] ?? '';
                                $issue_date = $ep['issue_date'] ?? $ep['created_at'] ?? 'N/A'; // Adjust column name based on your database schema
                                $is_valid = (!empty($valid_until) && strtotime($current_date_time) <= strtotime($valid_until));
                                $ep_qr_div = "emergency-pass-qr-" . $ep['id'];
                                ?>
                                <div class="border rounded-xl p-5 bg-gray-50 space-y-4 shadow-sm">
                                    <div class="flex justify-between items-center border-b pb-2">
                                        <span class="text-xs font-bold uppercase text-gray-500">Failed Bus: <strong class="text-red-600"><?php echo htmlspecialchars($ep['failed_bus'] ?? 'N/A'); ?></strong></span>
                                        <?php if ($is_valid): ?>
                                            <span class="bg-green-100 text-green-700 font-bold px-2.5 py-0.5 rounded text-xs">Active Pass</span>
                                        <?php else: ?>
                                            <span class="bg-red-100 text-red-700 font-bold px-2.5 py-0.5 rounded text-xs">Expired</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <span class="text-xs text-gray-500 block font-semibold">Alternative Assigned Bus</span>
                                            <span class="font-bold text-[#1A2E6B] text-base"><?php echo htmlspecialchars($ep['alternative_bus'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500 block font-semibold">Issue Date</span>
                                            <span class="font-bold text-gray-700"><?php echo htmlspecialchars($issue_date); ?></span>
                                        </div>
                                        <div class="col-span-2">
                                            <span class="text-xs text-gray-500 block font-semibold">Valid Until</span>
                                            <span class="font-bold text-amber-800"><?php echo htmlspecialchars($valid_until); ?></span>
                                        </div>
                                        <div class="col-span-2">
                                            <span class="text-xs text-gray-500 block font-semibold">Reason for Emergency</span>
                                            <p class="text-xs text-gray-700 mt-0.5"><?php echo htmlspecialchars($ep['reason'] ?? 'N/A'); ?></p>
                                        </div>
                                    </div>

                                    <?php if ($is_valid): ?>
                                        <div class="flex flex-col items-center justify-center p-3 bg-white border rounded-xl shadow-sm mt-2">
                                            <div id="<?php echo $ep_qr_div; ?>" class="w-[130px] h-[130px] flex items-center justify-center"></div>
                                            <span class="text-[10px] text-gray-500 mt-2 font-mono">EMERGENCY PASS ID: #<?php echo $ep['id']; ?></span>
                                        </div>
                                        <script>
                                            document.addEventListener("DOMContentLoaded", function() {
                                                new QRCode(document.getElementById("<?php echo $ep_qr_div; ?>"), {
                                                    text: "SNGCE-EMERGENCY-PASS|User:<?php echo $user_id; ?>|AltBus:<?php echo urlencode($ep['alternative_bus'] ?? ''); ?>|Expires:<?php echo $valid_until; ?>",
                                                    width: 120,
                                                    height: 120
                                                });
                                            });
                                        </script>
                                    <?php else: ?>
                                        <div class="bg-gray-100 text-gray-500 text-center py-2 rounded-lg text-xs font-medium">
                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> The QR code is not available because it has expired (Expired QR Code).
                                    <?php endif; ?>
                                </div>
                                <?php
                            }
                        } else {
                            echo '<div class="text-center py-8 text-gray-500 text-sm">No emergency bus pass alert is available.</div>';
                        }
                        ?>
                    </div>
                </div>
            <!-- 6. FEEDBACK WORKFLOW -->
            <?php elseif ($tab == 'feedback'): ?>
                <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-md border border-gray-200 p-8">
                    <h3 class="text-lg font-bold text-[#1A2E6B] mb-2">Transport Feedback & Suggestions</h3>
                    <p class="text-xs text-gray-500 mb-6">Please answer all published feedback questions below. Submissions are permanent and strictly read-only once submitted.</p>

                    <?php
                    $q_cols_res = $conn->query("SHOW COLUMNS FROM feedback_questions");
                    $q_cols = [];
                    while ($col_row = $q_cols_res->fetch_assoc()) {
                        $q_cols[] = $col_row['Field'];
                    }

                    if (in_array('status', $q_cols)) {
                        $questions_res = $conn->query("SELECT * FROM feedback_questions WHERE status = 'published' OR status = 'active'");
                    } elseif (in_array('is_published', $q_cols)) {
                        $questions_res = $conn->query("SELECT * FROM feedback_questions WHERE is_published = 1");
                    } else {
                        $questions_res = $conn->query("SELECT * FROM feedback_questions");
                    }

                    $published_questions = [];
                    if ($questions_res && $questions_res->num_rows > 0) {
                        while ($qr = $questions_res->fetch_assoc()) {
                            $published_questions[] = $qr;
                        }
                    }

                    $already_submitted_all = false;
                    $submitted_answers_map = [];
                    if (!empty($published_questions)) {
                        $sub_check = $conn->prepare("SELECT question_id, answer, status FROM user_feedback_assignments WHERE user_id = ?");
                        $sub_check->bind_param("i", $user_id);
                        $sub_check->execute();
                        $sub_res = $sub_check->get_result();
                        $submitted_count = 0;
                        while($srow = $sub_res->fetch_assoc()) {
                            $submitted_answers_map[$srow['question_id']] = $srow['answer'];
                            if (strtolower($srow['status']) === 'submitted') {
                                $submitted_count++;
                            }
                        }
                        $sub_check->close();

                        if ($submitted_count >= count($published_questions)) {
                            $already_submitted_all = true;
                        }
                    }
                    ?>

                    <?php if ($already_submitted_all): ?>
                        <div class="bg-green-50 border border-green-300 text-green-800 p-6 rounded-xl text-center space-y-2">
                            <i class="fa-solid fa-circle-check text-3xl mb-1"></i>
                            <p class="font-bold text-base">Feedback Already Submitted</p>
                            <p class="text-xs text-gray-600">You have already submitted your responses. Modifications are permanently locked.</p>
                        </div>
                    <?php elseif (empty($published_questions)): ?>
                        <div class="bg-gray-50 border border-gray-200 text-gray-600 p-6 rounded-xl text-center text-sm">
                            <i class="fa-solid fa-info-circle text-xl mb-1 block"></i>
                            <p class="font-medium">No published feedback questions available at the moment.</p>
                        </div>
                    <?php else: ?>
                        <form action="users_dashboard.php?tab=feedback" method="POST" class="space-y-6">
                            <?php foreach ($published_questions as $index => $q): 
                                $qid = $q['id'];
                                $q_text = $q['question_text'] ?? $q['question'] ?? $q['title'] ?? 'Question ' . ($index + 1);
                                $existing_ans = $submitted_answers_map[$qid] ?? '';
                            ?>
                                <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 space-y-2">
                                    <label class="block text-sm font-bold text-gray-800">
                                        <?php echo ($index + 1) . '. ' . htmlspecialchars($q_text); ?> <span class="text-red-500">*</span>
                                    </label>
                                    <textarea name="answers[<?php echo $qid; ?>]" rows="3" required placeholder="Type your answer here..." class="w-full px-4 py-2 bg-white border rounded-lg text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-[#1A2E6B]"><?php echo htmlspecialchars($existing_ans); ?></textarea>
                                </div>
                            <?php endforeach; ?>

                            <button type="submit" name="submit_all_feedback" class="w-full bg-[#1A2E6B] text-white py-3 rounded-lg font-semibold hover:bg-[#142353] transition shadow-sm text-sm">
                                Submit All Feedback Permanently
                            </button>
                            
                        </form>
                    <?php endif; ?>
                </div>
                

    </div>

            <?php endif; ?>

        </div>

    </main>

</body>
</html>