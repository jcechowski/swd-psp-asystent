const express = require('express');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || 'https://techtor.pl,https://www.techtor.pl')
  .split(',').map(s => s.trim()).filter(Boolean);

function cors(req, res, next) {
  const origin = req.headers.origin;
  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Access-Control-Allow-Methods', 'GET');
    res.set('Vary', 'Origin');
  }
  next();
}

// snippet.js — cache 5min, CORS (obsługuje też /v2/snippet.js)
app.get(['/snippet.js', '/v2/snippet.js'], cors, (req, res) => {
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
