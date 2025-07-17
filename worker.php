<?php
// +---------------------------------------------------------------------+
//	名称 宇视人脸识别闸机核验
//  适用 北海二中 	
//  位置 大门 $location ='大门';	宿舍 $location ='宿舍';														
// +---------------------------------------------------------------------+
use Workerman\Worker;
use Workerman\Redis\Client;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Http\Response;
require_once __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('PRC'); 
ini_set("error_reporting", "E_ALL & ~E_NOTICE");
//ini_set("display_errors", "stderr");  //ini_set函数作用：为一个配置选项设置值，
//error_reporting(E_ALL);
include './comm/SM4Algorithm.php'; 
include './comm/function.php'; 

//TcpConnection::$defaultMaxSendBufferSize = 2*1024*1024;//该回调可能会在调用Connection::send后立刻被触发，比如发送大数据或者连续快速的向对端发送数据，由于网络等原因数据被大量积压在对应连接的发送缓冲区，
//当超过TcpConnection::$maxSendBufferSize上限时触发。
$http_worker = new Worker("http://0.0.0.0:8080");
// 启动1个进程对外提供服务
$http_worker->count = 1;
$http_worker->onWorkerStart = function($http_worker)
{	
	global $redis;
	//$redis = new Client('redis://0.0.0.0:6379');
	//$redis = new Client('redis://127.0.0.1:6379');
	$redis = new Redis();
	$redis->connect('127.0.0.1',6379,1);
	$redis->select(0);
	global $sm4 ;
	$sm4 = new SM4();
    //将db实例存储在全局变量中(也可以存储在某类的静态成员中)
    global $db;
	$db = new \Workerman\MySQL\Connection('127.0.0.1', '3306', 'root', '^2zhlmcl@1hblsxt^', 'guard');
	
	//教职工类型
	global $zhiwu ;
	$sql = "select descript from parameter where name='职务' ";//长顺 正安 叫做职务。德江可以使用 寝室职务 也可职务。
 	$descript = $db->row($sql);
	$zhiwu = json_decode($descript['descript'],1);

	
	global $bumen ;
	$sql = "select descript from parameter where name='部门' ";
	$descript = $db->row($sql);
	$bumen = json_decode($descript['descript'],1);
	
	// 班级数据
	global $class_array;
	$sql = "select id,name from classinfo  ";
	$class_data = $db->query($sql);
	foreach($class_data as $k=>$v){
		$class_array[$v['id']]=$v['name'];
	};
	//var_dump($class_array);
	// 年级数据
	global $grade_array;
	$sql = "select id,name from grade  ";
	$grade_data = $db->query($sql);
	foreach($grade_data as $k=>$v){
		$grade_array[$v['id']]=$v['name'];
	};
	//var_dump($grade_array);

	//学生信息
	global $student_list;
	$student_sql = "select * from studentinfo ";
	$student_result = $db->query($student_sql);
	foreach($student_result as $k=>$v){
		if(!$v['id']){
			var_dump('id');continue;
		}
		if(!$v['id']){
			var_dump('descript');continue;
		}
		$desc=$sm4->decrypt($v['id'],$v['descript']);
		$desc = json_decode($desc,1);
		$desc['id']=$v['id'];
		$desc['PersonID']=$v['PersonID'];
		$student_list[$v['PersonID']]=$desc;
	};
	//var_dump($student_list);

	//教职工存储在全局变量中
	global $teacher_list;
	$teacher_sql = "select * from teacher ";
	$teacher_result = $db->query($teacher_sql);
	foreach($teacher_result as $k=>$v){
		$desc=$sm4->decrypt($v['id'],$v['descript']);
		$desc = json_decode($desc,1);
		$desc['id']=$v['id'];
		$desc['PersonID']=$v['PersonID'];
		$desc['personnel_type']=$v['personnel_type'];
		$zhiwu_array = explode(',',$desc['职务']);
		//var_dump($zhiwu_array);
		$name=[];
		foreach ($zhiwu as $kzw=>$vzw){
			if(in_array($kzw,$zhiwu_array)){
				array_push($name,$vzw);
			}
		}
		$desc['职务'] = implode(',',$name);
		$teacher_list[$v['PersonID']]=$desc;
	};
	/*
	foreach($teacher_list as $k =>$v){
		var_dump($v['职务']);
	}
	*/
	//var_dump($teacher_list);

	
	//将闸机设备 
	global $sluice_list;
	$sluice_list=[];
	$device_sql = "select * from sluice ";
	$device_result = $db->query($device_sql);
	foreach($device_result as $k=>$v){
		$descript=json_decode($v['descript'],1);
		$descript_base=json_decode($v['descript_base'],1);
		$descript['name']=$v['name'];
		$descript['code']=$v['code'];
		$descript['Address']=$descript_base['Address'];
		$sluice_list[$v['code']] = $descript;	
	};
	//var_dump($sluice_list);


	global $stutype;	
	$sql_stutype="select descript from parameter where name='学生类型' ";
	$descript=$db ->row($sql_stutype);
	$stutype= json_decode($descript['descript'],1);
	unset($stutype['名称']);
		//var_dump($stutype);
	global $web_worker;
	$web_worker = new Worker('websocket://127.0.0.1:2022');
    // 设置端口复用，可以创建监听相同端口的Worker（需要PHP>=7.0）
    //$web_worker->reusePort = true;
    $web_worker->onMessage = 'web_on_message';
    // 执行监听。正常监听不会报错
    $web_worker->listen();
	//$result = file_get_contents($url);	

};
// 当闸机发来数据时  给闸机回包$connection->send(json_encode($all_tables));
$http_worker->onBufferFull = function(TcpConnection $connection)
{
    echo "bufferFull and do not send again\n";
};
$http_worker->onMessage = function(TcpConnection $sluice_connection, $request)
{	
	//var_dump('==========');
	//var_dump($request);
	
	$location ='大门';
	
	
	global $redis;
	
	$send_data=[];
	global $db;
	global $sm4;
	global $zhiwu;
	global $bumen;	

	global $student_list;
	global $teacher_list;
	$update_teacher_and_student = file_get_contents('./sync/update_teacher_and_student.txt');
	if($update_teacher_and_student){
		
		//学生信息

		$student_sql = "select * from studentinfo where worker_update ='1'";
		$student_result = $db->query($student_sql);
		foreach($student_result as $k=>$v){

			$desc=$sm4->decrypt($v['id'],$v['descript']);
			$desc = json_decode($desc,1);
			$desc['id']=$v['id'];
			$desc['PersonID']=$v['PersonID'];
			$student_list[$v['PersonID']]=$desc;
		};
		$db->query("update studentinfo set worker_update ='0' where worker_update ='1' ");
		//var_dump($student_list);


		//教职工存储在全局变量中

		$teacher_sql = "select * from teacher where worker_update ='1'";
		$teacher_result = $db->query($teacher_sql);
		foreach($teacher_result as $k=>$v){
			$desc=$sm4->decrypt($v['id'],$v['descript']);
			$desc = json_decode($desc,1);
			$desc['id']=$v['id'];
			$desc['PersonID']=$v['PersonID'];
			$desc['personnel_type']=$v['personnel_type'];
			$zhiwu_array = explode(',',$desc['职务']);
			$name=[];
			foreach ($zhiwu as $kzw=>$vzw){
				if(in_array($kzw,$zhiwu_array)){
					array_push($name,$vzw);
				}
			}
			$desc['职务'] = implode(',',$name);
			$teacher_list[$v['PersonID']]=$desc;
		};	
		$db->query("update teacher set worker_update ='0' where worker_update ='1' ");		
		file_put_contents('./sync/update_teacher_and_student.txt','0');
	}

	global $web_worker;
	
	global $sluice_list;
	
	$update_sluice_list = file_get_contents('./sluice_list.txt');
	if($update_sluice_list){
		$device_sql = "select * from sluice ";
		$device_result = $db->query($device_sql);
		foreach($device_result as $k=>$v){
			$descript=json_decode($v['descript'],1);
			$descript_base=json_decode($v['descript_base'],1);
			$descript['name']=$v['name'];
			$descript['code']=$v['code'];
			$descript['Address']=$descript_base['Address'];
			$sluice_list[$v['code']] = $descript;	
		};
		file_put_contents('./sluice_list.txt','0');
	}
	global $student_list;
	global $stutype;
	global $grade_array;
	global $class_array;
	$accept_data = json_decode($request->rawBody(),1);
	if(count($accept_data) == 5 && $accept_data['Time']){//心跳
		$time = date("Y-m-d H:i:s",time());	
		$response = new Response(200, [
			//'Content-Length' => '109',
			'Content-Type' => 'text/plain',
			'Connection' => 'close',
			'X-Frame-Options' => 'SAMEORIGIN',
		], '{"ResponseURL":"/LAPI/V1.0/PACS/Controller/HeartReportInfo","Code":"0","Data":{"Time":"'.$time.'"}}');	
		$sluice_connection->send($response);	
	}
	$Image_base64 = @$accept_data['FaceInfoList'][0]['FaceImage']['Data'];
	$DeviceCode = @$accept_data['DeviceCode'];//闸机设备编码，默认和设备序列号保持一致
	$Timestamp = $accept_data['FaceInfoList'][0]['Timestamp']?$accept_data['FaceInfoList'][0]['Timestamp']:$accept_data['CardInfoList'][0]['Timestamp'];//人脸记录产生时间
	$CapSrc = $accept_data['FaceInfoList'][0]['CapSrc']?$accept_data['FaceInfoList'][0]['CapSrc']:$accept_data['CardInfoList'][0]['CapSrc'];//1刷脸 2刷卡
	if(!$Timestamp){
		$Timestamp = time();
	}
	$insert_success=0;
	if($sluice_list[$DeviceCode]){
		$sluice_data = $sluice_list[$DeviceCode];
	}else{
		$send_data=[
			'code'=>400,
			'msg'=>'设备信息错误,请刷新设备信息并重启监控程序',
			'data'=>[
				'time'=>date("Y-m-d H:i:s",$Timestamp),
				'device_name'=>$sluice_data['name'],
				'device_position'=>$sluice_data['位置'],
				'device_type'=>$sluice_data['进出类型'],
				'user_name'=>'',
				'user_type'=>'',								
				'user_card'=>'',													
				'user_class'=>'',
				'user_grade'=>'',
				'img'=>$Image_base64,
			],
		];
		$send_data=json_encode($send_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
		foreach($web_worker->connections as $connection)
		{
			$connection->send($send_data);
		}
	}	
	//var_dump($accept_data);
	//验证权限步骤	NotificationType 0：实时通知 1：历史通知
	$MatchStatus = @$accept_data['LibMatInfoList'][0]['MatchStatus'];
	$MatchPersonID = @$accept_data['LibMatInfoList'][0]['MatchPersonID'];//匹配到的PersonID
/***********************************************************************************实时记录处理开始*********************************************************/
	if(@$accept_data['NotificationType']==0){//显示时时通知		
		//file_put_contents('a.txt',"\n".json_encode($accept_data),FILE_APPEND);
		$insert_success=0;//数据入库 设为1 并发回包					
		//先看识别成功，看看是哪个闸机发来的数据
		//按LibMatInfoList->LibMatInfoList->0->MatchStatus:0：无核验状态 1核验成功   2：核验失败（比对失败）3：核验失败（对比成功，不在布控时间）4：核验失败（证件已过期）5：强制停止6：非活体		
		if($MatchStatus==1){
			//查找过闸人员信息
			if($MatchPersonID>=1000000){//教职工过闸		
				$teacher = $teacher_list[$MatchPersonID];			
				if($teacher){//先一律开门 存记录 	补充布放计划
					$opendoor =0;						
					$send_data=[
						'code'=>200,
						'msg'=>'',
						'data'=>[
							'time'=>date("Y-m-d H:i:s",time()),
							'device_name'=>$sluice_data['name'],
							'device_position'=>$sluice_data['位置'],
							'device_type'=>$sluice_data['进出类型'],
							'user_name'=>$teacher['教师姓名'],
							'user_card'=>$teacher['教师编号'],
							'user_type'=>$teacher['personnel_type'],							
							'user_grade'=>$bumen[$teacher['部门']]?$bumen[$teacher['部门']]:'无',
							'user_class'=>$teacher['职务'],
							'img'=>$Image_base64,
							'idcard'=>'../teacher/'.$teacher['头像'],
							'img_name'=>$teacher['PersonID'].'_'.date('YmdHis',$Timestamp).".jpg",									
						],
					];
					$times_teacher=0;					
					$xingqi  = date('w');
					$opentime_list=[];
					$opentime_sql = "select * from opentime  where json_value(descript,'$.\"位置\"')='{$location}' and (json_value(descript,'$.\"人员类型\"')='教职工' or json_value(descript,'$.\"人员类型\"')='第三方人员')";//布放计划
					$opentime_result = $db->query($opentime_sql);
					foreach($opentime_result as $k=>$v){
						$desc = json_decode($v['descript'],1);
						
						if($desc['启用']=='启用' && $desc['星期'.$xingqi] && $desc['人员类型'] == $teacher['personnel_type']){
							
							foreach($desc['开始时间'.$xingqi] as $itemk=>$itemv){
								$open_time=strtotime($itemv);
								$end_time=strtotime($desc['结束时间'.$xingqi][$itemk]);
								if(time()>=$open_time && time()<=$end_time ){//&& $sluice_data['进出类型']==$desc['进出'][$itemk]
									if($sluice_data['进出类型']=='进'){
										$times_teacher = $desc['进次数'.$xingqi][$itemk];	
									}else{
										$times_teacher = $desc['出次数'.$xingqi][$itemk];	
									}
									break;
								}
							}			
						}		
					};								
					if($times_teacher){
						//var_dump($times_teacher);
						//查询过闸记录
						$pass_time_sql ="select count(*) as count from crossing_teacher where PersonID ='".$teacher['PersonID']."' and in_out='".$sluice_data['进出类型']."' and time>='$open_time' and time<='$end_time'";		
						$pass_time_query = $db->row($pass_time_sql);
						//var_dump($pass_time_query['count']);
						if($pass_time_query['count'] >= $times_teacher){
							$send_data['msg'] = '过闸次数超过限定。';	
							file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名']."过闸次数超过限定",FILE_APPEND);
							var_dump('过闸次数超过限定。');								
						}else{//放行
							//file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s').$teacher['教师姓名']."放行了",FILE_APPEND);
							//var_dump('部门:'.$bumen[$teacher['部门']].',职务:'.$teacher['职务'].$teacher['教师姓名'].',教职工放行了');
							$teacher_can_pass=1;
						}							
					}else{
						$send_data['msg'] = '不在放行时间段内。';
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名']."不在放行时间段内",FILE_APPEND);
						var_dump('不在放行时间段内');
					}
					//20230214新增 过闸人员，限定;
					if(!in_array($teacher['personnel_type'],array_filter(explode(',',$sluice_data['过闸人员'])))){
						
						$send_data['msg'] = $sluice_data['name'].'过闸人员不允许'.$teacher['personnel_type'].'通行';
						var_dump($send_data['msg']);
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名'].$sluice_data['name'].'过闸人员不允许'.$teacher['personnel_type'].'通行',FILE_APPEND);
						$teacher_can_pass = 0;
					}
					//过闸方式 1 2刷脸  2刷卡;
					/*
					if(!in_array($CapSrc,array_filter(explode(',',$sluice_data['过闸方式'])))){
						$str = $CapSrc=='1'?'刷脸':'刷卡';
						$send_data['msg'] = $sluice_data['name'].'过闸方式不允许'.$str.'通行';
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名'].$sluice_data['name'].'过闸方式不允许'.$str.'通行',FILE_APPEND);
						var_dump($send_data['msg']);
						$teacher_can_pass = 0;
					}	
					*/
					if($teacher_can_pass){
						$opendoor =1;
						$send_data['msg'] = '开门成功';	
						//$url="http://".$sluice_data['Address']."/LAPI/V1.0/PACS/Controller/RemoteOpened";	
						$url="http://".$sluice_data['Address']."/LAPI/V1.0/PACS/Controller/GUIShowInfoEx";	
						
						$put_img = base64_encode(file_get_contents('./teacher/'.$teacher['头像']));
						$put_data = [
							"ResultCode"=>1,
							"PassTime"=>date("YmdHis",$Timestamp),
							"ResultCmd"=>2,
							"CodeStatus"=>1,
							"ResultColor"=>1, 
							"ResultMsg"=>$bumen[$teacher['部门']]?$bumen[$teacher['部门']]:'无',
							"AudioEnable"=>0,
							"AudioIndex"=>8,
							"ShowInfoNum"=>1, 
							"ShowInfoList"=>[
								[
									"Key"=>"姓名:",
									"Value"=>$teacher['教师姓名'],
								]],
							"ImageEnable"=>0,
							//"ImageLen"=>strlen($put_img), 
							"ImageLen"=>"0", 
							//"ImageData"=>$put_img,
							"ImageData"=>"",
						];
						
						$put_data=json_encode($put_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
						//file_put_contents('b.txt',$put_data); 
						puturl($url,$put_data);
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名']."开门指令已下发，闸机".$sluice_data['name']."闸机时间：".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
					}
					//过闸人脸 存本地文件夹 crossing_student/日期
					$img_name = $teacher['PersonID'].'_'.date('YmdHis',$Timestamp).".jpg";
					if($Image_base64){
						$img_data= base64_decode($Image_base64);
						
						$fp2=@fopen("./face/crossing_teacher/".date('Y-m-d').'/'.$img_name,'a');
						fwrite($fp2,$img_data);
						fclose($fp2);							
					}						
					$crossing_data=[
						'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
						'设备上报时间'=>$Timestamp,
						'设备序列号'=>$sluice_data['code'],
						'进出'=>$sluice_data['进出类型'],
						'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息
						'闸机名称'=>$sluice_data['name'],
						'核验'=>'成功',
						'位置'=>$sluice_data['位置'],
						'教师姓名'=>$teacher['教师姓名'],
						'教师编号'=>$teacher['教师编号'],
						'身份证'=>$teacher['身份证'],
						'职务'=>$teacher['职务'],
						'班级ID'=>$teacher['班级ID'],
						'年级ID'=>$teacher['年级ID'],
						'照片'=>"./crossing_teacher/".date('Y-m-d').'/'.$img_name,
						'教师ID'=>$teacher['id'],
						'校区ID'=>$teacher['校区ID'],			
						'楼栋ID'=>@$teacher['楼栋ID'],
						'楼层ID'=>@$teacher['楼层ID'],
						'宿舍ID'=>@$teacher['宿舍ID'],//无此项
						'房号'=>@$teacher['房号'],//无此项
								
					];					
					//入库
					$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
					$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
					$sql_insert = "insert into crossing_teacher (code,name,descript,PersonID,time,in_out) values('0','".$teacher['教师姓名']."','$descript_json','$MatchPersonID','$Timestamp','".$sluice_data['进出类型']."')";	
					$result = $db->query($sql_insert);
					if($result){
						$insert_success=1;
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名']."实时记录入库",FILE_APPEND);
						var_dump(date("Y-m-d H:i:s",time()).':实时记录入库:'.$sluice_data['位置'].":".$sluice_data['name'].':'.$sluice_data['进出类型'].':(教职工)'.$teacher['教师姓名']);	
					}else{
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(教师)".$teacher['教师姓名']."时信信息存储失败",FILE_APPEND);
						var_dump('教职工实时信息存储失败：');
						var_dump($db);
					}						
							
				}else{
					$opendoor = 0;
					$send_data['msg'] = '教职工信息错误。';
				}
			}else{//学生过闸     默认后台设置的过闸时间段没有重复的时间段 如果有，按遍历方法决定（最后一个？）
				//var_dump(date("Y-m-d H:i:s",time()).'收到过闸数据，开始处理。');
				$opendoor =0;
				$conditions = 1;//通道过闸人员和过闸类型的限制
				$push_to_parents=0;
				$push_to_teacher=0;
				$tongxing_msg ='禁止通行';
				$push_msg ="未推送";
				$remarks = '不在布防允许时间内';
				$student = $student_list[$MatchPersonID];	
				//var_dump('Timestamp:'.$Timestamp.'-'.$sluice_data['位置'].':'.$sluice_data['进出类型']);
				if($student){
				
					//给web端 默认值
					$send_data=[
						'code'=>200,
						'msg'=>'开门成功',
						'data'=>[
							'time'=>date("Y-m-d H:i:s",$Timestamp),
							'device_name'=>$sluice_data['name'],
							'device_position'=>$sluice_data['位置'],
							'device_type'=>$sluice_data['进出类型'],
							'user_name'=>$student['学生姓名'],
							'user_card'=>$student['学生编号'],								
							'user_type'=>$stutype[$student['学生类型']],
							'user_class'=>$class_array[$student['班级ID']],
							'user_grade'=>$grade_array[$student['年级ID']],
							'img'=>$Image_base64,
							'idcard'=>'../student/'.$student['头像'],
							'img_name'=>$student['PersonID'].'_'.date('YmdHis',$Timestamp).".jpg",
						],
					];
					//20230214新增 过闸人员，限定;
					if(!in_array('学生',array_filter(explode(',',$sluice_data['过闸人员'])))){
						
						$remarks = $send_data['msg'] = $sluice_data['name'].'过闸人员不允许学生通行';
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$send_data['msg'],FILE_APPEND);
						var_dump($send_data['msg']);
						$conditions = 0;
					}
					//过闸方式 1 2刷脸  2刷卡;
					if(!in_array($CapSrc,array_filter(explode(',',$sluice_data['过闸方式'])))){
						$str = $CapSrc=='1'?'刷脸':'刷卡';
						$remarks = $send_data['msg'] = $sluice_data['name'].'过闸方式不允许'.$str.'通行';
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$send_data['msg'],FILE_APPEND);
						var_dump($send_data['msg']);
						$conditions = 0;
					}	
					if($conditions){
						
						//20240925 北海二中 大门电脑的控制页面  新增  一键放假  功能
						$grade_sql = "select * from grade where id ='{$student['年级ID']}'";
						$grade = $db->row($grade_sql);
						$grade_desc = json_decode($grade['descript'],1);
						if($grade_desc['状态']=='放假'){
							$times = 9999;
							$can_pass=1;
							$push_to_parents=1;
						}else{						
							$pass_time_leave = 2; //请假过闸次数 默认值 2
							$ask_msg = '布防';
							//布放计划 开始
							$can_pass=0;					
							$xingqi  = date('w');
							$leave_id =0;
							$opentime_list=[];
							$opentime_sql = "select * from opentime  where json_value(descript,'$.\"位置\"')='{$location}'";//布放计划
							//var_dump($opentime_sql);
							$opentime_result = $db->query($opentime_sql);
							foreach($opentime_result as $k=>$v){
								$desc = json_decode($v['descript'],1);
								//var_dump($desc['启用']=='启用');
								//var_dump($desc['星期'.$xingqi]);
								//var_dump($desc['学生类型'] == $student['学生类型']);
								//var_dump($desc['人员类型'] == '学生');
								//var_dump($desc['年级ID'] == $student['年级ID']);
								$stu_type_arr = explode($desc['学生类型']);
								$stu_guard_arr = explode($desc['年级ID']);
								if($desc['启用']=='启用' && $desc['星期'.$xingqi] && $desc['学生类型'] == $student['学生类型'] && $desc['人员类型'] == '学生' && $desc['年级ID'] == $student['年级ID']){
								//if($desc['启用']=='启用' && $desc['星期'.$xingqi] && in_array($student['学生类型'],array_filter($stu_type_arr)) && $desc['人员类型'] == '学生' && in_array($student['年级ID'],array_filter($stu_guard_arr))){	
									
									//$pass_time_leave = $desc['请假开门'];
									if($sluice_data['进出类型']=='进'){
										$pass_time_leave = $desc['请假开门进'];
									}else{
										$pass_time_leave = $desc['请假开门出'];
									}
									//var_dump($desc['开始时间'.$xingqi]);
									//var_dump($desc['进次数'.$xingqi]);
									//var_dump($desc['出次数'.$xingqi]);
									foreach($desc['开始时间'.$xingqi] as $itemk=>$itemv){
										
									//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."".$sluice_data['name']." 匹配闸机开始时间：".$itemv,FILE_APPEND);
									//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."".$sluice_data['name']." 匹配闸机结束时间：".$desc['结束时间'.$xingqi][$itemk],FILE_APPEND);
										$open_time=strtotime($itemv);
										$end_time=strtotime($desc['结束时间'.$xingqi][$itemk]);
										
										//var_dump("开始时间:".$itemv);
										//var_dump("结束时间:".$desc['结束时间'.$xingqi][$itemk]);
										
										if(time()>=$open_time && time()<=$end_time ){//&& $sluice_data['进出类型']==$desc['进出'][$itemk]
											//var_dump($itemv);
											//var_dump($desc['结束时间'.$xingqi][$itemk]);
											
											if($sluice_data['进出类型']=='进'){
												$times = $desc['进次数'.$xingqi][$itemk];
												//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$stutype[$student['学生类型']]."".$sluice_data['name']." id：".$v['id'],FILE_APPEND);
												//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$grade_array[$student['年级ID']]."".$sluice_data['name']." 星期：".$xingqi,FILE_APPEND);
												//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."".$sluice_data['name']." itemk：".$itemk,FILE_APPEND);
												//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."".$sluice_data['name']." 进次数：".$times,FILE_APPEND);
													
											}else{
												$times = $desc['出次数'.$xingqi][$itemk];
												//file_put_contents('./log2/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."".$sluice_data['name']." 出次数：".$times,FILE_APPEND);											
											}
											$push_to_parents=$desc['push'.$xingqi][$itemk]=='on'?1:0;
											break;
										}
									}			
								}		
							};
						}
						var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':过闸次数is:'.$times);							
						if($times && $times < 9999){
							//var_dump('times:'.$times);
							//查询过闸记录
							//$pass_time_sql ="select count(*) as count from crossing_student where pass = '允许通行' AND PersonID ='".$student['PersonID']."' and in_out='".$sluice_data['进出类型']."' and time>='$open_time' and time<='$end_time'";
						
							$pass_time_sql = "select count(*) as count from crossing_student_temp where time>='{$open_time}' and time<='{$end_time}' AND PersonID ='{$student['PersonID']}' and in_out='{$sluice_data['进出类型']}'";
											
							$pass_time_query = $db->row($pass_time_sql);
							//var_dump('count:'.$pass_time_query['count']);
							if($pass_time_query['count']>=$times){
								//参考5秒内的结果，如果有 运行通行,就放。
								$time5 = $Timestamp - 5;
							
															
								//$pass_5s_sql ="select id  from crossing_student where pass = '允许通行' AND PersonID ='".$student['PersonID']."' and in_out='".$sluice_data['进出类型']."' and time>='$time5' ";
								$pass_5s_sql = "select id  from crossing_student_temp where time >= '{$time5}'  AND PersonID ='".$student['PersonID']."' and in_out='{$sluice_data['进出类型']}' ";
																
								$pass_5s_query = $db->row($pass_5s_sql);
								if($pass_5s_query){
									$remarks ='5秒内有放行,结果放行';
									file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 5秒内有放行,结果放行,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
									var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].'5秒内有放行,结果放行');
									$can_pass = 1;
								}else{
									$send_data['msg']='过闸次数超过限定。';
									$remarks ='过闸次数超过限定。';
									file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 过闸次数超过限定,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
								
									var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':过闸次数超过限定。');							
								}
							}else{//放行
								//回头刷问题处理：相同通道。刷了一个方向。另一个方向5秒内刷闸不生效。
								$time5 = $Timestamp - 5;
								
								if($sluice_data['进出类型']=='进'){
									$fan=='出';
								}else{
									$fan=='进';
								}
								$pass_5s_sql = "select id  from crossing_student_temp where time >= '{$time5}'  AND PersonID ='{$student['PersonID']}' and in_out='{$fan}' and code ='{$sluice_data['code']}'";
								$pass_5s_query = $db->row($pass_5s_sql);
								
								if($pass_5s_query){
									$remarks ='5秒内有另一个方向刷记录，本次刷不生效';
									file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 5秒内有另一个方向刷记录,本次刷不生效,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
									var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':5秒内有另一个方向刷记录,本次刷不生效');
									
								}else{
									$remarks = '布防时间内放行';
									file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 布防时间内放行,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
								
									var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':布防时间内放行');
									$can_pass = 1;						
								}								
								

							}							
						}elseif($times == 9999){
							file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 年级放假自由通行,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
							
							$send_data['msg']='年级放假自由通行。';
							var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':年级放假自由通行');
						}else{
							file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 不在放行时间段内,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
							
							$send_data['msg']='不在放行时间段内。';
							var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':不在放行时间段内');
						}				

						if($can_pass ){//放行，存储过闸记录，并推送公功能公众号消息 //上报云端 待定	
							$opendoor =1;
							//$push_to_parents=1;
							$tongxing_msg ='允许通行';
						}else{
							//在此处 处理 学生请假。		
							file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 不在布放时间段内,进入请假流程,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
							
							var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':进入请假流程');
							$leave_sql = 'select * from ask_for_leave';
							$leave_list = $db->query($leave_sql);
							$ask_leave=[];
							//var_dump($leave_list);
							foreach($leave_list as $kleave=>$vleave){
						
								if($vleave['stu_id'] == $student['id'] ){
									$ask_msg = '请假';
									$leave_time = $vleave['leave_time'];
									$back_time = $vleave['back_time'];
									//var_dump('申请离校时间:'.$leave_time);
									//var_dump('回校截至时间:'.$back_time);
																	
									if(time()>= strtotime($vleave['leave_time']) && time()<= strtotime($vleave['back_time'])){
										//回头刷问题处理：相同通道。刷了一个方向。另一个方向5秒内刷闸不生效。
										$time5 = $Timestamp - 5;
										
										if($sluice_data['进出类型']=='进'){
											$fan=='出';
										}else{
											$fan=='进';
										}
										$pass_5s_sql = "select id  from crossing_student_temp where time >= '{$time5}'  AND PersonID ='{$student['PersonID']}' and in_out='{$fan}' and code ='{$sluice_data['code']}'";
										$pass_5s_query = $db->row($pass_5s_sql);
										
										if($pass_5s_query){
											$remarks ='请假过闸5秒内有另一个方向刷记录，本次刷不生效';
											file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 请假过闸 5秒内有另一个方向刷记录,本次刷不生效,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
											var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':请假过闸5秒内有另一个方向刷记录,本次刷不生效');
											
										}else{
											$sql = "select count(*) as in_out_mun  from crossing_student where pass = '允许通行' AND ask_for_leave='请假' AND in_out='".$sluice_data['进出类型']."' AND PersonID ='".$student['PersonID']."' AND time>='". strtotime($vleave['leave_time'])."' AND time<='".strtotime($vleave['back_time'])."'";
											$in_out_mun = $db->row($sql);
											//var_dump('请假过闸次数是：'.$in_out_mun['in_out_mun']);
											//var_dump('可过闸次数是：'.$pass_time_leave);
											if($in_out_mun['in_out_mun']<$pass_time_leave){	
												$leave_id = $vleave['id'];
												file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 请假放行,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
								
												$remarks = '请假放行';
											}
											if($in_out_mun['in_out_mun']>=$pass_time_leave){
												//参考5秒内的结果，如果有 运行通行,就放。
												$time5 = $Timestamp - 5;
												$pass_5s_sql = "select *  from crossing_student where pass = '允许通行' AND ask_for_leave='请假' AND in_out='{$sluice_data['进出类型']}' AND PersonID ='{$student['PersonID']}' AND time>='{$time5}'";										
												$pass_5s_query = $db->row($pass_5s_sql);
												if($pass_5s_query){
													$leave_id = $vleave['id'];
													file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 5秒内有请假放行,结果请假放行,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
								
													$remarks = '5秒内有请假放行,结果请假放行';
												}else{
													//过闸次数超过限定。
													file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 请假过闸超过限定次数,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);

													$send_data['msg']='请假过闸超过限定次数';	
													$remarks = '请假过闸超过限定次数';
													var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':请假过闸超过限定次数');											
												}
											}												
										}
										//var_dump($mun);
										
									
									}else{
										var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':请假不在时间内');
									}
								}																					
							};					
						}
						//请假放行
						if($leave_id){
							//请假放行
							$opendoor =1;
							$push_to_parents=1;
							$push_to_teacher=1;
							$tongxing_msg ='允许通行';
							$send_data['msg']='请假放行';							
							var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':请假放行');
						}
						//教师通道，不允许学生放行
						/*
						if($sluice_data['name'] =='通道1进' || $sluice_data['name'] =='通道1出'){
							$opendoor =0;
							$push_to_parents=0;
							$tongxing_msg ='禁止通行';
							$remarks = '教师通道禁止学生通行';
							$send_data['msg']='教师通道禁止学生通行';	
							var_dump('教师通道禁止学生通行');						
						}*/
						if($opendoor){
							//puturl("http://".$sluice_data['Address']."/LAPI/V1.0/PACS/Controller/RemoteOpened",'');
							$url="http://".$sluice_data['Address']."/LAPI/V1.0/PACS/Controller/GUIShowInfoEx";	
							$put_img = base64_encode(file_get_contents('./student/'.$student['头像']));
							$put_data = [
								"ResultCode"=> 1,
								"PassTime"=>date("YmdHis",$Timestamp),
								"ResultCmd"=> 2,
								"CodeStatus"=> 1,
								"ResultColor"=>1 , 
								"ResultMsg"=>$stutype[$student['学生类型']],
								"AudioEnable"=>0,
								"AudioIndex"=>8,
								"ShowInfoNum"=>1, 
								"ShowInfoList"=>[
									[
										"Key"=>"姓名:",
										"Value"=>$student['学生姓名'],
									]],
								"ImageEnable"=>0,
								//"ImageLen"=>strlen($put_img), 
								"ImageLen"=>"0", 
								"ImageData"=>"",
								//"ImageData"=>$put_img,
							];							
							$put_data=json_encode($put_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
							//file_put_contents('b.txt',$put_data); 
							puturl($url,$put_data);							
							
							file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 开门指令已下发,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
							var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':已放行');
						}else{
							file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']." 未放行,闸机".$sluice_data['name']."闸机时间".date('Y-m-d H:i:s',$Timestamp),FILE_APPEND);
							var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':未放行');
						}
						if($push_to_teacher){//过闸记录 推送  班主任
							$class_sql ="select * from classinfo where id = '{$student['班级ID']}' ";	
							$class = $db->row($class_sql);
							$class_desc = json_decode($class['descript'],1);
							
							$tea_id_list = explode(",",$class_desc['负责人ID']);
							if(!empty($tea_id_list)){
								foreach($tea_id_list as $kt =>$vt){
									$sql ="select * from teacher where id = '$vt' ";	
									$teacher = $db->row($sql);
									if($teacher['openid']){
										// 与远程task服务建立异步连接，ip为远程task服务的ip，如果是本机就是127.0.0.1，如果是集群就是lvs的ip
										$task_connection = new AsyncTcpConnection('Text://127.0.0.1:8089');
										//var_dump($task_connection);
										// 任务及参数数据
										$task_data = array(
											'openid' => $teacher['openid'],	
											'Timestamp' => $Timestamp,	
											'PersonID' => $student['PersonID'],										
											'parents_name' => "教师:".$teacher['name'],
											'grade_id' => $student['年级ID'],
											'stu_type' => $student['学生类型'],	
											'is_leave' => $leave_id,		
											'in_out' => $sluice_data['进出类型'],										
											'data'=>[
												//'first'=>['value'=>'考勤通知','color'=>'#173177'],//请假类型
												//'location'=>['value'=>$sluice_data['位置']],//.':'.$crossing_descript['闸机名称'].'闸机'
												'thing2'=>['value'=>$student['学生姓名']],//学员姓名
												'time3'=>['value'=>date("Y-m-d H:i:s",$Timestamp)],//请假时间
												'thing4'=>['value'=>$sluice_data['位置']],//学员姓名
												'const14'=>['value'=>$sluice_data['进出类型']=='进'?"进校":"出校"],//备注
											],										
										);
										// 发送数据
										$task_connection->send(json_encode($task_data));
										// 异步获得结果
										$task_connection->onMessage = function(AsyncTcpConnection $task_connection, $task_result)use($sluice_connection)
										{
											file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."推送给教师结果:".$task_result,FILE_APPEND);
											 // 结果
											 //var_dump($task_result);
											 // 获得结果后记得关闭异步连接
											 $task_connection->close();
											 // 通知对应的websocket客户端任务完成
											 //$sluice_connection->send('task complete');
										};
										// 执行异步连接
										$task_connection->connect();
									}else{
										file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$class_array[$student['班级ID']]."负责人: ".$teacher['name']." 没有openid",FILE_APPEND);
									}
								}
							}else{
								file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$class_array[$student['班级ID']]."没有负责人:",FILE_APPEND);
							}							
						}
						if($push_to_parents && $opendoor){//过闸记录 推送家长 
							$parents_sql ="select * from parents where stu_id like '%".$student['id']."%' ";
							$parents_list = $db->query($parents_sql);
							if(empty($parents_list)){
								$push_msg ='未绑定家长';
							}else{
								$push_msg="推送结果：";
								foreach($parents_list as $k=>$v){
									if($v['openid']){						
										// 与远程task服务建立异步连接，ip为远程task服务的ip，如果是本机就是127.0.0.1，如果是集群就是lvs的ip
										$task_connection = new AsyncTcpConnection('Text://127.0.0.1:8089');
										//var_dump($task_connection);
										// 任务及参数数据
										$task_data = array(
											'openid' => $v['openid'],	
											'Timestamp' => $Timestamp,	
											'PersonID' => $student['PersonID'],										
											'parents_name' => "家长:".$v['name'],
											'grade_id' => $student['年级ID'],
											'stu_type' => $student['学生类型'],	
											'is_leave' => $leave_id,		
											'in_out' => $sluice_data['进出类型'],										
											'data'=>[
												//'first'=>['value'=>'考勤通知','color'=>'#173177'],//请假类型
												//'location'=>['value'=>$sluice_data['位置']],//.':'.$crossing_descript['闸机名称'].'闸机'
												'thing2'=>['value'=>$student['学生姓名']],//学员姓名
												'time3'=>['value'=>date("Y-m-d H:i:s",$Timestamp)],//请假时间
												'thing4'=>['value'=>$sluice_data['位置']],//学员姓名
												'const14'=>['value'=>$sluice_data['进出类型']=='进'?"进校":"出校"],//备注
											],										
										);
										// 发送数据
										$task_connection->send(json_encode($task_data));
										// 异步获得结果
										$task_connection->onMessage = function(AsyncTcpConnection $task_connection, $task_result)use($sluice_connection)
										{
											file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."推送结果:".$task_result,FILE_APPEND);
											 // 结果
											 //var_dump($task_result);
											 // 获得结果后记得关闭异步连接
											 $task_connection->close();
											 // 通知对应的websocket客户端任务完成
											 //$sluice_connection->send('task complete');
										};
										// 执行异步连接
										$task_connection->connect();			
									}else{
										
										$push_msg .="家长({$v['name']})未授权openid";
										file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名'].$push_msg,FILE_APPEND);
				
									}				
								};							
							}
							
						}						
										
						//20240814 异常考勤推送
						if($sluice_data['进出类型']=='进'){						
							$be_late_push = 0;
							$be_late = 0;
							$be_late_sql = "select * from be_late  where json_value(descript,'$.\"位置\"')='{$location}'";//布放计划
							//var_dump($opentime_sql);
							$be_late_sql_result = $db->query($be_late_sql);
							foreach($be_late_sql_result as $k=>$v){
								$desc = json_decode($v['descript'],1);
								$stu_type_arr = explode($desc['学生类型']);
								$stu_guard_arr = explode($desc['年级ID']);
								if($desc['启用']=='启用' && $desc['星期'.$xingqi] && $desc['学生类型'] == $student['学生类型'] && $desc['人员类型'] == '学生' && $desc['年级ID'] == $student['年级ID']){
									foreach($desc['开始时间'.$xingqi] as $itemk=>$itemv){								
										$open_time=strtotime($itemv);
										$end_time=strtotime($desc['结束时间'.$xingqi][$itemk]);
										if(time()>=$open_time && time()<=$end_time ){
											$be_late_push=$desc['push'.$xingqi][$itemk]=='on'?1:0;
											$be_late_push_list = $desc['推送'];
											break;
										}
									}			
								}		
							};					
							if($be_late_push && $be_late_push_list && $sluice_data['进出类型']=='进' && !$leave_id){
								$be_late = 1;
								$tea_id_list = explode(",",$be_late_push_list);
								foreach($tea_id_list as $kt =>$vt){
									$sql ="select * from teacher where id = '$vt' ";	
									$teacher = $db->row($sql);
									if($teacher['openid']){
										// 与远程task服务建立异步连接，ip为远程task服务的ip，如果是本机就是127.0.0.1，如果是集群就是lvs的ip
										$task_connection = new AsyncTcpConnection('Text://127.0.0.1:8089');
										//var_dump($task_connection);
										// 任务及参数数据
										$task_data = array(
											'openid' => $teacher['openid'],	
											'Timestamp' => $Timestamp,	
											'PersonID' => $student['PersonID'],										
											'parents_name' => "教师:".$teacher['name'],
											'grade_id' => $student['年级ID'],
											'stu_type' => $student['学生类型'],	
											'is_leave' => $leave_id,		
											'in_out' => $sluice_data['进出类型'],
											'be_late' => 1,								
											'data'=>[
												//'first'=>['value'=>'考勤通知','color'=>'#173177'],//请假类型
												//'location'=>['value'=>$sluice_data['位置']],//.':'.$crossing_descript['闸机名称'].'闸机'
												'thing3'=>['value'=>$student['学生姓名']],//学员姓名
												'thing2'=>['value'=>$class_array[$student['班级ID']]],//学员姓名
												'time5'=>['value'=>date("Y-m-d H:i:s",$Timestamp)],//请假时间
												'const4'=>['value'=>"迟到"],//学员姓名
												
											],										
										);
										// 发送数据
										$task_connection->send(json_encode($task_data));
										// 异步获得结果
										$task_connection->onMessage = function(AsyncTcpConnection $task_connection, $task_result)use($sluice_connection)
										{
											file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."推送给教师结果:".$task_result,FILE_APPEND);
											 // 结果
											 //var_dump($task_result);
											 // 获得结果后记得关闭异步连接
											 $task_connection->close();
											 // 通知对应的websocket客户端任务完成
											 //$sluice_connection->send('task complete');
										};
										// 执行异步连接
										$task_connection->connect();
									}
								}
								
							}
						}
						
					}
					/* conditions end */
					//实时记录 放不放行 都存 
					//存照片 过闸人脸 存本地文件夹 crossing_student/日期
					$img_name = $student['PersonID'].'_'.date('YmdHis',$Timestamp).".jpg";
					if($Image_base64){
						$img_data= base64_decode($Image_base64);
						$fp2=@fopen("./face/crossing_student/".date('Y-m-d').'/'.$img_name,'a');
						fwrite($fp2,$img_data);
						fclose($fp2);						
					}
				
					$crossing_data=[
						'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
						'设备序列号'=>$sluice_data['code'],
						'进出'=>$sluice_data['进出类型'],
						'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息							
						'闸机名称'=>$sluice_data['name'],
						'核验'=>'成功',
						'通行'=>$tongxing_msg,
						'位置'=>$sluice_data['位置'],
						'学生姓名'=>$student['学生姓名'],
						'学生编号'=>$student['学生编号'],
						'学生类型'=>$student['学生类型'],
						'身份证'=>$student['身份证'],
						'班级ID'=>$student['班级ID'],
						'年级ID'=>$student['年级ID'],
						'照片'=>"./crossing_student/".date('Y-m-d').'/'.$img_name,
						'学生ID'=>$student['id'],
						'校区ID'=>$student['校区ID'],			
						'楼栋ID'=>$student['楼栋ID'],
						'楼层ID'=>$student['楼层ID'],
						'宿舍ID'=>$student['宿舍ID'],
						'房号'=>$student['房号'],
						'请假ID' =>$leave_id,
						'be_late' =>$be_late,
					];														
					$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
					$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
					$sql_insert = "insert into crossing_student (stu_type,code,name,descript,PersonID,time,in_out,pass,ask_for_leave,remarks,grade_id,class_id,cardno,push_msg) ";
					$sql_insert .= " values('{$student['学生类型']}','0','".$student['学生姓名']."','$descript_json','$MatchPersonID','$Timestamp','{$sluice_data['进出类型']}','{$tongxing_msg}','{$ask_msg}','{$remarks}','{$student['年级ID']}','{$student['班级ID']}','{$student['学生卡号']}','{$push_msg}')";	
					$result = $db->query($sql_insert);	
					
					if($sluice_data['进出类型']=='进'){
						$in_flag = '1';
					}else{
						$in_flag = '0';
						
					}					
					$redis_data = [
						"in_flag"=>$in_flag,
						"Time"=>date("Y-m-d H:i:s",$Timestamp),
						"stu_id"=>$student['id'],
						"report"=>'0',//按分钟上报的标志位
					];
	
					$redis_json = json_encode($redis_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
					$redis->set($MatchPersonID, $redis_json);    
		
					//$uuid = date("YmdHis").rand(1000,9999);
					$sql = "insert into crossing_student_temp (code,name,PersonID,in_out,time) values('{$sluice_data['code']}','{$student['学生姓名']}','{$MatchPersonID}','{$sluice_data['进出类型']}','{$Timestamp}')";
					$db->query($sql);	
					
					if($result){	
						//var_dump($sql_insert);
						file_put_contents('./log/log'.date('Ymd').'.txt',"\n".date('Y-m-d H:i:s')."(学生)".$student['学生姓名']."实时记录入库",FILE_APPEND);
						var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':实时记录入库:'.$sluice_data['位置'].":".$sluice_data['name'].':'.$sluice_data['进出类型'].':(学生)'.$student['学生姓名']);				
						$insert_success=1;
					}
				}else{ //!$student
					$img_data= base64_decode($Image_base64);
					$img_name = date('YmdHis',$Timestamp).".jpg";
					$fp2=@fopen("./face/crossing_student/".date('Y-m-d').'/'.$img_name,'a');
					fwrite($fp2,$img_data);
					fclose($fp2);					
					$crossing_data=[
						'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
						'设备序列号'=>$sluice_data['code'],
						'进出'=>$sluice_data['进出类型'],
						'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息			
						'闸机名称'=>$sluice_data['name'],
						'位置'=>$sluice_data['位置'],
						'核验'=>'成功',
						'通行'=>'禁止通行',
						'人员姓名'=>'学生信息错误',
						'人员编号'=>'',
						'人员类型'=>'',
						'人员班级'=>'',
						'人员年级'=>'',
						'照片'=>"./crossing_student/".date('Y-m-d').'/'.$img_name,
						'人员ID'=>'',
						'上报云端'=>'否',							
					];						
					//入库
					$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
					$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
					$sql_insert = "insert into crossing_student (stu_type,code,name,descript,PersonID,time,in_out,pass,ask_for_leave) values('','0','','{$descript_json}','$MatchPersonID','$Timestamp','{$sluice_data['进出类型']}','{$tongxing_msg}','{$ask_msg}')";	
					$result = $db->query($sql_insert);
					if($result){
						var_dump('实时错误信息入库成功。');
						$insert_success=1;
					}else{
						var_dump('实时通知入库失败2');
					}					
					$send_data=[
						'code'=>400,
						'msg'=>'学生信息错误',
						'data'=>[
							'time'=>date("Y-m-d H:i:s",$Timestamp),
							'device_name'=>$sluice_data['name'],
							'device_position'=>$sluice_data['位置'],
							'device_type'=>$sluice_data['进出类型'],
							'user_name'=>'',
							'user_type'=>'',								
							'user_card'=>'',													
							'user_class'=>'',
							'user_grade'=>'',
							'img'=>$Image_base64,
						],
					];
				}	
			}
		}elseif($MatchStatus==2){//核验失败2 也存信息 
			$remarks = '核验失败';
			$send_data=[
				'code'=>400,
				'msg'=>'核验失败',
				'data'=>[
					'time'=>date("Y-m-d H:i:s",$Timestamp),
					'device_name'=>$sluice_data['name'],
					'device_position'=>$sluice_data['位置'],
					'device_type'=>$sluice_data['进出类型'],
					'user_name'=>'',
					'user_type'=>'',
					'user_card'=>'',													
					'user_class'=>'',
					'user_grade'=>'',					
					'img'=>$Image_base64,
				],
			];	
			$img_data= base64_decode($Image_base64);
			$img_name = date('YmdHis',$Timestamp).".jpg";
			$fp2=@fopen("./face/crossing_student/".date('Y-m-d').'/'.$img_name,'a');
			fwrite($fp2,$img_data);
			fclose($fp2);		
			$crossing_data=[
				'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
				'设备序列号'=>$sluice_data['code'],
				'进出'=>$sluice_data['进出类型'],
				'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息			
				'闸机名称'=>$sluice_data['name'],
				'位置'=>$sluice_data['位置'],
				'核验'=>'失败',
				'通行'=>'禁止通行',
				'人员姓名'=>'',
				'人员编号'=>'',
				'人员类型'=>'',
				'人员班级'=>'',
				'人员年级'=>'',
				'照片'=>"./crossing_student/".date('Y-m-d').'/'.$img_name,
				'人员ID'=>'',
				'上报云端'=>'否',		
				'家长推送'=>'否',
				//'是否请假'=>'否',				
			];						
			//入库
			$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
			$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
			$sql_insert = "insert into crossing_student_failure (stu_type,code,name,descript,PersonID,time,in_out,pass,ask_for_leave,remarks) values('','0','','$descript_json','$MatchPersonID','$Timestamp','{$sluice_data['进出类型']}','{$tongxing_msg}','$ask_msg','$remarks')";	
			$result = $db->query($sql_insert);	
			if($result){
				var_dump('实时核验失败入库成功');
				$insert_success=1;
			}else{
				var_dump('实时通知入库失败1');
			}
			
		}
		if($insert_success){// 入库成功 即 给闸机发回包
			if($opendoor != 1){
			
				$url="http://".$sluice_data['Address']."/LAPI/V1.0/PACS/Controller/GUIShowInfoEx";	
				//$put_img = base64_encode(file_get_contents('./teacher/'.$teacher['头像']));
				$put_data = [
					"ResultCode"=> 0,
					"PassTime"=>date("YmdHis",$Timestamp),
					"ResultCmd"=> 2,
					"CodeStatus"=> 1,
					"ResultColor"=>2 , 
					"ResultMsg"=>$stutype[$student['学生类型']]." 不在放行时间段内",
					"AudioEnable"=>0,
					"AudioIndex"=>8,
					"ShowInfoNum"=>3, 
					"ShowInfoList"=>[
						[
							"Key"=>"类别:",
							"Value"=>$stutype[$student['学生类型']]?$stutype[$student['学生类型']]:'无',
						],
						[
							"Key"=>"姓名:",
							"Value"=>$student['学生姓名']?$student['学生姓名']:'无',
						],
						[
							"Key"=>"班别:",
							"Value"=>$class_array[$student['班级ID']]?$class_array[$student['班级ID']]:'无',
						]
					],
					"ImageEnable"=>0,
					//"ImageLen"=>strlen($put_img), 
					"ImageLen"=>"0", 
					"ImageData"=>"",
					//"ImageData"=>$put_img,
				];
				$put_data=json_encode($put_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
				puturl($url,$put_data);				
			}
			/*

			*/
			var_dump(date("Y-m-d H:i:s").'  '.$student['学生姓名'].':实时通知入库回包:'.$sluice_data['位置'].$sluice_data['name'].':'.$MatchPersonID.':'.$accept_data["Seq"]);
			$time = date("Y-m-d H:i:s",time());	
			$response_data = [
				"Response"=>[
					"ResponseURL"=>"/LAPI/V1.0/PACS/Controller/Event/Notifications",
					"StatusCode"=>0,
					"StatusString"=>"Succeed",
					"Data"=>[
						"RecordID"=>$accept_data["Seq"],
						"Time"=>"$time",
					],
				]
			];
			
			$response_data=json_encode($response_data,JSON_UNESCAPED_SLASHES); 
			//file_put_contents('a1.txt',$response_data);
			/*
			$response = new Response(200, [
				'Content-Type' => 'application/json',
				'Connection' => 'close',
				'X-Frame-Options' => 'SAMEORIGIN',
			], '{"Response":{"ResponseURL":"/LAPI/V1.0/PACS/Controller/Event/Notifications","StatusCode":0,"StatusString":"Succeed","Data":{"RecordID":'.$accept_data["Seq"].',"Time":"'.$time.'"}}}');	
			*/
			$response = new Response(200, [
				'Content-Type' => 'application/json',
				'Connection' => 'close',
				'X-Frame-Options' => 'SAMEORIGIN',
			],$response_data);
			//file_put_contents('a.txt',$response,FILE_APPEND);
			$sluice_connection->send($response);			
		}
		//var_dump($send_data);
		//最后 给web端 推送消息
		$send_data=json_encode($send_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
		foreach($web_worker->connections as $connection)
		{
			$connection->send($send_data);
		}
/***********************************************************实时数据处理完毕************************************************/		
		//时间段验证
		//找过闸记录
		//决定是否开门
		//存储过闸记录
		//推送过闸记录到家长端				
/***********************************************************历史数据处理开始************************************************/			
	}elseif($accept_data['NotificationType']==1){// NotificationType =1 历史记录 。回包 	
		
		if($MatchStatus==1)
		{
			//var_dump($accept_data['FaceInfoList'][0]['Timestamp']);
			if($MatchPersonID>=1000000)//历史记录教师
			{
				//检查是否入库 
				$has_teacher = "select * from crossing_teacher where PersonID ='$MatchPersonID' and time ='$Timestamp'";
				$has_crossing = $db->row($has_teacher);
				if(empty($has_crossing)){
					$teacher = $teacher_list[$MatchPersonID];			
					if($teacher){//补存 存记录 			
						//过闸人脸 存本地文件夹 crossing_student/日期
						$img_data= base64_decode($Image_base64);
						$img_name = $teacher['PersonID'].'_'.date('YmdHis ',$Timestamp).".jpg";
						$fp2=@fopen("./face/crossing_teacher/".date('Y-m-d').'/'.$img_name,'a');
						fwrite($fp2,$img_data);
						fclose($fp2);		
						$crossing_data=[
							'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
							'设备上报时间'=>$Timestamp,
							'设备序列号'=>$sluice_data['code'],
							'进出'=>$sluice_data['进出类型'],
							'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息
							'闸机名称'=>$sluice_data['name'],
							'核验'=>'成功',
							'通行'=>'历史记录',
							'位置'=>$sluice_data['位置'],
							'教师姓名'=>$teacher['教师姓名'],
							'教师编号'=>$teacher['教师编号'],
							'身份证'=>$teacher['身份证'],
							'职务'=>$teacher['职务'],
							'班级ID'=>@$teacher['班级ID'],//无此项
							'年级ID'=>@$teacher['年级ID'],//无此项
							'照片'=>"./crossing_teacher/".date('Y-m-d').'/'.$img_name,
							'教师ID'=>$teacher['id'],
							'校区ID'=>$teacher['校区ID'],			
							'楼栋ID'=>@$teacher['楼栋ID'],//无此项
							'楼层ID'=>@$teacher['楼层ID'],//无此项
							'宿舍ID'=>@$teacher['宿舍ID'],//无此项
							'房号'=>$teacher['房号'],//无此项
							'上报云端'=>'否',							
							'历史数据'=>'是',							
						];						
						//入库
				
						$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
						$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
						$sql_insert = "insert into crossing_teacher (code,name,descript,PersonID,time,in_out) values('0','".$teacher['教师姓名']."','$descript_json','$MatchPersonID','$Timestamp','".$sluice_data['进出类型']."')";	
						$result = $db->query($sql_insert);
						if($result){
							var_dump($teacher['教师姓名'].':'.$MatchPersonID.'histli inset');
						}
						//给web端
						$send_data=[
							'code'=>200,
							'msg'=>'开门成功',
							'data'=>[
								'time'=>date("Y-m-d H:i:s",$Timestamp),
								'device_name'=>$sluice_data['name'],
								'device_position'=>$sluice_data['位置'],
								'device_type'=>$sluice_data['进出类型'],
								'user_name'=>$teacher['教师姓名'],
								'user_card'=>$teacher['教师编号'],								
				
								
								'user_type'=>$teacher['personnel_type'],
								'user_grade'=>$bumen[$teacher['部门']]?$bumen[$teacher['部门']]:'无',			
								'user_class'=>$teacher['职务'],
								'img'=>$Image_base64,
								'idcard'=>'../teacher/'.$teacher['头像'],
								
							],
						];
					}else{
						$send_data=[
							'code'=>400,
							'msg'=>'教职工信息错误',
							'data'=>[
								'time'=>date("Y-m-d H:i:s",$Timestamp),
								'device_name'=>$sluice_data['name'],
								'device_position'=>$sluice_data['位置'],
								'device_type'=>$sluice_data['进出类型'],
								'user_name'=>'',
								'user_type'=>'',								
								'user_card'=>'',													
								'user_class'=>'',
								'user_grade'=>'',
								'img'=>$Image_base64,
							],
						];
					}					
				}
			}else{//历史记录学生
				$has_student = "select * from crossing_student where PersonID ='$MatchPersonID' and time ='$Timestamp'";
				$has_crossing = $db->row($has_student);
				if(empty($has_crossing)){
					$student = $student_list[$MatchPersonID];	
					if($student){
						//按 星期 年级 类型 匹配 过闸时间段
						$can_pass=1;
						//*******
						if($can_pass){//放行，存储过闸记录，并推送公功能公众号消息 //上报云端 待定
							//过闸人脸 存本地文件夹 crossing_student/日期
							$img_data= base64_decode($Image_base64);
							$img_name = $student['PersonID'].'_'.date('YmdHis',$Timestamp).".jpg";
							$fp2=@fopen("./face/crossing_student/".date('Y-m-d').'/'.$img_name,'a');
							fwrite($fp2,$img_data);
							fclose($fp2);						
							$crossing_data=[
								'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
								'设备序列号'=>$sluice_data['code'],
								'进出'=>$sluice_data['进出类型'],
								'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息								
								'闸机名称'=>$sluice_data['name'],
								'核验'=>'成功',
								'通行'=>'历史记录',
								'位置'=>$sluice_data['位置'],
								'学生姓名'=>$student['学生姓名'],
								'学生编号'=>$student['学生编号'],
								'学生类型'=>$student['学生类型'],
								'身份证'=>$student['身份证'],
								'班级ID'=>$student['班级ID'],
								'年级ID'=>$student['年级ID'],
								'照片'=>"./crossing_student/".date('Y-m-d').'/'.$img_name,
								'学生ID'=>$student['id'],
								'校区ID'=>$student['校区ID'],			
								'楼栋ID'=>$student['楼栋ID'],
								'楼层ID'=>$student['楼层ID'],
								'宿舍ID'=>$student['宿舍ID'],
								'房号'=>$student['房号'],
								'上报云端'=>'否',
								'家长推送'=>'否',
								//'是否请假'=>'否',	
								'历史数据'=>'是',										
							];						
							//入库

							$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
							$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
							$sql_insert = "insert into crossing_student (pass,stu_type,code,name,descript,PersonID,time,in_out,ask_for_leave,grade_id,class_id,cardno) "; 
							$sql_insert .=" values('历史记录','{$student['学生类型']}','0','".$student['学生姓名']."','$descript_json','$MatchPersonID','$Timestamp','".$sluice_data['进出类型']."','$ask_msg','{$student['年级ID']}','{$student['班级ID']}','{$student['学生卡号']}')";	
							//var_dump($db->query($sql_insert));
							$result = $db->query($sql_insert);
							
							if($result){
								$insert_success=1;
								var_dump('历史记录入库:'.$sluice_data['位置'].$sluice_data['name'].':'.$sluice_data['进出类型'].':'.$accept_data["Seq"]);
							}else{
								var_dump('历史记录入库失败2学信息对');
								var_dump($sql_insert);
							}		
							//给web端
							$send_data=[
								'code'=>200,
								'msg'=>'开门成功',
								'data'=>[
									'time'=>date("Y-m-d H:i:s",$Timestamp),
									'device_name'=>$sluice_data['name'],
									'device_position'=>$sluice_data['位置'],
									'device_type'=>$sluice_data['进出类型'],
									'user_name'=>$student['学生姓名'],
									'user_card'=>$student['学生编号'],								
									'user_type'=>$stutype[$student['学生类型']],
									'user_class'=>$class_array[$student['班级ID']],
									'user_grade'=>$grade_array[$student['年级ID']],
									'img'=>$Image_base64,
									'idcard'=>'../student/'.$student['头像'],
								
								],
							];
			
								
						}else{
						}
					}else{//学生信息错误
						$img_data= base64_decode($Image_base64);
						$img_name = date('YmdHis',$Timestamp).".jpg";
						$fp2=@fopen("./face/crossing_student/".date('Y-m-d').'/'.$img_name,'a');
						fwrite($fp2,$img_data);
						fclose($fp2);						
						$crossing_data=[
							'过闸时间'=>date("Y-m-d H:i:s",$Timestamp),
							'设备序列号'=>$sluice_data['code'],
							'进出'=>$sluice_data['进出类型'],
							'过闸方式'=>$CapSrc,//1：人脸识别终端采集的人脸信息;2：读卡器采集的门禁卡信息;3：读卡器采集的身份证信息;4：闸机采集的闸机信息				
							'闸机名称'=>$sluice_data['name'],
							'位置'=>$sluice_data['位置'],
							'核验'=>'成功',
							'通行'=>'历史记录',
							'人员姓名'=>'学生信息错误',
							'人员编号'=>'',
							'人员类型'=>'',
							'人员班级'=>'',
							'人员年级'=>'',
							'照片'=>"./crossing_student/".date('Y-m-d').'/'.$img_name,
							'人员ID'=>'',
							'上报云端'=>'否',							
						];						
						//入库
					
						$descript_json=json_encode($crossing_data,JSON_UNESCAPED_UNICODE); 	
						$descript_json = $sm4->encrypt($Timestamp,$descript_json);	
						$sql_insert = "insert into crossing_student_failure (pass,stu_type,code,name,descript,PersonID,time,in_out,ask_for_leave) values('历史记录','','0','','$descript_json','$MatchPersonID','$Timestamp','".$sluice_data['进出类型']."','$ask_msg')";	
						$result	= $db->query($sql_insert);	
						if($result){
							$insert_success=1;
							var_dump('历史记录入库:'.$sluice_data['位置'].$sluice_data['name'].':'.$sluice_data['进出类型'].':'.$accept_data["Seq"]);
						}else{
							var_dump('历史记录入库失败1');
							var_dump($sql_insert);
						}
						$send_data=[
							'code'=>400,
							'msg'=>'学生信息错误',
							'data'=>[
								'time'=>date("Y-m-d H:i:s",$Timestamp),
								'device_name'=>$sluice_data['name'],
								'device_position'=>$sluice_data['位置'],
								'device_type'=>$sluice_data['进出类型'],
								'user_name'=>'',
								'user_type'=>'',								
								'user_card'=>'',													
								'user_class'=>'',
								'user_grade'=>'',
								'img'=>$Image_base64,
							],
						];

					}					
				}			
			}
			if($insert_success){
				//var_dump('历史数据入库:'.$sluice_data['name'].':'.$accept_data["Seq"]);
			}
			var_dump('历史记录回包:'.$sluice_data['name'].':'.$accept_data["Seq"]);
			
	
		}else{//未识别的历史记录
			var_dump('未识别的历史记录');
		}
		$send_data=json_encode($send_data,JSON_UNESCAPED_UNICODE+JSON_UNESCAPED_SLASHES);
		foreach($web_worker->connections as $connection)
		{
			$connection->send($send_data);
		}

		//给闸机发回包
		$time = date("Y-m-d H:i:s",time());	
		$response = new Response(200, [
			'Content-Type' => 'application/json',
		], '{
			"Response": {
			"ResponseURL":"/LAPI/V1.0/PACS/Controller/Event/Notifications",  
			"StatusCode": 0,
			"StatusString": "Succeed", 
			"Data": {
				"RecordID":'.$accept_data["Seq"].',
				"Time":"'.$time.'"
			}
			}	
		}');	
		$sluice_connection->send($response);
/*****************************************************历史记录处理完毕*********************************************/
	}else{//其他
		var_dump('other');
		//$sluice_connection->send($request>get());
	}
	

};
function web_on_message(TcpConnection $connection, $data)
{
	//默认不接受web端的信息

}

// 运行worker
Worker::runAll('hello');