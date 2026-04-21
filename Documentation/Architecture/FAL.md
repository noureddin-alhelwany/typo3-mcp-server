# FAL (File Abstraction Layer) — Workspace Boundary

## Context

The MCP server's default rule is: every write goes through a TYPO3 workspace. This keeps the live site safe until an editor publishes.

FAL breaks that rule on purpose, in one narrow place. This document explains where the boundary sits and why the safety net still holds.

## The Constraint

In TYPO3, the `sys_file` table has no `versioningWS` in its TCA — it is **not workspace-capable**. A file upload therefore cannot be "draft in a workspace"; the file and its `sys_file` row must go live immediately.

By contrast, `sys_file_reference` **is** workspace-capable (standard TYPO3 configuration). The linking layer — which is what actually connects files to pages, content elements, and records — supports drafts like any other content record.

## Policy

| Layer                                              | Workspace-capable? | Where MCP writes |
|----------------------------------------------------|--------------------|------------------|
| Physical file (storage)                            | n/a                | Live             |
| `sys_file` record                                  | No                 | Live             |
| `sys_file_metadata`                                | No (core)          | Live             |
| `sys_file_reference`                               | Yes                | Workspace        |
| Any `tt_content` / record that references a file  | Yes                | Workspace        |

**Rule of thumb**: the file exists, the link is pending.

## Why This Is Safe

- An uploaded file in `fileadmin/` is invisible to site visitors until something references it.
- Nothing references it until the workspace is published — because every reference lives in `sys_file_reference` (or equivalent relation), which stays in the workspace.
- Publishing the workspace is still the single moment a visitor sees a change.
- Editors reviewing a workspace see the new references and can trace them back to the uploaded file.

## Consequences for Tooling

- A future upload tool writes to live storage + `sys_file`, then returns a live `sys_file.uid`.
- Tools that link files to content (create / update `sys_file_reference`, or update inline file fields on records) operate on the current workspace as normal.
- Orphan-file cleanup (uploaded but never linked) is out of scope for now; see TYPO3 scheduler / file abstraction cleanup tasks.

## Read Shape

`ReadTable` returns FAL inline fields as an array of references, with the target `sys_file` embedded as `file`:

```json
{
  "uid": 120,
  "CType": "image",
  "image": [
    {
      "uid": 500,
      "uid_local": 1,
      "tablenames": "tt_content",
      "fieldname": "image",
      "alternative": "Hero alt text",
      "title": "Hero image title",
      "crop": {},
      "link": "",
      "file": {
        "uid": 1,
        "identifier": "/test.jpg",
        "name": "test.jpg",
        "mime_type": "image/jpeg",
        "extension": "jpg",
        "size": 123456
      }
    }
  ]
}
```

The reference `uid` is always the live UID — workspace overlays swap `t3ver_oid → uid` before the record is serialised, so the client never sees workspace IDs. `sys_file` is not workspace-capable and is therefore loaded directly from live.

## Linking Shortcut

`WriteTable` accepts an ergonomic shortcut for attaching existing files to a parent record. Instead of forcing the client to know the full `sys_file_reference` shape (`uid_local`, `tablenames`, `fieldname`, `uid_foreign`), they simply list the target files by UID on the parent's inline field:

```json
{
  "action": "create",
  "table": "tt_content",
  "pid": 1,
  "data": {
    "CType": "image",
    "header": "Hero",
    "image": [
      {
        "file": 42,
        "alternative": "Hero alt",
        "title": "Main hero",
        "link": "t3://page?uid=5"
      }
    ]
  }
}
```

Under the hood MCP translates each entry into a `sys_file_reference` row:

| Client field           | `sys_file_reference` column | Notes                                                                |
|------------------------|-----------------------------|----------------------------------------------------------------------|
| `file`                 | `uid_local`                 | Must be a positive integer, must exist in `sys_file`.                |
| (implicit)             | `tablenames` + `fieldname`  | Auto-filled from the parent table and field name.                    |
| (implicit)             | `uid_foreign`               | Set to the parent UID after DataHandler resolves `NEW…` placeholders.|
| `alternative`, `title`, `description`, `link`, `crop`, `autoplay`, … | same name | Passed through verbatim — extension-specific columns are accepted.   |

