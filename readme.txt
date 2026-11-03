=== FlitAgent ===
Contributors: flitagent
Tags: ai, mcp, agents, site management
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Beta, read-only: let ChatGPT, Claude or any MCP agent read and check your site through flitagent.com, with typed tools that change nothing.

== Description ==

FlitAgent adds a Model Context Protocol (MCP) endpoint to your site so ChatGPT, Claude and other AI assistants can read and check it. Every action is a named tool with checked inputs: there is no way to run arbitrary code, SQL or shell commands.

This version is a beta, and it is read-only: AI tools can read and check the site, but no tool changes anything. Tools that make changes may come in a later version.

FlitAgent is serviceware: it works only together with the flitagent.com service and needs a free flitagent.com account. Your AI app talks to flitagent.com at `https://mcp.flitagent.com/`, and flitagent.com relays each request to your site, which checks it and answers. The plugin does nothing on its own until an administrator clicks Connect (see External services for exactly what is sent).

= Read the site =

AI apps act as the administrator who connected the site, and get twelve read tools:

* `get_site_info`: the site's title, tagline, address, language, timezone and site icon.
* `get_site_health`: the WordPress, PHP and database versions, multisite and debug mode, and the issue counts of the last Site Health check.
* `get_health_triage`: checks the whole site at once and lists what is wrong, most serious first: pending updates, inactive plugins and unused themes, cron tasks running late, stuck maintenance mode, PHP errors shown to visitors and critical Site Health issues, each with what to do in wp-admin.
* `get_update_readiness`: checks every pending plugin, theme and WordPress update against the site's WordPress and PHP versions, lists them in the order to apply them, ready or blocked and why, and reports the last backup.
* `get_content_qa`: finds images without alt text, broken internal links, empty and duplicate titles, missing excerpts and SEO descriptions, short and stale content, old drafts, and categories and tags with no posts.
* `list_plugins`: the installed plugins, with version, whether each is active and whether an update is available.
* `list_themes`: the installed themes, with version, the active theme (and its parent) and whether an update is available.
* `list_recent_content`: the most recently modified posts and pages, without their content.
* `search_content`: searches posts and pages like the site's own search, with a short snippet of each, never the full content.
* `get_core_updates`: the installed WordPress version, the core updates WordPress last found and whether automatic updates are on.
* `count_comments`: comment counts by status, for the whole site or one post.
* `get_connection_info`: who the connection is logged in as, whether the administrator tools are available, and the active theme.

The update tools read only what WordPress already saved and never contact WordPress.org.

= Connect =

In FlitAgent > Connect, click Connect to flitagent.com. flitagent.com opens, where you log in and approve the connection, and then you are back in wp-admin. Then click Set up for ChatGPT, Claude or Manual configuration (Claude Code, Codex and other MCP clients): each shows that app's steps, with Copy buttons for what to paste (`https://mcp.flitagent.com/`, or the Claude Code and Codex commands). Log in to flitagent.com when the app asks. Step-by-step guide, with a section for ChatGPT, Claude and other MCP clients: [Connect your AI app](https://flitagent.com/connect/).

The connection is read only. FlitAgent > Connect shows it (account, access, when flitagent.com last made a request) with a Disconnect site button, and the AI tools that reached your site: set each one up from there, or disconnect one tool from your site while the others keep working. Disconnecting the site in your flitagent.com account disconnects it here too: flitagent.com sends the site a signed message, the plugin deletes the connection, and FlitAgent > Connect says when. flitagent.com must reach your site over the internet, so local and private sites cannot connect.

= Source code =

The JavaScript and CSS in `assets/` are the source: they are not compiled, minified or bundled. The same files, with the readme for developers, are in the public repository https://github.com/FlitAgent/FlitAgent (GPL-2.0-or-later). The design tokens in `assets/css/tokens.css` come from FlitAgent's design system (GPL-2.0-or-later).

Website and documentation: https://flitagent.com

== Installation ==

