<?php
if ( !class_exists('SimpleUserAvatar_Admin') ) :

    /**
     * PHP class SimpleUserAvatar_Admin
     *
     * @since   2.8
     */
    class SimpleUserAvatar_Admin {

        /**
         * Properties
         *
         * @since   3.6
         */
        private $avatar_size              = 96;
        private $notice_months_expiration = 3;
        private $notice_enabled_pages     = [ 'users.php', 'profile.php', 'user-new.php' ];
        private $donation_permalink       = 'https://www.paypal.com/donate/?cmd=_donations&business=matteomanna87%40gmail%2ecom';
        private $plugin_public_permalink  = 'https://wordpress.org/plugins/simple-user-avatar/';


        public function __construct() {

            // Admin scripts
            add_action( 'admin_enqueue_scripts', [ $this, 'custom_admin_enqueue_scripts' ] );

            // HTML render profile fields
            add_action( 'show_user_profile', [ $this, 'render_custom_user_profile_fields' ] );
            add_action( 'edit_user_profile', [ $this, 'render_custom_user_profile_fields' ] );

            // Update profile fields
            add_action( 'personal_options_update', [ $this, 'update_custom_user_profile_fields' ] );
            add_action( 'edit_user_profile_update', [ $this, 'update_custom_user_profile_fields' ] );

            // Delete user meta when attachment is deleted
            add_action( 'delete_attachment', [ $this, 'custom_delete_attachment' ] );

            // HTML render of notice
            add_action( 'admin_notices', [ $this, 'custom_admin_notice' ] );

            // Post call to close notice
            add_action( 'admin_post_close_notice', [ $this, 'post_close_notice' ] );

        }


        public static function init() {

            new self;

        }


        /**
         * Load CSS and JavaScript for wp-admin
         *
         * @since   1.0
         */
        public function custom_admin_enqueue_scripts() {

            // Get current user
            global $current_user;

            // Enqueue WordPress Media Library
            wp_enqueue_media();

            // CSS for wp-admin
            wp_enqueue_style( 'sua', plugins_url( '/css/style.css', __FILE__ ), [], SUA_PLUGIN_VERSION, 'all' );

            // JavaScript for wp-admin
            wp_enqueue_script( 'sua', plugins_url( '/js/scripts.js', __FILE__ ), [ 'jquery' ], SUA_PLUGIN_VERSION, true );

            // Get default avatar URL by user_email
            $l10n = [
                'default_avatar_src'    => $this->get_default_avatar_url_by_email( $current_user->user_email, $this->avatar_size ),
                'default_avatar_srcset' => $this->get_default_avatar_url_by_email( $current_user->user_email, ( $this->avatar_size * 2 ) ) . ' 2x',
                'input_name'            => SUA_USER_META_KEY
            ];
            wp_localize_script( 'sua', 'sua_obj', $l10n );

        }


        /**
         * Default WordPress avatar URL by user email
         *
         * @since   2.8
         */
        private function get_default_avatar_url_by_email( $user_email = '', $size = 96 ) {

            // Check the email provided
            if ( empty($user_email) || !filter_var($user_email, FILTER_VALIDATE_EMAIL) ) {
                return null;
            }

            // Sanitize email and get md5
            $user_email     = sanitize_email( $user_email );
            $md5_user_email = md5( $user_email );

            // SSL Gravatar URL
            $url = 'https://secure.gravatar.com/avatar/' . $md5_user_email;

            // Add query args
            $url = add_query_arg( 's', $size, $url );
            $url = add_query_arg( 'd', 'mm', $url );
            $url = add_query_arg( 'r', 'g', $url );

            return esc_url( $url );

        }


        /**
         * Add table in user profile
         *
         * @since   1.0
         */
        public function render_custom_user_profile_fields( $user ) {

            // Get user meta
            $attachment_id = get_user_meta( $user->ID, SUA_USER_META_KEY, true );
            ?>
        
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th>
                            <label for="btn-media-add"><?php _e('Profile picture', 'simple-user-avatar'); ?></label>
                        </th>
                        <td>
                            <?php echo get_avatar( $user->ID, $this->avatar_size, '', $user->display_name, [ 'class' => 'sua-attachment-avatar' ] ); ?>
                            <p class="description <?php if ( !empty($attachment_id) ) echo 'hidden'; ?>" id="sua-attachment-description"><?php _e("You're seeing the default profile picture.", 'simple-user-avatar'); ?></p>
                            <p>
                                <button type="button" class="button" id="btn-media-add"><?php _e('Select', 'simple-user-avatar'); ?></button>
                                <button type="button" class="button <?php if ( empty($attachment_id) ) echo 'hidden'; ?>" id="btn-media-remove"><?php _e('Remove', 'simple-user-avatar'); ?></button>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Hidden attachment ID -->
            <input type="hidden" name="<?php echo SUA_USER_META_KEY; ?>" value="<?php echo $attachment_id; ?>" />

            <?php

        }


        /**
         * Update the user meta when user save
         *
         * @since   1.0
         */
        public function update_custom_user_profile_fields( $user_id ) {

            // If user don't have permissions
            if ( !current_user_can( 'edit_user', $user_id ) ) {
                return false;
            }

            // Delete old user meta
            delete_user_meta( $user_id, SUA_USER_META_KEY );

            // Validate POST data and, if exists, add it
            if ( isset($_POST[SUA_USER_META_KEY]) && is_numeric($_POST[SUA_USER_META_KEY]) ) {
                add_user_meta( $user_id, SUA_USER_META_KEY, (int)$_POST[SUA_USER_META_KEY] );
            }

            return true;

        }


        /**
         * Delete user meta when attachment is deleted
         *
         * @since   3.9
         */
        public function custom_delete_attachment( $post_id ) {

            global $wpdb;

            // Delete all user meta where deleted attachment post ID exists
            $wpdb->delete(
                $wpdb->usermeta,
                [
                    'meta_key'   => SUA_USER_META_KEY,
                    'meta_value' => (int)$post_id
                ],
                [
                    '%s',
                    '%d'
                ]
            );

        }


        /**
         * Admin notices:
         * 1. Error notice if transient not saved
         * 2. Donate for this plugin, if transient not exists or is expired
         *
         * @since   2.6
         */
        public function custom_admin_notice() {

            // Get Current user
            global $current_user, $pagenow;

            // Check if there is a GET error
            if ( isset( $_GET['error'] ) ) {

                // Notice error container
                $notice_error_container = '<div class="notice notice-error is-dismissible">%s</div>';
                
                // Switch errors
                switch( $_GET['error'] ) {

                    // Transient not saved
                    case 'sua_transient_not_set':
                        printf(
                            $notice_error_container,
                            __( '<p>An error occurred while <strong>saving the transient</strong>. Please make sure this website can save transients.</p>', 'simple-user-avatar' )
                        );
                        break;

                }
                
            }

            // Get the transient
            $notice_is_expired = get_transient( SUA_TRANSIENT_NAME );

            // Check the return of transient, if it's okay empty return
            if ( $notice_is_expired !== false && is_numeric($notice_is_expired) && $notice_is_expired == 1 ) {
                return;
            }

            // Show the notice, if the page is in private array
            if ( in_array( $pagenow, $this->notice_enabled_pages ) ) {

                // Set the nonce field
                $wp_nonce_field = wp_nonce_field( SUA_TRANSIENT_NAME, '_wpnonce', true, false );
                $wp_nonce_field = preg_replace( '/id=("|\').*?("|\')/', '', $wp_nonce_field );
                ?>

                <div class="notice notice-info">
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                        <p>
                            <?php
                            printf(
                                __( 'Dear <strong>%s</strong>,<br />thank you for using my plugin <a href="%s" title="Simple User Avatar" target="_blank" rel="noopener">Simple User Avatar</a>! To <strong>support</strong> the development, also in the future, I invite you to <strong>support me</strong>. Even a small amount, such as <strong>1$</strong> for one coffee &#x2615, will be greatly appreciated.<br />Best regards, Matteo.', 'simple-user-avatar' ),
                                sanitize_text_field( $current_user->display_name ),
                                esc_url( $this->plugin_public_permalink )
                            );
                            ?>
                        </p>
                        <p>
                            <a href="<?php echo esc_url( $this->donation_permalink ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php _e( 'Donate now', 'simple-user-avatar' ); ?></a>
                            <button type="submit" class="button"><?php printf( __('Hide for %d months', 'simple-user-avatar' ), $this->notice_months_expiration ); ?></button>
                        </p>
                        <input type="hidden" name="action" value="close_notice" />
                        <?php echo $wp_nonce_field; ?>
                    </form>
                </div>

                <?php

            }

        }


        /**
         * Set the transient
         * Add query arg if there is an error
         *
         * @since   2.6
         */
        public function post_close_notice() {

            // Set default redirect URL
            $redirect_url = !empty( $_POST['_wp_http_referer'] ) ? $_POST['_wp_http_referer'] : admin_url( 'users.php' ) ;

            // Verify nonce
            if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], SUA_TRANSIENT_NAME ) ) {

                // Transient settings
                $transient  = SUA_TRANSIENT_NAME;
                $value      = 1;
                $expiration = (86400 * 30) * $this->notice_months_expiration;

                // Set the transient but, if an error has occurred, add query arg at redirect URL
                if ( set_transient( $transient, $value, $expiration ) === false ) {
                    $redirect_url = add_query_arg( 'error', 'sua_transient_not_set', $redirect_url );
                }

            }

            // Safe redirect
            if ( wp_safe_redirect( esc_url( $redirect_url ) ) ) {
                exit;
            }

        }

    }

    add_action( 'plugins_loaded', [ 'SimpleUserAvatar_Admin', 'init' ] );

endif;
