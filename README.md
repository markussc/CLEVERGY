# CLEVERGY
Clever Home Automation
* Optimize self consumption of your home made energy
* Take into account climate and weather data
* Charge your electrical car with your own electricity
* Minimize cost for heating and warm water production
* Automate various devices such as lights, blinds, cooling according to your needs
* Support for a growing variety of devices from world leading manufacturers such as SolarEdge, Siemens, SmartFox, Netatmo, MobileAlerts, Shelly, MyStrom, Weishaupt, Volkswagen, Gardena, Threema
* Simple installation and robust operation thanks to state of the art Docker runtime environment
* Easily extensible and customizable thanks to OpenSource licensing

### Run using Docker-Compose
* Create a parameters.yaml file in config/ folder according to the parameters.yaml dist file
* Create a .env.local file according to the .env.local.dist file
```sh
$ chmod +x start.sh
$ ./start.sh
```

* Check using the following command, whether the application is running:
```
$ docker-compose ps
```

### DB in Docker-Compose
* place a dump file in the current directory (e.g. dump.sql)
* in the config file (.env.local), use the following string for database access: 
```
DATABASE_URL=mysql://clevergy:clevergy@db:3306/clevergy?serverVersion=8.0.36
```
* the system will automatically create a new dump file (gzipped) every day with the name dump_clevergy.sql.gz in the backup folder
```sh
$ docker exec -i clevergy_db_<myinstancename> mysql -uroot -pdocker clevergy < dump.sql
```

### Clevergy Meter (use existing SmartFox to simulate Shelly Pro 3EM energy meter)
* Install (e.g. using a Raspberry Pi) avahi (installed in many Linux distributions by default) and apache2
```sh
$ apt-get install avahi-daemon apache2
$ a2enmod proxy_http
```
* Create config file for avahi as /etc/avahi/services/clevergy.service
```
<?xml version="1.0" standalone='no'?><!--*-nxml-*-->
<!DOCTYPE service-group SYSTEM "avahi-service.dtd">
<service-group>
  <name replace-wildcards="yes">%h</name>
  <service>
    <type>_shelly._tcp</type>
    <port>80</port>
  </service>
  <service>
    <type>_http._tcp</type>
    <port>80</port>
  </service>
</service-group>
```
* Create config file for apache2 as /etc/apache2/sites-available/018-clevergy.conf
```
<VirtualHost *:80>
  ServerAdmin webmaster@netti.ch
  ServerName shellypro3em.local
  ProxyPass / http://myinstance.myhost.com/
  ProxyPassReverse / http://myinstance.myhost.com/
</VirtualHost>
```
* activate configs, reload services or restart device
```sh
$ a2dissite 000-default.conf
$ a2ensite 018-clevergy
$ systemctl reload apache2
$ systemctl restart avahi-daemon
```
* set the correct hostname "ShellyPro3EM-clevergy.local" (in the Network Options --> Hostname menu); note, that the .local will be appended by the tool itself!
```sh
$ sudo raspi-config
$ shellypro3em
``