<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Our main plugin class
 */
class CF7_To_WP {

	/**
	 * The single instance of cf7_to_wp.
	 * @var 	object
	 */
	private static $_instance = null;

	/**
	 * Settings class object
	 * @var     object
	 */
	public $settings = null;

	/**
	 * The version number.
	 * @var     string
	 */
	public $_version;

	/**
	 * The token.
	 * @var     string
	 */
	public $_token;

	/**
	 * The main plugin file.
	 * @var     string
	 */
	public $file;

	/**
	 * The main plugin directory.
	 * @var     string
	 */
	public $dir;

	/**
	 * The plugin assets directory.
	 * @var     string
	 */
	public $assets_dir;

	/**
	 * The plugin assets URL.
	 * @var     string
	 */
	public $assets_url;

	/**
	 * Our post type slug.
	 *
	 * @var string
	 */
	private $post_type = 'cf7_form_messages';

	/**
	 * Constructor function.
	 * @access  public
	 */
	public function __construct ( $file = '', $version = '0.1' ) {
		$this->_version = $version;
		$this->_token   = 'cf7_to_wp';

		// Load plugin environment variables
		$this->file = $file;
		$this->dir = dirname( $this->file );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		// Handle localization
		$this->load_plugin_textdomain();
		add_action( 'init', array( $this, 'load_localization' ), 0 );
	}

