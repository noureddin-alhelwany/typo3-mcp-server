# FAL-Support für MCP-Server — 3-PR-Umsetzungsplan

## Ausgangslage (bestätigt durch Code-Analyse)

### Wie Inline-Relations heute serialisiert werden (Read)

Zentrale Methode: [ReadTableTool::includeInlineRelations()](Classes/MCP/Tool/Record/ReadTableTool.php#L806) (ca. Zeile 806–844), geruft aus [includeRelations()](Classes/MCP/Tool/Record/ReadTableTool.php#L641).

- Für jede Parent-Row werden alle TCA-Felder mit `type=inline` durchlaufen.
- Kinder werden per Query aus der Kind-Tabelle geholt ([getInlineRelatedRecords()](Classes/MCP/Tool/Record/ReadTableTool.php#L851), ca. Zeile 851–907), mit vollem Workspace-Set (`WorkspaceRestriction` + `WorkspaceDeletePlaceholderRestriction`) und dann durch `processRecord()` geschleust (Typ-Konvertierung, FlexForm-Expansion, UID-Swap `t3ver_oid → uid`).
- Unterscheidung nach `hideTable`:
  - `hideTable=false` (z.B. `tt_content` als Kind von `tx_news_domain_model_news.content_elements`): Rückgabe nur als UID-Array.
  - `hideTable=true` (z.B. `sys_file_reference`, `tx_news_domain_model_link`): Rückgabe als voll-serialisierte Record-Objekte.
- **Keine rekursive Weiter-Expansion** — Kind-Records werden so eingebettet, wie sie aus der DB kommen (nach TCA-Feldkonvertierung). Das bedeutet: ein sys_file_reference-Record käme mit `uid_local: 42` zurück, aber der dahinterstehende `sys_file`-Record wird heute nicht aufgelöst.

### Wie Inline-Relations heute geschrieben werden (Write)

Zentrale Methode: [WriteTableTool::processInlineRelations()](Classes/MCP/Tool/Record/WriteTableTool.php#L818) mit zwei Untervarianten:

- **Embedded** (`hideTable=true`): [processEmbeddedInlineRelations()](Classes/MCP/Tool/Record/WriteTableTool.php#L879) — zweistufiger DataHandler-Flow:
  1. Parent schreiben (ohne Inline-Daten).
  2. Children schreiben via separatem DataHandler, wobei das `foreign_field` (der Back-Pointer zum Parent, z.B. `uid_foreign`) aus dem Child-Datenrecord gelöscht wird, bevor DataHandler ausgeführt wird — sonst würde DataHandler es ignorieren.
  3. Nach `substNEWwithIDs`-Auflösung wird das `foreign_field` per direktem DB-UPDATE mit der **Live-UID** des Parents gesetzt.
- **Independent** (`hideTable=false`): [processIndependentInlineRelations()](Classes/MCP/Tool/Record/WriteTableTool.php#L923) — Client schickt UID-Liste, MCP aktualisiert `foreign_field` auf den Kind-Records per DataHandler-Update.
- **Update-Pfad**: [handleExistingEmbeddedRelations()](Classes/MCP/Tool/Record/WriteTableTool.php#L1002) vergleicht Bestand vs. neue Liste, deleted entfernte Kinder via DataHandler-CMD, behält verbleibende.
- **Delete**: Parent-Delete via DataHandler-CMD. Cascade der Kinder ist dem TYPO3-Core überlassen (TCA-basiert).

### Workspace-Overlay für Reads

- `AbstractRecordTool::initialize()` ruft `WorkspaceContextService::switchToOptimalWorkspace()` auf — sucht den ersten schreibbaren Workspace oder legt den "MCP Workspace" an.
- Alle Read-Queries setzen auf Query-Level `WorkspaceRestriction` (Core) + `WorkspaceDeletePlaceholderRestriction` (Custom). In `processRecord()` wird `t3ver_oid → uid` geswappt, damit der Client konsistent Live-UIDs sieht.

### Test-Pattern

- [Tests/Functional/AbstractFunctionalTest.php](Tests/Functional/AbstractFunctionalTest.php) lädt CSV-Fixtures aus `Tests/Functional/Fixtures/`, setzt BE-User + LanguageService, bietet `createAndSwitchToWorkspace()` und `switchToWorkspace()`.
- Fixtures vorhanden: `be_users.csv`, `pages.csv`, `tt_content.csv`, `sys_file.csv` (1 Datei, UID 1), `sys_workspace.csv`, `sys_category*.csv`, news-Fixtures.
- **`sys_file_reference.csv` fehlt** — muss für PR 1 Read-Tests + PR 2 Write-Tests neu angelegt werden.
- Beispielmuster für DataHandler-Roundtrip-Tests: [InlineRelationWriteTest.php:38](Tests/Functional/MCP/Tool/InlineRelationWriteTest.php#L38). Der skipped-Test in Zeile 118–121 (`testWriteHiddenTableInlineRelation`) kann in PR 2 aktiviert werden.

### Aktueller Zustand `sys_file` / `sys_file_reference`

| Tabelle | workspace_capable | restrictedTables | readOnlyTables | Heutiges Verhalten |
|---|---|---|---|---|
| `sys_file` | nein | nein | ja (TableAccessService:489) | Gesperrt zum Lesen, weil `validateTableAccess()` → `getTableAccessInfo()` mit `requireWorkspaceCapability=true` aufgerufen wird → nicht-workspace-fähige Tabellen fallen bei der Zugangsprüfung durch |
| `sys_file_reference` | **ja** | ja (TableAccessService:455) | nein | Komplett gesperrt (Kommentar: „file handling not supported yet"). Inline-Felder auf sys_file_reference werden dadurch auch aus `GetTableSchema` und `ListTables` ausgefiltert (`canAccessField` → Zeile 618–622) |

## FAL-Inline-Feld vs. „normale" eingebettete Inline-Relation

Beispiel `tt_content.image` (TYPO3-Core-TCA, Referenz):

```php
'image' => [
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'sys_file_reference',
        'foreign_field' => 'uid_foreign',
        'foreign_sortby' => 'sorting_foreign',
        'foreign_table_field' => 'tablenames',
        'foreign_match_fields' => [
            'fieldname' => 'image',
            'tablenames' => 'tt_content',
        ],
    ]
]
```

Unterschiede zu einer „normalen" eingebetteten Inline-Relation (z.B. `tx_news_domain_model_news.related_links` → `tx_news_domain_model_link`):

| Aspekt | tx_news_domain_model_link | sys_file_reference |
|---|---|---|
| `hideTable` | true | true |
| Back-Pointer-Spalte | `parent` (nicht als TCA-Column definiert — deshalb der direkte DB-UPDATE) | `uid_foreign` (ist reguläre TCA-Column, aber als IRRE-`foreign_field` wird sie vom DataHandler ignoriert → gleicher direkter-UPDATE-Trick) |
| Match-Felder | keine | `tablenames='tt_content'`, `fieldname='image'` aus `foreign_match_fields` → müssen MCP-seitig beim Schreiben automatisch gesetzt werden |
| Nutzlast-Felder | nur eigene (title, uri, ...) | eigene Metadaten (alternative, title, crop, link, description, autoplay) + `uid_local` (→ sys_file) |
| Zweite Ebene | keine | `uid_local` zeigt auf `sys_file` — beim Lesen will der Client den File-Record mit eingebettet sehen (identifier, mimeType, size, storage) |

**Konsequenz:** Die bestehende Two-Step-DataHandler-Mechanik ist fast komplett wiederverwendbar. Was FAL zusätzlich braucht:
- **Read**: zweite Embedding-Ebene (`uid_local` → `sys_file`-Record).
- **Write**: Auto-Injection von `tablenames`/`fieldname` aus `foreign_match_fields` und ein ergonomischer Input-Shortcut (`{file, alternative, title, crop, link}` statt `{uid_local, tablenames, fieldname, …}`).

---

## PR 1 — Read + FAL-Serializer

### Ziel

`sys_file` und `sys_file_reference` lesbar machen. Inline-FAL-Felder werden beim Read als zweistufig eingebettete Struktur zurückgegeben: Referenz-Metadata auf erster Ebene, voller `sys_file`-Record als `file: {...}` darunter.

**Akzeptanzkriterium**: `ReadTable` auf einem `tt_content`-Record mit `CType=image` liefert
```
image: [{uid, alternative, title, crop, link, file: {uid, identifier, mimeType, size, storage, name}}]
```
— Workspace-Transparenz für `sys_file_reference` bleibt intakt (Live-UIDs werden zurückgegeben).

### Änderungen

#### Geänderte Dateien

- **[Classes/Service/TableAccessService.php](Classes/Service/TableAccessService.php)**
  - Zeile 455: `sys_file_reference` aus `$restrictedTables` entfernen.
  - Zeile 195: `validateTableAccess()` — für `$operation='read'` den Workspace-Capability-Check überspringen. Konkret: `getTableAccessInfo($table, $operation !== 'read')` oder explizit eine neue kurze Logik, die für Read den Read-Only-Pfad durchlässt. Ist-Verhalten heute: `getTableAccessInfo($table)` mit Default `true` blockiert `sys_file` komplett.
  - **Wichtig**: `canAccessField()` (Zeile 618–622) filtert Inline-Felder auf restricted Tabellen heraus. Sobald `sys_file_reference` nicht mehr restricted ist, taucht das `image`-Feld automatisch in `GetTableSchema` und in Read-Outputs auf — **ohne weitere Änderung**.

- **[Classes/MCP/Tool/Record/ReadTableTool.php](Classes/MCP/Tool/Record/ReadTableTool.php)**
  - In `includeInlineRelations()` (ca. Zeile 806–844): wenn `foreign_table === 'sys_file_reference'`, jeden Child-Record um `file: <resolved sys_file>` anreichern.
  - Neue private Helper-Methode `loadSysFileRecord(int $sysFileUid): ?array` — nutzt dieselbe QueryBuilder-Struktur wie `getInlineRelatedRecords()` (DeletedRestriction, keine WorkspaceRestriction nötig — `sys_file` ist nicht workspace-capable), wählt nur Nutz-Spalten (`uid`, `identifier`, `name`, `mime_type`, `size`, `storage`, `type`, `sha1`), läuft durch `processRecord()` für Typ-Konvertierung.
  - Felder-Projektion überdenken: wollen wir `uid_local` im sys_file_reference-Record behalten oder durch `file` ersetzen? **Empfehlung**: behalten (transparent), `file` zusätzlich einhängen. Das bleibt rückwärtskompatibel zur heutigen Embedded-Logik und ist für den Client leichter zu verstehen.

- **[Classes/Utility/TcaFormattingUtility.php](Classes/Utility/TcaFormattingUtility.php)** (optional, nice-to-have)
  - Zeile ~143–148: bei `foreign_table='sys_file_reference'` zusätzlich den FAL-Hinweis in die Schema-Ausgabe aufnehmen (z.B. „FAL: uid_local references sys_file"). Macht es für den Client expliziter, dass da eine zweistufige Struktur kommt. **Kann auch in PR 2 verschoben werden**, falls PR 1 kleiner bleiben soll.

- **[Tests/Functional/Fixtures/sys_file_reference.csv](Tests/Functional/Fixtures/sys_file_reference.csv)** (neu)
  - Mindestens eine Referenz, die ein existierendes `tt_content`-Fixture (CType=image) mit dem vorhandenen `sys_file` (uid=1) verknüpft, inkl. `tablenames='tt_content'`, `fieldname='image'`, `uid_foreign=<content uid>`, Metadaten (`alternative`, `title`).

- **[Tests/Functional/Fixtures/tt_content.csv](Tests/Functional/Fixtures/tt_content.csv)**
  - Ggf. um einen `CType=image`-Record ergänzen, falls noch nicht vorhanden. Prüfen beim Implementieren.

- **[Tests/Functional/Fixtures/sys_file.csv](Tests/Functional/Fixtures/sys_file.csv)**
  - Ggf. ergänzen (zweite Datei mit anderem Mimetype), damit Tests auch „falsche" Datei nicht mit-matchen.

#### Neue PHPUnit-Tests

- **[Tests/Functional/MCP/Tool/FalReadTest.php](Tests/Functional/MCP/Tool/FalReadTest.php)** (neu, nach `InlineRelationWriteTest`-Muster)
  - `testReadSysFileDirectly`: `ReadTable` mit `table=sys_file, uid=1` muss jetzt erfolgreich sein und sauber serialisiertes File-Objekt liefern.
  - `testReadSysFileReferenceDirectly`: `ReadTable` mit `table=sys_file_reference, uid=<ref>` muss gehen.
  - `testReadContentElementWithImageExpandsFileReference`: `ReadTable` auf tt_content-image-Record; assert `image[0].file.identifier === '/test.jpg'` und `image[0].alternative === 'Alt text'`.
  - `testWorkspaceOverlayForFileReferenceReturnsLiveUid`: In einem Workspace die sys_file_reference modifizieren (alternative ändern), dann `ReadTable` — die Antwort muss die Live-UID zeigen, aber den geänderten alternative-Wert.
  - `testSysFileReferenceAppearsInGetTableSchema`: `GetTableSchema` auf `tt_content` → `image`-Feld ist jetzt enthalten mit `foreign_table: sys_file_reference`.
  - `testSysFileAppearsInListTables`: `ListTables` zeigt `sys_file` (read-only gekennzeichnet) — optional, falls sinnvoll.

#### Doku-Updates

- **[CLAUDE.md](CLAUDE.md)**: Keine Regel-Änderung nötig. Die FAL-Ausnahme steht bereits.
- **[Documentation/Architecture/FAL.md](Documentation/Architecture/FAL.md)**: Kurzen Abschnitt „Read shape" ergänzen (konkrete Beispielstruktur, was der Client sieht). Bleibt innerhalb des Policy-Scope — ist Beschreibung der observable Outputs.
- **[Documentation/Architecture/InlineRelations.md](Documentation/Architecture/InlineRelations.md)**: Abschnitt „Restricted Tables" aktualisieren — `sys_file_reference` ist dann lesbar; verbleibende Restriktion ist nur noch Schreib-Seite (die in PR 2 fällt).
- **[README.md](README.md)**: Fileadmin-Row-Status kann aktualisiert werden auf „Read ✅ / Upload ❌ (in progress)" — optional.

#### Risiken / offene Fragen

1. **`validateTableAccess()`-Signatur-Änderung**: Wird von mehreren Tools genutzt. Prüfen, dass kein anderer Konsument davon abhängt, dass Reads auf nicht-workspace-capable Tabellen fehlschlagen. Grep nach `validateTableAccess\|canReadTable` im gesamten `Classes/`-Baum als Teil der Implementierung.
2. **`processed` und `metadata`**: Soll der eingebettete `sys_file`-Record auch die `sys_file_metadata`-Overlays enthalten (alternative/title/description auf File-Ebene statt Reference-Ebene)? **Empfehlung für PR 1**: nein, nur `sys_file`-Kernfelder. Metadata-Overlay ist eigene Baustelle.
3. **Storage-Auflösung**: `sys_file.storage` ist eine numerische UID — soll der Client zusätzlich einen Storage-Namen/Pfad bekommen? **Empfehlung**: nein, Raw-UID reicht; das ist konsistent mit dem bisherigen MCP-Stil.
4. **Performance**: Jede FAL-Referenz löst eine zusätzliche Query nach sys_file aus. Bei einer Page mit 20 Bildern: 20 Zusatzqueries. **Empfehlung**: reicht für jetzt — Optimierung (Batch-Fetch) erst bei Bedarf.
5. **t3ver_*-Felder-Leak**: Agent-Analyse hat gezeigt, dass `t3ver_*` nicht explizit aus Read-Results gefiltert werden — kommen aber normalerweise nicht in Type-spezifischen Feldern vor. Für `sys_file_reference` prüfen und ggf. eine kleine Filterung einziehen, wenn sie auftauchen.

---

## PR 2 — Linking (Write `sys_file_reference`)

### Ziel

`sys_file_reference` wird schreibbar. Der Client kann FAL-Inline-Felder mit einer ergonomischen Shortcut-Form befüllen. Referenz-Records laufen durch den Workspace — das ist die Normalregel, nicht die FAL-Ausnahme.

**Input-Shortcut (erwartet vom Client):**
```json
{
  "table": "tt_content",
  "action": "create",
  "pid": 1,
  "data": {
    "CType": "image",
    "header": "Hero",
    "image": [
      {"file": 42, "alternative": "Hero image", "title": "Main hero", "crop": "{...}", "link": "t3://page?uid=5"}
    ]
  }
}
```

MCP transformiert das intern in sys_file_reference-Child-Records mit korrekt gesetzten `uid_local`, `tablenames`, `fieldname` (aus `foreign_match_fields`), `pid`, und löst `uid_foreign` wie gehabt per Post-Create-UPDATE auf die Live-Parent-UID auf.

**Akzeptanzkriterien:**
- Neuer `tt_content`-Record mit `CType=image` + bestehender `sys_file`-UID wird korrekt angelegt, die Referenz landet im Workspace.
- Update: Reihenfolge umstellen, einzelnes Bild austauschen, Metadaten ändern — funktioniert wie bei anderen Inline-Relations.
- Delete des Content-Elements cascadet die Referenzen (Default-DataHandler-Verhalten).

### Änderungen

#### Geänderte Dateien

- **[Classes/Service/TableAccessService.php](Classes/Service/TableAccessService.php)**
  - Sys_file_reference ist nach PR 1 aus `$restrictedTables` raus — schreibbar ist es dann auch automatisch, weil es workspace-capable ist und nicht in `$readOnlyTables` steht. **Kein zusätzlicher Eingriff nötig**. Die bestehende Relation-Table-Erkennung (Zeile 507–514) macht den Rest korrekt.

- **[Classes/MCP/Tool/Record/WriteTableTool.php](Classes/MCP/Tool/Record/WriteTableTool.php)**
  - In `processEmbeddedInlineRelations()` (Zeile 879–918) oder einer neuen Helper-Methode davor: wenn `foreign_table === 'sys_file_reference'`, den Client-Input transformieren:
    - `file` (oder `uid_local`) → `uid_local`
    - `foreign_match_fields` aus der Parent-TCA lesen und als konstante Felder in jeden Child-Record einfügen (`tablenames`, `fieldname`).
    - Metadatenfelder (`alternative`, `title`, `crop`, `link`, `description`, `autoplay`) durchreichen.
  - Entscheidung: Shortcut-Parsing **vor** `extractInlineRelations()` oder **in** `processEmbeddedInlineRelations()`? **Empfehlung**: direkt in `processEmbeddedInlineRelations()`, als erste Transformation. Hält die Änderung lokal und betrifft nur den FAL-Pfad.
  - Bestehende `handleExistingEmbeddedRelations()`-Logik (Update/Delete-Diff) sollte out-of-the-box funktionieren — Kinder werden nach UID identifiziert.
  - Direkter UPDATE am Ende setzt `uid_foreign` auf Live-Parent-UID. Für FAL ist das identisch.

- **[Tests/Functional/Fixtures/sys_file_reference.csv](Tests/Functional/Fixtures/sys_file_reference.csv)**
  - Aus PR 1 übernommen, ggf. um Ausgangs-Referenzen für Update/Delete-Szenarien ergänzen.

- **[Tests/Functional/MCP/Tool/InlineRelationWriteTest.php:118](Tests/Functional/MCP/Tool/InlineRelationWriteTest.php#L118)**
  - Den `markTestSkipped()` in `testWriteHiddenTableInlineRelation` entfernen und den Test für den FAL-Case konkretisieren (oder belassen für tx_news_domain_model_link und zusätzlich FalWriteTest.php anlegen).

#### Neue PHPUnit-Tests

- **[Tests/Functional/MCP/Tool/FalWriteTest.php](Tests/Functional/MCP/Tool/FalWriteTest.php)** (neu)
  - `testCreateContentElementWithFileReference`: Create tt_content/CType=image mit `image: [{file: 1, alternative: 'x'}]`. Assert: sys_file_reference landet in Workspace, `tablenames='tt_content'`, `fieldname='image'`, `uid_local=1`, `uid_foreign=<tt_content live uid>`.
  - `testReadAfterCreateRoundtrip`: Nach Create → ReadTable → das neue `image`-Feld ist mit File-Expansion zurück.
  - `testUpdateReorderReferences`: Zwei Bilder anlegen, dann Reihenfolge per Update tauschen. Assert: `sorting_foreign` korrekt, keine Duplikate, keine Löschungen.
  - `testUpdateSwapFile`: Ein Bild austauschen (`file: 2` statt `file: 1` auf einer Referenz). Assert: entweder die Referenz wird an Ort und Stelle aktualisiert oder alt-gelöscht/neu-angelegt — je nachdem, welches Verhalten die bestehende `handleExistingEmbeddedRelations()` zeigt. Dokumentieren, was passiert.
  - `testUpdateChangeMetadata`: Nur `alternative` auf einer bestehenden Referenz ändern, `file` bleibt. Assert: andere Felder unverändert.
  - `testDeleteContentElementCascadesReferences`: tt_content löschen, Referenzen müssen in der Follow-up-Read-Abfrage weg sein (via DataHandler-Default-Cascade).
  - `testWorkspaceIsolation`: Referenz in WS anlegen → in Live-Workspace darf sie nicht sichtbar sein; nach Publish schon.
  - `testForeignMatchFieldsAutoFilled`: Direkter Schreibversuch auf sys_file_reference ohne `tablenames`/`fieldname` via Inline-Shortcut — MCP füllt sie auf; mit manueller Direktschreib-API müsste man sie selbst liefern.
  - `testWriteRequiresExistingSysFile`: Ein nicht existierendes `file: 9999` → Fehler mit klarer Meldung.
  - `testDirectWriteSysFileReference`: Top-Level `WriteTable` mit `table=sys_file_reference` direkt (ohne Shortcut-Parent) — für Flexibilität; ist der Input ohne `foreign_match_fields` Auto-Fill möglich, muss der Client `tablenames` und `fieldname` selbst liefern.

#### Doku-Updates

- **[Documentation/Architecture/FAL.md](Documentation/Architecture/FAL.md)**: Abschnitt „Linking shortcut" ergänzen — dokumentiert das Input-Shape und die Auto-Fill-Regel.
- **[Documentation/Architecture/InlineRelations.md](Documentation/Architecture/InlineRelations.md)**: Den Restricted-Tables-Abschnitt entfernen/entschärfen; eventuell einen konkreten FAL-Beispielabschnitt dazu.
- **[TECHNICAL_OVERVIEW.md](TECHNICAL_OVERVIEW.md)**: Die „Relation Handling"-Zeile („File references: Currently read-only") kann jetzt auf „File references: Read + Link existing files" gehoben werden.
- **[README.md](README.md)**: Fileadmin-Row-Status aktualisieren („Read + Link ✅ / Upload ❌").

#### Risiken / offene Fragen

1. **Direkter `WriteTable` auf `sys_file_reference`**: Soll das erlaubt sein (Client schreibt Referenzen ohne Parent-Context)? Pro: Flexibilität, bestehende MCP-Konsistenz. Contra: größere Fehlerfläche (Client muss `tablenames`/`fieldname` manuell liefern), weniger ergonomisch. **Empfehlung**: erlauben (Tabelle ist dann eben schreibbar), aber Shortcut bleibt der primäre Pfad.
2. **`crop`-Feld**: Das ist in TYPO3 ein JSON-String. Nimmt der bestehende `convertDataForStorage()`-Flow das 1:1 durch? Muss beim Implementieren verifiziert werden.
3. **Metadaten-Felder sind extensible**: Andere Extensions können zusätzliche Spalten in `sys_file_reference` haben (z.B. news legt häufig eigene ab). Der Shortcut darf unbekannte Felder nicht ablehnen — sie sollten einfach durchgeschleust werden.
4. **Language-Varianten von Referenzen**: Wenn der Parent in einer übersetzten Sprache existiert, müssen die File-Referenzen die `sys_language_uid` korrekt erben. **Empfehlung**: in PR 2 erstmal nicht implementieren, Test für Standard-Sprache. Sprache-FAL ist eigenes Follow-up.
5. **`hideTable`-Check in `ListTables`**: `sys_file_reference` hat `hideTable=true`. Aktuell filtert `ListTablesTool` solche Tabellen aus der Standard-Auflistung. Prüfen, ob das gewünscht ist (es ist — FAL-Referenz soll nicht primär als „writable table" erscheinen, sondern über den Inline-Shortcut).

---

## PR 3 — UploadFile-Tool

### Ziel

Neues Tool `UploadFile`, das die physische Datei und den `sys_file`-Record in Live schreibt (FAL-Ausnahme der CLAUDE.md-Regel). Verwendet ausschließlich TYPO3-Core-FAL-APIs, kein manuelles SQL. Gibt `sys_file.uid` zurück, die der Client dann direkt in den PR-2-Linking-Flow einbauen kann.

**Akzeptanzkriterien:**
- Tool akzeptiert: base64-kodierter Inhalt, Dateiname, optional Zielordner (Default: `fileadmin/`), optional Ziel-Storage-UID (Default: erster verfügbarer, typisch 1), optional Metadaten (`alternative`, `title`, `description`).
- MIME-Whitelist (Default: gängige Bildformate + PDF) + Größenlimit (konfigurierbar via Extension-Setting).
- Duplicate-Handling via `DuplicationBehavior::RENAME` als Default.
- Permission-Check nutzt BE-User-Filemounts — ohne Mount-Zugriff wird mit klarer Meldung abgelehnt.
- Rückgabe: `{uid, identifier, name, mimeType, size, storage}`.

### Änderungen

#### Geänderte/Neue Dateien

- **[Classes/MCP/Tool/File/UploadFileTool.php](Classes/MCP/Tool/File/UploadFileTool.php)** (neu)
  - Extends `AbstractTool` oder eine neue `AbstractFileTool`-Basis (zur Diskussion). **Empfehlung**: direkt `AbstractTool`, da der File-Tool kein Record-Tool ist und **explizit keinen Workspace-Kontext initialisiert** — Upload geht live.
  - Nutzt `StorageRepository::findByUid()` / `findByCombinedIdentifier()` bzw. `getDefaultStorage()` zur Storage-Auswahl.
  - Nutzt `$storage->getFolder($path)` für den Ordner. Wenn Ordner nicht existiert: entweder Fehler oder optionales Anlegen — **Empfehlung**: Fehler in PR 3; Ordner-Anlegen ist eigenes Feature.
  - Upload selbst: `$folder->addFile($localTempPath, $filename, DuplicationBehavior::RENAME)` (nimmt Pfad zu lokaler Datei) oder einen temporären File-Handle aus `tempnam()`. **Nicht** `addUploadedFile` — das erwartet `$_FILES`-Struktur, die in MCP-Kontext nicht da ist.
  - Permission-Check: `$GLOBALS['BE_USER']->hasFileActionPermission('write', $folder->getStorage())` und Filemount-Check via `$GLOBALS['BE_USER']->getFileMountRecords()`.
  - Metadaten: nach erfolgreichem Upload via `MetaDataAspect` oder direkt auf dem File-Objekt (`$file->updateProperties(['alternative' => ..., 'title' => ...])`). Schreibt in `sys_file_metadata` — auch live, da `sys_file_metadata` ebenfalls nicht workspace-capable ist (siehe FAL.md).
  - **Sichert explizit ab, dass der Tool-Ausführende NICHT in einen Workspace-Context wechselt**, damit DataHandler beim Metadata-Schreiben live-schreibt. Die `initialize()`-Override lässt `AbstractRecordTool::initialize()`-Behavior weg.

- **[Classes/Service/FileUploadService.php](Classes/Service/FileUploadService.php)** (neu, optional)
  - Kapselt die eigentliche Upload-Logik (MIME-Check, Size-Check, Folder-Resolution, `addFile`-Call, Metadata-Write), damit das Tool selbst schmal bleibt und testbar ist. **Empfehlung**: ja, analog zu `WorkspaceContextService`.

- **[Configuration/Services.yaml](Configuration/Services.yaml)** (oder Äquivalent, falls die Extension Services Autowired konfiguriert)
  - `UploadFileTool` als MCP-Tool registrieren (wie andere Tools). Muss beim Implementieren geprüft werden — wahrscheinlich ist das schon auto-discovered.

- **[ext_conf_template.txt](ext_conf_template.txt)** oder Extension-Konfig (falls vorhanden)
  - Neue Einstellungen: `fileUpload.mimeWhitelist`, `fileUpload.maxSizeBytes`, `fileUpload.defaultStorageUid`, `fileUpload.defaultFolder`. **Empfehlung**: sinnvolle Defaults ohne Config-Tab zuerst; Config-Tab erst bei Bedarf.

#### Neue PHPUnit-Tests

- **[Tests/Functional/MCP/Tool/FileUploadToolTest.php](Tests/Functional/MCP/Tool/FileUploadToolTest.php)** (neu)
  - Test-Setup: temporäres Storage auf `Tests/Functional/Fixtures/fileadmin/`-Verzeichnis. Konkretes Setup-Pattern muss beim Implementieren definiert werden — ggf. neue Abstract-Basis `AbstractFileUploadTest`.
  - `testUploadValidImageCreatesSysFile`: base64-PNG uploaden. Assert: Datei liegt physisch im Storage, `sys_file`-Record existiert, Rückgabe-UID stimmt.
  - `testUploadReturnsLiveUidNotWorkspaceUid`: In einem aktiven MCP-Workspace uploaden; verifizieren dass `sys_file`-Record mit `t3ver_wsid=0` in der DB landet.
  - `testUploadRejectsDisallowedMime`: `.exe` oder anderes mit nicht-whitelisted MIME-Type uploaden → Fehler.
  - `testUploadEnforcesSizeLimit`: Datei > Limit → Fehler mit klarer Meldung.
  - `testUploadRespectsDuplicationBehavior`: Zweimal denselben Filename uploaden → zweiter kriegt `_01`-Suffix (RENAME-Default).
  - `testUploadWritesMetadataIfProvided`: Mit `alternative` und `title` → `sys_file_metadata` hat die Werte.
  - `testUploadRejectsOnMissingFilemount`: BE-User ohne Filemount auf Ziel-Storage → Fehler mit klarer Meldung.
  - `testUploadDoesNotCreateSysFileReference`: Nach Upload ist KEINE sys_file_reference angelegt (Link ist PR-2-Job).
  - `testUploadFollowedByLinkingRoundtrip`: Upload → dann `WriteTable` auf tt_content mit `image: [{file: <returned uid>}]` → volle Roundtrip funktioniert, Referenz im Workspace.

#### Doku-Updates

- **[Documentation/Architecture/FAL.md](Documentation/Architecture/FAL.md)**: Abschnitt „Upload behavior" ergänzen — konkret welches Tool, welche APIs, welche Defaults, welche Grenzen.
- **[TECHNICAL_OVERVIEW.md](TECHNICAL_OVERVIEW.md)**: 
  - Available Tools-Liste: `UploadFile` hinzufügen unter einer neuen Kategorie „File Management".
  - „What's Not Yet Implemented → Image/File Handling" entfernen oder auf „Workspace-less metadata editing for sys_file" reduzieren.
- **[README.md](README.md)**: Fileadmin-Row-Status auf „✅ Ready" setzen.
- **[CLAUDE.md](CLAUDE.md)**: Keine Regel-Änderung — die FAL-Ausnahme ist in PR 0 (dem Doc-PR) bereits verankert.

#### Risiken / offene Fragen

1. **Base64-Payload-Größe**: MCP-Protocol-Limits für einzelne Messages. Große Dateien (>10MB) könnten durch HTTP-Body-Limits oder stdio-Chunk-Probleme brechen. **Empfehlung**: hartes Limit default 20MB, dokumentiert, und erster Smoke-Test bei ~5MB, dann Stretching.
2. **Temporäre Datei-Schreibstelle**: `addFile()` braucht einen Pfad zu einer lokalen Datei. MCP bekommt die Bytes aus dem Base64. `GeneralUtility::tempnam('mcp_upload_')` + `unlink` nach Ende ist der Standard-Weg. Cleanup bei Fehler muss sicher sein (try/finally).
3. **Filemount-Semantik**: TYPO3 unterscheidet zwischen „File Mount Point"-Config (im BE-User) und Storage-Permissions. Admins bypassen beides. **Empfehlung**: für PR 3 nur Standard-Core-Checks nutzen, nicht eigene Logik einziehen. Wenn Core ein „nein" sagt, gibt das Tool exakt die Core-Fehlermeldung weiter.
4. **MIME-Detection**: Auf den tatsächlichen Content prüfen, nicht nur auf die Extension (Content-Sniffing via `finfo`). Sonst kann jemand eine `.exe` als `.jpg` umbenennen und sie wird hochgeladen.
5. **Orphan-Files**: Upload erfolgt, Client baut keine Referenz — File bleibt orphan in `fileadmin/` + `sys_file`. FAL.md hat das bereits als out-of-scope markiert. **Empfehlung**: keine Lösung in PR 3; wenn Bedarf kommt, eigener Cleanup-Scheduler-Task.
6. **Storage-Auswahl-UX**: Wenn der User Admin ist, sieht er alle Storages — wenn nicht, nur seine Mounts. Die LLM soll verstehen, welches Storage gültig ist. **Empfehlung**: `ListStorages`-Tool wäre sinnvoll — kann in PR 3 mitkommen oder als PR 3b nachgezogen werden.
7. **`addFile` vs. `addFileAndFolder`**: API-Namen checken beim Implementieren. Es gibt auch `$storage->addFile()` als Shortcut.
8. **Metadata für Bilder (Dimensions)**: TYPO3 Core extrahiert bei Bildern automatisch `width`/`height` ins `sys_file_metadata` — nichts eigenes zu tun. Nur für Sonderfälle (Preview-Generierung) ggf. nachziehen.
9. **`sys_file_metadata`**: Die FAL.md listet das als „Live". Ist das richtig? `sys_file_metadata` ist im TYPO3-Core ab v10 **workspace-capable** gesetzt — prüfen! Falls ja, muss FAL.md korrigiert werden UND Metadata-Writes laufen doch durch den Workspace. Konkrete Verifikation via `$GLOBALS['TCA']['sys_file_metadata']['ctrl']['versioningWS']` im Running-System — **muss vor Merge von PR 3 verifiziert werden**. Hat Implikationen für FAL.md.

---

## Reihenfolge & Abhängigkeiten

```
PR 1 (Read)  →  PR 2 (Link)  →  PR 3 (Upload)
     ↑               ↑                ↑
     │               │                │
   Eigenständig   Braucht          Braucht
   mergebar       PR 1 (Read       PR 1+2 nur für
                  für Roundtrip-   Roundtrip-Test
                  Tests)
```

Jeder PR liefert einen nutzbaren Inkrement:
- Nach PR 1 kann der Client FAL-Content **ansehen**.
- Nach PR 2 kann er Bestandsdateien **verlinken** (z.B. „nimm diese Datei und setz sie als Hero auf /about").
- Nach PR 3 kann er Dateien **hochladen und verlinken** (End-to-End).

## Verifikation vor Implementation-Start

- `composer test` in grünem Zustand: ✅ (aktueller Branch).
- Verifiziert: `validateTableAccess()` im Ist-Zustand blockiert `sys_file`-Reads durch Default-`requireWorkspaceCapability=true`.
- Verifiziert: `processEmbeddedInlineRelations()` hat bereits alle Mechaniken, die PR 2 braucht.
- Verifiziert: Keine FAL-API-Benutzung im bestehenden Code → PR 3 beginnt bei Null.
- Offen vor PR 3: `sys_file_metadata` Workspace-Fähigkeit verifizieren (siehe Risiko #9).

## Was der User als Nächstes okayen muss

Wenn der 3-PR-Schnitt und die Akzeptanzkriterien passen, fangen wir mit PR 1 an. Konkret zuerst:
1. Fixtures `sys_file_reference.csv` + Erweiterung `sys_file.csv`, `tt_content.csv`.
2. `TableAccessService`-Anpassung für Read-Zugriff auf nicht-workspace-capable Tabellen.
3. `sys_file_reference` aus der `$restrictedTables`-Liste.
4. FAL-Expander in `ReadTableTool`.
5. `FalReadTest.php`.
6. Doku-Nachtrag in FAL.md + InlineRelations.md.
