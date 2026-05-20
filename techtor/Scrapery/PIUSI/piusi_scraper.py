#!/usr/bin/env python3
"""
PIUSI Product Scraper
Pobiera zdjęcia, karty katalogowe (datasheets) i instrukcje obsługi
ze strony piusi.com i organizuje je w foldery per produkt.

Struktura wyjściowa:
    output/
    ├── cube-mc-2-0/
    │   ├── product.json          # metadane produktu
    │   ├── images/
    │   │   ├── cube_mc_2.0_main.webp
    │   │   └── cube_mc_2.0_gallery_1.webp
    │   ├── datasheets/
    │   │   └── CUBE_MC_2.0_PIUSI_DATASHEET_FUEL_0425_EN.pdf
    │   └── manuals/
    │       └── ...
    └── ...
"""

import argparse
import json
import logging
import re
import time
from pathlib import Path
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup

BASE_URL = "https://www.piusi.com"
MEDIA_URL = "https://media.piusi.com"
SITEMAP_URL = f"{BASE_URL}/sitemap.xml"

MATERIALS_SEARCH_URL = f"{BASE_URL}/actions/pxxpiusi/materials/search"
MATERIALS_LIST_DOCS_URL = f"{BASE_URL}/actions/pxxpiusi/materials/list-documents"
MATERIALS_FETCH_DOC_URL = f"{BASE_URL}/actions/pxxpiusi/materials/fetch-document"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36",
    "Accept-Language": "en-US,en;q=0.9",
}

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger("piusi")


