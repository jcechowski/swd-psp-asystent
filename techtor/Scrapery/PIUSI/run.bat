@echo off
cd /d "%~dp0"

if not exist .venv (
    echo Tworze srodowisko wirtualne...
    python -m venv .venv
    .venv\Scripts\pip install -q -r requirements.txt
)

.venv\Scripts\python piusi_scraper.py %*
pause
