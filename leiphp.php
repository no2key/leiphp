<?php
/**
 * LeiPHP
 *
 * @author 老雷<leizongmin@gmail.com>
 * @version 0.2
 */

/* 处理不同的请求方法 */
function _leiphp_request_method_router () {
  // 如果已调用APP::end()，则不再执行此函数，因为在die后仍然会执行register_shutdown_function注册的函数
  if (APP::$is_exit) return;

  // 执行相应的请求方法
  $method = strtolower($_SERVER['REQUEST_METHOD']);
  $funcname = "method_$method";
  define('APP_TIMESTAMP_ROUTE', microtime(true));
  if (function_exists($funcname)) {
    $funcname();
  } elseif (function_exists('method_all')) {
    $funcname = 'method_all';
    method_all();
  } else {
    $funcname = 'method_undefine';
  }

  // 如果为非永久连接，则自动关闭数据库连接
  if (!SQL::$is_permanent) @SQL::close();

  // 显示调试信息
  $accept_type = strtolower(trim($_SERVER['HTTP_ACCEPT']));
  if (APP::$is_debug && substr($accept_type, 0, 9) == 'text/html') {
    $spent2 = round((microtime(true) - APP_TIMESTAMP_ROUTE) * 1000, 3);
    $spent = round((microtime(true) - APP_TIMESTAMP_START) * 1000, 3);
    $debug = DEBUG::clear();
    echo "<div style='
      font-size: 14px;
      line-height: 1.6em;
      text-align: left;
      color: #000;
      padding: 12px 8px;
      border: 1px solid #DDD;
      font-family: \"Microsoft yahei\", \"Helvetica Neue\", \"Lucida Grande\", \"Lucida Sans Unicode\", Helvetica, Arial, sans-serif !important;
      background-color: #EEE;
      margin-top: 50px;
'>Debug<br>Function $funcname spent: {$spent2}ms<br>Total spent: {$spent}ms<br>
<hr><pre style='
      font-family: \"Microsoft yahei\", \"Helvetica Neue\", \"Lucida Grande\", \"Lucida Sans Unicode\", Helvetica, Arial, sans-serif !important;
'>$debug</pre>
</div>";
  }
}

/**
 * 调试信息流操作
 */
if (!class_exists('DEBUG', false)) {
  class DEBUG {
    public static $stack = '';

    /**
     * 添加到DEBUG流
     *
     * @param string $msg
     * @param string $title
     */
    public static function put ($msg = '', $title = '') {
      if (APP::$is_debug) {
        if (!empty($title)) {
          $msg = "[$title] $msg";
        }
        $timestamp = round((microtime(true) - APP_TIMESTAMP_START) * 1000, 3).'ms';
        DEBUG::$stack .= "[$timestamp] $msg\r\n";
      }
    }

    /**
     * 获取DEBUG流
     *
     * @return string
     */
    public static function get () {
      return DEBUG::$stack;
    }

    /**
     * 清空DEBUG流，并返回之前的信息
     *
     * @return string
     */
    public static function clear () {
      $ret = DEBUG::$stack;
      DEBUG::$stack = '';
      return $ret;
    }
  }
}

/**
 * MySQL 数据库操作
 */
