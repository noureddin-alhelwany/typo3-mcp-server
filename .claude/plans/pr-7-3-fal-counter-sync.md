# PR 7.3 — Sync FAL-Counter auf Parent-Feldern nach MCP-Create/Update

## Context

In TYPO3 speichern Felder wie `tt_content.image`, `tt_content.assets`, `pages.media` die **Anzahl** der verknüpften `sys_file_reference`-Rows als Integer in der Parent-Row. Dieser Wert ist kein echtes Fremdschlüssel-Feature, sondern ein von DataHandler gepflegter Cache, der beim normalen BE-Save aus `RelationHandler::countItems(false)` kommt (siehe `DataHandler::checkValue_inline_processDBdata`).

Der MCP-Create/Update-Flow extrahiert FAL-Inline-Felder aus dem Datamap (`extractInlineRelations()`, [WriteTableTool.php:1291](Classes/MCP/Tool/Record/WriteTableTool.php#L1291), `unset($data[$fieldName])` auf Zeile 1311/1317), weil Children separat verarbeitet werden müssen (Workspace-Timing: Parent erst anlegen → Live-UID resolven → Children anlegen → direkter UPDATE auf `uid_foreign`). Die Konsequenz: DataHandler sieht das Inline-Feld nie, und der Counter bleibt auf 0, obwohl Referenzen in `sys_file_reference` existieren.

**Sichtbarer Fehler:** In der User-Produktivumgebung rendern Fluid-Templates, die auf `<f:if condition="{image}">` o.ä. prüfen, den Bild-Block nicht. Direkt-DB-Vergleich (aus der Bug-Meldung): CE 712 (BE-save) hat `assets=1`, CE 720 (MCP-create) hat `assets=0` bei identischem Referenz-Bestand in `sys_file_reference`.

**Nicht-sichtbar in existierenden Tests:** `WorkspacePreviewRenderTest::testTextmediaCEWithFalShortcutRendersInPreview` läuft grün — das minimale TypoScript im Test-Setup ist nicht counter-abhängig. Die Produktions-Themes sind es. Ein Render-Test deckt das Problem also nicht ab; ein **DB-Invariance-Test** auf den Counter-Wert ist die richtige Gegenprobe.

**Out of scope:** Rendering-Fixes auf FE-Seite (ist TYPO3-Core / Theme-Angelegenheit). Nur der Counter auf der Parent-Row wird synchronisiert.

---

## Ansatz

**Ansatz 2 aus der Bug-Spec: direkter Post-Processing-UPDATE** nach dem bestehenden Inline-Flow.

**Warum nicht Ansatz 1 (natives DataHandler-Inline-Datamap):** Der bestehende Two-Step-Flow existiert, weil Workspace-Parent + Live-UID-Resolution nicht sauber in einem DataHandler-Call abbildbar sind. Ein generalisierter Umbau auf Inline-NEW-IDs im Parent-Datamap würde die bewährte Workspace-Transparenz-Mechanik (die in PR-2 kalibriert wurde) wieder in Frage stellen — hohes Risiko, wenig Gewinn. Ansatz 2 ist exakt analog zur bereits vorhandenen direkten `uid_foreign`-UPDATE-Logik ([WriteTableTool.php:777–788](Classes/MCP/Tool/Record/WriteTableTool.php#L777-L788) / [WriteTableTool.php:949–955](Classes/MCP/Tool/Record/WriteTableTool.php#L949-L955)) und fügt sich ohne Architektur-Umbau ein.

### Algorithmus

Neue `protected function syncParentInlineCounter()`, aufgerufen **nach** dem Child-DataHandler und dem `uid_foreign`-UPDATE in Create **und** Update.

Pro verarbeitetem Inline-Feld, das sich auf eine **hideTable**-Foreign-Table bezieht (das ist die einzige Kategorie, die in unserem Flow die Counter-Lücke hat; non-hidden-Kinder laufen ohnehin nicht durch unseren Zwei-Schritt-Pfad):

```
count := SELECT COUNT(*) FROM <foreignTable>
         WHERE <foreignField> = <liveParentUid>
           AND tablenames = <parentTable>
           AND fieldname  = <fieldName>
           AND deleted    = 0
           AND (<ws-clause>)

UPDATE <parentTable>
   SET <fieldName> = <count>
 WHERE uid = <parentUidStored>
```

Wobei:
- `<liveParentUid>` wie im bestehenden Code der für `uid_foreign` verwendete Wert ist (create: via `getLiveUid()`, fallback auf parent uid; update: der übergebene Live-UID).
- `<parentUidStored>` die UID der Row, die DataHandler gerade beschrieben hat — im Create die Return-UID von `substNEWwithIDs`, im Update der `$workspaceUid` von `ensureWorkspaceVersion()`.
- `<ws-clause>`: `t3ver_wsid IN (0, <currentWorkspace>)`. Das ist wichtig, weil `deleted=0` allein z.B. einen in Workspace-delete-placeholderten Record zählt, den der normale BE aber nicht mehr als sichtbar wertet. Konservative Semantik matcht DataHandler's `RelationHandler`-Zählweise (die berücksichtigt Workspace-Kontext). Aus Pragmatismus beginnen wir mit `deleted=0` und `t3ver_state != 2` (2 = delete placeholder). Wenn das real nicht reicht, erweitern wir auf Basis konkreter Fixtures.
- `tablenames`/`fieldname` sind nur für `sys_file_reference` relevant. Für andere hideTable-Foreign-Tables wie `tx_news_domain_model_link` existieren diese Felder nicht. Daher: beide Filter nur anhängen, wenn sie im `foreign_match_fields` des Inline-Configs stehen (der bereits in `extractInlineRelations` / `buildFileFieldInlineConfig` aufgebaut wird).

### Gate-Punkt im Code

Zwei Stellen:

1. **[Classes/MCP/Tool/Record/WriteTableTool.php:791](Classes/MCP/Tool/Record/WriteTableTool.php#L791)** — am Ende der Inline-Verarbeitung im Create, direkt nach dem `uid_foreign`-UPDATE-Block, aber **außerhalb** des inneren `if ($isHiddenTable)` und `if (!empty($childUids))`. Sync läuft auch dann, wenn der Input `'image' => []` war (resultiert in `image = 0`, matcht die DB-Realität).

2. **[Classes/MCP/Tool/Record/WriteTableTool.php:958](Classes/MCP/Tool/Record/WriteTableTool.php#L958)** — analog im Update, nach dem `uid_foreign`-Block.

Weil das Update-Pfad einen leeren `$childDataMap` hat, wenn der User alle Referenzen löscht (z.B. `'image' => []`), muss der Sync-Aufruf **außerhalb** von `if (!empty($childDataMap))` stehen — er läuft immer dann, wenn ein Inline-Feld im Input war. Kleinanpassung: der Sync-Loop geht direkt über `$inlineRelations` (das ist die Menge der verarbeiteten Felder), nicht über den Child-DataMap.

### Beispiel-Code-Shape (nicht ausgeführt, nur zur Kalibrierung)

```php
// Nach dem uid_foreign-UPDATE-Block, noch im "if (!empty($inlineRelations))":
foreach ($inlineRelations as $fieldName => $relationData) {
    $config = $relationData['config'];
    $foreignTable = $config['foreign_table'] ?? '';
    $foreignField = $config['foreign_field'] ?? '';
    $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
    $isHiddenTable = ($foreignTableTCA['ctrl']['hideTable'] ?? false) === true;
    if (!$isHiddenTable || $foreignTable === '' || $foreignField === '') {
        continue;
    }
    $this->syncParentInlineCounter(
        $table,                   // parent table
        $parentUid,               // row to UPDATE (WS record in workspace flow)
        $liveParentUid,           // value written into uid_foreign of children
        $fieldName,               // parent column carrying the counter
        $foreignTable,
        $foreignField,
        $config['foreign_match_fields'] ?? []
    );
}
```

---

## Betroffene Dateien

### Produktivcode (1 Datei)

**[Classes/MCP/Tool/Record/WriteTableTool.php](Classes/MCP/Tool/Record/WriteTableTool.php)**

- Im `createRecord()`: Neuer Sync-Loop nach Zeile 791 (nach dem `uid_foreign`-Block), innerhalb von `if (!empty($inlineRelations))` aber außerhalb `if (!empty($childDataMap))`.
- Im `updateRecord()`: Analoger Sync-Loop nach Zeile 958.
- Neue `protected function syncParentInlineCounter(...)` als Helper, untergebracht im Bereich der bestehenden Inline-Helpers (um Zeile 1770, bei `handleExistingEmbeddedRelations`).

Kein Delete-Pfad-Fix: der top-level Delete kaskadiert via DataHandler über Parent → Children; der Parent wird gelöscht, der Counter ist irrelevant.

### Tests (1 bestehende Datei erweitert)

**[Tests/Functional/MCP/Tool/FalWriteTest.php](Tests/Functional/MCP/Tool/FalWriteTest.php)** — neue Tests:

1. `testParentImageCounterIsSetOnCreateWithOneReference` — `image: [{file:1}]` → `tt_content.image === 1`.
2. `testParentImageCounterReflectsMultipleReferences` — `image: [{file:1},{file:2}]` → `tt_content.image === 2`.
3. `testParentAssetsCounterIsSetOnCreate` — analog für `assets` / `CType=textmedia` (gleicher Mechanismus, andere Spalte).
4. `testParentImageCounterDecrementsOnUpdateRemovingReferences` — create mit 2, update auf 1 (mit expliziter `uid` in der Referenz), assert counter === 1.
5. `testParentImageCounterZerosOnUpdateClearingAllReferences` — create mit 1, update mit `image: []`, assert counter === 0.

Die Assertions queryen direkt die Workspace-Row von `tt_content` (wie in den existierenden Tests; der `parentUid` aus der MCP-Antwort ist bereits die richtige UID zum SELECT).

### Regression-Guard

Keine Erweiterung von `WorkspacePreviewRenderTest`: Die FE-Render-Kette ist minimal und counter-unabhängig im Test-Setup — ein Render-Assertion würde das Problem nicht reproduzieren. Der DB-Counter-Wert ist die **echte** Invariante und wird durch die neuen Tests in `FalWriteTest` gepinnt.

### Doku (2 Dateien)

- **[Documentation/Architecture/FAL.md](Documentation/Architecture/FAL.md)**: Neuer Absatz "Parent counter fields" unter dem Linking-Shortcut-Abschnitt. Erklärt: (a) was der Counter ist, (b) dass DataHandler ihn im MCP-Flow nicht nativ pflegt (weil das Feld vor dem DataHandler-Call extrahiert wird), (c) dass MCP ihn post-hoc synchronisiert.
- **[Documentation/Architecture/WriteTableSemantics.md](Documentation/Architecture/WriteTableSemantics.md)**: Kurzer Cross-Ref im `colPos`/`PageTSconfig`-Block-Umfeld: "FAL parent counters are synced post-write — see FAL.md."

### CLAUDE.md

Ein Stichpunkt: "WriteTable synchronisiert nach jedem Create/Update mit FAL-Shortcut den Parent-Counter (`tt_content.image`, `pages.media`, …) auf `COUNT(sys_file_reference WHERE uid_foreign=...)`. Grund: das Inline-Feld wird vor dem DataHandler-Call extrahiert, DataHandler's native `countItems()`-Pflege greift also nicht. Siehe `Documentation/Architecture/FAL.md`."

---

## Wiederverwendete Bausteine

- **`$inlineRelations`-Datenstruktur** aus `extractInlineRelations()` — enthält pro Feld bereits das fertige `config`-Array (inkl. `foreign_match_fields`, `foreign_field`, `foreign_table`). Keine doppelte TCA-Resolution nötig.
- **Muster des direkten `uid_foreign`-UPDATEs** ([WriteTableTool.php:779–788](Classes/MCP/Tool/Record/WriteTableTool.php#L779-L788)) — wir verwenden denselben `ConnectionPool`/`Connection::update`-Weg.
- **Workspace-Transparenz**: das `uid_foreign` wird bereits auf `$liveParentUid` gesetzt, also zählen wir Children per `uid_foreign = $liveParentUid`. Die Parent-Row, auf die der Counter geschrieben wird, ist `$parentUid` (die Row, die DataHandler gerade bearbeitet hat, d.h. die WS-Version).

---

## Verification

```bash
# 1. Code-Änderung
cd /Users/n.alhelwany/workspace/typo3-mcp-server
# ... edits ...

# 2. Sync ins Test-Projekt
rsync -a /Users/n.alhelwany/workspace/typo3-mcp-server/Classes/ \
         /Users/n.alhelwany/workspace/default-project/vendor/hn/typo3-mcp-server/Classes/
rsync -a /Users/n.alhelwany/workspace/typo3-mcp-server/Tests/ \
         /Users/n.alhelwany/workspace/default-project/vendor/hn/typo3-mcp-server/Tests/

# 3. Neue Counter-Tests isoliert
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist \
  --filter FalWriteTest --testdox"

# 4. Regressions-Smoke: gesamte Write/Preview-Suite
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist \
  --filter 'FalWrite|FalRead|WriteTable|WorkspacePreviewRender'"
```

### Manuelle End-to-End-Gegenprobe

1. `create tt_content { CType: 'image', image: [{file: 1}] }` → `U`
2. SQL: `SELECT image FROM tt_content WHERE uid = U` → erwartet `1`
3. `create tt_content { CType: 'image', image: [{file:1},{file:2}] }` → `V`
4. SQL: `SELECT image FROM tt_content WHERE uid = V` → erwartet `2`
5. In der realen Produktivumgebung mit counter-abhängigem Theme: `GeneratePreviewLink` → Bilder rendern sofort, ohne BE-Save.

### Akzeptanzkriterien

- Alle neuen Tests in `FalWriteTest` grün.
- Keine bestehenden FAL-, Write-, oder Preview-Tests werden rot.
- DB-Invariante: nach jedem erfolgreichen MCP-Create/Update mit FAL-Shortcut stimmt der Counter auf der Parent-Row exakt mit `COUNT(*) FROM sys_file_reference` (mit passenden match-fields) überein.
- Der Fix ist generisch über hideTable-Inline-Felder — nicht FAL-spezifisch verdrahtet. `pages.media`, `tx_news_domain_model_news.*`-Felder profitieren automatisch.
