<?php
namespace SchoolAtelier\Installer;

/**
 * What the standard installer needs from a database server. $admin / $cred are arrays with db_host, db_port, db_user, db_password (and db_name for $cred).
 * MysqlAdmin is the real implementation; tests give the installer a fake where they need to observe or refuse provisioning calls
 * (the two databases themselves are real, temporary MariaDB/MySQL databases).
 */
interface DbAdmin
{
    public function exists(array $admin,string $db):bool;
    /** True/false when the account can be looked up, null when the server does not let the admin read it (unknown). A wrong admin password throws. */
    public function userExists(array $admin,string $user):?bool;
    public function createDatabase(array $admin,string $db):void;
    public function createUser(array $admin,string $user,string $password):void;
    /** Used only to undo what the same run created. */
    public function dropDatabase(array $admin,string $db):void;
    public function dropUser(array $admin,string $user):void;
    /** Grants only SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES,LOCK TABLES on that one database. */
    public function grant(array $admin,string $db,string $user):void;
    /** True when $cred logs in to its own database. */
    public function canConnect(array $cred):bool;
    /** True when $cred can read anything in database $other (it must not). Returns false ONLY for a proven "access denied"; any other failure throws. */
    public function canRead(array $cred,string $other):bool;
    /** @return list<string> GRANT statements the user holds (SHOW GRANTS). Throws when they cannot be read. */
    public function grants(array $cred):array;
}
