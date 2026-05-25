#!/usr/bin/env python3
"""
Sync stanów magazynowych Firmao + Tarnawa → Shoper.

Logika:
  stockTechtor  = Firmao currentStoreState (magazyn własny)
  stockTarnawa  = scraper dystrybutorypaliw.pl (dostawca)
  stockTotal    = stockTechtor + stockTarnawa   → Shoper stock (max do kupienia)

Dostępność (availability_id):
  0       → 9 (trwale niedostępny)
  1–9     → 2 (dostępny)
  10–19   → 4 (średnia ilość)
  20+     → 5 (duża ilość)

Czas wysyłki (delivery_id):
  stockTechtor > 0  → 1 (24 godziny)   — mamy na miejscu
  stockTechtor = 0  → 2 (48 godzin)    — trzeba ściągnąć od dostawcy

Użycie:
  python3 sync-stock.py              — pełny sync
  python3 sync-stock.py --dry-run    — podgląd zmian bez zapisu
  python3 sync-stock.py --code F00730000  — sync jednego produktu

CRON (codziennie o 4:00):
  0 4 * * * cd /root/projects/projekty/techtor/shoper && python3 sync-stock.py >> /var/log/shoper-stock-sync.log 2>&1
"""
import os
import sys
import json
import time
import argparse
import logging
from datetime import datetime
from pathlib import Path

try:
    import requests
except ImportError:
    print("pip install requests")
    sys.exit(1)

# ── Config ──────────────────────────────────────────────────────────────────
SHOPER_URL      = os.environ.get("SHOPER_URL", "https://techtor.pl/webapi/rest")
SHOPER_USER     = os.environ.get("SHOPER_API_LOGIN", "api_user")
SHOPER_PASS     = os.environ.get("SHOPER_API_PASSWORD", "&PnFY3Gg2kn^3Xn4GV3G")

FIRMAO_URL      = os.environ.get("FIRMAO_COMPANY_URL", "https://system.firmao.pl/techtor/svc/v1")
FIRMAO_EMAIL    = os.environ.get("FIRMAO_API_EMAIL", "techtor.api@firmao.pl")
FIRMAO_TOKEN    = os.environ.get("FIRMAO_API_TOKEN", "41c506a76ba94cf0")

TARNAWA_DIR     = os.environ.get("TARNAWA_OUTPUT_DIR",
    str(Path(__file__).resolve().parent.parent / "Scrapery" / "TARNAWA" / "output"))

# Shoper IDs
AVAIL_UNAVAILABLE   = 9   # trwale niedostępny (can_buy=0)
AVAIL_ON_ORDER      = 6   # dostępny na zamówienie (can_buy=0) — Tarnawa "on-backorder"
AVAIL_AVAILABLE     = 2   # dostępny
AVAIL_MEDIUM        = 4   # średnia ilość
AVAIL_LARGE         = 5   # duża ilość

DELIVERY_24H        = 1   # 24 godziny (mamy na magazynie)
DELIVERY_48H        = 2   # 48 godzin (trzeba ściągnąć z Tarnawa)

# Rate limiting
SHOPER_DELAY        = 0.5   # sekundy między PUT do Shoper
FIRMAO_DELAY        = 0.3   # sekundy między stronami Firmao

# ── Logging ─────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("stock-sync")

# ── Force IPv4 (Shoper blokuje IPv6) ───────────────────────────────────────
import socket
_orig_getaddrinfo = socket.getaddrinfo
def _ipv4_only(*args, **kwargs):
    return [r for r in _orig_getaddrinfo(*args, **kwargs) if r[0] == socket.AF_INET]
socket.getaddrinfo = _ipv4_only


def calc_availability(total: int, tarnawa_status: str = "") -> int:
    """Oblicz availability_id na podstawie sumarycznego stanu i statusu Tarnawa.

    Priorytet:
      1. Jeśli total > 0 → dostępny/średnia/duża (na podstawie ilości)
      2. Jeśli total = 0 i Tarnawa "on-backorder" → na zamówienie (can_buy=0)
      3. Jeśli total = 0 i Tarnawa "out-of-stock" → niedostępny (can_buy=0)
      4. Jeśli total = 0 → niedostępny
    """
    if total > 0:
        if total >= 20: return AVAIL_LARGE
        if total >= 10: return AVAIL_MEDIUM
        return AVAIL_AVAILABLE
    # total = 0 — sprawdź status Tarnawa
    if tarnawa_status == "on-backorder":
        return AVAIL_ON_ORDER       # ID 6: "dostępny na zamówienie" (can_buy=0)
    return AVAIL_UNAVAILABLE        # ID 9: "trwale niedostępny" (can_buy=0)


