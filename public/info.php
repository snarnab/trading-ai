<?php

echo "PDO Exists: ";
var_dump(class_exists('PDO'));

echo "<br><br>";

echo "PDO MySQL: ";
var_dump(extension_loaded('pdo_mysql'));

echo "<br><br>";

print_r(PDO::getAvailableDrivers());