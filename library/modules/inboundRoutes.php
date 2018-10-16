<?php
namespace library\modules;

use library\destination;
use library\module;
use library\postDestinations;

class inboundRoutes extends module implements postDestinations {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select
					`cidnum` as `cid_number`,
					`extension` as `did`,
					`description`,
					`destination`
				 from `{$database}`.`incoming`";

		return $this->db->query($query)->get_rows();
	}

	public function migrate(){
		echo "Migrating Inbound Routes.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($this->_ItemExists($row->did, $row->cid_number)){
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
		$description = (strlen(trim($item->description)) ? $item->description : $item->did);
		echo "Adding Inbound Route: {$description}\n";

		$destination_id = self::buildTemporaryDestination('inbound_route');
		$query = "insert into `{$database}`.`ombu_inbound_routes` 
					  (`description`, `did`, `cid_number`, `language`, `destination_id`) values
					  (?,?,?,?,?)";

		$db->query(
			$query,
			$description,
			$item->did,
			$item->cid_number,
			'en', //Hard Code - Language
			$destination_id
		);
	}

	protected function _update(\stdClass $item){
		$description = (strlen(trim($item->description)) ? $item->description : $item->did);
		echo "Skipping Inbound Route: {$description}, Already exists!\n";
	}

	protected function _ItemExists($did, $cid_number = ''){
		$database = \config::pbx_db;

		$query = "select `inbound_route_id` from `{$database}`.`ombu_inbound_routes` where `did` = '{$did}' and `cid_number` = '{$cid_number}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->inbound_route_id;

		return null;
	}

	public function setDestinations(){
		echo "Set Destinations to Inbound Routes.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($id = $this->_ItemExists($row->did, $row->cid_number)){
				$destination_id = $this->_getCurrentDestination($id, 'ombu_inbound_routes', 'inbound_route_id', 'destination_id');
				if(!$destination_id)
					continue ;

				$description = (strlen(trim($row->description)) ? $row->description : $row->did);
				echo "Set Destination to Inbound Route: {$description}\n";

				$destination = new destination($row->destination, 'inbound_route', $db, $destination_id);
				$destination->create();
			}
		}
		$db->end_transaction();
	}
}