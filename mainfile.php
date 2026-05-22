<?php
/*************************************
 * Company:
 * Program: mainfile.php
 * Author:  Ken Tsai
 * Date:    from 2005.03.24
 * Version: 2.0
 * Description: 站台共用函式庫
 *************************************/

//if (!headers_sent()) session_start();

include_once("config.php");  //讀取初始設定資訊
include_once("lib/performanceTimeMonitor.php"); //生活機能(含Google map 產圖及步行距、時間) 轉檔 by Johnny 2017.12.27
include_once("lib/cronlog.php"); // 排程log + function by Ann + 瑋廷 2018.02.12
include_once("lib/logcsv.inc.php"); //log寫入csv by Ann 2018.05.10
include_once("lib/checkprocess.inc.php");  //檢查排程是否執行中 by Ann 2019.09.11

//使用ADODB連線
include_once("adodb5/adodb.inc.php");
include_once("adodb5/tohtml.inc.php");

/**
 * 建立log的連線
 */
//改用新的連線方式，舊的先暫時不刪
/*
$GLOBALS['adoconnlog'] = &ADONewConnection(ADODATABASE_LOG);
for($i=rand(0,count($ADOHOST_LOG)-1),$j=1;$j<count($ADOHOST_LOG);$i--,$j++){
  if($GLOBALS['adoconnlog']->PConnect($ADOHOST_LOG[$i], ADOUNAME_LOG, ADOPASS_LOG, ADODBNAME_LOG))
     break;
  elseif($i<=0)
     $i=count($ADOHOST_LOG)-1;
}
*/

for($i=rand(0,count($ADOHOST_LOG)-1),$j=1;$j<count($ADOHOST_LOG);$i--,$j++)
  if($GLOBALS['adoconnlog'] = adoNewConnection(ADODATABASE_LOG."://".ADOUNAME_LOG.":".ADOPASS_LOG."@".$ADOHOST_LOG[$i]."/".ADODBNAME_LOG."?persist"))
    break;
  elseif($i<=0)
    $i=count($ADOHOST_LOG)-1;
/*
 * 排程固定開始程式
*/
//設定GLOBAL變數：程式名稱
$GLOBALS["proessName"] = basename($_SERVER['PHP_SELF'], '.php'); //檢查排程執行用
//若排程沒有加參數 type 則用排程名稱當做 logcsv 的檔名
//判斷排程是否有加參數type，若有 logcsv 的檔名要在後方加上 _type(參數)
$type_flag = false; //預設
$logvalue = '';
foreach($argv as $key => $value){
  $logvalue .= $argv[$key+1];
  if(strpos($value, "type")){
    $type_flag = true;
    $GLOBALS['logname'] = $GLOBALS["proessName"] ."_type" . $argv[$key+1];
  }
}
if(!$type_flag){
  $GLOBALS['logname'] = $GLOBALS["proessName"];
}

//log紀錄開始
//1.log_record
//取得log_foramt ID
$GLOBALS['format_id'] = logFormatId($GLOBALS["proessName"]);
$GLOBALS['log_id'] = logRecordInsert($GLOBALS['format_id'],$logvalue,"Start");
//2.logcsv 排程 程式執行開始
logcsv("www/cron/log",$GLOBALS["logname"],"Start",1);

//檢查是否有執行中排程
// 20200407 check_process_isrunning 加排程參數進行判斷 by Alan Pan
$chkProcess =  check_process_isrunning(implode(" ",$argv));
if($chkProcess){
  //仍有排程執行中，程式跳出
  //1.log_record
  $check_process_log['message'] = "Process $basename is running";
  logRecordUpdate($GLOBALS['log_id'],$check_process_log); 
  //2.logcsv
  logcsv("www/cron/log",$GLOBALS["logname"],json_encode($check_process_log,JSON_UNESCAPED_UNICODE),0);
  die();
}
   //  各排程(cron)資料庫連結設定檔
if(file_exists('config/dbconn/'.$GLOBALS["proessName"].'.inc.php'))
  include_once('config/dbconn/'.$GLOBALS["proessName"].'.inc.php');
//*******************************

