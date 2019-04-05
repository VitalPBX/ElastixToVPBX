<?php
namespace library\modules;

use library\module;
use library\simple_pdo;

class huntingGroups extends module {

	protected function getElastixData(){
		$huntingGroups = [];
		$this->_getHuntingGroups('sip', $huntingGroups);
		$this->_getHuntingGroups('iax', $huntingGroups);

		$huntingGroups = array_unique($huntingGroups, SORT_NUMERIC);
		foreach ($huntingGroups as $i => $group){
			$huntingGroups[$i] = [
				'description' => "Group {$group}",
				'members' => []
			];

			$this->_getMembers('sip', $group, $huntingGroups[$i]['members']);
			$this->_getMembers('iax', $group, $huntingGroups[$i]['members']);
		}

		return $huntingGroups;
	}

	private function _getMembers($technology, $group, &$members){
		$database = \config::elastix_db;
		$table = ($technology === 'sip' ? 'sip' : 'iax');

		$query = "select `e`.`user` as `extension`, `info`.`allow_pickup`, `info`.`group_member` 
				  from `{$database}`.`{$table}` as `t` 
					left join `{$database}`.`devices` as `e` on (`e`.`id` = `t`.`id`)
					left join (
						select
						  `id`,
						  if(`data` = '' and `keyword` = 'pickupgroup', 'no', 'yes')  as `allow_pickup`,
						  if(`data` = '' and `keyword` = 'callgroup', 'no', 'yes')  as `group_member`
						from `{$database}`.`{$table}`
					) as `info` on (`info`.`id` = `t`.`id`)
					where `keyword` in ('pickupgroup', 'callgroup') and `data` = {$group}
					group by `e`.`user`";

		$rows = $this->db->query($query)->get_rows();
		$members = array_merge($members, $rows);
	}

	private function _getHuntingGroups($technology, &$huntingGroups){
		$database = \config::elastix_db;
		$table = ($technology === 'sip' ? 'sip' : 'iax');

		$query = "select 
					`data` 
				  from `{$database}`.`{$table}` 
				  where `keyword` in ('pickupgroup', 'callgroup') and `data` != '' 
				  group by `data`";

		$rows = $this->db->query($query)->get_rows();
		foreach ($rows as $group){
			$huntingGroups[] = $group->data;
		}
	}

	public function getDependencies(){
		return ['extensions'];
	}

	public function migrate(){
		echo "Migrating Hunting Groups.......\n";
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

		echo "Adding Hunting Group: {$item->description}\n";

		$query = "insert into `{$database}`.`ombu_pickup_groups` (`description`, `tenant_id`) values (?, ?)";
		$group = $db->query($query, $item->description, $this->tenant_id);
		$pickup_group_id = $group->get_inserted_id();

		$this->_addMembers($item, $pickup_group_id, $db);
	}

	private function _addMembers(\stdClass $item, $pickup_group_id, simple_pdo $db){
		$members = (array) $item->members;
		$database = \config::pbx_db;

		foreach ($members as $member){
			$extensionID = $this->_getExtensionID($member->extension);
			if(!$extensionID)
				continue;

			$query = "insert into `{$database}`.`ombu_pickup_group_members` 
						(`pickup_group_id`, `extension_id`, `allow_pickup`, `group_member`) values (?, ?, ?, ?)";

			$db->query($query, $pickup_group_id, $extensionID, $member->allow_pickup, $member->group_member);
		}
	}

	private function _getExtensionID($extension){
		$database = \config::pbx_db;

		$query = "select 
					`extension_id` 
				  from `{$database}`.`ombu_extensions` 
				  where `extension` = '{$extension}'";

		$rows = $this->db->query($query)->get_rows();
		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->extension_id;

		return null;
	}

	protected function _update(\stdClass $item){
		echo "Skipping Hunting Group: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `pickup_group_id` from `{$database}`.`ombu_pickup_groups` where `description` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->pickup_group_id;

		return null;
	}
}