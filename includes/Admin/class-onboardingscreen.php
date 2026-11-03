<?php
/**
 * The first-run setup guide screen (#134).
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Walks an administrator through FlitAgent in five steps (#209 follows the design of 2026-10-28): welcome, check if the
 * site is ready (#148), connect (the site to flitagent.com, then the AI tools, as on the Connect screen), what AI tools
 * may do on the site (the site's one access setting), done. The guide's own forms post to admin-post.php with the
 * Onboarding::ACTION action and nonce. Every dynamic value is escaped here, so callers may echo it.
 */
final class OnboardingScreen {

	/**
	 * The screen for this site.
	 *
	 * @param array<string, mixed>|null $query The request's query arguments ($_GET).
	 * @param array<string, mixed>|null $facts Facts, or null for ConnectScreen::facts().
	 * @param callable|null             $run   Runs the checks and returns Diagnostics results, or null for DiagnosticsScreen::run().
	 * @return string
	 */
	public static function render( array $query, ?array $facts = null, ?callable $run = null ): string {
		$step  = self::step( $query );
		$facts = $facts ?? ConnectScreen::facts();
		$run   = $run ?? array( DiagnosticsScreen::class, 'run' );
		// The Connect step's Set up link without JavaScript (`&setup=<tool>`) opens that tool's dialog in the page, and
		// tool-status.js comes back with `&flitagent_connected=<tool>` to show that it is connected.
		$facts['setup'] = ConnectScreen::setup_tool( $query['setup'] ?? '' );
		$facts['done']  = ConnectScreen::setup_tool( $query[ ConnectScreen::DONE ] ?? '' );
		if ( ! self::runs_checks( $step, $facts, $query ) ) {
			return self::html( $step, $facts );
		}
		// Arriving on the step runs nothing before the page is sent: that would leave a blank screen while the server
		// tests itself. The page shows the checks in progress and the script fetches the run (`run=1`), which is also
		// what a click on Run the checks does.
		if ( ! isset( $query['run'] ) || '1' !== $query['run'] ) {
			return self::html( $step, $facts, null, true );
		}
		return self::html( $step, $facts, call_user_func( $run ) );
	}

	/**
	 * Whether this request runs Test connection (#148): on the check step, by itself only on an HTTPS site that is not
	 * local. A local server may answer one request at a time (WordPress Studio, also on custom domains such as
	 * mysite.local, which are plain HTTP), so every loopback would wait for its timeout; on plain HTTP the verdict is
	 * already known. There the checks run only when asked (`run=1`), on a link with a valid nonce (Request::query()).
	 *
	 * @param string               $step  The step shown.
	 * @param array<string, mixed> $facts Facts.
	 * @param array<string, mixed> $query The request's query arguments ($_GET).
	 * @return bool
	 */
	public static function runs_checks( string $step, array $facts, array $query ): bool {
		if ( 'check' !== $step ) {
			return false;
		}
		return ( empty( $facts['local'] ) && ! empty( $facts['https'] ) ) || ( isset( $query['run'] ) && '1' === $query['run'] );
	}

	/**
	 * The step asked for (a removed step's replacement, see Onboarding::RENAMED), or welcome when it is missing or unknown.
	 *
	 * @param array<string, mixed> $query The request's query arguments ($_GET).
	 * @return string One of Onboarding::STEPS.
	 */
	public static function step( array $query ): string {
		return Onboarding::step( $query['step'] ?? '' );
	}

