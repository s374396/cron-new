#!/usr/bin/php
<?php
/*************************************
 * Company: SinYi
 * Program: Sinyi2Rakuya.php
 * Author:  Alan Pan
 * Date:    2019.01.05
 * Version: 2.0
 * Description: 信義物件同步樂屋網，上架的條件是信義的物件上架10天後才能轉檔至同業，且只轉分店編號是全球的資產('BR21','FC10','FC40','FC50','R650')、代銷分店(HX開頭)與 R 開頭的分店編號，格局圖是否顯示於同業要判斷 nostylepic=0 才顯示，要排除 路段為空 且 建坪為 0 且 物件照片小於 2 張的物件
 * Steps:
 *			1.傳送分店上架資料
 *			2.傳送分店下架資料
 *			3.傳送物件上架資料
 *			4.傳送物件下架資料
 *
 * Log:		2019.01.05	實作初版
 *        2019.07.08	調整執行順序，1.分店上架資料 2.分店下架資料 3.先下架mysql下架物件 4.上架物件 5.sqlite 下架物件
 *        2019.11.04	將錯誤訊息寫入至 cloud_tc_sms_error_log 中，用於發簡訊通知有錯誤發生
 *        2019.11.25 特別處理樓層，當是多樓層的物件要傳maxfloors
 *        2019.12.24 加傳objectype欄位到同業(中古屋或預售屋)，若objectype是車位或土地的話歸類到中古屋
 *        2020.06.12 格局圖是否顯示於同業要判斷 nostylepic 為 0 才顯示 by Alan Pan
 *        2020.07.08 single_price單坪價格單位改成萬元取到小數第二位 by Alan Pan
 *        2021.04.16 因樂屋伺服單於4/14停機調整後，API速度變慢導致db連線timeout中斷，所以把 SQL 拉至最上方執行 by Alan Pan
 *        2021.06.01 新增 3 間全球分店(FC60、FC70、FC80) by Alan Pan
 * 			  2021.08.05 新增轉出 加蓋格局資訊 by Elvis Hsu 
 * 			  2021.08.26 新增 1 間全球分店 FCA0 by Alan Pan
 * 				2021.09.14 新增 entrust_type 欄位判斷物件是一般/專任委託，一般委託(entrust_type=1)只要有出外網就拋轉至樂屋網；專任委託(entrust_type=2)維持原本規則上架10天後才拋轉至樂屋 by Alan Pan
 * 				2022.05.06 將<BR>改為。 ; 增加拋轉3DVR連結 ; 新增特色標籤 by Lina Hu
 * 				2022.08.30 強制轉 69049P 至同業 by Alan Pan
 * 				2022.10.03 若物件有移轉分店需先下架物件在上架，且將sqlite改成mysql，故將執行順序改成 分店下架->分店上架->物件下架->物件上架->將 7 天前的記錄刪除
 *				2022.11.21 強制轉 81409Y 至同業 by Alan Pan
 *				2023.04.24 調整房屋分類，若objectype 為 1 成屋需在判斷屋齡是否小於等於3，若小於等於 3 為N新屋，反之為O中古屋 by Alan Pan
 *  			2023.05.25 大樓/華廈/公寓物件，改成落地窗朝向，落地窗沒有值，再沿用原先的建物朝向 BY Alan Pan
 *      	2023.08.16 寫入要轉檔的物件sql語法where條件新增排除預售屋 by Karen Chao
 *      	2024.03.26 多增加拋轉預售屋及「預售屋核准文號」、「預售屋核准日期」，若該物件的「預售屋核准期限」將在三天後過期，則須將此物件下架 by Karen Chao
 * 			2025.07.25 修正拋轉到樂屋後，分店資料錯誤問題。by Anny Liao
 * 			2025.09.23 專委物件全面開放，不限制上架11天後拋送。by Anny Liao
 * Parameter:   @IN 	@-type:執行類別 ( 1.傳送分店上架資料 2.傳送分店下架資料 3.傳送物件上架資料 4.傳送物件下架資料 )
 						    @-no:分店(物件)編號
 *						不傳：全部執行
 * Return:      
 * Log紀錄:	1.csv:log/Sinyi2Rakuya/Sinyi2Rakuya.csv(當天)
 *		    2.log_record format_id = 275
 *************************************/
chdir("/www/cron");
include_once("mainfile.php");
include_once("language/Sinyi2Rakuya-tc.php");
include_once("language/sinyi2common-tc.php");
include_once("/www/common/lib/sinyi/buy/sinyiPartner.php");
include_once("/www/common/lib/zenkaku_hankaku/kaku.inc.php");
$starttime_log = date('Y-m-d H:i:s');  // log: 開始時間
try{
	//排程傳入參數處理
	$arrpara = [
		'type' => '0',
		'no' => ''
	];
	foreach($argv as $key => $arrvalue)
	{
		//執行類別 1.傳送分店上架資料 2.傳送分店下架資料 3.傳送物件上架資料 4.傳送物件下架資料
		if($arrvalue=="-type" ){
			$arrpara['type']=$argv[$key+1];
		}
		//分店(物件)編號
		if($arrvalue=="-no" ){
			$arrpara['no']=$argv[$key+1];
		}
	}
	$log1['step']              = TYPE1;
	$log1['status']['total']   = 0;
	$log1['status']['success'] = 0;
	$log1['status']['fail']    = 0;
	$log2['step']              = TYPE2;
	$log2['status']['total']   = 0;
	$log2['status']['success'] = 0;
	$log2['status']['fail']    = 0;
	$log3['step']              = TYPE3;
	$log3['status']['total']   = 0;
	$log3['status']['success'] = 0;
	$log3['status']['fail']    = 0;
	$log4['step']              = TYPE4;
	$log4['status']['total']   = 0;
	$log4['status']['success'] = 0;
	$log4['status']['fail']    = 0;

	$common_business= checkCommonBusiness(RAKUYA_COMMONID);
	if($common_business['out_enable'] == 1){
		define("RAKUYA_URL",$common_business['outapiURL']); // 訂義 API 網址
		$object_condition = "";
		$getDataRecordSet = storeData($arrpara, $object_condition);
		//信義物件同步樂屋網處理執行
		if ( in_array($arrpara['type'],array('0','2')) && $getDataRecordSet['store_off_rs']) {//2.傳送分店下架資料
			logcsv($pathstr, $logname, json_encode('pull store'), $level); //log csv
			if($common_business['out_enable'] == 1){
				store_off($log, $getDataRecordSet['store_off_rs'], $log2);
				unset($getDataRecordSet['store_off_rs']);
			}else{
				$log2 = $common_business;
			}
			$log['message']['store_off'] = $log2;
			$log['status']['success']['sum']['pull_store'] = $log2['status']['success'];
			$log['status']['fail']['sum']['pull_store']    = $log2['status']['fail'];
			$log2 = null;
		}

		if ( in_array($arrpara['type'],array('0','1')) && $getDataRecordSet['store_on_rs']) { //1.傳送分店上架資料
			logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], json_encode('push store'), 1);
			if($common_business['out_enable'] == 1){
				store_on($log, $getDataRecordSet['store_on_rs'], $log1);
				unset($getDataRecordSet['store_on_rs']);
			}else{
				$log1 = $common_business;
			}
			$log['message']['store_on'] = $log1;
			$log['status']['success']['sum']['push_store'] = $log1['status']['success'];
			$log['status']['fail']['sum']['push_store']    = $log1['status']['fail'];
			$log1 = null;
		}
		
		if ( in_array($arrpara['type'],array('0','4'))  && $getDataRecordSet['object_off_rs']) {//4.傳送物件下架資料-sqlite
			logcsv($pathstr, $logname, json_encode('diff item'), $level); //log csv
			if($common_business['out_enable'] == 1){
				object_off($log, $getDataRecordSet['object_off_rs'], $common_business, $log4);
				unset($getDataRecordSet['object_off_rs']);
			}else{
				$log4 = $common_business;
			}
			$log['message']['object_off'] = $log4;
			$log['status']['success']['sum']['pull_item']  = $log4['status']['success'];
			$log['status']['fail']['sum']['pull_item']     = $log4['status']['fail'];
			$log4 = null;
		}
		
		if ( in_array($arrpara['type'], array('0','3')) ) {//3.傳送物件上架資料
			logcsv($pathstr, $logname, json_encode('push item'), $level); //log csv
			// var_dump($getDataRecordSet['object_on_rs']);exit();
			if($common_business['out_enable'] == 1){
				
				if($getDataRecordSet['object_total_rs']){
					$object_total_rs = $getDataRecordSet['object_total_rs'];
					$total_page = ceil($object_total_rs->fields['totalRow'] / RAKUYA_TRANSFERLIMIT);
					
					for ( $i=0 ; $i<$total_page ; $i++ ) {
						$object_on_rs = getObjectData($arrpara, $object_condition, $i);
						// var_dump($object_on_rs);exit();
						if(!$object_on_rs){
							$errmsg = "getObjectData object_on_rs fail. sql：" . $object_on_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
							logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
							sms_error_log($errmsg, '2');
						}
						object_on($log, $object_on_rs, $log3);
					}
				}
				unset($object_on_rs);
			}else{
				$log3 = $common_business;
			}
			$log['message']['object_on'] = $log3;
			$log['status']['success']['sum']['push_item']  = $log3['status']['success'];
			$log['status']['fail']['sum']['push_item']     = $log3['status']['fail'];
			$log3 = null;
		}
	}else{
		//20191104 將錯誤訊息寫入到 cloud_tc_sms_error_log 中
		$errmsg = RAKUYA . " - " . OUTENABLE_FALSE;
		sms_error_log(json_encode($errmsg,JSON_UNESCAPED_UNICODE), '2');
	}
	
	//LOG
	$log['endtime']                                = date('Y-m-d H:i:s');	
	logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], json_encode('sum log'), 1);
	logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], json_encode($log,JSON_UNESCAPED_UNICODE), 1);
	echo json_encode($log,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
	// 刪除 7 天前的記錄
	deleteOldData();
	include_once("mainfile_footer.php"); //logcsv 自動產生 end
	exit();
}catch(Exception $e) {
    $logcsvMsg = "cron Sinyi2Rakuya Exception：" . $e->getMessage();
    logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $logcsvMsg, 0);
}

