<?php
/**
 * Constants the analyser cannot see.
 *
 * `bogo-select.php` defines these with `define()` at load time, which PHPStan
 * does not execute, so every use of one is reported as an unknown constant.
 * Their values here are placeholders of the right *type* — nothing reads them,
 * and the analyser only needs to know that `BOGO_SELECT_URL` is a string.
 *
 * @package BOGO_Select
 */

define( 'BOGO_SELECT_VERSION', '0.0.0' );
define( 'BOGO_SELECT_MIN_WC', '0.0' );
define( 'BOGO_SELECT_FILE', __FILE__ );
define( 'BOGO_SELECT_PATH', __DIR__ . '/' );
define( 'BOGO_SELECT_URL', 'https://example.test/wp-content/plugins/bogo-select/' );
define( 'BOGO_SELECT_BASENAME', 'bogo-select/bogo-select.php' );
