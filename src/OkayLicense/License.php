<?php
namespace OkayLicense;
use Okay\Admin\Controllers\LicenseAdmin;
use Okay\Core\Config;
use Okay\Core\Design;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\OkayContainer\OkayContainer;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Router;
use Okay\Core\Modules\Module;
use Okay\Core\ServiceLocator;
use Smarty;
class License
{
    private static $config;
    private static $module;
    private static $validLicense = false;
    private static $licenseType;
    private static $smarty;
    private static $response;
    private static $request;
    private static $inited = false;
    private $responseType;
    private $plugins;
    private static $modulesRoutes = array();

    public static function getHtml(Design $design, $template)
    {
        if ($design->isUseModuleDir() && !self::checkModule(self::getVendor($design->getModuleTemplatesDir()) , self::getName($design->getModuleTemplatesDir())))
        {
            return '';
        }
        if ($design->isUseModuleDir() === false)
        {
            $design->setSmartyTemplatesDir($design->getDefaultTemplatesDir());
        }
        else
        {
            $vendor = self::getVendor($design->getModuleTemplatesDir());
            $module_name = self::getName($design->getModuleTemplatesDir());
            $design->setSmartyTemplatesDir(array(
                rtrim($design->getDefaultTemplatesDir() , '/') . "/../modules/{$vendor}/{$module_name}/html",
                $design->getModuleTemplatesDir() ,
                $design->getDefaultTemplatesDir()
            ));
        }
        $html = self::$smarty->fetch($template);
        if (self::$validLicense === false && $template == 'index.tpl' && strpos($design->getDefaultTemplatesDir() , 'backend/design/html') !== false)
        {
            $h = self::$request::getDomainWithProtocol();
            $rootUrl = self::$request::getRootUrl();
            if (!in_array(self::$request->get('controller') , array(
                'LicenseAdmin',
                'AuthAdmin'
            )))
            {
                $html .= strtr('<script>$(function() {alert("Current lisense is wrong for domain \"$domain\"");})</script>' , array(
                    '$domain' => $rootUrl
                ));
            }
            if (!in_array(self::$request->get('controller') , array(
                '',
                'LicenseAdmin',
                'AuthAdmin'
            )))
            {
                self::$response->redirectTo("{$rootUrl}/backend/index.php?controller=LicenseAdmin");
            }
        }
        return $html;
    }
    private static function getVendor($module)
    {
        $module = str_replace(DIRECTORY_SEPARATOR, '/', $module);
        return preg_replace('~.*/?Okay/Modules/([a-zA-Z0-9]+)/([a-zA-Z0-9]+)/?.*~', '$1', $module);
    }
    private static function getName($module)
    {
        $module = str_replace(DIRECTORY_SEPARATOR, '/', $module);
        return preg_replace('~.*/?Okay/Modules/([a-zA-Z0-9]+)/([a-zA-Z0-9]+)/?.*~', '$2', $module);
    }
    public function startModule($id, $vendor, $module_name)
    {
        if (empty(self::$module))
        {
            return array();
        }
        $container = OkayContainer::getInstance();
        $startModule = array();
        $initclass = self::$module->getInitClassName($vendor, $module_name);
        if (!empty($initclass))
        {
            $module = new $initclass((int)$id, $vendor, $module_name);
            $module->init();
            foreach ($module->getBackendControllers() as $start)
            {
                $start = $vendor . '.' . $module_name . '.' . $start;
                if (!in_array($start, $startModule))
                {
                    $startModule[] = $start;
                }
            }
        }
        $routes = self::$module->getRoutes($vendor, $module_name);
        if (self::checkModule($vendor, $module_name) === false)
        {
            foreach ($routes as & $route)
            {
                $route['mock'] = true;
            }
        }
        if (self::checkModule($vendor, $module_name) === true)
        {
            $services = self::$module->getServices($vendor, $module_name);
            $container->bindServices($services);
            $smartyplugins = self::$module->getSmartyPlugins($vendor, $module_name);
            $container->bindServices($smartyplugins);
            foreach ($smartyplugins as $key => $plugin)
            {
                $this->plugins[$key] = $plugin;
            }
        }
        self::$modulesRoutes = array_merge(self::$modulesRoutes, $routes);
        return $startModule;
    }
    public function bindModulesRoutes()
    {
        Router::bindRoutes(self::$modulesRoutes);
    }
    public function registerSmartyPlugins()
    {
        if (!empty($this->plugins))
        {
            $SL = ServiceLocator::getInstance();
            $design = $SL->getService(Design::class);
            $smartymodule = $SL->getService(Module::class);
            foreach ($this->plugins as $plugin)
            {
                $service = $SL->getService($plugin['class']);
                $service->register($design, $smartymodule);
            }
        }
    }
    public function check()
    {
        if (self::$inited === false)
        {
            self::$validLicense = false;
            $SL = ServiceLocator::getInstance();
            self::$config = $SL->getService(Config::class);
            self::$module = $SL->getService(Module::class);
            self::$smarty = $SL->getService(Smarty::class);
            self::$response = $SL->getService(Response::class);
            self::$request = $SL->getService(Request::class);
            $licenseText = $this->validate(self::$config->license);
            if (self::checkForErrors() && $this->checkExpiration())
            {
                $this->checkDomains($licenseText->nl['domains']);
            }
            self::$response->addHeader('X-Powered-CMS: OkayCMS' . ' ' . self::$config->version . ' ' . $licenseText->nl['version_type']);
            self::$inited = true;
        }
        return self::$validLicense;
    }
    public function name(&$reversedText)
    {
        if (!empty($reversedText) && $this->check() === true)
        {
            $reversedText = preg_match_all('/./us', $reversedText, $ar);
            $reversedText = implode(array_reverse($ar[0]));
        }
    }
    public function getLicenseDomains()
    {
        $licenseText = $this->validate(self::$config->license);
        $cryptdomains = array();
        foreach ($licenseText->nl['domains'] as $h)
        {
            $cryptdomains[] = $h;
            if (count(explode('.', $h)) >= 2)
            {
                $cryptdomains[] = '*.' . $h;
            }
        }
        return $cryptdomains;
    }
    public function getLicenseExpiration()
    {
        $licenseText = $this->validate(self::$config->license);
        return $licenseText->expiration;
    }
    private static function checkModule($vendor, $module_name)
    {
        if ($vendor != "OkayCMS" || self::getLicenseType() != 'lite' || in_array($module_name, self::$freeModules))
        {
            return true;
        }
        return false;
    }
    private static function getLicenseType()
    {
        if (empty(self::$licenseType))
        {
            $licenseText = self::validate(self::$config->license);
            self::$licenseType = $licenseText->nl['version_type'];
        }
        return self::$licenseType;
    }
    private static function checkForErrors()
    {
        @($license = self::$config->license);
        if (empty($license))
        {
            self::error();
        }
        $licenseText = self::validate($license);
        if (empty($licenseText->nl) || !is_array($licenseText->nl['domains']) || empty($licenseText->nl['version_type']))
        {
            self::error();
        }
        if (!in_array($licenseText->nl['version_type'], array(
            'pro',
            'lite',
            'start',
            'standard',
            'premium'
        )))
        {
            self::error();
        }
        if (!class_exists(LicenseAdmin::class) || !class_exists(OkayContainer::class))
        {
            self::error();
        }
        return true;
    }
    private function checkDomains(array $domains)
    {
        self::$validLicense = false;
        $h = getenv('HTTP_HOST');
        if (in_array($h, $domains))
        {
            self::$validLicense = true;
        }
        foreach ($domains as $domain)
        {
            $reverseValid = array_reverse(explode('.', $domain));
            if (count($reverseValid) >= 2)
            {
                $reverseHost = array_reverse(explode('.', $h));
                foreach ($reverseValid as $level => $value)
                {
                    if (!isset($reverseHost[$level]) || $value != $reverseHost[$level])
                    {
                        break;
                    }
                    if ($level == count($reverseValid) - 1)
                    {
                        self::$validLicense = true;
                        return;
                    }
                }
            }
        }
    }
    private static function error()
    {
        throw new \Exception('Some error with license');
    }
    private static function validate($key)
    {
        $p = 13;
        // $g = 3;
        $x = 5;
        $r = '';
        $s = $x;
        $bs = explode(' ', $key);
        foreach ($bs as $bl)
        {
            for ($i = 0, $m = '';$i < strlen($bl) && isset($bl[$i + 1]);$i += 2)
            {
                $a = base_convert($bl[$i], 36, 10) - ($i / 2 + $s) % 27;
                $b = base_convert($bl[$i + 1], 36, 10) - ($i / 2 + $s) % 24;
                $m .= $b * pow($a, $p - $x - 5) % $p;
            }
            $m = base_convert($m, 10, 16);
            $s += $x;
            for ($a = 0;$a < strlen($m);$a += 2)
            {
                $r .= @chr(hexdec($m[$a] . $m[$a + 1]));
            }
        }
        $l = new \stdClass();
        @(list($l->domains, $l->expiration, $l->comment, $crypt) = explode('#', $r, 4));
        $l->domains = explode(',', $l->domains);
        if (!empty($crypt))
        {
            $crypt = (new \phpseclib\Crypt\Blowfish())->{decrypt}(base64_decode($crypt));
            list($l->nl['domains'], $l->nl['version_type']) = explode('#', $crypt, 2);
            if (!empty($l->nl['domains']))
            {
                $cryptdomains = array();
                foreach (explode(',', $l->nl['domains']) as $cryptdomain)
                {
                    $cryptdomains[] = trim(htmlspecialchars(strip_tags($cryptdomain)));
                }
                $l->nl['domains'] = $cryptdomains;
            }
        }
        else
        {
            $l->nl['domains'] = array();
            $l->nl['version_type'] = 'lite';
        }
        return $l;
    }
    public function setResponseType($type)
    {
        $this->responseType = $type;
    }
    public function __destruct()
    {
        if ($this->responseType == RESPONSE_HTML && self::$validLicense === false && strpos($_SERVER['REQUEST_URI'], 'backend') === false)
        {
            print "<div style='text-align:center; font-size:22px; height:100px;'>Лицензия недействительна<br><a href='http://okay-cms.com'>Скрипт интернет-магазина Okay</a></div>";
        }
    }
    private static $freeModules = array(
        'LigPay',
        'Rozetka'
    );

    private function checkExpiration()
    {
        self::$validLicense = false;
        $expiration = $this->getLicenseExpiration();
        if ($expiration == '*' || strtotime($expiration) >= strtotime(date('d.m.Y')))
        {
            self::$validLicense = true;
        }
        return self::$validLicense;
    }
}
