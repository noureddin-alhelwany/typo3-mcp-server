# FAL-Support für TYPO3 MCP Server — 3-PR Implementierungsdoku

## Context

Der MCP-Server soll AI-Clients erlauben, TYPO3-Content sicher zu bearbeiten. Bisher ist FAL (File Abstraction Layer) komplett außen vor: `sys_file` ist read-blocked, `sys_file_reference` gesperrt, Uploads nicht möglich. Das blockiert jeden realen Content-Workflow, in dem Bilder vorkommen (news mit Teaser-Bild, Hero-Sections, Download-Dokumente, etc.).

TYPO3-Realität: `sys_file` ist nicht workspace-capable, Uploads müssen zwangsläufig live passieren. `sys_file_reference` dagegen **ist** workspace-capable — das Content-Linking bleibt also im Workspace-Safety-Netz. Diese Ausnahme ist dokumentiert in [Documentation/Architecture/FAL.md](../../workspace/typo3-mcp-server/Documentation/Architecture/FAL.md) und als CLAUDE.md-Regel verankert.

Der Rollout läuft in **3 PRs**:

| PR | Ziel | Status |
|---|---|---|
| PR 0 (Doku) | Architektur-Ausnahme verankern | ✅ gemergt (Commit `b5edc29`) |
| PR 1 | `sys_file` / `sys_file_reference` lesbar + FAL-Expansion auf Reads | ✅ gepusht (Branch `claude/typo3-14-compatibility-RvFn7`, Commits bis `119d6f3`) |
| PR 2 | `sys_file_reference` schreibbar via FAL-Shortcut | 🔲 offen |
| PR 3 | `UploadFileTool` | 🔲 offen |

Dieses Dokument hält die **Session-übergreifende Doku** für PR 2 und PR 3 vor, damit eine frische Session sie ausführen kann, ohne den vollen Kontext neu zu rekonstruieren.

---

## Dev-Workflow (gilt für alle PRs)

### Arbeitskopie

- Extension-Quelle: `/Users/n.alhelwany/workspace/typo3-mcp-server`
- Branch: `claude/typo3-14-compatibility-RvFn7` (von `main` abgezweigt)
- Remote: `https://github.com/noureddin-alhelwany/typo3-mcp-server.git`

### Test-Setup

Das Extension-Verzeichnis selbst hat **kein `vendor/`** und **kein lokales PHP**. Tests laufen in einem separaten TYPO3-14-Projekt mit DDEV:

- Test-Projekt: `/Users/n.alhelwany/workspace/default-project`
- DDEV mit PHP 8.2 / MySQL 8.0
- Das Projekt hat die Extension als VCS-Dependency: `"hn/typo3-mcp-server": "dev-claude/typo3-14-compatibility-RvFn7"`

### Workflow bei jeder Änderung

```bash
# 1. Code-Änderung in /Users/n.alhelwany/workspace/typo3-mcp-server

# 2. Commit + Push
git add -A
git commit -m "..."
git push

# 3. Extension im Test-Projekt aktualisieren
cd /Users/n.alhelwany/workspace/default-project
ddev composer update hn/typo3-mcp-server

# 4. Dev-Deps der Extension installieren (einmalig oder nach Reset des vendor/-Inhalts)
ddev composer --working-dir=vendor/hn/typo3-mcp-server install --ignore-platform-req=php
# --ignore-platform-req=php nötig weil paratest v7.9+ PHP 8.3 will; phpunit selbst geht auf 8.2
# (paratest-Version ist in composer.json bereits auf ^7.8 geöffnet, das passt — trotzdem kann
#  ein frischer Install ohne lock-file eine neuere Version ziehen wollen)

# 5. Tests laufen lassen (direkt phpunit, NICHT paratest — paratest v7.8 braucht 8.3+)
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter DeinTest --testdox"
```

### Schneller Iterations-Loop

Statt nach jeder Änderung committen+pushen+`composer update`, kann man die Dateien direkt ins installierte vendor-Verzeichnis syncen:

```bash
rsync -a /Users/n.alhelwany/workspace/typo3-mcp-server/Classes/ \
         /Users/n.alhelwany/workspace/default-project/vendor/hn/typo3-mcp-server/Classes/
rsync -a /Users/n.alhelwany/workspace/typo3-mcp-server/Tests/ \
         /Users/n.alhelwany/workspace/default-project/vendor/hn/typo3-mcp-server/Tests/

cd /Users/n.alhelwany/workspace/default-project
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter DeinTest --testdox"
```

Am Ende der PR-Iteration dann committen + pushen, damit der Branch auf GitHub synchron zum lokalen Stand ist.

### Wichtig: Test-Interferenz auf PHP 8.2

Die Extension-Testsuite ist für parallele Ausführung via **paratest** konzipiert (jeder Test in eigenem Prozess → keine statische State-Verunreinigung). paratest v7.9+ braucht PHP 8.3+, DDEV läuft mit 8.2, also muss phpunit direkt verwendet werden — sequentiell.

**Konsequenz**: Manche Tests fallen in der vollen sequenziellen Suite durch, laufen aber in Isolation grün. Das ist **Test-Framework-Flakiness, keine echte Regression**. Vor dem Melden einer „Regression" immer den Test isoliert laufen lassen:

```bash
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter TestName" 2>&1 | grep -E 'OK|FAIL|Tests: '
```

Nur was isoliert rot ist, zählt.

---

## PR 0 — Architektur-Ausnahme (abgeschlossen)

