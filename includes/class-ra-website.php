<?php
/**
 * Website tab for the client portal: the customer's hosted site (e.g. nc.itspector.com) plus a
 * landing page for web services (custom-domain packages).
 *
 * - Tab: added through AJCore's 'ajcore_portal_menu_default_items' / 'ajcore_portal_tab_content'
 *   filters, so it shows only while AJCore-RA is active. Admins can still hide it in the portal
 *   menu editor.
 * - Data: no new table. Each site is one row in AJCore's existing aj_portal_entity_mappings
 *   (portal DB, so shared across connected sites): entity_type 'website', entity_key = host,
 *   entity_label = display name, metadata = JSON { status, note }. AJCore's customer view already
 *   lists these rows.
 * - Staff only for now: customers see their site(s); AJCore > Websites assigns them.
 * - Packages: edited on the same staff page (one per line: Name | Price | Description).
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Website {

	const TYPE    = 'website';
	const PACKAGES = 'ajcore_ra_website_packages';

	/** @return array<string,string> */
	private static function statuses() {
		return array(
			'planned'  => __( 'Planned', 'ajcore-ra' ),
			'building' => __( 'We are building it', 'ajcore-ra' ),
			'live'     => __( 'Live', 'ajcore-ra' ),
		);
	}

	public static function base_domain() {
		return (string) apply_filters( 'ajcore_ra_website_base_domain', 'itspector.com' );
	}

	public static function init() {
		add_filter( 'ajcore_portal_menu_default_items', array( __CLASS__, 'add_tab' ) );
		add_filter( 'ajcore_portal_tab_content', array( __CLASS__, 'render_tab' ), 10, 3 );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 33 );
		add_action( 'admin_post_ajcore_ra_save_website', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_ra_remove_website', array( __CLASS__, 'handle_remove' ) );
		add_action( 'admin_post_ajcore_ra_save_website_packages', array( __CLASS__, 'handle_save_packages' ) );
	}

	// ── data ────────────────────────────────────────────────────────────────────

	public static function db() {
		if ( ! class_exists( 'AJCore_Extensions' ) || ! method_exists( 'AJCore_Extensions', 'rest_toolkit' ) ) {
			return null;
		}
		$kit = AJCore_Extensions::rest_toolkit();
		return array( 'db' => $kit['portal_db'](), 'kit' => $kit );
	}

	public static function table( $ctx, $suffix ) {
		return $ctx['kit']['portal_table']( $suffix );
	}

	/** @return array<int,array{key:string,label:string,status:string,note:string}> */
	public static function sites_for( $stripe_customer_id ) {
		$ctx = self::db();
		if ( ! $ctx || '' === (string) $stripe_customer_id ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = self::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		$rows = $db->get_results( $db->prepare( "SELECT entity_key, entity_label, metadata FROM `{$t}` WHERE stripe_customer_id = %s AND entity_type = %s ORDER BY id ASC", $stripe_customer_id, self::TYPE ) );
		return self::format_rows( $rows );
	}

	private static function format_rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			$meta  = json_decode( (string) $r->metadata, true );
			$meta  = is_array( $meta ) ? $meta : array();
			$out[] = array(
				'customer' => isset( $r->stripe_customer_id ) ? (string) $r->stripe_customer_id : '',
				'key'      => (string) $r->entity_key,
				'label'    => (string) $r->entity_label,
				'status'   => isset( $meta['status'] ) && isset( self::statuses()[ $meta['status'] ] ) ? $meta['status'] : 'planned',
				'note'     => isset( $meta['note'] ) ? (string) $meta['note'] : '',
			);
		}
		return $out;
	}

	/** Valid subdomain label: letters, digits, hyphens; 2-30 chars; no leading/trailing hyphen. */
	public static function clean_subdomain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/\.' . preg_quote( self::base_domain(), '/' ) . '$/', '', $value );
		$value = preg_replace( '/[^a-z0-9-]+/', '', $value );
		$value = trim( $value, '-' );
		return ( strlen( $value ) >= 2 && strlen( $value ) <= 30 ) ? $value : '';
	}

	/** "Trident Audio Video, Inc" -> "tav" (example of how initials are suggested). */
	public static function suggest_subdomain( $business ) {
		$words = preg_split( '/[^A-Za-z0-9]+/', (string) $business, -1, PREG_SPLIT_NO_EMPTY );
		$skip  = array( 'inc', 'llc', 'corp', 'co', 'ltd', 'company', 'corporation', 'incorporated', 'the', 'and', 'of' );
		$words = array_values( array_filter( $words, static function ( $w ) use ( $skip ) {
			return ! in_array( strtolower( $w ), $skip, true );
		} ) );
		if ( ! $words ) {
			return '';
		}
		if ( 1 === count( $words ) ) {
			return strtolower( substr( $words[0], 0, 10 ) );
		}
		return strtolower( implode( '', array_map( static function ( $w ) {
			return $w[0];
		}, $words ) ) );
	}

	private static function packages() {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) get_option( self::PACKAGES, '' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( '' !== $parts[0] ) {
				$lines[] = array(
					'name'  => $parts[0],
					'price' => isset( $parts[1] ) ? $parts[1] : '',
					'desc'  => isset( $parts[2] ) ? $parts[2] : '',
				);
			}
		}
		return $lines;
	}

	/** @return array{ok:bool,message:string,host:string} */
	public static function save_site( $customer, $subdomain, $status, $note, $label ) {
		$ctx = self::db();
		if ( ! $ctx ) {
			return array( 'ok' => false, 'message' => __( 'AJ Core is not ready.', 'ajcore-ra' ), 'host' => '' );
		}
		$sub    = self::clean_subdomain( $subdomain );
		$status = isset( self::statuses()[ $status ] ) ? $status : 'planned';
		if ( '' === (string) $customer || '' === $sub ) {
			return array( 'ok' => false, 'message' => __( 'Pick a customer and a valid subdomain (2-30 letters, numbers or hyphens).', 'ajcore-ra' ), 'host' => '' );
		}
		$db   = $ctx['db'];
		$t    = self::table( $ctx, 'aj_portal_entity_mappings' );
		$host = $sub . '.' . self::base_domain();
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array( 'ok' => false, 'message' => __( 'The entity mappings table is missing. Run "Initialize shared schema" in AJ Core.', 'ajcore-ra' ), 'host' => $host );
		}
		// One address belongs to one customer.
		$owner = $db->get_var( $db->prepare( "SELECT stripe_customer_id FROM `{$t}` WHERE entity_type = %s AND entity_key = %s LIMIT 1", self::TYPE, $host ) );
		if ( $owner && (string) $owner !== (string) $customer ) {
			return array( 'ok' => false, 'message' => sprintf( __( '%s is already assigned to another customer.', 'ajcore-ra' ), $host ), 'host' => $host );
		}
		$row    = array(
			'stripe_customer_id' => (string) $customer,
			'entity_key'         => $host,
			'entity_label'       => sanitize_text_field( (string) $label ),
			'entity_type'        => self::TYPE,
			'metadata'           => wp_json_encode( array( 'status' => $status, 'note' => sanitize_text_field( (string) $note ) ) ),
		);
		$fmt    = array( '%s', '%s', '%s', '%s', '%s' );
		$exists = $db->get_var( $db->prepare( "SELECT id FROM `{$t}` WHERE stripe_customer_id = %s AND entity_key = %s LIMIT 1", $customer, $host ) );
		if ( $exists ) {
			$db->update( $t, $row, array( 'id' => (int) $exists ), $fmt, array( '%d' ) );
		} else {
			$db->insert( $t, $row, $fmt );
		}
		return array( 'ok' => true, 'message' => sprintf( __( 'Saved %s.', 'ajcore-ra' ), $host ), 'host' => $host );
	}

	public static function remove_site( $customer, $host ) {
		$ctx = self::db();
		if ( ! $ctx || '' === (string) $customer || '' === (string) $host ) {
			return false;
		}
		$ctx['db']->delete( self::table( $ctx, 'aj_portal_entity_mappings' ), array( 'stripe_customer_id' => (string) $customer, 'entity_key' => (string) $host, 'entity_type' => self::TYPE ), array( '%s', '%s', '%s' ) );
		return true;
	}

	/** All sites across customers, newest address order, with the customer's name. */
	public static function list_sites() {
		$sites = self::all_sites();
		$names = self::customers();
		foreach ( $sites as &$site ) {
			$site['customer_name'] = isset( $names[ $site['customer'] ] ) ? $names[ $site['customer'] ]['name'] : $site['customer'];
		}
		unset( $site );
		return $sites;
	}

	public static function statuses_public() {
		return self::statuses();
	}

	// ── customer-facing tab ─────────────────────────────────────────────────────

	public static function add_tab( $items ) {
		$items[] = array(
			'id'      => 'website',
			'label'   => __( 'Website', 'ajcore-ra' ),
			'type'    => 'built_in',
			'url'     => '',
			'enabled' => true,
		);
		return $items;
	}

	public static function render_tab( $html, $tab, $context ) {
		if ( 'website' !== $tab ) {
			return $html;
		}
		$customer = isset( $context['stripe_customer_id'] ) ? (string) $context['stripe_customer_id'] : '';
		$sites    = self::sites_for( $customer );
		$contact  = home_url( '/email-us/' );
		$statuses = self::statuses();
		$base     = self::base_domain();

		ob_start();
		?>
		<section class="aj-customer-portal-panel aj-ra-website">
			<h2><?php esc_html_e( 'Website', 'ajcore-ra' ); ?></h2>

			<?php if ( $sites ) : ?>
				<?php foreach ( $sites as $site ) : ?>
					<?php $url = 'https://' . $site['key']; ?>
					<div class="aj-portal-account-summary" style="margin:0 0 14px;">
						<h3 style="margin:0 0 6px;"><?php echo esc_html( '' !== $site['label'] ? $site['label'] : $site['key'] ); ?></h3>
						<p style="margin:0 0 8px;">
							<?php if ( 'live' === $site['status'] ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" style="font-weight:800;font-size:18px;"><?php echo esc_html( $site['key'] ); ?></a>
							<?php else : ?>
								<strong style="font-size:18px;"><?php echo esc_html( $site['key'] ); ?></strong>
							<?php endif; ?>
							<span class="aj-ra-status" style="margin-left:8px;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;background:<?php echo 'live' === $site['status'] ? '#dcfce7;color:#166534' : '#fef3c7;color:#92400e'; ?>"><?php echo esc_html( $statuses[ $site['status'] ] ); ?></span>
						</p>
						<?php if ( '' !== $site['note'] ) : ?>
							<p style="margin:0 0 8px;"><?php echo esc_html( $site['note'] ); ?></p>
						<?php endif; ?>
						<p style="margin:0;">
							<?php if ( 'live' === $site['status'] ) : ?>
								<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Visit website', 'ajcore-ra' ); ?></a>
							<?php endif; ?>
							<a class="button" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Request a change', 'ajcore-ra' ); ?></a>
						</p>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="aj-portal-account-summary" style="margin:0 0 14px;">
					<h3 style="margin:0 0 6px;"><?php esc_html_e( 'A website for your business', 'ajcore-ra' ); ?></h3>
					<p style="margin:0 0 10px;"><?php echo esc_html( sprintf( /* translators: %s: domain */ __( 'We design, host and maintain your website for you, at your own address on %s, such as yourname.%s.', 'ajcore-ra' ), $base, $base ) ); ?></p>
					<p style="margin:0;"><a class="button button-primary" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Ask about a website', 'ajcore-ra' ); ?></a></p>
				</div>
			<?php endif; ?>

			<h3 style="margin:22px 0 8px;"><?php esc_html_e( 'Your own domain', 'ajcore-ra' ); ?></h3>
			<?php $packages = self::packages(); ?>
			<?php if ( $packages ) : ?>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
					<?php foreach ( $packages as $pkg ) : ?>
						<div class="aj-portal-account-summary">
							<h3 style="margin:0 0 4px;"><?php echo esc_html( $pkg['name'] ); ?></h3>
							<?php if ( '' !== $pkg['price'] ) : ?><p style="margin:0 0 6px;font-size:20px;font-weight:800;"><?php echo esc_html( $pkg['price'] ); ?></p><?php endif; ?>
							<?php if ( '' !== $pkg['desc'] ) : ?><p style="margin:0 0 10px;"><?php echo esc_html( $pkg['desc'] ); ?></p><?php endif; ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'subject', rawurlencode( 'Website: ' . $pkg['name'] ), $contact ) ); ?>"><?php esc_html_e( 'Request this', 'ajcore-ra' ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="aj-portal-account-summary">
					<p style="margin:0 0 10px;"><?php esc_html_e( 'Want your website on your own domain, such as yourcompany.com? We can build and host it. Contact us for pricing.', 'ajcore-ra' ); ?></p>
					<a class="button" href="<?php echo esc_url( $contact ); ?>"><?php esc_html_e( 'Get a quote', 'ajcore-ra' ); ?></a>
				</div>
			<?php endif; ?>

			<p class="description" style="margin-top:16px;"><?php esc_html_e( 'Coming soon: edit your blog posts and manage your site from here.', 'ajcore-ra' ); ?></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	// ── staff page: AJCore > Websites ───────────────────────────────────────────

	/** "Websites" in the Client Portal tab bar of AJCore admin. */
	public static function admin_tab( $tabs ) {
		$tabs['ra-websites'] = array( 'label' => __( 'Websites', 'ajcore-ra' ), 'url' => add_query_arg( array( 'page' => 'ajcore-ra-websites' ), admin_url( 'admin.php' ) ) );
		return $tabs;
	}

	public static function add_menu() {
		add_submenu_page( 'ajforms', __( 'Websites', 'ajcore-ra' ), __( 'Websites', 'ajcore-ra' ), 'manage_options', 'ajcore-ra-websites', array( __CLASS__, 'render_admin' ) );
	}

	public static function customers() {
		$ctx = self::db();
		if ( ! $ctx ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = self::table( $ctx, 'aj_portal_stripe_customers' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $db->get_results( "SELECT stripe_customer_id, name, email, metadata FROM `{$t}` WHERE portal_status IS NULL OR portal_status <> 'archived' ORDER BY name ASC, email ASC" ) as $c ) {
			$meta     = json_decode( (string) $c->metadata, true );
			$business = '';
			if ( is_array( $meta ) ) {
				foreach ( array( 'business_name', 'business', 'company', 'company_name' ) as $k ) {
					if ( ! empty( $meta[ $k ] ) && is_string( $meta[ $k ] ) ) {
						$business = trim( $meta[ $k ] );
						break;
					}
				}
			}
			$out[ (string) $c->stripe_customer_id ] = array(
				'name'     => '' !== trim( (string) $c->name ) ? (string) $c->name : (string) $c->email,
				'business' => $business,
			);
		}
		return $out;
	}

	/** Every website row across customers. */
	public static function all_sites() {
		$ctx = self::db();
		if ( ! $ctx ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = self::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		return self::format_rows( $db->get_results( $db->prepare( "SELECT stripe_customer_id, entity_key, entity_label, metadata FROM `{$t}` WHERE entity_type = %s ORDER BY entity_key ASC", self::TYPE ) ) );
	}

	private static function back( $notice, $ok = true ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'ajcore-ra-websites', 'ra_notice' => rawurlencode( $notice ), 'ra_ok' => $ok ? 1 : 0 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_website' );
		$r = self::save_site(
			isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '',
			isset( $_POST['subdomain'] ) ? wp_unslash( $_POST['subdomain'] ) : '',
			isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'planned',
			isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
			isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : ''
		);
		self::back( $r['message'], $r['ok'] );
	}

	public static function handle_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_remove_website' );
		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$host     = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		self::remove_site( $customer, $host );
		self::back( sprintf( __( 'Removed %s from the customer. The website itself was not touched.', 'ajcore-ra' ), $host ) );
	}

	public static function handle_save_packages() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_save_website_packages' );
		$raw = isset( $_POST['packages'] ) ? sanitize_textarea_field( wp_unslash( $_POST['packages'] ) ) : '';
		update_option( self::PACKAGES, $raw, false );
		self::back( __( 'Packages saved.', 'ajcore-ra' ) );
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$customers = self::customers();
		$sites     = self::all_sites();
		$statuses  = self::statuses();
		$base      = self::base_domain();
		$suggest   = array();
		foreach ( $customers as $cid => $c ) {
			$suggest[ $cid ] = self::suggest_subdomain( '' !== $c['business'] ? $c['business'] : $c['name'] );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Websites', 'ajcore-ra' ) . '</h1>';
		if ( isset( $_GET['ra_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ok = ! empty( $_GET['ra_ok'] ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['ra_notice'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}

		// Assign / edit.
		echo '<h2>' . esc_html__( 'Assign a website', 'ajcore-ra' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;max-width:1100px;">';
		echo '<input type="hidden" name="action" value="ajcore_ra_save_website">';
		wp_nonce_field( 'ajcore_ra_save_website' );
		echo '<label>' . esc_html__( 'Customer', 'ajcore-ra' ) . '<br><select name="customer" id="ajcore-ra-customer" style="min-width:280px;"><option value="">—</option>';
		foreach ( $customers as $cid => $c ) {
			echo '<option value="' . esc_attr( $cid ) . '">' . esc_html( $c['name'] . ( '' !== $c['business'] && $c['business'] !== $c['name'] ? ' — ' . $c['business'] : '' ) ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Address', 'ajcore-ra' ) . '<br><input type="text" name="subdomain" id="ajcore-ra-subdomain" placeholder="nc" size="14"> <code>.' . esc_html( $base ) . '</code></label>';
		echo '<label>' . esc_html__( 'Name shown', 'ajcore-ra' ) . '<br><input type="text" name="label" placeholder="NC LLC Agents Inc"></label>';
		echo '<label>' . esc_html__( 'Status', 'ajcore-ra' ) . '<br><select name="status">';
		foreach ( $statuses as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Note for the customer', 'ajcore-ra' ) . '<br><input type="text" name="note" size="34"></label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save', 'ajcore-ra' ) . '</button></form>';
		echo '<script>(function(){var s=' . wp_json_encode( $suggest ) . ';var c=document.getElementById("ajcore-ra-customer"),a=document.getElementById("ajcore-ra-subdomain");if(c&&a){c.addEventListener("change",function(){if(!a.value){a.value=s[c.value]||"";}});}})();</script>';

		// Current sites.
		echo '<h2 style="margin-top:24px">' . esc_html__( 'Customer websites', 'ajcore-ra' ) . ' <small>· ' . esc_html( (string) count( $sites ) ) . '</small></h2>';
		if ( ! $sites ) {
			echo '<p>' . esc_html__( 'None yet.', 'ajcore-ra' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Address', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Customer', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Status', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Note', 'ajcore-ra' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $sites as $s ) {
				$name = isset( $customers[ $s['customer'] ] ) ? $customers[ $s['customer'] ]['name'] : $s['customer'];
				echo '<tr><td><a href="' . esc_url( 'https://' . $s['key'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['key'] ) . '</a></td><td>' . esc_html( $name ) . '</td><td>' . esc_html( $statuses[ $s['status'] ] ) . '</td><td>' . esc_html( $s['note'] ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Remove this website from the customer?', 'ajcore-ra' ) ) . '\');" style="display:inline">';
				echo '<input type="hidden" name="action" value="ajcore_ra_remove_website"><input type="hidden" name="customer" value="' . esc_attr( $s['customer'] ) . '"><input type="hidden" name="host" value="' . esc_attr( $s['key'] ) . '">';
				wp_nonce_field( 'ajcore_ra_remove_website' );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Remove', 'ajcore-ra' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}

		// Packages shown on the customer landing page.
		echo '<h2 style="margin-top:24px">' . esc_html__( 'Custom-domain packages', 'ajcore-ra' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:1000px">';
		echo '<input type="hidden" name="action" value="ajcore_ra_save_website_packages">';
		wp_nonce_field( 'ajcore_ra_save_website_packages' );
		echo '<textarea name="packages" rows="5" class="large-text code" placeholder="Starter | $499 | 5-page website on your own domain">' . esc_textarea( (string) get_option( self::PACKAGES, '' ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line: Name | Price | Description. Blank = a "contact us for pricing" box.', 'ajcore-ra' ) . '</p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Save packages', 'ajcore-ra' ) . '</button></form></div>';
	}
}
