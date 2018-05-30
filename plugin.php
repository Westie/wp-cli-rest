<?php
/**
Plugin Name: WP-CLI Rest Endpoint
Plugin URI:  https://github.com/Westie/wp-cli-rest/
Description: WP-CLI endpoint but via REST
Version:     0.1.0
Author:      David Weston
Author URI:  https://typefish.co.uk
License:     MIT
*/


defined( 'ABSPATH' ) or die( 'No direct access.' );


class REST_API_Plugin_WP_CLI
{
	const VERSION = '0.1.0';
	const TEXT_DOMAIN = 'wp-cli-rest';
	
	
	/**
	 *	Called when the API is loaded
	 */
	public function __construct()
	{
		add_action('plugins_loaded', [ $this, "init" ], 0);
		add_action('rest_api_init', [ $this, "init_api" ], 0);
	}
	
	
	/**
	 *	Initialise plugin.
	 */
	public function init()
	{
		return true;
	}
	
	
	/**
	 * Initialize API.
	 */
	public function init_api()
	{
		register_rest_route("wp-cli-rest/v1", "run", [
			"methods" => "GET",
			"callback" => [ $this, "run" ],
		]);
	}
	
	
	/**
	 *	Run
	 */
	public function run($data)
	{
		set_time_limit(86400);
		ini_set("memory_limit", "2048M");
		
		# book keeping
		define("WP_CLI", true);
		define("WP_CLI_ROOT", "phar://".__DIR__."/wp-cli.phar");
		
		if(!defined('STDIN'))
			define('STDIN',  fopen('php://temp',  'r'));
		
		if(!defined('STDOUT'))
			define('STDOUT', fopen('php://temp', 'w'));
		
		if(!defined('STDERR'))
			define('STDERR', fopen('php://temp', 'w'));
		
		$GLOBALS["argv"] = array_merge([ WP_CLI_ROOT ], $data->get_param("args") ?: []);
		$GLOBALS["argc"] = count($GLOBALS["argv"]);
		
		# load shit
		require_once WP_CLI_ROOT."/php/utils.php";
		require_once WP_CLI_ROOT."/php/dispatcher.php";
		require_once WP_CLI_ROOT."/php/class-wp-cli-command.php";
		require_once WP_CLI_ROOT."/php/class-wp-cli.php";
		require_once WP_CLI_ROOT."/vendor/autoload.php";
		
		# how do i make this better??
		$this->include_files();
		
		# set up cli
		WP_CLI::set_logger(new \WP_CLI\Loggers\Quiet());
		
		$runner = (new \WP_CLI\Bootstrap\RunnerInstance())();
		$runner->init_config();
		
		# make sure commands are loaded
		$deferred_additions = \WP_CLI::get_deferred_additions();
		
		foreach ($deferred_additions as $name => $addition)
			\WP_CLI::add_command($name, $addition['callable'], $addition['args']);
		
		# grab the right variables
		$reflection = new \ReflectionObject($runner);
		
		$vars = [
			"arguments" => null,
			"assoc_args" => null,
		];
		
		foreach($vars as $var => $null)
		{
			$prop = $reflection->getProperty($var);
			$prop->setAccessible(true);
			
			$vars[$var] = $prop->getValue($runner);
		}
		
		# make sure we're able to capture errors
		$feed = [
			"error" => 0,
			"output" => null,
			"pipes" => [
				"stdout" => null,
				"stderr" => null,
			],
		];
		
		try
		{
			$reflection = new \ReflectionClass(WP_CLI::class);
			
			$prop = $reflection->getProperty("capture_exit");
			$prop->setAccessible(true);
			$prop->setValue(true);
			
			ob_start();
			
			$runner->run_command($vars["arguments"], $vars["assoc_args"]);
			
			$feed["output"] = ob_get_clean();
			
			if(isset($vars["assoc_args"]) && $vars["assoc_args"]["format"] === "json")
				$feed["output"] = json_decode($feed["output"]);
		}
		catch(\WP_CLI\ExitException $exception)
		{
			ob_end_clean();
			
			$feed["error"] = $exception->getCode();
		}
		
		rewind(STDOUT);
		rewind(STDERR);
		
		$feed["pipes"]["stdout"] = stream_get_contents(STDOUT);
		$feed["pipes"]["stderr"] = stream_get_contents(STDERR);
		
		# send stuff out
		return $feed;
	}
	
	
	/**
	 *	Include files
	 */
	protected function include_files()
	{
		require_once WP_PLUGIN_DIR."/woocommerce-memberships/includes/class-wc-memberships-cli.php";
	}
}


return new REST_API_Plugin_WP_CLI();
