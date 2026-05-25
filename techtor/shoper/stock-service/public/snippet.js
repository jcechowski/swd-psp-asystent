(function () {
  'use strict';

  var API_URL = 'https://stock.techtor.pl/api/stock-data.json';
  var attached = false;

  function getSku() {
    var el = document.querySelector('[data-product-code="sku"]');
    if (el) return el.textContent.trim();
    var m = document.body.innerHTML.match(/"sku"\s*:\s*"([^"]+)"/);
    if (m) return m[1];
    return null;
  }

  fetch(API_URL)
    .then(function (r) { return r.json(); })
    .then(function (stockData) {

      function tryAttach() {
        if (attached) return;

        var sku = getSku();
        if (!sku) return;

        var stockTechtor = stockData[sku] || 0;
        var tarnawaStatus = stockData[sku + '__status'] || '';

        // Dynamiczny czas wysyłki
        var qi = document.querySelector('h-input-stepper.product-quantity__input, .product-quantity__input');
        var de = document.querySelector('[data-shipping-time]');

        if (qi && de && stockTechtor > 0) {
          attached = true;

          function upd() {
            var q = parseInt(qi.getAttribute('value') || qi.value, 10) || 1;
            if (q <= stockTechtor) {
              de.textContent = '24 godziny';
              de.style.color = '';
            } else {
              de.textContent = '48 godzin';
              de.style.color = '#b45309';
            }
          }

          new MutationObserver(function () { setTimeout(upd, 10); })
            .observe(qi, { attributes: true, attributeFilter: ['value'] });
          var qc = qi.closest('product-quantity, [class*="quantity"]');
          if (qc) qc.addEventListener('click', function () { setTimeout(upd, 50); });
          upd();
        }

        // "Zapytaj o dostępność" gdy produkt niedostępny (oba magazyny = 0)
        if (stockTechtor <= 0 && !qi) {
          // Brak inputa ilości = Shoper zablokował koszyk (can_buy=0)
          attached = true;
          var buyArea = document.querySelector('buy-button, .product-actions, [data-module-name="product_actions"]');
          if (buyArea && !buyArea.querySelector('.techtor-ask-btn')) {
            var askBtn = document.createElement('a');
            askBtn.href = '/pl/contact';
            askBtn.className = 'techtor-ask-btn';
            askBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:12px 24px;border-radius:30px;text-decoration:none;font-weight:600;font-size:14px;background-color:#b45309;color:#fff;margin-top:8px;';
            askBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Zapytaj o dostępność';
            buyArea.appendChild(askBtn);
          }
        }
      }

      tryAttach();
      var obs = new MutationObserver(function () { if (!attached) tryAttach(); else obs.disconnect(); });
      obs.observe(document.documentElement, { childList: true, subtree: true });
    })
    .catch(function () {});
})();