Commit `b5edc29` (auf `claude/typo3-14-compatibility-RvFn7`, gemergt).

**Änderungen (reine Doku)**:
- `CLAUDE.md`: Regel um FAL-Ausnahme erweitert.
- `TECHNICAL_OVERVIEW.md`: Core Principle 7 um „FAL boundary" ergänzt, Relation-Handling + „Not Yet Implemented" mit Pointer auf FAL.md.
- `Documentation/Architecture/FAL.md`: neu, reines Policy-Dokument.
- `Documentation/Architecture/InlineRelations.md`: Restricted-Tables-Abschnitt fachlich korrigiert (`sys_file_reference` IST workspace-capable).
- `README.md`: Fileadmin-Status-Zeile mit FAL.md-Pointer.

Basis für PR 1–3. Keine Aktion mehr nötig.

---

## PR 1 — Read + FAL-Serializer (abgeschlossen)

### Geliefert

**Commits auf `claude/typo3-14-compatibility-RvFn7`** (nach PR 0):

1. `68edd04` — `feat(fal): read sys_file and sys_file_reference, expand file inside references`
2. `0e9f0e1` — `chore: widen paratest constraint to ^7.8 so PHP 8.2 can run the suite`
3. `5ecf3a6` — `fix(fal): allow sys_file and sys_file_reference as safe root-level tables`
4. `119d6f3` — `fix(fal): handle TYPO3 13/14 type=file, gate writes explicitly, align tests`

### Verhaltens-Änderungen

- `ReadTable` akzeptiert jetzt `sys_file` und `sys_file_reference` als Tabellen.
- `GetTableSchema` zeigt `sys_file_reference`-Felder.
- Bei einem Record mit FAL-Inline-Feld (z.B. `tt_content.image`) liefert `ReadTable` die Referenzen mit eingebettetem `sys_file`-Record:
  ```json
  "image": [
    {
      "uid": 500,
      "uid_local": 1,
      "tablenames": "tt_content",
      "fieldname": "image",
      "alternative": "Hero alt",
      "file": {"uid": 1, "identifier": "/test.jpg", "mime_type": "image/jpeg", ...}
    }
  ]
  ```
