const ICON_WARN = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
const ICON_ERROR = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
const ICON_INFO = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
const ICON_CHAT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

/** Baner "Zapytaj o dostępność" — stock=0, koszyk ODBLOKOWANY */
export function askBannerHtml(): string {
  return `<div class="techtor-banner techtor-banner--warning" id="techtor-ask-banner">
    <div class="techtor-banner__row">${ICON_WARN}<span class="techtor-banner__text techtor-banner__text--amber">Zapytaj o dostępność — czas realizacji może być dłuższy</span></div>
  </div>`;
}

/** Baner "Wysyłka 48h" — towar z magazynu Tarnawa, koszyk ODBLOKOWANY */
export function tarnawaBannerHtml(): string {
  return `<div class="techtor-banner techtor-banner--info" id="techtor-tarnawa-banner">
    <div class="techtor-banner__row">${ICON_INFO}<span class="techtor-banner__text techtor-banner__text--blue">Towar dostępny u dostawcy — czas wysyłki 48 godzin</span></div>
  </div>`;
}

/** Baner "Zapytaj o cenę i dostępność" — koszyk ZABLOKOWANY */
export function price0BannerHtml(): string {
  return `<div class="techtor-banner techtor-banner--error" id="techtor-price0-banner">
    <div class="techtor-banner__row">${ICON_ERROR}<span class="techtor-banner__text techtor-banner__text--red">Zapytaj o aktualną cenę i dostępność</span></div>
    <button class="techtor-ask-btn techtor-ask-btn--red" id="techtor-price0-ask">${ICON_CHAT} Zapytaj o produkt</button>
  </div>`;
}

/** Baner "Przekroczono ilość" — qty > total, koszyk ODBLOKOWANY + zapytaj */
export function overlimitBannerHtml(stock: number): string {
  return `<div class="techtor-banner techtor-banner--warning" id="techtor-overlimit-banner">
    <div class="techtor-banner__row">${ICON_WARN}<span class="techtor-banner__text techtor-banner__text--amber">Zapytaj o dostępność większej ilości</span></div>
    <p class="techtor-banner__detail">W magazynie posiadamy <strong>${stock} szt.</strong></p>
    <button class="techtor-ask-btn techtor-ask-btn--amber" id="techtor-overlimit-ask">${ICON_CHAT} Zapytaj o dostępność</button>
  </div>`;
}
