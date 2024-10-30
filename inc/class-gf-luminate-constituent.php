<?php
/**
 * Constituent API mapping for plugin
 */
namespace GF_Luminate;

class Constituent {
	private static $_instance = null;

	/**
	 * Constituent constructor.
	 */
	public function __construct() {
		add_filter( 'gf_luminate_feed_fields', array( $this, 'add_feed_fields' ), 10, 1 );
	}

	/**
	 * Get an instance of this class.
	 *
	 * @return Constituent
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			$instance        = get_class();
			self::$_instance = new $instance();
		}

		return self::$_instance;
	}

	/**
	 * Return an array of Luminate constituent fields which can be mapped to the Form fields/entry meta.
	 *
	 * @return array
	 */
	public function constituent_field_map() {

		$constituent_edit_fields = $this->get_constituent_edit_fields();
		if ( ! empty( $constituent_edit_fields ) ) {

			$constituent_fields = array();
			// add a constituent ID field that we can use to map which constituent gets updated without relying on the primary email address (which can actually throw errors if there are two users with the same primary email address)
			$constituent_fields['cons_id'] = array(
				'label'    => 'Constituent ID',
				'required' => false,
			);

			foreach ( $constituent_edit_fields as $field ) {
				$label = $field->label;

				// some fields have the same name or similar sounding name as other fields. To distiguish them, add the subgroup to the field label
				if ( ! empty( $field->subGroup ) ) {
					$label = $field->subGroup . ': ' . $label;
				}

				$field_setting = array(
					'label' => $label,
				);

				if ( ( 'true' === $field->required || true === $field->required ) && 'user_password' !== $field->name ) {
					$field_setting['required'] = true;
				}

				$new_name = gf_luminate()->add_friendly_field_name( $field->name );

				$constituent_fields[ $new_name ] = $field_setting;
			}
		} elseif ( empty( $constituent_edit_fields ) ) {
			// setup some default fields which should always be editable by the user
			$constituent_fields = array(
				'cons_id',
				'first_name',
				'last_name',
				'primary_email',
				'home_phone',
				'mobile_phone',
				'work_Phone',
				'home_street1',
				'home_street2',
				'home_street3',
				'home_city',
				'home_stateprov',
				'home_zip',
				'home_county',
				'home_country',
				'other_street1',
				'other_street2',
				'other_city',
				'other_stateprov',
				'other_county',
				'other_zip',
				'other_country',
				'employer',
				'employer_street1',
				'employer_street2',
				'employer_street3',
				'employer_city',
				'employer_stateprov',
				'employer_county',
				'employer_zip',
				'employer_country',
				'cons_occupation',
				'position',
			);
		}//end if

		// fields that aren't included in the list of fields editable returned from the API but ones we can submit using the createOrUpdate method
		$other_fields = array(
			'add_center_ids'           => array(
				'label' => 'Add Center IDs',
			),
			'add_center_opt_in_ids'    => array(
				'label' => 'Add Center IDs Email Opt-ins',
			),
			'add_interest_ids'         => array(
				'label' => 'Add Interest IDs',
			),
			'remove_center_ids'        => array(
				'label' => 'Remove Center IDs',
			),
			'remove_center_opt_in_ids' => array(
				'label' => 'Remove Center IDs Email Opt-ins',
			),
			'remove_group_ids'         => array(
				'label' => 'Remove Groups',
			),
			'remove_interest_ids'      => array(
				'label' => 'Remove Interest IDs',
			),
			'interaction_subject'      => array(
				'label' => 'Interaction Subject (limit 80 characters)',
			),
			'interaction_body'         => array(
				'label' => 'Interaction Body',
			),
			'interaction_cat_id'       => array(
				'label' => 'Interaction Category ID',
			),
			'interaction_count'        => array(
				'label' => 'Interaction Count (number of times interaction performed)',
			),
			'no_welcome'               => array(
				'label' => 'Dont\'t Send Welcome Email',
			),
			'suppress_cleaning'        => array(
				'label' => 'Suppress Data Cleaning',
			),
		);

		$constituent_fields = array_merge( $constituent_fields, $other_fields );

		$field_map = array();
		// add a field number since Constituent fields can have a lot of data that can be mapped
		$field_number = 1;
		foreach ( $constituent_fields as $key => $field ) {
			if ( ! is_integer( $key ) ) {
				$label = $field['label'];
				$name  = $key;
			} else {
				$label = ucwords( str_replace( '_', ' ', $field ) );
				$name  = $field;
			}

			$new_name = gf_luminate()->add_friendly_field_name( $name );

			$field_setting = array(
				'name'  => $new_name,
				'label' => $field_number . '. ' . $label,
			);

			$field_number++;

			// @TODO: Figure out why setting a constituent field as required breaks the saving of the feed
			/*
			if ( isset($field['required']) && $field['required'] === true ) {
				$field_setting['required'] = true;
			}*/

			$field_map[] = $field_setting;
		}//end foreach

		$field_map = array_merge( $field_map, gf_luminate()->common_api_fields() );

		return $field_map;
	}