def calc_delivery(stock_techtor: int) -> int:
    """Oblicz delivery_id: 24h jeśli mamy na magazynie, 48h jeśli tylko u dostawcy."""
    return DELIVERY_24H if stock_techtor > 0 else DELIVERY_48H


# ── Firmao ──────────────────────────────────────────────────────────────────
def fetch_firmao_stocks() -> dict[str, int]:
    """Pobierz stany magazynowe z Firmao (currentStoreState)."""
    log.info("Pobieranie stanów z Firmao...")
    stocks = {}
    start = 0
    while True:
        r = requests.get(
            f"{FIRMAO_URL}/products",
            auth=(FIRMAO_EMAIL, FIRMAO_TOKEN),
            params={"start": start, "limit": 100},
            headers={"Accept": "application/json"},
            timeout=30,
        )
        r.raise_for_status()
        entries = r.json().get("data", [])
        if not entries:
            break
        for p in entries:
            code = p.get("productCode", "")
            stock = p.get("currentStoreState", 0) or 0
            if code:
                stocks[code] = int(stock)
        start += 100
        if len(entries) < 100:
            break
        time.sleep(FIRMAO_DELAY)
    log.info(f"  Firmao: {len(stocks)} produktów")
    return stocks


# ── Tarnawa ─────────────────────────────────────────────────────────────────
def load_tarnawa_stocks() -> dict[str, dict]:
    """Załaduj stany Tarnawa ze scrapera (output/*/product.json).
    Zwraca {code: {"qty": int, "status": str}} gdzie status to:
      "in-stock", "on-backorder", "out-of-stock"
    """
    log.info(f"Ładowanie stanów Tarnawa z {TARNAWA_DIR}...")
    stocks = {}
    if not os.path.isdir(TARNAWA_DIR):
        log.warning(f"  Katalog Tarnawa nie istnieje: {TARNAWA_DIR}")
        return stocks

    for code_dir in os.listdir(TARNAWA_DIR):
        product_json = os.path.join(TARNAWA_DIR, code_dir, "product.json")
        if not os.path.isfile(product_json):
            continue
        try:
            with open(product_json) as f:
                data = json.load(f)
            qty = data.get("stock_quantity", 0)
            status = data.get("stock_status", "")
            stocks[code_dir] = {
                "qty": int(qty) if qty else 0,
                "status": status,
            }
        except (json.JSONDecodeError, ValueError, TypeError):
            pass

    by_status = {}
    for v in stocks.values():
        by_status[v["status"]] = by_status.get(v["status"], 0) + 1
    log.info(f"  Tarnawa: {len(stocks)} produktów — {by_status}")
    return stocks


# ── Shoper ──────────────────────────────────────────────────────────────────
class ShoperClient:
    def __init__(self):
        self.session = requests.Session()
        self._authenticate()

    def _authenticate(self):
        r = self.session.post(
            f"{SHOPER_URL}/auth",
            auth=(SHOPER_USER, SHOPER_PASS),
            timeout=15,
        )
        r.raise_for_status()
        token = r.json()["access_token"]
        self.session.headers.update({
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        })
        log.info("  Shoper: autoryzacja OK")

    def get_all_stocks(self) -> dict[str, dict]:
        """Pobierz wszystkie product-stocks z Shoper."""
        log.info("Pobieranie stocków z Shoper...")
        stocks = {}
        page = 1
        while True:
            r = self.session.get(
                f"{SHOPER_URL}/product-stocks",
                params={"limit": 50, "page": page},
                timeout=15,
            )
            r.raise_for_status()
            data = r.json()
            for s in data.get("list", []):
                code = s.get("code", "")
                if code:
                    stocks[code] = {
                        "stock_id":        int(s["stock_id"]),
                        "stock":           int(float(s.get("stock", 0))),
                        "availability_id": int(s["availability_id"]) if s.get("availability_id") else None,
                        "delivery_id":     int(s["delivery_id"]) if s.get("delivery_id") else None,
                    }
            pages = int(data.get("pages", 1))
            if page >= pages:
                break
            page += 1
            time.sleep(0.3)
        log.info(f"  Shoper: {len(stocks)} stocków")
        return stocks

    def update_stock(self, stock_id: int, payload: dict) -> bool:
        """PUT product-stocks/{id}."""
        r = self.session.put(
            f"{SHOPER_URL}/product-stocks/{stock_id}",
            json=payload,
            timeout=15,
        )
        return r.status_code == 200


