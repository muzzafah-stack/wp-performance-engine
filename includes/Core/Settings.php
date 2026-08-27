<?php
namespace WPPE\Core;

use WPPE\Security\SecurityHelper;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	private static ?Settings $instance = null;
	private array $settings = [];
	private string $option_name = 'wppe_settings';

	private array $defaults = [
		'debug_mode'                      => false,
		'enable_html_cache'               => false,
		'cache_exclusions'                => '',
		'enable_script_delay'             => false,
		'delay_timeout'                   => 5000,
		'delay_exclusions'                => '',
		'delay_allowlist'                 => '',
		'enable_lcp_priority'             => false,
		'enable_speculation'              => false,
		'speculation_mode'                => 'balanced',
		'cloudflare_api_token'            => '',
		'cloudflare_zone_id'              => '',
		'db_cleanup_revisions_retention'  => 10,
		'telemetry_opt_in'                => false,
		'failsafe_enabled'                => true,
	];

	private array $encrypted_fields = [
		'cloudflare_api_token',
	];

	private function __construct() {
		$this->load();
	}

	public static function get_instance(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load settings from DB.
	 */
	public function load(): void {
		$stored = get_option( $this->option_name, [] );
		$this->settings = array_merge( $this->defaults, is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Key name.
	 * @param mixed  $default Default value if not set.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		if ( ! array_key_exists( $key, $this->settings ) ) {
			return $default ?? $this->defaults[ $key ] ?? null;
		}

		$value = $this->settings[ $key ];

		// Decrypt if encrypted field.
		if ( in_array( $key, $this->encrypted_fields, true ) && ! empty( $value ) ) {
			$value = SecurityHelper::decrypt( $value );
		}

		return $value;
	}

	/**
	 * Set a setting value.
	 *
	 * @param string $key   Key name.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, mixed $value ): void {
		// Encrypt if encrypted field.
		if ( in_array( $key, $this->encrypted_fields, true ) && ! empty( $value ) ) {
			$value = SecurityHelper::encrypt( $value );
		}

		$this->settings[ $key ] = $value;
	}

	/**
	 * Save settings to DB.
	 */
	public function save(): bool {
		return update_option( $this->option_name, $this->settings );
	}

	/**
	 * Delete settings from DB.
	 */
	public function delete(): bool {
		$this->settings = $this->defaults;
		return delete_option( $this->option_name );
	}

	/**
	 * Get all raw settings.
	 */
	public function get_all(): array {
		$decrypted = [];
		foreach ( $this->settings as $key => $value ) {
			$decrypted[ $key ] = $this->get( $key );
		}
		return $decrypted;
	}
}