/**
 * Company: SINYI
 * func:    deleteOldData
 * Author:  Alan Pan
 * Date:    2022.10.03
 * Description: 刪除 7 天前的記錄
 * Parameter:   @IN     
 * Return:      @OUT
 * Example:     @IN
 *              deleteOldData()
 *              @OUT
 */
function deleteOldData(){
	for($i=0; $i<=2; $i++){
		$sql = "DELETE FROM sinyi_rakuya WHERE send_date < DATE_FORMAT(NOW() - INTERVAL 7 DAY,'%Y-%m-%d 00:00:00')";
		$rs = $GLOBALS['adoconnweb']->Execute($sql);
		if(!$rs){
			if($i < 2) {
                if(strpos(strtolower($GLOBALS['adoconnweb']->errorMsg()), 'mysql server has gone away') !== false ){
                    createNewConnection("sinyiweb");
                }else{
                    $errmsg = "deleteOldData fail. sql：" . $sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
					logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
					sms_error_log($errmsg, '2');
                    break;
                }
            }else{
                $errmsg = "deleteOldData fail. sql：" . $sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
				logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
				sms_error_log($errmsg, '2');
                break;
            }
		}else{
			break;
		}
	}
}

/**
 * Company: SINYI
 * func:    storeData
 * Author:  Alan Pan
 * Date:    2022.10.03
 * Description: 先把轉檔要用的資料先備好
 * Parameter:   @IN     @arrpara：cron 排程執行時的參數 
 * 							@type：執行類別 1.傳送分店上架資料 2.傳送分店下架資料 3.傳送物件上架資料 4.傳送物件下架資料
 * 							@no：分店編號/物件銷號
 * 						@object_condition：轉檔物件搜尋的條件
 * Return:      @OUT    @store_on_rs：需拋分店上架至樂屋的 recordset,
 *						@store_off_rs：需拋分店下架至樂屋的 recordset,
 *						@object_total_rs：需拋物件上架至樂屋的總筆數,
 *						@object_off_rs：需拋物件下架至樂屋的 recordset,
 * Example:     @IN
 *              storeData($arrpara, "")
 *              @OUT
 */
