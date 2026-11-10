# FlitAgent: MCP server for WordPress

**FlitAgent** lets AI assistants such as Claude and ChatGPT work on your WordPress site through typed tools, with changes approved in wp-admin and recorded.

> Website and documentation: https://flitagent.com

## What FlitAgent does

- **Reads the whole site.** Site health, plugins and themes, settings, cron, caches, files, page-builder layouts, WooCommerce store data and WordPress abilities. FlitAgent's read tools make no changes of their own.
- **Finds what is wrong.** `get_health_triage` checks the site at once and lists each problem, how serious it is and which tool fixes it. `get_content_qa` finds missing alt text, broken internal links, SEO basics and stale content.
- **Makes changes you approve.** Posts and pages, media, users and roles, settings, menus, widgets, block templates and global styles, cache and cron, page-builder layouts, and plugin, theme and core updates.
- **Groups fixes into plans.** Several changes go out as one plan with one approval, and you can untick any item.
- **Keeps a record.** Every change gets a receipt. Changes can be rolled back, with some exceptions that show a warning before you approve them.
- **Checks updates afterwards.** After a core update, or an update to an active plugin or theme, FlitAgent loads the home page and warns you if it fails.

Every action is a named tool with checked inputs, and no tool runs SQL or shell commands. Code can only reach the site through tools that always need your approval: page-builder code elements (for users who may post unfiltered HTML), abilities and scheduled tasks that plugins registered, and plugins and themes from WordPress.org.

## How FlitAgent works

```text
┌──────────────────────────────┐
│ AI app                       │  Claude, ChatGPT, Claude Code, Codex
└──────────────┬───────────────┘
               │ MCP over HTTPS, https://mcp.flitagent.com/
┌──────────────▼───────────────┐
│ flitagent.com                │  relays signed requests, stores no content
└──────────────┬───────────────┘
               │ signed requests over HTTPS
┌──────────────▼───────────────┐
│ FlitAgent, on your site      │  typed tools, checked inputs
│                              │  changes wait in FlitAgent > Approvals
└──────────────┬───────────────┘
               │ WordPress APIs
┌──────────────▼───────────────┐
│ WordPress                    │  receipts in FlitAgent > Activity
└──────────────────────────────┘
```

