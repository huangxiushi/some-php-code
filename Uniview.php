<?php	
	/*
	$username = 'admin';
	$password = 'qwe@123456';
	$uniview = new Uniview($username,$password);

	$url =  "http://192.168.1.105/LAPI/V1.0/PeopleLibraries/BasicInfo"; 
	$return = $uniview->get($url);
	var_dump($return);
	*/
	//NVR
	/*
	$username = 'admin';
	$password = 'qwe_123456';
	$uniview = new Uniview($username,$password);
	$url =  "http://192.168.1.100/LAPI/V1.0/PeopleLibraries/BasicInfo"; 
	$return = $uniview->sendRequest($url,"GET");
	var_dump($return);
	*/

	//基本信息及配置
	/*
	$urlNVR =  "http://192.168.1.100/LAPI/V1.0/System/DeviceInfo"; 
	$urlIPC =  "http://192.168.1.105/LAPI/V1.0/System/DeviceInfo";
	$username = 'admin';
	$password = 'qwe@123456';
	$uniview = new Uniview($username,$password);
	$return = $uniview->sendRequest($urlIPC,"GET");
	var_dump($return);
	*/
/**
 * This file is Uniview interface.
 * @author    hxs
 * @copyright 
 * @link      
 * @license   
 */
class Uniview{
	