function storeData(&$arrpara, &$object_condition){
	// 寫入要轉檔的分店編號資料
	$insert_store_sql = "
		INSERT INTO sinyi_rakuya(type, house_no, store_no, send_date)
		SELECT 1, storeno, storeno, DATE_FORMAT(NOW(),'%Y-%m-%d') FROM sinyi_store
		WHERE (
			( deptype = 'A' AND storeno like 'R%' ) 
			OR storeno LIKE 'HX%' 
			OR storeno IN ('BR21','FC10','FC40','FC50','R650','FC60','FC70','FC80','FCA0')
		)
	";
	if($arrpara['type']=='1' and $arrpara['no']!=''){
		$insert_store_sql .= " AND storeno='" . $arrpara['no'] . "'";
	}
	$insert_store_sql .= " 
		AND NOT EXISTS (
			SELECT house_no FROM sinyi_rakuya b WHERE
			type=1 AND b.house_no=sinyi_store.storeno AND send_date=DATE_FORMAT(NOW(),'%Y-%m-%d')
		)
	";
	$insert_store_sql .= "ORDER BY storeno";
	$insert_store_rs  = $GLOBALS['adoconnweb']->Execute($insert_store_sql);
	if(!$insert_store_rs){
		$errmsg = "insert_store fail. sql：" . $insert_store_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
		sms_error_log($errmsg, '2');
	}

	// 取出要上架的分店資料
	$store_on_sql = "
		SELECT
			tbls.storeno AS store_id,
			'Y' AS status,
			CONCAT('"._STORENAME."',REPLACE(tbls.storename,' ','')) AS store_name,
			1 store_type,
		    '"._COMPANYNAME."' company_name,
		    '"._FRANCHISENAME."' franchise_name,
		    CONCAT(REPLACE(tblss.salesname,'　',''),'"._FRANCHISEMANAGER."') AS leader,
		    CASE WHEN tbls.storeno IN ('BR21','FC10','FC40','FC50','R650') THEN
		    (SELECT MAX(CONCAT('S',REPLACE(tblsss.salesno,' ',''),'@sinyi.com.tw')) FROM sinyi_sales AS tblsss WHERE tblsss.stafftype = 'C' AND tblsss.storeno=tbls.storeno)
		    ELSE CONCAT('S',REPLACE(tbls.storeno,' ',''),'@sinyi.com.tw') END AS email,
		    REPLACE(tbls.tel ,' ','') AS tel,
		    REPLACE(IFNULL ( tblss.mobileno , tbls.tel ),' ','') AS mobile,
		    REPLACE(tbls.zipCode ,' ','') AS zipcode,
		    REPLACE(REPLACE(tbls.address,' ',''),'　','') AS address,
		    tbls.storelat AS lat,
		    tbls.storelon AS lng
		FROM sinyi_store AS tbls
		LEFT JOIN sinyi_sales AS tblss ON tblss.salesno = tbls.manager
		WHERE ( tbls.deptype = 'A' AND tbls.storeno like 'R%' ) 
		OR tbls.storeno LIKE 'HX%' 
		OR tbls.storeno IN ('BR21','FC10','FC40','FC50','R650','FC60','FC70','FC80','FCA0') 
	";

	if($arrpara['type']=='1' and $arrpara['no']!=''){
		$store_on_sql .= " AND tbls.storeno='" . $arrpara['no'] . "'";
	}
	$store_on_rs  = $GLOBALS['adoconnweb']->Execute($store_on_sql);
	if(!$store_on_rs){
		$errmsg = "storeData store_on_rs fail. sql：" . $store_on_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
		sms_error_log($errmsg, '2');
	}

	// 取出要下架的分店資料
	$store_off_sql = "
		SELECT 
			house_no AS store_id,
			'N' AS status
		FROM sinyi_rakuya 
		WHERE type=1 
		AND send_date < DATE_FORMAT(NOW(),'%Y-%m-%d') 
		AND house_no NOT IN (
			SELECT house_no 
			FROM sinyi_rakuya 
			WHERE type=1 
			AND send_date= DATE_FORMAT(NOW(),'%Y-%m-%d')
		)
	";
	$store_off_rs  = $GLOBALS['adoconnweb']->Execute($store_off_sql); 
	if(!$store_off_rs){
		$errmsg = "storeData store_off_rs fail. sql：" . $store_off_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
		sms_error_log($errmsg, '2');
	}

	// 寫入要轉檔的物件銷號資料
	$object_condition = "
		WHERE hl.status = 1 
		AND hl.houseinc=1 
		AND hl.roadname <> '' 
		AND ((hl.houselandtype1 <> 'I' AND hl.buildingmainping > 0) OR (hl.houselandtype1 = 'I' AND hl.buildingmainping= 0)) 
		AND (SELECT COUNT(*) AS cnt FROM sinyi_house_sell_img AS hsil WHERE substring(imgfilename,7,1) <> 'E' AND hsil.houseno=hl.houseno HAVING cnt > 2) 
		AND (hl.reservestoreno LIKE 'HX%' OR hl.reservestoreno IN ('BR21','FC10','FC40','FC50','R650','FC60','FC70','FC80','FCA0') OR hl.reservestoreno LIKE 'R%') 
		AND hl.houselandtype1 <> 'J'
	";
	if($arrpara['type']=='3' and $arrpara['no']!=''){
		$object_condition .= " AND hl.houseno='".$arrpara['no']."'";
	}
	$object_condition .= "
		AND hl.houseno NOT IN(SELECT houseno FROM sinyi_house_sell_common_blacklist WHERE commonid=" . RAKUYA_COMMONID . ")
	";
	$insert_object_sql = "
		INSERT INTO sinyi_rakuya(type, house_no, store_no, send_date)
		SELECT 2, hl.houseno, hl.reservestoreno, DATE_FORMAT(NOW(),'%Y-%m-%d') FROM sinyi_house_sell AS hl
		LEFT JOIN sinyi_store AS tblS on hl.reservestoreno = tblS.storeno
		LEFT JOIN sinyi_sales AS tSS on hl.reservesalesno = tSS.salesno
	" . $object_condition . " 
		AND NOT EXISTS (
			SELECT house_no FROM sinyi_rakuya b WHERE
			type=2 AND b.house_no=hl.houseno AND send_date=DATE_FORMAT(NOW(),'%Y-%m-%d')
		)
	";
	$object_condition .= "
		ORDER BY hl.createdatetime DESC, hl.totalprice DESC
	";
	$insert_object_rs  = $GLOBALS['adoconnweb']->Execute($insert_object_sql); 
	if(!$insert_object_rs){
		$errmsg = "insert_object fail. sql：" . $insert_object_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
		sms_error_log($errmsg, '2');
	}
	// 取出要上架的物件資料總數
	$object_total_sql = "
		SELECT count(*) AS totalRow
		FROM sinyi_house_sell AS hl
		LEFT JOIN sinyi_store AS tblS on hl.reservestoreno = tblS.storeno
		LEFT JOIN sinyi_sales as tSS on hl.reservesalesno = tSS.salesno
	" . $object_condition;
	$object_total_rs  = $GLOBALS['adoconnweb']->Execute($object_total_sql); 
	// $objcetDataInfo = getObjectData($arrpara, $object_condition);
	if(!$object_total_rs){
		$errmsg = "storeData object_total_rs fail. sql：" . $object_total_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
		sms_error_log($errmsg, '2');
	}
	// 取出要下架的物件銷編資料
	$object_off_sql = "
		SELECT 
			house_no 
		FROM sinyi_rakuya 
		WHERE type=2 
		AND send_date < DATE_FORMAT(NOW(),'%Y-%m-%d') 
		AND house_no NOT IN (
			SELECT house_no 
			FROM sinyi_rakuya 
			WHERE type=2 
			AND send_date= DATE_FORMAT(NOW(),'%Y-%m-%d')
		)
	UNION
		SELECT houseno AS house_no
		FROM sinyi_house_sell_common_blacklist 
		WHERE commonid=" . RAKUYA_COMMONID . "
		AND enable=0
	UNION
		SELECT
			a.house_no
		FROM (
			SELECT 
			house_no,
			ANY_VALUE(store_no) AS store_no
			FROM sinyi_rakuya 
			WHERE type=2 
			AND send_date < DATE_FORMAT(NOW(),'%Y-%m-%d') 
		)a
		JOIN (
			SELECT 
			house_no,
			ANY_VALUE(store_no) AS store_no
			FROM sinyi_rakuya 
			WHERE type=2 
			AND send_date = DATE_FORMAT(NOW(),'%Y-%m-%d') 
		) b ON a.house_no=b.house_no AND a.store_no<>b.store_no
		GROUP BY a.house_no
	UNION 
		SELECT houseno AS house_no
    	FROM sinyi_house_sell s
    	WHERE s.houseinc=1 
    	AND s.status=1 
    	AND s.objectype=4 
    	AND DATE_FORMAT(NOW(), '%Y-%m-%d') NOT BETWEEN DATE_FORMAT(s.approval_date, '%Y-%m-%d') AND DATE_FORMAT(DATE_SUB(s.approval_end_date, INTERVAL 4 DAY), '%Y-%m-%d')
	";
	$object_off_rs  = $GLOBALS['adoconnweb']->Execute($object_off_sql);
	if(!$object_off_rs){
		$errmsg = "storeData object_off_rs fail. sql：" . $object_off_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
		sms_error_log($errmsg, '2');
	}
	return [
		"store_on_rs" => $store_on_rs,
		"store_off_rs" => $store_off_rs,
		"object_total_rs" => $object_total_rs,
		"object_off_rs" => $object_off_rs,
	];
}

/**
 * Company: SINYI
 * func:    getObjectData
 * Author:  Alan Pan
 * Date:    2022.10.03
 * Description: 批次把物件資料取出來
 * Parameter:   @IN     @arrpara：cron 排程執行時的參數 
 * 							@type：執行類別 1.傳送分店上架資料 2.傳送分店下架資料 3.傳送物件上架資料 4.傳送物件下架資料
 * 							@no：分店編號/物件銷號
 * 						@object_condition：轉檔物件搜尋的條件
 * 						@page：頁數
 * Return:      @OUT    @object_on_rs：需拋物件上架至樂屋的 recordset,
 * Example:     @IN
 *              getObjectData($arrpara, "")
 *              @OUT
 */
