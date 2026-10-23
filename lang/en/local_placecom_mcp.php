<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for the Placecom MCP Connector.
 *
 * @package    local_placecom_mcp
 * @author     Vishal Sood <vishal@almabay.com>
 * @copyright  2026 Alma Bay Networks Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['accesstokenlifetime'] = 'Access token lifetime';
$string['accesstokenlifetime_desc'] = 'How long an access token stays valid. ID tokens use the same lifetime. Assistants renew access with their refresh token when it expires.';
$string['actions'] = 'Actions';
$string['addclient'] = 'Add client';
$string['cli_help'] = 'Manage the keys that sign OpenID Connect ID tokens.

Options:
  --list            List all keys with their status.
  --rotate          Create a new active key. The previous key is retired but stays published
                    in the JWKS so that tokens it signed can still be verified.
  --force           Rotate even though an active key already exists.
  --delete=<kid>    Delete a retired key. The active key cannot be deleted.
  -h, --help        Show this help.

Examples:
  php local/placecom_mcp/cli/keys.php --list
  php local/placecom_mcp/cli/keys.php --rotate --force
  php local/placecom_mcp/cli/keys.php --delete=<kid>';
$string['cli_keydeleted'] = 'Key {$a} deleted.';
$string['cli_keyexists'] = 'An active signing key already exists. Use --rotate --force to replace it.';
$string['cli_keygenerated'] = 'New active signing key created.
  Key ID (kid): {$a->kid}
  Algorithm:    {$a->algorithm}
  Key size:     {$a->bits} bits
  Public key:   {$a->preview}...';
$string['cli_keynotfound'] = 'No key with the ID {$a} exists.';
$string['cli_nokeys'] = 'No signing keys exist.';
$string['cli_opensslhint'] = 'OpenSSL could not create the key. On servers where OpenSSL cannot find its configuration file (common on Windows), set $CFG->opensslcnf in config.php to the path of openssl.cnf and try again. Current value: "{$a}".';
$string['cli_rotatenote'] = 'The previous key, if any, stays in the JWKS until you delete it with --delete=<kid>.';
$string['cli_unknownoption'] = 'Unknown option(s):
  {$a}
