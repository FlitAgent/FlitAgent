<?php
/**
 * Screens in wp-admin: Approvals, Activity, Settings.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Auth\Gateway\Client;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Clock;
use FlitAgent\Writes\Gateway;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Store;
use FlitAgent\Writes\Wp\WpPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the HTML of the FlitAgent screens. Every dynamic value is escaped here (esc_html, esc_attr, esc_url; diffs from
 * wp_text_diff() also pass wp_kses_post()); Admin prints the result through wp_kses() with Ui::allowed_html().
 */
final class Screens {

	/**
	 * Receipts per Activity page.
	 */
	const PER_PAGE = 50;

	/**
	 * Gateway.
	 *
	 * @var Gateway
	 */
	private $gateway;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Gateway.
	 * @param Store   $store   Store.
	 * @param Clock   $clock   Clock.
	 */
	public function __construct( Gateway $gateway, Store $store, Clock $clock ) {
		$this->gateway = $gateway;
		$this->store   = $store;
		$this->clock   = $clock;
	}

	/**
	 * The menu badge: pending changes counted as the Approvals list shows them, a plan once however many items it has
	 * (the list shows at most 100 rows per status; the badge counts them all).
	 * It runs on every admin page, so it reads only ids and tools (Store::open_change_heads), never arguments or previews.
	 *
	 * @param Store $store Store.
	 * @param int   $now   Current time.
	 * @return int
	 */
	public static function waiting( Store $store, int $now ): int {
		return count( self::fold_plans( $store->open_change_heads( 'pending', $now ) ) );
	}

