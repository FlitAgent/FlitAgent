/**
 * FlitAgent wp-admin screens. Loaded only on FlitAgent's own screens (Admin::enqueue), in the footer, after the five
 * scripts it depends on (dialog.js, copy-button.js, connect-tab.js, run-check.js, tool-status.js), which put their
 * classes on window.flitagentAdmin. Once the DOM is ready, it attaches them.
 */
( function () {
	'use strict';

	const ns = ( window.flitagentAdmin = window.flitagentAdmin || {} );

	/**
	 * Attaches every script to the page, in the order 1.0.0's entry did.
	 */
	function attach() {
		ns.ConnectTab.attachAll();
		ns.CopyButton.attachAll();
		ns.Dialog.attachAll();
		ns.RunCheck.attachAll();
		ns.RunCheck.autoRun();
		ns.ToolStatus.attachAll();
	}

	// As @wordpress/dom-ready did: at once when the DOM is ready (the usual case for a footer script), otherwise on
	// DOMContentLoaded.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', attach );
	} else {
		attach();
	}
} )();
