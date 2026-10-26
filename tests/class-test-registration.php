<?php
/**
 * Registration tests for every ability the plugin provides.
 *
 * Runs under PHPUnit (npm run test:wp), so a pass also shows tests/bootstrap.php
 * loads the plugin into the WordPress test library.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities\Tests;

use WP_Ability;
use WP_UnitTestCase;

/**
 * Registration tests.
 */
class Test_Registration extends WP_UnitTestCase {

	/**
	 * Returns every ability name the plugin registers, keyed by name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function ability_names(): array {
		$names = [
			'hm-abilities/whoami',
			'hm-abilities/list-content',
			'hm-abilities/list-recent-articles',
			'hm-abilities/get-content',
			'hm-abilities/create-content',
			'hm-abilities/update-content',
			'hm-abilities/list-terms',
			'hm-abilities/list-users',
			'hm-abilities/upload-media',
			'hm-abilities/list-navigation',
			'hm-abilities/get-navigation',
			'hm-abilities/update-navigation',
			'hm-abilities/get-settings',
			'hm-abilities/update-settings',
			'hm-abilities/validate-blocks',
			'hm-abilities/list-block-types',
			'hm-abilities/block-usage-stats',
			'hm-abilities/get-page-blocks',
			'hm-abilities/find-missing-block',
			'hm-abilities/list-network-sites',
		];

		return array_combine( $names, array_map( fn ( $name ) => [ $name ], $names ) );
	}

	/**
	 * Asserts the ability is registered under the plugin's category.
	 *
	 * @dataProvider ability_names
	 *
	 * @param string $name Ability name.
	 * @return void
	 */
	public function test_ability_is_registered_in_the_plugin_category( string $name ): void {
		$ability = wp_get_ability( $name );

		$this->assertInstanceOf( WP_Ability::class, $ability, "$name is not registered." );
		$this->assertSame( 'hm-abilities', $ability->get_category() );
	}
}
