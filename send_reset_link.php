<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include Composer autoloader from your vendor folder
require 'vendor/autoload.php';

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $conn->real_escape_string($_POST['email']);

    $sql = "SELECT * FROM users WHERE email='$email'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $token = bin2hex(random_bytes(16)); // 32-character secure token
        
        // Use MySQL NOW() and DATE_ADD to avoid timezone mismatches
        $sql_update = "UPDATE users SET reset_token='$token', reset_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE email='$email'";
        $conn->query($sql_update);

        // Hardcoded IP for mobile testing
        $reset_link = "http://192.168.0.106/College_Bus/reset_password.php?token=" . $token;
        // PHPMailer Configuration
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'philominasandriya@gmail.com';  // Your Gmail address
            $mail->Password   = 'sqtf fjie ykwp nnau';          // Your App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Sender and Recipient (setFrom must match Username to prevent spam issues)
            $mail->setFrom('philominasandriya@gmail.com', 'SNGCE Bus Pass System');
            $mail->addAddress($email);
            $mail->addReplyTo('philominasandriya@gmail.com', 'Support Team');

            // Email Content (Formatted professionally to bypass spam filters)
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request - SNGCE Bus Pass';
            $mail->Body    = '
                <div style="font-family: Arial, sans-serif; padding: 20px; color: #333; max-width: 600px; margin: auto; border: 1px solid #ddd; border-radius: 8px;">
                    <h2 style="color: #1A2E6B; text-align: center;">Password Reset Request</h2>
                    <p>Hello,</p>
                    <p>We received a request to reset the password for your Smart College Bus Pass account. Click the button below to proceed:</p>
                    <p style="text-align: center; margin: 30px 0;">
                        <a href="' . $reset_link . '" style="background-color: #1A2E6B; color: white; padding: 12px 25px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">Reset Password</a>
                    </p>
                    <p>If you did not request this password reset, please ignore this email. This link is valid for 30 minutes.</p>
                    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                    <p style="font-size: 12px; color: #777; text-align: center;">Smart College Bus Pass Management System</p>
                </div>';
            
            $mail->AltBody = "Hello,\n\nWe received a request to reset your password. Copy and paste the following link into your browser:\n" . $reset_link . "\n\nThis link is valid for 30 minutes.";

            $mail->send();
            echo "<script>alert('Password reset link has been successfully sent to your email.'); window.location.href='Login.html';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Mailer Error: {$mail->ErrorInfo}'); window.location.href='forgot_password.php';</script>";
        }

    } else {
        echo "<script>alert('This email ID is invalid or does not exist in our database. Please enter a correct registered email ID.'); window.location.href='forgot_password.php';</script>";
    }
}
$conn->close();
?>