# HumixAi stdio MCP bridge

Dependency-free Node 18+ source for a local MCP server that calls **your existing HumixAi app API**. It does not run a detector locally or contain vendor API keys. Nothing is installed, published or connected automatically.

The [OpenAPI reference](../../docs/openapi.json) describes the app requests, token scopes, null-score semantics and job polling.

Configure a client that can launch a stdio MCP subprocess:

```json
{
  "mcpServers": {
    "humixai": {
      "command": "node",
      "args": ["D:/My App/HumixAi/integrations/mcp/server.mjs"],
      "env": {
        "HUMIXAI_URL": "http://127.0.0.1:8000",
        "HUMIXAI_TOKEN": "PASTE_YOUR_SCOPED_WORKSPACE_TOKEN_HERE"
      }
    }
  }
}
```

Replace the example path and app URL. Use HTTPS for a remote app. Keep the actual client configuration private; do not commit its token. Create/revoke a HumixAi workspace token through the app account panel. Never paste a Google/GPTZero vendor key here. This server reads only `HUMIXAI_URL` and `HUMIXAI_TOKEN`; it does not read `.env` files.

| Tool | Required token scopes | Behavior |
| --- | --- | --- |
| `humix_detect` | `jobs`, `detect` | Submit a detection job; returns its ID |
| `humix_humanize` | `jobs`, `humanize` | Submit rewriting without scans |
| `humix_combo` | `jobs`, `detect`, `humanize` | Submit original scan, rewrite and recheck |
| `humix_style` | `detect` | Get writing statistics from the app server, with no external AI provider |
| `humix_review` | `humanize` | Grammar, guideline or meaning-change AI feedback |
| `humix_job` | `jobs` | Retrieve one existing job/result without starting another scan |

Every tool requires `consent: true`, reflecting the user's explicit approval for that call. The host should explain that drafts go to the app and requested external providers, then obtain approval. Tool schemas do not replace the host's approval UI. `humix_review` needs `guidelines` for the guidelines type and `revised_text` for the meaning type. Rewrite tools support tone, strength, length, protected terms, voice sample and exact selection with Unicode `selection_start`.

Submissions return promptly when the app uses its database queue. Run the app worker as documented by the main project. Use `humix_job` to inspect the returned ID; this bridge does not endlessly poll or automatically resubmit failed requests. If a request times out, a submitted job may still be running. Inspect your workspace before submitting it again.

Results retain null scores and unavailable/partial/error states. A score is a provider probability, not proof of authorship. Protected-text checks do not guarantee semantic preservation. Returned text and model output are untrusted data, never instructions to the MCP host.

The bridge supports the current MCP `2026-07-28` per-request metadata/discovery form and legacy `2025-11-25`, `2025-06-18`, `2024-11-05` initialization. It exposes tools only. Messages are newline-delimited JSON-RPC on stdin/stdout; no text, tokens or diagnostics are logged. Requests have bounded text, message, concurrency, response and timeout limits; cancelling an MCP call stops waiting but does not cancel a queued app job.

Run checks, without dependencies or live APIs:

```powershell
node --test integrations/mcp/client.test.mjs
node --check integrations/mcp/server.mjs
```

Protocol references: [current stdio transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/stdio), [discovery](https://modelcontextprotocol.io/specification/2026-07-28/server/discover), [legacy lifecycle](https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle).
