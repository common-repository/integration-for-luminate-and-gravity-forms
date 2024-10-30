( function( $, localized ) {
    $( document ).ready( function() {

        function enableConstituentMapping() {
            if (!$('#constituent').is(':checked')) {
                $('#gaddon-setting-row-mappedFields,#gform_setting_mappedFields').hide('medium');
            }

            $('#constituent').on('change', function(){
                if ( $(this).is(':checked') ) {
                    $('#gaddon-setting-row-mappedFields,#gform_setting_mappedFields').show('medium');
                } else {
                    $('#gaddon-setting-row-mappedFields,#gform_setting_mappedFields').hide('medium');
                    var inputs = $('#gaddon-setting-row-mappedFields td');

                    // remove the required attribute from the fields that are hidden
                    $.map(inputs, function(node,index){
                        var $node = $(node);
                        var select = $(node).find('select');
                        select.removeAttr('required');
                        var label = $(node).find('label span.required').remove();
                    });
                }
            });
        }

        enableConstituentMapping();

        function verifyEmailConstituentMapping() {
            // Decorate constituent primary email with a required star
            $('label[for="mappedFields_primary_email"]').append( ' <span class="required">*</span>' );
            // Validate via JS
            $( '#tab_gravityforms-luminate form#gform-settings' ).on( 'submit', function() {

                if ( ! isValidConstituentEmailField( true ) ) {
                    alert( 'You must specify a field for Primary Email if mapping constituents.' );

                    return false;
                }
            } );
        }

        verifyEmailConstituentMapping();

        function enableGroupMapping() {
            if (!$('#group').is(':checked')) {
                $('#gaddon-setting-row-groups,#gform_setting_groups').hide('medium');
            }

            $('#group').on('change', function(){
                if ( $(this).is(':checked') ) {
                    $('#gaddon-setting-row-groups,#gform_setting_groups').show('medium');
                } else {
                    $('#gaddon-setting-row-groups,#gform_setting_groups').hide('medium');
                }
            });
        }

        enableGroupMapping();

        function enableSurveyMapping() {
            if (!$('#survey').is(':checked')) {
                $('#gaddon-setting-row-surveyMappedFields,#gform_setting_listSurveys,#gform_setting_surveyMappedFields').hide();
            }

            $('#survey').on('change', function(){
                if ( $(this).is(':checked') ) {
                    $('#gaddon-setting-row-surveyMappedFields,#gform_setting_listSurveys,#gform_setting_surveyMappedFields').show( 'medium' );
                } else {
                    $('#gaddon-setting-row-surveyMappedFields,#gform_setting_listSurveys,#gform_setting_surveyMappedFields').hide( 'medium' );
                    $('#listSurveys').prop('selectedIndex',0);
                    // delete the mapped survey fields to prevent Gravity Forms from throwing and error saying that some required fields weren't mapped
                    $('#gaddon-setting-row-surveyMappedFields,#gform_setting_surveyMappedFields').remove();
                }
            } );
        }

        enableSurveyMapping();

        function changeSurveys() {
            var $list_surveys = $( '#listSurveys' );

            if ( ! $list_surveys.length ) {
                return;
            }

            var saved_survey = $('#gaddon-setting-row-surveyMappedFields .settings-field-map-table tbody').html();

            var saved_survey_id = $list_surveys.val();

            $list_surveys.on( 'change', function(){
                var survey = $( this ).val();
                // reload the saved mapping if we switch a form and re-switch back to that form after mapping
                if ( survey == saved_survey_id ) {
                    $( '#gaddon-setting-row-surveyMappedFields .settings-field-map-table tbody' ).html( saved_survey );

                    return true;
                }

                if ( survey.length > 0 ) {
                    loadSurveyQuestions( survey );
                } else {
                    $( '#gaddon-setting-row-surveyMappedFields' ).hide();
                }
            } );
        }

        changeSurveys();

        function loadSurveyQuestions( survey_id ) {
            var urlParams = new URLSearchParams(window.location.search);

            var post_data = {
                'action': 'get_luminate_survey_questions',
                'surveyId': survey_id,
                'formId': urlParams.get( 'id' )
            };

            // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
            // Set a timeout on adding loaders to table. Wait till everything is loaded since this was broken
            // before the release of version 1.2.0
            setTimeout( function() {
                $( '#listSurveys' ).after('<div class="loader-inner ball-pulse"></div>' ) ;
                $( '#gaddon-setting-row-surveyMappedFields' ).hide();
                $('.loader-inner').loaders();
            }, 200 );

            $.ajax({
                data: post_data,
                url: ajaxurl,
                method: 'POST',
                dataType: 'html',
                success: function( response, status, xhr ) {
                    try {
                        var json_string = JSON.parse( response );
                        // empty the value of the mapped fields so we can remap the fields
                        $( 'input[name="_gform_setting_surveyMappedFields"]' ).val( '' );
                        $( '#gaddon-setting-row-surveyMappedFields,#gform_setting_surveyMappedFields' ).remove();
                        $( '#gaddon-setting-row-listSurveys,#gform_setting_listSurveys' ).append( 'Reloading feed settings to get survey questions for selected survey' );
                        // force a page refresh where we will show the list of survey questions
                        if ( $( '#gform-settings-save' ).length ) {
                            $( '#gform-settings-save' ).click();
                        } else {
                            $( '#gform-settings' ).submit();
                        }

                    } catch ( error ) {
                        $( '#gaddon-setting-row-surveyMappedFields,#gform_setting_surveyMappedFields' ).replaceWith( '<tr class="no-survey-questions-found"><td colspan="2">No survey questions found for the specified survey or there was an error loading the questions. Enable Gravity Forms logging to see if there was an error getting the survey question</td></tr>' );
                    }
                }
            } ).always( function( xhr, status, xhr_error ) {
                $( '.loader-inner' ).remove();
            } );
        }

        /**
         * We need to check via JS if an email address has been mapped if the Constituents mapped is enabled
         * We can't just set constituent email to 'required' in the PHP for the form because we may only be mapping Surveys
         */
        function isValidConstituentEmailField( focusIfFalse ) {

            if ( $('#gaddon-setting-checkbox-choice-constituent > .gaddon-checkbox').is(':checked' ) ) {
                if ( jQuery('#mappedFields_primary_email option:first-child').is(':selected') ) {
                    jQuery('#mappedFields_primary_email').focus();
                    return false;
                }
            }

            return true;
        }

        /*
        Remove unselected feed groups from getting submitted to PHP/processed. If there are a lot of email groups that can be submitted but aren't selected, PHP may throw an error saying that the max_input_vars has been exceeded since we could post over 1000 inputs with most of them being empty.

        Experienced this problem when attempting to create a new feed.
         */
        function removeUnselectedGroupsFromProcessing() {
            var $feed_form = $( '#gform-settings' ).addClass( 'not-ready' ),
                $feed_form_submit = $feed_form.find( 'input[type="submit"]' );
            $feed_form_submit.on( 'click submit', function( e ) {
                if ( $feed_form.hasClass( 'not-ready' ) ) {
                    e.preventDefault();
                    $feed_form.removeClass( 'not-ready' );
                    var checkboxes = $('#gaddon-setting-row-groups input');

                    $.map( checkboxes, function( node, index ) {
                        var $node = $( node );

                        if ( $node.prop( 'type' ).toLowerCase() == 'checkbox' && $node.prop( 'checked' ) == false) {
                            $node.prop( 'disabled', true );
                            $node.prev().prop( 'disabled', true );
                        }
                    } );

                    $feed_form_submit.click();
                    return true;
                }

                return true;

            } );
        }

        removeUnselectedGroupsFromProcessing();

        /**
         * Add the ability for users to reveal the password field for the Luminate fields
         */
        function reveal_password() {
            $( 'input[type="password"]' ).hideShowPassword(true, true);
        }
        reveal_password();

    });
} )( window.jQuery || window.$ || window.Zepto, window.gf_luminate_strings );