=== FlitAgent ===
Contributors: flitagent
Tags: ai, mcp, agents, site management, approvals
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI assistants such as Claude and ChatGPT work on your site through typed tools and flitagent.com, with changes approved in wp-admin.

== Description ==

FlitAgent lets Claude, ChatGPT and other AI apps work on your WordPress site, and nothing changes until you approve it, unless you turn on auto-approve for a low-risk tool. Ask in plain words for a health check, a content fix or a plugin update. The AI proposes the change, you see a before-and-after preview in wp-admin, and you approve or reject it. Every change gets a receipt, and most can be rolled back.

FlitAgent works together with the flitagent.com service and needs a free flitagent.com account. The Free plan includes 100 tool calls a day; paid plans raise the daily limit (see the FAQ).

= An MCP server for WordPress =

FlitAgent gives your site a Model Context Protocol (MCP) endpoint, the open standard AI apps use to call tools. Your AI app connects once to `https://mcp.flitagent.com/`, and flitagent.com relays each request to your site, which checks it and answers.

Every action is a named tool with checked inputs. No tool accepts SQL queries or shell commands. Changes that add code, such as scripts, shortcodes and page-builder code elements, always wait for your approval.

= Connect Claude to WordPress =

Add `https://mcp.flitagent.com/` to Claude as a custom connector and log in to flitagent.com when Claude asks. Then ask about your site in any chat: "What needs attention on my site?" FlitAgent > Connect shows the steps, with a Copy button for the address.

= Connect ChatGPT to WordPress =

