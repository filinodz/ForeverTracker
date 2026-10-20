@echo off
rem ForeverTracker launcher: finds PHP and Python, starts the website and opens the setup wizard on the first run.
start "" powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -WindowStyle Hidden -File "%~dp0tools\launcher\Launcher.ps1"