	/**
	 * The screen for a step from facts about the site.
	 *
	 * @param string                                 $step    One of Onboarding::STEPS; anything else shows welcome.
	 * @param array<string, mixed>                   $facts   Facts (see ConnectScreen::facts()).
	 * @param array<int, array<string, string>>|null $results The check step's Diagnostics results, or null when they did not run.
	 * @param bool                                   $checking Whether the check step shows the checks in progress (the script then fetches them).
	 * @return string
	 */
	public static function html( string $step, array $facts, ?array $results = null, bool $checking = false ): string {
		$step = self::step( array( 'step' => $step ) );
		$n    = (int) array_search( $step, Onboarding::STEPS, true ) + 1;
		// Back goes in the step's own action row (self::actions()), before Continue, on every step after the first.
		$back = $n > 1 ? '<a href="' . esc_url( Onboarding::url( Onboarding::STEPS[ $n - 2 ] ) ) . '" class="button flitagent-back">' . esc_html__( 'Back', 'flitagent' ) . '</a>' : '';

		// The step card is the frame every step shares; its width follows the step's board (`--<step>` modifier). The
		// Connect step's notice (the outcome of connecting in a new tab (#198), a Disconnect or a Set up) sits above the
		// card, as on every other screen, not inside it (e2e 2026-10-30, M11).
		$html = Ui::open( __( 'Set up FlitAgent', 'flitagent' ), 'flitagent-onboarding' )
			. ( 'connect' === $step ? Ui::notice_once() : '' )
			/* translators: 1: current step number, 2: number of steps. */
			. '<section class="flitagent-card flitagent-onboarding-card flitagent-onboarding-card--' . esc_attr( $step ) . '"><p class="flitagent-onboarding-step">' . esc_html( sprintf( __( 'Step %1$d of %2$d', 'flitagent' ), $n, count( Onboarding::STEPS ) ) ) . '</p>';

		switch ( $step ) {
			case 'check':
				$html .= self::check( $facts, $results, $back, $checking );
				break;
			case 'connect':
				$html .= self::connect( $facts, $back );
				break;
			case 'done':
				$html .= self::done( $back );
				break;
			default:
				$html .= self::welcome();
		}//end switch

		return $html . '</section></div>';
	}

	/**
	 * Welcome: what FlitAgent does and how it keeps the site safe.
	 *
	 * @return string
	 */
	private static function welcome(): string {
		return '<h2>' . esc_html__( 'Welcome', 'flitagent' ) . '</h2>'
			. '<p>' . esc_html__( 'FlitAgent connects this site to AI apps like ChatGPT and Claude. The app works through typed tools: it can read your content and check the site\'s health.', 'flitagent' ) . '</p>'
			. '<p>' . esc_html__( 'Your site stays in your hands:', 'flitagent' ) . '</p><ul class="ul-disc">'
			. '<li>' . esc_html__( 'The app can only read: it cannot change anything on your site.', 'flitagent' ) . '</li>'
			. '<li>' . esc_html__( 'It acts as you, so it sees only what your administrator account can see.', 'flitagent' ) . '</li>'
			. '<li>' . esc_html__( 'The app cannot run arbitrary code, only the tools FlitAgent offers.', 'flitagent' ) . '</li></ul>'
			. self::actions( '<a href="' . esc_url( Onboarding::url( 'check' ) ) . '" class="button button-primary">' . esc_html__( 'Get started', 'flitagent' ) . '</a>' );
	}

	/**
	 * Connect (#209): the Connect screen's two cards inside the step. A progress tracker, then Site connectivity (the
	 * Connect to flitagent.com form, or the connection's facts) and Connected AI tools (Set up
	 * opens the Connect screen's dialogs, inert until the site is connected). A site that cannot connect says why;
	 * Continue goes on to the last step either way.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @param string               $back  Escaped Back button for the action row.
	 * @return string
	 */
	private static function connect( array $facts, string $back ): string {
		return '<h2>' . esc_html__( 'Connect', 'flitagent' ) . '</h2>'
			. self::progress( $facts )
			. '<p>' . esc_html__( 'Connect this site to flitagent.com, then set up the AI tools you use.', 'flitagent' ) . '</p>'
			. '<section class="flitagent-connect-box" aria-labelledby="flitagent-connectivity-title">' . ConnectScreen::status_section( $facts, true ) . '</section>'
			. '<section class="flitagent-connect-box" aria-labelledby="flitagent-tools-title">' . ConnectScreen::tools_section( $facts, true ) . '</section>'
			. ConnectScreen::dialogs( $facts, true )
			. self::actions( '<a href="' . esc_url( Onboarding::url( 'done' ) ) . '" class="button button-primary">' . esc_html__( 'Continue', 'flitagent' ) . '</a>', $back, true );
	}