Use --help to see the available options.';
$string['clientcreated'] = 'The client has been registered.';
$string['clientdeleted'] = 'The client has been deleted and all of its connections have been revoked.';
$string['clientenabled'] = 'Enabled';
$string['clientid'] = 'Client ID';
$string['clientid_help'] = 'The public identifier the AI assistant sends as client_id. Use 3 to 100 characters from letters, digits, dot, underscore, tilde and hyphen, for example "gemini-enterprise". It cannot be changed later.';
$string['clientname'] = 'Display name';
$string['clientname_help'] = 'Name shown to users on the consent screen and on their list of connected assistants. When empty, the client ID is shown.';
$string['clientnotfound'] = 'The client does not exist.';
$string['clientsecret'] = 'Secret';
$string['clientupdated'] = 'The client has been updated.';
$string['connectedsince'] = 'Connected since';
$string['consent_cancel'] = 'Cancel';
$string['consent_confirm'] = 'Allow';
$string['consent_dataheading'] = 'It will be able to:';
$string['consent_login'] = 'Sign you in with your Moodle account';
$string['consent_question'] = 'Do you want to allow {$a} to access your Moodle account?';
$string['consent_title'] = 'Connect an AI assistant';
$string['deleteclient_confirm'] = 'Delete the client "{$a}"? Every user connected through it is disconnected immediately and all of its tokens are revoked. This cannot be undone.';
$string['disableclient'] = 'Disable';
$string['disconnect'] = 'Disconnect';
$string['disconnect_confirm'] = 'Disconnect {$a}? It will lose access to your account immediately and will have to ask for your approval again to reconnect.';
$string['disconnected'] = '{$a} has been disconnected from your account.';
$string['editclient'] = 'Edit client';
$string['enableclient'] = 'Enable';
$string['enabled'] = 'Enable MCP server';
$string['enabled_desc'] = 'Master switch for the MCP endpoint. When off, every request to the endpoint is refused, whatever token is presented. Sign-in and token endpoints keep working.';
$string['endpointsinfo'] = 'Endpoints';
$string['endpointsinfo_desc'] = '<p>Give assistants the MCP server URL. They find everything else through the metadata documents.</p>
<ul>
<li>MCP server: <code>{$a->mcp}</code></li>
<li>Authorisation server metadata: <code>{$a->discovery}</code></li>
<li>Protected resource metadata: <code>{$a->resource}</code></li>
<li>Signing keys (JWKS): <code>{$a->jwks}</code></li>
</ul>
<p>{$a->wsstatus}</p>
<p>Some assistants look for these documents under <code>/.well-known/</code> on your web server. See the README of the plugin for the web server rewrite rules.</p>';
$string['err_accessdenied'] = 'The user or the site refused the authorization request.';
$string['err_clientauth'] = 'Client authentication failed.';
$string['err_clientidmissing'] = 'The client_id parameter is required.';
$string['err_codeexpired'] = 'The authorization code has expired.';
$string['err_codeinvalid'] = 'The authorization code is invalid or was issued to another client.';
$string['err_codemissing'] = 'The code parameter is required.';
$string['err_codereused'] = 'The authorization code has already been used. Tokens issued from it have been revoked.';
$string['err_consentrequired'] = 'The user has not yet approved this client and no user interaction was allowed.';
$string['err_granttypemissing'] = 'The grant_type parameter is required.';
$string['err_granttypeunsupported'] = 'Only the authorization_code and refresh_token grant types are supported.';
$string['err_insufficientscope'] = 'The access token does not include the openid scope.';
$string['err_loginrequired'] = 'The user is not logged in and no user interaction was allowed.';
$string['err_methodnotallowed'] = 'This HTTP method is not allowed here.';
$string['err_multipleauth'] = 'More than one client authentication method was used.';
$string['err_multipletokens'] = 'The access token must be sent in exactly one place.';
$string['err_nonce'] = 'The nonce parameter must not be longer than 255 characters.';
$string['err_nopermission'] = 'The user is not allowed to connect AI assistants.';
$string['err_pkcechallengeformat'] = 'The code_challenge must be 43 to 128 characters from A-Z, a-z, 0-9, "-", ".", "_" and "~".';
$string['err_pkcemethod'] = 'The code_challenge_method must be S256.';
$string['err_pkcerequired'] = 'This client must use PKCE: code_challenge and code_challenge_method=S256 are required.';
$string['err_promptinvalid'] = 'The prompt value none cannot be combined with other values.';
$string['err_redirectmismatch'] = 'The redirect_uri does not match the one used in the authorization request.';
$string['err_redirecturifragment'] = 'The redirect_uri must not contain a fragment.';
$string['err_redirecturimismatch'] = 'The redirect_uri is not registered for this client.';
$string['err_redirecturimissing'] = 'The redirect_uri parameter is required because the client has several registered redirect URIs.';
$string['err_refreshinvalid'] = 'The refresh token is invalid, expired or was issued to another client.';
$string['err_refreshmissing'] = 'The refresh_token parameter is required.';
$string['err_responsetypemissing'] = 'The response_type parameter is required.';
$string['err_responsetypeunsupported'] = 'Only the response_type code is supported.';
$string['err_scopeinvalid'] = 'None of the requested scopes is allowed for this client.';
$string['err_scopenotsubset'] = 'The requested scope exceeds the scope originally granted.';
$string['err_servererror'] = 'The server could not complete the request.';
$string['err_staterequired'] = 'The state parameter is required for OpenID Connect requests.';
$string['err_tokeninvalid'] = 'The access token is invalid or has expired.';
$string['err_tokenmissing'] = 'An access token is required.';
$string['err_unknownclient'] = 'The client is unknown or disabled.';
$string['err_userinvalid'] = 'The user account can no longer use this connection.';
$string['err_verifierinvalid'] = 'The code_verifier has an invalid format.';
$string['err_verifiermismatch'] = 'The code_verifier does not match the code challenge.';
$string['err_verifiermissing'] = 'The code_verifier parameter is required.';
$string['err_verifierunexpected'] = 'A code_verifier was sent but the authorization request did not use PKCE.';
$string['error_argumentsnotobject'] = 'The tool arguments must be a JSON object.';
$string['error_batchnotsupported'] = 'JSON-RPC batch requests are not supported. Send one message per request.';
$string['error_bodytoolarge'] = 'The request body is larger than the 1 MiB limit.';
$string['error_clientidexists'] = 'A client with this ID already exists.';
$string['error_clientidinvalid'] = 'The client ID must be 3 to 100 characters long and may only contain letters, digits, dot, underscore, tilde and hyphen.';
$string['error_clientidrequired'] = 'Enter a client ID.';
$string['error_deleteactivekey'] = 'The active signing key cannot be deleted. Rotate the keys first.';
$string['error_emptybody'] = 'The request body is empty.';
$string['error_headermismatch'] = 'The {$a} header is missing or does not match the request body.';
$string['error_insufficientscope'] = 'The access token does not include the moodle_mcp_read scope.';
$string['error_internal'] = 'Internal error';
$string['error_invalidid'] = 'The request id must be a string, an integer or null.';
$string['error_invalidjson'] = 'The request body is not valid JSON.';
$string['error_jsonrpcversion'] = 'The JSON-RPC version must be "2.0".';
$string['error_keygeneration'] = 'The signing key could not be generated: {$a}';
$string['error_maintenance'] = 'The site is in maintenance mode. Please try again later.';
$string['error_mcp'] = '{$a}';
$string['error_mcpdisabled'] = 'The MCP server is switched off on this site.';
$string['error_methodnotallowed'] = 'This HTTP method is not allowed. Send JSON-RPC messages with POST.';
$string['error_methodnotfound'] = 'Method not found';
$string['error_methodrequired'] = 'The method is required and must be a non-empty string.';
$string['error_nocapability'] = 'Your account is not allowed to use AI assistants on this site.';
$string['error_nokey'] = 'No usable signing key is available.';
$string['error_nokey_debug'] = 'local_placecom_mcp: no RS256 signing key is available, so ID tokens cannot be issued. Check that the PHP OpenSSL extension works (on Windows set $CFG->opensslcnf to the path of openssl.cnf), then run: php local/placecom_mcp/cli/keys.php --rotate';
$string['error_notobject'] = 'The request body must be a JSON object.';
$string['error_paramsnotobject'] = 'The params member must be a JSON object.';
$string['error_protocolversion'] = 'Unsupported MCP protocol version: {$a->version}. Supported versions: {$a->supported}.';
$string['error_publicclientpkce'] = 'A client without a secret must require PKCE. Tick "Require PKCE" or generate a secret.';
$string['error_redirecturiinvalid'] = 'Invalid redirect URI: {$a}. Use an absolute https address without a fragment or user name. Plain http is only accepted for localhost, 127.0.0.1 and [::1].';
$string['error_redirecturirequired'] = 'Enter at least one redirect URI.';
$string['error_redirecturitoomany'] = 'Enter at most {$a} redirect URIs.';
$string['error_scopereadrequired'] = 'The scope must include moodle_mcp_read. Add moodle_mcp_write only next to it.';
$string['error_scoperequired'] = 'Enter at least one scope.';
$string['error_scopeunknown'] = 'The scope contains an unknown value. Allowed values: openid, profile, email, offline_access, address, phone, moodle_mcp_read, moodle_mcp_write.';
$string['error_toolnamemissing'] = 'The tool name is missing.';
$string['error_toolnotallowed'] = 'The function {$a} is not on the list of functions approved for AI assistants.';
$string['error_toolunavailable'] = 'The function {$a} is not available on this site.';
$string['error_unauthorized'] = 'Authentication is required: send a valid access token in the Authorization header.';
$string['error_writescope'] = 'This connection is not allowed to make changes: it lacks the moodle_mcp_write permission.';
$string['error_wsdisabled'] = 'Web services are disabled on this site.';
$string['event_authorization_denied'] = 'Assistant authorization refused';
$string['event_authorization_granted'] = 'Assistant authorization granted';
$string['event_client_created'] = 'OAuth client registered';
$string['event_client_deleted'] = 'OAuth client deleted';
$string['event_client_updated'] = 'OAuth client updated';
$string['event_grant_revoked'] = 'Assistant connection revoked';
$string['event_mcp_access_refused'] = 'MCP access refused';
$string['event_token_issued'] = 'Access token issued';
$string['generatesecret'] = 'Generate client secret';
$string['generatesecret_help'] = 'Creates a confidential client that authenticates with a secret. The secret is displayed once after saving, so copy it straight away. Untick only for assistants that cannot keep a secret (public clients); those must use PKCE.';
$string['grantclient'] = 'Assistant';
$string['grantnotfound'] = 'The connection does not exist.';
$string['grantrevoked'] = 'The connection has been revoked.';
$string['grantscope'] = 'Permissions';
$string['grantstatus'] = 'Status';
$string['granttimeaccessed'] = 'Last used';
$string['granttimecreated'] = 'Connected';
$string['grantuser'] = 'User';
$string['issuer'] = 'Issuer URL';
$string['issuer_desc'] = 'Public base URL of this site as seen by assistants, for example when Moodle runs behind a reverse proxy. It is used as the token issuer and for every advertised endpoint. Leave empty to use the site URL.';
$string['lastused'] = 'Last used';
$string['manageclients'] = 'OAuth clients';
$string['managegrants'] = 'Connections';
$string['mcp_instructions'] = 'Moodle MCP server ready. Tools run as the signed-in Moodle user.';
$string['myconnections'] = 'Connected AI assistants';
$string['myconnections_intro'] = 'These AI assistants can act on your behalf in this site with the permissions listed. Disconnect any assistant you no longer use or do not recognise.';
$string['never'] = 'Never';
$string['noconnections'] = 'No AI assistant is connected to your account.';
$string['nogrants'] = 'No user has connected an AI assistant yet.';
$string['oautherror'] = 'OAuth error: {$a}';
$string['placecom_mcp:manage'] = 'Manage OAuth clients and AI assistant connections';
$string['placecom_mcp:use'] = 'Connect AI assistants to own account';
$string['pluginname'] = 'Placecom MCP Connector';
$string['privacy:export:accesstokens'] = 'Access tokens';
$string['privacy:export:authorizationcodes'] = 'Authorisation codes';
$string['privacy:export:connections'] = 'Connections';
$string['privacy:export:consents'] = 'Consents';
$string['privacy:export:refreshtokens'] = 'Refresh tokens';
$string['privacy:metadata:authcode'] = 'Short-lived authorisation codes issued when a user approves an assistant.';
$string['privacy:metadata:consent'] = 'Permissions a user has approved for an assistant on the consent screen.';
$string['privacy:metadata:field:clientid'] = 'The assistant (OAuth client) the record belongs to.';
$string['privacy:metadata:field:expires'] = 'When the record stops being valid.';
$string['privacy:metadata:field:nonce'] = 'Random value sent by the assistant to protect the sign-in.';
$string['privacy:metadata:field:redirecturi'] = 'Address the user was sent back to after approving the assistant.';
$string['privacy:metadata:field:scope'] = 'Permissions covered by the record.';
$string['privacy:metadata:field:timeaccessed'] = 'When the assistant last used the connection.';
$string['privacy:metadata:field:timecreated'] = 'When the record was created.';
$string['privacy:metadata:field:tokenhash'] = 'One-way hash of the token. The token itself is not stored.';
$string['privacy:metadata:field:userid'] = 'The user the record belongs to.';
$string['privacy:metadata:grant'] = 'Connections between a user and an assistant, created when the assistant first obtains tokens.';
$string['privacy:metadata:mcpclient'] = 'An AI assistant (MCP client) the user has connected to their account receives data about the user, depending on the permissions granted. The provider of the assistant processes this data under its own terms and privacy policy.';
$string['privacy:metadata:mcpclient:address'] = 'Postal address, when the address permission is granted.';
$string['privacy:metadata:mcpclient:email'] = 'Email address, when the email permission is granted.';
$string['privacy:metadata:mcpclient:fullname'] = 'Full name and other profile details, when the profile permission is granted.';
$string['privacy:metadata:mcpclient:moodledata'] = 'Data returned by the approved functions for the user: enrolled and recent courses, course categories and modules, groups and their members, calendar events, notification counts, completion status, badges, assignments, quizzes, lessons, pages, URLs and forums; and, with write permission, the forum discussions and replies the assistant posts.';
$string['privacy:metadata:mcpclient:phone'] = 'Phone number, when the phone permission is granted.';
$string['privacy:metadata:mcpclient:picture'] = 'Profile picture address, when the profile permission is granted.';
$string['privacy:metadata:mcpclient:userid'] = 'User id, sent as the subject of every identity token.';
$string['privacy:metadata:mcpclient:username'] = 'Username, when the profile permission is granted.';
$string['privacy:metadata:refresh'] = 'Refresh tokens that let an assistant renew its access.';
$string['privacy:metadata:token'] = 'Access tokens an assistant uses to call the MCP server.';
$string['publicclient'] = 'Public client (no secret)';
$string['redirecturis'] = 'Redirect URIs';
$string['redirecturis_help'] = 'Addresses the user is sent back to after approving the assistant, one per line (at most 10). Each must match exactly what the assistant sends. Examples: <code>https://vertexaisearch.cloud.google.com/oauth-redirect</code> for Gemini Enterprise, <code>https://claude.ai/api/mcp/auth_callback</code> for Claude. Use https; http is only accepted for localhost.';
$string['refreshtokenlifetime'] = 'Refresh token lifetime';
$string['refreshtokenlifetime_desc'] = 'How long a refresh token stays valid. After it expires, the user has to approve the assistant again.';
$string['requirepkce'] = 'Require PKCE';
$string['requirepkce_help'] = 'PKCE (S256) ties the authorisation code to the assistant that asked for it, so an intercepted code is useless. Keep it on unless the assistant does not support it. Clients without a secret always require it.';
$string['revoke'] = 'Revoke';
$string['revoke_confirm'] = 'Revoke the connection of {$a->user} to {$a->client}? Its tokens stop working immediately and the user has to approve the assistant again.';
$string['rotatesecret'] = 'New secret';
$string['rotatesecret_confirm'] = 'Generate a new secret for "{$a}"? The current secret stops working immediately, so the assistant must be updated with the new one.';
$string['scope'] = 'Scope';
$string['scope_address'] = 'See your postal address';
$string['scope_email'] = 'See your email address';
$string['scope_help'] = 'Permissions this client may receive, separated by spaces. Recommended: <code>openid profile email offline_access moodle_mcp_read</code>. moodle_mcp_read is required. Add moodle_mcp_write only if the assistant should be able to post in forums on behalf of users.';
$string['scope_moodle_mcp_read'] = 'Read your courses, groups, calendar and notifications';
$string['scope_moodle_mcp_write'] = 'Post to forums in your courses on your behalf';
$string['scope_offline_access'] = 'Stay connected without asking you to sign in again';
$string['scope_openid'] = 'Confirm your identity with your Moodle account';
$string['scope_phone'] = 'See your phone number';
$string['scope_profile'] = 'See your name, username, profile picture and similar profile details';
$string['secret_shownonce'] = 'Client secret: <code>{$a}</code><br>Copy it now. It is stored only in hashed form and cannot be displayed again.';
$string['settings'] = 'Settings';
$string['status_active'] = 'Active';
$string['status_expired'] = 'Expired';
$string['task_cleanup'] = 'Remove expired codes, tokens and connections';
$string['wsdisabledwarning'] = 'Web services are disabled on this site, so the MCP server refuses all requests. Turn on "Enable web services" under Site administration > General > Advanced features.';
$string['wsenabled'] = 'Web services are enabled on this site.';
