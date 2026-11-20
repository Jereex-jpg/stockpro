# GolStock

Sistema de inventario para una tienda de fútbol, hecho con PHP, MariaDB/MySQL y HTML/JS.

## Cómo correrlo (Ubuntu)

1. Instalar lo necesario:

       sudo apt install -y git php-cli php-mysql php-mbstring mariadb-server
       sudo systemctl start mariadb

2. Crear la base y un usuario (cambiá `TU_CLAVE`):

       sudo mysql -e "CREATE DATABASE golstock CHARACTER SET utf8mb4;
       CREATE USER 'golstock'@'localhost' IDENTIFIED BY 'TU_CLAVE';
       GRANT ALL ON golstock.* TO 'golstock'@'localhost';
       FLUSH PRIVILEGES;"

3. Copiar la configuración y completarla con los datos del paso anterior:

       cp config.example.php config.php

4. Crear las tablas, en este orden:

       mysql -u golstock -p golstock < database.sql
       mysql -u golstock -p golstock < tablas.sql

5. Levantar el servidor y abrir http://localhost:8000

       php -S localhost:8000

## Roles

- **Empleado** (atiende y repone): registra ventas, entradas y ajustes (el motivo es obligatorio en los ajustes), agrega y edita productos sin cambiar precios, y gestiona categorías y proveedores. No ve costos, ganancias ni reportes, y no elimina productos.
- **Administrador** (el dueño): hace todo lo anterior, define precios y costos, ve ganancias y reportes, y elimina productos y proveedores.

Los permisos se validan en el servidor (`api.php`), no solo en la pantalla.

## Crear el primer administrador

Las cuentas nuevas son siempre de empleado. Registrá el usuario desde la web y después promovelo:

    sudo mysql golstock -e "UPDATE usuarios SET rol='admin' WHERE usuario='TU_USUARIO';"
