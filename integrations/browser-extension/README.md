# HumixAi browser companion source

An unpacked Manifest V3 popup for current Chrome and Edge. It reads text only when you click **Use selected text**, lets you review/edit it, then submits Detect, Humanize or Analyze & improve to your own HumixAi API. It never rewrites a webpage automatically. This folder has not been installed or submitted to a store.

See the [OpenAPI reference](../../docs/openapi.json) for the app endpoints, token scopes and result definitions.

To try the source manually:

1. Start your HumixAi app and its queue worker as documented in the main project.
2. Create a scoped workspace API token. Include `jobs` plus `detect` for detection, `humanize` for rewriting, or both action scopes for the combined workflow.
3. Open Chrome's Extensions page or Edge's Extensions page. Enable Developer mode, choose **Load unpacked**, then select this `integrations/browser-extension` folder.
4. Open the extension popup, enter your app URL and **HumixAi workspace token**, then click **Allow app host & connect**. Remote apps require HTTPS; local development accepts HTTP on `localhost` or `127.0.0.1`. Grant the host access prompt for that specific app.
5. Select page text, open the popup and click the selection button, or paste text yourself. Review it, acknowledge processing and choose the action.

The app URL/token are kept only in `chrome.storage.session`: browser memory cleared on browser restart, extension reload/update/disable or **Clear connection**. They are never saved in sync/local storage or sent to the selected webpage. Drafts/results are not persisted by this popup. Only the job ID/action is kept in session memory for **Resume last job**. Your HumixAi server's own retention and provider policies still apply.

The manifest declares optional HTTPS/localhost host patterns so a user can choose an app; no host access is granted at install time. Runtime permission requests name only the configured app host. `activeTab`/`scripting` read the current selection after a user action. Browser internal pages, password fields, protected frames and some editors cannot be read; paste text instead. There are no always-on content scripts or remote scripts.

The app enforces token scopes, authentication, provider availability and quota. The popup never fabricates scores when an app/vendor is unavailable. Polling is bounded to one request every three seconds for up to 90 attempts. Closing the popup or clicking **Stop waiting** stops waiting, while an already submitted server job may continue. Reopen and resume, or inspect the job in your workspace.

Results stay editable only in the main app; this popup offers a copy button. Read revisions and check facts, citations and meaning before using them. No detector result is guaranteed.

Checks without installing the extension or calling an API:

```powershell
node --test integrations/browser-extension/settings.test.mjs
node --check integrations/browser-extension/popup.mjs
```

Browser references: [runtime optional host permissions](https://developer.chrome.com/docs/extensions/develop/concepts/declare-permissions), [session storage behavior](https://developer.chrome.com/docs/extensions/reference/api/storage).
