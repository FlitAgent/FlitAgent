# FlitAgent: MCP server for WordPress

**FlitAgent** lets ChatGPT, Claude and other AI assistants read and check your WordPress site through typed tools.

> Website and documentation: https://flitagent.com

## What the beta does

This version (0.9.0) is a beta, and it is read-only: AI tools can read and check the site, but no tool changes anything. Tools that make changes may come in a later version.

- **Reads the site.** Site details and health, plugins and themes, recent content, content search, core updates and comment counts.
- **Finds what is wrong.** `get_health_triage` checks the whole site at once and lists each problem, how serious it is and what to do about it in wp-admin. `get_update_readiness` checks pending updates before you apply them, and `get_content_qa` finds missing alt text, broken internal links, SEO basics and stale content.
- **Typed tools only.** Every action is a named tool with checked inputs. No tool runs code, SQL or shell commands.

## How FlitAgent works

```text
┌──────────────────────────────┐
│ AI app                       │  ChatGPT, Claude, Claude Code, Codex
└──────────────┬───────────────┘
               │ MCP over HTTPS, https://mcp.flitagent.com/
┌──────────────▼───────────────┐
│ flitagent.com                │  relays signed requests, stores no content
└──────────────┬───────────────┘
               │ signed requests over HTTPS
┌──────────────▼───────────────┐
│ FlitAgent, on your site      │  checks the signature, runs read tools
└──────────────┬───────────────┘
               │ WordPress APIs
┌──────────────▼───────────────┐
│ WordPress                    │
└──────────────────────────────┘
```

FlitAgent needs the flitagent.com service and a free flitagent.com account. Your AI app talks to flitagent.com, which relays each request to your site; the site checks the request's signature and answers. Tool calls and results pass through flitagent.com in transit and are not stored there. flitagent.com keeps usage metadata: raw events for 90 days and daily totals for 25 months. See the [terms](https://flitagent.com/terms/) and [privacy policy](https://flitagent.com/privacy/).

## Tools your AI app gets

| Tool | What it does |
| --- | --- |
| `get_site_info` | The site's title, tagline, address, language, timezone and site icon. |
| `get_site_health` | WordPress, PHP and database versions, multisite and debug mode, and the issue counts of the last Site Health check. |
| `get_health_triage` | Checks the whole site and lists what is wrong, most serious first, with what to do in wp-admin. |
| `get_update_readiness` | Checks every pending plugin, theme and WordPress update against the site, in the order to apply them, and reports the last backup. |
| `get_content_qa` | Finds images without alt text, broken internal links, empty and duplicate titles, missing SEO descriptions and stale content. |
| `list_plugins` | Installed plugins, with version, whether each is active and whether an update is available. |
| `list_themes` | Installed themes, with version, the active theme and whether an update is available. |
| `list_recent_content` | The most recently modified posts and pages, without their content. |
| `search_content` | Searches posts and pages like the site's own search, with a short snippet of each. |
| `get_core_updates` | The installed WordPress version, the core updates WordPress last found and whether automatic updates are on. |
| `count_comments` | Comment counts by status, for the whole site or one post. |
| `get_connection_info` | Who the connection is logged in as, whether the administrator tools are available, and the active theme. |

The update tools read only what WordPress already saved and never contact WordPress.org. All tools except `get_site_info`, `list_recent_content`, `search_content` and `get_connection_info` need an administrator; requests act as the administrator who connected the site.

## Installing

Requirements: WordPress 6.0 or later (tested up to 7.1), PHP 7.4 or later, and a site with a public HTTPS address (flitagent.com connects from the internet, so local and private sites cannot connect).

1. Get the plugin: download the zip from [flitagent.com](https://flitagent.com) and upload it under Plugins > Add New > Upload Plugin, or clone this repository into `wp-content/plugins/flitagent`:

   ```sh
   git clone https://github.com/FlitAgent/FlitAgent.git wp-content/plugins/flitagent
   ```

   Nothing to build: the PHP in `includes/` and the JavaScript and CSS in `assets/` run as they are.
2. Activate FlitAgent. A short setup guide opens and checks the host settings that most often stop flitagent.com from connecting (HTTPS, permalinks, the MCP endpoint, custom request headers), with a fix for each problem. Nothing is sent to flitagent.com yet.
3. Connect: click Connect to flitagent.com and log in at flitagent.com to approve the connection. Then click Set up for ChatGPT, Claude or Manual configuration and follow that app's steps (Copy buttons for what to paste: `https://mcp.flitagent.com/`, or the Claude Code and Codex commands). Guide: [Connect your AI app](https://flitagent.com/connect/), with sections for [ChatGPT](https://flitagent.com/connect/#chatgpt), [Claude](https://flitagent.com/connect/#claude) and [other clients](https://flitagent.com/connect/#manual).
4. Ask your AI app a first question, such as "Using FlitAgent, show me the connected site details".

If the app cannot connect, open FlitAgent > Settings and click Test connection. Disconnect in FlitAgent > Connect (or in your flitagent.com account) stops all requests. When an administrator disconnects or sets up an AI tool, disconnects the site, or deletes the plugin, the plugin tells flitagent.com with one POST that carries only the connection id.

## Reporting issues

Open an issue at https://github.com/FlitAgent/FlitAgent/issues with the bug report template: what happened, the steps to reproduce, and your WordPress, PHP and FlitAgent versions and AI app. Remove secrets before posting. Report security problems privately through the [Security tab](https://github.com/FlitAgent/FlitAgent/security/advisories/new), not in a public issue.

## Changelog

See the Changelog section of [readme.txt](readme.txt).

## Licence

GPL-2.0-or-later, the same licence as WordPress. See [LICENSE](LICENSE).
