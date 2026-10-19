@echo off
rem Forever Tracker: runs the analysis (official content vs. your server) and refreshes the website.
rem Add --wowhead to name the quests your server does not know (slow, cached).
cd /d "%~dp0"
python tools\tracker.py analyse %*
if errorlevel 1 pause
