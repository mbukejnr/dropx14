FROM php:8.4-cli

WORKDIR /app

RUN docker-php-ext-install pdo pdo_mysql

COPY . .

EXPOSE 8080

ENTRYPOINT ["php"]
CMD ["-S", "0.0.0.0:8080", "-t", "."]
