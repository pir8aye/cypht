<?php

if (!defined('DEBUG_MODE')) { die(); }

/* base configuration */
abstract class Hm_Config {

    protected $source = false;
    protected $config = array();

    abstract public function load($source, $key);

    public function dump() {
        return $this->config;
    }

    public function set($name, $value) {
        $this->config[$name] = $value;
    }

    public function get($name, $default=false) {
        return isset($this->config[$name]) ? $this->config[$name] : $default;
    }
    protected function set_tz() {
        date_default_timezone_set($this->get('timezone_setting', 'UTC'));
    }
}

/* file based user configuration */
class Hm_User_Config_File extends Hm_Config {

    private $site_config = false;

    public function __construct($config) {
        $this->site_config = $config;
    }
    private function get_path($username) {
        $path = $this->site_config->get('user_settings_dir', false);
        return sprintf('%s/%s.txt', $path, $username);
    }

    public function load($username, $key) {
        $source = $this->get_path($username);
        if (is_readable($source)) {
            $str_data = file_get_contents($source);
            if ($str_data) {
                $data = @unserialize(Hm_Crypt::plaintext($str_data, $key));
                if (is_array($data)) {
                    $this->config = array_merge($this->config, $data);
                    $this->set_tz();
                }
            }
        }
    }

    public function reload($data) {
        $this->config = $data;
        $this->set_tz();
    }

    public function save($username, $key) {
        $destination = $this->get_path($username);
        $data = Hm_Crypt::ciphertext(serialize($this->config), $key);
        file_put_contents($destination, $data);
    }
}

/* db based user configuration */
class Hm_User_Config_DB extends Hm_Config {

    private $site_config = false;
    private $dbh = false;

    public function __construct($config) {
        $this->site_config = $config;
    }

    public function load($username, $key) {
        if ($this->connect()) {
            $sql = $this->dbh->prepare("select * from hm_user_settings where username=?");
            if ($sql->execute(array($username))) {
                $data = $sql->fetch();
                if (!$data || !isset($data['settings'])) {
                    $sql = $this->dbh->prepare("insert into hm_user_settings values(?,?)");
                    if ($sql->execute(array($username, ''))) {
                        Hm_Debug::add(sprintf("created new row in hm_user_settings for %s", $username));
                        $this->config = array();
                    }
                }
                else {
                    $data = @unserialize(Hm_Crypt::plaintext($data['settings'], $key));
                    if (is_array($data)) {
                        $this->config = array_merge($this->config, $data);
                        $this->set_tz();
                    }
                }
            }
        }
    }

    public function reload($data) {
        $this->config = $data;
        $this->set_tz();
    }

    protected function connect() {
        $this->dbh = Hm_DB::connect($this->site_config);
        if ($this->dbh) {
            return true;
        }
        return false;
    }

    public function save($username, $key) {
        $config = Hm_Crypt::ciphertext(serialize($this->config), $key);
        if ($this->connect()) {
            $sql = $this->dbh->prepare("update hm_user_settings set settings=? where username=?");
            if ($sql->execute(array($config, $username))) {
                Hm_Debug::add(sprintf("Saved user data to DB for %s", $username));
            }
        }
    }
}

/* file based site configuration */
class Hm_Site_Config_File extends Hm_Config {

    public function __construct($source) {
        $this->load($source, false);
    }

    public function load($source, $key) {
        if (is_readable($source)) {
            $data = unserialize(file_get_contents($source));
            if ($data) {
                $this->config = array_merge($this->config, $data);
            }
        }
    }
}

/* handle page processing delegation */
class Hm_Router {

    public $type = false;
    public $sapi = false;
    private $page = 'home';

    public function process_request($config) {
        if (DEBUG_MODE) {
            $filters = array();
            $filters = array('allowed_get' => array(), 'allowed_cookie' => array(), 'allowed_post' => array(), 'allowed_server' => array(), 'allowed_pages' => array());
            $modules = explode(',', $config->get('modules', array()));
            foreach ($modules as $name) {
                if (is_readable(sprintf("modules/%s/setup.php", $name))) {
                    $filters = Hm_Router::merge_filters($filters, require sprintf("modules/%s/setup.php", $name));
                }
            }
            $handler_mods = array();
            $output_mods = array();
        }
        else {
            $filters = $config->get('input_filters', array());
            $handler_mods = $config->get('handler_modules', array());
            $output_mods = $config->get('output_modules', array());
        }

        /* process inbound data */
        $request = new Hm_Request($filters);

        /* check for HTTP TLS */
        $this->check_for_tls($config, $request);

        /* initiate a session class */
        $session = $this->setup_session($config);

        /* determine page or ajax request name */
        $this->get_page($request, $filters['allowed_pages']);

        /* load processing modules for this page */
        $this->load_modules($config, $handler_mods, $output_mods);

        /* run all the handler modules for a page and merge in some standard results */
        $result = $this->merge_response($this->process_page($request, $session, $config), $config, $request, $session);

        /* check for a POST redirect */
        $prior_results = $this->forward_redirect_data($session, $request);

        /* merge post redirect data */
        $result = array_merge($result, $prior_results);

        /* see if we should redirect this request */
        $this->check_for_redirect($request, $session, $result);

        /* return processed data */
        return array($result, $session);
    }

    private function check_for_tls($config, $request) {
        if (!$request->tls && !$config->get('disable_tls', false)) {
            $this->redirect('https://'.$request->server['SERVER_NAME'].$request->server['REQUEST_URI']);
        }
    }

