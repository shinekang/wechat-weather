<?php
/**
 * Created by PhpStorm.
 * User: kjj
 * Date: 2016/8/1
 * Time: 16:13
 */

define("TOKEN", "TOKEN");
define("AppID", "AppID");
define("EncodingAESKey", "EncodingAESKey");
define("BAIDUAPIKEY", "BAIDUAPIKEY");

include('./Public/errorCode.php');
include('./Public/wxBizMsgCrypt.php');


$wechatObj = new indexController();
if (!isset($_GET['echostr'])) {
    $wechatObj->responseMsg();
} else {
    $wechatObj->valid();
}

class indexController
{

    /**
     * 验证签名
     */
    public function valid()
    {
        $echoStr = $_GET["echostr"];
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];
        $tmpArr = array(TOKEN, $timestamp, $nonce);
        sort($tmpArr);
        $tmpStr = implode($tmpArr);
        $tmpStr = sha1($tmpStr);
        if ($tmpStr == $signature) {
            echo $echoStr;
            exit;
        }
    }

    /**
     * 响应消息
     */
    public function responseMsg()
    {
        $timestamp = isset($_GET['timestamp']) && $_GET['timestamp'] ? $_GET['timestamp'] : strtotime(date('Y-m-d H:i:s'));
        $nonce = isset($_GET["nonce"]) ? $_GET["nonce"] : '';
        $msg_signature = isset($_GET['msg_signature']) ? $_GET['msg_signature'] : '';
        $encrypt_type = (isset($_GET['encrypt_type']) && ($_GET['encrypt_type'] == 'aes')) ? "aes" : "raw";

        $postStr = file_get_contents("php://input");

        if (!empty($postStr)) {
            //解密
            if ($encrypt_type == 'aes') {
                $pc = new WXBizMsgCrypt(TOKEN, EncodingAESKey, AppID);
                $this->logger(" D \r\n" . $postStr);
                $decryptMsg = "";  //解密后的明文
                $errCode = $pc->DecryptMsg($msg_signature, $timestamp, $nonce, $postStr, $decryptMsg);
                $postStr = $decryptMsg;
            }
            $this->logger(" R \r\n" . $postStr);

            $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
            $RX_TYPE = trim($postObj->MsgType);
            //获取用户信息

            //消息类型分离
            switch ($RX_TYPE) {
                case "event":
                    $result = $this->receiveEvent($postObj);
                    break;
                case "text":
                    $result = $this->receiveText($postObj);
                    break;
            }
            $this->logger(" R \r\n" . $result);
            //加密
            if ($encrypt_type == 'aes') {
                $encryptMsg = ''; //加密后的密文
                $errCode = $pc->encryptMsg($result, $timestamp, $nonce, $encryptMsg);
                $result = $encryptMsg;
                $this->logger(" E \r\n" . $result);
            }
            echo $result;
        } else {
            echo "";
            exit;
        }
    }

    /**
     * 接收事件消息
     *
     * @param $object
     * @return string
     */
    public function receiveEvent($object)
    {
        $content = "";
        switch ($object->Event) {
            case "subscribe":
                $content = "目前功能如下：【1】 查看天气，如输入‘上海天气’\n更多内容，敬请期待...\n\n技术支持 康康";
                break;
        }

        $result = $this->transmitText($object, $content);
        return $result;
    }

    /**
     * 接收文本消息
     *
     * @param $object
     * @return string|void
     */
    public function receiveText($object)
    {
        $keyword = trim($object->Content);
        $content = $this->getContentInfoByKeyword($keyword);
        if (is_array($content)) {
            if (isset($content[0])) {
                $result = $this->transmitNews($object, $content);
            }
        } else {
            $result = $this->transmitText($object, $content);
        }
        return $result;
    }

    /**
     * 根据关键字获取返回内容
     *
     * @param $keyword
     * @return array|string
     */
    public function getContentInfoByKeyword($keyword)
    {
        if (strstr($keyword, "天气") && mb_substr($keyword, -2, 2, "UTF-8") == "天气") {
            $str_key = mb_substr($keyword, 0, -2, "UTF-8");
            if (!empty($str_key)) {
                $content = $this->weatherInfo($str_key);
                if (empty($content)) {
                    $content = "抱歉，没有查到\"" . $str_key . "\"的天气信息！";
                }
            } else {
                $content = "请输入城市名称，如‘上海天气’！";
            }
        } else {
            $content = date("Y-m-d H:i:s", time()) . "\n【1】 查看天气，如输入‘上海天气’\n更多内容，敬请期待...\n\n技术支持 康康";
        }

        return $content ? $content : '';
    }

    /**
     * 回复文本消息
     *
     * @param $object
     * @param $content
     * @return string
     */
    public function transmitText($object, $content)
    {
        $xmlTpl = "<xml>
    <ToUserName><![CDATA[%s]]></ToUserName>
    <FromUserName><![CDATA[%s]]></FromUserName>
    <CreateTime>%s</CreateTime>
    <MsgType><![CDATA[text]]></MsgType>
    <Content><![CDATA[%s]]></Content>
    <MsgId><![CDATA[%s]]></MsgId>
</xml>";
        $result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time(), $content, $object->MsgId);
        return $result;
    }

    /**
     * 回复图文消息
     *
     * @param $object
     * @param $newsArray
     * @return string|void
     */
    public function transmitNews($object, $newsArray)
    {
        if (!is_array($newsArray)) {
            return;
        }
        $itemTpl = "        <item>
            <Title><![CDATA[%s]]></Title>
            <Description><![CDATA[%s]]></Description>
            <PicUrl><![CDATA[%s]]></PicUrl>
            <Url><![CDATA[%s]]></Url>
        </item>
";
        $item_str = "";
        foreach ($newsArray as $item) {
            $item_str .= sprintf($itemTpl, $item['Title'], $item['Description'], $item['PicUrl'], $item['Url']);
        }
        $xmlTpl = "<xml>
    <ToUserName><![CDATA[%s]]></ToUserName>
    <FromUserName><![CDATA[%s]]></FromUserName>
    <CreateTime>%s</CreateTime>
    <MsgType><![CDATA[news]]></MsgType>
    <ArticleCount>%s</ArticleCount>
    <Articles>
$item_str    </Articles>
</xml>";

        $result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time(), count($newsArray));
        return $result;
    }

    /**
     * 多条消息格式整合
     *
     * @param $n
     * @return mixed|null
     */
    public function weatherInfo($cityName)
    {
        $result = $this->getWeatherData($cityName);
        if ($result["error"] != 0) {
            return $result["status"];
        }
        $curHour = (int)date('H', time());
        $weather = $result["results"][0];//按照微信公众号开发文档,组建设多图文回复信息

        $weatherArray[] = array("Title" => $weather['currentCity'] . "当前天气:" . "温度:" . $weather['weather_data'][0]['temperature'] . "," . $weather['weather_data'][0]['weather'] . "," . "风力:" . $weather['weather_data'][0]['wind'] . ".", "Description" => "", "PicUrl" => "./Public/weather/weather_bg.jpg", "Url" => "");
        for ($i = 0; $i < count($weather["weather_data"]); $i++) {
            $weatherArray[] = array("Title" => $weather["weather_data"][$i]["date"] . "\n" . $weather["weather_data"][$i]["weather"] . " " . $weather["weather_data"][$i]["wind"] . " " . $weather["weather_data"][$i]["temperature"] . "", "Description" => "", "PicUrl" => (($curHour >= 6) && ($curHour < 18)) ? $weather["weather_data"][$i]["dayPictureUrl"] : $weather["weather_data"][$i]["nightPictureUrl"], "Url" => "");
        }

        return $weatherArray;
    }

    /**
     * 对象转换成数组
     *
     * @param $obj
     * @return mixed
     */
    public function objectToArray($obj)
    {
        $_arr = is_object($obj) ? get_object_vars($obj) : $obj;
        foreach ($_arr as $key => $val) {
            $val = (is_array($val) || is_object($val)) ? $this->objectToArray($val) : $val;
            $arr[$key] = $val;
        }
        return $arr;
    }

    /**
     * 调取天气信息
     *
     * @param $cityName
     * @return mixed
     */
    public function getWeatherData($cityName)
    {
        $url = "http://api.map.baidu.com/telematics/v3/weather?location=" . urlencode($cityName) . "&output=json&ak=" . BAIDUAPIKEY;
        $json = file_get_contents($url);

        return $this->objectToArray(json_decode($json));
    }

    /**
     * 日志记录
     *
     * @param $log_content
     */
    public function logger($log_content)
    {
        if (isset($_SERVER['HTTP_APPNAME'])) {   //SAE
            sae_set_display_errors(false);
            sae_debug($log_content);
            sae_set_display_errors(true);
        } else if ($_SERVER['REMOTE_ADDR'] != "127.0.0.1") { //LOCAL
            $max_size = 500000;
            $log_filename = "log.xml";
            if (file_exists($log_filename) and (abs(filesize($log_filename)) > $max_size)) {
                unlink($log_filename);
            }
            file_put_contents($log_filename, date('Y-m-d H:i:s') . $log_content . "\r\n", FILE_APPEND);
        }
    }
}