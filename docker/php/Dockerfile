FROM php:8.4-apache

RUN apt-get update  \
    && apt-get install -y --no-install-recommends \
        vim \
        wget \
        git \
        unzip \
        ssh \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install xdebug
RUN docker-php-ext-enable xdebug


#RUN apt-get update && apt-get install -y libgmp-dev
#RUN docker-php-ext-install -j$(nproc) bcmath

RUN apt-get update && apt-get install -y libgmp-dev libffi-dev
RUN docker-php-ext-configure gmp 
RUN docker-php-ext-install -j$(nproc) gmp
RUN docker-php-ext-configure ffi --with-ffi
RUN docker-php-ext-install -j$(nproc) ffi
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Add ja_JP.UTF-8
RUN apt-get update && apt-get install -y \
  locales \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*
RUN sed -i -E 's/# (ja_JP.UTF-8)/\1/' /etc/locale.gen \
  && locale-gen
ENV LANG ja_JP.UTF-8
RUN locale-gen ja_JP.UTF-8

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer self-update
