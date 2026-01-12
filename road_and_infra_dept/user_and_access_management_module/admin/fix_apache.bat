@echo off
echo Restarting Apache...
cd /d C:\xampp
apache\bin\httpd.exe -k stop
timeout /t 3 /nobreak >nul
apache\bin\httpd.exe -k start
echo Apache restarted!
echo.
echo Testing PHP...
timeout /t 2 /nobreak >nul
start http://localhost/LGU-kristine/road_and_infra_dept/user_and_access_management_module/admin/test-simple.php
pause
