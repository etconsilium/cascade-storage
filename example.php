<?php include './vendor/autoload.php';

$config=Symfony\Component\Yaml\Yaml::parse('./config.yml');
$storage=new \Storage\Spooler($config);

var_dump($storage);