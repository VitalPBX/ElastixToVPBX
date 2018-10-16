<?php
namespace library\modules;

use library\module;

class conferences extends module {

	protected function getElastixData(){
		$database = \config::elastix_db;
		
		$query = "select
					`exten` as `extension`,
					`options`,
					`userpin`,
					`adminpin`,
					`description`,
					`joinmsg_id`
				from `{$database}`.`meetme`";
		
		$conferences = $this->db->query($query)->get_rows();
		
		return $conferences;
	}

	public function migrate(){
		echo "Migrating Conferences.......\n";
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
		
		echo "Adding Conference: {$item->description}\n";
		$this->_parseOptions($item);
		
		$query = "insert into `{$database}`.`ombu_conferences`
					(`extension`,
					`description`,
					`userpin`,
					`adminpin`,
					`language`,
					`record_conference`,
					`announcement_id`,
					`music_on_hold_when_empty`,
					`startmuted`,
					`quiet`,
					`announce_user_count_all`,
					`announce_user_count`,
					`announce_only_user`,
					`wait_marked`,
					`end_marked`,
					`dsp_drop_silence`,
					`talk_detection_events`,
					`announce_join_leave`
					) values  (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
		
		
		$db->query(
			$query,
			$item->extension,
			$item->description,
			$item->userpin,
			$item->adminpin,
			'en',
			$item->record_conference,
			recordings::getRecordingID($item->joinmsg_id, $db),
			$item->music_on_hold_when_empty,
			$item->startmuted,
			$item->quiet,
			$item->announce_user_count_all,
			$item->announce_user_count,
			$item->announce_only_user,
			$item->wait_marked,
			$item->end_marked,
			$item->dsp_drop_silence,
			$item->talk_detection_events,
			$item->announce_join_leave
		);
		
	}
	
	private function _parseOptions(&$item){
		$options = str_split($item->options);
		$item = (array) $item;
		$item['music_on_hold_when_empty'] = 'yes';
		$item['startmuted'] = 'no';
		$item['quiet'] = 'no';
		$item['announce_user_count_all'] = '';
		$item['announce_user_count'] = 'no';
		$item['announce_user_count'] = 'no';
		$item['announce_only_user'] = 'yes';
		$item['wait_marked'] = 'no';
		$item['end_marked'] = 'no';
		$item['dsp_drop_silence'] = 'yes';
		$item['talk_detection_events'] = 'no';
		$item['announce_join_leave'] = 'no';
		$item['record_conference'] = 'no';
		
		$item = (object) $item;
	}

	protected function _update(\stdClass $item){
		echo "Skipping Conference: {$item->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;
		
		$query = "select `conference_id` from `{$database}`.`ombu_conferences` where `extension` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->conference_id;

		return null;
	}

	public function getDependencies(){
		return ['recordings'];
	}
}