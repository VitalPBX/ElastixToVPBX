<?php
include "bootstrap.php";

use library\simple_pdo;
use library\postDestinations;

class migrate{
	/**
	 * @var simple_pdo
	 */
	private $db;


	public function __construct(){
		$this->db = simple_pdo::MySQLConnect(
			config::pbx_db,
			config::db_user,
			config::db_password,
			config::db_host,
			config::db_port
		);
	}

	public function run(){
		$db = $this->db;
		$database = \config::pbx_db;

		$query = "select `tenant_id` from `{$database}`.`ombu_tenants` where `name` = 'vitalpbx'";
		$rows = $this->db->query($query)->get_rows();
		$tenant_id = null

		if(array($rows) && array_key_exists(0,$rows))
			$tenant_id = $rows[0]->tenant_id;

		$pattern = buildpath([ __DIR__, 'library', 'modules', '*.php' ]);
		foreach (glob($pattern) as $filename) {
			include_once($filename);
		}

		$postClasses = [];
		$migratedModules = [];
		foreach (get_declared_classes() as $classname) {
			if (is_subclass_of($classname, "\\library\\module")) {
				$module = new $classname($this->db, $tenant_id);
				$reflection = new \ReflectionClass($classname);
				$shortName = $reflection->getShortName();
				$module->runMigration($migratedModules);
				if($module instanceof postDestinations){
					$postClasses[$shortName] = $module;
				}
			}
		}

		foreach ($postClasses as $c){
			$c->setDestinations();
		}
	}
}

$migrate = new migrate();
$migrate->run();