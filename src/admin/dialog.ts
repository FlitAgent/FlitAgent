/**
 * Dialogs on the Connect screen: a trigger `[data-flitagent-dialog="<dialog id>"]` (a Disconnect button or a
 * Set up link) opens that native `<dialog>` as a modal instead of submitting its form or following its link. The
 * browser moves focus into the dialog and closes it on Escape; Cancel and Close are `<form method="dialog">` buttons.
 * Closing returns focus to the trigger that opened it. A dialog the server rendered open (`[data-flitagent-dialog-open]`,
 * from `&setup=<tool>`, or the "is connected" dialog from `&flitagent_connected=<tool>`) is shown as a modal too, and
 * closing it drops those from the address. Without this script
 * the triggers keep working as plain links and submit buttons.
 */
export default class Dialog {
	/**
	 * Attribute naming the dialog a trigger opens.
	 */
	static readonly ATTRIBUTE = 'data-flitagent-dialog';

	/**
	 * Attribute of a dialog the server rendered open.
	 */
	static readonly OPEN_ATTRIBUTE = 'data-flitagent-dialog-open';

	/**
	 * Query args that open a dialog from the address.
	 */
	static readonly ADDRESS_PARAMS = [ 'setup', 'flitagent_connected' ];

	private readonly dialog: HTMLDialogElement;

	private opener: HTMLElement | null = null;

	private fromServer = false;

	constructor( dialog: HTMLDialogElement ) {
		this.dialog = dialog;
		this.dialog.addEventListener( 'close', () => this.closed() );
	}

	/**
	 * Wires every trigger under `root` whose dialog exists, then shows a dialog the server rendered open.
	 *
	 * @param root Where to look.
	 */
	static attachAll( root: Document = document ): Dialog[] {
		const dialogs = new Map< string, Dialog >();
		const triggers = new Map< string, HTMLElement >();
		root.querySelectorAll< HTMLElement >(
			`[${ Dialog.ATTRIBUTE }]`
		).forEach( ( trigger ) => {
			const id = trigger.getAttribute( Dialog.ATTRIBUTE ) ?? '';
			const element = root.getElementById( id );
			if ( ! Dialog.isDialog( element ) ) {
				return;
			}
			let dialog = dialogs.get( id );
			if ( undefined === dialog ) {
				dialog = new Dialog( element );
				dialogs.set( id, dialog );
				triggers.set( id, trigger );
			}
			const target = dialog;
			trigger.addEventListener( 'click', ( event: Event ) => {
				event.preventDefault();
				target.open( trigger );
			} );
		} );
		root.querySelectorAll< HTMLElement >(
			`dialog[${ Dialog.OPEN_ATTRIBUTE }]`
		).forEach( ( element ) => {
			if ( ! Dialog.isDialog( element ) ) {
				return;
			}
			const dialog = dialogs.get( element.id ) ?? new Dialog( element );
			dialog.showFromServer( triggers.get( element.id ) ?? null );
		} );
		return [ ...dialogs.values() ];
	}

	/**
	 * Whether an element is a dialog the browser can show as a modal.
	 *
	 * @param element Element.
	 */
	private static isDialog(
		element: Element | null
	): element is HTMLDialogElement {
		return (
			null !== element &&
			'function' === typeof ( element as HTMLDialogElement ).showModal
		);
	}

	/**
	 * Shows the dialog as a modal.
	 *
	 * @param opener The element focus returns to.
	 */
	open( opener: HTMLElement | null ): void {
		this.opener = opener;
		if ( ! this.dialog.open ) {
			this.dialog.showModal();
		}
	}

	/**
	 * Turns a dialog the server rendered open (in the page) into a modal.
	 *
	 * @param opener The element focus returns to.
	 */
	private showFromServer( opener: HTMLElement | null ): void {
		this.fromServer = true;
		this.dialog.removeAttribute( Dialog.OPEN_ATTRIBUTE );
		this.dialog.removeAttribute( 'open' );
		this.open( opener );
	}

	/**
	 * After the dialog closed: focus goes back to its trigger and a server-opened dialog's `setup` leaves the address.
	 */
	private closed(): void {
		if ( this.dialog.open ) {
			return;
		}
		this.opener?.focus();
		if ( this.fromServer ) {
			this.fromServer = false;
			Dialog.forgetSetup();
		}
	}

	/**
	 * Drops `setup` or the "is connected" dialog's `flitagent_connected` (and the fragment that scrolled to the dialog)
	 * from the address, so a reload does not reopen it.
	 */
	private static forgetSetup(): void {
		const url = new URL( window.location.href );
		const params = Dialog.ADDRESS_PARAMS.filter( ( name ) =>
			url.searchParams.has( name )
		);
		if ( 0 === params.length ) {
			return;
		}
		params.forEach( ( name ) => url.searchParams.delete( name ) );
		url.hash = '';
		window.history.replaceState( null, '', url.toString() );
	}
}
