<?php
/**
 * Main plugin bootstrap.
 *
 * @package BonoLeadsConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bono_Plugin {
	/**
	 * Settings handler.
	 *
	 * @var Bono_Settings|null
	 */
	private $settings = null;

	/**
	 * API client.
	 *
	 * @var Bono_API_Client|null
	 */
	private $api_client = null;

	/**
	 * Submission queue.
	 *
	 * @var Bono_Submission_Queue|null
	 */
	private $submission_queue = null;

	/**
	 * Status REST endpoint.
	 *
	 * @var Bono_Status_Endpoint|null
	 */
	private $status_endpoint = null;

	/**
	 * Load dependencies and register hooks.
	 *
	 * @return void
	 */
	public function run() {
		$this->load_dependencies();

		// Load bundled translations (e.g. Hebrew) from /languages. Hooked on
		// init, the recommended point for translation loading.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( class_exists( 'Bono_Settings' ) ) {
			$this->settings = new Bono_Settings();
			$this->settings->register_hooks();
		}

		if ( class_exists( 'Bono_API_Client' ) ) {
			$this->api_client = new Bono_API_Client();
		}

		if (
			$this->api_client instanceof Bono_API_Client &&
			class_exists( 'Bono_Submission_Queue' )
		) {
			$this->submission_queue = new Bono_Submission_Queue( $this->api_client );
			// register_hooks() schedules the recurring sweep on `init` (the
			// Action-Scheduler-safe point), so no direct scheduling call here.
			$this->submission_queue->register_hooks();
		}

		if ( class_exists( 'Bono_Status_Endpoint' ) ) {
			$this->status_endpoint = new Bono_Status_Endpoint( $this->submission_queue );
			$this->status_endpoint->register_hooks();
		}

		add_action( 'plugins_loaded', array( $this, 'initialize_integrations' ) );
	}

	/**
	 * Load the plugin text domain so bundled translations apply.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'bono-leads-connector',
			false,
			dirname( plugin_basename( BONO_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::load_core_dependencies();

		if ( class_exists( 'Bono_API_Client' ) && class_exists( 'Bono_Submission_Queue' ) ) {
			$queue = new Bono_Submission_Queue( new Bono_API_Client() );
			$queue->create_table();
			// The recurring sweep is scheduled on the next normal load via the
			// `init` hook (Action-Scheduler-safe); avoids scheduling too early
			// during activation before Action Scheduler's store is ready.
		}
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::load_core_dependencies();

		if ( class_exists( 'Bono_API_Client' ) && class_exists( 'Bono_Submission_Queue' ) ) {
			$queue = new Bono_Submission_Queue( new Bono_API_Client() );
			$queue->unschedule_cron();
		}
	}

	/**
	 * Load required class files if they exist.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$core_files = array(
			'includes/class-bono-settings.php',
			'includes/class-bono-api-client.php',
			'includes/class-bono-field-mapping.php',
			'includes/class-bono-form-capture.php',
			'includes/class-bono-submission-queue.php',
			'includes/class-bono-status-endpoint.php',
		);

		foreach ( $core_files as $file ) {
			$path = BONO_PLUGIN_PATH . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( 'Bono_Form_Capture' ) ) {
			return;
		}

		$integration_files = array(
			'includes/class-bono-cf7-capture.php',
			'includes/class-bono-elementor-capture.php',
			'includes/class-bono-wpforms-capture.php',
			'includes/class-bono-gravity-capture.php',
			'includes/class-bono-fluent-capture.php',
			'includes/class-bono-forminator-capture.php',
			'includes/class-bono-generic-capture.php',
		);

		foreach ( $integration_files as $file ) {
			$path = BONO_PLUGIN_PATH . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Load core class files for lifecycle hooks.
	 *
	 * @return void
	 */
	private static function load_core_dependencies() {
		$core_files = array(
			'includes/class-bono-settings.php',
			'includes/class-bono-api-client.php',
			'includes/class-bono-form-capture.php',
			'includes/class-bono-submission-queue.php',
		);

		foreach ( $core_files as $file ) {
			$path = BONO_PLUGIN_PATH . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Initialize optional form integrations after other plugins load.
	 *
	 * @return void
	 */
	public function initialize_integrations() {
		if ( ! $this->api_client instanceof Bono_API_Client ) {
			return;
		}

		if ( class_exists( 'Bono_CF7_Capture' ) ) {
			$cf7_capture = new Bono_CF7_Capture( $this->api_client, $this->submission_queue );
			$cf7_capture->register_hooks();
		}

		if ( class_exists( 'Bono_Elementor_Capture' ) ) {
			$elementor_capture = new Bono_Elementor_Capture( $this->api_client, $this->submission_queue );
			$elementor_capture->register_hooks();
		}

		if ( class_exists( 'Bono_WPForms_Capture' ) ) {
			$wpforms_capture = new Bono_WPForms_Capture( $this->api_client, $this->submission_queue );
			$wpforms_capture->register_hooks();
		}

		if ( class_exists( 'Bono_Gravity_Capture' ) ) {
			$gravity_capture = new Bono_Gravity_Capture( $this->api_client, $this->submission_queue );
			$gravity_capture->register_hooks();
		}

		if ( class_exists( 'Bono_Fluent_Capture' ) ) {
			$fluent_capture = new Bono_Fluent_Capture( $this->api_client, $this->submission_queue );
			$fluent_capture->register_hooks();
		}

		if ( class_exists( 'Bono_Forminator_Capture' ) ) {
			$forminator_capture = new Bono_Forminator_Capture( $this->api_client, $this->submission_queue );
			$forminator_capture->register_hooks();
		}

		if ( class_exists( 'Bono_Generic_Capture' ) ) {
			$generic_capture = new Bono_Generic_Capture( $this->api_client, $this->submission_queue );
			$generic_capture->register_hooks();
		}
	}
}