if (!class_exists('SQL', false)) {
  class SQL {

    // 当前数据库连接
    public static $connection = null;

    // 是否为永久连接
    public static $is_permanent = false;

    /**
     * 连接到数据库
     * 成功返回true, 失败返回false
     *
     * @param string $server
     * @param string $username
     * @param string $password
     * @param string $database
     * @param bool $permanent  是否永久连接
     * @return bool
     */
    public static function connect ($server = 'localhost:3306', $username = 'root', $password = '', $database = '', $permanent = true) {
      $timestamp = microtime(true);

      if ($permanent) {
        SQL::$connection = mysql_pconnect($server, $username, $password);
      } else {
        SQL::$connection = mysql_connect($server, $username, $password);
      }
      SQL::$is_permanent = $permanent;

      $r = mysql_select_db($database, SQL::$connection);
      DEBUG::put('Connected: '.$username.'@'.$server.' permanent='.$permanent.' spent: '.round((microtime(true) - $timestamp) * 1000, 3).'ms', 'MySQL');
      return $r;
    }

    /**
     * 获取出错信息
     * 返回数据格式：  {id:出错ID, error:出错描述}
     *
     * @return array
     */
    public static function error () {
      return array(
        'id'  =>    SQL::errno(),
        'error' =>  SQL::errmsg()
        );
    }

    /**
     * 返回出错代码
     *
     * @return int
     */
    public static function errno () {
      return mysql_errno(SQL::$connection);
    }

    /**
     * 返回出错描述信息
     *
     * @return string
     */
    public static function errmsg () {
      return mysql_error(SQL::$connection);
    }

    /**
     * 执行SQL语句
     *
     * @param string $sql
     * @return resource
     */
    public static function query ($sql) {
      $timestamp = microtime(true);
      $r = mysql_query($sql, SQL::$connection);
      $spent = round((microtime(true) - $timestamp) * 1000, 3);
      if ($r) {
        DEBUG::put('Query: '.$sql.' spent: '.$spent.'ms', 'MySQL');
      } else {
        DEBUG::put('Query: '.$sql.' fail: #'.SQL::errno().' '.SQL::errmsg().' spent: '.$spent, 'MySQL');
      }
      return $r;
    }

    /**
     * 查询并返回所有数据
     * 格式为： [{字段名:值, 字段名:值 ...}, ...]，返回false表示失败
     *
     * @param string $sql
     * @return array
     */
    public static function getAll ($sql, $where = null) {
      if (is_array($where)) return SQL::getAll2($sql, $where);

      $r = SQL::query($sql);
      if (!$r) return false;
      $data = array();
      while ($row = mysql_fetch_array($r, MYSQL_ASSOC)) {
        $data[] = $row;
      }
      return count($data) < 1 ? false : $data;
    }
    public static function getData ($sql) {
      return SQL::getAll($sql);
    }

    /**
     * 查询并返回一行数据 格式为 {字段名:值, 字段名:值 ...}，返回false表示失败
     *
     * @param string $sql
     * @return array
     */
    public static function getOne ($sql, $where = null) {
      if (is_array($where)) return SQL::getOne2($sql, $where);
      
      $sql .= ' LIMIT 1';
      $data = SQL::getAll($sql);
      return $data == false ? false : $data[0];
    }
    public static function getLine ($sql) {
      return SQL::getOne($sql);
    }

    /**
     * 执行SQL命令 返回影响的记录行数
     *
     * @param string $sql
     * @return int
     */
    public static function update ($sql, $where = null, $update = null) {
      if (is_array($where) && is_array($update)) return SQL::update2($sql, $where, $update);

      $r = SQL::query($sql);
      if (!$r) return false;
      return mysql_affected_rows(SQL::$connection);
    }
    public static function runSql ($sql) {
      return SQL::update($sql);
    }

    /**
     * 插入记录
     *
     * @param string $table
     * @param array $data
     * @return int
     */
    public static function insert ($table, $data) {
      if (!(is_array($data) && count($data) > 0)) return false;

      $table = SQL::escape($table);
      $fields = array();
      $values = array();
      foreach ($data as $f => $v) {
        $fields[] = '`'.SQL::escape($f).'`';
        $values[] = '\''.SQL::escape($v).'\'';
      }
      $fields = implode(', ', $fields);
      $values = implode(', ', $values);
      $sql = "INSERT INTO `$table` ($fields) VALUES ($values)";
      return SQL::update($sql) > 0 ? SQL::id() : false;
    }

    /**
     * 解析where条件
     *
     * @param array $where 例如： array(
     *                              'field' => 'values',
     *                              'link' =>  'OR'  // 可省略，默认为AND
     *                            )
     *                            array('field' => array(
     *                              'link' => 'OR', // 可省略，默认为AND
     *                              '>' =>    1200,
     *                              '<=' =>   555
     *                            ))
     * @return string
     */
    public static function _parseWhere ($where) {
      if (count($where) < 1) return '1';

      $items = array();
      $link = 'AND';
      foreach ($where as $f => $v) {
        if (strtolower($f) == 'link') {
          $link = strtoupper($v);
          continue;
        }
        $f = SQL::escape($f);
        if (is_array($v)) {
          $items2 = array();
          $link2 = 'AND';
          foreach ($v as $op1 => $op2) {
            if (strtolower($op1) == 'link') {
              $link2 = strtoupper($op2);
              continue;
            }
            $op2 = SQL::escape($op2);
            $items2[] = "`$f`$op1'$op2'";
          }
          $items[] = '('.implode(" $link2 ", $items2).')';
        } else {
          $v = SQL::escape($v);
          $items[] = "`$f`='$v'";
        }
      }
      return implode(" $link ", $items);
    }

    /**
     * 更新记录
     *
     * @param string $table
     * @param array $where
     * @param array $update
     * @return int
     */
    public static function update2 ($table, $where, $update) {
      if (!(is_array($where) && count($where) > 0 && is_array($update) && count($update) > 0)) return false;

      $table = SQL::escape($table);
      $set = array();
      foreach ($update as $f => $v) {
        $f = SQL::escape($f);
        $v = SQL::escape($v);
        $set[] = "`$f`='$v'";
      }
      $set = implode(', ', $set);
      $where = SQL::_parseWhere($where);
      $sql = "UPDATE `$table` SET $set WHERE $where";
      return SQL::update($sql);
    }

    /**
     * 删除记录
     *
     * @param string $table
     * @param array $where
     * @return int
     */
    public static function delete ($table, $where) {
      if (!is_array($where) && count($where) > 0) return false;

      $table = SQL::escape($table);
      $where = SQL::_parseWhere($where);
      $sql = "DELETE FROM `$table` WHERE $where";
      return SQL::update($sql);
    }

    /**
     * 查询一条记录
     * 
     * string $table
     * @param array $where
     * @return array
     */
    public static function getOne2 ($table, $where) {
      if (!is_array($where) && count($where) > 0) return false;

      $table = SQL::escape($table);
      $where = SQL::_parseWhere($where);
      $sql = "SELECT * FROM `$table` WHERE $where";
      return SQL::getOne($sql);
    }

    /**
     * 查询记录
     * 
     * string $table
     * @param array $where
     * @return array
     */
    public static function getAll2 ($table, $where) {
      if (!is_array($where) && count($where) > 0) return false;

      $table = SQL::escape($table);
      $where = SQL::_parseWhere($where);
      $sql = "SELECT * FROM `$table` WHERE $where";
      return SQL::getAll($sql);
    }

    /**
     * 取最后插入ID
     *
     * @return int
     */
    public static function id () {
      return mysql_insert_id(SQL::$connection);
    }
    public static function lastId () {
      return SQL::id();
    }

    /**
     * 转换为SQL安全字符串
     *
     * @param string $str
     * @return string
     */
    public static function escape ($str) {
      return  mysql_real_escape_string($str, SQL::$connection);
    }

    /**
     * 关闭SQL连接
     */
    public static function close () {
      DEBUG::put('Close connection.', 'MySQL');
      return @mysql_close(SQL::$connection);
    }
  }
} else {
  DEBUG::put('Class SQL is already exists!', 'Warning');
}

