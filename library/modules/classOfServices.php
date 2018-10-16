<?php
namespace library\modules;

use library\destination;
use library\module;

class classOfServices extends module {
	
	protected function getElastixData () {
		$db = $this->db;
		$elastix_db = \config::elastix_db;
		
		$query = "select
					`context`,
					`description`
				from `{$elastix_db}`.`customcontexts_contexts`
				where `context` != 'AVI'";
		
		$ClassOfServices = $db->query($query)->get_rows();
		return $ClassOfServices;
	}
	
	public function migrate () {
		echo "Migrating Class of Services.......\n";
		if($this->tableExists(\config::elastix_db, 'customcontexts_contexts')){
			$elastixData = $this->getElastixData();
			$db = $this->db;
			$db->start_transaction();
			foreach ($elastixData as $item){
				$item = (object) $item;
				if($this->_ItemExists($item->context)){
					$this->_update($item);
				}else{
					$this->_add($item);
				}
			}
			$db->end_transaction();
		}else{
			echo "No Contexts to Import\n";
		}
	}
	
	protected function _add (\stdClass $item) {
		$db = $this->db;
		$database = \config::pbx_db;
		
		echo "Adding Class of Service: {$item->description}\n";
		$query = "insert into `{$database}`.ombu_classes_of_service
					(`cos`, `description`, `default`) values (?,?,?)";
		
		$db->query(
			$query,
			$item->context,
			$item->description,
			'no'
		);
	}
	
	protected function _update (\stdClass $item) {
		echo "Skipping Class of Service: {$item->description}, Already exists!\n";
	}
	
	protected function _ItemExists ($value) {
		$database = \config::pbx_db;
		$query = "select `class_of_service_id` from `{$database}`.`ombu_classes_of_service` where `cos` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->class_of_service_id;

		return null;
	}
}