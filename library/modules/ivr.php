<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;
use library\simple_pdo;

class ivr extends module implements postDestinations {

	protected function getElastixData(){
		$database = \config::elastix_db;
		$ivrs = [];

		$query = "select 
					`ivr_id`, 
					`displayname` as `description`, 
					`enable_directdial`,
					 `announcement_id`,
					 `timeout_id`,
					 `invalid_id`
				  from `{$database}`.`ivr`";

	    $entries_tbl = 'ivr_dests';

	    if($this->tableExists($database, 'ivr_details')){
	    	$query = "select 
					`id` as `ivr_id`, 
					`name` as `description`, 
					IF(directdial is null, 'no', 'yes') as `enable_directdial`,
					 `announcement` as `announcement_id`,
					 `timeout_recording` as `timeout_id`,
					 `invalid_recording` as `invalid_id`
				  from `{$database}`.`ivr_details`";

		    $entries_tbl = 'ivr_entries';
	    }


		$rows = $this->db->query($query)->get_rows();

		foreach ($rows as $i => $row){
			$ivrs[$i] = (array) $row;
			$ivrs[$i]['entries'] = [];

			$entries = $this->db->query(
				"select 
						 `selection` as `option` ,
						 `dest`
					   from `{$database}`.`{$entries_tbl}` 
					   where `ivr_id` = ?",
				$row->ivr_id
			)->get_rows();

			if(count($entries) > 0){
				foreach ($entries as $entry){
					$ivrs[$i]['entries'][$entry->option] = $entry->dest;
				}

			}else{
				unset($ivrs[$i]);
			}
		}

		return $ivrs;
	}

	public function getDependencies(){
		return ['recordings'];
	}

	public function migrate(){
		echo "Migrating IVRs.......\n";
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
		echo "Adding IVR: {$item->description}\n";

		$invalid_destination_id = self::buildTemporaryDestination('ivr');
		$timeout_destination_id = self::buildTemporaryDestination('ivr');

		$query = "insert into `{$database}`.`ombu_ivrs` 
					  (`description`, `invalid_tries`, `timeout_tries`, `invalid_add_msg`, `timeout_add_msg`, `freedial`, 
					  `timeout`, `invalid_destination_id`, `timeout_destination_id`, `welcome_msg_id`, `invalid_msg_id`, `timeout_msg_id`, `tenant_id`) values
					  (?,?,?,?,?,?,?,?,?,?,?,?,?)";

		$freedial = (preg_match('/(checked|yes)/i', $item->enable_directdial) ? 'yes' : 'no');

		$ivr = $db->query(
			$query,
			$item->description,
			3,
			3,
			'yes',
			'yes',
			$freedial,
			10,
			$invalid_destination_id,
			$timeout_destination_id,
			recordings::getRecordingID($item->announcement_id, $db),
			recordings::getRecordingID($item->invalid_id, $db),
			recordings::getRecordingID($item->timeout_id, $db),
			$this->tenant_id
		);

		$ivr_id = $ivr->get_inserted_id();
		$this->_addEntries($ivr_id, $db, $item->entries);
	}

	private function _addEntries($ivr_id, simple_pdo $db, array $entries = []){
		$database = \config::pbx_db;
		$x = 0;
		foreach (array_keys($entries) as $option){
			if(in_array($option, ['i', 't']))
				continue ;

			$destination_id = self::buildTemporaryDestination('ivr');
			$query = "insert into `{$database}`.`ombu_ivr_entries` (`ivr_id`, `option`, `destination_id`, `enabled`, `sort`) values (?,?,?,?,?)";
			$db->query(
				$query,
				$ivr_id,
				$option,
				$destination_id,
				'yes',
				$x
			);

			$x++;
		}
	}

	protected function _update(\stdClass $item){
		echo "Skipping IVR: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `ivr_id` from `{$database}`.`ombu_ivrs` where `description` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->ivr_id;

		return null;
	}

	public function setDestinations(){
		echo "Set Destinations to IVRs.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->description)){
				$entries = $row->entries;
				foreach ($entries as $option => $option_destination){
					if(in_array($option, ['t', 'i'])){ //Main Destinations
						$field = ($option === 't' ? 'timeout_destination_id' : 'invalid_destination_id');
						$destination_id = $this->_getCurrentDestination($id,'ombu_ivrs', 'ivr_id', $field);
						if(!$destination_id)
							continue;

						$destination = new destination($option_destination, 'ivr', $db, $destination_id);
						$destination->create();
					}else{ //Destinations for the Options
						$destination_id = $this->_getCurrentOptionDestination($id, $option);
						if(!$destination_id)
							continue;

						$destination = new destination($option_destination, 'ivr', $db, $destination_id);
						$destination->create();
					}
				}
			}
		}
		$db->end_transaction();
	}

	private function _getCurrentOptionDestination($ivr_id, $option){
		$database = \config::pbx_db;

		$query = "select 
					`destination_id` 
				  from `{$database}`.`ombu_ivr_entries` 
				  where 
				  `ivr_id` = ? and
				  `option` = ? and
				  (`destination_id` is not null or `destination_id` != '')";

		$rows = $this->db->query($query, $ivr_id, $option)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->destination_id;

		return null;
	}
}