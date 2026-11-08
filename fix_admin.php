<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Properly hash 'admin123'
$new_password = password_hash('admin123', PASSWORD_DEFAULT);

// Update or insert the admin account cleanly
$check = $conn->query("SELECT * FROM users WHERE username='admin'");
if ($check->num_rows > 0) {
    $sql = "UPDATE users SET password='$new_password', status='approved', role='admin' WHERE username='admin'";
    if ($conn->query($sql) === TRUE) {
        echo "<h3 style='color: green;'>Admin password successfully updated! You can now log in with username: <b>admin</b> and password: <b>admin123</b></h3>";
    } else {
        echo "Error updating record: " . $conn->error;
    }
} else {
    $sql = "INSERT INTO users (full_name, username, email, password, role, department, course, batch, dob, blood_group, address, phone, guardian_name, guardian_phone, guardian_relationship, bus_number, boarding_point, status) 
            VALUES ('System Administrator', 'admin', 'admin@sngce.ac.in', '$new_password', 'admin', 'Admin', 'N/A', 'N/A', '2000-01-01', 'O+', 'College Office', '9876543210', 'N/A', 'N/A', 'N/A', 'N/A', 'N/A', 'approved')";
    if ($conn->query($sql) === TRUE) {
        echo "<h3 style='color: green;'>Admin account created successfully! Username: <b>admin</b>, Password: <b>admin123</b></h3>";
    } else {
        echo "Error inserting record: " . $conn->error;
    }
}

$conn->close();
echo "<br><a href='Login.html'>Go to Login Page</a>";
?>