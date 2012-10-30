<?php

namespace PublicTweetStream;

use \Symfony\Component\Config\Loader\FileLoader,
    \Symfony\Component\Yaml\Yaml;

/**
 * Settings loader for public tweet stream config
 */
class SettingsLoader extends FileLoader
{
    /**
     * @param mixed $resource
     * @param null $type
     * @return array
     */
    public function load($resource, $type = null)
    {
        $configValues = Yaml::parse($resource);
        return $configValues;
    }

    /**
     * @param mixed $resource
     * @param null $type
     * @return bool
     */
    public function supports($resource, $type = null)
    {
        return is_string($resource) && 'yml' === pathinfo(
            $resource,
            PATHINFO_EXTENSION
        );
    }
}