- Workspace-Transparenz bleibt intakt: Client sieht Live-UIDs (`t3ver_oid → uid`-Swap).
- Writes auf `sys_file_reference` sind **weiterhin blockiert** (read-only Flag) — das öffnet PR 2.
- Writes auf `type=file`-Felder werden explizit in `WriteTableTool` zurückgewiesen („File fields are not supported").

### Geänderte Dateien (Zeilen-Pointer auf den PR-1-Endstand)

- [`Classes/Service/TableAccessService.php`](../../workspace/typo3-mcp-server/Classes/Service/TableAccessService.php):
  - `validateTableAccess()`: read-Operation verwendet `requireWorkspaceCapability=false` → Reads auf nicht-workspace-capable Tabellen wie `sys_file` klappen.
  - `$restrictedTables`: `sys_file_reference` entfernt.
  - `$readOnlyTables`: `sys_file_reference` hinzugefügt (Reads ja, Writes nein — PR-2-Grenze).
  - Allowlist der Root-Level-Tabellen: `sys_file` und `sys_file_reference` freigeschaltet (sonst hätte der `rootLevel`-Check beide schon vor dem Operation-Check blockiert).
  - `canAccessField()`: Dispatch für `type=file` wie `type=inline` (Foreign-Table-Check), Default-Foreign-Table `sys_file_reference`.
- [`Classes/MCP/Tool/Record/ReadTableTool.php`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/Record/ReadTableTool.php):
  - Schema-Enum nutzt jetzt `getReadableTables()` (breiter als `getAccessibleTables`).
  - `includeRelations()`: Dispatch für `type=file` über neuen Helper `fileFieldToInlineConfig()` → übersetzt TYPO3 13/14 `type=file` in einen Inline-Config-Shape, damit die bestehende `includeInlineRelations()`-Pipeline ihn abarbeiten kann.
  - Neuer Helper `loadSysFileRecord()`: Lädt `sys_file`-Record (nur `DeletedRestriction`, keine `WorkspaceRestriction`) und schleust ihn durch `processRecord()`.
  - In `includeInlineRelations()`: Spezialfall für `foreign_table === 'sys_file_reference'` → jede Referenz bekommt `file: {...}` via `loadSysFileRecord()`.
- [`Classes/MCP/Tool/Record/WriteTableTool.php`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/Record/WriteTableTool.php):
  - Validierungsschleife lehnt `type=file`-Felder explizit ab, **bevor** `canAccessField()` aufgerufen wird (weil `canAccessField` solche Felder jetzt durchlässt).
- [`Classes/MCP/Tool/Record/GetTableSchemaTool.php`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/Record/GetTableSchemaTool.php):
  - Schema-Enum: `getReadableTables()` statt `getAccessibleTables(true)`.
- [`Classes/Utility/TcaFormattingUtility.php`](../../workspace/typo3-mcp-server/Classes/Utility/TcaFormattingUtility.php):
  - `type=file`-Case hinzugefügt: `[foreign table: sys_file_reference]` + optional `[allowed: ...]`.
- [`Tests/Functional/AbstractFunctionalTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/AbstractFunctionalTest.php):
  - `createAndSwitchToWorkspace()` schreibt `unpublish_time` / `swap_modes` nur für TYPO3 <14 (in 14 entfernt).
- [`composer.json`](../../workspace/typo3-mcp-server/composer.json):
  - `brianium/paratest: ^7.8` (vorher `^7.11` — hätte PHP 8.2 verboten).
- [`Tests/Functional/Fixtures/sys_file.csv`](../../workspace/typo3-mcp-server/Tests/Functional/Fixtures/sys_file.csv): erweitert (tstamp, mime_type, extension, sha1 + zweite Datei).
- [`Tests/Functional/Fixtures/sys_file_reference.csv`](../../workspace/typo3-mcp-server/Tests/Functional/Fixtures/sys_file_reference.csv): **neu**, 3 Referenzen (uid 500, 501, 502).
- [`Tests/Functional/Fixtures/fal_content.csv`](../../workspace/typo3-mcp-server/Tests/Functional/Fixtures/fal_content.csv): **neu**, `tt_content` Records 120 + 121 mit CType=image (absichtlich separat von `tt_content.csv`, damit bestehende Tests nicht mit-fixtured werden).
- [`Tests/Functional/MCP/Tool/FalReadTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/MCP/Tool/FalReadTest.php): **neu**, 10 Tests (Grund-Reads, FAL-Expansion, Workspace-Reads, neue Workspace-Referenzen).
- [`Tests/Functional/Service/TableAccessServiceFieldAccessTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/Service/TableAccessServiceFieldAccessTest.php): umgeschrieben (alte Tests kodierten „file fields blocked" — passt nicht mehr zur neuen Policy).
- [`Tests/Functional/NewsExtension/NewsSchemaTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/NewsExtension/NewsSchemaTest.php): `testNewsUsesSysFileReferenceForMedia` aktualisiert (keine „restricted"-Meldung mehr).
- `Documentation/Architecture/FAL.md` + `Documentation/Architecture/InlineRelations.md`: Read-Shape und aktuelle Restriktions-Story ergänzt.

### Wichtige Ist-Befunde aus PR 1 (für PR 2/3 entscheidend)

1. **TYPO3 13/14 nutzt `type=file`, nicht `type=inline`** für FAL-Felder. `tt_content.image`, `tt_content.assets`, `pages.media` sind alle `type=file` mit impliziter Verdrahtung nach `sys_file_reference`. Beim Schreiben muss MCP die implizite Verdrahtung (`foreign_field=uid_foreign`, `foreign_sortby=sorting_foreign`, `foreign_match_fields={fieldname, tablenames}`) selbst aus dem Feldnamen + Parent-Tabelle ableiten.

2. **TableAccessService hat mehrere Zugangs-Schichten**, die alle passieren müssen:
   - `isRestrictedSystemTable()` — Root-Level-Allowlist, hard-coded Blacklist.
   - Workspace-Capability — nur für Writes.
   - User-Permissions.
   - `isTableReadOnly()` — gate für Write-Permissions.
   - `canAccessField()` — per-Feld, mit Typ-Branches (`inline` vs. `file`).

3. **WorkspaceRestriction-Semantik (TYPO3 Core)**: Filtert Records mit `t3ver_oid > 0` standardmäßig raus (außer MOVE_POINTER). Modifizierte Workspace-Versionen (`t3ver_state=0, t3ver_oid>0`) erscheinen **nicht** in Queries, die nur die Restriction anwenden — TYPO3 erwartet, dass `workspaceOL()` im Post-Processing den Overlay macht. ReadTableTool macht das **nicht**, nur Delete-Placeholder-Filterung via Custom-Restriction. Konsequenz: für Modifikationen an Bestandsreferenzen im Workspace braucht man entweder:
   - `WriteTableTool`-Flow (legt Workspace-Version über DataHandler an + direkter UPDATE des `foreign_field` mit `live_uid`), **so funktioniert das heute für andere Inline-Relations** — ist der Standard-Pfad für PR 2.
   - Neue Workspace-Records (`t3ver_oid=0, t3ver_state=1`) erscheinen immer — die sind der einfache Fall.

4. **Two-Step-DataHandler-Flow** ([`WriteTableTool::processEmbeddedInlineRelations()`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/Record/WriteTableTool.php)) funktioniert bereits korrekt für hidden-Tabellen wie `sys_file_reference`:
   - Parent-Record via DataHandler anlegen.
   - Child-Records via DataHandler, **ohne** `foreign_field` im Datamap.
   - Nach `substNEWwithIDs` direkten DB-UPDATE des `foreign_field` mit Live-Parent-UID.

5. **Fixture-Isolation ist zwingend**: `tt_content.csv` ist von vielen Tests geteilt (feste Row-Counts in Assertions). Neue FAL-spezifische Records kommen in `fal_content.csv` und werden nur von `FalReadTest` (und künftig `FalWriteTest`) geladen.

### Lessons learned (Sollten die PR-2/3-Session leiten)

Aus meiner eigenen Retro auf PR 1:

1. **TCA vor Design prüfen.** `tt_content.image` ist in TYPO3 13/14 nicht mehr `type=inline`, sondern `type=file`. Ich habe das erst nach dem ersten roten Test-Lauf gemerkt. Bevor irgendein Code/Plan steht, einmal in der Ziel-TYPO3-Version die TCA für alle betroffenen Felder dumpen (siehe Verification-Snippet unten).
2. **Alle Zugangs-Schichten im `TableAccessService` auf einen Rutsch durchgehen.** Ich habe iterativ `restrictedTables` → `rootLevel` → `canAccessField` gefixt — hätten alle im ersten Durchlauf erkannt werden können, wenn ich die ganze Method-Kaskade vorab gelesen hätte.
3. **Nicht in geteilten Fixtures bauen.** Neue FAL-Test-Records von Anfang an in eine eigene Fixture-Datei, die nur der neue Test lädt.
4. **Weniger, schärfere Tests.** PR 1 hat 10 Tests — 3–4 hätten die Akzeptanzkriterien abgedeckt. Bei PR 2/3 nicht padden.
5. **Operation-aware Access-Checks.** `canAccessField()` war ursprünglich binär — Read und Write wurden gleich gefiltert. Bei PR 2 könnte derselbe Tie-Break wieder auftauchen (z.B. sys_file: readable aber niemals schreibbar). Vorab überlegen, wo das Schichten sinnvoll ist, statt beim ersten Testfehler hinterher zu patchen.

### TCA-Verification-Snippet

Vor dem Start von PR 2 einmal die TCA-Shape für alle Felder prüfen, die angefasst werden:

```php
// In einem neuen, wegwerfbaren Test (siehe FalDebugTcaTest.php in PR-1-History als Vorlage):
$cols = $GLOBALS['TCA']['tt_content']['columns'] ?? [];
foreach (['image', 'assets'] as $name) {
    $cfg = $cols[$name]['config'] ?? null;
    var_dump([
        'name' => $name,
        'type' => $cfg['type'] ?? null,
        'foreign_table' => $cfg['foreign_table'] ?? null,
        'foreign_field' => $cfg['foreign_field'] ?? null,
        'foreign_match_fields' => $cfg['foreign_match_fields'] ?? null,
        'allowed' => $cfg['allowed'] ?? null,
    ]);
}
```

---

## PR 2 — Linking (Write `sys_file_reference`)

### Ziel

`sys_file_reference` wird schreibbar, sodass der Client FAL-Inline-Felder auf Parent-Records mit einer ergonomischen Kurzform befüllen kann. Referenzen laufen durch den Workspace — das ist die Normalregel, nicht die FAL-Ausnahme. Vorhandene Referenzen sind aktualisier-/austausch-/löschbar. `sys_file` wird **nicht** angefasst (PR 3).

### Input-Shape

Client ruft `WriteTable` auf einem Parent-Record (z.B. `tt_content`) auf und befüllt das FAL-Inline-Feld mit einer Liste von Referenzen, jede referenziert eine **existierende** `sys_file`-UID:

```json
{
  "table": "tt_content",
  "action": "create",
  "pid": 1,
  "data": {
    "CType": "image",
    "header": "Hero",
    "image": [
      {
        "file": 42,
        "alternative": "Hero image",
        "title": "Main hero",
        "crop": "{...}",
        "link": "t3://page?uid=5"
      }
    ]
  }
}
```

MCP transformiert das intern in `sys_file_reference`-Child-Records mit:

- `uid_local` ← `file`
- `tablenames` + `fieldname` aus dem `foreign_match_fields`-Block der Parent-TCA (bzw. default bei `type=file`: `tablenames = <parent table>`, `fieldname = <field name>`).
- `pid` = Parent-PID.
- Metadaten (`alternative`, `title`, `crop`, `link`, `description`, `autoplay`) werden direkt durchgereicht.
- `uid_foreign` wird nach DataHandler über den bestehenden Direkt-UPDATE-Mechanismus auf die Live-Parent-UID gesetzt.

### Akzeptanzkriterien

1. `tt_content` mit `CType=image` + `image: [{file: 1, ...}]` → erfolgreich angelegt, Referenz landet im aktiven Workspace (nicht live).
2. Update: Array `image: [{file: 2, ...}, {file: 1, ...}]` auf bestehendem Content-Element → Reihenfolge umgestellt, keine Duplikate.
3. Update: nur `alternative` einer bestehenden Referenz ändern → andere Felder unverändert.
4. Update: komplettes Austauschen einer Referenz (neues `file`) → alte Referenz gelöscht oder modifiziert (je nach `handleExistingEmbeddedRelations()`-Default — Verhalten dokumentieren).
5. Delete des Parent-Content-Elements → sys_file_reference cascade via DataHandler-Default.
6. Workspace-Isolation: Referenz nur im aktuellen Workspace sichtbar, nicht live.
7. Nicht-existierende `file`-UID → klare Fehlermeldung.
8. Direkter `WriteTable` auf `table=sys_file_reference` (ohne Parent-Shortcut) ist zulässig, Client muss dann `tablenames` + `fieldname` selbst liefern.
9. Unbekannte Felder (z.B. news-spezifische Extensions auf `sys_file_reference`) werden durchgereicht, nicht abgelehnt.

### Geänderte Dateien

#### [`Classes/Service/TableAccessService.php`](../../workspace/typo3-mcp-server/Classes/Service/TableAccessService.php)

- `sys_file_reference` aus `$readOnlyTables` **entfernen**. Danach ist die Tabelle schreibbar, weil:
  - Nicht mehr in `$restrictedTables` (seit PR 1),
  - In der Root-Level-Allowlist (seit PR 1),
  - Workspace-capable (TCA, nicht änderbar),
  - Keine Admin-only-Flags.
- **Check, dass `$readOnlyTables` keine andere Schutzfunktion erfüllt**, die wir brauchen. Nein — hat keine.

#### [`Classes/MCP/Tool/Record/WriteTableTool.php`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/Record/WriteTableTool.php)

Zwei Baustellen:

**(a) `type=file`-Reject aus PR 1 entfernen/umbauen.**

In PR 1 wurde in der Validierungsschleife explizit geworfen, wenn `$fieldType === 'file'`:

```php
if ($fieldType === 'file') {
    return "Field '{$fieldName}': File fields are not supported. ...";
}
```

Dieser Check muss raus oder differenzieren: nur ablehnen, wenn der Wert **keine** strukturierte FAL-Inline-Liste ist (also z.B. ein Skalar wie im alten Test `'some_value'`). Bei Array-Input wird er akzeptiert und ins Inline-Processing übergeben.

**(b) FAL-Shortcut im Embedded-Inline-Flow.**

In `processEmbeddedInlineRelations()` (oder bereits früher in `processInlineRelations()`) vor dem DataMap-Aufbau ein Adapter-Schritt einziehen: wenn das Parent-Feld `type=file` oder `type=inline` mit `foreign_table=sys_file_reference` ist, jede Child-Row normalisieren:

- `file` (falls vorhanden) → `uid_local`
- Default-`tablenames` / `fieldname` aus `foreign_match_fields` oder (bei `type=file`) aus Parent-Tabelle + Feldname befüllen, wenn nicht explizit gesetzt.
- `uid_foreign` entfernen (wird wie heute per Post-Create-UPDATE gesetzt).
- Rest 1:1 durchreichen.

Die bestehende zweistufige DataHandler-Mechanik + Direkt-UPDATE bleibt unverändert. Nur die Eingabe-Normalisierung ist neu.

**Sicherstellen**, dass die Input-Shape-Validierung (`validateRecordData()`) nicht an unbekannten Metadaten-Feldern auf `sys_file_reference` scheitert (z.B. wenn news zusätzliche Spalten deklariert).

**Update-Pfad `handleExistingEmbeddedRelations()`**: Bereits heute funktional für hidden-Tables — verlässt sich auf UID-Matching. Prüfen, dass das mit FAL-Shortcut-Input (der ggf. keine `uid` mitgibt, sondern nur `file`+Metadaten) sauber läuft. Falls das Fehler liefert: Match-Strategie erweitern (UID explizit oder Fallback auf `file` + Position).

#### Fixtures

- [`Tests/Functional/Fixtures/fal_content.csv`](../../workspace/typo3-mcp-server/Tests/Functional/Fixtures/fal_content.csv): bereits da (aus PR 1), ggf. um ein leeres `tt_content`-Record (Parent ohne Bestandsreferenzen) erweitern, damit Create-Szenarien nicht immer an Bestand hängen.
- [`Tests/Functional/Fixtures/sys_file.csv`](../../workspace/typo3-mcp-server/Tests/Functional/Fixtures/sys_file.csv): bereits 2 Files (uid 1, 2) — ausreichend für Create + Swap-Tests.
- [`Tests/Functional/Fixtures/sys_file_reference.csv`](../../workspace/typo3-mcp-server/Tests/Functional/Fixtures/sys_file_reference.csv): bereits da (aus PR 1).

#### Neue PHPUnit-Tests

`Tests/Functional/MCP/Tool/FalWriteTest.php` — ausgerichtet am `FalReadTest`- und `InlineRelationWriteTest`-Muster, `AbstractFunctionalTest` als Basis.

**Minimal-Set (4 Tests, decken Akzeptanzkriterien ab)**:

1. `testCreateContentElementWithFileReference` — Create mit `image: [{file: 1, alternative: ...}]`. Assertions: Response enthält neue tt_content-UID; Direkt-DB-Query auf `sys_file_reference` zeigt eine Row mit korrekt gesetzten `uid_local`, `uid_foreign`, `tablenames='tt_content'`, `fieldname='image'`; diese Row liegt im aktuellen Workspace (`t3ver_wsid > 0` und `t3ver_state=1`).
2. `testReadAfterCreateRoundtrip` — Nach Create → `ReadTable tt_content uid=<new>` → `image[0].file.uid` stimmt, Alternative kommt durch.
3. `testUpdateReorderReferences` — Zwei Bilder anlegen, dann Reihenfolge tauschen. Assertion: `sorting_foreign` der Referenzen zeigt neue Ordnung, keine neuen UIDs.
4. `testDeleteContentElementCascadesReferences` — Parent löschen, in Workspace keine Referenzen mehr sichtbar.

**Optional, falls Akzeptanzkriterien es verlangen** (dann aber einzeln und zielgerichtet):
- `testWriteRequiresExistingSysFile` — nicht-existierende `file`-UID → Fehler.
- `testForeignMatchFieldsAutoFilled` — Create ohne explizite `tablenames`/`fieldname` → MCP füllt aus Parent-TCA.
- `testDirectWriteSysFileReference` — Top-Level `WriteTable` auf `sys_file_reference`.

Bestehende [`Tests/Functional/MCP/Tool/InlineRelationWriteTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/MCP/Tool/InlineRelationWriteTest.php) Zeile ~118 (`testWriteHiddenTableInlineRelation`) ist aktuell `markTestSkipped`. Den Skip entfernen und entweder mit FAL-Shortcut konkretisieren **oder** für einen anderen hidden-Table-Case umwidmen.

**Bestehende Tests, die evtl. angepasst werden müssen**:
- [`Tests/Functional/MCP/Tool/WriteTableToolErrorTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/MCP/Tool/WriteTableToolErrorTest.php) `testFileFieldsNotSupported`: heute positiver Test („Fehlermeldung kommt"). Nach PR 2 nicht mehr zutreffend für Array-Input. Umschreiben auf „Skalar-Input auf type=file wird abgelehnt" oder Test entfernen, wenn kein Skalar-Pfad übrig ist.
- [`Tests/Functional/Service/TableAccessServiceFieldAccessTest.php`](../../workspace/typo3-mcp-server/Tests/Functional/Service/TableAccessServiceFieldAccessTest.php) `testSysFileReferenceTableIsReadable`: die Assertion `permissions.write === false` wird umdrehen. Statt komplettem Refactor: Assertion auf `true` setzen + Test-Namen anpassen.

#### Doku

- [`Documentation/Architecture/FAL.md`](../../workspace/typo3-mcp-server/Documentation/Architecture/FAL.md):
  - Abschnitt „Linking shortcut" ergänzen: Input-Shape dokumentieren, Auto-Fill-Regel für `tablenames`/`fieldname`.
  - Implementierungs-Status-Tabelle: „Link" auf ✅ setzen.
- [`Documentation/Architecture/InlineRelations.md`](../../workspace/typo3-mcp-server/Documentation/Architecture/InlineRelations.md):
  - Abschnitt „FAL Tables (`sys_file`, `sys_file_reference`)": Write-Status aktualisieren.
- [`TECHNICAL_OVERVIEW.md`](../../workspace/typo3-mcp-server/TECHNICAL_OVERVIEW.md):
  - Zeile ~258 „File references: Currently read-only" → „Read + link existing files".
- [`README.md`](../../workspace/typo3-mcp-server/README.md):
  - Fileadmin-Zeile: „Read + Link ✅ / Upload ❌".

### Risiken / offene Fragen

1. **`crop` ist JSON-String** in TYPO3's TCA-Semantik. `WriteTableTool::convertDataForStorage()` konvertiert Arrays → JSON bei FlexForm-Feldern — prüfen, ob `crop` genauso behandelt werden muss, oder ob Client String liefern muss.
2. **Extensions können `sys_file_reference`-Spalten ergänzen** (z.B. news). Input-Validierung darf unbekannte Felder nicht ablehnen, nur unbekannte _Typen_.
3. **Language-Varianten**: Wenn Parent übersetzt, erben Referenzen `sys_language_uid`. In PR 2 auf Standard-Sprache fokussieren, Sprach-Variante als Follow-up markieren.
4. **`hideTable=true` Anzeige in `ListTables`**: `sys_file_reference` bleibt aus der Auflistung raus — der Shortcut-Pfad über Parent-Feld ist der Haupt-UX-Pfad. Explizit testen/bestätigen.
5. **Direkter `WriteTable sys_file_reference`**: zulassen (konsistent mit anderen schreibbaren Tabellen), aber ohne Auto-Fill. Akzeptanzkriterium 8.
6. **Modifizierte Workspace-Overlays auf Bestandsreferenzen**: Die bekannte TYPO3-Einschränkung aus PR 1 (WorkspaceRestriction filtert `t3ver_state=0, t3ver_oid>0` raus) trifft auch hier zu. Für Update-Pfade passt der Write-Flow aber — er erstellt die Workspace-Version und updated direkt, die Reads danach greifen auf die Live-Row zu (mit `t3ver_oid`-Swap im `processRecord`). Sollte funktionieren; **bei Read-After-Update-Test unbedingt verifizieren, dass das Ergebnis die neue Alternative zeigt.** Falls nicht: `workspaceOL()`-Overlay in `getInlineRelatedRecords()` nachrüsten (größeres Feature, dann eigener PR).

### Verification

```bash
# Im Extension-Repo
cd /Users/n.alhelwany/workspace/typo3-mcp-server
# (Code-Änderungen, Tests, commit)
git add -A && git commit -m "feat(fal): write sys_file_reference via linking shortcut"
git push

# Im Test-Projekt
cd /Users/n.alhelwany/workspace/default-project
ddev composer update hn/typo3-mcp-server
ddev composer --working-dir=vendor/hn/typo3-mcp-server install --ignore-platform-req=php

# Neue Tests isoliert
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter FalWriteTest --testdox"

# Regression-Check der bestehenden FAL-Tests
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter FalReadTest --testdox"

# Angepasste Tests
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter 'WriteTableToolErrorTest|TableAccessServiceFieldAccessTest' --testdox"
```

Jeder Test-Fehler, der in Isolation passiert, ist real. Full-Suite-Run ist wegen PHP-8.2-Paratest-Lücke nur Smoke-Check.

---

## PR 3 — UploadFile-Tool

### Ziel

Neues MCP-Tool `UploadFile`, das eine Datei hochlädt (base64-Content), den physischen File in den `fileadmin/` schreibt, den `sys_file`-Record anlegt — **beides live** (FAL-Ausnahme laut [CLAUDE.md](../../workspace/typo3-mcp-server/CLAUDE.md) + [FAL.md](../../workspace/typo3-mcp-server/Documentation/Architecture/FAL.md)). Verwendet ausschließlich TYPO3-Core-FAL-APIs, kein manuelles SQL. Rückgabe: `sys_file.uid`, die der Client dann direkt in den PR-2-Linking-Flow einbauen kann.

### Akzeptanzkriterien

1. Input: base64-Content + Dateiname. Optional: Ziel-Storage-UID (Default: `getDefaultStorage()`), Zielordner (Default: `fileadmin/`), Metadaten (`alternative`, `title`, `description`).
2. Ein gültiger Bild-Upload legt Datei + `sys_file`-Row **in Live** an (`t3ver_wsid=0`), auch wenn der Tool-Aufrufer in einem Workspace sitzt.
3. MIME-Whitelist (Default: gängige Bildformate + PDF, konfigurierbar) blockt ausführbare oder unbekannte Formate — sowohl per Dateiname als auch per Content-Sniffing (`finfo`).
4. Max-Dateigröße blockt Riesenfiles (Default ~20MB, konfigurierbar).
5. Duplicate-Handling via TYPO3s `DuplicationBehavior::RENAME` (Default): `image.jpg` + erneuter Upload → `image_01.jpg`.
6. BE-User ohne Filemount-Zugriff auf Ziel-Storage/Ordner → klare Fehlermeldung (Core-Meldung durchreichen).
7. Rückgabe enthält: `uid`, `identifier`, `name`, `mime_type`, `size`, `storage`. **Keine** `sys_file_reference` wird angelegt (das ist PR-2-Job).
8. Optional: wenn `alternative`/`title`/`description` mitgegeben, schreiben sie in `sys_file_metadata` (ebenfalls live, falls nicht workspace-capable — siehe offene Frage unten).
9. End-to-End: Upload → Linking (PR 2) → Read zeigt korrekt.

### Neue Dateien

#### [`Classes/MCP/Tool/File/UploadFileTool.php`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/File/UploadFileTool.php)

- Erbt von [`Classes/MCP/Tool/AbstractTool.php`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/AbstractTool.php) — **nicht** von [`AbstractRecordTool`](../../workspace/typo3-mcp-server/Classes/MCP/Tool/Record/AbstractRecordTool.php), weil wir **keinen** Workspace-Kontext initialisieren wollen. Ziel: Writes laufen live.
- `initialize()` override: setzt explizit **nicht** auf Workspace um. Ggf. aktiv auf Workspace 0 schalten, falls der BE_USER einen Workspace-Context hat, der nach unten durchschlagen könnte (Context-Aspect).
- `getSchema()`: beschreibt `file_content` (base64 string), `filename`, optional `path`, `storage_uid`, `alternative`, `title`, `description`.
- `doExecute()`:
  1. Input-Validierung (nicht-leerer Content, gültiger Dateiname).
  2. Permissions: `$GLOBALS['BE_USER']->getFileMountRecords()` + Storage-Check (Core-API).
  3. Storage + Folder holen via `StorageRepository::getDefaultStorage()` bzw. `findByUid()`, `$storage->getFolder($path)`.
  4. Base64 dekodieren → `GeneralUtility::tempnam('mcp_upload_')` → schreiben, mit `try { ... } finally { unlink($tempPath); }` wrapped.
  5. MIME-Check (`finfo_file`) + Größen-Check.
  6. `$folder->addFile($tempPath, $filename, DuplicationBehavior::RENAME)` → liefert `File`-Objekt.
  7. Metadata: `$file->updateProperties([...])` falls Metadaten mitgegeben.
  8. Rückgabe via `createJsonResult([...])`.

#### [`Classes/Service/FileUploadService.php`](../../workspace/typo3-mcp-server/Classes/Service/FileUploadService.php) (optional, empfohlen)

Extrahiert die FAL-Mechanik aus dem Tool:

- `validate(base64, filename, mimeWhitelist, maxSize): array<errors>`
- `uploadToStorage(Folder $folder, string $tempPath, string $filename, DuplicationBehavior): File`
- `applyMetadata(File $file, array $metadata): void`

Macht Testing einfacher (man kann Service mocken oder mit in-memory-storage laufen lassen).

#### Tool-Registrierung

- Prüfen, wie andere Tools registriert sind. Aktuell vermutlich Autodiscovery via Namespace — neue Datei unter `Classes/MCP/Tool/File/` sollte ausreichen. Falls explizite Registrierung in [`Configuration/Services.yaml`](../../workspace/typo3-mcp-server/Configuration/Services.yaml) nötig, dort eintragen.

#### Tests

`Tests/Functional/MCP/Tool/FileUploadToolTest.php` (neu). Base: `AbstractFunctionalTest` oder eine neue `AbstractFileUploadTest`-Basis, die ein temp-Storage auf `Tests/Functional/Fixtures/fileadmin/` aufsetzt.

**Minimal-Set (5–6 Tests)**:

1. `testUploadValidImageCreatesSysFile` — base64-PNG uploaden. Assert: physische Datei existiert, `sys_file`-Row hat korrekten `mime_type`, Rückgabe enthält UID.
2. `testUploadWritesToLiveEvenInWorkspace` — BE_USER im Workspace, Upload → `sys_file.t3ver_wsid = 0`.
3. `testUploadRejectsDisallowedMime` — base64 einer Nicht-whitelisted MIME-Type → Fehler.
4. `testUploadRespectsDuplicationBehavior` — zweimal denselben Filename → zweiter kriegt `_01`-Suffix.
5. `testUploadDoesNotCreateSysFileReference` — Nach Upload: keine `sys_file_reference` angelegt.
6. `testUploadFollowedByLinkingRoundtrip` — Upload → `WriteTable` auf `tt_content` mit `image: [{file: <returned uid>}]` → Roundtrip.

### Risiken / offene Fragen

1. **`sys_file_metadata` Workspace-Capability prüfen.** [`Documentation/Architecture/FAL.md`](../../workspace/typo3-mcp-server/Documentation/Architecture/FAL.md) behauptet „No (core)" — das ist **nicht verifiziert** in TYPO3 14. Vor Merge:
   ```php
   var_dump($GLOBALS['TCA']['sys_file_metadata']['ctrl']['versioningWS'] ?? null);
   ```
   Wenn workspace-capable: Metadata-Writes **müssen durch den Workspace**, nicht direkt live. Das ändert den Fluss und FAL.md muss korrigiert werden.
2. **Base64-Payload-Limit**: MCP-Protokoll via stdio/HTTP kann bei >10MB Probleme machen. Default 20MB als Hard-Limit, dokumentieren. Erst-Tests mit 100KB / 1MB / 5MB.
3. **Temp-Datei-Cleanup** bei Fehler: try/finally ist Pflicht, sonst Disk-Leak.
4. **`addFile` vs. `addFileAndFolder`**: API-Namen in TYPO3 14 checken. Hinweise aus TYPO3-Docs: `$storage->addFile(...)` ist Shortcut; `$folder->addFile(...)` nimmt lokalen Pfad.
5. **MIME-Sniffing gegen File-Extension-Spoofing**: Nur Extension checken reicht nicht. Content-Sniffing via `finfo_open(FILEINFO_MIME_TYPE)` + `finfo_file()` vor dem Upload.
6. **Filemount-Permissions**: Admin bypassen alles. Non-Admin nur in ihren Filemounts. Nicht selbst logik bauen — nur Core-Checks durchreichen.
7. **Orphan-Files** (Upload ohne Link): out of scope, dokumentiert in FAL.md.
8. **Image-Metadata** (`width`/`height`): TYPO3 extrahiert automatisch beim Upload in `sys_file_metadata`. Nichts zu tun, nur im Test verifizieren.
9. **ListStorages-Tool** wäre UX-verstärkend (damit der Client weiß, welche Storage-UIDs gültig sind). Out of scope für PR 3 oder als PR 3b nachziehen.

### Doku

- [`Documentation/Architecture/FAL.md`](../../workspace/typo3-mcp-server/Documentation/Architecture/FAL.md):
  - Abschnitt „Upload behavior" ergänzen: Input, Defaults, Limits, MIME-Whitelist, Filemount-Semantik.
  - `sys_file_metadata`-Zeile in Policy-Tabelle korrigieren, falls Verification zeigt, dass sie workspace-capable ist.
  - Implementierungs-Status: „Upload" auf ✅.
- [`TECHNICAL_OVERVIEW.md`](../../workspace/typo3-mcp-server/TECHNICAL_OVERVIEW.md):
  - Available-Tools-Liste: `UploadFile` hinzufügen unter neuer Kategorie „File Management".
  - „What's Not Yet Implemented → Image/File Handling": ganzen Block entfernen (oder auf „Live editing of sys_file metadata" reduzieren, falls nötig).
- [`README.md`](../../workspace/typo3-mcp-server/README.md):
  - Fileadmin-Status: „✅ Ready".
- [`CLAUDE.md`](../../workspace/typo3-mcp-server/CLAUDE.md): keine Regel-Änderung, FAL-Ausnahme steht seit PR 0.

### Verification

```bash
cd /Users/n.alhelwany/workspace/typo3-mcp-server
# ... Code ...
git add -A && git commit -m "feat(fal): add UploadFile tool"
git push

cd /Users/n.alhelwany/workspace/default-project
ddev composer update hn/typo3-mcp-server
ddev composer --working-dir=vendor/hn/typo3-mcp-server install --ignore-platform-req=php

ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter FileUploadToolTest --testdox"

# Regression
ddev exec "cd vendor/hn/typo3-mcp-server && vendor/bin/phpunit -c phpunit.xml.dist --filter 'FalReadTest|FalWriteTest' --testdox"
```

Manuelle End-to-End-Verifikation via MCP-Client (Claude Desktop o.ä.):

1. UploadFile mit einem lokalen `image.png`.
2. Antwort enthält `sys_file.uid`.
3. `WriteTable tt_content` mit `image: [{file: <uid>, alternative: "..."}]`.
4. `ReadTable tt_content uid=<new>` zeigt die Referenz + eingebetteten `sys_file`-Block.
5. Physische Datei liegt in `fileadmin/` (SFTP/FileAdmin-Backend-Modul prüfen).
6. Frontend zeigt das Bild nach Workspace-Publish.

---

## Branch-Strategie

Alle 3 PRs auf demselben Feature-Branch `claude/typo3-14-compatibility-RvFn7` stacken (wie PR 0 + PR 1). Merge gegen `main` erfolgt am Ende, wenn alle 3 in sich grün sind. Zwischen-Squashes nicht nötig — saubere Commit-Historie ist wertvoller als flaches Log.

## Offene Punkte vor Start von PR 2

Keine hartblockierenden. Empfohlen zu Beginn der PR-2-Session:

1. TCA-Shape aller Zielfelder dumpen (siehe „TCA-Verification-Snippet" oben in PR-1-Abschnitt).
2. `crop`-Feld-Semantik verifizieren (JSON-String vs. Array).
3. Bestehende `testFileFieldsNotSupported`-Erwartung checken — dort wird der Skalar-vs-Array-Case entschieden.

## Offene Punkte vor Start von PR 3

1. `sys_file_metadata.versioningWS` verifizieren — entscheidet, ob Metadata live oder Workspace geschrieben wird.
2. In TYPO3 14 die exakte API für `$folder->addFile()` checken (kann sich gegen 13 unterscheiden).
3. Entscheiden: eigenes `ListStorages`-Tool (UX) oder Storage-Auswahl nur via Storage-UID ohne Discovery.
