@echo off
setlocal EnableExtensions
title نظام كِنان لإدارة المدرسة
cd /d "%~dp0"

set "PHP_EXE="
if exist "%~dp0runtime\php\php.exe" set "PHP_EXE=%~dp0runtime\php\php.exe"
where php.exe >nul 2>&1 && for /f "delims=" %%I in ('where php.exe') do if not defined PHP_EXE set "PHP_EXE=%%I"
if not defined PHP_EXE (
  for /d %%D in ("%LocalAppData%\Microsoft\WinGet\Packages\PHP.PHP.NTS.8.4_*") do if exist "%%D\php.exe" set "PHP_EXE=%%D\php.exe"
)
if not defined PHP_EXE (
  echo لم يتم العثور على PHP. شغل الأمر التالي ثم أعد المحاولة:
  echo winget install --exact --id PHP.PHP.NTS.8.4
  pause
  exit /b 1
)

for %%I in ("%PHP_EXE%") do set "PHP_DIR=%%~dpI"
"%PHP_EXE%" -d "extension_dir=%PHP_DIR%ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium -d extension=openssl -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
  echo تعذر تهيئة امتدادات PHP المطلوبة.
  pause
  exit /b 1
)

for /f "tokens=2 delims=:" %%A in ('"%SystemRoot%\System32\ipconfig.exe" ^| findstr /i "IPv4"') do (
  for /f "tokens=*" %%B in ("%%A") do if not defined IP set "IP=%%B"
)
set "IP=%IP: =%"
if not defined IP set "IP=127.0.0.1"
set "URL=http://%IP%:8080"
for %%I in ("%PHP_EXE%") do set "PHP_DIR=%%~dpI"

netstat -ano | findstr /R /C:"127.0.0.1:8080" /C:"0.0.0.0:8080" >nul 2>&1
if not errorlevel 1 (
  echo المنفذ 8080 مستخدم حاليًا. أغلق البرنامج الذي يشغله ثم أعد تشغيل كِنان.
  start "" "http://localhost:8080"
  pause
  exit /b 1
)

netsh advfirewall firewall add rule name="Kinan School Local HTTP 8080" dir=in action=allow protocol=TCP localport=8080 profile=private >nul 2>&1

(
  echo ^<!doctype html^>
  echo ^<html lang="ar" dir="rtl"^>^<meta charset="utf-8"^>^<title^>اتصال كِنان^</title^>
  echo ^<style^>body{font-family:Tahoma,Arial;background:#eef4fb;text-align:center;margin:0;display:grid;place-items:center;min-height:100vh}main{background:white;border-radius:18px;padding:28px;box-shadow:0 12px 40px #b7c5d3}.url{direction:ltr;font-size:20px;font-weight:bold;color:#1458a8}img{width:260px;height:260px;border:12px solid white}^</style^>
  echo ^<main^>^<h1^>اتصال نظام كِنان من الهاتف^</h1^>^<div class="url"^>%URL%^</div^>^<br^>^<img alt="رمز QR" src="https://quickchart.io/qr?size=260^&amp;text=%URL%"^>^<p^>اتصل بنفس شبكة الواي فاي وافتح الرابط أو امسح الرمز^</p^>^</main^>
  echo ^</html^>
) > "%TEMP%\kinan-address.html"

echo ============================================
echo          نظام كِنان لإدارة المدرسة
echo ============================================
echo عنوان الدخول من هذا الجهاز: http://localhost:8080
echo عنوان الدخول من الهاتف: %URL%
echo.
echo ابق نافذة التشغيل مفتوحة أثناء الاستخدام.
echo.

start "" "%TEMP%\kinan-address.html"
start "" "http://localhost:8080"
"%PHP_EXE%" -d "extension_dir=%PHP_DIR%ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium -d extension=openssl -S 0.0.0.0:8080 -t "%~dp0"
pause
