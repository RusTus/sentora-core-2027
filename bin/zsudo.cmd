@echo off
rem zsudo for the panel on Windows: leaves the request for the Sentora service (bin\zsudo.php).
"%~dp0..\..\bin\php\php.exe" -q "%~dp0zsudo.php" %*
