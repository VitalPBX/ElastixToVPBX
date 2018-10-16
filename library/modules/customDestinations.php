<?php
namespace library\modules;

use library\module;

class customDestinations extends module {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select 
					`description`, 
					`destdial` as `destination`
				from `{$database}`.`miscdests`";

		return $this->db->query($query)->get_rows();
	}

	public function migrate(){
		echo "Migrating Custom Destinations.......\n";
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
		echo "Adding Custom Destination: {$item->description}\n";

		$query = "insert into `{$database}`.`ombu_custom_destinations` 
					  (`description`, `destination`, `class_of_service_id`) values
					  (?,?,?)";

		$db->query(
			$query,
			$item->description,
			$item->destination,
			1 //Hard Code - Class of Service
		);
	}

	protected function _update(\stdClass $item){
		echo "Skipping Custom Destination: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `custom_destination_id` from `{$database}`.`ombu_custom_destinations` where `description` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->custom_destination_id;

		return null;
	}
}