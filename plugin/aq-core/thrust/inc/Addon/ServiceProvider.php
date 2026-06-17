<?php
declare(strict_types=1);

namespace WP_Rocket\Addon;

use WP_Rocket\Dependencies\League\Container\ServiceProvider\AbstractServiceProvider;

/**
 * Service provider for WP Rocket addons.
 *
 * Boost: every former addon is gone — Sucuri (third-party firewall account) and
 * WebP (only affected WP Rocket's own page cache, which Pressable replaces, and it
 * depended on the removed CDN subscriber). The provider is kept registered but
 * empty so Plugin.php's registration line stays valid.
 */
class ServiceProvider extends AbstractServiceProvider {
	/**
	 * Array of services provided by this service provider
	 *
	 * @var array
	 */
	protected $provides = [];

	/**
	 * Check if the service provider provides a specific service.
	 *
	 * @param string $id The id of the service.
	 *
	 * @return bool
	 */
	public function provides( string $id ): bool {
		return in_array( $id, $this->provides, true );
	}

	/**
	 * Registers items with the container
	 */
	public function register(): void {
	}
}
