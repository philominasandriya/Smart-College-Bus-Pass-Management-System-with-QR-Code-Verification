<?php
session_start();

// Security Check: Ensure logged-in user is a Driver
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'driver') {
    header("Location: login.php");
    exit();
}

// Database Connection
$host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "sngce_bus_pass";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$driver_id = $_SESSION['user_id'];
$driver_name = $_SESSION['full_name'] ?? "Driver";
$assigned_bus_num = $_SESSION['assigned_bus_num'] ?? "";
$assigned_bus_raw = $_SESSION['bus_number_raw'] ?? "";

// Fetch complete driver details including profile image, email, phone, driving_license, etc. from database
$driver_email = "";
$driver_phone = "";
$driver_image = "";
$driver_license = "";

$stmt_driver = $conn->prepare("SELECT full_name, email, phone, bus_number, image, driving_license FROM users WHERE id = ?");
if ($stmt_driver) {
    $stmt_driver->bind_param("i", $driver_id);
    $stmt_driver->execute();
    $res = $stmt_driver->get_result();
    if ($d_row = $res->fetch_assoc()) {
        $driver_name = $d_row['full_name'] ?? "Driver";
        $driver_email = $d_row['email'] ?? "N/A";
        $driver_phone = $d_row['phone'] ?? "N/A";
        $assigned_bus_raw = $d_row['bus_number'] ?? "";
        $driver_image = $d_row['image'] ?? "";
        $driver_license = $d_row['driving_license'] ?? "";
        
        if (!empty($assigned_bus_raw)) {
            $assigned_bus_num = $assigned_bus_raw;
            $base_bus_parts = explode('(', $assigned_bus_raw);
            $bus_search_base = trim($base_bus_parts[0]);
        } else {
            $assigned_bus_num = "49";
            $bus_search_base = "49";
        }
    }
    $stmt_driver->close();
}

// Fetch ALL passengers (Students & Faculty) assigned to this bus number
$passenger_query = "SELECT id, full_name, role, course, department, phone, boarding_point, image, payment_status, bus_number 
                    FROM users 
                    WHERE bus_number LIKE ? AND LOWER(role) != 'driver' AND LOWER(role) != 'admin'
                    ORDER BY boarding_point ASC, full_name ASC";

$stmt_pass = $conn->prepare($passenger_query);
$passengers = false;

if ($stmt_pass) {
    $bus_search_param = $bus_search_base . "%";
    $stmt_pass->bind_param("s", $bus_search_param);
    $stmt_pass->execute();
    $passengers = $stmt_pass->get_result();$stmt_pass->close();
}