    private function setup_session($config) {
        $session_type = $config->get('session_type', false);
        $auth_type = $config->get('auth_type', false);

        switch ($session_type.$auth_type) {
            case 'DBDB':
                Hm_Debug::add('Using DB auth and DB sessions');
                $session = new Hm_DB_Session_DB_Auth($config);
                break;
            case 'PHPDB':
                Hm_Debug::add('Using DB auth and PHP sessions');
                $session = new Hm_PHP_Session_DB_Auth($config);
                break;
            case 'PHPIMAP':
                Hm_Debug::add('Using IMAP auth and PHP sessions');
                $session = new Hm_PHP_Session_IMAP_Auth($config);
                break;
            case 'PHPPOP3':
                Hm_Debug::add('Using POP3 auth and PHP sessions');
                $session = new Hm_PHP_Session_POP3_Auth($config);
                break;
            case 'DBIMAP':
                Hm_Debug::add('Using IMAP auth and DB sessions');
                $session = new Hm_DB_Session_IMAP_Auth($config);
                break;
            case 'DBPOP3':
                Hm_Debug::add('Using POP3 auth and DB sessions');
                $session = new Hm_DB_Session_POP3_Auth($config);
                break;
            default:
                Hm_Debug::add('Using default PHP sessions with no auth');
                $session = new Hm_PHP_Session($config);
                break;
        }
        return $session;
    }
    private function get_active_mods($mod_list) {
        return array_unique(array_values(array_map(function($v) { return $v['source']; }, $mod_list)));
    }

    private function load_modules($config, $handlers=array(), $output=array()) {

        foreach ($handlers as $page => $modlist) {
            foreach ($modlist as $name => $vals) {
                if ($this->page == $page) {
                    Hm_Handler_Modules::add($page, $name, $vals['logged_in'], false, 'after', true, $vals['source']);
                }
            }
        }
        Hm_Handler_Modules::try_queued_modules();

        foreach ($output as $page => $modlist) {
            foreach ($modlist as $name => $vals) {
                if ($this->page == $page) {
                    Hm_Output_Modules::add($page, $name, $vals['logged_in'], false, 'after', true, $vals['source']);
                }
            }
        }
        Hm_Output_Modules::try_queued_modules();
        $active_mods = array_unique(array_merge($this->get_active_mods(Hm_Output_Modules::get_for_page($this->page)),
            $this->get_active_mods(Hm_Handler_Modules::get_for_page($this->page))));

        $mods = explode(',', $config->get('modules', '')); 
        foreach ($mods as $name) {
            if (in_array($name, $active_mods) && is_readable(sprintf('modules/%s/modules.php', $name))) {
                require sprintf('modules/%s/modules.php', $name);
            }
        }
    }

    private function forward_redirect_data($session, $request) {
        $res = array();
        if (isset($request->cookie['hm_msgs']) && trim($request->cookie['hm_msgs'])) {
            $msgs = @unserialize(base64_decode($request->cookie['hm_msgs']));
            if (is_array($msgs)) {
                array_walk($msgs, function($v) { Hm_Msgs::add($v); });
            }
            secure_cookie($request, 'hm_msgs', '', 0);
        }
        return $res;
    }

    private function check_for_redirect($request, $session, $result) {
        if (!empty($request->post) && $request->type == 'HTTP') {
            $msgs = Hm_Msgs::get();
            if (!empty($msgs)) {
                secure_cookie($request, 'hm_msgs', base64_encode(serialize($msgs)), 0);
            }
            $session->end();
            $this->redirect($request->server['REQUEST_URI']);
        }
    }

    private function get_page($request, $pages) {
        if ($request->type == 'AJAX' && isset($request->post['hm_ajax_hook']) && in_array($request->post['hm_ajax_hook'], $pages)) {
            $this->page = $request->post['hm_ajax_hook'];
        }
        elseif ($request->type == 'AJAX' && isset($request->post['hm_ajax_hook']) && !in_array($request->post['hm_ajax_hook'], $pages)) {
            die(json_encode(array('status' => 'not callable')));;
        }
        elseif (isset($request->get['page']) && in_array($request->get['page'], $pages)) {
            $this->page = $request->get['page'];
        }
        elseif (!isset($request->get['page'])) {
            $this->page = 'home';
        }
        else {
            $this->page = 'notfound';
        }
    }

    private function process_page($request, $session, $config) {
        $response = array();
        $handler = new Hm_Request_Handler();
        $response = $handler->process_request($this->page, $request, $session, $config);
        return $response;
    }
    private function build_nonce_base($session, $config, $request) {
        $result = $session->get('username', false);
        if (isset($request->cookie['hm_id'])) {
            $result .= $request->cookie['hm_id']; 
        }
        elseif ($config->get('enc_key', false)) {
            $result .= $config->get('enc_key', false);
        }
        return $result;
    }

    private function merge_response($response, $config, $request, $session) {
        return array_merge($response, array(
            'router_page_name'    => $this->page,
            'router_nonce_base'   => $this->build_nonce_base($session, $config, $request),
            'router_request_type' => $request->type,
            'router_sapi_name'    => $request->sapi,
            'router_format_name'  => $request->format,
            'router_login_state'  => $session->active,
            'router_url_path'     => $request->path,
            'router_module_list'  => $config->get('modules', '')
        ));
    }

    public function redirect($url) {
        header('HTTP/1.1 303 Found');
        header('Location: '.$url);
        exit;
    }

    static public function merge_filters($existing, $new) {
        foreach (array('allowed_get', 'allowed_cookie', 'allowed_post', 'allowed_server', 'allowed_pages') as $v) {
            if (isset($new[$v])) {
                if ($v == 'allowed_pages') {
                    $existing[$v] = array_merge($existing[$v], $new[$v]);
                }
                else {
                    $existing[$v] += $new[$v];
                }
            }
        }
        return $existing;
    }
}

