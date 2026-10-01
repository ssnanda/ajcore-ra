<?php
/**
 * GitHub-release updater for AJCore RA, same source/rules as AJCore's own updater:
 * latest release by default, or the highest-version release (incl. prereleases from
 * non-main branches) when developer updates are on. Plugs into WordPress' native update
 * flow, so the Plugins screen shows "update available" and the normal Update Now works.
 * Runs independently of AJCore so RA can still be updated if AJCore is inactive.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_RA_Updater {

	const REPO       = 'ssnanda/ajcore-ra';
	const OPT_DEV    = 'ajcore_ra_developer_updates_enabled';
	const CACHE_PLAIN = 'ajcore_ra_latest_release_info';
	const CACHE_DEV   = 'ajcore_ra_latest_developer_release_info';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'plugin_action_links_' . AJCORE_RA_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	private static function dev_enabled() {
		return '1' === (string) get_option( self::OPT_DEV, '0' );
	}

	private static function cache_key() {
		return self::dev_enabled() ? self::CACHE_DEV : self::CACHE_PLAIN;
	}

	private static function release_version( $release ) {
		foreach ( array( 'tag_name', 'name' ) as $field ) {
			if ( isset( $release[ $field ] ) && preg_match( '/([0-9]+\.[0-9]+\.[0-9]+)/', (string) $release[ $field ], $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	private static function normalize( $release ) {
		$version = self::release_version( $release );
		if ( '' === $version ) {
			return null;
		}
		$tag = isset( $release['tag_name'] ) ? sanitize_text_field( (string) $release['tag_name'] ) : '';
		$url = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( preg_match( '/^ajcore-ra.*\.zip$/i', (string) ( $asset['name'] ?? '' ) ) && ! empty( $asset['browser_download_url'] ) ) {
				$url = esc_url_raw( (string) $asset['browser_download_url'] );
				break;
			}
		}
		if ( '' === $url ) {
			$url = 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode( $tag ) . '/ajcore-ra-' . rawurlencode( $version ) . '.zip';
		}
		return array(
			'version'      => $version,
			'download_url' => $url,
			'html_url'     => isset( $release['html_url'] ) ? esc_url_raw( (string) $release['html_url'] ) : '',
			'prerelease'   => ! empty( $release['prerelease'] ),
		);
	}

	/** @return array|null Release info, or null when unavailable. Cached 6h. */
	public static function latest_release( $force = false ) {
		$key = self::cache_key();
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}
		}

		$dev      = self::dev_enabled();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases' . ( $dev ? '' : '/latest' ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AJCoreRA/' . AJCORE_RA_VERSION . '; ' . home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		if ( $dev ) {
			$best = null;
			foreach ( $body as $release ) {
				if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
					continue;
				}
				$info = self::normalize( $release );
				if ( $info && ( null === $best || version_compare( $info['version'], $best['version'], '>' ) ) ) {
					$best = $info;
				}
			}
			$info = $best;
		} else {
			$info = self::normalize( $body );
		}

		if ( $info ) {
			set_transient( $key, $info, 6 * HOUR_IN_SECONDS );
		}
		return $info;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => 'ajcore-ra',
			'plugin'      => AJCORE_RA_BASENAME,
			'new_version' => $release['version'],
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $release['download_url'],
		);
		if ( version_compare( $release['version'], AJCORE_RA_VERSION, '>' ) ) {
			$transient->response[ AJCORE_RA_BASENAME ] = $item;
			unset( $transient->no_update[ AJCORE_RA_BASENAME ] );
		} else {
			$transient->no_update[ AJCORE_RA_BASENAME ] = $item;
			unset( $transient->response[ AJCORE_RA_BASENAME ] );
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'ajcore-ra' !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		return (object) array(
			'name'          => 'AJ Core RA',
			'slug'          => 'ajcore-ra',
			'version'       => $release ? $release['version'] : AJCORE_RA_VERSION,
			'download_link' => $release ? $release['download_url'] : '',
			'homepage'      => 'https://github.com/' . self::REPO,
			'sections'      => array( 'description' => 'Registered Agent extension for AJ Core.' ),
		);
	}

	public static function action_links( $links ) {
		$check = wp_nonce_url( add_query_arg( 'ajcore_ra_act', 'check', admin_url( 'plugins.php' ) ), 'ajcore_ra_update_check' );
		$dev   = wp_nonce_url( add_query_arg( 'ajcore_ra_act', self::dev_enabled() ? 'dev_off' : 'dev_on', admin_url( 'plugins.php' ) ), 'ajcore_ra_update_dev' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $check ) . '">' . esc_html__( 'Check for updates', 'ajcore-ra' ) . '</a>',
			'<a href="' . esc_url( $dev ) . '">' . esc_html( self::dev_enabled() ? __( 'Dev updates: on', 'ajcore-ra' ) : __( 'Dev updates: off', 'ajcore-ra' ) ) . '</a>'
		);
		return $links;
	}

	public static function handle_actions() {
		if ( empty( $_GET['ajcore_ra_act'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$act = sanitize_key( wp_unslash( $_GET['ajcore_ra_act'] ) );
		if ( 'check' === $act ) {
			check_admin_referer( 'ajcore_ra_update_check' );
		} elseif ( 'dev_on' === $act || 'dev_off' === $act ) {
			check_admin_referer( 'ajcore_ra_update_dev' );
			update_option( self::OPT_DEV, 'dev_on' === $act ? '1' : '0' );
		} else {
			return;
		}
		delete_transient( self::CACHE_PLAIN );
		delete_transient( self::CACHE_DEV );
		delete_site_transient( 'update_plugins' ); // Forces WP to re-run inject_update().
		wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
		exit;
	}
}
