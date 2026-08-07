<?php
/**
 * HM Abilities MCP main file.
 *
 * @package hm-abilities-mcp
 */

namespace HM\Abilities;

/**
 * Wire up each module's hooks.
 *
 * The modules register against the Abilities API actions
 * (`wp_abilities_api_categories_init`, `wp_abilities_api_init`), so this runs at
 * plugin load; the callbacks fire when the Abilities API initializes.
 *
 * @return void
 */
function bootstrap(): void {
	Category\bootstrap();
	Whoami\bootstrap();
	Content\bootstrap();
	Media\bootstrap();
	Navigation\bootstrap();
	Settings\bootstrap();
	Blocks\bootstrap();
	BlockInsights\bootstrap();
}

bootstrap();