/* data request details */
class Hm_Request {

    public $post = array();
    public $get = array();
    public $cookie = array();
    public $server = array(); 
    public $type = false;
    public $sapi = false;
    public $format = false;
    public $tls = false;
    public $path = '';

    public function __construct($filters) {
        $this->sapi = php_sapi_name();
        $this->get_request_type();

        if ($this->type == 'HTTP' || $this->type == 'AJAX') {
            $this->server = filter_input_array(INPUT_SERVER, $filters['allowed_server'], false);
            $this->post = filter_input_array(INPUT_POST, $filters['allowed_post'], false);
            $this->get = filter_input_array(INPUT_GET, $filters['allowed_get'], false);
            $this->cookie = filter_input_array(INPUT_COOKIE, $filters['allowed_cookie'], false);
            $this->path = $this->get_clean_url_path($this->server['REQUEST_URI']);
            $this->is_tls();
        }
        unset($_POST);
        unset($_SERVER);
        unset($_GET);
        unset($_COOKIE);
    }

    private function is_tls() {
        if (isset($this->server['HTTPS']) && strtolower($this->server['HTTPS']) == 'on') {
            $this->tls = true;
        }
        elseif (isset($this->server['REQUEST_SCHEME']) && strtolower($this->server['REQUEST_SCHEME']) == 'https') {
            $this->tls = true;
        }
    }

    private function get_request_type() {
        if ($this->is_cli()) {
            die("CLI support not implemented\n");
        }
        elseif ($this->is_ajax()) {
            $this->type = 'AJAX';
            $this->format = 'Hm_Format_JSON';
        }
        else {
            $this->type = 'HTTP';
            $this->format = 'Hm_Format_HTML5';
        }
    }

    private function is_cli() {
        return strtolower(php_sapi_name()) == 'cli';
    }

    private function is_ajax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function get_clean_url_path($uri) {
        if (strpos($uri, '?') !== false) {
            $parts = explode('?', $uri, 2);
            $path = $parts[0];
        }
        else {
            $path = $uri;
        }
        if (substr($path, -1) != '/') {
            $path .= '/';
        }
        return $path;
    }
}

/* data handler module runner */
class Hm_Request_Handler {

    public $page = false;
    public $request = false;
    public $session = false;
    public $config = false;
    public $user_config = false;
    public $response = array();
    private $modules = array();

    public function process_request($page, $request, $session, $config) {
        $this->page = $page;
        $this->request = $request;
        $this->session = $session;
        $this->config = $config;
        $this->modules = Hm_Handler_Modules::get_for_page($page);
        $this->load_user_config_object();
        $this->run_modules();
        $this->default_language();
        return $this->response;
    }

    private function load_user_config_object() {
        $type = $this->config->get('user_config_type', 'file');
        switch ($type) {
            case 'DB':
                $this->user_config = new Hm_User_Config_DB($this->config);
                Hm_Debug::add("Using DB user configuration");
                break;
            case 'file':
            default:
                $this->user_config = new Hm_User_Config_File($this->config);
                Hm_Debug::add("Using file based user configuration");
                break;
        }
    }

    private function default_language() {
        if (!isset($this->response['language'])) {
            $default_lang = $this->config->get('default_language', false);
            if ($default_lang) {
                $this->response['language'] = $default_lang;
            }
        }
    }

    protected function run_modules() {
        foreach ($this->modules as $name => $args) {
            $input = false;
            $name = "Hm_Handler_$name";
            if (class_exists($name)) {
                if (!$args['logged_in'] || ($args['logged_in'] && $this->session->active)) {
                    $mod = new $name( $this, $args['logged_in']);
                    $input = $mod->process($this->response);
                }
            }
            else {
                Hm_Debug::add(sprintf('Handler module %s activated but not found', $name));
            }
            if ($input) {
                $this->response = $input;
            }
        }
    }
}

/* base class for output formatting */
abstract class HM_Format {

    protected $modules = false;

    abstract protected function content($input, $lang_str);

    public function format_content($input) {
        $lang_strings = array();
        if (isset($input['language'])) {
            $lang_strings = $this->get_language($input['language']);
        }
        $this->modules = Hm_Output_Modules::get_for_page($input['router_page_name']);
        $formatted = $this->content($input, $lang_strings);
        return $formatted;
    }

    private function get_language($lang) {
        $strings = array();
        if (file_exists('language/'.$lang.'.php')) {
            $strings = require 'language/'.$lang.'.php';
        }
        return $strings;
    }
    protected function run_modules($input, $format, $lang_str) {
        $mod_output = array();

        foreach ($this->modules as $name => $args) {
            $name = "Hm_Output_$name";
            if (class_exists($name)) {
                if (!$args['logged_in'] || ($args['logged_in'] && $input['router_login_state'])) {
                    $mod = new $name($input);
                    if ($format == 'JSON') {
                        $mod_output = $mod->output_content($input, $format, $lang_str);
                        if ($mod_output) {
                            $input = $mod_output;
                        }
                    }
                    else {
                        $mod_output[] = $mod->output_content($input, $format, $lang_str);
                    }
                }
            }
            else {
                Hm_Debug::add(sprintf('Output module %s activated but not found', $name));
            }
        }
        if (empty($mod_output)) {
            return $input;
        }
        return $mod_output;
    }
}

/* JSON output format */
class Hm_Format_JSON extends HM_Format {

