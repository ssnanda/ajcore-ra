<?php
/**
 * Mailbox sub-pipelines under Pipeline: Invoices, Insurance & HOA (more can be added to self::pipelines()).
 *
 * Each one reads a Zoho shared mailbox (AJCore's Zoho login, the address is set per pipeline) with no filters (every message),
 * lists the emails by date, and lets staff move each through stages with a note. No parsing: the email itself is the record.
 * Items live in one table (aj_mail_items) keyed by pipeline + message id.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Mail_Pipelines {

	const CRON = 'ajcore_ra_mail_pipelines_pass';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** id => label, ops module key, stages (first = new, last = ignored). */
	public static function pipelines() {
		return array(
			'invoices'  => array( 'label' => 'Invoices', 'module' => 'invoices', 'stages' => array( 'new' => 'New', 'approved' => 'Approved', 'paid' => 'Paid', 'ignored' => 'Ignored' ) ),
			'insurance' => array( 'label' => 'Insurance & HOA', 'module' => 'insurance', 'stages' => array( 'new' => 'New', 'reviewed' => 'Reviewed', 'done' => 'Done', 'ignored' => 'Ignored' ) ),
		);
	}

	public static function init() {
		add_filter( 'ajcore_pipeline_views', array( __CLASS__, 'register_views' ) );
		add_filter( 'ajcore_ops_modules', array( __CLASS__, 'ops_modules' ) );
		add_filter( 'ajcore_ops_sections', array( __CLASS__, 'ops_sections' ) );
		add_filter( 'ajcore_ops_route_sections', array( __CLASS__, 'ops_route_sections' ) );
		add_filter( 'ajcore_ops_counts', array( __CLASS__, 'ops_counts' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_pass' ) );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + 1200, 'hourly', self::CRON );
			}
		} );
	}

	// ── registration with AJCore ────────────────────────────────────────────────

	public static function register_views( $views ) {
		foreach ( self::pipelines() as $id => $p ) {
			$views[ $id ] = array(
				'label'  => $p['label'],
				'badge'  => static function () use ( $id ) {
					return self::new_count( $id );
				},
				'render' => static function () use ( $id ) {
					self::instance()->render_view( $id );
				},
			);
		}
		return $views;
	}

	public static function ops_modules( $modules ) {
		$modules = (array) $modules;
		foreach ( self::pipelines() as $p ) {
			$modules[] = $p['module'];
		}
		return $modules;
	}

	public static function ops_sections( $sections ) {
		$sections = (array) $sections;
		foreach ( self::pipelines() as $p ) {
			$sections[ $p['module'] ] = $p['label'];
		}
		return $sections;
	}

	public static function ops_route_sections( $map ) {
		$map = (array) $map;
		foreach ( self::pipelines() as $p ) {
			$map[ $p['module'] ] = $p['module'];
		}
		return $map;
	}

	public static function ops_counts( $counts ) {
		try {
			foreach ( self::pipelines() as $id => $p ) {
				$n                       = self::new_count( $id );
				$counts[ $p['module'] ] = array( 'value' => $n, 'label' => 'New', 'alert' => $n > 0 );
			}
		} catch ( \Throwable $e ) {
			// Counts are optional.
		}
		return $counts;
	}

	public static function catalog( $catalog ) {
		$catalog   = (array) $catalog;
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/mail-pipelines/{pipeline}', 'auth' => 'Admin', 'purpose' => 'Items of a mailbox sub-pipeline (invoices, insurance): filters stage, q; stats per stage.', 'app' => 'OPS pipeline' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail-pipelines/{pipeline}/(process|stage|settings)', 'auth' => 'Admin', 'purpose' => 'Read the mailbox now; move items to a stage; save mailbox/enabled.', 'app' => 'OPS pipeline' );
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET|POST', 'path' => '/ops/mail-pipelines/{pipeline}/items/{id}', 'auth' => 'Admin', 'purpose' => 'One email with its text (fetched on first open); POST edits note/stage.', 'app' => 'OPS pipeline' );
		return $catalog;
	}

	public static function new_count( $id ) {
		$self = self::instance();
		if ( ! isset( self::pipelines()[ $id ] ) || ! $self->ensure_table() ) {
			return 0;
		}
		$pdb = $self->pdb();
		return (int) $pdb->get_var( $pdb->prepare( "SELECT COUNT(*) FROM `{$self->table()}` WHERE pipeline = %s AND stage = 'new'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── database ────────────────────────────────────────────────────────────────

	private function kit() {
		return class_exists( 'AJCore_Extensions' ) ? AJCore_Extensions::rest_toolkit() : array();
	}

	private function pdb() {
		$kit = $this->kit();
		return $kit['portal_db']();
	}

	private function table() {
		return $this->pdb()->prefix . 'aj_mail_items';
	}

	public function ensure_table() {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$pdb = $this->pdb();
		$kit = $this->kit();
		$tbl = $this->table();
		if ( $kit['table_exists']( $pdb, $tbl ) ) {
			return $done = true;
		}
		$charset = $pdb->get_charset_collate();
		$pdb->query( "CREATE TABLE IF NOT EXISTS `{$tbl}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline varchar(40) NOT NULL,
			message_id varchar(190) NOT NULL,
			subject varchar(500) NOT NULL DEFAULT '',
			sender varchar(255) NOT NULL DEFAULT '',
			summary text NULL,
			body mediumtext NULL,
			body_debug text NULL,
			received_at datetime NULL,
			stage varchar(20) NOT NULL DEFAULT 'new',
			notes text NULL,
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pipe_msg (pipeline, message_id),
			KEY pipe_stage (pipeline, stage),
			KEY received (received_at)
		) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $done = $kit['table_exists']( $pdb, $tbl );
	}

	// ── settings (per pipeline) ─────────────────────────────────────────────────

	public function settings( $id ) {
		$opt = get_option( 'ajcore_ra_mp_' . $id, array() );
		return array_merge( array( 'mailbox' => '', 'enabled' => '1', 'cursor' => 0, 'last_run' => array() ), is_array( $opt ) ? $opt : array() );
	}

	private function save_settings( $id, array $s ) {
		update_option( 'ajcore_ra_mp_' . $id, $s, false );
	}

	private function zoho() {
		return class_exists( 'AJCore_Zoho_Mail' ) ? AJCore_Zoho_Mail::instance() : null;
	}

	// ── ingestion ───────────────────────────────────────────────────────────────

	/** Same walk as Purchases: newest page plus the not-yet-caught-up pages; stores each unseen message (no filters). */
	public function run_pass( $id, $max_new = 60 ) {
		$zoho = $this->zoho();
		$s    = $this->settings( $id );
		if ( ! $zoho || ! $zoho->is_connected() ) {
			return new WP_Error( 'zoho_not_connected', __( 'Zoho is not connected.', 'ajcore-ra' ) );
		}
		if ( '' === $s['mailbox'] ) {
			return new WP_Error( 'no_mailbox', __( 'Set the mailbox first.', 'ajcore-ra' ) );
		}
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore-ra' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$pdb     = $this->pdb();
		$tbl     = $this->table();
		$summary = array( 'scanned' => 0, 'new' => 0, 'errors' => array(), 'complete' => false );
		$cursor  = max( 0, (int) $s['cursor'] );
		$pages   = array_unique( array( 0, $cursor ) );
		$guard   = 0;
		while ( $pages && $summary['new'] < $max_new && $guard++ < 40 ) {
			$start = (int) array_shift( $pages );
			$rows  = $zoho->get_recent_messages( 50, 'all', $start, $s['mailbox'] );
			if ( is_wp_error( $rows ) ) {
				$summary['errors'][] = $rows->get_error_message();
				break;
			}
			$summary['scanned'] += count( $rows );
			$ids   = wp_list_pluck( $rows, 'message_id' );
			$known = array();
			if ( $ids ) {
				$ph    = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );
				$known = array_flip( (array) $pdb->get_col( $pdb->prepare( "SELECT message_id FROM `{$tbl}` WHERE pipeline = %s AND message_id IN ({$ph})", array_merge( array( $id ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$all_known = true;
			foreach ( $rows as $row ) {
				if ( isset( $known[ $row['message_id'] ] ) ) {
					continue;
				}
				$all_known = false;
				if ( $summary['new'] >= $max_new ) {
					break;
				}
				$pdb->insert( $tbl, array(
					'pipeline'    => $id,
					'message_id'  => $row['message_id'],
					'subject'     => mb_substr( (string) $row['subject'], 0, 500 ),
					'sender'      => mb_substr( trim( $row['from_name'] . ' <' . $row['from_email'] . '>' ), 0, 255 ),
					'summary'     => (string) $row['summary'],
					'received_at' => '' !== (string) $row['received_at'] ? $row['received_at'] : current_time( 'mysql', true ),
					'stage'       => 'new',
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				) );
				$summary['new']++;
			}
			if ( count( $rows ) < 50 ) {
				$summary['complete'] = $start >= $cursor;
				if ( $start >= $cursor ) {
					$cursor = 0;
				}
				break;
			}
			if ( $start === $cursor && $all_known ) {
				$cursor  = $start + 50;
				$pages[] = $cursor;
			}
		}
		$s['cursor']   = $cursor;
		$s['last_run'] = array( 'at' => current_time( 'mysql' ), 'new' => $summary['new'], 'scanned' => $summary['scanned'], 'errors' => $summary['errors'] );
		$this->save_settings( $id, $s );
		return $summary;
	}

	public static function cron_pass() {
		$self = self::instance();
		foreach ( array_keys( self::pipelines() ) as $id ) {
			$s = $self->settings( $id );
			if ( '1' === $s['enabled'] && '' !== $s['mailbox'] ) {
				$self->run_pass( $id, 60 );
			}
		}
	}

	// ── REST ────────────────────────────────────────────────────────────────────

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public function register_routes() {
		$ns   = AJCore_Extensions::rest_namespace();
		$ops  = array( 'AJCore_Extensions', 'can_manage_ops' );
		$base = '/ops/mail-pipelines/(?P<pipe>[a-z_]+)';
		register_rest_route( $ns, $base, array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/process', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_process' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/stage', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_stage' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_settings' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/items/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_item' ), 'permission_callback' => $ops ) );
		register_rest_route( $ns, $base . '/items/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_item_update' ), 'permission_callback' => $ops ) );
	}

	private function pipe( WP_REST_Request $request ) {
		$id = (string) $request['pipe'];
		return isset( self::pipelines()[ $id ] ) ? $id : null;
	}

	private function bad_pipe() {
		return new WP_Error( 'unknown_pipeline', __( 'Unknown pipeline.', 'ajcore-ra' ), array( 'status' => 404 ) );
	}

	private function item_out( $r, $with_body = false ) {
		$out = array( 'id' => (int) $r->id, 'subject' => (string) $r->subject, 'sender' => (string) $r->sender, 'summary' => (string) $r->summary, 'received_at' => (string) $r->received_at, 'stage' => (string) $r->stage, 'notes' => (string) $r->notes );
		if ( $with_body ) {
			$out['body']       = (string) $r->body;
			$out['body_debug'] = (string) $r->body_debug;
		}
		return $out;
	}

	public function rest_list( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		if ( ! $this->ensure_table() ) {
			return new WP_Error( 'no_table', __( 'The table could not be created.', 'ajcore-ra' ), array( 'status' => 500 ) );
		}
		$pdb    = $this->pdb();
		$tbl    = $this->table();
		$where  = array( 'pipeline = %s' );
		$params = array( $id );
		$stage  = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( isset( self::pipelines()[ $id ]['stages'][ $stage ] ) ) {
			$where[]  = 'stage = %s';
			$params[] = $stage;
		}
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		if ( '' !== $q ) {
			$like     = '%' . $pdb->esc_like( $q ) . '%';
			$where[]  = '( subject LIKE %s OR sender LIKE %s OR summary LIKE %s OR notes LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$limit = min( 500, max( 1, (int) ( $request->get_param( 'limit' ) ?: 300 ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = (array) $pdb->get_results( $pdb->prepare( "SELECT id, subject, sender, summary, received_at, stage, notes FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY received_at DESC, id DESC LIMIT {$limit}", $params ) );
		$counts = array_fill_keys( array_keys( self::pipelines()[ $id ]['stages'] ), 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $pdb->get_results( $pdb->prepare( "SELECT stage, COUNT(*) AS n FROM `{$tbl}` WHERE pipeline = %s GROUP BY stage", $id ) ) as $c ) {
			$counts[ (string) $c->stage ] = (int) $c->n;
		}
		$s = $this->settings( $id );
		return rest_ensure_response( array(
			'label'    => self::pipelines()[ $id ]['label'],
			'stages'   => self::pipelines()[ $id ]['stages'],
			'items'    => array_map( array( $this, 'item_out' ), $rows ),
			'counts'   => $counts,
			'mailbox'  => $s['mailbox'],
			'enabled'  => '1' === (string) $s['enabled'],
			'last_run' => $s['last_run'],
		) );
	}

	public function rest_process( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$r = $this->run_pass( $id );
		return is_wp_error( $r ) ? new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( $r );
	}

	public function rest_stage( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		$ids   = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids || ! isset( self::pipelines()[ $id ]['stages'][ $stage ] ) || ! $this->ensure_table() ) {
			return new WP_Error( 'bad_request', __( 'Select items and a stage.', 'ajcore-ra' ), array( 'status' => 400 ) );
		}
		$pdb = $this->pdb();
		$ph  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $pdb->query( $pdb->prepare( "UPDATE `{$this->table()}` SET stage = %s, updated_at = %s WHERE pipeline = %s AND id IN ({$ph})", array_merge( array( $stage, current_time( 'mysql' ), $id ), $ids ) ) );
		return rest_ensure_response( array( 'updated' => (int) $n, 'stage' => $stage ) );
	}

	public function rest_settings( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id ) {
			return $this->bad_pipe();
		}
		$s            = $this->settings( $id );
		$s['mailbox'] = strtolower( sanitize_email( (string) $request->get_param( 'mailbox' ) ) );
		$s['enabled'] = $request->get_param( 'enabled' ) ? '1' : '0';
		$s['cursor']  = 0;
		$this->save_settings( $id, $s );
		return rest_ensure_response( array( 'mailbox' => $s['mailbox'], 'enabled' => '1' === $s['enabled'] ) );
	}

	private function load_item( $id, $item_id ) {
		$pdb = $this->pdb();
		return $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$this->table()}` WHERE pipeline = %s AND id = %d", $id, absint( $item_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** One email with its text; the text is fetched from Zoho the first time it is opened and kept. */
	public function rest_item( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id || ! $this->ensure_table() ) {
			return $this->bad_pipe();
		}
		$row = $this->load_item( $id, $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$zoho    = $this->zoho();
		$mailbox = $this->settings( $id )['mailbox'];
		if ( null === $row->body_debug && $zoho && '' !== $mailbox ) {
			$m = $zoho->intake_message( (string) $row->message_id, $mailbox );
			if ( ! is_wp_error( $m ) ) {
				$row->body       = mb_substr( (string) $m['body_text'], 0, 20000 );
				$row->body_debug = (string) ( $m['body_debug'] ?? '' );
				$this->pdb()->update( $this->table(), array( 'body' => $row->body, 'body_debug' => $row->body_debug ), array( 'id' => (int) $row->id ) );
			}
		}
		return rest_ensure_response( array( 'item' => $this->item_out( $row, true ) ) );
	}

	public function rest_item_update( WP_REST_Request $request ) {
		$id = $this->pipe( $request );
		if ( ! $id || ! $this->ensure_table() ) {
			return $this->bad_pipe();
		}
		$row = $this->load_item( $id, $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Not found.', 'ajcore-ra' ), array( 'status' => 404 ) );
		}
		$new = array( 'updated_at' => current_time( 'mysql' ) );
		if ( null !== $request->get_param( 'notes' ) ) {
			$new['notes'] = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		}
		$stage = sanitize_key( (string) $request->get_param( 'stage' ) );
		if ( '' !== $stage && isset( self::pipelines()[ $id ]['stages'][ $stage ] ) ) {
			$new['stage'] = $stage;
		}
		$this->pdb()->update( $this->table(), $new, array( 'id' => (int) $row->id ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	// ── WP admin view (inside the Pipeline tab) ─────────────────────────────────
	// Same screen as AJOps > Pipeline > <name>. Plain JS; text goes in via textContent.

	public function render_view( $id ) {
		$p = self::pipelines()[ $id ];
		$zoho = $this->zoho();
		if ( ! $zoho || ! $zoho->is_connected() ) {
			echo '<div class="ajmr-card ajmr-empty">' . esc_html__( 'Zoho is not connected. Open Connections above to connect it.', 'ajcore-ra' ) . '</div>';
			return;
		}
		?>
		<div id="ajra-mp">
			<div class="ajmr-row" id="ajra-mp-tiles"></div>
			<div class="ajmr-row ajmr-row-tools">
				<label class="ajmr-tile ajmr-search"><input type="search" id="ajra-mp-q" placeholder="<?php echo esc_attr__( 'Search subject, sender, note…', 'ajcore-ra' ); ?>" autocomplete="off"></label>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajra-mp-clear"><span><?php esc_html_e( 'Clear', 'ajcore-ra' ); ?></span></button>
				<button type="button" class="ajmr-tile ajmr-tool" id="ajra-mp-run"><span><?php esc_html_e( 'Read mailbox', 'ajcore-ra' ); ?></span></button>
			</div>
			<div class="ajmr-filters"><span class="ajmr-fnote" id="ajra-mp-status"></span></div>
			<div id="ajra-mp-bulk" class="ajmr-note" hidden></div>
			<div class="ajmr-card"><table class="widefat striped" id="ajra-mp-table"><thead><tr><th style="width:28px"><input type="checkbox" id="ajra-mp-all"></th><th><?php esc_html_e( 'Time', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Email', 'ajcore-ra' ); ?></th><th><?php esc_html_e( 'Stage', 'ajcore-ra' ); ?></th></tr></thead><tbody></tbody></table></div>
			<div class="ajmr-panel" id="ajra-mp-settings" hidden>
				<h3><?php esc_html_e( 'Mailbox', 'ajcore-ra' ); ?></h3>
				<p><input type="email" id="ajra-mp-mailbox" class="regular-text" placeholder="name@yourdomain.com"> <label><input type="checkbox" id="ajra-mp-enabled"> <?php esc_html_e( 'Read every hour', 'ajcore-ra' ); ?></label> <button type="button" class="button button-primary" id="ajra-mp-save"><?php esc_html_e( 'Save', 'ajcore-ra' ); ?></button></p>
			</div>
			<div class="ajmr-modal" id="ajra-mp-detail" hidden><div class="ajmr-modal-box"><div id="ajra-mp-detail-body"></div></div></div>
		</div>
		<script>
		( function () {
			var PIPE = <?php echo wp_json_encode( $id ); ?>, rest = <?php echo wp_json_encode( rest_url( 'ajcore/v1/ops/mail-pipelines/' ) ); ?> + PIPE, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var st = { stage: '', q: '' }, data = null, selected = {};
			function $( i ) { return document.getElementById( i ); }
			function el( t, c, x ) { var e = document.createElement( t ); if ( c ) { e.className = c; } if ( x != null ) { e.textContent = x; } return e; }
			function api( path, opts ) { opts = opts || {}; opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} ); return fetch( rest + path, opts ).then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) { throw new Error( d.message || 'Request failed' ); } return d; } ); } ); }
			function post( path, body ) { return api( path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } ); }
			function dayKey( s ) { return ( s || '' ).slice( 0, 10 ) || '—'; }
			function dayLabel( k ) { var d = new Date( k + 'T12:00:00' ); return isNaN( d ) ? k : d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } ); }
			function load() {
				var q = new URLSearchParams(); if ( st.stage ) { q.set( 'stage', st.stage ); } if ( st.q ) { q.set( 'q', st.q ); }
				api( '?' + q.toString() ).then( function ( d ) { var first = ! data; data = d; render(); if ( first && d.mailbox && ! ( d.last_run && typeof d.last_run.at === 'string' ) ) { $( 'ajra-mp-run' ).click(); } if ( ! d.mailbox ) { $( 'ajra-mp-settings' ).hidden = false; } } ).catch( function ( e ) { $( 'ajra-mp-status' ).textContent = e.message; } );
			}
			function render() {
				var tiles = $( 'ajra-mp-tiles' ); tiles.innerHTML = '';
				Object.keys( data.stages ).forEach( function ( k ) {
					if ( k === 'ignored' ) { return; }
					var t = el( 'a', 'ajmr-tile ajmr-stat' + ( st.stage === k ? ' is-on' : '' ) ); t.href = '#';
					var tx = el( 'span', 'ajmr-tile-txt' ); tx.appendChild( el( 'small', null, data.stages[ k ] ) ); tx.appendChild( el( 'strong', null, String( data.counts[ k ] || 0 ) ) ); t.appendChild( tx );
					t.addEventListener( 'click', function ( e ) { e.preventDefault(); st.stage = st.stage === k ? '' : k; load(); } ); tiles.appendChild( t );
				} );
				var side = el( 'div', 'ajmr-mailboxes' ), g = el( 'button', 'ajmr-tile ajmr-tile-sm' ); g.type = 'button';
				var gt = el( 'span', 'ajmr-tile-txt' ); gt.appendChild( el( 'small', null, 'Mailbox' ) ); gt.appendChild( el( 'strong', null, data.mailbox ? data.mailbox.split( '@' )[ 0 ] : 'Not set' ) ); g.appendChild( gt );
				g.addEventListener( 'click', function () { var p = $( 'ajra-mp-settings' ); p.hidden = ! p.hidden; } ); side.appendChild( g ); tiles.appendChild( side );
				$( 'ajra-mp-mailbox' ).value = data.mailbox || ''; $( 'ajra-mp-enabled' ).checked = !! data.enabled;
				var lr = data.last_run, note = '';
				if ( lr && typeof lr.at === 'string' ) { note = 'Last pass ' + lr.at + ' · ' + lr.scanned + ' scanned · ' + lr[ 'new' ] + ' new' + ( lr.errors && lr.errors.length ? ' · ERROR: ' + lr.errors.join( ' | ' ) : '' ); }
				if ( note && ! $( 'ajra-mp-status' ).textContent ) { $( 'ajra-mp-status' ).textContent = note; }
				var tb = $( 'ajra-mp-table' ).querySelector( 'tbody' ); tb.innerHTML = '';
				if ( ! data.items.length ) { var r0 = el( 'tr' ), c0 = el( 'td', null, 'No emails.' ); c0.colSpan = 4; r0.appendChild( c0 ); tb.appendChild( r0 ); }
				var days = [], by = {};
				data.items.forEach( function ( i ) { var k = dayKey( i.received_at ); if ( ! by[ k ] ) { by[ k ] = []; days.push( k ); } by[ k ].push( i ); } );
				days.sort().reverse().forEach( function ( k ) {
					var hr = el( 'tr', 'ajra-pu-day' ), hc = el( 'td', null, dayLabel( k ) + '  ·  ' + by[ k ].length + ( by[ k ].length === 1 ? ' email' : ' emails' ) ); hc.colSpan = 4; hr.appendChild( hc ); tb.appendChild( hr );
					by[ k ].forEach( function ( i ) {
						var tr = el( 'tr' ); tr.style.cursor = 'pointer';
						var td = el( 'td' ); td.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
						var cb = el( 'input' ); cb.type = 'checkbox'; cb.checked = !! selected[ i.id ]; cb.addEventListener( 'change', function () { if ( cb.checked ) { selected[ i.id ] = true; } else { delete selected[ i.id ]; } syncBulk(); } ); td.appendChild( cb ); tr.appendChild( td );
						tr.appendChild( el( 'td', null, ( i.received_at || '' ).slice( 11, 16 ) ) );
						var m = el( 'td' ); m.appendChild( el( 'strong', null, i.subject ) ); m.appendChild( el( 'div', 'description', ( i.sender || '' ) + ( i.notes ? ' · ' + i.notes : '' ) ) ); tr.appendChild( m );
						var s = el( 'td' ); s.appendChild( el( 'span', 'ajcore-status-pill ' + ( i.stage === 'new' ? '' : ( i.stage === 'ignored' ? 'dup' : 'active' ) ), data.stages[ i.stage ] || i.stage ) ); tr.appendChild( s );
						tr.addEventListener( 'click', function () { openDetail( i.id ); } ); tb.appendChild( tr );
					} );
				} );
				syncBulk();
			}
			function syncBulk() {
				var ids = Object.keys( selected ), box = $( 'ajra-mp-bulk' ); box.innerHTML = ''; box.hidden = ! ids.length; if ( ! ids.length ) { return; }
				box.appendChild( el( 'strong', null, ids.length + ' selected ' ) );
				Object.keys( data.stages ).forEach( function ( k ) { var b = el( 'button', 'button', 'Mark ' + data.stages[ k ].toLowerCase() ); b.type = 'button'; b.addEventListener( 'click', function () { post( '/stage', { ids: ids.map( Number ), stage: k } ).then( function () { selected = {}; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); box.appendChild( b ); box.appendChild( document.createTextNode( ' ' ) ); } );
			}
			function openDetail( id ) {
				var wrap = $( 'ajra-mp-detail' ), box = $( 'ajra-mp-detail-body' ); box.innerHTML = ''; box.appendChild( el( 'p', null, 'Loading…' ) ); wrap.hidden = false;
				api( '/items/' + id ).then( function ( d ) {
					var i = d.item; box.innerHTML = ''; box.appendChild( el( 'h2', null, i.subject ) ); box.appendChild( el( 'p', 'description', i.sender + ' · ' + i.received_at ) );
					if ( i.body_debug ) { var w = el( 'div', null, 'Only the preview could be read: ' + i.body_debug ); w.style.cssText = 'color:#b45309;font-size:12px;margin:4px 0;'; box.appendChild( w ); }
					var pre = el( 'pre', null, i.body || i.summary ); pre.style.cssText = 'max-height:380px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#f8fafc;padding:10px;border-radius:8px;'; box.appendChild( pre );
					var notes = el( 'textarea' ); notes.rows = 2; notes.className = 'large-text'; notes.value = i.notes; notes.placeholder = 'Note'; box.appendChild( notes );
					var bar = el( 'p' ); function act( label, stage, primary ) { var b = el( 'button', 'button' + ( primary ? ' button-primary' : '' ), label ); b.type = 'button'; b.addEventListener( 'click', function () { var body = { notes: notes.value }; if ( stage ) { body.stage = stage; } post( '/items/' + id, body ).then( function () { wrap.hidden = true; load(); } ).catch( function ( e ) { alert( e.message ); } ); } ); bar.appendChild( b ); bar.appendChild( document.createTextNode( ' ' ) ); }
					act( 'Save note', '', true ); Object.keys( data.stages ).forEach( function ( k ) { if ( k !== i.stage ) { act( 'Mark ' + data.stages[ k ].toLowerCase(), k, false ); } } );
					var close = el( 'a', null, 'Close' ); close.href = '#'; close.addEventListener( 'click', function ( e ) { e.preventDefault(); wrap.hidden = true; } ); bar.appendChild( close ); box.appendChild( bar );
				} ).catch( function ( e ) { box.innerHTML = ''; box.appendChild( el( 'p', null, e.message ) ); } );
			}
			var qt = null; $( 'ajra-mp-q' ).addEventListener( 'input', function ( e ) { clearTimeout( qt ); qt = setTimeout( function () { st.q = e.target.value.trim(); load(); }, 350 ); } );
			$( 'ajra-mp-clear' ).addEventListener( 'click', function () { st = { stage: '', q: '' }; $( 'ajra-mp-q' ).value = ''; selected = {}; load(); } );
			$( 'ajra-mp-all' ).addEventListener( 'change', function ( e ) { selected = {}; if ( e.target.checked && data ) { data.items.forEach( function ( i ) { selected[ i.id ] = true; } ); } render(); } );
			$( 'ajra-mp-detail' ).addEventListener( 'click', function ( e ) { if ( e.target === this ) { this.hidden = true; } } );
			$( 'ajra-mp-save' ).addEventListener( 'click', function () { post( '/settings', { mailbox: $( 'ajra-mp-mailbox' ).value.trim(), enabled: $( 'ajra-mp-enabled' ).checked ? 1 : 0 } ).then( function () { $( 'ajra-mp-status' ).textContent = 'Saved. Reading…'; load(); setTimeout( function () { $( 'ajra-mp-run' ).click(); }, 300 ); } ).catch( function ( e ) { $( 'ajra-mp-status' ).textContent = e.message; } ); } );
			$( 'ajra-mp-run' ).addEventListener( 'click', function () {
				if ( ! data || ! data.mailbox ) { $( 'ajra-mp-status' ).textContent = 'Set the mailbox first (gear tile), then Save.'; $( 'ajra-mp-settings' ).hidden = false; return; }
				var b = this; b.disabled = true; $( 'ajra-mp-status' ).textContent = 'Reading the mailbox…';
				post( '/process', {} ).then( function ( d ) { $( 'ajra-mp-status' ).textContent = d.new + ' new, ' + d.scanned + ' scanned' + ( d.complete ? ' (whole mailbox read)' : ' (more to read: click Read mailbox again)' ) + ( d.errors && d.errors.length ? ' · ' + d.errors.join( ' | ' ) : '' ); b.disabled = false; load(); } ).catch( function ( e ) { $( 'ajra-mp-status' ).textContent = e.message; b.disabled = false; } );
			} );
			load();
		} )();
		</script>
		<?php
	}
}
