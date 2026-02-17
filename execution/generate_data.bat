@echo off
set PHP_BIN=C:\wamp64\bin\php\php8.2.29\php.exe
set MOODLE_PATH=C:\wamp64\www\moodle

echo ==========================================
echo Generating Moodle Dummy Data
echo ==========================================

echo.
echo 1. Importing Users...
%PHP_BIN% %MOODLE_PATH%\public\admin\tool\uploaduser\cli\uploaduser.php --file=%MOODLE_PATH%\users.csv --delimiter=,
if %ERRORLEVEL% NEQ 0 (
    echo Error importing users!
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo 2. Generating Courses with Activities...
REM Generate Data Structure course
%PHP_BIN% %MOODLE_PATH%\public\admin\tool\generator\cli\maketestcourse.php --shortname="CS101" --fullname="Data Structure" --size=S --bypasscheck
if %ERRORLEVEL% NEQ 0 (
    echo Error generating Data Structure course!
)

REM Generate OOP course
%PHP_BIN% %MOODLE_PATH%\public\admin\tool\generator\cli\maketestcourse.php --shortname="CS102" --fullname="Object-Oriented Programming" --size=M --bypasscheck
if %ERRORLEVEL% NEQ 0 (
    echo Error generating OOP course!
)

REM Generate Computer Network course
%PHP_BIN% %MOODLE_PATH%\public\admin\tool\generator\cli\maketestcourse.php --shortname="CS103" --fullname="Computer Network" --size=S --bypasscheck
if %ERRORLEVEL% NEQ 0 (
    echo Error generating Computer Network course!
)

echo.
echo ==========================================
echo Data Generation Complete!
echo Login as 'admin' (or manager1/Password123!) to check.
echo ==========================================
pause
