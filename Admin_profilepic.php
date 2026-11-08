<?php
// Database Connection details
$host = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass"; // Replace with your actual database name

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";

// Handle form submission
if (isset($_POST['submit'])) {
    $admin_username = trim($_POST['username']);

    // Check if the admin username exists in the users table
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $admin_username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Check if file was uploaded without errors
        if (isset($_FILES['admin_image']) && $_FILES['admin_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['admin_image']['tmp_name'];
            $fileName = $_FILES['admin_image']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // Allowed image extensions
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($fileExtension, $allowedExtensions)) {
                // Folder where images will be stored
                $uploadFileDir = './uploads/';
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }

                // Generate a unique file name to avoid collisions
                $newFileName = 'admin_' . preg_replace('/[^a-zA-Z0-9]/', '_', $admin_username) . '_' . time() . '.' . $fileExtension;
                $dest_path = $uploadFileDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    // Update the existing 'image' column for this user
                    $update_stmt = $conn->prepare("UPDATE users SET image = ? WHERE username = ?");
                    $update_stmt->bind_param("ss", $dest_path, $admin_username);

                    if ($update_stmt->execute()) {
                        $message = "<p style='color: green;'>Admin profile photo uploaded and saved successfully!</p>";
                    } else {
                        $message = "<p style='color: red;'>Database update error: " . $conn->error . "</p>";
                    }
                    $update_stmt->close();
                } else {
                    $message = "<p style='color: red;'>Error moving the uploaded file to the destination folder.</p>";
                }
            } else {
                $message = "<p style='color: red;'>Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.</p>";
            }
        } else {
            $message = "<p style='color: red;'>Please select a valid image file to upload.</p>";
        }
    } else {
        $message = "<p style='color: red;'>Error: Username not found in the database.</p>";
    }
    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Admin Profile Photo</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; background-color: #f4f7f6; }
        .container { max-width: 400px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0px 0px 10px rgba(0,0,0,0.1); }
        input[type="text"], input[type="file"] { width: 100%; padding: 8px; margin: 10px 0 15px 0; box-sizing: border-box; }
        button { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; width: 100%; }
        button:hover { background-color: #45a049; }
    </style>
</head>
<body>

<div class="container">
    <h2>Upload Admin Photo</h2>
    <?php echo $message; ?>
    <form action="" method="POST" enctype="multipart/form-data">
        <label for="username">Admin Username:</label>
        <input type="text" name="username" required>

        <label for="admin_image">Select Image:</label>
        <input type="file" name="admin_image" accept="image/*" required>

        <button type="submit" name="submit">Upload & Save</button>
    </form>
</div>

</body>
</html>