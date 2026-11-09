/**
 * Connecting in a new tab. A connect form (`form[data-flitagent-connect]`, on the Connect screen and setup
 * step 3) opens a new tab from the click (without an opener) and posts there with `flitagent_tab=1`, so flitagent.com's
 * login and approval happen in that tab while this one waits: its status line says so, its submit button is disabled
 * and "Start again" (after the status line) enables it again while still listening. Whatever happens, flitagent.com's answer lands that tab on the Connect screen with
 * `[data-flitagent-tab-done]` (connected or failed) and the outcome's notice stored. That page tells the waiting tab
 * (a same-origin BroadcastChannel); the waiting tab answers `ack` and reloads, which shows the notice, and the new tab
 * then closes. With no answer, or if the browser keeps it open, the new tab shows the Connect screen itself. A tab
 * that shows a connect form but did not start connecting reloads when the site connects. A blocked popup or no script
 * leaves the form submitting in this tab, as before; inside the new tab itself the form submits there, still marked.
 */
export default class ConnectTab {
	/**
	 * Attribute of a connect form; its value is the status line for the waiting tab.
	 */
	static readonly FORM = 'data-flitagent-connect';

	/**
	 * Attribute of a connect form naming its "Start again" button.
	 */
	static readonly AGAIN = 'data-flitagent-connect-again';

	/**
	 * Attribute of the closing page's message; its value is the outcome.
	 */
	static readonly DONE = 'data-flitagent-tab-done';

	/**
	 * Query arg of the closing page.
	 */
	static readonly PARAM = 'flitagent_tab_done';

	/**
	 * Name of the new tab, and of the channel between the tabs.
	 */
	static readonly NAME = 'flitagent-connect';

	/**
	 * Form field that marks a connect from the new tab.
	 */
	static readonly FIELD = 'flitagent_tab';

	/**
	 * The outcomes the closing page sends, and the waiting tab's answer.
	 */
	static readonly CONNECTED = 'connected';
	static readonly FAILED = 'failed';
	static readonly ACK = 'ack';

	/**
	 * How long the closing page waits for an answer, in milliseconds.
	 */
	static readonly ACK_WAIT = 1500;

	/**
	 * How long a closing page that should have closed waits before showing the Connect screen, in milliseconds.
	 */
	static readonly CLOSE_WAIT = 500;

	/**
	 * Wires every connect form under `root`, listens while one is shown, and runs the closing page.
	 *
	 * @param root Where to look.
	 * @param win  The window.
	 */
	static attachAll( root: Document = document, win: Window = window ): void {
		const forms = root.querySelectorAll< HTMLFormElement >(
			`form[${ ConnectTab.FORM }]`
		);
		const state = { waiting: false };
		forms.forEach( ( form ) => {
			// A browser that restores form state on reload (Firefox) could bring back the disabled button.
			ConnectTab.enable( form );
			form.addEventListener( 'submit', () =>
				ConnectTab.submit( form, root, win, state )
			);
		} );
		const outcome =
			root
				.querySelector( `[${ ConnectTab.DONE }]` )
				?.getAttribute( ConnectTab.DONE ) ?? '';
		if ( 0 === forms.length && '' === outcome ) {
			return;
		}
		const channel = ConnectTab.channel( win );
		if ( forms.length > 0 && null !== channel ) {
			channel.addEventListener( 'message', ( event: Event ) => {
				const data = ( event as MessageEvent ).data;
				if ( ConnectTab.CONNECTED !== data && ConnectTab.FAILED !== data ) {
					return;
				}
				if ( state.waiting ) {
					channel.postMessage( ConnectTab.ACK );
					win.location.reload();
				} else if ( ConnectTab.CONNECTED === data ) {
					win.location.reload();
				}
			} );
		}
		if ( '' !== outcome ) {
			ConnectTab.handBack( outcome, channel, win );
		}
	}

