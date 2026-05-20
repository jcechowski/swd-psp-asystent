#!/usr/bin/env bash
# Uruchomienie PIUSI scraper — automatycznie tworzy venv jeśli nie istnieje
cd "$(dirname "$0")"

if [ ! -d .venv ]; then
    echo "Tworzę środowisko wirtualne..."
    python3 -m venv .venv
    .venv/bin/pip install -q -r requirements.txt
fi

.venv/bin/python piusi_scraper.py "$@"
