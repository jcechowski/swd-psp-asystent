import type { WidgetState, StockInfo } from '../types';
import { dbg } from '../utils/debug';

/** Oblicza stan widgetu na podstawie danych stocku i ilości */
export function computeState(info: StockInfo, qty: number): WidgetState {
  // Priorytet 1: cena = 0
  if (info.isPrice0) return 'price-zero';

  // Priorytet 2: brak stocku
  if (info.totalStock <= 0) return 'out-of-stock';

  // Priorytet 3: overlimit (ilość > stock)
  if (qty > info.totalStock) return 'overlimit';

  // Dostępny
  return 'available';
}

/** Loguje zmianę stanu */
export function logTransition(prev: WidgetState, next: WidgetState, info: StockInfo, qty: number): void {
  if (prev !== next) {
    dbg(`Stan: ${prev} → ${next} (sku=${info.sku}, stock=${info.totalStock}, qty=${qty}, price0=${info.isPrice0})`);
  }
}
