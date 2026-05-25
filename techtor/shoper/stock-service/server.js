const express = require('express');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || 'https://techtor.pl,https://www.techtor.pl')
  .split(',').map(s => s.trim()).filter(Boolean);

const NOTIFY_EMAIL = process.env.NOTIFY_EMAIL || 'biuro@techtor.pl';
const SMTP_HOST = process.env.SMTP_HOST || 'smtp.gmail.com';
const SMTP_PORT = parseInt(process.env.SMTP_PORT || '587');
const SMTP_USER = process.env.SMTP_USER || 'biuro@techtor.pl';
const SMTP_PASS = process.env.SMTP_PASS || '';

app.use(express.json());

// ── Snippet hash — automatyczne wersjonowanie ──────────────────────────────
let snippetHash = '';
let snippetContent = '';
function loadSnippet() {
  try {
    snippetContent = fs.readFileSync(path.join(__dirname, 'public', 'snippet.js'), 'utf-8');
    snippetHash = crypto.createHash('md5').update(snippetContent).digest('hex').slice(0, 8);
    console.log(`Snippet loaded: hash=${snippetHash}, size=${snippetContent.length}B`);
  } catch (e) {
    console.error('Snippet load error:', e.message);
  }
}
loadSnippet();
// Reload co 60s (łapie zmiany z volume mount)
setInterval(loadSnippet, 60000);

// ── CORS ────────────────────────────────────────────────────────────────────
app.options('*', (req, res) => {
  const origin = req.headers.origin;
  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.set('Access-Control-Allow-Headers', 'Content-Type');
  }
  res.sendStatus(204);
});

function cors(req, res, next) {
  const origin = req.headers.origin;
  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.set('Access-Control-Allow-Headers', 'Content-Type');
    res.set('Vary', 'Origin');
  }
  next();
}

// ── Snippet JS — wildcard route, cache 1h ───────────────────────────────────
// Obsługuje: /snippet.js, /v2/snippet.js, /v99/snippet.js, /snippet.js?v=abc
app.get(/^\/(v\d+\/)?snippet\.js$/, cors, (req, res) => {
  res.set('Cache-Control', 'public, max-age=3600, s-maxage=3600');
  res.set('Content-Type', 'application/javascript; charset=utf-8');
  res.set('ETag', `"${snippetHash}"`);
  res.send(snippetContent);
});

// ── Hash endpoint — sync-stock.py pobiera aktualny hash ─────────────────────
app.get('/api/snippet-hash', cors, (req, res) => {
  res.json({ hash: snippetHash });
});

// ── stock-data.json — cache 5min, CORS ──────────────────────────────────────
app.get('/api/stock-data.json', cors, (req, res) => {
  res.set('Cache-Control', 'public, max-age=300');
  res.set('Content-Type', 'application/json; charset=utf-8');
  res.sendFile(path.join(__dirname, 'public', 'stock-data.json'), err => {
    if (err) res.status(404).json({ error: 'stock-data.json not generated yet' });
  });
});

// ── Rate limiting dla /api/ask ──────────────────────────────────────────────
const askLimiter = {};
const ASK_LIMIT = 5;          // max 5 zapytań
const ASK_WINDOW = 10 * 60000; // per 10 minut per IP

function checkRateLimit(ip) {
  const now = Date.now();
  if (!askLimiter[ip]) askLimiter[ip] = [];
  askLimiter[ip] = askLimiter[ip].filter(t => now - t < ASK_WINDOW);
  if (askLimiter[ip].length >= ASK_LIMIT) return false;
  askLimiter[ip].push(now);
  return true;
}
// Cleanup co 15 min
setInterval(() => {
  const now = Date.now();
  for (const ip in askLimiter) {
    askLimiter[ip] = askLimiter[ip].filter(t => now - t < ASK_WINDOW);
    if (askLimiter[ip].length === 0) delete askLimiter[ip];
  }
}, 15 * 60000);

// ── Zapytanie o dostępność ──────────────────────────────────────────────────
app.post('/api/ask', cors, async (req, res) => {
  const ip = req.headers['x-forwarded-for']?.split(',')[0]?.trim() || req.ip;

  // Rate limit
  if (!checkRateLimit(ip)) {
    return res.status(429).json({ ok: false, error: 'Za dużo zapytań. Spróbuj za 10 minut.' });
  }

  const { name, email, phone, message, sku, product, url, _hp } = req.body || {};

  // Honeypot — pole _hp powinno być puste (boty je wypełniają)
  if (_hp) {
    return res.json({ ok: true }); // fake success dla bota
  }

  if (!name || !email || !message) {
    return res.status(400).json({ ok: false, error: 'Wypełnij wymagane pola' });
  }

  // Walidacja email
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return res.status(400).json({ ok: false, error: 'Nieprawidłowy adres email' });
  }

  const text = `Nowe zapytanie o dostępność produktu\n\n` +
    `Produkt: ${product || '?'} (${sku || '?'})\n` +
    `Link: ${url || 'https://techtor.pl/?s=' + encodeURIComponent(sku || '')}\n\n` +
    `Od: ${name}\nEmail: ${email}\nTelefon: ${phone || 'brak'}\n\n` +
    `Wiadomość:\n${message}`;

  console.log(`[ASK] ${sku} od ${email} (IP: ${ip})`);

  if (SMTP_PASS) {
    try {
      const nodemailer = require('nodemailer');
      const transport = nodemailer.createTransport({
        host: SMTP_HOST, port: SMTP_PORT, secure: false,
        auth: { user: SMTP_USER, pass: SMTP_PASS },
      });
      await transport.sendMail({
        from: `"Sklep TECHTOR" <${SMTP_USER}>`,
        replyTo: email,
        to: NOTIFY_EMAIL,
        subject: `Zapytanie o dostępność: ${product || sku}`,
        text,
      });
      await transport.sendMail({
        from: `"TECHTOR" <${SMTP_USER}>`,
        to: email,
        subject: `Potwierdzenie zapytania — ${product || sku}`,
        text: `Dzień dobry ${name},\n\n` +
          `Dziękujemy za zapytanie o produkt:\n` +
          `${product} (${sku})\n` +
          `${url || ''}\n\n` +
          `Otrzymaliśmy Twoje zgłoszenie i skontaktujemy się najszybciej jak to możliwe.\n\n` +
          `Pozdrawiamy,\n` +
          `Zespół TECHTOR\n` +
          `tel. 736 133 816\n` +
          `biuro@techtor.pl\n` +
          `https://techtor.pl`,
      });
    } catch (e) {
      console.error('[ASK] SMTP error:', e.message);
    }
  }

  const logFile = path.join(__dirname, 'ask-log.json');
  let log = [];
  try { log = JSON.parse(fs.readFileSync(logFile, 'utf-8')); } catch {}
  log.push({ ts: new Date().toISOString(), sku, product, name, email, phone, ip });
  try { fs.writeFileSync(logFile, JSON.stringify(log.slice(-500), null, 2)); } catch {}

  res.json({ ok: true });
});

// ── Health ───────────────────────────────────────────────────────────────────
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'shoper-stock-service',
    snippetHash,
    uptime: Math.round(process.uptime()),
  });
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`Stock service running on port ${PORT}`);
});
