<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "sngce_bus_pass";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Safely retrieve token from GET or POST using traditional isset for PHP compatibility
$token = isset($_GET['token']) ? $_GET['token'] : (isset($_POST['token']) ? $_POST['token'] : '');
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_pass = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_pass = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    // Password validation rules: At least 8 characters, 1 uppercase, 1 lowercase, 1 digit, 1 special char
    $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/';

    if ($new_pass !== $confirm_pass) {
        $error = "New Password and Confirm Password do not match.";
    } elseif (!preg_match($pattern, $new_pass)) {
        $error = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one digit, and one special character.";
    } else {
        // Check token validity using MySQL's native time
        $stmt = $conn->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_expires > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            // Password meets rules, proceed to hash and update
            $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
            
            $update_stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE reset_token = ?");
            $update_stmt->bind_param("ss", $hashed_password, $token);
            $update_stmt->execute();

            echo "<script>alert('Password successfully updated! You can now login.'); window.location.href='Login.html';</script>";
            exit();
        } else {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Smart College Bus Pass Management System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-[#F8F5F0] font-sans m-0 p-0 min-h-screen flex flex-col justify-between">

    <!-- Header with Emblem on Left -->
    <header class="w-full bg-[#1A2E6B] px-6 py-4 flex justify-between items-center shadow-md">
        <div class="flex items-center space-x-3">
            <a href="index.html" class="flex items-center space-x-2">
                <img src="Emblem.jpg" alt="SNGCE Emblem" class="h-10 w-auto object-contain">
                <span class="text-white font-bold text-sm tracking-wider hidden sm:inline">SNGCE BUS PASS</span>
            </a>
        </div>
    </header>

    <!-- Main Content Area with Centered Card -->
    <main class="flex-grow flex items-center justify-center px-4 py-12">
        <div class="max-w-md w-full bg-white p-8 rounded-xl shadow-xl border border-[#1A2E6B]/20">
            
            <div class="text-center mb-6">
                <h1 class="text-3xl font-bold text-[#1A2E6B]">Reset Password</h1>
                <p class="text-gray-500 text-xs tracking-wider uppercase mt-1">Min 8 chars (1 Upper, 1 Lower, 1 Digit, 1 Special)</p>
            </div>

            <?php if(!empty($error)) { ?>
                <div class="mb-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded text-xs text-center font-medium">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php } ?>
            
            <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST" class="space-y-5">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <!-- New Password Field with Toggle Eye -->
                <div>
                    <div class="relative flex items-center">
                        <span class="absolute left-4 text-gray-400">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                        <input type="password" id="password" name="password" minlength="8" required 
                            placeholder="New Password" 
                            class="w-full pl-11 pr-12 py-3 bg-[#F8F5F0] border border-gray-300 rounded-lg text-gray-800 placeholder-gray-400 focus:outline-none focus:border-[#1A2E6B] focus:ring-1 focus:ring-[#1A2E6B] transition text-sm">
                        <button type="button" onclick="togglePassword('password', 'eyeIcon1')" class="absolute right-4 text-gray-400 hover:text-[#1A2E6B] focus:outline-none">
                            <i id="eyeIcon1" class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Confirm Password Field with Toggle Eye -->
                <div>
                    <div class="relative flex items-center">
                        <span class="absolute left-4 text-gray-400">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required 
                            placeholder="Confirm Password" 
                            class="w-full pl-11 pr-12 py-3 bg-[#F8F5F0] border border-gray-300 rounded-lg text-gray-800 placeholder-gray-400 focus:outline-none focus:border-[#1A2E6B] focus:ring-1 focus:ring-[#1A2E6B] transition text-sm">
                        <button type="button" onclick="togglePassword('confirm_password', 'eyeIcon2')" class="absolute right-4 text-gray-400 hover:text-[#1A2E6B] focus:outline-none">
                            <i id="eyeIcon2" class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Update Password Button -->
                <div class="pt-4 flex justify-center">
                    <button type="submit" class="bg-[#1A2E6B] text-white px-6 py-2 rounded-lg font-semibold hover:bg-[#142353] transition shadow-md text-xs tracking-wide">
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full bg-[#1A2E6B] text-white text-center py-4 text-xs tracking-wide">
        &copy; 2026 Smart College Bus Pass Management System. All Rights Reserved.
    </footer>

    <!-- JavaScript for Toggle Password Visibility -->
    <script>
        function togglePassword(fieldId, iconId) {
            const passwordInput = document.getElementById(fieldId);
            const eyeIcon = document.getElementById(iconId);
            
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
</body>
</html>