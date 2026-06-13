/** Globalne style widgetu — wstrzykiwane do <head> */
export const WIDGET_CSS = `
.techtor-hide { display: none !important; }
.techtor-banner {
  margin: 16px 0 12px;
  padding: 16px 20px;
  border-radius: 12px;
  text-align: center;
}
.techtor-banner--warning {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  border: 1px solid #fde68a;
}
.techtor-banner--error {
  background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
  border: 1px solid #fecaca;
}
.techtor-banner__row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}
.techtor-banner__text {
  font-size: 14px;
  font-weight: 600;
}
.techtor-banner__text--amber { color: #92400e; }
.techtor-banner__text--red { color: #991b1b; }
.techtor-banner__detail {
  margin: 8px 0 0;
  font-size: 13px;
  color: #6b7280;
}
.techtor-ask-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 14px 32px;
  border-radius: 30px;
  border: none;
  cursor: pointer;
  font-weight: 700;
  font-size: 15px;
  color: #fff;
  transition: all 0.2s ease;
  margin-top: 12px;
}
.techtor-ask-btn--amber {
  background: #d97706;
  box-shadow: 0 4px 14px rgba(217,119,6,0.25);
}
.techtor-ask-btn--amber:hover {
  background: #b45309;
  box-shadow: 0 6px 20px rgba(217,119,6,0.35);
  transform: translateY(-1px);
}
.techtor-ask-btn--red {
  background: #dc2626;
  box-shadow: 0 4px 14px rgba(220,38,38,0.25);
}
.techtor-ask-btn--red:hover {
  background: #b91c1c;
  transform: translateY(-1px);
}
`;

/** CSS ukrywający InPost Pay */
export const INPOST_HIDE_CSS = `inpost-izi-button, INPOST-IZI-BUTTON { display: none !important; }`;

/** CSS ukrywający natywny formularz Shoper "Zapytaj o produkt" */
export const SHOPER_ASK_HIDE_CSS = `product-ask-questions, [data-module-name="product_ask_questions"] { display: none !important; }`;