    public function content($input, $lang_str) {
        $input['router_user_msgs'] = Hm_Msgs::get();
        $output = $this->run_modules($input, 'JSON', $lang_str);
        unset($output['session_type']);
        return json_encode($output, JSON_FORCE_OBJECT);
    }
}

/* HTML5 output format */
class Hm_Format_HTML5 extends HM_Format {

    public function content($input, $lang_str) {
        $output = $this->run_modules($input, 'HTML5', $lang_str);
        return implode('', $output);
    }
}

/* base output class */
abstract class Hm_Output {

    abstract protected function output_content($content);

    public function send_response($response, $input=array()) {
        if (isset($input['http_headers'])) {
            $this->output_content($response, $input['http_headers']);
        }
        else {
            $this->output_content($response);
        }
    }
}

/* HTTP output class */
class Hm_Output_HTTP extends Hm_Output {

    protected function output_headers($headers) {
        foreach ($headers as $header) {
            header($header);
        }
    }

    protected function output_content($content, $headers=array()) {
        $this->output_headers($headers);
        ob_end_clean();
        echo $content;
    }
}

/* STDOUT output class */
class Hm_Output_STDOUT extends Hm_Output {

    protected function output_content($content) {
        $stdout = fopen('php://stdout', 'w');
        fwrite($stdout, $content);
        fclose($stdout);
    }
}

/* file output class */
class Hm_Output_File extends Hm_Output {

    public $filename = 'test.out';

    protected function output_content($content) {
        $fh = fopen($this->filename, 'a');
        fwrite($fh, $content);
        fclose($fh);
    }
}

/* output sanitizing */
trait Hm_Sanitize {

