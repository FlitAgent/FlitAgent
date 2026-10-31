/**
 * A Copy button: `<button data-flitagent-copy="<input id>">` copies that input's value and briefly shows a check mark.
 * `<button data-flitagent-copy-text="<text>">` (the "is connected" dialog's first question) copies that text instead and
 * briefly shows `data-flitagent-copied` ("Copied") in its `[data-flitagent-copy-label]`, keeping its icon, and in the
 * polite live region `data-flitagent-copy-status` names, so screen readers hear it. Where nothing could be copied, it
 * says nothing and selects the text instead (the input, or the element `data-flitagent-copy-select` names), so the
 * keyboard's copy shortcut works.
 *
 * A classic script: it puts the class on window.flitagentAdmin, and admin.js attaches it.
 */
( function () {
	'use strict';

	const ns = ( window.flitagentAdmin = window.flitagentAdmin || {} );

	class CopyButton {
		/**
		 * @param {HTMLElement}           button The button.
		 * @param {HTMLInputElement|null} field  The input it copies, or null for a button with its own text.
		 */
		constructor( button, field ) {
			/** @type {HTMLElement} */
			this.button = button;

			/** @type {HTMLInputElement|null} */
			this.field = field;

			/** @type {string} */
			this.text = button.getAttribute( CopyButton.TEXT ) ?? '';

			/** @type {HTMLElement} */
			this.target =
				null === field
					? button.querySelector(
							`[${ CopyButton.LABEL }]`
					  ) ?? button
					: button;

			/** @type {string} */
			this.label = this.target.textContent ?? '';

			this.button.addEventListener( 'click', () => this.copy() );
		}

		/**
		 * Attaches a CopyButton to every Copy button under `root` whose input exists, and to every button with its own text.
		 *
		 * @param {ParentNode} [root=document] Where to look.
		 * @return {CopyButton[]} The buttons.
		 */
		static attachAll( root = document ) {
			const buttons = [];
			root.querySelectorAll( `[${ CopyButton.ATTRIBUTE }]` ).forEach( ( button ) => {
				const field = document.getElementById( button.getAttribute( CopyButton.ATTRIBUTE ) ?? '' );
				if ( field instanceof HTMLInputElement ) {
					buttons.push( new CopyButton( button, field ) );
				}
			} );
			root.querySelectorAll( `[${ CopyButton.TEXT }]` ).forEach( ( button ) => {
				buttons.push( new CopyButton( button, null ) );
			} );
			return buttons;
		}

		/**
		 * Copies the input's value (or the button's text), falling back to the selection where the Clipboard API is missing
		 * or refused. Confirms only what was copied; otherwise selects the text for the keyboard.
		 *
		 * @return {Promise<void>}
		 */
		async copy() {
			const value = null === this.field ? this.text : this.field.value;
			this.field?.select();
			let copied;
			try {
				await navigator.clipboard.writeText( value );
				copied = true;
			} catch {
				copied = this.copySelection( value );
			}
			if ( copied ) {
				this.confirm();
			} else {
				this.selectText();
			}
		}

		/**
		 * Copies the selection: the input's, or a read-only field holding the text that is added next to the button (inside
		 * its dialog, which keeps the rest of the page inert) only for the copy.
		 *
		 * @private
		 * @param {string} value The text.
		 * @return {boolean} Whether the browser copied it.
		 */
		copySelection( value ) {
			if ( null !== this.field ) {
				return CopyButton.copyCommand();
			}
			const field = document.createElement( 'textarea' );
			field.value = value;
			field.readOnly = true;
			field.className = 'screen-reader-text';
			this.button.after( field );
			field.select();
			const copied = CopyButton.copyCommand();
			field.remove();
			this.button.focus();
			return copied;
		}

		/**
		 * The browser's copy command, which reports false (or throws) where it is not allowed.
		 *
		 * @private
		 * @return {boolean} Whether the browser copied.
		 */
		static copyCommand() {
			try {
				return document.execCommand( 'copy' );
			} catch {
				return false;
			}
		}

		/**
		 * After a failed copy: selects the text, so the keyboard's copy shortcut works. The input gets focus with its value
		 * selected; a button's text is selected where it is shown.
		 *
		 * @private
		 */
		selectText() {
			if ( null !== this.field ) {
				this.field.focus();
				this.field.select();
				return;
			}
			const shown = document.getElementById(
				this.button.getAttribute( CopyButton.SELECT ) ?? ''
			);
			const selection = window.getSelection();
			if ( null === shown || null === selection ) {
				return;
			}
			const range = document.createRange();
			range.selectNodeContents( shown );
			selection.removeAllRanges();
			selection.addRange( range );
		}

		/**
		 * Shows a check mark (or the copied word, also in the live region), then the original label.
		 *
		 * @private
		 */
		confirm() {
			const copied = this.button.getAttribute( CopyButton.COPIED ) ?? '';
			const status =
				'' === copied
					? null
					: document.getElementById(
							this.button.getAttribute( CopyButton.STATUS ) ?? ''
					  );
			this.target.textContent = '' === copied ? '✓' : copied;
			if ( null !== status ) {
				status.textContent = copied;
			}
			window.setTimeout( () => {
				this.target.textContent = this.label;
				if ( null !== status ) {
					// Emptied, so the next copy is heard again.
					status.textContent = '';
				}
			}, CopyButton.CONFIRM_MS );
		}
	}

	/**
	 * Attribute naming the input a button copies.
	 */
	CopyButton.ATTRIBUTE = 'data-flitagent-copy';

	/**
	 * Attributes of a button that copies its own text: the text, the word it shows once copied, the element that word
	 * replaces, the id of its live region, and the id of the element showing the text (selected when copying failed).
	 */
	CopyButton.TEXT = 'data-flitagent-copy-text';
	CopyButton.COPIED = 'data-flitagent-copied';
	CopyButton.LABEL = 'data-flitagent-copy-label';
	CopyButton.STATUS = 'data-flitagent-copy-status';
	CopyButton.SELECT = 'data-flitagent-copy-select';

	/**
	 * Milliseconds the check mark stays.
	 */
	CopyButton.CONFIRM_MS = 1500;

	ns.CopyButton = CopyButton;
} )();
