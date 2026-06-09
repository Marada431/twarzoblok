@echo off
cd /d "%~dp0chat-server"
node --env-file=../.env server.js
pause
