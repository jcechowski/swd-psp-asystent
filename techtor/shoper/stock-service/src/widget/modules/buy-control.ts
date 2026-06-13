import type { WidgetModule, WidgetState, StockInfo } from '../types';

/** Kontroluje blokadę/odblokadę koszyka (buy-button) */
export class BuyControl implements WidgetModule {
  apply(state: WidgetState, _info: StockInfo, _qty: number): void {
    const buyBtns = document.querySelectorAll<HTMLElement>('buy-button, [class*="buy-button"], .product-buy__button');
    const stepper = document.querySelector<HTMLElement>('h-input-stepper, [class*="quantity__input"]');
    const stepperWrapper = stepper?.closest('product-quantity, [class*="quantity"], .product-quantity') as HTMLElement | null;

    // Blokada TYLKO przy price-zero (brak ceny → klient musi zapytać)
    // Overlimit → koszyk odblokowany, baner "Zapytaj o dostępność"
    const shouldBlock = state === 'price-zero';
    const shouldHide = state === 'price-zero';

    for (const bb of buyBtns) {
      if (shouldHide) {
        bb.classList.add('techtor-hide');
        bb.dataset.techtorHidden = '1';
      } else {
        bb.classList.remove('techtor-hide');
        delete bb.dataset.techtorHidden;
      }

      const btn = bb.querySelector<HTMLButtonElement>('.btn_primary, button[type="submit"]')
        || (bb.classList.contains('btn_primary') ? bb as HTMLButtonElement : null);
      if (btn) {
        if (shouldBlock) {
          btn.setAttribute('disabled', 'true');
          btn.style.opacity = '0.5';
          btn.style.pointerEvents = 'none';
        } else {
          btn.removeAttribute('disabled');
          btn.style.opacity = '';
          btn.style.pointerEvents = '';
        }
      }

      if (shouldBlock) {
        bb.setAttribute('is-buyable', '0');
      } else {
        bb.removeAttribute('is-buyable');
      }
    }

    if (stepperWrapper) {
      if (shouldHide) {
        stepperWrapper.classList.add('techtor-hide');
        stepperWrapper.dataset.techtorHidden = '1';
      } else {
        stepperWrapper.classList.remove('techtor-hide');
        delete stepperWrapper.dataset.techtorHidden;
      }
    }
  }

  destroy(): void {
    document.querySelectorAll<HTMLElement>('[data-techtor-hidden]').forEach(el => {
      el.classList.remove('techtor-hide');
      delete el.dataset.techtorHidden;
    });
    document.querySelectorAll<HTMLElement>('buy-button').forEach(bb => {
      bb.removeAttribute('is-buyable');
      const btn = bb.querySelector<HTMLButtonElement>('.btn_primary, button');
      if (btn) {
        btn.removeAttribute('disabled');
        btn.style.opacity = '';
        btn.style.pointerEvents = '';
      }
    });
  }
}
