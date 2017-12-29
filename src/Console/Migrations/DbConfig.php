<?php
namespace Urbics\Civitools\Console\Migrations;

use Illuminate\Config\Repository as Repository;
use Urbics\Civitools\Traits\PackageNameTrait;

class DbConfig
{
    use PackageNameTrait;
    
    protected $package = 'urbics/civitools';
    protected $config;
    protected $connectName;
    protected $connection;

    public function __construct(Repository $config)
    {
        $this->config = $config;
        $this->connectName = env('CIVI_DB_CONNECTION');
        $this->setConnection();
    }

    public function connectionName()
    {
        return $this->connectName;
    }

    public function setConnectionName($name = '')
    {
        $this->connectName = $name ?: $this->connectName;
        $this->setConnection();
    }

    public function dbName()
    {
        return config("database.connections.{$this->connectName}.database");
    }

    public function sqlPath()
    {
        return ($this->packagePath() . '/schema/sql');
    }

    public function setDbName($dbName)
    {
        $curName = $this->dbName();
        config(["database.connections.{$this->connectName}.database" => $dbName]);
        return $curName;
    }

    protected function setConnection()
    {
        if (!config("database.connections.{$this->connectName}")) {
            config(["database.connections.{$this->connectName}" => [
                'driver'    => 'mysql',
                'host'      => env('CIVI_DB_HOST'),
                'database'  => env('CIVI_DB_DATABASE'),
                'username'  => env('CIVI_DB_USERNAME'),
                'password'  => env('CIVI_DB_PASSWORD'),
                'port'      => env('CIVI_DB_PORT'),
                'charset'   => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix'    => '',
            ]]);
        }
    }

    public function getConnection()
    {
        return config("database.connections.{$this->connectName}");
    }

}