/**
 * 上传文件管理
 */
if (!class_exists('UPLOAD', false)) {
  class UPLOAD {
    /**
     * 获取上传文件
     * 返回格式：{name: 名称, type: 文件MIME类型, size: 大小, tmp_name: 临时文件名}
     *
     * @param string $filename
     * @return array
     */
    public static function get ($filename) {
      if (isset($_FILES[$filename])) {
        $uploaded_file = array(
          'name' => $_FILES[$filename]['name'],
          'type' => $_FILES[$filename]['type'],
          'size' => $_FILES[$filename]['size'],
          'tmp_name' => $_FILES[$filename]['tmp_name']
          );
      } elseif (isset($_POST[$filename])) {
        $uploaded_file = array(
          'name' => $_POST[$filename],
          );
      } elseif (isset($GLOBALS['HTTP_POST_FILES'][$filename])) {
        global $HTTP_POST_FILES;
        $uploaded_file = array(
          'name' => $HTTP_POST_FILES[$filename]['name'],
          'type' => $HTTP_POST_FILES[$filename]['type'],
          'size' => $HTTP_POST_FILES[$filename]['size'],
          'tmp_name' => $HTTP_POST_FILES[$filename]['tmp_name']
          );
      } elseif (isset($GLOBALS['HTTP_POST_VARS'][$filename])) {
        global $HTTP_POST_VARS;
        $uploaded_file = array(
          'name' => $HTTP_POST_VARS[$filename],
          );
      } else {
        $uploaded_file = array(
          'name' => $GLOBALS[$filename . '_name'],
          'type' => $GLOBALS[$filename . '_type'],
          'size' => $GLOBALS[$filename . '_size'],
          'tmp_name' => $GLOBALS[$filename]
        );
      }
      return $uploaded_file;
    }

    /**
     * 移动临时文件
     *
     * @param {array} $file
     * @param {string} $target
     * @return {string}
     */
    public static function move ($file, $target) {
      $timestamp = microtime(true);
      $source = is_array($file) ? $file['tmp_name'] : $file;
      move_uploaded_file($source, $target);
      DEBUG::put('Move '.$source.' to '.$target.' spent: '.round((microtime(true) - $timestamp) * 1000, 3).'ms', 'Upload');
      return $target;
    }
  }
} else {
  DEBUG::put('Class UPLOAD is already exists!', 'Warning');
}

