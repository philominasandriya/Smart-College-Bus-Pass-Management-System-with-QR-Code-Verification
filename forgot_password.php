<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart College Bus Pass Management System - Forgot Password</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-[#F8F5F0] font-sans m-0 p-0 min-h-screen flex flex-col justify-between">

    <!-- Header with Emblem on Left and Back to Login Button on Right -->
    <header class="w-full bg-[#1A2E6B] px-6 py-4 flex justify-between items-center shadow-md">
        <!-- Emblem / Logo on Left -->
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
                <h1 class="text-3xl font-bold text-[#1A2E6B]">Forgot Password</h1>
                <p class="text-gray-500 text-xs tracking-wider uppercase mt-1">Smart College Bus Pass Management System</p>
            </div>
            
            <form action="send_reset_link.php" method="POST" class="space-y-5">
                
                <!-- Email Field -->
                <div>
                    <div class="relative flex items-center">
                        <span class="absolute left-4 text-gray-400">
                            <i class="fa-regular fa-envelope"></i>
                        </span>
                        <input type="email" id="email" name="email" required 
                            placeholder="Enter your registered email ID" 
                            class="w-full pl-11 pr-4 py-3 bg-[#F8F5F0] border border-gray-300 rounded-lg text-gray-800 placeholder-gray-400 focus:outline-none focus:border-[#1A2E6B] focus:ring-1 focus:ring-[#1A2E6B] transition text-sm">
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-4 flex justify-center">
                    <button type="submit" class="bg-[#1A2E6B] text-white px-6 py-2 rounded-lg font-semibold hover:bg-[#142353] transition shadow-md text-xs tracking-wide">
                        Send Reset Link
                    </button>
                </div>

                <!-- Divider -->
                <div class="relative flex py-1 items-center">
                    <div class="flex-grow border-t border-gray-300"></div>
                    <span class="flex-shrink mx-4 text-gray-400 text-xs uppercase">or</span>
                    <div class="flex-grow border-t border-gray-300"></div>
                </div>

                <!-- Return to Login Option -->
                <div class="pt-2 flex justify-center">
                    <a href="Login.html" class="inline-block text-center bg-transparent border-2 border-[#1A2E6B] text-[#1A2E6B] px-6 py-2 rounded-lg font-semibold hover:bg-[#1A2E6B] hover:text-white transition text-xs tracking-wide">
                        Remember Password? Login
                    </a>
                </div>

            </form>
        </div>
    </main>

    <!-- Footer -->
    <footer class="w-full bg-[#1A2E6B] text-white text-center py-4 text-xs tracking-wide">
        &copy; 2026 Smart College Bus Pass Management System. All Rights Reserved.
    </footer>

</body>
</html>