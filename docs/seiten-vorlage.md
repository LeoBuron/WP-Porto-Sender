# Standardseiten des Porto-Senders — Vorlage & Anleitung

Der Porto-Sender zeigt Besuchern zwei Rückmelde-Seiten. Beide funktionieren ohne
weitere Einrichtung („Plugin-Standard"); dieses Dokument zeigt, **was** sie
anzeigen, **wo** man die Texte ändert und wie man stattdessen **eigene Seiten**
baut.

---

## 1. Die zwei Seiten im Überblick

| Seite | Wann sieht sie der Besucher? | URL (Plugin-Standard) |
|---|---|---|
| **„Bitte E-Mail bestätigen"** | Direkt nach dem Absenden des Formulars | `https://deine-seite.de/?porto_view=sent` |
| **Ergebnisseite** | Nach Klick auf den Bestätigungslink in der E-Mail | `https://deine-seite.de/?porto_view=result&porto_status=…` |

## 2. So sieht die Standardseite aus

Die Plugin-Standardseite wird **im aktiven Theme** gerendert (mit deinem Header,
Menü und Footer). Der Inhalt zwischen Header und Footer ist bewusst schlicht —
nur ein Hinweiskasten:

```html
<main class="porto-page">
    <div class="porto-notice" role="status">
        <p>… Hinweistext …</p>
    </div>
</main>
```

Es gibt also keine „versteckte" Seite mit mehr Inhalt: Die Standardseite **ist**
dieser eine Hinweistext im Theme-Rahmen.

## 3. Die Standardtexte (und wo man sie ändert)

**Alle folgenden Texte sind direkt in den Einstellungen änderbar:**
*Porto-Sender → Tab „Seiten"*. Die Felder sind mit den Standardtexten
vorbefüllt — einfach ändern und speichern. Ein geleertes Feld springt beim
Speichern auf den Standardtext zurück.

### Seite „Bitte E-Mail bestätigen"

> Wir haben deine Anfrage erhalten. Eine Mail sollte zeitnah zu dir geschickt werden. Klicke auf den Link in der Mail, um den Code abzurufen.

### Ergebnisseite — ein Text je Ausgang (`porto_status`)

| Status | Bedeutung | Standardtext |
|---|---|---|
| `issued` | Erfolg, Code verschickt | Du hast deine Mail-Adresse erfolgreich bestätigt. Eine Mail mit dem Porto-Code ist auf dem Weg zu dir. |
| `already_issued` | Link erneut geklickt | Du hast deinen Porto-Code bereits erhalten. |
| `expired` | Bestätigungslink abgelaufen | Dieser Bestätigungslink ist abgelaufen. Bitte stelle eine neue Anfrage. |
| `out_of_stock` | Kein Vorrat | Aktuell sind keine Codes verfügbar. Bitte versuche es später erneut. |
| `email_failed` | Versand fehlgeschlagen | Der Versand ist fehlgeschlagen. Bitte versuche es später erneut. |
| `invalid_token` | Link ungültig/unbekannt | Dieser Bestätigungslink ist ungültig. |

## 4. Eigene Seiten verwenden (optional)

Wenn dir der schlichte Hinweiskasten nicht reicht (z. B. mit Bild, weiterführenden
Links oder FAQ), kannst du **normale WordPress-Seiten** anlegen und im Tab
„Seiten" als Ersatz auswählen. Wichtig zu wissen:

1. Der Porto-Sender **blendet den passenden Hinweistext automatisch über dem
   Seiteninhalt ein** (derselbe Text wie in Abschnitt 3 — auch dort gepflegt).
   Du musst den Status also nicht selbst behandeln; deine Seite liefert den
   Rahmen drumherum.
2. Die Ergebnisseite ist für **alle** Status dieselbe Seite — schreibe den
   Inhalt so, dass er zu Erfolg *und* Fehlschlag passt (die konkrete Meldung
   liefert der eingeblendete Hinweis).
3. Wer die Seite normal (ohne Bestätigungslink) aufruft, sieht nur deinen
   Seiteninhalt ohne Hinweiskasten.

### Vorlage: Seite „Bitte E-Mail bestätigen"

Neue Seite anlegen (z. B. Titel „Fast geschafft!"), Inhalt nach diesem Muster,
dann unter *Porto-Sender → Seiten* als Seite „Bitte E-Mail bestätigen" wählen:

```markdown
## Fast geschafft!

Wir haben dir soeben eine E-Mail geschickt.

**So geht es weiter:**

1. Öffne dein E-Mail-Postfach.
2. Klicke auf den Bestätigungslink in unserer E-Mail.
3. Danach senden wir dir deinen Porto-Code zu.

*Keine E-Mail bekommen? Schau bitte auch im Spam-Ordner nach.
Der Link ist 48 Stunden gültig.*
```

### Vorlage: Ergebnisseite

Neue Seite anlegen (z. B. Titel „Deine Porto-Anfrage"), Inhalt nach diesem
Muster, dann als „Ergebnisseite" wählen. **Wichtig:** Das Plugin stellt den
Ergebnistext (Erfolg, bereits erhalten, abgelaufen, kein Vorrat, …) immer
**ganz oben über dem Seiteninhalt** dar – also noch vor deiner Überschrift.
Schreibe deinen Inhalt daher als Ergänzung darunter:

```markdown
<!-- Der Ergebnistext des Plugins erscheint automatisch oberhalb dieser Zeile. -->

## Deine Porto-Anfrage

**Gut zu wissen:**

- Den Code schreibst du oben rechts auf den Umschlag (Frankierfeld),
  mit `#PORTO` davor.
- Der Code ist bis zum Ende des dritten Jahres nach dem Kauf gültig.
- Hat etwas nicht geklappt, kannst du einfach eine neue Anfrage stellen.

[Zurück zur Startseite](/)
```

## 5. Gestaltung des Hinweiskastens

Der eingeblendete Hinweis hat immer die CSS-Klasse `porto-notice` — er lässt
sich per CSS (z. B. im Customizer unter „Zusätzliches CSS") frei gestalten:

```css
.porto-notice {
    padding: 1em 1.25em;
    border-left: 4px solid #0b5fff; /* Akzentfarbe */
    background: #f0f5ff;
    border-radius: 4px;
}
```
