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

// ── Snippet JS — NO CACHE (Cloudflare nadpisywał max-age na 28800=8h!) ──────
// Obsługuje: /snippet.js, /v2/snippet.js, /v99/snippet.js, /snippet.js?v=abc
app.get(/^\/(v\d+\/)?snippet\.js$/, cors, (req, res) => {
  res.set('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
  res.set('CDN-Cache-Control', 'no-store');
  res.set('Cloudflare-CDN-Cache-Control', 'no-store');
  res.set('Pragma', 'no-cache');
  res.set('Expires', '0');
  res.set('Content-Type', 'application/javascript; charset=utf-8');
  res.set('ETag', `"${snippetHash}"`);
  loadSnippet();
  res.send(snippetContent);
});

// ── Loader HTML — iframe w opisie produktu ładuje snippet na parent ──────────
app.get('/loader.html', (req, res) => {
  res.set('Content-Type', 'text/html; charset=utf-8');
  res.set('Cache-Control', 'public, max-age=300');
  res.send(`<!DOCTYPE html><html><body><script>
try {
  var p = window.parent;
  if (p && p !== window && !p.document.querySelector('script[data-techtor]')) {
    var s = p.document.createElement('script');
    s.src = 'https://stock.techtor.pl/v2/snippet.js?v=${snippetHash}';
    s.dataset.techtor = '1';
    p.document.head.appendChild(s);
  }
} catch(e) {}
</script></body></html>`);
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

  const { name, email, phone, nip, company, street, zip, city, message, quantity, sku, product, url, _hp } = req.body || {};

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
    `Ilość: ${quantity || '?'} szt.\n` +
    `Link: ${url || 'https://techtor.pl/?s=' + encodeURIComponent(sku || '')}\n\n` +
    `Od: ${name}\nEmail: ${email}\nTelefon: ${phone || 'brak'}\n` +
    `${nip ? 'NIP: ' + nip + '\n' : ''}` +
    `${company ? 'Firma: ' + company + '\n' : ''}` +
    `${street ? 'Adres: ' + street + (zip ? ', ' + zip : '') + (city ? ' ' + city : '') + '\n' : ''}\n` +
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

// ── Debugger — strona HTML do wklejenia w iframe na techtor.pl ──────────────
app.get('/debug', cors, (req, res) => {
  res.set('Content-Type', 'text/html; charset=utf-8');
  res.send(`<!DOCTYPE html><html><head><title>TECHTOR Stock Debug</title>
<style>
  body{font-family:system-ui;background:#111;color:#eee;padding:20px;font-size:14px;margin:0}
  h2{color:#f59e0b;margin:0 0 16px}
  .ok{color:#10b981} .err{color:#ef4444} .warn{color:#f59e0b}
  pre{background:#1f2937;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px;max-height:300px;overflow-y:auto}
  button{background:#3b82f6;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;margin:4px}
  button:hover{background:#2563eb}
  #log{white-space:pre-wrap}
</style></head><body>
<h2>TECHTOR Stock Debugger</h2>
<div id="log"></div>
<button onclick="runTest()">Uruchom ponownie</button>
<button onclick="testForm()">Test formularza</button>
<script>
var L=document.getElementById('log');
function log(msg,cls){L.innerHTML+='<div class="'+(cls||'')+'">'+ new Date().toLocaleTimeString()+' '+msg+'</div>';}

async function runTest(){
  L.innerHTML='';
  log('=== TECHTOR Stock Debugger ===','warn');

  // 1. Czy snippet zaladowany?
  log('window._tLoad = '+window.parent._tLoad);
  log('window._tRunId = '+window.parent._tRunId);
  log('window._tInterval = '+window.parent._tInterval);
  log('window._tRerun = '+(typeof window.parent._tRerun));
  log('window._tSendAsk = '+(typeof window.parent._tSendAsk));

  if(window.parent._tLoad){log('Snippet ZALADOWANY','ok');}
  else{log('Snippet NIE ZALADOWANY!','err');}

  // 2. DOM elementy
  var p=window.parent.document;
  var sku=p.querySelector('[data-product-code="sku"]');
  var qi=p.querySelector('h-input-stepper, [class*="quantity__input"], input[name="quantity"]');
  var de=p.querySelector('[data-shipping-time]');
  var buyBtns=p.querySelectorAll('buy-button, .btn_primary');
  var banner=p.querySelector('.techtor-unavailable-banner');
  var askBtn=p.querySelector('.techtor-ask-btn');
  var modal=p.getElementById('techtor-ask-modal');
  var sendBtn=p.getElementById('techtor-ask-send');
  var hideCSS=p.getElementById('techtor-unavailable-css');

  log('SKU: '+(sku?sku.textContent.trim():'NULL'));
  log('Quantity input (qi): '+(qi?qi.tagName+'.'+qi.className:'NULL'));
  log('Shipping time (de): '+(de?'"'+de.textContent+'"':'NULL'));
  log('Buy buttons: '+buyBtns.length);
  log('Unavailable banner: '+(banner?'TAK':'NIE'));
  log('Ask button: '+(askBtn?'TAK':'NIE'));
  log('Modal open: '+(modal?'TAK':'NIE'));
  log('Send button: '+(sendBtn?'TAK — disabled='+sendBtn.disabled:'NIE'));
  log('Hide CSS: '+(hideCSS?'TAK':'NIE'));
  log('.techtor-hide elements: '+p.querySelectorAll('.techtor-hide').length);
  log('[data-techtor-hidden] elements: '+p.querySelectorAll('[data-techtor-hidden]').length);

  // 3. Stock data
  try{
    var r=await fetch('https://stock.techtor.pl/api/stock-data.json');
    var d=await r.json();
    var skuVal=sku?sku.textContent.trim():'';
    if(skuVal&&d){
      log('stockTechtor['+skuVal+']: '+(d[skuVal]||0));
      log('totalStock['+skuVal+']: '+(d[skuVal+'__total']||0));
      log('status['+skuVal+']: '+(d[skuVal+'__status']||'brak'));
    }
    log('Stock data: '+Object.keys(d).length+' entries','ok');
  }catch(e){log('Stock data BLAD: '+e.message,'err');}

  // 4. Script tags
  var scripts=p.querySelectorAll('script[data-techtor-snippet], script[src*="stock.techtor"]');
  log('Script tags ze snippet: '+scripts.length);
  scripts.forEach(function(s,i){log('  ['+i+'] src='+(s.src||'inline').substring(0,80));});

  // 5. Snippet wersja
  try{
    var r2=await fetch('https://stock.techtor.pl/health');
    var h=await r2.json();
    log('Server snippet hash: '+h.snippetHash);
    log('Server uptime: '+h.uptime+'s');
  }catch(e){log('Health check BLAD: '+e.message,'err');}

  log('=== KONIEC ===','warn');
}

function testForm(){
  log('--- TEST FORMULARZA ---','warn');
  var p=window.parent;
  log('_tSendAsk: '+(typeof p._tSendAsk));

  // Sprobuj otworzyc modal
  var modal=p.document.getElementById('techtor-ask-modal');
  if(!modal){
    log('Modal nie istnieje — probuje otworzyc...','warn');
    var askBtn=p.document.querySelector('.techtor-ask-btn');
    if(askBtn){askBtn.click();log('Kliknalem askBtn','ok');}
    else{log('Brak przycisku Zapytaj!','err');return;}
    setTimeout(function(){testFormInner();},500);
  }else{testFormInner();}
}
function testFormInner(){
  var p=window.parent;
  var modal=p.document.getElementById('techtor-ask-modal');
  log('Modal po otwarciu: '+(modal?'TAK':'NIE'));
  log('_tSendAsk po otwarciu: '+(typeof p._tSendAsk));
  var sendBtn=p.document.getElementById('techtor-ask-send');
  log('Send button: '+(sendBtn?'TAK, disabled='+sendBtn.disabled+', onclick='+(typeof sendBtn.onclick):'NIE'));
  var form=p.document.getElementById('techtor-ask-form');
  log('Form: '+(form?'TAK, onsubmit='+(typeof form.onsubmit):'NIE'));
  if(sendBtn){
    log('Send btn HTML: '+sendBtn.outerHTML.substring(0,200));
  }

  // Test fetch do API
  fetch('https://stock.techtor.pl/api/ask',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({name:'DEBUG TEST',email:'debug@test.pl',message:'test debugger',sku:'TEST',product:'Debug',url:location.href,quantity:'1',_hp:''})
  }).then(function(r){return r.json();}).then(function(d){
    log('API /ask odpowiedz: '+JSON.stringify(d),(d.ok?'ok':'err'));
  }).catch(function(e){log('API /ask BLAD: '+e.message,'err');});
}

runTest();
</script></body></html>`);
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`Stock service running on port ${PORT}`);
});
