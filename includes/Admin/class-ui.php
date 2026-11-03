<?php
/**
 * Markup pieces of the FlitAgent look (#137).
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The shared components of the Flit design language that need markup: the page header with the mark, and the status chip
 * (icon plus text, never colour alone). Everything else is styling of the screens' own markup in assets/css/admin.css.
 * Every dynamic value is escaped here; a screen still goes through wp_kses() with allowed_html() when it's printed.
 */
final class Ui {

	/**
	 * Status => role, label and Lucide icon, as the design language's status mapping lists them (the connection and diagnostic statuses).
	 *
	 * @return array<string, array{string, string, string}> Role, label, icon name.
	 */
	private static function map(): array {
		return array(
			'warning'           => array( 'warning', __( 'Warning', 'flitagent' ), 'alert-triangle' ),
			'skipped'           => array( 'neutral', __( 'Skipped', 'flitagent' ), 'minus-circle' ),
			'connected'         => array( 'success', __( 'Connected', 'flitagent' ), 'check' ),
			'waiting'           => array( 'warning', __( 'Waiting', 'flitagent' ), 'clock' ),
			'not_connected'     => array( 'danger', __( 'Not connected', 'flitagent' ), 'minus-circle' ),
			'connected_gateway' => array( 'success', __( 'Connected to flitagent.com', 'flitagent' ), 'check' ),
			'pass'              => array( 'success', __( 'Pass', 'flitagent' ), 'check' ),
			'fail'              => array( 'danger', __( 'Fail', 'flitagent' ), 'x-circle' ),
		);
	}

	/**
	 * Lucide icon shapes (ISC licence, kept in assets/css/admin.css), drawn on a 24px grid with a 2px round stroke.
	 *
	 * @return array<string, string>
	 */
	private static function icons(): array {
		return array(
			'clock'          => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
			'check'          => '<path d="M20 6 9 17l-5-5"/>',
			'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'x-circle'       => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
			'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
			'minus-circle'   => '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>',
			'copy'           => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
		);
	}

	/**
	 * A status chip: an icon (decorative) and the status word.
	 *
	 * @param string $status A connection status or a diagnostic result; anything else is shown as is, neutral.
	 * @return string
	 */
	public static function chip( string $status ): string {
		$map                         = self::map();
		list( $role, $label, $icon ) = $map[ $status ] ?? array( 'neutral', $status, 'minus-circle' );

		return '<span class="flitagent-chip flitagent-chip--' . esc_attr( $role ) . '">'
			. '<svg class="flitagent-chip-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::icons()[ $icon ] . '</svg>'
			. '<span>' . esc_html( $label ) . '</span></span>';
	}

