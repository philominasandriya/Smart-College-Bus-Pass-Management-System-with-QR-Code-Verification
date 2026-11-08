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

// Security check: Must be admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: Login.html");
    exit();
}

// Handle Driver Approval with Bus Assignment & Confirmation
if (isset($_GET['action']) && $_GET['action'] == 'approve_driver_step1' && isset($_GET['id'])) {
    $driver_id = intval($_GET['id']);
    // Redirect to bus selection confirmation view for this driver
    header("Location: admin_dashboard.php?tab=registrations&subaction=assign_bus&id=" . $driver_id);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_assign_bus'])) {
    $driver_id = intval($_POST['driver_id']);
    $assigned_bus = $conn->real_escape_string($_POST['assigned_bus']);
    
    if (!empty($assigned_bus)) {
        // Approve driver and assign bus
        $stmt = $conn->prepare("UPDATE users SET status='approved', bus_number = ? WHERE id = ? AND role = 'driver'");
        $stmt->bind_param("si", $assigned_bus, $driver_id);
        if ($stmt->execute()) {
            $success_msg = "Driver approved and successfully assigned to bus $assigned_bus!";
        } else {
            $error_msg = "Error assigning bus to driver: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_msg = "Please select a valid unassigned bus.";
    }
    header("Location: admin_dashboard.php?tab=registrations");
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

// Handle Publishing Semester Payment Cycles
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['publish_payment_cycle'])) {
    $academic_year = trim($conn->real_escape_string($_POST['academic_year']));
    $semester_period = trim($conn->real_escape_string($_POST['semester_period']));

    if (!empty($academic_year) && !empty($semester_period)) {
        // Strict validation for yyyy-yyyy format (e.g., 2026-2027) with no other letters or characters
        if (!preg_match('/^\d{4}-\d{4}$/', $academic_year)) {
            $error_msg = "Invalid Academic Year format. It must be strictly yyyy-yyyy (e.g., 2026-2027) with no letters or extra characters.";
        } else {
            // Check if this academic year and semester period is already published
            $check_dup = $conn->prepare("SELECT id FROM semester_payment_cycles WHERE academic_year = ? AND semester_period = ? AND status = 'published'");
            $check_dup->bind_param("ss", $academic_year, $semester_period);
            $check_dup->execute();
            $dup_res = $check_dup->get_result();
            
            if ($dup_res && $dup_res->num_rows > 0) {
                $error_msg = "Error: This academic year and semester period has already been published! You cannot publish the same semester twice.";
            } else {
                $stmt = $conn->prepare("INSERT INTO semester_payment_cycles (academic_year, semester_period, status, published_at) VALUES (?, ?, 'published', NOW())");
                $stmt->bind_param("ss", $academic_year, $semester_period);
                if ($stmt->execute()) {
                    $success_msg = "Payment cycle for $academic_year ($semester_period) published successfully!";
                } else {
                    $error_msg = "Error publishing payment cycle: " . $conn->error;
                }
                $stmt->close();
            }
            $check_dup->close();
        }
    } else {
        $error_msg = "Please fill in both academic year and semester period.";
    }
}

