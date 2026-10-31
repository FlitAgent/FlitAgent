/**
 * The check step's Run / Check again links (`<a data-flitagent-run="Checking…">`): the checks run on the server while the
 * page loads, which can take a while and would leave a blank screen. So the page stays, the link shows a spinner and the
 * given text, and the checked card is fetched and swapped in. If anything fails, the link is simply followed.
 *
 * A classic script: it puts the class on window.flitagentAdmin, and admin.js attaches it.
 */
( function () {
	'use strict';

	const ns = ( window.flitagentAdmin = window.flitagentAdmin || {} );

	class RunCheck {
		/**
		 * @param {HTMLAnchorElement} link The link.
		 */
		constructor( link ) {
			/** @type {HTMLAnchorElement} */
			this.link = link;

			/** @type {string} */
			this.label = link.textContent ?? '';

			link.addEventListener( 'click', ( event ) => this.start( event ) );
			// Back-forward cache: coming back to the page must not show a finished spinner.
			window.addEventListener( 'pageshow', () => this.reset() );
		}

		/**
		 * Attaches a RunCheck to every link under `root`.
		 *
		 * @param {ParentNode} [root=document] Where to look.
		 * @return {RunCheck[]} The links' RunChecks.
		 */
		static attachAll( root = document ) {
			return Array.from( root.querySelectorAll( `a[${ RunCheck.ATTRIBUTE }]` ) ).map( ( link ) => new RunCheck( link ) );
		}

		/**
		 * Shows the spinner and lets the navigation go on; a second click while it runs does nothing.
		 *
		 * @private
		 * @param {MouseEvent} event The click.
		 */
		start( event ) {
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
		 * @param {ParentNode} [root=document] Where to look.
		 */
		static autoRun( root = document ) {
			const pending = root.querySelector( '[data-flitagent-autorun]' );
			const url = pending?.dataset.flitagentAutorun ?? '';
			if ( ! pending || '' === url ) {
				return;
			}
			RunCheck.fetchCard( url ).then(
				( card ) => RunCheck.swap( card, url ),
				() => window.location.assign( url )
			);
		}

		/**
		 * Puts the fetched card in place of the page's, with its address, and wires its links.
		 *
		 * @private
		 * @param {Element} card The fetched setup card.
		 * @param {string}  url  Its address.
		 */
		static swap( card, url ) {
			document.querySelector( RunCheck.CARD )?.replaceWith( card );
			window.history.replaceState( null, '', url );
			RunCheck.attachAll( card );
		}

		/**
		 * Fetches the page at `url` and returns its setup card.
		 *
		 * @private
		 * @param {string} url The step's address, with the checks switched on.
		 * @return {Promise<Element>} The card.
		 */
		static async fetchCard( url ) {
			const response = await window.fetch( url, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'Not OK' );
			}
			const card = new DOMParser().parseFromString( await response.text(), 'text/html' ).querySelector( RunCheck.CARD );
			if ( ! card || ! document.querySelector( RunCheck.CARD ) ) {
				throw new Error( 'No card' );
			}
			return card;
		}

		/**
		 * The link as it was before the click.
		 *
		 * @private
		 */
		reset() {
			this.link.classList.remove( 'is-running' );
			this.link.removeAttribute( 'aria-busy' );
			this.link.removeAttribute( 'role' );
			this.link.textContent = this.label;
		}
	}

	/**
	 * Attribute holding the text shown while the checks run.
	 */
	RunCheck.ATTRIBUTE = 'data-flitagent-run';

	/**
	 * The setup guide's card, which holds the step.
	 *
	 * @private
	 */
	RunCheck.CARD = '.flitagent-onboarding > .flitagent-card';

	ns.RunCheck = RunCheck;
} )();
