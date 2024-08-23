<?php
namespace OSM\Route\Admin;

class Synconeroster extends \OSM\Tools\Route {
	public function action(){
		$this->requireAdmin();

		set_time_limit(0);

		\OSM\Tools\DB::truncate('tbl_oneroster');
		$enrollments = \OSM\Tools\OneRoster::downloadData();
		foreach($enrollments as $enrollment){
			\OSM\Tools\DB::insert('tbl_oneroster',$enrollment);
		}

		//allow custom hooking here
		//make sure to set restrictive permissions on this file
		if (file_exists($GLOBALS['dataDir'].'/custom/sync-oneroster-append.php')){
			require_once($GLOBALS['dataDir'].'/custom/sync-oneroster-append.php');
		}


		echo 'Done (count: '.count($enrollments).')';
	}
}
