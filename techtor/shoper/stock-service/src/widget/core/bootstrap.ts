import type { WidgetAdapter, WidgetState, StockInfo, WidgetModule } from '../types';
import { EventBusAdapter } from '../adapters/event-bus-adapter';
import { DomAdapter } from '../adapters/dom-adapter';
import { loadStockData, getStockInfo, invalidateCache } from './stock-data';
import { computeState, logTransition } from './state-machine';
import { getSku } from '../utils/sku-resolver';
import { initDebug, dbg, dbgWarn } from '../utils/debug';
import { WIDGET_CSS, SHOPER_ASK_HIDE_CSS } from '../ui/styles';
import { BuyControl } from '../modules/buy-control';
import { BannerManager } from '../modules/banner-manager';
import { AvailabilityOverlay } from '../modules/availability-overlay';
import { DeliveryTime } from '../modules/delivery-time';
import { InpostHider } from '../modules/inpost-hider';
import { VariantSelector } from '../modules/variant-selector';
import { showAskModal } from '../modules/ask-modal';

/** Tworzy pełny zestaw modułów widgetu */
function createModules(): WidgetModule[] {
  const banners = new BannerManager();
  banners.setAskHandler(showAskModal);
  return [
    new BuyControl(),
    banners,
    new AvailabilityOverlay(),
    new DeliveryTime(),
    new InpostHider(),
    new VariantSelector(),
  ];
}

/** Główna klasa widgetu */
class Widget {
  private adapter: WidgetAdapter;
  private modules: WidgetModule[] = [];
  private currentState: WidgetState = 'loading';
  private currentQty = 1;
  private currentSku = '';

  constructor(adapter: WidgetAdapter) {
    this.adapter = adapter;
  }

  start(): void {
    // Wstrzyknij globalne style
    this.injectStyles();

    // Inicjalizuj moduły
    this.modules = createModules();

    // Nasłuchuj na zmiany produktu (wariantu)
    this.adapter.onProductChange(sku => {
      dbg('SKU changed:', sku);
      this.currentSku = sku;
      this.update();
    });

    // Nasłuchuj na zmiany ilości
    this.adapter.onQuantityChange(qty => {
      dbg('Qty changed:', qty);
      this.currentQty = qty;
      this.update();
    });

    // Nasłuchuj na nawigację SPA
    this.adapter.onNavigation(() => {
      dbg('Navigation detected — restart');
      this.restart();
    });

    // Inicjalny stan
    const sku = getSku();
    if (sku) {
      this.currentSku = sku;
      this.update();
    } else {
      // Czekaj na SKU (DOM może nie być jeszcze gotowy)
      const waitForSku = setInterval(() => {
        const s = getSku();
        if (s) {
          clearInterval(waitForSku);
          this.currentSku = s;
          this.update();
        }
      }, 200);
      // Timeout po 10s
      setTimeout(() => clearInterval(waitForSku), 10000);
    }

    // Expose rerun dla legacy
    window._tRerun = () => this.restart();

    dbg('Widget started, mode:', window.__techtorWidget?.mode);
  }

  private update(): void {
    if (!this.currentSku) return;

    const info = getStockInfo(this.currentSku);
    const newState = computeState(info, this.currentQty);
    logTransition(this.currentState, newState, info, this.currentQty);
    this.currentState = newState;

    if (window.__techtorWidget) window.__techtorWidget.state = newState;

    // Aplikuj stan na wszystkie moduły
    for (const mod of this.modules) {
      mod.apply(newState, info, this.currentQty);
    }
  }

  restart(): void {
    dbg('Widget restart');
    // Zniszcz moduły
    for (const mod of this.modules) mod.destroy();
    // Invaliduj cache
    invalidateCache();
    // Reset stan
    this.currentState = 'loading';
    this.currentQty = 1;
    this.currentSku = '';

    // Przeładuj stock data i restart
    loadStockData().then(() => {
      this.modules = createModules();

      const sku = getSku();
      if (sku) {
        this.currentSku = sku;
        this.update();
      }
    });
  }

  private injectStyles(): void {
    if (!document.getElementById('techtor-widget-css')) {
      const style = document.createElement('style');
      style.id = 'techtor-widget-css';
      style.textContent = WIDGET_CSS + '\n' + SHOPER_ASK_HIDE_CSS;
      document.head.appendChild(style);
    }
  }
}

/** Aktywna instancja widgetu — potrzebna do rerun */
let _activeWidget: Widget | null = null;

/** Inicjalizacja widgetu — próbuje Event Bus, fallback na DOM */
export async function bootstrap(): Promise<void> {
  // Przy ponownym wywołaniu (img onload po SPA nav) — restart istniejącego
  if (window.__techtorWidget?.initialized && _activeWidget) {
    dbg('Widget already initialized — rerun');
    _activeWidget.restart();
    return;
  }

  // Reset stanu przy ponownym załadowaniu skryptu (page refresh)
  window.__techtorWidget = { version: 3, runId: 0, initialized: false, mode: 'loading' };

  initDebug();
  dbg('Widget v3 bootstrap start');

  // Wymuś świeże dane stocku (nie z sessionStorage cache)
  invalidateCache();
  await loadStockData();

  function initWidget(adapter: WidgetAdapter): void {
    const widget = new Widget(adapter);
    _activeWidget = widget;
    widget.start();
  }

  // Próbuj Shoper Event Bus
  if (typeof window.useStorefront === 'function') {
    window.useStorefront((api) => {
      if (window.__techtorWidget!.initialized) return;
      window.__techtorWidget!.initialized = true;
      window.__techtorWidget!.mode = 'eventbus';
      dbg('Tryb: Event Bus (useStorefront)');
      initWidget(new EventBusAdapter(api));
    });

    // Timeout: jeśli useStorefront nie wywołał callbacka po 5s → fallback
    setTimeout(() => {
      if (!window.__techtorWidget!.initialized) {
        dbgWarn('useStorefront timeout — fallback na DOM adapter');
        window.__techtorWidget!.initialized = true;
        window.__techtorWidget!.mode = 'dom-fallback';
        initWidget(new DomAdapter());
      }
    }, 5000);
  } else {
    // Brak useStorefront — DOM fallback
    dbgWarn('Brak useStorefront — DOM fallback');
    window.__techtorWidget!.initialized = true;
    window.__techtorWidget!.mode = 'dom-fallback';
    initWidget(new DomAdapter());
  }
}
