<?php

/**
 * Joomla Varnish plugin
 *
 * Code style:
 * - PHP PSR-12 https://www.php-fig.org/psr/psr-12/
 * - https://github.com/joomla/joomla-cms/blob/4.0-dev/.php_cs.dist
 *
 * @author    Emerson Rocha <emerson@alligo.com.br>
 * @copyright 2015 (C) Alligo Ltda.
 * @license   Public Domain <https://unlicense.org>
 * @link      https://github.com/alligo/joomla_plg_system_alligovarnish
 */

// phpcs:disable
// PSR-1 does not like this side-effect, but Joomla requires this on every
// PHP file.
defined('_JEXEC') or die;
// phpcs:enable

class PlgSystemAlligovarnish extends JPlugin// phpcs:ignore
{
    /**
     * Constant to express that is logged user
     *
     * @var int
     */
    const RULE_LOGGED = 1; // phpcs:ignore
    // public const RULE_LOGGED = 1; // PSR-12 on php 7.1+

    /**
     * Constant to express that is Joomla component
     *
     * @var int
     */
    const RULE_COMPONENT = 2; // phpcs:ignore
    // public const RULE_COMPONENT = 2; // PSR-12 on php 7.1+

    /**
     * Constant to express that is URL prefix
     *
     * @var int
     */
    const RULE_URLPREFIX = 3; // phpcs:ignore
    // public const RULE_URLPREFIX = 3; // PSR-12 on php 7.1+

    /**
     * Constant to express that is URL prefix
     *
     * @var int
     */
    const RULE_URL = 4; // phpcs:ignore
    // public const RULE_URLPREFIX = 3; // PSR-12 on php 7.1+

    /**
     * Constant to express that is Joomla ItemID (ID of menu)
     *
     * @var int
     */
    const RULE_ITEMID = 5; // phpcs:ignore
    // public const RULE_ITEMID = 4; // PSR-12 on php 7.1+

    /**
     * Default time for browser time, if not specified on $exptbrowser
     *
     * @var Integer
     */
    protected $browsertime = 0;

    /**
     * Debug info
     *
     * @var Array
     */
    protected $debug = [];

    /**
     * Debug is enabled?
     *
     * @var Boolean
     */
    protected $debug_is = false;

    /**
     * Parsed browser exeptions for each menu item id
     *
     * @var Array
     */
    protected $exptbrowser = [];

    /**
     * Parsed proxy cache exeptions for each menu item id
     *
     * @var Array
     */
    protected $exptproxy = [];

    protected $exception_header_key = '';
    protected $exception_header_val = '';

    /**
     * Extra headers enabled?
     *
     * @var Array
     */
    protected $extrainfo = 0;

    /**
     * This plugin is running on Joomla frontend?
     *
     * @var Boolean
     */
    protected $is_site = false;

    /**
     * Menu item ID (is is running on front-end)
     *
     * @var Integer
     */
    protected $itemid = 0;

    /**
     * Default time for proxy time, if not specified on $exptproxy
     *
     * @var Integer
     */
    protected $varnishtime = 0;

    /**
     * Time to inform Varnish cache that contend can be used as old
     * object from cache even if expired
     *
     * @var Integer
     */
    protected $stale_time = 30;

    protected $run = 0;

    // /**
    //  * Constructor.
    //  *
    //  * @param   object  &$subject  The object to observe.
    //  * @param   array   $config    An optional associative array of configuration settings.
    //  *
    //  */
    // public function __construct($subject, $config)
    // {
    //     // Calling the parent Constructor
    //     parent::__construct($subject, $config);

    //     // $this->params = $config->params;

    //     // Do some extra initialisation in this constructor if required

    //     //echo '<!--' . print_r($config, true) . '--!>';
    // }

    /**
     * Convert a string terminated by s, m, d or y to seconds
     *
     * @param String $time Time string
     *
     * @return Integer Time in seconds
     */
    private function getTimeAsSeconds($time)
    {
        $seconds = 0;
        if (!empty($time)) {
            switch (substr($time, -1)) {
                case 's':
                    $seconds = (int) substr($time, 0, -1);
                    break;
                case 'm':
                    $minutes = (int) substr($time, 0, -1);
                    $seconds = $minutes * 60;
                    break;
                case 'h':
                    $hour = (int) substr($time, 0, -1);
                    $seconds = $hour * 60 * 60;
                    break;
                case 'd':
                    $days = (int) substr($time, 0, -1);
                    $seconds = $days * 24 * 60 * 60;
                    break;
                case 'M':
                    $months = (int) substr($time, 0, -1);
                    $seconds = $months * 30 * 24 * 60 * 60;
                    break;
                case 'y':
                    $years = (int) substr($time, 0, -1);
                    $seconds = $years * 365 * 24 * 60 * 60;
                    break;

                default:
                    // ¯\_(ツ)_/¯
                    $seconds = (int) $time;
                    break;
            }
        }

        return $seconds;
    }

