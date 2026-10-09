# Medienreaktor.NeosApi.Mcp — development notes

Human docs: `README.md`.

- Tools are derived from the Neos API's `openapi.yaml` (`ApiOperationToolCatalog`). Do not hand-write tool schemas or descriptions here; fix the OpenAPI document in `Medienreaktor.NeosApi` instead.
- A tool call never reimplements API logic: `InProcessApiClient` routes and dispatches the REST action on behalf of the caller's bearer token.
- After changing code in the catalog: `./flow cache:flushone MedienreaktorNeosApiMcp_Tools` (the cache key covers the OpenAPI document and the tool settings, not the code).
- The endpoint stays off by default (`enabled: false`). Do not enable it in this package's `Configuration/`, not even for Development.
- Errors: API errors go back to the model as tool errors; unexpected exceptions are logged once via the ThrowableStorage in `McpController::callTool()` and the client gets the log reference only.
- Smoke test against a DDEV instance: a confidential `client_credentials` client (`./flow neosapi:createclient … --grant-types client_credentials --confidential`) mapped to an account via `Medienreaktor.NeosApi.oauth.clientCredentialsAccounts`, then JSON-RPC `initialize` / `tools/list` / `tools/call` against `/mcp`.