	/**
	 * Initialize all the things!
	 */
	public function init() {
		// Register Messages post type.
		add_action( 'init', array( $this, 'register_form_msg_post_type' ) );
		add_filter( 'add_menu_classes', array( $this, 'menu_msg_form_bubble' ) );
		add_filter( 'post_row_actions', array( $this, 'action_row_for_msg_posts' ), 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_mark_form_message_as_read' ] );

		// Hook into CF7 actions.
		add_filter( 'wpcf7_editor_panels', array( $this, 'add_cf7_panel' ) ) ;
		add_action( 'wpcf7_after_save', array( $this, 'save_cf7_data' ), 50, 1 );
		add_action( 'wpcf7_mail_sent', array( $this, 'create_post_on_form_submission' ), 50, 1 );
		add_action( 'wpcf7_mail_failed', array( $this, 'create_post_on_form_submission' ), 50, 1 );
	}


	/**
	 * Load plugin localisation
	 */
	public function load_localization () {
		load_plugin_textdomain( 'cf7_to_wp', false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
	}

	/**
	 * Load plugin textdomain
	 */
	public function load_plugin_textdomain () {
		$domain = 'cf7_to_wp';

		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
	}

	/**
	 * Register our post type to store messages.
	 */
	public function register_form_msg_post_type() {
		register_post_type(
			$this->post_type,
			array(
				'labels' => array(
					'name'          => __( 'Messages', 'cf7_to_wp' ),
					'singular_name' => __( 'Message', 'cf7_to_wp' ),
					'add_new'       => __( 'Add New', 'cf7_to_wp' ),
					'add_new_item'  => __( 'Add new message', 'cf7_to_wp' ),
					'edit'          => __( 'Edit', 'cf7_to_wp' ),
				),
				'menu_position' => 32,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'public'        => false,
				'menu_icon'     => 'dashicons-email',
				'supports'      => array(
					'title',
					'editor',
				),
			)
		);
	}

	/**
	 * Add bubble to admin menu
	 *
	 * @param array $menu
	 * @return array $menu
	 */
	public function menu_msg_form_bubble( $menu ) {
		$form_messages_count = wp_count_posts( $this->post_type );
		$pending_count       = $form_messages_count->draft + $form_messages_count->pending;

		foreach ( $menu as $menu_key => $menu_data ) {
			if ( "edit.php?post_type={$this->post_type}" !== $menu_data[2] )
				continue;

			$menu[$menu_key][0] .= " <span class='update-plugins count-$pending_count'><span class='plugin-count'>" . number_format_i18n( $pending_count ) . '</span></span>';
		}

		return $menu;
	}

	/**
	 * Add "Mark as read" action for our post type
	 *
	 * @param array $actions
	 * @param WP_Post $post
	 * @return array $actions
	 */
	public function action_row_for_msg_posts( $actions, $post ) {
		if ( $post->post_type === $this->post_type && $post->post_status !== 'publish' ) {
			$actions['mark_as_read'] = sprintf(
				'<a href="%s" class="aria-button-if-js" aria-label="%s">%s</a>',
				wp_nonce_url( "edit.php?post_type={$this->post_type}&action=mark_as_read&message_id={$post->ID}", "mark_message_as_read_{$post->ID}" ),
				esc_attr( __( 'Mark as read', 'cf7_to_wp' ) ),
				__( 'Mark as read', 'cf7_to_wp' )
			);
		}

		return $actions;
	}

	/**
	 * Mark form message as read
	 */
	public function maybe_mark_form_message_as_read() {
		if ( isset( $_GET['action'] ) && $_GET['action'] == 'mark_as_read' && isset( $_GET['message_id'] ) ) {
			$message_id = (int) $_GET['message_id'];

			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], "mark_message_as_read_{$message_id}" ) ) {
				$updated_post = wp_update_post(
					array(
						'ID'          => $message_id,
						'post_status' => 'publish',
					)
				);

				wp_redirect( wp_get_referer() );
				exit();
			}
		}
	}

	/**
	 * Add new panel to CF7 form settings
	 *
	 * @param array $panels
	 * @return array
	 */
	public function add_cf7_panel( $panels ) {
		$panels['cf7-to-wp'] = array(
			'title' => __( 'Save messages', 'cf7_to_wp' ),
			'callback' => array( $this, 'cf7_to_wp_form_metabox' ),
		);

		return $panels;
	}

	/**
	 * Output the content of our panel/metabox
	 *
	 * @param WPCF7_ContactForm $post CF7 object
	 */
	public function cf7_to_wp_form_metabox( $post ) {
		$id      = $post->id();
		$cf7towp = get_post_meta( $id, '_cf7towp', true );
		$cf7towp = wp_parse_args(
			$cf7towp,
			array(
				'active'  => 0,
				'title'   => '',
				'content' => '',
			)
		); ?>

		<p style="margin-bottom:1em; font-size:1.25em;">
			<?php _e('If enabled, this addon will automagically save this form submissions into a new WordPress "Messages" post.', 'cf7_to_wp' ); ?>
		</p>

		<div class="mail-field" style="margin-bottom:1em;">
			<label for="cf7towp-active">
				<input type="checkbox" id="cf7towp-active" name="wpcf7-cf7towp-active" value="1" <?php checked($cf7towp['active'], 1); ?> />
				<strong><?php echo esc_html( __( 'Should we save this form submissions to WordPress posts ?', 'cf7_to_wp' ) ); ?></strong>
			</label>
		</div>

		<div class="pseudo-hr"></div>

		<div class="mail-field">
			<p class="description">
				<label for="cf7towp-title"><?php echo esc_html( __( 'Post title', 'cf7_to_wp' ) ); ?></label>
				<input type="text" id="cf7towp-title" name="wpcf7-cf7towp-title" class="large-text" value="<?php echo esc_attr( $cf7towp['title'] ); ?>" />
			</p>
		</div>

		<div class="mail-field">
			<p class="description">
				<label for="cf7towp-content"><?php echo esc_html( __( 'Post content', 'cf7_to_wp' ) ); ?></label>
				<textarea id="cf7towp-content" name="wpcf7-cf7towp-content" cols="100" rows="10" class="large-text"><?php echo esc_attr( $cf7towp['content'] ); ?></textarea>
			</p>
		</div>

		<hr>

		<p class="description" style="margin-top:.5em;">
			<span style="float:left; width:60%;">
				<?php _e('Use the usual CF7 [tags] to populate the post title and post content.', 'cf7_to_wp' ); ?>
			</span>
			<span style="text-align:right; float:right; width:40%;">
				<?php 
				$credits_link = '<a target="_blank" href="https://saika.li">Pierre Saïkali</a>';
				printf( __('A Contact Form 7 addon by %1$s', 'cf7_to_wp' ), $credits_link ); 
				?>
			</span>
		</p>

		<hr>
	<?php }

	/**
	 * Save metabox/tab data when CF7 form settings page is saved.
	 *
	 * @param WPCF7_ContactForm $contact_form
	 */
	public function save_cf7_data( $contact_form ) {
		$id                = $contact_form->id();
		$cf7towp           = array();
		$cf7towp['active'] = ( ! empty( $_POST['wpcf7-cf7towp-active'] ) );

		if ( isset( $_POST['wpcf7-cf7towp-title'] ) ) {
			$cf7towp['title'] = sanitize_text_field( $_POST['wpcf7-cf7towp-title'] );
		}

		if ( isset( $_POST['wpcf7-cf7towp-content'] ) ) {
			$cf7towp['content'] = wp_kses_post( $_POST['wpcf7-cf7towp-content'] );
		}

		update_post_meta( $id, '_cf7towp', $cf7towp );
	}

	/**
	 * Create a Messages post when form is submitted
	 *
	 * @param WPCF7_ContactForm $contact_form
	 */
	public function create_post_on_form_submission( $contact_form ) {
		$form_post    = $contact_form->id();
		$cf7towp_data = get_post_meta( $form_post, '_cf7towp', true );

		if ( $cf7towp_data['active'] === true ) {
			$submission = WPCF7_Submission::get_instance();

			if ( $submission ) {
				$meta         = array();
				$meta['ip']   = $submission->get_meta( 'remote_ip' );
				$meta['ua']   = $submission->get_meta( 'user_agent' );
				$meta['url']  = $submission->get_meta( 'url' );
				$meta['date'] =  date_i18n( get_option( 'date_format' ), $submission->get_meta( 'timestamp' ));
				$meta['time'] =  date_i18n( get_option( 'time_format' ), $submission->get_meta( 'timestamp' ));
			}

			$post_title_template   = $cf7towp_data['title'];
			$post_content_template = $cf7towp_data['content'];

			$post_title = wpcf7_mail_replace_tags(
				$post_title_template,
				array(
					'html' => true,
					'exclude_blank' => true,
				)
			);

			$post_content = wpcf7_mail_replace_tags(
				$post_content_template,
				array(
					'html' => true,
					'exclude_blank' => true,
				)
			);

			$new_form_msg = wp_insert_post(
				array(
					'post_type'    => $this->post_type,
					'post_title'   => $post_title,
					'post_content' => $post_content,
				)
			);

			if ( $submission ) {
				update_post_meta( $new_form_msg, 'cf7towp_meta', $meta );
			}
		}
	}

	/**
	 * Main cf7_to_wp singleton instance
	 *
	 * Ensures only one instance of cf7_to_wp is loaded or can be loaded.
	 *
	 * @static
	 * @see cf7_to_wp()
	 * @return Main cf7_to_wp instance
	 */
	public static function instance ( $file = '', $version = '0.1' ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $file, $version );
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 */
	public function __clone () {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ), $this->_version );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 */
	public function __wakeup () {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ), $this->_version );
	}
}