//建立sinyiweb連線
//改用新的連線方式，舊的先暫時不刪
/*
$GLOBALS['adoconnweb_m'] = &ADONewConnection(ADODATABASE_WEB);
$GLOBALS['adoconnweb_m']->PConnect($ADOHOST_WEB_MASTER, ADOUNAME_WEB, ADOPASS_WEB, ADODBNAME_WEB);

$GLOBALS['adoconnweb'] = &ADONewConnection(ADODATABASE_WEB);
//$GLOBALS['adoconnweb']->PConnect(ADOHOST_WEB, ADOUNAME_WEB, ADOPASS_WEB, ADODBNAME_WEB);
for($i=rand(0,count($ADOHOST_WEB)-1),$j=1;$j<count($ADOHOST_WEB);$i--,$j++)
  if($GLOBALS['adoconnweb']->PConnect($ADOHOST_WEB[$i], ADOUNAME_WEB, ADOPASS_WEB, ADODBNAME_WEB))
     break;
elseif($i<=0)
     $i=count($ADOHOST_WEB)-1;
*/
$GLOBALS['adoconnweb_m'] = adoNewConnection(ADODATABASE_WEB."://".ADOUNAME_WEB.":".ADOPASS_WEB."@".$ADOHOST_WEB[$i]."/".ADODBNAME_WEB."?persist");
for($i=rand(0,count($ADOHOST_WEB)-1),$j=1;$j<count($ADOHOST_WEB);$i--,$j++)
  if($GLOBALS['adoconnweb'] = adoNewConnection(ADODATABASE_WEB."://".ADOUNAME_WEB.":".ADOPASS_WEB."@".$ADOHOST_WEB[$i]."/".ADODBNAME_WEB."?persist")){
    break;
  }elseif($i<=0){
    $i=count($ADOHOST_WEB)-1;
  }
    

/**
 * Cloud資料庫連線
 */
//改用新的連線方式，舊的先暫時不刪
if(!isset($dbflag['cloud']) || $dbflag['cloud']==true){
  // $GLOBALS['adoconn_m'] = &ADONewConnection(ADODATABASE);
  // $GLOBALS['adoconn_m']->PConnect($ADOHOST_MASTER, ADOUNAME, ADOPASS, ADODBNAME);

  // $GLOBALS['adoconn'] = &ADONewConnection(ADODATABASE);
  // for($i=rand(0,count($ADOHOST)-1),$j=1;$j<count($ADOHOST);$i--,$j++)
  //   if($GLOBALS['adoconn']->PConnect($ADOHOST[$i], ADOUNAME, ADOPASS, ADODBNAME))
  //      break;
  //   elseif($i<=0)
  //      $i=count($ADOHOST)-1;
  $GLOBALS['adoconn_m'] = adoNewConnection(ADODATABASE."://".ADOUNAME.":".ADOPASS."@".$ADOHOST_MASTER."/".ADODBNAME."?persist");

  for($i=rand(0,count($ADOHOST)-1),$j=1;$j<count($ADOHOST);$i--,$j++)
    if($GLOBALS['adoconn'] = adoNewConnection(ADODATABASE."://".ADOUNAME.":".ADOPASS."@".$ADOHOST[$i]."/".ADODBNAME."?persist"))
      break;
    elseif($i<=0)
      $i=count($ADOHOST)-1;
  //20200720 cloud db 連線有開的才做處理，開始執行排程狀態資訊寫入，回傳值$crontabexec_id會帶給mainfile_footer更新結束時間
  $GLOBALS['crontabexec_id']  = process_start2db($argv);
}

