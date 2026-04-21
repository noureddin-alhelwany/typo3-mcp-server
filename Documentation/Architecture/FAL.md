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

## Current Implementation Status

- **Read**: Implemented. `sys_file` and `sys_file_reference` are readable via `ReadTable`, and `GetTableSchema` exposes both. FAL inline fields are automatically expanded with the embedded `file` block.
- **Link (write `sys_file_reference`)**: Planned. References will go through the workspace with an ergonomic shortcut on the parent inline field.
- **Upload**: Planned. Uploads will write `sys_file` and the physical file directly to live (the exception this document describes).

## Related

- [WorkspaceTransparency.md](WorkspaceTransparency.md) — how workspace overlays are hidden from the MCP client
- [InlineRelations.md](InlineRelations.md) — `sys_file_reference` in the context of general inline-relation handling
