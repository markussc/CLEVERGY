FROM ubuntu:24.04
LABEL maintainer="markus.schafroth@3084.ch"
LABEL description="Clevergy.fi Python API"
ARG DEBIAN_FRONTEND=noninteractive
ARG BACKUP_HOUR
ARG INSTANCENAME
RUN apt-get -y update && apt-get upgrade -y
        python3 \
        python3-pip \
    && true

# install Clevergy.fi python API dependencies
RUN pip3 install flask pandas scikit-learn

# start flask web server
CMD python python/pyapi.py
EXPOSE 8192
