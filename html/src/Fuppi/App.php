<?php

namespace Fuppi;

use Exception;

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'User.php');


class App
{
    protected \Fuppi\Voucher $voucher;
    protected \Fuppi\User $user;
    protected \Fuppi\Config $config;
    protected \Fuppi\Db $db;
    protected \Fuppi\FileSystem $fileSystem;

    protected static array $instances = [];
    protected function __construct()
    {
    }

    protected function init()
    {
        $this->config = Config::getInstance();
        $this->db = new Db();
        $this->fileSystem = FileSystem::getInstance();
        $this->user = new User();

        if (empty($_SESSION['\Fuppi\App.user']) && !empty($_COOKIE[$this->config->session_persist_cookie_name])) {
            // load session from cookie
            try {
                if ($cookieUser = User::findBySessionId($_COOKIE[$this->config->session_persist_cookie_name])) {
                    $this->user = $cookieUser;

                    // regenerate the session_id to reduce css attack vector from cookies
                    if ($this->user->user_id <= 0) {
                        // not logged in, destroy the cookie
                        setcookie($this->config->session_persist_cookie_name, session_id(), -1, $this->config->session_persist_cookie_path, $this->config->session_persist_cookie_domain);
                        unset($_COOKIE[$this->config->session_persist_cookie_name]);
                    } else {
                        $oldSessionId = $_COOKIE[$this->config->session_persist_cookie_name];
                        session_regenerate_id();
                        $newSessionId = session_id();
                        setcookie($this->config->session_persist_cookie_name, $newSessionId, time() + $this->config->session_persist_cookie_lifetime, $this->config->session_persist_cookie_path, $this->config->session_persist_cookie_domain);
                        $this->user->extendPersistentCookie($oldSessionId, $newSessionId, time() + $this->config->session_persist_cookie_lifetime, $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
                    }
                }
            } catch (Exception $e) {
            }
        } else {
            // load session from PHP session handler if possible, or set to default
            $this->user->setData($_SESSION['\Fuppi\App.user'] ?? []);
            $this->user->setSettings($_SESSION['\Fuppi\App.userSettings'] ?? []);
        }

        if (isset($_SESSION['\Fuppi\App.voucher'])) {
            try {
                $voucher = Voucher::getOne($_SESSION['\Fuppi\App.voucher']['voucher_id']);
                if (!is_null($voucher->expires_at) && strtotime($voucher->expires_at) < time()) {
                    logout();
                    fuppi_add_error_message(['The voucher "' . $voucher->voucher_code . '" has expired']);
                    redirect($_SERVER['REQUEST_URI']);
                } else {
                    $this->voucher = $voucher;
                }
            } catch (\Exception $e) {
                unset($_SESSION['\Fuppi\App.voucher']);
            }
        }
    }

    public function __destruct()
    {
        if (($this->user ?? null) instanceof User) {
            $_SESSION['\Fuppi\App.user'] = $this->user->getData();
            $_SESSION['\Fuppi\App.userSettings'] = $this->user->getSettings();
        }
        if (($this->voucher ?? null) instanceof Voucher) {
            $_SESSION['\Fuppi\App.voucher'] = $this->voucher->getData();
        }
    }

    public static function getInstance($namespace = "Fuppi")
    {
        if (!array_key_exists($namespace, self::$instances)) {
            self::$instances[$namespace] = new self();
            self::$instances[$namespace]->init();
        }
        return self::$instances[$namespace];
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getFilesystem() : \Fuppi\FileSystem
    {
        return $this->fileSystem;
    }

    public function getVoucher() : ?Voucher
    {
        if (isset($this->voucher)) {
            return $this->voucher;
        }
        return null;
    }

    public function setVoucher(Voucher $voucher = null)
    {
        if (is_null($voucher)) {
            unset($this->voucher);
        } else {
            $this->voucher = $voucher;
        }
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getDb() : Db
    {
        return $this->db;
    }
}
