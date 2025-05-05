# NLDX Update Checker Class for WordPress plugins

## Getting started

```
composer require nldx/wp-plugin-update-checker-class
```

## Useage

```

define( 'NLDX_BOOTSTRAP_BLOCKS_VERSION', '1.0.0' );
define( 'NLDX_BOOTSTRAP_BLOCKS_FILE', __FILE__ );
define( 'NLDX_BOOTSTRAP_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NLDX_BOOTSTRAP_BLOCKS_SLUG', dirname ( plugin_basename( __FILE__ ) ) );
define( 'NLDX_BOOTSTRAP_BLOCKS_INFO', 'https://example.domain.com/example-plugin/info.json');

/**
 * Include classes.
 */
 
if ( !class_exists( \NLDX\UpdateChecker::class ) ) {
	require_once NLDX_BOOTSTRAP_BLOCKS_DIR . 'vendor/nldx/wp-plugin-update-checker-class/class-update-checker.php';
}

/**
 * Check for updates.
 */

use NLDX\UpdateChecker;
new UpdateChecker(
	NLDX_BOOTSTRAP_BLOCKS_SLUG,
	NLDX_BOOTSTRAP_BLOCKS_VERSION,
	NLDX_BOOTSTRAP_BLOCKS_INFO
);

```
