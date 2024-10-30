<?php
/**
 * Survey API mapping for the plugin
 */
namespace GF_Luminate;
use GF_Luminate\Constituent as Constituent;

class Survey {
	private static $_instance = null;

	/**
	 * Survey constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_get_luminate_survey_questions', array( $this, 'ajax_load_survey_fields' ), 10 );
		add_filter( 'gf_luminate_feed_fields', array( $this, 'add_feed_fields' ), 11, 1 );
	}

	/**
	 * Get an instance of this class.
	 *
	 * @return Survey
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			$instance        = get_class();
			self::$_instance = new $instance();
		}

		return self::$_instance;
	}

	/**
	 * Get the Luminate survey form's fields from the Luminate API after a user selects the survey they want to load
	 */
	public function ajax_load_survey_fields() {
		global $_GET;
		// set the Gravity Forms ID so that Gravity Forms knows which form to get the fields for
		$_GET['id'] = sanitize_text_field( $_POST['formId'] );

		$questions = $this->get_luminate_survey_questions( sanitize_text_field( $_POST['surveyId'] ) );

		if ( is_array( $questions ) && ! empty( $questions ) ) {
			echo wp_json_encode( $questions );
		} else {
			echo 'No questions found that can be mapped';
		}

		die;
	}

	/**
	 * Get a list of the published Luminate surveys for this Luminate instance
	 *
	 * @return array
	 */
	public function get_luminate_surveys() {

		$current_page = 0;
		$params       = array(
			'published_only'   => 'true',
			'list_ascending'   => 'true',
			'list_page_offset' => &$current_page,
			'sso_auth_token'   => gf_luminate()->get_sso_auth_token(),
		);

		$found_all_surveys = false;
		$display_surveys   = array();
		$display_surveys[] = array(
			'value' => '',
			'label' => 'Select a Survey',
		);

		try {
			gf_luminate()->log_debug( __METHOD__ . '(): Calling - getting the luminate surveys, Parameters ' . print_r( $params, true ) );

			do {
				$get_surveys = gf_luminate()->getConvioAPI()->call( 'CRSurveyAPI_listSurveys', $params, 'json', 'GET' );

				// if we successfully got the surveys, add to array to show in the dropdown
				if ( isset( $get_surveys->listSurveysResponse ) ) {
					$surveys = $get_surveys;

					// if there is only 1 survey, Luminate does NOT return an array of surveys
					if ( ! is_array( $surveys->listSurveysResponse->surveys ) ) {
						$surveys->listSurveysResponse->surveys = array( $surveys->listSurveysResponse->surveys );
					}

					foreach ( $surveys->listSurveysResponse->surveys as $survey ) {
						$display_surveys[] = array(
							'value' => $survey->surveyId,
							'label' => $survey->surveyName,
						);
					}

					// if there are more surveys than the ones we found on page 1, then get the rest of the surveys until we have listed them all
					// 25 is the default amount of surveys returned by the Luminate API
					if ( (int) $surveys->listSurveysResponse->pagingMetadata->currentSize > 25 ) {
						$current_page++;
					} else {
						$found_all_surveys = true;
					}
				} else {
					$found_all_surveys = true;
					// if we have not found any surveys in all of the API calls, then throw exception
					if ( 1 === count( $display_surveys ) ) {
						throw new \Exception( wp_json_encode( $get_surveys ), 403 );
					}
					break;
				}//end if
			} while ( false === $found_all_surveys );

			// if we did not find any surveys and only the default placeholder is in the dropdown, show error in log
			if ( 1 === count( $display_surveys ) ) {
				gf_luminate()->log_debug( __METHOD__ . '(): No published surveys found. Returned Luminate API message' . print_r( $get_surveys, true ) );
			}

			return $display_surveys;
		} catch ( \Exception $e ) {
			gf_luminate()->log_error( __METHOD__ . '(): Getting luminate surveys. Error ' . $e->getCode() . ' - ' . $e->getMessage() );
		}//end try
	}