class PiusiScraper:
    # Mapowanie kodów językowych (argument CLI → API PIUSI)
    LANG_MAP = {"EN": "E", "IT": "I", "FR": "F", "DE": "D", "ES": "S"}

    def __init__(self, output_dir: str = "output", delay: float = 1.0, lang: str = "EN"):
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(parents=True, exist_ok=True)
        self.delay = delay
        self.lang = lang
        self.api_lang = self.LANG_MAP.get(lang, "E")
        self.session = requests.Session()
        self.session.headers.update(HEADERS)
        self.csrf_token = None

    def _wait(self):
        time.sleep(self.delay)

    def _download_file(self, url: str, dest: Path) -> bool:
        if dest.exists():
            log.info("  Plik istnieje, pomijam: %s", dest.name)
            return True
        dest.parent.mkdir(parents=True, exist_ok=True)
        try:
            resp = self.session.get(url, stream=True, timeout=30)
            resp.raise_for_status()
            with open(dest, "wb") as f:
                for chunk in resp.iter_content(chunk_size=8192):
                    f.write(chunk)
            log.info("  Pobrano: %s", dest.name)
            return True
        except requests.RequestException as e:
            log.warning("  Nie udalo sie pobrac %s: %s", url, e)
            return False

    # ── Sitemap ──────────────────────────────────────────────

    def get_product_urls(self) -> list[str]:
        """Pobiera listę URL-i produktów z sitemap.xml."""
        log.info("Pobieram sitemap: %s", SITEMAP_URL)
        resp = self.session.get(SITEMAP_URL, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.text, "lxml-xml")
        urls = []
        for loc in soup.find_all("loc"):
            url = loc.text.strip()
            if "/products/" in url:
                urls.append(url)
        log.info("Znaleziono %d produktow w sitemap", len(urls))
        return urls

    # ── Strona produktu ──────────────────────────────────────

    def scrape_product(self, url: str) -> dict | None:
        """Scrapuje pojedynczą stronę produktu."""
        slug = url.rstrip("/").split("/")[-1]
        log.info("Scrapuje produkt: %s", slug)

        try:
            resp = self.session.get(url, timeout=15)
            resp.raise_for_status()
        except requests.RequestException as e:
            log.error("Blad pobierania strony %s: %s", url, e)
            return None

        soup = BeautifulSoup(resp.text, "html.parser")
        product_dir = self.output_dir / slug

        product = {
            "slug": slug,
            "url": url,
            "name": self._extract_name(soup),
            "description": self._extract_description(soup),
            "product_codes": self._extract_product_codes(soup),
            "images": [],
            "datasheets": [],
            "manuals": [],
        }

        # Zdjęcia
        images = self._extract_image_urls(soup)
        for i, img_url in enumerate(images):
            filename = urlparse(img_url).path.split("/")[-1]
            dest = product_dir / "images" / filename
            if self._download_file(img_url, dest):
                product["images"].append(filename)

        # Datasheet PDFs (bezpośrednie linki na stronie)
        datasheets = self._extract_datasheet_urls(soup)
        for pdf_url in datasheets:
            filename = urlparse(pdf_url).path.split("/")[-1]
            dest = product_dir / "datasheets" / filename
            if self._download_file(pdf_url, dest):
                product["datasheets"].append(filename)

        # Spare parts (rysunki eksplodowane + dokumenty z API)
        for code in product["product_codes"]:
            docs = self._fetch_spare_parts_docs(code)
            for doc in docs:
                subdir = doc["type"]  # "manuals" lub "spare_parts"
                dest = product_dir / subdir / doc["filename"]
                if self._download_file(doc["url"], dest):
                    product["manuals"].append(doc["filename"])

        # Zapisz metadane
        product_dir.mkdir(parents=True, exist_ok=True)
        meta_path = product_dir / "product.json"
        with open(meta_path, "w", encoding="utf-8") as f:
            json.dump(product, f, ensure_ascii=False, indent=2)

        log.info("Gotowe: %s — %d zdjec, %d datasheets, %d manuals",
                 slug, len(product["images"]), len(product["datasheets"]),
                 len(product["manuals"]))
        return product

    def _extract_name(self, soup: BeautifulSoup) -> str:
        h1 = soup.find("h1")
        return h1.get_text(strip=True) if h1 else ""

    def _extract_description(self, soup: BeautifulSoup) -> str:
        meta = soup.find("meta", attrs={"name": "description"})
        if meta and meta.get("content"):
            return meta["content"].strip()
        return ""

    def _extract_product_codes(self, soup: BeautifulSoup) -> list[str]:
        """Wyciąga kody produktów (FERT) ze strony — z tabel i linków spare-parts."""
        codes = set()

        # Z linków spare-parts/manuals (?fert=...)
        for a in soup.find_all("a", href=True):
            href = a["href"]
            if "fert=" in href:
                match = re.search(r"fert=([A-Z0-9]+)", href)
                if match:
                    codes.add(match.group(1))

        # Z tabel (kolumna "Product code")
        for td in soup.find_all("td"):
            text = td.get_text(strip=True)
            if re.match(r"^[A-Z]?\d{5,}[A-Z]?\d*$", text):
                codes.add(text)

        return sorted(codes)

    def _extract_image_urls(self, soup: BeautifulSoup) -> list[str]:
        """Wyciąga URLe zdjęć produktu z galerii i tagów img."""
        urls = set()

        # Obrazy z media.piusi.com
        for img in soup.find_all("img", src=True):
            src = img["src"]
            if "media.piusi.com/prodotti" in src:
                urls.add(src)

        # data-src (lazy loading)
        for img in soup.find_all("img", attrs={"data-src": True}):
            src = img["data-src"]
            if "media.piusi.com/prodotti" in src:
                urls.add(src)

        # Szukaj w JavaScript (galleryData)
        for script in soup.find_all("script"):
            if script.string and "media.piusi.com/prodotti" in (script.string or ""):
                found = re.findall(
                    r'(https?://media\.piusi\.com/prodotti/[^\s"\'<>]+\.(?:webp|jpg|jpeg|png))',
                    script.string,
                )
                urls.update(found)

        # Odfiltruj thumbnails z _productsSmall — pobieraj pełne wersje
        full_urls = set()
        for u in urls:
            # Dodaj zarówno full jak i small (small jest na stronie, full może nie istnieć)
            full_urls.add(u)
            # Spróbuj też wersję bez _productsSmall
            if "_productsSmall/" in u:
                full_url = u.replace("_productsSmall/", "")
                full_urls.add(full_url)

        return sorted(full_urls)

    def _extract_datasheet_urls(self, soup: BeautifulSoup) -> list[str]:
        """Wyciąga linki do kart katalogowych (PDF) ze strony."""
        urls = set()

        # Bezpośrednie linki do PDF na media.piusi.com
        for a in soup.find_all("a", href=True):
            href = a["href"]
            if "media.piusi.com" in href and href.endswith(".pdf"):
                urls.add(href)

        # PDF linki w JavaScript
        for script in soup.find_all("script"):
            if script.string and "media.piusi.com" in (script.string or ""):
                found = re.findall(
                    r'(https?://media\.piusi\.com/pdf/[^\s"\'<>]+\.pdf)',
                    script.string,
                )
                urls.update(found)

        return sorted(urls)

    # ── API Materials (instrukcje) ───────────────────────────

    def _ensure_csrf_token(self):
        """Pobiera CSRF token z dowolnej strony PIUSI (CraftCMS: var tokenValue)."""
        if self.csrf_token:
            return
        log.info("Pobieram CSRF token...")
        resp = self.session.get(f"{BASE_URL}/support/search-manuals", timeout=15)
        resp.raise_for_status()

        # CraftCMS ustawia token w JS: var tokenValue = "...";
        match = re.search(r'var\s+tokenValue\s*=\s*"([^"]+)"', resp.text)
        if match:
            self.csrf_token = match.group(1)
            log.info("CSRF token pobrany")
            return

        # Fallback: szukaj w input hidden
        soup = BeautifulSoup(resp.text, "html.parser")
        csrf_input = soup.find("input", attrs={"name": "CRAFT_CSRF_TOKEN"})
        if csrf_input and csrf_input.get("value"):
            self.csrf_token = csrf_input["value"]
            log.info("CSRF token pobrany (z input)")
            return

        log.warning("Nie udalo sie pobrac CSRF tokena — instrukcje moga byc niedostepne")

    def _fetch_spare_parts_docs(self, product_code: str) -> list[dict]:
        """Pobiera dokumenty (rysunki eksplodowane, instrukcje) z API PIUSI."""
        self._ensure_csrf_token()
        if not self.csrf_token:
            return []

        docs = []

        try:
            for mode, doc_type in [("WEB", "spare_parts"), ("MAN", "manuals")]:
                search_data = {
                    "CRAFT_CSRF_TOKEN": self.csrf_token,
                    "mode": mode,
                    "code": product_code,
                    "description": "",
                    "language": self.api_lang,
                    "location": "1000",
                    "status": "06,07",
                }
                resp = self.session.post(MATERIALS_SEARCH_URL, data=search_data, timeout=15)
                resp.raise_for_status()
                results = resp.json()

                if not isinstance(results, list) or not results:
                    continue

                for item in results:
                    matnr = item.get("MATNR", "")
                    dokar = item.get("DOKAR", "")
                    doknr = item.get("DOKNR", "")
                    dokvr = item.get("DOKVR", "")
                    doktl = item.get("DOKTL", "")
                    descr = item.get("MAKTX", "")

                    if not doknr:
                        continue

                    # Pobierz PDF dokumentu
                    params = {
                        "nomefile": matnr,
                        "doknr": doknr,
                        "dokar": dokar,
                        "dokvr": dokvr,
                        "doktl": doktl,
                        "descr": descr,
                    }
                    fetch_url = f"{MATERIALS_FETCH_DOC_URL}?{requests.compat.urlencode(params)}"
                    safe_name = re.sub(r'[^\w\-.]', '_', f"{matnr}_{doknr}")
                    docs.append({
                        "url": fetch_url,
                        "filename": f"{safe_name}.pdf",
                        "type": doc_type,
                    })

                    # Pobierz preview (rysunek eksplodowany — PNG)
                    preview_url = (
                        f"{BASE_URL}/actions/pxxpiusi/materials/fetch-preview?"
                        f"{requests.compat.urlencode(params)}"
                    )
                    docs.append({
                        "url": preview_url,
                        "filename": f"{safe_name}_preview.png",
                        "type": doc_type,
                    })

                    # Sprawdź pod-elementy (sub-assemblies)
                    list_data = {
                        "CRAFT_CSRF_TOKEN": self.csrf_token,
                        "mode": mode,
                        "dokar": dokar,
                        "doknr": doknr,
                        "dokvr": dokvr,
                        "doktl": doktl,
                        "descr": descr,
                        "nomefile": matnr,
                    }
                    try:
                        resp2 = self.session.post(
                            MATERIALS_LIST_DOCS_URL, data=list_data, timeout=15
                        )
                        resp2.raise_for_status()
                        sub = resp2.json()
                        if isinstance(sub, dict) and isinstance(sub.get("items"), list):
                            for child in sub["items"]:
                                c_doknr = child.get("DOKNR", "")
                                if not c_doknr:
                                    continue
                                c_params = {
                                    "nomefile": child.get("MATNR") or child.get("IDNRK", ""),
                                    "doknr": c_doknr,
                                    "dokar": child.get("DOKAR", ""),
                                    "dokvr": child.get("DOKVR", ""),
                                    "doktl": child.get("DOKTL", ""),
                                    "descr": child.get("DESCR", ""),
                                }
                                c_name = re.sub(r'[^\w\-.]', '_',
                                                f"{c_params['nomefile']}_{c_doknr}")
                                c_fetch = (f"{MATERIALS_FETCH_DOC_URL}?"
                                           f"{requests.compat.urlencode(c_params)}")
                                docs.append({
                                    "url": c_fetch,
                                    "filename": f"{c_name}.pdf",
                                    "type": doc_type,
                                })
                    except Exception:
                        pass

                self._wait()

        except requests.RequestException as e:
            log.warning("Blad API materials dla %s: %s", product_code, e)

        return docs

    # ── Główna pętla ─────────────────────────────────────────

    def run(self, slugs: list[str] | None = None, limit: int | None = None):
        """
        Uruchamia scraper.

        Args:
            slugs: Lista slug-ów produktów do pobrania (None = wszystkie z sitemap)
            limit: Maksymalna liczba produktów do pobrania
        """
        if slugs:
            urls = [f"{BASE_URL}/products/{s}" for s in slugs]
        else:
            urls = self.get_product_urls()

        if limit:
            urls = urls[:limit]

        log.info("Do pobrania: %d produktow", len(urls))
        results = []

        for i, url in enumerate(urls, 1):
            log.info("── [%d/%d] ──", i, len(urls))
            product = self.scrape_product(url)
            if product:
                results.append(product)
            self._wait()

        # Raport końcowy
        summary_path = self.output_dir / "summary.json"
        with open(summary_path, "w", encoding="utf-8") as f:
            json.dump({
                "total_products": len(results),
                "total_images": sum(len(p["images"]) for p in results),
                "total_datasheets": sum(len(p["datasheets"]) for p in results),
                "total_manuals": sum(len(p["manuals"]) for p in results),
                "products": [
                    {
                        "slug": p["slug"],
                        "name": p["name"],
                        "codes": p["product_codes"],
                        "images": len(p["images"]),
                        "datasheets": len(p["datasheets"]),
                        "manuals": len(p["manuals"]),
                    }
                    for p in results
                ],
            }, f, ensure_ascii=False, indent=2)

        log.info("="*50)
        log.info("GOTOWE!")
        log.info("Produkty: %d", len(results))
        log.info("Zdjecia:  %d", sum(len(p["images"]) for p in results))
        log.info("Karty:    %d", sum(len(p["datasheets"]) for p in results))
        log.info("Instrukcje: %d", sum(len(p["manuals"]) for p in results))
        log.info("Wyniki w: %s", self.output_dir.resolve())


