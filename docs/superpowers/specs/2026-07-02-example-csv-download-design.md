# Beispiel-CSV zum Download für den Porto-Code-Import

**Datum:** 2026-07-02
**Status:** Genehmigt

## Problem

Der CSV-Import auf der Admin-Seite *„Codes hinzufügen"* erwartet ein bestimmtes
Format (`product,code[,purchase_date]`), das aktuell nur als Fließtext-Hinweis
beschrieben ist. Admins haben keine Vorlage, an der sie sich orientieren können –
das führt zu Formatfehlern (unbekannte `product`-Schlüssel, falsches Datumsformat).

## Ziel

Auf derselben Seite einen Download **„Beispiel-CSV herunterladen"** anbieten, der
eine gültige, selbsterklärende Beispieldatei liefert.

## Design

### Ausgelieferte Datei (`porto-codes-beispiel.csv`)

```
product,code,purchase_date
standardbrief,BEISPIEL-CODE-0001,2026-07-02
grossbrief,BEISPIEL-CODE-0002,
```

- **Eine Zeile pro Katalog-Produkt**, erzeugt aus `ProductCatalog::all()` – kann so
  nie einen ungültigen `product`-Schlüssel enthalten.
- **Klar erkennbare Platzhalter-Codes** (`BEISPIEL-CODE-####`): falls die Datei
  versehentlich unverändert importiert wird, sind die Codes als Muster erkennbar
  und unschädlich.
- **`purchase_date` als optionale Spalte dokumentiert:** Zeile 1 trägt ein Datum,
  Zeile 2 lässt die Zelle leer. Der Importer behandelt eine leere `purchase_date`
  bereits als „heute" – die Datei demonstriert die Optionalität also selbst.
- Datumswert = heute, via `current_time('Y-m-d')`.

### Codeänderungen (alle in `src/Admin/CodeIntakePage.php`)

Folgt der bestehenden Aufteilung dieser Datei in *testbare Builder* + *dünne,
abgesicherte admin-post-Handler*.

1. **`public function exampleCsv(string $today): string`** – reiner Builder, nutzt
   `CsvWriter::toString(['product','code','purchase_date'], …)` (RFC-4180 +
   Formel-Injection-Schutz). Deterministisch bei gegebenem `$today`, unit-testbar.
2. **Handler `admin_post_porto_intake_csv_example`** – `current_user_can('manage_options')`
   + `check_admin_referer('porto_intake_csv_example')`, streamt dann die CSV
   (`nocache_headers()`, `Content-Type: text/csv; charset=utf-8`,
   `Content-Disposition: attachment; filename="porto-codes-beispiel.csv"`, echo,
   exit). Spiegelt `ToolsPage::stream()`.
3. **`render()`** – im Abschnitt *CSV-Import* einen Link **„Beispiel-CSV
   herunterladen"** (via `wp_nonce_url()`) plus eine Zeile Hinweis, dass
   `purchase_date` optional ist (leer = heute).

### Test

Unit-Test für `exampleCsv()`: prüft Header, eine Zeile je Produkt, gültige
`product`-Schlüssel, gefüllte Datumszelle in Zeile 1 und leere in Zeile 2.

## Bewusst nicht Teil des Umfangs

- Keine statisch ausgelieferte `.csv`-Datei (würde bei Katalog-Änderungen
  stillschweigend veralten).
- Keine Änderungen an `CodesCsvImporter`/`CsvReader` – sie decken bereits jeden
  hier benötigten Fall ab (leere `purchase_date`, Spaltenreihenfolge, BOM).