    /**
     * Explode lines and itens separed by : and return and array,
     * with debug option if syntax error
     *
     * @param Array $string String to be converted
     *
     * @return Array
     */
    private function getTimes($string)
    {
        $times = [];
        if (!empty($string)) {
            $lines = explode("\r\n", $string);

            foreach ($lines as $line) {
                // TODO: remove separator :
                if (strpos($line, '|') !== false) {
                    $parts = explode("|", $line);
                } else {
                    $parts = explode(":", $line);
                }

                if (count($parts) < 2) {
                    $this->debug['wrongtime'] = empty($this->debug['wrongtime']) ? $line : $this->debug['wrongtime'] . ',' . $line;
                } else {
                    if (substr($parts[1], 0, 1) === "0") {
                        // Do not cache this
                        $times[(int) $parts[0]] = false;
                    } elseif (!in_array(substr($parts[1], -1), ['s', 'm', 'h', 'd', 'M', 'y'])) {
                        $this->debug['wrongtime'] = empty($this->debug['wrongtime']) ? $line : $this->debug['wrongtime'] . ',' . $line;
                    } else {
                        $times[(int) $parts[0]] = $this->getTimeAsSeconds($parts[1]);
                    }
                }
            }
        }

        return $times;
    }

    /**
     * Check if is one individual string is a rule, and already break it value
     * in some parts
     *
     * Return null if invalid / malformated and False if is a comment rule.
     *
     * @param  String  $rule_raw  A full raw rule to evaluate
     *
     * @return  Array
     */
    protected function getRule($rule_raw)
    {
        $result = [null, null, -1];
        $accepted = ['url', 'urlprefix', 'component', 'menu', 'logged'];
        if (isset($rule) && is_string($rule)) {
            // Start with comment
            if ($rule[0] === "#" or substr($rule, 0, 2) === "//") {
                return false;
            }

            // Have comment on some part of the line
            if (strpos($rule_raw, "#") !== false) {
                $rule_raw = substr($rule_raw, 0, strpos($rule_raw, "#"));
            }

            if (strpos($rule_raw, "//") !== false) {
                $rule_raw = substr($rule_raw, 0, strpos($rule_raw, "//"));
            }

            $parts = explode("|", $rule_raw);
            if (count($parts) !== 3 or in_array($parts[0], $accepted)) {
                return null;
            }

            $result[0] = trim($parts[0]);
            $result[1] = trim($parts[1]);
            $result[2] = $this->getTimeAsSeconds(trim($parts[2]));
            return $result;
        }
        return null;
    }

    /**
     * Early check if this is an exception and should be totally ignored
     *
     * @return Int
     */
    private function isException()
    {
        // TODO: deal with old cookies from logged users
        //       if they are present on a request
        if ((int) $this->params->get('never_logged_enabled') > 0) {
            if (!JFactory::getUser()->guest) {
                // $this->isExceptionCustomHTTPHeader();
                return self::RULE_LOGGED;
            }
        }

        if ((int) $this->params->get('never_option_enabled') > 0) {
            $raw = $this->params->get('never_option', '');
            $lines = array_filter(explode("\r\n", $raw));
            if (in_array(JFactory::getApplication()->input->get('option'), $lines)) {
                $this->isExceptionCustomHTTPHeader(
                    $this->params->get('never_option_httpheader', '')
                );
                return self::RULE_COMPONENT;
            }
        }

        if ((int) $this->params->get('never_prefix_enabled') > 0) {
            $raw = $this->params->get('never_prefix', '');
            $lines = array_filter(explode("\r\n", $raw));
            $uri = \Joomla\CMS\Uri\Uri::getInstance();
            $cpath = str_replace($uri->base(false), '/', JURI::current());
            foreach ($lines as $key => $value) {
                if (strlen($value) > 1) { // No '' or '/'
                    if (strpos($cpath, $value) === 0) {
                        $this->isExceptionCustomHTTPHeader(
                            $this->params->get('never_prefix_httpheader', '')
                        );
                        return self::RULE_URLPREFIX;
                    }
                }
            }
        }
        if ((int) $this->params->get('never_itemid_enabled') > 0) {
            $raw = $this->params->get('never_itemid', '');
            $lines = array_filter(explode("\r\n", $raw));
            // return print_r($lines, true);
            // return JFactory::getApplication()->getMenu()->getActive()->id;
            if (in_array((string) JFactory::getApplication()->getMenu()->getActive()->id, $lines)) {
                $this->isExceptionCustomHTTPHeader(
                    $this->params->get('never_itemid_httpheader', '')
                );
                return self::RULE_ITEMID;
            }
        }

        return 0;
    }