// Fetch warnings sent specifically to this driver from the driver_warnings table
$driver_warnings_result = null;
$stmt_warn =$conn->prepare("
    SELECT dw.id, dw.warning_message, dw.sent_date, c.complaint_text 
    FROM driver_warnings dw 
    LEFT JOIN complaint c ON dw.complaint_id = c.id 
    WHERE dw.driver_id = ? 
    ORDER BY dw.id DESC
");
if ($stmt_warn) {
    $stmt_warn->bind_param("i", $driver_id);
    $stmt_warn->execute();$driver_warnings_result = $stmt_warn->get_result();$stmt_warn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Bus Pass Portal</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
</head>
<body class="bg-[#F8F5F0] font-sans flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-[#1A2E6B] text-white flex flex-col justify-between shadow-lg h-screen overflow-y-auto">
        <div>
            <div class="p-6 text-center border-b border-white/10">
                <h1 class="font-bold text-lg tracking-wider uppercase">Driver Portal</h1>
                <p class="text-xs text-gray-300 mt-1"><?php echo htmlspecialchars($driver_name); ?></p>
            </div>
            <nav class="mt-4 space-y-1">
                <button onclick="switchTab('registeredUsers')" id="navRegisteredUsers" class="w-full flex items-center px-6 py-3 text-sm transition bg-[#142353] border-l-4 border-white font-semibold cursor-pointer text-left text-white">
                    <i class="fa-solid fa-users w-5 mr-3"></i> Registered Users
                </button>
                <button onclick="switchTab('complaints')" id="navComplaints" class="w-full flex items-center px-6 py-3 text-sm transition hover:bg-[#142353]/50 border-l-4 border-transparent font-semibold cursor-pointer text-left text-gray-300">
                    <i class="fa-solid fa-triangle-exclamation w-5 mr-3"></i> View Complaints & Warnings
                </button>
            </nav>
        </div>
        <div class="p-4 border-t border-white/10">
            <a href="Login.html" class="flex items-center justify-center w-full bg-red-600/20 text-red-300 py-2 rounded-lg text-sm font-semibold hover:bg-red-600 hover:text-white transition">
                <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Workspace -->
    <main class="flex-1 flex flex-col overflow-y-auto">
        <header class="bg-white shadow-sm px-8 py-4 flex justify-between items-center border-b border-gray-200">
            <div>
                <h2 class="text-xl font-bold text-[#1A2E6B]">Driver Bus Management Portal</h2>
                <p class="text-xs text-gray-500">Route Passengers & QR Scanner Verification</p>
            </div>
            <div onclick="openDriverProfileModal()" class="flex items-center space-x-3 cursor-pointer hover:bg-gray-50 px-3 py-1.5 rounded-lg transition border border-transparent hover:border-gray-200">
                <div class="text-right">
                    <span class="text-[10px] font-bold text-blue-600 uppercase tracking-wider block">Driver Profile</span>
                    <p class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($driver_name); ?></p>
                </div>
                <div>
                    <?php if (!empty($driver_image) && file_exists("uploads/" . $driver_image)): ?>
                        <img src="uploads/<?php echo htmlspecialchars($driver_image); ?>" class="w-9 h-9 rounded-full object-cover border border-gray-300 shadow-xs" alt="Driver">
                    <?php else: ?>
                        <div class="w-9 h-9 rounded-full bg-[#1A2E6B] text-white flex items-center justify-center font-bold text-xs shadow-xs">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- TAB 1: Registered Users View -->
        <div id="tabRegisteredUsers" class="p-8 space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-white rounded-xl shadow-md border border-gray-200 p-6 flex flex-col justify-between">
                    <div class="flex items-center space-x-4">
                        <div class="bg-blue-50 text-[#1A2E6B] p-4 rounded-xl border border-blue-100">
                            <i class="fa-solid fa-bus text-3xl"></i>
                        </div>
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-gray-500">Assigned Bus Route</span>
                            <h3 class="text-3xl font-extrabold text-[#1A2E6B] mt-0.5">
                                Bus No. <?php echo htmlspecialchars($assigned_bus_num); ?>
                            </h3>
                            <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($assigned_bus_raw); ?></p>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-100 flex items-center justify-between">
                        <span class="text-xs text-gray-500 font-medium">Roster (Students & Faculty)</span>
                        <span class="inline-flex items-center bg-emerald-100 text-emerald-800 text-xs px-3 py-1 rounded-full font-semibold">
                            <i class="fa-solid fa-circle-check mr-1.5 text-[10px]"></i> Route Active
                        </span>
                    </div>
                </div>

                <div class="bg-[#1A2E6B] text-white rounded-xl shadow-md p-6 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between">
                            <h4 class="font-bold text-lg"><i class="fa-solid fa-qrcode mr-2"></i> Pass Scanner</h4>
                            <span id="scanStatusBadge" class="bg-blue-500/30 text-blue-200 text-xs px-2.5 py-0.5 rounded-full">Camera Off</span>
                        </div>
                        <p class="text-xs text-gray-300 mt-1">Scan pass QR code for instant lookup.</p>
                    </div>
                    <div class="mt-4">
                        <button id="startScanBtn" onclick="toggleScanner()" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 px-4 rounded-lg shadow transition flex items-center justify-center cursor-pointer">
                            <i class="fa-solid fa-camera mr-2"></i> Start Camera Scanner
                        </button>
                    </div>
                </div>
            </div>

            <!-- Scanner Viewport -->
            <div id="scannerSection" class="hidden bg-white rounded-xl shadow-md border border-gray-200 p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-[#1A2E6B]"><i class="fa-solid fa-expand mr-2 text-blue-600"></i> Live QR Reader</h3>
                    <button onclick="toggleScanner()" class="text-gray-400 hover:text-gray-600 text-sm font-semibold cursor-pointer">
                        <i class="fa-solid fa-xmark mr-1"></i> Close
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                    <div class="bg-black/5 rounded-xl border-2 border-dashed border-gray-300 overflow-hidden flex items-center justify-center min-h-[240px] p-2">
                        <div id="reader" class="w-full max-w-sm rounded-lg overflow-hidden"></div>
                    </div>
                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-6 text-gray-700 space-y-2">
                        <h4 class="font-bold text-[#1A2E6B]"><i class="fa-solid fa-circle-info mr-2"></i> Verification Instructions</h4>
                        <ul class="text-xs space-y-2 text-gray-600 list-disc list-inside">
                            <li>Scan Regular Pass, Urgent Pass, or Emergency Pass QR code.</li>
                            <li>System automatically redirects to <strong>scan_verify.php</strong> to validate full user details and bus permissions.</li>
                        </ul>
                    </div>
                </div>

                <form id="verification-form" action="scan_verify.php" method="GET" class="hidden">
                    <input type="hidden" name="qr_data" id="qr_data_input">
                    <input type="hidden" name="bus_no" value="<?php echo htmlspecialchars($assigned_bus_num); ?>">
                </form>
            </div>

            <!-- Passengers Data Table -->
            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                    <h3 class="font-bold text-[#1A2E6B]">Registered Users for Bus No. <?php echo htmlspecialchars($assigned_bus_num); ?></h3>
                    <div class="flex items-center space-x-2">
                        <input type="text" id="manifestSearch" onkeyup="filterManifest()" placeholder="Search passenger..." class="text-xs border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-[#1A2E6B]">
                        <span class="bg-blue-100 text-blue-800 text-xs px-3 py-1 rounded-full font-semibold">
                            <?php echo ($passengers) ?$passengers->num_rows : 0; ?> Total
                        </span>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm" id="passengerTable">
                        <thead>
                            <tr class="bg-gray-100 text-gray-700 text-xs uppercase tracking-wider border-b border-gray-200">
                                <th class="py-3 px-4">Photo</th>
                                <th class="py-3 px-4">Passenger Name</th>
                                <th class="py-3 px-4">Role</th>
                                <th class="py-3 px-4">Boarding Point</th>
                                <th class="py-3 px-4 text-center">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 text-gray-700">
                            <?php if ($passengers &&$passengers->num_rows > 0): ?>
                                <?php while ($row =$passengers->fetch_assoc()): ?>
                                    <?php 
                                        $dept = !empty($row['course']) ? $row['course'] : ($row['department'] ?? 'N/A');
                                        $pay_status =$row['payment_status'] ?? 'Paid';
                                        $formatted_adm = '25SNG' . str_pad($row['id'], 4, '0', STR_PAD_LEFT);
                                        $role_label = ucfirst($row['role']);
                                    ?>
                                    <tr class="passenger-row hover:bg-gray-50 transition" data-adm-no="<?php echo htmlspecialchars($formatted_adm); ?>" data-user-id="<?php echo $row['id']; ?>" data-bus-no="<?php echo htmlspecialchars($row['bus_number'] ?? $assigned_bus_num); ?>">
                                        <td class="py-3 px-4">
                                            <?php if (!empty($row['image']) && file_exists("uploads/" . $row['image'])): ?>
                                                <img src="uploads/<?php echo htmlspecialchars($row['image']); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-300" alt="User">
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold text-xs border border-gray-300">
                                                    <i class="fa-solid fa-user"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 font-bold text-gray-900">
                                            <?php echo htmlspecialchars($row['full_name']); ?>
                                            <span class="block text-[10px] font-mono text-gray-400"><?php echo htmlspecialchars($formatted_adm); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-xs">
                                            <span class="px-2 py-0.5 rounded font-semibold <?php echo strtolower($role_label) === 'faculty' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                <?php echo htmlspecialchars($role_label); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-xs font-semibold text-gray-800">
                                            <i class="fa-solid fa-location-dot mr-1 text-red-500"></i><?php echo htmlspecialchars($row['boarding_point']); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <button onclick="verifyAndShowPass({
                                                id: '<?php echo $row['id']; ?>',
                                                name: '<?php echo htmlspecialchars($row['full_name']); ?>',
                                                role: '<?php echo htmlspecialchars($role_label); ?>',
                                                department: '<?php echo htmlspecialchars($dept); ?>',
                                                busNo: '<?php echo htmlspecialchars($assigned_bus_num); ?>',
                                                boarding: '<?php echo htmlspecialchars($row['boarding_point']); ?>',
                                                paymentStatus: '<?php echo htmlspecialchars($pay_status); ?>',
                                                admNo: '<?php echo htmlspecialchars($formatted_adm); ?>',
                                                image: '<?php echo htmlspecialchars($row['image'] ?? ''); ?>'
                                            })" class="btn-check-pass bg-[#1A2E6B] hover:bg-[#142353] text-white text-xs px-3 py-1.5 rounded-lg font-medium shadow-xs transition cursor-pointer">
                                                <i class="fa-solid fa-id-card mr-1"></i> View User
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-gray-500 text-sm">
                                        No registered users found for Bus Route No. <?php echo htmlspecialchars($assigned_bus_num); ?>.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: View Complaints and Warnings View -->
        <div id="tabComplaints" class="p-8 space-y-6 hidden">
            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                <h3 class="font-bold text-lg text-[#1A2E6B] mb-2"><i class="fa-solid fa-triangle-exclamation mr-2 text-amber-500"></i> Complaints and Warnings</h3>
                <p class="text-xs text-gray-500 mb-6">Review any feedback, warnings, or reports related to Bus No. <?php echo htmlspecialchars($assigned_bus_num); ?>.</p>
                
                <div class="space-y-4">
                    <?php if ($driver_warnings_result &&$driver_warnings_result->num_rows > 0): ?>
                        <?php while ($warn =$driver_warnings_result->fetch_assoc()): ?>
                            <div class="bg-red-50 border-l-4 border-red-600 p-4 rounded-r-xl shadow-xs space-y-2">
                                <div class="flex justify-between items-center">
                                    <span class="text-xs font-bold uppercase text-red-700 tracking-wider">Official Admin Warning</span>
                                    <span class="text-xs text-gray-500"><i class="fa-regular fa-clock mr-1"></i> <?php echo htmlspecialchars($warn['sent_date']); ?></span>
                                </div>
                                <div class="text-sm text-gray-800">
                                    <strong>Warning Message:</strong> 
                                    <p class="mt-1 bg-white p-3 rounded border border-red-200 text-gray-700"><?php echo htmlspecialchars($warn['warning_message']); ?></p>
                                </div>
                                <?php if (!empty($warn['complaint_text'])): ?>
                                    <div class="text-xs text-gray-600 pt-1">
                                        <span class="font-semibold">Original User Complaint:</span> "<?php echo htmlspecialchars($warn['complaint_text']); ?>"
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="bg-gray-50 border border-gray-200 rounded-xl p-8 text-center text-gray-500">
                            <i class="fa-solid fa-circle-check text-emerald-500 text-3xl mb-2"></i>
                            <p class="text-sm font-semibold text-gray-700">No active complaints or warnings reported for this route.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- DRIVER PROFILE MODAL -->
    <div id="driverProfileModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden p-4">
        <div class="bg-white rounded-xl shadow-2xl border border-gray-300 w-full max-w-md overflow-hidden relative">
            <button onclick="closeDriverProfileModal()" class="absolute top-3 right-3 text-gray-500 hover:text-gray-800 bg-gray-100 hover:bg-gray-200 rounded-full w-8 h-8 flex items-center justify-center cursor-pointer z-10">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="bg-[#1A2E6B] text-white p-6 text-center">
                <div class="flex justify-center mb-3">
                    <?php if (!empty($driver_image) && file_exists("uploads/" . $driver_image)): ?>
                        <img src="uploads/<?php echo htmlspecialchars($driver_image); ?>" class="w-24 h-24 rounded-full object-cover border-4 border-white/20 shadow-md" alt="Driver Photo">
                    <?php else: ?>
                        <div class="w-24 h-24 rounded-full bg-white/10 text-white flex items-center justify-center text-3xl font-bold border-4 border-white/20 shadow-md">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <h3 class="font-bold text-lg"><?php echo htmlspecialchars($driver_name); ?></h3>
                <span class="inline-block bg-blue-500/40 text-blue-100 text-xs px-3 py-0.5 rounded-full mt-1 font-semibold">Registered Driver</span>
            </div>
            <div class="p-6 space-y-3 text-xs text-gray-700">
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="font-bold text-gray-500">Email Address</span>
                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($driver_email); ?></span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="font-bold text-gray-500">Phone Number</span>
                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($driver_phone); ?></span>
                </div>
                <div class="flex justify-between py-2">
                    <span class="font-bold text-gray-500">Assigned Bus</span>
                    <span class="font-semibold text-blue-900">Bus No. <?php echo htmlspecialchars($assigned_bus_num); ?></span>
                </div>
            </div>
            
            <div class="px-6 pb-4">
                <button onclick="openLicenseModal()" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white text-xs py-2.5 rounded-lg font-bold shadow transition cursor-pointer">
                    <i class="fa-solid fa-id-card mr-2"></i> View License
                </button>
            </div>

            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 text-right">
                <button onclick="closeDriverProfileModal()" class="bg-[#1A2E6B] hover:bg-[#142353] text-white text-xs px-4 py-2 rounded-lg font-semibold cursor-pointer">Close</button>
            </div>
        </div>
    </div>

    <!-- DRIVER LICENSE MODAL -->
    <div id="driverLicenseModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden p-4">
        <div class="bg-white rounded-xl shadow-2xl border border-gray-300 w-full max-w-md overflow-hidden relative">
            <div class="bg-[#1A2E6B] text-white p-4 text-center font-bold text-sm">
                Driver License Verification
            </div>
            <div class="p-6 text-center space-y-4">
                <?php if (!empty($driver_license) && file_exists("uploads/" . $driver_license)): ?>
                    <img src="uploads/<?php echo htmlspecialchars($driver_license); ?>" class="w-full h-64 object-contain rounded-lg border border-gray-300 bg-gray-50" alt="Driver License">
                <?php else: ?>
                    <div class="py-12 bg-gray-50 rounded-lg border border-gray-200 text-gray-500 text-xs">
                        <i class="fa-solid fa-file-shield text-3xl mb-2 text-gray-400"></i>
                        <p>No license document uploaded or found.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 flex justify-between">
                <button onclick="backToProfileModal()" class="bg-gray-600 hover:bg-gray-700 text-white text-xs px-4 py-2 rounded-lg font-semibold cursor-pointer">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Back
                </button>
                <button onclick="closeDriverLicenseModal()" class="bg-[#1A2E6B] hover:bg-[#142353] text-white text-xs px-4 py-2 rounded-lg font-semibold cursor-pointer">Close</button>
            </div>
        </div>
    </div>

    <!-- VERIFICATION MODAL -->
    <div id="passModal" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 hidden p-4">
        <div class="bg-white rounded-xl shadow-2xl border-2 border-gray-300 w-full max-w-sm overflow-hidden relative">
            <button onclick="closePassModal()" class="absolute top-3 right-3 text-gray-500 hover:text-gray-800 bg-gray-100 hover:bg-gray-200 rounded-full w-8 h-8 flex items-center justify-center cursor-pointer z-10">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="p-6 border-8 border-gray-100 rounded-lg text-center">
                <div class="my-2">
                    <h3 id="modalPassTitle" class="font-bold text-base uppercase tracking-wider text-black">User Details</h3>
                </div>
                
                <div class="flex flex-col items-center justify-center my-4 space-y-2">
                    <div class="border-2 border-gray-300 p-1 rounded bg-gray-50 inline-block shadow-sm">
                        <img id="cardPhoto" src="" class="w-32 h-36 object-cover rounded-xs" alt="Photo">
                    </div>
                    <div class="text-xs font-bold text-gray-800 mt-1">
                        Ref ID: <span id="cardAdmNo" class="font-mono text-black">--</span>
                    </div>
                </div>

                <div class="my-3">
                    <h4 id="cardName" class="font-black text-lg text-black uppercase">--</h4>
                    <p id="cardDepartment" class="text-xs font-bold text-gray-700 uppercase">--</p>
                </div>

                <div class="mt-4 space-y-1.5 text-xs text-gray-900 border-t pt-3 border-gray-200 text-left">
                    <div class="grid grid-cols-12"><span class="col-span-4 font-bold">User ID</span><span class="col-span-1">:</span><span id="cardProfileId" class="col-span-7 font-mono font-bold text-black">--</span></div>
                    <div class="grid grid-cols-12"><span class="col-span-4 font-bold">User Role</span><span class="col-span-1">:</span><span id="cardRole" class="col-span-7 font-bold text-indigo-900">--</span></div>
                    <div class="grid grid-cols-12"><span class="col-span-4 font-bold">Bus No.</span><span class="col-span-1">:</span><span id="cardBusNo" class="col-span-7 font-bold text-blue-900">--</span></div>
                    <div class="grid grid-cols-12"><span class="col-span-4 font-bold">Boarding Point</span><span class="col-span-1">:</span><span id="cardBoarding" class="col-span-7">--</span></div>
                    <div id="cardPaymentRow" class="grid grid-cols-12"><span class="col-span-4 font-bold">Payment Status</span><span class="col-span-1">:</span><span id="cardPaymentStatus" class="col-span-7 font-bold uppercase">--</span></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let html5QrcodeScanner = null;
        let isScanning = false;
        const currentAssignedBus = "<?php echo htmlspecialchars($assigned_bus_num); ?>";

        function switchTab(tabName) {
            const tabReg = document.getElementById('tabRegisteredUsers');
            const tabComp = document.getElementById('tabComplaints');
            const navReg = document.getElementById('navRegisteredUsers');
            const navComp = document.getElementById('navComplaints');

            if (tabName === 'registeredUsers') {
                tabReg.classList.remove('hidden');
                tabComp.classList.add('hidden');
                
                navReg.className = "w-full flex items-center px-6 py-3 text-sm transition bg-[#142353] border-l-4 border-white font-semibold cursor-pointer text-left text-white";
                navComp.className = "w-full flex items-center px-6 py-3 text-sm transition hover:bg-[#142353]/50 border-l-4 border-transparent font-semibold cursor-pointer text-left text-gray-300";
            } else {
                tabReg.classList.add('hidden');
                tabComp.classList.remove('hidden');
                
                navComp.className = "w-full flex items-center px-6 py-3 text-sm transition bg-[#142353] border-l-4 border-white font-semibold cursor-pointer text-left text-white";
                navReg.className = "w-full flex items-center px-6 py-3 text-sm transition hover:bg-[#142353]/50 border-l-4 border-transparent font-semibold cursor-pointer text-left text-gray-300";
            }
        }

        function openDriverProfileModal() {
            document.getElementById('driverProfileModal').classList.remove('hidden');
        }

        function closeDriverProfileModal() {
            document.getElementById('driverProfileModal').classList.add('hidden');
        }

        function openLicenseModal() {
            document.getElementById('driverProfileModal').classList.add('hidden');
            document.getElementById('driverLicenseModal').classList.remove('hidden');
        }

        function backToProfileModal() {
            document.getElementById('driverLicenseModal').classList.add('hidden');
            document.getElementById('driverProfileModal').classList.remove('hidden');
        }

        function closeDriverLicenseModal() {
            document.getElementById('driverLicenseModal').classList.add('hidden');
        }

        function toggleScanner() {
            const scannerSec = document.getElementById('scannerSection');
            const btn = document.getElementById('startScanBtn');
            const badge = document.getElementById('scanStatusBadge');

            if (!isScanning) {
                scannerSec.classList.remove('hidden');
                btn.innerHTML = `<i class="fa-solid fa-stop mr-2"></i> Stop Scanner`;
                btn.classList.replace('bg-emerald-600', 'bg-red-600');
                badge.textContent = "Camera Live";

                html5QrcodeScanner = new Html5QrcodeScanner("reader", { 
                    fps: 30, 
                    qrbox: { width: 250, height: 250 },
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true
                    }
                }, false);

                html5QrcodeScanner.render(onScanSuccess, (error) => {});

                isScanning = true;
            } else {
                if (html5QrcodeScanner) {
                    html5QrcodeScanner.clear().then(() => {
                        scannerSec.classList.add('hidden');
                        btn.innerHTML = `<i class="fa-solid fa-camera mr-2"></i> Start Camera Scanner`;
                        btn.classList.replace('bg-red-600', 'bg-emerald-600');
                        badge.textContent = "Camera Off";
                        isScanning = false;
                    }).catch(err => {
                        console.error("Failed to clear scanner", err);
                        scannerSec.classList.add('hidden');
                        isScanning = false;
                    });
                }
            }
        }

        function onScanSuccess(decodedText) {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear().then(() => {
                    document.getElementById('qr_data_input').value = decodedText.trim();
                    document.getElementById('verification-form').submit();
                }).catch(err => {
                    console.error("Failed to clear scanner", err);
                    document.getElementById('qr_data_input').value = decodedText.trim();
                    document.getElementById('verification-form').submit();
                });
            } else {
                document.getElementById('qr_data_input').value = decodedText.trim();
                document.getElementById('verification-form').submit();
            }
        }

        function verifyAndShowPass(user) {
            document.getElementById('cardName').textContent = user.name;
            const role = user.role || 'Passenger';
            document.getElementById('cardRole').textContent = role;
            document.getElementById('cardDepartment').textContent = user.department;
            document.getElementById('cardBusNo').textContent = user.busNo || currentAssignedBus;
            document.getElementById('cardBoarding').textContent = user.boarding;
            document.getElementById('cardAdmNo').textContent = user.admNo || user.id;
            document.getElementById('cardProfileId').textContent = user.id;

            const paymentRow = document.getElementById('cardPaymentRow');
            if (role.toLowerCase() === 'faculty') {
                if (paymentRow) paymentRow.classList.add('hidden');
            } else {
                if (paymentRow) paymentRow.classList.remove('hidden');
                const payStatusElem = document.getElementById('cardPaymentStatus');
                const status = (user.paymentStatus || 'PAID').toUpperCase();
                payStatusElem.textContent = status;
                payStatusElem.className = status === 'PAID' ? "col-span-7 font-bold text-emerald-700" : "col-span-7 font-bold text-red-600";
            }

            let rawImage = user.image ? user.image.trim() : '';
            if (rawImage !== '') {
                if (!rawImage.startsWith('uploads/')) {
                    rawImage = 'uploads/' + rawImage;
                }
                document.getElementById('cardPhoto').src = rawImage;
            } else {
                document.getElementById('cardPhoto').src = "https://via.placeholder.com/150?text=No+Photo";
            }

            document.getElementById('passModal').classList.remove('hidden');
        }

        function closePassModal() {
            document.getElementById('passModal').classList.add('hidden');
        }

        function filterManifest() {
            const input = document.getElementById('manifestSearch').value.toLowerCase();
            document.querySelectorAll('.passenger-row').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
            });
        }
    </script>
</body>
</html>