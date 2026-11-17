# Written by saan-entrypoint from vhosts.conf.tpl at every start (ports and certificate come from the environment).
ServerName localhost
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Listen @APP_PORT@
Listen @WWW_PORT@
SSLProtocol -all +TLSv1.2 +TLSv1.3
SSLHonorCipherOrder off
SSLSessionTickets off
ErrorLog /proc/self/fd/2
LogFormat "%a %v:%p \"%r\" %>s %b %Dus" saan
CustomLog /proc/self/fd/1 saan

<Directory /srv/saan>
    Options -Indexes -Includes
    AllowOverride None
    Require all denied
</Directory>

# internal system (staff, students, guardians): /srv/saan/app
<VirtualHost *:@APP_PORT@>
    DocumentRoot /srv/saan/app/public
    SSLEngine on
    SSLCertificateFile @CERT@
    SSLCertificateKeyFile @KEY@
    <Directory /srv/saan/app/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# public school website: /srv/saan/www (holds no student data; PHP may not leave its own folder)
<VirtualHost *:@WWW_PORT@>
    DocumentRoot /srv/saan/www/public
    SSLEngine on
    SSLCertificateFile @CERT@
    SSLCertificateKeyFile @KEY@
    <Directory /srv/saan/www/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    php_admin_value open_basedir "/srv/saan/www/:/tmp/:/usr/share/zoneinfo/"
    php_admin_value upload_tmp_dir "/tmp"
</VirtualHost>
