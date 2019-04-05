<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;
use library\simple_pdo;

class ringGroups extends module implements postDestinations {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select
					`grpnum` as `extension`,
					`description`,
					`strategy`,
					`grptime` as `ringtime`,
					`grppre` as `prefix`,
					`grplist` as `members`,
					`postdest` as `destination`
				from `{$database}`.`ringgroups`";

		return $this->db->query($query)->get_rows();
	}

	public function getDependencies(){
		return ['extensions'];
	}

	public function migrate(){
		echo "Migrating Ring Groups.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($this->_ItemExists($row->extension)){
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

		echo "Adding Ring Group: {$item->description}\n";

		$query = "insert into `{$database}`.`ombu_ring_groups`
					  (`extension`, `description`, `strategy`, `ringtime`, `prefix`, `tenant_id`) values (?, ?, ?, ?, ?, ?)";


		$ringGroup = $db->query($query,
			$item->extension,
			$item->description,
			($item->strategy === 'hunt' ? 'one_by_one' : 'ringall'),
			$item->ringtime,
			$item->prefix,
			$this->tenant_id
		);

		$id = $ringGroup->get_inserted_id();
		$this->_addMembers($item->members, $id, $db);
	}

	private function _addMembers($members, $ring_group_id, simple_pdo $db){
		$members = explode('-', $members);
		$database = \config::pbx_db;

		foreach ($members as $member){
			$extension_id = $this->_getExtensionIDByExtension($member);
			if(!$extension_id)
				continue;

			$db->query(
				"insert into `{$database}`.`ombu_ring_group_members` 
								(`ring_group_id`, `extension_id`) values (?,?)",
				$ring_group_id,
				$extension_id
			);
		}
	}

	private function _getExtensionIDByExtension($extension){
		$database = \config::pbx_db;

		$query = "select `extension_id` from `{$database}`.`ombu_extensions` where `extension` = '{$extension}'";
		$rows = $this->db->query($query)->get_rows();
		if(is_array($rows) && array_key_exists(0, $rows)){
			return $rows[0]->extension_id;
		}

		return null;
	}

	protected function _update(\stdClass $item){
		echo "Skipping Ring Group: {$item->extension} - {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `ring_group_id` from `{$database}`.`ombu_ring_groups` where `extension` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->ring_group_id;

		return null;
	}

	public function setDestinations(){
		echo "Set Destinations to Ring Groups.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->extension)){
				$destination_id = $this->_getCurrentDestination($id, 'ombu_ring_groups', 'ring_group_id', 'destination_id');
				if(!$destination_id)
					continue;

				echo "Set Destination to Ring Group: {$row->description}\n";

				$destination = new destination($row->destination, 'ring_group', $db, $destination_id);
				$destination->create();
			}
		}
		$db->end_transaction();
	}
}