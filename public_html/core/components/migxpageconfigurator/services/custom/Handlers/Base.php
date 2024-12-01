<?php

/**
 * Сервис с общими методами для обработчиков.
 */

namespace CustomServices\Handlers;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class Base{

    public \modX $modx;
    public array $properties = [];

    public function __construct(\modX $modx, array $properties = [])
    {
        $this->modx = $modx;
        $this->setProperties($properties);
    }

    protected function setProperties(array $properties)
    {
        $this->properties = array_merge($this->properties, $properties);
    }

}