def main():
    parser = argparse.ArgumentParser(
        description="PIUSI Product Scraper — pobiera zdjecia, datasheets i instrukcje"
    )
    parser.add_argument(
        "-o", "--output",
        default="output",
        help="Katalog wyjsciowy (domyslnie: output/)",
    )
    parser.add_argument(
        "-d", "--delay",
        type=float,
        default=1.0,
        help="Opoznienie miedzy requestami w sekundach (domyslnie: 1.0)",
    )
    parser.add_argument(
        "-l", "--limit",
        type=int,
        default=None,
        help="Maksymalna liczba produktow do pobrania",
    )
    parser.add_argument(
        "-s", "--slugs",
        nargs="+",
        default=None,
        help="Konkretne slug-i produktow do pobrania (np. cube-mc-2-0 k600-4-diesel-version)",
    )
    parser.add_argument(
        "--lang",
        default="EN",
        help="Jezyk instrukcji (domyslnie: EN)",
    )
    parser.add_argument(
        "-v", "--verbose",
        action="store_true",
        help="Wiecej logow (DEBUG)",
    )
    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    scraper = PiusiScraper(
        output_dir=args.output,
        delay=args.delay,
        lang=args.lang,
    )
    scraper.run(slugs=args.slugs, limit=args.limit)


if __name__ == "__main__":
    main()
