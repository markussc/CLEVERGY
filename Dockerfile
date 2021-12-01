FROM ubuntu:21.04
LABEL maintainer="markus.schafroth@3084.ch"
LABEL description="OSHANS"
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get -y update && apt-get install -y \
        php7.4 \
        php7.4-zip \
        php7.4-xml \
        php7.4-mysql \
        php7.4-intl \
        php7.4-gmp \
        php7.4-curl \
        wget \
        curl \
        gnupg \
        sshpass \
        wait-for-it \
        cron \
        python3 \
        python3-pip \
    && true

RUN curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | apt-key add -
RUN echo "deb https://dl.yarnpkg.com/debian/ stable main" | tee /etc/apt/sources.list.d/yarn.list
RUN curl -sL https://deb.nodesource.com/setup_14.x | bash -
RUN apt-get -y update && apt-get install -y \
        nodejs \
        yarn \
        composer \
    && true

// install chrome
RUN wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | apt-key add - \
    && sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google.list' \
    && apt-get update \
    && apt-get install -y google-chrome-stable fonts-ipafont-gothic fonts-wqy-zenhei fonts-thai-tlwg fonts-kacst fonts-freefont-ttf libxss1 \
      --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# install weconnect-cli
RUN pip3 install weconnect-cli

# install Symfony CLI
RUN wget https://get.symfony.com/cli/installer -O - | bash

# config changes in PHP config
RUN sed -i -e 's/^memory_limit\s*=.*/memory_limit = 1G/' \
           -e 's/^max_execution_time\s*=.*/max_execution_time = 180/' \
           -e 's/^;realpath_cache_size\s*=.*/realpath_cache_size = 4096k/' \
           -e 's/^;realpath_cache_ttl\s*=.*/realpath_cache_ttl = 7200/' \
    /etc/php/7.4/cli/php.ini

# add cron jobs
RUN echo "* * * * * root cd /www && /root/.symfony/bin/symfony console oshans:data:update" >> /etc/cron.d/oshans
RUN echo "*/5 * * * * root cd /www && /root/.symfony/bin/symfony console oshans:data:archive" >> /etc/cron.d/oshans

# prepare symfony app
WORKDIR "/www"
COPY ./ /www
RUN /usr/bin/composer install --no-interaction
RUN yarn install
RUN yarn run encore prod

# apply database migrations and run symfony web server
CMD wait-for-it db:3306 -- env >> /etc/environment ; bin/console cache:clear ; bin/console doctrine:migrations:migrate --no-interaction ; bin/console cache:warmup ; service cron start ; /root/.symfony/bin/symfony server:start
EXPOSE 8000
