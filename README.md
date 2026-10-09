# Medienreaktor.NeosApi.Mcp

An [MCP](https://modelcontextprotocol.io) server for Neos 9, built on [Medienreaktor.NeosApi](https://github.com/medienreaktor/Medienreaktor.NeosApi). Claude and other MCP clients work with your content as a signed-in Neos user: read pages, content and node types, write through the content repository's commands into a workspace, review the changes and publish them.

- **One endpoint**, `/mcp` (Streamable HTTP), next to the Neos API.
- **The Neos API's OAuth 2.1** for sign-in: the client discovers the authorization server, the editor logs in to Neos and approves the client. No extra accounts, no API keys.
- **Every tool is one API operation.** Name, description and input schema come from the API's OpenAPI document, so the tools stay in sync with the API.
- **Same security as the API.** A tool call is dispatched in-process to the REST action, on behalf of the caller's token: the API's policy, the token's scopes and the content repository's workspace and node permissions all apply unchanged.

Requires Neos `^9.1`, PHP `^8.2` and `medienreaktor/neos-api` `^1.14`.

## Enabling it

The endpoint is **off by default**. `/mcp` and its discovery document answer 404 until a distribution switches them on:

```yaml
Medienreaktor:
  NeosApi:
    Mcp:
      enabled: true
```

MCP clients such as Claude register themselves via OAuth dynamic client registration. The Neos API ships it disabled outside the Development context, so enable it as well where clients should connect (see the Neos API README for the trade-off):

```yaml
Medienreaktor:
  NeosApi:
    oauth:
      dynamicClientRegistration:
        enabled: true
```

If the site sits behind HTTP basic auth, exclude `/mcp` and `/.well-known/oauth-protected-resource/mcp` from it, like `/api`, `/oauth` and `/.well-known` — the client sends a bearer token in the `Authorization` header.

## Connecting a client

- **Claude Code:** `claude mcp add --transport http neos https://example.com/mcp`, then `/mcp` in Claude Code to sign in.
- **Claude (claude.ai / desktop):** Settings → Connectors → Add custom connector, URL `https://example.com/mcp`.

Sign-in opens the Neos login and the consent screen of the Neos API. The scopes the editor approves narrow what the client may do: `neos.read`, `neos.write` (content commands), `neos.publish` (publish and discard), `neos.media`.

## Tools

| Tool | API operation | |
|---|---|---|
| `get_me` | `getMe` | read |
| `list_sites` | `listSites` | read |
| `list_dimensions` | `listDimensions` | read |
| `list_workspaces` | `listWorkspaces` | read |
| `get_workspace` | `getWorkspace` | read |
| `list_workspace_changes` | `listWorkspaceChanges` | read |
| `list_node_types` | `listNodeTypes` | read |
| `get_node_type` | `getNodeType` | read |
| `get_node` | `getNode` | read |
| `get_node_relation` | `getNodeRelation` | read |
| `render_node` | `renderNode` | read |
| `list_assets` | `listAssets` | read |
| `get_asset` | `getAsset` | read |
| `execute_commands` | `executeCommandBatch` | write |
| `publish_workspace` | `publishWorkspace` | publish |
| `discard_workspace` | `discardWorkspace` | publish |

The list is configuration (`Medienreaktor.NeosApi.Mcp.tools`): tool name ⇒ OpenAPI `operation`, plus `readOnly` / `destructive` for the MCP tool annotations. Any API operation with path/query parameters and an optional JSON body can be added; set an entry to `null` to remove a tool.

`Medienreaktor.NeosApi.Mcp.instructions` is sent to the client on connect and tells the model how to work with the tools (find the personal workspace, read node types before writing, write into the workspace, publish only when asked). Distributions can extend it with site-specific hints.

## Errors

- An API error (4xx) goes back to the model as a tool error carrying the API's error body, so the model sees what a REST client would and can correct its call. Arguments that violate the input schema are rejected before the API is called.
- An unexpected exception is logged once with the full trace (Flow exception log, plus an error entry with tool and operation in the system log). The client gets a tool error with the log reference only.

## Caches

- `MedienreaktorNeosApiMcp_Tools` — the tools derived from the OpenAPI document, keyed by the document and the tool settings. Flushed with `flow:cache:flush` on deployment.
- `MedienreaktorNeosApiMcp_Sessions` — protocol state of MCP sessions (no credentials, every request authenticates with its own token), one hour. Setups with several web nodes need a shared backend here.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