Updates with a stable `uid` on each reference (as returned by `ReadTable`) preserve the live UID through reorders and content edits; entries without a `uid` are treated as new and the existing references not present in the input are removed.

References always run through the current workspace.

## Workspace Versioning for Live Records

Updates that target a record with no pre-existing workspace version — whether on the parent (`tt_content`) or directly on a `sys_file_reference` — run through an explicit two-step DataHandler sequence:

1. `cmdmap: {table: {liveUid: {version: {action: "new"}}}}` creates a workspace version of the record. TYPO3 auto-versionizes inline children (sys_file_reference rows reachable via IRRE) at the same time.
2. `datamap: {table: {workspaceUid: {…fields…}}}` applies the edit on the fresh workspace version.

Why explicit, not relying on DataHandler's auto-version on `process_datamap`: in practice the auto-versioning path silently dropped updates on live records under TYPO3 14 without ever populating `errorLog`, which meant writes would report success while nothing changed. The cmdmap path always produces a stable workspace UID we can target and surface failures through.

Client-visible UIDs remain live UIDs throughout. The workspace → live mapping is hidden by `processRecord` (swaps `t3ver_oid → uid`) and by `BackendUtility::workspaceOL` applied during reads (merges workspace field values into the live row so the client sees pending edits).

## Direct Updates on `sys_file_reference`

Top-level `WriteTable action=update table=sys_file_reference` is permitted for metadata fields — `alternative`, `title`, `description`, `link`, `crop`, `autoplay`, and any extension-added columns. This is the efficient path for bulk A11y rollouts that set alt-text on many existing references without traversing every parent: a rollout script iterates directly over reference UIDs from `ReadTable sys_file_reference` and issues one update per row, instead of a O(n)-lookup of the parent and its FAL field for every entry.

Structural fields are rejected:

- `uid_local`, `uid_foreign`
- `tablenames`, `fieldname`
- `sorting_foreign`, `sys_language_uid`
- `l10n_parent`, `l10n_source`
- any `t3ver_*`

Changing those would corrupt the parent ↔ reference link. If you need a different file on the same slot, or need to move a reference between parents, use the parent's FAL shortcut (`image: [{uid: <ref-uid>, file: <new-sys_file-uid>}]`) where those fields flow automatically from the parent's TCA.

`pid` is also rejected on update actions (for any table, not just sys_file_reference) — pid can only be set on create.

Non-existent UIDs fail fast with a clear error that names the offending table and UID; the call never reaches DataHandler. Live rows get a workspace version created on the first update (see *Workspace Versioning for Live Records* above); subsequent updates on the same reference reuse the existing workspace version rather than creating a second one.

## Debug Output

`WriteTable` accepts a `debug: true` input parameter. When set, the response includes a `_debug` block with DataHandler internals per phase (`datamap`, `cmdmap`, `errorLog`, `substNEWwithIDs`, `copyMappingArray`). Use it when a write silently succeeds-but-doesn't, or to trace versioning decisions. Do not leave it on in production calls — the block can be large.

When the write throws an exception that would otherwise be caught and reported as a generic `"Database operation failed"` / `"Invalid input provided"` / etc., `debug=true` surfaces the real exception class, message, file, line, and a trimmed stack trace inside the `_debug.exception` entry, and the user-facing error text becomes `<ExceptionClass>: <message>`. Without `debug=true` the generic message is preserved so DBAL query strings and paths don't leak unintentionally.

## Current Implementation Status

- **Read**: Implemented. `sys_file` and `sys_file_reference` are readable via `ReadTable`, and `GetTableSchema` exposes both. FAL inline fields are automatically expanded with the embedded `file` block. Workspace modifications are overlaid into the response.
- **Link (write `sys_file_reference`)**: Implemented. Both via the parent FAL shortcut and via direct `WriteTable` on `sys_file_reference` (metadata fields only). All writes go through the current workspace.
- **Upload**: Planned. Uploads will write `sys_file` and the physical file directly to live (the exception this document describes).

## Related

- [WorkspaceTransparency.md](WorkspaceTransparency.md) — how workspace overlays are hidden from the MCP client
- [InlineRelations.md](InlineRelations.md) — `sys_file_reference` in the context of general inline-relation handling
