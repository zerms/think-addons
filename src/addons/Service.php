<?php
declare(strict_types=1);

namespace think\addons;

use app\api\library\Menu;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\db\exception\PDOException;
use think\Exception;
use think\facade\App;
use think\facade\Cache;
use app\common\library\Cache as CacheLib;
use think\facade\Db;
use think\Route;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Event;
use think\addons\middleware\Addons;
use xb\File;
use xb\Rsa;

/**
 * 插件服务
 * Class Service
 * @package think\addons
 */
class Service extends \think\Service
{
    protected $addons_path;

    public function register()
    {
        $this->addons_path = $this->getAddonsPath();
        // 加载系统语言包
        Lang::load([
            $this->app->getRootPath() . '/vendor/zzstudio/think-addons/src/lang/zh-cn.php'
        ]);
        // 自动载入插件
        $this->autoload();
        // 加载插件事件
        $this->loadEvent();
        // 加载插件系统服务
        $this->loadService();
        // 绑定插件容器
        $this->app->bind('addons', Service::class);
    }

    public function boot()
    {
        $this->registerRoutes(function (Route $route) {
            // 路由脚本
            $execute = '\\think\\addons\\Route::execute';

            // 注册插件公共中间件
            if (is_file($this->app->addons->getAddonsPath() . 'middleware.php')) {
                $this->app->middleware->import(include $this->app->addons->getAddonsPath() . 'middleware.php', 'route');
            }

            // 注册控制器路由
            $route->rule("addons/:addon/[:controller]/[:action]", $execute)->middleware(Addons::class);
            // 自定义路由
            $routes = (array)Config::get('addons.route', []);
            foreach ($routes as $key => $val) {
                if (!$val) {
                    continue;
                }
                if (is_array($val)) {
                    $domain = $val['domain'];
                    $rules = [];
                    foreach ($val['rule'] as $k => $rule) {
                        [$addon, $controller, $action] = explode('/', $rule);
                        $rules[$k] = [
                            'addons' => $addon,
                            'controller' => $controller,
                            'action' => $action,
                            'indomain' => 1,
                        ];
                    }
                    $route->domain($domain, function () use ($rules, $route, $execute) {
                        // 动态注册域名的路由规则
                        foreach ($rules as $k => $rule) {
                            $route->rule($k, $execute)
                                ->name($k)
                                ->completeMatch(true)
                                ->append($rule);
                        }
                    });
                } else {
                    list($addon, $controller, $action) = explode('/', $val);
                    $route->rule($key, $execute)
                        ->name($key)
                        ->completeMatch(true)
                        ->append([
                            'addons' => $addon,
                            'controller' => $controller,
                            'action' => $action
                        ]);
                }
            }
        });
    }

