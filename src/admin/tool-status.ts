/**
 * Live AI tool status on the Connect screen and setup step 3. The tool list (`[data-flitagent-tool-status]`, rendered
 * only while the site is connected) names the REST route (`GET flitagent/v1/admin/tools`) and its `wp_rest` nonce; each
 * row says its tool and the state it was rendered with. While a Set up dialog is open (up to ten minutes) the script
 * asks every three seconds, and once whenever the tab becomes visible or gets focus; meanwhile it marks the list live
 * (`data-flitagent-live`), which shows the dialogs' waiting line. When a tool's state differs from the page, the page
 * reloads, keeping an open Set up dialog open (`setup=<tool>`); if the tool that turned Connected is the one being set up (its dialog is open or the
 * script opened a tab for it), the script first closes that tab where the browser lets it (see closeTab() for why
 * chatgpt.com and claude.ai tabs cannot be) and the page comes back with its "is connected" dialog
 * (`flitagent_connected=<tool>`).
 *
 * A link `[data-flitagent-open-tool]` (Open ChatGPT Plugins, Open Claude connectors) opens its site in a tab named
 * `flitagent-<tool>` the script keeps a handle to: it opens a blank tab, drops its opener, then sends it on from inside
 * that tab without a referrer, so the other site cannot reach back to wp-admin or learn its address. If the browser opens no tab, the link works as a plain link. Failed
 * requests change nothing.
 */
export default class ToolStatus {
	/**
	 * Attribute of the tool list: the route's URL.
	 */
	static readonly LIST = 'data-flitagent-tool-status';

	/**
	 * Attribute of the tool list: the `wp_rest` nonce.
	 */
	static readonly NONCE = 'data-flitagent-nonce';

	/**
	 * Attribute of the tool list: the nonce a link to a dialog carries.
	 */
	static readonly VIEW_NONCE = 'data-flitagent-view-nonce';

	/**
	 * Attribute the script puts on the tool list while it is checking; the Set up dialogs' waiting line shows only then.
	 */
	static readonly LIVE = 'data-flitagent-live';

	/**
	 * Attributes of a row: its tool and the state it was rendered with.
	 */
	static readonly TOOL = 'data-flitagent-tool';
	static readonly STATE = 'data-flitagent-state';

	/**
	 * Attribute of a Set up dialog: its tool.
	 */
	static readonly SETUP = 'data-flitagent-setup-tool';

	/**
	 * Attribute of a link that opens a tool's site in a tab the script keeps.
	 */
	static readonly OPEN = 'data-flitagent-open-tool';

	/**
	 * Query arg of the page that shows a tool's "is connected" dialog.
	 */
	static readonly DONE = 'flitagent_connected';

	/**
	 * The states the route reports.
	 */
	static readonly CONNECTED = 'connected';
	static readonly NOT_CONNECTED = 'not_connected';

	/**
	 * How often to ask while a Set up dialog is open, and for how long, in milliseconds.
	 */
	static readonly INTERVAL = 3000;
	static readonly LIMIT = 600000;

	/**
	 * The current time in milliseconds (replaced in tests).
	 */
	static now: () => number = () => Date.now();

	private readonly list: HTMLElement;

	private readonly root: Document;

	private readonly win: Window;

	private readonly tabs = new Map< string, Window >();

	private openedAt: number | null = null;

	private busy = false;

	private leaving = false;

	constructor( list: HTMLElement, root: Document, win: Window ) {
		this.list = list;
		this.root = root;
		this.win = win;
	}

	/**
	 * Starts on a page that has the tool list.
	 *
	 * @param root Where to look.
	 * @param win  The window.
	 */
	static attachAll(
		root: Document = document,
		win: Window = window
	): ToolStatus | null {
		const list = root.querySelector< HTMLElement >(
			`[${ ToolStatus.LIST }]`
		);
		if ( null === list ) {
			return null;
		}
		const status = new ToolStatus( list, root, win );
		status.start();
		return status;
	}