// Ensure necessary feedback & questions tables exist
$conn->query("CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'draft',
    published_month VARCHAR(7) DEFAULT NULL,
    published_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS user_feedback_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    question_id INT,
    answer TEXT DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    assigned_month VARCHAR(7) DEFAULT NULL,
    assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure emergency bus passes table exists
$conn->query("CREATE TABLE IF NOT EXISTS emergency_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    failed_bus VARCHAR(100),
    alternative_bus VARCHAR(100),
    valid_until VARCHAR(100),
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure urgent bus pass requests table exists
$conn->query("CREATE TABLE IF NOT EXISTS urgent_pass_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    requested_route VARCHAR(255),
    reason TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$success_msg = "";
$error_msg = "";

// Auto-seed default feedback questions in draft status if none exist
$q_check = $conn->query("SELECT * FROM feedback_questions");
if ($q_check->num_rows == 0) {
    $default_questions = [
        "How satisfied are you with the punctuality and timing of your assigned bus?",
        "Is the bus driver polite, professional, and driving safely?",
        "Is the bus maintained cleanly, and is there adequate seating space?",
        "How would you rate the overall experience and convenience of the Smart Bus Pass system?"
    ];
    foreach ($default_questions as $dq) {
        $stmt_dq = $conn->prepare("INSERT INTO feedback_questions (question_text, status) VALUES (?, 'draft')");
        $stmt_dq->bind_param("s", $dq);
        $stmt_dq->execute();
        $stmt_dq->close();
    }
}

// Handle User Complete Cascade Deletion (Students & Faculty)
if (isset($_GET['action']) && $_GET['action'] == 'delete_user_profile' && isset($_GET['id'])) {
    $target_user_id = intval($_GET['id']);
    
    // 1. Fetch user record to locate profile image, role, and username
    $usr_q = $conn->prepare("SELECT image, role, username FROM users WHERE id = ?");
    $usr_q->bind_param("i", $target_user_id);
    $usr_q->execute();
    $usr_res = $usr_q->get_result();
    
    if ($usr_res->num_rows > 0) {
        $usr_data = $usr_res->fetch_assoc();
        $user_role = $usr_data['role'];
        $user_image = $usr_data['image'];
        $username = $usr_data['username']; // username എടുത്തു വെക്കുന്നു
        
        // Delete physical photo file if it exists in uploads/
        if (!empty($user_image)) {
            $file_path = "uploads/" . $user_image;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
            if (file_exists($user_image)) {
                @unlink($user_image);
            }
        }
        
        // 2. Cascade delete related records across all dependent tables
        $conn->query("DELETE FROM emergency_passes WHERE user_id = $target_user_id");
        $conn->query("DELETE FROM urgent_pass_requests WHERE user_id = $target_user_id");
        $conn->query("DELETE FROM user_feedback_assignments WHERE user_id = $target_user_id");
        
        // complaint ടേബിളിൽ user_id ഇല്ല, username ആണ് ഉള്ളത് അതുകൊണ്ട് username വെച്ച് ഡിലീറ്റ് ചെയ്യുന്നു
        if (!empty($username)) {
            $stmt_comp = $conn->prepare("DELETE FROM complaint WHERE username = ?");
            $stmt_comp->bind_param("s", $username);
            $stmt_comp->execute();
            $stmt_comp->close();
        }
        
        // 3. Finally delete user profile record
        $del_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $del_stmt->bind_param("i", $target_user_id);
        if ($del_stmt->execute()) {
            $success_msg = "User profile and all associated assignment/credential records deleted successfully.";
        } else {
            $error_msg = "Error deleting user profile: " . $conn->error;
        }
        $del_stmt->close();
        
        // Redirect back to appropriate tab
        $redirect_tab = ($user_role == 'faculty') ? 'view_faculty' : 'view_students';
        header("Location: admin_dashboard.php?tab=" . $redirect_tab);
        exit();
    }
    $usr_q->close();
}

// Handle Driver Complete Deletion (ENHANCEMENT 1)
if (isset($_GET['action']) && $_GET['action'] == 'delete_driver_profile' && isset($_GET['id'])) {
    $target_driver_id = intval($_GET['id']);
    
    $drv_q = $conn->prepare("SELECT image FROM users WHERE id = ? AND role = 'driver'");
    $drv_q->bind_param("i", $target_driver_id);
    $drv_q->execute();
    $drv_res = $drv_q->get_result();
    
    if ($drv_res->num_rows > 0) {
        $drv_data = $drv_res->fetch_assoc();
        $drv_image = $drv_data['image'];
        
        if (!empty($drv_image)) {
            $file_path = "uploads/" . $drv_image;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
            if (file_exists($drv_image)) {
                @unlink($drv_image);
            }
        }
        
        // Clean up any warnings associated with this driver
        $conn->query("DELETE FROM driver_warnings WHERE id = $target_driver_id");
        
        // Delete driver record from users table
        $del_drv = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'driver'");
        $del_drv->bind_param("i", $target_driver_id);
        if ($del_drv->execute()) {
            $success_msg = "Driver account and associated records deleted successfully.";
        } else {
            $error_msg = "Error deleting driver profile: " . $conn->error;
        }
        $del_drv->close();
        
        header("Location: admin_dashboard.php?tab=view_drivers");
        exit();
    }
    $drv_q->close();
}

// Handle Editing or Adding Feedback Questions (Full CRUD for draft questions)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_question'])) {
    $q_id = intval($_POST['question_id'] ?? 0);
    $question_text = trim($conn->real_escape_string($_POST['question_text']));

    if (!empty($question_text)) {
        if ($q_id > 0) {
            $stmt = $conn->prepare("UPDATE feedback_questions SET question_text = ? WHERE id = ?");
            $stmt->bind_param("si", $question_text, $q_id);
            if ($stmt->execute()) {
                $success_msg = "Feedback question updated successfully!";
            } else {
                $error_msg = "Error updating question: " . $conn->error;
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO feedback_questions (question_text, status) VALUES (?, 'draft')");
            $stmt->bind_param("s", $question_text);
            if ($stmt->execute()) {
                $success_msg = "New feedback question added successfully!";
            } else {
                $error_msg = "Error adding question: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        $error_msg = "Question text cannot be empty.";
    }
}

// Handle Deleting Feedback Question
if (isset($_GET['action']) && $_GET['action'] == 'delete_question' && $_GET['id']) {
    $qid = intval($_GET['id']);
    $conn->query("DELETE FROM feedback_questions WHERE id=$qid");
    $conn->query("DELETE FROM user_feedback_assignments WHERE question_id=$qid");
    $success_msg = "Feedback question deleted successfully.";
    header("Location: admin_dashboard.php?tab=feedback");
    exit();
}

// Handle Reset / Clear All Published Feedback Assignments
if (isset($_GET['action']) && $_GET['action'] == 'reset_monthly_feedback') {
    $conn->query("DELETE FROM user_feedback_assignments");
    $conn->query("UPDATE feedback_questions SET status='draft', published_month=NULL, published_at=NULL");
    $success_msg = "Successfully reset all published feedback and cleared all user assignments.";
    header("Location: admin_dashboard.php?tab=feedback");
    exit();
}

// Handle Publishing Questions to All Approved Users' Dashboards with Monthly Lock Check
if (isset($_POST['publish_questions'])) {
    $current_month = date('Y-m');
    $check_monthly_lock = $conn->query("SELECT id FROM feedback_questions WHERE published_month = '$current_month' LIMIT 1");
    if ($check_monthly_lock && $check_monthly_lock->num_rows > 0) {
        $error_msg = "Publishing for this month is already done.";
    } else {
        $approved_users = $conn->query("SELECT id FROM users WHERE status='approved' AND role != 'admin'");
        $questions = $conn->query("SELECT id FROM feedback_questions");
        $q_ids = [];
        while($q = $questions->fetch_assoc()) {
            $q_ids[] = $q['id'];
        }

        if (!empty($q_ids)) {
            while($user = $approved_users->fetch_assoc()) {
                $uid = $user['id'];
                foreach($q_ids as $qid) {
                    $check_dup = $conn->query("SELECT id FROM user_feedback_assignments WHERE user_id=$uid AND question_id=$qid");
                    if ($check_dup->num_rows == 0) {
                        $stmt_ins = $conn->prepare("INSERT INTO user_feedback_assignments (user_id, question_id, status, assigned_month) VALUES (?, ?, 'pending', ?)");
                        $stmt_ins->bind_param("iis", $uid, $qid, $current_month);
                        $stmt_ins->execute();
                        $stmt_ins->close();
                    }
                }
            }
            $conn->query("UPDATE feedback_questions SET status='published', published_month='$current_month', published_at=NOW()");
            $success_msg = "Feedback questions successfully submitted.";
        } else {
            $error_msg = "No feedback questions available to publish.";
        }
    }
}

// Handle Publishing Emergency Passes (Restricted to Students and Faculty only with Course/Department filtering and warning support)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['publish_emergency_pass'])) {
    $failed_bus = trim($conn->real_escape_string($_POST['failed_bus']));
    $alternative_bus = trim($conn->real_escape_string($_POST['alternative_bus']));
    $valid_until = trim($conn->real_escape_string($_POST['valid_until']));
    $reason = trim($conn->real_escape_string($_POST['reason']));
    
    // ഫിൽട്ടറിങ് വാല്യൂ ക്യാപ്ചർ ചെയ്യുന്നു
    $filter_department = trim($conn->real_escape_string($_POST['filter_department'] ?? ''));

    if (!empty($failed_bus) && !empty($alternative_bus) && !empty($valid_until) && !empty($reason)) {
        if ($valid_until < date('Y-m-d')) {
            $error_msg = "Validity date cannot be earlier than the current date.";
        } elseif ($failed_bus === $alternative_bus) {
            $error_msg = "Alternative bus cannot be the same as the current bus.";
        } else {
            // ഡിപ്പാർട്ട്മെന്റ്/കോഴ്സ് ഫിൽട്ടർ സെലക്ട് ചെയ്തിട്ടുണ്ടോ എന്ന് നോക്കി ക്വറി തയ്യാറാക്കുന്നു
            // സ്റ്റുഡന്റ്സിന് 'course'-ഉം ഫാക്കൽറ്റിക്ക് 'department'-ഉം രണ്ട് സ്ഥലത്തും പരിശോധിക്കുന്നു
            if (!empty($filter_department)) {
                $dept_param = "%" . $filter_department . "%";
                $stmt_users = $conn->prepare("SELECT id FROM users WHERE status='approved' AND bus_number = ? AND role IN ('student', 'faculty') AND (course LIKE ? OR department LIKE ?)");
                $stmt_users->bind_param("sss", $failed_bus, $dept_param, $dept_param);
            } else {
                // ഫിൽട്ടർ ഇല്ലെങ്കിൽ ആ ബസിലെ മുഴുവൻ വിദ്യാർത്ഥികൾക്കും ഫാക്കൽട്ടികൾക്കും
                $stmt_users = $conn->prepare("SELECT id FROM users WHERE status='approved' AND bus_number = ? AND role IN ('student', 'faculty')");
                $stmt_users->bind_param("s", $failed_bus);
            }

            $stmt_users->execute();
            $users_result = $stmt_users->get_result();
            
            // ആ ബസിൽ/ഫിൽട്ടറിൽ ആൾക്കാർ ആരെങ്കിലും ഉണ്ടോ എന്ന് പരിശോധിക്കുന്നു
            if ($users_result->num_rows > 0) {
                $count = 0;
                while ($usr = $users_result->fetch_assoc()) {
                    $uid = $usr['id'];
                    $stmt_pass = $conn->prepare("INSERT INTO emergency_passes (user_id, failed_bus, alternative_bus, valid_until, reason) VALUES (?, ?, ?, ?, ?)");
                    $stmt_pass->bind_param("issss", $uid, $failed_bus, $alternative_bus, $valid_until, $reason);
                    $stmt_pass->execute();
                    $stmt_pass->close();
                    $count++;
                }
                $success_msg = "Emergency bus pass successfully issued to $count passengers affected by breakdown of $failed_bus!";
            } else {
                // സെലക്ട് ചെയ്ത ഫിൽട്ടർ അനുസരിച്ച് ആ ബസിൽ ആരുമില്ലെങ്കിൽ വാണിംഗ് നൽകുന്നു
                if (!empty($filter_department)) {
                    $error_msg = "Warning: No registered users found in '$filter_department' for $failed_bus. Emergency pass was not issued.";
                } else {
                    $error_msg = "Warning: No approved passengers found assigned to $failed_bus.";
                }
            }
            $stmt_users->close();
        }
    } else {
        $error_msg = "Please fill out all required emergency pass details.";
    }
}

// Handle Urgent Pass Request Approvals / Rejections
if (isset($_GET['action']) && isset($_GET['urgent_id'])) {
    $urgent_id = intval($_GET['urgent_id']);
    $action = $_GET['action'];
    
    if ($action == 'approve_urgent') {
        $conn->query("UPDATE urgent_pass_requests SET status='approved' WHERE id=$urgent_id");
        $success_msg = "Urgent pass request approved successfully.";
    } elseif ($action == 'reject_urgent') {
        $conn->query("UPDATE urgent_pass_requests SET status='rejected' WHERE id=$urgent_id");
        $success_msg = "Urgent pass request rejected.";
    }
    header("Location: admin_dashboard.php?tab=urgent_passes");
    exit();
}

// Handle sending anonymous warning to driver from complaints section
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_driver_warning'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $driver_id = intval($_POST['driver_id']);
    $warning_message = trim($conn->real_escape_string($_POST['warning_message']));

    $conn->query("CREATE TABLE IF NOT EXISTS driver_warnings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT,
        complaint_id INT,
        warning_message TEXT,
        sent_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $conn->prepare("INSERT INTO driver_warnings (driver_id, complaint_id, warning_message) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iis", $driver_id, $complaint_id, $warning_message);
        if ($stmt->execute()) {
            // Warning send cheythal complaint status 'Resolved' aakki update cheyyunnu
            $conn->query("UPDATE complaint SET status = 'Resolved' WHERE id = $complaint_id");
            
            $success_msg = "Anonymous warning sent successfully to the driver and complaint status updated to Resolved!";
        } else {
            $error_msg = "Error sending warning: " . $conn->error;
        }
        $stmt->close();
    }
}

// Fetch logged-in admin's profile picture from database
$admin_profile_query = $conn->query("SELECT * FROM users WHERE role='admin' LIMIT 1");
$admin_data = ($admin_profile_query && $admin_profile_query->num_rows > 0) ? $admin_profile_query->fetch_assoc() : null;

// Handle Driver Account Creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_driver'])) {
    $full_name = trim($conn->real_escape_string($_POST['driver_name']));
    $username = trim($conn->real_escape_string($_POST['driver_username']));
    $email = $conn->real_escape_string($_POST['driver_email'] ?? 'driver@sngce.ac.in');
    $password = password_hash($_POST['driver_password'], PASSWORD_DEFAULT);
    $phone = trim($conn->real_escape_string($_POST['driver_phone']));
    $bus_number = $conn->real_escape_string($_POST['driver_bus']);
    
    if (!preg_match("/^[A-Za-z\s]+$/", $full_name)) {
        $error_msg = "Driver Full Name should contain letters and spaces only.";
    } elseif (!preg_match("/^\d{10}$/", $phone)) {
        $error_msg = "Phone number must be exactly 10 digits.";
    } else {
        $photo_name = "";
        if (isset($_FILES['driver_photo']) && $_FILES['driver_photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $filename = $_FILES['driver_photo']['name'];
            $filetmp = $_FILES['driver_photo']['tmp_name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $photo_name = "driver_" . time() . "_" . rand(1000, 9999) . "." . $ext;
                move_uploaded_file($filetmp, "uploads/" . $photo_name);
            } else {
                $error_msg = "Invalid image format for driver photo. Allowed: JPG, JPEG, PNG, WEBP.";
            }
        }

        if (empty($error_msg)) {
            $check = $conn->query("SELECT * FROM users WHERE username='$username'");
            if ($check->num_rows > 0) {
                $error_msg = "Username already exists! Choose another.";
            } else {
                $sql = "INSERT INTO users (full_name, username, email, password, role, phone, bus_number, image, status) 
                        VALUES ('$full_name', '$username', '$email', '$password', 'driver', '$phone', '$bus_number', '$photo_name', 'approved')";
                if ($conn->query($sql) === TRUE) {
                    $success_msg = "Driver account created successfully!";
                } else {
                    $error_msg = "Error: " . $conn->error;
                }
            }
        }
    }
}

// Handle Route Updates / Creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_route'])) {
    $route_id = intval($_POST['route_id'] ?? 0);
    $bus_no_reg = $conn->real_escape_string($_POST['bus_no_reg']);
    $starting_point = $conn->real_escape_string($_POST['starting_point']);
    $route_details = $conn->real_escape_string($_POST['route_details']);
    $starting_time = $conn->real_escape_string($_POST['starting_time']);
    $note = $conn->real_escape_string($_POST['note']);

    if ($route_id > 0) {
        $sql = "UPDATE bus_routes SET bus_no_reg='$bus_no_reg', starting_point='$starting_point', route_details='$route_details', starting_time='$starting_time', note='$note' WHERE id=$route_id";
        if ($conn->query($sql) === TRUE) {
            $success_msg = "Bus route updated successfully!";
        } else {
            $error_msg = "Error updating route: " . $conn->error;
        }
    } else {
        $sql = "INSERT INTO bus_routes (bus_no_reg, starting_point, route_details, starting_time, note) VALUES ('$bus_no_reg', '$starting_point', '$route_details', '$starting_time', '$note')";
        if ($conn->query($sql) === TRUE) {
            $success_msg = "New bus route added successfully!";
        } else {
            $error_msg = "Error adding route: " . $conn->error;
        }
    }
}

// Handle Route Deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete_route' && $_GET['id']) {
    $rid = intval($_GET['id']);
    $conn->query("DELETE FROM bus_routes WHERE id=$rid");
    header("Location: admin_dashboard.php?tab=routes");
    exit();
}

// Handle Bus Fare Insert and Update Operations
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_fare'])) {
    $fare_id = intval($_POST['fare_id'] ?? 0);
    $bus_no = $conn->real_escape_string($_POST['fare_bus_no']);
    $starting_point = $conn->real_escape_string($_POST['fare_starting_point']);
    $fare_stage = $conn->real_escape_string($_POST['fare_stage']);
    $installment = $conn->real_escape_string($_POST['installment_amount']);
    $consolidated = $conn->real_escape_string($_POST['consolidated_fee']);

    if ($fare_id > 0) {
        $sql = "UPDATE bus_fares SET bus_no='$bus_no', starting_point='$starting_point', fare_stage='$fare_stage', installment_amount='$installment', consolidated_fee='$consolidated' WHERE id=$fare_id";
        if ($conn->query($sql) === TRUE) {
            $success_msg = "Bus fare record updated successfully!";
        } else {
            $error_msg = "Error updating record: " . $conn->error;
        }
    } else {
        $sql = "INSERT INTO bus_fares (bus_no, starting_point, fare_stage, installment_amount, consolidated_fee) VALUES ('$bus_no', '$starting_point', '$fare_stage', '$installment', '$consolidated')";
        if ($conn->query($sql) === TRUE) {
            $success_msg = "New bus fare record added successfully!";
        } else {
            $error_msg = "Error adding record: " . $conn->error;
        }
    }
}

