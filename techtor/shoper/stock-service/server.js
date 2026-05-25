const express = require('express');
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

function cors(req, res, next) {
  const origin = req.headers.origin;
  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.set('Access-Control-Allow-Headers', 'Content-Type');
    res.set('Vary', 'Origin');
  }
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
}

// snippet.js — cache 5min, CORS (obsługuje też /v2/snippet.js)
app.get(['/snippet.js', '/v2/snippet.js', '/v3/snippet.js', '/v4/snippet.js', '/v5/snippet.js', '/v6/snippet.js'], cors, (req, res) => {
  res.set('Cache-Control', 'public, max-age=300, s-maxage=300');
  res.set('Content-Type', 'application/javascript; charset=utf-8');
  res.sendFile(path.join(__dirname, 'public', 'snippet.js'));
});

// stock-data.json — cache 5min, CORS
app.get('/api/stock-data.json', cors, (req, res) => {
  res.set('Cache-Control', 'public, max-age=300');
  res.set('Content-Type', 'application/json; charset=utf-8');
  res.sendFile(path.join(__dirname, 'public', 'stock-data.json'), err => {
    if (err) res.status(404).json({ error: 'stock-data.json not generated yet' });
  });
});

// Zapytanie o dostępność — formularz z karty produktu
app.post('/api/ask', cors, async (req, res) => {
  const { name, email, phone, message, sku, product } = req.body || {};
  if (!name || !email || !message) {
    return res.status(400).json({ ok: false, error: 'Wypełnij wymagane pola' });
  }

  const text = `Nowe zapytanie o dostępność produktu\n\n` +
    `Produkt: ${product || '?'} (${sku || '?'})\n` +
    `Link: https://techtor.pl/?s=${encodeURIComponent(sku || '')}\n\n` +
    `Od: ${name}\nEmail: ${email}\nTelefon: ${phone || 'brak'}\n\n` +
    `Wiadomość:\n${message}`;

  console.log(`[ASK] ${sku} od ${email}`);

  // Wyślij maila przez SMTP
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
    } catch (e) {
      console.error('[ASK] SMTP error:', e.message);
    }
  }

  // Zapisz do pliku (backup)
  const fs = require('fs');
  const logFile = path.join(__dirname, 'ask-log.json');
  let log = [];
  try { log = JSON.parse(fs.readFileSync(logFile, 'utf-8')); } catch {}
  log.push({ ts: new Date().toISOString(), sku, product, name, email, phone });
  fs.writeFileSync(logFile, JSON.stringify(log.slice(-500), null, 2));

  res.json({ ok: true });
});

// OAuth callback — łapie authorization code z Shoper
app.get('/oauth/callback', async (req, res) => {
  const code = req.query.code;
  if (!code) return res.status(400).send('Missing code');

  const fs = require('fs');
  // Zapisz code do pliku żeby go użyć
  fs.writeFileSync(path.join(__dirname, 'oauth_code.txt'), String(code));
  console.log('OAuth code received:', code);
  res.send('OK — code received: ' + code);
});

// health
app.get('/health', (req, res) => {
  res.json({ status: 'ok', service: 'shoper-stock-service' });
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`Stock service running on port ${PORT}`);
});