    /**
     * Extends isException()
     *
     * @param String $raw_header Raw header string
     *
     * @return Void
     *
     * @see isException()
     */
    private function isExceptionCustomHTTPHeader($raw_header)
    {
        $parts = explode(': ', (string) $raw_header);
        if (count($parts) == 2) {
            $this->exception_header_key = $parts[0];
            $this->exception_header_val = $parts[1];
        }
    }

    /**
     * Extends isException()
     *
     * @param String $raw_header Raw header string
     *
     * @return Void
     *
     * @see isException()
     */
    private function isExceptionCustomCookie($raw_header)
    {
        throw new Exception('Not implemented');
    }

    /**
     * Check if a rule and optionaly a variable match current state.
     *
     * @param  String  $rule      A rule to evaluate
     * @param  String  $variable  Variable for the rule
     *
     * @return  Bool
     */
    protected function isRule($rule, $variable = null)
    {
        if ($rule == 'component') {
            $jos_option = JFactory::getApplication()->input->get('option');
            return $jos_option === $variable;
        }

        if ($rule == 'logged') {
            return !JFactory::getUser()->guest;
        }

        if ($rule == 'menu') {
            $menu = JFactory::getApplication()->getMenu()->getActive()->id;
            return ((string) $variable === (string) $variable);
        }

        $uri = \Joomla\CMS\Uri\Uri::getInstance();
        $cpath = str_replace($uri->base(false), '/', JURI::current());

        if ($rule == 'url') {
            return $variable === $cpath;
        }

        if ($rule == 'urlprefix') {
            return (strpos($variable, $cpath) === 0);
        }

        return null;
    }

    /**
     * This event is triggered after the framework has loaded and the
     * application initialise method has been called.
     *
     * @return Void
     */
    public function onAfterInitialise()
    {
        $this->is_site = JFactory::getApplication()->isSite();
    }

    /**
     * This event is triggered after the framework has dispatched the
     * application.
     * Dispatching is the process of pulling the option from the request
     * object and mapping them to a component. If the component does not
     * exist, it handles determining a default component to dispatch.
     *
     * @link https://docs.joomla.org/Plugin/Events/System#onAfterDispatch
     *
     * @return Void
     */
    public function onAfterDispatch()
    {
        $this->is_site && $this->prepareToCache();
    }

    /**
     * This event is triggered after the framework has rendered the application.
     *
     * Rendering is the process of pushing the document buffers into the
     * template placeholders, retrieving data from the document and pushing
     * it into the JResponse buffer.
     *
     * When this event is triggered the output of the application is
     * available in the response buffer.
     *
     * @return void
     */
    public function onAfterRender()
    {
        // $this->is_site && $this->prepareToCache();  // testing disabled
    }

    /**
     * This event is triggered before the framework creates the Head
     * section of the Document.
     *
     * @return Void
     */
    public function onBeforeCompileHead()
    {
        // $this->is_site && $this->prepareToCache(); // testing disabled

        // @todo Feature: maybe implement a way to set robots "nofollow" if site
        // is not behind varnish cache
        //if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] !== 80) {
        //    JFactory::getDocument()->setMetaData('robots', 'noindex, nofollow');
        //}
        // echo '<!--' . print_r($this->params, true) . '--!>';
    }

    /**
     * This event is triggered immediately before the framework has rendered
     *  the application.
     *
     * Rendering is the process of pushing the document buffers into the
     * template placeholders, retrieving data from the document and pushing it
     * into the JResponse buffer.
     *
     * @return Void
     */
    public function onBeforeRender()
    {
        // Not necessary
        // $this->is_site && $this->prepareToCache();
    }