    /**
     * 插件事件
     */
    private function loadEvent()
    {
        $hooks = $this->app->isDebug() ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array)Config::get('addons.hooks', []);
            // 初始化钩子
            foreach ($hooks as $key => $values) {
                if (is_string($values)) {
                    $values = explode(',', $values);
                } else {
                    $values = (array)$values;
                }
                $hooks[$key] = array_filter(array_map(function ($v) use ($key) {
                    return [get_addons_class($v), $key];
                }, $values));
            }
            Cache::set('hooks', $hooks);
        }
        //如果在插件中有定义 AddonsInit，则直接执行
        if (isset($hooks['AddonsInit'])) {
            foreach ($hooks['AddonsInit'] as $k => $v) {
                Event::trigger('AddonsInit', $v);
            }
        }
        Event::listenEvents($hooks);
    }

    /**
     * 挂载插件服务
     */
    private function loadService()
    {
        $results = scandir($this->addons_path);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file($this->addons_path . $name)) {
                continue;
            }
            $addonDir = $this->addons_path . $name . DIRECTORY_SEPARATOR;
            if (!is_dir($addonDir)) {
                continue;
            }

            if (!is_file($addonDir . ucfirst($name) . '.php')) {
                continue;
            }

            $service_file = $addonDir . 'service.ini';
            if (!is_file($service_file)) {
                continue;
            }
            $info = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $info);
        }
        $this->app->bind($bind);
    }

    /**
     * 自动载入插件
     * @return bool
     */
    private function autoload()
    {
        // 是否处理自动载入
        if (!Config::get('addons.autoload', true)) {
            return true;
        }
        $config = Config::get('addons');
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob($this->getAddonsPath() . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            // 找到插件入口文件
            if (strtolower($info['filename']) === 'plugin') {
                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }
        Config::set($config, 'addons');
    }

    /**
     * 获取 addons 路径
     * @return string
     */
    public function getAddonsPath()
    {
        // 初始化插件目录
        $addons_path = $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
        // 如果插件目录不存在则创建
        if (!is_dir($addons_path)) {
            @mkdir($addons_path, 0755, true);
        }

        return $addons_path;
    }

    /**
     * 获取插件的配置信息
     * @param string $name
     * @return array
     */
    public function getAddonsConfig()
    {
        $name = $this->app->request->addon;
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }

        return $addon->getConfig();
    }

    /**
     * 安装插件
     *
     * @param string $name 插件名称
     * @param boolean $force 是否覆盖
     * @param array $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $force = false, $extend = [])
    {
        if (!$name || (is_dir(addon_path() . $name) && !$force)) {
            throw new Exception(__("Addon already exists"));
        }
        $extend['domain'] = request()->host(true);
        // 远程下载插件
        $tmpFile = Service::download($name, $extend);
        $addonDir = self::getAddonDir($name);

        try {
            // 解压插件压缩包到插件目录
            Service::unzip($name);
            // 检查插件是否完整
            Service::check($name);
            if (!$force) {
                Service::noconflict($name);
            }
        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        // 默认启用该插件
        $info = get_addons_info($name);

        Db::startTrans();
        try {
            if (!$info['status']) {
                $info['status'] = 1;
                set_addons_info($name, $info);
            }
            // 执行安装脚本
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(App::instance());
                $addon->install();
                if (isset($info['has_menulist']) && $info['has_menulist']) {
                    $menu_list = property_exists($addon, 'menu_list') ? $addon->menu_list : [];
                    //添加菜单
                    Menu::addAddonMenu($menu_list, $info);
                }
            }
            Db::commit();
        } catch (Exception $e) {
            @rmdirs($addonDir);
            Db::rollback();
            throw new Exception($e->getMessage());
        }
        // 导入
        self::importsql($name);
        // 启用插件
        self::enable($name, true);

        $info['config'] = get_addons_config($name) ? 1 : 0;
        $info['testdata'] = is_file(self::getTestdataFile($name));
        return $info;
    }

    /**
     * 卸载插件
     *
     * @param string $name
     * @param boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
    {
        if (!$name || !is_dir(addon_path() . $name)) {
            throw new Exception(__("Addon not exists"));
        }
        $info = get_addons_info($name);

        if ($info['status'] == 1) {
            throw new Exception(__("Please disable the add before trying to uninstall"));
        }
        if (!$force) {
            Service::noconflict($name);
        }

        // 移除插件全局资源文件
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink(ROOT_PATH . $v);
            }
        }

        // 执行卸载脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(App::instance());
                $addon->uninstall();

                // 删除插件后台菜单
                if (isset($info['has_menulist']) && $info['has_menulist']) {
                    Menu::delAddonMenu($name);
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        // 移除插件目录
        File::del_dir(addon_path() . $name);
        // 刷新
        self::refresh();
        // 卸载插件钩子方法
        self::write_hook_function($name,true);
        // 禁用中间件
        self::middleware($name, false);
        return true;
    }

    /**
     * 刷新插件缓存文件
     *
     * @return  boolean
     * @throws  Exception
     */
    public static function refresh()
    {
        Cache::delete("addons");
        Cache::delete("hooks");
        $file = self::getExtraAddonsFile();

        $config = get_addons_autoload_config(true);
        if ($config['autoload']) {
            return;
        }
        if (!File::is_really_writable($file)) {
            throw new Exception(__("Unable to open file '%s' for writing", "addons.php"));
        }

        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . 'return ' . var_export($config, true) . ';');
            fclose($handle);
        } else {
            throw new Exception(__("Unable to open file '%s' for writing", ""));
        }
        return true;
    }

    /**
     * 启用
     * @param string $name 插件名称
     * @param boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(addon_path() . $name)) {
            throw new Exception(__("Addon not exists"));
        }
        if (!$force) {
            Service::noconflict($name);
        }

        // 备份冲突文件
        if (config('cms.backup_global_files')) {
            // 仅备份修改过的文件
            $conflictFiles = self::getGlobalFiles($name, true);
            if ($conflictFiles) {
                $zip = new ZipFile();
                try {
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(root_path() . $v, $v);
                    }
                    $addonsBackupDir = self::getAddonsBackupDir();
                    $zip->saveAsFile($addonsBackupDir . $name . "-conflict-enable-" . date("YmdHis") . ".zip");
                } catch (Exception $e) {

                } finally {
                    $zip->close();
                }
            }
        }

        $addonDir = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);

        $files = self::getGlobalFiles($name);
        if ($files) {
            //刷新插件配置缓存
            Service::config($name, ['files' => $files]);
        }

        // 复制文件
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }

        // 复制全局文件到全局
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, root_path() . $dir);
            }
        }

        // 插件纯净模式时将插件目录下的app、public、assets删除
        if (config('cms.addon_pure_mode')) {
            // 删除插件目录已复制到全局的文件
            @rmdirs($sourceAssetsDir);
            foreach (self::getCheckDirs() as $k => $dir) {
                @rmdirs($addonDir . $dir);
            }
        }
        // 执行启用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(App::instance());
                if (method_exists($class, "enable")) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $info = get_addons_info($name);

        $info['status'] = 1;

        unset($info['url']);

        set_addons_info($name, $info);

        // 刷新
        Service::refresh();
        // 写入插件钩子方法
        self::write_hook_function($name);
        // 启用中间件
        self::middleware($name, true);
        return true;
    }

    /**
     * 禁用
     *
     * @param string $name 插件名称
     * @param boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        if (!$name || !is_dir(addon_path() . $name)) {
            throw new Exception('Addon not exists');
        }
        $file = self::getExtraAddonsFile();
        if (!is_really_writable($file)) {
            throw new Exception(__("Unable to open file '%s' for writing", "addons.php"));
        }
        if (!$force) {
            Service::noconflict($name);
        }
        // 备份冲突文件
        if (config('cms.backup_global_files')) {
            // 仅备份修改过的文件
            $conflictFiles = self::getGlobalFiles($name, true);
            if ($conflictFiles) {
                $zip = new ZipFile();
                try {
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(root_path() . $v, $v);
                    }
                    $addonsBackupDir = self::getAddonsBackupDir();
                    $zip->saveAsFile($addonsBackupDir . $name . "-conflict-enable-" . date("YmdHis") . ".zip");
                } catch (Exception $e) {

                } finally {
                    $zip->close();
                }
            }
        }
        // 读取插件配置
        $config = Service::config($name);
        // 指定插件目录
        $addonDir = self::getAddonDir($name);
        //插件资源目录
        $destAssetsDir = self::getDestAssetsDir($name);
        // 移除插件全局文件
        $list = Service::getGlobalFiles($name);
        // 插件纯净模式时将原有的文件复制回插件目录
        // 当无法获取全局文件列表时也将列表复制回插件目录
        if (config('cms.addon_pure_mode') || !$list) {
            if ($config && isset($config['files']) && is_array($config['files'])) {
                foreach ($config['files'] as $index => $item) {
                    // 避免切换不同服务器后导致路径不一致
                    $item = str_replace(['/', '\\'], ds(), $item);
                    // 插件资源目录，无需重复复制
                    if (stripos($item, str_replace(root_path(), '', $destAssetsDir)) === 0) {
                        continue;
                    }
                    // 检查目录是否存在，不存在则创建
                    $itemBaseDir = dirname($addonDir . $item);
                    if (!is_dir($itemBaseDir)) {
                        @mkdir($itemBaseDir, 0755, true);
                    }
                    if (is_file(root_path() . $item)) {
                        @copy(root_path() . $item, $addonDir . $item);
                    }
                    $list = $config['files'];
                }
            }
            //复制插件目录资源
            if (is_dir($destAssetsDir)) {
                @copydirs($destAssetsDir, $addonDir . 'assets' . ds());
            }
        }
        $dirs = [];
        foreach ($list as $k => $v) {
            $file = root_path() . $v;
            $dirs[] = dirname($file);
            @unlink($file);
        }

        // 移除插件空目录
        $dirs = array_filter(array_unique($dirs));
        foreach ($dirs as $k => $v) {
            remove_empty_folder($v);
        }
        $info = get_addons_info($name);
        $info['status'] = 0;

        unset($info['url']);
        set_addons_info($name, $info);
        // 执行禁用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(App::instance());

                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新
        Service::refresh();
        // 卸载插件钩子方法
        self::write_hook_function($name,true);
        // 禁用中间件
        self::middleware($name, false);
        return true;
    }

    /**
     * 升级插件
     *
     * @param string $name 插件名称
     * @param array $extend 扩展参数
     */
    public static function upgrade($name, $extend = [])
    {
        $info = get_addons_info($name);
        $config = get_addons_config($name);
        if ($config) {
            //备份配置
        }

        // 远程下载插件
        $tmpFile = Service::download($name, $extend);

        // 备份插件文件
        Service::backup($name);

        $addonDir = self::getAddonDir($name);

        // 删除插件目录下的全局文件
        $files = self::getCheckDirs();
        foreach ($files as $index => $file) {
            @rmdirs($addonDir . $file);
        }
        try {
            // 解压插件
            Service::unzip($name);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        if ($config) {
            // 还原配置
            set_addons_config($name, $config);
        }

        // 导入
        Service::importsql($name, "update.sql");

        // 执行升级脚本
        try {
            $addonName = ucfirst($name);
            //创建临时类用于调用升级的方法
            $sourceFile = $addonDir . $addonName . ".php";
            $destFile = $addonDir . $addonName . "Upgrade.php";

            $classContent = str_replace("class {$addonName} extends", "class {$addonName}Upgrade extends", file_get_contents($sourceFile));

            //创建临时的类文件
            @file_put_contents($destFile, $classContent);

            $className = "\\addons\\" . $name . "\\" . $addonName . "Upgrade";
            $addon = new $className(App::instance());

            //调用升级的方法
            if (method_exists($addon, "upgrade")) {
                $addon->upgrade();
            }
            //移除临时文件
            @unlink($destFile);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        // 刷新
        Service::refresh();
        // 启用插件
        self::enable($name, true);
        // 启用中间件
        self::middleware($name, true);
        //必须变更版本号
        $info['version'] = isset($extend['plugin_version']) ? $extend['plugin_version'] : $info['version'];

        $info['config'] = get_addons_config($name) ? 1 : 0;
        return $info;
    }

    /**
     * 远程下载插件
     *
     * @param string $name 插件名称
     * @param array $extend 扩展参数
     * @return  string
     */
    public static function download($name, $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();
        $tmpFile = $addonsTempDir . $name . ".zip";
        try {
            $data = [
                'licence' => $extend['licence'],
                'domain' => $extend['domain'],
                'version' => $extend['version'],
                'mac' => $extend['mac'],
                'pluginVersion' => $extend['plugin_version'],
                'sign' => $extend['plugin_sign'],
            ];
            $content = null;
            $install_data = self::encryption_request($extend['url'], $data, "post", $extend, 0);
            if (!preg_match("/code/is", $install_data)) {
                $content = $install_data;
            } else {
                // 下载返回错误，抛出异常
                $install_data = json_decode($install_data, true);
                throw new AddonException($install_data['message'] ?? ($install_data['msg'] ?? __("Error")), $install_data['code'] ?? 400);
            }
        } catch (TransferException $e) {
            throw new Exception(__("Addon package download failed"));
        }
        if ($write = fopen($tmpFile, 'w')) {
            fwrite($write, $content);
            fclose($write);
            return $tmpFile;
        }
        throw new Exception(__("No permission to write temporary files"));
    }

    /**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
    {
        $addonsBackupDir = self::getAddonsBackupDir();
        $file = $addonsBackupDir . $name . '-backup-' . date("YmdHis") . '.zip';
        $zipFile = new ZipFile();
        try {
            $zipFile
                ->addDirRecursive(self::getAddonDir($name))
                ->saveAsFile($file)
                ->close();
        } catch (ZipException $e) {

        } finally {
            $zipFile->close();
        }

        return true;
    }

    /**
     * 解压插件
     *
     * @param string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
    {
        if (!$name) {
            throw new Exception(__("Invalid parameters"));
        }
        $addonsBackupDir = self::getAddonsBackupDir();
        $file = $addonsBackupDir . $name . '.zip';

        // 打开插件压缩包
        $zip = new ZipFile();
        try {
            $zip->openFile($file);
        } catch (ZipException $e) {
            $zip->close();
            throw new Exception(__("Unable to open the zip file"));
        }

        $dir = self::getAddonDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }

        // 解压插件压缩包
        try {
            $zip->extractTo($dir);
        } catch (ZipException $e) {
            throw new Exception(__("Unable to extract the file"));
        } finally {
            $zip->close();
        }
        return $dir;
    }

    /**
     * 更新配置文件
     *
     * @param string $name 插件名称
     * @param string $url 获取详情链接
     * @param array $data 数据
     * @return  string
     * @throws  Exception
     */
    public static function set_ini(string $name, string $url, array $data)
    {
        $file = addon_path() . $name . ds() . 'info.ini';
        $info = get_addons_info($name);
        $addon_detail = self::encryption_request($url, ['sign' => $data['plugin_sign'], 'licence' => $data['licence'], 'domain' => $data['domain'], 'mac' => $data['mac']], "get", ["public_key" => $data['public_key'], "sign" => $data['sign']]);
        if ($addon_detail['code'] == 200) {
            if (isset($info['url'])) unset($info['url']);
            $addon_detail_data = $addon_detail['data'] ?? [];
            $readme = $addon_detail_data['readme'] ?? "";
            $readme = str_replace(["\n", "\r"], "", $readme);

            $info['title'] = $addon_detail_data['name'] ?? "";
            $info['intro'] = $addon_detail_data['description'] ?? "";
            $info['img'] = $addon_detail_data['img'] ?? "";
            $info['readme'] = $readme;
            $info['version'] = $addon_detail_data['version'] ?? "v1.0.0";
            $info['author'] = $addon_detail_data['developer'] ?? "118CMS";
            $info['createTime'] = date("Y-m-d H:i:s");
            $info_ini = "";
            foreach ($info as $k => $v) {
                $info_ini .= $k . " = " . $v . "\n";
            }
            if ($handle = fopen($file, 'w')) {
                fwrite($handle, $info_ini);
                fclose($handle);
            } else {
                throw new Exception('文件没有写入权限');
            }
        }
        return true;
    }

    /**
     * 验证前置插件
     *
     * @param string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function check_pre(string $name)
    {
        $sign = "";
        $class = get_addons_class($name);
        if (class_exists($class)) {
            $addon = new $class(App::instance());
            $addons_pre = $addon->addon_pre ?? [];
            foreach ($addons_pre as $addon) {
                $info = get_addons_info($addon['sign']);
                if (!$info || $info['status'] != 1) {
                    $sign .= $addon['title'] . ",";
                }
            }
        }
        $sign = rtrim($sign, ",");
        if (!empty($sign)) {
            throw new Exception(__("Please install and enable %s in the backend plugin management before attempting again", $sign));
        }
        return true;
    }

    /**
     * 验证同类型插件
     *
     * @param string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function check_type(string $name)
    {
        return true;
    }

    /**
     * 将插件钩子内容写入对应插件钩子汇总文件中
     *
     * @param string $name 插件名称
     * @param boolean $force 是否禁用
     * @return  string
     * @throws  Exception
     */
    public static function write_hook_function(string $name, $force = false)
    {
        $sign = "";
        $class = get_addons_class($name);
        $write_hook_text = "";
        $write_hook_local = "index";
        if (class_exists($class)) {
            $addon = new $class(App::instance());
            $write_hook = $addon->write_hook();
            $write_hook_text = !empty($write_hook['text']) ? "#$name#\n" . trim($write_hook['text']) . "\n#$name#" : $write_hook_text;
            $write_hook_local = $write_hook['local'] ?? $write_hook_local;
        }
        $hook_file = root_path("app" . ds() . "common" . ds() . "hook") . $write_hook_local . ".php";
        $hook_text = @file_get_contents($hook_file);

        $pattern = "#$name\#[\S\s]*?\#$name#";
        if ($force == false) {
            // 启用(插入或覆盖)
            if (preg_match($pattern, $hook_text) == 0) {
                // 不存在(插入)
                $hook_text .= $write_hook_text;
            } else {
                // 存在(覆盖)
                $hook_text = preg_replace("($pattern)", $write_hook_text, $hook_text);
            }
        } else {
            // 禁用(替换为空)
            $hook_text = preg_replace("($pattern)", "", $hook_text);
        }
        @file_put_contents($hook_file, $hook_text);
        return true;
    }

    /**
     * 导入SQL
     *
     * @param string $name 插件名称
     * @param string $fileName SQL文件名称
     * @return  boolean
     */
    public static function importsql($name, $fileName = null)
    {
        $fileName = is_null($fileName) ? 'install.sql' : $fileName;
        $sqlFile = self::getAddonDir($name) . $fileName;
        if (is_file($sqlFile)) {
            $lines = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', get_database("prefix"), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        Db::getPdo()->exec($templine);
                    } catch (PDOException $e) {
                        //$e->getMessage();
                    }
                    $templine = '';
                }
            }
        }
        return true;
    }

    /**
     * 写入中间件
     *
     * @param string $name 插件名称
     * @param boolean $force 是否禁用
     * @return  boolean
     */
    public static function middleware(string $name, bool $force = false)
    {
        $file = root_path("app") . 'middleware.php';
        if (!File::is_really_writable($file)) {
            throw new Exception('文件没有写入权限');
        }
        $array = require $file;

        if ($force == true) {
            $path = root_path();
        } else {
            $path = addon_path() . ds() . strtolower($name) . ds();
        }

        $ins_middleware = [
            "app\api\middleware\\" . ucwords($name),
            "app\index\middleware\\" . ucwords($name),
        ];
        foreach ($ins_middleware as $k => $val) {
            if (!file_exists($path . $val . ".php")) {
                unset($ins_middleware[$k]);
            }
        }

        foreach ($array as $key => $item) {
            foreach ($ins_middleware as $k => $v) {
                if ($item == $v) {
                    unset($array[$key]);
                }
            }
        }
        if ($force == true) {
            foreach ($ins_middleware as $v) {
                $array[] = $v;
            }
        }

        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . 'return ' . var_export($array, true) . ";\n");
            fclose($handle);
        } else {
            throw new Exception('文件没有写入权限');
        }
        return true;
    }

    /**
     * 检测插件是否完整
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(addon_path() . $name)) {
            throw new Exception(__("Addon not exists"));
        }
        $addonClass = get_addons_class($name);
        if (!$addonClass) {
            throw new Exception(__("The addon file does not exist"));
        }
        $addon = new $addonClass(App::instance());
        if (!$addon->checkInfo()) {
            throw new Exception(__("The configuration file content is incorrect"));
        }
        return true;
    }

    /**
     * 是否有冲突
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            throw new AddonException(__("Conflicting file found"), -3, ['conflictlist' => $list]);
        }
        return true;
    }

    /**
     * 读取或修改插件配置
     * @param string $name
     * @param array $changed
     * @return array
     */
    public static function config($name, $changed = [])
    {
        $addonDir = self::getAddonDir($name);
        $addonConfigFile = $addonDir . '.addonrc';
        $config = [];
        if (is_file($addonConfigFile)) {
            $config = (array)json_decode(file_get_contents($addonConfigFile), true);
        }
        $config = array_merge($config, $changed);
        if ($changed) {
            file_put_contents($addonConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE));
        }
        return $config;
    }

    /**
     * 获取插件行为、路由配置文件
     * @return string
     */
    public static function getExtraAddonsFile()
    {
        return root_path() . 'config' . ds() . 'addons.php';
    }

    /**
     * 获取testdata.sql路径
     * @return string
     */
    public static function getTestdataFile($name)
    {
        return addon_path() . $name . ds() . 'testdata.sql';
    }

    /**
     * 获取插件在全局的文件
     *
     * @param string $name 插件名称
     * @param boolean $onlyconflict 是否只返回冲突文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $addonDir = self::getAddonDir($name);
        $checkDirList = self::getCheckDirs();
        $checkDirList = array_merge($checkDirList, ['assets']);

        $assetDir = self::getDestAssetsDir($name);

        // 扫描插件目录是否有覆盖的文件
        foreach ($checkDirList as $k => $dirName) {
            //检测目录是否存在
            if (!is_dir($addonDir . $dirName)) {
                continue;
            }
            //匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($addonDir . $dirName, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    //如果名称为assets需要做特殊处理
                    if ($dirName === 'assets') {
                        $path = str_replace(root_path(), '', $assetDir) . str_replace($addonDir . $dirName . ds(), '', $filePath);
                    } else {
                        $path = str_replace($addonDir, '', $filePath);
                    }
                    if ($onlyconflict) {
                        $destPath = root_path() . $path;
                        if (is_file($destPath)) {
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }
        $list = array_filter(array_unique($list));
        return $list;
    }

    /**
     * 获取插件源资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getSourceAssetsDir($name)
    {
        return addon_path() . $name . ds() . 'assets' . ds();
    }

    /**
     * 获取指定插件的目录
     */
    public static function getAddonDir($name)
    {
        $dir = addon_path() . $name . ds();
        return $dir;
    }

    /**
     * 获取检测的全局文件夹目录
     * @return  array
     */
    protected static function getCheckDirs()
    {
        return [
            'app',
            'public',
            'view'
        ];
    }

    /**
     * 获取插件目标资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = root_path() . str_replace("/", ds(), "public/assets/addons/{$name}/");
        return $assetsDir;
    }

    /**
     * 获取插件备份目录
     */
    public static function getAddonsBackupDir()
    {
        $dir = runtime_path() . 'addons' . ds();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
    {
        return config('cms.api_url');
    }

    /**
     * 获取请求对象
     * @return Client
     */
    public static function getClient()
    {
        $options = [
            'base_uri' => self::getServerUrl(),
            'timeout' => 30,
            'connect_timeout' => 30,
            'verify' => false,
            'http_errors' => false,
            'headers' => [
                'X-REQUESTED-WITH' => 'XMLHttpRequest',
                'Referer' => dirname(request()->root(true)),
                'User-Agent' => '118CmsAddon',
            ]
        ];
        static $client;
        if (empty($client)) {
            $client = new Client($options);
        }
        return $client;
    }

    /**
     * 加密请求API接口
     * @access public
     */
    public static function encryption_request($url = "", $data = [], $method = "post", $extend = [], $status = 1)
    {
        if (empty($url) or empty($data) or empty($method)) {
            throw new Exception(__("Invalid parameters"));
        }
        try {
            $data = json_encode($data);
            $openssl = new Rsa([
                'publicKey' => $extend['public_key']
            ]);
            //公钥加密
            $encrypt = $openssl->encrypt($data, 1);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        $url = $url . $encrypt;
        $res = curl_send($url, $method, "", $extend['sign']);
        if ($status == 1) {
            $res = !empty($res) ? json_decode($res, true) : [];
        }
        return $res;
    }
}