	/**
	 * The Connect step's progress (#209): connect to flitagent.com, then connect AI tools. Not connected (or waiting):
	 * the first is current. Connected without a tool that has used the site: the first is done, the second current.
	 * Once a tool has used the site (Apps::tools()): both done. An ordered list; the current step has
	 * `aria-current="step"` and each says its state to screen readers. The track and the circles are decoration.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return string
	 */
	public static function progress( array $facts ): string {
		$site  = 'connected' === ( $facts['state'] ?? '' );
		$tools = $site && array() !== ConnectScreen::connected_tools( $facts );
		$at    = $site ? ( $tools ? 'done' : 'tools' ) : 'site';
		$steps = array(
			array( __( 'Connect to flitagent.com', 'flitagent' ), $site ? 'done' : 'current' ),
			array( __( 'Connect AI tools', 'flitagent' ), $tools ? 'done' : ( $site ? 'current' : 'upcoming' ) ),
		);
		$said  = array(
			'done'     => __( ', completed', 'flitagent' ),
			'current'  => __( ', current step', 'flitagent' ),
			'upcoming' => __( ', not started', 'flitagent' ),
		);

		$html = '<div class="flitagent-steps-wrap"><ol class="flitagent-steps flitagent-steps--' . esc_attr( $at ) . '" aria-label="' . esc_attr__( 'Connect progress', 'flitagent' ) . '">';
		foreach ( $steps as list( $label, $state ) ) {
			$html .= '<li class="flitagent-steps-item is-' . esc_attr( $state ) . '"' . ( 'current' === $state ? ' aria-current="step"' : '' ) . '>'
				. '<span class="flitagent-steps-label">' . esc_html( $label ) . '<span class="screen-reader-text">' . esc_html( $said[ $state ] ) . '</span></span>'
				. '<span class="flitagent-steps-dot" aria-hidden="true">' . ( 'done' === $state ? Ui::icon( 'check', 15 ) : '' ) . '</span></li>';
		}
		return $html . '</ol></div>';
	}

	/**
	 * How the check step ends (#148): `local` (flitagent.com cannot reach the site), `fail` (not HTTPS as WordPress
	 * sees it, or a check failed), `unchecked` (a check could not tell, such as a loopback that got no answer or ran out
	 * of time: never read as ready) or `ready`. Other warnings (Plain permalinks, a blocked path with a fallback) do not
	 * hold the site back.
	 *
	 * @param array<string, mixed>                   $facts   Facts.
	 * @param array<int, array<string, string>>|null $results Diagnostics results, or null when they did not run.
	 * @return string
	 */
	public static function verdict( array $facts, ?array $results ): string {
		if ( ! empty( $facts['local'] ) ) {
			return 'local';
		}
		if ( empty( $facts['https'] ) ) {
			return 'fail';
		}
		foreach ( (array) $results as $result ) {
			if ( Diagnostics::FAIL === ( $result['status'] ?? '' ) ) {
				return 'fail';
			}
		}
		foreach ( (array) $results as $result ) {
			if ( ! empty( $result['unchecked'] ) ) {
				return 'unchecked';
			}
		}
		// Results that did not run on a public site (render() always runs them there) never read as ready.
		return null === $results ? 'fail' : 'ready';
	}

