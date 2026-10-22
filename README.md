# Placecom MCP Connector (local_placecom_mcp)

Connect AI assistants that speak the [Model Context Protocol](https://modelcontextprotocol.io/) (MCP),
such as Gemini Enterprise or Claude, to Moodle. Each user signs in to Moodle and approves the assistant;
the assistant then works **as that user**, through a fixed list of reviewed Moodle functions, and sees
only what the user is allowed to see.

The plugin contains two parts:

- an **OAuth 2.0 / OpenID Connect authorisation server** (authorisation code grant with PKCE, refresh
  tokens, RS256 ID tokens, UserInfo, JWKS and discovery metadata);
- an **MCP server** (JSON-RPC 2.0 over HTTP POST, Streamable HTTP transport, stateless) that exposes
  25 Moodle web service functions as MCP tools.

It has no third-party dependencies.

## Requirements

- Moodle 4.5 to 5.2.
- PHP 8.1 or later with the OpenSSL extension.
- Site administration > General > Advanced features > **Enable web services** must be on.
- HTTPS in production. Assistants send bearer tokens on every request.

## Installation

1. Copy the plugin to `local/placecom_mcp` (on Moodle 5.1 and later: `public/local/placecom_mcp`), or
   install the ZIP through Site administration > Plugins > Install plugins.
2. Visit Site administration > Notifications to complete the installation.
3. Turn on **Enable web services** if it is not on yet.

During installation an RSA signing key is created for ID tokens. If OpenSSL cannot create the key (this
happens on some Windows servers without an OpenSSL configuration file), the installation still
completes; set `$CFG->opensslcnf` in `config.php` to the path of `openssl.cnf` and run
`php local/placecom_mcp/cli/keys.php --rotate`.

## Configuration

Site administration > Server > **Placecom MCP Connector** contains three pages.

### Settings

| Setting | Default | Meaning |
| --- | --- | --- |
| Enable MCP server | On | Master switch for the MCP endpoint. When off, every MCP request is refused with HTTP 403. |
| Access token lifetime | 1 hour | Lifetime of access tokens and ID tokens. |
| Refresh token lifetime | 1 week | Lifetime of refresh tokens. When a refresh token expires the user must approve the assistant again. |
| Issuer URL | empty | Public base URL used as the token issuer and for every advertised endpoint, for example when Moodle runs behind a reverse proxy. Empty means the site URL. |

The page also lists the endpoint URLs of your site and tells you whether web services are enabled.

### OAuth clients

Register one client per assistant. Fields:

- **Client ID**: 3 to 100 characters from `A-Z a-z 0-9 . _ ~ -`, for example `gemini-enterprise`. Cannot be
  changed later.
- **Display name**: shown to users on the consent screen and on their list of connected assistants.
- **Redirect URIs**: one per line, up to 10. Assistants must send exactly one of them. `https` is
  required; `http` is accepted only for `localhost`, `127.0.0.1` and `[::1]`. Examples:
  - Gemini Enterprise: `https://vertexaisearch.cloud.google.com/oauth-redirect`
  - Claude: `https://claude.ai/api/mcp/auth_callback`
- **Scope**: the permissions the client may receive. `moodle_mcp_read` is required. Recommended:
  `openid profile email offline_access moodle_mcp_read`. Add `moodle_mcp_write` only if the assistant
  should post to forums on behalf of users.
- **Require PKCE**: recommended. Clients without a secret always require PKCE.
- **Generate client secret**: creates a confidential client. The secret is displayed **once**, right
  after saving; copy it into the assistant's configuration immediately. Only a hash is stored.

From the client list you can edit a client, generate a new secret (the old one stops working at once),
disable or enable a client (a disabled client cannot sign users in, obtain or refresh tokens, or use its
existing tokens), and delete a client (this disconnects every user of that client).

### Connections

Lists every user's connection to an assistant, with the granted permissions, when it was created and
last used, and whether it still has a valid token. **Revoke** ends a connection immediately: its tokens
stop working and the user has to approve the assistant again.

Both the client and the connection pages require the capability `local/placecom_mcp:manage`, which the
Manager role has by default.

## Connecting an assistant

1. Register a client as described above and note the client ID (and secret, for confidential clients).
2. In the assistant, add a custom MCP server (connector) with the **MCP server URL**
   `https://your.moodle.site/local/placecom_mcp/mcp.php`, plus the client ID and secret.
3. If the assistant asks for OAuth endpoints explicitly, use the values from the discovery document at
   `https://your.moodle.site/local/placecom_mcp/discovery.php`.
4. When a user first uses the assistant with Moodle, they are sent to Moodle to sign in and see a consent
   screen listing what the assistant will be able to do. After they confirm, the assistant receives its
   tokens and can call the tools.

Assistants that implement the MCP authorisation flow discover everything automatically: an MCP request
without a token is answered with HTTP 401 and a `WWW-Authenticate` header pointing to the protected
resource metadata, which names the authorisation server.

### Users: reviewing connected assistants

Every user with the capability `local/placecom_mcp:use` (all authenticated users by default) finds
**Connected AI assistants** in the Privacy and policies section of their profile. The page lists their
connected assistants and lets them disconnect any of them. Disconnecting revokes the tokens and the
stored consent, so the assistant has to ask for approval again.

Remove `local/placecom_mcp:use` from a role to stop its users from connecting assistants.

## Endpoints

All endpoints are below `<issuer>/local/placecom_mcp/`.

| Endpoint | Methods | Purpose |
| --- | --- | --- |
| `mcp.php` | POST, OPTIONS | MCP server (JSON-RPC 2.0). Requires `Authorization: Bearer <access token>`. |
| `protected_resource.php` | GET | OAuth protected resource metadata (RFC 9728) for the MCP server. |
| `discovery.php` | GET | OpenID Connect discovery / OAuth authorisation server metadata. |
| `authorize.php` | GET, POST | Authorisation endpoint with Moodle sign-in and consent screen. |
| `token.php` | POST | Token endpoint (`authorization_code` and `refresh_token` grants). |
| `userinfo.php` | GET, POST | OpenID Connect UserInfo. |
| `jwks.php` | GET | Public keys for verifying ID tokens. |

OAuth behaviour in short:

- Response type `code` only; PKCE method `S256` only.
- Client authentication: `client_secret_basic`, `client_secret_post`, or `none` for public clients.
- Authorisation codes are valid for 30 seconds and can be used once. Reusing a code revokes the
  connection created with it.
- `state` is required when `openid` is requested. Authorisation responses include `iss`.
- Requested scopes are reduced to those registered for the client. Without a `scope` parameter the
  client's registered scope is used.
- `prompt=consent` always shows the consent screen; `prompt=none` never shows a page and fails with
  `login_required` or `consent_required` instead.
- A refresh token is issued when `offline_access` is granted, or when `openid` is not requested at all.
  Refreshing rotates the refresh token and revokes the previous access token.

MCP behaviour in short:

- Supported protocol versions: `2025-11-25`, `2025-06-18`, `2025-03-26`.
- Methods: `initialize`, `ping`, `tools/list`, `tools/call`. Notifications are acknowledged with HTTP 202.
- `GET` is answered with 405 (no server-sent event stream). `OPTIONS` answers CORS preflight requests.
- Request bodies are limited to 1 MiB.
- Tool failures, such as a permission error inside Moodle, are returned as tool results with
  `isError: true`.

### `/.well-known` rewrite rules

Some assistants look for metadata at fixed `/.well-known/` addresses, which a Moodle plugin cannot serve.
Add rewrite rules to your web server so that these addresses reach the plugin. The examples assume
Moodle at the root of `https://moodle.example.com`.

nginx:

```nginx
location = /.well-known/openid-configuration {
    rewrite ^ /local/placecom_mcp/discovery.php last;
}
location = /.well-known/oauth-authorization-server {
    rewrite ^ /local/placecom_mcp/discovery.php last;
}
location = /.well-known/oauth-protected-resource/local/placecom_mcp/mcp.php {
    rewrite ^ /local/placecom_mcp/protected_resource.php last;
}
```

Apache (virtual host or `.htaccess` in the web root, with `mod_rewrite`):

```apache
RewriteEngine On
RewriteRule ^/?\.well-known/(openid-configuration|oauth-authorization-server)$ /local/placecom_mcp/discovery.php [L]
RewriteRule ^/?\.well-known/oauth-protected-resource/local/placecom_mcp/mcp\.php$ /local/placecom_mcp/protected_resource.php [L]
```

When Moodle lives in a subdirectory, for example `https://example.com/moodle`, the issuer contains the
path and clients may try any of these forms; map all of them:

| Request | Target |
| --- | --- |
| `/.well-known/openid-configuration/moodle` | `/moodle/local/placecom_mcp/discovery.php` |
| `/moodle/.well-known/openid-configuration` | `/moodle/local/placecom_mcp/discovery.php` |
| `/.well-known/oauth-authorization-server/moodle` | `/moodle/local/placecom_mcp/discovery.php` |
| `/.well-known/oauth-protected-resource/moodle/local/placecom_mcp/mcp.php` | `/moodle/local/placecom_mcp/protected_resource.php` |

Behind a reverse proxy, set the **Issuer URL** setting to the public address and make the rewrites on
the server that receives the public requests.

## Scopes

| Scope | Grants |
| --- | --- |
| `openid` | Sign-in with OpenID Connect (ID token, UserInfo). |
| `profile` | Name, username, profile and picture URLs, website, time zone, language. |
| `email` | Email address. |
| `address` | Postal address (street, city, country). |
| `phone` | Phone number. |
| `offline_access` | Refresh tokens, so the assistant keeps access without the user signing in again. |
| `moodle_mcp_read` | Use the MCP server and its read tools. **Required** for every client. |
| `moodle_mcp_write` | Additionally use the two forum posting tools. |

A connection without `moodle_mcp_write` does not see the write tools and cannot call them.

## Tools

Only these functions can ever be listed or called. Each one runs as the connected user, with Moodle's
normal capability checks.

Read (23):

- `core_webservice_get_site_info` (reduced to user id, username, first name, last name, full name and
  site name)
- `core_course_get_courses_by_field`, `core_course_search_courses`, `core_course_get_categories`,
  `core_course_get_course_module`, `core_course_get_enrolled_courses_by_timeline_classification`,
  `core_course_get_recent_courses`
- `core_enrol_get_users_courses`
- `core_group_get_course_groups`, `core_group_get_course_user_groups`, `core_group_get_group_members`
- `core_calendar_get_action_events_by_course`, `core_calendar_get_action_events_by_timesort`
- `core_message_get_unread_notification_count`, `message_popup_get_unread_popup_notification_count`
- `core_completion_get_course_completion_status`
- `core_badges_get_user_badges`
- `mod_assign_get_assignments`, `mod_quiz_get_quizzes_by_courses`, `mod_lesson_get_lessons_by_courses`,
  `mod_page_get_pages_by_courses`, `mod_url_get_urls_by_courses`, `mod_forum_get_forums_by_courses`

Write (2, need `moodle_mcp_write`):

- `mod_forum_add_discussion`
- `mod_forum_add_discussion_post`

User lookup functions and functions that read forum posts are deliberately not included, because they
expose other users' personal data.

## Command line: signing keys

```
php local/placecom_mcp/cli/keys.php --list
php local/placecom_mcp/cli/keys.php --rotate [--force]
php local/placecom_mcp/cli/keys.php --delete=<kid>
```

`--rotate` creates a new signing key and makes it active. The previous key is kept in the JWKS so that
ID tokens signed with it can still be verified; delete it with `--delete` once those tokens have expired.

## Privacy

The plugin stores, per user: consents (approved scopes per assistant), connections, and the authorisation
codes, access tokens and refresh tokens issued for them. Codes and tokens are stored **only as SHA-256
hashes**; client secrets only as password hashes; signing keys are encrypted with the site encryption key.

Data released to an assistant depends on the scopes the user approves (identity claims) and on the tools
it calls (course, group, calendar, notification, completion, badge and activity data visible to the
user). The provider of the assistant processes that data under its own terms.

The plugin implements the Moodle Privacy API (export and deletion). Deleting a Moodle user removes all of
their plugin data.

## Security notes

- Tokens are random 256-bit values; only their hashes are stored. Access tokens are accepted only in the
  `Authorization` header, never in the URL.
- Only tokens issued by this plugin work at the MCP endpoint. Core web service tokens are not accepted.
- Every MCP request re-checks the user: deleted, suspended, unconfirmed, locked-out, `nologin` users,
  users with expired passwords and users without `local/placecom_mcp:use` are refused. During maintenance
  mode, requests are refused with HTTP 503 unless the user has maintenance access.
- Revoking a connection, disconnecting an assistant, disabling or deleting a client take effect on the
  next request.
- Keep debugging display off on production sites.
- An hourly scheduled task removes expired codes, tokens and connections.

## Support

Report problems and feature requests at
<https://github.com/almabay/moodle-local_placecom_mcp/issues>.

## Credits

Developed by Alma Bay Networks Private Limited (Placecom). Author: Vishal Sood.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
hello
