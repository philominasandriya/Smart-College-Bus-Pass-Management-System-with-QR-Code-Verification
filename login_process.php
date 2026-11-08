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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $conn->real_escape_string($_POST['username']);
    $pass = $_POST['password'];

    $sql = "SELECT * FROM users WHERE username='$user'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        
        // Verify password hash
        if (password_verify($pass, $row['password'])) {
            
            // Check approval status for non-admin users (or admins too)
            if ($row['status'] == 'approved') {
                $_SESSION['user_id'] = $row['id'];             // ഡാറ്റാബേസിലെ id ഇവിടെ സെറ്റ് ചെയ്യുന്നു
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['full_name'] = $row['full_name'];   // ഡ്രൈവറുടെ പേര് കിട്ടാൻ
                $_SESSION['bus_number_raw'] = $row['bus_number']; // ഡ്രൈവറുടെ ബസ് നമ്പർ കിട്ടാൻ
              if ($row['role'] == 'admin') {
    header("Location: admin_dashboard.php");
} elseif ($row['role'] == 'driver') {
    header("Location: driver_dashboard.php");
} else {
    header("Location: users_dashboard.php");
}
exit();
            } else {
                echo "<script>alert('Your account is pending admin approval.'); window.location.href='Login.html';</script>";
            }

        } else {
            echo "<script>alert('Invalid password!'); window.location.href='Login.html';</script>";
        }
    } else {
        echo "<script>alert('User not found!'); window.location.href='Login.html';</script>";
    }
}
$conn->close();
?>