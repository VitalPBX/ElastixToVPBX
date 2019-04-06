<?php
namespace library\modules;

use library\module;
use library\simple_pdo;

class trunks extends module {
	private $_peerParameters = [
		'host' => '' ,
		'port' => '' ,
		'secret' => '' ,
		'insecure' => '',
		'type' => 'yes',
		'remotesecret' => '' ,
		'fromuser' => '' ,
		'fromdomain' => '' ,
		'qualify' => 'yes'
	];

	private $_userParameters = [
		'host' => '',
		'secret' => '',
		'remotesecret' => '',
		'insecure' => '',
		'qualify' => 'yes' ,
		'type' => 'no',
		'trunk' => 'yes'
	];


	protected function getElastixData(){
		$db = $this->db;
		$elastix_db = \config::elastix_db;
		$trunks = [];
		//Get only SIP/IAX Trunks
		$rows = $db->query(
			"select
 					`trunkid` as `id`,
					`name` as `description`, 
					`tech` as `technology`, 
					`outcid`, 
					`keepcid` as `overwrite_cid`, 
					`maxchans`, 
					`channelid` as `outgoing_username`, 
					`usercontext` as `incoming_username`
				   from `{$elastix_db}`.`trunks`"
		)->get_rows();

		foreach ($rows as $i => $row){
			$trunks[$i] = (array) $row;
			$trunks[$i]['register'] = '';
			$trunks[$i]['parameters'] = [];

			if(in_array($row->technology, ['sip', 'iax']))
				$this->_buildDigitalSettings($trunks[$i], $row, $db);
		}

		return $trunks;
	}

	private function _buildDigitalSettings(&$trunk, \stdClass $row, simple_pdo $db){
		$elastix_db = \config::elastix_db;

		$table = ($row->technology === 'sip' ? 'sip' : 'iax');
		$settings = $db->query(
			"select
						`id` as `type`,
						`keyword` as `name`,
						`data` as `value`
						from `{$elastix_db}`.`{$table}` 
						where `id` in('tr-peer-{$row->id}', 'tr-user-{$row->id}', 'tr-reg-{$row->id}')"
		)->get_rows();

		foreach ($settings as $setting){
			if(preg_match('/reg/i',$setting->type)){ //Catch the Register String
				$trunk['register'] = $setting->value;
				continue ;
			}

			$type = 'peer';
			if(preg_match('/user/i',$setting->type))
				$type = 'user';

			$trunk['parameters'][$type][$setting->name] = $setting->value;
		}
	}

	public function migrate(){
		echo "Migrating Trunks.......\n";
		$elastixTrunks = $this->getElastixData();
		$db = $this->db;
		$db->start_transaction();
		foreach ($elastixTrunks as $trunk){
			$trunk = (object) $trunk;
			if($this->_ItemExists($trunk->outgoing_username)){
				$this->_update($trunk);
			}else{
				$this->_add($trunk);
			}
		}
		$db->end_transaction();
	}

	protected function _add(\stdClass $trunk){
		echo "Adding Trunk: {$trunk->description}\n";
		$parameters = $trunk->parameters;
		$db = $this->db;
		$database = \config::pbx_db;

		$query = "insert into `{$database}`.`ombu_trunks` (
					`description`, 
					`technology`, 
					`dial_profile_id`, 
					`ringtimer`, 
					`register`,
					`outgoing_username`,
					`incoming_username`,
					`mode`,
					`register_flag`,
					`disable`,
					`tenant_id`
					) values (?,?,?,?,?,?,?,?,?,?,?)";


		$technology = ($trunk->technology === 'zap' || $trunk->technology === 'dahdi' ? 'telephony' : $trunk->technology);
                $technology = ($technology === 'iax' ? 'iax2' : $technology);

		$q = $db->query($query,
			$trunk->description,
			$technology,
			1, //Hard Code for dial profile
			60,
			$trunk->register,
			$trunk->outgoing_username,
			$trunk->incoming_username,
			($technology === 'telephony' ? 'visual': 'plain'),
			'no',
			'no',
			$this->tenant_id
		);

		$trunk_id = $q->get_inserted_id();

		if(in_array($technology, ['sip', 'iax', 'iax2'])){
			$this->_addTrunkParameters($trunk_id, $parameters);
			$this->_addTrunkParameters($trunk_id, $parameters, 'user');
		}
	}

	public function _addTrunkParameters($trunk_id, $parameters, $type ='peer'){
		if(!array_key_exists($type, $parameters))
			return ;

		$configurations = $parameters[$type];
		$trunkFields = ($type === 'peer' ? $this->_peerParameters : $this->_userParameters);

		$configurations = array_merge($trunkFields, $configurations);
		$database = \config::pbx_db;

		foreach ($configurations as $param => $value){
			if($param === 'context')
				continue ;

			if($param === 'username')
				$param = 'defaultuser';

			if($param === 'insecure' && preg_match('/very/', $value))
				$value = 'port,invite';

			if($param === 'type')
				$value = ($value === 'friend' ? 'yes' : 'no');

			if($param === 'qualify' && preg_match('/yes/', $value))
				$value = 'yes';

			if($param === 'nat' && $value === 'yes')
				$value = 'force_rport,comedia';

			$query = "insert into 
						`{$database}`.`ombu_trunk_parameters` (
								`trunk_id`, 
								`advanced_param`, 
								`type`, 
								`param`, 
								`value`, 
								`enabled`, 
								`sort`
						) values (?, ?, ?, ?, ?, ?, ?)";

			$this->db->query($query,
				$trunk_id,
				'no',
				$type,
				$param,
				$value,
				'yes',
				0
			);

		}
	}

	protected function _update(\stdClass $trunk){
		echo "Skipping Trunk: {$trunk->description}, Already exists!\n";
	}

	protected function _ItemExists($peerName){
		$database = \config::pbx_db;
		$query = "select `trunk_id` from `{$database}`.`ombu_trunks` where `outgoing_username` = '$peerName'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->trunk_id;

		return null;
	}
}
