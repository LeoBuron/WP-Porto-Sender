document.querySelectorAll('.porto-request-form').forEach((form) => {
  const msg = form.querySelector('.porto-message');
  const submitBtn = form.querySelector('button[type="submit"]') || form.querySelector('button');

  // The ALTCHA captcha computes its proof-of-work with the Web Crypto API
  // (crypto.subtle), which browsers expose ONLY in a secure context — HTTPS, or
  // localhost. On an insecure origin (typically a phone opening the site over plain
  // http://) crypto.subtle is undefined, so the widget can never produce a solution
  // and the request would just bounce as "captcha_failed". Fail loudly with the real
  // reason and don't wire up a submit that cannot succeed.
  if (!window.isSecureContext) {
    if (submitBtn) { submitBtn.disabled = true; }
    if (msg) {
      msg.textContent = 'Die Sicherheitsabfrage funktioniert nur über eine sichere '
        + 'HTTPS-Verbindung. Bitte öffne diese Seite über https://.';
    }
    return;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    // The ALTCHA widget exposes its solved payload via a hidden <input name="altcha">
    // it injects into the form (not via a property on the custom element).
    const altchaInput = form.querySelector('input[name="altcha"]');
    const payload = {
      name: form.porto_name.value,
      email: form.porto_email.value,
      product: (form.querySelector('input[name="porto_product"]:checked') || {}).value,
      captcha: altchaInput ? altchaInput.value : '',
    };

    // On slower phones the ALTCHA proof-of-work (started on first focus) may not be
    // finished yet. Submitting now would just bounce as captcha_failed — instead tell
    // the user to wait a moment and retry once the widget shows "verified".
    if (!payload.captcha) {
      if (msg) { msg.textContent = 'Die Sicherheitsabfrage läuft noch. Bitte einen Moment warten und erneut senden.'; }
      return;
    }

    // Processing state: the server call is synchronous (it sends the confirmation
    // email before responding), so give clear feedback and block a double submit —
    // double submits are what let one visitor collect several confirmation links.
    const originalLabel = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Wird verarbeitet …'; }
    form.setAttribute('aria-busy', 'true');
    if (msg) { msg.textContent = 'Deine Anfrage wird verarbeitet …'; }

    try {
      const res = await fetch(form.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      const messages = {
        confirmation_sent: 'Bitte bestätige die Anfrage über den Link in deiner E-Mail.',
        duplicate: 'Du hast bereits einen Code angefordert.',
        out_of_stock: 'Aktuell sind keine Codes verfügbar.',
        captcha_failed: 'Bitte löse die Sicherheitsabfrage erneut.',
        invalid: 'Bitte fülle alle Felder korrekt aus.',
        rate_limited: 'Zu viele Anfragen. Bitte versuche es später erneut.',
        geo_blocked: 'Dieser Dienst ist auf Anfragen aus Deutschland beschränkt.',
      };
      if (msg) { msg.textContent = messages[data.status] || 'Es ist ein Fehler aufgetreten.'; }
    } catch (err) {
      if (msg) { msg.textContent = 'Verbindungsfehler. Bitte versuche es später erneut.'; }
    } finally {
      form.removeAttribute('aria-busy');
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalLabel; }
    }
  });
});
