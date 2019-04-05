<?php
namespace library\modules;

use library\module;
use library\simple_pdo;

class extensions extends module {

	protected function getElastixData(){
		$database = \config::elastix_db;
		$extensions = [];

		$query = "select
					`id`,
					`user` as `extension`,
					`description`,
					`tech` as `technology`
				from `{$database}`.`devices`
				where `tech` in ('sip', 'iax')";

		$rows = $this->db->query($query)->get_rows();

		foreach ($rows as $i => $row){
			$extensions[$i] = (array) $row;
			$techTable = ($row->technology === 'sip' ? 'sip' : 'iax');
			$query = "select
						`keyword`,
						`data`
					from `{$database}`.`{$techTable}`
					where `id` = {$row->id}";

			$settings = $this->db->query($query)->get_rows();
			$extensions[$i]['record_in'] = 'no';
			$extensions[$i]['record_out'] = 'no';
			foreach ($settings as $setting){
				$extensions[$i][$setting->keyword] = $setting->data;
			}
		}

		return $extensions;
	}

	public function getDependencies(){
		return ['classOfServices'];
	}

	public function migrate(){
		echo "Migrating Extensions.......\n";
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
		$cid = '"'.$item->description.'" <'.$item->extension.'>';

		echo "Adding Extension: {$item->description}\n";

		$query = "insert into `{$database}`.`ombu_extensions`
					 (`extension`,
					 `name`,
					 `language`,
					 `class_of_service_id`, 
					 `dial_profile_id`, 
					 `internal_cid`, 
					 `external_cid`,
					 `ringtime`,
					 `accountcode`,
					 `features_password`,
					 `incoming_rec`,
					 `outgoing_rec`,
					 `tenant_id`
					 ) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

		$result = $db->query(
			$query,
			$item->extension,
			$item->description,
			'en',
			$this->_getClassOfServiceID($item->context), //Hard Code Class of Service ID
			1, //Hard Code Dial Profile ID
			$cid,
			$cid,
			0,
			$item->accountcode,
			"*{$item->extension}",
			(preg_match('/always/i', $item->record_in) ? 'yes' : 'no'),
			(preg_match('/always/i', $item->record_out) ? 'yes' : 'no'),
			$this->tenant_id
		);

		$extension_id = $result->get_inserted_id();
		$this->_addDevice($item, $extension_id, $db);
		$this->_addVoicemail($item, $extension_id, $db);
		$this->_addFollowMe($item, $extension_id, $db);
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

	private function _addDevice(\stdClass $item, $extension_id, simple_pdo $db){
		$database = \config::pbx_db;
		$techTable = ($item->technology === 'sip' ? 'ombu_sip_devices' : 'ombu_iax_devices');
		$profile_id = $this->_getProfile($item->technology);

		//Add Device
		$query = "insert into `{$database}`.`ombu_devices` 
					(`extension_id`, `profile_id`, `user`, `secret`, `description`, `ring_device`, `technology`, `tenant_id`) values (?, ?, ?, ?, ?, ?, ?, ?)";

		$device = $db->query(
			$query,
			$extension_id,
			$profile_id,
			$item->extension,
			$item->secret,
			$item->description,
			'yes',
			$item->technology,
			$this->tenant_id
		);

		$device_id = $device->get_inserted_id();

		//Add Tech-Info
		$query = "insert into `{$database}`.`{$techTable}` 
					(`device_id`, `deny`, `permit`) values (?, ?, ?)";

		$db->query(
			$query,
			$device_id,
			$this->_parseDenyPermit($item->deny),
			$this->_parseDenyPermit($item->permit)
		);
	}

	private function _parseDenyPermit($value){
		if($value === '0.0.0.0/0.0.0.0')
			return '0.0.0.0/0';

		return $value;
	}

	private function _addVoicemail(\stdClass $item, $extension_id, simple_pdo $db){
		$database = \config::pbx_db;
		$db->query("insert into 
							`{$database}`.`ombu_extensions_vm` (`extension_id`, `password`) 
							values (?, ?)", $extension_id, $item->extension);
	}

	private function _addFollowMe(\stdClass $item, $extension_id, simple_pdo $db){
		$database = \config::pbx_db;
		$db->query("insert into 
							`{$database}`.`ombu_followme` (`extension_id`) 
							values (?)", $extension_id);
	}

	private function _getProfile($technology){
		$database = \config::pbx_db;

		$query = "select 
					`profile_id` 
				  from `{$database}`.`ombu_device_profiles` 
				  where `technology` = '{$technology}' and `default` = 'yes'";

		$rows = $this->db->query($query)->get_rows();
		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->profile_id;

		return null;
	}

	protected function _update(\stdClass $item){
		echo "Skipping Extension: {$item->extension} - {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `extension_id` from `{$database}`.`ombu_extensions` where `extension` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->extension_id;

		return null;
	}
}