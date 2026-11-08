<?php
session_start();
// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

include('db_connect.php'); // Ensure this matches your actual database connection filename

$success_msg = "";
$error_msg = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_SESSION['username'];
    $complaint_text = trim($_POST['complaint_text']);

    if (!empty($complaint_text)) {
        // 1. Fetch the user's assigned bus number from the users table
        $user_bus_num = '';
        $stmt_bus = $conn->prepare("SELECT bus_number FROM users WHERE username = ? LIMIT 1");
        $stmt_bus->bind_param("s", $username);
        $stmt_bus->execute();
        $res_bus = $stmt_bus->get_result();
        if ($row_bus = $res_bus->fetch_assoc()) {
            $user_bus_num = $row_bus['bus_number'];
        }
        $stmt_bus->close();

        // 2. Insert complaint along with the fetched bus_number into the database
        $stmt = $conn->prepare("INSERT INTO complaint (username, complaint_text, bus_number, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $username, $complaint_text, $user_bus_num);
        
        if ($stmt->execute()) {
            $success_msg = "Your complaint has been submitted successfully.";
        } else {
            $error_msg = "Error submitting complaint. Please try again.";
        }
        $stmt->close();
    } else {
        $error_msg = "Complaint field cannot be empty.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaint - SNGCE Bus Pass System</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
        }

        .sidebar {
            width: 250px;
            min-width: 250px;
            background: #1A2E6B;
            color: white;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow-y: auto;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 15px 22px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s;
        }

        .sidebar-link i {
            width: 25px;
            margin-right: 10px;
        }

        .sidebar-link:hover {
            background: #142353;
            color: white;
        }

        .sidebar-link.active {
            background: #142353;
            color: white;
            border-left: 4px solid white;
            font-weight: 600;
        }

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 22px 30px;
            border-bottom: 1px solid #e5e7eb;
            color: #1e293b;
            font-size: 22px;
            font-weight: bold;
        }

        .content-area {
            padding: 35px;
        }

        .complaint-card {
            background: white;
            max-width: 850px;
            margin: 0 auto;
            padding: 30px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .complaint-card textarea {
            width: 100%;
            min-height: 180px;
            padding: 15px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            resize: vertical;
            outline: none;
        }

        .complaint-card textarea:focus {
            border-color: #1A2E6B;
            box-shadow: 0 0 0 2px rgba(26,46,107,0.1);
        }

        .submit-btn {
            width: 100%;
            padding: 13px;
            margin-top: 18px;
            background: #1A2E6B;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .submit-btn:hover {
            background: #142353;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .logout-btn {
            display: block;
            margin: 15px;
            padding: 12px;
            text-align: center;
            background: rgba(220,38,38,0.2);
            color: #fca5a5;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
        }

        .logout-btn:hover {
            background: #dc2626;
            color: white;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 190px;
                min-width: 190px;
            }

            .main-content {
                margin-left: 190px;
            }

            .content-area {
                padding: 15px;
            }

            .complaint-card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="sidebar">

    <div>
        <div class="text-center p-6 border-b border-white/10">
            <h1 class="font-bold text-lg tracking-wider">
                USER PORTAL
            </h1>
            <p class="text-xs text-gray-300 mt-2">
                SNGCE Bus Pass System
            </p>
        </div>

        <nav class="mt-4">

            <a href="users_dashboard.php?tab=overview"
               class="sidebar-link">
                <i class="fa-solid fa-id-card"></i>
                My Bus Pass & Profile
            </a>

            <a href="users_dashboard.php?tab=payments"
               class="sidebar-link">
                <i class="fa-solid fa-credit-card"></i>
                Fee Payment
            </a>

            <a href="users_dashboard.php?tab=bus_driver"
               class="sidebar-link">
                <i class="fa-solid fa-bus"></i>
                Assigned Bus & Driver
            </a>

            <a href="users_dashboard.php?tab=urgent_pass"
               class="sidebar-link">
                <i class="fa-solid fa-bolt"></i>
                Urgent Pass Request
            </a>

            <a href="users_dashboard.php?tab=emergency"
               class="sidebar-link">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Emergency Pass Alerts
            </a>

            <a href="users_dashboard.php?tab=feedback"
               class="sidebar-link">
                <i class="fa-solid fa-comments"></i>
                Feedback
            </a>

            <a href="complaint.php"
               class="sidebar-link active">
                <i class="fa-solid fa-comment-dots"></i>
                Complaint
            </a>

        </nav>
    </div>

    <div class="border-t border-white/10">
        <a href="Login.html" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket mr-2"></i>
            Logout
        </a>
    </div>

</div>

<div class="main-content">

    <header class="top-header">
        Complaint
    </header>

    <main class="content-area">

        <div class="complaint-card">

            <h2 class="text-xl font-bold text-[#1A2E6B] mb-2">
                <i class="fa-solid fa-comments mr-2"></i>
                Submit a Complaint
            </h2>

            <p class="text-sm text-gray-500 mb-6">
                Write your transport-related complaint below.
                Your complaint will be submitted to the administrator.
            </p>

            <?php if (!empty($success_msg)): ?>
                <div class="alert-success">
                    <i class="fa-solid fa-circle-check mr-2"></i>
                    <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="alert-danger">
                    <i class="fa-solid fa-circle-exclamation mr-2"></i>
                    <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>

            <form action="complaint.php" method="POST">

                <label class="block text-sm font-semibold text-gray-700 mb-3">
                    Write Your Complaint
                </label>

                <textarea
                    name="complaint_text"
                    placeholder="Type your complaint here..."
                    required></textarea>

                <button type="submit" class="submit-btn">
                    <i class="fa-solid fa-paper-plane mr-2"></i>
                    Submit Complaint
                </button>

            </form>

        </div>

    </main>

</div>

</body>
</html>