	/**
	 * Wires the links that open a tool's site, the timer and the focus checks.
	 */
	start(): void {
		this.list.setAttribute( ToolStatus.LIVE, '' );
		this.root
			.querySelectorAll< HTMLAnchorElement >( `a[${ ToolStatus.OPEN }]` )
			.forEach( ( link ) => {
				link.addEventListener( 'click', ( event: Event ) =>
					this.openTab( link, event )
				);
			} );
		this.win.setInterval( () => this.tick(), ToolStatus.INTERVAL );
		this.root.addEventListener( 'visibilitychange', () => {
			if ( 'visible' === this.root.visibilityState ) {
				void this.check();
			}
		} );
		this.win.addEventListener( 'focus', () => void this.check() );
	}

	/**
	 * Opens a tool's site in the tab named for it, keeping the handle. A tab of that name already on the other site is
	 * only brought forward, so the steps the administrator is in are not lost.
	 *
	 * @param link  The link.
	 * @param event Its click.
	 */
	openTab( link: HTMLAnchorElement, event: Event ): void {
		const tool = link.getAttribute( ToolStatus.OPEN ) ?? '';
		let tab: Window | null = null;
		try {
			tab = this.win.open( '', `flitagent-${ tool }` );
		} catch {
			tab = null;
		}
		if ( null === tab ) {
			// No tab (a blocker): the link opens its own.
			return;
		}
		event.preventDefault();
		if ( ToolStatus.isBlank( tab ) ) {
			try {
				// Still this site's about:blank, so the opener can go before the other site loads.
				tab.opener = null;
			} catch {
				// Not ours to change.
			}
			ToolStatus.navigate( tab, link.href );
		}
		tab.focus();
		this.tabs.set( tool, tab );
	}

	/**
	 * Sends a blank tab of this site on without a referrer: a `rel="noreferrer"` link clicked inside the tab itself (a
	 * navigation the opener starts would send wp-admin's address). Where the tab's document cannot be used, the tab is
	 * sent on directly.
	 *
	 * @param tab  The blank tab.
	 * @param href Where to.
	 */
	private static navigate( tab: Window, href: string ): void {
		try {
			const doc = tab.document;
			const link = doc.createElement( 'a' );
			link.href = href;
			link.rel = 'noreferrer';
			( doc.body ?? doc.documentElement ).appendChild( link );
			link.click();
			return;
		} catch {
			// Fall back below.
		}
		tab.location.href = href;
	}

	/**
	 * Whether a tab is still this site's blank page (reading the address of another site's tab throws).
	 *
	 * @param tab The tab.
	 */
	private static isBlank( tab: Window ): boolean {
		try {
			return 'about:blank' === tab.location.href;
		} catch {
			return false;
		}
	}

	/**
	 * Every INTERVAL: asks while a Set up dialog is open, for at most LIMIT after it was first seen open.
	 */
	tick(): void {
		if ( '' === this.openSetup() ) {
			this.openedAt = null;
			this.list.setAttribute( ToolStatus.LIVE, '' );
			return;
		}
		const now = ToolStatus.now();
		if ( null === this.openedAt ) {
			this.openedAt = now;
		}
		if ( now - this.openedAt <= ToolStatus.LIMIT ) {
			this.list.setAttribute( ToolStatus.LIVE, '' );
			void this.check();
		} else {
			// Stopped: the dialog no longer says it updates by itself.
			this.list.removeAttribute( ToolStatus.LIVE );
		}
	}

	/**
	 * The tool of the open Set up dialog, or ''.
	 */
	openSetup(): string {
		const dialog = this.root.querySelector(
			`dialog[${ ToolStatus.SETUP }][open]`
		);
		return dialog?.getAttribute( ToolStatus.SETUP ) ?? '';
	}

