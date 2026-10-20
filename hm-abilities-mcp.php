<?php
/**
 * Plugin Name:       HM Abilities MCP
 * Description:       Registers a set of WordPress Abilities API entries (whoami, content CRUD, media sideload, navigation, settings read/write, block helpers) flagged for exposure over MCP.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Human Made
 * License:           GPL-2.0-or-later
 * Text Domain:       hm-abilities-mcp
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

namespace HM\Abilities;

defined( 'ABSPATH' ) || exit;

// Ability modules. Each file registers its own hooks via a bootstrap() function.
require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/support.php';
require_once __DIR__ . '/inc/category.php';
require_once __DIR__ . '/inc/whoami.php';
require_once __DIR__ . '/inc/content.php';
require_once __DIR__ . '/inc/terms.php';
require_once __DIR__ . '/inc/users.php';
require_once __DIR__ . '/inc/media.php';
require_once __DIR__ . '/inc/media-data.php';
require_once __DIR__ . '/inc/navigation.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/blocks.php';
require_once __DIR__ . '/inc/block-insights.php';
require_once __DIR__ . '/inc/sites.php';

bootstrap();
