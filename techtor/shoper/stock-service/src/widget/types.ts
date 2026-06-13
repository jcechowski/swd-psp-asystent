/** Stany maszyny stanów widgetu */
export type WidgetState = 'loading' | 'available' | 'overlimit' | 'out-of-stock' | 'price-zero';

/** Dane stocku dla SKU */
export interface StockInfo {
  sku: string;
  stockTechtor: number;
  totalStock: number;
  isPrice0: boolean;
}

/** Interfejs adaptera — Event Bus lub DOM fallback */
export interface WidgetAdapter {
  onProductChange(cb: (sku: string) => void): void;
  onQuantityChange(cb: (qty: number) => void): void;
  onNavigation(cb: () => void): void;
  destroy(): void;
}

/** Interfejs modułu widgetu */
export interface WidgetModule {
  apply(state: WidgetState, info: StockInfo, qty: number): void;
  destroy(): void;
}

/** Shoper useStorefront callback params */
export interface StorefrontApi {
  eventBus: {
    on(event: string, cb: (data: unknown) => void): void;
    once(event: string, cb: (data: unknown) => void): void;
    off(event: string, cb: (data: unknown) => void): void;
    emit(event: string, data?: unknown): void;
  };
  commandBus: {
    execute(command: unknown): Promise<unknown>;
    executeSync(command: unknown): unknown;
  };
  queryBus: {
    execute(query: unknown): Promise<unknown>;
    executeSync(query: unknown): unknown;
  };
  getApi(name: string): Promise<unknown>;
  getApiSync(name: string): unknown;
  isFeatureEnabled(name: string): boolean;
}

declare global {
  interface Window {
    useStorefront?: (cb: (api: StorefrontApi) => void) => void;
    __techtorWidget?: {
      version: number;
      runId: number;
      initialized: boolean;
      mode: string;
      state?: WidgetState;
    };
    _tRerun?: () => void;
  }
}