//建立sinyirent
//改用新的連線方式，舊的先暫時不刪
if(!isset($dbflag['sinyirent']) || $dbflag['sinyirent']==true){
  /*
  $GLOBALS['adoconnrent'] = &ADONewConnection(ADODATABASE_RENT);
  $GLOBALS['adoconnrent']->PConnect(ADODBHOST_RENT, ADOUNAME_RENT, ADOPASS_RENT, ADODBNAME_RENT);
  */

  $GLOBALS['adoconnrent'] = adoNewConnection(ADODATABASE_RENT."://".ADOUNAME_RENT.":".ADOPASS_RENT."@".ADODBHOST_RENT."/".ADODBNAME_RENT."?persist");
  /*
  for($i=rand(0,count($ADOHOST_RENT)-1),$j=1;$j<count($ADOHOST_RENT);$i--,$j++)
    if($GLOBALS['adoconnrent']->PConnect($ADOHOST_RENT[$i], ADOUNAME_RENT, ADOPASS_RENT, ADODBNAME_RENT))
       break;
    elseif($i<=0)
       $i=count($ADOHOST_RENT)-1;
  */
}

/**
 * 建立居家的連線(setClearMemcache)
 * Log：改回舊的連線方式，因非cloudsql的db無法用DSN的連線方式 by Alan Pan 20191105
 */
if(!isset($dbflag['living']) || $dbflag['living']==true){
  
  $GLOBALS['adoconnliving'] = &ADONewConnection(ADODATABASE_LIVING);
  $GLOBALS['adoconnliving']->PConnect(ADOHOST_LIVING, ADOUNAME_LIVING, ADOPASS_LIVING, ADODBNAME_LIVING);
  

  //$GLOBALS['adoconnliving'] = adoNewConnection(ADODATABASE_LIVING."://".ADOUNAME_LIVING.":".ADOPASS_LIVING."@".ADOHOST_LIVING."/".ADODBNAME_LIVING."?persist");
}

/**
 * KYC資料庫連線
 */
if($dbflag['kyc']==true){
  $GLOBALS['adoconnkyc'] = adoNewConnection(ADODATABASE_KYC."://".ADOUNAME_KYC.":".ADOPASS_KYC."@".ADOHOST_KYC."/".ADODBNAME_KYC."?persist");
}

/**
 * events資料庫連線
 */
if($dbflag['events']==true){
  $GLOBALS['adoconnevents'] = adoNewConnection(ADODATABASE_EVENTS."://".ADOUNAME_EVENTS.":".ADOPASS_EVENTS."@".ADOHOST_EVENTS."/".ADODBNAME_EVENTS."?persist");
}

/**
 * sinyidata dbadmin 資料庫連線
 */
if($dbflag['dbadmin']==true){
  $GLOBALS['adoconndbadmin'] = adoNewConnection(ADODATABASE_DBADMIN."://".ADOUNAME_DBADMIN.":".ADOPASS_DBADMIN."@".ADOHOST_DBADMIN."/".ADODBNAME_DBADMIN."?persist");
}

/**
 * sinyidata sinyiedm 資料庫連線
 */
if($dbflag['edm']==true){
  $GLOBALS['adoconnedm'] = adoNewConnection(ADODATABASE_EDM."://".ADOUNAME_EDM.":".ADOPASS_EDM."@".ADOHOST_EDM."/".ADODBNAME_EDM."?persist");
}

/**
 * LineOA 資料庫連線
 */
if($dbflag['lineoa']==true){
  $GLOBALS['adoconnlineoa_m'] = adoNewConnection(ADODATABASE_LINEOA."://".ADOUNAME_LINEOA.":".ADOPASS_LINEOA."@".$ADOHOST_LINEOA_MASTER."/".ADODBNAME_LINEOA."?persist");
  for($i=rand(0,count($ADOHOST_LINEOA)-1),$j=1;$j<count($ADOHOST_LINEOA);$i--,$j++){
    if($GLOBALS['adoconnlineoa'] = adoNewConnection(ADODATABASE_LINEOA."://".ADOUNAME_LINEOA.":".ADOPASS_LINEOA."@".$ADOHOST_LINEOA[$i]."/".ADODBNAME_LINEOA."?persist")){
      break;
    }elseif($i<=0){
      $i=count($ADOHOST_LINEOA)-1;
    }
  }
}

/**
 * 產生亂數數值
 * @return 亂數數值
 */
function randnum()
{
  return sprintf("%06d",mt_rand(1,999999));
}

