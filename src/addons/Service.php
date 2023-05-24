<?php
declare(strict_types=1);

namespace think\addons;

use app\api\library\Menu;
use app\api\model\Permission;
use think\Exception;
use think\facade\App;
use think\facade\Db;
use think\Route;
use think\helper\Str;
use think\facade\Config;
use think\facade\Lang;
use think\facade\Cache;
use think\facade\Event;
use think\addons\middleware\Addons;
use xb\File;
use xb\Sql;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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

    /**
     * 安装插件.
     * @param string $name 插件名称
     * @param boolean $force 是否覆盖
     * @return bool
     * @throws Exception
     */
    public static function install($name, $force = false)
    {
        try {
            // 检查插件是否完整
            self::check($name);
            if (!$force) {
                self::noconflict($name);
            }
        } catch (AddonException $e) {
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        try {
            $info = get_addons_info($name);
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
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        self::runSQL($name);
        // 启用插件
        self::enable($name, true);
        // 是否存在配置
        $info['config']   = get_addons_config($name) ? 1 : 0;
        // 是否存在测试数据
        $info['testdata'] = is_file(Service::getTestdataFile($name));

        return $info;
    }

    /**
     * 卸载插件.
     * @param string $name
     * @param boolean $force 是否强制卸载
     * @return bool
     * @throws Exception
     */


    /**
     * 启用.
     * @param string $name 插件名称
     * @param bool $force 是否强制覆盖
     * @return bool
     */
    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(addon_path() . $name)) {
            throw new Exception('插件不存在！');
        }
        if (!$force) {
            self::noconflict($name);
        }
        $addonDir = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($sourceAssetsDir)) {
            File::copy_dir($sourceAssetsDir, $destAssetsDir);
        }
        // 复制文件夹及文件到全局
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                File::copy_dir($addonDir . $dir, root_path() . $dir);
            }
        }
        // 执行启用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(App::instance());
                if (method_exists($class, 'enable')) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        $info = get_addons_info($name);
        $info['status'] = 1;
        set_addons_info($name, $info);
        if (isset($info['has_menulist']) && $info['has_menulist']) {
            Menu::enableAddonMenu($name);
        }
        // 刷新插件缓存

        return true;
    }

    /**
     * 执行安装数据库脚本
     * @param type $name 模块名(目录名)
     * @return boolean
     */
    public static function runSQL($name = '', $Dir = 'install')
    {
        $sql_file = addon_path() . "{$name}" . ds() . "{$Dir}.sql";
        if (file_exists($sql_file)) {
            $sql_statement = Sql::getSqlFromFile($sql_file);
            if (!empty($sql_statement)) {
                foreach ($sql_statement as $value) {
                    $value = str_ireplace('__PREFIX__', get_database("prefix"), $value);
                    $value = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $value);
                    try {
                        Db::execute($value);
                    } catch (Exception $e) {
                        throw new Exception("导入SQL失败，请检查{$name}.sql的语句是否正确");
                    }
                }
            }
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
            throw new AddonException("发现冲突文件", -3, ['conflictlist' => $list]);
        }
        return true;
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
     * 获取插件备份目录
     */
    public static function getAddonsBackupDir()
    {
        $dir = root_path() . 'runtime' . ds() . 'addons' . ds();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
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
            'view',
        ];
    }

    /**
     * 获取插件源资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getSourceAssetsDir($name)
    {
        return root_path("addons") . $name . ds() . 'assets' . ds();
    }

    /**
     * 获取插件目标资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = root_path() . str_replace("/", ds(), "public/addons/{$name}/");
        return $assetsDir;
    }

    /**
     * 检测插件是否完整.
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(addon_path() . $name)) {
            throw new Exception('插件不存在！');
        }
        $addonClass = get_addons_class($name);
        if (!$addonClass) {
            throw new Exception('插件主启动程序不存在！');
        }
        $addon = new $addonClass(App::instance());
        if (!$addon->getInfo()) {
            throw new Exception('配置文件不完整！');
        }
        return true;
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
}
