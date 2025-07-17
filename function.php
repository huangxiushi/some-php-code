<?php
//判断文件夹是否存在,没有则新建。
if(!(is_dir('../face/crossing_student/'.date('Y-m-d')))){
	mkdir("../face/crossing_student/".date('Y-m-d'),777);
}
if(!(is_dir('../face/crossing_teacher/'.date('Y-m-d')))){
	mkdir("../face/crossing_teacher/".date('Y-m-d'),777);
}
if(!(is_dir('../face/crossing_student/'.date('Y-m-d',strtotime('+1 day'))))){
	mkdir("../face/crossing_student/".date('Y-m-d',strtotime('+1 day')),777);
}

if(!(is_dir('../face/crossing_teacher/'.date('Y-m-d',strtotime('+1 day'))))){
	mkdir("../face/crossing_teacher/".date('Y-m-d',strtotime('+1 day')),777);
}
function get_local_ip(){
	if(PHP_OS=='WINNT'){
		$preg = "/\A((([0-9]?[0-9])|(1[0-9]{2})|(2[0-4][0-9])|(25[0-5]))\.){3}(([0-9]?[0-9])|(1[0-9]{2})|(2[0-4][0-9])|(25[0-5]))\Z/";
		exec("ipconfig", $out, $stats);
		if (!empty($out)) {
			foreach ($out AS $row) {
				if (strstr($row, "IP") && strstr($row, ":") && !strstr($row, "IPv6")) {
					$tmpIp = explode(":", $row);
					if (preg_match($preg, trim($tmpIp[1]))) {
						return ( trim($tmpIp[1]));
					}
				}
			}
		}		
	}else{
		exec("ifconfig", $out, $stats);
		if (!empty($out)) {
			if (isset($out[1]) && strstr($out[1], 'addr:')) {
				$tmpArray = explode(":", $out[1]);
				$tmpIp = explode(" ", $tmpArray[1]);
				if (preg_match($preg, trim($tmpIp[0]))) {
					return trim($tmpIp[0]);
				}
			}
		}		
	}
} 
//file_put_contents('a.txt',$data,FILE_APPEND);
function curlGet($url = '', $options = array(),$time_out='10')
{
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $time_out);
	if (!empty($options)) {
		curl_setopt_array($ch, $options);
	}
	//https请求 不验证证书和host
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	$data = curl_exec($ch);
	curl_close($ch);
	return $data;
}
//CURLOPT_TIMEOUT	设置cURL允许执行的最长秒数。	
//CURLOPT_TIMEOUT_MS	设置cURL允许执行的最长毫秒数。
//CURLOPT_CONNECTTIMEOUT	在发起连接前等待的时间，如果设置为0，则无限等待。
function puturl($url,$data){
    //$data = json_encode($data);
    $ch = curl_init(); //初始化CURL句柄 
    curl_setopt($ch, CURLOPT_URL, $url); //设置请求的URL
    //curl_setopt ($ch, CURLOPT_HTTPHEADER, array('Content-type:application/json'));
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($data)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); //设为TRUE把curl_exec()结果转化为字串，而不是直接输出 
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,"PUT"); //设置请求方式
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);//设置提交的字符串
	curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    $output = curl_exec($ch);
    curl_close($ch);
    return json_decode($output,true);
}
function curl_post( $url, $post = '', $timeout = 10 ){ 
	if( empty( $url ) ){
		return ;
	}
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);//在发起连接前等待的时间，如果设置为0，则无限等待。
	if( $post != '' && !empty( $post ) ){
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($post)));
	}
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$result = curl_exec($ch);
	if($result === false)
	{
		curl_close($ch);
		return 'Curl error: ' . curl_error($ch);
	}else{
		curl_close($ch);
		return $result;
	}
}
/*
*功能：php完美实现下载远程图片保存到本地
*参数：文件url,保存文件目录,保存文件名称，使用的下载方式
*当保存文件名称为空时则使用远程文件原来的名称
*/
function getImage($url,$save_dir='',$filename='',$type=0){
    if(trim($url)==''){
        return array('file_name'=>'','save_path'=>'','error'=>1);
    }
    if(trim($save_dir)==''){
        $save_dir='./';
    }
    if(trim($filename)==''){//保存文件名
        $ext=strrchr($url,'.');
        if($ext!='.gif'&&$ext!='.jpg'){
            return array('file_name'=>'','save_path'=>'','error'=>3);
        }
        $filename=time().$ext;
    }
    if(0!==strrpos($save_dir,'/')){
        $save_dir.='/';
    }
    //创建保存目录
    if(!file_exists($save_dir)&&!mkdir($save_dir,0777,true)){
        return array('file_name'=>'','save_path'=>'','error'=>5);
    }
    //获取远程文件所采用的方法
    if($type){
        $ch=curl_init();
        $timeout=10;
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
        $img=curl_exec($ch);
        curl_close($ch);
    }else{
        ob_start();
        readfile($url);
        $img=ob_get_contents();
        ob_end_clean();
    }
    //$size=strlen($img);
    //文件大小
    $fp2=@fopen($save_dir.$filename,'a');
    fwrite($fp2,$img);
    fclose($fp2);
    unset($img,$url);
    return array('file_name'=>$filename,'save_path'=>$save_dir.$filename,'error'=>0);
}

function curl_del($url,$data){
    $ch = curl_init();
    curl_setopt ($ch, CURLOPT_URL,$url);
    curl_setopt ($ch, CURLOPT_HTTPHEADER, array('Content-type:application/json'));
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, "DELETE");   
	curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, '10');
    curl_setopt ($ch, CURLOPT_POSTFIELDS,$data);
    $output = curl_exec($ch);
    curl_close($ch);
    //$output = json_decode($output,true);
	return $output;
}
//获取parameter 表 按名称
function get_parameter_by_name($db,$name=''){
    if(!$name){
        return [];
    }
    $sql="select * from parameter where name='$name' ";

      //return  $sql;
    $result = $db -> getOne($sql);
    
    $row=$db -> getOne($sql);
    $bumen_array= json_decode($row['descript'],1);
    unset($bumen_array['名称']);
    return $bumen_array;
}

?>