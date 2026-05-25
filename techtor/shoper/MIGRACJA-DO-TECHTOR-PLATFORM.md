# Migracja implementacji Shoper Stock do techtor-platform

## Obecna architektura (repo shoper)

```
sync-stock.py (CRON 6:00)
  → Firmao API + Tarnawa scraper output
  → Shoper API (stock, availability_id, delivery_id, warn_level)
  → stock-data.json → stock.techtor.pl

stock-service (Docker: shoper_stock_service, port 3100)
  → /v7/snippet.js — dynamiczny czas wysyłki
  → /api/stock-data.json — mapa {SKU: stockTechtor}
  → /api/ask — formularz "Zapytaj o dostępność" + mail SMTP

snippet.js wstrzyknięty w opisy 669 produktów jako <img onload="...">
```

---

## WAŻNE: Zakres migracji

Migracja dotyczy **TYLKO zakładki Produkty** (PIUSI, RAASM, Adam Pumps itp.).

**NIE dotyczy:**
- **Węży** — kody 11-znakowe zaczynające się na `W` (np. `WA019008GZ1`)
  - Format: `W` + typ (litera) + średnica 3 cyfry + długość 3 cyfry + końcówka (000/GZ1/GZ2)
  - Stany magazynowe węży będą zarządzane osobno (moduł FlexGen / warianty)
- **FlexGen** — generator wariantów węży
- **Innych modułów** techtor-platform (HoseFlow, zamówienia itp.)

`sync-stock.py` powinien **pomijać produkty z kodem W*** przy sync do Shoper.

---

## Konflikty do rozwiązania PRZED migracją

### 1. KRYTYCZNY: Dwa systemy piszą do Shoper stock

**Problem:**
- `sync-stock.py` pushuje `stock`, `availability_id`, `delivery_id`, `warn_level` codziennie o 6:00
- `backend-wezy PUT /api/shoper/product/:code` pushuje `availability_id`, `delivery_id` przy ręcznym sync z UI

**Rozwiązanie:**
Usunąć logikę `stockTotal` / `availability_id` z `backend-wezy/index.cjs` linie ~1684-1722.
Backend-wezy NIE powinien pushować stock/availability/delivery — to robi sync-stock.py.
Backend-wezy powinien pushować TYLKO: ceny, opisy, SEO, gabaryty, EAN, producenta.

Konkretnie w `apps/backend-wezy/index.cjs`:
- Linie 1684-1694 (availability z body.stockTotal) → USUNĄĆ cały blok
- Linia 1708-1709 (stockPayload.availability_id) → USUNĄĆ
- Linia 1710 (stockPayload.stock) → USUNĄĆ
- Zachować: ceny (1711-1714), weight (1715), delivery (1708 — opcjonalnie usunąć też)

### 2. KRYTYCZNY: Różna logika availability

**Problem:**
- sync-stock.py: stock=0 + Tarnawa on-backorder → ID 6 (na zamówienie)
- backend-wezy: stock=0 → zawsze ID 9 (niedostępny)

**Rozwiązanie:**
Po usunięciu availability z backend-wezy (punkt 1) — problem znika.
sync-stock.py jest jedynym źródłem prawdy dla availability.

### 3. ŚREDNI: Ochrona snippeta w opisach produktów

**Problem:**
Backend-wezy pushuje `descriptionLong` do Shoper przy sync.
Jeśli użytkownik edytuje opis w techtor-platform, a opis nie zawiera
tagu `<img onload="...">`, snippet zostanie usunięty.

**Rozwiązanie A (zalecane): Przenieść snippet z opisu do stock-service**
- sync-stock.py przy każdym runie sprawdza czy produkt ma snippet w opisie
- Jeśli nie ma → dodaje go automatycznie
- Dodać to jako krok 5 w sync-stock.py (po generowaniu stock-data.json)

**Rozwiązanie B: Chronić tag w backend-wezy**
- W PUT /api/shoper/product/:code, przed wysłaniem opisu do Shoper:
- Sprawdź czy aktualny opis w Shoper zawiera `stock.techtor.pl`
- Jeśli tak — zachowaj ten tag na końcu nowego opisu

### 4. ŚREDNI: Frontend ProductEditor — kolumna "Dostępność"

**Problem:**
Wcześniej (przez pomyłkę) zmodyfikowałem ProductList.tsx i ProductEditor.tsx
w techtor-platform. Te zmiany mogą kolidować z obecną implementacją.

**Co sprawdzić:**
- `apps/produkty/src/lib/storage.ts` — czy ma `getTotalStock()`, `getAvailabilityLabel()`
- `apps/produkty/src/components/ProductList.tsx` — czy kolumna "Dostępność" jest jedna (zamiast dwóch)
- `apps/produkty/src/components/ProductEditor.tsx` — czy banner dostępności w zakładce Wysyłka

**Rozwiązanie:**
Te zmiany w techtor-platform są OK i mogą zostać — pokazują admin sumę stanów.
Ale upewnić się że NIE pushują availability/stock do Shoper (to robi sync-stock.py).

---

## Kroki migracji

