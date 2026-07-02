// Per-field validation: the single source of truth for each field's message, the
// element to visually mark, the element to focus, and the validity check. Used by
// BOTH the pre-submit client check and the server "invalid" response handler, so
// however a bad value is caught the user sees the same field-specific feedback.
// The email check is stricter than the browser's lenient type="email" (which
// accepts "foo@bar"): it requires a dot in the domain, matching what the server's
// FILTER_VALIDATE_EMAIL will accept, so the common case is caught before submit.
const PORTO_FIELDS = {
  name: {
    message: 'Bitte gib deinen Namen ein.',
    mark: (form) => form.elements.porto_name,
    focus: (form) => form.elements.porto_name,
    valid: (form) => form.elements.porto_name.value.trim() !== '',
  },
  email: {
    message: 'Bitte gib eine gültige E-Mail-Adresse ein (z. B. name@example.de).',
    mark: (form) => form.elements.porto_email,
    focus: (form) => form.elements.porto_email,
    valid: (form) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.elements.porto_email.value.trim()),
  },
  product: {
    message: 'Bitte wähle ein Produkt aus.',
    mark: (form) => form.querySelector('fieldset'),
    focus: (form) => form.querySelector('input[name="porto_product"]'),
    valid: (form) => !!form.querySelector('input[name="porto_product"]:checked'),
  },
  consent: {
    message: 'Bitte stimme der Datenverarbeitung zu.',
    mark: (form) => form.elements.porto_consent,
    focus: (form) => form.elements.porto_consent,
    valid: (form) => form.elements.porto_consent.checked,
  },
};
// Validation order mirrors the fields' order in the form.
const PORTO_FIELD_ORDER = ['name', 'email', 'product', 'consent'];

function portoSetInvalid(el, on) {
  if (!el) { return; }
  el.classList.toggle('porto-invalid', on);
  if (on) { el.setAttribute('aria-invalid', 'true'); }
  else { el.removeAttribute('aria-invalid'); }
}

function portoClearMarks(form) {
  form.querySelectorAll('.porto-invalid').forEach((el) => portoSetInvalid(el, false));
}

// Clear a field's mark the moment the user starts fixing it.
function portoClearFieldMark(form, target) {
  if (!target) { return; }
  portoSetInvalid(target, false);
  // A product radio lives inside the <fieldset> we mark, so clear that too.
  if (target.name === 'porto_product') {
    portoSetInvalid(form.querySelector('fieldset'), false);
  }
}

// Mark every invalid field, announce the first one's message, and focus it.
function portoShowInvalid(form, keys, showMessage) {
  keys.forEach((k) => portoSetInvalid(PORTO_FIELDS[k].mark(form), true));
  showMessage(PORTO_FIELDS[keys[0]].message, true);
  const focusEl = PORTO_FIELDS[keys[0]].focus(form);
  if (focusEl && typeof focusEl.focus === 'function') { focusEl.focus(); }
}

document.querySelectorAll('.porto-request-form').forEach((form) => {
  const msg = form.querySelector('.porto-message');
  const submitBtn = form.querySelector('button[type="submit"]') || form.querySelector('button');

  // Single message sink: sets the text and toggles the error styling so every
  // status renders consistently (red for problems, neutral for progress/success).
  const showMessage = (text, isError) => {
    if (!msg) { return; }
    msg.textContent = text;
    msg.classList.toggle('porto-message--error', !!isError);
  };

  // Native validation is off (the form carries `novalidate`) so JS owns all field
  // feedback; drop a field's mark as soon as the user edits it.
  form.addEventListener('input', (e) => portoClearFieldMark(form, e.target));
  form.addEventListener('change', (e) => portoClearFieldMark(form, e.target));

  // The ALTCHA captcha computes its proof-of-work with the Web Crypto API
  // (crypto.subtle), which browsers expose ONLY in a secure context — HTTPS, or
  // localhost. On an insecure origin (typically a phone opening the site over plain
  // http://) crypto.subtle is undefined, so the widget can never produce a solution
  // and the request would just bounce as "captcha_failed". Fail loudly with the real
  // reason and don't wire up a submit that cannot succeed.
  if (!window.isSecureContext) {
    if (submitBtn) { submitBtn.disabled = true; }
    showMessage('Die Sicherheitsabfrage funktioniert nur über eine sichere '
      + 'HTTPS-Verbindung. Bitte öffne diese Seite über https://.', true);
    return;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // Validate every field up front. `novalidate` means the browser no longer
    // blocks submission, so this is the only gate before the captcha/network cost.
    portoClearMarks(form);
    const invalid = PORTO_FIELD_ORDER.filter((k) => !PORTO_FIELDS[k].valid(form));
    if (invalid.length) {
      portoShowInvalid(form, invalid, showMessage);
      return;
    }

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
      showMessage('Die Sicherheitsabfrage läuft noch. Bitte einen Moment warten und erneut senden.', true);
      return;
    }

    // Processing state: the server call is synchronous (it sends the confirmation
    // email before responding), so give clear feedback and block a double submit —
    // double submits are what let one visitor collect several confirmation links.
    const originalLabel = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Wird verarbeitet …'; }
    form.setAttribute('aria-busy', 'true');
    showMessage('Deine Anfrage wird verarbeitet …', false);

    try {
      const res = await fetch(form.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      // On success, navigate (GET) to the "check your e-mail" page so a reload can
      // never re-POST this request. Falls back to the inline message if no URL is set.
      if (data.status === 'confirmation_sent' && form.dataset.sentUrl) {
        window.location.assign(form.dataset.sentUrl);
        return;
      }
      // Server-detected invalid: mark exactly the fields it named (e.g. an address
      // the client regex let through but FILTER_VALIDATE_EMAIL rejected).
      if (data.status === 'invalid') {
        const keys = (Array.isArray(data.fields) ? data.fields : []).filter((k) => PORTO_FIELDS[k]);
        if (keys.length) { portoShowInvalid(form, keys, showMessage); return; }
      }
      const messages = {
        confirmation_sent: 'Bitte bestätige die Anfrage über den Link in deiner E-Mail.',
        duplicate: 'Du hast bereits einen Code angefordert.',
        out_of_stock: 'Aktuell sind keine Codes verfügbar.',
        captcha_failed: 'Bitte löse die Sicherheitsabfrage erneut.',
        invalid: 'Bitte fülle alle Felder korrekt aus.',
        rate_limited: 'Zu viele Anfragen. Bitte versuche es später erneut.',
        geo_blocked: 'Dieser Dienst ist auf Anfragen aus Deutschland beschränkt.',
      };
      showMessage(messages[data.status] || 'Es ist ein Fehler aufgetreten.', data.status !== 'confirmation_sent');
    } catch (err) {
      showMessage('Verbindungsfehler. Bitte versuche es später erneut.', true);
    } finally {
      form.removeAttribute('aria-busy');
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalLabel; }
    }
  });
});
