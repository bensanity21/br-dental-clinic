@echo off
REM tools/backup-daily.bat - run a database backup, for Windows Task Scheduler.
REM
REM Register it once (run this in an Administrator command prompt):
REM
REM   schtasks /Create /TN "BR Dental Clinic Backup" ^
REM     /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\draft for clinic\tools\backup.php\"" ^
REM     /SC DAILY /ST 20:00 /RU SYSTEM
REM
REM Check it:
REM   schtasks /Query /TN "BR Dental Clinic Backup" /V /FO LIST
REM
REM Remove it:
REM   schtasks /Delete /TN "BR Dental Clinic Backup" /F

setlocal
set PHP=C:\xampp\php\php.exe
set SCRIPT=C:\xampp\htdocs\draft for clinic\tools\backup.php
set LOG=C:\xampp\htdocs\draft for clinic\backups\backup.log

echo. >> "%LOG%"
echo ================================================== >> "%LOG%"
echo Run started %DATE% %TIME% >> "%LOG%"

"%PHP%" "%SCRIPT%" >> "%LOG%" 2>&1

if errorlevel 1 (
    echo BACKUP FAILED with exit code %ERRORLEVEL% >> "%LOG%"
    exit /b 1
)

echo Backup finished OK %DATE% %TIME% >> "%LOG%"
exit /b 0
