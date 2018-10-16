<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;
use library\simple_pdo;

class queues extends module implements postDestinations {

	protected function getElastixData(){
		$db = $this->db;
		$elastix_db = \config::elastix_db;

		$query = "select
					`extension`,
					`descr` as `description`,
					`grppre` as `prefix`,
					`ivr_id`,
					`dest` as `destination`
				from `{$elastix_db}`.`queues_config`";

		$queues = $db->query($query)->get_rows();
		$data = [];
		foreach ($queues as $queue){
			$data[$queue->extension] = (array) $queue;

			$query = "select
					`keyword` as `name`,
					`data` as `value`
				from `{$elastix_db}`.`queues_details`
				where `id` = {$queue->extension}";


			$parameters = $db->query($query)->get_rows();
			$data[$queue->extension]['members'] = [];

			foreach ($parameters as $parameter){
				$parameter->name = str_replace('-', '_', $parameter->name);

				if($parameter->name === 'member'){
					$data[$queue->extension]['members'][] = $parameter->value;
				}else{
					$data[$queue->extension][$parameter->name] = $parameter->value;
				}

			}

			$data[$queue->extension] = (object) $data[$queue->extension];
		}

		return $data;
	}

	public function getDependencies(){
		return ['extensions', 'ivr'];
	}

	public function migrate(){
		echo "Migrating Queues.......\n";
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

		echo "Adding Queue: {$item->description}\n";

		$destination_id = self::buildTemporaryDestination('queues');
		$query = "insert into `{$database}`.`ombu_queues`
					(`extension`,
					`ivr_id`,
					`description`,
					`prefix`,
					`destination_id`,
					`strategy`,
					`autofill`,
					`record`,
					`servicelevel`,
					`wrapuptime`,
					`announce_to_first_user`,
					`ringinuse`,
					`memberdelay`,
					`timeoutpriority`,
					`timeout`,
					`queue_timeout`,
					`joinempty`,
					`leavewhenempty`,
					`retry`,
					`announce_holdtime`,
					`announce_position`
					) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

		$result = $db->query(
			$query,
			$item->extension,
			$this->_getIVRID($item->ivr_id),
			$item->description,
			$item->prefix,
			$destination_id,
			$item->strategy,
			$item->autofill,
			$item->monitor_join,
			$item->servicelevel,
			$item->wrapuptime,
			'no',
			$item->ringinuse,
			0,
			'app',
			$item->timeout,
			$item->timeout,
			$item->joinempty,
			$item->leavewhenempty,
			$item->retry,
			$item->announce_holdtime,
			$item->announce_position
		);

		$queue_id = $result->get_inserted_id();
		$this->_addMembers($item, $queue_id, $db);
	}

	private function _addMembers(\stdClass $item, $queue_id, simple_pdo $db){
		$database = \config::pbx_db;

		$query = "insert into `{$database}`.`ombu_queue_members` 
					(`queue_id`, `extension_id`, `penalty`, `diversions`, `type`) values (?, ?, ?, ?, ?)";

		$members = $item->members;
		foreach ($members as $member){
			if(preg_match('/^local/([0-9]+)@(.*)/i', $member, $matches)){
				$extension = $member[1];
				$extension_id = $this->_getExtensionID($extension);
				if($extension_id){
					$this->db->query(
						$query,
						$queue_id,
						$extension_id,
						0,
						'no',
						'static'
					);
				}
			}
		}
	}

	private function _getExtensionID($extension){
		$database = \config::pbx_db;
		$query = "select `extension_id` from `{$database}`.`ombu_extensions` where `extension` = '{$extension}'";
		$rows = $this->db->query($query)->get_rows();
		if(is_array($rows) && array_key_exists(0, $rows))
			return $rows[0]->extension_id;

		return null;
	}

	private function _getIVRID($ivr_id){
		$database = \config::pbx_db;
		$elastixDB = \config::elastix_db;

		if(!strlen(trim($ivr_id)))
			return null;

		$query = "select `displayname` as `description` from `{$elastixDB}`.`ivr` where `ivr_id` = ?";
		$rows = $this->db->query($query, $ivr_id)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$description = $rows[0]->description;
			$query = "select `ivr_id` from `{$database}`.`ombu_ivrs` where `description` = '{$description}'";
			$rows = $this->db->query($query)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->ivr_id;
		}

		return null;
	}

	protected function _update(\stdClass $item){
		echo "Skipping Queue: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `queue_id` from `{$database}`.`ombu_queues` where `description` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->queue_id;

		return null;
	}

	public function setDestinations(){
		echo "Set Destinations to Queues.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->description)){
				$destination_id = $this->_getCurrentDestination($id, 'ombu_queues', 'queue_id', 'destination_id');

				if(!$destination_id)
					continue ;

				echo "Set Destination to Queue: {$row->description}\n";

				$destination = new destination($row->destination, 'queues', $db, $destination_id);
				$destination->create();
			}
		}

		$db->end_transaction();
	}
}