function getObjectData(&$arrpara, &$object_condition, &$page=0){
	// 取出要上架的物件資料
	$total = RAKUYA_SEARCHTOTAL;
	$object_on_sql = "
	SELECT 
		houseSell.*,
		REPLACE(tSS.salesname,'　','') AS owner_name,
		tblS.tel AS owner_tel,
		REPLACE(tblS.storename,' ','') AS store_name,
		tSS.salesno AS broker,
		tSS.storeno AS store_id
	FROM (	
		SELECT 
			hl.houseno AS objno,
			'Y' AS status,
			'S' AS objind,
			hl.name AS title,
			1 AS company_id,
			'"._COMPANYNAME."' AS company_name ,		
			1 AS store_type,
			'"._FRANCHISENAME."' AS franchise_name,
			CASE hl.houseuse
				WHEN '1' THEN 1                                                                  
				WHEN '2' THEN 2
				WHEN '3' THEN 6
				WHEN '4' THEN 12
				WHEN '5' THEN 1
				WHEN '6' THEN 1
				WHEN '8' THEN 3
				WHEN '9' THEN 5
				ELSE 4
			END AS usecode,
			CASE 
				WHEN hl.houseuse = '5' THEN 'R3'
				WHEN hl.houseuse = '6' THEN 'R4'
				WHEN hl.houseuse = '3' THEN 'B1'
				WHEN hl.houseuse = '2' THEN 'B2'
				WHEN hl.houseuse = '4' THEN 'B3'
				WHEN hl.houseuse = '9' THEN 'B4'
				WHEN hl.buildingtype = '1' THEN 'R5'		                
				WHEN hl.buildingtype = '2' THEN 'R1'
				WHEN hl.buildingtype = '3' THEN 'R2'
				WHEN hl.buildingtype = '4' THEN 'R2'
				WHEN hl.buildingtype = '5' THEN 'R6'
				WHEN hl.buildingtype = '6' THEN 'B5'
				ELSE 'B5'
			END AS typecode,
			CASE 
				WHEN hl.objectype='4' THEN 'P'
				WHEN hl.objectype='1' AND hl.houseage <= 3 THEN 'N'
				ELSE 'O'
			END AS obj_type,
			CASE hl.houseuse
				WHEN '1' THEN '"._USECODENAME1."' 
				WHEN '2' THEN '"._USECODENAME2."' 
				WHEN '3' THEN '"._USECODENAME3."' 
				WHEN '4' THEN '"._USECODENAME4."' 
				WHEN '5' THEN '"._USECODENAME5."' 
				WHEN '6' THEN '"._USECODENAME6."' 
				ELSE '"._USECODENAMEOTHER."'
			END AS usecode_name,
			REPLACE(hl.zipcode,' ','') AS zipcode,
			hl.roadname AS address,
			hl.totalprice*10000 AS price,
			CASE houselandtype1
				WHEN 'I' THEN hl.landping
				ELSE buildingping
			END AS totalsize,
			hl.buildingmainping AS mainsize,
			hl.landping AS basesize,
			hl.totalfloor AS surfloors,
			hl.roomcount AS bedrooms,
			hl.bathroomcount AS bathrooms,
			hl.hallcount AS livingrooms,
			CASE hl.hasManager 
				WHEN 0 THEN 1 
				ELSE 9
			END AS manage,
			CAST(hl.magfee AS signed) AS securityfee,
			CASE 
				WHEN IFNULL(hl.completedate,'') <> '' THEN (DATEDIFF(hl.completedate,'1970-01-01 00:00:00')* 24*60*60)
				ELSE 0
			END AS findate,
			hl.houselat AS lat,
			hl.houselon AS lng,
			CASE hl.parktype
				WHEN 0 THEN 0
				WHEN 1 THEN 2
				ELSE 1
			END AS parking_type,
			CASE hl.parktype
				WHEN 0 THEN '"._GARAGE0."'
				WHEN 1 THEN '"._GARAGE1."'
				WHEN 2 THEN '"._GARAGE2."'
				WHEN 3 THEN '"._GARAGE3."'
				WHEN 4 THEN '"._GARAGE4."'
				WHEN 5 THEN '"._GARAGE5."'
				WHEN 6 THEN '"._GARAGE6."'
				WHEN 7 THEN '"._GARAGE7."'
			END AS garage,
			CASE hl.directionbuild 
				WHEN 1 THEN '"._DIRECTIONTYPE1."'
				WHEN 2 THEN '"._DIRECTIONTYPE2."'
				WHEN 3 THEN '"._DIRECTIONTYPE3."'
				WHEN 4 THEN '"._DIRECTIONTYPE4."'
				WHEN 5 THEN '"._DIRECTIONTYPE5."'
				WHEN 6 THEN '"._DIRECTIONTYPE6."'
				WHEN 7 THEN '"._DIRECTIONTYPE7."'
				WHEN 8 THEN '"._DIRECTIONTYPE8."'
			END AS direction_type,
			CASE hl.directionwindow 
				WHEN 1 THEN '"._DIRECTIONTYPE1."'
				WHEN 2 THEN '"._DIRECTIONTYPE2."'
				WHEN 3 THEN '"._DIRECTIONTYPE3."'
				WHEN 4 THEN '"._DIRECTIONTYPE4."'
				WHEN 5 THEN '"._DIRECTIONTYPE5."'
				WHEN 6 THEN '"._DIRECTIONTYPE6."'
				WHEN 7 THEN '"._DIRECTIONTYPE7."'
				WHEN 8 THEN '"._DIRECTIONTYPE8."'
			END AS direction_window_type,
			CAST(hl.elevatorcount AS signed) AS elevator,
			hl.publicping AS sharesize,
			hl.garageping AS reg_garagesize,
			REPLACE(REPLACE(hl.housedesc,'<BR>','。'), '　', '') AS feature_desc,
			floor AS floors,
			(
				SELECT SUM(pd.ping)
				FROM sinyi_house_sell_pingdetail as pd
				WHERE pd.ping > 0 
				AND pd.pingtype NOT IN ('1','3','4','5','H') 
				AND pd.houseno = hl.houseno
				GROUP BY pd.houseno
				ORDER BY pd.houseno
			) AS othersize,
			(
				SELECT pd.ping
				FROM sinyi_house_sell_pingdetail as pd
				WHERE pd.pingdesc = '附屬建物'
				AND pd.houseno = hl.houseno
			) AS subsize,
			(
				SELECT GROUP_CONCAT(CONCAT('".IMAGE_URL."',houseno,'/bigimg/',imgfilename) ORDER BY imgfilename) 
				FROM sinyi_house_sell_img AS hsil
				WHERE substring(imgfilename,1,1) = 'E'
				AND hsil.houseno=hl.houseno 
				AND hl.nostylepic='0'
			) AS layout_images,
			hl.balconyping AS balcony_size,
			hl.openroomcount AS chamber,
			hl.communityname AS community,
			CASE 
				WHEN CAST(hl.uniprice AS decimal(8,2)) = 0 THEN NULL 
				ELSE CAST(hl.uniprice as decimal(8,2)) 
			END AS single_price,
			CASE hl.totalpricetype 
				WHEN '1' THEN 'Y' 
				ELSE 'N' 
			END AS price_include_parking,
			REPLACE(REPLACE(hl.buildingstructure,' ',''),'　','') AS building_structure,
			REPLACE(REPLACE(hl.wallstructure,' ',''),'　','') AS wall_material,
			hl.everyfloorhouse AS floor_houses,			
			hl.hastopaddroom, 
			hl.roomcountadd, 
			hl.hallcountadd, 
			hl.bathroomcountadd, 
			hl.openroomcountadd,
			hl.entrust_type,
			DATE_FORMAT(hl.createdatetime,'%Y-%m-%d') AS createdatetime,
			hl.packageid,
			hl.medialinktype,
			hl.tag,
			hl.approval_letter_no,
			hl.approval_date
		FROM sinyi_house_sell AS hl". $object_condition;

	$object_on_sql .= "
		) houseSell LEFT JOIN (
			SELECT 
				houseno,
				(
					case 
						WHEN reservestoreno LIKE 'HX%' THEN TRIM(reservesalesno)
						ELSE
							CASE
								WHEN (TRIM(recommendagent1) <> '' AND TRIM(reservesalesno) <> '' AND TRIM(recommendagent1) <> TRIM(reservesalesno)) THEN 
								CASE recommendDefaultTab 
									WHEN 0 THEN 
										CASE  
											WHEN (SELECT count(*) FROM sinyi_sales WHERE salesno = TRIM(recommendagent1))>0 
											THEN TRIM(recommendagent1) 
											ELSE TRIM(reservesalesno) 
										END
									WHEN 1 THEN TRIM(reservesalesno) 
									WHEN 2 THEN TRIM(reservesalesno) 
									ELSE TRIM(reservesalesno)
								END 
							WHEN TRIM(recommendagent1) <> '' THEN 
								CASE
									WHEN (SELECT count(*) FROM sinyi_sales WHERE salesno = TRIM(recommendagent1))>0 
									THEN TRIM(recommendagent1) 
									ELSE TRIM(reservesalesno) 
								END
							ELSE TRIM(reservesalesno) 
						END 
					END
				) AS broker
			FROM sinyi_house_sell
		) houseSellAgentNo ON houseSell.objno = houseSellAgentNo.houseno
			LEFT JOIN sinyi_sales AS tSS ON houseSellAgentNo.broker = tSS.salesno
			LEFT JOIN sinyi_store AS tblS ON tSS.storeno = tblS.storeno" ;
	if($total !=0){
		$object_on_sql .= " LIMIT " . $total;
	}else{
		$object_on_sql .= " LIMIT " . ($page*RAKUYA_TRANSFERLIMIT) . "," . RAKUYA_TRANSFERLIMIT;
	}

	for($i=0; $i<=2; $i++){
		$object_on_rs  = $GLOBALS['adoconnweb']->Execute($object_on_sql);
		if(!$object_on_rs){
			if($i < 2) {
				if(strpos(strtolower($GLOBALS['adoconnweb']->errorMsg()), 'mysql server has gone away') !== false ) {
					createNewConnection("sinyiweb");
				}else{
						$errmsg = "storeData object_on_rs fail. sql：" . $object_on_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
						logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
						sms_error_log($errmsg, '2');
            break;
        }
      }else {	
        $errmsg = "storeData object_on_rs fail. sql：" . $object_on_sql . ". errMsg： " . $GLOBALS['adoconnweb']->errorMsg();
				logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], $errmsg, 0);
				sms_error_log($errmsg, '2');
        break;
    	}
		}else{
			break;
		}
	}

	return $object_on_rs;
}

