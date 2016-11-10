<?php

if (!isset($location))
    $location = "../";
include_once $location . 'db/DatabaseManager.php';
date_default_timezone_set('GMT');
//-- process remote api calls
if (isset($_GET['fetch'])) {
    $fetch = strtolower($_GET['fetch']);

    switch ($fetch) {
        case "list":
            echo ApiManager::getVedaList("JSON");
            break;
        case "chart":
            break;
        case "stocks":
            break;
    }
    exit();
}

class TrendReturnType {

    const DAILY = 0;
    const WEEKLY = 1;
    const MONTHLY = 2;
    const ANNUAL = 3;
    const INCEPTION = 4;

    public static function display($type) {
        $display = "UNKNOWN";
        switch ($type) {
            case TrendReturnType::DAILY:
                $display = "DAILY";
                break;
            case TrendReturnType::WEEKLY:
                $display = "WEEKLY";
                break;
            case TrendReturnType::MONTHLY:
                $display = "MONTHLY";
                break;
            case TrendReturnType::ANNUAL:
                $display = "ANNUAL";
                break;
            case TrendReturnType::INCEPTION:
                $display = "INCEPTION";
                break;
        }
        return $display . " Return";
    }

}

class ApiManager {

    private $_debug = FALSE;

    public function setDebug($debug = TRUE) {
        $this->_debug = $debug;
    }

    public function getDebug() {
        return $this->_debug;
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------

    public static function formatDisplayReturn($return, $sign = " %") {

        return round($return * 100, 3) . $sign;
    }

    public static function getDisplayReturn($json, $sign = " %") {

        //-- this is last element in the return column

        $lastRecord = end(json_decode($json));

        return ($lastRecord[1] * 100) . $sign;
    }

    public static function getStockReturn($symbol, $trendReturnType = TrendReturnType::MONTHLY) {

        //-- data comes back as a percentage ready for display
        $array = ApiManager::getChartTrendDataForStock($symbol, $trendReturnType);

        $json = json_decode($array);

        $lastRecord = end($json);

        return round($lastRecord[1], 2) . " %";
    }

    public static function getStockReturnUsingDate($symbol, $date) {

        DatabaseManager::connect();
        $query = "select adjustedClose from StockData where stockID = (select stockID from Stock where symbol = '$symbol') and (date = '$date' 
            or date = (select max(date) from StockData where stockID = (select stockID from Stock where symbol = '$symbol'))) order by date asc;";
        $result = mysql_query($query);

        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }

       

        $count = 0;
        while ($row = mysql_fetch_assoc($result)) {
            $count++;
            if ($count == 1) {
                $first = $row['adjustedClose'];
                
            } else {
                if ($count == 2) {
                    $last = $row['adjustedClose'];
               
                } else {
                    return null;
                }
            }
        }
        
