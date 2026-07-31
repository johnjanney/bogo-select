<?php
/**
 * PHPUnit bootstrap for the BOGO Select unit suite.
 *
 * @package BOGO_Select
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'BOGO_SELECT_VERSION', '1.2.0' );
define( 'BOGO_SELECT_URL', 'https://example.test/wp-content/plugins/bogo-select/' );
define( 'BOGO_SELECT_PATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';

require_once dirname( __DIR__ ) . '/includes/class-bogo-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-cart.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-frontend.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-ajax.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-blocks.php';

require_once __DIR__ . '/TestCase.php';