1. Install FlitAgent from Plugins > Add New, or upload the zip under Plugins > Add New > Upload Plugin, and activate it. You also need a free account at https://flitagent.com.
2. A short setup guide opens. It checks the site for the host settings that most often stop flitagent.com from connecting (HTTPS, permalinks, the MCP endpoint, custom request headers), with a fix for each problem. Nothing is sent to flitagent.com while it runs.
3. Connect: click Connect to flitagent.com and log in at flitagent.com to approve the connection. Then click Set up for ChatGPT, Claude or Manual configuration and follow that app's steps.
4. Ask your AI app a first question, such as "Using FlitAgent, show me the connected site details".

FlitAgent > Settings has Test connection, which runs the same checks again, and the choice of what deleting the plugin removes.

flitagent.com connects to your site from the internet, so the site needs a public HTTPS address. Local and private sites cannot connect.

== Frequently Asked Questions ==

= Can the AI change my site? =

No. This beta is read-only: every tool only reads, and none changes posts, settings, plugins or anything else on the site. Tools that make changes may come in a later version.

= Who can connect? =

An administrator (a user who can manage options) connects the site to flitagent.com, and requests from your AI apps act as that user. If the connecting user loses administrator access, requests are refused until an administrator connects again.

= Does FlitAgent send my data to flitagent.com? =

Nothing is sent before an administrator clicks Connect. After that, your AI app's tool calls and their results pass through flitagent.com on the way to and from your site, and are not stored there. flitagent.com does keep usage metadata: raw events for 90 days and daily totals for 25 months. flitagent.com also stores this site's name, address and access, its FlitAgent, WordPress and PHP versions, and which AI tools are disconnected, and caches the list of tools. The plugin makes two kinds of request on its own. It fetches flitagent.com's public key set (daily, when an administrator clicks Connect, when the Connect screen opens while the site is connected, or when a key id is unknown, at most once every 300 seconds), which carries no site details. And when an administrator disconnects or sets up an AI tool, disconnects the site, or deletes the plugin, it tells flitagent.com with one POST that carries only the connection id; flitagent.com then asks the site, with a signed request, what changed. It sends the same POST in the background, at most once an hour, when an administrator opens FlitAgent > Connect while the site is connected, so a disconnect made in your flitagent.com account reaches the site even if the site was down then. Everything else is your site answering requests that flitagent.com sends. See External services and Privacy.

= Does it work on multisite? =

Yes. The read tools work on multisite, and each site of a network connects to flitagent.com on its own.

= My AI app cannot connect. What can I check? =

Open FlitAgent > Settings and click Test connection. It checks HTTPS, permalinks, the MCP endpoint and custom request headers from your server, and shows a fix for each problem.

== Screenshots ==

1. Ask ChatGPT (or Claude) about your site: FlitAgent's read tools check updates, health and content, and nothing on the site changes.
2. The setup guide checks HTTPS, permalinks, the MCP endpoint and custom headers before you connect.
3. FlitAgent > Connect: the site connected to flitagent.com, read only, and the AI tools that can reach it.
4. Your flitagent.com account: each site, its access, versions, status and connected AI tools.
5. Settings: what deleting the plugin removes, and a connection test.

== External services ==

