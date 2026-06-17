<?php
/**
 * Plugin Name: Bono Leads Connector
 * Description: Captures WordPress form submissions and sends them to Bono.
 * Version: 0.6.3
 * Author: Bono
 * Requires PHP: 7.4
 * Text Domain: bono-leads-connector
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BONO_PLUGIN_VERSION', '0.6.3' );
define( 'BONO_PLUGIN_FILE', __FILE__ );
define( 'BONO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BONO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Default Bono API base URL used by the "Connect to Bono" flow.
// Currently points to STAGING (https://dev.bono.let.co.il/api) — the plugin has
// not been released to production yet. Switch to the production URL at release.
// Can be overridden per-site by defining BONO_API_BASE_URL in wp-config.php, or
// via the settings field (both take precedence over this default).
if ( ! defined( 'BONO_DEFAULT_API_BASE_URL' ) ) {
	define( 'BONO_DEFAULT_API_BASE_URL', 'https://dev.bono.let.co.il/api' );
}

// Load Action Scheduler as early as possible when bundled (vendored via Composer).
// It self-negotiates its version across plugins and registers the as_* functions.
// When absent, the submission queue gracefully falls back to WP-Cron.
$bono_action_scheduler = BONO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

if ( file_exists( $bono_action_scheduler ) ) {
	require_once $bono_action_scheduler;
}

// Self-update from the private distribution channel (public dist repo on GitHub,
// holding only release ZIPs). plugin-update-checker reads its Releases tokenless
// and installs the attached bono-leads-connector.zip asset. When the library is
// absent (e.g. an unbuilt source checkout), updates are simply unavailable — the
// plugin still runs. Disable entirely with define('BONO_DISABLE_UPDATE_CHECK', true).
$bono_update_checker_lib = BONO_PLUGIN_PATH . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

if ( ! defined( 'BONO_UPDATE_REPO_URL' ) ) {
	define( 'BONO_UPDATE_REPO_URL', 'https://github.com/LET-GROUP-LTD/bono-wordpress-plugin/' );
}

if (
	! ( defined( 'BONO_DISABLE_UPDATE_CHECK' ) && BONO_DISABLE_UPDATE_CHECK )
	&& file_exists( $bono_update_checker_lib )
) {
	require_once $bono_update_checker_lib;

	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		$bono_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			BONO_UPDATE_REPO_URL,
			BONO_PLUGIN_FILE,
			'bono-leads-connector'
		);
		$bono_update_checker->setBranch( 'main' );

		// Install the attached release asset (our built ZIP), not GitHub's
		// auto-generated source tarball which omits vendored dependencies.
		$bono_update_checker->getVcsApi()->enableReleaseAssets( '/bono-leads-connector\.zip$/' );
	}
}

$bono_plugin_class_file = BONO_PLUGIN_PATH . 'includes/class-bono-plugin.php';

if ( file_exists( $bono_plugin_class_file ) ) {
	require_once $bono_plugin_class_file;

	if ( class_exists( 'Bono_Plugin' ) ) {
		register_activation_hook( __FILE__, array( 'Bono_Plugin', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'Bono_Plugin', 'deactivate' ) );

		$bono_plugin = new Bono_Plugin();
		$bono_plugin->run();
	}
}