    /**
     * Set cache. Should be called ONLY on Joomla front-end. Backend
     * should call $this->setCacheProxy(null) for ensure no cache;
     *
     * @see setCacheBrowser()
     * @see setCacheProxy()
     *
     * @return Void
     */
    public function setCache()
    {
        if ($this->setCacheExceptions()) {
            return;
        }

        $debug_has_defaults = '';
        // Exist specific instruction for Proxy cache time? No? Use defaults
        if (!empty($this->exptproxy[$this->itemid])) {
            $this->setCacheProxy($this->exptproxy[$this->itemid]);
            $debug_has_defaults .= 'Custom proxy time';
        } else {
            $this->setCacheProxy($this->varnishtime);
            $debug_has_defaults .= 'Default proxy time';
        }

        // Exist specific instruction for Broser cache time? No? Use defaults
        if (!empty($this->exptbrowser[$this->itemid])) {
            $this->setCacheBrowser($this->exptbrowser[$this->itemid]);
            $debug_has_defaults .= ', Custom browser time';
        } else {
            $this->setCacheBrowser($this->browsertime);
            $debug_has_defaults .= ', Default browser time';
        }
        if ($this->debug_is) {
            JFactory::getApplication()
                ->setHeader('X-Alligo-JoomlaItemId', $this->itemid, true);
            JFactory::getApplication()
                ->setHeader('X-Alligo-CacheTimes', $debug_has_defaults, true);
        }
    }

    /**
     * Extends isException()
     *
     * @return Void
     *
     * @see isException()
     */
    private function setCache2()
    {
        throw new Exception('Not implemented');
    }