/**
 * Company: SINYI
 * func:    storeApiLog
 * Author:  Alan Pan
 * Date:    2022.10.03
 * Description: 呼叫 API 後針對回傳結果記錄 LOG
 * Parameter:   @IN     @result：API 回傳結果
 * 						@log：整個排程的 sum log 記錄
 * 						@typeLog：此次呼叫 API 的 log 記錄
 * 						@curl_error_code：呼叫 API 的 http status code
 * 						@type：此次呼叫 API 的類型
 * 						@list：呼叫 API 物件/分店編號
 * Return:      @OUT
 * Example:     @IN
 *              storeApiLog()
 *              @OUT
 */
function storeApiLog(&$result, &$log, &$typeLog, &$curl_error_code, &$type, &$list){
	if ($result['status'] != 1) {
		$log['endtime'] = date('Y-m-d H:i:s');
		$errmsg = ' ['.$type.'] curl_errno = ' . $curl_error_code . '，' . $result['message'];// 因每一段記錄的 log 訊息不同，所以先組合好在丟進 Error_Log
		$log['errMsg'][$type][]  = $errmsg;
		$log[$type]['fail_list'][] = $list;
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], json_encode($log,JSON_UNESCAPED_UNICODE), 0);
		//20191104 將錯誤訊息寫入到 cloud_tc_sms_error_log 中
		sms_error_log(json_encode($errmsg,JSON_UNESCAPED_UNICODE));
	}else {
		$typeLog['step']              = $type;
		$typeLog['status']['total']   += $result['xml_data_count'];
		$typeLog['status']['success'] += $result['success_count'];
		$typeLog['status']['fail']    += $result['fail_count'];
		logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], json_encode($result,JSON_UNESCAPED_UNICODE), 1); //log csv
	}
}
/*
 * Func: step1_push_store
 * Description:  上傳樂屋上架分店資料
 * Parameter:   @IN
 * @pathstr string 必填 log 的檔案路徑
 * @basename string 必填 此程式的檔名
 * @log_id string 必填 log_record 的 id
 * @log array 必填 記錄 log 記錄的陣列
 * @arrpara array 必填 傳入的參數
 * @adoconnsqlite sqlite connection
 * 傳給樂屋的 json 格式
{
	"stores":[
		{
			"store_id":"S001", // string 分站編號 (PK)，最多 10 字元
			"status":"Y", // string 分店狀態 Y：有效 N：關閉
			"store_name":"樂屋不動產 大安總店", // string 分店名稱
			"store_type":1, // string 分店類型 1：直營 2：加盟 3：其他
			"company_name":"樂屋不動產", // string 品牌名稱
			"franchise_name":"PChome經紀業", // string 經紀業名稱
			"leader":"王樂屋", // string 店長姓名
			"email":"rakuya@rakuya.com.tw", // string 店長Email (需為唯一值)
			"tel":"0255526565", // string 分店電話
			"mobile":"0928863560", // string 店長 or 分店手機號碼，此手機號碼將會接收到樂屋的預約看屋簡訊通知
			"zipcode":"106",// string 郵遞區號
			"address":"敦化南路二段105號",// string 去掉行政區的路段地址 
			"lat":25.11864342145, // double 座標緯度
			"lng":121.52597194158 // double 座標經度
		}
	]
}
*/
function store_on(&$log, &$store_on_rs, &$log1)
{
	//每次拋送樂屋筆數上限
	$transferlimit = RAKUYA_TRANSFERLIMIT;
	$log_type = 'store_on_api';
	
	//上傳樂屋信義上架分店
	//sinyi_store.deptype=A 代表門市；只轉分店編號是全球的資產('BR21','FC10','FC40','FC50','R650')、代銷分店(HX開頭)與 R 開頭的分店編號 
	$result = array(); // 存放 API 回傳的結果
	if($store_on_rs){
		$arrayData=array();
		$store_list = array();
		$storecount = 0;
		while (!$store_on_rs->EOF)
		{
			$storecount++;
			$storearray = array(
				'store_id'       => $store_on_rs->fields['store_id'],		//分店編號
				'status'         => $store_on_rs->fields['status'],			//分店狀態
				'store_name'     => $store_on_rs->fields['store_name'],		//分店名稱
				'store_type'     => $store_on_rs->fields['store_type'],		//分店類型
				'franchise_name' => $store_on_rs->fields['franchise_name'],	//經紀業名稱
				'leader'         => $store_on_rs->fields['leader'],			//店長姓名
				'email'          => $store_on_rs->fields['email'],			//店長Email
				'tel'            => $store_on_rs->fields['tel'],				//分店電話
				'mobile'         => $store_on_rs->fields['mobile'],			//店長/分店手機號碼
				'zipcode'        => $store_on_rs->fields['zipcode'],			//郵遞區號 
				'address'        => str_replace(" ","",$store_on_rs->fields['address']),			//地址
				'lat'            => (double)$store_on_rs->fields['lat'],		//座標緯度
				'lng'            => (double)$store_on_rs->fields['lng']		//座標經度
			);
			$store_list[] = $store_on_rs->fields['store_id'];
			$arrayData['stores'][]=$storearray;
			if ($storecount%$transferlimit == 0) {
				//為避免cloud db連線waittimeout,所以送出一個無用的查詢
				waittimeout_query();
				waittimeout_query_database("sinyiweb");
				$curl_error_code = "";
				$API_result = rakuyaAPI('store', $arrayData, $curl_error_code);
				// echo $API_result;
				$result = json_decode($API_result,true);
				//記錄 call API 成功/失敗 LOG
				storeApiLog($result, $log, $log1, $curl_error_code, $log_type, $store_list);
				$arrayData = array();
				$store_list = array();
			}
			$store_on_rs->MoveNext();
		}
		if($arrayData && count($arrayData) != 0){
			$curl_error_code = "";
			$API_result = rakuyaAPI('store', $arrayData, $curl_error_code);

			$result = json_decode($API_result,true);
			//記錄 call API 成功/失敗 LOG
			storeApiLog($result, $log, $log1, $curl_error_code, $log_type, $store_list);
			$store_list = array();
			$arrayData = array();
		}
		
	}
}

/*
 * Func: step2_pull_store
 * Description:  樂屋下架分店資料
 * Parameter:   @IN
 * @pathstr string 必填 log 的檔案路徑
 * @basename string 必填 此程式的檔名
 * @log_id string 必填 log_record 的 id
 * @log array 必填 記錄 log 記錄的陣列
 * @arrpara array 必填 傳入的參數
 * @adoconnsqlite sqlite connection
 * 傳給樂屋的 json 格式
{
	"stores":[
		{
			"store_id":"S001", // string 分站編號 (PK)，最多 10 字元
			"status":"Y", // string 分店狀態 Y：有效 N：關閉
		}
	]
}
*/
function store_off(&$log, &$rs, &$log2)
{
	//每次拋送樂屋筆數上限
	$transferlimit = RAKUYA_TRANSFERLIMIT;
	$log_type = 'store_off_api';
	//上傳樂屋信義下架分店
	$result = array(); // 存放 API 回傳的結果
	
	if($rs){
		$storecount = 0;
		$arrayData=array();
		$store_list = array();
		while (!$rs->EOF)
		{
			$storecount++;
			
			$storearray = array(
				'store_id'       => $rs->fields['store_id'],
				'status'         => $rs->fields['status']
			);
			$store_list[] = $rs->fields['store_id'];
			$arrayData['stores'][]=$storearray;
			if ($storecount%$transferlimit == 0 && count($arrayData)>0) {
				//為避免cloud db連線waittimeout,所以送出一個無用的查詢
				waittimeout_query();
				waittimeout_query_database("sinyiweb");
				$curl_error_code = "";
				$API_result = rakuyaAPI('store', $arrayData, $curl_error_code);
				$result = json_decode($API_result,true);
				//記錄 call API 成功/失敗 LOG
				storeApiLog($result, $log, $log2, $curl_error_code, $log_type, $store_list);
				$arrayData = array();
				$store_list = array();
			}
			$rs->MoveNext();
		}
		$curl_error_code = "";
		// var_dump(count($arrayData));exit();
		if($arrayData && count($arrayData) != 0){
			$API_result = rakuyaAPI('store', $arrayData, $curl_error_code);

			$result = json_decode($API_result,true);
			//記錄 call API 成功/失敗 LOG
			storeApiLog($result, $log, $log2, $curl_error_code, $log_type, $store_list);
			$arrayData = array();
			$store_list = array();
		}
	}
}