/**
 * @return void
 * @param String $formaction  目的位置
 * @param Array  $fields    傳出值
 * @param String $formname    表單名稱
 * @param String $showaitmsg  是否顯示等待訊息(1:顯示, 0:不顯示)
 * @desc 以HTTP POST方式將值傳出
 * 20060629多加了method參數
 * 20060915多加了showaitmsg參數
 */
function POSTFORM($formaction,$fields,$formname='goto',$method='post',$showaitmsg='1')
{
  echo "<html><head>";
  echo "<META HTTP-EQUIV='Content-Type' CONTENT='text/html; charset="._CHARSET."'>\n";
  echo "<title>POSTFORM</title></head>\n<body>\n";
  //顯示請稍待訊息 modify by yushin, 2006-09-15.
  if ($showaitmsg == "1")
  {
    echo "<center><font size='4' color='blue'>"._WAITINGMSG."</font></center>";
  }
  echo "<form name='$formname' id='$formname' action='$formaction' method='$method'>\n"; //modify Ken Tsai 20060629
  foreach($fields as $fieldname => $fieldvalue)
    echo "<input type='hidden' name='".$fieldname."' value='".$fieldvalue."'>\n";
  echo "</form>\n";
  echo "</body></html>";
  echo "<script type='text/javascript'>\n"
  //modify by amy 2006/01/18
  ."document.getElementById('$formname').submit();\n"
  ."</script>\n";
}

/*
*加密演算法
*@parameter  $data 未加密字串, $credate 時間為加密鍵值 yyyy-mm-dd HH:ii:ss, 若未傳加密鍵值, 預設是固定鍵值
*@return 加密碼
*/
function encrypt($data, $credate=CRT_STR)
{
    if ($credate!=CRT_STR)
    {
$credate=strtotime($credate);
    }
    $key = md5($credate);
    $x = 0;
    $len = strlen($data);
    $l = strlen($key);
    for ($i = 0; $i < $len; $i++)
    {
        if ($x == $l)
        {
        $x = 0;
        }
        $char .= $key{$x};
        $x++;
    }
    for ($i = 0; $i < $len; $i++)
    {
        $str .= chr(ord($data{$i}) + (ord($char{$i})) % 256);
    }
    return base64_encode($str);
}

/*
*解密演算法著
*@parameter  $data 加密字串, $credate 時間為加密鍵值 yyyy-mm-dd HH:ii:ss, 若未傳加密鍵值, 預設是固定鍵值
**@return 還原加密碼
*
*/
function decrypt($data, $credate=CRT_STR)
{
    if ($credate!=CRT_STR)
    {
$credate=strtotime($credate);
    }
    $key = md5($credate);
    $x = 0;
    $data = base64_decode($data);
    $len = strlen($data);
    $l = strlen($key);
    for ($i = 0; $i < $len; $i++)
    {
        if ($x == $l)
        {
        $x = 0;
        }
        $char .= substr($key, $x, 1);
        $x++;
    }
    for ($i = 0; $i < $len; $i++)
    {
        if (ord(substr($data, $i, 1)) < ord(substr($char, $i, 1)))
        {
            $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
        }
        else
        {
            $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
        }
    }
    return $str;
}
/*
 * Function: logFormatId
 * Description: 取得log_format id
 * Params:  @logname: string, 排程名稱
 * Return:  @format_id: int, 編號
*/
function logFormatId($logname){

  //找出此排程的formatid
  $sql = "select id from ".ADOPREFIX_LOG."_format where name = ? and exectype = ? ";
  $sql_params = array($logname,3);
  $format_rs = $GLOBALS['adoconnlog']->Execute($sql,$sql_params);
  $format_id  = $format_rs->fields['id'];

  return $format_id;
}

/*
 * Function: waittimeout_query
 * Description: 為避免連線waittimeout,所以送出一個無用的查詢
 * Return:  @rs: recordset
*/
function waittimeout_query(){
  //為避免cloud db連線waittimeout,所以送出一個無用的查詢
  $waittimeout_sql = "select now() as stependtime";
  $rs_m = $GLOBALS['adoconn_m']->Execute($waittimeout_sql);
  $rs = $GLOBALS['adoconn']->Execute($waittimeout_sql);
  return $rs;
}

