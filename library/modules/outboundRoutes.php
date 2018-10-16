<?php
namespace library\modules;

use library\module;
use library\simple_pdo;

class outboundRoutes extends module {

	protected function getElastixData(){
		$database = \config::elastix_db;
		$outboundRoutes = [];

		$query = "select
 					`route_id`,
					`name` as `description`, 
					`intracompany_route` as `intracompany`,
					`outcid` as `cid`
				from `{$database}`.`outbound_routes`
				order by `route_id` asc";

		$routes = $this->db->query($query)->get_rows();

		foreach ($routes as $i => $route){
			$outboundRoutes[$i] = (array) $route;

			$query = "select 
						`ot`.`trunk_id`, 
						`t`.`name` as `description`,
						`ot`.`seq` as `index`
					  from `{$database}`.`outbound_route_trunks` as `ot`
					  left join `{$database}`.`trunks` as `t` on (`t`.`trunkid` = `ot`.`trunk_id`)
					  where `route_id` = {$route->route_id}";

			$outboundRoutes[$i]['trunks'] = $this->db->query($query)->get_rows();


			$query = "select 
						`match_pattern_prefix` as `prefix`, 
						`match_pattern_pass` as `pattern`,
						`prepend_digits` as `prepend`
					  from `{$database}`.`outbound_route_patterns`
					  where `route_id` = {$route->route_id}";

			$outboundRoutes[$i]['patterns'] = $this->db->query($query)->get_rows();
		}

		return $outboundRoutes;

	}

	public function getDependencies(){
		return ['trunks'];
	}

	public function migrate(){
		echo "Migrating Outbound Routes.......\n";
		$db = $this->db;
		$db->start_transaction();
		$elastixRoutes = $this->getElastixData();
		foreach ($elastixRoutes as $route){
			$route = (object) $route;
			if($this->_ItemExists($route->description)){
				$this->_update($route);
			}else{
				$this->_add($route);
			}

		}
		$db->end_transaction();
	}

	protected function _add(\stdClass $route){
		echo "Adding Route: {$route->description}\n";
		$database = \config::pbx_db;
		$db = $this->db;
		$query = "insert into `{$database}`.`ombu_outbound_routes` 
						(`description`, `intra_company`) values (?, ?)";

		$intracompany = (preg_match('/yes/i', $route->intracompany) ? 'yes' : 'no');
		$result = $db->query($query, $route->description, $intracompany);
		$outbound_route_id = $result->get_inserted_id();

		//Add trunks
		$this->_addTrunks($route->trunks, $outbound_route_id, $db);
		$this->_addPatterns($route->patterns, $outbound_route_id, $db);
	}

	private function _addPatterns($patterns, $outbound_route_id, simple_pdo $db){
		$patterns = (array) $patterns;
		$database = \config::pbx_db;

		foreach ($patterns as $i => $pattern){
			$query = "insert into `{$database}`.`ombu_outbound_route_patterns`
							(`outbound_route_id`, `prepend`, `prefix`, `pattern`, `order`) values (?, ?, ?, ?, ?)";

			$db->query($query, $outbound_route_id, $pattern->prepend, $pattern->prefix, $pattern->pattern, $i);
		}
	}

	private function _addTrunks($trunks, $outbound_route_id, simple_pdo $db){
		$trunks = (array) $trunks;
		$database = \config::pbx_db;

		foreach ($trunks as $trunk){
			if($t = $this->_getTrunkByDescription($trunk->description, $db)){
				$query = "insert into `{$database}`.`ombu_outbound_route_members` 
								(`outbound_route_id`, `trunk_id`, `index`) values (?, ?, ?)";

				$db->query($query, $outbound_route_id, $t->trunk_id, $trunk->index);
			}

			continue;
		}
	}

	private function _getTrunkByDescription($description, simple_pdo $db){
		$database = \config::pbx_db;
		$trunk = $db->query("select 
									  `trunk_id` 
								    from `{$database}`.`ombu_trunks` 
								    where `description` = '$description'
				 ")->get_rows();

		if(is_array($trunk) && array_key_exists(0, $trunk))
			return $trunk[0];

		return null;
	}

	protected function _update(\stdClass $route){
		echo "Skipping Outbound Route: {$route->description}, Already exists!\n";
	}

	protected function _ItemExists($value){
		$database = \config::pbx_db;
		$query = "select `outbound_route_id` from `{$database}`.`ombu_outbound_routes` where `description` = '$value'";
		$rows = $this->db->query($query)->get_rows();

		if(array($rows) && array_key_exists(0,$rows))
			return $rows[0]->outbound_route_id;

		return null;
	}

}