=== Integration for Luminate and Gravity Forms ===
Contributors: rxnlabs, kenjigarland
Tags: forms, crm, integration
Requires at least: 5.5
Requires PHP: 7.0
Tested up to: 6.6.2
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This is a Gravity Forms Add-On to feed submission data from Gravity Forms into the Luminate Online Marketing platform (formerly known as Convio).

== Description ==

If you're using the [Gravity Forms](http://www.gravityforms.com/) plugin, you can now integrate it with the [Blackbaud Luminate](https://www.blackbaud.com/fundraising-crm/luminate-crm) CRM. This Add-On supports creating or updating Constituent records as well as Surveys.

To use this Add-On, you'll need to:

1. Have an licensed, active version of Gravity Forms >= 1.9.3
2. Have a working Luminate instance, as well as Luminate API credentials (key, username, and password)
3. Make sure the IP address of the server(s) you're running this Add-On on is whitelisted with Luminate

If you meet those requirements, this plugin is for you, and should make building new forms and passing constituent data and/or survey responses into Luminate much easier than manually mucking with HTML forms provided by the platform.

*Initial development of this plugin was funded in part by the [Center for Victims of Torture](http://www.cvt.org/).*

**Need custom support on Luminate or WordPress?** Contact Cornershop Creative to get help with a custom designed Luminate donation form, email marketing support, or supporting your WordPress website. [Contact us](https://cornershopcreative.com).

== Installation ==

1. Upload the `gravityforms-luminate` directory to your plugins directory (typically `/wp-content/plugins/`)
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Forms > Settings in the WordPress admin
4. Click on "Luminate Constituents API" in the lefthand column of that page
5. Enter your organization's servlet (domain), organization short name, API key, API username, and API password
6. Edit the "Settings" of individual forms to configure how data is fed into Luminate

== Frequently Asked Questions ==

= Does this work with Ninja Forms, Contact Form 7, Jetpack, etc? =

Nope. This is specifically an Add-On for Gravity Forms and will not have any effect if installed an activated without it.

= What version of Gravity Forms do I need? =

You must be running at least Gravity Forms 1.9.3.

= What kinds of data can this pass to Luminate? =

Right now, this Add-On supports pushing Gravity Forms responses into Constituent records, into Survey responses and/or adding users to Luminate Groups. It does not support advocacy forms or donation forms. It can pass data into any built-in Constituent field in Luminate, as well as map Constituent profile data in response to published Surveys.

= My survey data isn't making it into Luminate how I expected. Is this plugin broken? =

Due to the flexibility of Luminate's survey tool, it's not possible at this time for this plugin to perform any validation on the field values being fed from the user into Luminate. The survey tool can be very particular about what it accepts: a minor mismatch in a field's value (such as a misspelling) can cause Luminate to ignore/reject a provided value. If you're having problems, your best bet is to triple-check that the Gravity Forms-generated field values *exactly* match the valid, acceptable field values defined in your survey. If you're having problems, you should enable Gravity Forms logging (or install the Gravity Forms logging plugin if the Gravity Forms version is less than version 2.2) to see the data that is sent to Luminate.

= My site's IP address keeps changing and the plugin stops sending information to Luminate. What's happening? =

Due to limitations with Luminate's API, you must whitelist the IP address API requests are coming from. If your website is hosted on a service such as Pantheon or another service where your site's public IP address frequently changes, this plugin may not work for you.

= My Luminate site uses a custom domain, will this plugin work with my custom Luminate domain? =

Yes, this plugin will work with custom Luminate domains. If your secure site is not https://secure.convio.net or https://secure2.convio.net and is instead something like https://secure.my-domain-here.org, this plugin should work for you. On the plugin settings page, use your custom secure domain when entering in the Luminate servlet.

= My form submissions are not showing up in Luminate, why is this happening? =

Enable Gravity Forms logging and you will see a log file being generated for this plugin. The log file should contain information about why your submissions are not appearing in Luminate. If you are unable to resolve the issues in the log file, please contact Cornershop Creative support or add a ticket to the plugin support forum.

== Changelog ==

= 1.3.4 =
* Removed dependency on polyfill.io to prevent supply chain attack.

= 1.3.3 =
* Fixed a PHP error that could occur when a required Luminate survey field was not mapped to a Gravity Form field.

= 1.3.2 =
* Added a button to the add-on settings screen that can be used to clear the cached lists of groups and constituent fields.
* Fixed PHP errors that could occur when Luminate credentials were missing or invalid.
* Improved the formatting of some debug messages.
* Clarified that users with custom secure domains can and should leave the "Luminate Organization" setting blank.

= 1.3.1 =
* Fixed a PHP error that occurs when you submit a form that maps to a Luminate Survey. This prevented submissions from being sent to Luminate and the form from being collected as an entry

= 1.3.0 =
* Fixed Survey mapping to be compatible with Gravity Forms 2.5 while remaining backwards-compatible with older Gravity Forms versions

= 1.2.3 =
* Fix regular expression for improved compatibility with PHP 7.3

= 1.2.21 =
* Change the "Luminate Servlet" plugin setting to "Luminate Domain"
* Fix the format of the luminate domain

= 1.2.2 =
* Add the /inc folder with the new PHP classes

= 1.2.1 =
* Fix bug that caused custom Luminate domains not to work with the plugin

= 1.2.0 =
* Increased minimum PHP version to 7.0.
* Added Group mappings. Map form submissions to Luminate groups.
* Add better logging to troubleshoot Luminate API errors.
* Add hide-show-password functionality on the plugin settings page to show the Luminate API password
* Fixed bug that could cause surveys not to display in the dropdown when attempting survey mappings.
* Removed ConvioOpenAPI.php library and replaced it with a library that leverages the WP_HTTP library.

= 1.1.10 =
* Added Luminate IP address restriction message when saving the Luminate API settings. This will show you the IP address that Luminate is reporting so you can whitelist the correct IP address in Luminate in case you are having API connection errors due to IP restrictions.

= 1.1.9 =
* Add a checkbox field to the plugin settings page so users can select whether the Luminate domain the plugin connects to is in fact a custom domain.

= 1.1.8 =
* Added support for custom Luminate domains. If your Luminate account uses a custom domain such as https://secure.my-organization.org, the plugin now works for these domains.
* Output better debugging log to display the exact information that the Luminate API returns.
* Added instructions to the plugin settings page to contact the plugin author if you are having problems with the plugin.

= 1.1.7 =
* Plugin now provides better immediate confirmation of valid Luminate API credentials when saving settings.

= 1.1.6 =
* Fixed bug where the plugin would not let users create new feeds
* Moved external CDN CSS and JavaScript assets so they are stored locally to decrease external dependencies.

= 1.1.5 =
* Fixed bug where the plugin would throw a fatal error if the Luminate API credentials were not supplied and the tried to edit a Gravity Form in the WordPress admin. This caused the Gravity Form sidebar field menu not to work.

= 1.1.4 =
* Updated more instructions on the Luminate API settings page
* Added note about updating the Luminate servlet
* Fixed bug with a undefined PHP variable

= 1.1.2 =
* Updated the constituent mapping functionality so constituent data is sent to Luminate (a previous fix would cause only the email address to be sent to Luminate)
* Updated FAQ to indicate that the Survey functionality is limited to updating constituent profile information
* Added instructions to settings page about creating a API password and not using special characters when creating a API password

= 1.1.1 =
* Updated the survey functionality so that surveys works again
* Added better instructions to the plugin settings page that links to the Luminate documentation and walks you through configuring API access

= 1.1.0 =
* Implemented feature to support pushing data into Luminate Surveys (in addition to or instead of pushing to Constituents)
* Minor UI adjustments to account for new features

= 1.0.1 =
* Removing the groups feature, as there's currently no reasonable way of getting just user-supplied groups via Luminate API.

= 1.0 =
* Initial release.
