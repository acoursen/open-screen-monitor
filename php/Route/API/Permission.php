<?php
namespace OSM\Route\API;

class Permission extends \OSM\Tools\Route {
	public $renderRaw = true;

	public function action(){
		if (!($_SESSION['api'] ?? false)){
			http_response_code(403);
			die('Permission Denied');
                }
		set_time_limit(0);

		$action = $_POST['action'] ?? '';

		if ($action == 'list'){
			$data = \OSM\Tools\DB::select('tbl_lab_permission');
			echo json_encode($data);
		} elseif ($action == 'update'){
			$data = $_POST['data'] ?? [];

			foreach($data['delete'] ?? [] as $delete){
				//make sure we only have valid keys, sql injection possible if not
				foreach(array_keys($delete) as $key){
					if (!in_array($key,['id','username','groupid'])){
						unset($delete[$key]);
					}
				}

				\OSM\Tools\DB::delete('tbl_lab_permission',['fields'=>$delete]);
			}
			foreach($data['insert'] ?? [] as $insert){
				\OSM\Tools\DB::insert('tbl_lab_permission',$insert);
			}
		}
	}
}