# ── Sync ────────────────────────────────────────────────────────────────────
def sync(dry_run: bool = False, filter_code: str | None = None):
    start_time = datetime.now()
    log.info(f"=== Stock sync start: {start_time.strftime('%Y-%m-%d %H:%M:%S')} ===")
    if dry_run:
        log.info("  TRYB DRY-RUN — bez zmian w Shoper")

    # 1. Pobierz dane ze wszystkich źródeł
    firmao_stocks  = fetch_firmao_stocks()
    tarnawa_stocks = load_tarnawa_stocks()
    shoper = ShoperClient()
    shoper_stocks  = shoper.get_all_stocks()

    # 2. Oblicz i synchronizuj
    matched = 0
    updated = 0
    skipped = 0
    errors  = 0

    for code, s in shoper_stocks.items():
        if filter_code and code != filter_code:
            continue

        # Pomijaj węże (11-znakowe kody W*) — zarządzane osobno
        if len(code) == 11 and code.startswith("W") and code[1:2].isalpha():
            continue

        # Suma obu magazynów
        stock_techtor = firmao_stocks.get(code, 0)
        tarnawa_data  = tarnawa_stocks.get(code, {"qty": 0, "status": ""})
        stock_tarnawa = tarnawa_data["qty"]
        tarnawa_status = tarnawa_data["status"]
        total = stock_techtor + stock_tarnawa

        # Pomijaj produkty bez danych w żadnym źródle
        if code not in firmao_stocks and code not in tarnawa_stocks:
            continue
        matched += 1

        # Oblicz docelowe wartości
        new_avail    = calc_availability(total, tarnawa_status)
        new_delivery = calc_delivery(stock_techtor)

        # Sprawdź czy potrzebna zmiana
        if (s["stock"] == total
                and s["availability_id"] == new_avail
                and s["delivery_id"] == new_delivery):
            skipped += 1
            continue

        # Loguj zmianę
        parts = [f"stock {s['stock']}→{total}"]
        if stock_tarnawa > 0:
            parts[0] += f" (T:{stock_techtor}+D:{stock_tarnawa})"
        if s["availability_id"] != new_avail:
            parts.append(f"avail {s['availability_id']}→{new_avail}")
        if s["delivery_id"] != new_delivery:
            delivery_label = "24h" if new_delivery == DELIVERY_24H else "48h"
            parts.append(f"delivery→{delivery_label}")
        log.info(f"  {code}: {', '.join(parts)}")

        if dry_run:
            updated += 1
            continue

        # Wyślij do Shoper
        # warn_level = stan Techtor — używany przez snippet JS
        # do dynamicznego czasu wysyłki (qty ≤ warn_level → 24h, > → 48h)
        payload = {
            "stock":           total,
            "availability_id": new_avail,
            "delivery_id":     new_delivery,
            "warn_level":      stock_techtor,
        }
        try:
            if shoper.update_stock(s["stock_id"], payload):
                updated += 1
            else:
                errors += 1
                log.error(f"  {code}: Shoper PUT failed")
        except Exception as e:
            errors += 1
            log.error(f"  {code}: {e}")

        time.sleep(SHOPER_DELAY)

    # 3. Podsumowanie
    elapsed = (datetime.now() - start_time).total_seconds()
    log.info(f"=== GOTOWE ({elapsed:.0f}s) ===")
    log.info(f"  Dopasowane: {matched}")
    log.info(f"  Zaktualizowane: {updated}")
    log.info(f"  Bez zmian: {skipped}")
    log.info(f"  Błędy: {errors}")

    # 4. Generuj stock-data.json dla snippet JS
    # Format: {"SKU": stockTechtor, ...} dla produktów ze stanem > 0
    # Dla on-backorder/out-of-stock: {"SKU": 0, "SKU__status": "on-backorder"}
    stock_data_path = Path(__file__).resolve().parent / "stock-service" / "public" / "stock-data.json"
    stock_map = {}
    for code in shoper_stocks:
        st = firmao_stocks.get(code, 0)
        td = tarnawa_stocks.get(code, {"qty": 0, "status": ""})
        total = st + td["qty"]
        # Zawsze zapisz stockTechtor (0 = niedostępny → snippet pokaże "Zapytaj")
        stock_map[code] = st
        if total == 0 and td["status"] in ("on-backorder", "out-of-stock"):
            stock_map[code + "__status"] = td["status"]
    try:
        stock_data_path.parent.mkdir(parents=True, exist_ok=True)
        stock_data_path.write_text(json.dumps(stock_map, separators=(",", ":")))
        log.info(f"  stock-data.json: {len(stock_map)} produktów ({stock_data_path.stat().st_size} B)")
    except Exception as e:
        log.error(f"  stock-data.json: {e}")

    return {"matched": matched, "updated": updated, "skipped": skipped, "errors": errors}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Sync stanów magazynowych Firmao+Tarnawa → Shoper")
    parser.add_argument("--dry-run", action="store_true", help="Podgląd zmian bez zapisu do Shoper")
    parser.add_argument("--code", type=str, help="Sync tylko jednego produktu (SKU)")
    args = parser.parse_args()

    result = sync(dry_run=args.dry_run, filter_code=args.code)

    if result["errors"] > 0:
        sys.exit(1)
