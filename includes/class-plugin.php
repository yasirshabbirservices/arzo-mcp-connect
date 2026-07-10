<?php
/**
 * Plugin orchestrator (composition root).
 *
 * @package Arzo\MCP
 */

declare(strict_types=1);

namespace Arzo\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the subsystems together and registers their hooks. Single instance.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Metadata */
	private $metadata;

	/** @var OAuth_Store */
	private $store;

	/** @var Tokens */
	private $tokens;

	/** @var OAuth_REST */
	private $rest;

	/** @var Authorize */
	private $authorize;

	/** @var Bearer_Auth */
	private $bearer;

	/** @var Admin */
	private $admin;

	private function __construct() {
		$this->store     = new OAuth_Store();
		$this->tokens    = new Tokens( $this->store );
		$this->metadata  = new Metadata();
		$this->rest      = new OAuth_REST( $this->store, $this->tokens );
		$this->authorize = new Authorize( $this->store );
		$this->bearer    = new Bearer_Auth( $this->tokens, $this->metadata );
		$this->admin     = new Admin();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks.
	 */
	public function register(): void {
		$this->metadata->register();
		$this->rest->register();
		$this->authorize->register();
		$this->bearer->register();
		if ( is_admin() ) {
			$this->admin->register();
		}
	}
}
