<?php
/**
 * VERSION 2014.08.19 - 02
 */

// 声明 __DIR__ 兼容php5.2
if (!defined('__DIR__')) {
    define('__DIR__', dirname(__FILE__));
}

// 声明模板后缀名
define('SDK_PAGE_EXT', '.html');

// 处理quotes_gpc转义
function stripslashes_deep($value)
{
    $value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
    return $value;
}

if ((function_exists("get_magic_quotes_gpc") && get_magic_quotes_gpc())
    || (ini_get('magic_quotes_sybase') && (strtolower(ini_get('magic_quotes_sybase')) != "off"))
) {
    $_GET = stripslashes_deep($_GET);
    $_POST = stripslashes_deep($_POST);
    $_COOKIE = stripslashes_deep($_COOKIE);
}

/**
 * NFSS Proxy Interface
 *
 * TODO Rewrite支持
 * TODO 会话保持，当有登陆等功能时的支持
 *
 * @copyright Copyright (c) 2013 Shenzhen Qianhai Xueteng Technology Co.,Ltd. (http://www.cmstop.com)
 */
class nfss
{
    const DEVICE = 'desktop';

    /**
     * 配置文件
     *
     * @var array
     */
    private $config = array();

    /**
     * Memcache操作
     *
     * @var Memcache
     */
    private $memcache = null;

    private $_tmp = array();

    /**
     * 执行流程
     *
     * CURL的执行要并发
     * PROXY请求时，要保持会话，要支持GET和POST
     *
     * 1. 加载配置文件
     * 2. 检测是否有缓存支持
     * 3. 处理请求，分发到应用
     * 4. 解析include标签，build页面，
     * 5. 缓存页面，输出
     */
    function __construct()
    {
        // 加载配置文件
        $config = file_get_contents(__DIR__ . '/nfss.conf');
        if (!$config) {
            $this->error('配置文件缺失，请联系管理员！');
        }
        $config = json_decode($config, true);
        if (!$config) {
            $this->error('配置文件有误，请联系管理员！');
        }
        $this->config = $config;
        // 检查Memcache缓存
        if (class_exists('Memcache')) {
            $memcache = new Memcache;
            if (@$memcache->connect('127.0.0.1', 11211)) {
                $this->memcache = $memcache;
                if (empty($this->config['memcache'])) {
                    $this->config['memcache'] = array(
                        'ttl' => 600,
                        'prefix' => 'nfss_' . $this->config['projectid'] . '::' . self::DEVICE . '::'
                    );
                }
            }
        }
    }

    /**
     * 缓存控制：读取
     *
     * @param string $key
     * @return array|bool|string
     */
    private function cacheGet($key)
    {
        if ($this->memcache === null) {
            return false;
        }
        return $this->memcache->get($this->config['memcache']['prefix'] . $key);
    }

    /**
     * 缓存控制：写入
     *
     * @param string $key
     * @param string $data
     * @param int $ttl
     * @return bool
     */
    private function cacheSet($key, $data, $ttl)
    {
        if ($this->memcache === null) {
            return false;
        }
        return $this->memcache->set(
            $this->config['memcache']['prefix'] . $key,
            $data,
            0,
            $ttl ? $ttl : $this->config['memcache']['ttl']
        );
    }

    /**
     * URL安全的base64
     *
     * @param string $str
     * @return string
     */
    private function base64_encode_safe($str = '')
    {
        $str = base64_encode($str);
        $str = str_replace(array('+', '/', '='), array('-', '_', '!'), $str);
        return $str;
    }

    private function base64_decode_safe($str = '')
    {
        $str = str_replace(array('-', '_', '!'), array('+', '/', '='), $str);
        $str = base64_decode($str);
        return $str;
    }

    private function disable_functions($func = '')
    {
        $disabled = explode(',', ini_get('disable_functions'));
        return in_array($func, $disabled);
    }

