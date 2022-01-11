# OSHANS
Open smart home automation, notification and statistics center

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