/*
 * Func: object_on
 * Description:  上傳樂屋上架物件資料, 上架的條件是信義的物件上架10天後才能轉檔至同業，且只轉分店編號是全球的資產('BR21','FC10','FC40','FC50','R650')、代銷分店(HX開頭)與 R 開頭的分店編號，而房屋類型要不等於車位
 * Parameter:   @IN
 * @pathstr string 必填 log 的檔案路徑
 * @basename string 必填 此程式的檔名
 * @log_id string 必填 log_record 的 id
 * @log array 必填 記錄 log 記錄的陣列
 * @arrpara array 必填 傳入的參數
 * @adoconnsqlite sqlite connection
 * 傳給樂屋的 json 格式
{
	"items":[
		{
			"objno":"20181112S001", //物件編號(PK)
			"status":"Y", //物件狀態 Y:上架 N:下架 S:成交			
			"objind":"S", //租售類別 R:租屋S:售屋
			"store_id":"S001", //分店代號
			"owner_name":"樂先生", //聯絡人姓名，若未傳入將顯示分店會員資料
			"owner_tel":"" //聯絡人室內電話，若未傳入將顯示分店會員資料
			"title":"測試物件標題", // 物件名稱 最少2個中文字，最多30個中文字
			"company_id":"1" //物件所屬房仲品牌 1:信義房屋 2:太平洋房屋 3:住商不動產 4:中信房屋 5:大家房屋 6:其他專業代理人
			"company_name":"信義房屋仲介股份有限公司" // 品牌名稱
			"store_name":"" //分店名稱
			"store_type":"" //分店類型
			"franchise_name":"" //分店名稱
			"usecode":1, //出售物件形式 1:住宅 2:商用 3:車位 5:土地 6:住辦 12:廠房 4:其他
			"typecode":"R2", 
						//物件類型 R1:公寓 R2:電梯大廈 R3:套房 R4:別墅 R5:透天厝 R6:樓中樓 R7:雅房 R9:農舍 RA:華廈 B1:辦公 B2:店面 B3:廠房 B4:土地 C1:車位 L1:住宅用地 L2:商業用地 L3:工業用地 L4:建地 L5:農地 L6:林地 B5:其他
			"usecode_name":"" //用途說明，直接傳入中文字，住家用、商業用、一般事務所、工業用、廠房、住商用、住工用、工商用、國民住宅、店鋪、農舍、一般零售業、其他
			"zipcode":"116", //郵遞區號 3碼區域碼
			"address":"中山路", //去掉行政區的路段地址
			"price":13070000, // 售價或租金整數，請勿使用千分位( , )符號
			"totalsize":41.19, //登記總面積 指權狀或登記簿所載之面積（坪），若物件用途(形式)為土地也填寫與 basesize 相同之數值，四捨五入到小數點第2位
			"mainsize":""//主建物面積 指室內所使用得到的面積（坪）四捨五入到小數點第2位
			"basesize":"" //土地持分（坪）四捨五入到小數點第2位
			"surfloors":"" // 建物總樓層
			"bedrooms":"" ,	//格局(幾房 )3:三房 2:二房 1:一房 -1:開放式格局
			"bathrooms":"" ,//格局(幾衛) 
			"livingrooms":"",//格局(幾廳)
			"manage":2, //管理方式 1:無 9:有 2:管理員(警衛) 3:社區守望亭 4:保全系統 5:保全公司；簡易區分管理方式為 1: 無 或 9: 有
			"securityfee":"" // 管理費，請勿使用千分位( , )符號
			"findate":"" //完工日期(計算屋齡用) 格式為 Unix 時間戳記, 若真的沒有完工日期則可填入 0
			"lat":25.029424,//座標緯度
			"lng":121.49918,//座標經度
			"parking_type":"" //車位類型 0:無車位、1:有車位、2:租用、3:抽籤、-1:僅帶入車位敘述欄位資訊(預設值) 車位類型會顯示 -
			"garage":"" //車位敘述
			"school_desc":"" //各級學校，用半形逗點隔開
			"park_desc":"" //公園綠地
			"market_desc":"" //市場量販
			"direction_type":"" //朝向 東、西、南、北、東北、東南、西北、西南
			"elevator":"" //電梯數量
			"sharesize_include_parking":"Y", //公設是否包含車位坪數 Y:是 N:否
			"reg_garagesize":"" //車位坪數
			"feature_desc":"" //特色描述
			"floors":"" //物件所在樓層
			"thersize":"" //其它坪數
			"images":[//圖片網址為 images 子元素，提供完整照片檔案絕對路徑
				"http://img.rakuya.com/image1.jpg",
				"http://img.rakuya.com/image2.jpg"
			],
			"layout_images":[//格局圖片網址為 layout_images 子元素，提供完整照片檔案絕對路徑
				"http://img.rakuya.com/image1.jpg",
				"http://img.rakuya.com/image2.jpg"
			],
			"balcony_size":"" //陽台坪數
			"chamber":"" //格局(幾室)
			"community":"凌雲通商大樓",//社區名稱
			"single_price":31.2233, //單坪價格整數，請勿使用千分位( , )符號 (四捨五入到小數點第2位)
			"price_include_parking":"" //售價是否含車位價格 Y:是 N:否
			"building_structure":"" //建物結構敘述
			"wall_material":"" //外牆材質敘述
			"floor_houses":"" //同層戶數
			"video_url":"" // 3dvr
		}
	]
}
*/
function object_on(&$log, &$rs, &$log3)
{
	//可上架樂屋物件上限
	//$total = 60000;
	$total = RAKUYA_SEARCHTOTAL;
	//每次上架樂屋物件上限
	$transferlimit = RAKUYA_TRANSFERLIMIT;
	$log_type = 'object_on_api';

	//上傳樂屋信義上架物件

	$result = array(); // 存放 API 回傳的結果

	if($rs) {
		$housecount=0;
		$arrayData=array();
		$objecton_houseno = array();
		while (!$rs->EOF){
			// 20220505 強制轉物件銷編 06645H 到樂屋，因業務需求
			// 20221003 移至 SQL 判斷過濾
			// if($rs->fields['objno'] != '06645H' && $rs->fields['objno'] != '14171P' && $rs->fields['objno'] != '69049P'){
			// 	if($rs->fields['entrust_type'] == '2' && strtotime($rs->fields['createdatetime']) > strtotime(date('Y-m-d') .' -' . RAKUYA_UPLOAD_DAYLIMIT . ' day')){
			// 		$rs->MoveNext();
			// 		continue;
			// 	}
			// }
			$housecount++;
			$images = getImagesByHouseno($rs->fields['objno']);
			
			// 20220506 3DVR URL by Lina hu
			if($rs->fields['medialinktype'] == 1 && !empty($rs->fields['packageid'])) {
				$videoUrl = str_replace("[[packageid]]",$rs->fields['packageid'],API_123DVR_URL)."&houseno=".$rs->fields['objno']."&s=101";
			} else {
				$videoUrl = null;
			}
			// 20220512 增加特色標籤 by Lina Hu
			$tagsString = !empty(tagsToWords($rs->fields['tag'])) ? "<BR><BR>".tagsToWords($rs->fields['tag']) : "";
			$specialDesc = $rs->fields['feature_desc'].$tagsString;
			$itemarray = array(
				'objno'                 => $rs->fields['objno'],												//物件編號
				'status'                => $rs->fields['status'],												//物件狀態
				'objind'                => $rs->fields['objind'],												//租售類別
				'store_id'              => $rs->fields['store_id'],											//分店代號
				'owner_name'            => $rs->fields['owner_name'],										//聯絡人姓名
				'owner_tel'             => $rs->fields['owner_tel'],										//聯絡人室內電話
				'title'                 => $rs->fields['title'],												//物件名稱
				'company_id'            => (int)$rs->fields['company_id'],							//公司代碼
				'company_name'          => $rs->fields['company_name'],									//公司名稱
				'store_name'            => $rs->fields['store_name'],										//分店名稱
				'store_type'            => (int)$rs->fields['store_type'],							//分店類型
				'franchise_name'        => $rs->fields['franchise_name'],								//分店名稱
				'usecode'               => (int)$rs->fields['usecode'],									//出售物件形式
				'typecode'              => $rs->fields['typecode'],											//物件類型
				'obj_type'              => $rs->fields['obj_type'],											//房屋分類
				'usecode_name'          => $rs->fields['usecode_name'],									//用途說明
				'zipcode'               => $rs->fields['zipcode'],											//郵遞區號
				'address'               => $rs->fields['address'],											//地址
				'price'                 => (int)$rs->fields['price'],										//售價
				'totalsize'             => (double)$rs->fields['totalsize'],						//登記總面積
				'mainsize'              => (double)$rs->fields['mainsize'],							//主建物面積
				'basesize'              => (double)$rs->fields['basesize'],							//土地持分
				'surfloors'             => (int)$rs->fields['surfloors'],								//建物總樓層
				'bedrooms'              => (int)$rs->fields['bedrooms'],								//格局(幾房)
				'bathrooms'             => (double)$rs->fields['bathrooms'],						//格局(幾衛) 
				'livingrooms'           => (int)$rs->fields['livingrooms'],							//格局(幾廳)
				'manage'                => (int)$rs->fields['manage'],									//管理方式
				'securityfee'           => (int)$rs->fields['securityfee'],							//管理費
				'findate'               => (int)$rs->fields['findate'],									//完工日期
				'lat'                   => (double)$rs->fields['lat'],									//座標緯度
				'lng'                   => (double)$rs->fields['lng'],									//座標經度
				'parking_type'          => (int)$rs->fields['parking_type'],						//車位類型
				'garage'                => $rs->fields['garage'],												//車位敘述
				// 'school_desc'           => $rs->fields['school_desc'],								//各級學校 2019.02.01 起棄用
				// 'park_desc'             => $rs->fields['park_desc'],									//公園綠地 2019.02.01 起棄用
				// 'market_desc'           => $rs->fields['market_desc'],								//市場量販 2019.02.01 起棄用
				'direction_type'        => $rs->fields['direction_type'],								//朝向
				'elevator'              => (int)$rs->fields['elevator'],								//電梯數量
				'sharesize'             => (double)$rs->fields['sharesize'],						//公設
				'reg_garagesize'        => (double)$rs->fields['reg_garagesize'],				//車位坪數
				'feature_desc'          => $specialDesc,																//特色描述
				'othersize'             => (double)$rs->fields['othersize'],						//其它坪數
				'images'                => $images,																			//圖片網址
				'layout_images'         => explode(',',$rs->fields['layout_images']),		//格局圖片網址
				'chamber'               => (int)$rs->fields['chamber'],									//格局(幾室)
				'community'             => $rs->fields['community'],										//社區名稱
				'single_price'          => (double)$rs->fields['single_price'],					//單坪價格
				'price_include_parking' => $rs->fields['price_include_parking'],				//售價是否含車位價格
				'building_structure'    => $rs->fields['building_structure'],						//建物結構敘述
				'wall_material'         => $rs->fields['wall_material'],								//外牆材質敘述
				'floor_houses'          => (int)$rs->fields['floor_houses'],						//同層戶數
				'broker'                => str_replace(" ","",$rs->fields['broker']),		//經紀人ID
				'video_url'							=> $videoUrl, 							 													//影音網址(3dvr)
				'pre_sale_valid_number'	=> $rs->fields['approval_letter_no'] ? $rs->fields['approval_letter_no'] : '',  //預售屋核准文號
				'pre_sale_valid_date'		=> $rs->fields['approval_date'] ? $rs->fields['approval_date'] : ''     					//預售屋核准日期
			);

			//20191125 特別處理樓層 Alan Pan
			//20201006 地下室樓層處理 ex. -1、-2… Alan Pan
			if(!empty($rs->fields['floors'])){
				$floors = explode("-", converzenhan($rs->fields['floors'],$type='as'));
				if(empty(strtolower($floors[0]))){
					$itemarray['floors'] = (int)("-" . str_replace("b", "-", strtolower($floors[1])));//物件所在樓層
					if(isset($floors[2])){
						$itemarray['maxfloors'] = (int)str_replace("b", "-", strtolower($floors[2]));//物件所在樓層(最大)若樓層為區間則使用
					}
				}else{
					$itemarray['floors'] = (int)str_replace("b", "-", strtolower($floors[0]));//物件所在樓層
					if(isset($floors[1])){
						$itemarray['maxfloors'] = (int)str_replace("b", "-", strtolower($floors[1]));//物件所在樓層(最大)若樓層為區間則使用
					}
				}
			}else{
				$itemarray['floors'] = -99; //物件所在樓層(無資料)
			}
			
			// 20230525 大樓/華廈/公寓物件，改成落地窗朝向，落地窗沒有值，再沿用原先的建物朝向 BY Alan Pan
			if(in_array($rs->fields['typecode'], ["R1", "R2", "RA"] )){
				if($rs->fields['direction_window_type']){
					$itemarray['direction_type'] = $rs->fields['direction_window_type'];
				}
			}

			//20210805 新增加蓋資訊
			$roomCountAdd = $rs->fields["roomcountadd"];
			$hallCountAdd = $rs->fields["hallcountadd"];
			$bathroomCountAdd = (double)$rs->fields["bathroomcountadd"];
			$openroomCountAdd = $rs->fields["openroomcountadd"];
			$itemarray["layout_addition_desc"] = (
				((!empty($roomCountAdd)) ? (int)$roomCountAdd._OBJECT_ROOM : "").
				((!empty($hallCountAdd)) ? (int)$hallCountAdd._OBJECT_HALL : "").
				(($bathroomCountAdd != 0.0) ? (double)$bathroomCountAdd._OBJECT_BATHROOM : "").
				((!empty($openroomCountAdd)) ? (int)$openroomCountAdd._OBJECT_OPENROOM : "")
			);

			//20240806 預售屋傳附屬建物坪數，不傳陽台坪數
			if($itemarray["obj_type"] == "P") {
				$itemarray["subsize"] = (double)$rs->fields['subsize'];
			}else {
				$itemarray["balcony_size"] = (double)$rs->fields['balcony_size'];
			}
						
			$objecton_houseno[] = $rs->fields['objno'];
			$arrayData['items'][]=$itemarray;
			if ($housecount%$transferlimit == 0) {
				$curl_error_code = "";
				$API_result = rakuyaAPI('item', $arrayData, $curl_error_code);
				$arrayData=array();
				//為避免cloud db連線waittimeout,所以送出一個無用的查詢
				waittimeout_query();
				waittimeout_query_database("sinyiweb");
				$result = json_decode($API_result,true);
				//記錄 call API 成功/失敗 LOG
				storeApiLog($result, $log, $log3, $curl_error_code, $log_type, $objecton_houseno);
				// echo json_encode($log3);
				$objecton_houseno = array();
				// $housecount = 0;
			}
			//為避免cloud db連線waittimeout,所以送出一個無用的查詢
			waittimeout_query();
			waittimeout_query_database("sinyiweb");
			$rs->MoveNext();
		}

		if ($housecount%$transferlimit != 0) {
			$curl_error_code = "";
			$API_result = rakuyaAPI('item', $arrayData, $curl_error_code);
			//為避免cloud db連線waittimeout,所以送出一個無用的查詢
			waittimeout_query();
			waittimeout_query_database("sinyiweb");
			$result = json_decode($API_result,true);

			//記錄 call API 成功/失敗 LOG
			storeApiLog($result, $log, $log3, $curl_error_code, $log_type, $objecton_houseno);
			$objecton_houseno = array();
			$arrayData=array();
		}
	}
}

