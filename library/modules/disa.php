<?php
namespace library\modules;

use library\module;

class disa extends module {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select 
					`displayname` as `description`, 
					`pin` as `password`, 
					`context`,
					`digittimeout` as `digit_timeout`,
					`resptimeout` as `resp_timeout`
				from `{$database}`.`disa`";

		return $this->db->query($query)->get_rows();
	}

	public function getDependencies(){
		return ['classOfServices'];
	}

	public function migrate(){
		echo "Migrating DISA.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($this->_ItemExists($row->description)){
				$this->_update($row);
			}else{
				$this->_add($row);
			}
		}
		$db->end_transaction();
	}

	protected function _add(\stdClass $item){
		$db = $this->db;
		$database = \config::pbx_db;
		echo "Adding DISA: {$item->description}\n";

		$query = "insert into `{$database}`.`ombu_disa` 
					  (`description`, `class_of_service_id`, `password`, `resp_timeout`, `digit_timeout`) values
					  (?,?,?,?,?)";

		$db->query(
			$query,
			$item->description,
			$this->_getClassOfServiceID($item->context),
			(!strlen($item->password) ? rand(100, 200) : $item->password),
			$item->resp_timeout,
			$item->digit_timeout
		);
	}

	private function _getClassOfServiceID($context){
		$context = ($context === 'from-internal' ? 'all' : $context);
		$database = \config::pbx_db;
		$query = "select `class_of_service_id` from `{$database}`.`ombu_classes_of_service` where `cos` = '{$context}'";
		$rows = $this->db->query($query)->get_rows();
		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->class_of_service_id;

		return 1; //Return the Default Class of Service
	}

	protected function _update(\stdClass $item){
		echo "Skipping DISA: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `disa_id` from `{$database}`.`ombu_disa` where `description` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->disa_id;

		return null;
	}
}