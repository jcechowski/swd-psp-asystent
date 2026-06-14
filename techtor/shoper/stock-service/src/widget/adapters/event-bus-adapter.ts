import type { WidgetAdapter, StorefrontApi } from '../types';
import { getSku } from '../utils/sku-resolver';
import { dbg } from '../utils/debug';

/** Adapter korzystający z Shoper Phoenix Event Bus (useStorefront) */
export class EventBusAdapter implements WidgetAdapter {
  private listeners: Array<{ event: string; cb: (data: unknown) => void }> = [];
  private navCleanup: (() => void) | null = null;

  constructor(private api: StorefrontApi) {
    dbg('EventBusAdapter: zainicjalizowany');
    // Log wszystkich eventów w debug mode
    if (typeof localStorage !== 'undefined' && localStorage.getItem('techtor_debug') === '1') {
      this.logAllEvents();
    }
  }

  onProductChange(cb: (sku: string) => void): void {
    // Shoper Phoenix emituje te eventy przy zmianie wariantu
    const handler = () => {
      const sku = getSku();
      if (sku) cb(sku);
    };

    for (const event of ['product.stockChanged', 'product.priceChanged', 'product.variantChanged']) {
      this.api.eventBus.on(event, handler);
      this.listeners.push({ event, cb: handler });
    }

    // Fallback: MutationObserver na SKU element (gdyby eventy nie pokrywały)
    const skuEl = document.querySelector('[data-product-code="sku"]');
    if (skuEl) {
      const mo = new MutationObserver(() => {
        const sku = skuEl.textContent?.trim();
        if (sku) cb(sku);
      });
      mo.observe(skuEl, { childList: true, characterData: true, subtree: true });
      this.listeners.push({ event: '__mo_sku', cb: () => mo.disconnect() } as never);
    }
  }

  onQuantityChange(cb: (qty: number) => void): void {
    const handler = (data: unknown) => {
      const d = data as Record<string, unknown>;
      if (typeof d?.quantity === 'number') {
        cb(d.quantity);
      } else {
        // Fallback: czytaj z DOM
        const stepper = document.querySelector<HTMLElement>('h-input-stepper, [class*="quantity__input"]');
        if (stepper) {
          const val = parseInt(stepper.getAttribute('value') || '1', 10);
          cb(val || 1);
        }
      }
    };

    this.api.eventBus.on('product.quantityChanged', handler);
    this.listeners.push({ event: 'product.quantityChanged', cb: handler });

    // Fallback: MutationObserver na stepper
    const stepper = document.querySelector('h-input-stepper');
    if (stepper) {
      const mo = new MutationObserver(() => {
        const val = parseInt(stepper.getAttribute('value') || '1', 10);
        cb(val || 1);
      });
      mo.observe(stepper, { attributes: true, attributeFilter: ['value'] });
      this.listeners.push({ event: '__mo_qty', cb: () => mo.disconnect() } as never);

      // Input event na polu ilości (ręczne wpisywanie)
      const qtyInput = stepper.querySelector<HTMLInputElement>('input[type="number"], input');
      if (qtyInput) {
        const inputHandler = () => {
          const val = parseInt(qtyInput.value || '1', 10);
          if (val > 0) cb(val);
        };
        qtyInput.addEventListener('change', inputHandler);
        qtyInput.addEventListener('input', inputHandler);
      }

      // Click listeners na stepper buttons — czekaj aż DOM się zaktualizuje
      stepper.querySelectorAll('h-button-stepper, button').forEach(btn => {
        const clickHandler = () => setTimeout(() => {
          const val = parseInt(stepper.getAttribute('value') || '1', 10);
          cb(val || 1);
        }, 250);
        btn.addEventListener('click', clickHandler);
      });
    }
  }

  onNavigation(cb: () => void): void {
    // Event Bus navigation events
    for (const event of ['page.changed', 'navigation.completed', 'route.changed', 'rendered']) {
      this.api.eventBus.on(event, () => {
        dbg('Navigation event:', event);
        setTimeout(cb, 300);
      });
      this.listeners.push({ event, cb: () => {} });
    }

    // Fallback: popstate + pushState monkey-patch
    const popHandler = () => setTimeout(cb, 300);
    window.addEventListener('popstate', popHandler);

    const origPush = history.pushState.bind(history);
    history.pushState = function (...args: Parameters<typeof history.pushState>) {
      origPush(...args);
      setTimeout(cb, 300);
    };

    // Ostateczny fallback: URL polling
    let lastUrl = location.href;
    const poll = setInterval(() => {
      if (location.href !== lastUrl) {
        lastUrl = location.href;
        setTimeout(cb, 300);
      }
    }, 500);

    this.navCleanup = () => {
      window.removeEventListener('popstate', popHandler);
      clearInterval(poll);
      history.pushState = origPush;
    };
  }

  destroy(): void {
    for (const { event, cb } of this.listeners) {
      if (event.startsWith('__mo_')) {
        (cb as unknown as () => void)(); // disconnect MutationObserver
      } else {
        try { this.api.eventBus.off(event, cb); } catch { /* ignore */ }
      }
    }
    this.listeners = [];
    this.navCleanup?.();
    dbg('EventBusAdapter: zniszczony');
  }

  private logAllEvents(): void {
    const events = [
      'product.stockChanged', 'product.priceChanged', 'product.quantityChanged',
      'product.variantChanged', 'basket.itemAddedToBasket', 'basket.basketUpdated',
      'page.changed', 'navigation.completed', 'route.changed', 'rendered',
    ];
    for (const event of events) {
      this.api.eventBus.on(event, (data) => {
        console.log(`[TECHTOR EVENT] ${event}`, data);
      });
    }
  }
}
