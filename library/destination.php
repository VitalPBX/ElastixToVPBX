<?php
namespace library;

class destination{
	private $_category_id;
	private $_module_id;
	private $_module;
	private $_index;
	private $_current_id;
	private $tenant_id;

	/**
	 * @var simple_pdo
	 */
	private $db;

	/**
	 * destination constructor.
	 * @param string $destination Full destination string from Elastix DB
	 * @param string $module Name of the module who will use this destination
	 * @param simple_pdo $db Database Instance
	 * @param null $current_id ID of the destination if already have one
	 */
	public function __construct($destination, $module, simple_pdo $db, $current_id = null){
		$destination =  explode(',', $destination);
		list($category,$index,$priority) = $destination;
		$this->_getIndex($category, $index);
		$this->db = $db;
		$this->_module = $module;
		$this->_current_id = $current_id;

		$category =  $this->_parseCategory($category);
		$this->_category_id = $this->_getCategoryID($category);
		$this->_module_id = $this->_getModuleIDByName($module);
		$this->_index = $this->_parseIndex($category, $index);
		$this->tenant_id = $this->_getTenantID();
	}

	private function _getTenantID(){
		$query = "select `tenant_id` from `{$database}`.`ombu_tenants` where `name` = 'vitalpbx'";
		$rows = $this->db->query($query)->get_rows();
		$tenant_id = null

		if(array($rows) && array_key_exists(0,$rows))
			$tenant_id = $rows[0]->tenant_id;

		return $tenant_id;
	}

	private function _getIndex($category,&$index){
		if(preg_match('/ivr/i',$category)){
			$tmp = explode('-',$category);
			$index = $tmp[1];
		}

		if(preg_match('/app-announcement/i',$category)){
			$tmp = explode('-',$category);
			$index = $tmp[2];
		}
	}

	public function create(){
		if(!$this->_index) //If the destination doesn't exists, return provided ID
			return $this->_current_id;

		$database = \config::pbx_db;
		$parameters = [$this->_category_id, $this->_module_id, $this->_index, $this->tenant_id];

		if(!$this->_current_id){
			$query = "insert into `{$database}`.`ombu_destinations`
						(`category_id`, `module_id`, `index`, `tenant_id`) values (?, ?, ?, ?)";
		}else{
			$query = "update `{$database}`.`ombu_destinations` set 
						`category_id` = ?, `module_id` = ?,  `index` = ?, `tenant_id` = ? where `id` = ?";
			$parameters[] = $this->_current_id;
		}


		$result = $this->db->query($query, $parameters);

		return $result->get_inserted_id();
	}

	private function _getModuleIDByName($name){
		$database = \config::pbx_db;
		$query = "select `module_id` from `{$database}`.`ombu_modules` where `name` = ?";
		$rows = $this->db->query($query, $name)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->module_id;

		return null;
	}

	private function _getCategoryID($name){
		$module_id  = $this->_getModuleIDByName($name);

		if($module_id){
			$database = \config::pbx_db;
			$query = "select `id` from `{$database}`.`ombu_destinations_category` where `module_id` = ?";
			$rows = $this->db->query($query, $module_id)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->id;
		}

		return null;
	}

	private function _parseCategory($category){
		switch ($category){
			case 'app-blackhole':
				return 'terminate_call';
				break;
			case 'ext-group':
				return 'ring_group';
				break;
			case 'from-did-direct':
				return 'extensions';
				break;
			case 'ext-trunk':
				return 'trunks';
				break;
			case 'ext-queues':
				return 'queues';
				break;
			case 'ext-miscdests':
				return 'custom_dest';
				break;
			case 'disa':
				return 'disa';
				break;
			case 'ext-meetme':
				return 'conferences';
				break;
			case 'callback':
				return 'call_back';
				break;
			default:
				if(preg_match('/ivr/i',$category))
					return 'ivr';

				if(preg_match('/app-announcement/i',$category))
					return 'preannoun';

				break;
		}

		return 'terminate_call';
	}

