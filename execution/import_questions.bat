@echo off
set PHP_BIN=C:\wamp64\bin\php\php8.2.29\php.exe
set MOODLE_PATH=C:\wamp64\www\moodle

echo ==========================================
echo Moodle Question Bank Importer
echo ==========================================
echo.

set /p COURSE_ID="Enter Course ID to import into: "

if "%COURSE_ID%"=="" (
    echo Course ID cannot be empty.
    pause
    exit /b 1
)

echo.
echo Importing questions from questions.xml into Course ID %COURSE_ID%...
%PHP_BIN% %MOODLE_PATH%\admin\cli\import_question_bank.php --courseid=%COURSE_ID% --file=%MOODLE_PATH%\questions.xml

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Error importing questions!
) else (
    echo.
    echo Successfully imported!
)

pause
