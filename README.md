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

### Clevergy Meter (use existing SmartFox to simulate Shelly Pro 3EM energy meter)
* Install (e.g. using a Raspberry Pi) avahi (installed in many Linux distributions by default) and apache2
```sh
$ apt-get install avahi-daemon apache2
$ a2enmod proxy_http
```
* Create config file for avahi as /etc/avahi/services/clevergy.service
```
<?xml version="1.0" standalone='no'?>
<service-group>
  <name>ShellyPro3EM</name>
  <service>
    <type>_shelly._tcp</type>
    <port>80</port>
    <txt-record>app=Pro3EM</txt-record>
    <txt-record>gen=2</txt-record>
  </service>
  <service>
    <type>_http._tcp</type>
    <port>80</port>
    <txt-record>app=Pro3EM</txt-record>
    <txt-record>gen=2</txt-record>
  </service>
</service-group>
```
* Create config file for apache2 as /etc/apache2/sites-available/018-clevergy.conf
```
<VirtualHost *:80>
  ServerAdmin webmaster@netti.ch
  ServerName clevergy-meter.local
  ProxyPass / http://myinstance.myhost.com/
  ProxyPassReverse / http://myinstance.myhost.com/
</VirtualHost>
```
* activate configs, reload services or restart device
```sh
$ a2dissite 000-default.conf
$ a2ensite 018-clevergy
$ systemctl reload apache2
$ systemctl reload avahi-daemon
```