/**
 * Dialogs on the Connect screen (#188): a trigger `[data-flitagent-dialog="<dialog id>"]` (a Disconnect button or a
 * Set up link) opens that native `<dialog>` as a modal instead of submitting its form or following its link. The
 * browser moves focus into the dialog and closes it on Escape; Cancel and Close are `<form method="dialog">` buttons.
 * Closing returns focus to the trigger that opened it. A dialog the server rendered open (`[data-flitagent-dialog-open]`,
 * from `&setup=<tool>`, or the "is connected" dialog from `&flitagent_connected=<tool>`) is shown as a modal too, and
 * closing it drops those from the address. Without this script
 * the triggers keep working as plain links and submit buttons.
 *
 * A classic script: it puts the class on window.flitagentAdmin, and admin.js attaches it.
 */
( function () {
	'use strict';

	const ns = ( window.flitagentAdmin = window.flitagentAdmin || {} );

	class Dialog {
		/**
		 * @param {HTMLDialogElement} dialog The dialog.
		 */
		constructor( dialog ) {
			/** @type {HTMLDialogElement} */
			this.dialog = dialog;

			/** @type {HTMLElement|null} */
			this.opener = null;

			this.fromServer = false;

			this.dialog.addEventListener( 'close', () => this.closed() );
		}

		/**
		 * Wires every trigger under `root` whose dialog exists, then shows a dialog the server rendered open.
		 *
		 * @param {Document} [root=document] Where to look.
		 * @return {Dialog[]} The dialogs.
		 */
		static attachAll( root = document ) {
			const dialogs = new Map();
			const triggers = new Map();
			root.querySelectorAll(
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
				trigger.addEventListener( 'click', ( event ) => {
					event.preventDefault();
					target.open( trigger );
				} );
			} );
			root.querySelectorAll(
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
		 * @private
		 * @param {Element|null} element Element.
		 * @return {boolean} Whether it is an HTMLDialogElement.
		 */
		static isDialog( element ) {
			return (
				null !== element &&
				'function' === typeof element.showModal
			);
		}

		/**
		 * Shows the dialog as a modal.
		 *
		 * @param {HTMLElement|null} opener The element focus returns to.
		 */
		open( opener ) {
			this.opener = opener;
			if ( ! this.dialog.open ) {
				this.dialog.showModal();
			}
		}

		/**
		 * Turns a dialog the server rendered open (in the page) into a modal.
		 *
		 * @private
		 * @param {HTMLElement|null} opener The element focus returns to.
		 */
		showFromServer( opener ) {
			this.fromServer = true;
			this.dialog.removeAttribute( Dialog.OPEN_ATTRIBUTE );
			this.dialog.removeAttribute( 'open' );
			this.open( opener );
		}

		/**
		 * After the dialog closed: focus goes back to its trigger and a server-opened dialog's `setup` leaves the address.
		 *
		 * @private
		 */
		closed() {
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
		 *
		 * @private
		 */
		static forgetSetup() {
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

	/**
	 * Attribute naming the dialog a trigger opens.
	 */
	Dialog.ATTRIBUTE = 'data-flitagent-dialog';

	/**
	 * Attribute of a dialog the server rendered open.
	 */
	Dialog.OPEN_ATTRIBUTE = 'data-flitagent-dialog-open';

	/**
	 * Query args that open a dialog from the address.
	 */
	Dialog.ADDRESS_PARAMS = [ 'setup', 'flitagent_connected' ];

	ns.Dialog = Dialog;
} )();
