<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

$conn = new mysqli($servername, $username, $password, $dbname);


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = $conn->real_escape_string($_POST['full_name'] ?? '');
    $username = $conn->real_escape_string($_POST['username'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT); 
    $role = $conn->real_escape_string($_POST['role'] ?? '');
    $designation = $conn->real_escape_string($_POST['designation'] ?? '');
    $department = $conn->real_escape_string($_POST['department'] ?? '');
    $course = $conn->real_escape_string($_POST['course'] ?? '');
    $batch = $conn->real_escape_string($_POST['batch'] ?? '');
    $phone = $conn->real_escape_string($_POST['phone'] ?? ''); 
    $bus_number = !empty($_POST['bus_number']) ? $conn->real_escape_string($_POST['bus_number']) : NULL;
$boarding_point = !empty($_POST['boarding_point']) ? $conn->real_escape_string($_POST['boarding_point']) : NULL;
$guardian_name = !empty($_POST['guardian_name']) ? $conn->real_escape_string($_POST['guardian_name']) : NULL;
$guardian_phone = !empty($_POST['guardian_phone']) ? $conn->real_escape_string($_POST['guardian_phone']) : NULL;
$guardian_relationship = !empty($_POST['guardian_relationship']) ? $conn->real_escape_string($_POST['guardian_relationship']) : NULL;

    // Handle Image Upload
    $image_name = NULL;
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true); 
        }
        $image_name = time() . "_" . basename($_FILES["image"]["name"]);
        $target_file = $target_dir . $image_name;
        move_uploaded_file($_FILES["image"]["tmp_name"], $target_file);
    }
    // Handle Driving License Upload (Driver Only)
$driving_license_name = NULL;
if (isset($_FILES['driving_license']) && $_FILES['driving_license']['error'] == 0) {
    $driving_license_name = time() . "_license_" . basename($_FILES["driving_license"]["name"]);
    $target_file = $target_dir . $driving_license_name;
    move_uploaded_file($_FILES["driving_license"]["tmp_name"], $target_file);
}

    // Insert into database
    $sql = "INSERT INTO users (full_name, username, email, password, role, designation, department, course, batch, phone, guardian_name, guardian_phone, guardian_relationship, bus_number, boarding_point, image,driving_license) 
            VALUES ('$full_name', '$username', '$email', '$password', '$role', '$designation', '$department', '$course', '$batch', '$phone', '$guardian_name', '$guardian_phone', '$guardian_relationship', '$bus_number', '$boarding_point', " . ($image_name ? "'$image_name'" : "NULL") . "," . ($driving_license_name ? "'$driving_license_name'" : "NULL") . ")";

    if ($conn->query($sql) === TRUE) {
        echo "<script>
            alert('Registration request submitted successfully!');
            window.location.href = 'Login.html';
        </script>";
        exit();
    } else {
        echo "<h3 style='color: red;'>MySQL Error: " . $conn->error . "</h3>";
    }

    $conn->close();
}
?>