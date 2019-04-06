<?php
namespace library;

abstract class module{
	protected $db;
	protected $tenant_id;

	public function __construct(simple_pdo $db, $tenant_id = null){
		$this->db = $db;
		$this->tenant_id = $tenant_id;
	}

	abstract protected function getElastixData();

	abstract public function migrate();

	abstract protected function _add(\stdClass $item);

	abstract protected function _update(\stdClass $item);

	abstract protected function _ItemExists($value);

	public function runMigration(&$migratedModules){
		$shortName = (new \ReflectionClass($this))->getShortName();
		if(in_array($shortName, $migratedModules))
			return ;

		$dependencies = $this->getDependencies();
		foreach ($dependencies as $dependency){
			if(in_array($dependency, $migratedModules))
				continue ;

			$dependencyClassName = "\\library\\modules\\{$dependency}";
			if(class_exists($dependencyClassName) && is_subclass_of($dependencyClassName, "\\library\\module")){
				echo "Processing {$dependency} class as Dependency\n";
				$o = new $dependencyClassName($this->db, $this->tenant_id);
				$o->runMigration($migratedModules);
			}
		}

		$this->migrate();
		$migratedModules[] =  $shortName;
	}

	protected function buildTemporaryDestination($module){
		$tempDest = "terminate_call,1,1";
		$destination = new destination($tempDest,$module,$this->db);
		$id = $destination->create();
		return $id;
	}

	public function getDependencies(){ return []; }
	
	protected function tableExists($database, $table){
		$query = "select * from information_schema.tables
					where `table_schema` = '$database' and `table_name` = '$table'
					limit 1";
		
		$rows = $this->db->query($query)->get_rows();
		return count($rows);
	}

	protected function _getCurrentDestination($id, $table, $idField, $destinations_field){
		$database = \config::pbx_db;

		$query = "select 
					`{$destinations_field}` 
				  from `{$database}`.`{$table}` 
				  where 
				  `{$idField}` = ? and
				  (`{$destinations_field}` is not null or `{$destinations_field}` != '')";

		$rows = $this->db->query($query, $id)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->$destinations_field;

		return null;
	}
}
