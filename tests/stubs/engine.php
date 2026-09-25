<?php

declare(strict_types=1);

namespace Opencart\System\Engine {
    if (!class_exists(Controller::class)) {
        class Controller
        {
            protected $registry = null;

            public function __construct($registry = null)
            {
                $this->registry = $registry;
            }

            // Like the real engine: controllers read the registry.
            public function __get($key)
            {
                return is_object($this->registry) && method_exists($this->registry, 'get')
                    ? $this->registry->get($key)
                    : null;
            }
        }
    }

    if (!class_exists(Model::class)) {
        class Model
        {
            protected $registry = null;

            public function __construct($registry = null)
            {
                $this->registry = $registry;
            }

            public function __get($key)
            {
                return null;
            }
        }
    }
}
