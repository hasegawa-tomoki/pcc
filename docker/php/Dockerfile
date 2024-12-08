FROM php:8.4-apache

RUN apt-get update  \
    && apt-get install -y --no-install-recommends \
        vim \
        wget \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install xdebug
RUN docker-php-ext-enable xdebug

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
