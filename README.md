# CLEVERGY
Clever Home Automation
* Optimize self consumption of your home made energy
* Take into account climate and weather data
* Charge your electrical car with your own electricity
* Minimize cost for heating and warm water production
* Automate various devices such as lights, blinds, cooling according to your needs
* Support for a growing variety of devices from world leading manufacturers such as SolarEdge, Siemens, SmartFox, Netatmo, Shelly, MyStrom, Weishaupt, Volkswagen, Gardena, Threema
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
