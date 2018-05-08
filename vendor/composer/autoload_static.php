<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit932a306c44e4bbe1ce6466d5a7c981ee
{
    public static $prefixLengthsPsr4 = array (
        'C' => 
        array (
            'Comhon\\' => 7,
            'Callipolis\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Comhon\\' => 
        array (
            0 => __DIR__ . '/..' . '/comhon-project/comhon/src/Comhon',
        ),
        'Callipolis\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit932a306c44e4bbe1ce6466d5a7c981ee::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit932a306c44e4bbe1ce6466d5a7c981ee::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
