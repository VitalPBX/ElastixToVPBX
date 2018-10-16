<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;

class announcements extends module implements postDestinations {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select
					`description`,
					`recording_id`,
					`post_dest` as `destinaiton`
				 from `{$database}`.`announcement`";

		return $this->db->query($query)->get_rows();
	}

	public function migrate(){
		echo "Migrating Inbound Routes.......\n";
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
		echo "Adding Announcement: {$item->description}\n";

		$destination_id = self::buildTemporaryDestination('preannoun');
		$query = "insert into `{$database}`.`ombu_announcements` 
					  (`description`, `recording_id`, `destination_id`) values
					  (?,?,?,?,?)";

		$db->query(
			$query,
			$item->description,
			recordings::getRecordingID($item->recording_id),
			$destination_id
		);
	}

	protected function _update(\stdClass $item){
		echo "Skipping Announcement: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `announcement_id` from `{$database}`.`ombu_announcements` where `description` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->announcement_id;

		return null;
	}

	public function getDependencies(){
		return ['recordings'];
	}

	public function setDestinations(){
		echo "Set Destinations to Announcements.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->description)){
				$destination_id = $this->_getCurrentDestination($id, 'ombu_announcements', 'announcement_id', 'destination_id');
				if(!$destination_id)
					continue ;

				echo "Set Destination to Announcement: {$row->description}\n";

				$destination = new destination($row->destination, 'preannoun', $db, $destination_id);
				$destination->create();
			}
		}
		$db->end_transaction();
	}
}