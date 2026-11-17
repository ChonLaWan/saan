<?php
namespace SchoolAtelier\Installer;

require_once __DIR__.'/DbAdmin.php';

/** MySQL / MariaDB implementation of DbAdmin. Identifiers and passwords are never concatenated unquoted. */
final class MysqlAdmin implements DbAdmin
{
    public const PRIVILEGES = 'SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES,LOCK TABLES';
    /** ER_DBACCESS_DENIED_ERROR and ER_ACCESS_DENIED_ERROR: the only errors that prove "this user cannot get in". */
    private const DENIED = [1044, 1045];

    private function pdo(array $c,?string $db=null):\PDO
    {
        return new \PDO('mysql:host='.$c['db_host'].';port='.$c['db_port'].($db!==null?';dbname='.$db:'').';charset=utf8mb4',$c['db_user'],$c['db_password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>5]);
    }
    private static function ident(string $name):string
    {
        if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name))throw new \InvalidArgumentException('ชื่อไม่ถูกต้อง');return '`'.$name.'`';
    }
    /** MySQL error number of a PDOException (connect errors carry it in getCode(), statement errors in errorInfo[1]). */
    public static function errno(\PDOException $e):int
    {
        return (int)($e->errorInfo[1]??0)?:(is_int($e->getCode())?$e->getCode():0);
    }
    /**
     * Account hosts for the new user: the loopback names when the server is local; otherwise the client host the server itself sees for the admin
     * connection (SUBSTRING_INDEX(USER(),'@',-1)), so the new user is not usable from anywhere else. Falls back to '%' only when the server cannot tell
     * (then the firewall decides, see docs/split-advanced.th.md). A "local" address that is really a forwarded port (database in a container
     * published on 127.0.0.1, an SSH tunnel) reaches the server from another address: that address is added, or the new user could never log in.
     */
    private function hosts(array $c,\PDO $pdo):array
    {
        try{$h=(string)$pdo->query("SELECT SUBSTRING_INDEX(USER(),'@',-1)")->fetchColumn();}catch(\PDOException){$h='';}
        $seen=$h!==''&&preg_match('/^[A-Za-z0-9._:-]{1,255}$/D',$h)?$h:'';
        if(in_array(strtolower($c['db_host']),['localhost','127.0.0.1','::1'],true))return array_values(array_unique(array_filter(['localhost','127.0.0.1',in_array(strtolower($seen),['localhost','127.0.0.1','::1'],true)?'':$seen])));
        return[$seen!==''?$seen:'%'];
    }
    public function exists(array $admin,string $db):bool
    {
        $s=$this->pdo($admin)->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');$s->execute([$db]);return (int)$s->fetchColumn()>0;
    }
    public function userExists(array $admin,string $user):?bool
    {
        try{$s=$this->pdo($admin)->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');$s->execute([$user]);return (int)$s->fetchColumn()>0;}
        catch(\PDOException $e){if(self::errno($e)===1045)throw $e;return null;} // wrong admin password is an error; an unreadable mysql.user is "unknown"
    }
    public function createDatabase(array $admin,string $db):void
    {
        $this->pdo($admin)->exec('CREATE DATABASE '.self::ident($db).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    public function dropDatabase(array $admin,string $db):void
    {
        $this->pdo($admin)->exec('DROP DATABASE IF EXISTS '.self::ident($db));
    }
    public function createUser(array $admin,string $user,string $password):void
    {
        $pdo=$this->pdo($admin);$done=[];
        try{foreach($this->hosts($admin,$pdo)as$h){$pdo->exec('CREATE USER '.$pdo->quote($user).'@'.$pdo->quote($h).' IDENTIFIED BY '.$pdo->quote($password));$done[]=$h;}}
        catch(\PDOException $e){foreach($done as$h){try{$pdo->exec('DROP USER IF EXISTS '.$pdo->quote($user).'@'.$pdo->quote($h));}catch(\PDOException){}}throw $e;} // only the hosts this call created
    }
    public function dropUser(array $admin,string $user):void
    {
        $pdo=$this->pdo($admin);foreach($this->hosts($admin,$pdo)as$h)$pdo->exec('DROP USER IF EXISTS '.$pdo->quote($user).'@'.$pdo->quote($h));
    }
    public function grant(array $admin,string $db,string $user):void
    {
        $pdo=$this->pdo($admin);self::ident($db);
        // "_" is a wildcard in the database part of GRANT: escape it, otherwise saan_ab_int would also cover saan_abXint
        $pattern='`'.str_replace('_','\\_',$db).'`';
        foreach($this->hosts($admin,$pdo)as$h)$pdo->exec('GRANT '.self::PRIVILEGES.' ON '.$pattern.'.* TO '.$pdo->quote($user).'@'.$pdo->quote($h));
    }
    public function canConnect(array $cred):bool
    {
        try{$this->pdo($cred,$cred['db_name'])->query('SELECT 1');return true;}catch(\PDOException){return false;}
    }
    /** False only when the server answers "access denied" (1044/1045). Timeouts, too many connections or a dead host throw: "could not check" is not "isolated". */
    public function canRead(array $cred,string $other):bool
    {
        try{$p=$this->pdo($cred,$other);$p->query('SHOW TABLES');return true;}
        catch(\PDOException $e){if(in_array(self::errno($e),self::DENIED,true))return false;throw $e;}
    }
    public function grants(array $cred):array
    {
        return $this->pdo($cred)->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN)?:[];
    }
}