	/**
	 * Sends a submitting form to the new tab, when the browser opens one.
	 *
	 * @param form  The connect form.
	 * @param root  Its document.
	 * @param win   The window.
	 * @param state Whether this tab is waiting for the new one.
	 * @param state.waiting
	 */
	static submit(
		form: HTMLFormElement,
		root: Document,
		win: Window,
		state: { waiting: boolean }
	): void {
		if ( ConnectTab.NAME === win.name ) {
			// Already the new tab (retrying after a failure): submit here, still marked, so the outcome is handed back.
			form.target = '';
			ConnectTab.mark( form, root );
			return;
		}
		const tab = win.open( '', ConnectTab.NAME );
		if ( null === tab ) {
			form.target = '';
			form.querySelector( `input[name="${ ConnectTab.FIELD }"]` )?.remove();
			return;
		}
		try {
			// Still this site's about:blank, so the opener can go before flitagent.com loads.
			tab.opener = null;
		} catch {
			// A tab of that name already showing another site: its opener is not ours to change.
		}
		form.target = ConnectTab.NAME;
		ConnectTab.mark( form, root );
		state.waiting = true;
		// Disabled after the browser has taken the form, so the submission goes ahead.
		win.setTimeout( () => ConnectTab.wait( form, root ), 0 );
		const status = form.querySelector( '.flitagent-connect-status' );
		if ( null !== status ) {
			status.textContent = form.getAttribute( ConnectTab.FORM ) ?? '';
		}
	}

	/**
	 * Adds the `flitagent_tab=1` field, once.
	 *
	 * @param form The connect form.
	 * @param root Its document.
	 */
	private static mark( form: HTMLFormElement, root: Document ): void {
		let field = form.querySelector< HTMLInputElement >(
			`input[name="${ ConnectTab.FIELD }"]`
		);
		if ( null === field ) {
			field = root.createElement( 'input' );
			field.type = 'hidden';
			field.name = ConnectTab.FIELD;
			form.appendChild( field );
		}
		field.value = '1';
	}

	/**
	 * Disables the form while the new tab works, and offers "Start again".
	 *
	 * @param form The connect form.
	 * @param root Its document.
	 */
	private static wait( form: HTMLFormElement, root: Document ): void {
		form.querySelectorAll< HTMLButtonElement >(
			'button[type="submit"]'
		).forEach( ( button ) => {
			button.disabled = true;
		} );
		const status = form.querySelector( '.flitagent-connect-status' );
		if ( null === status ) {
			return;
		}
		// After the live region, not in it, so it is not read out with the status line.
		const again = root.createElement( 'button' );
		again.type = 'button';
		again.className = 'button-link flitagent-connect-again';
		again.textContent = form.getAttribute( ConnectTab.AGAIN ) ?? '';
		again.addEventListener( 'click', () => {
			// Still waiting: if the other tab finishes after all, it is answered and its notice shows here.
			form.target = '';
			ConnectTab.enable( form );
			status.textContent = '';
			again.remove();
		} );
		status.insertAdjacentElement( 'afterend', again );
	}

	/**
	 * Enables a connect form's submit buttons.
	 *
	 * @param form The connect form.
	 */
	private static enable( form: HTMLFormElement ): void {
		form.querySelectorAll< HTMLButtonElement >(
			'button[type="submit"]'
		).forEach( ( button ) => {
			button.disabled = false;
		} );
	}

	/**
	 * The closing page: tells the waiting tab the outcome, closes once it answers, and otherwise shows the Connect
	 * screen (without the marker, so the stored notice shows here).
	 *
	 * @param outcome CONNECTED or FAILED.
	 * @param channel The channel, or null.
	 * @param win     The window.
	 */
	private static handBack(
		outcome: string,
		channel: BroadcastChannel | null,
		win: Window
	): void {
		const show = () =>
			win.location.replace( ConnectTab.without( win.location.href ) );
		if ( null === channel ) {
			show();
			return;
		}
		let answered = false;
		channel.addEventListener( 'message', ( event: Event ) => {
			if ( answered || ConnectTab.ACK !== ( event as MessageEvent ).data ) {
				return;
			}
			answered = true;
			win.close();
			win.setTimeout( show, ConnectTab.CLOSE_WAIT );
		} );
		channel.postMessage( outcome );
		win.setTimeout( () => {
			if ( ! answered ) {
				show();
			}
		}, ConnectTab.ACK_WAIT );
	}

	/**
	 * A URL without the closing page's marker.
	 *
	 * @param href URL.
	 */
	private static without( href: string ): string {
		const url = new URL( href );
		url.searchParams.delete( ConnectTab.PARAM );
		return url.toString();
	}

	/**
	 * The channel between the tabs, or null where the browser has none.
	 *
	 * @param win The window.
	 */
	private static channel( win: Window ): BroadcastChannel | null {
		const scope = win as Window & typeof globalThis;
		return 'function' === typeof scope.BroadcastChannel
			? new scope.BroadcastChannel( ConnectTab.NAME )
			: null;
	}
}
