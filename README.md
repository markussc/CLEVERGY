# OSHANS
Open smart home automation, notification and statistics center


### Installation

* Create a parameters.yaml file in config/ folder according to the parameters.yaml.dist file
* Create a .env.local file according to the .env.local.dist file

```sh
$ chmod +x nodesource_setup.sh
$ sudo ./nodesource_setup.sh
$ sudo apt install -y nodejs
$ curl -sL https://dl.yarnpkg.com/debian/pubkey.gpg | sudo apt-key add -
$ echo "deb https://dl.yarnpkg.com/debian/ stable main" | sudo tee /etc/apt/sources.list.d/yarn.list
$ sudo apt update && sudo apt install yarn php-zip
$ composer install
$ wget https://get.symfony.com/cli/installer -O - | bash
$ export PATH="$HOME/.symfony/bin:$PATH"
$ yarn install
$ yarn run encore prod
$ bin/console cache:warmup
$ chmod -R 777 var/log/
```

* Check using the following command, whether the application can be served correctly using the symfony internal webserver:
```sh
$ symfony server:start
```
