@echo off
rem ForeverTracker: runs the analysis (official content vs. your server), then the locator (unspawned creatures
rem and their official positions, asking Wowhead only for what is not cached yet), and refreshes the website.
rem Add --wowhead to also name the quests your server does not know (slow, cached).
cd /d "%~dp0"
python -X utf8 tools\tracker.py analyse %*
if errorlevel 1 goto failed
python -X utf8 tools\tracker.py locate
if errorlevel 1 goto failed
exit /b 0
:failed
pause