if (!class_exists('ROUTER', false)) {
  class ROUTER {

    // 中间件列表
    public static $_list = array();

    /**
     * 开始自动路由
     *
     * @param string $dir  目录，默认为 action 表示应用目录下的action目录
     * @param string $path 路径，默认使用 $_GET['__path__']，如为空则为 /
     */
    public static function run ($dir = 'action', $path = '_____NORMAL_____') {
      // 目录不能以/开头及结尾
      if (substr($dir, 0, 1) == '/') $dir = substr($dir, 1);
      if (substr($dir, -1) == '/') $dir = substr($dir, strlen($dir) - 1);
      // 路径必须以/开头，但不能以/结尾
      if ($path == '_____NORMAL_____') $path = @$_GET['__path__'];
      if (empty($path)) $path = '/';
      if (substr($path, 0, 1) != '/') $path = '/'.$path;
      if ($path != '/' && substr($path, -1) == '/') $path = substr($path, strlen($path) - 1);

      // 中间件处理
      ROUTER::runMiddleware($path);

      $filename = APP_ROOT.$dir.$path.(substr($path, -1) == '/' ? '/index' : '').'.php';
      DEBUG::put("path=$path, file=$filename", 'Router');
      if (file_exists($filename)) {
        require($filename);
      } else {
        ROUTER::notFound($path, $filename);
      }
    }

    /**
     * 路由未找到
     */
    public static function notFound ($path, $filename) {
      @header("HTTP/1.1 404 Not Found");
      APP::showError("Path \"$path\" Not Found.");
      DEBUG::put("Not found: path=$path, file=$filename", 'Router');
    }

    /**
     * 注册中间件
     *
     * @param string $path        路径
     * @param callback $function  要执行的函数
     * @param bool $is_preg       路径是否为正则表达式，默认为false
     */
    public static function register ($path, $function, $is_preg = false) {
      ROUTER::$_list[] = array($path, $function, $is_preg);
      DEBUG::put("Use: $path => $function", 'Router');
    }

    /**
     * 执行中间件
     *
     * @param string $path
     */
    public static function runMiddleware ($path) {
      $pathlen = strlen($path);
      foreach (ROUTER::$_list as $i => $v) {
        $p = $v[0];
        $f = $v[1];
        $is_preg = $v[2];
        if ($is_preg) {
          if (!preg_match($p, $path)) continue;
        } else {
          $pl = strlen($p);
          if ($pl > $pathlen) continue;
          if (substr($path, 0, $pl) != $p) continue;
        }
        @call_user_func($f, $path);
      }
    }
  }
} else {
  DEBUG::put('Class ROUTER is already exists!', 'Warning');
}

class APP {

  // 版本号
  public static $version = 1;

  // 是否提前退出
  public static $is_exit = false;

  // 是否为调试状态
  public static $is_debug = false;

  // 模板变量
  public static $locals = array();

