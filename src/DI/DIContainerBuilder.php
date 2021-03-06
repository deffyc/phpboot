<?php
namespace PhpBoot\DI;

use DI\Container;

/**
 * Class DIContainerBuilder
 * @see http://php-di.org/doc/container.html
 */
class DIContainerBuilder extends \DI\ContainerBuilder
{
    public function __construct($containerClass = 'DI\Container')
    {
        parent::__construct($containerClass);
        $this->addDefinitions(new AnnotationReader());
    }
    /**
     * Build and return a container.
     *
     * @return Container
     */
    public function build()
    {

        $this->useAutowiring(false);
        $this->useAnnotations(false);
        return parent::build();
    }
}