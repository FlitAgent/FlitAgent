/**
 * The check step's Run / Check again links (`<a data-flitagent-run="Checking…">`): the checks run on the server while the
 * page loads, which can take a while and would leave a blank screen. So the page stays, the link shows a spinner and the
 * given text, and the checked card is fetched and swapped in. If anything fails, the link is simply followed.
 */
export default class RunCheck {
	/**
	 * Attribute holding the text shown while the checks run.
	 */
	static readonly ATTRIBUTE = 'data-flitagent-run';

	/**
	 * The setup guide's card, which holds the step.
	 */
	private static readonly CARD = '.flitagent-onboarding > .flitagent-card';

	/**
	 * Class of the check step's card.
	 */
	private static readonly CHECK_CARD = 'flitagent-onboarding-card--check';

	private readonly link: HTMLAnchorElement;

	private readonly label: string;

	constructor( link: HTMLAnchorElement ) {
		this.link = link;
		this.label = link.textContent ?? '';
		link.addEventListener( 'click', ( event ) => this.start( event ) );
		// Back-forward cache: coming back to the page must not show a finished spinner.
		window.addEventListener( 'pageshow', () => this.reset() );
	}

	/**
	 * Attaches a RunCheck to every link under `root`.
	 *
	 * @param root Where to look.
	 */
	static attachAll( root: ParentNode = document ): RunCheck[] {
		return Array.from( root.querySelectorAll< HTMLAnchorElement >( `a[${ RunCheck.ATTRIBUTE }]` ) ).map( ( link ) => new RunCheck( link ) );
	}

	/**
	 * Shows the spinner and lets the navigation go on; a second click while it runs does nothing.
	 *
	 * @param event The click.
	 */
	private start( event: MouseEvent ): void {
		if ( this.link.classList.contains( 'is-running' ) ) {
			event.preventDefault();
			return;
		}
		// A new tab or window keeps this page as it is.
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}
		event.preventDefault();
		this.link.classList.add( 'is-running' );
		this.link.setAttribute( 'aria-busy', 'true' );
		this.link.textContent = this.link.getAttribute( RunCheck.ATTRIBUTE ) ?? this.label;
		// Announced to screen readers: a link's changed text is not.
		this.link.setAttribute( 'role', 'status' );
		const url = this.link.href;
		RunCheck.fetchCard( url ).then(
			( card ) => RunCheck.swap( card, url ),
			() => window.location.assign( url )
		);
	}

	/**
	 * Starts the checks the page shows as in progress (`[data-flitagent-autorun]`, the check step reached from the
	 * previous step), and swaps in the finished step.
	 *
	 * @param root Where to look.
	 */
	static autoRun( root: ParentNode = document ): void {
		const pending = root.querySelector< HTMLElement >( '[data-flitagent-autorun]' );
		const url = pending?.dataset.flitagentAutorun ?? '';
		if ( ! pending ) {
			return;
		}
		if ( ! RunCheck.isAdminUrl( url ) ) {
			RunCheck.offerLink( pending );
			return;
		}
		RunCheck.fetchCard( url ).then(
			( card ) => RunCheck.swap( card, url ),
			() => window.location.assign( url )
		);
	}

	/**
	 * Whether an address is on this site's wp-admin: same origin, in the folder of this admin page. A step's address is always
	 * one on a usual site, and nothing else may be fetched or followed by script: wp_kses() does not look at a data attribute's
	 * protocol, but it does look at a link's.
	 *
	 * @param url The address.
	 */
	private static isAdminUrl( url: string ): boolean {
		if ( '' === url ) {
			return false;
		}
		try {
			const here = window.location.href;
			const target = new URL( url, here );
			return target.origin === new URL( here ).origin && target.pathname.startsWith( new URL( './', here ).pathname );
		} catch {
			return false;
		}
	}

	/**
	 * In place of checks that won't start: the step's own Run the checks link, which a person can follow, and no progress bar.
	 *
	 * @param pending The step's in-progress block.
	 */
	private static offerLink( pending: HTMLElement ): void {
		pending.querySelector( '.flitagent-checking-run' )?.removeAttribute( 'hidden' );
		pending.querySelector( '.flitagent-checking-text' )?.remove();
		pending.querySelector( '.flitagent-progress' )?.remove();
	}

	private static swap( card: Element, url: string ): void {
		document.querySelector( RunCheck.CARD )?.replaceWith( card );
		window.history.replaceState( null, '', url );
		RunCheck.attachAll( card );
	}

	/**
	 * Fetches the page at `url` and returns its setup card.
	 *
	 * @param url The step's address, with the checks switched on.
	 */
	private static async fetchCard( url: string ): Promise< Element > {
		const response = await window.fetch( url, { credentials: 'same-origin' } );
		if ( ! response.ok ) {
			throw new Error( 'Not OK' );
		}
		const card = new DOMParser().parseFromString( await response.text(), 'text/html' ).querySelector( RunCheck.CARD );
		// Another step's card means the link's nonce has expired: follow the link rather than swap that card in.
		if ( ! card || ! document.querySelector( RunCheck.CARD ) || ! card.classList.contains( RunCheck.CHECK_CARD ) ) {
			throw new Error( 'No card' );
		}
		return card;
	}

	private reset(): void {
		this.link.classList.remove( 'is-running' );
		this.link.removeAttribute( 'aria-busy' );
		this.link.removeAttribute( 'role' );
		this.link.textContent = this.label;
	}
}