/*
 * Function: waittimeout_query
 * Description: 為避免連線waittimeout,所以送出一個無用的查詢
 * Return:  @rs: recordset
*/
function waittimeout_query_database($database){
  //為避免cloud db連線waittimeout,所以送出一個無用的查詢
  $waittimeout_sql = "SELECT now()";
  switch ($database) {
    case 'cloud':
      $GLOBALS['adoconn_m']->Execute($waittimeout_sql);
      $GLOBALS['adoconn']->Execute($waittimeout_sql);
      break;
    case 'sinyiweb':
      $GLOBALS['adoconnweb_m']->Execute($waittimeout_sql);
      $GLOBALS['adoconnweb']->Execute($waittimeout_sql);
      break;
    case 'logservice':
      $GLOBALS['adoconnlog']->Execute($waittimeout_sql);
      break;
  }
}

//調整waittimeout時間為90秒
if(!isset($dbflag['cloud']) || $dbflag['cloud']==true){
  $GLOBALS['adoconn_m']->Execute("set wait_timeout = 90");
  $GLOBALS['adoconn']->Execute("set wait_timeout = 90");
}
$GLOBALS['adoconnlog']->Execute("set wait_timeout = 90");
$GLOBALS['adoconnweb_m']->Execute("set wait_timeout = 90");
$GLOBALS['adoconnweb']->Execute("set wait_timeout = 90");
if(!isset($dbflag['sinyirent']) || $dbflag['sinyirent']==true){
  $GLOBALS['adoconnrent']->Execute("set wait_timeout = 90");
}
if(!isset($dbflag['living']) || $dbflag['living']==true){
  $GLOBALS['adoconnliving']->Execute("set wait_timeout = 90");
}
if($dbflag['kyc']==true){
  $GLOBALS['adoconnkyc']->Execute("set wait_timeout = 90");
}
if($dbflag['events']==true){
  $GLOBALS['adoconnevents']->Execute("set wait_timeout = 90");
}
if($dbflag['dbadmin']==true){
  $GLOBALS['adoconndbadmin']->Execute("set wait_timeout = 90");
}
if($dbflag['edm']==true){
  $GLOBALS['adoconnedm']->Execute("set wait_timeout = 90");
}

/**
 * Company: SINYI
 * func:    reConnectionWeb
 * Author:  Alan Pan
 * Date:    2022.01.05
 * Description: 強制建立新的 sinyiweb 連線
 * Parameter:
 * Return:
 */
function reConnectionWeb(){
  global $ADOHOST_WEB_MASTER;
  global $ADOHOST_WEB;
  // $ADOHOST_WEB_MASTER、$ADOHOST_WEB 這兩個全域變數設定在 config.php
  $GLOBALS['adoconnweb_m'] = &ADONewConnection(ADODATABASE_WEB);
  // Connect(hostName,userId,password,database,isForceNewConnection)
  $GLOBALS['adoconnweb_m']->Connect($ADOHOST_WEB_MASTER, ADOUNAME_WEB, ADOPASS_WEB, ADODBNAME_WEB, true);
  for($i=rand(0,count($ADOHOST_WEB)-1),$j=1;$j<count($ADOHOST_WEB);$i--,$j++){
    $GLOBALS['adoconnweb'] = &ADONewConnection(ADODATABASE_WEB);
    if($GLOBALS['adoconnweb']->Connect($ADOHOST_WEB[$i], ADOUNAME_WEB, ADOPASS_WEB, ADODBNAME_WEB, true)){
      break;
    }elseif($i<=0){
      $i=count($ADOHOST_WEB)-1;
    }
  }
}

/**
 * Company: SINYI
 * func:    reConnectionEvents
 * Author:  Alan Pan
 * Date:    2022.01.18
 * Description: 強制建立新的 events 連線
 * Parameter:
 * Return:
 */
function reConnectionEvents(){
  $GLOBALS['adoconnevents'] = &ADONewConnection(ADODATABASE_EVENTS);
  // Connect(hostName,userId,password,database,isForceNewConnection)
  $GLOBALS['adoconnevents']->Connect(ADOHOST_EVENTS, ADOUNAME_EVENTS, ADOPASS_EVENTS, ADODBNAME_EVENTS, true);
}

