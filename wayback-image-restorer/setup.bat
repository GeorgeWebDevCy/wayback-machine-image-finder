@echo off
echo Wayback Image Restorer - Setup
echo ==============================
echo.

where composer >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo Composer is required but was not found in PATH.
    echo Install Composer or run: php composer.phar install --no-dev --prefer-dist --optimize-autoloader
    exit /b 1
)

echo Installing Composer dependencies...
composer install --no-dev --prefer-dist --optimize-autoloader
if %ERRORLEVEL% NEQ 0 exit /b %ERRORLEVEL%

echo.
echo Setup complete!
echo.
echo Next steps:
echo 1. Build or upload the plugin with the vendor folder included
echo 2. Activate the plugin in WordPress
pause
