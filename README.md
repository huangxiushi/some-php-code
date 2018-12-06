/* 
* 下月同一天。
* 下下月同一天。
*/
function nextmonth（）{
$nextmonth = date('m')+1;
$next_month = date('m')+2;

$yes =date('Y');
$next_yes =date('Y');
if($nextmonth==13){
	$nextmonth=1;
	$yes =date('Y')+1;
}
if($next_month==13){
	$next_month=1;
	$next_yes = date('Y')+1;
}elseif($next_month==14){
	$next_month=2;
	$next_yes = date('Y')+1;
}
$d=cal_days_in_month(CAL_GREGORIAN,$nextmonth,date('Y'));
$d_d=cal_days_in_month(CAL_GREGORIAN,$next_month,date('Y'));
		if(date('d')>$d){ 
			var_dump('下个月今天是??'.date('Y-m-'.$d, strtotime($yes.'-'.$nextmonth)));
		}else{
			var_dump('下个月今天是??'.date('Y-m-'.date('d'), strtotime($yes.'-'.$nextmonth)));
		}
		if(date('d')>$d_d){
			var_dump('下下个月今天是??'.date('Y-m-'.$d_d, strtotime($next_yes.'-'.$next_month)));
		}else{
			var_dump('下下个月今天是??'.date('Y-m-'.date('d'), strtotime($next_yes.'-'.$next_month)));
		}
 }
