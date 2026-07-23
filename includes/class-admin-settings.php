<?php
/**
 * Standalone wp-admin settings page for the plugin.
 *
 * @package Newspack\ExtendedAccess
 */

namespace Newspack\ExtendedAccess;

/**
 * Registers a settings page under the wp-admin Settings menu.
 *
 * This is the plugin's canonical configuration surface. It works with any
 * content gating system: unlike the WooCommerce settings section (see
 * WC_Settings_Memberships_Option_Tab), it remains reachable on sites gated by
 * Newspack Access Control, where WooCommerce Memberships is deactivated and
 * the wc-settings Memberships tab does not exist. Both surfaces read and
 * write the same option.
 */
class Admin_Settings {

	/**
	 * Option storing the Google Client API ID.
	 */
	const GOOGLE_CLIENT_API_ID_OPTION = 'newspack_extended_access__google_client_api_id';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'newspack-extended-access';

	/**
	 * Settings group.
	 */
	const SETTINGS_GROUP = 'newspack_extended_access';

	/**
	 * Settings section id.
	 */
	const SECTION_ID = 'newspack_extended_access_google';

	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . self::get_plugin_basename(), array( __CLASS__, 'add_settings_action_link' ) );
	}

	/**
	 * Get the plugin basename, e.g. `newspack-extended-access/newspack-extended-access.php`.
	 *
	 * @return string The plugin basename.
	 */
	protected static function get_plugin_basename(): string {
		return plugin_basename( dirname( __DIR__ ) . '/newspack-extended-access.php' );
	}

	/**
	 * Get the URL of the settings page.
	 *
	 * @return string The settings page URL.
	 */
	public static function get_settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Add a Settings link to the plugin's row on the Plugins page.
	 *
	 * @param string[] $links The plugin action links.
	 * @return string[] The action links with the Settings link prepended.
	 */
	public static function add_settings_action_link( $links ) {
		$settings_link = '<a href="' . esc_url( self::get_settings_url() ) . '">' . esc_html__( 'Settings', 'newspack-extended-access' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register the settings page under the Settings menu.
	 */
	public static function register_settings_page() {
		add_options_page(
			__( 'Newspack Extended Access', 'newspack-extended-access' ),
			__( 'Extended Access', 'newspack-extended-access' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the setting, its section, and its field.
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::GOOGLE_CLIENT_API_ID_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_google_client_api_id' ),
				'default'           => '',
			)
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Google Extended Access', 'newspack-extended-access' ),
			array( __CLASS__, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::GOOGLE_CLIENT_API_ID_OPTION,
			__( 'Google Client API ID', 'newspack-extended-access' ),
			array( __CLASS__, 'render_google_client_api_id_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::GOOGLE_CLIENT_API_ID_OPTION )
		);
	}

	/**
	 * Sanitize the Google Client API ID.
	 *
	 * An invalid-looking value is still saved (matching the WooCommerce
	 * settings surface), but a warning is surfaced because the plugin stays
	 * dormant until the option holds a valid hostname-shaped client ID.
	 *
	 * @param mixed $value The raw submitted value.
	 * @return string The sanitized value.
	 */
	public static function sanitize_google_client_api_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		// The callback runs on every update of the option (it is hooked to
		// sanitize_option_*), so the admin-only settings-error API may be
		// unavailable, e.g. under WP-CLI.
		if ( '' !== $value && ! filter_var( $value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) && function_exists( 'add_settings_error' ) ) {
			// The callback can run twice for a single save (when the option
			// row does not exist yet, update_option falls through to
			// add_option and the sanitizer runs again), so only add the
			// warning if it is not already queued.
			$already_warned = false;
			foreach ( get_settings_errors( self::GOOGLE_CLIENT_API_ID_OPTION ) as $settings_error ) {
				if ( 'invalid_google_client_api_id' === $settings_error['code'] ) {
					$already_warned = true;
					break;
				}
			}
			if ( ! $already_warned ) {
				add_settings_error(
					self::GOOGLE_CLIENT_API_ID_OPTION,
					'invalid_google_client_api_id',
					__( 'The Google Client API ID does not look valid. It should look like 1234567890-abcdef.apps.googleusercontent.com. Extended Access will remain inactive until a valid ID is saved.', 'newspack-extended-access' ),
					'warning'
				);
			}
		}
		return $value;
	}

	/**
	 * Render the section description.
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'An integration for utilizing Google Extended Access.', 'newspack-extended-access' ) . '</p>';
	}

	/**
	 * Render the Google Client API ID field.
	 */
	public static function render_google_client_api_id_field() {
		$home_url_parts    = wp_parse_url( home_url() );
		$allowed_referrers = $home_url_parts['scheme'] . '://' . $home_url_parts['host'];
		// A non-standard port is part of the origin Google must allow.
		if ( ! empty( $home_url_parts['port'] ) ) {
			$allowed_referrers .= ':' . $home_url_parts['port'];
		}
		$allowed_referrers = esc_url( $allowed_referrers );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( self::GOOGLE_CLIENT_API_ID_OPTION ); ?>"
			name="<?php echo esc_attr( self::GOOGLE_CLIENT_API_ID_OPTION ); ?>"
			value="<?php echo esc_attr( get_option( self::GOOGLE_CLIENT_API_ID_OPTION, '' ) ); ?>"
			class="large-text code"
			placeholder="1234567890-abcdef.apps.googleusercontent.com"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: 1: Google developer documentation link, 2: site origin to allow. */
				wp_kses(
					__( 'Refer to the <a href="%1$s" target="_blank">Google Developer Documents</a> to set up and configure your Google Client API ID. Make sure to add your domain <b><u>%2$s</u></b> to Authorized JavaScript origins.', 'newspack-extended-access' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
						),
						'b' => array(),
						'u' => array(),
					)
				),
				'https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid',
				esc_url( $allowed_referrers )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
