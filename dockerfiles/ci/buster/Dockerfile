FROM debian:buster AS base

ENV LANG=C.UTF-8
ENV DEBIAN_FRONTEND=noninteractive
ENV ACCEPT_EULA=Y

# For LLVM dependencies:
# Minimum: libclang. Nice-to-have: full toolchain including linker to play
# with cross-language link-time optimization. Needs to match rustc -Vv's llvm
# version.
ENV DEVLIBS \
    clang-16 \
    libclang-16-dev \
    libclang-rt-16-dev \
    llvm-16-dev \
    lld-16 \
    libcurl4-openssl-dev \
    libedit-dev \
    libffi-dev \
    libfreetype6-dev \
    libicu-dev \
    libjpeg-dev \
    libmcrypt-dev \
    libmemcached-dev \
    libonig-dev \
    libpq-dev \
    libpng-dev \
    libsodium-dev \
    libsqlite3-dev \
    libssl-dev \
    libwebp-dev \
    libxml2-dev \
    libxslt1-dev \
    libzip-dev \
    zlib1g-dev \
    libasan5 \
    gnupg \
    unixodbc-dev \
    unixodbc

ENV RUNTIME_DEPS \
    apache2 \
    apache2-dev \
    ca-certificates \
    clang \
    clang-format \
    curl \
    debian-goodies \
    gdb \
    git \
    less \
    netbase \
    netcat \
    nginx \
    strace \
    sudo \
    unzip \
    valgrind \
    vim \
    xz-utils \
    zip

ENV PHPIZE_DEPS \
    autoconf \
    bison \
    dpkg-dev \
    file \
    g++ \
    gcc \
    libc-dev \
    make \
    pkg-config \
    re2c

ARG TARGETPLATFORM
RUN set -eux; \
# Set timezone to UTC by default
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime; \
    \
# Use unicode
    locale-gen C.UTF-8 || true; \
    \
# Core Dumps
    ulimit -c unlimited; \
    \
# Ensure debug symbols are available
    echo "deb http://deb.debian.org/debian-debug/ buster-debug main" | \
        tee -a /etc/apt/sources.list; \
    \
# Use LLVM from orig vendor (also LLVM 16 is not shipped with buster)
    apt-get update; \
    apt-get install -y curl gnupg; \
    echo "deb http://apt.llvm.org/buster/ llvm-toolchain-buster-16 main" >> /etc/apt/sources.list; \
    echo "deb-src http://apt.llvm.org/buster/ llvm-toolchain-buster-16 main" >> /etc/apt/sources.list; \
    curl https://apt.llvm.org/llvm-snapshot.gpg.key | apt-key add -; \
    curl https://apt.llvm.org/llvm-snapshot.gpg.key | tee /etc/apt/trusted.gpg.d/apt.llvm.org.asc; \
    \
# prevent Debian's PHP packages from being installed
# https://github.com/docker-library/php/pull/542
    { \
        echo 'Package: php*'; \
        echo 'Pin: release *'; \
        echo 'Pin-Priority: -1'; \
    } > /etc/apt/preferences.d/no-debian-php; \
    \
# persistent / runtime deps
    apt-get update; \
    apt-get install -y --no-install-recommends \
        gcc-$(if [ "$TARGETPLATFORM" != "linux/arm64" ]; then echo aarch64; else echo x86-64; fi)-linux-gnu \
        g++-$(if [ "$TARGETPLATFORM" != "linux/arm64" ]; then echo aarch64; else echo x86-64; fi)-linux-gnu \
        libc6-dev-$(if [ "$TARGETPLATFORM" != "linux/arm64" ]; then echo arm64; else echo amd64; fi)-cross \
        $DEVLIBS \
        $PHPIZE_DEPS \
        $RUNTIME_DEPS; \
    \
# valgrind 3.14 has a fatal bug making it unusable with PHP on arm64, manually install valgrind from bullseye \
    curl -O http://ftp.de.debian.org/debian/pool/main/v/valgrind/valgrind_3.16.1-1_${TARGETPLATFORM#linux/}.deb; \
    dpkg -i valgrind*.deb; \
    rm valgrind*.deb; \
    \
# Disable DST ROOT X3 certificate explicitly to fix conflicts with older openssl versions \
    sed -ri 's/(mozilla\/DST_Root_CA_X3.crt)/!\1/' /etc/ca-certificates.conf; \
    update-ca-certificates; \
    \
# circleci user + sudo
    groupadd --gid 3434 circleci; \
        useradd --uid 3434 --gid circleci --shell /bin/bash --create-home circleci; \
        echo 'circleci ALL=NOPASSWD: ALL' >> /etc/sudoers.d/50-circleci; \
        echo 'Defaults    env_keep += "DEBIAN_FRONTEND"' >> /etc/sudoers.d/env_keep; \
    \
# Allow nginx to be run as non-root for tests
    chown -R circleci:circleci /var/log/nginx/ /var/lib/nginx/;

ENV CMAKE_VERSION="3.24.4"

