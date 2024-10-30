<?php
/*
 * Major to-do items:

 - cache groups and display them properly on feed list
 - provide some sort of control over "update" vs. "create or update"
 */

use GF_Luminate\WP_HTTP_Luminate as WP_HTTP_Luminate;
use GF_Luminate\Constituent as Constituent;
use GF_Luminate\Survey as Survey;
use GF_Luminate\Donation as Donation;
use GF_Luminate\BBCheckout as BBCheckout;

GFForms::include_payment_addon_framework();

class GFLuminate extends GFPaymentAddOn {

	protected $_version                  = GF_LUMINATE_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	public $_slug                        = 'gravityforms-luminate';
	protected $_path                     = 'gravityforms-luminate/gravityforms-luminate.php';
	protected $_full_path                = __FILE__;
	protected $_url                      = 'https://cornershopcreative.com';
	protected $_title                    = 'Gravity Forms Luminate API Add-On';
	protected $_short_title              = 'Luminate API';
	protected $_enable_rg_autoupgrade    = true;

	/**
	 * Members plugin integration
	 */
	protected $_capabilities = array( 'gravityforms_luminate', 'gravityforms_luminate_uninstall' );

	/**
	 * Permissions
	 */
	protected $_capabilities_settings_page = 'gravityforms_luminate';
	protected $_capabilities_form_settings = 'gravityforms_luminate';
	protected $_capabilities_uninstall     = 'gravityforms_luminate_uninstall';

	/**
	 * Other stuff
	 */
	private static $settings;
	private $luminate_api;
	private static $_instance = null;
	private $auth_token;
	private $use_sso_token  = false;
	private $constituent_id = null;
	private static $static_auth_token;

	/**
	 * GFLuminate constructor.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Initializing method used by Gravity Forms to initiate add-ons
	 */
	public function init() {
		parent::init();

		add_filter( 'gform_settings_save_button', array( $this, 'add_cornershop_info_below_save_button' ) );
		add_filter( 'gform_predefined_choices', array( $this, 'add_bulk_choices' ) );
		add_action( 'wp_http_luminate_request_failed_results', array( $this, 'log_failed_api_calls' ), 10, 3 );
		add_action( 'wp_http_luminate_request_success_results', array( $this, 'log_success_api_calls' ), 10, 3 );
		add_action( 'gform_enqueue_scripts', array( $this, 'enqueue_plugin_scripts' ), 10, 2 );

		Constituent::get_instance();
		Survey::get_instance();

		if ( class_exists( '\GF_Luminate\Donation' ) ) {
			Donation::get_instance();
		}

		if ( class_exists( '\GF_Luminate\BBCheckout' ) ) {
			BBCheckout::get_instance();
		}
	}

	/**
	 * Append a Cornershop info box below the Save button on the plugin settings screen.
	 */
	public function add_cornershop_info_below_save_button( $html ) {
		if ( rgget( 'subview' ) === $this->_slug ) {
			$html .= '<div class="gfluminate-cornershop-info">';
			$html .= '<a href="https://cornershopcreative.com/" class="cshp-logo-link" target="_blank" rel="nofollow noreferrer"><img src="' . esc_url( plugins_url( '../assets/images/cshp-logo.png', __FILE__ ) ) . '" width="160" height="66" alt="' . esc_attr__( 'Cornershop Creative logo', 'gfluminate' ) . '"></a>';
			$html .= '<p class="blurb">';
			$html .= sprintf(
				// translators: %1$s: cornershopcreative.com link; %2$s: hello@cornershopcreative.com link
				esc_html__( 'Cornershop Creative does a lot more than build great WordPress plugins like this one. Do you need help with a custom designed Luminate donation form, email marketing support, or supporting your WordPress website? Check out more at %1$s or contact us at %2$s.', 'gfluminate' ),
				'<a href="https://cornershopcreative.com/" target="_blank" rel="nofollow noreferrer">cornershopcreative.com</a>',
				'<a href="mailto:hello@cornershopcreative.com" target="_blank" rel="nofollow noreferrer">hello@cornershopcreative.com</a>',
			);
			$html .= '</p>';
			$html .= '</div><!-- .gfluminate-cornershop-info -->';
		}
		return $html;
	}

	/**
	 * Get the current version of the plugin
	 *
	 * @return string Current plugin version or the current timestamp if the constant does not exist
	 */
	public function get_version() {
		if ( empty( $this->_version ) ) {
			return time();
		}

		return $this->_version;
	}