    public function html_safe($string) {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/* interface and debug mssages */
trait Hm_List {

    private static $msgs = array();

    public static function add($string) {
        self::$msgs[] = $string;
    }

    public static function get() {
        return self::$msgs;
    }

    public static function show($type='print') {
        if ($type == 'log') {
            error_log(str_replace(array("\n", "\t", "  "), array(' '), print_r(self::$msgs, true)));
        }
        elseif ($type == 'return') {
            return print_r(self::$msgs, true);
        }
        else {
            print_r(self::$msgs);
        }
    }
}
class Hm_Msgs { use Hm_List; }
class Hm_Debug { use Hm_List;

    public static function load_page_stats() {
        self::add(sprintf("PHP version %s", phpversion()));
        self::add(sprintf("Zend version %s", zend_version()));
        self::add(sprintf("Peak Memory: %d", (memory_get_peak_usage(true)/1024)));
        self::add(sprintf("PID: %d", getmypid()));
        self::add(sprintf("Included files: %d", count(get_included_files())));
    }
}

/* base handler module */
abstract class Hm_Handler_Module {

    protected $session = false;
    protected $request = false;
    protected $config = false;
    protected $page = false;
    protected $user_data = false;

    public function __construct($parent, $logged_in) {
        $this->session = $parent->session;
        $this->request = $parent->request;
        $this->config = $parent->config;
        $this->user_config = $parent->user_config;
        $this->page = $parent->page;
    }

    protected function process_form($form, $nonce=false) {
        $post = $this->request->post;
        $success = false;
        $new_form = array();
        foreach($form as $name) {
            if (isset($post[$name]) && (trim($post[$name]) || (($post[$name] === '0' ||  $post[$name] === 0 )))) {
                $new_form[$name] = $post[$name];
            }
        }
        if (count($form) == count($new_form)) {
            $success = true;
        }
        if ($nonce && $success) {
            $success = false;
            if (isset($post['hm_nonce'])) {
                $key = $this->session->get('username', false);
                if (isset($this->request->cookie['hm_id'])) {
                    $key .= $this->request->cookie['hm_id'];
                }
                elseif ($this->config->get('enc_key', false)) {
                    $key .= $this->config->get('enc_key', false);
                }
                if (hash_hmac('sha256', $nonce, $key) == $post['hm_nonce']) {
                    $success = true;
                }
            }
            else {
                $success = false;
            }
        }
        return array($success, $new_form);
    }

    abstract public function process($data);
}

/* base output module */
abstract class Hm_Output_Module {

    use Hm_Sanitize;

    protected $lstr = array();
    protected $lang = false;
    protected $nonce_base = false;

    function __construct($input) {
        $this->nonce_base = $input['router_nonce_base'];
    }

    abstract protected function output($input, $format);

    protected function build_nonce($name) {
        return hash_hmac('sha256', $name, $this->nonce_base);
    }
    public function trans($string) {
        if (isset($this->lstr[$string])) {
            if ($this->lstr[$string] === false) {
                return $string;
            }
            else {
                return $this->lstr[$string];
            }
        }
        else {
            Hm_Debug::add(sprintf('No translation found: %s', $string));
        }
        return $string;
    }

    public function output_content($input, $format, $lang_str) {
        $this->lstr = $lang_str;
        if (isset($lang_str['interface_lang'])) {
            $this->lang = $lang_str['interface_lang'];
        }
        return $this->output($input, $format);
    }
}

/* module managers */
trait Hm_Modules {

    private static $module_list = array();
    private static $source = false;
    private static $module_queue = array();

    public static function load($mod_list) {
        self::$module_list = $mod_list;
    }
    public static function set_source($source) {
        self::$source = $source;
    }
    public static function add($page, $module, $logged_in, $marker=false, $placement='after', $queue=true, $source=false) {
        $inserted = false;
        if (!isset(self::$module_list[$page])) {
            self::$module_list[$page] = array();
        }
        if (isset(self::$module_list[$page][$module])) {
            Hm_Debug::add(sprintf("Already registered module re-attempted: %s", $module));
            return;
        }
        if (!$source) {
            $source = self::$source;
        }
        if ($marker) {
            $mods = array_keys(self::$module_list[$page]);
            $index = array_search($marker, $mods);
            if ($index !== false) {
                if ($placement == 'after') {
                    $index++;
                }
                $list = self::$module_list[$page];
                self::$module_list[$page] = array_merge(array_slice($list, 0, $index), 
                    array($module => array('source' => $source, 'logged_in' => $logged_in)),
                    array_slice($list, $index));
                $inserted = true;
            }
        }
        else {
            $inserted = true;
            self::$module_list[$page][$module] = array('source' => $source, 'logged_in' => $logged_in);
        }
        if (!$inserted) {
            if ($queue) {
                Hm_Debug::add(sprintf('queueing module %s', $module));
                self::$module_queue[] = array($page, $module, $logged_in, $marker, $placement);
            }
            else {
                Hm_Debug::add(sprintf('failed to insert module %s on %s', $module, $page));
            }
        }
    }
    public static function try_queued_modules() {
        foreach (self::$module_queue as $vals) {
            self::add($vals[0], $vals[1], $vals[2], $vals[3], $vals[4], false);
        }
    }

    public static function del($page, $module) {
        if (isset(self::$module_list[$page][$module])) {
            unset(self::$module_list[$page][$module]);
        }
    }

    public static function get_for_page($page) {
        $res = array();
        if (isset(self::$module_list[$page])) {
            $res = array_merge($res, self::$module_list[$page]);
        }
        return $res;
    }

    public static function dump() {
        return self::$module_list;
    }
}

class Hm_Handler_Modules { use Hm_Modules; }
class Hm_Output_Modules { use Hm_Modules; }

class Hm_Crypt {

    static private $mode = BLOCK_MODE;
    static private $cipher = CIPHER;
    static private $r_source = RAND_SOURCE;

    public static function plaintext($string, $key) {
        if (!MCRYPT_DATA) {
            return $string;
        }
        $key = substr(md5($key), 0, mcrypt_get_key_size(self::$cipher, self::$mode));
        $string = base64_decode($string);
        $iv_size = self::iv_size();
        $iv_dec = substr($string, 0, $iv_size);
        $string = substr($string, $iv_size);
        return mcrypt_decrypt(self::$cipher, $key, $string, self::$mode, $iv_dec);
    }

    public static function ciphertext($string, $key) {
        if (!MCRYPT_DATA) {
            return $string;
        }
        $key = substr(md5($key), 0, mcrypt_get_key_size(self::$cipher, self::$mode));
        $iv_size = self::iv_size();
        $iv = mcrypt_create_iv($iv_size, self::$r_source);
        return base64_encode($iv.mcrypt_encrypt(self::$cipher, $key, $string, self::$mode, $iv));
    }

    public static function iv_size() {
        return mcrypt_get_iv_size(self::$cipher, self::$mode);
    }
}

class Hm_DB {

    static public $dbh = array();
    static private $required_config = array('db_user', 'db_pass', 'db_name', 'db_host', 'db_driver');
    static private $config;

    static private function parse_config($site_config) {
        self::$config = array(
            'db_driver' => $site_config->get('db_driver', false),
            'db_host' => $site_config->get('db_host', false),
            'db_name' => $site_config->get('db_name', false),
            'db_user' => $site_config->get('db_user', false),
            'db_pass' => $site_config->get('db_pass', false),
        );
        foreach (self::$required_config as $v) {
            if (!self::$config[$v]) {
                Hm_Debug('Missing configuration setting for %s', $v);
            }
        }
    }
    static private function db_key() {
        return md5(self::$config['db_driver'].
            self::$config['db_host'].
            self::$config['db_name'].
            self::$config['db_user'].
            self::$config['db_pass']
        );
    }

    static public function connect($site_config) {

        self::parse_config($site_config);
        $key = self::db_key();

        if (isset(self::$dbh[$key]) && self::$dbh[$key]) {
            return self::$dbh[$key];
        }
        $dsn = sprintf('%s:host=%s;dbname=%s', self::$config['db_driver'], self::$config['db_host'], self::$config['db_name']);
        try {
            self::$dbh[$key] = new PDO($dsn, self::$config['db_user'], self::$config['db_pass']);
            Hm_Debug::add(sprintf('Connecting to dsn: %s', $dsn));
            return self::$dbh[$key];
        }
        catch (Exception $oops) {
            Hm_Debug::add($oops->getMessage());
            Hm_Msgs::add("An error occurred communicating with the database");
            self::$dbh[$key] = false;
            return false;
        }
    }
}

trait Hm_Server_List {

    private static $server_list = array();

    public static function connect($id, $cache=false, $user=false, $pass=false, $save_credentials=false) {
        if (isset(self::$server_list[$id])) {
            $server = self::$server_list[$id];
            if ($server['object']) {
                return $server['object'];
            }
            else {
                if ((!$user || !$pass) && (!isset($server['user']) || !isset($server['pass']))) {
                    return false;
                }
                elseif (isset($server['user']) && isset($server['pass'])) {
                    $user = $server['user'];
                    $pass = $server['pass'];
                }
                if ($user && $pass) {
                    $res = self::service_connect($id, $server, $user, $pass, $cache);
                    if ($res) {
                        self::$server_list[$id]['connected'] = true;
                        if ($save_credentials) {
                            self::$server_list[$id]['user'] = $user;
                            self::$server_list[$id]['pass'] = $pass;
                        }
                    }
                    return self::$server_list[$id]['object'];
                }
            }
        }
        return false;
    }

    public static function reuse($id) {
        if (isset(self::$server_list[$id]) && is_object(self::$server_list[$id]['object'])) {
            return self::$server_list[$id]['object'];
        }
        return false;
    }

    public static function forget_credentials($id) {
        if (isset(self::$server_list[$id])) {
            unset(self::$server_list[$id]['user']);
            unset(self::$server_list[$id]['pass']);
        }
    }

    public static function add($atts, $id=false) {
        $atts['object'] = false;
        $atts['connected'] = false;
        if ($id) {
            self::$server_list[$id] = $atts;
        }
        else {
            self::$server_list[] = $atts;
        }
    }

    public static function del($id) {
        if (isset(self::$server_list[$id])) {
            unset(self::$server_list[$id]);
            return true;
        }
        return false;
    }

    public static function dump($id=false, $full=false) {
        $list = array();
        foreach (self::$server_list as $index => $server) {
            if ($id !== false && $index != $id) {
                continue;
            }
            if ($full) {
                $list[$index] = $server;
            }
            else {
                $list[$index] = array(
                    'name' => $server['name'],
                    'server' => $server['server'],
                    'port' => $server['port'],
                    'tls' => $server['tls']
                );
                if (isset($server['user'])) {
                    $list[$index]['user'] = $server['user'];
                }
                if (isset($server['pass'])) {
                    $list[$index]['pass'] = $server['pass'];
                }
            }
            if ($id !== false) {
                return $list[$index];
            }
        }
        return $list;
    }

    public static function clean_up($id=false) {
        foreach (self::$server_list as $index => $server) {
            if ($id !== false && $id != $index) {
                continue;
            }
            if ($server['connected'] && $server['object']) {
                if (method_exists(self::$server_list[$index]['object'], 'disconnect')) {
                    self::$server_list[$index]['object']->disconnect();
                }
                self::$server_list[$index]['connected'] = false;
            }
        }
    }
}

class Hm_IMAP_List {
    
    use Hm_Server_List;

    public static function service_connect($id, $server, $user, $pass, $cache=false) {
        self::$server_list[$id]['object'] = new Hm_IMAP();
        if ($cache) {
            self::$server_list[$id]['object']->load_cache($cache, 'string');
        }
        return self::$server_list[$id]['object']->connect(array(
            'server'    => $server['server'],
            'port'      => $server['port'],
            'tls'       => $server['tls'],
            'username'  => $user,
            'password'  => $pass
        ));
    }
    public static function get_cache($session, $id) {
        $server_cache = $session->get('imap_cache', array());
        if (isset($server_cache[$id])) {
            return $server_cache[$id];
        }
        return false;
    }
}

class Hm_POP3_List {
    
    use Hm_Server_List;

    public static function service_connect($id, $server, $user, $pass, $cache=false) {
        self::$server_list[$id]['object'] = new Hm_POP3();
        self::$server_list[$id]['object']->server = $server['server'];
        self::$server_list[$id]['object']->port = $server['port'];
        self::$server_list[$id]['object']->ssl = $server['tls'];

        if (self::$server_list[$id]['object']->connect()) {
            self::$server_list[$id]['object']->auth($user, $pass);
            return self::$server_list[$id]['object'];
        }
        return false;
    }
    public static function get_cache($session, $id) {
        return false;
    }
}

class Hm_Feed_List {

    use Hm_Server_List;

    public static function service_connect($id, $server, $user, $pass, $cache=false) {
        self::$server_list[$id]['object'] = new Hm_Feed();
        return self::$server_list[$id]['object'];
    }
    public static function get_cache($session, $id) {
        return false;
    }
}

class Hm_Page_Cache {

    private static $pages = array();

    public static function add($key, $page, $save=false) {
        self::$pages[$key] = array($page, $save);
    }
    public static function concat($key, $page, $save = false, $delim=false) {
        if (isset(self::$pages[$key])) {
            if ($delim) {
                self::$pages[$key][0] .= $delim.$page;
            }
            else {
                self::$pages[$key][0] .= $page;
            }
        }
        else {
            self::$pages[$key] = array($page, $save);
        }
    }
    public static function del($key) {
        if (isset(self::$pages[$key])) {
            unset(self::$pages[$key]);
            return true;
        }
        return false;
    }
    public static function get($key) {
        if (isset(self::$pages[$key])) {
            Hm_Debug::add(sprintf("PAGE CACHE: %s", $key));
            return self::$pages[$key][0];
        }
        return false;
    }
    public static function dump() {
        return self::$pages;
    }
    public static function flush($session) {
        self::$pages = array();
        $session->set('page_cache', array());
        $session->set('saved_pages', array());
    }
    public static function load($session) {
        self::$pages = $session->get('page_cache', array());
        self::$pages = array_merge(self::$pages, $session->get('saved_pages', array()));
    }
    public static function save($session) {
        $pages = self::$pages;
        $saved_pages = array();
        foreach (self::$pages as $key => $page) {
            if ($page[1]) {
                $saved_pages[$key] = $pages[$key];
                unset($pages[$key]);
            }
        }
        $session->set('page_cache', $pages);
        $session->set('saved_pages', $saved_pages);
    }
}

trait Hm_Uid_Cache {

    private static $uids;
    
    public static function load($uid_array) {
        if (!empty($uid_array)) {
            self::$uids = array_combine($uid_array, array_fill(0, count($uid_array), 0));
        }
        else {
            self::$uids = array();
        }
    }
    public static function is_present($uid) {
        return isset(self::$uids[$uid]);
    }
    public static function dump() {
        return array_keys(self::$uids);
    }
    public static function add($uid) {
        self::$uids[$uid] = 0;
    }
    public static function remove($uid) {
        if (isset(self::$uids)) {
            unset(self::$uids[$uid]);
            return true;
        }
        return false;
    }
}

class Hm_POP3_Seen_Cache {
    use Hm_Uid_Cache;
}
class Hm_Feed_Seen_Cache {
    use Hm_Uid_Cache;
}

class Hm_Image_Sources {
    public static $power = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAOlJREFUOI2VkjFuwkAQRV+sNKZEKJh7UCEBl4BwhEhpQpSSFu5CgyjgIECiSIhLBBpoAhT+lker9dqMNPLsnz9f650PxXFTBiMqIzwq8BzghnoAvAI/QF1n+wsN4BcYFg0PgasG3jwCY9X/wMAdbgJHEd4N7j7iROcjkFiBqRpzR9i3hZWwmQV3AjsVBPrCvi14ERhTHrG4lwywayw1DfDkciPgoLpdQSDj7K3AWvVHBYFPfdcWbAEnXSsk8kW+xqbbHJEbaQF0SR+sBvSApXpXAm4cmZv48g+PC91ISE2yBc7KDanZXnwDd6ZsRKAfKovZAAAAAElFTkSuQmCC';
    public static $home = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAHFJREFUOI3FkMsNgCAQBQfjCUvRlkyMlWlR2IMFcMYLJARxV+OBSebA520ewDMW2KJWuFdlBBwQoi7uvWIBfBZOemCVgqlyGSzdgUGrrHkAk1ZZ8wRmk7UI0vsqGIDuY+hG+wG9cGaKdfWP2j/h94D2XMGCMeGhOf42AAAAAElFTkSuQmCC';
    public static $box = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAADtJREFUOI1jZGBg+M9AAWCiRPPgAf9xYHTwAZs6isOAEYdtDAwMDF/R+NykGkAUoNgLowYMFgMoSQf/AFJDEffBNhqvAAAAAElFTkSuQmCC';
    public static $env_closed = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAFVJREFUOI3NkkEKACEMA2d92fpzfVn3oHhYqAF7qIFeSpImUMjGA1jEoEQTFKAC/UDbp3bhBRqj0m7a5C78F56Rx5MEdUBHFMlkV09ogN3xB7kG+fgA0tc160Jy09wAAAAASUVORK5CYII=';
    public static $star = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAKBJREFUOI2dk7ENgDAMBE8U7MAKMAEdS7ACa8AaLMQGrEBKaipoHMmExAReegkl/ij2BbDVALVVULwc0It/axX/UgOc4mQbVgt94jtbq7qB2cYAHKo414dkAWiB7UN4k8xNFbBkhBepjaoEZiM8S40pjS/0A2cMo4UsC6fH56esKb2+Sn/9cMqakvlzTaSn7CmNejGcwQ50gIsc4GRv14sXF4BOBP5lBBYAAAAASUVORK5CYII=';
    public static $globe = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAASNJREFUOI1907FKQ0EQBdBjldhaa28TgmAldnb+gP/gF+gHKAiCYCIGFdv0lhYiYmtriEUsFKysTRASi901j3WfAwPv7d57d2fuLH9jDT28YBJziHO0C/jfWMQVZv/kNIo3S+SHCugU62jEveNM6D4XyU8+zA64icJVzHm15rT4GcmlWvu4xo7Ql2nC9SL5FQfF7rCAES5jaf3IOSN0e4bbGnISeIq4d4zj90C8zgzfWKkR2Fd2ZVwVWMVWgbwUxWsFhvFnO141jwY+agSeCXakhQmOCiLL2MMFHiv4DsGK3OPdml7AhvnAtdJisjLlqIbcwlvEdKsbTWE8k8AXNoUxbgren5jbdyf0Ri7SK5STP6ZuiVyNtjBhg3jiWOh2p1pzih9Ox36Q1K2kawAAAABJRU5ErkJggg==';
    public static $doc = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAFpJREFUOI3dkEEKACAIBLfo4f28ToJWm0adGghCdFwEgEaeUOHgCZoniQi2kqiAStgAWzBJTgVGkoZmTSJ1Q4407dAJZCNLtJq9T1DUP7rZ8DSBd/Vlwg9u0AEYijfoDYVTdgAAAABJRU5ErkJggg==';
    public static $monitor = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAGBJREFUOI1jYGBguMTAwPCfTHyCEcogG7AgsRlJ1PufgYGBgYkS20cNGCwGUJyQmBgYGE5RoP8YvtSH7jKsaqkaiEEMDAyvGRA5DZuL/jMwMLxiYGDwx2YYsmZC+CVMEwCAuidyZ/rWAAAAAABJRU5ErkJggg==';
    public static $cog = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAONJREFUOI2Vk00OAUEQhT+zkiC4gz2CcRpxAJO4gMRJLHAIq7Hxk7iQzGywmG5502qESiqpqX71+lVNNVRbDDydD7/g3tYC+vK9E4KN5AdAwyq+OvAZWAGZENxdzmNSoK4EYwH/4g+gF6q4/UFw9EWREBwkzoEE6AJtF+dynurNMbB3AH/DIpTnSPx5RjHkmAqJHYOgbWEjAwjFkEKrWcAImEoL3mYGdi5xri14W1PuMXGyO8CS8oxWlpoLv//Gc1g8+qPYu659aZVPfK5y5nIXwTRDFd8e01byA6vYMn3OkyrQC5Q1dBrXQiO8AAAAAElFTkSuQmCC';
    public static $people = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAN1JREFUOI2d0E0uBVEQxfHfk4dBD5lZgYFNmAgDQqyCeAkLYBfW8WIHxEfELsx8znzkGTCpRl/VyY2T3Nx0nar/6brUaQ9PeMSocuZby/gszmZf836k3GE3ag1uC8B5NrydJG2Ed1DUXzPAZQI4DW+xBvCeAF7CmynqZxngLQG0SbM9q3V0kQDapAU8497P4/7RVgJY7+mdxhHmSmOEh0jaSQYbrOEmAsYwj0MMK5KOiz+cwEl8XGElUhqs4vp3EpaSNX0kxfJMAjAsvUFLqdAg7k5/tnctCEz9A9DRFyWUZi0vm1MaAAAAAElFTkSuQmCC';
    public static $caret = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAACRJREFUGJVjYECABgYC4D8hRf8JKfqPTRETIXtJtgKnJAMuSQC7gAx6LfypjQAAAABJRU5ErkJggg==';
    public static $folder = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAADBJREFUOI1jZGBg+M+AHTDiEEcBTMQowgcY8biAKECxCwYeDHwYjBowbAw4SYH+YwB6YwSnsuTkoAAAAABJRU5ErkJggg==';
    public static $chevron = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAADtJREFUGJVjYCACKDAwMByA0ljFDjAwMPxnYGB4ABVQgLL/Q+VQBB6gseGmIivCkERXhFUSWRFOSawAAEl7E3uv1iMcAAAAAElFTkSuQmCC';
    public static $check = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAFFJREFUGJWdzDsOQEAABNCXiE8vkj2CTuPuJApOgVs4As1KxKcx5bzJ8DMtJlTJBw6okUKDESHihh09chF3rDcszsuAJcIDr6MZ3RueKZHdywPRLxDHyg6J8AAAAABJRU5ErkJggg==';
    public static $refresh = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAXtJREFUSIm11b9KHFEUBvBf7BK0VDD4r5E0glhIQDsLJQ/gJiAm+AQSKzsR8SFMG0RLQVA0JPgKgiZPoIVrZbp1/VPMLHN3dnZmFnY/ODDn3nu+795zz9xDPiawiXPcoIZ6/H2KDQznxG+3mxjDAZ7wUmA17GEwxbETz7fgC/7nED63Ga9iKebYDcab8D2D7AgVjKIvtnGs4iK1vo7j1FjTzsOJS8xkHTGFT7jNOTGinIdpOcO7EuQNvMd9nsBBauedkJNcaKbAhKRanpVLS1nyF6I6bzhHHZJviS42z5wHApUOBUrhJhAY7YVALRDo6zZ51wmzBKqBP9ILgevAn++FwO/AX+m2ANHDVZf8aLO9ENmXVNI1+rstMIKHQOQPBjqIH8C3okUVzc3kH+ZKkH/E3zimbYtsYF1rx/qFNXwQvbJvMYmvOEmtfcR0kciy5nSVtSoWS5wY0Z38lFRXUdP/obXpgzcFQmP4jAVMYSiOucOV6CU+FLXMTLwC6Xmw7eTc8o8AAAAASUVORK5CYII=';
    public static $big_cog = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAXVJREFUSImtlsEyA0EQhj/hAXgAHORIOEqJgyqiXFURJVUeAHcOXkCpxGu4OgjXRPACDryD2ji62HWYmarJVPfuRPJX9WF7/v57Zrt7ZyEeHSCzdj9CXBSWgNRL8AssxgSWBF8TWAl8Z8BUEHcacFaBo6KEV94ue8AhUAUGnt/ZF7BuRfue/1wTPxFE/mMpsOMf1eG96HiRyIBPbfFtAifo+ILTQYIfYF9J3gfugBf7vKDwLoAP7QSbwo4SYFvg1pGLX5WEy8CtEiCJO+wK/AHQxswOYN5ZKhAz4DlH3OFViU2tdm7BriMS3ORpSJM8UZSAR5tNwkaERk3xD7VsGVMYqcj1HPE9gZ8ALbwi+9gSAgaYbpHEvwX+UJvOBEFzgtAs8ISZcjdkNcyHTsK85YroCjsa1Xqa+NoExJ1VnKjfpuElMw6WtQX/wukCB+gXTmLXGpiJd/7LouxNYQdtIUEr4FSA4yJxDdKlL/b5OPB/Wx5ig/4AeR3UqLNaCmAAAAAASUVORK5CYII=';
    public static $big_caret = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAEtJREFUOI2lzkEKACAMA8Hgy/15PAnSRjCmx8JOC/SZYmcNU4QpQoQIESIVsBEFWMgNkMhwXks/aNcd4DlWgBVXwI5P4CvewHcMN15ViDhSMdkjzgAAAABJRU5ErkJggg==';
}

function handler_source($source) {
    Hm_Handler_Modules::set_source($source);
}
function output_source($source) {
    Hm_Output_Modules::set_source($source);
}
function add_handler($page, $mod, $logged_in, $source=false, $marker=false, $placement='after', $queue=true) {
    Hm_Handler_Modules::add($page, $mod, $logged_in, $marker, $placement, $queue, $source);
}
function add_output($page, $mod, $logged_in, $source=false, $marker=false, $placement='after', $queue=true) {
    Hm_Output_Modules::add($page, $mod, $logged_in, $marker, $placement, $queue, $source);
}
function secure_cookie($request, $name, $value, $lifetime=0, $path='', $domain='') {
    if ($request->tls) {
        $secure = true;
    }
    else {
        $secure = false;
    }
    setcookie($name, $value, $lifetime, $path, $domain, $secure);
}

?>