# Install CMake
RUN set -eux; \
    if [ "$(uname -m)" = "aarch64" ]; then \
        CMAKE_SHA256="86f823f2636bf715af89da10e04daa476755a799d451baee66247846e95d7bee"; \
    else \
        CMAKE_SHA256="cac77d28fb8668c179ac02c283b058aeb846fe2133a57d40b503711281ed9f19"; \
    fi; \
    cd /tmp && curl -L --output cmake.tar.gz https://github.com/Kitware/CMake/releases/download/v${CMAKE_VERSION}/cmake-${CMAKE_VERSION}-Linux-$(uname -m).tar.gz; \
    (echo "${CMAKE_SHA256} cmake.tar.gz" | sha256sum -c -); \
    mkdir -p /opt/cmake/${CMAKE_VERSION}; \
    tar --strip-components 1 -C /opt/cmake/${CMAKE_VERSION} -xf /tmp/cmake.tar.gz; \
# Currently there's only one version of cmake, make it default
    ln -s /opt/cmake/${CMAKE_VERSION}/bin/cmake /usr/local/bin/cmake; \
    rm -rfv /tmp/*

# Install Catch2
RUN set -eux; \
    CATCH2_VERSION="2.13.10"; \
    CATCH2_SHA256="d54a712b7b1d7708bc7a819a8e6e47b2fde9536f487b89ccbca295072a7d9943"; \
    cd /tmp && curl -OL https://github.com/catchorg/Catch2/archive/v${CATCH2_VERSION}.tar.gz; \
    (echo "${CATCH2_SHA256}  v${CATCH2_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir catch2 && cd catch2; \
    tar -xf ../v${CATCH2_VERSION}.tar.gz --strip 1; \
    /opt/cmake/${CMAKE_VERSION}/bin/cmake -Bbuild -H. -DBUILD_TESTING=OFF -DCMAKE_INSTALL_PREFIX=/opt/catch2 -DCATCH_BUILD_STATIC_LIBRARY=ON; \
    /opt/cmake/${CMAKE_VERSION}/bin/cmake --build build/ --target install; \
    rm -rfv /tmp/*

# Install lcov
RUN set -eux; \
    LCOV_VERSION="1.15"; \
    LCOV_SHA256="c1cda2fa33bec9aa2c2c73c87226cfe97de0831887176b45ee523c5e30f8053a"; \
    cd /tmp && curl -OL https://github.com/linux-test-project/lcov/releases/download/v${LCOV_VERSION}/lcov-${LCOV_VERSION}.tar.gz; \
    (echo "${LCOV_SHA256} lcov-${LCOV_VERSION}.tar.gz" | sha256sum -c -); \
    mkdir lcov && cd lcov; \
    tar -xf ../lcov-${LCOV_VERSION}.tar.gz --strip 1; \
    make install; \
    lcov --version; \
    rm -rfv /tmp/*

# Install SqlServer PHP Driver
# https://learn.microsoft.com/en-us/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server
RUN set -eux; \
    curl -s https://packages.microsoft.com/keys/microsoft.asc | apt-key add -; \
    curl -s https://packages.microsoft.com/config/debian/11/prod.list > /etc/apt/sources.list.d/mssql-release.list; \
    apt-get update; \
    apt-get install -y \
      msodbcsql18 \
      mssql-tools18 \
      $(if [ "$TARGETPLATFORM" = "linux/amd64" ]; then echo msodbcsql17 mssql-tools; fi);

# Apache config
ENV APACHE_CONFDIR /etc/apache2
ENV APACHE_ENVVARS $APACHE_CONFDIR/envvars

RUN set -eux; \
# generically convert lines like
#   export APACHE_RUN_USER=www-data
# into
#   : ${APACHE_RUN_USER:=www-data}
#   export APACHE_RUN_USER
# so that they can be overridden at runtime ("-e APACHE_RUN_USER=...")
    sed -ri 's/^export ([^=]+)=(.*)$/: ${\1:=\2}\nexport \1/' "$APACHE_ENVVARS"; \
    \
# setup directories and permissions
    . "$APACHE_ENVVARS"; \
    for dir in \
        "$APACHE_LOCK_DIR" \
        "$APACHE_RUN_DIR" \
        "$APACHE_LOG_DIR" \
    ; do \
        rm -rvf "$dir"; \
        mkdir -p "$dir"; \
        chown "$APACHE_RUN_USER:$APACHE_RUN_GROUP" "$dir"; \
# allow running as an arbitrary user (https://github.com/docker-library/php/issues/743)
        chmod 777 "$dir"; \
    done; \
    \
# delete the "index.html" that installing Apache drops in here
    rm -rvf /var/www/html/*; \
    \
# disable config depending on env var
    a2disconf other-vhosts-access-log.conf; \
    \
# logs should go to stdout / stderr
    ln -sfT /dev/stderr "$APACHE_LOG_DIR/error.log"; \
    ln -sfT /dev/stdout "$APACHE_LOG_DIR/access.log"; \
    chown -R --no-dereference "$APACHE_RUN_USER:$APACHE_RUN_GROUP" "$APACHE_LOG_DIR"; \
    \
# Apache + PHP requires preforking Apache for best results
    a2dismod mpm_event && a2enmod mpm_prefork ;\
# PHP files should be handled by PHP, and should be preferred over any other file type
    { \
        echo '<FilesMatch \.php$>'; \
        echo '\tSetHandler application/x-httpd-php'; \
        echo '</FilesMatch>'; \
        echo; \
        echo 'DirectoryIndex disabled'; \
        echo 'DirectoryIndex index.php index.html'; \
        echo; \
        echo '<Directory /var/www/>'; \
        echo '\tOptions -Indexes'; \
        echo '\tAllowOverride All'; \
        echo '</Directory>'; \
    } | tee "$APACHE_CONFDIR/conf-available/docker-php.conf"; \
    a2enconf docker-php; \
# We want to use mod_rewrite
    a2enmod rewrite;

RUN set -eux; \
# Share welcome message with the world
    echo '[ ! -z "$TERM" -a -r /etc/motd ] && cat /etc/motd' \
        >> /etc/bash.bashrc;

# Set up PHP directories
ENV PHP_SRC_DIR=/usr/local/src/php
ENV PHP_INSTALL_DIR=/opt/php

RUN set -eux; \
# Setup php source directory
    mkdir -p $PHP_SRC_DIR; \
    chown -R circleci:circleci /usr/local/src; \
# Setup php install directory
    mkdir -p $PHP_INSTALL_DIR; \
    chown -R circleci:circleci /opt;

# rust sha256sum generated locally after verifying it with sha256
ARG RUST_VERSION="1.71.1"
ARG RUST_SHA256_ARM="c7cf230c740a62ea1ca6a4304d955c286aea44e3c6fc960b986a8c2eeea4ec3f"
ARG RUST_SHA256_X86="34778d1cda674990dfc0537bc600066046ae9cb5d65a07809f7e7da31d4689c4"
# Mount a cache into /rust/cargo if you want to pre-fetch packages or something
ENV CARGO_HOME=/rust/cargo
ENV RUSTUP_HOME=/rust/rustup
RUN mkdir -p -v "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && chmod -R 777 "${CARGO_HOME}" "${RUSTUP_HOME}" \
    && MARCH=$(uname -m) \
    && RUST_SHA256=$(if [ "$MARCH" = "x86_64" ]; then echo ${RUST_SHA256_X86}; elif [ "$MARCH" = "aarch64" ]; then echo ${RUST_SHA256_ARM}; fi) \
    && FILENAME=rust-${RUST_VERSION}-${MARCH}-unknown-linux-gnu.tar.gz \
    && curl -L --write-out '%{http_code}' -O https://static.rust-lang.org/dist/${FILENAME} \
    && printf '%s  %s' "$RUST_SHA256" "$FILENAME" | sha256sum --check --status \
    && tar -xf "$FILENAME" \
    && cd ${FILENAME%.tar.gz} \
    && ./install.sh --components="rustc,cargo,clippy-preview,rustfmt-preview,rust-std-${MARCH}-unknown-linux-gnu" \
    && cd - \
    && rm -fr "$FILENAME" "${FILENAME%.tar.gz}" \
    && rm -rfv /tmp/*

ENV PATH="/rust/cargo/bin:${PATH}"

# Add the wait script to the image: note SHA 672a28f0509433e3b4b9bcd4d9cd7668cea7e31a has been reviewed and should not
# be changed without an appropriate code review.
ADD https://raw.githubusercontent.com/eficode/wait-for/672a28f0509433e3b4b9bcd4d9cd7668cea7e31a/wait-for /usr/bin/wait-for
RUN chmod a+rx /usr/bin/wait-for

# Run everything else as circleci user
USER circleci

RUN set -eux; \
# Pretty prompt
    echo "PS1='\[\033[01;32m\]\u\[\033[00m\]\[\033[00;35m\](buster)\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '" | \
        tee -a /home/circleci/.bashrc; \
# Autocomplete of Makefile targets (see: https://stackoverflow.com/a/38415982)
    echo "complete -W \"\\\`grep -oE '^[a-zA-Z0-9_.-]+:([^=]|$)' ?akefile | sed 's/[^a-zA-Z0-9_.-]*$//'\\\`\" make" | \
        tee -a /home/circleci/.bashrc; \
# Handy aliases
    echo "alias ll='ls -al'" | \
        tee -a /home/circleci/.bash_aliases; \
# Please remember gdb history
    echo 'set history save on' >> /home/circleci/.gdbinit; \
        chmod 600 /home/circleci/.gdbinit;

COPY install-ext-from-source /usr/local/bin/install-ext-from-source
COPY switch-php /usr/local/bin/

WORKDIR /home/circleci

# Override stop signal to stop process gracefully
# https://github.com/php/php-src/blob/17baa87faddc2550def3ae7314236826bc1b1398/sapi/fpm/php-fpm.8.in#L163
STOPSIGNAL SIGQUIT

EXPOSE 9000
EXPOSE 80

CMD [ "bash" ]