	/**
	 * A decorative icon drawn at a size, for screens that need one beside text.
	 *
	 * @param string $name Icon name from the set above, e.g. clock.
	 * @param int    $size Width and height in px.
	 * @return string Empty for an unknown name.
	 */
	public static function icon( string $name, int $size = 16 ): string {
		$icons = self::icons();
		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}
		return '<svg class="flitagent-icon flitagent-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
	}

	/**
	 * The picker icon of an AI app: the app's own mark for ChatGPT and Claude (drawn in the tile's text colour), and a
	 * terminal prompt for manual configuration. Decorative: the tile names the app.
	 *
	 * @param string $app chatgpt, claude or manual.
	 * @return string
	 */
	public static function app_icon( string $app ): string {
		$marks = array(
			'chatgpt' => '<path fill-rule="evenodd" d="%s"/>',
			'claude'  => '<path d="%s"/>',
		);
		$paths = array(
			'chatgpt' => 'M9.205 8.658v-2.26c0-.19.072-.333.238-.428l4.543-2.616c.619-.357 1.356-.523 2.117-.523 2.854 0 4.662 2.212 4.662 4.566 0 .167 0 .357-.024.547l-4.71-2.759a.797.797 0 00-.856 0l-5.97 3.473zm10.609 8.8V12.06c0-.333-.143-.57-.429-.737l-5.970-3.473 1.950-1.118a.433.433 0 01.476 0l4.543 2.617c1.309.76 2.189 2.378 2.189 3.948 0 1.808-1.07 3.473-2.76 4.163zM7.802 12.703l-1.95-1.142c-.167-.095-.239-.238-.239-.428V5.899c0-2.545 1.950-4.472 4.591-4.472 1 0 1.927.333 2.712.928L8.23 5.067c-.285.166-.428.404-.428.737v6.898zM12 15.128l-2.795-1.57v-3.33L12 8.658l2.795 1.570v3.33L12 15.128zm1.796 7.23c-1 0-1.927-.332-2.712-.927l4.686-2.712c.285-.166.428-.404.428-.737v-6.898l1.974 1.142c.167.095.238.238.238.428v5.233c0 2.545-1.974 4.472-4.614 4.472zm-5.637-5.303l-4.544-2.617c-1.308-.761-2.188-2.378-2.188-3.948A4.482 4.482 0 014.21 6.327v5.423c0 .333.143.571.428.738l5.947 3.449-1.95 1.118a.432.432 0 01-.476 0zm-.262 3.9c-2.688 0-4.662-2.021-4.662-4.519 0-.19.024-.38.047-.57l4.686 2.71c.286.167.571.167.856 0l5.97-3.448v2.26c0 .19-.07.333-.237.428l-4.543 2.616c-.619.357-1.356.523-2.117.523zm5.899 2.83a5.947 5.947 0 005.827-4.756C22.287 18.339 24 15.840 24 13.296c0-1.665-.713-3.282-1.998-4.448.119-.5.19-.999.19-1.498 0-3.401-2.759-5.947-5.946-5.947-.642 0-1.260.095-1.880.31A5.962 5.962 0 0010.205 0a5.947 5.947 0 00-5.827 4.757C1.713 5.447 0 7.945 0 10.490c0 1.666.713 3.283 1.998 4.448-.119.5-.19 1-.19 1.499 0 3.401 2.759 5.946 5.946 5.946.642 0 1.260-.095 1.880-.309a5.96 5.96 0 004.162 1.713z',
			'claude'  => 'm4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z',
		);
		if ( isset( $marks[ $app ] ) ) {
			return '<svg class="flitagent-app-icon" viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false">' . sprintf( $marks[ $app ], $paths[ $app ] ) . '</svg>';
		}
		return '<svg class="flitagent-app-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 17l6-5-6-5"/><path d="M12 19h8"/></svg>';
	}

	/**
	 * A link to another site that opens in a new tab, saying so to screen readers, with Lucide's external-link icon.
	 *
	 * @param string                $url     URL.
	 * @param string                $text    Link text (plain).
	 * @param string                $classes Classes of the link, such as `button` ('' for none).
	 * @param array<string, string> $data    Extra `data-` attributes: name => value.
	 * @return string
	 */
	public static function external_link( string $url, string $text, string $classes = '', array $data = array() ): string {
		$attributes = '';
		foreach ( $data as $name => $value ) {
			if ( 1 === preg_match( '/^data-[a-z0-9-]+$/', $name ) ) {
				$attributes .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"' . ( '' !== $classes ? ' class="' . esc_attr( $classes ) . '"' : '' ) . $attributes . '>' . esc_html( $text )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'flitagent' ) . '</span>'
			. '<svg class="flitagent-external-icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg></a>';
	}

	/**
	 * The page header: the bird mark (decorative; the wordmark is the name) and the Newsreader page title.
	 *
	 * @param string $title Page title (plain text).
	 * @return string
	 */
	public static function header( string $title ): string {
		return '<header class="flitagent-header"><span class="flitagent-mark" aria-hidden="true"></span>'
			. '<div><p class="flitagent-wordmark" aria-hidden="true">FlitAgent</p><h1>' . esc_html( $title ) . '</h1></div></header>';
	}

	/**
	 * Opens a wp-admin screen: core's notice marker, then the wrapper and the header. Core's common.js moves every
	 * notice that is not `.inline` to just after `.wp-header-end`; with the marker before the wrapper, notices other
	 * code prints stay outside it and keep core's look (FlitAgent's own notices are `.inline`).
	 *
	 * @param string $title Page title (plain text).
	 * @param string $extra Extra wrapper class.
	 * @return string
	 */
	public static function open( string $title, string $extra = '' ): string {
		return '<hr class="wp-header-end" /><div class="' . esc_attr( trim( 'wrap flitagent-wrap ' . $extra ) ) . '">' . self::header( $title );
	}

	/**
	 * What FlitAgent's screens may print, for wp_kses(): exactly the elements and attributes they use. No script, style,
	 * frame, event handler or style attribute, and of the `data-` attributes only FlitAgent's own.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$common = array( 'class', 'id', 'role', 'hidden', 'aria-current', 'aria-disabled', 'aria-hidden', 'aria-label', 'aria-labelledby', 'aria-live' );
		foreach ( array( 'autorun', 'connect', 'connect-again', 'copied', 'copy', 'copy-label', 'copy-select', 'copy-status', 'copy-text', 'dialog', 'dialog-open', 'nonce', 'open-tool', 'run', 'setup-tool', 'state', 'tab-done', 'tool', 'tool-status', 'view-nonce' ) as $name ) {
			$common[] = 'data-flitagent-' . $name;
		}

		$shape    = array( 'fill', 'fill-rule', 'stroke', 'stroke-linecap', 'stroke-linejoin', 'stroke-width' );
		$elements = array(
			'a'        => array( 'href', 'rel', 'target' ),
			'br'       => array(),
			'button'   => array( 'disabled', 'form', 'name', 'type', 'value' ),
			'circle'   => array_merge( $shape, array( 'cx', 'cy', 'r' ) ),
			'code'     => array(),
			'dd'       => array(),
			'dialog'   => array( 'open' ),
			'div'      => array(),
			'dl'       => array(),
			'dt'       => array(),
			'form'     => array( 'action', 'autocomplete', 'method' ),
			'h1'       => array(),
			'h2'       => array(),
			'h3'       => array(),
			'header'   => array(),
			'hr'       => array(),
			'input'    => array( 'autocomplete', 'checked', 'disabled', 'form', 'max', 'min', 'name', 'placeholder', 'readonly', 'type', 'value' ),
			'label'    => array( 'for' ),
			'li'       => array(),
			'noscript' => array(),
			'ol'       => array(),
			'p'        => array(),
			'path'     => array_merge( $shape, array( 'd' ) ),
			'rect'     => array_merge( $shape, array( 'height', 'rx', 'ry', 'width', 'x', 'y' ) ),
			'section'  => array(),
			'span'     => array(),
			'strong'   => array(),
			'svg'      => array_merge( $shape, array( 'focusable', 'height', 'viewbox', 'width', 'xmlns' ) ),
			'table'    => array(),
			'tbody'    => array(),
			'td'       => array(),
			'th'       => array( 'scope' ),
			'thead'    => array(),
			'tr'       => array(),
			'ul'       => array(),
		);

		$allowed = array();
		foreach ( $elements as $element => $attributes ) {
			$allowed[ $element ] = array_fill_keys( array_merge( $common, $attributes ), true );
		}
		return $allowed;
	}

	/**
	 * The current user's one-time notice, then forgets it.
	 *
	 * @return string
	 */
	public static function notice_once(): string {
		$key     = 'flitagent_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return '';
		}
		delete_transient( $key );
		return '<div class="notice notice-info inline is-dismissible flitagent-notice"><p>' . esc_html( $message ) . '</p></div>';
	}
}