	/**
	 * Get the constituent fields from the Luminate API that a constituent is able to edit.
	 *
	 * @return array An array of fields that the user is allowed to edit
	 */
	public function get_constituent_edit_fields() {
		$cache_name = sprintf( '%s_constituent_fields', gf_luminate()->_slug );

		$get_fields_cache = get_transient( $cache_name );

		if ( empty( $get_fields_cache ) ) {
			$params = array(
				'access'          => 'update',
				'include_choices' => 'true',
				'sort_order'      => 'group',
			);

			$api = gf_luminate()->getConvioAPI();
			if ( ! is_object( $api ) ) {
				gf_luminate()->log_error( __METHOD__ . '(): Error getting list of Constituent fields to map: failed to get API object. Check your Luminate credentials.' );
				return false;
			}

			$get_editable_fields = $api->call( 'SRConsAPI_listUserFields', $params );
			if ( isset( $get_editable_fields->listConsFieldsResponse ) ) {

				if ( ! is_array( $get_editable_fields->listConsFieldsResponse->field ) ) {
					$get_editable_fields->listConsFieldsResponse->field = array( $get_editable_fields->listConsFieldsResponse->field );
				}

				set_transient( $cache_name, $get_editable_fields->listConsFieldsResponse->field, WEEK_IN_SECONDS );
				return $get_editable_fields->listConsFieldsResponse->field;
			} else {
				gf_luminate()->log_error( __METHOD__ . '(): Error getting list of Constituent fields to map ' . print_r( $get_editable_fields, true ) );
			}
		}

		return $get_fields_cache;
	}

	/**
	 * Index the constituent edit fields by field name.
	 *
	 * @return array Array with field names indexed
	 */
	public function get_constituent_edit_fields_indexed_by_name() {
		$fields = $this->get_constituent_edit_fields();

		$index = array();

		foreach ( $fields as $field ) {
			$index[ $field->name ]      = $field->name;
			$no_special_chars           = preg_replace( '/[^A-Za-z0-9 ]/', '', $field->name );
			$index[ $no_special_chars ] = $field->name;
		}

		return $index;
	}

