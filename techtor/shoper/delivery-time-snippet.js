/**
 * Dynamiczny czas wysyłki na karcie produktu — Shoper snippet.
 *
 * Logika:
 *   warn_level = stan magazynu TECHTOR (ustawiany przez sync-stock.py)
 *   stock      = suma TECHTOR + TARNAWA (max do kupienia)
 *
 *   qty ≤ warn_level  → "Czas wysyłki: 24 godziny"  (mamy na magazynie)
 *   qty > warn_level  → "Czas wysyłki: 48 godzin"   (część z dostawcy)
 *
 * Instalacja:
 *   Panel Shoper → Wygląd → Edycja szablonu → product.tpl
 *   Wklej ten skrypt przed </body> lub dodaj w:
 *   Panel Shoper → Wygląd → Ustawienia → Własny kod JavaScript
 */
(function () {
  'use strict';

  // Czekaj na załadowanie strony produktu
  if (typeof Shop === 'undefined' || !Shop.product) return;

  var productId = Shop.product.id;
  if (!productId) return;

  // Pobierz dane stocka z front API
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '/webapi/front/product/' + productId + '?include=stock');
  xhr.onload = function () {
    if (xhr.status !== 200) return;

    var data;
    try { data = JSON.parse(xhr.responseText); } catch (e) { return; }

    var stock = data.stock || data.main_stock || {};
    var warnLevel = parseInt(stock.warn_level, 10);
    var totalStock = parseInt(stock.stock, 10);

    // warn_level = stan TECHTOR (ustawiony przez sync-stock.py)
    // Jeśli warn_level nie ustawiony → nie zmieniaj nic
    if (isNaN(warnLevel) || warnLevel <= 0) return;
    if (isNaN(totalStock) || totalStock <= 0) return;

    var stockTechtor = warnLevel;

    // Znajdź input ilości na stronie
    var qtyInput = document.querySelector(
      'input[name="quantity"], input.product-quantity, input#product_quantity, input[data-quantity]'
    );
    if (!qtyInput) return;

    // Znajdź element z czasem wysyłki
    var deliveryEl = findDeliveryElement();
    if (!deliveryEl) return;

    var originalText = deliveryEl.textContent;

    function updateDeliveryInfo() {
      var qty = parseInt(qtyInput.value, 10) || 1;

      if (qty <= stockTechtor) {
        deliveryEl.textContent = '24 godziny';
        deliveryEl.style.color = '';
      } else if (qty <= totalStock) {
        deliveryEl.textContent = '48 godzin';
        deliveryEl.style.color = '#b45309'; // amber
      }
    }

    // Nasłuchuj zmian ilości
    qtyInput.addEventListener('input', updateDeliveryInfo);
    qtyInput.addEventListener('change', updateDeliveryInfo);

    // Nasłuchuj kliknięć +/- (Shoper używa przycisków do zmiany ilości)
    var qtyContainer = qtyInput.closest('.product__quantity, .quantity, .product-quantity');
    if (qtyContainer) {
      qtyContainer.addEventListener('click', function () {
        setTimeout(updateDeliveryInfo, 50);
      });
    }

    // Ustaw początkowy stan
    updateDeliveryInfo();
  };
  xhr.send();

  /**
   * Znajdź element DOM z czasem wysyłki.
   * Shoper szablony różnią się — próbujemy kilka selektorów.
   */
  function findDeliveryElement() {
    // Typowe selektory w szablonach Shoper
    var selectors = [
      '.product__delivery-time .value',
      '.product__delivery .delivery-value',
      '.delivery-time__value',
      '[data-delivery-time]',
      '.product-delivery-time',
    ];

    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el) return el;
    }

    // Fallback: szukaj tekstu "24 godziny" lub "48 godzin" w pobliżu "Czas wysyłki"
    var allSpans = document.querySelectorAll('span, div, p, td');
    for (var j = 0; j < allSpans.length; j++) {
      var text = allSpans[j].textContent.trim();
      if (text === '24 godziny' || text === '48 godzin' || text === '3-4 dni') {
        // Sprawdź czy parent zawiera "wysyłk" lub "dostaw"
        var parent = allSpans[j].parentElement;
        if (parent && /wysyłk|dostaw|delivery/i.test(parent.textContent)) {
          return allSpans[j];
        }
      }
    }

    return null;
  }
})();
