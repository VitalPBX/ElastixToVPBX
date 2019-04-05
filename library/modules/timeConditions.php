<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;

class timeConditions extends module implements postDestinations {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select 
					`tc`.`displayname` as `description`, 
					 `tg`.`description` as `time_group_desc`,
					 `tc`.`truegoto` as `match_destination`,
					 `tc`.`falsegoto` as `mismatch_destination`
				  from `{$database}`.`timeconditions` as `tc`
				  left join `{$database}`.`timegroups_groups` as `tg` on (`tg`.`id` = `tc`.`time`)";

		return $this->db->query($query)->get_rows();
	}

	public function getDependencies(){
		return ['timeGroups'];
	}

	public function migrate(){
		echo "Migrating Time Conditions.......\n";
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

		echo "Adding Time Condition: {$item->description}\n";

		$match_destination_id = self::buildTemporaryDestination('time_conditions');
		$mismatch_destination_id = self::buildTemporaryDestination('time_conditions');

		$query = "insert into `{$database}`.`ombu_time_conditions` (`description`, `time_group_id`, `match_destination_id`, `mismatch_destination_id`, `tenant_id`) values (?, ?, ?, ?, ?)";
		$time_group_id = $this->_getTimeGroupIDByDescription($item->time_group_desc);

		$db->query($query, $item->description, $time_group_id, $match_destination_id, $mismatch_destination_id, $this->tenant_id);
	}

	private function _getTimeGroupIDByDescription($description){
		$database = \config::pbx_db;

		$query = "select `time_group_id` from `{$database}`.`ombu_time_groups` where `description` = '{$description}'";
		$rows = $this->db->query($query)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->time_group_id;

		return null;
	}

	protected function _update(\stdClass $item){
		echo "Skipping Time Condition: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `time_condition_id` from `{$database}`.`ombu_time_conditions` where `description` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->time_condition_id;

		return null;
	}

	public function setDestinations(){
		echo "Set Destinations to Time Conditions.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->description)){
				$match_destination_id = $this->_getCurrentDestination($id, 'ombu_time_conditions', 'time_condition_id', 'match_destination_id');
				$mismatch_destination_id = $this->_getCurrentDestination($id, 'ombu_time_conditions', 'time_condition_id', 'mismatch_destination_id');
				echo "Set Destination to Time Condition: {$row->description}\n";

				$match_destination = new destination($row->match_destination, 'time_conditions', $db, $match_destination_id);
				$match_destination->create();

				$mismatch_destination = new destination($row->mismatch_destination, 'time_conditions', $db, $mismatch_destination_id);
				$mismatch_destination->create();
			}
		}
		$db->end_transaction();
	}
}