    /**
     * Some places of Jooma should never cache
     *
     * @todo Ainda não está funcional da forma como está sendo chamada. Deve
     *       ser chamada em fase mais inicial da sequencia de eventos do
     *       Joomla (fititnt, 2015-12-20 07:27)
     *
     * @return Boolean
     */
    protected function setCacheExceptions()
    {

        $component = JFactory::getApplication()->input->getCmd('option', '');
        $reason = false;
        if ($component === 'com_ajax') {
            $this->setCacheProxy(false);
            $reason = 'Ajax Request';
        } elseif ($component === 'com_banners') {
            $task = JFactory::getApplication()->input->getCmd('task', '');
            if ($task === 'click') {
                $this->setCacheProxy(false);
                $reason = 'Ajax Request';
            }
        } elseif (!JFactory::getUser()->guest) {
            $this->setCacheProxy(false);
            $reason = 'joomla_logged_in';
        } elseif ($component === 'com_users') {
            $this->setCacheProxy(false);
            $reason = 'Possible login page?';
        }

        if ($reason) {
            $this->setCacheProxy(null);
            if ($this->debug_is) {
                JFactory::getApplication()
                    ->setHeader('X-Alligo-ProxyCache', 'disabled');
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param  Int  $maxage  max-age
     * @param  Int  $smaxage s-maxage
     * @param  Bool  $extrainfo If must send extra info, prefixed with X-.
     *                          Can be used if other extensions are overriding
     *                          the custom headers.
     *
     * @return  Void
     */
    protected function setCacheHeaders(
        $maxage = false,
        $smaxage = false,
        $stale_while_revalidate = false,
        $extrainfo = false
    ) {
        $this->run += 1;
        $extrainfo = true;
        // echo "<!-- maxage" . $maxage . " --!>";
        // JFactory::getApplication()->setHeader('x-teste3', 'valor-teste2', true);
        JFactory::getApplication()->setHeader('x-debug-run-' . $this->run, $maxage . '-' . $smaxage);

        // return '';
        $cachecontrolstr = 'no-cache, no-store, must-revalidate';
        if ($maxage === false or $maxage === 0) {
            // Default $cachecontrolstr
            JFactory::getApplication()->setHeader('Pragma', 'no-cache', true);
            JFactory::getApplication()->setHeader('Expires', '0', true);
        } else {
            $epoch = strtotime('+' . $maxage . 's', JFactory::getDate()->getTimestamp());
            JFactory::getApplication()->setHeader('Expires', date('D, j M Y H:i:s T', $epoch), true);
            if ($extrainfo) {
                JFactory::getApplication()->setHeader('X-Expires', date('D, j M Y H:i:s T', $epoch), true);
            }
        }
        if ($maxage > 0) {
            // Default $cachecontrolstr
            $cachecontrolstr = 'public, max-age=' . $maxage;
            JFactory::getApplication()->setHeader('Pragma', 'cache', true);
            if ($extrainfo) {
                JFactory::getApplication()->setHeader('X-Pragma', 'cache', true);
            }
        }
        if (!empty($smaxage)) {
            // Default $cachecontrolstr
            $cachecontrolstr = $cachecontrolstr . ', s-maxage=' . (int) $smaxage;
        }
        if (!empty($stale_while_revalidate)) {
            // Default $cachecontrolstr
            $cachecontrolstr = $cachecontrolstr . ', stale-while-revalidate=' . (int) $stale_while_revalidate;
        }

        // echo $cachecontrolstr; die;

        // JFactory::getApplication()->setHeader('Cache-Control', $cachecontrolstr, true);
        JFactory::getApplication()->setHeader('Cache-Control', $cachecontrolstr, true);
        if ($extrainfo) {
            JFactory::getApplication()->setHeader('X-Cache-Control', $cachecontrolstr, true);
        }
    }

    /**
     * Set headers specific for the browser cache
     *
     * @deprecated
     *
     * @param Integer $time Time in seconds
     *
     * @return Void
     */
    protected function setCacheBrowser($time = null)
    {
        if (empty($time)) {
            //JFactory::getApplication()->allowCache(false);
            JFactory::getApplication()->setHeader(
                'Cache-Control',
                'no-cache, no-store, must-revalidate',
                true
            );
            JFactory::getApplication()->setHeader('Pragma', 'no-cache', true);
            JFactory::getApplication()->setHeader('Expires', '0', true);
            if ($this->debug_is) {
                JFactory::getApplication()->setHeader(
                    'X-Alligo-BrowserCache',
                    'disabled'
                );
            }
        } else {
            //date_default_timezone_set('GMT');
            $epoch = strtotime(
                '+' . $time . 's',
                JFactory::getDate()->getTimestamp()
            );

            //JFactory::getApplication()->allowCache(true);
            JFactory::getApplication()->setHeader(
                'Cache-Control',
                'public, max-age=' . $time,
                true
            );
            JFactory::getApplication()->setHeader('Pragma', 'cache', true);
            JFactory::getApplication()->setHeader(
                'Expires',
                date('D, j M Y H:i:s T', $epoch),
                true
            );
            if ($this->extrainfo) {
                JFactory::getApplication()->setHeader(
                    'X-Cache-Control',
                    'public, max-age=' . $time,
                    true
                );
                JFactory::getApplication()->setHeader('X-Pragma', 'cache');
                JFactory::getApplication()->setHeader(
                    'X-Expires',
                    date('D, j M Y H:i:s T', $epoch),
                    true
                );
            }
            if ($this->debug_is) {
                JFactory::getApplication()->setHeader(
                    'X-Alligo-BrowserCache',
                    'enabled, ' . $time . 's, datetime ' . date(
                        'D, j M Y H:i:s T',
                        $epoch
                    )
                );
            }
        }
    }

    /**
     * Set headers specific for the proxy cache
     *
     * @deprecated
     *
     * @param Integer $time Time in seconds
     *
     * @return Void
     */
    protected function setCacheProxy($time = null)
    {
        if (empty($time)) {
            JFactory::getApplication()->setHeader('Surrogate-Control', 'no-store', true);
            if ($this->debug_is) {
                JFactory::getApplication()->setHeader('X-Alligo-ProxyCache', 'disabled');
            }
        } else {
            //date_default_timezone_set('GMT');
            $epoch = strtotime(
                '+' . $time . 's',
                JFactory::getDate()->getTimestamp()
            );
            //JFactory::getApplication()->setHeader('Surrogate-Control', 'public, max-age=' . $time, true);
            JFactory::getApplication()->setHeader(
                'Surrogate-Control',
                'max-age=' . $time . '+' . $this->stale_time,
                true
            );
            if ($this->debug_is) {
                JFactory::getApplication()->setHeader(
                    'X-Alligo-ProxyCache',
                    'enabled, ' . $time . 's, datetime ' . date(
                        'D, j M Y H:i:s T',
                        $epoch
                    )
                );
            }
        }
    }

    /**
     * More than one plugin WILL try to change headers that define cache, so
     * its sad, but we need to setup more than one time this call. Remember
     * that just put on last event maybe will not work, because its not
     * very sure that will trigger the last event
     *
     * @see https://docs.joomla.org/Plugin/Events/System
     *
     * @return Void
     */
    public function prepareToCache()
    {
        // NOTE: when doing debug, remember that $app->setHeader() will
        //       NOT send any new header if any echo / print_r was previously
        //       used. This is not a bug, is how low level HTTP requests
        //       should operate (aka only send headers before echoing
        //       real content)

        // JFactory::getApplication()->setHeader('x-teste4', 'valor-teste2', true);
        // JFactory::getApplication()->setHeader('X-Alligo-ProxyCache', 'teeee');
        // JFactory::getApplication()->setHeader('X-Alligo-ProxyCache2', 'teeee', true);
        // echo "<!-- " . print_r($this->params, true) . '--!>';

        // If debug is enabled, this code section will look for "client side"
        // headers starting with "x-joomla" and, if they exist, will print
        // it back as if was created by this site.
        // The only reason to use this is advanced debugging (like echoing
        // back variables from Varnish proxy cache)
        if ($this->params->get('debug', "0") === "1") {
            foreach ($_SERVER as $key => $value) {
                if (strpos(strtolower($key), 'x_joomla')) {
                    $xheader = str_replace(
                        '_',
                        '-',
                        str_replace('http_', '', strtolower($key))
                    );
                    JFactory::getApplication()->setHeader($xheader, $value, true);
                }
            }
        }

        // Early test if this page is a caching exception to abort earlier.
        $exception = $this->isException();
        if ($exception > 0) {
            if ($this->params->get('debug', "0") === "1") {
                JFactory::getApplication()->setHeader('x-cache-debug-exception', (string) $exception, true);
            }
            // JFactory::getApplication()->setHeader('x-teste2', 'valor-teste2', true);
            if ($this->exception_header_key && $this->exception_header_val) {
                JFactory::getApplication()->setHeader(
                    $this->exception_header_key,
                    $this->exception_header_val,
                    true
                );
            }
            // if ($this->debug_is) {
            // }
            // echo '<!--' . $exception . '--!>';
            return null;
        }

        // Typical use
        $maxage = null;
        $smaxage = null;
        $stale_while_revalidate = null;
        if ((int) $this->params->get('max-age_enabled') > 0) {
            if (in_array($this->params->get('max-age_enabled'), ["1", "2"])) {
                $maxage = $this->getTimeAsSeconds($this->params->get('max-age_default'));
            }
            // $maxage_default =
        }
        if ((int) $this->params->get('s-maxage_enabled') > 0) {
            if (in_array($this->params->get('s-maxage_enabled'), ["1", "2"])) {
                $smaxage = $this->getTimeAsSeconds($this->params->get('s-maxage_default'));
            }
            // $maxage_default =
        }
        if ((int) $this->params->get('stale-while-revalidate_enabled') > 0) {
            if (in_array($this->params->get('stale-while-revalidate_enabled'), ["1", "2"])) {
                $stale_while_revalidate = $this->getTimeAsSeconds($this->params->get('stale-while-revalidate_default'));
            }
            // $maxage_default =
        }

        // $stale_while_revalidate = 123;

        $this->setCacheHeaders(
            $maxage,
            $smaxage,
            $stale_while_revalidate,
            (bool) $this->params->get('extrainfo')
        );

        // Deprecated: remove after here
        /*
        if ($this->is_site) {
            $menu_active = JFactory::getApplication()->getMenu()->getActive();
            $this->itemid = empty($menu_active) || empty($menu_active->id) ? 0 : (int) $menu_active->id;
            $this->varnishtime = $this->getTimeAsSeconds($this->params->get('varnishtime', ''));
            $this->browsertime = $this->getTimeAsSeconds($this->params->get('browsertime', ''));
            $this->exptproxy = $this->getTimes($this->params->get('exptproxy', ''));
            $this->exptbrowser = $this->getTimes($this->params->get('exptbrowser', ''));
            $this->extrainfo = (bool) $this->params->get('extrainfo', false);
            $this->debug_is = (bool) $this->params->get('debug', false);
            $this->setCache();
        } else {
            // Tip for varnish that we REALLY do not want cache this
            $this->setCacheProxy(null);
        }
        if ($this->debug_is && count($this->debug)) {
            JFactory::getApplication()->setHeader('X-Alligo-Debug', json_encode($this->debug), true);
        }
        */
        // echo "<!-- " . print_r($this->params, true) . '--!>';
    }
}
