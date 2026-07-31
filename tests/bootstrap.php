<?php
/**
 * PHPUnit bootstrap for the BOGO Select unit suite.
 *
 * @package BOGO_Select
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';

require_once dirname( __DIR__ ) . '/includes/class-bogo-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-bogo-cart.php';

require_once __DIR__ . '/TestCase.php';