	private function _parseIndex($category, $index){
		switch ($category){
			case 'terminate_call':
				return 1;
			case 'ring_group':
				return $this->_getIndexByNumber('ombu_ring_groups', $index, 'ring_group_id');
				break;
			case 'extensions':
				return $this->_getIndexByNumber('ombu_extensions', $index, 'extension_id');
				break;
			case 'conferences':
				return $this->_getIndexByNumber('ombu_conferences', $index, 'conference_id');
				break;
			case 'queues':
				return $this->_getIndexByNumber('ombu_queues', $index, 'queue_id');
				break;
			case 'ivr':
				return $this->_getIVRIndex($index);
				break;
			case 'disa':
				return $this->_getDISAIndex($index);
				break;
			case 'call_back':
				return $this->_getCallBackIndex($index);
				break;
			case 'trunks':
				return $this->_getTrunkIndex($index);
				break;
			case 'custom_dest':
				return $this->_getCustomDestIndex($index);
				break;
			case 'preannoun':
				return $this->_getAnnouncementIndex($index);
				break;
		}

		return 1;
	}

	private function _getAnnouncementIndex($index){
		$elastixDB = \config::elastix_db;
		$database = \config::pbx_db;

		$query = "select `description` from `{$elastixDB}`.`announcement` where `announcement_id` = ?";
		$rows = $this->db->query($query, $index)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$description = $rows[0]->description;
			$query = "select `announcement_id` from `{$database}`.`ombu_announcements` where `description` = ?";
			$rows = $this->db->query($query, $description)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->announcement_id;
		}

		return null;
	}

	private function _getCustomDestIndex($index){
		$elastixDB = \config::elastix_db;
		$database = \config::pbx_db;

		$query = "select `description` from `{$elastixDB}`.`miscdests` where `id` = ?";
		$rows = $this->db->query($query, $index)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$description = $rows[0]->description;
			$query = "select `custom_destination_id` from `{$database}`.`ombu_custom_destinations` where `description` = ?";
			$rows = $this->db->query($query, $description)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->custom_destination_id;
		}

		return null;
	}

	private function _getTrunkIndex($index){
		$elastixDB = \config::elastix_db;
		$database = \config::pbx_db;

		$query = "select `channelid` as `peer` from `{$elastixDB}`.`trunks` where `trunkid` = ?";
		$rows = $this->db->query($query, $index)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$peer = $rows[0]->peer;
			$query = "select `trunk_id` from `{$database}`.`ombu_trunks` where `outgoing_username` = ?";
			$rows = $this->db->query($query, $peer)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->trunk_id;
		}

		return null;
	}

	private function _getCallBackIndex($index){
		$elastixDB = \config::elastix_db;
		$database = \config::pbx_db;

		$query = "select `description` from `{$elastixDB}`.`callback` where `callback_id` = ?";
		$rows = $this->db->query($query, $index)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$description = $rows[0]->description;
			$query = "select `callback_id` from `{$database}`.`ombu_callbacks` where `description` = ?";
			$rows = $this->db->query($query, $description)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->callback_id;
		}

		return null;
	}

	private function _getDISAIndex($index){
		$elastixDB = \config::elastix_db;
		$database = \config::pbx_db;

		$query = "select `displayname` as `description` from `{$elastixDB}`.`disa` where `disa_id` = ?";
		$rows = $this->db->query($query, $index)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$description = $rows[0]->description;
			$query = "select `disa_id` from `{$database}`.`ombu_disa` where `description` = ?";
			$rows = $this->db->query($query, $description)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->disa_id;
		}

		return null;
	}

	private function _getIVRIndex($index){
		$index = str_replace('ivr-', '', $index);
		$elastixDB = \config::elastix_db;
		$database = \config::pbx_db;

		$query = "select `displayname` as `description` from `{$elastixDB}`.`ivr` where `ivr_id` = ?";
		$rows = $this->db->query($query, $index)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$description = $rows[0]->description;
			$query = "select `ivr_id` from `{$database}`.`ombu_ivrs` where `description` = ?";
			$rows = $this->db->query($query, $description)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->ivr_id;
		}

		return null;
	}

	private function _getIndexByNumber($table,$number, $idField, $searchField = 'extension'){
		$database = \config::pbx_db;
		$query = "select `{$idField}` from `{$database}`.`{$table}` where `{$searchField}` = ?";
		$rows = $this->db->query($query, $number)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->$idField;

		return null;
	}
}