Add `https://mcp.flitagent.com/` to ChatGPT as a connector and log in to flitagent.com when ChatGPT asks. FlitAgent > Connect walks you through it, and the [Connect your AI app](https://flitagent.com/connect/) guide explains each step.

= Connect Claude Code, Codex and other MCP apps =

Any MCP app that supports remote servers with OAuth sign-in works. FlitAgent > Connect gives you the command to copy: `claude mcp add --transport http flitagent https://mcp.flitagent.com/` for Claude Code, or `codex mcp add flitagent --url https://mcp.flitagent.com/` for Codex. In other apps, add a remote (Streamable HTTP) server with the same address.

= Check your whole site in one question =

`get_health_triage` checks the site at once and lists what is wrong, how serious it is and which tool fixes it: pending updates, inactive plugins and unused themes, overdue cron tasks, stuck maintenance mode, PHP errors shown to visitors and critical Site Health issues. `get_content_qa` finds missing alt text, broken internal links, SEO basics and stale content. Apps can also read site health, plugins and themes, settings, cron, caches and files. Read tools never change anything.

= Manage content and settings by asking =

AI apps can propose changes to posts and pages, terms and comments, media, users and roles, site settings, menus, widgets, block templates and global styles, and run cache and cron operations. Several fixes can go together as one plan with one approval, and you can untick any item.

= WooCommerce, page builders and WordPress abilities =

AI apps can read WooCommerce products, orders and a store overview (order counts and revenue). FlitAgent has no tools that change orders, prices or stock; products, product categories and reviews can be changed through the general content tools, with your approval. Elementor and Beaver Builder layouts can be read, and saved after your approval, when the builder is active on the site. On WordPress 6.9 or later, abilities that WordPress and other plugins register can be listed and run; running one waits for your approval unless it is declared read-only.

= Plugin, theme and core updates, checked afterwards =

AI apps can install, update and delete plugins and themes and update WordPress core (`install_plugin`, `update_plugin`, `update_core` and so on). These always wait for your approval. After updating WordPress core or an active plugin or theme, FlitAgent loads the home page and warns you if it shows a server or PHP error.

= Approvals, receipts and rollback =

* Every change is previewed ("Preview: nothing has changed yet") and waits for an administrator in FlitAgent > Approvals, unless the site's own policy auto-approves low-risk changes for a tool. Auto-approve is offered only for low-risk content, term, comment and cache tools. Destructive changes, plugin, theme and core updates, changes to existing categories, tags and media, changes that can't be rolled back (apart from clearing caches, which rebuild themselves) and plans always wait.
* Every change gets a receipt in FlitAgent > Activity, naming the app that asked and how the change was approved.
* Each receipt says whether the change can be rolled back; the AI app calls `rollback_change` with the receipt. A rollback follows the same approval rules as any other change: it waits for you unless you ticked `rollback_change` for auto-approve, and undoing another app's change always waits. Some changes can't be rolled back, and the change's summary says so before you approve it.

= Access for AI tools =

One setting decides what every AI tool connected to the site may do: Read and propose changes, the default, or Read only. You choose it when you approve the site on flitagent.com, and can change it at any time under FlitAgent > Settings or in step 4 of the setup guide. A change applies straight away, with no need to reconnect.

FlitAgent > Connect shows the connection (account, access, when flitagent.com last made a request) with a Disconnect site button, and the AI tools that reached your site. Set each one up from there, or disconnect one tool while the others keep working. Disconnecting the site in your flitagent.com account disconnects it here too.

= Who FlitAgent is for =

**Site owners** who want to ask an AI app what needs fixing and approve the fix, without logging in to check every screen.

**Developers** who work in Claude Code, Codex or another MCP app and want to read and change a WordPress site from there, with a receipt for every change.

**Agencies** who look after many sites: one flitagent.com account connects up to 50 sites, each with its own approvals and receipts.

= Features =

* MCP endpoint at `https://mcp.flitagent.com/` for Claude, ChatGPT, Claude Code, Codex and other MCP apps
* Named tools with checked inputs; no SQL or shell commands
* Whole-site health check and content checks
* Changes to content, media, users, settings, menus, widgets, templates and styles, with approval
* Plugin, theme and core installs and updates, always with approval; the home page is checked after updates
* WooCommerce products, orders and store overview (read)
* Elementor and Beaver Builder layouts
* WordPress abilities (WordPress 6.9 or later)
* Before-and-after preview for every change
* Plans: several changes, one approval
* Receipts and rollback
* Access for AI tools: Read and propose changes or Read only
* Auto-approve per tool for low-risk changes
* Setup guide that checks HTTPS, permalinks and the MCP endpoint before you connect

= Third-party service =

FlitAgent needs the flitagent.com service. Nothing is sent before an administrator clicks Connect; after that, your AI app's requests and their results pass through flitagent.com and are not stored there. External services below lists what is sent, when and why. [Terms of service](https://flitagent.com/terms/), [privacy policy](https://flitagent.com/privacy/).

= Source code =

https://github.com/FlitAgent/flitagent

= Resources =

* Website: https://flitagent.com
* Connect your AI app: https://flitagent.com/connect/
* Documentation: https://flitagent.com/docs/
* Plans and pricing: https://flitagent.com/pricing/
* Security: https://flitagent.com/security/

== Installation ==

1. Install FlitAgent from Plugins > Add Plugin, or upload the zip under Plugins > Add Plugin > Upload Plugin, and activate it. You also need a free account at https://flitagent.com.
2. A short setup guide opens. It checks the site for the host settings that most often stop flitagent.com from connecting (HTTPS, permalinks, the MCP endpoint, custom request headers), with a fix for each problem. Nothing is sent to flitagent.com while it runs.
3. Connect: click Connect to flitagent.com and log in at flitagent.com to approve the connection. Then click Set up for ChatGPT, Claude or Manual configuration (Claude Code, Codex and other MCP clients): each shows that app's steps, with Copy buttons for what to paste (`https://mcp.flitagent.com/`, or the Claude Code and Codex commands). Log in to flitagent.com when the app asks.
4. Choose what AI tools may do on the site: Read and propose changes (the default) or Read only. You can change it at any time under FlitAgent > Settings.

flitagent.com connects to your site from the internet, so the site needs a public HTTPS address. Local and private sites cannot connect.

== Frequently Asked Questions ==

= My site can't connect. What should I check? =

The setup guide checks the host settings that most often stop flitagent.com from connecting (HTTPS, permalinks, the MCP endpoint, custom request headers) and shows a fix for each problem. flitagent.com connects to your site from the internet, so the site needs a public HTTPS address; local and private sites cannot connect.

= Is FlitAgent free? Do I need an AI subscription? =

The plugin is free. It needs a flitagent.com account, and the Free plan includes 100 tool calls a day across all your sites. Paid plans raise the daily limit and add faster email help; no feature is held back for them. See [plans and pricing](https://flitagent.com/pricing/). You use your own Claude, ChatGPT or other AI app account, and FlitAgent never charges for the AI itself.

= Which AI apps work with FlitAgent? =

Claude, ChatGPT, Claude Code, Codex and any other MCP app that supports remote servers with OAuth sign-in. FlitAgent > Connect has steps for each.

= Is FlitAgent a WordPress MCP server? =

Yes. Your AI app connects to the MCP server at `https://mcp.flitagent.com/`, which relays each tool call to your site. The tools themselves run on your site, as the administrator who connected it.

= Can the AI change my site without me? =

Only if you allow it. Every change waits for your approval in FlitAgent > Approvals, unless you turn on auto-approve for a low-risk tool. Access for AI tools is Read and propose changes by default, and you can choose Read only when you approve the site on flitagent.com or later under FlitAgent > Settings.

= Can a change be undone? =

Often. Each receipt in FlitAgent > Activity says whether the change can be rolled back; your AI app calls `rollback_change` with the receipt. Some changes can't, such as permanent deletes, core updates and running a scheduled task, and the change's summary says so before you approve it.

= Can the AI break my site? =

Every change waits for your approval with a preview, unless you turn on auto-approve for a low-risk tool, and destructive changes and updates are never auto-approved. After updating WordPress core or an active plugin or theme, FlitAgent loads the home page and warns you if it shows a server or PHP error. Most changes can be rolled back: ask your AI app to roll back the change by its receipt. Keep your usual backups too.

= Who can connect? =

An administrator (a user who can manage options) connects the site to flitagent.com, and requests from your AI apps act as that user. Access for AI tools (FlitAgent > Settings) is one setting for every app: Read only or Read and propose changes. Each receipt names the app. If the connecting user loses administrator access, requests are refused until an administrator connects again.

= Does FlitAgent send my data to flitagent.com? =

Nothing is sent before an administrator clicks Connect. After that, your AI app's requests and their results pass through flitagent.com and are not stored there. flitagent.com keeps usage metadata (raw events for 90 days, daily totals for 25 months) and basic details about the site, such as its name, address, access and versions. On its own, the plugin fetches flitagent.com's public keys and sends short notices that carry only the connection id. External services and Privacy below list everything.

= Why can't an administrator change another administrator through FlitAgent on my host? =

FlitAgent never lets an agent's user change, demote or grant capabilities to someone with administrator capabilities they cannot use themselves. Some hosts turn plugin and theme installs off with a filter rather than the `DISALLOW_FILE_MODS` setting; FlitAgent cannot tell that apart from a restriction on one user, so on those hosts it refuses such changes. Make them in wp-admin instead.

= Does it work with WooCommerce? =

AI apps can read products, orders and a store overview (order counts and revenue). FlitAgent has no tools that change orders, prices or stock. Products, product categories and reviews can be changed through the general content tools, with your approval.

= Does it work with Elementor and Beaver Builder? =

Elementor and Beaver Builder layouts can be read, and saved after your approval, when the builder is active on the site.

= Can I connect more than one site? =

Yes. One flitagent.com account connects up to 50 sites, on every plan. They share the account's daily limit of tool calls, which resets at midnight UTC.

= Do I need to know how to code? =

No. You ask your AI app in plain words, and you approve or reject each change on a screen that shows what will change. Developers can also use FlitAgent from Claude Code, Codex and other MCP apps.

= Does it work on multisite? =

Read tools work. Write tools refuse on multisite for now.

== Screenshots ==

1. A change waiting for approval: what it will change, before and after, the exact arguments that will run, and Approve or Reject.
2. Approvals: every change an AI app proposed, which app asked and as which user, with a Review button for each.
3. Activity: a receipt for every change made, refused or failed, with how it was approved and whether it can be rolled back.

== External services ==

FlitAgent needs the flitagent.com service. Nothing is sent to it before an administrator clicks Connect.

* **flitagent.com** (https://flitagent.com, https://mcp.flitagent.com) connects your AI apps to this site. When you click Connect, your browser goes to flitagent.com with this site's address, the access you chose and a one-time code, and you log in and approve the site. After that, your AI app's requests and their results pass through flitagent.com and are not stored there. The plugin also checks flitagent.com's security keys (once a day and when FlitAgent > Connect opens), and tells flitagent.com when you disconnect or set up an AI tool, disconnect the site or delete the plugin, and at most once an hour when FlitAgent > Connect opens; these messages carry no more than the connection id. flitagent.com keeps usage metadata (raw events for 90 days, daily totals for 25 months) and basic details about the site: its name, address, access, versions and connection status. [Terms of service](https://flitagent.com/terms/), [privacy policy](https://flitagent.com/privacy/)
* **WordPress.org** (api.wordpress.org, downloads.wordpress.org) for checksums, plugin directory searches, and installing, updating and deleting plugins and themes and updating core. Sent: the plugin or theme name and version, or the search terms, plus the site address, WordPress version and language that WordPress sends with these requests. Packages download only after your approval. [Privacy policy](https://wordpress.org/about/privacy/)
* **Addresses an approved tool fetches.** `upload_media_from_url` downloads the file at the address the AI app gives, only after you approve the change.
* **ChatGPT and Claude** (https://chatgpt.com, https://claude.ai). The Set up dialog's Try now link opens a new chat with a first question filled in; nothing is sent unless you click it. [OpenAI terms](https://openai.com/policies/terms-of-use/) and [privacy policy](https://openai.com/policies/privacy-policy/); [Anthropic terms](https://www.anthropic.com/legal/consumer-terms) and [privacy policy](https://www.anthropic.com/legal/privacy)

== Privacy ==

FlitAgent stores change requests, approvals and receipts (tool, arguments, preview, result, the data needed to roll back, user, app name and time) in its own database tables on your site, the connection to flitagent.com (connection id, the connecting administrator, account hint, access, time, and flitagent.com's public keys), and the AI apps that reached your site through it (app id and name, access, when each was first and last seen; at most 20) with the tools you disconnected. It holds no tokens or passwords for your AI apps. Nothing is sent to FlitAgent's developers before you click Connect. Once connected, tool calls and results pass through flitagent.com and are not stored there; flitagent.com keeps usage metadata (raw events for 90 days, daily totals for 25 months), as described in its [privacy policy](https://flitagent.com/privacy/). flitagent.com also stores this site's name, address and access, its FlitAgent, WordPress and PHP versions, and which AI tools are disconnected, and caches the list of tools. It also keeps when the site was connected and the last connection error. Receipts are kept when the plugin is deactivated. "Delete FlitAgent's receipts, change sets and settings when the plugin is deleted" under Settings is ticked by default, so deleting the plugin removes them; untick it and save to keep them, with the settings and the finished setup guide, for a reinstall. Deleting the plugin removes the connection from your site and tells flitagent.com, which disconnects the site and its AI tools (if flitagent.com cannot be reached, or on a large network, the site only shows as unreachable there; disconnect it in your flitagent.com account); after a reinstall you connect again and set up each AI tool again. A reinstall with kept data skips the setup guide; connect again under FlitAgent > Connect.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
AI tools can read your site and propose changes, and nothing changes until you approve it in FlitAgent → Approvals, unless you turn on auto-approve for a low-risk tool. Choose Read only or Read and propose changes, the default, under Access for AI tools in FlitAgent → Settings.
