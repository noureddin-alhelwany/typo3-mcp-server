# Preview Links — Workspace Drafts Without Backend Login

## Context

An MCP client edits content in a workspace. Before publishing, someone outside the editorial team — a client, a colleague, a proofreader — should see the draft. TYPO3's workspaces extension already solves this: `sys_preview` holds short-lived tokens that unlock workspace-scoped frontend rendering for anyone with the URL. The `GeneratePreviewLink` tool exposes that mechanism so an MCP client can hand a reviewer a link and move on.

## Policy

| Layer | Where written |
|-------|---------------|
| `sys_preview` row (short-lived keyword) | Live |
| Workspace content the token unlocks | Still in the workspace |

`sys_preview` is not a content table — it's a short-lived access token. Writing it live is the only way the frontend middleware can pick it up before the workspace is published. Nothing the reviewer sees gets promoted to live; they see the workspace overlay that's already there.

## Read / Write Shape

`GeneratePreviewLink(pageUid, language?)`:

Request:
```json
{
  "pageUid": 21,
  "language": "en"
}
```

Response:
```json
{
  "url":          "https://example.com/about/?ADMCMD_prev=<keyword>&cHash=<…>",
  "pageUid":      21,
  "pagePath":     "/about/",
  "language":     "en",
  "workspaceUid": 3,
  "expiresAt":    "2026-04-24T14:00:00+00:00"
}
```

`url` is always absolute (scheme + host from the site configuration). `expiresAt` is read back from `sys_preview.endtime` after the insert, so it reflects the real TTL — not our assumption.

`language` accepts either a configured ISO code (`"en"`, `"de"`, …) or `"default"` (the site's default language). Unknown ISO codes fail fast with a clear message.

## TTL Resolution

TYPO3 core resolves the preview-link TTL in this order, and we inherit it without override:

1. `sys_workspace.previewlink_lifetime` — workspace-level override (hours).
2. `options.workspaces.previewLinkTTLHours` in TSConfig — installation-level.
3. Default: 48 hours.

We deliberately don't expose a TTL parameter on the MCP tool. Installations that want a shorter or longer lifetime configure it once; the tool respects whatever is set.

## Session Caching Behaviour

TYPO3 14's `PreviewUriBuilder` caches the keyword per `(workspace, user)` in the runtime cache. Two consecutive calls from the same backend-user session return the **same** token and URL. That's intentional — one session, one stable preview URL. Tests verify this behaviour rather than fight it. The token still expires at the `endtime` stored on the sys_preview row.

## Typical Workflow

The three-step loop the tool is built for:

1. **Edit** via `WriteTable` — changes go into the workspace.
2. **Share** via `GeneratePreviewLink` — hand the URL to a reviewer by email / chat.
3. **Publish** — the reviewer replies with feedback; the publisher opens the TYPO3 backend's Workspaces module and publishes. MCP does not expose the publish step itself (see [TECHNICAL_OVERVIEW.md](../../TECHNICAL_OVERVIEW.md) → Direct Workspace Management).

## Possible Future Extensions

Out of scope for the initial implementation, listed so the boundary is visible:

- Configurable TTL per call.
- Bulk preview for a set of pages.
- Listing / revoking existing preview tokens.
- Side-by-side language comparison links.
- Explicit workspace selection (currently always the caller's active workspace).
- QR-code rendering of the URL.

Each of these is cheap to add later; none is needed to get the core use-case working.

## Related

- [WorkspaceTransparency.md](WorkspaceTransparency.md) — how workspace context is hidden from the client for content reads.
- [FAL.md](FAL.md) — parallel exception pattern ("infrastructure lives, content stays in the workspace").