/*
 * Func: 物件下架
 * Description:  樂屋下架物資料
 * Parameter:   @IN
 * @pathstr string 必填 log 的檔案路徑
 * @basename string 必填 此程式的檔名
 * @log_id string 必填 log_record 的 id
 * @log array 必填 記錄 log 記錄的陣列
 * @arrpara array 必填 傳入的參數
 * @adoconnsqlite sqlite connection
 * @blacklist_houseno_arr array cloud 後台手動下架的物件清單
 * @common_business array 同業的 api 網址與 id
*/
//上傳樂屋下架物件資料
function object_off(&$log, &$object_off_rs, &$common_business, &$log4)
{	
	//每次拋送樂屋筆數上限
	$transferlimit = RAKUYA_TRANSFERLIMIT;
	$log_type = 'object_off_api';
	$curl_error_code = "";
	$objectoff_houseno = array(); // 存要下架的物件銷編
	
	// START ------ 處理要下架的物件 ------ START
	$objectoff_houseno=array(); // 初始化存要下架的物件銷編，以存放 SQLite 要下架的物件
	$object_result = array(); // 存放 API 回傳的結果
	if($object_off_rs){	
		if($object_off_rs->RecordCount()>0){ // 有需要下架的記錄才跑
			$objectcount = 0;
			while (!$object_off_rs->EOF){
				$objectcount++;
				array_push($objectoff_houseno, $object_off_rs->fields['house_no']); // 將要下架的物件銷編存進陣列，以方便傳到 common/lib 中
				if ($objectcount%$transferlimit == 0) {
					//為避免cloud db連線waittimeout,所以送出一個無用的查詢
					waittimeout_query();
					waittimeout_query_database("sinyiweb");
					// 將 rakuya 的 commonid 與 DB 中要下架的物件銷編以陣列的型態傳到 partnerSinyiObjectOff 中，此 function 為下架物件的共同 lib function
					$object_result = partnerSinyiObjectOff(array(RAKUYA_COMMONID => $objectoff_houseno),$common_business);
					//記錄 call API 成功/失敗 LOG
					$result = $object_result[RAKUYA_COMMONID];
					storeApiLog($result['message'], $log, $log4, $curl_error_code, $log_type, $objectoff_houseno);
					$objectoff_houseno = array();
				}
				$object_off_rs->MoveNext();
			}
		}

		if(count($objectoff_houseno)>0){
			//為避免cloud db連線waittimeout,所以送出一個無用的查詢
			waittimeout_query();
			waittimeout_query_database("sinyiweb");
			// 將 rakuya 的 commonid 與 DB 中要下架的物件銷編以陣列的型態傳到 partnerSinyiObjectOff 中，此 function 為下架物件的共同 lib function
			$object_result = partnerSinyiObjectOff(array(RAKUYA_COMMONID => $objectoff_houseno),$common_business);
			//記錄 call API 成功/失敗 LOG
			$result = $object_result[RAKUYA_COMMONID];
			storeApiLog($result['message'], $log, $log4, $curl_error_code, $log_type, $objectoff_houseno);
			$objectoff_houseno = array();
		}		
	}
	// END ------ 處理要下架的物件 ------ END
}
/*
 * Function: rakuyaAPI
 * Description:上架分店、上架物件、下架分店呼叫樂屋的API
 * Parameter:   @IN
 * @url_type string 必填 呼叫樂屋API的type (store分店/item物件)
 * @arrayData array 必填 要 post 到樂屋的資料
 * @curl_error_code 必填 string curl 發生錯誤時回傳的錯誤代碼
 *
 * @OUT
 * 		@result	json api回傳的資料
 */