	public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }		
    public function sendRequest($url,$method,$requestBody=[]){
		if($method == 'GET'){
			$authConnection = $this->curlGet($url,$requestBody);
		}
		if($method == 'POST'){
			$authConnection = $this->curlPost($url,'',$requestBody);
		}
		if($method == 'PUT'){
			$authConnection = $this->curlPut($url,'',$requestBody);
		}
		if($method == 'DELETE'){
			$authConnection = $this->curlDel($url,$requestBody);
		}

		//var_dump($authConnection);
		$rawHead = $this->rawHead($authConnection);
		$rawBody = $this->rawBody($authConnection);
		//var_dump($rawBody);
		$Body = json_decode($rawBody,1);
		if(empty($Body)){
			//var_dump($Body);
			$rawHead = $this->rawHead($rawBody);
			$rawBody = $this->rawBody($rawBody);
			$Body = json_decode($rawBody,1);
		}
		//var_dump($Body);;die;
		if ($Body['Response']['StatusCode'] == '401') {
			$headerParams = $this->extractChallengeInfo($rawHead);
			//var_dump($headerParams);die;
			$authHeader = $this->createDigestAuthHeader($this->username,$this->password, $method, $url, $headerParams);
			//var_dump($authHeader);
			if($method == 'GET'){
				$secondConnection = $this->curlGet($url,$authHeader);
			}
			if($method == 'POST'){
				$secondConnection = $this->curlPost($url,$authHeader,$requestBody);
			}
			if($method == 'PUT'){
				$secondConnection = $this->curlPut($url,$authHeader,$requestBody);
			}
			if($method == 'DELETE'){
				$secondConnection = $this->curlDel($url,$authHeader);
			}		
			return $secondConnection;
		}
    }	
	// 提取挑战信息
    public function extractChallengeInfo($headerFields) {
		//var_dump($headerFields);
		$end_line_position = \strpos($headerFields, "\n");
        if ($end_line_position === false) {
            return;
        }
        $head_buffer = \substr($headerFields, $end_line_position + 2);
		//var_dump($head_buffer);
		$head_data = \explode("\n", $head_buffer);
		//var_dump($head_data);
        foreach ($head_data as $content) {
            if (false !== \strpos($content, ':')) {
                list($key, $value) = \explode(':', $content, 2);
                $key = \strtolower($key);
                $value = \trim($value);
            } else {
                $key = \strtolower($content);
                $value = '';
            }
			$head[$key] = $value;
        }
		//var_dump($head);
        $authHeaders = $head["www-authenticate"];
		//var_dump($authHeaders);
		if ($authHeaders != null && !empty($authHeaders)) {
			$array =  \explode('",', $authHeaders);
			//var_dump($array);
			foreach ($array as $content) {
				if (false !== \strpos($content, '="')) {
					list($key, $value) = \explode('="', $content, 2);			
					$key = str_replace("Digest",'',$key);
					$key = \trim($key);
					$value = \trim($value);
				} else {
					$key = \trim($content);
					$value = '';
				}
				$authenticate[$key] = $value;
			}
			return $authenticate;
		}
		return null;
    }

	// 创建摘要认证头部信息
    public function createDigestAuthHeader($username, $password, $httpMethod, $uri,$headerParams) {
        $nonce = $headerParams["nonce"];
		$realm = $headerParams["realm"];
		$qop = $headerParams["qop"];		
        $algorithm = $headerParams["algorithm"];
        $nonceCount = "00000001";		
        $cnonce = "0wQGXJQP";
        $HA1 = md5($username . ":" . $realm . ":" . $password);
        $HA2 = md5($httpMethod . ":" . $uri);
        $response = md5($HA1 . ":" . $nonce . ":" . $nonceCount . ":" . $cnonce . ":" . $qop . ":" . $HA2);
        return "Digest username=\"" . $username . "\", realm=\"" . $realm . "\", nonce=\"" . $nonce . "\", uri=\"" . $uri
                . "\", algorithm=\"" . $algorithm . "\", qop=" . $qop . ", response=\"" . $response . "\", nc="
                . $nonceCount . ", cnonce=\"0wQGXJQP\"";
    }
	public function curlDel($url,$authHeader='',$data=[]){
		$ch = curl_init();
		if($authHeader){
			$headers = [
				'Authorization:'.$authHeader,
				'Content-Type: application/json',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			
		}
		curl_setopt ($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_HEADER, true); // 开启头部信息获取
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, "DELETE");   
		curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, '10');
		curl_setopt ($ch, CURLOPT_POSTFIELDS,$data);
		$output = curl_exec($ch);
		curl_close($ch);
		//$output = json_decode($output,true);
		return $output;
	}
	public function curlGet($url = '',$authHeader='', $options = array(),$time_out='10')
	{
		$ch = curl_init($url);
		if($authHeader){
			$headers = [
				'Authorization:'.$authHeader,
				'Content-Type: application/json',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	 
		// 发送 HTTP HEAD 请求并获取头部信息
		curl_setopt($ch, CURLOPT_HEADER, true); // 开启头部信息获取
		
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
	public function curlPut($url,$authHeader='',$data='',$timeout = 10 ){
		//$data = json_encode($data);
		$ch = curl_init(); //初始化CURL句柄 
		curl_setopt($ch, CURLOPT_URL, $url); //设置请求的URL
		$headers = [
			'Content-Type: application/json',
		];	
		if($authHeader){
			array_push($headers,'Authorization: '.$authHeader);
		}	
		/*		
		if( $data != '' && !empty( $data ) ){
			array_push($headers,'Content-Length: ' . strlen($post));
			//curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization:'.$authHeader, 'Content-Length: ' . strlen($post)));
		}
		*/
		curl_setopt($ch, CURLOPT_HEADER, true); // 开启头部信息获取
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); //设为TRUE把curl_exec()结果转化为字串，而不是直接输出 
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST,"PUT"); //设置请求方式
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);//设置提交的字符串
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		$output = curl_exec($ch);
		curl_close($ch);
		
		return $output;
	}
	public function curlPost( $url,$authHeader='', $post = '', $timeout = 10 ){ 
		if( empty( $url ) ){
			return ;
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true); // 开启头部信息获取
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);//在发起连接前等待的时间，如果设置为0，则无限等待。
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		$headers = [
			'Content-Type: application/json',
		];	
		
		if($authHeader){
			array_push($headers,'Authorization: '.$authHeader);
		}		
		if( $post != '' && !empty( $post ) ){
			array_push($headers,'Content-Length: ' . strlen($post));
			//curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization:'.$authHeader, 'Content-Length: ' . strlen($post)));
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		
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
	public function rawHead($data)
	{

		return \substr($data,0, \strpos($data, "\r\n\r\n"));
	}

	public function rawBody($data)
	{
		//var_dump(strpos($data, "\r\n\r\n")+4);
		//var_dump(substr($data,25));
		//return \substr($data, \strpos($data, "\r\n\r\n") + 4);
		return \substr($data, \strpos($data, "\r\n\r\n") + 4);
	}
	public function get_local_ip(){
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
}
?>