FlitAgent needs the flitagent.com service and a free flitagent.com account. Your AI app talks to flitagent.com, which relays each request to your site; the site checks the request's signature and answers. Tool calls and results pass through flitagent.com in transit and are not stored there. flitagent.com keeps usage metadata: raw events for 90 days and daily totals for 25 months. flitagent.com also stores this site's name, address and access, its FlitAgent, WordPress and PHP versions, and which AI tools are disconnected, and caches the list of tools. See the [terms](https://flitagent.com/terms/) and [privacy policy](https://flitagent.com/privacy/).

## Tools your AI app gets

| Area | Tools |
| --- | --- |
| Site and health | `get_site_info`, `get_health_triage`, `get_site_health`, `get_update_readiness`, `verify_core_checksums`, `verify_plugin_checksums` |
| Content | `list_recent_content`, `search_content`, `wp_rest_get`, `get_content_qa`, `create_post`, `update_post`, `replace_in_post`, `trash_post`, `restore_post_revision`, `set_post_terms` |
| Media | `upload_media_from_url`, `update_media`, `delete_media` |
| Users and roles | `list_roles`, `create_user`, `update_user`, `set_user_role`, `send_password_reset` |
| Plugins, themes and core | `list_plugins`, `list_themes`, `search_plugin_directory`, `install_plugin`, `update_plugin`, `activate_plugin`, `switch_theme`, `update_core` |
| Site configuration | `get_option`, `update_option`, `set_theme_mod`, `update_custom_css`, `set_permalink_structure`, `create_menu`, `add_menu_item`, `update_widget`, `update_template`, `update_global_styles` |
| Operations | `get_cache_info`, `flush_object_cache`, `purge_page_cache`, `list_cron_events`, `run_cron_event`, `delete_expired_transients`, `flush_rewrite_rules` |
| Page builders | `get_elementor_page`, `get_bricks_page`, `get_breakdance_page`, `regenerate_builder_css` |
| WooCommerce (read tools) | `get_store_overview`, `list_orders`, `list_products` |
| Files (read only) | `list_files`, `read_file`, `search_files` |
| Approvals and receipts | `submit_plan`, `apply_change`, `get_change`, `list_receipts`, `rollback_change` |

This is a selection. Each tool's description in your AI app says what it does and what it needs. Write tools, and the approval and receipt tools, appear only while writes are on. Page-builder tools appear only when that builder is active, and WooCommerce tools only when WooCommerce is active. All tools except `get_site_info`, `list_recent_content` and `search_content` need an administrator.

## Installing

Requirements: WordPress 6.0 or later (tested up to 7.1), PHP 7.4 or later, and a site with a public HTTPS address (flitagent.com connects from the internet, so local and private sites cannot connect).

1. Get the plugin: download the zip from [flitagent.com](https://flitagent.com) and upload it under Plugins > Add New > Upload Plugin, or clone this repository into `wp-content/plugins/flitagent`:

   ```sh
   git clone https://github.com/FlitAgent/FlitAgent.git wp-content/plugins/flitagent
   ```

   Nothing to build: this repository holds the compiled `build/` that the release zip ships, next to its sources.
2. Activate FlitAgent and follow the [Quick start](#quick-start).

If the app cannot connect, open FlitAgent > Settings and click Test connection.

## Quick start

1. Install FlitAgent and activate it. A short setup guide opens.
2. The guide checks the host settings that most often stop flitagent.com from connecting, with a fix for each problem. Nothing is sent to flitagent.com yet.
3. Connect: click Connect to flitagent.com and log in at flitagent.com to approve the connection. Then click Set up for ChatGPT, Claude or Manual configuration, follow that app's steps (Copy buttons for what to paste: `https://mcp.flitagent.com/`, or the Claude Code and Codex commands), and log in to flitagent.com when the app asks. Guide: [Connect your AI app](https://flitagent.com/connect/), with sections for [ChatGPT](https://flitagent.com/connect/#chatgpt), [Claude](https://flitagent.com/connect/#claude) and [other clients](https://flitagent.com/connect/#manual).

flitagent.com connects from the internet, so the site needs a public HTTPS address. Local and private sites cannot connect.

## Safety model

- **Read only by default.** AI tools can only read until an administrator sets Access for AI tools to Read and propose changes, in step 4 of the setup guide or under FlitAgent > Settings.
- **Changes wait for approval** in FlitAgent > Approvals, with a preview of what will change and the exact arguments, unless the site's own policy auto-approves low-risk changes for that tool. Destructive changes, plugin, theme and core updates, changes that can't be rolled back, and plans are never auto-approved.
- **Receipts for everything** made, refused or failed, in FlitAgent > Activity.
- **Rollback.** Each receipt says whether the change can be rolled back; some can't, and those show a warning before you approve them.
- **Signed requests only.** The site accepts a request only when it carries a valid signature from flitagent.com, and the app acts as the administrator who connected the site. Access for AI tools (FlitAgent > Settings) is one setting for every app: Read only by default, or Read and propose changes. Receipts name the app and add "(via flitagent.com)".
- **Disconnect** in FlitAgent > Connect (or in your flitagent.com account) stops all requests.
- **Administrator boundaries.** An agent's user can't change, demote or grant capabilities to someone with administrator capabilities they can't use themselves.
- **What leaves the site.** Nothing goes to flitagent.com before Connect. Afterwards the plugin makes two kinds of request on its own. It fetches flitagent.com's public key set (daily; when an administrator clicks Connect; when the Connect screen opens, only while the site is connected; or on an unknown key id, at most every 300 seconds), which carries no site details. And when an administrator disconnects or sets up an AI tool, disconnects the site, or deletes the plugin, it tells flitagent.com with one POST that carries only the connection id; flitagent.com then asks the site, with a signed request, what changed. Everything else is the site answering flitagent.com.

## Compatibility

- WordPress 6.0 or later, tested up to 7.1
- PHP 7.4 or later
- AI apps: Claude, ChatGPT, Claude Code, Codex and other MCP clients
- Multisite: read tools work, write tools refuse for now

## Building from source

The plugin's PHP is in `includes/`. The wp-admin scripts and styles in `build/` are compiled, unminified, from the TypeScript and SCSS in `src/` with `@wordpress/scripts`. This repository holds both, from the same commit. To compile `build/` yourself you need Node 20.19 or later and pnpm:

```sh
pnpm install && pnpm build
```

The design tokens, fonts and mark are copied from the `@flit/design` package into `src/flit-design/` (GPL-2.0-or-later, the fonts under the SIL Open Font License 1.1), so the build needs nothing from outside this repository except the npm packages in `pnpm-lock.yaml`.

## Reporting issues

Open an issue at https://github.com/FlitAgent/FlitAgent/issues with the bug report template: what happened, the steps to reproduce, and your WordPress, PHP and FlitAgent versions and AI app. Remove secrets before posting. Report security problems privately through the [Security tab](https://github.com/FlitAgent/FlitAgent/security/advisories/new), not in a public issue.

## Changelog

See the Changelog section of [readme.txt](readme.txt).

## License

GPL-2.0-or-later, the same licence as WordPress. See [LICENSE](LICENSE).
