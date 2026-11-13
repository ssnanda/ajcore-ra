<?php
/**
 * Shared SOSNC flyers (one shared file per flyer, auto-shared with Registered Agent / ncllcagents.com customers).
 *
 * Split out of Gmail Intake when that moved back to AJCore: the /ajcore/v1/gmail-intake/flyers* URLs, the daily
 * cron hook (ajcore_ra_flyers_auto_share) and the one-time "Articles of Organization" fold are unchanged. The card
 * is drawn on AJCore's Gmail Intake tab through 'ajcore_gmail_intake_tab_after_connection'.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Flyers {

	const FLYER_CRON = 'ajcore_ra_flyers_auto_share';

	/** @var array<string,callable> */
	private $kit;

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_action( 'ajcore_gmail_intake_tab_after_connection', array( __CLASS__, 'render_tab_card' ) );
		add_filter( 'ajcore_gmail_intake_skip_category', array( __CLASS__, 'skip_category' ), 10, 2 );
		add_filter( 'ajcore_gmail_intake_group_labels', array( __CLASS__, 'group_labels' ) );
		// New RA / ncllcagents.com customers get the shared flyers automatically (daily; adds only, never removes).
		add_action( 'admin_init', array( __CLASS__, 'fold_aoo_category' ) );
		add_action( self::FLYER_CRON, array( __CLASS__, 'flyers_auto_share' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::FLYER_CRON ) ) {
				wp_schedule_event( time() + 600, 'daily', self::FLYER_CRON );
			}
		} );
	}

	public function __construct() {
		$this->kit = class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public static function render_tab_card() {
		echo '<details class="ajforms-settings-card" style="margin-bottom:14px"><summary style="cursor:pointer;font-weight:600;padding:8px 0">' . esc_html__( 'Shared flyers', 'ajcore-ra' ) . '</summary>';
		self::instance()->render_flyers_card();
		echo '</details>';
	}

	/** Flyers are never imported from mail. */
	public static function skip_category( $skip, $category ) {
		return $skip || 'sosnc flyers' === strtolower( (string) $category );
	}

	public static function group_labels( $labels ) {
		$labels['extra'] = __( 'Important Flyers from Secretary of State', 'ajcore-ra' );
		return $labels;
	}

	/** One-time: existing files filed as "Articles of Organization" move into "Formation Documents". */
	public static function fold_aoo_category() {
		if ( get_option( 'ajcore_ra_aoo_folded' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aj_portal_files', array( 'category' => 'Formation Documents' ), array( 'category' => 'Articles of Organization' ) );
		update_option( 'ajcore_ra_aoo_folded', 1, false );
	}

	public static function flyers_auto_share() {
		$self = self::instance();
		foreach ( array_keys( $self->flyer_groups() ) as $key ) {
			$self->flyers_share_audience( $key, true );
		}
	}

	public function register_routes() {
		$ns    = AJCore_Extensions::rest_namespace();
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( $ns, '/gmail-intake/flyers', array( 'methods' => 'GET', 'callback' => array( $this, 'flyers_list_route' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/flyers/replace', array( 'methods' => 'POST', 'callback' => array( $this, 'flyers_replace_route' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/flyers/share-all', array( 'methods' => 'POST', 'callback' => array( $this, 'flyers_share_all_route' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/gmail-intake/flyers/merge', array( 'methods' => 'POST', 'callback' => array( $this, 'flyers_merge_route' ), 'permission_callback' => $admin ) );
	}

	// ── AJCore helpers (forwarded) ──────────────────────────────────────────────

	private function get_pdb() {
		return $this->kit['portal_db']();
	}

	private function table_exists( $db, $table ) {
		return $this->kit['table_exists']( $db, $table );
	}

	private function get_portal_stripe_customers_table() {
		return $this->kit['portal_table']( 'aj_portal_stripe_customers' );
	}

	/** The Shared flyers card (own collapsible section on the Gmail Intake tab). */
	public function render_flyers_card() {
		?>
		<div class="ajcore-gi-flyers" style="padding:4px 0;max-width:760px;">
			<strong><?php esc_html_e( 'Shared flyers', 'ajcore-ra' ); ?></strong>
			<span style="color:#64748b;font-size:12px;"> — <?php esc_html_e( 'one file per flyer, shared with customers. Not imported from mail.', 'ajcore-ra' ); ?></span>
			<div id="ajcore-gi-flyers-rows" style="margin-top:8px;font-size:13px;"></div>
			<p style="margin:8px 0 0;">
				<button type="button" class="button" id="ajcore-gi-flyers-preview"><?php esc_html_e( 'Preview merge of old duplicates', 'ajcore-ra' ); ?></button>
				<button type="button" class="button" id="ajcore-gi-flyers-apply" disabled><?php esc_html_e( 'Apply merge', 'ajcore-ra' ); ?></button>
			</p>
			<div id="ajcore-gi-flyers-out" style="font-size:13px;margin-top:6px;"></div>
		</div>
		<script>
		(function () {
			var base = <?php echo wp_json_encode( rest_url( 'ajcore/v1/gmail-intake/flyers' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var rows = document.getElementById( 'ajcore-gi-flyers-rows' ), out = document.getElementById( 'ajcore-gi-flyers-out' );
			var pv = document.getElementById( 'ajcore-gi-flyers-preview' ), ap = document.getElementById( 'ajcore-gi-flyers-apply' );
			function api( path, opts ) { opts = opts || {}; opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} ); return fetch( base + path, opts ).then( function ( r ) { return r.json(); } ); }
			function el( tag, text ) { var e = document.createElement( tag ); if ( text ) { e.textContent = text; } return e; }
			function load() {
				api( '' ).then( function ( d ) {
					rows.innerHTML = '';
					( d.flyers || [] ).forEach( function ( f ) {
						var r = el( 'div' ); r.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:6px 0;border-top:1px solid #f1f5f9';
						var t = el( 'span', f.label + ( f.file_id ? ' — shared with ' + f.shared + ', added ' + ( f.added || '' ).slice( 0, 10 ) + ( f.duplicates ? ' (' + f.duplicates + ' old duplicate(s))' : '' ) : ' — no file yet' ) ); t.style.flex = '1';
						var inp = el( 'input' ); inp.type = 'file'; inp.accept = 'application/pdf,.pdf'; inp.style.display = 'none';
						var rb = el( 'button', f.file_id ? <?php echo wp_json_encode( __( 'Replace file', 'ajcore-ra' ) ); ?> : <?php echo wp_json_encode( __( 'Upload file', 'ajcore-ra' ) ); ?> ); rb.className = 'button button-small'; rb.type = 'button';
						rb.addEventListener( 'click', function () { inp.click(); } );
						inp.addEventListener( 'change', function () {
							if ( ! inp.files[0] ) { return; }
							var fd = new FormData(); fd.append( 'key', f.key ); fd.append( 'file', inp.files[0] );
							rb.disabled = true; rb.textContent = '…';
							api( '/replace', { method: 'POST', body: fd } ).then( function ( res ) { out.textContent = res.file_id ? <?php echo wp_json_encode( __( 'Updated. Customers who had the old flyer now have the new one.', 'ajcore-ra' ) ); ?> : ( res.message || 'Failed.' ); load(); } );
						} );
						r.appendChild( t ); r.appendChild( inp ); r.appendChild( rb );
						if ( f.file_id ) {
							var sb = el( 'button', <?php echo wp_json_encode( __( 'Share with RA / ncllcagents.com customers', 'ajcore-ra' ) ); ?> ); sb.className = 'button button-small'; sb.type = 'button';
							sb.addEventListener( 'click', function () {
								api( '/share-all?key=' + encodeURIComponent( f.key ), { method: 'POST' } ).then( function ( p ) {
									if ( ! p.new_shares && p.new_shares !== 0 ) { out.textContent = p.message || 'Failed.'; return; }
									if ( ! p.new_shares ) { out.textContent = <?php echo wp_json_encode( __( 'Every Registered Agent / ncllcagents.com customer already has this flyer.', 'ajcore-ra' ) ); ?>; return; }
									if ( window.confirm( <?php echo wp_json_encode( __( 'Share this flyer with', 'ajcore-ra' ) ); ?> + ' ' + p.new_shares + ' ' + <?php echo wp_json_encode( __( 'more customer(s)?', 'ajcore-ra' ) ); ?> ) ) { api( '/share-all?apply=1&key=' + encodeURIComponent( f.key ), { method: 'POST' } ).then( function ( q ) { out.textContent = '✓ ' + q.new_shares + ' ' + <?php echo wp_json_encode( __( 'customer(s) added.', 'ajcore-ra' ) ); ?>; load(); } ); }
								} );
							} );
							r.appendChild( sb );
						}
						rows.appendChild( r );
					} );
				} );
			}
			function run( apply ) {
				out.textContent = '…';
				api( '/merge' + ( apply ? '?apply=1' : '' ), { method: 'POST' } ).then( function ( d ) {
					if ( ! d.flyers ) { out.textContent = d.message || 'Failed.'; return; }
					var dupes = 0;
					out.innerHTML = d.flyers.map( function ( f ) { dupes += f.duplicates; return '<div>' + ( apply ? '✓ ' : '' ) + f.keep_title + ': ' + f.duplicates + ' duplicate(s) ' + ( apply ? 'archived' : 'to archive' ) + ', ' + f.new_shares + ' customer(s) added to the shared copy</div>'; } ).join( '' ) || <?php echo wp_json_encode( __( 'No flyer files found.', 'ajcore-ra' ) ); ?>;
					ap.disabled = apply || ! dupes; if ( apply ) { load(); }
				} ).catch( function () { out.textContent = 'Request failed.'; } );
			}
			pv.addEventListener( 'click', function () { run( false ); } );
			ap.addEventListener( 'click', function () { if ( window.confirm( <?php echo wp_json_encode( __( 'Archive the duplicate flyer files and share the kept copy with those customers?', 'ajcore-ra' ) ); ?> ) ) { run( true ); } } );
			load();
		})();
		</script>
		<?php
	}

	// ── Shared flyers: one file per flyer, shared with every customer ─────────────

	/** 'rural-rise' etc. for the known SOSNC flyers, '' for anything else. */
	private function flyer_key( $text ) {
		$t = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $text ) );
		foreach ( array( 'rural rise' => 'rural-rise', 'misleading mailings' => 'misleading-mailings', 'notice to employers' => 'notice-to-employers' ) as $needle => $key ) {
			if ( false !== strpos( $t, $needle ) ) {
				return $key;
			}
		}
		return '';
	}

	/** Active (not archived) SOSNC Flyers files grouped by flyer key, oldest first. */
	private function flyer_groups() {
		global $wpdb;
		$files  = $wpdb->prefix . 'aj_portal_files';
		$rows   = $wpdb->get_results( "SELECT id, attachment_id, title, created_at FROM `{$files}` WHERE LOWER(category) = 'sosnc flyers' AND ( status <> 'archived' OR status IS NULL ) ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$key = $this->flyer_key( $row->title );
			if ( '' !== $key ) {
				$groups[ $key ][] = $row;
			}
		}
		return $groups;
	}

	/** The one shared copy for a flyer (the oldest active file), or 0 if none exists yet. */
	private function flyer_canonical_id( $key ) {
		$groups = $this->flyer_groups();
		return isset( $groups[ $key ][0] ) ? (int) $groups[ $key ][0]->id : 0;
	}

	/** Adds a share row for the customer on a file; no-op if they already have it. */
	private function flyer_share_file( $file_id, $email, $user_id ) {
		global $wpdb;
		$users = $wpdb->prefix . 'aj_portal_file_users';
		$email = strtolower( sanitize_email( (string) $email ) );
		if ( '' === $email ) {
			return false;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$users}` WHERE file_id = %d AND ( LOWER(user_email) = %s OR ( %d > 0 AND user_id = %d ) ) LIMIT 1", (int) $file_id, $email, (int) $user_id, (int) $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			return true;
		}
		return false !== $wpdb->insert( $users, array( 'file_id' => (int) $file_id, 'user_id' => (int) $user_id, 'user_email' => $email, 'created_at' => current_time( 'mysql' ) ), array( '%d', '%d', '%s', '%s' ) );
	}

	private function flyer_customer_user_id( $stripe_customer_id ) {
		global $wpdb;
		$map = $wpdb->prefix . 'aj_auth_user_mappings';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM `{$map}` WHERE stripe_customer_id = %s LIMIT 1", (string) $stripe_customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Merge duplicate flyer files: per flyer, keep the oldest as the single shared copy, share it with
	 * everyone who had a duplicate, and archive the duplicates (never deleted; the Archived tab keeps them).
	 * $apply false = plan only.
	 */
	private function flyers_merge( $apply ) {
		global $wpdb;
		$files = $wpdb->prefix . 'aj_portal_files';
		$users = $wpdb->prefix . 'aj_portal_file_users';
		$out   = array();
		foreach ( $this->flyer_groups() as $key => $group ) {
			$keep  = $group[0];
			$dupes = array_slice( $group, 1 );
			$have  = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, LOWER(user_email) AS email FROM `{$users}` WHERE file_id = %d", (int) $keep->id ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$have[ $u->email ] = true;
			}
			$to_share = array();
			foreach ( $dupes as $d ) {
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, LOWER(user_email) AS email FROM `{$users}` WHERE file_id = %d", (int) $d->id ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( '' !== $u->email && empty( $have[ $u->email ] ) ) {
						$have[ $u->email ]     = true;
						$to_share[ $u->email ] = (int) $u->user_id;
					}
				}
			}
			if ( $apply ) {
				foreach ( $to_share as $email => $uid ) {
					$this->flyer_share_file( $keep->id, $email, $uid );
				}
				foreach ( $dupes as $d ) {
					$wpdb->update( $files, array( 'status' => 'archived', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $d->id ), array( '%s', '%s' ), array( '%d' ) );
				}
			}
			$out[] = array(
				'flyer'      => $key,
				'keep_id'    => (int) $keep->id,
				'keep_title' => (string) $keep->title,
				'duplicates' => count( $dupes ),
				'new_shares' => count( $to_share ),
			);
		}
		return $out;
	}

	private function flyer_titles() {
		return array( 'rural-rise' => 'Flyer-Rural-Rise', 'misleading-mailings' => 'Flyer-Misleading-Mailings-Targeting-New-Companies', 'notice-to-employers' => 'Flyer-Important-Notice-to-Employers' );
	}

	private function flyer_share_count( $file_id ) {
		global $wpdb;
		$users = $wpdb->prefix . 'aj_portal_file_users';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$users}` WHERE file_id = %d", (int) $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function flyers_list_route() {
		$groups = $this->flyer_groups();
		$out    = array();
		foreach ( $this->flyer_titles() as $key => $title ) {
			$cur   = isset( $groups[ $key ][0] ) ? $groups[ $key ][0] : null;
			$out[] = array(
				'key'        => $key,
				'label'      => $title,
				'file_id'    => $cur ? (int) $cur->id : 0,
				'added'      => $cur ? (string) $cur->created_at : '',
				'shared'     => $cur ? $this->flyer_share_count( $cur->id ) : 0,
				'duplicates' => $cur ? count( $groups[ $key ] ) - 1 : 0,
			);
		}
		return rest_ensure_response( array( 'flyers' => $out ) );
	}

	/** Replace a flyer with a newer PDF: the new file gets the same shares, the old copy is archived. */
	public function flyers_replace_route( WP_REST_Request $request ) {
		global $wpdb;
		$key    = sanitize_key( (string) $request->get_param( 'key' ) );
		$titles = $this->flyer_titles();
		if ( ! isset( $titles[ $key ] ) ) {
			return new WP_Error( 'bad_flyer', __( 'Unknown flyer.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$file = $request->get_file_params();
		$file = isset( $file['file'] ) ? $file['file'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! preg_match( '/\.pdf$/i', (string) $file['name'] ) ) {
			return new WP_Error( 'no_file', __( 'Choose a PDF file.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$title    = $titles[ $key ];
		$uploaded = wp_handle_upload( array( 'name' => $title . '.pdf', 'type' => 'application/pdf', 'tmp_name' => $file['tmp_name'], 'error' => 0, 'size' => (int) $file['size'] ), array( 'test_form' => false ) );
		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'upload_failed', (string) $uploaded['error'], array( 'status' => 500 ) );
		}
		$attachment_id = wp_insert_attachment( array( 'post_title' => $title, 'post_content' => '', 'post_status' => 'inherit', 'post_mime_type' => $uploaded['type'] ), $uploaded['file'], 0, true );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		$files = $wpdb->prefix . 'aj_portal_files';
		$users = $wpdb->prefix . 'aj_portal_file_users';
		$wpdb->insert( $files, array( 'attachment_id' => (int) $attachment_id, 'title' => $title, 'category' => 'SOSNC Flyers', 'description' => '', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
		$new_id = (int) $wpdb->insert_id;
		if ( ! $new_id ) {
			return new WP_Error( 'create_failed', __( 'Could not create the file record.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		if ( class_exists( 'AJCore_Storage_Service' ) ) {
			AJCore_Storage_Service::migrate_attachment_ids( array( (int) $attachment_id ) );
		}
		$moved = 0;
		$groups = $this->flyer_groups();
		foreach ( isset( $groups[ $key ] ) ? $groups[ $key ] : array() as $old ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, user_email FROM `{$users}` WHERE file_id = %d", (int) $old->id ) ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $this->flyer_share_file( $new_id, $u->user_email, (int) $u->user_id ) ) {
					$moved++;
				}
			}
			$wpdb->update( $files, array( 'status' => 'archived', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $old->id ), array( '%s', '%s' ), array( '%d' ) );
		}
		return rest_ensure_response( array( 'file_id' => $new_id, 'shared' => $this->flyer_share_count( $new_id ) ) );
	}

	/**
	 * Customers who should see the NC LLC Agents flyers: an active Registered Agent subscription, or a
	 * portal login assigned to ncllcagents.com. Returns a set of customer ids.
	 */
	private function flyer_audience_ids() {
		$ids = array();
		if ( class_exists( 'AJForms_Admin' ) ) {
			$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
			if ( method_exists( $admin, 'get_portal_core_subscription_product_counts' ) ) {
				$counts = $admin->get_portal_core_subscription_product_counts();
				foreach ( (array) ( $counts['registered_agent_subscription_customers'] ?? array() ) as $id ) {
					$ids[ (string) $id ] = true;
				}
			}
		}
		$pdb    = $this->get_pdb();
		$states = $pdb->prefix . 'aj_portal_customer_states';
		$sites  = $pdb->prefix . 'aj_shared_sites';
		if ( $this->table_exists( $pdb, $states ) && $this->table_exists( $pdb, $sites ) ) {
			$domain = apply_filters( 'ajcore_ra_flyer_site_domain', 'ncllcagents.com' );
			foreach ( (array) $pdb->get_col( $pdb->prepare( "SELECT cs.stripe_customer_id FROM `{$states}` cs INNER JOIN `{$sites}` s ON s.site_uuid = cs.site_uuid WHERE s.domain LIKE %s", '%' . $pdb->esc_like( $domain ) . '%' ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids[ (string) $id ] = true;
			}
		}
		return $ids;
	}

	/** Shares one flyer with the audience; returns the number of new shares (or WP_Error). apply=false only counts. */
	private function flyers_share_audience( $key, $apply ) {
		$fileid = $this->flyer_canonical_id( $key );
		if ( ! $fileid ) {
			return new WP_Error( 'no_flyer', __( 'Upload this flyer first.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$pdb       = $this->get_pdb();
		$local     = $pdb->prefix . 'aj_portal_local_customers';
		$customers = array_merge(
			(array) $pdb->get_results( "SELECT stripe_customer_id, email FROM {$this->get_portal_stripe_customers_table()} WHERE email <> ''" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(array) $pdb->get_results( "SELECT local_customer_id AS stripe_customer_id, email FROM `{$local}` WHERE email <> ''" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$audience = $this->flyer_audience_ids();
		if ( ! $audience ) {
			return new WP_Error( 'no_audience', __( 'No Registered Agent or ncllcagents.com customers found.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$customers = array_values( array_filter( $customers, function ( $c ) use ( $audience ) {
			return isset( $audience[ (string) $c->stripe_customer_id ] );
		} ) );
		global $wpdb;
		$users = $wpdb->prefix . 'aj_portal_file_users';
		$have  = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT LOWER(user_email) FROM `{$users}` WHERE file_id = %d", $fileid ) ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$have[ $e ] = true;
		}
		$added = 0;
		foreach ( $customers as $c ) {
			$email = strtolower( sanitize_email( (string) $c->email ) );
			if ( '' === $email || ! empty( $have[ $email ] ) ) {
				continue;
			}
			$have[ $email ] = true;
			if ( $apply ) {
				$this->flyer_share_file( $fileid, $email, $this->flyer_customer_user_id( $c->stripe_customer_id ) );
			}
			$added++;
		}
		return $added;
	}

	/** Share a flyer with the NC LLC Agents audience (Registered Agent customers or ncllcagents.com logins) — preview with apply=0. */
	public function flyers_share_all_route( WP_REST_Request $request ) {
		$key   = sanitize_key( (string) $request->get_param( 'key' ) );
		$apply = rest_sanitize_boolean( $request->get_param( 'apply' ) );
		$added = $this->flyers_share_audience( $key, $apply );
		if ( is_wp_error( $added ) ) {
			return $added;
		}
		return rest_ensure_response( array( 'applied' => $apply, 'new_shares' => $added ) );
	}

	public function flyers_merge_route( WP_REST_Request $request ) {
		$apply = rest_sanitize_boolean( $request->get_param( 'apply' ) );
		return rest_ensure_response( array( 'applied' => $apply, 'flyers' => $this->flyers_merge( $apply ) ) );
	}
}
