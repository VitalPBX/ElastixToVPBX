<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;

class callback extends module implements postDestinations {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select 
					`description`, 
					`callbacknum` as `dialnumber`,
					`sleep` as `delay`,
					`destination`
				from `{$database}`.`callback`";

		return $this->db->query($query)->get_rows();
	}

	public function getDependencies(){
		return ['classOfServices'];
	}

	public function migrate(){
		echo "Migrating CallBacks.......\n";
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
		echo "Adding CallBack: {$item->description}\n";

		$destination_id = self::buildTemporaryDestination('call_back');
		$query = "insert into `{$database}`.`ombu_callbacks` 
					  (`description`, `dialnumber`, `delay`, `class_of_service_id`, `destination_id`, `tenant_id`) values
					  (?,?,?,?,?,?)";

		$db->query(
			$query,
			$item->description,
			$item->dialnumber,
			$item->delay,
			1, //Hard Code - Class of Service
			$destination_id,
			$this->tenant_id
		);
	}

	protected function _update(\stdClass $item){
		echo "Skipping CallBack: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `callback_id` from `{$database}`.`ombu_callbacks` where `description` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->callback_id;

		return null;
	}

	public function setDestinations(){
		echo "Set Destinations to Callbacks.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->description)){
				$destination_id = $this->_getCurrentDestination($id, 'ombu_callbacks', 'callback_id', 'destination_id');
				if(!$destination_id)
					continue ;

				echo "Set Destination to CallBack: {$row->description}\n";

				$destination = new destination($row->destination, 'call_back', $db, $destination_id);
				$destination->create();
			}
		}
		$db->end_transaction();
	}
}