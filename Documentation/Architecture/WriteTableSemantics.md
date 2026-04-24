# WriteTable — Semantics, Defaults, Guarantees

Notes about the non-obvious behaviour of the `WriteTable` tool. Targeted at contributors and at operators who want to understand why a given response looks the way it does.

## Error-Status Invariant

`isError` in the response reflects **only** whether the DB write was committed. Nothing else can flip it.

- DataHandler `errorLog` non-empty → `isError: true`, no DB change.
- DataHandler `errorLog` empty AND the record was written → `isError: false`, DB has the effect.

Post-processing failures — read-backs that translate a workspace UID to a live UID, parent-pid lookups for follow-up children, the final response assembly — cannot turn a committed write into an error. They surface as warnings:

```json
{
  "action": "create",
  "table": "tt_content",
  "uid": 42,
  "_warnings": [
    "Post-processing step 'resolve-live-uid-for-response' failed (ConnectionLost): ..."
  ]
}
```

`_warnings` is omitted entirely on a clean response. This is the contract that [WriteTableErrorReportingTest](../../Tests/Functional/MCP/Tool/WriteTableErrorReportingTest.php) pins.

DBAL-level exceptions that do surface as errors now carry their concrete class + message (`"Database error (DriverException): ..."`) instead of the former generic `"Database operation failed"`. The `debug=true` option still adds the full `_debug` block on top; it's not a replacement for a useful default message.

## Synthetic Backend Request

MCP tools run outside HTTP, so `$GLOBALS['TYPO3_REQUEST']` is unset by default. DataHandler's internal parent-page resolution and any DataHandler hook that calls `FormDataCompiler` (b13/container, content_defender, …) both expect a request with `applicationType = REQUESTTYPE_BE` and a `site` attribute.

`WriteTableTool` injects a synthetic request around the tool body and restores the previous global unconditionally via `finally`. The site is resolved best-effort:

1. `SiteFinder::getSiteByPageId($pid)` — rootline traversal, works even for workspace-new pages that have no live sibling on the same level.
2. Fall back to the first configured site.
3. If no site exists at all, the request is attached without a `site` attribute; hooks that need one will fail clearly.

The previous global is restored via `finally` so subsequent MCP calls in the same long-lived process don't inherit our request.

## `position` — Defaults and Semantics

- `position` defaults to `"bottom"` (last in the same `(pid, colPos)`).
- Implementation uses TYPO3's native `pid = -lastRecordUid` convention. We look up the last record in the target `(pid, colPos)` by `sorting DESC`, then hand DataHandler a negative pid; DataHandler's own `getSortNumber()` places the new row directly after.
- For tables with a `colPos` column (chiefly `tt_content`) the last-record lookup is scoped to `colPos`. Without the scope, a new row for column A would otherwise anchor to the globally-last record on the page even if that record lived in column B.
- Empty `(pid, colPos)` combinations fall back to DataHandler's first-record logic (positive pid, no sort override).
- `position = "after:<UID>"` / `"before:<UID>"` continues to use an explicit move cmdmap after the create.

## `colPos` — Default 0

When omitted on `tt_content`, `colPos` defaults to `0` ("Normal" column). This is just DataHandler's normal behaviour; nothing MCP-specific.

## Container Children (`tx_container_parent` + `colPos`)

For content placed inside a `b13/container` element, `tx_container_parent` alone is **not** enough — `colPos` must also point at one of the container's grid slots (e.g. `101` for a single-slot accordion). With `colPos=0`, the child is structurally the right CE (it has the right parent reference) but renders as a top-level block because column 0 is outside the container.

WriteTable closes that gap on `create`:

- Gate: `table = tt_content`, `tx_container_parent > 0`, and the caller did **not** set `colPos` explicitly.
- Looks up the parent's `CType`, then reads `$GLOBALS['TCA']['tt_content']['containerConfiguration'][<CType>]['grid']` (populated by `b13/container`'s `Registry::configureContainer`).
- Sets `colPos` to the grid's first slot.

