<?php
namespace library\modules;

use library\module;
use library\simple_pdo;

class recordings extends module {

	protected function getElastixData(){
		$database = \config::elastix_db;

		$query = "select 
					`displayname` as `name`, 
					`filename` as `original_filename`
				from `{$database}`.`recordings`";

		return $this->db->query($query)->get_rows();
	}

	public function migrate(){
		echo "Migrating Recordings.......\n";
		$db = $this->db;
		$db->start_transaction();
		$rows = $this->getElastixData();
		foreach ($rows as $row){
			$row = (object) $row;
			if($this->_ItemExists($row->name)){
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

		if($item->name === '__invalid')
			return ;

		echo "Adding Recording: {$item->name}\n";

		$filename = $item->original_filename;
		$filename = explode('&', $filename);
		$filename = str_replace('custom/','', array_shift($filename).".wav");

		$query = "insert into `{$database}`.`ombu_recordings` 
					  (`original_filename`, `name`, `duration`) values
					  (?,?,?)";

		$db->query(
			$query,
			$filename,
			$item->name,
			0
		);
	}

	protected function _update(\stdClass $item){
		echo "Skipping Recording: {$item->name}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;

		$query = "select `recording_id` from `{$database}`.`ombu_recordings` where `name` = '{$value}'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->recording_id;

		return null;
	}

	public static function getRecordingID($id = null, simple_pdo $db){
		if(!$id)
			return null;

		$database = \config::pbx_db;
		$elastixDB = \config::elastix_db;

		$query = "select `displayname` as `name` from `{$elastixDB}`.`recordings` where `id` = ?";
		$rows = $db->query($query, $id)->get_rows();

		if(is_array($rows) && array_key_exists(0, $rows)){
			$name = $rows[0]->name;
			$query = "select `recording_id` from `{$database}`.`ombu_recordings` where `name` = '{$name}'";
			$rows = $db->query($query)->get_rows();

			if(is_array($rows) && array_key_exists(0, $rows))
				return $rows[0]->recording_id;
		}

		return null;
	}
}