	/**
	 * Create a new Constituent in Luminate or update a constituent in Luminate.
	 *
	 * Create or update a constituent in Luminate.
	 *
	 * @param Feedobject  $feed Gravityforms current feed object
	 * @param Entryobject $entry Gravityforms entry object. Contains the current entry being processed.
	 * @param Formobject  $form Current Gravity Forms form being processed.
	 *
	 * @return void
	 */
	public function process_luminate_constituent( $feed, $entry, &$form ) {
		// check to see if the form should submit to the constituents API
		if ( isset( $feed['meta']['constituent'] ) && '1' === $feed['meta']['constituent'] ) {
			$settings = gf_luminate()->get_plugin_settings();

			// retrieve name => value pairs for all fields mapped in the 'mappedFields' field map
			$field_map = gf_luminate()->get_field_map_fields( $feed, 'mappedFields' );

			$possible_fields_to_fix = $this->get_constituent_edit_fields_indexed_by_name();

			$override_empty_fields = gf_apply_filters( 'gform_luminate_override_empty_fields', $form['id'], true, $form, $entry, $feed );
			if ( ! $override_empty_fields ) {
				gf_luminate()->log_debug( __METHOD__ . '(): Empty fields will not be overridden.' );
			}

			$post_vars = array();

			// Loop through the fields, populating $post_vars as necessary
			foreach ( $field_map as $name => $field_id ) {

				// Ignore unmapped fields. If we're updating a record, we don't want to overwrite stored
				// values that the user hasn't actually changed.
				if ( empty( $field_id ) ) {
					continue;
				}

				$field_value = gf_luminate()->get_field_value( $form, $entry, $field_id );

				if ( empty( $field_value ) && ! $override_empty_fields ) {
					continue;
				} else {
					$new_name               = gf_luminate()->remove_friendly_field_name( $name );
					$post_vars[ $new_name ] = $field_value;
				}
			}//end foreach

			try {
				$params = $post_vars;
				// modify the data before sending it to Luminate
				$params = gf_apply_filters( array( 'gform_luminate_constituent_args_pre_post', $form['id'] ), $params );

				if ( isset( $params['primary_email'] ) || isset( $params['email.primary_address'] ) ) {

					if ( isset( $params['email.primary_address'] ) ) {
						$primary_email                   = $params['email.primary_address'];
						$params['email_primary_address'] = $params['email.primary_address'];
					} else {
						$primary_email = $params['primary_email'];
					}
				}

				if ( ! isset( $params['interaction_body'] ) || empty( $params['interaction_body'] ) ) {
					$params['interaction_body'] = __( 'Update profile using Gravity Forms Luminate plugin on website ', 'gfluminate' ) . get_bloginfo( 'url' );
				}

				if ( ! isset( $params['interaction_subject'] ) || empty( $params['interaction_subject'] ) ) {
					$params['interaction_subject'] = __( 'Update profile data externally', 'gfluminate' );
				}

				$method            = 'createOrUpdate';
				$convio_url_params = array(
					'method'          => $method,
					'api_key'         => $settings['luminate_api_key'],
					'login_name'      => $settings['luminate_api_user'],
					'login_password'  => $settings['luminate_api_pass'],
					'v'               => '1.0',
					'response_format' => 'json',
				);
				$convio_url_params = array_merge( $convio_url_params, $params );

				// set the email address if we're submitting a survey and creating a constituent before we submit the survey
				if ( ! empty( $GLOBALS['gfluminate_survey_primary_email'] ) ) {
					$convio_url_params['primary_email']         = $GLOBALS['gfluminate_survey_primary_email'];
					$convio_url_params['email_primary_address'] = $GLOBALS['gfluminate_survey_primary_email'];
					$convio_url_params['email.primary_address'] = $GLOBALS['gfluminate_survey_primary_email'];
				}

				gf_luminate()->log_debug( __METHOD__ . '(): Calling - update constituent profile, Parameters ' . print_r( $convio_url_params, true ) );

				$create_constituent = gf_luminate()->getConvioAPI()->call( 'SRConsAPI_createOrUpdate', $convio_url_params, 'json' );

				// verify that the constituent was created and/or updated. If there was an error, log that error
				if ( WP_HTTP_Luminate::is_api_error( $create_constituent ) ) {
					throw new \Exception( wp_json_encode( $create_constituent ) );
				} else {
					gf_luminate()->set_constituent_id( $create_constituent->createOrUpdateConsResponse->cons_id );
					gf_luminate()->log_debug( __METHOD__ . '(): Successfully added or updated a constituent record. API response. ' . print_r( $create_constituent, true ) );
				}
			} catch ( \Exception $e ) {
				gf_luminate()->log_error( __METHOD__ . '(): Could not create or update a constituent. API response: ' . $e->getMessage() );
				return;
			}//end try

			try {
				// if we successfully added the constituent, try to add groups
				if ( ! empty( $create_constituent ) && ! WP_HTTP_Luminate::is_api_error( $create_constituent ) && isset( $feed['meta']['group'] ) && '1' === $feed['meta']['group'] ) {

					$constituent_id = gf_luminate()->get_constituent_id();

					gf_luminate()->log_debug( __METHOD__ . '(): Starting process to add user to group' );

					// inspect the feed to get the groups
					$group_ids = array();

					// all Luminate groups are stored as Numeric values
					foreach ( $feed['meta'] as $key => $value ) {
						if ( '1' === $value && is_numeric( $key ) ) {
							$group_ids[] = $key;
						}
					}

					// pass the group IDs if present in the feed
					if ( count( $group_ids ) ) {
						gf_luminate()->log_debug( __METHOD__ . '(): Identified groups are ' . implode( ',', $group_ids ) );

						$convio_params = [
							'add_group_ids' => implode( ',', $group_ids ),
							'cons_id'       => $constituent_id,
						];

						gf_luminate()->log_debug( __METHOD__ . '(): Calling - update constituent profile with mapped Groups, Parameters ' . print_r( $convio_params, true ) );

						$add_to_groups = gf_luminate()->getConvioAPI()->call( 'SRConsAPI_update', $convio_params, 'json' );

						if ( ! WP_HTTP_Luminate::is_api_error( $add_to_groups ) ) {
							// Groups successfully added.
							gf_luminate()->log_debug( __METHOD__ . "(): API update to set groups for $primary_email, ConsId $constituent_id successful. Responding with response body: " . print_r( $add_to_groups, true ) );
						} else {
							throw new \Exception( wp_json_encode( $create_constituent ) );
						}
					} else {
						gf_luminate()->log_debug( __METHOD__ . '(): No groups configured in feed settings to add the user' );
					}//end if
				}//end if
			} catch ( \Exception $e ) {
				// Groups weren't added successfully. Log the issue.
				gf_luminate()->log_error( __METHOD__ . "(): API update to set groups for $primary_email failed. Responding with response body: " . $e->getMessage() );
			}//end try
		}//end if
	}

	/**
	 * Add Constituent specific fields to the Feed Settings edit page to map field submissions to a Luminate constituent
	 *
	 * @param array $feed_fields Current fields for the feed
	 *
	 * @return array Updated list of fields that can be mapped for a feed
	 */
	public function add_feed_fields( $feed_fields ) {
		if ( ! is_array( $feed_fields ) || empty( $feed_fields ) ) {
			return $feed_fields;
		}

		$feed_fields[] = array(
			'name'      => 'mappedFields',
			'label'     => esc_html__( 'Constituent Map Fields', 'gfluminate' ),
			'type'      => 'field_map',
			'field_map' => $this->constituent_field_map(),
			'tooltip'   => '<h6>' . esc_html__( 'Constituent Map Fields', 'gfluminate' ) . '</h6>' . esc_html__( 'Associate your Luminate constituent fields with the appropriate Gravity Form fields.', 'gfluminate' ),
			'required'  => false,
		);

		return $feed_fields;
	}
}
