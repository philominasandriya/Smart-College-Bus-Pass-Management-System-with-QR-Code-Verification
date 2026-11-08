<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include central database connection file
include 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Routes & Fee Structure - Smart College Bus Pass Management System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-[#F4F6F9] font-sans m-0 p-0 min-h-screen flex flex-col justify-between">

    <!-- Header with Emblem on Left and Home Button on Right -->
    <header class="w-full bg-[#1A2E6B] px-6 py-4 flex justify-between items-center shadow-md">
        <!-- Emblem / Logo on Left -->
        <div class="flex items-center space-x-3">
            <a href="#" class="flex items-center space-x-2">
                <img src="Emblem.jpg" alt="SNGCE Emblem" class="h-10 w-auto object-contain rounded">
                <span class="text-white font-bold text-sm tracking-wider hidden sm:inline">SNGCE BUS PASS</span>
            </a>
        </div>

        <!-- Home Button on Right -->
        <a href="index.html" class="border border-white/40 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-white hover:text-[#1A2E6B] transition shadow-sm">
            Back
        </a>
    </header>

    <!-- Main Content Area -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-10 space-y-12">
        
        <!-- Page Title Section -->
        <div class="text-center">
            <h1 class="text-3xl font-bold text-[#1A2E6B]">Bus Routes & Fee Structure</h1>
            <p class="text-gray-500 text-xs tracking-wider uppercase mt-1">Academic Year 2026-2027 (Odd Semester)</p>
        </div>

      <!-- Section 1: Bus Routes Schedule (Dynamic from database) -->
<div class="bg-white rounded-xl shadow-xl border border-[#1A2E6B]/15 overflow-hidden">
    <div class="bg-[#1A2E6B] px-6 py-4">
        <h2 class="text-white font-semibold text-base uppercase tracking-wider flex items-center gap-2">
            <i class="fa-solid fa-bus"></i> Bus Routes & Timings
        </h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse text-sm">
            <thead>
                <tr class="bg-gray-100 text-[#1A2E6B] border-b border-gray-200 text-xs uppercase tracking-wider">
                    <th class="p-4 font-semibold">Bus No. & Reg. No.</th>
                    <th class="p-4 font-semibold">Starting Point</th>
                    <th class="p-4 font-semibold">Route Details</th>
                    <th class="p-4 font-semibold">Starting Time</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-gray-700">
                <?php
                // Updated query using 'id' for ordering since 'bus_no_reg' combines number and registration
                $route_result = $conn->query("SELECT * FROM bus_routes ORDER BY id ASC");

                if ($route_result && $route_result->num_rows > 0) {
                    while ($route_row = $route_result->fetch_assoc()) {
                        echo "<tr class='hover:bg-gray-50 transition'>";
                        echo "<td class='p-4 font-medium text-[#1A2E6B]'>" . htmlspecialchars($route_row['bus_no_reg']) . "</td>";
                        echo "<td class='p-4'>" . htmlspecialchars($route_row['starting_point']) . "</td>";
                        echo "<td class='p-4'>" . htmlspecialchars($route_row['route_details']) . "</td>";
                        echo "<td class='p-4 font-semibold text-gray-800'>" . htmlspecialchars($route_row['starting_time']) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='4' class='p-4 text-center text-gray-500'>No bus schedules available.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
        <!-- Section 2: College Bus Fee Structure (Dynamic from database) -->
        <div class="bg-white rounded-xl shadow-xl border border-[#1A2E6B]/15 overflow-hidden">
            <div class="bg-[#1A2E6B] px-6 py-4">
                <h2 class="text-white font-semibold text-base uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-indian-rupee-sign"></i> College Bus Fare Structure (AY-2026-2027)
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-gray-100 text-[#1A2E6B] border-b border-gray-200 text-xs uppercase tracking-wider">
                            <th class="p-4 font-semibold">Bus No.</th>
                            <th class="p-4 font-semibold">Starting Point (Zone)</th>
                            <th class="p-4 font-semibold">Fare Stage</th>
                            <th class="p-4 font-semibold">Instalment Amount</th>
                            <th class="p-4 font-semibold">Consolidated Semester Fee</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-gray-700">
                        <?php
                        $result = $conn->query("SELECT * FROM bus_fares ORDER BY bus_no ASC");

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                echo "<tr class='hover:bg-gray-50 transition'>";
                                echo "<td class='p-4 font-medium text-[#1A2E6B]'>" . htmlspecialchars($row['bus_no']) . "</td>";
                                echo "<td class='p-4'>" . htmlspecialchars($row['starting_point']) . "</td>";
                                echo "<td class='p-4 font-medium'>" . htmlspecialchars($row['fare_stage']) . "</td>";
                                echo "<td class='p-4'>" . htmlspecialchars($row['installment_amount']) . "</td>";
                                echo "<td class='p-4 font-semibold text-gray-800'>" . htmlspecialchars($row['consolidated_fee']) . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5' class='p-4 text-center text-gray-500'>No bus routes or fee records available.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="w-full bg-[#1A2E6B] text-white text-center py-4 text-xs tracking-wide">
        &copy; 2026 Smart College Bus Pass Management System. All Rights Reserved.
    </footer>

</body>
</html>