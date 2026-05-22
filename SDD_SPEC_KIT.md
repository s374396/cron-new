# CRON-NEW 軟體設計文件 (Software Design Document)

**文件版本:** 1.0  
**最後更新:** 2026-05-22  
**作者:** Copilot  
**專案:** cron-new (信義房屋 → 樂屋房產同步系統)  
**語言:** 100% PHP  

---

## 目錄

1. [系統概述](#系統概述)
2. [需求規格](#需求規格)
3. [架構設計](#架構設計)
4. [詳細設計](#詳細設計)
5. [模組設計](#模組設計)
6. [數據流設計](#數據流設計)
7. [資料庫設計](#資料庫設計)
8. [API 設計](#api-設計)
9. [異常處理](#異常處理)
10. [效能考量](#效能考量)

---

## 1. 系統概述

### 1.1 系統目的

CRON-NEW 系統是一個自動化的房產數據同步引擎,用於將信義房屋平台的房產及門市信息實時同步至樂屋房產網平台,實現跨平台的房產資訊一體化管理。

### 1.2 系統範圍

- **數據來源:** 信義房屋內部資料庫 (Sinyi Web DB)
- **數據目標:** 樂屋房產網 API
- **同步對象:** 房產 (Property/Object) 及門市 (Store/Branch)
- **執行方式:** 命令行排程任務 (Cron Job)
- **部署環境:** Linux 伺服器 (PHP-CLI)

### 1.3 系統特性

| 特性 | 說明 |
|-----|------|
| **可靠性** | 主從資料庫自動轉移、連線自動重連 |
| **可擴展性** | 分頁批處理、支持大規模數據同步 |
| **可維護性** | 模組化設計、完整日誌記錄 |
| **安全性** | 資料加密、API 金鑰管理、異常通知 |
| **效能** | 批��處理、連線池管理、查詢最佳化 |

---

## 2. 需求規格

### 2.1 功能需求

#### FR1: 門市信息同步
- **FR1.1** 系統應支持將信義活躍門市上架至樂屋
- **FR1.2** 系統應支持將已停業門市從樂屋下架
- **FR1.3** 系統應支持選擇性同步特定門市

#### FR2: 房產信息同步
- **FR2.1** 系統應支持將符合條件的房產上架至樂屋
- **FR2.2** 系統應支持將已下架/過期房產從樂屋移除
- **FR2.3** 系統應支持房產轉移門市時的重新上架
- **FR2.4** 系統應支持預售屋的特殊處理及執照期限監控

#### FR3: 數據篩選與轉換
- **FR3.1** 系統應根據房產年齡自動判定新屋/中古屋/預售屋分類
- **FR3.2** 系統應自動轉換房產朝向 (建物朝向 vs. 落地窗朝向)
- **FR3.3** 系統應過濾黑名單房產
- **FR3.4** 系統應自動計算多層房產的最大/最小樓層

#### FR4: 日誌與監控
- **FR4.1** 系統應記錄每次同步的詳細日誌
- **FR4.2** 系統應在錯誤發生時發送簡訊通知
- **FR4.3** 系統應保留 7 天內的同步記錄
- **FR4.4** 系統應防止重複執行同一排程

### 2.2 非功能需求

#### NFR1: 效能需求
- **NFR1.1** 單次同步應在 30 分鐘內完成 (預計 10,000+ 房產)
- **NFR1.2** 數據庫查詢回應時間 < 5 秒
- **NFR1.3** API 調用逾時設定為 90 秒

#### NFR2: 可靠性需求
- **NFR2.1** 系統可用性 ≥ 99.5%
- **NFR2.2** 資料庫連線中斷時自動轉移到從庫
- **NFR2.3** API 調用失敗應重試機制

#### NFR3: 安全性需求
- **NFR3.1** 敏感資料使用 AES-256-CBC 加密
- **NFR3.2** API 金鑰應存儲在安全的配置檔中
- **NFR3.3** 所有 API 通訊應使用 HTTPS (SSL/TLS)

#### NFR4: 可維護性需求
- **NFR4.1** 代碼應遵循 PHP 編碼標準
- **NFR4.2** 關鍵函數應包含詳細註釋
- **NFR4.3** 配置項應集中管理

---

## 3. 架構設計

### 3.1 系統架構

```
┌─────────────────────────────────────────────────────────────┐
│                    Cron Scheduler (Linux)                    │
│                  (每日/每小時執行排程)                       │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
        ┌──────────────────────┐
        │  Sinyi2Rakuya.php    │
        │  (主執行程序)        │
        └──────────┬───────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ▼                     ▼
┌──────────────────┐  ┌──────────────────┐
│  mainfile.php    │  │ config.php       │
│  (共用函式庫)    │  │ (設定檔)         │
└──────────┬───────┘  └──────────────────┘
        │
     ┌──┴──┬────────┬─────────┐
     │     │        │         │
     ▼     ▼        ▼         ▼
┌─────┐┌───────┐┌────────┐┌────────┐
│Cloud││Sinyi  ││Sinyi   ││其他    │
│ DB  ││Web DB ││Rent DB ││Support │
│     ││       ││        ││DB      │
└─────┘└───────┘└────────┘└────────┘
     │
     └─────────┬──────────────┐
               │              │
               ▼              ▼
          ┌─────────┐    ┌─────────┐
          │ Logs    │    │ Error   │
          │ (CSV)   │    │ Logs    │
          └─────────┘    │ (SMS)   │
                         └─────────┘
                              │
                              ▼
                         ┌──────────┐
                         │  樂屋API  │
                         │ (Rakuya) │
                         └──────────┘
```

### 3.2 分層架構

```
┌─────────────────────────────────────┐
│   表現層 (Presentation Layer)        │
│  - 命令行參數解析                    │
│  - 日誌輸出格式化                    │
└────────────────┬────────────────────┘
                 │
┌────────────────▼────────────────────┐
│   業務邏輯層 (Business Logic Layer)  │
│  - storeData()        - 數據準備     │
│  - store_on()         - 門市上架     │
│  - store_off()        - 門市下架     │
│  - object_on()        - 房產上架     │
│  - object_off()       - 房產下架     │
│  - getImagesByHouse() - 圖片蒐集     │
└────────────────┬────────────────────┘
                 │
┌────────────────▼────────────────────┐
│   數據訪問層 (Data Access Layer)     │
│  - rakuyaAPI()        - API 調用     │
│  - SQL 查詢執行        - 數據查詢     │
│  - 連線管理            - 連線池管理   │
└────────────────┬────────────────────┘
                 │
┌────────────────▼────────────────────┐
│   基礎設施層 (Infrastructure Layer)  │
│  - ADODB 連線         - 資料庫連線   │
│  - CURL HTTP          - HTTP 通訊    │
│  - File I/O           - 日誌檔案     │
└─────────────────────────────────────┘
```

### 3.3 模組組織

```
cron-new/
├── Sinyi2Rakuya.php          # 主程序入口
├── mainfile.php              # 共用函式庫
├── config.php                # 配置檔 (外部)
├── language/
│   ├── Sinyi2Rakuya-tc.php   # 中文語言包
│   └── sinyi2common-tc.php    # 共用語言包
├── lib/
│   ├── cronlog.php            # 排程日誌
│   ├── logcsv.inc.php         # CSV 日誌
│   ├── checkprocess.inc.php   # 進程檢查
│   └── performanceTimeMonitor.php  # 效能監控
├── adodb5/                    # ADODB 函式庫
├── log/
│   └── Sinyi2Rakuya/          # 日誌目錄
└── config/
    └── dbconn/
        └── Sinyi2Rakuya.inc.php  # 資料庫配置

```

---

## 4. 詳細設計

### 4.1 程序流程

#### 4.1.1 初始化階段

```php
初始化流程:
1. 包含配置文件 (config.php)
2. 包含語言文件 (Sinyi2Rakuya-tc.php)
3. 包含共用函式 (mainfile.php)
4. 建立資料庫連線:
   - Cloud DB (Master & Slave)
   - Sinyi Web DB (Master & Slave)
   - Log DB
   - 其他支持資料庫
5. 檢查進程鎖: 防止重複執行
6. 解析命令行參數 (-type, -no)
7. 初始化日誌系統
```

#### 4.1.2 數據準備階段

```php
storeData(&$arrpara, &$object_condition):
  ├─ 插入要上架的門市到臨時表
  ├─ 查詢要上架的門市 (store_on_rs)
  ├─ 查詢要下架的門市 (store_off_rs)
  ├─ 插入要上架的房產到臨時表
  ├─ 查詢要上架的房產總數 (object_total_rs)
  └─ 查詢要下架的房產 (object_off_rs)
     ├─ 查詢已下架的房產
     ├─ 查詢黑名單房產
     ├─ 查詢轉移門市的房產
     └─ 查詢預售屋執照過期的房產
```

#### 4.1.3 數據同步階段

```
執行順序 (依據 -type 參數):
1. 門市下架 (Type 2)   [store_off()]
2. 門市上架 (Type 1)   [store_on()]
3. 房產下架 (Type 4)   [object_off()]
4. 房產上架 (Type 3)   [object_on()]
   └─ 分頁循環: 每 RAKUYA_TRANSFERLIMIT 筆記錄調用一次 API
```

#### 4.1.4 結束階段

```php
程序終止流程:
1. 刪除 7 天前的同步記錄
2. 輸出最終日誌 (JSON 格式)
3. 更新 log_record 資料庫
4. 寫入 CSV 日誌
5. 發送簡訊通知 (如有錯誤)
6. 關閉所有資料庫連線
7. 程序結束
```

### 4.2 關鍵函數設計

#### 4.2.1 storeData()

**功能:** 準備所有需要同步的數據

**簽名:**
```php
function storeData(&$arrpara, &$object_condition)
```

**參數:**
- `$arrpara`: 包含 type、no 的參數陣列
- `$object_condition`: 房產查詢條件字符串

**返回值:**
```php
array(
    "store_on_rs" => RecordSet,      // 要上架的門市
    "store_off_rs" => RecordSet,     // 要下架的門市
    "object_total_rs" => RecordSet,  // 房產總數
    "object_off_rs" => RecordSet     // 要下架的房產
)
```

**關鍵邏輯:**
```sql
-- 門市上架條件
WHERE ( deptype = 'A' AND storeno like 'R%' ) 
   OR storeno LIKE 'HX%' 
   OR storeno IN ('BR21','FC10','FC40','FC50','R650','FC60','FC70','FC80','FCA0')

-- 房產上架條件
WHERE hl.status = 1 
  AND hl.houseinc = 1 
  AND hl.roadname <> '' 
  AND ((hl.houselandtype1 <> 'I' AND hl.buildingmainping > 0) 
       OR (hl.houselandtype1 = 'I' AND hl.buildingmainping = 0)) 
  AND (SELECT COUNT(*) FROM sinyi_house_sell_img WHERE ...) > 2
  AND hl.houseno NOT IN(SELECT houseno FROM sinyi_house_sell_common_blacklist)
```

#### 4.2.2 getObjectData()

**功能:** 批次取得房產數據 (支持分頁)

**簽名:**
```php
function getObjectData(&$arrpara, &$object_condition, &$page=0)
```

**參數:**
- `$arrpara`: 執行參數
- `$object_condition`: 查詢條件
- `$page`: 頁碼 (0 起始)

**返回值:** 房產 RecordSet

**分頁邏輯:**
```php
$total_page = ceil($object_total_rs->fields['totalRow'] / RAKUYA_TRANSFERLIMIT);
for ($i=0; $i<$total_page; $i++) {
    $object_on_rs = getObjectData($arrpara, $object_condition, $i);
    object_on($log, $object_on_rs, $log3);
}
```

#### 4.2.3 object_on()

**功能:** 上架房產至樂屋

**簽名:**
```php
function object_on(&$log, &$rs, &$log3)
```

**核心邏輯:**
```php
1. 遍歷 RecordSet 中的每筆房產
2. 為每筆房產構建 JSON 對象:
   - 基本信息 (編號、標題、價格)
   - 位置信息 (地址、座標)
   - 建築信息 (面積、房數、樓層)
   - 影像信息 (相片、格局圖、3D VR)
   - 特殊信息 (預售屋執照、標籤)
3. 每 RAKUYA_TRANSFERLIMIT 筆記錄調用一次 rakuyaAPI()
4. 記錄 API 回應與錯誤
```

**房產分類邏輯:**
```php
CASE 
    WHEN hl.objectype='4' THEN 'P'           // 預售屋
    WHEN hl.objectype='1' AND hl.houseage <= 3 THEN 'N'  // 新屋
    ELSE 'O'                                 // 中古屋
END AS obj_type
```

**朝向選擇邏輯:**
```php
// 大樓/華廈/公寓: 優先使用落地窗朝向
IF(in_array($rs->fields['typecode'], ["R1", "R2", "RA"])) {
    IF($rs->fields['direction_window_type']) {
        $itemarray['direction_type'] = $rs->fields['direction_window_type'];
    }
}
```

#### 4.2.4 rakuyaAPI()

**功能:** 調用樂屋 API

**簽名:**
```php
function rakuyaAPI($url_type, &$arrayData, &$curl_error_code)
```

**參數:**
- `$url_type`: 'store' 或 'item'
- `$arrayData`: 要傳送的數據陣列
- `$curl_error_code`: 回傳的錯誤碼

**HTTP 配置:**
```php
CURLOPT_SSL_VERIFYPEER = false    // 允許自簽證書
CURLOPT_RETURNTRANSFER = true     // 返回回應內容
CURLOPT_POST = true               // POST 方法
```

**請求格式:**
```php
POST /store 或 /item HTTP/1.1
Content-Type: application/x-www-form-urlencoded

{
    "company": "RAKUYA_COMPANY_ID",
    "key": "RAKUYA_API_KEY",
    "json": "{...}"  // JSON 序列化
}
```

**回應處理:**
```php
$result = json_decode($API_result, true);
IF($result['status'] != 1) {
    // 記錄錯誤
    logcsv(...);
    sms_error_log(...);
} ELSE {
    // 更新計數器
    $typeLog['status']['total'] += $result['xml_data_count'];
    $typeLog['status']['success'] += $result['success_count'];
    $typeLog['status']['fail'] += $result['fail_count'];
}
```

---

## 5. 模組設計

### 5.1 Sinyi2Rakuya.php (主程序)

| 責任 | 說明 |
|-----|------|
| **參數解析** | 解析 -type 和 -no 命令行參數 |
| **流程控制** | 協調各個同步操作的執行順序 |
| **異常捕捉** | 捕捉並記錄所有異常 |
| **日誌管理** | 輸出最終同步結果 |

**主要函數:**
- `deleteOldData()` - 刪除 7 天前的記錄
- `storeData()` - 準備同步數據
- `store_on()` - 上架門市
- `store_off()` - 下架門市
- `object_on()` - 上架房產
- `object_off()` - 下架房產

### 5.2 mainfile.php (共用函式庫)

| 責任 | 說明 |
|-----|------|
| **資料庫連線** | 初始化所有資料庫連線 |
| **進程管理** | 檢查重複執行 |
| **日誌系統** | 記錄排程執行狀態 |
| **連線維護** | 防止連線逾時 |
| **加密解密** | 敏感數據保護 |

**主要函數:**
- `createNewConnection()` - 建立新連線
- `waittimeout_query()` - 防止連線逾時
- `logFormatId()` - 取得日誌格式 ID
- `encryptSensitiveData()` - 加密敏感數據
- `commonWriteSmsErrorLog()` - 寫入簡訊錯誤日誌

### 5.3 共用函式庫 (lib 目錄)

| 檔案 | 責任 |
|-----|------|
| **cronlog.php** | 排程日誌記錄 |
| **logcsv.inc.php** | CSV 日誌輸出 |
| **checkprocess.inc.php** | 進程重複執行檢查 |
| **performanceTimeMonitor.php** | 效能監控 |

---

## 6. 數據流設計

### 6.1 門市同步數據流

```
信義房屋內部 DB
    │
    ▼
[SELECT 門市資料]
    │
    ├─→ 插入臨時表 (sinyi_rakuya)
    │
    ▼
[篩選條件判斷]
    │
    ├─→ 門市編號匹配全球資產 ✓
    ├─→ 非重複記錄 ✓
    │
    ▼
[構建 JSON 對象]
    │
    ├─→ 門市ID
    ├─→ 狀態 (Y/N)
    ├─→ 名稱、類型、經紀業
    ├─→ 店長、聯絡信息
    ├─→ 地址、座標
    │
    ▼
[批次堆積 (每 RAKUYA_TRANSFERLIMIT 筆)]
    │
    ▼
[調用樂屋 API]
    │
    ├─→ CURL POST 請求
    ├─→ 等待回應
    │
    ▼
[記錄結果]
    │
    ├─→ 成功計數
    ├─→ 失敗計數
    ├─→ 錯誤信息
    │
    ▼
樂屋房產網
```

### 6.2 房產同步數據流

```
信義房屋內部 DB
    │
    ▼
[複雜 SQL 查詢 (多表 JOIN)]
    │
    ├─→ sinyi_house_sell (基本房產)
    ├─→ sinyi_store (門市信息)
    ├─→ sinyi_sales (銷售人員)
    ├─→ sinyi_house_sell_img (圖片)
    ├─→ sinyi_house_sell_pingdetail (面積明細)
    │
    ▼
[多條件篩選]
    │
    ├─→ 房產狀態 = 上架中 ✓
    ├─→ 包含在交易清單內 ✓
    ├─→ 有有效路名 ✓
    ├─→ 圖片數量 > 2 ✓
    ├─→ 未在黑名單 ✓
    ├─→ 門市編號符合 ✓
    │
    ▼
[計算總筆數]
    │
    └─→ object_total_rs
    
[分頁循環]
    │
    ├─→ 每頁 RAKUYA_TRANSFERLIMIT 筆
    │
    ▼ (for each page)
[字段映射與轉換]
    │
    ├─→ 房產編號、狀態、標題
    ├─→ 價格計算 (萬元 × 10000)
    ├─→ 面積計算 (坪數)
    ├─→ 房屋分類 (新/中古/預售)
    ├─→ 朝向選擇 (建物/落地窗)
    ├─→ 圖片蒐集 (IMAGE_URL + 圖片檔名)
    ├─→ 格局圖過濾 (nostylepic = 0)
    ├─→ 加蓋資訊組合
    ├─→ 特色標籤轉換 (tag ID → 中文)
    ├─→ 3D VR URL 生成
    │
    ▼
[構建房產 JSON 對象]
    │
    ├─→ 40+ 個房產欄位
    │
    ▼
[批次堆積]
    │
    └─→ 當計數達到 RAKUYA_TRANSFERLIMIT
    
    ▼
[調用樂屋 API]
    │
    ├─→ CURL POST (JSON)
    ├─→ 等待回應
    │
    ▼
[記錄結果]
    │
    ├─→ 成功筆數
    ├─→ 失敗筆數
    └─→ 錯誤詳情

[repeat for each page]
    
    ▼
樂屋房產網 (更新/新增房產)
```

### 6.3 房產下架數據流

```
[查詢要下架的房產]
    │
    ├─ UNION 1: 已下架的房產
    ├─ UNION 2: 黑名單中的房產
    ├─ UNION 3: 轉移門市的房產
    ├─ UNION 4: 預售屋執照過期的房產
    │
    ▼
[批次堆積 (每 RAKUYA_TRANSFERLIMIT 筆)]
    │
    ▼
[調用樂屋 API (下架)]
    │
    ├─→ 傳送房產編號
    ├─→ 設定狀態為 'N' (下架)
    │
    ▼
[記錄結果]
    │
    ▼
樂屋房產網 (移除/下架房產)
```

---

## 7. 資料庫設計

### 7.1 核心表格

#### 7.1.1 sinyi_rakuya (同步記錄表)

**用途:** 記錄已同步的門市和房產,用於增量同步判斷

**結構:**
```sql
CREATE TABLE sinyi_rakuya (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type INT NOT NULL,           -- 1:門市, 2:房產
    house_no VARCHAR(20),        -- 房產編號 (type=2 時填入)
    store_no VARCHAR(20),        -- 門市編號
    send_date DATE NOT NULL,     -- 同步日期
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_date (type, send_date),
    INDEX idx_house_no (house_no),
    INDEX idx_store_no (store_no)
);
```

**查詢示例:**
```sql
-- 查詢今天已同步的門市
SELECT house_no FROM sinyi_rakuya 
WHERE type=1 AND send_date=CURDATE();

-- 查詢要下架的門市 (昨天同步但今天未同步)
SELECT house_no FROM sinyi_rakuya 
WHERE type=1 AND send_date < CURDATE() 
  AND house_no NOT IN (
    SELECT house_no FROM sinyi_rakuya 
    WHERE type=1 AND send_date = CURDATE()
  );
```

#### 7.1.2 sinyi_house_sell_common_blacklist (黑名單表)

**用途:** 管理不允許轉檔至同業的房產

**結構:**
```sql
CREATE TABLE sinyi_house_sell_common_blacklist (
    id INT PRIMARY KEY AUTO_INCREMENT,
    houseno VARCHAR(20) NOT NULL,
    commonid INT NOT NULL,         -- 同業 ID
    enable INT DEFAULT 1,          -- 1:啟用, 0:停用
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_houseno_commonid (houseno, commonid)
);
```

#### 7.1.3 cloud_tc_sms_error_log (簡訊錯誤日誌表)

**用途:** 記錄排程執行中的錯誤,用於發送簡訊通知

**結構:**
```sql
CREATE TABLE cloud_tc_sms_error_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    createdatetime DATETIME NOT NULL,
    type INT NOT NULL,             -- 錯誤類型 ID
    errmsg TEXT,                   -- 錯誤訊息
    is_send INT DEFAULT 0,         -- 0:未發, 1:已發
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_issend (type, is_send)
);
```

### 7.2 查詢最佳化

#### 7.2.1 索引設計

```sql
-- 房產查詢優化
CREATE INDEX idx_status_houseinc ON sinyi_house_sell (status, houseinc);
CREATE INDEX idx_reservestoreno ON sinyi_house_sell (reservestoreno);
CREATE INDEX idx_objectype_houseage ON sinyi_house_sell (objectype, houseage);

-- 門市查詢優化
CREATE INDEX idx_deptype_storeno ON sinyi_store (deptype, storeno);

-- 圖片查詢優化
CREATE INDEX idx_houseno_imgfilename ON sinyi_house_sell_img (houseno, imgfilename);
```

#### 7.2.2 查詢執行計畫

**房產查詢 (最耗時):**
```
Execution Plan:
1. 掃描 sinyi_house_sell (過濾條件)
2. 根據 status、houseinc 使用索引
3. 與 sinyi_store 進行 JOIN
4. 與 sinyi_sales 進行 JOIN
5. 與 sinyi_house_sell_img 進行子查詢
6. 應用 LIMIT 和 ORDER BY
```

---

## 8. API 設計

### 8.1 樂屋 API 規範

#### 8.1.1 端點定義

| 端點 | 方法 | 用途 | 認証 |
|------|------|------|------|
| `/store` | POST | 門市上下架 | company + key |
| `/item` | POST | 房產上下架 | company + key |

#### 8.1.2 門市上架請求

**URL:** `{RAKUYA_URL}/store`

**HTTP Method:** `POST`

**Content-Type:** `application/x-www-form-urlencoded`

**請求體:**
```json
{
  "company": "RAKUYA_COMPANY_ID",
  "key": "RAKUYA_API_KEY",
  "json": {
    "stores": [
      {
        "store_id": "BR21",
        "status": "Y",
        "store_name": "信義房屋 新竹門市",
        "store_type": 1,
        "company_name": "信義房屋",
        "franchise_name": "PChome經紀業",
        "leader": "王店長",
        "email": "s001@sinyi.com.tw",
        "tel": "0355123456",
        "mobile": "0912345678",
        "zipcode": "300",
        "address": "新竹市東區中山路100號",
        "lat": 24.8123,
        "lng": 120.9456
      }
    ]
  }
}
```

**成功回應 (200 OK):**
```json
{
  "status": 1,
  "message": "Success",
  "xml_data_count": 1,
  "success_count": 1,
  "fail_count": 0,
  "fail_list": []
}
```

**失敗回應:**
```json
{
  "status": 0,
  "message": "API Key 驗証失敗",
  "error_code": "AUTH_FAILED"
}
```

#### 8.1.3 房產上架請求

**URL:** `{RAKUYA_URL}/item`

**HTTP Method:** `POST`

**請求體結構:**
```json
{
  "company": "RAKUYA_COMPANY_ID",
  "key": "RAKUYA_API_KEY",
  "json": {
    "items": [
      {
        "objno": "12345A",
        "status": "Y",
        "objind": "S",
        "title": "精美 3 房大套房",
        "company_id": 1,
        "company_name": "信義房屋",
        "store_id": "BR21",
        "store_name": "新竹門市",
        "usecode": 1,
        "typecode": "R2",
        "obj_type": "O",
        "zipcode": "300",
        "address": "中山路 100 號",
        "price": 5000000,
        "totalsize": 35.5,
        "mainsize": 28.2,
        "basesize": 35.5,
        "surfloors": 20,
        "bedrooms": 3,
        "bathrooms": 2,
        "livingrooms": 1,
        "manage": 9,
        "lat": 24.8123,
        "lng": 120.9456,
        "images": [
          "http://image.url/12345A/bigimg/001.jpg",
          "http://image.url/12345A/bigimg/002.jpg"
        ],
        "layout_images": [
          "http://image.url/12345A/bigimg/E001.jpg"
        ],
        "community": "中山大樓",
        "single_price": 140.85,
        "pre_sale_valid_number": "核准文號",
        "pre_sale_valid_date": "2026-05-22"
      }
    ]
  }
}
```

**房產欄位說明:**

| 欄位 | 類型 | 說明 | 範例 |
|------|------|------|------|
| objno | String | 房產編號 (PK) | "12345A" |
| status | String | 狀態 (Y/N) | "Y" |
| objind | String | 租售類別 (R/S) | "S" |
| obj_type | String | 房屋分類 (P/N/O) | "O" |
| price | Integer | 售價 (無千分位) | 5000000 |
| totalsize | Double | 登記總面積 (坪) | 35.50 |
| mainsize | Double | 主建物面積 (坪) | 28.20 |
| surfloors | Integer | 建物總樓層 | 20 |
| bedrooms | Integer | 房間數 | 3 |
| bathrooms | Double | 衛浴數 | 2.0 |
| floors | Integer | 所在樓層 | 5 |
| maxfloors | Integer | 最大樓層 (多層用) | 8 |
| single_price | Double | 單坪價格 | 140.85 |

---

## 9. 異常處理

### 9.1 異常分類

#### 9.1.1 資料庫異常

| 異常 | 處理方式 | 恢復策略 |
|------|---------|--------|
| Connection Lost | 記錄錯誤,重試 | 調用 `createNewConnection()` |
| Query Timeout | 記錄錯誤,中斷同步 | 檢查查詢性能 |
| Connection Pool Exhausted | 記錄錯誤,重試 | 增加連線池大小 |

**異常捕捉代碼:**
```php
for($i=0; $i<=2; $i++){
    $rs = $GLOBALS['adoconnweb']->Execute($sql);
    if(!$rs){
        if($i < 2) {
            if(strpos(strtolower($GLOBALS['adoconnweb']->errorMsg()), 
                'mysql server has gone away') !== false) {
                createNewConnection("sinyiweb");
            } else {
                break;
            }
        }
    } else {
        break;
    }
}
```

#### 9.1.2 API 異常

| 異常 | HTTP 碼 | 處理方式 |
|------|--------|--------|
| 認証失敗 | 401 | 記錄錯誤,發送簡訊 |
| 伺服器錯誤 | 500 | 記錄錯誤,重試 |
| 逾時 | 504 | 記錄錯誤,重試 |
| 網路錯誤 | CURL Error | 記錄 curl_errno,重試 |

**API 回應驗証:**
```php
if ($result['status'] != 1) {
    $errmsg = '[' . $type . '] curl_errno = ' . $curl_error_code . 
              ', ' . $result['message'];
    $log['errMsg'][$type][] = $errmsg;
    sms_error_log(json_encode($errmsg, JSON_UNESCAPED_UNICODE));
}
```

#### 9.1.3 業務邏輯異常

| 異常 | 原因 | 處理方式 |
|------|------|--------|
| 無效的執行類型 | 參數錯誤 | 紀錄日誌,程序終止 |
| 重複執行 | 進程仍在運行 | 程序終止 |
| 黑名單房產 | 業務規則 | 跳過該房產 |
| 執照過期 | 預售屋規則 | 下架該房產 |

### 9.2 日誌記錄

#### 9.2.1 日誌級別

| 級別 | 說明 | 記錄內容 |
|------|------|--------|
| **INFO** (1) | 信息 | 正常流程、操作完成 |
| **WARN** (0) | 警告 | 部分操作失敗、異常恢復 |
| **ERROR** (0) | 錯誤 | 操作完全失敗、程序終止 |

#### 9.2.2 日誌輸出

**CSV 日誌:**
```
log/Sinyi2Rakuya/Sinyi2Rakuya_YYYY-MM-DD.csv

時間戳,級別,操作,訊息
2026-05-22 10:00:00,1,Start,開始執行排程
2026-05-22 10:00:01,1,Prepare,準備數據
2026-05-22 10:00:05,1,StoreOn,上架 5 個門市
2026-05-22 10:00:10,1,ObjectOn,上架 100 個房產
2026-05-22 10:00:15,0,Error,API 調用失敗
2026-05-22 10:00:20,1,End,排程完成
```

**JSON 日誌 (stdout):**
```json
{
  "starttime": "2026-05-22 10:00:00",
  "endtime": "2026-05-22 10:00:20",
  "message": {
    "store_on": {
      "step": "上架分店",
      "status": {
        "total": 5,
        "success": 5,
        "fail": 0
      }
    },
    "object_on": {
      "step": "上架房產",
      "status": {
        "total": 100,
        "success": 98,
        "fail": 2
      }
    }
  },
  "status": {
    "success": {
      "sum": {
        "push_store": 5,
        "push_item": 98
      }
    },
    "fail": {
      "sum": {
        "push_store": 0,
        "push_item": 2
      }
    }
  }
}
```

---

## 10. 效能考量

### 10.1 效能指標

| 指標 | 目標 | 監控方式 |
|------|------|--------|
| **同步時間** | < 30 分鐘 | 記錄 starttime 和 endtime |
| **查詢時間** | < 5 秒 | 監控 SQL 執行時間 |
| **API 回應** | < 2 秒 | 監控 CURL 響應時間 |
| **記憶體使用** | < 512 MB | 監控 PHP 記憶體占用 |
| **連線使用** | < 50 個 | 監控連線池狀態 |

### 10.2 效能最佳化

#### 10.2.1 數據庫最佳化

**分頁查詢:**
```php
// 避免一次加載所有房產
$total_page = ceil($total_count / RAKUYA_TRANSFERLIMIT);
for ($page = 0; $page < $total_page; $page++) {
    $limit = $page * RAKUYA_TRANSFERLIMIT . ", " . RAKUYA_TRANSFERLIMIT;
    // 處理當前頁數據
}
```

**索引使用:**
```sql
-- 創建複合索引加速查詢
CREATE INDEX idx_status_houseinc_storeno ON sinyi_house_sell 
  (status, houseinc, reservestoreno);
```

**查詢減少:**
```php
// 合併多個查詢為單個批次查詢
SELECT ... FROM sinyi_house_sell 
WHERE status = 1 AND houseinc = 1
  AND reservestoreno IN ('BR21', 'FC10', ...);
```

#### 10.2.2 API 最佳化

**批次提交:**
```php
// 每 RAKUYA_TRANSFERLIMIT 筆記錄調用一次 API
if ($count % RAKUYA_TRANSFERLIMIT == 0) {
    rakuyaAPI('item', $arrayData, $curl_error_code);
    $arrayData = array();  // 清空準備下一批
}
```

**連線重用:**
```php
// 避免為每個請求建立新連線
$GLOBALS['adoconn']->Execute($sql);  // 重用連線
```

#### 10.2.3 記憶體最佳化

**流式處理:**
```php
// 避免一次加載所有 RecordSet
while (!$rs->EOF) {
    // 處理單筆記錄
    $rs->MoveNext();
}
```

**及時釋放:**
```php
unset($arrayData);  // 及時釋放大型變量
unset($object_on_rs);
```

### 10.3 監控與調試

#### 10.3.1 效能監控

**執行時間統計:**
```php
$starttime = microtime(true);
// ... 代碼 ...
$endtime = microtime(true);
$duration = round(($endtime - $starttime), 2);
logcsv(..., "耗時: {$duration} 秒");
```

**記憶體監控:**
```php
$memory_peak = memory_get_peak_usage(true) / 1024 / 1024;  // MB
logcsv(..., "峰值記憶體: {$memory_peak} MB");
```

#### 10.3.2 慢查詢日誌

**啟用 MySQL 慢查詢:**
```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 5;  -- 5 秒以上的查詢
```

---

## 附錄

### A. 常數定義

```php
// 排程基本常數
define('RAKUYA_COMMONID', 樂屋同業 ID);
define('RAKUYA_TRANSFERLIMIT', 100);      // 每次 API 調用筆數
define('RAKUYA_SEARCHTOTAL', 60000);      // 總同步上限
define('RAKUYA_UPLOAD_DAYLIMIT', 10);     // 房產上架前等待天數

// 資料庫常數
define('ADOPREFIX', 'db_prefix');
define('ADOPREFIX_LOG', 'log_prefix');

// 圖片 URL
define('IMAGE_URL', 'http://image.domain.com/');

// 3D VR URL
define('API_123DVR_URL', 'http://3dvr.url/[[packageid]]');
```

### B. 執行命令示例

```bash
# 執行所有操作
php /www/cron/Sinyi2Rakuya.php

# 執行特定類型
php /www/cron/Sinyi2Rakuya.php -type 3

# 執行特定房產
php /www/cron/Sinyi2Rakuya.php -type 3 -no 12345A

# Crontab 配置
0 2 * * * /usr/bin/php /www/cron/Sinyi2Rakuya.php
```

### C. 常見問題 (FAQ)

**Q1: 為什麼某些房產沒有被同步?**
- A: 檢查黑名單、門市編號、圖片數量、房產狀態等篩選條件

**Q2: 如何手動同步特定房產?**
- A: 執行 `php Sinyi2Rakuya.php -type 3 -no HOUSENO`

**Q3: 如何查看同步日誌?**
- A: 查看 `log/Sinyi2Rakuya/` 目錄中的 CSV 檔案

**Q4: 如何處理連線逾時?**
- A: 系統自動重連,或檢查資料庫伺服器狀態

**Q5: API 調用失敗怎麼辦?**
- A: 檢查 API Key、網路連線、樂屋伺服器狀態

---

**文件完成日期:** 2026-05-22  
**下次審視日期:** 2026-08-22  
**文件維護人:** Copilot (GitHub)