/**
 * Company: SINYI
 * func:    commonWriteSmsErrorLog
 * Author:  Alan Pan
 * Date:	2023.02.22
 * Description: 記錄錯誤記錄通知
 * Log:		
 * Parameter:   @IN     @dbConnection：資料庫連線
 *                      @errmsg：錯誤訊息（string）
 *                      @smsErrorType：錯誤訊息類型ID（array）
 * Return:      @OUT
 * Example:     @IN    
 * 				writeSmsErrorLog("錯誤訊息")
 *              @OUT   
 */
function commonWriteSmsErrorLog($dbConnection, $smsErrorType, $errmsg){
  if(is_array($smsErrorType)){
    $sql_params = [];
    $sql = "INSERT INTO cloud_tc_sms_error_log(createdatetime,type,errmsg,is_send) VALUES ";
    foreach($smsErrorType as $key => $value){
      $sql .= "(NOW(), ?, ? , 0)";
      $sql_params[] = $value;
      $sql_params[] = $errmsg;
      if ($value !== end($smsErrorType)){
        $sql .= ", ";
      }
    }
    $rs = $dbConnection->Execute($sql, $sql_params);
    return $rs;
  }
}

/*
 * Function: createNewConnection
 * Description: 重新建立連線
 * Return:  @rs: recordset
*/
function createNewConnection($database){
  global $ADOHOST;
  global $ADOHOST_MASTER;
  global $ADOHOST_WEB;
  global $ADOHOST_WEB_MASTER;
  
  switch ($database) {
    case 'cloud':
      $masterConn = adoNewConnection(ADODATABASE."://".ADOUNAME.":".ADOPASS."@".$ADOHOST_MASTER."/".ADODBNAME."?persist");
      for($i=rand(0,count($ADOHOST)-1),$j=1;$j<count($ADOHOST);$i--,$j++){
        if($slaveConn = adoNewConnection(ADODATABASE."://".ADOUNAME.":".ADOPASS."@".$ADOHOST[$i]."/".ADODBNAME."?persist")){
          break;
        }elseif($i<=0){
          $i=count($ADOHOST)-1;
        }
      }  
      $GLOBALS['adoconn_m'] = $masterConn;
      $GLOBALS['adoconn'] = $slaveConn;
      break;
    case 'sinyiweb':
      $masterConn = adoNewConnection(ADODATABASE_WEB."://".ADOUNAME_WEB.":".ADOPASS_WEB."@".$ADOHOST_WEB_MASTER."/".ADODBNAME_WEB."?persist");
      for($i=rand(0,count($ADOHOST_WEB)-1),$j=1;$j<count($ADOHOST_WEB);$i--,$j++){
        if($slaveConn = adoNewConnection(ADODATABASE_WEB."://".ADOUNAME_WEB.":".ADOPASS_WEB."@".$ADOHOST_WEB[$i]."/".ADODBNAME_WEB."?persist")){
          break;
        }elseif($i<=0){
          $i=count($ADOHOST_WEB)-1;
        }
      }
      $GLOBALS['adoconnweb_m'] = $masterConn;
      $GLOBALS['adoconnweb'] = $slaveConn;
      break;
  }
}
/**
 * Company: SINYI
 * func:    encryptSensitiveData
 * Author:  Elvis Hsu
 * Date:	2021.08.27
 * Description: 敏感資料AES-256-CBC加密
 * Parameter:   @IN     @data：資料字串
 * 						@key：加密密鑰
 * 						@iv：初始向量
 *                      @debug：偵錯功能
 * Return:      @OUT    加密字串
 * Example:     @IN
 *              encryptdata()
 *              @OUT
 *              ""
 */
function encryptSensitiveData($data, $key, $iv, $debug = false) {
	$padding = 16 - (strlen($data) % 16);
	$data .= str_repeat(chr($padding), $padding);
	if ($debug) echo "key=$key; iv=$iv; padding=$padding; paddingData=$data;\n";
	$encryptstr = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $data, MCRYPT_MODE_CBC, $iv);

	return base64_encode($encryptstr);
}
?>