### Krok 1: Backend-wezy — usuń stock/availability z sync do Shoper
Plik: `apps/backend-wezy/index.cjs`
- W `PUT /api/shoper/product/:code` (~linia 1684):
  - Usuń cały blok `if (body.stockTotal != null)` (obliczanie availability z stockTotal)
  - Usuń fallback `else if (body.availability)` (ręczne ustawianie availability)
  - Usuń z stockPayload: `availability_id`, `stock` (ilość)
  - Opcjonalnie: usuń `delivery_id` (sync-stock.py to obsługuje)
- W `PUT /api/shoper/product/:code` nowy produkt (~linia 1577):
  - Zachowaj domyślny `stock: { active: 1 }` — sync-stock.py zaktualizuje resztę

### Krok 2: Frontend — usuń `stockTotal` z sync
Plik: `apps/produkty/src/components/ProductEditor.tsx`
- W `syncToShoper()` (~linia 328): zamień `stockTotal: getTotalStock(cfg)` na brak tego pola
- Lub: po prostu usuń `stockTotal` z body wysyłanego do Shoper

Plik: `apps/produkty/src/components/ProductList.tsx`
- W `bulkPushToShoper()` (~linia 596): tak samo — usuń `stockTotal`

### Krok 3: Przenieś sync-stock.py do techtor-platform
- Skopiuj `sync-stock.py` do `techtor-platform/scripts/sync-shoper-stock.py`
- Zaktualizuj ścieżkę `TARNAWA_DIR` (relatywna do nowej lokalizacji)
- Zaktualizuj ścieżkę `stock_data_path` (do stock-service/public/)
- Zaktualizuj CRON z nową ścieżką

### Krok 4: Dodaj auto-inject snippeta
W sync-stock.py, po kroku generowania stock-data.json, dodaj:
```python
# 5. Sprawdź i dodaj snippet do opisów produktów bez niego
SNIPPET_TAG = '<img src="data:image/gif;base64,R0lGOD..." onload="...">'
for code, s in shoper_stocks.items():
    # pobierz opis, sprawdź czy ma snippet, jeśli nie — dodaj
```
Dzięki temu snippet jest automatycznie utrzymywany.

### Krok 5: stock-service do docker-compose techtor-platform
Dodaj stock-service jako serwis w `techtor-platform/docker-compose.yml`:
```yaml
stock_service:
  build: ../shoper/stock-service
  container_name: shoper_stock_service
  restart: unless-stopped
  ports:
    - "3100:3000"
  environment:
    ALLOWED_ORIGINS: "https://techtor.pl,https://www.techtor.pl"
    NOTIFY_EMAIL: biuro@techtor.pl
    SMTP_HOST: smtp.gmail.com
    SMTP_PORT: "587"
    SMTP_USER: biuro@techtor.pl
    SMTP_PASS: ${SMTP_PASSWORD}
  volumes:
    - ../shoper/stock-service/public/stock-data.json:/app/public/stock-data.json:ro
  healthcheck:
    test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1:3000/health"]
    interval: 30s
    timeout: 5s
    retries: 3
```

---

## Harmonogram CRON po migracji

```
0 1 * * *  Tarnawa stock updater (scraper)
0 5 * * *  Firmao stock sync (techtor-platform)
0 6 * * *  Shoper stock sync (sync-stock.py) — JEDYNY system piszący stock/avail do Shoper
```

---

## Diagram — kto co pushuje po migracji

```
                    techtor-platform (backend-wezy)
                    ┌──────────────────────────────────┐
                    │ Push do Shoper:                   │
                    │  ✓ ceny (price, wholesale, buying)│
                    │  ✓ opisy (translations)           │
                    │  ✓ SEO (title, description, url)  │
                    │  ✓ EAN, producent, gabaryty       │
                    │  ✗ stock (ilość)      ← USUNIĘTE │
                    │  ✗ availability_id    ← USUNIĘTE │
                    │  ✗ delivery_id        ← USUNIĘTE │
                    └──────────────────────────────────┘

                    sync-stock.py (CRON 6:00)
                    ┌──────────────────────────────────┐
                    │ Push do Shoper:                   │
                    │  ✓ stock = Firmao + Tarnawa      │
                    │  ✓ availability_id (reguły)      │
                    │  ✓ delivery_id (24h/48h)         │
                    │  ✓ warn_level (stockTechtor)     │
                    │  ✓ snippet w opisie (auto-inject)│
                    └──────────────────────────────────┘
```

---

## Podsumowanie ryzyk

| Ryzyko | Prawdopodobieństwo | Wpływ | Mitygacja |
|---|---|---|---|
| Nadpisanie ręcznych zmian stock | WYSOKIE | WYSOKI | Usunąć stock z backend-wezy |
| Utrata snippeta przy edycji opisu | ŚREDNIE | ŚREDNI | Auto-inject w sync-stock.py |
| Różna logika availability | ŚREDNIE | WYSOKI | Jedno źródło (sync-stock.py) |
| Race condition timing | NISKIE | NISKI | CRON kolejność gwarantuje |
