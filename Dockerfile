FROM ubuntu:22.04
LABEL maintainer="markus.schafroth@3084.ch"
LABEL description="OSHANS"
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get -y update && apt-get install -y \
        php \
        php-zip \
        php-xml \
        php-mysql \
        php-intl \
        php-gmp \
        php-curl \
        apache2 \
        acl \
        wget \
        curl \
        gnupg \
        ca-certificates \
        sshpass \
        wait-for-it \
        cron \
        python3 \
        python3-pip \
        htop \
        nano \
    && true

RUN curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
RUN echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list

RUN curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | apt-key add -
RUN echo "deb https://dl.yarnpkg.com/debian/ stable main" | tee /etc/apt/sources.list.d/yarn.list

RUN apt-get -y update && apt-get install -y \
        nodejs \
        yarn \
        composer \
    && true

# install Symfony CLI
RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.deb.sh' | bash
RUN apt install symfony-cli

# install chrome
RUN wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | apt-key add - \
    && sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google.list' \
    && apt-get update \
    && apt-get install -y google-chrome-stable fonts-ipafont-gothic fonts-wqy-zenhei fonts-thai-tlwg fonts-kacst fonts-freefont-ttf libxss1 libx11-xcb1 \
      --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# install weconnect-cli
RUN pip3 install weconnect-cli

# config changes in PHP config
RUN sed -i -e 's/^memory_limit\s*=.*/memory_limit = 1G/' \
           -e 's/^max_execution_time\s*=.*/max_execution_time = 180/' \
           -e 's/^;realpath_cache_size\s*=.*/realpath_cache_size = 4096k/' \
           -e 's/^;realpath_cache_ttl\s*=.*/realpath_cache_ttl = 7200/' \
    /etc/php/8.1/apache2/php.ini

# config changes in PHP config (CLI)
RUN sed -i -e 's/^memory_limit\s*=.*/memory_limit = 4G/' \
           -e 's/^max_execution_time\s*=.*/max_execution_time = 180/' \
           -e 's/^;realpath_cache_size\s*=.*/realpath_cache_size = 4096k/' \
           -e 's/^;realpath_cache_ttl\s*=.*/realpath_cache_ttl = 7200/' \
    /etc/php/8.1/cli/php.ini

# config changes in apache2 config
RUN sed -i -e 's/^ServerTokens\s* .*/ServerTokens Prod/' \
           -e 's/^ServerSignature\s* .*/ServerSignature Off/' \
	/etc/apache2/conf-available/security.conf

# add cron jobs
RUN echo "* * * * * root cd /www && symfony console oshans:data:update" >> /etc/cron.d/oshans
RUN echo "* 0 * * * root cd /www && symfony console oshans:devices:configure" >> /etc/cron.d/oshans
# delete will run once a year: on january first at 2am
RUN echo "0 2 1 1 * root cd /www && symfony console oshans:data:delete" >> /etc/cron.d/oshans

# configure apache2
COPY ./oshans.conf /etc/apache2/sites-available/oshans.conf
RUN a2dissite 000-default
RUN a2ensite oshans
RUN a2enmod rewrite
RUN a2enmod ssl
RUN a2enmod headers

# prepare symfony app
WORKDIR "/www"
COPY ./ /www
RUN /usr/bin/composer install --no-interaction
RUN yarn install
RUN yarn run encore prod
RUN bin/console cache:warmup

# set permissions
RUN HTTPDUSER=$(ps axo user,comm | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1)
RUN setfacl -dR -m u:"$HTTPDUSER":rwX -m u:$(whoami):rwX var
RUN setfacl -R -m u:"$HTTPDUSER":rwX -m u:$(whoami):rwX var

# apply database migrations and run apache2 web server
CMD wait-for-it db:3306 -- env >> /etc/environment ; bin/console doctrine:migrations:migrate --no-interaction ; service cron start ; /usr/sbin/apache2ctl -D FOREGROUND
EXPOSE 443
