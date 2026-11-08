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

// Ensure table exists with necessary columns
$conn->query("CREATE TABLE IF NOT EXISTS urgent_pass_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT 0,
    applicant_name VARCHAR(255) NOT NULL,
    department VARCHAR(255) NOT NULL,
    requested_route VARCHAR(255) NOT NULL,
    boarding_point VARCHAR(255) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    payment_status VARCHAR(50) DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure urgent pass payments table exists for Razorpay logging
$conn->query("CREATE TABLE IF NOT EXISTS urgent_pass_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT 0,
    urgent_request_id INT,
    amount DECIMAL(10,2),
    razorpay_payment_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Safely add payment_timestamp column if it does not exist in the existing table
$pay_cols = $conn->query("SHOW COLUMNS FROM urgent_pass_payments");
$pay_col_list = [];
if ($pay_cols) {
    while($pc = $pay_cols->fetch_assoc()) { $pay_col_list[] = $pc['Field']; }
}
if (!in_array('payment_timestamp', $pay_col_list)) {
    $conn->query("ALTER TABLE urgent_pass_payments ADD COLUMN payment_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

$success_msg = "";
$error_msg = "";

// Handle form submission for urgent pass requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_urgent_pass'])) {
    $applicant_name = trim($conn->real_escape_string($_POST['applicant_name']));
    $department = trim($conn->real_escape_string($_POST['department']));
    $requested_bus = trim($conn->real_escape_string($_POST['requested_bus']));
    $boarding_point = trim($conn->real_escape_string($_POST['boarding_point']));
    $reason = trim($conn->real_escape_string($_POST['reason']));

    if (!empty($applicant_name) && !empty($department) && !empty($requested_bus) && !empty($boarding_point) && !empty($reason)) {
        $stmt = $conn->prepare("INSERT INTO urgent_pass_requests (applicant_name, department, requested_route, boarding_point, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("sssss", $applicant_name, $department, $requested_bus, $boarding_point, $reason);
        
        if ($stmt->execute()) {
            $success_msg = "Urgent temporary pass request for hosteller/day scholar (Fee: ₹200) submitted successfully! Once approved by the administrator, you can proceed with the payment below.";
        } else {
            $error_msg = "Error submitting request: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error_msg = "Please fill out all required fields including the reason.";
    }
}

// Handle Razorpay Payment Verification & Fee Update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_urgent_payment'])) {
    $request_id = intval($_POST['request_id']);
    $payment_id = trim($conn->real_escape_string($_POST['razorpay_payment_id']));

    $chk_req = $conn->prepare("SELECT * FROM urgent_pass_requests WHERE id = ? AND status = 'approved'");
    $chk_req->bind_param("i", $request_id);
    $chk_req->execute();
    $req_res = $chk_req->get_result();

    if ($req_res && $req_res->num_rows > 0) {
        $stmt_pay = $conn->prepare("INSERT INTO urgent_pass_payments (urgent_request_id, amount, razorpay_payment_id, payment_timestamp) VALUES (?, 200.00, ?, NOW())");
        $stmt_pay->bind_param("is", $request_id, $payment_id);
        $stmt_pay->execute();
        $stmt_pay->close();

        $conn->query("UPDATE urgent_pass_requests SET payment_status='paid', status='Paid & Issued' WHERE id=$request_id");
        $success_msg = "Payment of ₹200 successful via Razorpay! Your 24-hour temporary urgent pass and QR code are now active.";
    } else {
        $error_msg = "Invalid or unapproved request selected for payment.";
    }
    $chk_req->close();
}

// Fetch available buses from bus_routes
$buses = [];
$bus_query = $conn->query("SELECT DISTINCT bus_no_reg FROM bus_routes");
if ($bus_query && $bus_query->num_rows > 0) {
    while($b = $bus_query->fetch_assoc()) {
        $buses[] = $b['bus_no_reg'];
    }
}

// Fetch bus routes details to map boarding points dynamically in JS
$route_mapping = [];
$routes_data = $conn->query("SELECT bus_no_reg, starting_point, route_details FROM bus_routes");
if ($routes_data && $routes_data->num_rows > 0) {
    while($rd = $routes_data->fetch_assoc()) {
        $bus_name = $rd['bus_no_reg'];
        $points = [];
        if(!empty($rd['starting_point'])) {
            $points[] = trim($rd['starting_point']);
        }
        if(!empty($rd['route_details'])) {
            $split_points = preg_split('/[,–\n-]/', $rd['route_details']);
            foreach($split_points as $sp) {
                $cleaned = trim($sp);
                if(!empty($cleaned) && !in_array($cleaned, $points)) {
                    $points[] = $cleaned;
                }
            }
        }
        $route_mapping[$bus_name] = $points;
    }
}

$search_name = trim($_GET['search_name'] ?? '');
$requests_result = null;
if (!empty($search_name)) {
    $esc_name = $conn->real_escape_string($search_name);
    $requests_result = $conn->query("SELECT r.*, p.payment_timestamp FROM urgent_pass_requests r LEFT JOIN urgent_pass_payments p ON r.id = p.urgent_request_id WHERE r.applicant_name LIKE '%$esc_name%' OR r.department LIKE '%$esc_name%' ORDER BY r.id DESC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Urgent Bus Pass Request - SNGCE</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Razorpay Checkout Script -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <!-- QRCode Generator Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const routeMapping = <?php echo json_encode($route_mapping); ?>;

        function updateBoardingPoints() {
            const busSelect = document.getElementById('requested_bus_select');
            const boardingSelect = document.getElementById('boarding_point_select');
            const selectedBus = busSelect.value;

            boardingSelect.innerHTML = '<option value="">-- Select Boarding Point --</option>';

            if (selectedBus && routeMapping[selectedBus]) {
                const points = routeMapping[selectedBus];
                points.forEach(point => {
                    const option = document.createElement('option');
                    option.value = point;
                    option.textContent = point;
                    boardingSelect.appendChild(option);
                });
            }
        }

        // Razorpay Payment Trigger Function
        function payUrgentPassRazorpay(requestId, applicantName) {
            var options = {
                "key": "rzp_test_6dalArTaXA3vfg",
                "amount": 20000, // ₹200.00 in paisa
                "currency": "INR",
                "name": "SNGCE Bus Pass Portal",
                "description": "Urgent Temporary Pass Fee (₹200 - 24 Hours Validity)",
                "handler": function (response){
                    document.getElementById('form_request_id').value = requestId;
                    document.getElementById('form_razorpay_payment_id').value = response.razorpay_payment_id;
                    document.getElementById('razorpay-urgent-form').submit();
                },
                "prefill": {
                    "name": applicantName,
                    "email": "student@sngce.ac.in"
                },
                "theme": {
                    "color": "#1A2E6B"
                }
            };
            var rzp1 = new Razorpay(options);
            rzp1.open();
        }
    </script>
    <style>
        :root { --primary: #1A2E6B; --primary-hover: #12214f; }
        body { background-color: #fcfcfc; font-family: 'Inter', sans-serif; }
        header { background: var(--primary); color: white; padding: 1rem 5%; display: flex; justify-content: space-between; align-items: center; }
        nav { display: flex; gap: 2rem; align-items: center; }
        nav a { color: #e5e7eb; text-decoration: none; font-weight: 500; }
        nav a:hover { color: #fff; }
        @media print {
            body * { visibility: hidden; }
            .printable-pass, .printable-pass * { visibility: visible !important; }
            .printable-pass { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">
   <!-- Main Navigation Header -->
    <header>
        <div class="brand-container">
            <a href="index.html" style="display: flex; align-items: center; gap: 1rem; text-decoration: none;">
                <img src="Emblem.jpg" alt="SNGCE Emblem" style="height: 40px; width: auto; object-fit: contain;">
                <span style="color: white; font-weight: bold; font-size: 0.95rem; letter-spacing: 0.05em;">SNGCE BUS PASS</span>
            </a>
        </div>
        <nav>
            <a href="index.html" style="border: 2px solid rgba(255,255,255,0.4); padding: 0.4rem 1rem; border-radius: 6px; font-weight: 600;">Back</a>
        </nav>
    </header>

    <main class="flex-1 max-w-3xl w-full mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-8 mb-8 no-print">
            <h1 class="text-2xl font-bold text-[#1A2E6B] mb-2"><i class="fa-solid fa-bolt text-amber-500 mr-2"></i> Urgent Bus Pass Request</h1>
            <p class="text-sm text-gray-600 mb-6">Urgent temporary pass request for hosteller/day scholar (Fee: <strong>₹200</strong>, Valid for <strong>24 Hours</strong> only). Submit your details below.</p>

            <?php if(!empty($success_msg)): ?>
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded text-sm"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-sm"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <form action="urgent_pass.php" method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Full Name with Roll No <span class="text-red-500">*</span></label>
                    <input type="text" name="applicant_name" required placeholder="Enter your full name" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Department / Course <span class="text-red-500">*</span></label>
                    <select name="department" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                        <option value="">-- Select Department --</option>
                        <option value="Civil Engineering">Civil Engineering</option>
                        <option value="Mechanical Engineering">Mechanical Engineering</option>
                        <option value="Electrical Engineering">Electrical Engineering</option>
                        <option value="CSE">Computer Science Engineering (CSE)</option>
                        <option value="Naval Architecture">Naval Architecture</option>
                        <option value="MCA">MCA</option>
                        <option value="MBA">MBA</option>
                        <option value="Administrative">Administrative</option>

                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Requested Bus <span class="text-red-500">*</span></label>
                    <select id="requested_bus_select" name="requested_bus" required onchange="updateBoardingPoints()" class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                        <option value="">-- Select Bus --</option>
                        <?php foreach($buses as $bus): ?>
                            <option value="<?php echo htmlspecialchars($bus); ?>"><?php echo htmlspecialchars($bus); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Boarding Point <span class="text-red-500">*</span></label>
                    <select id="boarding_point_select" name="boarding_point" required class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm">
                        <option value="">-- First Select a Bus --</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Reason for Urgent Pass <span class="text-red-500">*</span></label>
                    <textarea name="reason" rows="3" required placeholder="Enter the reason why you need this urgent pass..." class="w-full px-4 py-2.5 bg-[#F8F5F0] border rounded-lg text-sm"></textarea>
                </div>
                <div class="pt-2">
                    <button type="submit" name="submit_urgent_pass" class="w-full bg-[#1A2E6B] text-white py-3 rounded-lg font-semibold hover:bg-[#12214f] transition text-sm">
                        Submit Urgent Request
                    </button>
                </div>
            </form>
        </div>

        <!-- Status, Payment & QR Code Section -->
        <div class="bg-white rounded-xl shadow-md border border-gray-200 p-8">
            <h2 class="text-lg font-bold text-[#1A2E6B] mb-2">Check Request Status & Get Pass QR Code</h2>
            <p class="text-xs text-gray-500 mb-4">Tip: If you are faculty, you can search by typing both Full Name and Department (e.g., <strong>Rose CSE</strong>).</p>
            
            <form action="urgent_pass.php" method="GET" class="flex gap-3 mb-6 no-print">
                <input type="text" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Enter name..." class="flex-1 px-4 py-2 bg-[#F8F5F0] border rounded-lg text-sm">
                <button type="submit" class="bg-emerald-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-emerald-700 transition">Search</button>
            </form>

            <?php if($requests_result && $requests_result->num_rows > 0): ?>
                <div class="space-y-6">
                    <?php while($req = $requests_result->fetch_assoc()): 
                        $is_paid = ($req['payment_status'] == 'paid' || $req['status'] == 'Paid & Issued');
                        $qr_div_id = "urgent-qr-" . $req['id'];
                        
                        // 24-Hour Validity Check Calculation
                        $is_expired = false;
                        $expiry_time_str = "";
                        if ($is_paid && !empty($req['payment_timestamp'])) {
                            $payment_time = strtotime($req['payment_timestamp']);
                            $expiry_time = $payment_time + (24 * 60 * 60); // Adding 24 hours
                            $expiry_time_str = date('Y-m-d H:i:s', $expiry_time);
                            
                            if (time() > $expiry_time) {
                                $is_expired = true;
                            }
                        }
                    ?>
                        <div class="border rounded-xl p-6 bg-gray-50 space-y-4 shadow-sm <?php echo ($is_paid && !$is_expired) ? 'printable-pass' : ''; ?>">
                            <div class="flex justify-between items-center border-b pb-3">
                                <div>
                                    <h4 class="font-bold text-gray-900 text-base"><?php echo htmlspecialchars($req['applicant_name']); ?> <span class="text-xs font-normal text-gray-600">(ID: #<?php echo $req['id']; ?>)</span></h4>
                                    <p class="text-xs font-semibold text-[#1A2E6B]"><i class="fa-solid fa-graduation-cap mr-1"></i> <?php echo htmlspecialchars($req['department']); ?></p>
                                </div>
                                <div>
                                    <?php if($req['status'] == 'approved' && !$is_paid): ?>
                                        <span class="bg-green-100 text-green-800 px-2.5 py-1 rounded-full text-xs font-semibold">Approved by Admin</span>
                                    <?php elseif($is_paid && !$is_expired): ?>
                                        <span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded-full text-xs font-semibold">Paid & Active (24h)</span>
                                    <?php elseif($is_paid && $is_expired): ?>
                                        <span class="bg-red-100 text-red-800 px-2.5 py-1 rounded-full text-xs font-semibold">Pass Expired</span>
                                    <?php elseif($req['status'] == 'rejected'): ?>
                                        <span class="bg-red-100 text-red-800 px-2.5 py-1 rounded-full text-xs font-semibold">Rejected</span>
                                    <?php else: ?>
                                        <span class="bg-amber-100 text-amber-800 px-2.5 py-1 rounded-full text-xs font-semibold">Pending Approval</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="text-xs text-gray-500 block font-semibold">Requested Route / Bus</span>
                                    <span class="font-bold text-[#1A2E6B]"><?php echo htmlspecialchars($req['requested_route']); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 block font-semibold">Boarding Point</span>
                                    <span class="font-bold text-gray-800"><?php echo htmlspecialchars($req['boarding_point']); ?></span>
                                </div>
                                <div class="col-span-2">
                                    <span class="text-xs text-gray-500 block font-semibold">Reason Provided</span>
                                    <p class="text-xs text-gray-700 bg-white p-2 rounded border mt-1"><?php echo htmlspecialchars($req['reason']); ?></p>
                                </div>
                                <?php if($is_paid && !empty($expiry_time_str)): ?>
                                <div class="col-span-2 bg-blue-50 p-2.5 rounded border border-blue-200 text-xs">
                                    <span class="font-semibold text-blue-900">Pass Validity Period:</span> 
                                    <span>From <?php echo $req['payment_timestamp']; ?> to <?php echo $expiry_time_str; ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Payment Button or QR Code Display / Expiry Notice -->
                            <div class="pt-2 text-center">
                                <?php if($req['status'] == 'approved' && !$is_paid): ?>
                                    <button type="button" onclick="payUrgentPassRazorpay(<?php echo $req['id']; ?>, '<?php echo addslashes($req['applicant_name']); ?>')" class="bg-indigo-600 text-white px-6 py-2 rounded-lg text-xs font-semibold hover:bg-indigo-700 transition shadow-sm no-print">
                                        <i class="fa-solid fa-credit-card mr-1"></i> Pay ₹200 via Razorpay Now
                                    </button>
                                <?php elseif($is_paid && !$is_expired): ?>
                                    <div class="bg-white p-4 rounded-xl border shadow-inner flex flex-col items-center justify-center space-y-2">
                                        <h5 class="text-xs font-bold uppercase text-[#1A2E6B]">Verified Digital Urgent Pass QR (24h Active)</h5>
                                        <div id="<?php echo $qr_div_id; ?>" class="flex justify-center"></div>
                                        <span class="text-[10px] text-gray-500 font-mono">PASS ID: URGENT-<?php echo $req['id']; ?> | EXPIRES: <?php echo $expiry_time_str; ?></span>
                                    </div>
                                    <div class="pt-2 no-print">
                                        <button onclick="window.print()" class="bg-[#1A2E6B] text-white px-5 py-2 rounded-lg text-xs font-semibold hover:bg-[#142353] transition shadow-sm">
                                            <i class="fa-solid fa-print mr-1"></i> Print Pass & QR Code
                                        </button>
                                    </div>
                                    <script>
                                        document.addEventListener("DOMContentLoaded", function() {
                                            new QRCode(document.getElementById("<?php echo $qr_div_id; ?>"), {
                                                text: "SNGCE-URGENT-PASS|ID:<?php echo $req['id']; ?>|Name:<?php echo urlencode($req['applicant_name']); ?>|Route:<?php echo urlencode($req['requested_route']); ?>|Expires:<?php echo $expiry_time_str; ?>",
                                                width: 120,
                                                height: 120
                                            });
                                        });
                                    </script>
                                <?php elseif($is_paid && $is_expired): ?>
                                    <div class="bg-red-50 border border-red-200 text-red-700 p-3 rounded-lg text-xs font-medium">
                                        <i class="fa-solid fa-triangle-exclamation mr-1"></i> This urgent pass has expired because 24 hours have passed since payment. The QR code has been terminated.
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs italic no-print">Payment unlocks automatically after admin approval.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php elseif(!empty($search_name)): ?>
                <p class="text-sm text-gray-500 italic">No requests found matching "<?php echo htmlspecialchars($search_name); ?>".</p>
            <?php endif; ?>
        </div>
    </main>

    <!-- Hidden form to submit Razorpay payment response -->
    <form id="razorpay-urgent-form" action="urgent_pass.php?search_name=<?php echo htmlspecialchars($search_name); ?>" method="POST" class="hidden">
        <input type="hidden" name="verify_urgent_payment" value="1">
        <input type="hidden" name="request_id" id="form_request_id">
        <input type="hidden" name="razorpay_payment_id" id="form_razorpay_payment_id">
    </form>

    <footer class="text-center py-4 bg-[#1A2E6B] text-white text-xs no-print">
        <p>&copy; 2026 Smart College Bus Pass Management System. All Rights Reserved.</p>
    </footer>
</body>
</html>