// Handle Bus Fare Deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete_fare' && $_GET['id']) {
    $fid = intval($_GET['id']);
    $conn->query("DELETE FROM bus_fares WHERE id=$fid");
    header("Location: admin_dashboard.php?tab=payments");
    exit();
}

// Handle Approvals / Rejections for registrations
if (isset($_GET['action']) && isset($_GET['id']) && !isset($_GET['urgent_id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action == 'approve') {
        $conn->query("UPDATE users SET status='approved' WHERE id=$id");
    } elseif ($action == 'reject') {
        $conn->query("UPDATE users SET status='rejected' WHERE id=$id");
    }
    header("Location: admin_dashboard.php?tab=registrations");
    exit();
}

$tab = $_GET['tab'] ?? 'registrations';
$pending_users = $conn->query("SELECT * FROM users WHERE status='pending'");

// ENHANCEMENT 2: Fetch Guardian details along with student/faculty profiles
$students = $conn->query("SELECT id, full_name, username, email, course, batch, bus_number, boarding_point, phone, image, guardian_name, guardian_relationship, guardian_phone FROM users WHERE role='student' AND status='approved'");
$faculty = $conn->query("SELECT id, full_name, username, email, department, designation, bus_number, boarding_point, phone, image, guardian_name, guardian_relationship, guardian_phone FROM users WHERE role='faculty' AND status='approved'");

$drivers = $conn->query("SELECT * FROM users WHERE role='driver' AND status='approved'");
$routes_result = $conn->query("SELECT * FROM bus_routes");

$urgent_passes_result = $conn->query("SELECT up.*, COALESCE(u.full_name, up.applicant_name) AS full_name, COALESCE(u.username, 'Hosteller/Guest') AS username, COALESCE(u.role, 'Student') AS role, COALESCE(u.course, up.department) AS course FROM urgent_pass_requests up LEFT JOIN users u ON up.user_id = u.id ORDER BY up.id DESC");
$emergency_passes_result = $conn->query("SELECT ep.*, u.full_name, u.username FROM emergency_passes ep JOIN users u ON ep.user_id = u.id ORDER BY ep.id DESC");


$payment_cycles_result = $conn->query("SELECT * FROM semester_payment_cycles ORDER BY id DESC");
$complaints_query = $conn->query("SHOW TABLES LIKE 'complaint'");
$complaints_result = null;
if ($complaints_query && $complaints_query->num_rows > 0) {
    // Join complaint table with users table and fetch status
    $complaints_result = $conn->query("SELECT c.*, u.full_name AS complainant_name, u.bus_number AS joined_bus 
                                       FROM complaint c 
                                       LEFT JOIN users u ON c.username = u.username 
                                       ORDER BY c.id DESC");
}


$feedback_questions_result = $conn->query("SELECT * FROM feedback_questions ORDER BY id DESC");

// Fetch User Feedback Responses with Optional Bus Filtering
$selected_filter_bus = $_GET['filter_bus'] ?? '';
if (!empty($selected_filter_bus)) {
    $esc_filter_bus = $conn->real_escape_string($selected_filter_bus);
    $feedback_responses_query = "SELECT ufa.*, u.full_name, u.role, u.bus_number, fq.question_text 
                                  FROM user_feedback_assignments ufa 
                                  JOIN users u ON ufa.user_id = u.id 
                                  JOIN feedback_questions fq ON ufa.question_id = fq.id 
                                  WHERE u.bus_number = '$esc_filter_bus' AND ufa.answer IS NOT NULL 
                                  ORDER BY ufa.id DESC";
} else {
    $feedback_responses_query = "SELECT ufa.*, u.full_name, u.role, u.bus_number, fq.question_text 
                                  FROM user_feedback_assignments ufa 
                                  JOIN users u ON ufa.user_id = u.id 
                                  JOIN feedback_questions fq ON ufa.question_id = fq.id 
                                  WHERE ufa.answer IS NOT NULL 
                                  ORDER BY ufa.id DESC";
}
$feedback_responses_result = $conn->query($feedback_responses_query);

$approved_drivers_list = $conn->query("SELECT id, full_name, bus_number FROM users WHERE role='driver' AND status='approved'");

// Auto-seed bus_routes table if empty
if ($routes_result->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS bus_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bus_no_reg VARCHAR(100),
        starting_point VARCHAR(100),
        route_details TEXT,
        starting_time VARCHAR(50),
        note TEXT
    )");
    
    $default_routes = [
        ['1 \n KL40E6327', 'Elamkunnapuzha', 'High court – Kaloor – Palarivattom – Padamughal – NGO Quarters – Kakkanad – Pallikkara – SNGCE', '07.15 am', 'Passengers boarding in between High court and J N Stadium have to board the tourist bus from St. Albans College; both the stops inclusive.'],
        ['3 \n KL40J1150', 'North Paravur', 'Mannam – Karumalloor – U C Collage –kizhakkambalam- Pazhamthottam- SNGCE', '07.20 am', 'Passengers boarding from and up to U C College have to board the tourist bus from Paravur (Bus No.3)']
    ];

    foreach ($default_routes as $dr) {
        $b_reg = $conn->real_escape_string($dr[0]);
        $s_pt = $conn->real_escape_string($dr[1]);
        $r_dt = $conn->real_escape_string($dr[2]);
        $s_tm = $conn->real_escape_string($dr[3]);
        $nt = $conn->real_escape_string($dr[4]);
        $conn->query("INSERT INTO bus_routes (bus_no_reg, starting_point, route_details, starting_time, note) VALUES ('$b_reg', '$s_pt', '$r_dt', '$s_tm', '$nt')");
    }
    $routes_result = $conn->query("SELECT * FROM bus_routes");
}

$fares_result = $conn->query("SELECT * FROM bus_fares");
$student_payments = $conn->query("SELECT * FROM users WHERE role='student' AND status='approved' AND (payment_status='paid' OR payment_status='Completed')");
$faculty_payments = $conn->query("SELECT * FROM users WHERE role='faculty' AND status='approved' AND (payment_status='paid' OR payment_status='Completed')");

$assigned_buses = [];
$driver_buses_result = $conn->query("SELECT bus_number FROM users WHERE role='driver' AND status='approved'");
while($db_row = $driver_buses_result->fetch_assoc()) {
    if(!empty($db_row['bus_number'])) {
        $assigned_buses[] = $db_row['bus_number'];
    }
}


$all_buses = [
    'Bus 1 (KL40E6327)',
    'Bus 3 (KL40J1150)',
    'Bus 4 (KL-40-J-1978)',
    'Bus 5 (KL17B6354)',
    'Bus 6 (KL40J2946)',
    'Bus 7 (KL-40-5927)',
    'Bus 8 (KL-40-6311)',
    'Bus 9 (KL40A4058)',
    'Bus 12 (KL17AA0478)',
    'Bus 14 (KL17AA0443)',
    '49 Seater Tourist Bus - KL67B2270',
    '26 Seater Tempo Traveller - KL667463',
    '26 Seater Tempo Traveller - KL33F9504',
    '26 Seater Tempo Traveller - KL35D7746',
    '26 Seater Tempo Traveller - KL46N2849'
];

// Department Filtering Logic (Flexible Search)
$selected_department = $_GET['course'] ?? '';
$allowed_departments = [
    "Civil Engineering",
    "Mechanical Engineering",
    "Electrical Engineering",
    "CSE",
    "Naval",
    "MCA",
    "MBA",
];

$filtered_students_result = null;
if (!empty($selected_department) && in_array($selected_department, $allowed_departments)) {
    // Using LIKE allows partial matches in case of subtle naming variations in the database
    $search_param = "%" . $selected_department . "%";
    $stmt_filter = $conn->prepare("SELECT * FROM users WHERE course LIKE ? AND (LOWER(role) = 'student' OR role IS NULL) ORDER BY full_name ASC");
    $stmt_filter->bind_param("s", $search_param);
    $stmt_filter->execute();
    $filtered_students_result = $stmt_filter->get_result();
    $stmt_filter->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Smart College Bus Pass</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        function viewLicense(imageSrc, driverName) {
        const modal = document.getElementById('licenseModal');
        const modalImage = document.getElementById('modalLicenseImage');
        const modalTitle = document.getElementById('modalDriverTitle');
        const noLicenseText = document.getElementById('noLicenseText');
        
        if (imageSrc && imageSrc.trim() !== '') {
            // Handle if the path already includes 'uploads/' or not
            modalImage.src = imageSrc.startsWith('uploads/') ? imageSrc : "uploads/" + imageSrc;
            modalImage.style.display = "block";
            if (noLicenseText) noLicenseText.style.display = "none";
        } else {
            modalImage.style.display = "none";
            if (noLicenseText) noLicenseText.style.display = "block";
        }
        
        if (modalTitle) modalTitle.innerText = "Driving License - " + driverName;
        if (modal) modal.classList.remove('hidden');
    }

    function closeLicenseModal() {
        const modal = document.getElementById('licenseModal');
        if (modal) modal.classList.add('hidden');
    }
    // JavaScript confirmation handler for profile deletion / discontinuation
    function confirmUserDeletion(userName, role) {
        let msg = `WARNING: Are you sure you want to discontinue and completely delete ${userName}'s profile (${role})?\n\nThis will permanently remove:\n- User profile & login credentials\n- Assignment & pass history\n- Uploaded photos & documents\n\nThis action CANNOT be undone!`;
        return confirm(msg);
    }

    // Confirmation handler for driver deletion (ENHANCEMENT 1)
    function confirmDriverDeletion(driverName) {
        let msg = `WARNING: Are you sure you want to completely delete driver ${driverName}?\n\nThis will remove their driver account, login credentials, and unassign their bus.\n\nThis action CANNOT be undone!`;
        return confirm(msg);
    }

    // Dynamic filtering for Alternative Bus Dropdown in Emergency Pass
    function updateAlternativeBusOptions() {
        const currentBusSelect = document.getElementById('failed_bus_select');
        const altBusSelect = document.getElementById('alternative_bus_select');
        
        if (!currentBusSelect || !altBusSelect) return;
        
        const selectedCurrentBus = currentBusSelect.value;
        
        for (let i = 0; i < altBusSelect.options.length; i++) {
            altBusSelect.options[i].hidden = false;
            altBusSelect.options[i].disabled = false;
        }
        
        if (selectedCurrentBus) {
            for (let i = 0; i < altBusSelect.options.length; i++) {
                if (altBusSelect.options[i].value === selectedCurrentBus) {
                    altBusSelect.options[i].hidden = true;
                    altBusSelect.options[i].disabled = true;
                }
            }
            if (altBusSelect.value === selectedCurrentBus) {
                altBusSelect.value = "";
            }
        }
    }
    </script>
</head>
<body class="bg-[#F8F5F0] font-sans flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-[#1A2E6B] text-white flex flex-col justify-between shadow-lg h-screen overflow-y-auto">
        <div>
            <div class="p-6 text-center border-b border-white/10">
                <h1 class="font-bold text-lg tracking-wider">ADMINISTRATOR</h1>
                <p class="text-xs text-gray-300 mt-1">SNGCE Bus Pass System</p>
            </div>
            <nav class="mt-4 space-y-1">
                <a href="admin_dashboard.php?tab=registrations" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'registrations') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-user-check w-5 mr-3"></i> Registrations
                </a>
                
                <a href="admin_dashboard.php?tab=view_students" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'view_students') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-graduation-cap w-5 mr-3"></i> Students Profile
                </a>
                <a href="admin_dashboard.php?tab=view_faculty" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'view_faculty') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-chalkboard-user w-5 mr-3"></i> Faculty Profile
                </a>
                <a href="admin_dashboard.php?tab=view_drivers" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'view_drivers') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-bus w-5 mr-3"></i> Drivers Profile
                </a>
                <a href="admin_dashboard.php?tab=routes" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'routes') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-route w-5 mr-3"></i> Bus Routes
                </a>
                <a href="admin_dashboard.php?tab=payments" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'payments') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-credit-card w-5 mr-3"></i> Fares Table
                </a>
              
                <a href="admin_dashboard.php?tab=paid_unpaid_users" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'paid_unpaid_users') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-users-viewfinder w-5 mr-3"></i> Paid & Unpaid Students
                </a>
                <a href="admin_dashboard.php?tab=urgent_passes" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'urgent_passes') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-bolt w-5 mr-3"></i> Urgent Pass Requests
                </a>
                <a href="admin_dashboard.php?tab=emergency_passes" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'emergency_passes') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-ticket-simple w-5 mr-3"></i> Emergency Pass 
                </a>
                <a href="admin_dashboard.php?tab=feedback" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'feedback') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-comments w-5 mr-3"></i> Feedback Management
                </a>
                <a href="admin_dashboard.php?tab=payment_publish" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'payment_publish') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
    <i class="fa-solid fa-calendar-days w-5 mr-3"></i> Payment Publish