	/**
	 * Rows without the items of the plans among them: a plan's items are decided on the plan's own screen.
	 *
	 * @param array<int, array<string, mixed>> $rows Change rows (uuid, tool and, for plans, args_json).
	 * @return array<int, array<string, mixed>>
	 */
	private static function fold_plans( array $rows ): array {
		// A set keyed by item id: linear in rows and items, since the badge runs on every admin page.
		$in_plans = array();
		foreach ( $rows as $row ) {
			if ( Gateway::PLAN_TOOL === $row['tool'] ) {
				foreach ( Gateway::plan_item_ids( $row ) as $item ) {
					$in_plans[ $item ] = true;
				}
			}
		}
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $in_plans ): bool {
					return ! isset( $in_plans[ (string) $row['uuid'] ] );
				}
			)
		);
	}

	/**
	 * Approvals: open changes, or one change in detail.
	 *
	 * @param array<string, mixed> $query Sanitized query fields (Request::query()); `change` opens that change.
	 * @return string
	 */
	public function approvals( array $query ): string {
		if ( isset( $query['change'] ) && is_string( $query['change'] ) ) {
			return $this->detail( $query['change'] );
		}

		$now  = $this->clock->now();
		$rows = array();
		foreach ( array( 'pending', 'approved' ) as $status ) {
			foreach ( $this->store->changes( array( 'status' => $status ), 100, 0 ) as $row ) {
				if ( (int) $row['expires_at'] > $now ) {
					$rows[] = $row;
				}
			}
		}
		$rows = self::fold_plans( $rows );

		$html  = Ui::open( __( 'FlitAgent approvals', 'flitagent' ) ) . $this->notice();
		$html .= '<p>' . esc_html__( 'Changes AI agents asked to make. Nothing changes until you approve (unless your policy approves them automatically) and the agent then applies the change before the approval expires. Approved changes stay listed until they are applied or expire.', 'flitagent' ) . '</p>';
		if ( array() === $rows ) {
			return $html . self::empty_card() . '</div>';
		}
		// Only a waiting change has a Review button; approved ones read View.
		if ( in_array( 'pending', array_column( $rows, 'status' ), true ) ) {
			$html .= '<p class="flitagent-hint">' . esc_html__( 'Click Review to see exactly what will change, then approve or reject it.', 'flitagent' ) . '</p>';
		}

		$cells = array();
		foreach ( $rows as $row ) {
			$cells[] = array(
				'summary'   => '<a href="' . esc_url( self::change_url( (string) $row['uuid'] ) ) . '">' . esc_html( (string) $row['summary'] ) . '</a>',
				'requested' => esc_html( $this->age( (int) $row['created_at'] ) ),
				'by'        => esc_html( Client::display_name( (string) $row['client_name'] ) ) . '<span class="flitagent-cell-sub">' . esc_html(
					/* translators: %s: the WordPress user the app acts as */
					sprintf( __( 'as %s', 'flitagent' ), self::user( (int) $row['requester_user_id'] ) )
				) . '</span>',
				'tool'      => Ui::tool_name( (string) $row['tool'] ),
				'tier'      => esc_html( (string) $row['tier'] ),
				'status'    => Ui::chip( (string) $row['status'] ),
				// Date and time each keep to one line; a narrow card wraps between them, never at the date's hyphens.
				'expires'   => '<span class="flitagent-nowrap">' . esc_html( gmdate( 'Y-m-d', (int) $row['expires_at'] ) ) . '</span> <span class="flitagent-nowrap">' . esc_html( gmdate( 'H:i', (int) $row['expires_at'] ) ) . '</span>',
				'details'   => self::review_button( $row ),
			);
		}
		$columns = array(
			'summary'   => __( 'Summary', 'flitagent' ),
			'requested' => __( 'Requested', 'flitagent' ),
			'by'        => __( 'Requested by', 'flitagent' ),
			'tool'      => __( 'Tool', 'flitagent' ),
			'tier'      => __( 'Tier', 'flitagent' ),
			'status'    => __( 'Status', 'flitagent' ),
			'expires'   => __( 'Expires (UTC)', 'flitagent' ),
			'details'   => __( 'Details', 'flitagent' ),
		);
		return $html . '<section class="flitagent-card flitagent-card--table">' . self::list_table( 'flitagent-changes', $columns, $cells, __( 'Changes waiting for approval', 'flitagent' ) ) . '</section></div>';
	}

	/**
	 * A row's way into the change: Review while it waits for a decision, View once approved. The label names the change
	 * for screen readers, since every row has the same button text.
	 *
	 * @param array<string, mixed> $row A change row (a plan's first change stands for the plan).
	 * @return string
	 */
	private static function review_button( array $row ): string {
		$approved = 'approved' === (string) $row['status'];
		$verb     = $approved ? _x( 'View', 'button: open an approved change', 'flitagent' ) : _x( 'Review', 'button: open a change to approve or reject it', 'flitagent' );
		return '<a class="' . ( $approved ? 'button' : 'button button-primary' ) . '" href="' . esc_url( self::change_url( (string) $row['uuid'] ) ) . '" aria-label="' . esc_attr(
			/* translators: 1: Review or View, 2: the change's summary */
			sprintf( __( '%1$s: %2$s', 'flitagent' ), $verb, (string) $row['summary'] )
		) . '">' . esc_html( $verb ) . Ui::icon( 'chevron-right', 14 ) . '</a>';
	}

	/**
	 * The Nothing waiting card: the shield, the title and where to look instead.
	 *
	 * @return string
	 */
	private static function empty_card(): string {
		$activity = '<a href="' . esc_url( admin_url( 'admin.php?page=flitagent-activity' ) ) . '">' . esc_html__( 'Activity', 'flitagent' ) . '</a>';
		return '<section class="flitagent-card flitagent-card--empty">' . Ui::icon( 'shield-check', 32 )
			. '<p class="flitagent-empty-title">' . esc_html__( 'No changes are waiting.', 'flitagent' ) . '</p>'
			. '<p class="flitagent-empty-text">' . esc_html__( 'When an app proposes a change, it shows here and in the FlitAgent menu badge.', 'flitagent' ) . ' '
			. sprintf( /* translators: %s: link to the Activity screen, text "Activity" */ esc_html__( 'See what already happened in %s.', 'flitagent' ), $activity ) . '</p></section>';
	}

	/**
	 * Activity: receipts, newest first, filterable by tool and status.
	 *
	 * @param array<string, mixed> $query Sanitized query fields (Request::query()): `tool`, `status`, `paged`.
	 * @return string
	 */
	public function activity( array $query ): string {
		$where = array();
		foreach ( array( 'tool', 'status' ) as $filter ) {
			if ( isset( $query[ $filter ] ) && is_string( $query[ $filter ] ) && '' !== $query[ $filter ] ) {
				$where[ $filter ] = sanitize_key( $query[ $filter ] );
			}
		}
		$page = isset( $query['paged'] ) ? max( 1, absint( $query['paged'] ) ) : 1;
		$rows = $this->store->receipts( $where, self::PER_PAGE + 1, ( $page - 1 ) * self::PER_PAGE );
		$more = count( $rows ) > self::PER_PAGE;
		$rows = array_slice( $rows, 0, self::PER_PAGE );

		$html  = Ui::open( __( 'FlitAgent activity', 'flitagent' ) ) . $this->notice();
		$html .= '<p>' . esc_html__( 'Receipts of every write made, refused or failed through FlitAgent, newest first. Receipts are an audit record kept in this site\'s database; they are not tamper-proof.', 'flitagent' ) . '</p>';
		$html .= $this->filters( $where );

		if ( array() === $rows ) {
			return $html . '<p class="flitagent-empty">' . esc_html__( 'No receipts match.', 'flitagent' ) . '</p></div>';
		}

		$cells = array();
		foreach ( $rows as $row ) {
			// What the design's columns drop (tier, user, arguments, result) opens from the row, so no field is lost.
			$fields = array(
				__( 'Tier', 'flitagent' )      => esc_html( (string) $row['tier'] ),
				__( 'User', 'flitagent' )      => esc_html( self::user( (int) $row['user_id'] ) ),
				__( 'Arguments', 'flitagent' ) => self::json_full( (string) $row['args_json'] ),
				__( 'Result', 'flitagent' )    => self::json_full( (string) $row['result_json'] ),
				__( 'Receipt', 'flitagent' )   => '<code class="flitagent-id">' . esc_html( (string) $row['uuid'] ) . '</code>',
			);
			if ( '' !== self::rollback( $row, false ) && ( '' !== (string) $row['rolled_back_by'] || '' !== (string) $row['rolls_back'] ) ) {
				$fields[ __( 'Rollback', 'flitagent' ) ] = esc_html( self::rollback( $row, false ) );
			}
			$details = '<details class="flitagent-details flitagent-row-details"><summary>' . esc_html__( 'Details', 'flitagent' ) . '</summary><dl class="flitagent-facts">';
			foreach ( $fields as $label => $value ) {
				$details .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
			}
			$details .= '</dl></details>';

			$cells[] = array(
				'tool'     => Ui::tool_name( (string) $row['tool'] ) . $details,
				'time'     => esc_html( self::utc( (int) $row['created_at'] ) ),
				'status'   => '<div class="flitagent-chips">' . Ui::chip( (string) $row['status'] ) . ( '' !== (string) $row['rolled_back_by'] ? Ui::chip( 'rolled_back' ) : '' ) . '</div>',
				'approval' => esc_html( self::approval( (string) $row['approval'] ) ),
				'client'   => esc_html( Client::display_name( (string) $row['client_name'] ) ),
				'rollback' => self::rollback( $row ),
				'receipt'  => '<code class="flitagent-id" title="' . esc_attr( (string) $row['uuid'] ) . '">' . self::short_id_html( (string) $row['uuid'] ) . '</code>',
			);
		}//end foreach
		$columns = array(
			'tool'     => __( 'Tool', 'flitagent' ),
			'time'     => __( 'Time (UTC)', 'flitagent' ),
			'status'   => __( 'Status', 'flitagent' ),
			'approval' => __( 'Approval', 'flitagent' ),
			'client'   => __( 'Client', 'flitagent' ),
			'rollback' => __( 'Rollback', 'flitagent' ),
			'receipt'  => __( 'Receipt', 'flitagent' ),
		);
		$html   .= '<section class="flitagent-card flitagent-card--table flitagent-receipts-card">' . self::list_table( 'flitagent-receipts', $columns, $cells, __( 'Receipts', 'flitagent' ) ) . '</section>'
			. '<p class="flitagent-hint">' . esc_html__( 'Each row opens to show the tier, user, exact arguments and result.', 'flitagent' ) . '</p>';

		$base  = add_query_arg( array( 'page' => 'flitagent-activity' ) + $where + array( '_wpnonce' => wp_create_nonce( Request::VIEW ) ), admin_url( 'admin.php' ) );
		$pages = '';
		if ( $page > 1 ) {
			$pages .= '<a class="button" href="' . esc_url( add_query_arg( array( 'paged' => $page - 1 ), $base ) ) . '">' . esc_html__( 'Newer', 'flitagent' ) . '</a> ';
		}
		if ( $more ) {
			$pages .= '<a class="button" href="' . esc_url( add_query_arg( array( 'paged' => $page + 1 ), $base ) ) . '">' . esc_html__( 'Older', 'flitagent' ) . '</a>';
		}
		return $html . ( '' === $pages ? '' : '<p class="flitagent-pages">' . $pages . '</p>' ) . '</div>';
	}

	/**
	 * Settings form, then any extra sections (forms of their own, so they cannot go inside this one).
	 *
	 * @param string $sections Extra sections, already escaped (the Connection section, DiagnosticsScreen).
	 * @param string $paired   The flitagent.com connection's scope, or '' when the site is not connected.
	 * @return string
	 */
	public function settings( string $sections = '', string $paired = '' ): string {
		$enabled = WpPolicy::is_on( get_option( WpPolicy::OPTION_ENABLED, WpPolicy::DEFAULT_ENABLED ) );
		$auto    = get_option( WpPolicy::OPTION_AUTO, array() );
		$auto    = is_array( $auto ) ? $auto : array();
		$ttl     = WpPolicy::clamp_minutes( get_option( WpPolicy::OPTION_TTL, WpPolicy::DEFAULT_TTL ) );
		$remove  = WpPolicy::is_on( get_option( WpPolicy::OPTION_REMOVE_DATA, WpPolicy::DEFAULT_REMOVE_DATA ) );

		$html = Ui::open( __( 'FlitAgent settings', 'flitagent' ) ) . $this->notice();
		if ( is_multisite() ) {
			$html .= '<div class="notice notice-warning inline flitagent-notice"><p>' . esc_html__( 'Writes are not available on multisite installs yet. Every write tool refuses, whatever is set here.', 'flitagent' ) . '</p></div>';
		}

		$html .= '<section class="flitagent-card flitagent-card--form"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="flitagent_settings" />'
			. wp_nonce_field( 'flitagent_settings', '_wpnonce', true, false );

		// Access for AI tools: the site's one access setting, the same as the setup guide's step 4. Read and propose changes
		// stores writes on (`1`), Read only off (`0`), as the Writes checkbox did.
		$html .= '<div class="flitagent-setting"><p class="flitagent-setting-label">' . esc_html__( 'Access for AI tools', 'flitagent' ) . '</p><fieldset class="flitagent-setting-body"><legend class="screen-reader-text">' . esc_html__( 'Access for AI tools', 'flitagent' ) . '</legend>'
			. '<label class="flitagent-check-label"><input type="radio" name="' . esc_attr( WpPolicy::OPTION_ENABLED ) . '" value="0"' . checked( $enabled, false, false ) . ' /> '
			/* translators: %s: "Read only". */
			. '<span>' . sprintf( esc_html__( '%s — AI tools can look but not change anything', 'flitagent' ), '<strong>' . esc_html__( 'Read only', 'flitagent' ) . '</strong>' ) . '</span></label>'
			. '<label class="flitagent-check-label"><input type="radio" name="' . esc_attr( WpPolicy::OPTION_ENABLED ) . '" value="1"' . checked( $enabled, true, false ) . ' /> '
			/* translators: %s: "Read and propose changes". */
			. '<span>' . sprintf( esc_html__( '%s — each change waits in Approvals unless auto-approve below allows it', 'flitagent' ), '<strong>' . esc_html__( 'Read and propose changes', 'flitagent' ) . '</strong>' ) . '</span></label>'
			. '<p class="description">' . esc_html__( 'Read and propose changes by default. One setting for every AI tool connected to this site, however it connected; tools follow a change straight away, with no need to reconnect. While Read only, no write tool is offered and nothing can be changed through FlitAgent.', 'flitagent' ) . '</p>'
			// A connection paired for reading only stays read whatever is chosen here.
			. ( 'read' === $paired ? '<div class="notice notice-warning inline flitagent-notice"><p>' . esc_html__( 'This site is connected to flitagent.com for reading only, so AI tools stay Read only whatever is chosen here. Disconnect the site in FlitAgent → Connect and connect it again to allow changes.', 'flitagent' ) . '</p></div>' : '' )
			. '</fieldset></div>';

		$html .= '<div class="flitagent-setting"><p class="flitagent-setting-label">' . esc_html__( 'Auto-approve', 'flitagent' ) . '</p><fieldset class="flitagent-setting-body"><legend class="screen-reader-text">' . esc_html__( 'Auto-approve', 'flitagent' ) . '</legend><div class="flitagent-tools">';
		foreach ( $this->gateway->auto_approvable_tools() as $tool ) {
			$html .= '<label class="flitagent-check-label"><input type="checkbox" name="' . esc_attr( WpPolicy::OPTION_AUTO ) . '[]" value="' . esc_attr( $tool ) . '"' . checked( in_array( $tool, $auto, true ), true, false ) . ' /> ' . Ui::tool_name( $tool ) . '</label>';
		}
		$html .= '</div><p class="description">' . esc_html__( 'Used only with Read and propose changes. Apply these tools\' low-risk, reversible changes without asking (for content tools: only plain text and simple blocks, and only while nothing becomes public). Everything else, including destructive changes, always needs your approval, whatever is ticked here.', 'flitagent' ) . '</p></fieldset></div>';

		$html .= '<div class="flitagent-setting"><p class="flitagent-setting-label"><label for="flitagent-ttl">' . esc_html__( 'Approval expiry', 'flitagent' ) . '</label></p><div class="flitagent-setting-body flitagent-setting-inline"><input id="flitagent-ttl" type="number" min="5" max="60" name="' . esc_attr( WpPolicy::OPTION_TTL ) . '" value="' . esc_attr( (string) $ttl ) . '" class="small-text" /> <span>'
			. esc_html__( 'minutes the agent has to apply a change after you approve it (5-60).', 'flitagent' ) . '</span></div></div>';

		$html .= '<div class="flitagent-setting"><p class="flitagent-setting-label">' . esc_html__( 'Uninstall', 'flitagent' ) . '</p><div class="flitagent-setting-body"><label class="flitagent-check-label"><input type="checkbox" name="' . esc_attr( WpPolicy::OPTION_REMOVE_DATA ) . '" value="1"' . checked( $remove, true, false ) . ' /> '
			. esc_html__( 'Delete FlitAgent\'s receipts, change sets and settings when the plugin is deleted.', 'flitagent' ) . '</label>'
			. '<p class="description">' . esc_html__( 'On by default: a reinstall starts fresh with the setup guide. Turn it off to keep the settings for a reinstall; deactivating the plugin keeps them either way. Deleting the plugin always disconnects this site and its AI tools from flitagent.com; after you reconnect, set up each AI tool again.', 'flitagent' ) . '</p></div></div>';

		$html .= '<p class="flitagent-setting-save"><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'flitagent' ) . '</button></p></form></section>';

		return $html . $sections . '</div>';
	}

	/**
	 * The current user's one-time notice, then forgets it.
	 *
	 * @return string
	 */
	public function notice(): string {
		return Ui::notice_once();
	}

	/**
	 * One change: facts, preview diff, exact arguments, decision buttons.
	 *
	 * @param string $id Change UUID.
	 * @return string
	 */
	private function detail( string $id ): string {
		$html   = Ui::open( __( 'FlitAgent change', 'flitagent' ) ) . $this->notice();
		$change = $this->store->change( $id );
		if ( null === $change ) {
			return $html . '<p class="flitagent-empty">' . esc_html__( 'Change not found.', 'flitagent' ) . '</p></div>';
		}

		// Label => escaped value.
		$facts = array(
			__( 'Summary', 'flitagent' )   => esc_html( (string) $change['summary'] ),
			__( 'Status', 'flitagent' )    => Ui::chip( (string) $change['status'] ),
			__( 'Tool', 'flitagent' )      => Ui::tool_name( (string) $change['tool'] ),
			__( 'Tier', 'flitagent' )      => esc_html( (string) $change['tier'] ),
			__( 'Requester', 'flitagent' ) => esc_html( self::user( (int) $change['requester_user_id'] ) ),
			__( 'Client', 'flitagent' )    => esc_html( Client::display_name( (string) $change['client_name'] ) ),
			__( 'Requested', 'flitagent' ) => esc_html( $this->age( (int) $change['created_at'] ) ),
			__( 'Expires', 'flitagent' )   => esc_html( self::utc( (int) $change['expires_at'] ) . ' UTC' ),
		);
		$html .= '<section class="flitagent-card"><dl class="flitagent-facts">';
		foreach ( $facts as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd>';
		}
		$html .= '</dl></section>';

		$plan = Gateway::PLAN_TOOL === $change['tool'] ? null : $this->plan_of( $id );
		if ( null !== $plan ) {
			$html .= '<div class="notice notice-info inline flitagent-notice"><p>' . esc_html__( 'This change is part of a plan. Review and decide it on the plan:', 'flitagent' ) . ' <a href="' . esc_url( self::change_url( (string) $plan['uuid'] ) ) . '">' . esc_html( (string) $plan['summary'] ) . '</a></p></div>';
		}

		$preview = Canonical::decode( $change['preview_json'] );
		if ( Gateway::PLAN_TOOL === $change['tool'] ) {
			return $html . $this->plan_detail( $id, $change ) . self::back_link() . '</div>';
		}
		$body = self::preview_body( $preview );
		if ( '' !== $body ) {
			$html .= '<section class="flitagent-card"><h2>' . esc_html( self::preview_heading( (string) $change['status'] ) ) . '</h2>' . $body . '</section>';
		}

		$buttons = '';
		if ( null !== $plan ) {
			$buttons = '';
		} elseif ( 'pending' === $change['status'] ) {
			$buttons .= '<button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Approve', 'flitagent' ) . '</button> ';
		}
		if ( null === $plan && in_array( $change['status'], array( 'pending', 'approved' ), true ) ) {
			$buttons .= '<button type="submit" name="decision" value="reject" class="button">' . esc_html__( 'Reject', 'flitagent' ) . '</button>';
		}
		// The decision buttons sit in the arguments card: what you approve is what you read just above them.
		$arguments = '<section class="flitagent-card"><h2>' . esc_html__( 'Exact arguments that will run', 'flitagent' ) . '</h2><pre>' . esc_html( self::pretty( Canonical::decode( $change['args_json'] ) ) ) . '</pre>'
			. ( '' === $buttons ? '' : '<p class="flitagent-actions">' . $buttons . '</p>' ) . '</section>';
		if ( '' !== $buttons ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="flitagent_decide" />'
				. '<input type="hidden" name="change" value="' . esc_attr( $id ) . '" />'
				. self::decide_nonces( $id )
				. $arguments . '</form>';
		} else {
			$html .= $arguments;
		}

		return $html . self::back_link() . '</div>';
	}

	/**
	 * The approve or reject form's two nonces: the form's own, then the one bound to this change (Actions::CHANGE_NONCE).
	 *
	 * @param string $id Change UUID.
	 * @return string
	 */
	private static function decide_nonces( string $id ): string {
		return wp_nonce_field( Actions::DECIDE, '_wpnonce', true, false )
			. '<input type="hidden" name="' . esc_attr( Actions::CHANGE_NONCE ) . '" value="' . esc_attr( wp_create_nonce( Actions::DECIDE . '_' . $id ) ) . '" />';
	}

	/**
	 * The link back to the Approvals list.
	 *
	 * @return string
	 */
	private static function back_link(): string {
		return '<p><a href="' . esc_url( admin_url( 'admin.php?page=flitagent-approvals' ) ) . '">' . esc_html__( 'Back to approvals', 'flitagent' ) . '</a></p>';
	}

	/**
	 * A plan: its items with a checkbox each (ticked by default) and one decision form.
	 *
	 * @param string               $id     Plan UUID.
	 * @param array<string, mixed> $change Plan row.
	 * @return string
	 */
	private function plan_detail( string $id, array $change ): string {
		$pending = 'pending' === $change['status'];
		$rows    = array();
		$details = '';
		foreach ( Gateway::plan_item_ids( $change ) as $item_id ) {
			$item = $this->store->change( $item_id );
			if ( null === $item ) {
				continue;
			}
			// Approving the plan approves these items, so each one's preview and exact arguments are shown here.
			$details .= '<section class="flitagent-card flitagent-card--item"><h3>' . esc_html( (string) $item['summary'] ) . '</h3>'
				. self::preview_body( Canonical::decode( $item['preview_json'] ) )
				. '<details class="flitagent-details"><summary>' . esc_html__( 'Exact arguments that will run', 'flitagent' ) . '</summary><pre>' . esc_html( self::pretty( Canonical::decode( $item['args_json'] ) ) ) . '</pre></details></section>';
			// The checkbox is named by the change's summary link (aria-labelledby), so screen readers say what it selects.
			$label  = 'flitagent-item-' . $item_id;
			$box    = $pending ? '<input type="checkbox" name="items[]" value="' . esc_attr( $item_id ) . '" checked aria-labelledby="' . esc_attr( $label ) . '" />' : '';
			$rows[] = array(
				'select'  => $box,
				'summary' => '<a id="' . esc_attr( $label ) . '" href="' . esc_url( self::change_url( $item_id ) ) . '">' . esc_html( (string) $item['summary'] ) . '</a>',
				'tool'    => Ui::tool_name( (string) $item['tool'] ),
				'tier'    => esc_html( (string) $item['tier'] ),
				'status'  => Ui::chip( (string) $item['status'] ),
			);
		}//end foreach
		$columns = array(
			'select'  => __( 'Include', 'flitagent' ),
			'summary' => __( 'Change', 'flitagent' ),
			'tool'    => __( 'Tool', 'flitagent' ),
			'tier'    => __( 'Tier', 'flitagent' ),
			'status'  => __( 'Status', 'flitagent' ),
		);

		$intro = '<h2>' . esc_html__( 'Changes in this plan', 'flitagent' ) . '</h2>'
			. '<p>' . esc_html__( 'They are applied in this order, each with its own receipt and rollback. Untick a change to leave it out; approving with nothing ticked rejects the plan. The preview and exact arguments of every change are below.', 'flitagent' ) . '</p>';
		$table = self::list_table( 'flitagent-plan', $columns, $rows, __( 'Changes in this plan', 'flitagent' ) );

		$buttons = '';
		if ( $pending ) {
			$buttons .= '<button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Approve ticked changes', 'flitagent' ) . '</button> ';
		}
		if ( in_array( $change['status'], array( 'pending', 'approved' ), true ) ) {
			$buttons .= '<button type="submit" name="decision" value="reject" class="button">' . esc_html__( 'Reject plan', 'flitagent' ) . '</button>';
		}
		$card = '<section class="flitagent-card">' . $intro . $table . ( '' === $buttons ? '' : '<p class="flitagent-actions">' . $buttons . '</p>' ) . '</section>';
		if ( '' !== $buttons ) {
			$card = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="flitagent_decide" />'
				. '<input type="hidden" name="change" value="' . esc_attr( $id ) . '" />'
				. '<input type="hidden" name="plan_items" value="1" />'
				. self::decide_nonces( $id )
				. $card . '</form>';
		}
		return $card . '<h2 class="flitagent-section-title">' . esc_html( self::preview_heading( (string) $change['status'] ) ) . '</h2>' . $details;
	}

	/**
	 * The plan a change belongs to, or null.
	 *
	 * @param string $id Change UUID.
	 * @return array<string, mixed>|null
	 */
	private function plan_of( string $id ): ?array {
		foreach ( $this->store->changes( array( 'tool' => Gateway::PLAN_TOOL ), 200, 0 ) as $plan ) {
			if ( in_array( $id, Gateway::plan_item_ids( $plan ), true ) ) {
				return $plan;
			}
		}
		return null;
	}

	/**
	 * A preview: the Now / After legend and diffs when it has text fields to compare, then its data. Many previews have
	 * none (a permanent delete has nothing after it, a cache flush has no before and after), so the legend shows only
	 * above diff rows and the data starts open in their place; an empty preview gives nothing (e2e recheck 2026-10-30, N3).
	 *
	 * @param array<string, mixed> $preview Preview.
	 * @return string Escaped HTML, or '' for an empty preview.
	 */
	private static function preview_body( array $preview ): string {
		if ( array() === $preview ) {
			return '';
		}
		$diffs = self::diffs( $preview );
		return ( '' === $diffs ? '' : self::diff_legend() . $diffs )
			. '<details class="flitagent-details"' . ( '' === $diffs ? ' open' : '' ) . '><summary>' . esc_html__( 'Preview data', 'flitagent' ) . '</summary><pre>' . esc_html( self::pretty( $preview ) ) . '</pre></details>';
	}

	/**
	 * Side-by-side diffs of string fields in before/after (writes) or current/restored (rollbacks).
	 *
	 * @param array<string, mixed> $preview Preview.
	 * @return string
	 */
	private static function diffs( array $preview ): string {
		$html = '';
		foreach ( array(
			'before'  => 'after',
			'current' => 'restored',
		) as $left => $right ) {
			if ( ! isset( $preview[ $left ], $preview[ $right ] ) || ! is_array( $preview[ $left ] ) || ! is_array( $preview[ $right ] ) ) {
				continue;
			}
			foreach ( $preview[ $right ] as $field => $value ) {
				$old = isset( $preview[ $left ][ $field ] ) ? $preview[ $left ][ $field ] : '';
				if ( ! is_string( $old ) || ! is_string( $value ) ) {
					continue;
				}
				$diff  = wp_text_diff(
					$old,
					$value,
					array(
						'title_left'  => esc_html__( 'Now', 'flitagent' ),
						'title_right' => esc_html__( 'After', 'flitagent' ),
					)
				);
				$html .= '<h3 class="flitagent-diff-field"><code>' . esc_html( (string) $field ) . '</code></h3>' . ( '' === $diff ? '<p>' . esc_html__( 'No visible difference.', 'flitagent' ) . '</p>' : '<div class="flitagent-diff">' . wp_kses_post( $diff ) . '</div>' );
			}
		}
		return $html;
	}

	/**
	 * The preview's heading. "Nothing has changed yet" holds only while the change waits (pending, or approved and not
	 * yet applied) or was stopped before it ran (stale: the target changed since approval); once it is applying, ran,
	 * failed, was rejected or expired, the preview is just the preview.
	 *
	 * @param string $status Change status.
	 * @return string
	 */
	private static function preview_heading( string $status ): string {
		return in_array( $status, array( 'pending', 'approved', 'stale' ), true ) ? __( 'Preview: nothing has changed yet', 'flitagent' ) : __( 'Preview', 'flitagent' );
	}

	/**
	 * The text legend of the diffs (A6: markers and a visible legend, never colour alone).
	 *
	 * @return string
	 */
	private static function diff_legend(): string {
		return '<p class="flitagent-diff-legend"><span class="flitagent-diff-legend-removed">' . esc_html__( '− Now (removed)', 'flitagent' ) . '</span> '
			. '<span class="flitagent-diff-legend-added">' . esc_html__( '+ After (added)', 'flitagent' ) . '</span></p>';
	}

	/**
	 * Tool and status filters.
	 *
	 * @param array<string, string> $where Current filters.
	 * @return string
	 */
	private function filters( array $where ): string {
		$tools = array();
		foreach ( $this->gateway->tools() as $tool ) {
			$tools[] = $tool->name();
		}
		$html = '<form method="get" class="flitagent-filters"><input type="hidden" name="page" value="flitagent-activity" />'
			. wp_nonce_field( Request::VIEW, '_wpnonce', false, false );
		foreach ( array(
			'tool'   => $tools,
			'status' => Gateway::RECEIPT_STATUSES,
		) as $name => $choices ) {
			$current = isset( $where[ $name ] ) ? $where[ $name ] : '';
			$html   .= '<label class="flitagent-filter"><span>' . esc_html( 'tool' === $name ? __( 'Tool', 'flitagent' ) : __( 'Status', 'flitagent' ) ) . '</span><select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( 'tool' === $name ? __( 'All tools', 'flitagent' ) : __( 'All statuses', 'flitagent' ) ) . '</option>';
			foreach ( $choices as $choice ) {
				$html .= '<option value="' . esc_attr( $choice ) . '"' . selected( $current, $choice, false ) . '>' . esc_html( $choice ) . '</option>';
			}
			$html .= '</select></label> ';
		}
		return $html . '<button type="submit" class="button">' . esc_html__( 'Filter', 'flitagent' ) . '</button></form>';
	}

	/**
	 * "user:3" as a name.
	 *
	 * @param string $approval Approval source.
	 * @return string
	 */
	private static function approval( string $approval ): string {
		if ( 0 === strpos( $approval, 'user:' ) ) {
			return sprintf( /* translators: %s: user name */ __( 'approved by %s', 'flitagent' ), self::user( (int) substr( $approval, 5 ) ) );
		}
		return $approval;
	}

	/**
	 * Rollback cell. Ids are shortened (first 4, last 2) with the full id as the title; `$short` false gives plain text
	 * with the full ids, which the row's Details escape themselves.
	 *
	 * @param array<string, mixed> $row   Receipt row.
	 * @param bool                 $short Escaped HTML with short ids (true) or plain text with full ids.
	 * @return string
	 */
	private static function rollback( array $row, bool $short = true ): string {
		$by   = (string) $row['rolled_back_by'];
		$of   = (string) $row['rolls_back'];
		$show = static function ( string $id ) use ( $short ): string {
			return $short ? '<span title="' . esc_attr( $id ) . '">' . self::short_id_html( $id ) . '</span>' : $id;
		};
		if ( '' !== $by ) {
			$text = sprintf( /* translators: %s: receipt id */ $short ? esc_html__( 'rolled back by %s', 'flitagent' ) : __( 'rolled back by %s', 'flitagent' ), $show( $by ) );
		} elseif ( '' !== $of ) {
			$text = sprintf( /* translators: %s: receipt id */ $short ? esc_html__( 'rolls back %s', 'flitagent' ) : __( 'rolls back %s', 'flitagent' ), $show( $of ) );
		} else {
			$view = Rollback::public_view( Canonical::decode( $row['rollback_json'] ) );
			$text = $view['available'] ? $view['type'] : __( 'none', 'flitagent' );
			$text = $short ? esc_html( $text ) : $text;
		}
		return $text;
	}

	/**
	 * An id shown short, with the full id for screen readers (a title alone is not reliably announced).
	 *
	 * @param string $id Id.
	 * @return string Escaped HTML.
	 */
	private static function short_id_html( string $id ): string {
		return '<span aria-hidden="true">' . esc_html( self::short_id( $id ) ) . '</span><span class="screen-reader-text">' . esc_html( $id ) . '</span>';
	}

	/**
	 * An id as its first 4 and last 2 characters ("d40b…12"); short ids stay whole.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	private static function short_id( string $id ): string {
		return strlen( $id ) > 8 ? substr( $id, 0, 4 ) . '…' . substr( $id, -2 ) : $id;
	}

	/**
	 * A user's display name, or "#id".
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : '#' . $user_id;
	}

	/**
	 * "5 mins ago".
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function age( int $time ): string {
		return sprintf( /* translators: %s: time difference */ __( '%s ago', 'flitagent' ), human_time_diff( $time, $this->clock->now() ) );
	}

	/**
	 * Unix time as "Y-m-d H:i" UTC.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function utc( int $time ): string {
		return gmdate( 'Y-m-d H:i', $time );
	}

	/**
	 * Detail URL of a change, for the browser of the administrator viewing this page: its nonce is good only for them. A
	 * link that leaves the session is WpPolicy::approve_url(), which has none and comes back here through Admin::change_link().
	 *
	 * @param string $id Change UUID.
	 * @return string
	 */
	public static function change_url( string $id ): string {
		return add_query_arg(
			array(
				'page'     => 'flitagent-approvals',
				'change'   => $id,
				'_wpnonce' => wp_create_nonce( Request::VIEW ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Pretty JSON.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private static function pretty( $data ): string {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * A list table in core's markup (as WP_List_Table prints it): the first column is the primary one, with core's
	 * toggle for narrow screens; every cell has its column class and label.
	 *
	 * @param string                            $name    Extra table class.
	 * @param array<string, string>             $columns Column key => label; the first is the primary column.
	 * @param array<int, array<string, string>> $rows    Rows of column key => escaped cell HTML.
	 * @param string                            $label   Accessible name of the scroll region around the table.
	 * @return string
	 */
	private static function list_table( string $name, array $columns, array $rows, string $label ): string {
		// A leading "select" column holds tick boxes: core's check column, its header only for screen readers.
		$primary = '';
		foreach ( array_keys( $columns ) as $key ) {
			if ( 'select' !== $key ) {
				$primary = (string) $key;
				break;
			}
		}
		// A table wider than its card scrolls inside the card; the region takes focus so a keyboard reaches every column.
		$html = '<div class="flitagent-table-scroll" role="region" aria-label="' . esc_attr( $label ) . '" tabindex="0"><table class="wp-list-table widefat fixed striped table-view-list flitagent-table ' . esc_attr( $name ) . '"><thead><tr>';
		foreach ( $columns as $key => $label ) {
			if ( 'select' === $key ) {
				$html .= '<th scope="col" class="manage-column column-select check-column"><span class="screen-reader-text">' . esc_html( $label ) . '</span></th>';
				continue;
			}
			$html .= '<th scope="col" class="' . esc_attr( 'manage-column column-' . $key . ( $primary === $key ? ' column-primary' : '' ) ) . '">' . esc_html( $label ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $cells ) {
			$html .= '<tr>';
			foreach ( $columns as $key => $label ) {
				$colname = ' data-colname="' . esc_attr( $label ) . '"';
				if ( 'select' === $key ) {
					$html .= '<td class="column-select check-column"' . $colname . '>' . $cells[ $key ] . '</td>';
				} elseif ( $primary === $key ) {
					$html .= '<th scope="row" class="' . esc_attr( 'column-' . $key . ' column-primary' ) . '"' . $colname . '>' . $cells[ $key ]
						. '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'flitagent' ) . '</span></button></th>';
				} else {
					$html .= '<td class="' . esc_attr( 'column-' . $key ) . '"' . $colname . '>' . $cells[ $key ] . '</td>';
				}
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * A JSON value for a row's Details: the complete value, escaped. Nothing is cut, so audit values stay whole.
	 *
	 * @param string $json JSON.
	 * @return string
	 */
	private static function json_full( string $json ): string {
		return '<code>' . esc_html( $json ) . '</code>';
	}
}