  /**
   * 账户验证加密解密函数 （来自Discuz!的authcode函数）
   *
   * @param string $string 明文 或 密文
   * @param string $operation DECODE表示解密,其它表示加密
   * @param string $key 密匙
   * @param int $expiry 密文有效期
   *
   * @return string
   */
  public static function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0) {
    $ckey_length = 4;
    $key = md5($key ? $key : 'leiphp-default-key');
    $keya = md5(substr($key, 0, 16));
    $keyb = md5(substr($key, 16, 16));
    $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length): substr(md5(microtime()), -$ckey_length)) : '';
    $cryptkey = $keya.md5($keya.$keyc);
    $key_length = strlen($cryptkey);
    $string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
    $string_length = strlen($string);
    $result = '';
    $box = range(0, 255);
    $rndkey = array();
    for($i = 0; $i <= 255; $i++) {
      $rndkey[$i] = ord($cryptkey[$i % $key_length]);
    }
    for($j = $i = 0; $i < 256; $i++) {
      $j = ($j + $box[$i] + $rndkey[$i]) % 256;
      $tmp = $box[$i];
      $box[$i] = $box[$j];
      $box[$j] = $tmp;
    }
    for($a = $j = $i = 0; $i < $string_length; $i++) {
      $a = ($a + 1) % 256;
      $j = ($j + $box[$a]) % 256;
      $tmp = $box[$a];
      $box[$a] = $box[$j];
      $box[$j] = $tmp;
      $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
    }
    if($operation == 'DECODE') {
      if((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
        return substr($result, 26);
      } else {
        return '';
      }
    } else {
      return $keyc.str_replace('=', '', base64_encode($result));
    }
  }

  /**
   * 加密账户验证信息
   *
   * @param string $string  要加密的字符串
   * @param string $key     密匙
   * @param int $expiry     从现在起的有效时间（秒）
   * @return string
   */
  public static function authEncode ($string, $key, $expiry = 0) {
    return APP::authcode($string, 'ENCODE', $key, $expiry);
  }

  /**
   * 解密账户验证信息
   *
   * @param string $string  密文
   * @param string $key     密匙
   * @return string
   */
  public static function authDecode ($string, $key) {
    return APP::authcode($string, 'DECODE', $key);
  }

  /**
   * 加密密码
   *
   * @param string $password
   * @return string
   */
  public static function encryptPassword ($password) {
    $random = strtoupper(md5(rand().rand()));
    $left = substr($random, 0, 2);
    $right = substr($random, -2);
    $newpassword = strtoupper(md5($left.$password.$right));
    return $left.':'.$newpassword.':'.$right;
  }

  /**
   * 验证密码
   *
   * @param string $password 待验证的密码
   * @param string $encrypted 密码加密字符串
   * @return bool
   */
  public static function validatePassword ($password, $encrypted) {
    $random = explode(':', strtoupper($encrypted));
    if (count($random) < 3) return false;
    $left = $random[0];
    $right = $random[2];
    $main = $random[1];
    $newpassword = strtoupper(md5($left.$password.$right));
    return $newpassword == $main;
  }

  /**
   * 显示出错信息
   *
   * @param string $msg
   */
  public static function showError ($msg) {
    $accept_type = strtolower(trim($_SERVER['HTTP_ACCEPT']));
    if (strpos($accept_type, 'json') !== false) {
      APP::sendJSON(array('error' => $msg));
    } else {
      echo "<div style='color: #900;
            font-size: 16px;
            border: 1px solid #900;
            padding: 8px 12px;
            border-radius: 5px;
            margin: 12px 0px;'>$msg</div>";
    }
  }

  /**
   * 载入模板
   * 如果指定了参数$layout，则会嵌套一个layout模板
   *
   * @param string $name   模板名
   * @param array $locals  变量
   * @return string
   */
  public static function getTemplate ($name, $locals = array()) {
    if (!pathinfo($name, PATHINFO_EXTENSION)) $name = $name.'.html';
    $filename = APP_TEMPLATE_ROOT.$name;
    $timestamp = microtime(true);
    ob_start();
    extract($locals, EXTR_SKIP);
    include($filename);
    $html = ob_get_clean();
    DEBUG::put('Render '.$filename.' spent: '.round((microtime(true) - $timestamp) * 1000, 3).'ms', 'Template');
    return $html;
  }

  /**
   * 渲染模板，自动使用APP::$locals中的数据
   * 如果指定了参数$layout，则会嵌套一个layout模板
   *
   * Examples:
   * APP::render('template');
   * APP::render('template', $locals);
   * APP::render('template', 'layout');
   * APP::render('template', $locals, 'layout');
   *
   * @param string $name
   * @param array $locals
   * @param string $layout
   */
  public static function render ($name, $locals = array(), $layout = '') {
    if (!is_array($locals)) {
      $layout = $locals;
      $locals = array();
    }

    foreach (APP::$locals as $i => $v) {
      if (!isset($locals[$i])) $locals[$i] = $v;
    }

    $body = APP::getTemplate($name, $locals);
    if (empty($layout)) {
      echo $body;
    } else {
      $locals['body'] = $body;
      echo APP::getTemplate($layout, $locals);
    }
  }

  /**
   * 设置模板变量
   *
   * @param string $name
   * @param mixed $value
   * @return mixed
   */
  public static function setLocals ($name, $value = null) {
    APP::$locals[$name] = $value;
    return @APP::$locals[$name];
  }

  /**
   * 取模板变量
   *
   * @param string $name
   * @return mixed
   */
  public static function getLocals ($name) {
    return @APP::$locals[$name];
  }

  /**
   * 返回JSON格式数据
   *
   * @param mixed $data
   */
  public static function sendJSON ($data = null) {
    @header('content-type: application/json');
    if (is_array($data) && APP::$is_debug) $data['debug'] = DEBUG::get();
    echo json_encode($data);
    APP::end();
  }

  /**
   * 返回JSON格式的出错信息
   *
   * @param string $msg   出错信息
   * @param array $data   其他数据
   */
  public static function sendError ($msg, $data = array()) {
    $data['error'] = $msg;
    APP::sendJSON($data);
  }

  /**
   * 加载文件
   * 文件名如果不指定扩展名，则自动加上.php再加载
   * 如果以 / 开头，则从应用根目录开始查找
   *
   * @param string $filename
   * @return mixed
   */
  public static function load ($filename) {
    if (!pathinfo($filename, PATHINFO_EXTENSION)) {
      $filename = $filename.'.php';
    }
    if (substr($filename, 0, 1) == '/') {
      $filename = APP_ROOT.substr($filename, 1);
    } else {
      $filename = dirname($_SERVER["SCRIPT_FILENAME"]).'/'.$filename;
    }
    return require($filename);
  }

  /**
   * 调试输出
   *
   * @param mixed $var
   */
  public static function dump ($var) {
    echo '<pre>';
    print_r($var);
    echo '</pre>';
  }

  /**
   * 初始化
   */
  public static function init () {
    // 是否关闭出错显示
    if (defined('APP_DEBUG') && APP_DEBUG) {
      APP::$is_debug = true;
      error_reporting(E_ALL);
      ini_set('display_errors', '1');
    } else {
      error_reporting(0);
      ini_set('display_errors', '0');
    }

    // 开始时间
    define('APP_TIMESTAMP_START', microtime(true));
    
    // 只要定义了数据库配置中的任一项均自动连接数据库
    if (defined('CONF_MYSQL_SERVER') || defined('CONF_MYSQL_USER') ||
        defined('CONF_MYSQL_PASSWD') || defined('CONF_MYSQL_DBNAME')) {
      $server = defined('CONF_MYSQL_SERVER') ? CONF_MYSQL_SERVER : 'localhost:3306';
      $user = defined('CONF_MYSQL_USER') ? CONF_MYSQL_USER : 'root';
      $passwd = defined('CONF_MYSQL_PASSWD') ? CONF_MYSQL_PASSWD : '';
      $dbname = defined('CONF_MYSQL_DBNAME') ? CONF_MYSQL_DBNAME : '';
      $permanent = defined('CONF_MYSQL_PERMANENT') ? CONF_MYSQL_PERMANENT : false;
      SQL::connect($server, $user, $passwd, $dbname, $permanent);
    }

    // 自动执行 method_VERB
    register_shutdown_function('_leiphp_request_method_router');
  }

  /**
   * 提前退出
   */
  public static function end () {
    APP::$is_exit = true;
    die;
  }
}