	/**
	 * Get the question associated with a Luminate survey so we can map those questions to Gravity Forms fields
	 *
	 * @param int $survey_id Survey to get the questions for
	 *
	 * @return array|void Survey questions as an array that can be used by Gravity Forms for mapping
	 */
	public function get_luminate_survey_questions( $survey_id ) {
		try {
			$params = array(
				'survey_id'      => $survey_id,
				'sso_auth_token' => gf_luminate()->get_sso_auth_token(),
			);

			gf_luminate()->log_debug( __METHOD__ . '(): Calling - getting the Luminate survey questions, Parameters ' . print_r( $params, true ) );
			$questions = gf_luminate()->getConvioAPI()->call( 'CRSurveyAPI_getSurvey', $params );

			// Check for errors
			if ( isset( $questions->errorResponse ) ) {
				throw new \Exception( wp_json_encode( $questions ), 403 );
			}

			$found_questions  = [];
			$survey_questions = $questions->getSurveyResponse->survey->surveyQuestions;

			// normalize the Survey questions to an array if the form only contains one(1) question
			if ( ! is_array( $questions->getSurveyResponse->survey->surveyQuestions ) && ! empty( $survey_questions ) ) {
				$survey_questions = array( $survey_questions );
			}

			foreach ( $survey_questions as $map_question ) {
				// get the constituent survey questions
				if ( isset( $map_question->questionTypeData ) && isset( $map_question->questionTypeData->consRegInfoData ) ) {
					$constituent_questions = isset( $map_question->questionTypeData->consRegInfoData->contactInfoField ) && ! empty( $map_question->questionTypeData->consRegInfoData->contactInfoField ) ? $map_question->questionTypeData->consRegInfoData->contactInfoField : [];

					if ( ! is_array( $constituent_questions ) && ! empty( $constituent_questions ) ) {
						$constituent_questions = array( $constituent_questions );
					}

					foreach ( $constituent_questions as $constituent_question ) {
						$required          = isset( $constituent_question->fieldStatus ) && 'required' === strtolower( $constituent_question->fieldStatus );
						$question_data     = [
							'label'    => $constituent_question->label,
							'required' => $required,
							'name'     => $constituent_question->fieldName,
						];
						$found_questions[] = $question_data;
					}
				} elseif ( isset( $map_question->questionText ) && ! empty( $map_question->questionText ) ) {
					// get the non-constituent survey questions
					$required          = isset( $map_question->questionRequired ) &&
								( 'true' === strtolower( $map_question->questionRequired ) || true === $map_question->questionRequired ); // phpcs:ignore
					$question_data     = [
						'label'    => $map_question->questionText, // phpcs:ignore
						'required' => $required,
						'id'       => $map_question->questionId,
					];
					$found_questions[] = $question_data;
				}
			}

			$question_map = [];

			foreach ( $found_questions as $map_question ) {
				if ( 'cons_first_name' === $map_question['label'] ) {
					$label = 'First Name:';
				} elseif ('cons_last_name' ===  $map_question['label'] ) {
					$label = 'Last Name:';
				} else {
					$label = ucwords( str_replace( '_', ' ', $map_question['label'] ) );
				}

				if ( isset( $map_question['id'] ) ) {
					$new_name = sprintf( 'survey_field_id_%s', esc_attr( $map_question['id'] ) );
				} else {
					$new_name = gf_luminate()->add_friendly_field_name( $map_question['name'] );
				}

				$question_map[] = array(
					'name'     => $new_name,
					'label'    => $label,
					'required' => $map_question['required'],
				);
			}

			if ( ! empty( $question_map ) ) {
				$question_map = array_merge( $question_map, gf_luminate()->common_api_fields() );
			}

			return $question_map;
		} catch ( \Exception $e ) {
			gf_luminate()->log_error( __METHOD__ . '(): Error getting Luminate survey questions for survey ID ' . $survey_id . ' - Error ' . $e->getCode() . ' - ' . $e->getMessage() );
		}//end try

		return;
	}

	/**
	 * Get the fields that were mapped for the current Survey feed.
	 *
	 * @return array|void Field map the feed
	 */
	public function survey_field_map() {
		// get the currently saved Survey feed and display it
		$feed              = gf_luminate()->get_current_feed();
		$survey_id         = $feed['meta']['listSurveys'];
		$survey_map_fields = gf_luminate()->get_field_map_fields( $feed, 'surveyMappedFields' );
		$survey_feed_map   = [];

		if ( ! empty( $survey_id ) ) {
			$survey_feed_map = $this->get_luminate_survey_questions( $survey_id );
		}

		return $survey_feed_map;
	}

