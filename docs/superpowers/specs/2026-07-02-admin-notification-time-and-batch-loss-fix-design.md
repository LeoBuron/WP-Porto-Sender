# Admin-Benachrichtigung: Abrufzeit + Fix für verlorene Sammel-Benachrichtigungen

**Datum:** 2026-07-02
**Status:** Genehmigt

## Zwei zusammenhängende Anliegen

### A) Feature: Abrufzeit + Standard Name/E-Mail an
Die Admin-Benachrichtigung („WP-Porto-Sender: Porto abgerufen") soll die **Abrufzeit**
je Anfragendem enthalten, und standardmäßig **Zeit, Name, E-Mail** zeigen.

### B) Bug: Sammel-Benachrichtigungen gehen verloren
`AdminNotifier::purgeStalePendingBatch()` (tägliche Wartung) **verwirft** einen
hängengebliebenen Sammel-Stapel (Anzahl + Claimant-PII), **ohne ihn zu senden**.
Auf einem Low-Traffic-Site läuft der tägliche Cron fast immer vor dem nächsten
Abruf → jede Anfrage nach der ersten im Fenster wird **stillschweigend verworfen**.
Symptom: „nur beim ersten Mal, dann nie wieder".

**Verifizierter Repro (echter WP-Store):** Anna (Leading Edge) → Mail; Bea (im
Fenster) → akkumuliert; Fenster läuft ab, Wartung läuft → Bea verworfen, nie
gemailt. Admin erfährt nur von Anna.

## Design (Sammelbetrieb bleibt, Bug wird gefixt)

### 1. Abrufzeit je Claimant
- `IssuanceService::confirm()` übergibt `'time' => $now->getTimestamp()` an `onIssued`.
- Der Zeitstempel wandert in jeden Claimant-Eintrag `{name, email, time}` und wird
  mit dem Stapel persistiert, damit eine Sammel-Mail je Claimant die Abrufzeit zeigt.
- Neuer Platzhalter `%time%` (erster Claimant); jede `%requests%`-Zeile bekommt ihre
  Zeit. Anzeige via `wp_date('d.m.Y H:i', $ts)` (Site-Zeitzone). `time` fehlt/0 →
  keine Zeit angezeigt (rückwärtskompatibel mit Altaufrufern).

### 2. Standard: `admin_notify_include_pii` → true
- Out-of-the-box enthält die Mail Zeit, Name, E-Mail. Bewusster Bruch mit dem
  DSGVO-first-Default (vom Nutzer bestätigt) → im Changelog vermerken.
- Checkbox-Label in den Einstellungen: „Name, E-Mail und Abrufzeit … mitsenden".

### 3. Bugfix: Wartung flusht statt verwirft
- `purgeStalePendingBatch()`: bei abgelaufenem Fenster und `pending > 0` wird der
  Stapel **gesendet** (Anzahl + akkumulierte Claimants), dann geleert — statt
  verworfen. Respektiert `adminNotifyEnabled` + Empfänger; PII wird auf die
  **aktuelle** Einstellung neu gegatet (PII jetzt aus → nur Zahlen). Retention
  bleibt gebunden (nach dem Senden geleert). Noch kühlend → unverändert warten.
- Damit die Flush-Mail vollständig ist (Produkt/Vorrat), speichert der Stapel
  einen kleinen **Kontext** `{product_label, remaining}` vom letzten akkumulierten
  Abruf. Neue Store-Methoden `pendingContext()/setPendingContext()`
  (Option `porto_notify_pending_context`).

### Betroffene Dateien
`Settings` (Default), `IssuanceService::confirm` (Zeit übergeben), `AdminNotifier`
(Zeit durchreichen, flush-statt-verwerfen, Kontext), `NotifyThrottleStore` +
`WpNotifyThrottleStore` (Zeit je Claimant + Kontext), `Mailer` (`%time%` +
Zeit je Claimant), `SettingsPage` (Label), plus `FakeNotifyStore` im Test.

## Tests (TDD)
- **Mailer:** `%time%` + Zeit je Claimant in Einzel- und Sammel-Mail; ohne Zeit
  unverändert (Altverhalten).
- **AdminNotifier:** Zeit landet im Claimant-Eintrag; `purgeStalePendingBatch`
  **sendet** den Stapel bei abgelaufenem Fenster (statt zu verwerfen) und leert
  danach; kühlend → kein Senden; Kontext wird gespeichert/genutzt.
- **Settings:** Default `admin_notify_include_pii === true`.
- **IssuanceService:** `confirm` reicht `time` an `onIssued`; bestehende Confirm-Tests
  bleiben grün.
- **Integration (MaintenanceTest):** ein hängengebliebener Stapel wird beim
  `run()` **gesendet** (Mail erfasst) und geleert.

## Bewusst nicht Teil des Umfangs
- Keine Umstellung auf „eine Mail pro Abruf" (Nutzer wählte Sammelbetrieb behalten).
- Keine neue Cadence-Checkbox; die bestehende PII-Checkbox (jetzt Default an) deckt
  „nur Anfragen / Name-Option" ab.
