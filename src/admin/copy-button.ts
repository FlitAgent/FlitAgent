/**
 * A Copy button: `<button data-flitagent-copy="<input id>">` copies that input's value and briefly shows a check mark.
 * `<button data-flitagent-copy-text="<text>">` (the "is connected" dialog's first question) copies that text instead and
 * briefly shows `data-flitagent-copied` ("Copied") in its `[data-flitagent-copy-label]`, keeping its icon, and in the
 * polite live region `data-flitagent-copy-status` names, so screen readers hear it. Where nothing could be copied, it
 * says nothing and selects the text instead (the input, or the element `data-flitagent-copy-select` names), so the
 * keyboard's copy shortcut works.
 */
export default class CopyButton {
	/**
	 * Attribute naming the input a button copies.
	 */
	static readonly ATTRIBUTE = 'data-flitagent-copy';

	/**
	 * Attributes of a button that copies its own text: the text, the word it shows once copied, the element that word
	 * replaces, the id of its live region, and the id of the element showing the text (selected when copying failed).
	 */
	static readonly TEXT = 'data-flitagent-copy-text';
	static readonly COPIED = 'data-flitagent-copied';
	static readonly LABEL = 'data-flitagent-copy-label';
	static readonly STATUS = 'data-flitagent-copy-status';
	static readonly SELECT = 'data-flitagent-copy-select';

	/**
	 * Milliseconds the check mark stays.
	 */
	static readonly CONFIRM_MS = 1500;

	private readonly button: HTMLElement;

	private readonly field: HTMLInputElement | null;

	private readonly text: string;

	private readonly target: HTMLElement;

	private readonly label: string;

	constructor( button: HTMLElement, field: HTMLInputElement | null ) {
		this.button = button;
		this.field = field;
		this.text = button.getAttribute( CopyButton.TEXT ) ?? '';
		this.target =
			null === field
				? button.querySelector< HTMLElement >(
						`[${ CopyButton.LABEL }]`
				  ) ?? button
				: button;
		this.label = this.target.textContent ?? '';
		this.button.addEventListener( 'click', () => this.copy() );
	}

	/**
	 * Attaches a CopyButton to every Copy button under `root` whose input exists, and to every button with its own text.
	 *
	 * @param root Where to look.
	 */
	static attachAll( root: ParentNode = document ): CopyButton[] {
		const buttons: CopyButton[] = [];
		root.querySelectorAll< HTMLElement >( `[${ CopyButton.ATTRIBUTE }]` ).forEach( ( button ) => {
			const field = document.getElementById( button.getAttribute( CopyButton.ATTRIBUTE ) ?? '' );
			if ( field instanceof HTMLInputElement ) {
				buttons.push( new CopyButton( button, field ) );
			}
		} );
		root.querySelectorAll< HTMLElement >( `[${ CopyButton.TEXT }]` ).forEach( ( button ) => {
			buttons.push( new CopyButton( button, null ) );
		} );
		return buttons;
	}

	/**
	 * Copies the input's value (or the button's text), falling back to the selection where the Clipboard API is missing
	 * or refused. Confirms only what was copied; otherwise selects the text for the keyboard.
	 */
	async copy(): Promise< void > {
		const value = null === this.field ? this.text : this.field.value;
		this.field?.select();
		let copied: boolean;
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
	 * @param value The text.
	 * @return Whether the browser copied it.
	 */
	private copySelection( value: string ): boolean {
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
	 */
	private static copyCommand(): boolean {
		try {
			return document.execCommand( 'copy' );
		} catch {
			return false;
		}
	}

	/**
	 * After a failed copy: selects the text, so the keyboard's copy shortcut works. The input gets focus with its value
	 * selected; a button's text is selected where it is shown.
	 */
	private selectText(): void {
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
	 */
	private confirm(): void {
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
