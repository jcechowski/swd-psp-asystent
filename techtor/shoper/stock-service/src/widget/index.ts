/**
 * TECHTOR Widget v3 — natywny widget Shoper
 * Zastępuje snippet.js — Event Bus zamiast DOM polling
 * https://stock.techtor.pl/v3/widget.js
 */
import { bootstrap } from './core/bootstrap';

// Start
bootstrap().catch(err => console.error('[TECHTOR] Widget bootstrap error:', err));
