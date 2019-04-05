<?php
namespace library\modules;

use library\module;
use library\simple_pdo;

class timeGroups extends module {
	
	protected function getElastixData () {
		$database = \config::elastix_db;
		$timeGroups = [];
		
		$query = "select `id`, `description` from `{$database}`.`timegroups_groups`";
		$groups = $this->db->query($query)->get_rows();
		
		foreach ($groups as $i => $group){
			$timeGroups[$i] = (array) $group;
			$timeGroups[$i]['schedules'] = [];
			
			$query = "select `time` from `{$database}`.`timegroups_details`";
			$schedules = $this->db->query($query)->get_rows();
			foreach ($schedules as $schedule){
				$timeGroups[$i]['schedules'][] = str_replace('|',',', $schedule->time);
			}
		}
		
		return $timeGroups;
	}
	
	public function migrate () {
		echo "Migrating TimeGroups.......\n";
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
	
	protected function _add (\stdClass $item) {
		$db = $this->db;
		$database = \config::pbx_db;
		
		echo "Adding TimeGroup: {$item->description}\n";
		
		$query = "insert into `{$database}`.`ombu_time_groups` (`description`, `tenant_id`) values (?, ?)";
		$group = $db->query($query, $item->description, $this->tenant_id);
		$time_group_id = $group->get_inserted_id();
		$this->_addSchedules($item->schedules, $time_group_id, $db);
	}
	
	private function _addSchedules(array $schedules = [], $time_group_id, simple_pdo $db){
		$database = \config::pbx_db;
		foreach ($schedules as $i => $schedule){
			$this->_parseSchedule($schedule);
			
			if(!$schedule)
				continue;
			
			$query  = "insert into `{$database}`.`ombu_time_groups_schedules`
							(`time_group_id`, `time`, `sort`) values (?, ?, ?)";
			
			$db->query(
				$query,
				$time_group_id,
				$schedule->time,
				$i
			);
		}
	}
	
	private function _parseSchedule(&$schedule){
		if ($schedule !== '*,*,*,*') {
			$tc = explode(',', $schedule);
			$tmpMonthIni = '01';
			$tmpMonthEnd = '12';
			$tmpMonthDayIni = '01';
			$tmpMonthDayEnd = '31';
			$tmpWeekDayIni = '01';
			$tmpWeekDayEnd = '07';
			$tmpTimeIni = '00:00';
			$tmpTimeEnd = '23:59';
			
			if ($tc[3] !== '*') {
				$month = explode('-', $tc[3]);
				if($month[0] != '*') {
					$tmpMonthIni = $this->getNumericMonth($month[0]);
				}
				$month[1] = (isset($month[1]) ? $month[1] : $month[0]);
				if($month[1] != '*') {
					$tmpMonthEnd = $this->getNumericMonth($month[1]);
				}
			}
			
			if ($tc[2] !== '*') {
				$monthDay = explode('-', $tc[2]);
				$tmpMonthDayIni = $monthDay[0];
				$monthDay[1] = (isset($monthDay[1]) ? $monthDay[1] : $monthDay[0]);
				$tmpMonthDayEnd = $monthDay[1];
			}
			
			if ($tc[1] !== '*') {
				$weekDay = explode('-', $tc[1]);
				if($weekDay[0] != '*') {
					$tmpWeekDayIni = $this->getNumericDay($weekDay[0]);
				}
				$weekDay[1] = (isset($weekDay[1]) ? $weekDay[1] : $weekDay[0]);
				if($weekDay[1] != '*') {
					$tmpWeekDayEnd = $this->getNumericDay($weekDay[1]);
				}
			}
			
			if ($tc[0] !== '*') {
				$hour = explode('-', $tc[0]);
				$tmpTimeIni = $hour[0];
				$tmpTimeEnd = $hour[0];
				if(count($hour) > 1) {
					$tmpTimeEnd = $hour[1];
				}
			}
			
			$tcini = $tmpMonthIni . '-' . $tmpMonthDayIni . '-' . $tmpWeekDayIni . '-' . $tmpTimeIni;
			$tcend = $tmpMonthEnd . '-' . $tmpMonthDayEnd . '-' . $tmpWeekDayEnd . '-' . $tmpTimeEnd;
			
			$schedule = (object)[
				'time' => $schedule,
				'tcini' => $tcini,
				'tcend' => $tcend
			];
			
			return ;
		}
		
		$schedule = null;
	}
	
	private function getNumericMonth($month) {
		$monthData = [
			'jan' => '01',
			'feb' => '02',
			'mar' => '03',
			'apr' => '04',
			'may' => '05',
			'jun' => '06',
			'jul' => '07',
			'aug' => '08',
			'sep' => '09',
			'oct' => '10',
			'nov' => '11',
			'dec' => '12'
		];
		if(array_key_exists($month, $monthData)) {
			return $monthData[$month];
		}
		return null;
	}
	
	private function getNumericDay($day) {
		$dayData = [
			'mon' => '01',
			'tue' => '02',
			'wed' => '03',
			'thu' => '04',
			'fri' => '05',
			'sat' => '06',
			'sun' => '00'
		];
		if(array_key_exists($day, $dayData)) {
			return $dayData[$day];
		}
		return null;
	}
	
	protected function _update (\stdClass $item) {
		echo "Skipping TimeGroup: {$item->description}, Already exists!\n";
	}
	
	protected function _ItemExists ($value) {
		$database = \config::pbx_db;
		
		$query = "select `time_group_id` from `{$database}`.`ombu_time_groups` where `description` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->time_group_id;

		return null;
	}
}