function rakuyaAPI($url_type, &$arrayData,&$curl_error_code)
{
	//樂屋API相關資料
	$store_url = RAKUYA_URL . "/store";
	$item_url = RAKUYA_URL . "/item";
	$rakuya_company = _RAKUYACOMPANY;
	$rakuya_key = _RAKUYAKEY;

	if ($url_type=='store') {
		$url=$store_url;
	} else {
		$url=$item_url;
	}
	$jsonData = array(
        "company"         => $rakuya_company,	
        "key"             => $rakuya_key,
        "json"            =>  json_encode($arrayData,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
    );
	// var_dump($jsonData);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_URL,$url);
	curl_setopt($ch, CURLOPT_POST, true); // 啟用POST
	curl_setopt($ch, CURLOPT_POSTFIELDS,  $jsonData);		
	$result = curl_exec($ch);
	if(curl_errno($ch)){
    	$curl_error_code = curl_errno($ch);
	}
	curl_close($ch);
	unset($jsonData);
	return $result;
}
/*
 * Function: sms_error_log
 * Description:記錄轉檔中發生的錯誤訊息
 * Parameter:   @IN
 * @errmsg string 必填 錯誤訊息
 */
function sms_error_log(&$errmsg,$sms_error_type=RAKUYA_SMSERRORTYPE){
	try{
		if(!empty($sms_error_type)){
			$sms_error_log_parameter=[];
			$sms_error_log_sql = "INSERT INTO ".ADOPREFIX."_sms_error_log (createdatetime,type,errmsg,is_send) value";
			$sms_error_type = explode(",",$sms_error_type);
			foreach ($sms_error_type as $key => $value) {
				$sms_error_log_sql .= "(?,?,?,?)";
				$sms_error_log_parameter[] = date("Y-m-d H:i:s");
				$sms_error_log_parameter[] = $value;
				$sms_error_log_parameter[] = $errmsg;
				$sms_error_log_parameter[] = 0;
				if ($value !== end($sms_error_type)) $sms_error_log_sql .= ", ";
			}
		}
		$rs = $GLOBALS['adoconn_m']->Execute($sms_error_log_sql, $sms_error_log_parameter);
		if(!$rs){
			logcsv('log/' . $GLOBALS["logname"], $GLOBALS["logname"], "insert sms_error_log fail. errorMsg => " . $GLOBALS['adoconn_m']->errorMsg(), 0); //log csv
		}
	} catch (Exception $e) {
        //
    }
}
/**
 * Function: tagsToWords
 * Description:將特色標籤轉成中文字
 * Parameter:   @IN
 * @errmsg string 必填 錯誤訊息
 */
function tagsToWords($tags){
	$tagString = [];
	$tagsList = explode(',', $tags);
	if(sizeof($tagsList) > 0) {
		$titleString .= _TAGDEFINE;
		foreach($tagsList as $tag) {
			switch($tag) {
				case 1:
				case "1":
					$tagString[] = _TAG01;
					break;
				case 2:
				case "2":
					$tagString[] = _TAG02;
					break;
				case 3:
				case "3":
					$tagString[] = _TAG03;
				break;
				case 4:
				case "4":
					$tagString[] = _TAG04;
				break;
				case 5:
				case "5":
					$tagString[] = _TAG05;
				break;
				case 6:
				case "6":
					$tagString[] = _TAG06;
				break;
				case 7:
				case "7":
					$tagString[] = _TAG07;
				break;
				case 8:
				case "8":
					$tagString[] = _TAG08;
				break;
				case 9:
				case "9":
					$tagString[] = _TAG09;
				break;
				case 10:
				case "10":
					$tagString[] = _TAG10;
					break;
				case 11:
				case "11":
					$tagString[] = _TAG11;
					break;
				case 12:
				case "12":
					$tagString[] = _TAG12;
					break;
				case 13:
				case "13":
					$tagString[] = _TAG13;
					break;
				case 14:
				case "14":
					$tagString[] = _TAG14;
					break;
				case 15:
				case "15":
					$tagString[] = _TAG15;
					break;
				case 16:
				case "16":
					$tagString[] = _TAG16;
					break;
				case 17:
				case "17":
					$tagString[] = _TAG17;
					break;
				case 18:
				case "18":
					$tagString[] = _TAG18;
					break;
				case 19:
				case "19":
					$tagString[] = _TAG19;
					break;
				case 20:
				case "20":
					$tagString[] = _TAG20;
					break;
				case 21:
				case "21":
					$tagString[] = _TAG21;
					break;
				case 22:
				case "22":
					$tagString[] = _TAG22;
					break;																																	
			}
		}
		if($tagString){
			$tagString = implode(_COMMA, $tagString);
		}else{
			$tagString = "";
		}
	}
	if(!empty($tagString)) {$result = $titleString.$tagString;}
	else { $result = "";}
	return $result;
}


function getImagesByHouseno($houseno) {
	$result = [];
	$sql = "SELECT CONCAT(houseno, '/bigimg/', imgfilename) as imageurl FROM sinyi_house_sell_img as hsil
	where substring(imgfilename,1,1) <> 'E'
	and hsil.houseno=?";
	$rs = $GLOBALS['adoconnweb']->Execute($sql, [$houseno]);
	if($rs) {
		while(!$rs->EOF) {
			$result[] = IMAGE_URL.$rs->fields['imageurl'];
			$rs->MoveNext();
		}
	}

	return $result;
}
?>