	/**
	 * Verify that we have all of the Luminate API credentials needed to make the plugin work
	 *
	 * @param array $keys_to_check Luminate creds
	 * @return bool If we have all of the needed creds for this plugin
	 */
	public function have_luminate_creds( $keys_to_check ) {

		if ( ! is_array( $keys_to_check ) ) {
			return false;
		}

		// Check for the handful of values that must always be present.
		$needed_keys = array( 'luminate_servlet', 'luminate_api_key', 'luminate_api_user', 'luminate_api_pass' );
		foreach ( $needed_keys as $cred ) {
			if ( empty( $keys_to_check[ $cred ] ) ) {
				return false;
			}
		}

		// If we're NOT using a custom secure domain, then we need a 'luminate_organization' value too.
		if ( empty( $keys_to_check['luminate_custom_servlet'] ) && empty( $keys_to_check['luminate_organization'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Log the Luminate setting creds to the Gravity Forms debugging log
	 *
	 * @return void
	 */
	protected function log_luminate_creds() {
		$settings       = $this->get_plugin_settings();
		$servlet        = ! empty( $settings['luminate_servlet'] ) ? $settings['luminate_servlet'] : __( 'is empty', 'gfluminate' );
		$custom_servlet = isset( $settings['luminate_custom_servlet'] ) ? boolval( $settings['luminate_custom_servlet'] ) : __( 'is empty', 'gfluminate' );
		$api_key        = ! empty( $settings['luminate_api_key'] ) ? $settings['luminate_api_key'] : __( 'is empty', 'gfluminate' );
		$organization   = ! empty( $settings['luminate_organization'] ) ? $settings['luminate_organization'] : __( 'is empty', 'gfluminate' );
		$api_user       = ! empty( $settings['luminate_api_user'] ) ? $settings['luminate_api_user'] : __( 'is empty', 'gfluminate' );
		$api_password   = ! empty( $settings['luminate_api_pass'] ) ? $settings['luminate_api_pass'] : __( 'is empty', 'gfluminate' );

		$this->log_error(
			__METHOD__ . '(): ' . sprintf(
				' %s %s: %s, %s: %s, %s: %s, %s: %s, %s: %s. %s',
				__( 'Gravity Forms Luminate settings are currently: ', 'gfluminate' ),
				__( 'Servlet', 'gfluminate' ),
				$servlet,
				__( 'Custom Servlet', 'gfluminate' ),
				$custom_servlet,
				__( 'Custom Domain', 'gfluminate' ),
				$organization,
				__( 'API key', 'gfluminate' ),
				$api_key,
				__( 'API user', 'gfluminate' ),
				$api_user,
				__( 'API user password', 'gfluminate' ),
				$api_password,
				__( 'If the settings are not correct, go to the plugin Settings page and add the correct creds. If the creds still aren\'t working, verify that database write access is working', 'gfluminate', 'gfluminate' )
			)
		);
	}

	public function setConvioAPI() {
		$settings = $this->get_plugin_settings();

		// check if the creds are currently being updated in the Gravity Forms admin settings page and use the updated creds before they are saved so we can test to make sure these creds work
		$post_data = parent::get_posted_settings();

		// make sure our $_POSTed changes have all of the Luminate keys that we need before we overwrite them
		if ( ! empty( $post_data ) && $this->have_luminate_creds( $post_data ) ) {
			$settings = array();
			foreach ( $post_data as $key => $data ) {
				$settings[ $key ] = rgar( $post_data, $key );
			}
		}

		if ( true === $this->have_luminate_creds( $settings ) ) {
			$api       = WP_HTTP_Luminate::get_instance();
			$api->host = $settings['luminate_servlet'];
			if ( isset( $settings['luminate_custom_servlet'] ) && ! empty( $settings['luminate_custom_servlet'] ) ) {
				$api->is_custom_domain = boolval( $settings['luminate_custom_servlet'] );
			}
			$api->api_key        = $settings['luminate_api_key'];
			$api->short_name     = $settings['luminate_organization'];
			$api->login_name     = $settings['luminate_api_user'];
			$api->login_password = $settings['luminate_api_pass'];

			$this->luminate_api = $api;
		} else {
			throw new \Exception( __( 'Luminate creds are empty. You must set all luminate credentials: Luminate servlet, Luminate organization, API key, Luminate API user, and Luminate API user password', 'gfluminate' ) );
		}
	}

	public function getConvioAPI() {
		if ( ! $this->luminate_api instanceof WP_HTTP_Luminate ) {
			try {
				$this->setConvioAPI();
				return $this->luminate_api;
			} catch ( \Exception $e ) {
				$this->log_error( __METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage() );
				$this->log_luminate_creds();
				return null;
			}
		}

		return $this->luminate_api;
	}

	/**
	 * Enqueue scripts in the admin Dashboard
	 *
	 * Scripts needed to do conditional toggling for the feed settings
	 */
	public function scripts() {
		wp_register_script( 'hideshowpassword', plugins_url( '/assets/js/vendor/hideshowpassword/hideShowPassword.min.js', __DIR__ ), [ 'jquery' ], '2.1.1', true );

		$localized_data = [];

		if ( isset( $_GET['id'] ) && isset( $_GET['fid'] ) ) {
			$form_feeds = $this->get_my_feeds( absint( $_GET['id'] ) );
			$feed_id    = sanitize_text_field( $_GET['fid'] );
			if ( ! empty( $form_feeds ) && ! is_wp_error( $form_feeds ) ) {
				foreach ( $form_feeds as $feed ) {
					if ( $feed_id == $feed['id'] ) {
						$field_map = gf_luminate()->get_field_map_fields( $feed, 'donationMappedFields' );

						if ( ! empty( $field_map ) ) {
							$localized_data['donation'] = $field_map;
						}

						break;
					}
				}
			}
		}

		$scripts = [
			[
				'handle'    => 'gf_luminate',
				'src'       => gf_luminate_get_plugin_file_uri( '/assets/js/gravityforms-luminate-admin.js' ),
				'version'   => $this->get_version(),
				'deps'      => [ 'jquery', 'hideshowpassword' ],
				'in_footer' => true,
				'callback'  => '',
				'enqueue'   => [
					[
						'admin_page' => [ 'form_settings', 'plugin_settings', 'plugin_page' ],
						'tab'        => '',
					],
				],
				'strings'   => $localized_data,
			],
			[
				'handle'    => 'loaders',
				'src'       => gf_luminate_get_plugin_file_uri( '/assets/js/vendor/loaders.css/loaders.css.js' ),
				'version'   => $this->get_version(),
				'deps'      => [ 'jquery' ],
				'in_footer' => true,
				'callback'  => '',
				'enqueue'   => [
					[
						'admin_page' => [ 'form_settings' ],
						'tab'        => '',
					],
				],
			],
		];

		return array_merge( parent::scripts(), $scripts );
	}

	public function styles() {
		wp_register_style( 'hideshowpassword', plugins_url( '/assets/js/vendor/hideshowpassword/hideShowPassword.min.css', __DIR__ ), '', '2.1.1', 'all' );

		$styles = array(
			array(
				'handle'    => 'gravityforms-luminate',
				'src'       => gf_luminate_get_plugin_file_uri( '/assets/css/gravityforms-luminate.css' ),
				'version'   => $this->get_version(),
				'deps'      => [ 'hideshowpassword' ],
				'in_footer' => false,
				'callback'  => '',
				'enqueue'   => array(
					array(
						'admin_page' => array( 'form_settings', 'plugin_settings', 'plugin_page' ),
						'tab'        => '',
					),
				),
			),
			array(
				'handle'    => 'loaders',
				'src'       => gf_luminate_get_plugin_file_uri( '/assets/js/vendor/loaders.css/loaders.css' ),
				'version'   => $this->get_version(),
				'deps'      => '',
				'in_footer' => false,
				'callback'  => '',
				'enqueue'   => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => '',
					),
				),
			),
		);

		return array_merge( parent::styles(), $styles );
	}

	/**
	 * Load frontend scripts needed to make the donation processing work
	 *
	 * @param $form
	 * @param $is_ajax
	 */
	public function enqueue_plugin_scripts( $form, $is_ajax ) {
		wp_enqueue_style( 'gf-luminate-frontend', gf_luminate_get_plugin_file_uri( 'assets/css/gravityforms-luminate-frontend.css' ), [], $this->get_version() );
	}

	/**
	 * Get an instance of this class.
	 *
	 * @return GFLuminate
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new \GFLuminate();
		}

		return self::$_instance;
	}

	/**
	 * Clear the cached settings on uninstall.
	 *
	 * @return bool
	 */
	public function uninstall() {

		parent::uninstall();

		GFCache::delete( 'gforms_luminate_settings' );

		return true;
	}

	// ------- Plugin settings -------
	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$settings = $this->get_plugin_settings();

		if ( is_array( $settings ) ) {
			$is_valid_creds = $this->is_valid_luminate_auth();

			if ( ! is_bool( $is_valid_creds ) ) {
				printf( '<div class="notice notice-error luminate_api_message"><p>%s</p></div>', $is_valid_creds );
			} elseif ( $is_valid_creds === true ) {
				printf( '<div class="notice notice-success luminate_api_message"><p>%s</p></div>', __( 'Luminate API credentials are working! Create a Gravity Forms feed to get started.', 'gfluminate' ) );
			}
		}

		$description  = esc_html__( 'Use Gravity Forms to collect user information and add it to your Luminate constituents list, provided your Luminate account supports API calls.', 'gfluminate' ) . ' ';
		$description .= __( '<a href="http://open.convio.com/api/#main.site_configuration.html" rel="noopener" target="_blank">Follow the instructions here</a> to configure the Luminate API.', 'gfluminate' ) . '
 <br><br>';
		$description .= sprintf( '%s <strong>%s</strong>. %s.', __( 'You must whitelist your site\'s <strong>public</strong> IP address to use the API. Your site\'s IP address may be', 'gfluminate' ), $this->get_server_ip_address(), __( 'Make sure to enter in <strong>32</strong> as the netmask when you whitelist the IP address instead of 24, which is the default value that Luminate uses. If you are certain about your IP address netmask, then use that value instead of 32. If you continue to receive a error showing that the Luminate API is not working because of a IP address restriction, contact your web hosting company to get the IP address and the IP address netmask of your website', 'gfluminate' ) ) . ' <br><br>';
		$description .= sprintf( __( '<strong>Note</strong>: If you know your site\'s public IP address is <strong>NOT</strong> %s or you receive an error when trying to connect to the API, please enter in the correct IP address. The IP address listed above is the plugin\'s best guess based on tests.', 'gfluminate' ), $this->get_server_ip_address() ) . ' <br><br>';
		$description .= sprintf( __( '<strong>Also Note</strong>: If your site has a dynamic IP address and is hosted on a cloud service such as Pantheon or other services that do not allow you to point your DNS to a IP address, this plugin may not work since Luminate requires you to Whitelist the IP address API requests are coming from. This is a Luminate limitation that we are unable to do anything about. Please contact your web host and Luminate to resolve this issue. If you still want to use the plugin, you need to monitor your site\'s IP address and update the Luminate API settings in Luminate when your site\'s public IP address changes.', 'gfluminate' ) ) . '<br><br>';

		$description .= sprintf( __( '<strong>Another Note:</strong> If you want to process Luminate donations from your site, you must whitelist your WordPress domain in Luminate. Add <strong>%s</strong> to the list of domains allowed to execute AJAX and JavaScript in the Luminate API settings. <a href=""http://open.convio.com/api/#main.javascript_crossdomain_library.html" rel="noopener" target="_blank">Follow the instructions here</a> to learn how to add this domain to your Luminate site', 'gfluminate' ), wp_parse_url( site_url(), PHP_URL_HOST ) ) . '<br><br>';

		$description .= __( 'If you don\'t know your Luminate Domain, <a href="http://open.convio.com/api/#main.servlet" rel="noopener" target="_blank">follow the instructions here</a>. Enter the domain only as the Luminate domain instead of the full servlet.', 'gfluminate' ) . '<br><br>';
		$description .= __( 'When creating a API password, make sure your API passsword does <strong>not have</strong> any spaces or any of the following special characters (that are listed here and separated by commas) such as: ; (semicolon), / (forward slash), ?, : (colon), @, =, &, <,  >, #, %, {, }, |, \ (backslash), ^, ~, [, ], `, \' (single-quote), " (double-quote). This will cause the Gravity Forms mapping not to work correctly. Dashes, asterisks, and exclamation points are ok.', 'gfluminate' );

		if ( class_exists( '\GF_Luminate\BBCheckout' ) && ! empty( BBCheckout::get_instance()->getSKYAPI() ) && ( '1' === $settings['luminate_enable_donation_bb_checkout'] || true == $settings['luminate_enable_donation_bb_checkout'] ) ) {
			$description .= sprintf(
				'<br><br>%s <a href="https://developer.blackbaud.com/skyapi/apis/payments/checkout/integration-guide#prerequisites" target="_blank" rel="noopener">listed here</a> %s <a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_html__( 'In order to use Blackbaud Checkout to process donations, you must meet the prerequisites ', 'gfluminate' ),
				esc_html__( ' and authorize your Blackbaud SKY application. Follow ', 'gfluminate' ),
				esc_url( BBCheckout::get_instance()->getSKYAPI()->generate_oauth_url() ),
				esc_html__( 'this link to authorize the application' ) 
			);
		}

		$fields = [
			array(
				'name'        => 'luminate_servlet',
				'label'       => esc_html__( 'Luminate Domain', 'gfluminate' ),
				'placeholder' => __( 'secure.convio.net', 'gfluminate' ),
				'type'        => 'text',
				'class'       => 'large wide',
				'tooltip'     => esc_html__( 'Enter in your Luminate servlet value (find out more here http://open.convio.com/api/#main.servlet.html). Usually the domain where you login (e.g. https://secure2.convio.net or https://secure3.convio.net). Enter in the servlet domain without the https part. Just enter in the servlet like secure2.convio.net, secure3.convio.net, or whatever your servlet is. <strong>If you use a custom secure domain in Luminate</strong> such as secure.my-custom-domain.org, then use your custom domain without the https part. NOTE: Use the SECURE domain that starts with https:// and not the custom non-secure domain.' ),
			),
			array(
				'name'    => 'luminate_custom_servlet',
				'label'   => esc_html__( 'Luminate Custom Domain', 'gfluminate' ),
				'type'    => 'checkbox',
				'tooltip' => esc_html__( 'If you use a secure custom Luminate domain (e.g. NOT secure2.convio.net, secure3.convio.net, etc...), then select this field to use your custom secure domain for API connections.' ),
				'choices' => array(
					array(
						'label' => 'Yes, I use a custom Luminate domain',
						'name'  => 'luminate_custom_servlet',
						'text'  => 'Yes, I use a custom domain',
					),
				),
			),
			array(
				'name'        => 'luminate_organization',
				'label'       => esc_html__( 'Luminate Organization', 'gfluminate' ),
				'placeholder' => __( 'cshp', 'gfluminate' ),
				'type'        => 'text',
				'class'       => 'large wide',
				'tooltip'     => esc_html__( 'Enter in your Luminate organization value (example: if you log in to Luminate at https://secure3.convio.net/myorg, then myorg is the name of your Organization). If you use a custom secure domain, leave this field blank.' ),
			),
			array(
				'name'        => 'luminate_api_key',
				'label'       => esc_html__( 'Luminate API Key', 'gfluminate' ),
				'placeholder' => __( 'secret-luminate-api-key', 'gfluminate' ),
				'type'        => 'text',
				'class'       => 'large wide',
				'tooltip'     => esc_html__( 'Enter the Luminate API key. Learn more here http://open.convio.com/api/#main.site_configuration.html', 'gfluminate' ),
			),
			array(
				'name'        => 'luminate_api_user',
				'label'       => esc_html__( 'Luminate API Username', 'gfluminate' ),
				'placeholder' => __( 'luminate-api-username', 'gfluminate' ),
				'type'        => 'text',
				'class'       => 'large wide',
				'tooltip'     => esc_html__( 'Enter the Luminate API user. Learn more here http://open.convio.com/api/#main.site_configuration.html', 'gfluminate' ),
			),
			array(
				'name'        => 'luminate_api_pass',
				'label'       => esc_html__( 'Luminate API Password', 'gfluminate' ),
				'placeholder' => __( 'secret-luminate-api-password', 'gfluminate' ),
				'type'        => 'text',
				'input_type'  => 'password',
				'class'       => 'large password',
				'tooltip'     => esc_html__( 'Enter the password of the Luminate API user. IMPORTANT: This is not stored encrypted; make sure it\'s not too valuable', 'gfluminate' ),
			),
			array(
				'name'    => 'luminate_enable_group_mapping',
				'label'   => esc_html__( 'Enable Luminate Group Mapping', 'gfluminate' ),
				'type'    => 'checkbox',
				'tooltip' => esc_html__( 'Add constituents to Groups when they submit a Gravity Form. Warning: If you have a lot of groups, this could cause a slow down loading a feed when the groups are first fetched from the Luminate API.' ),
				'choices' => [
					[
						'label' => 'Yes, Enable Group Mapping',
						'name'  => 'luminate_enable_group_mapping',
						'text'  => 'Yes, Enable Group Mapping',
					],
				],
			),
			array(
				'name'        => 'luminate_api_clear_cache',
				'label'       => 'Luminate API Cache',
				'type'        => 'luminate_api_clear_cache_button',
				'tooltip'     => esc_html__( 'If you need to refresh the list of Luminate groups or constituent custom fields, use this. Otherwise, the constituent field list may be cached for up to a week, and the group list for up to a month.', 'gfluminate' ),
			),
			array(
				'name'        => 'cornershop_info',
				'label'       => '',
				'type'        => 'luminate_cornershop_info',
			),
		];

		$fields = apply_filters( 'gf_luminate_settings_fields', $fields );

		return array(
			array(
				'title'       => '',
				'description' => '<p>' . $description . '</p>',
				'fields'      => $fields,
			),
		);
	}

	/**
	 * Custom Setting Field to display clear cache button.
	 */
	public function settings_luminate_api_clear_cache_button() {
		?>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=gf_settings&subview=gravityforms-luminate' ), 'luminate_api_clearing_cache', 'luminate_api_clear_cache' ) ); ?>"
		class="button"><?php esc_html_e( 'Clear Cache', 'gfluminate' ); ?></a>
		<?php
	}

	/**
	 * Configures the settings which should be rendered on the feed edit page.
	 *
	 * @return array The feed settings.
	 */
	public function feed_settings_fields() {
		$this->auth_token = $this->get_sso_auth_token();

		$description = '<p>' . esc_html__( 'Use Gravity Forms to collect user information and add it to your Luminate constituents list or Luminate surveys, provided your Luminate account supports API calls', 'gfluminate' ) . '</p>';

		$fields = [
			array(
				'name'     => 'feedName',
				'label'    => esc_html__( 'Name', 'gfluminate' ),
				'type'     => 'text',
				'required' => true,
				'class'    => 'medium',
				'tooltip'  => '<h6>' . esc_html__( 'Name', 'gfluminate' ) . '</h6>' . esc_html__( 'Enter a feed name to uniquely identify this setup.', 'gfluminate' ),
			),
			array(
				'name'     => 'mappingType',
				'label'    => esc_html__( 'Mapping Types', 'gfluminate' ),
				'type'     => 'checkbox',
				'required' => false,
				'tooltip'  => '<h6>' . esc_html__( 'Mapping Types', 'gfluminate' ) . '</h6>' . esc_html__( 'Select the kind of mapping that this feed maps to: Constituent or Survey' ),
				'choices'  => $this->luminate_mapping_types(),
			),

		];

		$fields = apply_filters( 'gf_luminate_feed_fields', $fields );

		// Add the group mapping and the save feed button last
		if ( is_array( $fields ) && ! empty( $fields ) ) {
			$fields = array_merge(
				$fields,
				[
					array(
						'name'                => 'groups',
						'label'               => esc_html__( 'Groups', 'gfluminate' ),
						'type'                => 'checkbox',
						'tooltip'             => '<h6>' . esc_html__( 'Groups', 'gfluminate' ) . '</h6>' . esc_html__( 'Enter in the Group IDs that you would like users assigned to in Luminate. Optional.', 'gfluminate' ),
						'choices'             => $this->luminate_group_choices(),
						'required'            => false,
						'validation_callback' => function() {
							// Always validate this as being true since there are a LOT of Luminate Groups
							// without this, saving Luminate groups will fail when there is more than 1000 inputs being  POSTed to PHP
							return true;
						},
						'dependency'          => function() {
							return $this->is_group_enabled();
						},
					),
					array(
						'name'     => 'optinCondition',
						'label'    => esc_html__( 'Conditional Logic', 'gfluminate' ),
						'type'     => 'feed_condition',
						'tooltip'  => '<h6>' . esc_html__( 'Conditional Logic', 'gfluminate' ) . '</h6>' . esc_html__( 'When conditional logic is enabled, form submissions will only be passed to Luminate when the conditions are met. When disabled all form submissions will be exported.', 'gfluminate' ),
						'required' => false,
					),
					array(
						'type' => 'save',
					),
				] 
			);
		}

		return array(
			array(
				'title'       => esc_html__( 'Luminate Feed Settings', 'gfluminate' ),
				'description' => $description,
				'fields'      => $fields,
			),
		);
	}


	// # FEED PROCESSING -----------------------------------------------------------------------------------------------
	/**
	 * Process the feed, add the submission to Luminate.
	 *
	 * @param array $feed  The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form  The form object currently being processed.
	 *
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {
		$this->log_debug( __METHOD__ . '(): Processing feed ID ' . $feed['id'] );
		$this->auth_token = $this->get_sso_auth_token();

		$auth_token = $this->auth_token;

		// Make sure we can actually connect to the Luminate API before attempting to process any feeds
		$are_creds_valid = $this->is_valid_luminate_auth();
		if ( true === $are_creds_valid ) {
			$entry = apply_filters( 'gform_luminate_preprocess_entry', $entry );

			Constituent::get_instance()->process_luminate_constituent( $feed, $entry, $form, $auth_token );

			// both of these API calls requires that we be able to get an authorization token
			if ( ! empty( $auth_token ) ) {
				Survey::get_instance()->process_luminate_survey( $feed, $entry, $form, $auth_token, $this->is_sso_token() );
			}
		} else {
			$this->log_error( __METHOD__ . '(): Unable to process feed ID ' . $feed['id'] . '. The current error is ' . $are_creds_valid );
		}
	}

	/**
	 * Returns the value of the selected field.
	 *
	 * @param array  $form      The form object currently being processed.
	 * @param array  $entry     The entry object currently being processed.
	 * @param string $field_id The ID of the field being processed.
	 *
	 * @return array
	 */
	public function get_field_value( $form, $entry, $field_id, $die = false ) {
		$field_value = '';

		switch ( strtolower( $field_id ) ) {

			case 'form_title':
				$field_value = rgar( $form, 'title' );
				break;

			case 'date_created':
				$date_created = rgar( $entry, strtolower( $field_id ) );
				if ( empty( $date_created ) ) {
					// the date created may not yet be populated if this function is called during the validation phase and the entry is not yet created
					$field_value = gmdate( 'Y-m-d H:i:s' );
				} else {
					$field_value = $date_created;
				}
				break;

			case 'ip':
			case 'source_url':
				$field_value = rgar( $entry, strtolower( $field_id ) );
				break;

			default:
				$field = GFFormsModel::get_field( $form, $field_id );

				if ( is_object( $field ) ) {
					$field_type = $field->type;
					$is_integer = $field_id === intval( $field_id );
					$input_type = RGFormsModel::get_input_type( $field );

					if ( $is_integer && 'address' === $input_type ) {

						$field_value = $this->get_full_address( $entry, $field_id );

					} elseif ( $is_integer && 'name' === $input_type ) {

						$field_value = $this->get_full_name( $entry, $field_id );

					} elseif ( $is_integer && 'checkbox' === $input_type ) {

						$selected = array();
						foreach ( $field->inputs as $input ) {
							$index = (string) $input['id'];
							if ( ! rgempty( $index, $entry ) ) {
								$selected[] = rgar( $entry, $index );
							}
						}
						$field_value = implode( '|', $selected );

					} elseif ( 'phone' === $input_type && 'standard' === $field->phoneFormat ) {

						// reformat standard format phone to match preferred Luminate format
						// format: NPA-NXX-LINE (404-555-1212) when US/CAN
						$field_value = rgar( $entry, $field_id );
						if ( ! empty( $field_value ) && preg_match( '/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $field_value, $matches ) ) {
							$field_value = sprintf( '%s-%s-%s', $matches[1], $matches[2], $matches[3] );
						}
					} elseif ( 'product' === $field_type && is_callable( array( 'GFCommon', 'get_calculation_value' ) ) ) {
						$field_value = GFCommon::get_calculation_value( $field_id, $form, $entry );
					} else {
						if ( is_callable( array( 'GF_Field', 'get_value_export' ) ) ) {
							$field_value = $field->get_value_export( $entry, $field_id );
						} else {
							$field_value = rgar( $entry, $field_id );
						}
					}//end if
				} else {
					$field_value = rgar( $entry, $field_id );

				}//end if
		}//end switch

		return $field_value;
	}



	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------
	// ------- Feed list page -------
	/**
	 * Should prevent feeds being listed or created if the API credentials are invalid or the user is unable to connect to the Luminate API for some reason
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		$are_creds_valid = $this->is_valid_luminate_auth();

		$return_value = false;

		$current_form = $this->get_current_form();

		// If the Luminate creds work, then this should return a true as a boolean
		if ( is_bool( $are_creds_valid ) ) {
			$return_value = $are_creds_valid;
		}

		// If the user has already created a feed for this form, then show the feed(s) just in case something happens when trying to connect to the Luminate API after they up the feeds
		if ( ! empty( $current_form ) && $this->has_feed( $current_form['id'] ) ) {
			$return_value = true;
		}

		return $return_value;
	}

	/**
	 * If the api key is invalid or empty return the appropriate message.
	 *
	 * @return string
	 */
	public function configure_addon_message() {

		$settings_label = sprintf( esc_html__( '%s Settings', 'gravityforms' ), $this->get_short_title() );
		$settings_link  = sprintf( '<a href="%s">%s</a>', esc_url( $this->get_plugin_settings_url() ), $settings_label );

		$settings = $this->get_plugin_settings();

		if ( false === $this->have_luminate_creds( $settings ) ) {
			return sprintf( esc_html__( 'To get started, please configure your %s.', 'gravityforms' ), $settings_link );
		} elseif ( true !== $this->is_valid_luminate_auth() ) {
			return $this->is_valid_luminate_auth();
		}

		return sprintf( esc_html__( 'Unable to connect to Luminate with the provided credentials. Please make sure you have entered valid information on the %s page.', 'gfluminate' ), $settings_link );

	}

	/**
	 * Display a warning message instead of the feeds if the API key isn't valid.
	 *
	 * @param array   $form The form currently being edited.
	 * @param integer $feed_id The current feed ID.
	 */
	public function feed_edit_page( $form, $feed_id ) {

		$is_valid_luminate_auth = $this->is_valid_luminate_auth();

		if ( $is_valid_luminate_auth !== true ) {

			echo '<h3><span>' . $this->feed_settings_title() . '</span></h3>';
			echo '<div>' . $is_valid_luminate_auth . '</div>';

			return;
		}

		parent::feed_edit_page( $form, $feed_id );
	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'          => esc_html__( 'Name', 'gfluminate' ),
			'luminate_mappings' => esc_html__( 'Luminate Mappings' ),
		);
	}

	/**
	 * Output a list of the Luminate mappings this feed pushes into
	 */
	public function get_column_value_luminate_mappings( $item ) {
		$mappings = array();
		$types    = $this->luminate_mapping_types();

		foreach ( $types as $type ) {

			if ( isset( $item['meta'][ $type['id'] ] ) && ! empty( $item['meta'][ $type['id'] ] ) ) {
				$mappings[] = $type['label'];
			}
		}

		return implode( ', ', $mappings );
	}

	/**
	 * Add bulk choices for Gravityforms dropdowns whose data is valid values in Luminate
	 *
	 * Provide data that users can import into dropdowns when creating dropdowns. These values are valid Luminate API values.
	 *
	 * @param array $current_bulk_choices Current Gravityforms bulk choices
	 *
	 * @return array Current bulk choices along with the new bulk choices from Luminate
	 */
	public function add_bulk_choices( $current_bulk_choices ) {
		$constituent_fields = Constituent::get_instance()->get_constituent_edit_fields();
		$special_fields     = array( 'State/Province' );

		if ( ! empty( $constituent_fields ) ) {
			foreach ( $constituent_fields as $field ) {
				if ( isset( $field->choices ) && isset( $field->choices->choice ) ) {
					$name = 'Luminate';

					if ( isset( $field->subGroup ) ) {
						$name .= ' - ' . $field->subGroup . ': ' . $field->label;
					} else {
						$name .= ' - ' . $field->label;
					}

					// make the value for state the state two letter code but make the label the full state name
					if ( in_array( $field->label, $special_fields ) ) {

						$field->choices->choice == array();
						foreach ( $field->choices->choice as &$state ) {
							$abbr = $this->state_abbr( $state, true );

							// check to see if maybe the state is a canadian province
							if ( $abbr == $state ) {
								$abbr = $this->canadian_provinces( $state, true );
							}

							// if the state code is neither a state nor a canadian province, let's just use the code instead
							if ( $abbr == $state ) {
								$state = $abbr;
							}

							$state = $abbr . '|' . $state;
						}
					}

					$current_bulk_choices[ $name ] = $field->choices->choice;
				}//end if
			}//end foreach
		}//end if

		return $current_bulk_choices;
	}

	/**
	 * Generate a auth token for the API user
	 *
	 * Create a Luminate single sign-on token for the API user. We use this token to retrieve and update information for constituents.
	 *
	 * @param bool $return_all_data
	 *
	 * @return mixed
	 */
	public function get_auth_token( $return_all_data = false, $temp_creds = false ) {

		// Pull values from globals if present
		if ( ! $temp_creds && isset( $GLOBALS['gfluminate_auth_token_all'] ) && $return_all_data ) {
			return $GLOBALS['gfluminate_auth_token_all'];
		}

		if ( ! $temp_creds && isset( $GLOBALS['gfluminate_auth_token'] ) && ! $return_all_data ) {
			return $GLOBALS['gfluminate_auth_token'];
		}

		if ( is_object( $this->getConvioAPI() ) ) {
			try {

				if ( is_array( $temp_creds ) ) {
					$params = $temp_creds;
				} else {
					$params = array(
						'user_name' => $this->getConvioAPI()->login_name,
						'password'  => $this->getConvioAPI()->login_password,
					);
				}

				$token = $this->getConvioAPI()->call( 'SRConsAPI_login', $params );

				// Check for errors
				if ( WP_HTTP_Luminate::is_api_error( $token ) || ( isset( $token->loginResponse ) && ( $token->loginResponse->token == 'null' || is_null( $token->loginResponse->token ) ) ) || empty( $token ) ) {
					$this->log_debug( __METHOD__ . '(): Well that did not go well, token is ' . wp_json_encode( $token ) );
					throw new \Exception( wp_json_encode( $token ), 403 );
				}

				$this->log_debug( __METHOD__ . '(): Login Auth Token Received. We\'re ready to send information to Luminate. Luminate data is: ' . print_r( $token, true ) );

				if ( $return_all_data === true ) {
					$GLOBALS['gfluminate_auth_token_all'] = $token->loginResponse;
					return $token->loginResponse;
				} else {
					$GLOBALS['gfluminate_auth_token'] = $token->loginResponse->token;
					return $token->loginResponse->token;
				}
			} catch ( \Exception $e ) {

				$this->log_error( __METHOD__ . '(): Error getting a Luminate single sign-on token: ' . $e->getCode() . ' - ' . $e->getMessage() );

				if ( $return_all_data == true ) {
					return json_decode( $e->getMessage() );
				}
			}//end try
		}//end if

		return null;
	}

	/**
	 * Generate a single sign-on token for the API user.
	 *
	 * Create a Luminate single sign-on token for the API user. We use this token to retrieve and update information for constituents.
	 *
	 * @param string $cons_id Constituent ID of the constituent that we're generating an SSO token for
	 * @return mixed
	 */
	public function get_sso_auth_token( $cons_id = '' ) {

		// Use globals to persist token across instantiation of object, unless we want to generate a sso token for a different constituent
		if ( isset( $GLOBALS['gfluminate_sso_auth_token'] ) && empty( $cons_id ) ) {
			$this->log_debug( __METHOD__ . '(): Using saved Single Sign-On Auth Token' );
			$this->use_sso_token = true;
			return $GLOBALS['gfluminate_sso_auth_token'];
		}

		if ( empty( $cons_id ) ) {
			$cons_id = $this->get_luminate_cons_id();
		}

		try {
			$params = array(
				'cons_id' => $cons_id,
			);

			$this->log_debug( __METHOD__ . '(): Calling - getting a Luminate single sign-on of the Luminate user' );

			$token = $this->getConvioAPI()->call( 'SRConsAPI_getSingleSignOnToken', $params );

			// Check for errors
			if ( WP_HTTP_Luminate::is_api_error( $token ) || 'null' === $token->getSingleSignOnTokenResponse->token || is_null( $token->getSingleSignOnTokenResponse->token ) ) {
				throw new \Exception( wp_json_encode( $token ), 403 );
			}

			$this->log_debug( __METHOD__ . '(): Login Single Sign-On Auth Token Received. We\'re ready to begin. Luminate data is: ' . print_r( $token, true ) );

			$this->use_sso_token = true;

			$token = $this->getConvioAPI()->call( 'SRConsAPI_getSingleSignOnToken', $params );

			// Store the token in a global so it persists across instances
			$GLOBALS['gfluminate_sso_auth_token'] = $token->getSingleSignOnTokenResponse->token;

			if ( empty( self::$static_auth_token ) ) {
				self::$static_auth_token = $token->getSingleSignOnTokenResponse->token;
			}

			return $token->getSingleSignOnTokenResponse->token;

		} catch ( \Exception $e ) {
			$this->log_error( __METHOD__ . '(): Error getting a Luminate single sign-on token' . $e->getCode() . ' - ' . $e->getMessage() );
		}//end try
	}

	/**
	 * Get the Constituent ID associated with our API user or with the user that we just fetched a AUTH token for
	 *
	 * @return int|null The constituent's ID if a auth token was generated or a null if no auth token was generated
	 */
	public function get_luminate_cons_id() {
		// we can get the constituent id by signing in the user using their username and password
		$auth_data = $this->get_auth_token( true );

		if ( isset( $auth_data->cons_id ) ) {
			return $auth_data->cons_id;
		}

		return;
	}

	/**
	 * Determine if the Luminate auth token is a singe sign-on token
	 *
	 * @return bool True if a single sign-on token is being used. False otherwise
	 */
	public function is_sso_token() {
		return $this->use_sso_token;
	}

	/**
	 * Define the markup for the Luminate groups type field.
	 *
	 * @return string|void
	 */
	public function luminate_group_choices() {
		if ( ! $this->is_group_enabled() ) {
			return;
		}

		$groups  = $this->get_luminate_groups();
		$choices = array();

		$max_count = 10000;
		foreach ( $groups as $index => $group ) {
			if ( $index >= $max_count ) {
				continue;
			}

			$label = sprintf( '%s (ID: %s)', $group['label'], $group['value'] );

			$choices[] = array(
				'label' => $label,
				'name'  => $group['value'],
			);
		}

		/*
		$choices[] = array(
			'label' => 'Thing 1',
			'name'  => 'thing',
		);*/

		return $choices;

	}

	/**
	 * Define which field types can be used for the group conditional logic.
	 * Probably NO LONGER NECESSARY
	 *
	 * @return array
	 */
	public function get_conditional_logic_fields() {
		$form   = $this->get_current_form();
		$fields = array();
		foreach ( $form['fields'] as $field ) {
			if ( $field->is_conditional_logic_supported() ) {
				$fields[] = array(
					'value' => $field->id,
					'label' => GFCommon::get_label( $field ),
				);
			}
		}

		return $fields;
	}


	// # HELPERS -------------------------------------------------------------------------------------------------------
	/**
	 * Checks to make sure the Luminate credentials stored in settings actually work!
	 *
	 * @return bool|string True if the Luminate API credentials are valid.Error message if invalid API credentials
	 */
	public function is_valid_luminate_auth( $temp_login = false ) {
		$login_api_user = $this->get_auth_token( true, $temp_login );
		if ( ! empty( $login_api_user ) && isset( $login_api_user->cons_id ) ) {
			return true;
		} elseif ( WP_HTTP_Luminate::is_api_error( $login_api_user ) && $login_api_user->errorResponse->code == '4' ) {
			// Luminate API IP whitelisting error. The user needs to make sure their IP address is whitelisted for the API connections
			$ip = $this->get_server_ip_address();
			return sprintf(
				'%s "<strong>%s</strong>". %s %s %s. %s. %s',
				__( '<strong>Unable connect to the Luminate API</strong> because of IP restrictions. Verify that the site\'s IP address is whitelisted in Luminate. Luminate is reporting this error which contains the IP address that Luminate is seeing:', 'gfluminate' ),
				$login_api_user->errorResponse->message,
				__( 'If this IP address', 'gfluminate' ),
				$ip,
				__( 'and the one that Luminate is reporting are not the same, then add the correct IP address to the Luminate API Whitelist', 'gfluminate' ),
				__( 'Add the correct IP address that Luminate is reporting, which is', 'gfluminate' ),
				__(
					'<a href="http://open.convio.com/api/#main.site_configuration.html" rel="noopener" target="_blank">Follow the
instructions here</a>. If you are unsure what your site\'s public IP address is, contact your web hosting company to get the IP address of your website.',
					'gfluminate'
				)
			);
		} elseif ( WP_HTTP_Luminate::is_api_error( $login_api_user ) && $login_api_user->errorResponse->code == '3' ) {
			return __( '<strong>Unable to connect to the Luminate API</strong> because the Luminate credentials are incorrect. Verify that the API Username, Password, and API key are correct.', 'gfluminate' );
		} elseif ( WP_HTTP_Luminate::is_api_error( $login_api_user ) && $login_api_user->errorResponse->code == '2' ) {
			return __( '<strong>Unable to connect to the Luminate API</strong> because the Luminate API key is incorrect. Verify that the API Username, Password, and API key are correct.', 'gfluminate' );
		} elseif ( WP_HTTP_Luminate::is_api_error( $login_api_user ) && $login_api_user->errorResponse->code == '1' ) {
			return __( '<strong>Unable to authenticate with Luminate!</strong> Login to your Luminate dashboard and enable debugging. <a href="http://open.convio.com/api/#main.logging_api_calls.html" rel="noopener" target="_blank">Follow the instructions here</a>. If you need help debugging your API connection, please enable Gravity Forms logging and contact the plugin author Cornershop Creative by adding a comment to the plugin support forums on the official WordPress plugin directory at <a href="http://open.convio.com/api/#main.logging_api_calls.html" rel="noopener" target="_blank">https://wordpress.org/support/plugin/integration-for-luminate-and-gravity-forms/</a>, Luminate support or the Blackbaud help forums.', 'gfluminate' );
		} else {
			return __( '<strong>Unable to authenticate with Luminate!</strong> Please check that all provided credentials are valid. You may wish to  <a href="http://open.convio.com/api/#main.logging_api_calls.html" rel="noopener" target="_blank">login to your Luminate dashboard and enable debugging</a>. If you need help connecting to the API, please contact Luminate support or the Blackbaud help forums.', 'gfluminate' );
		}//end if

		return false;
	}

	/**
	 * Retrieve the Luminate groups for this account.
	 *
	 * @return array|bool
	 */
	private function get_luminate_groups() {

		$luminate_groups_transient = sprintf( '%s_luminate_groups', $this->_slug );
		$groups                    = [];

		$check_groups_cache = get_transient( $luminate_groups_transient );

		if ( ! empty( $check_groups_cache ) ) {
			return $check_groups_cache;
		}

		try {
			$this->log_debug( __METHOD__ . '(): Retrieving groups from API' );
			$list_page_offset = 0;
			$params           = array(
				'selection_mode'   => 'MEMBERSHIP',
				'list_page_offset' => &$list_page_offset,
			);

			do {
				$get_groups = $this->getConvioAPI()->call( 'SRGroupAPI_listGroups', $params );

				if ( ! isset( $get_groups->listGroupsResponse->groupInfo ) ) {
					break;
				}

				$this->log_debug( __METHOD__ . '(): Successfully retrieved groups from Luminate API => ' . print_r( $get_groups, true ) );

				if ( ! is_array( $get_groups->listGroupsResponse->groupInfo ) ) {
					$get_groups->listGroupsResponse->groupInfo = array( $get_groups->listGroupsResponse->groupInfo );
				}

				foreach ( $get_groups->listGroupsResponse->groupInfo as $group ) {
					if ( 'DYNAMIC_REBUILDABLE' === $group->groupMode ) {
						continue;
					}

					$groups[] = array(
						'label' => $group->name,
						'name'  => $group->id,
						'value' => $group->id,
					);
				}

				$number_results = count( $get_groups->listGroupsResponse->groupInfo );

				$list_page_offset++;

			} while ( $number_results >= 25 );

		} catch ( \Exception $e ) {
			$this->log_error( __METHOD__ . '(): Error getting groups. Error =>' . $e->getCode() . ' - ' . $e->getMessage() );
			$groups = [];
		}//end try

		if ( WP_HTTP_Luminate::is_api_error( $get_groups ) ) {
			$this->log_error( __METHOD__ . '(): Error getting groups. Error => ' . print_r( $get_groups, true ) );
		}

		if ( ! empty( $groups ) ) {
			// make the cache last for a month since pulling up all of the groups can be incredibly slow
			// if there are a lot of groups
			set_transient( $luminate_groups_transient, $groups, WEEK_IN_SECONDS * 4 );
		}

		return $groups;
	}

	/**
	 * Select the mapping type for the feed
	 *
	 * Feeds can either push data to a constituent record or create a survey record in Luminate
	 *
	 * @return array List of mapping types
	 */
	private function luminate_mapping_types() {
		$luminate_mapping_types = [
			[
				'id'    => 'constituent',
				'name'  => 'constituent',
				'label' => 'Constituent',
			],
			[
				'id'    => 'survey',
				'name'  => 'survey',
				'label' => 'Survey',
			],
		];

		$luminate_mapping_types = apply_filters( 'gf_luminate_mapping_types', $luminate_mapping_types );

		if ( $this->is_group_enabled() ) {
			$luminate_mapping_types[] = [
				'id'    => 'group',
				'name'  => 'group',
				'label' => 'Group',
			];
		}

		return $luminate_mapping_types;
	}

	/**
	 * Is the Groups feature enabled in the plugin settings
	 *
	 * @return bool
	 */
	public function is_group_enabled() {
		$settings = $this->get_plugin_settings();
		return isset( $settings['luminate_enable_group_mapping'] ) && boolval( $settings['luminate_enable_group_mapping'] );
	}

	/**
	 * Returns the combined value of the specified Address field.
	 * Street 2 and Country are the only inputs not required by MailChimp.
	 * If other inputs are missing MailChimp will not store the field value, we will pass a hyphen when an input is empty.
	 * MailChimp requires the inputs be delimited by 2 spaces.
	 *
	 * @param array  $entry The entry currently being processed.
	 * @param string $field_id The ID of the field to retrieve the value for.
	 *
	 * @return string
	 */
	public function get_full_address( $entry, $field_id ) {
		$street_value  = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.1' ) ) );
		$street2_value = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.2' ) ) );
		$city_value    = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.3' ) ) );
		$state_value   = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.4' ) ) );
		$zip_value     = trim( rgar( $entry, $field_id . '.5' ) );
		$country_value = trim( rgar( $entry, $field_id . '.6' ) );

		if ( ! empty( $country_value ) ) {
			$country_value = GF_Fields::get( 'address' )->get_country_code( $country_value );
		}

		$address = array(
			! empty( $street_value ) ? $street_value : '-',
			$street2_value,
			! empty( $city_value ) ? $city_value : '-',
			! empty( $state_value ) ? $state_value : '-',
			! empty( $zip_value ) ? $zip_value : '-',
			$country_value,
		);

		return implode( '  ', $address );
	}

	static function is_valid_email( $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	public function remove_non_numeric( $string ) {
		return preg_replace( '/[^0-9]/', '', $string );
	}

	public function remove_non_numeric_period( $string ) {
		return preg_replace( '/[^0-9.]/', '', $string );
	}

	/**
	 * Wrapper for state_abbr() that falls back on canadian_provinces() if state_abbr() doesn't find a
	 * US state abbreviation, and returns an empty string if no valid abbreviation could be found.
	 */
	public function international_state_abbr( $state_name, $reverse = false ) {
		$abbr = $this->state_abbr( $state_name, $reverse );
		if ( $state_name === $abbr ) {
			$abbr = $this->canadian_provinces( $state_name, $reverse );
		}
		$abbr = trim( strtoupper( $abbr ) );
		// Just in case a user typed in 'ny ', make it 'NY'.
		if ( ! $this->is_valid_state_province( $abbr ) ) {
			return '';
		}
		return $abbr;
	}

	public function state_abbr( $state_name, $reverse = false ) {
		$state_name_upper = strtoupper( $state_name );

		$us_state_abbrevs_names = array(
			'AL' => 'ALABAMA',
			'AK' => 'ALASKA',
			'AS' => 'AMERICAN SAMOA',
			'AZ' => 'ARIZONA',
			'AR' => 'ARKANSAS',
			'CA' => 'CALIFORNIA',
			'CO' => 'COLORADO',
			'CT' => 'CONNECTICUT',
			'DE' => 'DELAWARE',
			'DC' => 'DISTRICT OF COLUMBIA',
			'FM' => 'FEDERATED STATES OF MICRONESIA',
			'FL' => 'FLORIDA',
			'GA' => 'GEORGIA',
			'GU' => 'GUAM GU',
			'HI' => 'HAWAII',
			'ID' => 'IDAHO',
			'IL' => 'ILLINOIS',
			'IN' => 'INDIANA',
			'IA' => 'IOWA',
			'KS' => 'KANSAS',
			'KY' => 'KENTUCKY',
			'LA' => 'LOUISIANA',
			'ME' => 'MAINE',
			'MH' => 'MARSHALL ISLANDS',
			'MD' => 'MARYLAND',
			'MA' => 'MASSACHUSETTS',
			'MI' => 'MICHIGAN',
			'MN' => 'MINNESOTA',
			'MS' => 'MISSISSIPPI',
			'MO' => 'MISSOURI',
			'MT' => 'MONTANA',
			'NE' => 'NEBRASKA',
			'NV' => 'NEVADA',
			'NH' => 'NEW HAMPSHIRE',
			'NJ' => 'NEW JERSEY',
			'NM' => 'NEW MEXICO',
			'NY' => 'NEW YORK',
			'NC' => 'NORTH CAROLINA',
			'ND' => 'NORTH DAKOTA',
			'MP' => 'NORTHERN MARIANA ISLANDS',
			'OH' => 'OHIO',
			'OK' => 'OKLAHOMA',
			'OR' => 'OREGON',
			'PW' => 'PALAU',
			'PA' => 'PENNSYLVANIA',
			'PR' => 'PUERTO RICO',
			'RI' => 'RHODE ISLAND',
			'SC' => 'SOUTH CAROLINA',
			'SD' => 'SOUTH DAKOTA',
			'TN' => 'TENNESSEE',
			'TX' => 'TEXAS',
			'UT' => 'UTAH',
			'VT' => 'VERMONT',
			'VI' => 'VIRGIN ISLANDS',
			'VA' => 'VIRGINIA',
			'WA' => 'WASHINGTON',
			'WV' => 'WEST VIRGINIA',
			'WI' => 'WISCONSIN',
			'WY' => 'WYOMING',
			'AE' => 'ARMED FORCES AFRICA \ CANADA \ EUROPE \ MIDDLE EAST',
			'AA' => 'ARMED FORCES AMERICA (EXCEPT CANADA)',
			'AP' => 'ARMED FORCES PACIFIC',
		);

		if ( $reverse === true ) {

			if ( $state_name == 'DC' ) {
				$state_name = 'District of Columbia';
			} elseif ( isset( $us_state_abbrevs_names[ $state_name_upper ] ) ) {
				$state_name = ucwords( strtolower( $us_state_abbrevs_names[ $state_name_upper ] ) );
			}

			return $state_name;
		} else {

			$found_state = array_search( $state_name_upper, $us_state_abbrevs_names );

			if ( empty( $found_state ) ) {
				$found_state = $state_name;
			}

			return $found_state;
		}
	}

	public function canadian_provinces( $province_name, $reverse = false ) {

		$province_name_upper = strtoupper( $province_name );

		$canadian_states = array(
			'BC' => 'BRITISH COLUMBIA',
			'ON' => 'ONTARIO',
			'NL' => 'NEWFOUNDLAND AND LABRADOR',
			'NS' => 'NOVA SCOTIA',
			'PE' => 'PRINCE EDWARD ISLAND',
			'NB' => 'NEW BRUNSWICK',
			'QC' => 'QUEBEC',
			'MB' => 'MANITOBA',
			'SK' => 'SASKATCHEWAN',
			'AB' => 'ALBERTA',
			'NT' => 'NORTHWEST TERRITORIES',
			'NU' => 'NUNAVUT',
			'YT' => 'YUKON TERRITORY',
		);

		if ( $reverse === true ) {

			if ( isset( $canadian_states[ $province_name_upper ] ) ) {
				$province_name = ucwords( strtolower( $canadian_states[ $province_name_upper ] ) );
			}

			return $province_name;

		} else {
			$found_province = array_search( $province_name_upper, $canadian_states );

			if ( empty( $found_province ) ) {
				$found_province = $province_name;
			}

			return $found_province;
		}
	}

	/**
	 * Check whether a given string is a valid two-letter state/province abbreviation, per
	 * http://old-open.pub30.convio.net/webservices/apidoc/Convio_Web_Services_API_Reference.pdf
	 * (see API Reference -> Types -> Address). The TeamRaiser API will reject registration requests
	 * whose contact or billing state value(s) aren't among these.
	 */
	public function is_valid_state_province( $abbr ) {
		$valid_state_province_abbrs = array(
			'AK',
			'AL',
			'AR',
			'AZ',
			'CA',
			'CO',
			'CT',
			'DC',
			'DE',
			'FL',
			'GA',
			'HI',
			'IA',
			'ID',
			'IL',
			'IN',
			'KS',
			'KY',
			'LA',
			'MA',
			'MD',
			'ME',
			'MI',
			'MN',
			'MO',
			'MS',
			'MT',
			'NC',
			'ND',
			'NE',
			'NH',
			'NJ',
			'NM',
			'NV',
			'NY',
			'OH',
			'OK',
			'OR',
			'PA',
			'RI',
			'SC',
			'SD',
			'TN',
			'TX',
			'UT',
			'VA',
			'VT',
			'WA',
			'WI',
			'WV',
			'WY',
			'AS',
			'FM',
			'GU',
			'MH',
			'MP',
			'PR',
			'PW',
			'VI',
			'AA',
			'AE',
			'AP',
			'AB',
			'BC',
			'MB',
			'NB',
			'NL',
			'NS',
			'NT',
			'NU',
			'ON',
			'PE',
			'QC',
			'SK',
			'YT',
		);
		return in_array( $abbr, $valid_state_province_abbrs, true );
	}

	public function country_codes( $country_name, $reverse = false ) {

		$country_name_upper = strtoupper( $country_name );

		$countries = array(
			'AF' => 'AFGHANISTAN',
			'AX' => 'ALAND ISLANDS',
			'AL' => 'ALBANIA',
			'DZ' => 'ALGERIA',
			'AS' => 'AMERICAN SAMOA',
			'AD' => 'ANDORRA',
			'AO' => 'ANGOLA',
			'AI' => 'ANGUILLA',
			'AQ' => 'ANTARCTICA',
			'AG' => 'ANTIGUA AND BARBUDA',
			'AR' => 'ARGENTINA',
			'AM' => 'ARMENIA',
			'AW' => 'ARUBA',
			'AU' => 'AUSTRALIA',
			'AT' => 'AUSTRIA',
			'AZ' => 'AZERBAIJAN',
			'BS' => 'BAHAMAS',
			'BH' => 'BAHRAIN',
			'BD' => 'BANGLADESH',
			'BB' => 'BARBADOS',
			'BY' => 'BELARUS',
			'BE' => 'BELGIUM',
			'BZ' => 'BELIZE',
			'BJ' => 'BENIN',
			'BM' => 'BERMUDA',
			'BT' => 'BHUTAN',
			'BO' => 'BOLIVIA',
			'BA' => 'BOSNIA AND HERZEGOVINA',
			'BW' => 'BOTSWANA',
			'BV' => 'BOUVET ISLAND',
			'BR' => 'BRAZIL',
			'IO' => 'BRITISH INDIAN OCEAN TERRITORY',
			'BN' => 'BRUNEI DARUSSALAM',
			'BG' => 'BULGARIA',
			'BF' => 'BURKINA FASO',
			'BI' => 'BURUNDI',
			'KH' => 'CAMBODIA',
			'CM' => 'CAMEROON',
			'CA' => 'CANADA',
			'CV' => 'CAPE VERDE',
			'KY' => 'CAYMAN ISLANDS',
			'CF' => 'CENTRAL AFRICAN REPUBLIC',
			'TD' => 'CHAD',
			'CL' => 'CHILE',
			'CN' => 'CHINA',
			'CX' => 'CHRISTMAS ISLAND',
			'CC' => 'COCOS (KEELING) ISLANDS',
			'CO' => 'COLOMBIA',
			'KM' => 'COMOROS',
			'CG' => 'CONGO',
			'CD' => 'CONGO, DEMOCRATIC REPUBLIC',
			'CK' => 'COOK ISLANDS',
			'CR' => 'COSTA RICA',
			'CI' => 'COTE D\'IVOIRE',
			'HR' => 'CROATIA',
			'CU' => 'CUBA',
			'CY' => 'CYPRUS',
			'CZ' => 'CZECH REPUBLIC',
			'DK' => 'DENMARK',
			'DJ' => 'DJIBOUTI',
			'DM' => 'DOMINICA',
			'DO' => 'DOMINICAN REPUBLIC',
			'EC' => 'ECUADOR',
			'EG' => 'EGYPT',
			'SV' => 'EL SALVADOR',
			'GQ' => 'EQUATORIAL GUINEA',
			'ER' => 'ERITREA',
			'EE' => 'ESTONIA',
			'ET' => 'ETHIOPIA',
			'FK' => 'FALKLAND ISLANDS (MALVINAS)',
			'FO' => 'FAROE ISLANDS',
			'FJ' => 'FIJI',
			'FI' => 'FINLAND',
			'FR' => 'FRANCE',
			'GF' => 'FRENCH GUIANA',
			'PF' => 'FRENCH POLYNESIA',
			'TF' => 'FRENCH SOUTHERN TERRITORIES',
			'GA' => 'GABON',
			'GM' => 'GAMBIA',
			'GE' => 'GEORGIA',
			'DE' => 'GERMANY',
			'GH' => 'GHANA',
			'GI' => 'GIBRALTAR',
			'GR' => 'GREECE',
			'GL' => 'GREENLAND',
			'GD' => 'GRENADA',
			'GP' => 'GUADELOUPE',
			'GU' => 'GUAM',
			'GT' => 'GUATEMALA',
			'GG' => 'GUERNSEY',
			'GN' => 'GUINEA',
			'GW' => 'GUINEA-BISSAU',
			'GY' => 'GUYANA',
			'HT' => 'HAITI',
			'HM' => 'HEARD ISLAND & MCDONALD ISLANDS',
			'VA' => 'HOLY SEE (VATICAN CITY STATE)',
			'HN' => 'HONDURAS',
			'HK' => 'HONG KONG',
			'HU' => 'HUNGARY',
			'IS' => 'ICELAND',
			'IN' => 'INDIA',
			'ID' => 'INDONESIA',
			'IR' => 'IRAN, ISLAMIC REPUBLIC OF',
			'IQ' => 'IRAQ',
			'IE' => 'IRELAND',
			'IM' => 'ISLE OF MAN',
			'IL' => 'ISRAEL',
			'IT' => 'ITALY',
			'JM' => 'JAMAICA',
			'JP' => 'JAPAN',
			'JE' => 'JERSEY',
			'JO' => 'JORDAN',
			'KZ' => 'KAZAKHSTAN',
			'KE' => 'KENYA',
			'KI' => 'KIRIBATI',
			'KR' => 'KOREA',
			'KW' => 'KUWAIT',
			'KG' => 'KYRGYZSTAN',
			'LA' => 'LAO PEOPLE\'S DEMOCRATIC REPUBLIC',
			'LV' => 'LATVIA',
			'LB' => 'LEBANON',
			'LS' => 'LESOTHO',
			'LR' => 'LIBERIA',
			'LY' => 'LIBYAN ARAB JAMAHIRIYA',
			'LI' => 'LIECHTENSTEIN',
			'LT' => 'LITHUANIA',
			'LU' => 'LUXEMBOURG',
			'MO' => 'MACAO',
			'MK' => 'MACEDONIA',
			'MG' => 'MADAGASCAR',
			'MW' => 'MALAWI',
			'MY' => 'MALAYSIA',
			'MV' => 'MALDIVES',
			'ML' => 'MALI',
			'MT' => 'MALTA',
			'MH' => 'MARSHALL ISLANDS',
			'MQ' => 'MARTINIQUE',
			'MR' => 'MAURITANIA',
			'MU' => 'MAURITIUS',
			'YT' => 'MAYOTTE',
			'MX' => 'MEXICO',
			'FM' => 'MICRONESIA, FEDERATED STATES OF',
			'MD' => 'MOLDOVA',
			'MC' => 'MONACO',
			'MN' => 'MONGOLIA',
			'ME' => 'MONTENEGRO',
			'MS' => 'MONTSERRAT',
			'MA' => 'MOROCCO',
			'MZ' => 'MOZAMBIQUE',
			'MM' => 'MYANMAR',
			'NA' => 'NAMIBIA',
			'NR' => 'NAURU',
			'NP' => 'NEPAL',
			'NL' => 'NETHERLANDS',
			'AN' => 'NETHERLANDS ANTILLES',
			'NC' => 'NEW CALEDONIA',
			'NZ' => 'NEW ZEALAND',
			'NI' => 'NICARAGUA',
			'NE' => 'NIGER',
			'NG' => 'NIGERIA',
			'NU' => 'NIUE',
			'NF' => 'NORFOLK ISLAND',
			'MP' => 'NORTHERN MARIANA ISLANDS',
			'NO' => 'NORWAY',
			'OM' => 'OMAN',
			'PK' => 'PAKISTAN',
			'PW' => 'PALAU',
			'PS' => 'PALESTINIAN TERRITORY, OCCUPIED',
			'PA' => 'PANAMA',
			'PG' => 'PAPUA NEW GUINEA',
			'PY' => 'PARAGUAY',
			'PE' => 'PERU',
			'PH' => 'PHILIPPINES',
			'PN' => 'PITCAIRN',
			'PL' => 'POLAND',
			'PT' => 'PORTUGAL',
			'PR' => 'PUERTO RICO',
			'QA' => 'QATAR',
			'RE' => 'REUNION',
			'RO' => 'ROMANIA',
			'RU' => 'RUSSIAN FEDERATION',
			'RW' => 'RWANDA',
			'BL' => 'SAINT BARTHELEMY',
			'SH' => 'SAINT HELENA',
			'KN' => 'SAINT KITTS AND NEVIS',
			'LC' => 'SAINT LUCIA',
			'MF' => 'SAINT MARTIN',
			'PM' => 'SAINT PIERRE AND MIQUELON',
			'VC' => 'SAINT VINCENT AND GRENADINES',
			'WS' => 'SAMOA',
			'SM' => 'SAN MARINO',
			'ST' => 'SAO TOME AND PRINCIPE',
			'SA' => 'SAUDI ARABIA',
			'SN' => 'SENEGAL',
			'RS' => 'SERBIA',
			'SC' => 'SEYCHELLES',
			'SL' => 'SIERRA LEONE',
			'SG' => 'SINGAPORE',
			'SK' => 'SLOVAKIA',
			'SI' => 'SLOVENIA',
			'SB' => 'SOLOMON ISLANDS',
			'SO' => 'SOMALIA',
			'ZA' => 'SOUTH AFRICA',
			'GS' => 'SOUTH GEORGIA AND SANDWICH ISL.',
			'ES' => 'SPAIN',
			'LK' => 'SRI LANKA',
			'SD' => 'SUDAN',
			'SR' => 'SURINAME',
			'SJ' => 'SVALBARD AND JAN MAYEN',
			'SZ' => 'SWAZILAND',
			'SE' => 'SWEDEN',
			'CH' => 'SWITZERLAND',
			'SY' => 'SYRIAN ARAB REPUBLIC',
			'TW' => 'TAIWAN',
			'TJ' => 'TAJIKISTAN',
			'TZ' => 'TANZANIA',
			'TH' => 'THAILAND',
			'TL' => 'TIMOR-LESTE',
			'TG' => 'TOGO',
			'TK' => 'TOKELAU',
			'TO' => 'TONGA',
			'TT' => 'TRINIDAD AND TOBAGO',
			'TN' => 'TUNISIA',
			'TR' => 'TURKEY',
			'TM' => 'TURKMENISTAN',
			'TC' => 'TURKS AND CAICOS ISLANDS',
			'TV' => 'TUVALU',
			'UG' => 'UGANDA',
			'UA' => 'UKRAINE',
			'AE' => 'UNITED ARAB EMIRATES',
			'GB' => 'UNITED KINGDOM',
			'US' => 'UNITED STATES',
			'UM' => 'UNITED STATES OUTLYING ISLANDS',
			'UY' => 'URUGUAY',
			'UZ' => 'UZBEKISTAN',
			'VU' => 'VANUATU',
			'VE' => 'VENEZUELA',
			'VN' => 'VIET NAM',
			'VG' => 'VIRGIN ISLANDS, BRITISH',
			'VI' => 'VIRGIN ISLANDS, U.S.',
			'WF' => 'WALLIS AND FUTUNA',
			'EH' => 'WESTERN SAHARA',
			'YE' => 'YEMEN',
			'ZM' => 'ZAMBIA',
			'ZW' => 'ZIMBABWE',
		);

		if ( $reverse === true ) {

			if ( isset( $countries[ $country_name_upper ] ) ) {
				$country_name = ucwords( strtolower( $countries[ $country_name_upper ] ) );
			}

			return $country_name;

		} else {

			$found_country = array_search( $country_name_upper, $countries );

			if ( empty( $found_country ) ) {
				$found_country = $country_name;
			}

			return $found_country;
		}
	}


	/**
	 * Get the saved constituent id for this transaction
	 *
	 * @return string Saved Luminate constituent id for this process
	 */
	public function get_constituent_id() {

		// get the global constituent id that was saved earlier
		if ( empty( $this->constituent_id ) && ! empty( $GLOBALS['gfluminate_constituent_id'] ) ) {
			return $GLOBALS['gfluminate_constituent_id'];
		}

		return $this->constituent_id;
	}

	/**
	 * Save the constituent id for this transaction
	 *
	 * @param string $constituent_id A Luminate constituent id that all feeds will map to. Any feed will update this particular constituent's information
	 *
	 * @return string The Luminate constituent id that was just saved
	 */
	public function set_constituent_id( $constituent_id ) {
		$this->constituent_id = $constituent_id;

		// save the constituent id as a global object since Gravity forms creates a new feed object for each type of feed mapped to a form, so we have to get the constituent id multiple times or try to have Luminate get the constituent id based on the submitted information
		$GLOBALS['gfluminate_constituent_id'] = $constituent_id;

		return $this->get_constituent_id();
	}

	/**
	 * Get the site's public ip address
	 *
	 * @return string
	 */
	public function get_server_ip_address() {
		$host = gethostname();
		$ip   = gethostbyname( $host );

		$ping_ip  = '';
		$response = wp_remote_get( 'http://ipecho.net/plain' );
		if ( is_array( $response ) ) {
			$ping_ip = $ip = $response['body'];
		}

		// try to get the public IP address of this site by having the site ping itself
		if ( extension_loaded( 'curl' ) && empty( $ping_ip ) ) {
			$ping_site_publicly = curl_init();
			curl_setopt( $ping_site_publicly, CURLOPT_URL, home_url( '/' ) );
			$ping_output = curl_setopt( $ping_site_publicly, CURLOPT_RETURNTRANSFER, true );
			curl_exec( $ping_site_publicly );
			$get_public_ip = curl_getinfo( $ping_site_publicly, CURLINFO_PRIMARY_IP );
			curl_close( $ping_site_publicly );

			// If the site's public IP address and PHP's best guess about the IP address are not the same, then use the public IP address
			if ( $ip != $get_public_ip ) {
				$ip = $get_public_ip;
			}
		}

		return $ip;
	}

	/**
	 * Log the failed API calls that are sent to the Luminate API.
	 *
	 * Useful for API troubleshooting
	 *
	 * @param string           $url API endpoint that was called
	 * @param array            $request_data Array of data sent to the API
	 * @param WP_HTTP_Response $wp_http_response WP Http Response object
	 */
	public function log_failed_api_calls( $url, $request_data, $wp_http_response ) {
		$data = array_merge(
			[
				'url' => $url,
			],
			$request_data
		);

		if ( isset( $data['body'] ) && ! empty( $data['body'] ) && ( is_array( $data['body'] ) || is_object( $data['body'] ) ) ) {
			$data['body'] = http_build_query( $data['body'] );
		}

		if ( isset( $data['cookies'] ) && ! empty( $data['cookies'] ) ) {
			$data['cookies'] = WP_Http::normalize_cookies( $data['cookies'] );
		}

		$this->log_error( __METHOD__ . '(): Raw data sent to Luminate API call ' . print_r( $data, true ) );

		$received_data                    = [];
		$received_data['response_status'] = wp_remote_retrieve_response_code( $wp_http_response );
		$received_data['response_body']   = wp_remote_retrieve_body( $wp_http_response );

		$this->log_error( __METHOD__ . '(): Raw data received from Luminate API call ' . print_r( $received_data, true ) );
	}

	/**
	 * Log the successful API calls that are sent to the Luminate API.
	 *
	 * Useful for API troubleshooting
	 *
	 * @param string           $url API endpoint that was called
	 * @param array            $request_data Array of data sent to the API
	 * @param WP_HTTP_Response $wp_http_response WP Http Response object
	 */
	public function log_success_api_calls( $url, $request_data, $wp_http_response ) {
		$data = array_merge(
			[
				'url' => $url,
			],
			$request_data
		);

		if ( isset( $data['body'] ) && ! empty( $data['body'] ) && ( is_array( $data['body'] ) || is_object( $data['body'] ) ) ) {
			$data['body'] = http_build_query( $data['body'] );
		}

		if ( isset( $data['cookies'] ) && ! empty( $data['cookies'] ) ) {
			$data['cookies'] = WP_Http::normalize_cookies( $data['cookies'] );
		}

		$this->log_debug( __METHOD__ . '(): Raw data sent to Luminate API call ' . print_r( $data, true ) );

		$received_data                    = [];
		$received_data['response_status'] = wp_remote_retrieve_response_code( $wp_http_response );
		$received_data['response_body']   = wp_remote_retrieve_body( $wp_http_response );

		$this->log_debug( __METHOD__ . '(): Raw data received from Luminate API call ' . print_r( $received_data, true ) );
	}

	/**
	 * Get all of the Luminate feeds attached to a form.
	 *
	 * @param $form_id
	 *
	 * @return array|WP_Error
	 */
	public function get_my_feeds( $form_id ) {
		$feeds = GFAPI::get_feeds( null, $form_id, $this->_slug, true );

		if ( empty( $feeds ) || is_wp_error( $feeds ) ) {
			return;
		}

		return $feeds;
	}

	/**
	 * Transform the Luminate field names to a format that Gravity Forms can understand and that we can reverse engineer.
	 *
	 * For some strange reason, Gravity Forms is removing the period(.) from our mapped field names and replacing them
	 * with an underscore. This causes the fields not to be updated. We need to look for the field names and replace them
	 * with the correct one when we sync back to Luminate
	 *
	 * @param $luminate_field_name
	 *
	 * @return string Gravity Forms friendly field name
	 */
	public function add_friendly_field_name( $luminate_field_name ) {
		return str_replace( '.', '_gfl_', $luminate_field_name );
	}

	/**
	 * Transform the Luminate field names back to the original Luminate field name with periods added in the right place.
	 *
	 * For some strange reason, Gravity Forms is removing the period(.) from our mapped field names and replacing them
	 * with an underscore. This causes the fields not to be updated. We need to look for the field names and replace them
	 * with the correct one when we sync back to Luminate
	 *
	 * @param $luminate_field_name
	 *
	 * @return string Original Luminate form field
	 */
	public function remove_friendly_field_name( $luminate_field_name ) {
		return str_replace( '_gfl_', '.', $luminate_field_name );
	}

	/**
	 * List common Luminate API fields we can map to these fields in the Gravity Forms UI
	 */
	function common_api_fields() {
		return [
			[
				'name'  => 'source',
				'label' => __( 'Source', 'gfluminate' ),
			],
			[
				'name'  => 'sub_source',
				'label' => __( 'Sub-source', 'gfluminate' ),
			],
		];
	}

	/**
	 * Setup for payment capturing using either the Luminate donation ednpoint or Blackbaud Checkout endpoint
	 *
	 * @param array $feed
	 * @param array $submission_data
	 * @param array $form
	 * @param array $entry
	 *
	 * @return array
	 */
	public function authorize( $feed, $submission_data, $form, $entry ) {
		if ( class_exists( '\GF_Luminate\Donation' ) && Donation::get_instance()->is_donation_feed( $feed ) ) {
			return Donation::get_instance()->authorize( $feed, $submission_data, $form, $entry );
		} elseif ( class_exists( '\GF_Luminate\BBCheckout' ) && BBCheckout::get_instance()->is_bb_checkout_feed( $feed ) ) {
			return BBCheckout::get_instance()->authorize( $feed, $submission_data, $form, $entry );
		}
	}

	/**
	 * Setup payment capturing using either the Luminate donation endpoint or the Blackbaud Checkout endpoint
	 *
	 * @param array $authorization
	 * @param array $feed
	 * @param array $submission_data
	 * @param array $form
	 * @param array $entry
	 *
	 * @return array
	 */
	public function capture( $authorization, $feed, $submission_data, $form, $entry ) {
		if ( class_exists( '\GF_Luminate\Donation' ) && Donation::get_instance()->is_donation_feed( $feed ) ) {
			return Donation::get_instance()->capture( $authorization, $feed, $submission_data, $form, $entry );
		} elseif ( class_exists( '\GF_Luminate\BBCheckout' ) && BBCheckout::get_instance()->is_bb_checkout_feed( $feed ) ) {
			return BBCheckout::get_instance()->capture( $authorization, $feed, $submission_data, $form, $entry );
		}
	}

	/**
	 * Normalize some Luminate return data to convert things that could be an array into an array
	 *
	 * Luminate's API returns single objects instead for some returned properties and arrays of those properties at other times
	 * depending on if you are trying to get all objects matching some API call but only one exists or if there is only
	 * one error object sometimes if there's only one error but an array of errors if there are multiple errors.
	 *
	 * @param object $luminate_return JSON decoded Luminate return
	 * @param string $property Property of the object to normalize
	 * @param bool $deep_recursive Look for the property name throughout the entire returned object and recursively traverse to update
	 */
	public function normalize_luminate_api_return( $luminate_return, $property, $deep_recursive = true ) {
		if ( ! is_object( $luminate_return ) && ! is_array( $luminate_return ) ) {
			return $luminate_return;
		}

		$normalized_return = $luminate_return;
		foreach ( $normalized_return as $prop => &$value ) {
			if ( $property === $prop && ! is_array( $value ) ) {
				$value = array( $value );
			} elseif ( true === $deep_recursive && $property !== $prop && ( is_array( $value ) || ( is_object( $value ) && isset( $value->{$property} ) ) ) ) {
				// loop through entire object to look for the property
				$value = $this->normalize_luminate_api_return( $value, $property );
			}
		}

		return $normalized_return;
	}
}