FlitAgent requires the flitagent.com service (it is serviceware). Nothing is sent to flitagent.com until an administrator starts connecting (Connect to flitagent.com on the setup guide's Connect step or on FlitAgent > Connect). After that, the plugin's own requests to flitagent.com are fetching its public key set and the change notice described below; everything else flitagent.com receives is this site answering requests that flitagent.com sent. The other outbound requests are listed below.

* **flitagent.com** (https://flitagent.com, https://mcp.flitagent.com). Sent when an administrator starts connecting: the administrator's browser is sent to flitagent.com with this site's MCP address, the return address in wp-admin, the access requested (read) and a one-time code, and the administrator logs in there. After that, the plugin fetches flitagent.com's public key set with an ordinary GET request (once a day; when an administrator starts connecting, just before the browser goes to flitagent.com; when the Connect screen opens, but only while the site is connected; or when a request names a key id the site does not know, at most once every 300 seconds); this request carries no site details and no site address in its User-Agent. When an administrator disconnects or sets up an AI tool on FlitAgent > Connect or on the setup guide's Connect step, disconnects the site, or deletes the plugin, the plugin sends https://flitagent.com/wp-json/flitclient/v1/nudge one POST whose body is only the connection id (no site address, no user data, no site address in its User-Agent); the answer is ignored. The same POST is sent in the background, at most once an hour, when an administrator opens FlitAgent > Connect while the site is connected. All other traffic is the site answering requests flitagent.com sends it: your AI app's tool calls reach the site through flitagent.com (https://mcp.flitagent.com), and the results go back the same way. They pass through in transit and are not stored there. flitagent.com keeps usage metadata: raw events for 90 days and daily totals for 25 months. flitagent.com also stores this site's name, address and access, its FlitAgent, WordPress and PHP versions, and which AI tools are disconnected, and caches the list of tools. [Terms of service](https://flitagent.com/terms/), [privacy policy](https://flitagent.com/privacy/)
* **ChatGPT and Claude** (https://chatgpt.com, https://claude.ai). The Set up dialogs link to each app's own page for adding FlitAgent: Open ChatGPT Plugins (chatgpt.com/plugins) and Open Claude connectors (claude.ai/customize/connectors, with the name FlitAgent and the MCP address `https://mcp.flitagent.com/` in the link). When ChatGPT or Claude is connected, the dialog offers Try now, a link that opens a new chat with a first question filled in ("Using FlitAgent, show me the connected site details"); the question is the only thing in that link. Nothing is sent unless you click a link, and then only by your browser. [OpenAI terms of use](https://openai.com/policies/terms-of-use/) and [privacy policy](https://openai.com/policies/privacy-policy/); [Anthropic consumer terms](https://www.anthropic.com/legal/consumer-terms) and [privacy policy](https://www.anthropic.com/legal/privacy)
* **This site itself.** Test connection (FlitAgent > Settings) and the setup guide's site check request this site's own MCP endpoint and REST API from the server (loopback requests), with no cookies or credentials; nothing leaves the site.

== Privacy ==

FlitAgent stores, in your site's database: the connection to flitagent.com (connection id, the connecting user, access, when it connected and an account hint) and flitagent.com's public keys; a pending connection while you connect; when flitagent.com last made a request; the AI apps that reached your site through it (app id and name, access, when each was first and last seen; at most 20), with the tools you disconnected and the apps revoked on flitagent.com; a table of recent request nonces, so a signed request cannot be replayed; and when the plugin was installed, for the setup guide. It holds no tokens or passwords for your AI apps and keeps nothing from the requests themselves. Nothing is sent to FlitAgent's developers before you click Connect. Once connected, tool calls and results pass through flitagent.com and are not stored there; flitagent.com keeps usage metadata (raw events for 90 days, daily totals for 25 months), as described in its [privacy policy](https://flitagent.com/privacy/). flitagent.com also stores this site's name, address and access, its FlitAgent, WordPress and PHP versions, and which AI tools are disconnected, and caches the list of tools.

Deleting the plugin removes the connection and the AI apps it saw, on every site of a network, and tells flitagent.com, which disconnects the site and its AI tools (if flitagent.com cannot be reached, or on a large network, the site only shows as unreachable there; disconnect it in your flitagent.com account); after a reinstall you connect again and set up each AI tool again. "Delete FlitAgent's settings and connection data when the plugin is deleted" under FlitAgent > Settings is ticked by default: deleting the plugin then also removes the nonce table and every other FlitAgent setting, and a reinstall starts the setup guide from step 1. If you untick it and save, the settings and the finished setup guide are kept, so a reinstall goes straight to Connect.

== Credits ==

* Icons from [Lucide](https://lucide.dev) (ISC; icons derived from Feather, MIT), licence in `assets/css/admin.css`.

== Changelog ==

= 0.9.1 =
* The setup guide and the plugin's forms read requests more safely.
* New drafts no longer show as abandoned in the content check.
* Tools refuse arguments they do not accept.
* Tool output comes back as plain text, so text from your site cannot carry markup into your AI app.
* An invalid connection address now shows a clear message.
* A search that only excludes words now works like the site's own search.

= 0.9.0 =
* Beta: read-only. Twelve read tools, the Connect screen and the setup guide.

== Upgrade Notice ==

= 0.9.1 =
Safer request handling and plain-text tool output. New drafts no longer show as abandoned, tools refuse arguments they do not accept, an invalid connection address shows a clear message, and a search that only excludes words works.

= 0.9.0 =
Beta: read-only. Twelve read tools, the Connect screen and the setup guide.