    /**
     * 获取远程数据，简化版
     *
     * @param string $url
     * @param array $post
     * @param int $timeout
     * @param bool $sendcookie
     * @return array
     */
    private function getHttp($url, $post = array(), $timeout = 30, $sendcookie = false)
    {
        $result = $this->getHttpCurl($url, $post, $timeout, $sendcookie);
        if (!$result) {
            $result = $this->getHttpSocket($url, $post, $timeout, $sendcookie);
        }
        if (!$result) {
            $this->error('您的服务器PHP环境同时不支持curl和socket函数，请联系你的服务器提供商开通。');
        }
        return $result;
    }

    private function getHttpCurl($url, $post = array(), $timeout = 30, $sendcookie = false)
    {
        if (!function_exists('curl_init') || $this->disable_functions('curl_exec')) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 35);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ? $timeout : 40);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        if ($sendcookie) {
            $cookie = '';
            foreach ($_COOKIE as $key => $val) {
                $cookie .= rawurlencode($key) . '=' . rawurlencode($val) . ';';
            }
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
        }

        $ret = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        if (!$content_length) $content_length = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (strpos($content_type, ';')) {
            $content_type = substr($content_type, 0, strpos($content_type, ';'));
        }
        if (!$content_length || $content_length == -1) {
            $content_length = strlen($ret);
        }

        return array(
            'httpcode' => $httpcode,
            'content_length' => $content_length,
            'content_type' => $content_type,
            'content' => $ret
        );
    }

    function getHttpSocket($url, $post = array(), $timeout = 30, $sendcookie = false)
    {
        if (!function_exists('fsockopen')) {
            return false;
        }

        $matches = parse_url($url);
        !isset($matches['host']) && $matches['host'] = '';
        !isset($matches['path']) && $matches['path'] = '';
        !isset($matches['query']) && $matches['query'] = '';
        !isset($matches['port']) && $matches['port'] = '';
        $host = $matches['host'];
        $path = $matches['path'] ? $matches['path'] . ($matches['query'] ? '?' . $matches['query'] : '') : '/';
        $port = !empty($matches['port']) ? $matches['port'] : 80;
        $post = is_array($post) ? http_build_query($post) : $post;

        $cookie = '';
        if ($sendcookie) {
            $cookie = '';
            foreach ($_COOKIE as $key => $val) {
                $cookie .= rawurlencode($key) . '=' . rawurlencode($val) . ';';
            }
        }

        if ($post) {
            $out = "POST $path HTTP/1.0\r\n";
            $out .= "Accept: */*\r\n";
            $out .= "Referer: $url\r\n";
            $out .= "Accept-Language: zh-cn\r\n";
            $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $out .= "User-Agent: $_SERVER[HTTP_USER_AGENT]\r\n";
            $out .= "Host: $host\r\n";
            $out .= 'Content-Length: ' . strlen($post) . "\r\n";
            $out .= "Connection: Close\r\n";
            $out .= "Cache-Control: no-cache\r\n";
            $out .= "Cookie: $cookie\r\n\r\n";
            $out .= $post;
        } else {
            $out = "GET $path HTTP/1.0\r\n";
            $out .= "Accept: */*\r\n";
            $out .= "Referer: $url\r\n";
            $out .= "Accept-Language: zh-cn\r\n";
            $out .= "User-Agent: $_SERVER[HTTP_USER_AGENT]\r\n";
            $out .= "Host: $host\r\n";
            $out .= "Connection: Close\r\n";
            $out .= "Cookie: $cookie\r\n\r\n";
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$fp) {
            return '';
        }
        stream_set_blocking($fp, true);
        stream_set_timeout($fp, $timeout);
        @fwrite($fp, $out);
        $header = $result = '';
        $status = stream_get_meta_data($fp);
        if (!$status['timed_out']) {
            while (!feof($fp)) {
                $ret = @fgets($fp);
                if ($ret && ($ret == "\r\n" || $ret == "\n")) {
                    break;
                }
                $header[] = trim($ret);
            }
            while (!feof($fp)) {
                $ret = fread($fp, 4096);
                $result .= $ret;
            }
        }
        @fclose($fp);

        $httpcode = 0;
        $content_length = 0;
        $content_type = 'text/html';
        if ($header) {
            foreach ($header as $ret) {
                if (strpos($ret, 'HTTP/') !== false) {
                    $httpcode = substr($ret, strpos($ret, ' ') + 1, 3);
                    continue;
                }
                if (strpos($ret, 'Content-Type:') !== false) {
                    $content_type = substr($ret, strlen('Content-Type: '));
                    if (strpos($content_type, ';')) {
                        $content_type = substr($content_type, 0, strpos($content_type, ';'));
                    }
                    continue;
                }
                if (strpos($ret, 'Content-Length:') !== false) {
                    $content_length = substr($ret, strlen('Content-Type: '));
                    continue;
                }
            }
            if (!$content_length) {
                $content_length = strlen($result);
            }
        }
        return array(
            'httpcode' => $httpcode,
            'content_length' => $content_length,
            'content_type' => $content_type,
            'content' => $result
        );
    }

    /**
     * 处理URL进行代理
     *
     * @param string $app
     * @param string $data
     * @return mixed|string
     */
    private function proxyUrl($app, $data = '')
    {
        $this->_tmp['proxyUrl::APP'] = $app;

        $data = preg_replace_callback('/(href|src)=(["\']?)([^>"\'\s>]+)([>"\']?)[\s>]?/', array($this, '_proxyUrlCallback'), $data);
        return $data;
    }

    private function _proxyUrlCallback($match)
    {
        $app = $this->_tmp['proxyUrl::APP'];
        if (strpos($match[3], '://') === false) {
            // 有可能在发布时已经处理过的链接
            if (strpos($match[3], 'nfss')) {
                return $match[0];
            }
            // 有可能是本地的资源文件
            if (strpos($match[3], '.')) {
                $file = __DIR__ . '/' . ltrim($match[3], '/');
                $path = parse_url($file);
                $file = $path['path'];
                if (file_exists($file)) {
                    return $match[0];
                }
            }
            // 代理请求
            return str_replace(
                $match[2] . $match[3] . $match[4],
                '"index.php?nfssa=' . $this->base64_encode_safe($app . ':' . $match[3]) . '"',
                $match[0]
            );
        }
        return $match[0];
    }

    /**
     * 解析标签：include
     *
     * @param string $data
     * @return string
     */
    private function parseTags($data = '')
    {
        if (strpos($data, '<!--{include widget') === false) {
            return $data;
        }
        $data = preg_replace_callback('/<!--{include widget="(\d*)"}-->/U', array($this, '_parseTagsCallback'), $data);
        return $data;
    }

    private function _parseTagsCallback($match)
    {
        $widgetid = (int)$match[1] - 1;
        $widgetInfo = isset($this->config['widgets'][$widgetid]) ?
            $this->config['widgets'][$widgetid] :
            array();
        if (empty($widgetInfo)) {
            return '';
        }
        $api = $widgetInfo['api'];
        if (strpos($api, '?')) {
            $api .= '&';
        } else {
            $api .= '?';
        }
        $api .= 'projectid=' . $this->config['projectid'] . '&device=' . self::DEVICE;
        $data = $this->getHttp($api, $widgetInfo['params']);
        if ($data['httpcode'] != 200) {
            switch($data['httpcode']){
                case 0:
                    $msg = '您的服务器暂时无法连接到9466应用平台，请稍后再试，或联系服务器管理员检测。';
                    break;
                case 500:
                    $msg = '9466应用故障，暂时无法提供服务，请稍后再试，或联系9466技术支持。';
                    break;
                default:
                    $msg = '错误码 HTTP STATUS ' .$data['httpcode'];
                    break;
            }
            return '[' . $widgetInfo['type'] . ']接口调用失败: ' . $msg;
        }
        $data = json_decode($data['content'], true);
        if (!$data || $data['code'] != 0) {
            return '[' . $widgetInfo['appid'] . '.' . $widgetInfo['type'] . ']接口调用失败: ' . $data['message'];
        }

        return $this->proxyUrl($widgetInfo['appid'], $data['data']);
    }

    /**
     * 读取静态页面
     *
     * @param string $page
     * @return string
     */
    private function getTpl($page = '')
    {
        if (!$page) {
            $page = 'index';
        }
        $file = __DIR__ . '/pages/' . $page . SDK_PAGE_EXT;
        if (!file_exists($file)) {
            $this->error('404 Not Found');
        }
        return file_get_contents($file);
    }

    /**
     * 读取应用的页面数据
     *
     * @param $app
     * @param $params
     * @return mixed|string
     */
    private function getApp($app, $params)
    {
        if (!$app) {
            $this->error('Applaction Not Found');
        }
        $config = isset($this->config['apps'][$app]) ? $this->config['apps'][$app] : array();
        if (empty($config)) {
            $this->error('Applaction Not Found');
        }
        $uri = $config['api'] . $params['nfssu'];
        if (strpos($uri, '?') === false) {
            $uri .= '?';
        } else {
            $uri .= '&';
        }
        $uri .= 'projectid=' . $this->config['projectid'] . '&device=' . self::DEVICE;
        unset($params['nfssa'], $params['nfssu'], $params['nfssp'], $params['_url']);
        $uri .= http_build_query($params);
        $data = $this->getHttp($uri, $_POST);
        if ($data['httpcode'] != 200) {
            $this->error('Applaction [' . $app . '] 服务器内部错误，请稍后再试，或联系9466技术支持。');
        }
        if ($data['content_type']) {
            header('Content-Type: ' . $data['content_type']);
        }
        return $this->proxyUrl($app, $data['content']);
    }

    /**
     * 代理访问一个URL请求，避免前台的post跨域
     *
     * @param $uri
     * @return mixed
     */
    private function appRequest($uri)
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
            $this->error('Request URL Not Validate');
        }
        $params = $_GET;
        unset($params['nfssa'], $params['nfssu'], $params['nfssp'], $params['_url'], $params['appRequest']);
        if (strpos($uri, '?') === false) {
            $uri .= '?';
        } else {
            $uri .= '&';
        }
        $uri .= http_build_query($params);
        $data = $this->getHttp($uri, $_POST);
        if ($data['httpcode'] != 200) {
            $this->error('Internal Server Error');
        }
        if ($data['content_type']) {
            header('Content-Type: ' . $data['content_type']);
        }
        return $data['content'];
    }

    /**
     * 处理入口
     */
    public function dispatch()
    {
        // 检测请求类型，如果是post的不应该缓存
        // 暂不处理

        // 由缓存输出
        $_key = md5('page_' . $_SERVER["REQUEST_URI"]);
        $data = $this->cacheGet($_key);
        if ($data) {
            //echo $_key . ' cached';
            return $data;
        }

        // 代理请求
        $appRequest = isset($_GET['appRequest']) ? $_GET['appRequest'] : '';
        if ($appRequest) {
            return $this->appRequest($appRequest);
        }

        // 接收请求
        $app = isset($_GET['nfssa']) ? $_GET['nfssa'] : '';
        if ($app) {
            $path = $this->base64_decode_safe($app);
            if (!strpos($path, ':')) {
                $this->error('404 Not Found');
            }
            $app = substr($path, 0, strpos($path, ':'));
            $_GET['nfssu'] = substr($path, strlen($app) + 1);
            $data = $this->getApp($app, $_GET);
        } else {
            $page = isset($_GET['nfssp']) ? $_GET['nfssp'] : 'index';
            $data = $this->getTpl($page);
        }
        // 解析标签
        $data = $this->parseTags($data);
        // 缓存数据，缓存页面1分钟
        $this->cacheSet($_key, $data, 60);
        return $data;
    }

    /**
     * 输出错误
     *
     * @param $msg
     */
    private function error($msg = '')
    {
        if (!$msg) {
            $msg = 'Unknown Error';
        }
        echo '<!DOCTYPE html>
                <html>
                <head>
                    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
                    <title>错误提示</title>
                </head>
                <body>
                    <h2>' . $msg . '</h2>
                </body>
                </html>';
        exit;
    }

    /**
     * 运行
     */
    public static function run()
    {
        $nfss = new nfss();
        echo $nfss->dispatch();
    }
}

nfss::run();
