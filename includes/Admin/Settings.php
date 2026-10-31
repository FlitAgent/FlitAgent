<?php
/**
 * The FlitAgent → Settings screen.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings: the uninstall choice, then the Connection card (DiagnosticsScreen). Every dynamic value is escaped here.
 */
final class Settings {

	/**
	 * Option: delete FlitAgent's data when the plugin is deleted (`1`), otherwise keep it (`0`). On until an administrator
	 * saves it unticked, so a site that never saved it reads DEFAULT_REMOVE_DATA.
	 */
	const OPTION_REMOVE_DATA = 'flitagent_remove_data_on_uninstall';

	/**
	 * What a site that never saved OPTION_REMOVE_DATA gets: on.
	 */
	const DEFAULT_REMOVE_DATA = '1';

	/**
	 * Menu slug.
	 */
	const SLUG = 'flitagent-settings';

	/**
	 * Whether an option value means on: `1`, true or the string "1".
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_on( $value ): bool {
		return in_array( $value, array( '1', 1, true ), true );
	}

	/**
	 * The screen: the settings form, then any extra sections (forms of their own, so they cannot go inside this one).
	 *
	 * @param string $sections Extra sections, already escaped (the Connection card, DiagnosticsScreen).
	 * @return string
	 */
	public static function html( string $sections = '' ): string {
		$remove = self::is_on( get_option( self::OPTION_REMOVE_DATA, self::DEFAULT_REMOVE_DATA ) );

		$html = Ui::open( __( 'FlitAgent settings', 'flitagent' ) ) . Ui::notice_once()
			. '<section class="flitagent-card flitagent-card--form"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="flitagent_settings" />'
			. wp_nonce_field( 'flitagent_settings', '_wpnonce', true, false )
			. '<div class="flitagent-setting"><p class="flitagent-setting-label">' . esc_html__( 'Uninstall', 'flitagent' ) . '</p><div class="flitagent-setting-body"><label class="flitagent-check-label"><input type="checkbox" name="' . esc_attr( self::OPTION_REMOVE_DATA ) . '" value="1"' . checked( $remove, true, false ) . ' /> '
			. esc_html__( 'Delete FlitAgent\'s settings and connection data when the plugin is deleted.', 'flitagent' ) . '</label>'
			. '<p class="description">' . esc_html__( 'On by default: a reinstall starts fresh with the setup guide. Turn it off to keep the settings for a reinstall; deactivating the plugin keeps them either way. Deleting the plugin always disconnects this site and its AI tools from flitagent.com; after you reconnect, set up each AI tool again.', 'flitagent' ) . '</p></div></div>'
			. '<p class="flitagent-setting-save"><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'flitagent' ) . '</button></p></form></section>';

		/**
		 * Filters extra sections appended to FlitAgent → Settings, after the settings form (each section escapes its own output).
		 *
		 * @param string $sections HTML.
		 */
		$extra = apply_filters( 'flitagent_settings_sections', '' );
		return $html . $sections . ( is_string( $extra ) ? $extra : '' ) . '</div>';
	}
}
