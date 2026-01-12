@echo off
echo Restarting XAMPP Apache...
cd /d C:\xampp
xampp_start.exe
echo.
echo Please click "Stop" next to Apache, then "Start" to restart it
echo After restarting, test PHP at:
echo http://localhost/LGU-kristine/road_and_infra_dept/user_and_access_management_module/admin/test-simple.php
pause
