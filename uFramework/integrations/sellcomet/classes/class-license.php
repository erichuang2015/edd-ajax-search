<?php
/**
 * License handler for Sell Comet plugins
 *
 * This class should simplify the process of adding license information
 * to new EDD extensions.
 *
 * @version 1.1
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Sell_Comet_License' ) ) :

    /**
     * EDD_License Class
     */
    class Sell_Comet_License {

        private $file;
        private $license;
        private $item_name;
        private $item_shortname;
        private $version;
        private $author;
        private $api_url = 'https://sellcomet.com/edd-sl-api/';

        /**
         * Class constructor
         *
         * @param string  $_file
         * @param string  $_item_name
         * @param string  $_version
         */
        function __construct( $_file, $_item_name, $_version ) {

            $this->file             = $_file;
            $this->item_name        = $_item_name;
            $this->version          = $_version;
            $this->item_shortname   = $this->get_item_shortname( $this->item_name );
            $this->license          = trim( uframework_get_option( $this->item_shortname . '-license', 'key', '' ) );
            $this->author           = 'Sell Comet';

            // Setup hooks
            $this->includes();
            $this->hooks();
        }

        /**
         * Helper function to get the item_shortname
         *
         * @access  private
         * @param   string      $item_name
         * @return  string      $item_name
         */
        private function get_item_shortname( $item_name ) {
            $item_name = strtolower( str_replace( 'Easy Digital Downloads - ', 'edd-', $item_name ) );
            $item_name = str_replace( ' ', '-', $item_name );

            return $item_name;
        }

        /**
         * Include the updater class
         *
         * @access  private
         * @return  void
         */
        private function includes() {
            if ( ! class_exists( 'EDD_SL_Plugin_Updater' ) )  {
                require_once 'EDD_SL_Plugin_Updater.php';
            }
        }

        /**
         * Setup hooks
         *
         * @access  private
         * @return  void
         */
        private function hooks() {
            // Display help text at the top of the Licenses tab
            // add_action( 'edd_settings_tab_top', array( $this, 'license_help_text' ) );

            // Activate license key on settings save
            add_action( 'admin_init', array( $this, 'activate_license' ) ); // Normal request (if no js)
            add_action( 'wp_ajax_sellcomet_activate_license', array( $this, 'activate_license' ) ); // Ajax request

            // Deactivate license key
            add_action( 'admin_init', array( $this, 'deactivate_license' ) ); // Normal request (if no js)
            add_action( 'wp_ajax_sellcomet_deactivate_license', array( $this, 'deactivate_license' ) ); // Ajax request

            // Check that license is valid once per week
            add_action( 'edd_weekly_scheduled_events', array( $this, 'weekly_license_check' ) );

            // For testing license notices, uncomment this line to force checks on every page load
            // add_action( 'admin_init', array( $this, 'weekly_license_check' ) );

            // Updater
            add_action( 'admin_init', array( $this, 'auto_updater' ), 0 );

            // Display notices to admins
            add_action( 'admin_notices', array( $this, 'notices' ) );

            // Displays message inline on the plugin row that the license key is missing
            add_action( 'in_plugin_update_message-' . plugin_basename( $this->file ), array( $this, 'plugin_row_license_missing' ), 10, 2 );
        }

        /**
         * Auto updater
         *
         * @access  private
         * @return  void
         */
        public function auto_updater() {
            $args = array(
                'version'        => $this->version,
                'license'        => $this->license,
                'author'         => $this->author,
                'wp_override'    => true,
                'beta'           => edd_extension_has_beta_support( $this->item_shortname ),
            );

            $args['item_name'] = $this->item_shortname;

            // TODO: Temporal fix for issue https://github.com/easydigitaldownloads/EDD-Software-Licensing/issues/1099
            $license_details = uframework_get_option( $this->item_shortname . '-license', 'details' );

            if ( ! is_object( $license_details ) || 'valid' !== $license_details->license ) {
                $args['license'] = '';
            }

            // Setup the updater
            $edd_updater = new EDD_SL_Plugin_Updater(
                $this->api_url,
                $this->file,
                $args
            );
        }

        /**
         * Display help text at the top of the Licenses tag
         *
         * @access  public
         * @since   2.5
         * @param   string   $active_tab
         * @return  void
         */
        public function license_help_text( $active_tab = '' ) {

            static $has_ran;

            if( 'licenses' !== $active_tab ) {
                return;
            }

            if( ! empty( $has_ran ) ) {
                return;
            }

            echo '<p>' . sprintf(
                    __( 'Enter your extension license keys here to receive updates for purchased extensions. If your license key has expired, please <a href="%s" target="_blank">renew your license</a>.', 'sellcomet' ),
                    'http://docs.easydigitaldownloads.com/article/1000-license-renewal'
                ) . '</p>';

            $has_ran = true;

        }

        /**
         * Activate the license key
         *
         * @access  public
         * @return  void
         */
        public function activate_license() {
            if ( ! isset( $_REQUEST[ $this->item_shortname . '-license-nonce' ] ) || ! wp_verify_nonce( $_REQUEST[ $this->item_shortname . '-license-nonce'], $this->item_shortname . '-license-nonce' ) ) {
                return;
            }

            if ( ! current_user_can( 'manage_shop_settings' ) ) {
                return;
            }

            if ( empty( $_POST[ $this->item_shortname . '-license-key'] ) ) {

                uframework_delete_option( $this->item_shortname . '-license', 'details' );

                return;

            }

            foreach ( $_POST as $key => $value ) {
                if( false !== strpos( $key, 'license_key_deactivate' ) ) {
                    // Don't activate a key when deactivating a different key
                    return;
                }
            }

            $details = uframework_get_option( $this->item_shortname . '-license', 'details' );

            if ( is_object( $details ) && 'valid' === $details->license ) {
                return;
            }

            $license = sanitize_text_field( $_REQUEST[ $this->item_shortname . '-license-key'] );

            if( empty( $license ) ) {
                return;
            }

            // Data to send to the API
            $api_params = array(
                'edd_action' => 'activate_license',
                'license'    => $license,
                'item_name'  => urlencode( $this->item_shortname ),
                'url'        => home_url()
            );

            // Call the API
            $response = wp_remote_post(
                $this->api_url,
                array(
                    'timeout'   => 15,
                    'sslverify' => false,
                    'body'      => $api_params
                )
            );

            // Make sure there are no errors
            if ( is_wp_error( $response ) ) {
                // Return license data
                return;
            }

            // Tell WordPress to look for updates
            set_site_transient( 'update_plugins', null );

            // Decode license data
            $license_data = json_decode( wp_remote_retrieve_body( $response ) );

            uframework_update_option( $this->item_shortname . '-license', 'key', $license );
            uframework_update_option( $this->item_shortname . '-license', 'details', $license_data );

            // Return license data
            wp_send_json( $license_data );
            wp_die();

        }

        /**
         * Deactivate the license key
         *
         * @access  public
         * @return  void
         */
        public function deactivate_license() {
            if( ! isset( $_REQUEST[ $this->item_shortname . '-license-nonce' ] ) || ! wp_verify_nonce( $_REQUEST[ $this->item_shortname . '-license-nonce' ], $this->item_shortname . '-license-nonce' ) ) {
                return;
            }

            if( ! current_user_can( 'manage_shop_settings' ) ) {
                return;
            }

            // Data to send to the API
            $api_params = array(
                'edd_action' => 'deactivate_license',
                'license'    => $this->license,
                'item_name'  => urlencode( $this->item_shortname ),
                'url'        => home_url()
            );

            // Call the API
            $response = wp_remote_post(
                $this->api_url,
                array(
                    'timeout'   => 15,
                    'sslverify' => false,
                    'body'      => $api_params
                )
            );

            // Make sure there are no errors
            if ( is_wp_error( $response ) ) {
                return;
            }

            // Decode the license data
            $license_data = json_decode( wp_remote_retrieve_body( $response ) );

            uframework_delete_option( $this->item_shortname . '-license', 'key' );
            uframework_delete_option( $this->item_shortname . '-license', 'details' );

            // Return license data
            wp_send_json( $license_data );
            wp_die();

        }

        /**
         * Check if license key is valid once per week
         *
         * @access  public
         * @since   2.5
         * @return  void
         */
        public function weekly_license_check() {
            if ( empty( $this->license ) ) {
                return;
            }

            // data to send in our API request
            $api_params = array(
                'edd_action'=> 'check_license',
                'license' 	=> $this->license,
                'item_name' => urlencode( $this->item_shortname ),
                'url'       => home_url()
            );

            // Call the API
            $response = wp_remote_post(
                $this->api_url,
                array(
                    'timeout'   => 15,
                    'sslverify' => false,
                    'body'      => $api_params
                )
            );

            // make sure the response came back okay
            if ( is_wp_error( $response ) ) {
                return false;
            }

            $license_data = json_decode( wp_remote_retrieve_body( $response ) );

            uframework_update_option( $this->item_shortname . '-license', 'details', $license_data );

        }

        /**
         * Admin notices for errors
         *
         * @access  public
         * @return  void
         */
        public function notices() {

            static $showed_invalid_message;

            if( empty( $this->license ) ) {
                return;
            }

            if( ! current_user_can( 'manage_shop_settings' ) ) {
                return;
            }

            if( isset( $_GET['page'] ) && $_GET['page'] == 'sellcomet' ) {
                return;
            }

            $messages = array();

            $license = uframework_get_option( $this->item_shortname . '-license', 'details' );

            if( ( ! is_object( $license ) || 'valid' !== $license->license ) && empty( $showed_invalid_message ) ) {

                    $messages[] = sprintf(
                        __( 'Please <a href="%s">register</a> <strong>%s</strong> with a valid license key to get access to updates and support.', 'sellcomet' ),
                        admin_url( 'admin.php?page=sellcomet' ),
                        $this->item_name
                    );

                    $showed_invalid_message = true;

            }

            if( ! empty( $messages ) ) {

                foreach( $messages as $message ) {

                    echo '<div class="error">';
                    echo '<p>' . $message . '</p>';
                    echo '</div>';

                }

            }

        }

        /**
         * Displays message inline on plugin row that the license key is missing
         *
         * @access  public
         * @since   2.5
         * @return  void
         */
        public function plugin_row_license_missing( $plugin_data, $version_info ) {

            static $showed_imissing_key_message;

            $license = uframework_get_option( $this->item_shortname . '-license', 'details' );

            if ( ( ! is_object( $license ) || 'valid' !== $license->license ) && empty( $showed_imissing_key_message[ $this->item_shortname ] ) ) {

                echo '&nbsp;<strong><a href="' . esc_url( admin_url( 'admin.php?page=sellcomet' ) ) . '">' . __( 'Enter valid license key for automatic updates.', 'sellcomet' ) . '</a></strong>';
                $showed_imissing_key_message[ $this->item_shortname ] = true;
            }

        }
    }

endif; // end class_exists check
