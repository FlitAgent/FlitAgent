/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	// The require scope
/******/ 	const __webpack_require__ = {};
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = (module) => {
/******/ 		const getter = module && module.__esModule ?
/******/ 			() => (module['default']) :
/******/ 			() => (module);
/******/ 		__webpack_require__.d(getter, { a: getter });
/******/ 		return getter;
/******/ 	};
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	// define getter/value functions for harmony exports
/******/ 	__webpack_require__.d = (exports, definition) => {
/******/ 		for(var key in definition) {
/******/ 			if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 				Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 			}
/******/ 		}
/******/ 	};
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	__webpack_require__.o = (obj, prop) => (Object.hasOwn(obj, prop));
/******/ 	
/************************************************************************/

;// external ["wp","domReady"]
const external_wp_domReady_namespaceObject = window["wp"]["domReady"];
var external_wp_domReady_default = /*#__PURE__*/__webpack_require__.n(external_wp_domReady_namespaceObject);
;// ./src/admin/connect-tab.ts
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
class ConnectTab {
  /**
   * Wires every connect form under `root`, listens while one is shown, and runs the closing page.
   *
   * @param root Where to look.
   * @param win  The window.
   */
  static attachAll(root = document, win = window) {
    const forms = root.querySelectorAll(`form[${ConnectTab.FORM}]`);
    const state = {
      waiting: false
    };
    forms.forEach(form => {
      // A browser that restores form state on reload (Firefox) could bring back the disabled button.
      ConnectTab.enable(form);
      form.addEventListener('submit', () => ConnectTab.submit(form, root, win, state));
    });
    const outcome = root.querySelector(`[${ConnectTab.DONE}]`)?.getAttribute(ConnectTab.DONE) ?? '';
    if (0 === forms.length && '' === outcome) {
      return;
    }
    const channel = ConnectTab.channel(win);
    if (forms.length > 0 && null !== channel) {
      channel.addEventListener('message', event => {
        const data = event.data;
        if (ConnectTab.CONNECTED !== data && ConnectTab.FAILED !== data) {
          return;
        }
        if (state.waiting) {
          channel.postMessage(ConnectTab.ACK);
          win.location.reload();
        } else if (ConnectTab.CONNECTED === data) {
          win.location.reload();
        }
      });
    }
    if ('' !== outcome) {
      ConnectTab.handBack(outcome, channel, win);
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
  static submit(form, root, win, state) {
    if (ConnectTab.NAME === win.name) {
      // Already the new tab (retrying after a failure): submit here, still marked, so the outcome is handed back.
      form.target = '';
      ConnectTab.mark(form, root);
      return;
    }
    const tab = win.open('', ConnectTab.NAME);
    if (null === tab) {
      form.target = '';
      form.querySelector(`input[name="${ConnectTab.FIELD}"]`)?.remove();
      return;
    }
    try {
      // Still this site's about:blank, so the opener can go before flitagent.com loads.
      tab.opener = null;
    } catch {
      // A tab of that name already showing another site: its opener is not ours to change.
    }
    form.target = ConnectTab.NAME;
    ConnectTab.mark(form, root);
    state.waiting = true;
    // Disabled after the browser has taken the form, so the submission goes ahead.
    win.setTimeout(() => ConnectTab.wait(form, root), 0);
    const status = form.querySelector('.flitagent-connect-status');
    if (null !== status) {
      status.textContent = form.getAttribute(ConnectTab.FORM) ?? '';
    }
  }
  /**
   * Adds the `flitagent_tab=1` field, once.
   *
   * @param form The connect form.
   * @param root Its document.
   */
  static mark(form, root) {
    let field = form.querySelector(`input[name="${ConnectTab.FIELD}"]`);
    if (null === field) {
      field = root.createElement('input');
      field.type = 'hidden';
      field.name = ConnectTab.FIELD;
      form.appendChild(field);
    }
    field.value = '1';
  }
  /**
   * Disables the form while the new tab works, and offers "Start again".
   *
   * @param form The connect form.
   * @param root Its document.
   */
  static wait(form, root) {
    form.querySelectorAll('button[type="submit"]').forEach(button => {
      button.disabled = true;
    });
    const status = form.querySelector('.flitagent-connect-status');
    if (null === status) {
      return;
    }
    // After the live region, not in it, so it is not read out with the status line.
    const again = root.createElement('button');
    again.type = 'button';
    again.className = 'button-link flitagent-connect-again';
    again.textContent = form.getAttribute(ConnectTab.AGAIN) ?? '';
    again.addEventListener('click', () => {
      // Still waiting: if the other tab finishes after all, it is answered and its notice shows here.
      form.target = '';
      ConnectTab.enable(form);
      status.textContent = '';
      again.remove();
    });
    status.insertAdjacentElement('afterend', again);
  }
  /**
   * Enables a connect form's submit buttons.
   *
   * @param form The connect form.
   */
  static enable(form) {
    form.querySelectorAll('button[type="submit"]').forEach(button => {
      button.disabled = false;
    });
  }
  /**
   * The closing page: tells the waiting tab the outcome, closes once it answers, and otherwise shows the Connect
   * screen (without the marker, so the stored notice shows here).
   *
   * @param outcome CONNECTED or FAILED.
   * @param channel The channel, or null.
   * @param win     The window.
   */
  static handBack(outcome, channel, win) {
    const show = () => win.location.replace(ConnectTab.without(win.location.href));
    if (null === channel) {
      show();
      return;
    }
    let answered = false;
    channel.addEventListener('message', event => {
      if (answered || ConnectTab.ACK !== event.data) {
        return;
      }
      answered = true;
      win.close();
      win.setTimeout(show, ConnectTab.CLOSE_WAIT);
    });
    channel.postMessage(outcome);
    win.setTimeout(() => {
      if (!answered) {
        show();
      }
    }, ConnectTab.ACK_WAIT);
  }
  /**
   * A URL without the closing page's marker.
   *
   * @param href URL.
   */
  static without(href) {
    const url = new URL(href);
    url.searchParams.delete(ConnectTab.PARAM);
    return url.toString();
  }
  /**
   * The channel between the tabs, or null where the browser has none.
   *
   * @param win The window.
   */
  static channel(win) {
    const scope = win;
    return 'function' === typeof scope.BroadcastChannel ? new scope.BroadcastChannel(ConnectTab.NAME) : null;
  }
}
/**
 * Attribute of a connect form; its value is the status line for the waiting tab.
 */
ConnectTab.FORM = 'data-flitagent-connect';
/**
 * Attribute of a connect form naming its "Start again" button.
 */
ConnectTab.AGAIN = 'data-flitagent-connect-again';
/**
 * Attribute of the closing page's message; its value is the outcome.
 */
ConnectTab.DONE = 'data-flitagent-tab-done';
/**
 * Query arg of the closing page.
 */
ConnectTab.PARAM = 'flitagent_tab_done';
/**
 * Name of the new tab, and of the channel between the tabs.
 */
ConnectTab.NAME = 'flitagent-connect';
/**
 * Form field that marks a connect from the new tab.
 */
ConnectTab.FIELD = 'flitagent_tab';
/**
 * The outcomes the closing page sends, and the waiting tab's answer.
 */
ConnectTab.CONNECTED = 'connected';
ConnectTab.FAILED = 'failed';
ConnectTab.ACK = 'ack';
/**
 * How long the closing page waits for an answer, in milliseconds.
 */
ConnectTab.ACK_WAIT = 1500;
/**
 * How long a closing page that should have closed waits before showing the Connect screen, in milliseconds.
 */
ConnectTab.CLOSE_WAIT = 500;
/* harmony default export */ const connect_tab = (ConnectTab);
;// ./src/admin/copy-button.ts
/**
 * A Copy button: `<button data-flitagent-copy="<input id>">` copies that input's value and briefly shows a check mark.
 * `<button data-flitagent-copy-text="<text>">` (the "is connected" dialog's first question) copies that text instead and
 * briefly shows `data-flitagent-copied` ("Copied") in its `[data-flitagent-copy-label]`, keeping its icon, and in the
 * polite live region `data-flitagent-copy-status` names, so screen readers hear it. Where nothing could be copied, it
 * says nothing and selects the text instead (the input, or the element `data-flitagent-copy-select` names), so the
 * keyboard's copy shortcut works.
 */
class CopyButton {
  constructor(button, field) {
    this.button = button;
    this.field = field;
    this.text = button.getAttribute(CopyButton.TEXT) ?? '';
    this.target = null === field ? button.querySelector(`[${CopyButton.LABEL}]`) ?? button : button;
    this.label = this.target.textContent ?? '';
    this.button.addEventListener('click', () => this.copy());
  }
  /**
   * Attaches a CopyButton to every Copy button under `root` whose input exists, and to every button with its own text.
   *
   * @param root Where to look.
   */
  static attachAll(root = document) {
    const buttons = [];
    root.querySelectorAll(`[${CopyButton.ATTRIBUTE}]`).forEach(button => {
      const field = document.getElementById(button.getAttribute(CopyButton.ATTRIBUTE) ?? '');
      if (field instanceof HTMLInputElement) {
        buttons.push(new CopyButton(button, field));
      }
    });
    root.querySelectorAll(`[${CopyButton.TEXT}]`).forEach(button => {
      buttons.push(new CopyButton(button, null));
    });
    return buttons;
  }
  /**
   * Copies the input's value (or the button's text), falling back to the selection where the Clipboard API is missing
   * or refused. Confirms only what was copied; otherwise selects the text for the keyboard.
   */
  async copy() {
    const value = null === this.field ? this.text : this.field.value;
    this.field?.select();
    let copied;
    try {
      await navigator.clipboard.writeText(value);
      copied = true;
    } catch {
      copied = this.copySelection(value);
    }
    if (copied) {
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
  copySelection(value) {
    if (null !== this.field) {
      return CopyButton.copyCommand();
    }
    const field = document.createElement('textarea');
    field.value = value;
    field.readOnly = true;
    field.className = 'screen-reader-text';
    this.button.after(field);
    field.select();
    const copied = CopyButton.copyCommand();
    field.remove();
    this.button.focus();
    return copied;
  }
  /**
   * The browser's copy command, which reports false (or throws) where it is not allowed.
   */
  static copyCommand() {
    try {
      return document.execCommand('copy');
    } catch {
      return false;
    }
  }
  /**
   * After a failed copy: selects the text, so the keyboard's copy shortcut works. The input gets focus with its value
   * selected; a button's text is selected where it is shown.
   */
  selectText() {
    if (null !== this.field) {
      this.field.focus();
      this.field.select();
      return;
    }
    const shown = document.getElementById(this.button.getAttribute(CopyButton.SELECT) ?? '');
    const selection = window.getSelection();
    if (null === shown || null === selection) {
      return;
    }
    const range = document.createRange();
    range.selectNodeContents(shown);
    selection.removeAllRanges();
    selection.addRange(range);
  }
  /**
   * Shows a check mark (or the copied word, also in the live region), then the original label.
   */
  confirm() {
    const copied = this.button.getAttribute(CopyButton.COPIED) ?? '';
    const status = '' === copied ? null : document.getElementById(this.button.getAttribute(CopyButton.STATUS) ?? '');
    this.target.textContent = '' === copied ? '✓' : copied;
    if (null !== status) {
      status.textContent = copied;
    }
    window.setTimeout(() => {
      this.target.textContent = this.label;
      if (null !== status) {
        // Emptied, so the next copy is heard again.
        status.textContent = '';
      }
    }, CopyButton.CONFIRM_MS);
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
/* harmony default export */ const copy_button = (CopyButton);
;// ./src/admin/dialog.ts
/**
 * Dialogs on the Connect screen: a trigger `[data-flitagent-dialog="<dialog id>"]` (a Disconnect button or a
 * Set up link) opens that native `<dialog>` as a modal instead of submitting its form or following its link. The
 * browser moves focus into the dialog and closes it on Escape; Cancel and Close are `<form method="dialog">` buttons.
 * Closing returns focus to the trigger that opened it. A dialog the server rendered open (`[data-flitagent-dialog-open]`,
 * from `&setup=<tool>`, or the "is connected" dialog from `&flitagent_connected=<tool>`) is shown as a modal too, and
 * closing it drops those from the address. Without this script
 * the triggers keep working as plain links and submit buttons.
 */
class Dialog {
  constructor(dialog) {
    this.opener = null;
    this.fromServer = false;
    this.dialog = dialog;
    this.dialog.addEventListener('close', () => this.closed());
  }
  /**
   * Wires every trigger under `root` whose dialog exists, then shows a dialog the server rendered open.
   *
   * @param root Where to look.
   */
  static attachAll(root = document) {
    const dialogs = new Map();
    const triggers = new Map();
    root.querySelectorAll(`[${Dialog.ATTRIBUTE}]`).forEach(trigger => {
      const id = trigger.getAttribute(Dialog.ATTRIBUTE) ?? '';
      const element = root.getElementById(id);
      if (!Dialog.isDialog(element)) {
        return;
      }
      let dialog = dialogs.get(id);
      if (undefined === dialog) {
        dialog = new Dialog(element);
        dialogs.set(id, dialog);
        triggers.set(id, trigger);
      }
      const target = dialog;
      trigger.addEventListener('click', event => {
        event.preventDefault();
        target.open(trigger);
      });
    });
    root.querySelectorAll(`dialog[${Dialog.OPEN_ATTRIBUTE}]`).forEach(element => {
      if (!Dialog.isDialog(element)) {
        return;
      }
      const dialog = dialogs.get(element.id) ?? new Dialog(element);
      dialog.showFromServer(triggers.get(element.id) ?? null);
    });
    return [...dialogs.values()];
  }
  /**
   * Whether an element is a dialog the browser can show as a modal.
   *
   * @param element Element.
   */
  static isDialog(element) {
    return null !== element && 'function' === typeof element.showModal;
  }
  /**
   * Shows the dialog as a modal.
   *
   * @param opener The element focus returns to.
   */
  open(opener) {
    this.opener = opener;
    if (!this.dialog.open) {
      this.dialog.showModal();
    }
  }
  /**
   * Turns a dialog the server rendered open (in the page) into a modal.
   *
   * @param opener The element focus returns to.
   */
  showFromServer(opener) {
    this.fromServer = true;
    this.dialog.removeAttribute(Dialog.OPEN_ATTRIBUTE);
    this.dialog.removeAttribute('open');
    this.open(opener);
  }
  /**
   * After the dialog closed: focus goes back to its trigger and a server-opened dialog's `setup` leaves the address.
   */
  closed() {
    if (this.dialog.open) {
      return;
    }
    this.opener?.focus();
    if (this.fromServer) {
      this.fromServer = false;
      Dialog.forgetSetup();
    }
  }
  /**
   * Drops `setup` or the "is connected" dialog's `flitagent_connected` (and the fragment that scrolled to the dialog)
   * from the address, so a reload does not reopen it.
   */
  static forgetSetup() {
    const url = new URL(window.location.href);
    const params = Dialog.ADDRESS_PARAMS.filter(name => url.searchParams.has(name));
    if (0 === params.length) {
      return;
    }
    params.forEach(name => url.searchParams.delete(name));
    url.hash = '';
    window.history.replaceState(null, '', url.toString());
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
Dialog.ADDRESS_PARAMS = ['setup', 'flitagent_connected'];
/* harmony default export */ const dialog = (Dialog);
;// ./src/admin/run-check.ts
/**
 * The check step's Run / Check again links (`<a data-flitagent-run="Checking…">`): the checks run on the server while the
 * page loads, which can take a while and would leave a blank screen. So the page stays, the link shows a spinner and the
 * given text, and the checked card is fetched and swapped in. If anything fails, the link is simply followed.
 */
class RunCheck {
  constructor(link) {
    this.link = link;
    this.label = link.textContent ?? '';
    link.addEventListener('click', event => this.start(event));
    // Back-forward cache: coming back to the page must not show a finished spinner.
    window.addEventListener('pageshow', () => this.reset());
  }
  /**
   * Attaches a RunCheck to every link under `root`.
   *
   * @param root Where to look.
   */
  static attachAll(root = document) {
    return Array.from(root.querySelectorAll(`a[${RunCheck.ATTRIBUTE}]`)).map(link => new RunCheck(link));
  }
  /**
   * Shows the spinner and lets the navigation go on; a second click while it runs does nothing.
   *
   * @param event The click.
   */
  start(event) {
    if (this.link.classList.contains('is-running')) {
      event.preventDefault();
      return;
    }
    // A new tab or window keeps this page as it is.
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    event.preventDefault();
    this.link.classList.add('is-running');
    this.link.setAttribute('aria-busy', 'true');
    this.link.textContent = this.link.getAttribute(RunCheck.ATTRIBUTE) ?? this.label;
    // Announced to screen readers: a link's changed text is not.
    this.link.setAttribute('role', 'status');
    const url = this.link.href;
    RunCheck.fetchCard(url).then(card => RunCheck.swap(card, url), () => window.location.assign(url));
  }
  /**
   * Starts the checks the page shows as in progress (`[data-flitagent-autorun]`, the check step reached from the
   * previous step), and swaps in the finished step.
   *
   * @param root Where to look.
   */
  static autoRun(root = document) {
    const pending = root.querySelector('[data-flitagent-autorun]');
    const url = pending?.dataset.flitagentAutorun ?? '';
    if (!pending) {
      return;
    }
    if (!RunCheck.isAdminUrl(url)) {
      RunCheck.offerLink(pending);
      return;
    }
    RunCheck.fetchCard(url).then(card => RunCheck.swap(card, url), () => window.location.assign(url));
  }
  /**
   * Whether an address is on this site's wp-admin: same origin, in the folder of this admin page. A step's address is always
   * one on a usual site, and nothing else may be fetched or followed by script: wp_kses() does not look at a data attribute's
   * protocol, but it does look at a link's.
   *
   * @param url The address.
   */
  static isAdminUrl(url) {
    if ('' === url) {
      return false;
    }
    try {
      const here = window.location.href;
      const target = new URL(url, here);
      return target.origin === new URL(here).origin && target.pathname.startsWith(new URL('./', here).pathname);
    } catch {
      return false;
    }
  }
  /**
   * In place of checks that won't start: the step's own Run the checks link, which a person can follow, and no progress bar.
   *
   * @param pending The step's in-progress block.
   */
  static offerLink(pending) {
    pending.querySelector('.flitagent-checking-run')?.removeAttribute('hidden');
    pending.querySelector('.flitagent-checking-text')?.remove();
    pending.querySelector('.flitagent-progress')?.remove();
  }
  static swap(card, url) {
    document.querySelector(RunCheck.CARD)?.replaceWith(card);
    window.history.replaceState(null, '', url);
    RunCheck.attachAll(card);
  }
  /**
   * Fetches the page at `url` and returns its setup card.
   *
   * @param url The step's address, with the checks switched on.
   */
  static async fetchCard(url) {
    const response = await window.fetch(url, {
      credentials: 'same-origin'
    });
    if (!response.ok) {
      throw new Error('Not OK');
    }
    const card = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector(RunCheck.CARD);
    // Another step's card means the link's nonce has expired: follow the link rather than swap that card in.
    if (!card || !document.querySelector(RunCheck.CARD) || !card.classList.contains(RunCheck.CHECK_CARD)) {
      throw new Error('No card');
    }
    return card;
  }
  reset() {
    this.link.classList.remove('is-running');
    this.link.removeAttribute('aria-busy');
    this.link.removeAttribute('role');
    this.link.textContent = this.label;
  }
}
/**
 * Attribute holding the text shown while the checks run.
 */
RunCheck.ATTRIBUTE = 'data-flitagent-run';
/**
 * The setup guide's card, which holds the step.
 */
RunCheck.CARD = '.flitagent-onboarding > .flitagent-card';
/**
 * Class of the check step's card.
 */
RunCheck.CHECK_CARD = 'flitagent-onboarding-card--check';
/* harmony default export */ const run_check = (RunCheck);
;// ./src/admin/tool-status.ts
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
class ToolStatus {
  constructor(list, root, win) {
    this.tabs = new Map();
    this.openedAt = null;
    this.busy = false;
    this.leaving = false;
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
  static attachAll(root = document, win = window) {
    const list = root.querySelector(`[${ToolStatus.LIST}]`);
    if (null === list) {
      return null;
    }
    const status = new ToolStatus(list, root, win);
    status.start();
    return status;
  }
  /**
   * Wires the links that open a tool's site, the timer and the focus checks.
   */
  start() {
    this.list.setAttribute(ToolStatus.LIVE, '');
    this.root.querySelectorAll(`a[${ToolStatus.OPEN}]`).forEach(link => {
      link.addEventListener('click', event => this.openTab(link, event));
    });
    this.win.setInterval(() => this.tick(), ToolStatus.INTERVAL);
    this.root.addEventListener('visibilitychange', () => {
      if ('visible' === this.root.visibilityState) {
        void this.check();
      }
    });
    this.win.addEventListener('focus', () => void this.check());
  }
  /**
   * Opens a tool's site in the tab named for it, keeping the handle. A tab of that name already on the other site is
   * only brought forward, so the steps the administrator is in are not lost.
   *
   * @param link  The link.
   * @param event Its click.
   */
  openTab(link, event) {
    const tool = link.getAttribute(ToolStatus.OPEN) ?? '';
    let tab = null;
    try {
      tab = this.win.open('', `flitagent-${tool}`);
    } catch {
      tab = null;
    }
    if (null === tab) {
      // No tab (a blocker): the link opens its own.
      return;
    }
    event.preventDefault();
    if (ToolStatus.isBlank(tab)) {
      try {
        // Still this site's about:blank, so the opener can go before the other site loads.
        tab.opener = null;
      } catch {
        // Not ours to change.
      }
      ToolStatus.navigate(tab, link.href);
    }
    tab.focus();
    this.tabs.set(tool, tab);
  }
  /**
   * Sends a blank tab of this site on without a referrer: a `rel="noreferrer"` link clicked inside the tab itself (a
   * navigation the opener starts would send wp-admin's address). Where the tab's document cannot be used, the tab is
   * sent on directly.
   *
   * @param tab  The blank tab.
   * @param href Where to.
   */
  static navigate(tab, href) {
    try {
      const doc = tab.document;
      const link = doc.createElement('a');
      link.href = href;
      link.rel = 'noreferrer';
      (doc.body ?? doc.documentElement).appendChild(link);
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
  static isBlank(tab) {
    try {
      return 'about:blank' === tab.location.href;
    } catch {
      return false;
    }
  }
  /**
   * Every INTERVAL: asks while a Set up dialog is open, for at most LIMIT after it was first seen open.
   */
  tick() {
    if ('' === this.openSetup()) {
      this.openedAt = null;
      this.list.setAttribute(ToolStatus.LIVE, '');
      return;
    }
    const now = ToolStatus.now();
    if (null === this.openedAt) {
      this.openedAt = now;
    }
    if (now - this.openedAt <= ToolStatus.LIMIT) {
      this.list.setAttribute(ToolStatus.LIVE, '');
      void this.check();
    } else {
      // Stopped: the dialog no longer says it updates by itself.
      this.list.removeAttribute(ToolStatus.LIVE);
    }
  }
  /**
   * The tool of the open Set up dialog, or ''.
   */
  openSetup() {
    const dialog = this.root.querySelector(`dialog[${ToolStatus.SETUP}][open]`);
    return dialog?.getAttribute(ToolStatus.SETUP) ?? '';
  }
  /**
   * Asks the route and acts on the answer; one request at a time, and nothing once the page is leaving.
   */
  async check() {
    if (this.busy || this.leaving) {
      return;
    }
    this.busy = true;
    try {
      const response = await this.win.fetch(this.list.getAttribute(ToolStatus.LIST) ?? '', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-WP-Nonce': this.list.getAttribute(ToolStatus.NONCE) ?? ''
        }
      });
      if (response.ok) {
        this.apply(await response.json());
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
  apply(state) {
    if (null === state || 'object' !== typeof state) {
      return;
    }
    const {
      connected,
      tools
    } = state;
    if (false === connected) {
      // The site was disconnected meanwhile.
      this.leave('');
      return;
    }
    if (true !== connected || null === tools || 'object' !== typeof tools) {
      return;
    }
    const setup = this.openSetup();
    let changed = false;
    let done = '';
    this.list.querySelectorAll(`[${ToolStatus.TOOL}]`).forEach(row => {
      const tool = row.getAttribute(ToolStatus.TOOL) ?? '';
      const now = tools[tool];
      if (ToolStatus.CONNECTED !== now && ToolStatus.NOT_CONNECTED !== now || now === row.getAttribute(ToolStatus.STATE)) {
        return;
      }
      changed = true;
      if (ToolStatus.CONNECTED === now && (setup === tool || this.tabs.has(tool)) && ('' === done || setup === tool)) {
        done = tool;
      }
    });
    if (changed) {
      if ('' !== done) {
        this.closeTab(done);
      }
      // Another tool changed while a dialog is open: the page comes back with that dialog still open.
      this.leave(done, '' === done ? setup : '');
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
  closeTab(tool) {
    const tab = this.tabs.get(tool);
    this.tabs.delete(tool);
    if (undefined === tab || tab.closed) {
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
  leave(done, setup = '') {
    this.leaving = true;
    const url = new URL(this.win.location.href);
    url.searchParams.delete('setup');
    url.searchParams.delete(ToolStatus.DONE);
    url.hash = '';
    if ('' !== done) {
      url.searchParams.set(ToolStatus.DONE, done);
    } else if ('' !== setup) {
      url.searchParams.set('setup', setup);
    }
    if ('' !== done || '' !== setup) {
      url.searchParams.set('_wpnonce', this.list.getAttribute(ToolStatus.VIEW_NONCE) ?? '');
    }
    this.win.location.replace(url.toString());
  }
}
/**
 * Attribute of the tool list: the route's URL.
 */
ToolStatus.LIST = 'data-flitagent-tool-status';
/**
 * Attribute of the tool list: the `wp_rest` nonce.
 */
ToolStatus.NONCE = 'data-flitagent-nonce';
/**
 * Attribute of the tool list: the nonce a link to a dialog carries.
 */
ToolStatus.VIEW_NONCE = 'data-flitagent-view-nonce';
/**
 * Attribute the script puts on the tool list while it is checking; the Set up dialogs' waiting line shows only then.
 */
ToolStatus.LIVE = 'data-flitagent-live';
/**
 * Attributes of a row: its tool and the state it was rendered with.
 */
ToolStatus.TOOL = 'data-flitagent-tool';
ToolStatus.STATE = 'data-flitagent-state';
/**
 * Attribute of a Set up dialog: its tool.
 */
ToolStatus.SETUP = 'data-flitagent-setup-tool';
/**
 * Attribute of a link that opens a tool's site in a tab the script keeps.
 */
ToolStatus.OPEN = 'data-flitagent-open-tool';
/**
 * Query arg of the page that shows a tool's "is connected" dialog.
 */
ToolStatus.DONE = 'flitagent_connected';
/**
 * The states the route reports.
 */
ToolStatus.CONNECTED = 'connected';
ToolStatus.NOT_CONNECTED = 'not_connected';
/**
 * How often to ask while a Set up dialog is open, and for how long, in milliseconds.
 */
ToolStatus.INTERVAL = 3000;
ToolStatus.LIMIT = 600000;
/**
 * The current time in milliseconds (replaced in tests).
 */
ToolStatus.now = () => Date.now();
/* harmony default export */ const tool_status = (ToolStatus);
;// ./src/admin/index.ts
/**
 * FlitAgent wp-admin screens. Loaded only on FlitAgent's own screens (Admin::enqueue).
 */








external_wp_domReady_default()(() => {
  connect_tab.attachAll();
  copy_button.attachAll();
  dialog.attachAll();
  run_check.attachAll();
  run_check.autoRun();
  tool_status.attachAll();
});
/******/ })()
;