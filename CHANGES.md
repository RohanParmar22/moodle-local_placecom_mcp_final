# Changelog

All notable changes to this plugin are documented in this file.

## [Unreleased]

### Added

- MCP protocol revision 2026-07-28 next to 2025-11-25, 2025-06-18 and 2025-03-26: the version travels in `_meta` and in the `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` headers, there is no `initialize` handshake, and the `server/discover` method is available. Results carry `resultType`, and `tools/list` also carries `ttlMs` and `cacheScope`.
- Site setting "Allow assistants to post to forums", off by default. While it is off, the two forum posting tools are not listed and are refused even for connections with the `moodle_mcp_write` scope, and the consent screen and the list of connected assistants say that posting is switched off. The descriptions of the posting tools ask the assistant to show the user the forum and the text before posting; the plugin does not ask users to approve each post.

### Changed

- An unknown `MCP-Protocol-Version` is answered with the `UnsupportedProtocolVersion` error (code -32022) whose data lists the supported versions. It was a generic invalid request error before.
- The `Mcp-Method` and `Mcp-Name` headers are allowed in CORS preflight answers.
- The authorisation code and refresh token grants run under a lock named from a hash of the credential, so two simultaneous requests cannot both use the same code or refresh token. A lock that cannot be obtained within 5 seconds is answered with `server_error`.
- Group enrolment keys, quiz and lesson passwords and the quiz network restriction are removed from the replies and the advertised schemas of `core_group_get_course_groups`, `mod_quiz_get_quizzes_by_courses` and `mod_lesson_get_lessons_by_courses`.
- Forum posting by assistants is off until an administrator ticks "Allow assistants to post to forums" in the plugin settings.

## [0.3.0] - 2026-10-20

Placecom MCP Connector: connect MCP-capable AI assistants to Moodle on behalf of signed-in users.

### Added

- OAuth 2.0 / OpenID Connect authorisation server: authorisation code grant with PKCE (S256), refresh token
  rotation, RS256 ID tokens, UserInfo, JWKS, discovery metadata and RFC 9728 protected resource metadata. Authorisation responses carry `iss`.
- MCP server (JSON-RPC 2.0 over HTTP POST) supporting protocol versions 2025-11-25, 2025-06-18 and
  2025-03-26, with 25 allowlisted Moodle functions (23 read, 2 forum write functions behind the
  `moodle_mcp_write` scope).
- Administration pages for OAuth clients (multiple redirect URIs, secret rotation, enable/disable) and
  for connections, with per-connection revocation.
- "Connected AI assistants" page where users review and disconnect their assistants.
- Signing key rotation from the command line (`cli/keys.php`), with retired keys kept in the JWKS.
- Hourly cleanup of expired codes, tokens and connections; removal of plugin data when a user is deleted.
- Privacy API provider.

### Security

- Authorisation codes, access tokens and refresh tokens are stored only as SHA-256 hashes; client secrets
  as password hashes; private signing keys encrypted at rest. Events never contain secrets.
- Only tokens issued by this plugin are accepted by the MCP endpoint, and only from the `Authorization`
  header. Users are checked on every request.

[0.3.0]: https://github.com/almabay/moodle-local_placecom_mcp/releases/tag/v0.3.0
