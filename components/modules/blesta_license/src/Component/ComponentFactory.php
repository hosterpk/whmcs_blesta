<?php
namespace Blesta\Module\BlestaLicense\Component;

use Blesta\ResellerApi\Command\CommandFactory;
use Blesta\ResellerApi\ConnectionInterface;
use Input;
use Module;

/**
 * Component Factory
 */
class ComponentFactory
{

    /**
     * Create a module component
     *
     * @param string $component
     * @param Input $input
     * @param ConnectionInterface $connection
     * @param stdClass $moduleRow An stdClass object of module row info
     * @param CommandFactory $factory
     * @param Module $parent The instance of the parent module class
     * @return AbstractComponent
     */
    public function create(
        $component,
        Input $input,
        ConnectionInterface $connection,
        $moduleRow = null,
        CommandFactory $factory = null,
        Module $parent = null
    ) {
        if (null === $factory) {
            $factory = new CommandFactory();
        }

        switch ($component) {
            case 'Package':
                return new Package($input, $connection, $factory, $moduleRow, $parent);
                break;
            case 'Service':
                return new Service($input, $connection, $factory, $moduleRow, $parent);
                break;
        }
    }
}