	/**
	 * Asks the route and acts on the answer; one request at a time, and nothing once the page is leaving.
	 */
	async check(): Promise< void > {
		if ( this.busy || this.leaving ) {
			return;
		}
		this.busy = true;
		try {
			const response = await this.win.fetch(
				this.list.getAttribute( ToolStatus.LIST ) ?? '',
				{
					credentials: 'same-origin',
					cache: 'no-store',
					headers: {
						'X-WP-Nonce':
							this.list.getAttribute( ToolStatus.NONCE ) ?? '',
					},
				}
			);
			if ( response.ok ) {
				this.apply( await response.json() );
			}
		} catch {
			// Offline or not JSON: try again next time.
		} finally {
			this.busy = false;
		}
	}

	/**
	 * Compares the answer with the page and, when they differ, closes the tab of a tool that just connected and goes
	 * to the page that shows it.
	 *
	 * @param state The route's answer.
	 */
	apply( state: unknown ): void {
		if ( null === state || 'object' !== typeof state ) {
			return;
		}
		const { connected, tools } = state as {
			connected?: unknown;
			tools?: Record< string, unknown >;
		};
		if ( false === connected ) {
			// The site was disconnected meanwhile.
			this.leave( '' );
			return;
		}
		if ( true !== connected || null === tools || 'object' !== typeof tools ) {
			return;
		}
		const setup = this.openSetup();
		let changed = false;
		let done = '';
		this.list
			.querySelectorAll< HTMLElement >( `[${ ToolStatus.TOOL }]` )
			.forEach( ( row ) => {
				const tool = row.getAttribute( ToolStatus.TOOL ) ?? '';
				const now = tools[ tool ];
				if (
					( ToolStatus.CONNECTED !== now &&
						ToolStatus.NOT_CONNECTED !== now ) ||
					now === row.getAttribute( ToolStatus.STATE )
				) {
					return;
				}
				changed = true;
				if (
					ToolStatus.CONNECTED === now &&
					( setup === tool || this.tabs.has( tool ) ) &&
					( '' === done || setup === tool )
				) {
					done = tool;
				}
			} );
		if ( changed ) {
			if ( '' !== done ) {
				this.closeTab( done );
			}
			// Another tool changed while a dialog is open: the page comes back with that dialog still open.
			this.leave( done, '' === done ? setup : '' );
		}
	}

	/**
	 * Closes the tab the script opened for a tool, if it still holds it. A handle can already read closed without the
	 * tab being gone: a site that sends `Cross-Origin-Opener-Policy: same-origin` (chatgpt.com and claude.ai do) moves
	 * the tab out of reach, and so does the browser after a cross-site navigation of a tab whose opener was dropped.
	 * Then nothing can close it, so the "is connected" dialog says nothing about the tab.
	 *
	 * @param tool The tool.
	 */
	private closeTab( tool: string ): void {
		const tab = this.tabs.get( tool );
		this.tabs.delete( tool );
		if ( undefined === tab || tab.closed ) {
			return;
		}
		try {
			tab.close();
		} catch {
			// Not ours to close.
		}
	}

	/**
	 * Loads the page again: with the "is connected" dialog of `done`, or with the Set up dialog of `setup`, or neither.
	 *
	 * @param done  The tool that just connected, or ''.
	 * @param setup The tool whose Set up dialog stays open, or ''.
	 */
	private leave( done: string, setup = '' ): void {
		this.leaving = true;
		const url = new URL( this.win.location.href );
		url.searchParams.delete( 'setup' );
		url.searchParams.delete( ToolStatus.DONE );
		url.hash = '';
		if ( '' !== done ) {
			url.searchParams.set( ToolStatus.DONE, done );
		} else if ( '' !== setup ) {
			url.searchParams.set( 'setup', setup );
		}
		if ( '' !== done || '' !== setup ) {
			url.searchParams.set(
				'_wpnonce',
				this.list.getAttribute( ToolStatus.VIEW_NONCE ) ?? ''
			);
		}
		this.win.location.replace( url.toString() );
	}
}