	/**
	 * Check: Test connection's results and what to do next.
	 *
	 * @param array<string, mixed>                   $facts    Facts.
	 * @param array<int, array<string, string>>|null $results  Diagnostics results, or null when they did not run.
	 * @param string                                 $back     Escaped Back button for the action row.
	 * @param bool                                   $checking Whether to show the checks in progress (the script then fetches them).
	 * @return string
	 */
	private static function check( array $facts, ?array $results, string $back, bool $checking = false ): string {
		if ( $checking ) {
			return self::checking( $back );
		}
		$verdict = self::verdict( $facts, $results );
		$connect = esc_url( Onboarding::url( 'connect' ) );
		$again   = esc_url( Onboarding::url( 'check', true ) );
		$label   = null === $results ? esc_html__( 'Run the checks', 'flitagent' ) : esc_html__( 'Check again', 'flitagent' );
		// The row ends with its primary button; Continue anyway (a link) sits before it.
		$run    = static function ( string $primary, string $text ) use ( $again ): string {
			return '<a href="' . $again . '" data-flitagent-run="' . esc_attr( __( 'Checking…', 'flitagent' ) ) . '" class="button' . $primary . '">' . $text . '</a>';
		};
		$anyway = '<a href="' . $connect . '" class="button-link">' . esc_html__( 'Continue anyway', 'flitagent' ) . '</a>';
		$onward = '<a href="' . $connect . '" class="button button-primary">' . esc_html__( 'Continue', 'flitagent' ) . '</a>';

		$html = '<h2>' . esc_html__( 'Check if your site is ready', 'flitagent' ) . '</h2>'
			. '<p>' . esc_html__( 'FlitAgent checks, from this server, the settings that most often stop flitagent.com from connecting: HTTPS, permalinks, the MCP endpoint and custom headers.', 'flitagent' ) . '</p>';
		if ( null !== $results ) {
			$html .= DiagnosticsScreen::table( $results );
		}

		switch ( $verdict ) {
			case 'local':
				$failed = in_array( Diagnostics::FAIL, array_column( (array) $results, 'status' ), true );
				$html  .= self::notice( 'info', __( 'flitagent.com must reach this site over the internet, so local and private sites cannot be connected.', 'flitagent' ) )
					. ( $failed ? self::actions( $anyway . $run( ' button-primary', esc_html__( 'Check again', 'flitagent' ) ), $back ) : self::actions( $run( '', $label ) . $onward, $back ) );
				break;
			case 'unchecked':
				$html .= self::notice( 'warning', __( 'Some checks could not run, so FlitAgent cannot tell whether apps will connect. Follow the warnings above, then check again.', 'flitagent' ) )
					. self::actions( $anyway . $run( ' button-primary', esc_html__( 'Check again', 'flitagent' ) ), $back );
				break;
			case 'fail':
				if ( null !== $results ) {
					$message = __( 'Fix what failed before connecting an app, then check again.', 'flitagent' );
				} elseif ( empty( $facts['https_address'] ) ) {
					$message = __( 'This site is not on HTTPS, so flitagent.com cannot connect. Turn on HTTPS with your host, change the WordPress Address and Site Address in Settings → General to https://, then check again.', 'flitagent' );
				} else {
					$message = __( 'The site\'s address uses HTTPS, but WordPress sees its requests as plain HTTP, so flitagent.com cannot connect. Run the checks for the fix (Cloudflare\'s Flexible SSL or a proxy that ends HTTPS without telling WordPress). If you opened this page over http://, open it over https:// and check again.', 'flitagent' );
				}
				$html .= self::notice( 'error', $message ) . self::actions( $anyway . $run( ' button-primary', $label ), $back );
				break;
			default:
				$warned = in_array( Diagnostics::WARNING, array_column( (array) $results, 'status' ), true );
				$html  .= self::notice( 'success', $warned ? __( 'Ready to connect. Some checks warned: if an app has trouble connecting, start with them.', 'flitagent' ) : __( 'Ready to connect.', 'flitagent' ) )
					. self::actions( $onward, $back );
		}//end switch

		if ( 'local' !== $verdict ) {
			$html .= '<p class="flitagent-onboarding-note">' . esc_html__( 'These checks run from this server to itself. Passing them does not prove flitagent.com can reach the site: a firewall or bot protection that blocks outside visitors, or an invalid or expired certificate (which these checks do not verify), can still stop them.', 'flitagent' ) . '</p>';
		}
		return $html;
	}

