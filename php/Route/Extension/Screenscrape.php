<?php
namespace OSM\Route\Extension;

class Screenscrape extends \OSM\Tools\Route {
	public $renderRaw = true;

	public function action(){
		//in case the http timeout kills the connection, still log it
		ignore_user_abort(1);

		$now = time();

		//validate data
		$post = json_decode($_POST['data'] ?? '[]',true);
		$data = [];
		$fields = ['text','url','sessionID','email','deviceID'];
		foreach($fields as $field){
			$data[$field] = $post[$field] ?? '';
		}
		$data['text'] = strtolower($data['text']);

                //allow custom hooking here
                //make sure to set restrictive permissions on this file
		$dataDir = $GLOBALS['dataDir'];
                if (file_exists($dataDir.'/custom/screenscrape-prepend.php')){
                        include($dataDir.'/custom/screenscrape-prepend.php');
                }


		if ($data['email'] == ''){$data['email'] = 'unknown';}

		//validate sessionID
		$data['sessionID'] = preg_replace('/[^0-9a-z\-]/','',$data['sessionID']);
		$data['sessionID'] = substr($data['sessionID'],0,36);
		//this is just to help keep collisions on the session id from happening
		//if these values change it may cause issues
		$data['sessionID'] .= '--'.md5($data['deviceID'].$data['email']);

		if ($data['url'] == '' || $data['text'] == ''){
			http_response_code(404);
			die();
		}

		//go through filter
		$filter = \OSM\Tools\Config::getFilter();
		foreach(['user','server'] as $pass){
			//determine action
			$toReturn = [];
			$action = '';
			$search = '';
			$word = '';
			$count = 1;
			$counted = 0;
			$email = '';

			foreach($filter['entries'] as $entry){
				if ($entry['resourceType'] != 'SCREENSCRAPE'){continue;}

				if ($pass == 'user' && !in_array($entry['action'],['BLOCK','BLOCKPAGE','BLOCKNOTIFY'])){continue;}
				if ($pass == 'server' && !in_array($entry['action'],['TRIGGER','TRIGGER_EXEMPT'])){continue;}

				if ($entry['username'] != '' && !$this->testString($data['email'], $entry['username'])){continue;}

				if (!$this->testURL($data,$entry['url'])){continue;}

				$words = $entry['initiator'];
				$words = explode(',',$words,2);
				$count = intval($words[1] ?? 1);

				$words = strtolower($words[0]);
				$words = explode('|',$words);
				foreach($words as $word){
					$counted = substr_count($data['text'],$word);
					if ($word == '' ||  (0 < $count && $count <= $counted)){
						$action = $entry['action'];
						$search = $entry['url'];
						$email = $entry['appName'];
						break 2;
					}
				}
			}


			//handle action
			if ($action == 'BLOCK') {
				$toReturn['commands'][] = ['action'=>'BLOCK'];
			} elseif ($action == 'BLOCKPAGE'){
				$toReturn['commands'][] = [
					'action'=>'BLOCKPAGE',
					'data'=>$this->urlRoot().'?block&data='.urlencode(base64_encode(gzcompress(json_encode([
						'url' => $data['url'],
						'username' => $data['email'],
						'search' => $search,
						'deviceID' => $data['deviceID'],
						'screenscrape' => $word,
					]),9))),
				];
				$toReturn['return']['cancel'] = true;
			} elseif ($action == 'BLOCKNOTIFY') {
				//show notification instead
				$toReturn['commands'][] = ['action'=>'BLOCK'];
				$toReturn['commands'][] = ['action'=>'NOTIFY','data'=>[
					'requireInteraction'=>false,
					'type'=>'basic',
					'iconUrl'=>'icon.png',
					'title'=>'Blocked Tab',
					'message'=>'Tab was blocked with the url '.$data['url'].' by OSM filter.',
				]];
			} elseif ($entry['action'] == 'TRIGGER'){
				$uid = md5(uniqid(time()));
				// header
				$header = "From: Open Screen Monitor <".$email.">\r\n";
				$header .= "MIME-Version: 1.0\r\n";
				$header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n\r\n";

				// message & attachment
				$text = "Screenscrape: ".date("Y-m-d h:i a")."\n\n";
				$text .= "\nUser: ".$data['email'];
				$text .= "\nDevice: ".$this->niceName($data['deviceID']);
				$text .= "\nDevice: ".$data['deviceID'];
				$text .= "\nDevice Address: ".str_replace(".",'-',$_SERVER['REMOTE_ADDR']);
				$text .= "\nSession ID: ".$data['sessionID'];
				$text .= "\nURL: ".$data['url'];
				$text .= "\nTriggered on keyword: $word ($counted)";

				$raw = "--".$uid."\r\n";
				$raw .= "Content-type:text/plain; charset=iso-8859-1\r\n";
				$raw .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
				$raw .= "$text\r\n\r\n";

				$screenshot = \OSM\Tools\TempDB::get('screenshot/'.$data['sessionID']);;
				if ($screenshot != '') {
					$raw .= "--".$uid."\r\n";
					$raw .= "Content-Type: image/jpeg; name=\"screenshot.jpg\"\r\n";
					$raw .= "Content-Transfer-Encoding: base64\r\n";
					$raw .= "Content-Disposition: attachment; filename=\"screenshot.jpg\"\r\n\r\n";
					$raw .= chunk_split(base64_encode($screenshot))."\r\n\r\n";
				}

				$raw .= "--".$uid."\r\n";
				$raw .= "Content-Type: text/plain; name=\"screenscrape.txt\"\r\n";
				$raw .= "Content-Transfer-Encoding: base64\r\n";
				$raw .= "Content-Disposition: attachment; filename=\"screenscrape.txt\"\r\n\r\n";
				$raw .= chunk_split(base64_encode($data['text']))."\r\n\r\n";

				$raw .= "--".$uid."--";
				mail($email, 'OSM Trigger Alert: '.preg_replace('/[^0-9a-zA-Z\_\-\@\.]/','',$data['email']), $raw, $header);
			} elseif ($entry['action'] == 'TRIGGER_EXEMPT'){
				//no action
			}

			if ($action != ''){
				\OSM\Tools\DB::insert('tbl_filter_log',[
					'date' => date('Y-m-d',$now),
					'time' => date('H:i:s',$now),
					'ip' => $_SERVER['REMOTE_ADDR'],
					'username' => $data['email'],
					'deviceid' => $data['deviceID'],
					'action' => $entry['action'],
					'type' => '',
					'url' => $word.'|'.$data['url'],
				]);
			}

			if ($pass == 'user'){
				//send it back
				$this->sendAndClose(json_encode($toReturn));
			}
		}
	}
}
