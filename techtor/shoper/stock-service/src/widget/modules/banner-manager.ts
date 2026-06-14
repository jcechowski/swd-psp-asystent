import type { WidgetModule, WidgetState, StockInfo } from '../types';
import { askBannerHtml, tarnawaBannerHtml, price0BannerHtml, overlimitBannerHtml } from '../ui/templates';

const BANNER_IDS = ['techtor-ask-banner', 'techtor-tarnawa-banner', 'techtor-price0-banner', 'techtor-overlimit-banner'];

/** Zarządza banerami na stronie produktu */
export class BannerManager implements WidgetModule {
  private onAskClick: ((sku: string, name: string, qty?: number, priceInquiry?: boolean) => void) | null = null;

  setAskHandler(fn: (sku: string, name: string, qty?: number, priceInquiry?: boolean) => void): void {
    this.onAskClick = fn;
  }

  apply(state: WidgetState, info: StockInfo, qty: number): void {
    this.removeAll();

    const buyArea = document.querySelector<HTMLElement>(
      'buy-button, .product-actions, [data-module-name="product_actions"], .product-buy'
    )?.closest('.product-actions, [data-module-name="product_actions"]') as HTMLElement
      || document.querySelector<HTMLElement>('buy-button')?.parentElement;

    if (!buyArea) return;

    let html = '';
    switch (state) {
      case 'available-tarnawa':
        return; // czas wysyłki zmieniony przez DeliveryTime — baner zbędny
      case 'out-of-stock':
        html = askBannerHtml();
        break;
      case 'price-zero':
        html = price0BannerHtml();
        break;
      case 'overlimit':
        html = overlimitBannerHtml(info.totalStock);
        break;
      default:
        return; // available — brak baneru
    }

    const container = document.createElement('div');
    container.innerHTML = html;
    const banner = container.firstElementChild as HTMLElement;
    buyArea.insertBefore(banner, buyArea.firstChild);

    // Bind ask buttons
    const askBtn = banner.querySelector<HTMLElement>('[id$="-ask"]');
    if (askBtn && this.onAskClick) {
      const handler = this.onAskClick;
      const name = document.querySelector<HTMLElement>('h1')?.textContent?.trim() || '';
      askBtn.addEventListener('click', () => {
        handler(info.sku, name, state === 'overlimit' ? qty : undefined, state === 'price-zero');
      });
    }
  }

  destroy(): void {
    this.removeAll();
  }

  private removeAll(): void {
    for (const id of BANNER_IDS) {
      document.getElementById(id)?.remove();
    }
  }
}