        return round(($last/$first - 1) * 100,2) . "%";
    }

    public static function getVedaReturn($vedaID, $trendReturnType = TrendReturnType::MONTHLY) {
        //-- deprecated
        DatabaseManager::connect();
        switch ($trendReturnType) {
            case TrendReturnType::DAILY:
                $query = "select dailyReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::WEEKLY:
                $query = "select weeklyReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::MONTHLY:
                $query = "select monthlyReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::ANNUAL:
                $query = "select annualReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::INCEPTION:
                $query = "select inceptionReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
        }
        $result = mysql_query($query);

        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }

        $row = mysql_fetch_row($result);
        $json = $row[0];
        $lastRecord = end(json_decode($json));
        return ($lastRecord[1] * 100) . " %";
    }

    //---------------------------------------------------------------------
    //---------------------------------------------------------------------

    /*
      [{"type":"veda","id":"2","name":"VedaTech",returnType":"3"},{"type":"stock","id":"3", "name":"Apple Inc",returnType":"3"}]
     */
    public static function getChartTrendDataAll($jsontargets, $format = "ARRAY") {

        DatabaseManager::connect();
        $targets = json_decode($jsontargets);
        $earliestDate = null;

        foreach ($targets as &$target) {

            if (!isset($target->returnType))
                $target->returnType = TrendReturnType::MONTHLY;

            if ($target->type == "veda") {

                switch ($trendReturnType = $target->returnType) {
                    case TrendReturnType::DAILY:
                        $returnType = "dailyReturn";
                        $query = "select dailyReturn from Veda where vedaID = '$target->id' LIMIT 1;";
                        break;
                    case TrendReturnType::WEEKLY:
                        $returnType = "weeklyReturn";
                        $query = "select weeklyReturn from Veda where vedaID = '$target->id' LIMIT 1;";
                        break;
                    case TrendReturnType::MONTHLY:
                        $returnType = "monthlyReturn";
                        $query = "select monthlyReturn from Veda where vedaID = '$target->id' LIMIT 1;";
                        break;
                    case TrendReturnType::ANNUAL:
                        $returnType = "annualReturn";
                        $query = "select annualReturn from Veda where vedaID = '$target->id' LIMIT 1;";
                        break;
                    case TrendReturnType::INCEPTION:
                        $returnType = "inceptionReturn";
                        $query = "select inceptionReturn from Veda where vedaID = '$target->id' LIMIT 1;";
                        break;
                }

                $result = mysql_query($query);
                if (!$result) {
                    echo 'MySQL Error: ' . mysql_error();
                    exit;
                }

                $row = mysql_fetch_assoc($result);
                $json = $row[$returnType];
                $array = json_decode($json);
                $firstArr = reset($array);
                $startDate = reset($firstArr);

                if (($earliestDate == null) || ($startDate < $earliestDate)) {
                    $earliestDate = $startDate;
                }
            }
        }

        //-- earliest date is set only if we have a Veda in the mix
        if ($earliestDate != null) {
            $earliestDate = $earliestDate / 1000;
            $earliestDate = date('Y-m-d', $earliestDate);
        }

        $arr = array();
        foreach ($targets as &$target) {

            if ($target->type == "stock") {
                $symbol = $target->id;
                $name = $target->name;
                if ($earliestDate == null) {
                    $data = ApiManager::getChartTrendDataForStock($symbol, $target->returnType);
                } else {
                    $data = ApiManager::getChartTrendDataForStockUsingStartDate($symbol, $earliestDate);
                }

                //-- add a count field for debug
                $arr[] = array("name" => $name, "data" => $data, "count" => count(json_decode($data)));
                //$arr[] = array("name" => $name, "data" => $data);
            }

            if ($target->type == "veda") {
                $name = $target->name;
                $vedaID = $target->id;
                $data = ApiManager::getChartTrendDataForVeda($vedaID, $target->returnType);
                //-- add a count field for debug
                $arr[] = array("name" => $name, "data" => $data, "count" => count(json_decode($data)));
                //$arr[] = array("name" => $name, "data" => $data);
            }
        }

        if ($format == "JSON")
            return json_encode($arr);
        else
            return $arr;
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------


    public function getVedaStockDisplayReturn($symbol, $trendReturnType = TrendReturnType::MONTHLY, $vedaName) {

        DatabaseManager::connect();
        $inceptionQuery = "select createdDate from Veda where vedaID = (select vedaID from Veda where name = '$vedaName'";
        $result = mysql_query($inceptionQuery);
        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }

        $row = mysql_fetch_assoc($result);
        $initialDate = $row['createdDate'];

        $today = date('Y-m-d');
        switch ($trendReturnType) {
            case TrendReturnType::DAILY:
                $startDate = strtotime('-1 day', strtotime($today));
                break;
            case TrendReturnType::WEEKLY:
                $startDate = strtotime('-7 day', strtotime($today));
                break;
            case TrendReturnType::MONTHLY:
                $startDate = strtotime('-30 day', strtotime($today));
                break;
            case TrendReturnType::ANNUAL:
                $startDate = strtotime('-365', strtotime($today));
                break;
            case TrendReturnType::INCEPTION:
                $startDate = $initialDate;
                break;
        }

        $startDate = date('Y-m-d', $startDate);

        if ($startDate >= $initialDate) {
            return getStockReturn($symbol, $trendReturnType);
        } else {
            return getStockReturnUsingDate($symbol, $initialDate);
        }
    }

    public static function getStockChartTrendData($query) {
        DatabaseManager::connect();
        $result = mysql_query($query);
        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }
        $cnt = 0;
        $startPrice = 0.0;
        $json = array();
        while ($row = mysql_fetch_assoc($result)) {
            //-- if first row set start price 
            if (++$cnt == 1) {
                $startPrice = floatval($row['adjustedClose']);
            }
            $date = $row['date'];
            $seconds = strtotime($date) * 1000;
            $price = floatval($row['adjustedClose']);
            $return = ($price / $startPrice) - 1;
            $json[] = array($seconds, round($return * 100, 4));
        }
        sort($json);
        return json_encode($json);
    }

    public static function getChartTrendDataForStock($symbol, $trendReturnType = TrendReturnType::MONTHLY) {

        $today = date('Y-m-d');

        switch ($trendReturnType) {
            case TrendReturnType::DAILY:
                $startDate = strtotime('-1 day', strtotime($today));
                break;
            case TrendReturnType::WEEKLY:
                $startDate = strtotime('-7 day', strtotime($today));
                break;
            case TrendReturnType::MONTHLY:
                $startDate = strtotime('-30 day', strtotime($today));
                break;
            case TrendReturnType::ANNUAL:
                $startDate = strtotime('-365 day', strtotime($today));
                break;
            case TrendReturnType::INCEPTION:
                $query = "select StockData.date, StockData.adjustedClose from StockData, Stock WHERE "
                        . "StockData.stockID = Stock.stockID AND Stock.symbol = '$symbol' "
                        . "order by date ASC;";
                return ApiManager::getStockChartTrendData($query);
                break;
        }

        $startDate = date('Y-m-d', $startDate);

        $query = "select StockData.date, StockData.adjustedClose from StockData, Stock WHERE "
                . "StockData.stockID = Stock.stockID AND Stock.symbol = '$symbol' AND date >= '$startDate' "
                . "order by date ASC;";

        return ApiManager::getStockChartTrendData($query);
    }

    public static function getChartTrendDataForStockUsingStartDate($symbol, $startDate) {

        $query = "select StockData.date, StockData.adjustedClose from StockData, Stock WHERE "
                . "StockData.stockID = Stock.stockID AND Stock.symbol = '$symbol' AND date >= '$startDate' "
                . "order by date ASC;";

        return ApiManager::getStockChartTrendData($query);
    }

    public static function getChartTrendDataForVeda($vedaID, $trendReturnType = TrendReturnType::MONTHLY) {

        DatabaseManager::connect();
        switch ($trendReturnType) {
            case TrendReturnType::DAILY:
                $query = "select dailyReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::WEEKLY:
                $query = "select weeklyReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::MONTHLY:
                $query = "select monthlyReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::ANNUAL:
                $query = "select annualReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
            case TrendReturnType::INCEPTION:
                $query = "select inceptionReturn from Veda where vedaID = '$vedaID' LIMIT 1;";
                break;
        }

        $result = mysql_query($query);
        $row = mysql_fetch_row($result);

        //-- multiply elements by 100
        $array = json_decode($row[0]);
        foreach ($array as &$el) {
            $el[1] *= 100;
        }
        return json_encode($array);
    }

    public static function getChartPriceDataForStock($symbol) {

        DatabaseManager::connect();

        $query = "select StockData.date, StockData.adjustedClose from StockData, Stock WHERE "
                . "StockData.stockID = Stock.stockID AND Stock.symbol = '$symbol' "
                . "order by date ASC;";

        //echo $query;
        $result = mysql_query($query);
        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }

        $json = array();
        while ($row = mysql_fetch_assoc($result)) {
            $date = $row['date'];
            $seconds = strtotime($date) * 1000;
            $price = floatval($row['adjustedClose']);
            $json[] = array($seconds, $price);
        }
        sort($json);
        return json_encode($json);
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------

    public static function getData($query, $format = "ARRAY") {
        DatabaseManager::connect();

        //echo $query;
        $result = mysql_query($query);
        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }
        $array = array();
        //-- simplifies return if only a single element is required
        //-- otherwise we need to access using array[0] and so forth
        if (substr($query, - 8) == "LIMIT 1;") {
            $array = mysql_fetch_assoc($result);
        } else {
            //echo "NO LIMIT 1:".$query ;
            while ($row = mysql_fetch_assoc($result)) {
                $array[] = $row;
            }
        }
        if ($format == "JSON")
            return json_encode($array);
        else
            return $array;
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------

    protected static function compareMonthlyReturn($a, $b) {
        //-- no longer needed
        if ($a['monthlyReturnDisplay'] == $b['monthlyReturnDisplay']) {
            return 0;
        }
        return ($a['monthlyReturnDisplay'] > $b['monthlyReturnDisplay']) ? -1 : 1;
    }

    public static function getTopVedaListTemporary($limit, $format = "ARRAY") {
        //-- no longer needed since we get values directly
        DatabaseManager::connect();
        $query = "select * from Veda;";
        $result = mysql_query($query);
        if (!$result) {
            echo 'MySQL Error: ' . mysql_error();
            exit;
        }
        $array = array();
        while ($row = mysql_fetch_assoc($result)) {
            //-- for now get inceptionReturn for top vedas
            $monthlyReturnDisplay = ApiManager::getDisplayReturn($row['inceptionReturn'], "");
            $row['monthlyReturnDisplay'] = $monthlyReturnDisplay;
            $array[] = $row;
        }

        usort($array, "ApiManager::compareMonthlyReturn");

        $arrayslice = array_slice($array, 0, $limit);
        if ($format == "JSON")
            return json_encode($arrayslice);
        else
            return $arrayslice;
    }

    public static function getTopVedaList($limit, $format = "ARRAY") {
        $query = "select * from Veda order by inceptionDisplayReturn DESC LIMIT $limit;";
        return ApiManager::getData($query, $format);
    }

    public static function getVedaList($format = "ARRAY") {

        $query = "select * from Veda;";
        return ApiManager::getData($query, $format);
    }

    public static function getVeda($vedaID, $format = "ARRAY") {

        $query = "select * from Veda WHERE "
                . "Veda.vedaID = '$vedaID' "
                . "LIMIT 1;";

        return ApiManager::getData($query, $format);
    }

    public static function getVedaStockList($vedaID, $format = "ARRAY") {

        $query = "select * ,Stock.name as stockName, Industry.name as industryName, Sector.name as sectorName from Stock, VedaStock, Industry, Sector WHERE "
                . "VedaStock.vedaID = '$vedaID' AND VedaStock.stockID = Stock.stockID";
        $query = "select * ,Stock.name as stockName from VedaStock, Stock WHERE "
                . "VedaStock.vedaID = '$vedaID' AND Stock.stockID = VedaStock.stockID";

        return ApiManager::getData($query, $format);
    }

    /* for remote api implementation */

    public static function getVedaListRemote() {
        $requestUrl = "http://localhost/LaVeda/api/ApiManager.php?fetch=list";
        //echo "requestUrl=" . $requestUrl;
        $curl = curl_init($requestUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_URL, $requestUrl);
        $json = curl_exec($curl);
        curl_close($curl);
        return $json;
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------

    public static function getStockUsingID($stockID, $format = "ARRAY") {

        $query = "select * ,Stock.name as stockName, Industry.name as industryName, Sector.name as sectorName from Stock, Industry, Sector WHERE "
                . "Stock.stockID = '$stockID' "
                . "AND Industry.industryID = Stock.industryID "
                . "LIMIT 1;";

        return ApiManager::getData($query, $format);
    }

    public static function getStock($symbol, $format = "ARRAY") {

        $query = "select * ,Stock.name as stockName, Industry.name as industryName, Sector.name as sectorName from Stock, Industry, Sector WHERE "
                . "Stock.symbol = '$symbol' "
                . "AND Industry.industryID = Stock.industryID "
                . "LIMIT 1;";

        return ApiManager::getData($query, $format);
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------

    public static function getStockDescription($symbol) {

        //-- get source page and strip out description
        $url = "http://www.google.com/finance?q=NASDAQ:" . $symbol;
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $contents = curl_exec($curl);
        curl_close($curl);
        libxml_use_internal_errors(true);
        $DOM = new DOMDocument;
        try {
            $DOM->loadHTML($contents);
        } catch (ErrorException $e) {
            // ...
        }
        $xpath = new DOMXPath($DOM);
        $domNodeList = $xpath->query('//div[@class="companySummary"]'); // google finance
        if ($domNodeList->length == 0)
            return "";
        $div = $domNodeList->item(0);
        $str = $DOM->saveXML($div);

        //-- strip out and return everything in between
        //-- <div class="companySummary">....<div class="sfe-break-top">
        $divlen = strlen("<div class=\"companySummary\">");
        $end = strrpos($str, "<div class=\"sfe-break-top\">");
        $description = substr($str, $divlen, $end - $divlen);
        //echo $description . "<br />";
        return $description;
    }

//---------------------------------------------------------------------
//---------------------------------------------------------------------

    public static function getCategoryList($format = "ARRAY") {

        $query = "select * from Category";

        return ApiManager::getData($query, $format);
    }

    public static function getCategory($categoryID, $format = "ARRAY") {

        $query = "select * from Category WHERE "
                . "Category.categoryID = '$categoryID' "
                . "LIMIT 1;";

        return ApiManager::getData($query, $format);
    }

    public static function getSector($sectorID, $format = "ARRAY") {

        $query = "select * from Sector WHERE "
                . "Sector.sectorID = '$sectorID' "
                . "LIMIT 1;";

        return ApiManager::getData($query, $format);
    }

    public static function getIndustry($industryID, $format = "ARRAY") {

        $query = "select * from Industry WHERE "
                . "Industry.industryID = '$industryID' "
                . "LIMIT 1;";

        return ApiManager::getData($query, $format);
    }

}

//---------------------------------------------------------------------
//---------------------------------------------------------------------
//-- test
if (isset($_GET['apiprice'])) {
    include_once '../utilities/Debug.php';
    ini_set('display_errors', '1');
    $symbol = $_GET['apiprice'];
    Debug::sdebug(ApiManager::getChartPriceDataForStock($symbol), "Chart Price Data");
}

if (isset($_GET['apiq'])) {
    include_once '../utilities/Debug.php';
    ini_set('display_errors', '1');
    $query = $_GET['apiq'];
    Debug::sdebug(ApiManager::getData($query), $query);
}

if (isset($_GET['apitest'])) {
    include_once '../utilities/Debug.php';
    ini_set('display_errors', '1');
    if (false) {
        Debug::sdebug(ApiManager::getStockReturn("AAPL"), "getStockReturn");
    }
    if (false) {

        $targets = array();
        //$targets[] = array("type" => "veda", "id" => "4", "name" => "VedaTech", "returnType" => "2");
        $targets[] = array("type" => "stock", "id" => "AAPL", "name" => "Apple Inc", "returnType" => "2");
        $targets[] = array("type" => "stock", "id" => "RFMD", "name" => "RF Micro Devices", "returnType" => "2");

        $dataSets = ApiManager::getChartTrendDataAll(json_encode($targets));
        Debug::sdebug($dataSets, "Data Sets");
        $JSONdataSets = json_encode($dataSets);
        foreach ($dataSets as $dataSet) {
            Debug::sdebug($dataSet, $dataSet['name'] . " dataSet");
        }
    }
    if (false) {
        Debug::sdebug(ApiManager::getTopVedaListTemporary(4), "Top Vedas");
    }
    if (false) {
        Debug::sdebug(ApiManager::getCategoryList(), "Categories");
        Debug::sdebug(ApiManager::getCategory("3"), "Category");
        Debug::sdebug(ApiManager::getVedaList(), "Vedas");
        Debug::sdebug(ApiManager::getVedaStockList("1"), "Veda Stocks");
    }
    if (false) {
        Debug::sdebug(ApiManager::getSector("1"), "Sector");
        Debug::sdebug(ApiManager::getIndustry("1"), "Industry");
        Debug::sdebug(ApiManager::getStock("FB"), "Stock Info");
        Debug::sdebug(ApiManager::getStockUsingID("926"), "Stock Info Using ID");
    }
    if (true) {
        //Debug::sdebug(ApiManager::getVedaReturn("2"), "GetVedaReturn");
        Debug::sdebug(ApiManager::getChartTrendDataForStock("AAPL", TrendReturnType::MONTHLY), "Chart Stock Trend Data");
        //Debug::sdebug(ApiManager::getChartTrendDataForVeda("1"), "Chart Trend Data");
        
    }
    if (false) {
        //Debug::sdebug(ApiManager::getChartPriceDataForStock("ANR"), "Chart Trend for Stock");
        Debug::sdebug(ApiManager::getChartTrendDataForStockUsingStartDate("^GSPC", "2014-08-01"), "Chart Trend For Stock and Start Date");
        Debug::sdebug(ApiManager::getChartTrendDataForStock("FB", TrendReturnType::MONTHLY), "Chart Trend Data For Stock");
    }

    if (false) {
        Debug::sdebug(ApiManager::getVedaList("JSON"), "VedaList JSON");
        Debug::sdebug(ApiManager::getVedaList(), "VedaList ARRAY");
        Debug::sdebug(ApiManager::getVedaListRemote(), "VedaListRemote");
    }
   
    ApiManager::getVedaStockDisplayReturn("INO",TrendReturnType::MONTHLY, "VedaPharmaceuticals");
    Debug::sdebug(ApiManager::getStockReturnUsingDate("GILD","2014-08-11"), "Return");
}
?>