	/**
	 * Send the Gravity Forms entry to Luminate as a Survey entry.
	 *
	 * @param Feedobject  $feed Gravityforms current feed object
	 * @param Entryobject $entry Gravityforms entry object. Contains the current entry being processed.
	 * @param Formobject  $form Current Gravity Forms form being processed.
	 * @param  string      $auth_token Luminate authorization token.
	 * @param bool        $sso_token Use a single sign-on token when making an auth token request
	 *
	 * @return void
	 */
	public function process_luminate_survey( $feed, $entry, &$form, $auth_token = '', $sso_token = false ) {
		// check to see if the feed should submit a survey using the API
		if ( isset( $feed['meta']['survey'] ) && '1' === $feed['meta']['survey'] ) {
			try {
				$field_map = gf_luminate()->get_field_map_fields( $feed, 'surveyMappedFields' );
				$email     = null;

				if ( isset( $field_map['cons_email'] ) ) {
					$email = gf_luminate()->get_field_value( $form, $entry, $field_map['cons_email'] );
				}

				// Set a global so that we can use this same email address if this form submission is results in a Constituent feed being run
				$GLOBALS['gfluminate_survey_primary_email'] = $email;
				$override_empty_fields                      = gf_apply_filters( 'gform_luminate_override_empty_fields', $form['id'], true, $form, $entry, $feed );
				if ( ! $override_empty_fields ) {
					gf_luminate()->log_debug( __METHOD__ . '(): Empty fields will not be overridden.' );
				}

				$post_vars = array();

				// Loop through the fields, populating $post_vars as necessary
				foreach ( $field_map as $name => $field_id ) {

					if ( 'Email' === $name || '' === $field_id ) {
						continue;
						// we already did email, and we can skip unassigned stuff
					}

					$field_value = gf_luminate()->get_field_value( $form, $entry, $field_id );

					if ( empty( $field_value ) && ! $override_empty_fields ) {
						continue;
					}

					// Get the survey question ids
					if ( false !== strpos( $name, 'survey_field_id_' ) ) {
						$question_id = str_replace( 'survey_field_id_', '', $name );
						$new_name    = sprintf( 'question_%s', $question_id );
					} else {
						$new_name = gf_luminate()->remove_friendly_field_name( $name );
					}

					$post_vars[ $new_name ] = $field_value;
				}

				$params = $post_vars;
				$params = gf_apply_filters( 'gform_luminate_survey_args_pre_post', $form['id'], $params, $form, $entry, $feed );

				$survey_id = $feed['meta']['listSurveys'];
				if ( ! empty( $survey_id ) ) {

					$survey_params = array(
						'survey_id' => $survey_id,
					);

					if ( true === $sso_token ) {
						$survey_params['sso_auth_token'] = $auth_token;
					} else {
						$survey_params['auth'] = $auth_token;
					}

					// before we can process a survey submission, we must first create a constituent in Luminate, if the constituent wasn't already created when a constituent feed ran
					$cons_id = gf_luminate()->get_constituent_id();

					if ( empty( $cons_id ) ) {
						// let's hook into the constituent feed filter so we can modify the data that gets sent to Luminate, so this constituent can be created using the email address supplied to the survey
						add_filter(
							'gform_luminate_constituent_args_pre_post',
							function( $params ) {
								$email  = $GLOBALS['gfluminate_survey_primary_email'];
								$params = array_merge(
									array(
										'primary_email' => $email,
										'email_primary_address' => $email,
									),
									$params
								);

								return $params;

							},
							10,
							3
						);
						// temporarily enable the constituent feed so the constituent is created and/or updated
						// we need to map Surveys to constituents
						$feed['meta']['constituent'] = '1';
						Constituent::get_instance()->process_luminate_constituent( $feed, $entry, $form );

						$cons_id = gf_luminate()->get_constituent_id();
						if ( ! empty( $cons_id ) ) {
							// Add the constituent ID to the survey submission
							$survey_params['cons_id'] = $cons_id;
							$new_sso_auth_token       = gf_luminate()->get_sso_auth_token( $cons_id );
							if ( ! empty( $new_sso_auth_token ) ) {
								$survey_params['sso_auth_token'] = $new_sso_auth_token;
								unset( $survey_params['auth'] );
							}
						}
					}//end if

					$survey_params = array_merge( $survey_params, $params );
					gf_luminate()->log_debug( __METHOD__ . '(): Calling - Pushing survey submissions to Luminate for survey ID ' . $survey_id . '. ' . print_r( $survey_params, true ) );

					$submit_survey = gf_luminate()->getConvioAPI()->call( 'CRSurveyAPI_submitSurvey', $survey_params );
					// Check for errors
					if ( WP_HTTP_Luminate::is_api_error( $submit_survey ) || $this->is_luminate_survey_api_error( $submit_survey ) ) {
						$error = $submit_survey;
						// If there were field errors and the form was not successfully submitted to Luminate
						if ( $this->is_luminate_survey_api_field_error( $submit_survey ) ) {
							$error = print_r( $this->get_field_errors( $form, $entry, $feed, $submit_survey ), true );
						}

						throw new \Exception( wp_json_encode( $error ), 403 );
					} else {
						gf_luminate()->log_debug( __METHOD__ . '(): Successfully added survey response to Luminate for survey ID: ' . $survey_id . '. Response is' . wp_json_encode( $submit_survey ) );

						// If there were field errors but the form submitted successfully. Highlight those errors in a note for the submission
						if ( $this->is_luminate_survey_api_field_error( $submit_survey ) ) {
							$errors = $this->get_field_errors( $form, $entry, $feed, $submit_survey );
							$note   = sprintf( '%s %s %s %s %s: %s', __( 'Successfully added Survey ID', 'gfluminate' ), $survey_id, __( 'to Luminate for Constituent', 'gfluminate' ), $cons_id, __( 'but some fields had errors during the submission', 'gfluminate' ), print_r( $errors, true ) );
							gf_luminate()->add_note( $entry['id'], $note, 'error' );
						} else {
							// add a note to entry
							$note = sprintf( '%s %s %s %s', __( 'Successfully added Survey ID', 'gfluminate' ), $survey_id, __( 'to Luminate for Constituent', 'gfluminate' ), $cons_id );
							gf_luminate()->add_note( $entry['id'], $note, 'success' );
						}
					}
				}//end if
			} catch ( \Exception $e ) {
				gf_luminate()->log_error( __METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage() );
				$note = sprintf( '%s %s', __( 'Error submitting Survey ID. Luminate API error', 'gfluminate' ), $e->getMessage() );
				gf_luminate()->add_note( $entry['id'], $note, 'error' );
			}//end try
		}//end if
	}

