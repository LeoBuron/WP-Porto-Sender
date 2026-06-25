document.querySelectorAll('.porto-request-form').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = form.querySelector('.porto-message');
    // The ALTCHA widget exposes its solved payload via a hidden <input name="altcha">
    // it injects into the form (not via a property on the custom element).
    const altchaInput = form.querySelector('input[name="altcha"]');
    const payload = {
      name: form.porto_name.value,
      email: form.porto_email.value,
      product: (form.querySelector('input[name="porto_product"]:checked') || {}).value,
      captcha: altchaInput ? altchaInput.value : '',
    };
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
    msg.textContent = messages[data.status] || 'Es ist ein Fehler aufgetreten.';
  });
});