Caller-provided `colPos` always wins — useful when the container has multiple slots (e.g. a two-column container with slots 101 and 102) and the caller wants a specific one. If the parent isn't a container (no `containerConfiguration` entry for its CType), the auto-assignment silently does nothing and `colPos` falls through to the TCA default (`0`). No error is raised for that case — DataHandler or the caller is better placed to explain what went wrong.

MCP itself has no runtime dependency on `b13/container`; the logic reads TCA directly, so it works wherever the container extension has populated its config.

Pinned by [WriteTableContainerChildTest](../../Tests/Functional/MCP/Tool/WriteTableContainerChildTest.php).

## FAL Parent Counter Sync

Inline fields like `tt_content.image`, `tt_content.assets`, `pages.media` store an integer count of their related `sys_file_reference` rows on the parent row. DataHandler maintains that counter during a normal BE save; the MCP two-step flow can't, because the inline field is extracted from the parent's datamap before DataHandler runs. WriteTable syncs the counter manually after child processing completes on create and update. See [FAL.md — Parent counter fields](FAL.md) for the mechanics.

## FAL Linking Shortcut — `crop` Default

Writing a `sys_file_reference` via the FAL shortcut (e.g. `image: [{file: N, alternative: "..."}]`) fills `crop` with `'{}'` when the client did not provide one.

The read-shape documented in [FAL.md](FAL.md) also shows `"crop": {}`. `CropVariantCollection::create('{}')` falls back to the viewport defaults from TCA, which renders the full image at every viewport. This avoids the "must open+save in the BE before the FE renders" class of bugs without requiring MCP to synthesise the concrete cropVariants JSON from TCA on every write.

Explicit client-provided crop still passes through verbatim.

## PageTSconfig TCAdefaults on Create

The BE form applies `TCAdefaults.<table>.<field>` (and the type-specific
variant `TCAdefaults.<table>.<field>.types.<recordType>`) from PageTSconfig
when an editor opens the "new record" form; the submitted form then carries
those values into DataHandler. Programmatic creates via WriteTable skip the
form layer, so without intervention they produce rows where those defaults
are missing.

WriteTable runs an equivalent pass before handing data to DataHandler:

- Reads PageTSconfig for the target pid via `BackendUtility::getPagesTSconfig`.
- Applies both the field-level and type-specific variants, with type-specific
  winning when the record's type matches.
- Caller-provided values always win (`array_key_exists` check, so even an
  explicit `null` or `""` is preserved).

The practical effect: themes that condition Fluid rendering on a field like
`color` render correctly from the first MCP create, without requiring an
editor to "open and save" the record in the backend. See
[PR-7 plan](../../.claude/plans/pr-7-workspace-preview-render-gap.md) for the
original investigation.

## Workspace-Preview Render Invariant

The pipeline from MCP-create to frontend-preview-render must work without a
BE round-trip. `WorkspacePreviewRenderTest` is the end-to-end guard that
pins this: create page → create tt_content → render preview → HTML contains
the CE's header. Any write-path change that risks breaking this invariant
should run the test.

When investigating related regressions, `ReadTable` accepts a diagnostic
`includeWorkspaceFields: true` parameter that surfaces the workspace and
localization metadata columns (`t3ver_*`, `l10n_state`, `l10n_source`,
`l10n_diffsource`, `l10n_parent`, `l18n_parent`) that are normally stripped
for transparency. Use it to verify DataHandler produced the expected
versioning shape without resorting to an out-of-band SQL session.

## `debug` Parameter

`debug: true` attaches a `_debug` block with DataHandler snapshots per phase (`datamap`, `cmdmap`, `errorLog`, `substNEWwithIDs`, `copyMappingArray`) and, on exceptions, the concrete exception class/message/file/line. The block can be large — do not leave it on in production calls. Warnings live in the standard `_warnings` key and are independent of debug mode.

## See Also

- [FAL.md](FAL.md) — FAL-specific write semantics (linking shortcut, upload, folder management).
- [Preview.md](Preview.md) — Preview-link workflow that closes the Edit → Review → Publish loop.
- [WorkspaceTransparency.md](WorkspaceTransparency.md) — how workspace UIDs are hidden from the client.
