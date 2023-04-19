<?php
/**
 * Registers required scripts for SwG implementation
 * specific to Newspack functionality.
 *
 * @package newspack-extended-access
 */

namespace Newspack_Extended_Access;

/**
 * Defines functionality to bypass paywall restriction for single post for single user.
 */
class Option_Page {

	/**
	 * Maintains options for managing Extended Access.
	 * 
	 * @var mixed Holds value to the option.
	 */
	private static $newspack_extended_access_options;
	
	/**
	 * Set up hooks and filters.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'newspack_extended_access_add_plugin_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'newspack_extended_access_page_init' ] );
	}
		
	/**
	 * Registers submenu inside woocommerce.
	 */
	public static function newspack_extended_access_add_plugin_page() {
		add_submenu_page(
			'woocommerce',
			'Extended Access',
			'Extended Access',
			'manage_options',
			'newspack-extended-access',
			[ __CLASS__, 'newspack_extended_access_create_admin_page' ]
		);
	}
	
	/**
	 * Renders admin page for submenu.
	 */
	public static function newspack_extended_access_create_admin_page() {
		self::$newspack_extended_access_options = get_option( 'newspack_extended_access_configuration' ); ?>

		<div class="wrap">
			<h2>Newspack Extended Access</h2>
			<p>Sample description.</p>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
					settings_fields( 'newspack_extended_access_option_group' );
					do_settings_sections( 'newspack-extended-access-admin' );
					submit_button();
				?>
			</form>
		</div>
		<?php 
	}
	
	/**
	 * Initialize fields and sections for submenu.
	 */
	public static function newspack_extended_access_page_init() {
		register_setting(
			'newspack_extended_access_option_group',
			'newspack_extended_access_configuration',
			[ __CLASS__, 'newspack_extended_access_sanitize' ]
		);

		add_settings_section(
			'newspack_extended_access_setting_section',
			'Settings',
			[ __CLASS__, 'newspack_extended_access_section_info' ],
			'newspack-extended-access-admin'
		);

		add_settings_field(
			'google_client_id',
			'Google Client ID',
			[ __CLASS__, 'google_client_id_callback' ],
			'newspack-extended-access-admin',
			'newspack_extended_access_setting_section'
		);

		add_settings_field(
			'default_subscription',
			'Default Subscription',
			[ __CLASS__, 'default_subscription_callback' ],
			'newspack-extended-access-admin',
			'newspack_extended_access_setting_section'
		);
	}
	
	/**
	 * Sanitize values inputted by user.
	 *
	 * @param  mixed $input Value to be sanitize.
	 * @return mixed Returns sanitized value.
	 */
	public static function newspack_extended_access_sanitize( $input ) {
		$sanitary_values = array();
		if ( isset( $input['google_client_id'] ) ) {
			$sanitary_values['google_client_id'] = sanitize_text_field( $input['google_client_id'] );
		}

		if ( isset( $input['default_subscription'] ) ) {
			$sanitary_values['default_subscription'] = $input['default_subscription'];
		}

		return $sanitary_values;
	}
	
	/**
	 * Renders section information.
	 * TODO (@AnuragVasanwala): TBD.
	 */
	public static function newspack_extended_access_section_info() {
		
	}
	
	/**
	 * Renders Google Client ID input box.
	 */
	public static function google_client_id_callback() {
		printf(
			'<input class="regular-text" type="text" name="newspack_extended_access_configuration[google_client_id]" id="google_client_id" value="%s">',
			isset( self::$newspack_extended_access_options['google_client_id'] ) ? esc_attr( self::$newspack_extended_access_options['google_client_id'] ) : ''
		);
	}
	
	/**
	 * Renders membership plan selection box.
	 */
	public static function default_subscription_callback() {
		if ( function_exists( 'wc_memberships_get_membership_plans' ) ) {
			$plans = wc_memberships_get_membership_plans();
			?>
			<select name="newspack_extended_access_configuration[default_subscription]" id="default_subscription">
			<?php
			foreach ( $plans as $id => $plan ) {
				$selected = ( isset( self::$newspack_extended_access_options['default_subscription'] ) && self::$newspack_extended_access_options['default_subscription'] === $plan->slug ) ? 'selected' : '';
				?>
					<option value="<?php echo esc_attr( $plan->slug ); ?>" <?php echo esc_attr( $selected ); ?>><?php echo esc_html( $plan->name ); ?></option>
					<?php
			}
			?>
			</select> 
			<?php
		} else {
			?>
			<select name="newspack_extended_access_configuration[default_subscription]" id="default_subscription">
				<option value="no-plan" selected>No plans available</option>
			</select> 
			<?php
		}
	}
}
