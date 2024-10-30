<?php
namespace GF_Luminate;

/**
 * Make Luminate API calls using the WordPress WP_HTTP library.
 *
 * Drop-In replacement for the older ConvioOpenAPI call library that used cURL and the stream API.
 * This replacement allows for better cookie handling, error reporting, maintaining Cookies across requests, adding the
 * correct routes to the non-server side API calls.
 */
class WP_HTTP_Luminate {
	public $cookies = [];

	public $version = '1.0';

	public $host;

	public $short_name;

	public $api_key;

	public $is_custom_domain;

	public $login_name;

	public $login_password;

	/**
	 * Servlet where the Luminate site is hosted
	 *
	 * @var
	 */
	public $servlet;

	public $sso_routing_id;

	public $sso_nonce;

	public $sso_js_session_id;

	public $sso_auth_token;

	public $auth_routing_id;

	public $auth_nonce;

	public $auth_js_session_id;

	public $auth_token;

	/**
	 * Keep a static reference to this class so we can maintain cookies across requests
	 *
	 * @var null
	 */
	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return WP_HTTP_Luminate
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			$instance        = get_class();
			self::$_instance = new $instance();
		}

		return self::$_instance;
	}

	/**
	 * Get the URL to the Luminate API for this Luminate instance
	 *
	 * @return string API to the Luminate instance
	 */
	public function get_api_url() {
		if ( $this->is_custom_domain ) {
			return sprintf( 'https://%s/site', $this->get_host() );
		}

		return sprintf( 'https://%s/%s/site', $this->get_host(), $this->short_name );
	}

	/**
	 * Get the Luminate host  used to communicate with the Luminate site
	 *
	 * @return mixed
	 */
	public function get_host() {
		return $this->host = self::format_host( $this->host ); //phpcs:ignore
	}

	/**
	 * Format the host of the Luminate domain in the correct format that we expect it in
	 *
	 * @param string $host Luminate host to format
	 */
	public static function format_host( $host ) {
		// If the host contains any '/' indicating that it contains string that is not just a domain
		// or subdomain, then convert this host to the format that we expect to prevent API errors
		if ( false !== strpos( $host, '/' ) ) {
			$url = wp_parse_url( $host );

			if ( false !== $url && isset( $url['host'] ) ) {
				$host = $url['host'];
			}
		}

		// If the host contains any '/' indicating that it contains string that is not just a domain
		// or subdomain, then convert this host to the format that we expect to prevent API errors
		// Remove non-alphanumeric characters except for dashes
		$host = preg_replace( '/[^\w\.-]/', '', $host );

		return $host;
	}

	/**
	 * Override the old ConvioOpenAPI class.
	 *
	 * @param $servlet_method
	 * @param array         $params
	 * @param string        $response_format
	 * @param string        $request_type
	 *
	 * @return mixed|string
	 */
	public function call( $servlet_method, $params = array(), $response_format = 'json', $request_type = 'POST' ) {
		list( $servlet, $method ) = explode( '_', $servlet_method );
		$api_params               = $params;

		$data = [
			'method' => $method,
		];

		if ( is_string( $params ) ) {
			parse_str( $params, $api_params );
		}

		foreach ( $api_params as $key => $param ) {
			$data[ $key ] = $param;
		}

		$data['response_format'] = $response_format;
		$use_sso_token           = false;

		if ( isset( $data['sso_auth_token'] ) ) {
			$use_sso_token = true;
		}

		return $this->request( $servlet, $data, $request_type, $use_sso_token );
	}

	/**
	 * Make a call to the Luminate API
	 *
	 * @param $servlet
	 * @param $data
	 * @param string  $http_method
	 * @param bool    $use_sso_token
	 *
	 * @return array|mixed|string|WP_Error
	 */
	public function request( $servlet, $data, $http_method = 'GET', $use_sso_token = false, $force_token = false ) {
		// determine if this is a Client API call or a Server API call
		$api_type = strtoupper( substr( $servlet, 0, 2 ) );
		$api_data = $data;

		if ( is_string( $data ) ) {
			parse_str( $data, $api_data );
		}

		$url = sprintf( '%s/%s', $this->get_api_url(), $servlet );

		// Set the creds for a single sign-on request using a single sign-on token
		if ( 'CR' === $api_type ) {
			// remove the auth token from calls that don't require a API token
			// sometimes having a auth token in calls that don't need a auth token throws errors with Luminate API
			if ( self::is_method_no_auth( $api_data['method'] ) && true !== $force_token ) {
				unset( $api_data['auth'] );
				unset( $api_data['sso_auth_token'] );
			} elseif ( true === $use_sso_token ) {
				unset( $api_data['auth'] );

				if ( ! isset( $api_data['sso_auth_token'] ) ) {
					$api_data['sso_auth_token'] = $this->sso_auth_token;
				}

				// set the routing ID to send the request to the correct load balancer
				if ( ! empty( $this->sso_routing_id ) ) {
					$url = sprintf( '%s;jsessionid=%s', $url, $this->sso_routing_id );
				}

				if ( ! empty( $this->sso_js_session_id ) ) {
					$api_data['JSESSIONID'] = $this->sso_js_session_id;
				}

				if ( ! empty( $this->sso_nonce ) ) {
					$api_data['nonce'] = $this->sso_nonce;
				}
			} else {
				unset( $api_data['sso_auth_token'] );

				if ( ! isset( $api_data['auth'] ) ) {
					$api_data['auth'] = $this->auth_token;
				}

				// set the routing ID to send the request to the correct load balancer
				if ( ! empty( $this->auth_routing_id ) ) {
					$url = sprintf( '%s;jsessionid=%s', $url, $this->auth_routing_id );
				}

				if ( ! empty( $this->auth_js_session_id ) ) {
					$api_data['JSESSIONID'] = $this->auth_js_session_id;
				}

				if ( ! empty( $this->auth_nonce ) ) {
					$api_data['nonce'] = $this->auth_nonce;
				}
			}//end if
		} elseif ( 'SR' === $api_type ) {
			// Pass the API users credentials if making a server request
			// remove auth tokens from server side calls
			unset( $api_data['auth'] );
			unset( $api_data['sso_auth_token'] );

			if ( ! isset( $api_data['login_name'] ) ) {
				$api_data['login_name'] = $this->login_name;
			}

			if ( ! isset( $api_data['login_password'] ) ) {
				$api_data['login_password'] = $this->login_password;
			}
		}//end if

		if ( ! isset( $api_data['api_key'] ) ) {
			$api_data['api_key'] = $this->api_key;
		}

		if ( ! isset( $api_data['v'] ) ) {
			$api_data['v'] = $this->version;
		}

		// set the response format to JSON unless the user specifically wants XML
		if ( ! isset( $api_data['response_format'] ) ) {
			$api_data['response_format'] = 'json';
		}

		$request_data = [
			'method'  => $http_method,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
			],
		];

		$request_data['body'] = $api_data;

		// remove body data from GET requests and append as URL query params
		if ( 'GET' === $http_method ) {
			$url = add_query_arg( $api_data, $url );
			unset( $request_data['body'] );
			$request_data['headers']['Content-Type'] = 'text/plain; charset=UTF-8';
		}

		// phpcs:ignore
		if ( ! in_array( $api_data['method'], [ 'getSingleSignOnToken', 'login', 'getLoginUrl' ] ) && ! empty( $this->cookies ) ) {
			$request_data['cookies'] = $this->cookies;
		}

		/**
		 * Data that will be sent to the API before making the call
		 */
		do_action( 'wp_http_luminate_request_pre_args', $url, $request_data );

		if ( 'GET' !== $http_method ) {
			$request_data['body'] = http_build_query( $api_data );
		}

		$request  = wp_remote_request( $url, $request_data );
		$raw_body = wp_remote_retrieve_body( $request );
		$body     = self::maybe_json_or_xml( $raw_body, $api_data['response_format'] );

		// Log the cookies when we make a request to get the login tokens
		if ( ! is_wp_error( $request ) && ! self::is_api_error( $body ) ) {
			// phpcs:ignore
			if ( in_array( $api_data['method'], [ 'getSingleSignOnToken', 'login', 'getLoginUrl' ] ) ) {
				$this->set_cookies( $request['cookies'] );

				if ( 'getSingleSignOnToken' === $api_data['method'] ) {
					$this->set_sso_params( $body );
				} else {
					$this->set_auth_params( $body );
				}
			}

			/**
			 * Data returned from API that resulted in a error
			 */
			do_action( 'wp_http_luminate_request_success_results', $url, $request_data, $request );

			return $body;
		} elseif ( self::is_api_error( $body ) ) {
			/**
			 * Data returned from API that resulted in a error
			 */
			do_action( 'wp_http_luminate_request_failed_results', $url, $request_data, $request );

			return $body;
		}

		return $request;
	}

	public function set_cookies( $cookies ) {
		return $this->cookies = array_merge( $this->cookies, $cookies ); //phpcs:ignore
	}

	public function set_sso_params( $body ) {
		// phpcs:disable
		if ( isset( $body->getSingleSignOnTokenResponse->token ) ) {
			$this->sso_auth_token = $body->getSingleSignOnTokenResponse->token;
		}

		if ( isset( $body->getSingleSignOnTokenResponse->JSESSIONID ) ) {
			$this->sso_js_session_id = $body->getSingleSignOnTokenResponse->JSESSIONID;
		}

		if ( isset( $body->getSingleSignOnTokenResponse->nonce ) ) {
			$this->sso_nonce = $body->getSingleSignOnTokenResponse->nonce;
		}

		if ( isset( $body->getSingleSignOnTokenResponse->routing_id ) ) {
			$this->sso_routing_id = $body->getSingleSignOnTokenResponse->routing_id;
		}

		// phpcs:enable
	}

	public function set_auth_params( $body ) {
		// phpcs:disable
		if ( isset( $body->loginResponse->token ) ) {
			$this->auth_token = $body->loginResponse->token;
		}

		if ( isset( $body->loginResponse->JSESSIONID ) ) {
			$this->auth_js_session_id = $body->loginResponse->JSESSIONID;
		}

		if ( isset( $body->loginResponse->nonce ) ) {
			$this->auth_nonce = $body->loginResponse->nonce;
		}

		if ( isset( $body->loginResponse->routing_id ) ) {
			$this->auth_routing_id = $body->loginResponse->routing_id;
		}

		// phpcs:enable
	}

	/**
	 * Check if a Luminate request results in a generic, non-helpful error message
	 *
	 * Sometimes the Luminate API will report the error {"errorResponse":{"code":"1","message":"Unable to process request."}} . This is a non-helpful error message that we need to watch out for. The troubleshooting steps are to check the Luminate API log in the Admin user's account and turn on the API log to debugging mode to troubleshoot the error (which has never helped me resolve an API issue by the way).
	 *
	 * @param $error_message object Luminate API error message as a JSON object
	 */
	public static function is_generic_error( $error_message ) {
		// phpcs:disable
		if ( isset( $error_message->errorResponse ) && $error_message->errorResponse->code == '1' ) {
			return true;
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Check if a Luminate request results in a specific error message that we may be able to troubleshoot
	 *
	 * Test to see if a Luminate API error is a specific error that we may be able to troubleshoot. It may be one of the errors listed here http://open.convio.com/api/#main.error_codes.html
	 *
	 * @param $error_message object Luminate API error message as a JSON object
	 */
	public static function is_specific_error( $error_message ) {
		// phpcs:disable
		if ( isset( $error_message->errorResponse ) && $error_message->errorResponse->code !== '1' ) {
			return true;
		}

		// phpcs:enable

		return false;

	}

	/**
	 * Determine if an API call resulted in an error.
	 *
	 * @param $error_message object Luminate API error message as a JSON object
	 * @return bool
	 */
	public static function is_api_error( $error_message ) {
		// phpcs:disable
		if ( is_object( $error_message ) && isset( $error_message->errorResponse ) ) {
			return true;
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Determine if a HTTP response returned from the Luminate API resulted in an error when submitting a survey
	 *
	 * @param object $error_message Luminate API message as a stdClass object
	 *
	 * @return bool
	 */
	public static function is_survey_api_error( $error_message ) {
		// phpcs:disable
		if ( is_object( $error_message ) && isset( $error_message->submitSurveyResponse ) && ( 'false' === $error_message->submitSurveyResponse->success || false === $error_message->submitSurveyResponse->success ) ) {
			return true;
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Keep a list of methods that don't require authentication.
	 *
	 * Including authentication tokens with these methods can cause the API requests to fail
	 */
	public static function get_methods_no_auth() {
		return [
			'listSurveys',
			'startDonation',
			'getDonationFormInfo',
			'donate',
			'getDesignationTypes',
			'getDesignees',
			'addOfflineDonation',
			'offlineOrganizationGift',
			'recordRecurringTransaction',
			'refundOfflineDonation',
			'refundTransaction',
			'addLocalCompany',
			'addTeamraiserData',
			'createAndLinkFacebookFundraiser',
			'getCampaignByNameData',
			'getCaptainsMessage',
			'getCompaniesByInfo',
			'getCompanyList',
			'getCompanyPageInfo',
			'getCompanyPhoto',
			'getEventDataParameter',
			'getFundraisingResults',
			'getLocalCompany',
			'getNationalCompany',
			'getOrganizationMessage',
			'getParticipantCenterWrapper',
			'getParticipantFBConnectInfo',
			'getParticipantProgress',
			'getParticipants',
			'getParticipationType',
			'getParticipationTypes',
		];
	}

	/**
	 * Check if a method requires no auth token
	 *
	 * Remove the auth token from a API call if that method does not require a auth token
	 *
	 * @param $method_name
	 *
	 * @return bool
	 */
	public static function is_method_no_auth( $method_name ) {
		return false !== array_search( $method_name, self::get_methods_no_auth() ) ? true : false; // phpcs:ignore
	}

	/**
	 * Convert the Luminate API response to either a PHP object that corresponds to a JSON object or a PHP SimpleXMLElement class instance
	 *
	 * @param string $response Luminate API response
	 * @param string $response_format json or xml
	 *
	 * @return false|mixed|\SimpleXMLElement|string|null
	 */
	public static function maybe_json_or_xml( $response, $response_format = 'json' ) {
		if ( 'xml' === $response_format ) {
			$new_response = simplexml_load_string( $response );
		} else {
			$new_response = json_decode( $response );
		}

		return $new_response;
	}
}