</a>
                <a href="admin_dashboard.php?tab=complaints" class="flex items-center px-6 py-3 text-sm transition hover:bg-[#142353] <?php echo ($tab == 'complaints') ? 'bg-[#142353] border-l-4 border-white font-semibold' : 'text-gray-300'; ?>">
                    <i class="fa-solid fa-triangle-exclamation w-5 mr-3"></i> Complaints & Warnings
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
        <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
            <h2 class="text-xl font-bold text-[#1A2E6B] capitalize"><?php echo str_replace('_', ' ', $tab); ?></h2>
            <div class="flex items-center space-x-3">
                <span class="text-sm font-medium text-gray-600">
                    <?php echo htmlspecialchars($admin_data['full_name'] ?? 'Administrator'); ?>
                </span>
                <?php if (!empty($admin_data['image'])): ?>
                    <img src="<?php echo htmlspecialchars($admin_data['image']); ?>" class="h-9 w-9 rounded-full object-cover border border-gray-300 shadow-sm" alt="Admin Profile">
                <?php else: ?>
                    <div class="h-9 w-9 bg-[#1A2E6B] text-white rounded-full flex items-center justify-center font-bold text-sm">A</div>
                <?php endif; ?>
            </div>
        </header>

        <div class="p-8">
            <!-- Alerts -->
            <?php if(!empty($success_msg)): ?>
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

           <!-- PENDING REGISTRATIONS -->
            <?php if ($tab == 'registrations'): ?>
                
                <?php 
                // Fetch pending students & faculty
                $pending_students_faculty = $conn->query("SELECT * FROM users WHERE status='pending' AND role IN ('student', 'faculty')");
                // Fetch pending drivers
                $pending_drivers = $conn->query("SELECT * FROM users WHERE status='pending' AND role='driver'");
                
                $subaction = $_GET['subaction'] ?? '';
                $assign_driver_id = intval($_GET['id'] ?? 0);
                ?>

                <?php if ($subaction == 'assign_bus' && $assign_driver_id > 0): ?>
                    <!-- Step 2: Confirm Bus Assignment for Driver -->
                    <?php 
                    $drv_info_q = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role='driver'");
                    $drv_info_q->bind_param("i", $assign_driver_id);
                    $drv_info_q->execute();
                    $drv_info_res = $drv_info_q->get_result();
                    $drv_name = ($drv_info_res->num_rows > 0) ? $drv_info_res->fetch_assoc()['full_name'] : 'Driver';
                    $drv_info_q->close();
                    
                    // Fetch currently taken buses by other approved drivers
                    $taken_buses = [];
                    $tb_res = $conn->query("SELECT bus_number FROM users WHERE role='driver' AND status='approved' AND bus_number IS NOT NULL AND bus_number != ''");
                    while($tb_row = $tb_res->fetch_assoc()) {
                        $taken_buses[] = $tb_row['bus_number'];
                    }
                    ?>
                    <div class="max-w-xl mx-auto bg-white p-8 rounded-xl shadow-md border border-gray-200">
                        <h3 class="text-lg font-bold text-[#1A2E6B] mb-2">Assign Bus to Driver: <?php echo htmlspecialchars($drv_name); ?></h3>
                        <p class="text-xs text-gray-500 mb-6">Select an available unassigned bus.</p>
                        
                        <form action="admin_dashboard.php?tab=registrations" method="POST" onsubmit="return confirm('Are you sure you want to finalize and confirm this bus assignment for the driver?');" class="space-y-4">
                            <input type="hidden" name="driver_id" value="<?php echo $assign_driver_id; ?>">
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Select Unassigned Bus <span class="text-red-500">*</span></label>
                                <select name="assigned_bus" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border border-gray-300 rounded-lg text-sm">
                                    <option value="">-- Choose Unassigned Bus --</option>
                                    <?php foreach($all_buses as $bus_opt): ?>
                                        <?php if(!in_array($bus_opt, $taken_buses)): ?>
                                            <option value="<?php echo htmlspecialchars($bus_opt); ?>"><?php echo htmlspecialchars($bus_opt); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex justify-end space-x-3 pt-4">
                                <a href="admin_dashboard.php?tab=registrations" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold">Cancel</a>
                                <button type="submit" name="confirm_assign_bus" class="bg-green-600 text-white px-6 py-2 rounded-lg text-sm font-semibold hover:bg-green-700 transition">Confirm & Assign Bus</button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Table 1: Students & Faculty Pending Registrations -->
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden mb-8">
                        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                            <h3 class="font-bold text-[#1A2E6B]">Pending Student & Faculty Registrations</h3>
                            <span class="bg-blue-100 text-blue-800 text-xs px-3 py-1 rounded-full font-semibold">Students & Faculty</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase tracking-wider">
                                        <th class="py-3 px-4">Photo</th>
                                        <th class="py-3 px-4">Full Name</th>
                                        <th class="py-3 px-4">Role</th>
                                        <th class="py-3 px-4">Academic / Professional Details</th>
                                        <th class="py-3 px-4">Bus & Boarding</th>
                                        <th class="py-3 px-4">Contact & Guardian Info</th>
                                        <th class="py-3 px-4 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-sm text-gray-700">
                                    <?php if ($pending_students_faculty && $pending_students_faculty->num_rows > 0): ?>
                                        <?php while($row = $pending_students_faculty->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="py-3 px-4">
                                                    <?php if(!empty($row['image'])): ?>
                                                        <img src="uploads/<?php echo htmlspecialchars($row['image']); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-300">
                                                    <?php else: ?>
                                                        <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user"></i></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                                    <div class="text-xs text-gray-500">@<?php echo htmlspecialchars($row['username']); ?></div>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <span class="px-2.5 py-1 text-xs rounded-full font-semibold <?php echo ($row['role'] == 'student') ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'; ?>">
                                                        <?php echo ucfirst(htmlspecialchars($row['role'])); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4 text-xs">
                                                    <?php if(strtolower($row['role']) == 'student'): ?>
                                                        <div>Course: <?php echo htmlspecialchars($row['course']); ?></div>
                                                        <div>Batch: <?php echo htmlspecialchars($row['batch']); ?></div>
                                                    <?php else: ?>
                                                        <div>Dept: <?php echo htmlspecialchars($row['department']); ?></div>
                                                        <div>Designation: <?php echo htmlspecialchars($row['designation']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-4 text-xs">
                                                    <div>Bus No: <span class="font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($row['bus_number']); ?></span></div>
                                                    <div>Boarding: <?php echo htmlspecialchars($row['boarding_point']); ?></div>
                                                </td>
                                                <td class="py-3 px-4 text-xs">
                                                    <div><i class="fa-solid fa-phone text-xs mr-1 text-gray-400"></i> <?php echo htmlspecialchars($row['phone']); ?></div>
                                                    <?php if(!empty($row['guardian_name'])): ?>
                                                        <div class="mt-1 text-gray-600 font-medium">Guardian: <?php echo htmlspecialchars($row['guardian_name']); ?> (<?php echo htmlspecialchars($row['guardian_relationship']); ?> - <?php echo htmlspecialchars($row['guardian_phone']); ?>)</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-4 text-center">
                                                    <div class="flex justify-center space-x-2">
                                                        <a href="admin_dashboard.php?action=approve&id=<?php echo $row['id']; ?>" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-green-700 transition shadow-sm">Approve</a>
                                                        <a href="admin_dashboard.php?action=reject&id=<?php echo $row['id']; ?>" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition shadow-sm">Reject</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No pending student or faculty registrations found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                 <!-- Table 2: Drivers Pending Registrations -->
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                            <h3 class="font-bold text-[#1A2E6B]">Pending Driver Registrations</h3>
                            <span class="bg-amber-100 text-amber-800 text-xs px-3 py-1 rounded-full font-semibold">Drivers</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase tracking-wider">
                                        <th class="py-3 px-4">Photo</th>
                                        <th class="py-3 px-4">Full Name</th>
                                        <th class="py-3 px-4">Role</th>
                                        <th class="py-3 px-4">Contact Number</th>
                                        <th class="py-3 px-4">Driving License</th>
                                        <th class="py-3 px-4 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 text-sm text-gray-700">
                                    <?php if ($pending_drivers && $pending_drivers->num_rows > 0): ?>
                                        <?php while($d_row = $pending_drivers->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="py-3 px-4">
                                                    <?php if(!empty($d_row['image'])): ?>
                                                        <img src="uploads/<?php echo htmlspecialchars($d_row['image']); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-300">
                                                    <?php else: ?>
                                                        <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user"></i></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-4 font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($d_row['full_name']); ?>
                                                    <div class="text-xs text-gray-500 font-normal">@<?php echo htmlspecialchars($d_row['username']); ?></div>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <span class="px-2.5 py-1 text-xs rounded-full font-semibold bg-emerald-100 text-emerald-800">
                                                        <?php echo ucfirst(htmlspecialchars($d_row['role'])); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4 text-xs font-medium">
                                                    <i class="fa-solid fa-phone text-xs mr-1 text-gray-400"></i> <?php echo htmlspecialchars($d_row['phone']); ?>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <?php 
                                                    $license_file = trim($d_row['driving_license'] ?? '');
                                                    ?>
                                                    <?php if(!empty($license_file)): ?>
                                                        <button type="button" onclick="viewLicense('<?php echo htmlspecialchars($license_file, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($d_row['full_name'], ENT_QUOTES); ?>')" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-indigo-700 transition shadow-sm inline-flex items-center">
                                                            <i class="fa-solid fa-id-card mr-1.5"></i> View License
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400 italic">Not Uploaded</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3 px-4 text-center">
                                                    <div class="flex justify-center space-x-2">
                                                        <a href="admin_dashboard.php?tab=registrations&action=approve_driver_step1&id=<?php echo $d_row['id']; ?>" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-green-700 transition shadow-sm">Approve & Assign Bus</a>
                                                        <a href="admin_dashboard.php?action=reject&id=<?php echo $d_row['id']; ?>" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition shadow-sm">Reject</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="py-8 text-center text-gray-500 italic">No pending driver registrations found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <!-- Driving License Modal Viewer -->
                <div id="licenseModal" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center p-4 z-50">
                    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full p-6 relative flex flex-col items-center">
                        <div class="w-full flex justify-between items-center mb-4 border-b pb-3">
                            <h3 id="modalDriverTitle" class="text-lg font-bold text-[#1A2E6B]">Driving License</h3>
                            <button type="button" onclick="closeLicenseModal()" class="text-gray-500 hover:text-gray-700 text-xl font-bold">&times;</button>
                        </div>
                        <div class="w-full max-h-[70vh] flex items-center justify-center overflow-auto my-2 bg-gray-100 rounded-lg p-2">
                            <img id="modalLicenseImage" src="" alt="Driving License Document" class="max-h-[60vh] object-contain rounded border shadow-sm">
                            <p id="noLicenseText" class="text-gray-500 italic hidden py-8">No driving license document uploaded for this driver.</p>
                        </div>
                        <div class="w-full flex justify-end pt-4 border-t mt-2">
                            <button type="button" onclick="closeLicenseModal()" class="bg-gray-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-gray-700 transition flex items-center shadow-sm">
                                <i class="fa-solid fa-arrow-left mr-2"></i> Back
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            
            
                <script>
                function togglePassword() {
                    const passwordInput = document.getElementById('driver_password');
                    const eyeIcon = document.getElementById('eyeIcon');
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        eyeIcon.classList.remove('fa-eye');
                        eyeIcon.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        eyeIcon.classList.remove('fa-eye-slash');
                        eyeIcon.classList.add('fa-eye');
                    }
                }
                </script>

            <!-- VIEW STUDENTS PROFILE (WITH GUARDIAN DETAILS & DELETE BUTTON) -->
            <?php elseif ($tab == 'view_students'): ?>
                <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Approved Students Directory</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                    <th class="p-3">Photo</th>
                                    <th class="p-3">Name</th>
                                    <th class="p-3">Email</th>
                                    <th class="p-3">Course / Batch</th>
                                    <th class="p-3">Bus No & Boarding</th>
                                    <th class="p-3">Contact</th>
                                    <th class="p-3">Guardian Details</th>
                                    <th class="p-3 text-center">Actions / Discontinue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php while($s = $students->fetch_assoc()): ?>
                                    <tr>
                                        <td class="p-3">
                                            <?php if(!empty($s['image'])): ?>
                                                <img src="uploads/<?php echo htmlspecialchars($s['image']); ?>" class="w-9 h-9 rounded-full object-cover border">
                                            <?php else: ?>
                                                <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user text-xs"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 font-bold text-gray-900"><?php echo htmlspecialchars($s['full_name']); ?></td>
                                        <td class="p-3 text-gray-600 text-xs"><?php echo htmlspecialchars($s['email']); ?></td>
                                        <td class="p-3 text-xs"><?php echo htmlspecialchars($s['course']); ?> (<?php echo htmlspecialchars($s['batch']); ?>)</td>
                                        <td class="p-3 text-xs"><span class="font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($s['bus_number']); ?></span><br><?php echo htmlspecialchars($s['boarding_point']); ?></td>
                                        <td class="p-3 text-xs"><?php echo htmlspecialchars($s['phone']); ?></td>
                                        <td class="p-3 text-xs">
                                            <?php if(!empty($s['guardian_name'])): ?>
                                                <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($s['guardian_name']); ?></div>
                                                <div class="text-gray-500">Rel: <?php echo htmlspecialchars($s['guardian_relationship']); ?></div>
                                                <div class="text-gray-500">Ph: <?php echo htmlspecialchars($s['guardian_phone']); ?></div>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not Provided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 text-center">
                                            <a href="admin_dashboard.php?tab=view_students&action=delete_user_profile&id=<?php echo $s['id']; ?>" 
                                               onclick="return confirmUserDeletion('<?php echo addslashes($s['full_name']); ?>', 'Student');" 
                                               class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition shadow-sm inline-flex items-center">
                                                <i class="fa-solid fa-trash-can mr-1.5"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- VIEW FACULTY PROFILE (WITH GUARDIAN DETAILS & DELETE BUTTON) -->
            <?php elseif ($tab == 'view_faculty'): ?>
                <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Approved Faculty Directory</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                    <th class="p-3">Photo</th>
                                    <th class="p-3">Name</th>
                                    <th class="p-3">Email</th>
                                    <th class="p-3">Department / Designation</th>
                                    <th class="p-3">Bus No & Boarding</th>
                                    <th class="p-3">Contact</th>
                                
                                    <th class="p-3 text-center">Actions / Discontinue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php while($f = $faculty->fetch_assoc()): ?>
                                    <tr>
                                        <td class="p-3">
                                            <?php if(!empty($f['image'])): ?>
                                                <img src="uploads/<?php echo htmlspecialchars($f['image']); ?>" class="w-9 h-9 rounded-full object-cover border">
                                            <?php else: ?>
                                                <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user text-xs"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 font-bold text-gray-900"><?php echo htmlspecialchars($f['full_name']); ?></td>
                                        <td class="p-3 text-gray-600 text-xs"><?php echo htmlspecialchars($f['email']); ?></td>
                                        <td class="p-3 text-xs"><?php echo htmlspecialchars($f['department']); ?> - <?php echo htmlspecialchars($f['designation']); ?></td>
                                        <td class="p-3 text-xs"><span class="font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($f['bus_number']); ?></span><br><?php echo htmlspecialchars($f['boarding_point']); ?></td>
                                        <td class="p-3 text-xs"><?php echo htmlspecialchars($f['phone']); ?></td>
                                        
                                        <td class="p-3 text-center">
                                            <a href="admin_dashboard.php?tab=view_faculty&action=delete_user_profile&id=<?php echo $f['id']; ?>" 
                                               onclick="return confirmUserDeletion('<?php echo addslashes($f['full_name']); ?>', 'Faculty');" 
                                               class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition shadow-sm inline-flex items-center">
                                                <i class="fa-solid fa-trash-can mr-1.5"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

           <!-- VIEW DRIVERS PROFILE (WITH DRIVING LICENSE BUTTON) -->
            <?php elseif ($tab == 'view_drivers'): ?>
                <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Drivers Directory</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                    <th class="p-3">Photo</th>
                                    <th class="p-3">Driver Name</th>
                                    <th class="p-3">Username</th>
                                    <th class="p-3">Assigned Bus No</th>
                                    <th class="p-3">Phone</th>
                                    <th class="p-3">Driving License</th>
                                    <th class="p-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php while($d = $drivers->fetch_assoc()): ?>
                                    <tr>
                                        <td class="p-3">
                                            <?php if(!empty($d['image'])): ?>
                                                <img src="uploads/<?php echo htmlspecialchars($d['image']); ?>" class="w-9 h-9 rounded-full object-cover border">
                                            <?php else: ?>
                                                <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center text-gray-500"><i class="fa-solid fa-user text-xs"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 font-bold"><?php echo htmlspecialchars($d['full_name']); ?></td>
                                        <td class="p-3 text-gray-500">@<?php echo htmlspecialchars($d['username']); ?></td>
                                        <td class="p-3 font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($d['bus_number']); ?></td>
                                        <td class="p-3"><?php echo htmlspecialchars($d['phone']); ?></td>
                                        <td class="p-3">
                                            <?php 
                                            $license_file = trim($d['driving_license'] ?? '');
                                            ?>
                                            <?php if(!empty($license_file)): ?>
                                                <button type="button" onclick="viewLicense('<?php echo htmlspecialchars($license_file, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($d['full_name'], ENT_QUOTES); ?>')" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-indigo-700 transition shadow-sm inline-flex items-center">
                                                    <i class="fa-solid fa-id-card mr-1.5"></i> View License
                                                </button>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400 italic">Not Uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 text-center">
                                            <a href="admin_dashboard.php?tab=view_drivers&action=delete_driver_profile&id=<?php echo $d['id']; ?>" 
                                               onclick="return confirmDriverDeletion('<?php echo addslashes($d['full_name']); ?>');" 
                                               class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition shadow-sm inline-flex items-center">
                                                <i class="fa-solid fa-trash-can mr-1.5"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Driving License Modal Viewer (Needed for the modal to display properly here) -->
                <div id="licenseModal" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center p-4 z-50">
                    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full p-6 relative flex flex-col items-center">
                        <div class="w-full flex justify-between items-center mb-4 border-b pb-3">
                            <h3 id="modalDriverTitle" class="text-lg font-bold text-[#1A2E6B]">Driving License</h3>
                            <button type="button" onclick="closeLicenseModal()" class="text-gray-500 hover:text-gray-700 text-xl font-bold">&times;</button>
                        </div>
                        <div class="w-full max-h-[70vh] flex items-center justify-center overflow-auto my-2 bg-gray-100 rounded-lg p-2">
                            <img id="modalLicenseImage" src="" alt="Driving License Document" class="max-h-[60vh] object-contain rounded border shadow-sm">
                            <p id="noLicenseText" class="text-gray-500 italic hidden py-8">No driving license document uploaded for this driver.</p>
                        </div>
                        <div class="w-full flex justify-end pt-4 border-t mt-2">
                            <button type="button" onclick="closeLicenseModal()" class="bg-gray-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-gray-700 transition flex items-center shadow-sm">
                                <i class="fa-solid fa-arrow-left mr-2"></i> Back
                            </button>
                        </div>
                    </div>
                </div>

            <!-- BUS ROUTES MANAGEMENT -->
            <?php elseif ($tab == 'routes'): ?>
                <div class="space-y-6">
                    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-md border border-gray-200">
                        <h3 class="text-lg font-bold text-[#1A2E6B]">Bus Routes & Schedules Management</h3>
                        <button onclick="openRouteModal()" class="bg-[#1A2E6B] text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-[#142353] transition shadow-sm">
                            <i class="fa-solid fa-plus mr-2"></i> Add New Route
                        </button>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">Bus No. & Reg. No.</th>
                                        <th class="p-3">Starting Point</th>
                                        <th class="p-3">Route Details / Path</th>
                                        <th class="p-3">Starting Time</th>
                                        <th class="p-3 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php while($rt = $routes_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3 font-bold text-[#1A2E6B] whitespace-pre-line"><?php echo htmlspecialchars($rt['bus_no_reg']); ?></td>
                                            <td class="p-3 font-medium text-gray-900"><?php echo htmlspecialchars($rt['starting_point']); ?></td>
                                            <td class="p-3 text-gray-700 text-xs"><?php echo htmlspecialchars($rt['route_details']); ?></td>
                                            <td class="p-3 font-semibold text-gray-800"><?php echo htmlspecialchars($rt['starting_time']); ?></td>
                                            <td class="p-3 text-center">
                                                <div class="flex justify-center space-x-2">
                                                    <button onclick='editRoute(<?php echo json_encode($rt); ?>)' class="bg-blue-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>
                                                    <a href="admin_dashboard.php?tab=routes&action=delete_route&id=<?php echo $rt['id']; ?>" onclick="return confirm('Delete this route?');" class="bg-red-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Add/Edit Route Modal -->
                <div id="routeModal" class="fixed inset-0 bg-black/50 hidden flex items-center justify-center p-4 z-50">
                    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 relative">
                        <h3 id="modalTitle" class="text-lg font-bold text-[#1A2E6B] mb-4">Add New Bus Route</h3>
                        <form action="admin_dashboard.php?tab=routes" method="POST" class="space-y-4">
                            <input type="hidden" id="route_id" name="route_id" value="0">
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Bus No. & Reg. No.</label>
                                <input type="text" id="bus_no_reg" name="bus_no_reg" required class="w-full px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Starting Point</label>
                                <input type="text" id="starting_point" name="starting_point" required class="w-full px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Route Path Details</label>
                                <textarea id="route_details" name="route_details" rows="3" required class="w-full px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Starting Time</label>
                                <input type="text" id="starting_time" name="starting_time" required class="w-full px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Special Note</label>
                                <input type="text" id="note" name="note" class="w-full px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm">
                            </div>
                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" onclick="closeRouteModal()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold">Cancel</button>
                                <button type="submit" name="save_route" class="bg-[#1A2E6B] text-white px-4 py-2 rounded-lg text-sm font-semibold">Save Route</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                function openRouteModal() {
                    document.getElementById('modalTitle').innerText = "Add New Bus Route";
                    document.getElementById('route_id').value = "0";
                    document.getElementById('bus_no_reg').value = "";
                    document.getElementById('starting_point').value = "";
                    document.getElementById('route_details').value = "";
                    document.getElementById('starting_time').value = "";
                    document.getElementById('note').value = "";
                    document.getElementById('routeModal').classList.remove('hidden');
                }
                function editRoute(rt) {
                    document.getElementById('modalTitle').innerText = "Edit Bus Route";
                    document.getElementById('route_id').value = rt.id;
                    document.getElementById('bus_no_reg').value = rt.bus_no_reg;
                    document.getElementById('starting_point').value = rt.starting_point;
                    document.getElementById('route_details').value = rt.route_details;
                    document.getElementById('starting_time').value = rt.starting_time;
                    document.getElementById('note').value = rt.note;
                    document.getElementById('routeModal').classList.remove('hidden');
                }
                function closeRouteModal() { document.getElementById('routeModal').classList.add('hidden'); }
                </script>

            <!-- PAYMENTS & FARES MANAGEMENT -->
            <?php elseif ($tab == 'payments'): ?>
                <div class="space-y-6">
                    <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-md border border-gray-200">
                        <h3 class="text-lg font-bold text-[#1A2E6B]">Comprehensive Fee Table Management</h3>
                        <button onclick="openFareModal()" class="bg-[#1A2E6B] text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-[#142353] transition shadow-sm">
                            <i class="fa-solid fa-plus mr-2"></i> Add New Fare Entry
                        </button>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                        <div class="overflow-x-auto max-h-[60vh]">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead class="sticky top-0 bg-gray-100 z-10">
                                    <tr class="text-gray-700 text-xs uppercase">
                                        <th class="p-3">Bus No.</th>
                                        <th class="p-3">Starting Point</th>
                                        <th class="p-3">Fare Stage</th>
                                        <th class="p-3">Installment</th>
                                        <th class="p-3">Consolidated Fee</th>
                                        <th class="p-3 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php while($fr = $fares_result->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50 transition">
                                            <td class="p-3 font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($fr['bus_no']); ?></td>
                                            <td class="p-3"><?php echo htmlspecialchars($fr['starting_point']); ?></td>
                                            <td class="p-3 font-medium text-gray-900"><?php echo htmlspecialchars($fr['fare_stage']); ?></td>
                                            <td class="p-3 text-gray-700"><?php echo htmlspecialchars($fr['installment_amount']); ?></td>
                                            <td class="p-3 font-semibold text-green-700"><?php echo htmlspecialchars($fr['consolidated_fee']); ?></td>
                                            <td class="p-3 text-center">
                                                <div class="flex justify-center space-x-2">
                                                    <button onclick='editFare(<?php echo json_encode($fr); ?>)' class="bg-blue-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>
                                                    <a href="admin_dashboard.php?tab=payments&action=delete_fare&id=<?php echo $fr['id']; ?>" onclick="return confirm('Delete this record?');" class="bg-red-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Add/Edit Fare Modal -->
                <div id="fareModal" class="fixed inset-0 bg-black/50 hidden flex items-center justify-center p-4 z-50">
                    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 relative">
                        <h3 id="fareModalTitle" class="text-lg font-bold text-[#1A2E6B] mb-4">Add Bus Fare Entry</h3>
                        <form action="admin_dashboard.php?tab=payments" method="POST" class="space-y-4">
                            <input type="hidden" id="fare_id" name="fare_id" value="0">
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Bus Number</label>
                                <input type="text" id="fare_bus_no" name="fare_bus_no" required class="w-full px-4 py-2 border rounded-lg text-sm bg-[#F8F5F0]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Starting Point</label>
                                <input type="text" id="fare_starting_point" name="fare_starting_point" required class="w-full px-4 py-2 border rounded-lg text-sm bg-[#F8F5F0]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Fare Stage</label>
                                <input type="text" id="fare_stage" name="fare_stage" required class="w-full px-4 py-2 border rounded-lg text-sm bg-[#F8F5F0]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Installment Amount</label>
                                <input type="text" id="installment_amount" name="installment_amount" required class="w-full px-4 py-2 border rounded-lg text-sm bg-[#F8F5F0]">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Consolidated Semester Fee</label>
                                <input type="text" id="consolidated_fee" name="consolidated_fee" required class="w-full px-4 py-2 border rounded-lg text-sm bg-[#F8F5F0]">
                            </div>
                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" onclick="closeFareModal()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm font-semibold">Cancel</button>
                                <button type="submit" name="save_fare" class="bg-[#1A2E6B] text-white px-4 py-2 rounded-lg text-sm font-semibold">Save Record</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                function openFareModal() {
                    document.getElementById('fareModalTitle').innerText = "Add Bus Fare Entry";
                    document.getElementById('fare_id').value = "0";
                    document.getElementById('fare_bus_no').value = "";
                    document.getElementById('fare_starting_point').value = "";
                    document.getElementById('fare_stage').value = "";
                    document.getElementById('installment_amount').value = "";
                    document.getElementById('consolidated_fee').value = "";
                    document.getElementById('fareModal').classList.remove('hidden');
                }
                function editFare(fr) {
                    document.getElementById('fareModalTitle').innerText = "Edit Bus Fare Entry";
                    document.getElementById('fare_id').value = fr.id;
                    document.getElementById('fare_bus_no').value = fr.bus_no;
                    document.getElementById('fare_starting_point').value = fr.starting_point;
                    document.getElementById('fare_stage').value = fr.fare_stage;
                    document.getElementById('installment_amount').value = fr.installment_amount;
                    document.getElementById('consolidated_fee').value = fr.consolidated_fee;
                    document.getElementById('fareModal').classList.remove('hidden');
                }
                function closeFareModal() { document.getElementById('fareModal').classList.add('hidden'); }
                </script>

           
<?php
           $db_buses = [];
$res_u = $conn->query("SELECT DISTINCT bus_number FROM users WHERE bus_number IS NOT NULL AND bus_number != ''");
if ($res_u) {
    while($r = $res_u->fetch_assoc()) {
        $db_buses[] = $r['bus_number'];
    }
}
$res_r = $conn->query("SELECT DISTINCT bus_no_reg FROM bus_routes WHERE bus_no_reg IS NOT NULL AND bus_no_reg != ''");
if ($res_r) {
    while($r = $res_r->fetch_assoc()) {
        $db_buses[] = $r['bus_no_reg'];
    }
}

$default_buses = [
    'Bus 1 (KL40E6327)',
    'Bus 3 (KL40J1150)',
    'Bus 4 (KL-40-J-1978)',
    'Bus 5 (KL17B6354)',
    'Bus 6 (KL40J2946)',
    'Bus 7 (KL-40-5927)',
    'Bus 8 (KL-40-6311)',
    'Bus 9 (KL40A4058)',
    'Bus 12 (KL17AA0478)',
    'Bus 14 (KL17AA0443)',
    '49 Seater Tourist Bus (KL67B2270)',
    '26 Seater Tempo Traveller (KL667463)',
    '26 Seater Tempo Traveller (KL33F9504)',
    '26 Seater Tempo Traveller (KL35D7746)',
    '26 Seater Tempo Traveller (KL46N2849)'
];

// Combine default buses and database buses uniquely
$all_buses = array_unique(array_merge($default_buses, $db_buses));
sort($all_buses);
?>

<!-- PAID & UNPAID USERS BY BUS TAB -->
<?php elseif ($tab == 'paid_unpaid_users'): ?>
    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">View Paid & Unpaid Students by Bus</h3>
            <form method="GET" action="admin_dashboard.php" class="flex items-center space-x-4 mb-6">
                <input type="hidden" name="tab" value="paid_unpaid_users">
                <div class="flex-1 max-w-md">
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Select Bus</label>
                    <select name="selected_bus" onchange="this.form.submit()" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                        <option value="">-- Choose Bus --</option>
                        <?php foreach($all_buses as $b): ?>
                            <option value="<?php echo htmlspecialchars($b); ?>" <?php echo (isset($_GET['selected_bus']) && $_GET['selected_bus'] == $b) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php 
            $selected_bus = $_GET['selected_bus'] ?? '';
            if (!empty($selected_bus)):
                $esc_bus = $conn->real_escape_string($selected_bus);
                // Updated query: restricted strictly to role = 'student'
                $bus_users = $conn->query("SELECT * FROM users WHERE status='approved' AND bus_number='$esc_bus' AND role='student'");
            ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                <th class="p-3">Student Name</th>
                                <th class="p-3">Course</th>
                                <th class="p-3">Boarding Point</th>
                                <th class="p-3 text-center">Payment Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if ($bus_users && $bus_users->num_rows > 0): ?>
                                <?php while($bu = $bus_users->fetch_assoc()): 
                                    $p_status_raw = trim($bu['payment_status'] ?? 'unpaid');
                                    $p_status = strtolower($p_status_raw);
                                    
                                    $is_paid = ($p_status == 'paid' || $p_status == 'completed' || $p_status == 'fully paid');
                                    $is_unpaid = ($p_status == 'unpaid' || $p_status == '' || $p_status == 'pending');
                                    $is_partial = !$is_paid && !$is_unpaid;
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="p-3 font-bold text-gray-900"><?php echo htmlspecialchars($bu['full_name']); ?></td>
                                        <td class="p-3 text-xs"><?php echo htmlspecialchars($bu['course'] ?? 'N/A'); ?></td>
                                        <td class="p-3 text-xs"><?php echo htmlspecialchars($bu['boarding_point']); ?></td>
                                        <td class="p-3 text-center">
                                            <?php if($is_paid): ?>
                                                <span class="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-semibold">Paid</span>
                                            <?php elseif($is_partial): ?>
                                                <span class="bg-amber-100 text-amber-800 text-xs px-2.5 py-1 rounded-full font-semibold">
                                                    <?php echo htmlspecialchars(ucwords($p_status_raw)); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-red-100 text-red-800 text-xs px-2.5 py-1 rounded-full font-semibold">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="py-6 text-center text-gray-500">No active students assigned to this bus.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500 italic"></p>
            <?php endif; ?>
        </div>
    </div>

            <!-- URGENT PASS REQUESTS TAB -->
            <?php elseif ($tab == 'urgent_passes'): ?>
                <div class="space-y-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-[#1A2E6B]">Urgent Bus Pass Requests</h3>
                            <span class="bg-blue-100 text-blue-800 text-xs px-3 py-1 rounded-full font-semibold">Admin Approval Queue</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">ID</th>
                                        <th class="p-3">User Name</th>
                                        <th class="p-3">Role / Details</th>
                                        <th class="p-3">Requested Route</th>
                                        <th class="p-3">Reason / Details</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                  <?php if ($urgent_passes_result && $urgent_passes_result->num_rows > 0): ?>
    <?php while($up = $urgent_passes_result->fetch_assoc()): ?>
        <tr class="hover:bg-gray-50 transition">
            <td class="p-3"><?php echo $up['id']; ?></td>
            <td class="p-3 font-bold text-gray-900"><?php echo htmlspecialchars($up['full_name']); ?> <span class="text-xs text-gray-500 font-normal">(@<?php echo htmlspecialchars($up['username']); ?>)</span></td>
            <td class="p-3 text-xs capitalize">
                <div><?php echo htmlspecialchars($up['role'] ?? ''); ?></div>
                <div class="text-gray-500"><?php echo htmlspecialchars($up['course'] ?? $up['department'] ?? ''); ?></div>
            </td>
            <td class="p-3 font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($up['requested_route']); ?></td>
            <td class="p-3 text-xs text-gray-600"><?php echo htmlspecialchars($up['reason']); ?></td>
            <td class="p-3">
                <?php if($up['payment_status'] == 'paid' || $up['status'] == 'Paid & Issued'): ?>
                    <span class="bg-emerald-100 text-emerald-800 text-xs px-2.5 py-1 rounded-full font-semibold">Paid & Issued</span>
                <?php elseif($up['status'] == 'approved'): ?>
                    <span class="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-semibold">Approved (Payment Unlocked)</span>
                <?php elseif($up['status'] == 'rejected'): ?>
                    <span class="bg-red-100 text-red-800 text-xs px-2.5 py-1 rounded-full font-semibold">Rejected</span>
                <?php else: ?>
                    <span class="bg-amber-100 text-amber-800 text-xs px-2.5 py-1 rounded-full font-semibold">Pending Approval</span>
                <?php endif; ?>
            </td>
            
            <!-- Actions Column (പേയ്‌മെന്റ് കഴിഞ്ഞാൽ ബട്ടണുകൾ ഹൈഡ് ചെയ്ത് 'Action Completed' കാണിക്കുന്നു) -->
            <td class="p-3 text-center">
                <?php if($up['payment_status'] == 'paid' || $up['status'] == 'Paid & Issued' || $up['status'] == 'approved' || $up['status'] == 'rejected'): ?>
                    <span class="text-xs text-gray-400 italic font-medium">Action Completed</span>
                <?php else: ?>
                    <div class="flex justify-center space-x-2">
                        <a href="admin_dashboard.php?tab=urgent_passes&action=approve_urgent&urgent_id=<?php echo $up['id']; ?>" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-green-700 transition shadow-sm">Approve</a>
                        <a href="admin_dashboard.php?tab=urgent_passes&action=reject_urgent&urgent_id=<?php echo $up['id']; ?>" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition shadow-sm">Reject</a>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr><td colspan="7" class="py-6 text-center text-gray-500">No urgent pass requests submitted yet.</td></tr>
<?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <!-- EMERGENCY PASS PUBLISHING TAB -->
            <?php elseif ($tab == 'emergency_passes'): ?>
                <div class="space-y-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 max-w-2xl mx-auto">
                        <h3 class="text-lg font-bold text-[#1A2E6B] mb-2"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-2"></i> Issue Emergency Bus Passes</h3>
                        <form action="admin_dashboard.php?tab=emergency_passes" method="POST" class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Current Bus <span class="text-red-500">*</span></label>
                                <select id="failed_bus_select" name="failed_bus" required onchange="updateAlternativeBusOptions()" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                                    <option value="">-- Select Current Bus --</option>
                                    <?php foreach($all_buses as $bus): ?>
                                        <option value="<?php echo htmlspecialchars($bus); ?>"><?php echo htmlspecialchars($bus); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Alternative Bus <span class="text-red-500">*</span></label>
                                <select id="alternative_bus_select" name="alternative_bus" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                                    <option value="">-- Select Alternative Bus --</option>
                                    <?php foreach($all_buses as $bus): ?>
                                        <option value="<?php echo htmlspecialchars($bus); ?>"><?php echo htmlspecialchars($bus); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Validity Period <span class="text-red-500">*</span></label>
                                <input type="date" name="valid_until" required min="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Reason / Note <span class="text-red-500">*</span></label>
                                <textarea name="reason" rows="2" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm"></textarea>
                            </div>
                            <!-- OPTIONAL FILTERING DROPDOWN -->
<div>
    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">
        Filtering (Optional)
    </label>

    <select name="filter_department"
        class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">

        <option value="">-- Select Department / Course (Optional) --</option>
        <option value="civil">Civil Engineering</option>
        <option value="mechanical">Mechanical Engineering</option>
        <option value="electrical">Electrical Engineering</option>
        <option value="cse">Computer Science Engineering (CSE)</option>
        <option value="naval">Naval Architecture</option>
        <option value="MCA">MCA</option>
        <option value="MBA">MBA</option>
        <option value="administrative">Administrative</option>

    </select>
</div>
                            <div class="text-center pt-2">
                                <button type="submit" name="publish_emergency_pass" class="w-full bg-red-600 text-white py-3 rounded-lg font-semibold hover:bg-red-700 transition shadow-sm text-sm">
                                    <i class="fa-solid fa-bullhorn mr-2"></i> Publish Emergency Pass to Affected Users
                                </button>
                            </div>
        
                        </form>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Active Issued Emergency Passes Log</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">ID</th>
                                        <th class="p-3">User Name</th>
                                        <th class="p-3">Current Bus</th>
                                        <th class="p-3">Alternative Bus</th>
                                        <th class="p-3">Validity</th>
                                        <th class="p-3">Reason</th>
                                        <th class="p-3">Issued Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if ($emergency_passes_result && $emergency_passes_result->num_rows > 0): ?>
                                        <?php while($ep = $emergency_passes_result->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-3"><?php echo $ep['id']; ?></td>
                                                <td class="p-3 font-bold text-gray-900"><?php echo htmlspecialchars($ep['full_name']); ?> <span class="text-xs text-gray-500 font-normal">(@<?php echo htmlspecialchars($ep['username']); ?>)</span></td>
                                                <td class="p-3 text-red-600 font-semibold"><?php echo htmlspecialchars($ep['failed_bus']); ?></td>
                                                <td class="p-3 text-emerald-600 font-semibold"><?php echo htmlspecialchars($ep['alternative_bus']); ?></td>
                                                <td class="p-3 text-xs bg-amber-50 font-medium text-amber-800"><?php echo htmlspecialchars($ep['valid_until']); ?></td>
                                                <td class="p-3 text-xs text-gray-600"><?php echo htmlspecialchars($ep['reason']); ?></td>
                                                <td class="p-3 text-xs text-gray-500"><?php echo htmlspecialchars($ep['created_at']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No emergency passes issued yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <!-- FEEDBACK MANAGEMENT TAB -->
            <?php elseif ($tab == 'feedback'): ?>
                <div class="space-y-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-lg font-bold text-[#1A2E6B]">Feedback Questions Management</h3>
                                <p class="text-xs text-gray-500 mt-0.5">Manage questions in draft status and publish them once per month.</p>
                            </div>
                            <div class="flex space-x-3">
                                <button onclick="openQuestionModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                                    <i class="fa-solid fa-plus mr-2"></i> Add Question
                                </button>
                                <form action="admin_dashboard.php?tab=feedback" method="POST" onsubmit="return confirm('Publish these feedback questions to all active user dashboards?');">
                                    <button type="submit" name="publish_questions" class="bg-emerald-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-emerald-700 transition shadow-sm">
                                        <i class="fa-solid fa-bullhorn mr-2"></i> Publish
                                    </button>
                                </form>
                                <a href="admin_dashboard.php?tab=feedback&action=reset_monthly_feedback" onclick="return confirm('WARNING: This will clear all user feedback assignments and reset question statuses back to draft. Proceed?');" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-red-700 transition shadow-sm flex items-center">
                                    <i class="fa-solid fa-rotate-right mr-2"></i> Reset Published Feedback
                                </a>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">ID</th>
                                        <th class="p-3">Feedback Question</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3">Published Month</th>
                                        <th class="p-3 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if ($feedback_questions_result && $feedback_questions_result->num_rows > 0): ?>
                                        <?php while($fq = $feedback_questions_result->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-3"><?php echo $fq['id']; ?></td>
                                                <td class="p-3 text-gray-800 font-medium"><?php echo htmlspecialchars($fq['question_text']); ?></td>
                                                <td class="p-3">
                                                    <?php if($fq['status'] == 'published'): ?>
                                                        <span class="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-semibold">Published</span>
                                                    <?php else: ?>
                                                        <span class="bg-amber-100 text-amber-800 text-xs px-2.5 py-1 rounded-full font-semibold">Draft</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-3 text-xs text-gray-600"><?php echo htmlspecialchars($fq['published_month'] ?? 'N/A'); ?></td>
                                                <td class="p-3 text-center">
                                                    <div class="flex justify-center space-x-2">
                                                        <?php if($fq['status'] == 'draft'): ?>
                                                            <button onclick='editQuestion(<?php echo json_encode($fq); ?>)' class="bg-blue-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                                                                <i class="fa-solid fa-pen-to-square"></i>
                                                            </button>
                                                            <a href="admin_dashboard.php?tab=feedback&action=delete_question&id=<?php echo $fq['id']; ?>" onclick="return confirm('Delete this feedback question?');" class="bg-red-600 text-white px-2.5 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-xs text-gray-400 italic">Locked (Published)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="py-6 text-center text-gray-500">No feedback questions configured.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-[#1A2E6B]">Submitted User Feedback Responses</h3>
                            <form method="GET" action="admin_dashboard.php" class="flex items-center space-x-3">
                                <input type="hidden" name="tab" value="feedback">
                                <div>
                                    <select name="filter_bus" onchange="this.form.submit()" class="px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm font-medium text-gray-700">
                                        <option value="">-- All Buses (Filter by Bus) --</option>
                                        <?php foreach($all_buses as $bus_opt): ?>
                                            <option value="<?php echo htmlspecialchars($bus_opt); ?>" <?php echo ($selected_filter_bus == $bus_opt) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($bus_opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if(!empty($selected_filter_bus)): ?>
                                    <a href="admin_dashboard.php?tab=feedback" class="text-xs text-red-600 font-semibold hover:underline">Clear Filter</a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">ID</th>
                                        <th class="p-3">User Name & Role</th>
                                        <th class="p-3">Bus Number</th>
                                        <th class="p-3">Question</th>
                                        <th class="p-3">Response / Answer</th>
                                        <th class="p-3">Assigned Month</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if ($feedback_responses_result && $feedback_responses_result->num_rows > 0): ?>
                                        <?php while($fbr = $feedback_responses_result->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-3"><?php echo $fbr['id']; ?></td>
                                                <td class="p-3">
                                                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($fbr['full_name']); ?></div>
                                                    <div class="text-xs text-gray-500 capitalize"><?php echo htmlspecialchars($fbr['role']); ?></div>
                                                </td>
                                                <td class="p-3 font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($fbr['bus_number'] ?? 'N/A'); ?></td>
                                                <td class="p-3 text-xs text-gray-700 font-medium"><?php echo htmlspecialchars($fbr['question_text']); ?></td>
                                                <td class="p-3 text-gray-800"><?php echo htmlspecialchars($fbr['answer']); ?></td>
                                                <td class="p-3 text-xs text-gray-500"><?php echo htmlspecialchars($fbr['assigned_month'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No feedback responses found for the selected filter.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

              <!-- PAYMENT PUBLISH TAB -->
<?php elseif ($tab == 'payment_publish'): ?>
    <div class="space-y-8">
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6 max-w-2xl mx-auto">
            <h3 class="text-lg font-bold text-[#1A2E6B] mb-2"><i class="fa-solid fa-credit-card text-[#1A2E6B] mr-2"></i> Publish Semester Payment Cycle</h3>
            <form action="admin_dashboard.php?tab=payment_publish" method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Academic Year <span class="text-red-500">*</span></label>
                    <input type="text" name="academic_year" required pattern="\d{4}-\d{4}" title="Format must be strictly yyyy-yyyy (e.g., 2026-2027)" placeholder="e.g., 2026-2027" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Semester / Period <span class="text-red-500">*</span></label>
                    <select name="semester_period" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                        <option value="">-- Select Period --</option>
                        <option value="July to December">July to December</option>
                        <option value="January to May">January to May</option>
                    </select>
                </div>
                <div class="text-center pt-2">
                    <button type="submit" name="publish_payment_cycle" class="w-full bg-[#1A2E6B] text-white py-3 rounded-lg font-semibold hover:bg-[#142353] transition shadow-sm text-sm">
                        <i class="fa-solid fa-bullhorn mr-2"></i> Publish Payment Cycle
                    </button>
                </div>
            </form>
        </div>
        <!-- ബാക്കി ഭാഗങ്ങൾ പഴയതുപോലെ തുടരാം -->
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">Published Payment Cycles Log</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                            <th class="p-3">ID</th>
                            <th class="p-3">Academic Year</th>
                            <th class="p-3">Semester Period</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Published At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if ($payment_cycles_result && $payment_cycles_result->num_rows > 0): ?>
                            <?php while($pc = $payment_cycles_result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-3"><?php echo $pc['id']; ?></td>
                                    <td class="p-3 font-bold text-gray-900"><?php echo htmlspecialchars($pc['academic_year']); ?></td>
                                    <td class="p-3 font-semibold text-[#1A2E6B]"><?php echo htmlspecialchars($pc['semester_period']); ?></td>
                                    <td class="p-3">
                                        <span class="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-semibold"><?php echo ucfirst(htmlspecialchars($pc['status'])); ?></span>
                                    </td>
                                    <td class="p-3 text-xs text-gray-500"><?php echo htmlspecialchars($pc['published_at']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="py-6 text-center text-gray-500">No payment cycles published yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
                

                <!-- Add/Edit Feedback Question Modal -->
                <div id="questionModal" class="fixed inset-0 bg-black/50 hidden flex items-center justify-center p-4 z-50">
                    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 relative">
                        <h3 id="questionModalTitle" class="text-lg font-bold text-[#1A2E6B] mb-4">Add Feedback Question</h3>
                        <form action="admin_dashboard.php?tab=feedback" method="POST" class="space-y-4">
                            <input type="hidden" id="question_id" name="question_id" value="0">
                            <div>
                                <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Question Text</label>
                                <textarea id="question_text" name="question_text" rows="3" required placeholder="Enter feedback question here..." class="w-full px-4 py-2 border rounded-lg text-sm bg-[#F8F5F0]"></textarea>
                            </div>
                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" onclick="closeQuestionModal()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm font-semibold">Cancel</button>
                                <button type="submit" name="save_question" class="bg-[#1A2E6B] text-white px-4 py-2 rounded-lg text-sm font-semibold">Save Question</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                    // JavaScript functions for Driver's License Modal Viewer
    function viewLicense(imageSrc, driverName) {
        const modal = document.getElementById('licenseModal');
        const modalImage = document.getElementById('modalLicenseImage');
        const modalTitle = document.getElementById('modalDriverTitle');
        
        if (imageSrc && imageSrc !== '') {
            modalImage.src = "uploads/" + imageSrc;
            modalImage.style.display = "block";
            document.getElementById('noLicenseText').style.display = "none";
        } else {
            modalImage.style.display = "none";
            document.getElementById('noLicenseText').style.display = "block";
        }
        
        modalTitle.innerText = "Driving License - " + driverName;
        modal.classList.remove('hidden');
    }

    function closeLicenseModal() {
        document.getElementById('licenseModal').classList.add('hidden');
    }
                function openQuestionModal() {
                    document.getElementById('questionModalTitle').innerText = "Add Feedback Question";
                    document.getElementById('question_id').value = "0";
                    document.getElementById('question_text').value = "";
                    document.getElementById('questionModal').classList.remove('hidden');
                }
                function editQuestion(fq) {
                    document.getElementById('questionModalTitle').innerText = "Edit Feedback Question";
                    document.getElementById('question_id').value = fq.id;
                    document.getElementById('question_text').value = fq.question_text;
                    document.getElementById('questionModal').classList.remove('hidden');
                }
                function closeQuestionModal() { document.getElementById('questionModal').classList.add('hidden'); }
                </script>
<!-- COMPLAINTS TAB -->
            <?php elseif ($tab == 'complaints'): ?>
                <div class="space-y-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                        <h3 class="text-lg font-bold text-[#1A2E6B] mb-4">User Complaints & Anonymous Driver Warning System</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-100 text-gray-700 text-xs uppercase">
                                        <th class="p-3">ID</th>
                                        <th class="p-3">Complainant Name</th>
                                        <th class="p-3">Bus Number</th>
                                        <th class="p-3">Complaint Description</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3">Date</th>
                                        <th class="p-3 text-center">Action / Warning Form</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if ($complaints_result && $complaints_result->num_rows > 0): ?>
                                        <?php while($cmp = $complaints_result->fetch_assoc()): ?>
                                            <tr class="hover:bg-gray-50 transition">
                                                <td class="p-3"><?php echo $cmp['id']; ?></td>
                                                
                                                <!-- Complainant Name -->
                                                <td class="p-3 font-medium">
                                                    <?php 
                                                    $comp_name = !empty($cmp['complainant_name']) ? $cmp['complainant_name'] : (!empty($cmp['username']) ? $cmp['username'] : 'N/A');
                                                    echo htmlspecialchars($comp_name); 
                                                    ?>
                                                </td>
                                                
                                                <!-- Bus Number -->
                                                <td class="p-3 text-[#1A2E6B] font-bold">
                                                    <?php 
                                                    $display_bus = !empty($cmp['bus_number']) ? $cmp['bus_number'] : (!empty($cmp['joined_bus']) ? $cmp['joined_bus'] : 'N/A');
                                                    echo htmlspecialchars($display_bus); 
                                                    ?>
                                                </td>
                                                
                                                <td class="p-3 text-gray-700"><?php echo htmlspecialchars($cmp['complaint_text'] ?? $cmp['description'] ?? 'N/A'); ?></td>
                                                
                                                <!-- Status Display Column -->
                                                <td class="p-3">
                                                    <?php if(isset($cmp['status']) && $cmp['status'] == 'Resolved'): ?>
                                                        <span class="bg-green-100 text-green-800 text-xs px-2.5 py-1 rounded-full font-semibold">Resolved</span>
                                                    <?php else: ?>
                                                        <span class="bg-amber-100 text-amber-800 text-xs px-2.5 py-1 rounded-full font-semibold">Pending Warning</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="p-3 text-xs text-gray-500"><?php echo htmlspecialchars($cmp['created_at'] ?? 'N/A'); ?></td>
                                                
                                                <!-- Action Column: If resolved, show status text, else show warning form -->
                                                <td class="p-3 text-center">
                                                    <?php if(isset($cmp['status']) && $cmp['status'] == 'Resolved'): ?>
                                                        <span class="text-xs font-semibold text-green-700 bg-green-50 px-3 py-1.5 rounded-lg border border-green-200 inline-block">
                                                            <i class="fa-solid fa-check-circle mr-1"></i> Warning Sent & Resolved
                                                        </span>
                                                    <?php else: ?>
                                                        <form action="admin_dashboard.php?tab=complaints" method="POST" class="space-y-2">
                                                            <input type="hidden" name="complaint_id" value="<?php echo $cmp['id']; ?>">
                                                            <select name="driver_id" required class="w-full px-3 py-1.5 bg-[#F8F5F0] border rounded-lg text-xs">
                                                                <option value="">-- Select Driver to Warn --</option>
                                                                <?php 
                                                                if ($approved_drivers_list) {
                                                                    $approved_drivers_list->data_seek(0);
                                                                    while($drv = $approved_drivers_list->fetch_assoc()): 
                                                                ?>
                                                                    <option value="<?php echo $drv['id']; ?>">
                                                                        <?php echo htmlspecialchars($drv['full_name'] . ' (' . $drv['bus_number'] . ')'); ?>
                                                                    </option>
                                                                <?php 
                                                                    endwhile; 
                                                                } 
                                                                ?>
                                                            </select>
                                                            <textarea name="warning_message" rows="2" required class="w-full px-3 py-1.5 bg-[#F8F5F0] border rounded-lg text-xs" placeholder="Type warning message..."><?php echo htmlspecialchars($cmp['complaint_text'] ?? ''); ?></textarea>
                                                            <button type="submit" name="send_driver_warning" class="w-full bg-red-600 text-white py-1.5 rounded-lg text-xs font-semibold hover:bg-red-700 transition">
                                                                Send Warning
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No complaints submitted.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-200">
                    <p class="text-gray-600">Select a tab from the sidebar to begin managing the system.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
