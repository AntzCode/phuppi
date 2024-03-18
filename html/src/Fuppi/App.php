<?php

namespace Fuppi;

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'User.php');

use Fuppi\User;

class App
{
    protected \Fuppi\Voucher $voucher;
    protected \Fuppi\User $user;
    protected \Fuppi\Config $config;
    protected \Fuppi\Db $db;

    protected static array $instances = [];
    protected function __construct()
    {
    }

    protected function init()
    {
        $this->config = Config::getInstance();
        $this->db = new Db();
        $this->user = new User();
        $this->user->setData($_SESSION['\Fuppi\App.user'] ?? []);
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

    public function getVoucher(): ?Voucher
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

    public function getDb(): Db
    {
        return $this->db;
    }
}
