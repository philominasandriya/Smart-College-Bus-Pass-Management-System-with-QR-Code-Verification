<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    exit("DB Connection Failed");
}

if (isset($_POST['field']) && isset($_POST['value'])) {
    $field = $_POST['field']; 
    $value = $conn->real_escape_string($_POST['value']);

    $allowed_fields = ['username', 'email', 'phone'];
    if (!in_array($field, $allowed_fields)) {
        exit("Invalid field");
    }

    $sql = "SELECT id FROM users WHERE $field = '$value'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        echo "EXISTS";
    } else {
        echo "OK";
    }
}
$conn->close();
?>