/**
 * FlitAgent wp-admin screens. Loaded only on FlitAgent's own screens (Admin::enqueue).
 */
import domReady from '@wordpress/dom-ready';
import ConnectTab from './connect-tab';
import CopyButton from './copy-button';
import Dialog from './dialog';
import RunCheck from './run-check';
import ToolStatus from './tool-status';
import '../flit-design/fonts.css';
import '../scss/admin.scss';

domReady( () => {
	ConnectTab.attachAll();
	CopyButton.attachAll();
	Dialog.attachAll();
	RunCheck.attachAll();
	RunCheck.autoRun();
	ToolStatus.attachAll();
} );