	/*
	 * Determine if a HTTP response returned from the Luminate API resulted in an error when submitting a survey
	 *
	 * @param object $error_message Luminate API message as a stdClass object
	 *
	 * @return bool
	 */
	public function is_luminate_survey_api_error( $error_message ) {
		if ( is_object( $error_message ) && isset( $error_message->submitSurveyResponse ) && ( 'false' === $error_message->submitSurveyResponse->success || false === $error_message->submitSurveyResponse->success ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect if the survey did not submit successfully or if the survey did submit but some fields had errors
	 *
	 * @param string $error_message Error message from the Luminate API during survey submission
	 *
	 * @return bool
	 */
	public function is_luminate_survey_api_field_error( $error_message ) {
		if ( ! empty( $this->get_luminate_survey_api_errors( $error_message ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the fields that had errors submitting
	 *
	 * @param string $error_message Error message from the Luminate API during survey submission
	 *
	 * @return array|null Errors for the fields or form. Null if no errors found
	 */
	public function get_luminate_survey_api_errors( $error_message ) {
		// normalize the errors if there is only 1 error since Luminate will have an array of errors for multiple fields
		// that have issues but only one single error object if only one field has an issue
		$normalize_errors = gf_luminate()->normalize_luminate_api_return( $error_message->submitSurveyResponse, 'errors' );

		if ( ! isset( $normalize_errors->errors ) || empty( $normalize_errors->errors ) ) {
			return;
		} else {
			$errors       = $normalize_errors->errors;
			$found_errors = [];
		}

		foreach ( $errors as $error ) {
			$format_error = [ 'message' => $error->errorMessage ];

			if ( isset( $error->questionInError ) && ! empty( $error->questionInError ) ) {
				$format_error['question_id'] = $error->questionInError;
			}

			$found_errors[] = $format_error;
		}

		return ! empty( $found_errors ) ? $found_errors : null;
	}

	/**
	 * Get the field errors and the corresponding Gravity Forms field label
	 *
	 * @param array $form Gravity Forms form
	 * @param array $entry Current Gravity Forms entry
	 * @param array $feed Current feed object
	 * @param object $error_message Luminate error when the survey was submitted
	 */
	function get_field_errors( $form, $entry, $feed, $error_message ) {
		$errors = $this->get_luminate_survey_api_errors( $error_message );

		if ( empty( $errors ) ) {
			return;
		}

		$extract_question_ids = [];
		foreach ( $errors as $error ) {
			if ( isset( $error['question_id'] ) ) {
				$extract_question_ids[ $error['question_id'] ] = [
					'message' => $error['message'],
					'mapped'  => false,
				];
			}
		}

		$field_map        = gf_luminate()->get_field_map_fields( $feed, 'surveyMappedFields' );
		$formatted_errors = [];
		// Loop through the fields, populating $post_vars as necessary
		foreach ( $field_map as $name => $field_id ) {

			// Get the survey question ids
			if ( false !== strpos( $name, 'survey_field_id_' ) ) {
				$question_id = str_replace( 'survey_field_id_', '', $name );
			} else {
				$question_id = gf_luminate()->remove_friendly_field_name( $name );
			}

			if ( isset( $extract_question_ids[ $question_id ] ) ) {
				$extract_question_ids[ $question_id ]['mapped'] = true;
				$field_value                                    = gf_luminate()->get_field_value( $form, $entry, $field_id );
				$field = \GFFormsModel::get_field( $form, $field_id );

				$formatted_errors[] = [
					'Luminate Question ID'      => $question_id,
					'Luminate Error Message'    => $extract_question_ids[ $question_id ]['message'],
					'Gravity Forms Field ID'    => $field->id,
					'Gravity Forms Field Label' => $field->label,
					'Gravity Forms Field Value' => $field_value,
				];
			}

		}

		foreach ( $extract_question_ids as $question_id => $error ) {
			if ( $error['mapped'] ) {
				continue;
			}

			$formatted_errors[] = [
				'Luminate Question ID'   => $question_id,
				'Luminate Error Message' => $extract_question_ids[ $question_id ]['message'],
				'Unmapped Field'         => 'This question is not mapped to a Gravity Forms field',
			];
		}

		return $formatted_errors;
	}

	/**
	 * Add Survey-specific fields to the Feed Settings edit page to map field submissions to a Luminate survey
	 *
	 * @param array $feed_fields Current fields for the feed
	 *
	 * @return array Updated list of fields that can be mapped for a feed
	 */
	public function add_feed_fields( $feed_fields ) {
		if ( ! is_array( $feed_fields ) || empty( $feed_fields ) ) {
			return $feed_fields;
		}

		$feed_fields = array_merge(
			$feed_fields,
			[
				array(
					'name'     => 'listSurveys',
					'label'    => esc_html__( 'Survey', 'gfluminate' ),
					'type'     => 'select',
					'required' => false,
					'choices'  => $this->get_luminate_surveys(),
					'tooltip'  => '<h6>' . esc_html__( 'Survey List', 'gfluminate' ) . '</h6>' . esc_html__( 'Select which published survey to map entries to.', 'gfluminate' ),
				),
				array(
					'name'      => 'surveyMappedFields',
					'label'     => esc_html__( 'Survey Map Fields', 'gfluminate' ),
					'type'      => 'field_map',
					'required'  => false,
					'field_map' => $this->survey_field_map(),
					'tooltip'   => '<h6>' . esc_html__( 'Survey Map Fields', 'gfluminate' ) . '</h6>' . esc_html__( 'Associate your Luminate survey fields with the appropriate Gravity Form fields.', 'gfluminate' ),
				),
			] 
		);

		return $feed_fields;
	}

}
