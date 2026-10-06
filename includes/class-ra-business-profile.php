<?php
/**
 * Business Profile: what we need to know about a customer's business to build their website.
 * Customers edit it any time from the client portal; staff can edit it too (AJCore admin, AJOps).
 * Every change is recorded.
 *
 * - Storage: no new table. One row per customer in AJCore's existing aj_portal_entity_mappings
 *   (portal DB: the shared DB when sharing is on, this site's DB otherwise): entity_type
 *   'business_profile', entity_key 'profile', entity_label = business name,
 *   metadata = JSON { values, history, files, reviewed_at, reviewed_by }.
 * - History: each save that changes something adds one entry (when, who, field old -> new); the
 *   newest HISTORY_MAX are kept.
 * - Review flag: staff press "Site is up to date" after building or updating the website. The
 *   profile "needs review" while it has changes newer than that.
 * - Uploads: a logo and up to MAX_PHOTOS photos (images only), stored in this site's uploads
 *   folder under ajcore-ra-profile/<private token>/. The profile holds the file URLs. Removing a
 *   file only unlinks it from the profile; the file itself is left on disk.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Business_Profile {

	const TYPE        = 'business_profile';
	const KEY         = 'profile';
	const HISTORY_MAX = 30;
	const MAX_PHOTOS  = 8;
	const MAX_BYTES   = 8388608; // 8 MB per image.

	/** @return array<string,array{label:string,type:string,section:string,hint?:string}> */
	public static function fields() {
		return array(
			'business_name'   => array( 'label' => __( 'Business name', 'ajcore-ra' ), 'type' => 'text', 'section' => 'basics' ),
			'tagline'         => array( 'label' => __( 'Motto / tagline', 'ajcore-ra' ), 'type' => 'text', 'section' => 'basics' ),
			'industry'        => array( 'label' => __( 'Industry', 'ajcore-ra' ), 'type' => 'text', 'section' => 'basics', 'hint' => __( 'e.g. Audio / video installation', 'ajcore-ra' ) ),
			'founded_year'    => array( 'label' => __( 'Year started', 'ajcore-ra' ), 'type' => 'year', 'section' => 'basics' ),
			'description'     => array( 'label' => __( 'What does your business do?', 'ajcore-ra' ), 'type' => 'textarea', 'section' => 'what' ),
			'offerings'       => array( 'label' => __( 'Products or services', 'ajcore-ra' ), 'type' => 'textarea', 'section' => 'what', 'hint' => __( 'One per line', 'ajcore-ra' ) ),
			'why_us'          => array( 'label' => __( 'What makes you different?', 'ajcore-ra' ), 'type' => 'textarea', 'section' => 'what' ),
			'customers'       => array( 'label' => __( 'Who are your customers?', 'ajcore-ra' ), 'type' => 'text', 'section' => 'what' ),
			'street'          => array( 'label' => __( 'Street address', 'ajcore-ra' ), 'type' => 'text', 'section' => 'where' ),
			'city'            => array( 'label' => __( 'City', 'ajcore-ra' ), 'type' => 'text', 'section' => 'where' ),
			'state'           => array( 'label' => __( 'State', 'ajcore-ra' ), 'type' => 'text', 'section' => 'where' ),
			'zip'             => array( 'label' => __( 'ZIP', 'ajcore-ra' ), 'type' => 'text', 'section' => 'where' ),
			'service_area'    => array( 'label' => __( 'Areas you serve', 'ajcore-ra' ), 'type' => 'text', 'section' => 'where' ),
			'hours'           => array( 'label' => __( 'Business hours', 'ajcore-ra' ), 'type' => 'textarea', 'section' => 'where' ),
			'phone'           => array( 'label' => __( 'Public phone', 'ajcore-ra' ), 'type' => 'text', 'section' => 'contact' ),
			'email'           => array( 'label' => __( 'Public email', 'ajcore-ra' ), 'type' => 'email', 'section' => 'contact', 'hint' => __( 'The address shown on your website', 'ajcore-ra' ) ),
			'facebook'        => array( 'label' => __( 'Facebook', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'instagram'       => array( 'label' => __( 'Instagram', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'linkedin'        => array( 'label' => __( 'LinkedIn', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'x_twitter'       => array( 'label' => __( 'X (Twitter)', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'youtube'         => array( 'label' => __( 'YouTube', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'tiktok'          => array( 'label' => __( 'TikTok', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'google_maps'     => array( 'label' => __( 'Google Maps link', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links', 'hint' => __( 'Open your business in Google Maps, click Share, copy the link', 'ajcore-ra' ) ),
			'google_business' => array( 'label' => __( 'Google Business Profile', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'yelp'            => array( 'label' => __( 'Yelp', 'ajcore-ra' ), 'type' => 'url', 'section' => 'links' ),
			'other_links'     => array( 'label' => __( 'Other links (directories, reviews)', 'ajcore-ra' ), 'type' => 'textarea', 'section' => 'links', 'hint' => __( 'One per line', 'ajcore-ra' ) ),
			'existing_site'   => array( 'label' => __( 'Current website, if any', 'ajcore-ra' ), 'type' => 'url', 'section' => 'online' ),
			'domain_wanted'   => array( 'label' => __( 'Domain you would like', 'ajcore-ra' ), 'type' => 'text', 'section' => 'online' ),
			'style'           => array( 'label' => __( 'Colors / style you like', 'ajcore-ra' ), 'type' => 'text', 'section' => 'online' ),
			'story'           => array( 'label' => __( 'Your story (for an About page)', 'ajcore-ra' ), 'type' => 'textarea', 'section' => 'story' ),
		);
	}

	/** @return array<string,string> */
	public static function sections() {
		return array(
			'basics'  => __( 'The basics', 'ajcore-ra' ),
			'what'    => __( 'What you do', 'ajcore-ra' ),
			'where'   => __( 'Where you are', 'ajcore-ra' ),
			'contact' => __( 'How customers reach you', 'ajcore-ra' ),
			'links'   => __( 'Social media and listings', 'ajcore-ra' ),
			'online'  => __( 'Website wishes', 'ajcore-ra' ),
			'story'   => __( 'Your story', 'ajcore-ra' ),
		);
	}

	/** Label for a history key (fields, or the upload pseudo-fields). */
	private static function history_label( $key ) {
		$f = self::fields();
		if ( isset( $f[ $key ] ) ) {
			return $f[ $key ]['label'];
		}
		return array( 'logo' => __( 'Logo', 'ajcore-ra' ), 'photo' => __( 'Photo', 'ajcore-ra' ) )[ $key ] ?? $key;
	}

	public static function init() {
		add_filter( 'ajcore_portal_menu_default_items', array( __CLASS__, 'add_tab' ), 9 ); // before the Website tab
		add_filter( 'ajcore_portal_tab_content', array( __CLASS__, 'render_tab' ), 10, 3 );
		add_filter( 'ajcore_admin_portal_tabs', array( __CLASS__, 'admin_tab' ) );
		add_action( 'admin_post_ajcore_ra_save_business_profile', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_ra_staff_save_profile', array( __CLASS__, 'handle_staff_save' ) );
		add_action( 'admin_post_ajcore_ra_profile_reviewed', array( __CLASS__, 'handle_reviewed' ) );
		add_action( 'ajcore_admin_portal_tab_render', array( __CLASS__, 'render_admin_tab' ) );
	}

	// ── data ────────────────────────────────────────────────────────────────────

	/**
	 * @return array{values:array,history:array,files:array,reviewed_at:string,reviewed_by:string,updated:string,exists:bool,needs_review:bool}
	 */
	public static function load( $stripe_customer_id ) {
		$out = array( 'values' => array(), 'history' => array(), 'files' => array(), 'reviewed_at' => '', 'reviewed_by' => '', 'updated' => '', 'exists' => false, 'needs_review' => false );
		$ctx = AJCore_RA_Website::db();
		if ( ! $ctx || '' === (string) $stripe_customer_id ) {
			return $out;
		}
		$db = $ctx['db'];
		$t  = AJCore_RA_Website::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return $out;
		}
		$row = $db->get_row( $db->prepare( "SELECT metadata, updated_at FROM `{$t}` WHERE stripe_customer_id = %s AND entity_type = %s AND entity_key = %s LIMIT 1", $stripe_customer_id, self::TYPE, self::KEY ) );
		if ( ! $row ) {
			return $out;
		}
		$meta = json_decode( (string) $row->metadata, true );
		$meta = is_array( $meta ) ? $meta : array();

		$out['values']      = isset( $meta['values'] ) && is_array( $meta['values'] ) ? $meta['values'] : array();
		$out['history']     = isset( $meta['history'] ) && is_array( $meta['history'] ) ? $meta['history'] : array();
		$out['files']       = isset( $meta['files'] ) && is_array( $meta['files'] ) ? array_values( $meta['files'] ) : array();
		$out['reviewed_at'] = isset( $meta['reviewed_at'] ) ? (string) $meta['reviewed_at'] : '';
		$out['reviewed_by'] = isset( $meta['reviewed_by'] ) ? (string) $meta['reviewed_by'] : '';
		$out['updated']     = (string) $row->updated_at;
		$out['exists']      = true;
		// Needs review: there are changes, and none of them is older than the last "up to date".
		$out['needs_review'] = ! empty( $out['history'] ) && ( '' === $out['reviewed_at'] || strcmp( (string) $out['history'][0]['at'], $out['reviewed_at'] ) > 0 );
		return $out;
	}

	/** Writes the whole record (values, history, files, review state). */
	private static function store( $stripe_customer_id, array $data ) {
		$ctx = AJCore_RA_Website::db();
		if ( ! $ctx ) {
			return false;
		}
		$db = $ctx['db'];
		$t  = AJCore_RA_Website::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return false;
		}
		$row    = array(
			'stripe_customer_id' => $stripe_customer_id,
			'entity_key'         => self::KEY,
			'entity_label'       => isset( $data['values']['business_name'] ) ? (string) $data['values']['business_name'] : '',
			'entity_type'        => self::TYPE,
			'metadata'           => wp_json_encode( array(
				'values'      => $data['values'],
				'history'     => array_slice( $data['history'], 0, self::HISTORY_MAX ),
				'files'       => array_values( $data['files'] ),
				'reviewed_at' => $data['reviewed_at'],
				'reviewed_by' => $data['reviewed_by'],
			) ),
		);
		$fmt    = array( '%s', '%s', '%s', '%s', '%s' );
		$exists = $db->get_var( $db->prepare( "SELECT id FROM `{$t}` WHERE stripe_customer_id = %s AND entity_type = %s AND entity_key = %s LIMIT 1", $stripe_customer_id, self::TYPE, self::KEY ) );
		if ( $exists ) {
			$db->update( $t, $row, array( 'id' => (int) $exists ), $fmt, array( '%d' ) );
		} else {
			$db->insert( $t, $row, $fmt );
		}
		return true;
	}

	private static function clean( $type, $value ) {
		$value = is_string( $value ) ? $value : '';
		switch ( $type ) {
			case 'textarea':
				return mb_substr( sanitize_textarea_field( $value ), 0, 4000 );
			case 'email':
				return sanitize_email( $value );
			case 'url':
				$value = trim( $value );
				return '' === $value ? '' : esc_url_raw( preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value );
			case 'year':
				$y = (int) $value;
				return ( $y >= 1800 && $y <= (int) gmdate( 'Y' ) ) ? (string) $y : '';
			default:
				return mb_substr( sanitize_text_field( $value ), 0, 300 );
		}
	}

	/**
	 * Saves the text fields, recording what changed. Files and review state are kept as they are.
	 *
	 * @return array{ok:bool,changed:int}
	 */
	public static function save( $stripe_customer_id, array $posted, $who ) {
		if ( '' === (string) $stripe_customer_id ) {
			return array( 'ok' => false, 'changed' => 0 );
		}
		$data = self::load( $stripe_customer_id );
		$new  = array();
		$diff = array();
		foreach ( self::fields() as $key => $def ) {
			$new[ $key ] = self::clean( $def['type'], isset( $posted[ $key ] ) ? $posted[ $key ] : '' );
			$old         = isset( $data['values'][ $key ] ) ? (string) $data['values'][ $key ] : '';
			if ( $old !== $new[ $key ] ) {
				$diff[ $key ] = array( mb_substr( $old, 0, 200 ), mb_substr( $new[ $key ], 0, 200 ) );
			}
		}
		if ( ! $diff && $data['exists'] ) {
			return array( 'ok' => true, 'changed' => 0 );
		}
		$data['values'] = $new;
		if ( $diff ) {
			array_unshift( $data['history'], array( 'at' => current_time( 'mysql' ), 'by' => (string) $who, 'changes' => $diff ) );
		}
		return array( 'ok' => self::store( $stripe_customer_id, $data ), 'changed' => count( $diff ) );
	}

	/** Staff: "the website matches this profile now". */
	public static function mark_reviewed( $stripe_customer_id, $who ) {
		$data = self::load( $stripe_customer_id );
		if ( ! $data['exists'] ) {
			return false;
		}
		$data['reviewed_at'] = current_time( 'mysql' );
		$data['reviewed_by'] = (string) $who;
		return self::store( $stripe_customer_id, $data );
	}

	/** Share of text fields filled in, 0-100. */
	public static function completeness( array $values ) {
		$total  = count( self::fields() );
		$filled = 0;
		foreach ( self::fields() as $key => $def ) {
			if ( isset( $values[ $key ] ) && '' !== trim( (string) $values[ $key ] ) ) {
				$filled++;
			}
		}
		return $total ? (int) round( 100 * $filled / $total ) : 0;
	}

	// ── uploads ─────────────────────────────────────────────────────────────────

	private static function upload_token( $stripe_customer_id ) {
		return substr( hash_hmac( 'sha256', (string) $stripe_customer_id, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Handles the logo / photos file inputs of the posted form and the "remove" ticks.
	 *
	 * @return int Number of changes made (files added or unlinked).
	 */
	public static function process_files( $stripe_customer_id, $who ) {
		$data    = self::load( $stripe_customer_id );
		$changes = array();

		// Unlink ticked files (the file itself stays on disk).
		$remove = isset( $_POST['remove_file'] ) && is_array( $_POST['remove_file'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['remove_file'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification -- checked by the caller.
		if ( $remove ) {
			$kept = array();
			foreach ( $data['files'] as $f ) {
				if ( in_array( (string) $f['id'], $remove, true ) ) {
					$changes[ 'logo' === $f['kind'] ? 'logo' : 'photo' ] = array( $f['name'], '' );
				} else {
					$kept[] = $f;
				}
			}
			$data['files'] = $kept;
		}

		$added = array();
		$jobs  = array();
		if ( ! empty( $_FILES['logo']['name'] ) && ! is_array( $_FILES['logo']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$jobs[] = array( 'kind' => 'logo', 'file' => $_FILES['logo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( ! empty( $_FILES['photos']['name'] ) && is_array( $_FILES['photos']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			foreach ( array_keys( $_FILES['photos']['name'] ) as $i ) {
				$jobs[] = array( 'kind' => 'photo', 'file' => array(
					'name'     => $_FILES['photos']['name'][ $i ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'type'     => $_FILES['photos']['type'][ $i ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'tmp_name' => $_FILES['photos']['tmp_name'][ $i ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'error'    => $_FILES['photos']['error'][ $i ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'size'     => $_FILES['photos']['size'][ $i ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				) );
			}
		}

		if ( $jobs ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$token = self::upload_token( $stripe_customer_id );
			$dir   = static function ( $d ) use ( $token ) {
				$sub        = '/ajcore-ra-profile/' . $token;
				$d['subdir'] = $sub;
				$d['path']   = $d['basedir'] . $sub;
				$d['url']    = $d['baseurl'] . $sub;
				return $d;
			};
			add_filter( 'upload_dir', $dir );
			foreach ( $jobs as $job ) {
				$file = $job['file'];
				if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES ) {
					continue;
				}
				$photo_count = count( array_filter( $data['files'], static function ( $f ) {
					return 'photo' === $f['kind'];
				} ) );
				if ( 'photo' === $job['kind'] && $photo_count >= self::MAX_PHOTOS ) {
					continue;
				}
				$moved = wp_handle_upload( $file, array(
					'test_form' => false,
					'mimes'     => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ),
				) );
				if ( ! empty( $moved['error'] ) || empty( $moved['url'] ) ) {
					continue;
				}
				if ( 'logo' === $job['kind'] ) {
					foreach ( $data['files'] as $k => $f ) {
						if ( 'logo' === $f['kind'] ) {
							unset( $data['files'][ $k ] ); // the old logo is unlinked, the file stays
						}
					}
				}
				$name            = sanitize_file_name( (string) $file['name'] );
				$data['files'][] = array(
					'id'   => wp_generate_password( 10, false ),
					'kind' => $job['kind'],
					'name' => $name,
					'url'  => $moved['url'],
					'size' => (int) $file['size'],
					'at'   => current_time( 'mysql' ),
				);
				$changes[ $job['kind'] ] = array( '', $name );
			}
			remove_filter( 'upload_dir', $dir );
		}

		if ( ! $changes ) {
			return 0;
		}
		$data['files'] = array_values( $data['files'] );
		array_unshift( $data['history'], array( 'at' => current_time( 'mysql' ), 'by' => (string) $who, 'changes' => $changes ) );
		self::store( $stripe_customer_id, $data );
		return count( $changes );
	}

	// ── shared form ─────────────────────────────────────────────────────────────

	/** The sections, fields and file inputs, used by the customer tab and the staff page. */
	private static function render_form_body( array $values, array $files ) {
		foreach ( self::sections() as $section_key => $section_label ) :
			?>
			<div class="aj-portal-account-summary" style="margin:0 0 14px;">
				<h3 style="margin:0 0 10px;"><?php echo esc_html( $section_label ); ?></h3>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px 14px;">
					<?php foreach ( self::fields() as $key => $def ) : ?>
						<?php if ( $def['section'] !== $section_key ) { continue; } ?>
						<?php $val = isset( $values[ $key ] ) ? (string) $values[ $key ] : ''; ?>
						<label style="display:block;<?php echo 'textarea' === $def['type'] ? 'grid-column:1/-1;' : ''; ?>">
							<span style="display:block;font-weight:700;margin-bottom:3px;"><?php echo esc_html( $def['label'] ); ?></span>
							<?php if ( 'textarea' === $def['type'] ) : ?>
								<textarea name="<?php echo esc_attr( $key ); ?>" rows="3" style="width:100%;"><?php echo esc_textarea( $val ); ?></textarea>
							<?php else : ?>
								<input type="<?php echo esc_attr( 'year' === $def['type'] ? 'number' : $def['type'] ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>" style="width:100%;" <?php echo 'year' === $def['type'] ? 'min="1800" max="' . esc_attr( gmdate( 'Y' ) ) . '"' : ''; ?>>
							<?php endif; ?>
							<?php if ( ! empty( $def['hint'] ) ) : ?><small style="color:#64748b;"><?php echo esc_html( $def['hint'] ); ?></small><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		endforeach;
		?>
		<div class="aj-portal-account-summary" style="margin:0 0 14px;">
			<h3 style="margin:0 0 10px;"><?php esc_html_e( 'Logo and photos', 'ajcore-ra' ); ?></h3>
			<?php if ( $files ) : ?>
				<div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 12px;">
					<?php foreach ( $files as $f ) : ?>
						<div style="width:120px;text-align:center;font-size:12px;">
							<a href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $f['url'] ); ?>" alt="" style="width:120px;height:90px;object-fit:contain;background:#f1f5f9;border-radius:8px;"></a>
							<div><strong><?php echo 'logo' === $f['kind'] ? esc_html__( 'Logo', 'ajcore-ra' ) : esc_html__( 'Photo', 'ajcore-ra' ); ?></strong></div>
							<label><input type="checkbox" name="remove_file[]" value="<?php echo esc_attr( $f['id'] ); ?>"> <?php esc_html_e( 'Remove', 'ajcore-ra' ); ?></label>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px 14px;">
				<label style="display:block;"><span style="display:block;font-weight:700;margin-bottom:3px;"><?php esc_html_e( 'Logo', 'ajcore-ra' ); ?></span><input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp"><small style="color:#64748b;"><?php esc_html_e( 'A new logo replaces the old one', 'ajcore-ra' ); ?></small></label>
				<label style="display:block;"><span style="display:block;font-weight:700;margin-bottom:3px;"><?php echo esc_html( sprintf( /* translators: %d: max photos */ __( 'Photos (up to %d)', 'ajcore-ra' ), self::MAX_PHOTOS ) ); ?></span><input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/gif,image/webp"><small style="color:#64748b;"><?php esc_html_e( 'JPG, PNG, GIF or WebP, up to 8 MB each', 'ajcore-ra' ); ?></small></label>
			</div>
		</div>
		<?php
	}

	// ── customer tab ────────────────────────────────────────────────────────────

	public static function add_tab( $items ) {
		$items[] = array( 'id' => 'business-profile', 'label' => __( 'Business Profile', 'ajcore-ra' ), 'type' => 'built_in', 'url' => '', 'enabled' => true );
		return $items;
	}

	/**
	 * The logged-in portal customer, resolved by AJCore the same way the portal pages do it. (The
	 * REST toolkit's lookup uses a different mapping table, which is why it must not be used here:
	 * the form would be drawn for one customer and saved for none.)
	 */
	private static function customer_id() {
		return (string) apply_filters( 'ajcore_current_portal_customer_id', '' );
	}

	public static function render_tab( $html, $tab, $context ) {
		if ( 'business-profile' !== $tab ) {
			return $html;
		}
		$customer = isset( $context['stripe_customer_id'] ) ? (string) $context['stripe_customer_id'] : '';
		$data     = self::load( $customer );
		$percent  = self::completeness( $data['values'] );
		$saved    = isset( $_GET['profile_saved'] ) ? sanitize_key( wp_unslash( $_GET['profile_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		ob_start();
		?>
		<section class="aj-customer-portal-panel aj-ra-profile">
			<h2><?php esc_html_e( 'Business Profile', 'ajcore-ra' ); ?></h2>
			<p class="aj-portal-intro-text"><?php esc_html_e( 'Tell us about your business. We use this to build and update your website. You can change it any time.', 'ajcore-ra' ); ?></p>
			<?php if ( 'nochange' === $saved ) : ?>
				<div class="aj-portal-add-service-message is-success"><?php esc_html_e( 'No changes to save.', 'ajcore-ra' ); ?></div>
			<?php elseif ( 'fail' === $saved ) : ?>
				<div class="aj-portal-add-service-message is-error"><?php esc_html_e( 'Your profile could not be saved. Please try again.', 'ajcore-ra' ); ?></div>
			<?php elseif ( '' !== $saved ) : ?>
				<div class="aj-portal-add-service-message is-success"><?php esc_html_e( 'Saved. Thank you!', 'ajcore-ra' ); ?></div>
			<?php endif; ?>
			<p style="margin:0 0 14px;">
				<strong><?php echo esc_html( sprintf( /* translators: %d: percent */ __( '%d%% complete', 'ajcore-ra' ), $percent ) ); ?></strong>
				<?php if ( '' !== $data['updated'] ) : ?>
					<span style="color:#64748b;margin-left:8px;"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Last updated %s', 'ajcore-ra' ), mysql2date( get_option( 'date_format' ), $data['updated'] ) ) ); ?></span>
				<?php endif; ?>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ajcore_ra_save_business_profile">
				<?php wp_nonce_field( 'ajcore_ra_business_profile' ); ?>
				<?php self::render_form_body( $data['values'], $data['files'] ); ?>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save profile', 'ajcore-ra' ); ?></button></p>
			</form>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Front-end form post: always the logged-in customer's own profile, never an id from the form. */
	public static function handle_save() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in.', 'ajcore-ra' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ajcore_ra_business_profile' );
		$customer = self::customer_id();
		$back     = wp_get_referer() ? remove_query_arg( 'profile_saved', wp_get_referer() ) : home_url( '/' );
		if ( '' === $customer ) {
			wp_safe_redirect( add_query_arg( 'profile_saved', 'fail', $back ) );
			exit;
		}
		$user   = wp_get_current_user();
		$who    = 'customer: ' . $user->user_email;
		$result = self::save( $customer, wp_unslash( $_POST ), $who ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field is cleaned by type in save().
		$files  = $result['ok'] ? self::process_files( $customer, $who ) : 0;
		$flag   = ! $result['ok'] ? 'fail' : ( ( $result['changed'] || $files ) ? '1' : 'nochange' );
		wp_safe_redirect( add_query_arg( 'profile_saved', $flag, $back ) );
		exit;
	}

	// ── staff: AJCore admin ─────────────────────────────────────────────────────

	/** "Business Profiles" in the Client Portal tab bar of AJCore admin (opens in that page). */
	public static function admin_tab( $tabs ) {
		$tabs['ra-profiles'] = array( 'label' => __( 'Business Profiles', 'ajcore-ra' ) );
		return $tabs;
	}

	public static function render_admin_tab( $tab ) {
		if ( 'ra-profiles' === $tab ) {
			self::render_admin();
		}
	}

	private static function tab_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => 'ra-profiles' ), $args ), admin_url( 'admin.php' ) );
	}

	/** Plain-text brief of one profile, for pasting into a website build. */
	public static function brief( array $values, array $files = array() ) {
		$lines = array();
		foreach ( self::sections() as $section_key => $section_label ) {
			$part = array();
			foreach ( self::fields() as $key => $def ) {
				if ( $def['section'] === $section_key && isset( $values[ $key ] ) && '' !== trim( (string) $values[ $key ] ) ) {
					$part[] = $def['label'] . ': ' . str_replace( "\n", "\n  ", trim( (string) $values[ $key ] ) );
				}
			}
			if ( $part ) {
				$lines[] = strtoupper( $section_label ) . "\n" . implode( "\n", $part );
			}
		}
		if ( $files ) {
			$part = array();
			foreach ( $files as $f ) {
				$part[] = ( 'logo' === $f['kind'] ? 'Logo' : 'Photo' ) . ': ' . $f['url'];
			}
			$lines[] = "LOGO AND PHOTOS\n" . implode( "\n", $part );
		}
		return implode( "\n\n", $lines );
	}

	/** Everything AJOps needs to show or edit one profile. */
	public static function api_profile( $customer ) {
		$data   = self::load( $customer );
		$names  = AJCore_RA_Website::customers();
		$fields = array();
		foreach ( self::fields() as $key => $def ) {
			$fields[] = array_merge( array( 'key' => $key ), $def );
		}
		$history = array();
		foreach ( $data['history'] as $h ) {
			$changes = array();
			foreach ( (array) $h['changes'] as $key => $pair ) {
				$changes[] = array( 'field' => $key, 'label' => self::history_label( $key ), 'from' => (string) $pair[0], 'to' => (string) $pair[1] );
			}
			$history[] = array( 'at' => (string) $h['at'], 'by' => (string) $h['by'], 'changes' => $changes );
		}
		$sections = array();
		foreach ( self::sections() as $k => $label ) {
			$sections[] = array( 'key' => $k, 'label' => $label );
		}
		return array(
			'customer_id'   => $customer,
			'customer_name' => isset( $names[ $customer ] ) ? $names[ $customer ]['name'] : $customer,
			'exists'        => $data['exists'],
			'sections'      => $sections,
			'fields'        => $fields,
			'values'        => (object) $data['values'],
			'files'         => $data['files'],
			'history'       => $history,
			'completeness'  => self::completeness( $data['values'] ),
			'updated'       => $data['updated'],
			'reviewed_at'   => $data['reviewed_at'],
			'reviewed_by'   => $data['reviewed_by'],
			'needs_review'  => $data['needs_review'],
			'brief'         => self::brief( $data['values'], $data['files'] ),
		);
	}

	/** One row per customer that has a profile, for lists. */
	public static function api_list() {
		$ctx = AJCore_RA_Website::db();
		if ( ! $ctx ) {
			return array();
		}
		$db = $ctx['db'];
		$t  = AJCore_RA_Website::table( $ctx, 'aj_portal_entity_mappings' );
		if ( ! $ctx['kit']['table_exists']( $db, $t ) ) {
			return array();
		}
		$names = AJCore_RA_Website::customers();
		$out   = array();
		foreach ( (array) $db->get_results( $db->prepare( "SELECT stripe_customer_id, entity_label, metadata, updated_at FROM `{$t}` WHERE entity_type = %s ORDER BY updated_at DESC", self::TYPE ) ) as $r ) {
			$meta    = json_decode( (string) $r->metadata, true );
			$meta    = is_array( $meta ) ? $meta : array();
			$vals    = isset( $meta['values'] ) && is_array( $meta['values'] ) ? $meta['values'] : array();
			$history = isset( $meta['history'] ) && is_array( $meta['history'] ) ? $meta['history'] : array();
			$rev     = isset( $meta['reviewed_at'] ) ? (string) $meta['reviewed_at'] : '';
			$out[]   = array(
				'customer_id'   => (string) $r->stripe_customer_id,
				'customer_name' => isset( $names[ $r->stripe_customer_id ] ) ? $names[ $r->stripe_customer_id ]['name'] : (string) $r->stripe_customer_id,
				'business'      => (string) $r->entity_label,
				'completeness'  => self::completeness( $vals ),
				'updated'       => (string) $r->updated_at,
				'needs_review'  => ! empty( $history ) && ( '' === $rev || strcmp( (string) $history[0]['at'], $rev ) > 0 ),
				'files'         => isset( $meta['files'] ) && is_array( $meta['files'] ) ? count( $meta['files'] ) : 0,
			);
		}
		return $out;
	}

	public static function handle_staff_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_staff_save_profile' );
		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$user     = wp_get_current_user();
		$who      = 'staff: ' . $user->user_login;
		$result   = self::save( $customer, wp_unslash( $_POST ), $who ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by type in save().
		if ( $result['ok'] ) {
			self::process_files( $customer, $who );
		}
		wp_safe_redirect( self::tab_url( array( 'customer' => $customer, 'ra_msg' => $result['ok'] ? 'saved' : 'fail' ) ) );
		exit;
	}

	public static function handle_reviewed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore-ra' ) );
		}
		check_admin_referer( 'ajcore_ra_profile_reviewed' );
		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$user     = wp_get_current_user();
		self::mark_reviewed( $customer, 'staff: ' . $user->user_login );
		wp_safe_redirect( self::tab_url( array( 'customer' => $customer, 'ra_msg' => 'reviewed' ) ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="ajforms-settings-card"><h2>' . esc_html__( 'Business Profiles', 'ajcore-ra' ) . '</h2>';
		if ( ! AJCore_RA_Website::db() ) {
			echo '<p>' . esc_html__( 'AJ Core is not ready.', 'ajcore-ra' ) . '</p></div>';
			return;
		}
		$selected = isset( $_GET['customer'] ) ? sanitize_text_field( wp_unslash( $_GET['customer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $selected ) {
			self::render_detail( $selected );
			echo '</div>';
			return;
		}

		$rows = self::api_list();
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No customer has filled in a profile yet.', 'ajcore-ra' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Customer', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Business', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Complete', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Updated', 'ajcore-ra' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url = self::tab_url( array( 'customer' => $r['customer_id'] ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $r['customer_name'] ) . '</a></td><td>' . esc_html( $r['business'] ) . '</td><td>' . esc_html( $r['completeness'] . '%' ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $r['updated'] ) ) . '</td><td>' . ( $r['needs_review'] ? '<span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:10px;font-weight:700;font-size:12px">' . esc_html__( 'Needs review', 'ajcore-ra' ) . '</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		}
		echo '</tbody></table></div>';
	}

	private static function render_detail( $customer ) {
		$data = self::load( $customer );
		$p    = self::api_profile( $customer );
		$back = self::tab_url();

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All profiles', 'ajcore-ra' ) . '</a></p>';
		if ( isset( $_GET['ra_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = sanitize_key( wp_unslash( $_GET['ra_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-' . ( 'fail' === $msg ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( 'reviewed' === $msg ? __( 'Marked up to date.', 'ajcore-ra' ) : ( 'fail' === $msg ? __( 'Could not save.', 'ajcore-ra' ) : __( 'Saved.', 'ajcore-ra' ) ) ) . '</p></div>';
		}
		echo '<h2>' . esc_html( $p['customer_name'] ) . ' <small>· ' . esc_html( $p['completeness'] . '%' ) . '</small>';
		if ( $p['needs_review'] ) {
			echo ' <span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:10px;font-weight:700;font-size:12px">' . esc_html__( 'Needs review', 'ajcore-ra' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static.
		}
		echo '</h2>';
		if ( $data['exists'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 14px">';
			echo '<input type="hidden" name="action" value="ajcore_ra_profile_reviewed"><input type="hidden" name="customer" value="' . esc_attr( $customer ) . '">';
			wp_nonce_field( 'ajcore_ra_profile_reviewed' );
			echo '<button type="submit" class="button">' . esc_html__( 'Website is up to date', 'ajcore-ra' ) . '</button> ';
			if ( '' !== $data['reviewed_at'] ) {
				echo '<span style="color:#646970">' . esc_html( sprintf( __( 'Last marked up to date %1$s by %2$s', 'ajcore-ra' ), mysql2date( 'M j, Y g:i a', $data['reviewed_at'] ), $data['reviewed_by'] ) ) . '</span>';
			}
			echo '</form>';
		}

		// Edit on the customer's behalf.
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:960px">';
		echo '<input type="hidden" name="action" value="ajcore_ra_staff_save_profile"><input type="hidden" name="customer" value="' . esc_attr( $customer ) . '">';
		wp_nonce_field( 'ajcore_ra_staff_save_profile' );
		self::render_form_body( $data['values'], $data['files'] );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'ajcore-ra' ) . '</button></form>';

		echo '<h3 style="margin-top:20px">' . esc_html__( 'Brief for the website build', 'ajcore-ra' ) . '</h3>';
		echo '<textarea readonly rows="10" class="large-text code" style="max-width:960px" onclick="this.select()">' . esc_textarea( $p['brief'] ) . '</textarea>';

		echo '<h3 style="margin-top:20px">' . esc_html__( 'Change history', 'ajcore-ra' ) . '</h3>';
		if ( ! $p['history'] ) {
			echo '<p>' . esc_html__( 'No changes recorded.', 'ajcore-ra' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th style="width:150px">' . esc_html__( 'When', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Who', 'ajcore-ra' ) . '</th><th>' . esc_html__( 'Changes', 'ajcore-ra' ) . '</th></tr></thead><tbody>';
		foreach ( $p['history'] as $h ) {
			$parts = array();
			foreach ( $h['changes'] as $c ) {
				$parts[] = '<strong>' . esc_html( $c['label'] ) . '</strong>: ' . ( '' === $c['from'] ? '<em>' . esc_html__( '(empty)', 'ajcore-ra' ) . '</em>' : esc_html( $c['from'] ) ) . ' &rarr; ' . ( '' === $c['to'] ? '<em>' . esc_html__( '(cleared)', 'ajcore-ra' ) . '</em>' : esc_html( $c['to'] ) );
			}
			echo '<tr><td>' . esc_html( mysql2date( 'M j, Y g:i a', $h['at'] ) ) . '</td><td>' . esc_html( $h['by'] ) . '</td><td>' . implode( '<br>', $parts ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</tbody></table>';
	}
}