	/**
	 * The check step while the checks run: a message and an endless progress bar. The script fetches the finished step
	 * from the `data-flitagent-autorun` address and swaps it in; without it, the button runs the checks.
	 *
	 * @param string $back Escaped Back button.
	 * @return string
	 */
	private static function checking( string $back ): string {
		$run = esc_url( Onboarding::url( 'check', true ) );
		return '<h2>' . esc_html__( 'Check if your site is ready', 'flitagent' ) . '</h2>'
			. '<div class="flitagent-checking" data-flitagent-autorun="' . $run . '" role="status" aria-live="polite">'
			. '<p class="flitagent-checking-text">' . esc_html__( 'Checking this site…', 'flitagent' ) . '</p>'
			. '<div class="flitagent-progress" aria-hidden="true"><span></span></div>'
			. '<p class="description">' . esc_html__( 'FlitAgent is testing HTTPS, permalinks, the MCP endpoint and custom headers from this server. It takes a few seconds.', 'flitagent' ) . '</p>'
			. '<noscript><p><a href="' . $run . '" class="button button-primary">' . esc_html__( 'Run the checks', 'flitagent' ) . '</a></p></noscript>'
			. '</div>'
			. self::actions( '', $back );
	}

	/**
	 * An inline notice.
	 *
	 * @param string $type    info, success, warning or error.
	 * @param string $message Message.
	 * @return string
	 */
	private static function notice( string $type, string $message ): string {
		return '<div class="notice notice-' . esc_attr( $type ) . ' inline flitagent-notice flitagent-onboarding-verdict" role="status"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * A step's action row, the last part of the frame every step shares: Back first, then the step's own buttons, all
	 * at the right end (the boards). `$rule` draws a hairline above the row (the Connect board).
	 *
	 * @param string $buttons Escaped buttons and links of the step.
	 * @param string $back    Escaped Back button, or empty on the first step.
	 * @param bool   $rule    Whether a hairline separates the row from the step's content.
	 * @return string
	 */
	public static function actions( string $buttons, string $back = '', bool $rule = false ): string {
		return '<p class="flitagent-actions flitagent-onboarding-actions' . ( $rule ? ' flitagent-onboarding-actions--rule' : '' ) . '">' . $back . $buttons . '</p>';
	}

	/**
	 * Done: where to go next, and Finish.
	 *
	 * @param string $back Escaped Back button for the action row.
	 * @return string
	 */
	private static function done( string $back ): string {
		return '<h2>' . esc_html__( 'You\'re set', 'flitagent' ) . '</h2><ul class="ul-disc">'
			. '<li><a href="' . esc_url( admin_url( 'admin.php?page=flitagent-settings' ) ) . '">' . esc_html__( 'Settings', 'flitagent' ) . '</a>: ' . esc_html__( 'the connection test and what happens on uninstall.', 'flitagent' ) . '</li>'
			. '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . ConnectScreen::SLUG ) ) . '">' . esc_html__( 'Connect', 'flitagent' ) . '</a>: ' . esc_html__( 'your flitagent.com connection and the MCP address.', 'flitagent' ) . '</li></ul>'
			. self::form( 'finish', self::actions( '<button type="submit" class="button button-primary">' . esc_html__( 'Finish', 'flitagent' ) . '</button>', $back ) );
	}

	/**
	 * A form posting to admin-post.php with the setup guide's action and nonce.
	 *
	 * @param string $verb   The `do` value: finish.
	 * @param string $inside Escaped HTML inside the form.
	 * @return string
	 */
	private static function form( string $verb, string $inside ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Onboarding::ACTION ) . '" />'
			. '<input type="hidden" name="do" value="' . esc_attr( $verb ) . '" />'
			. wp_nonce_field( Onboarding::ACTION, '_wpnonce', true, false )
			. $inside . '</form>';
	}
}
