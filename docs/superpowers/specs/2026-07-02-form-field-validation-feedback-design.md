# Feldspezifisches Validierungs-Feedback im Anfrageformular

**Datum:** 2026-07-02
**Status:** Genehmigt

## Problem

Gibt ein Besucher eine ungültige E-Mail ein (z. B. `foo@bar`, das die lockere
Browser-Prüfung `type="email"` passiert), antwortet der Server mit dem einen
opaken Status `invalid`, und das Formular zeigt die generische Meldung
*„Bitte fülle alle Felder korrekt aus."* – obwohl alle Felder ausgefüllt sind.
Der Nutzer erfährt nicht, **welches** Feld das Problem ist, und das Feld wird
nicht hervorgehoben.

Ursache: `IssuanceService::submit()` fasst drei verschiedene Fehler (leerer Name,
ungültige E-Mail, ungültiges/kein Produkt) in `['status' => 'invalid']` zusammen.

## Ziel

Pro Feld eine spezifische deutsche Meldung, das betroffene Feld sichtbar
markieren und fokussieren – für **alle** Felder, clientseitig gesteuert.

## Design (vollständige JS-Validierung)

### Formular (`RequestForm.php`)

- `<form>` erhält `novalidate`, damit der Browser die native (englische,
  unmarkierbare) Blockierung nicht mehr vorwegnimmt und JS die volle Kontrolle
  über einheitliche, markierte Meldungen hat.
- `required`/`type="email"` bleiben erhalten (Semantik, Mobil-Tastatur, `:required`).

### Client (`assets/porto-form.js`)

Zentrale `FIELD_DEFS` (Single Source für Meldung + zu markierendes Element +
Fokus-Ziel + Gültigkeitsprüfung), genutzt von **beiden** Pfaden:

| Feld     | Prüfung                                          | Meldung |
|----------|--------------------------------------------------|---------|
| name     | `value.trim() !== ''`                            | „Bitte gib deinen Namen ein." |
| email    | `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` auf `value.trim()` | „Bitte gib eine gültige E-Mail-Adresse ein (z. B. name@example.de)." |
| product  | `input[name=porto_product]:checked` vorhanden    | „Bitte wähle ein Produkt aus." |
| consent  | `porto_consent.checked`                          | „Bitte stimme der Datenverarbeitung zu." |

Ablauf im `submit`-Handler (vor der Captcha-Prüfung):
1. Alte Markierungen entfernen.
2. `FIELD_DEFS` in Reihenfolge prüfen; alle ungültigen Felder markieren
   (`aria-invalid="true"` + CSS-Klasse `.porto-invalid`), Meldung des **ersten**
   ungültigen Feldes anzeigen, dieses fokussieren, Absenden abbrechen.
3. Markierung eines Feldes wird beim nächsten `input`/`change` an diesem Feld
   automatisch entfernt (Event-Delegation).

Server-Antwort-Pfad: Bei `status === 'invalid'` mit `data.fields` dieselben
`FIELD_DEFS` nutzen (markieren + Meldung + Fokus). So ist auch ein rein
serverseitig erkannter Fall (E-Mail, die die JS-Regex passiert, aber
`FILTER_VALIDATE_EMAIL` ablehnt) feldgenau – die generische Meldung bleibt nur
letzter Fallback.

Meldungsausgabe zentralisiert in `showMessage(text, isError)`; Fehlermeldungen
erhalten `.porto-message--error`.

### Server (`IssuanceService::submit()`)

Statt eines pauschalen `invalid` die fehlerhaften Felder benennen:

```php
$fields = [];
if ($name === '') { $fields[] = 'name'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $fields[] = 'email'; }
if (!in_array($product, $enabled, true)) { $fields[] = 'product'; }
if ($fields !== []) { return ['status' => 'invalid', 'fields' => $fields]; }
```

Rückgabetyp: `array{status:string, fields?:array<int,string>}`. `RestController`
reicht das Array unverändert durch (HTTP 422 wie bisher). Bestehende Tests prüfen
nur `['status']` und bleiben grün.

### CSS (`assets/porto-form.css`)

- `.porto-invalid` – roter Rahmen/Outline (`#d63638`) für Inputs und das Produkt-`<fieldset>`.
- `.porto-message--error` – rote Variante der Statusmeldung.

## Tests

- **Server (Unit, TDD):** `submit()` liefert `fields` für ungültigen Name,
  ungültige E-Mail, ungültiges Produkt und Kombinationen.
- **Markup (Unit, TDD):** gerendertes Formular enthält `novalidate`.
- **JS/CSS:** kein Unit-Harness vorhanden → Verifikation in wp-env (leerer Name,
  `foo@bar`, kein Produkt, keine Einwilligung → jeweils richtige Meldung +
  Markierung; gültige Eingabe → Absenden funktioniert).

## Bewusst nicht Teil des Umfangs

- Keine Änderung an der eigentlichen Server-Validierungslogik (Regeln bleiben
  identisch, nur die Fehlerattribution kommt hinzu).
- Kein JS-Test-Harness einführen (nicht vorhanden; wp-env-Verifikation genügt).
